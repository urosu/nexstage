<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\DailySnapshot;
use App\Models\Workspace;
use App\Services\WorkspaceContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class BillingController extends Controller
{
    public function show(): Response
    {
        $workspace = Workspace::withoutGlobalScopes()
            ->select([
                'id', 'name', 'billing_plan', 'trial_ends_at', 'stripe_id',
                'pm_type', 'pm_last_four', 'billing_name', 'billing_email',
                'billing_address', 'vat_number', 'has_store', 'reporting_currency',
            ])
            ->findOrFail(app(WorkspaceContext::class)->id());

        $this->authorize('viewBilling', $workspace);

        $now = now();

        // Last full calendar month billable amount (source of truth for tier assignment).
        // Billing basis: has_store=true → GMV; has_store=false → ad spend.
        // See: PLANNING.md "Billing basis auto-derivation"
        $lastMonth   = $now->copy()->subMonth();
        $startOfLast = $lastMonth->copy()->startOfMonth()->toDateString();
        $endOfLast   = $lastMonth->copy()->endOfMonth()->toDateString();

        $lastMonthRevenue = $this->computeBillableAmount($workspace, $startOfLast, $endOfLast);

        // Current month billable so far (for the "next billing" preview)
        $currentMonthRevenue = $this->computeBillableAmount(
            $workspace,
            $now->copy()->startOfMonth()->toDateString(),
            $now->toDateString(),
        );

        // Resolve the tier that would be assigned based on last month's revenue
        $revenueForTier = $lastMonthRevenue > 0.0 ? $lastMonthRevenue : $currentMonthRevenue;
        $resolvedTier   = $this->resolveTierFromRevenue($revenueForTier);

        // Subscription, payment methods, and invoices via Cashier — wrapped in try/catch
        // so the billing page renders even when Stripe is not configured.
        // Stripe data is cached for 60 s per workspace to avoid 4 API round-trips on every
        // page load. The lazy-sync of pm_type/pm_last_four runs on a cache miss only.
        $subscriptionData    = null;
        $paymentMethodsData  = [];
        $invoicesData        = [];
        $upcomingInvoiceData = null;

        try {
            // subscription() reads the local subscriptions table — no Stripe call needed.
            $subscription = $workspace->subscription('default');
            if ($subscription) {
                $subscriptionData = [
                    'stripe_status'   => $subscription->stripe_status,
                    'stripe_price'    => $subscription->stripe_price,
                    'ends_at'         => $subscription->ends_at?->toISOString(),
                    'trial_ends_at'   => $subscription->trial_ends_at?->toISOString(),
                    'on_grace_period' => $subscription->onGracePeriod(),
                ];
            }

            // Payment methods, invoices, and upcoming invoice are cached to avoid
            // 3 Stripe API round-trips on every page render.
            $stripeCacheKey = "billing_stripe_{$workspace->id}";
            $stripeData     = Cache::remember($stripeCacheKey, 60, function () use ($workspace): array {
                $paymentMethods = [];
                $invoices       = [];
                $upcoming       = null;
                $defaultPmSync  = null;

                $defaultPmId = $workspace->defaultPaymentMethod()?->id;
                foreach ($workspace->paymentMethods() as $pm) {
                    $card  = $pm->card;
                    $entry = [
                        'id'         => $pm->id,
                        'brand'      => $card?->brand,
                        'last4'      => $card?->last4,
                        'exp_month'  => $card?->exp_month,
                        'exp_year'   => $card?->exp_year,
                        'wallet'     => $card?->wallet?->type,
                        'is_default' => $pm->id === $defaultPmId,
                    ];
                    $paymentMethods[] = $entry;

                    if ($entry['is_default']) {
                        $defaultPmSync = ['type' => $card?->brand ?? $pm->type, 'last4' => $card?->last4];
                    }
                }

                foreach ($workspace->invoices() as $invoice) {
                    $invoices[] = [
                        'id'           => $invoice->id,
                        'number'       => $invoice->number,
                        'amount_due'   => $invoice->rawTotal() / 100,
                        'currency'     => strtoupper($invoice->invoice->currency),
                        'status'       => $invoice->status,
                        'date'         => $invoice->date()->toISOString(),
                        'download_url' => route('settings.billing.invoices.download', ['invoiceId' => $invoice->id]),
                        'hosted_url'   => $invoice->hostedInvoiceUrl(),
                    ];
                }

                $upcomingObj = $workspace->upcomingInvoice();
                if ($upcomingObj) {
                    $upcoming = [
                        'amount'   => $upcomingObj->rawTotal() / 100,
                        'currency' => strtoupper($upcomingObj->invoice->currency),
                        'date'     => $upcomingObj->date()->toISOString(),
                    ];
                }

                return [
                    'payment_methods' => $paymentMethods,
                    'invoices'        => $invoices,
                    'upcoming'        => $upcoming,
                    'default_pm_sync' => $defaultPmSync,
                ];
            });

            $paymentMethodsData  = $stripeData['payment_methods'];
            $invoicesData        = $stripeData['invoices'];
            $upcomingInvoiceData = $stripeData['upcoming'];

            // Lazy-sync default pm columns — only when fetched fresh from Stripe (cache miss).
            // On cache hits, $stripeData came from closure execution which would have synced already.
            if ($stripeData['default_pm_sync'] !== null) {
                $freshType  = $stripeData['default_pm_sync']['type'];
                $freshLast4 = $stripeData['default_pm_sync']['last4'];
                if ($workspace->pm_type !== $freshType || $workspace->pm_last_four !== $freshLast4) {
                    $workspace->forceFill([
                        'pm_type'      => $freshType,
                        'pm_last_four' => $freshLast4,
                    ])->save();
                }
            }
        } catch (\Stripe\Exception\AuthenticationException|\Stripe\Exception\ApiConnectionException $e) {
            \Illuminate\Support\Facades\Log::warning('Stripe unavailable on billing page load', ['error' => $e->getMessage()]);
        }

        $currentMonthTier = $this->resolveTierFromRevenue($currentMonthRevenue);
        $daysUntilBilling = (int) $now->diffInDays($now->copy()->startOfMonth()->addMonth(), false);

        // Extrapolate current month billable to a full-month estimate.
        // Only meaningful from day 7 onwards — before that there's too little data.
        $dayOfMonth              = (int) $now->format('j');
        $daysInMonth             = (int) $now->format('t');
        $projectedMonthRevenue   = null;
        $projectedMonthTier      = null;
        if ($dayOfMonth >= 7 && $currentMonthRevenue > 0.0) {
            $projectedMonthRevenue = (float) round(($currentMonthRevenue / $dayOfMonth) * $daysInMonth, 2);
            $projectedMonthTier    = $this->resolveTierFromRevenue($projectedMonthRevenue);
        }

        // 'gmv' for ecom workspaces, 'ad_spend' for non-ecom.
        // Used in the frontend to label revenue cards appropriately.
        $billingBasis = $workspace->has_store ? 'gmv' : 'ad_spend';

        return Inertia::render('Settings/Billing', [
            'workspaceInfo' => [
                'id'              => $workspace->id,
                'name'            => $workspace->name,
                'billing_plan'    => $workspace->billing_plan,
                'trial_ends_at'   => $workspace->trial_ends_at?->toISOString(),
                'stripe_id'       => $workspace->stripe_id,
                'pm_type'         => $workspace->pm_type,
                'pm_last_four'    => $workspace->pm_last_four,
                'billing_name'    => $workspace->billing_name,
                'billing_email'   => $workspace->billing_email,
                'billing_address' => $workspace->billing_address,
                'vat_number'      => $workspace->vat_number,
            ],
            'subscription'            => $subscriptionData,
            'upcoming_invoice'        => $upcomingInvoiceData,
            'payment_methods'         => $paymentMethodsData,
            'invoices'                => $invoicesData,
            'last_month_revenue'      => $lastMonthRevenue,
            'current_month_revenue'   => $currentMonthRevenue,
            'resolved_tier'           => $resolvedTier,
            'current_month_tier'      => $currentMonthTier,
            'projected_month_revenue' => $projectedMonthRevenue,
            'projected_month_tier'    => $projectedMonthTier,
            'days_until_billing'      => $daysUntilBilling,
            'day_of_month'            => $dayOfMonth,
            'days_in_month'           => $daysInMonth,
            'tier_prices'             => $this->tierPrices(),
            'billing_basis'           => $billingBasis,
            'status'                  => session('status'),
        ]);
    }

    /**
     * Start a Stripe Checkout session for a new subscription.
     *
     * The tier is determined automatically from last month's revenue.
     * The user only selects monthly vs annual billing interval.
     */
    public function subscribe(Request $request): RedirectResponse
    {
        $workspace = Workspace::withoutGlobalScopes()
            ->select(['id', 'name', 'billing_plan', 'stripe_id', 'billing_name', 'billing_email', 'has_store'])
            ->findOrFail(app(WorkspaceContext::class)->id());

        $this->authorize('manageBilling', $workspace);

        $validated = $request->validate([
            'interval' => ['sometimes', 'string', Rule::in(['monthly', 'annual'])],
        ]);

        $interval = $validated['interval'] ?? 'monthly';

        // Determine tier from last full calendar month billable amount (GMV or ad spend).
        $now         = now();
        $lastMonth   = $now->copy()->subMonth();
        $startOfLast = $lastMonth->copy()->startOfMonth()->toDateString();
        $endOfLast   = $lastMonth->copy()->endOfMonth()->toDateString();

        $revenue = $this->computeBillableAmount($workspace, $startOfLast, $endOfLast);

        // Fall back to current month if no last month data yet.
        if ($revenue === 0.0) {
            $revenue = $this->computeBillableAmount(
                $workspace,
                $now->copy()->startOfMonth()->toDateString(),
                $now->toDateString(),
            );
        }

        $tier     = $this->resolveTierFromRevenue($revenue);
        $priceId  = $this->priceIdForTier($tier, $interval);

        if ($priceId === null) {
            return back()->withErrors(['billing' => 'Subscription not available. Please contact support.']);
        }

        $workspace->createOrGetStripeCustomer([
            'name'  => $workspace->billing_name ?? $workspace->name,
            'email' => $workspace->billing_email ?? $request->user()->email,
        ]);

        // Anchor to the 1st of next month so billing aligns with ReportMonthlyRevenueToStripeJob.
        // Stripe prorates the first partial month automatically at checkout.
        $checkout = $workspace->newSubscription('default', $priceId)
            ->anchorBillingCycleTo(now()->startOfMonth()->addMonth())
            ->checkout([
                'success_url' => route('settings.billing') . '?subscribed=1',
                'cancel_url'  => route('settings.billing'),
            ]);

        return redirect($checkout->url);
    }

    /**
     * Cancel the current subscription at period end.
     */
    public function cancel(): RedirectResponse
    {
        $workspace = Workspace::findOrFail(app(WorkspaceContext::class)->id());

        $this->authorize('manageBilling', $workspace);

        $subscription = $workspace->subscription('default');

        if (! $subscription) {
            return back()->withErrors(['billing' => 'No subscription found.']);
        }

        if ($subscription->onGracePeriod()) {
            return back()->withErrors(['billing' => 'Subscription is already cancelled.']);
        }

        try {
            $subscription->cancel();
        } catch (\Stripe\Exception\ApiErrorException $e) {
            \Illuminate\Support\Facades\Log::error('Stripe cancel failed', ['error' => $e->getMessage(), 'workspace_id' => $workspace->id]);
            return back()->withErrors(['billing' => 'Could not cancel subscription: ' . $e->getMessage()]);
        }

        return back()->with('success', 'Subscription cancelled. You retain access until the end of the billing period.');
    }

    /**
     * Resume a cancelled subscription while still within the grace period.
     */
    public function resume(): RedirectResponse
    {
        $workspace = Workspace::findOrFail(app(WorkspaceContext::class)->id());

        $this->authorize('manageBilling', $workspace);

        $subscription = $workspace->subscription('default');

        if (! $subscription || ! $subscription->onGracePeriod()) {
            return back()->withErrors(['billing' => 'No cancelled subscription to resume.']);
        }

        $subscription->resume();

        return back()->with('success', 'Subscription resumed successfully.');
    }

    /**
     * Update billing details (name, email, address, VAT) and sync to Stripe.
     */
    public function updateDetails(Request $request): RedirectResponse
    {
        $workspace = Workspace::findOrFail(app(WorkspaceContext::class)->id());

        $this->authorize('manageBilling', $workspace);

        $validated = $request->validate([
            'billing_name'                => ['required', 'string', 'max:255'],
            'billing_email'               => ['required', 'email', 'max:255'],
            'vat_number'                  => ['nullable', 'string', 'max:50'],
            'billing_address.company'     => ['nullable', 'string', 'max:255'],
            'billing_address.line1'       => ['nullable', 'string', 'max:255'],
            'billing_address.line2'       => ['nullable', 'string', 'max:255'],
            'billing_address.city'        => ['nullable', 'string', 'max:255'],
            'billing_address.state'       => ['nullable', 'string', 'max:255'],
            'billing_address.postal_code' => ['nullable', 'string', 'max:50'],
            'billing_address.country'     => ['nullable', 'string', 'size:2'],
        ]);

        $workspace->update([
            'billing_name'    => $validated['billing_name'],
            'billing_email'   => $validated['billing_email'],
            'vat_number'      => $validated['vat_number'] ?? null,
            'billing_address' => array_filter([
                'company'     => $validated['billing_address']['company']     ?? null,
                'line1'       => $validated['billing_address']['line1']       ?? null,
                'line2'       => $validated['billing_address']['line2']       ?? null,
                'city'        => $validated['billing_address']['city']        ?? null,
                'state'       => $validated['billing_address']['state']       ?? null,
                'postal_code' => $validated['billing_address']['postal_code'] ?? null,
                'country'     => $validated['billing_address']['country']     ?? null,
            ]) ?: null,
        ]);

        // Sync billing details to Stripe if customer exists.
        // Use company name as Stripe customer name when present; fall back to billing_name.
        if ($workspace->stripe_id) {
            $taxData = $workspace->vat_number
                ? [['type' => 'eu_vat', 'value' => $workspace->vat_number]]
                : [];

            // Strip company out of the address before sending to Stripe (not a Stripe address field)
            $stripeAddress = collect($workspace->billing_address ?? [])
                ->except('company')
                ->filter()
                ->toArray() ?: null;

            $workspace->updateStripeCustomer([
                'name'        => $workspace->billing_address['company'] ?? $workspace->billing_name,
                'email'       => $workspace->billing_email,
                'address'     => $stripeAddress,
                'tax_id_data' => $taxData,
            ]);
        }

        return back()->with('success', 'Billing details updated.');
    }

    /**
     * Create a Stripe SetupIntent so the frontend can collect a new payment method.
     */
    public function createSetupIntent(Request $request): \Illuminate\Http\JsonResponse
    {
        $workspace = Workspace::findOrFail(app(WorkspaceContext::class)->id());
        $this->authorize('manageBilling', $workspace);

        $workspace->createOrGetStripeCustomer([
            'name'  => $workspace->billing_name ?? $workspace->name,
            'email' => $workspace->billing_email ?? $request->user()->email,
        ]);

        $intent = $workspace->createSetupIntent();

        return response()->json(['client_secret' => $intent->client_secret]);
    }

    /**
     * Called after the frontend confirms a SetupIntent.
     * Sets the new payment method as default if the workspace has no default yet.
     *
     * Also attempts to infer store primary_country_code from the payment method's
     * billing address country when the store has no country set yet. This is one of
     * three auto-detection mechanisms per PLANNING section 10 (Country auto-detection):
     *   1. ccTLD from domain URL    — client-side, highest priority
     *   2. IP geolocation on login  — server-side session hint
     *   3. Stripe billing country   — extracted here, applied directly when unambiguous
     *
     * Only fills NULL country codes — never overwrites a value the user explicitly set.
     */
    public function confirmPaymentMethod(string $pmId): RedirectResponse
    {
        $workspace = Workspace::findOrFail(app(WorkspaceContext::class)->id());
        $this->authorize('manageBilling', $workspace);

        if ($workspace->defaultPaymentMethod() === null) {
            $workspace->updateDefaultPaymentMethod($pmId);
        }

        // Extract billing country from the payment method and apply to stores missing one.
        $this->applyBillingCountryFromPaymentMethod($workspace, $pmId);

        return back()->with('success', 'Payment method saved.');
    }

    /**
     * Reads billing_details.address.country from the Stripe payment method and applies
     * it as primary_country_code to every store in the workspace that has no country set.
     *
     * Only stores the detected country when it is a valid 2-letter ISO code and the store
     * field is currently NULL. Users can override via store settings.
     */
    private function applyBillingCountryFromPaymentMethod(Workspace $workspace, string $pmId): void
    {
        // Only run when there are stores without a country (avoids unnecessary Stripe calls).
        $nullCountryStores = \App\Models\Store::withoutGlobalScopes()
            ->where('workspace_id', $workspace->id)
            ->whereNull('primary_country_code')
            ->get(['id']);

        if ($nullCountryStores->isEmpty()) {
            return;
        }

        try {
            $pm = $workspace->findPaymentMethod($pmId);
            // Cashier wraps the raw Stripe PM — access the underlying object for billing_details.
            $country = $pm?->billing_details?->address?->country ?? null;

            if (! $country || ! preg_match('/^[A-Z]{2}$/', strtoupper($country))) {
                return;
            }

            $countryCode = strtoupper($country);

            foreach ($nullCountryStores as $store) {
                $store->update(['primary_country_code' => $countryCode]);
            }

            \Illuminate\Support\Facades\Log::info('Applied billing address country to stores', [
                'workspace_id' => $workspace->id,
                'country'      => $countryCode,
                'store_count'  => $nullCountryStores->count(),
            ]);
        } catch (\Throwable $e) {
            // Non-critical — log and continue. The store page always lets users set country manually.
            \Illuminate\Support\Facades\Log::debug('Could not extract Stripe billing country', [
                'workspace_id' => $workspace->id,
                'pm_id'        => $pmId,
                'error'        => $e->getMessage(),
            ]);
        }
    }

    /**
     * Set a payment method as the customer's default.
     */
    public function setDefaultPaymentMethod(string $pmId): RedirectResponse
    {
        $workspace = Workspace::findOrFail(app(WorkspaceContext::class)->id());
        $this->authorize('manageBilling', $workspace);

        $workspace->updateDefaultPaymentMethod($pmId);

        return back()->with('success', 'Default payment method updated.');
    }

    /**
     * Remove a payment method from the customer's account.
     * If only one card remains after deletion, it is automatically made the default.
     */
    public function deletePaymentMethod(string $pmId): RedirectResponse
    {
        $workspace = Workspace::findOrFail(app(WorkspaceContext::class)->id());
        $this->authorize('manageBilling', $workspace);

        // Pass the ID string directly — avoids the Cashier wrapper type mismatch.
        $workspace->deletePaymentMethod($pmId);

        // If exactly one card remains, ensure it is the default.
        $remaining = collect($workspace->paymentMethods());
        if ($remaining->count() === 1) {
            $workspace->updateDefaultPaymentMethod($remaining->first()->id);
        }

        return back()->with('success', 'Payment method removed.');
    }

    /**
     * Stream a Stripe invoice PDF download.
     */
    public function downloadInvoice(Request $request, string $invoiceId): HttpResponse
    {
        $workspace = Workspace::findOrFail(app(WorkspaceContext::class)->id());
        $this->authorize('manageBilling', $workspace);

        return $workspace->downloadInvoice($invoiceId, [
            'vendor'  => config('app.name'),
            'product' => 'Subscription',
        ]);
    }

    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Compute the billable amount for a workspace over the given date range.
     *
     * Billing basis (see PLANNING.md "Billing basis auto-derivation"):
     *   has_store = true  → GMV from daily_snapshots.revenue
     *   has_store = false → ad spend from ad_insights at campaign level
     *
     * Why campaign level for ad spend: PLANNING.md says "never SUM across
     * levels". Campaign level gives correct workspace total without double-count.
     */
    private function computeBillableAmount(Workspace $workspace, string $from, string $to): float
    {
        if ($workspace->has_store) {
            return (float) DailySnapshot::withoutGlobalScopes()
                ->where('workspace_id', $workspace->id)
                ->whereBetween('date', [$from, $to])
                ->select(DB::raw('COALESCE(SUM(revenue), 0) AS total'))
                ->value('total');
        }

        // Non-ecom: total ad spend (campaign-level to avoid double-counting).
        return (float) DB::table('ad_insights')
            ->join('ad_accounts', 'ad_insights.ad_account_id', '=', 'ad_accounts.id')
            ->where('ad_accounts.workspace_id', $workspace->id)
            ->where('ad_insights.level', 'campaign')
            ->whereBetween('ad_insights.date', [$from, $to])
            ->select(DB::raw('COALESCE(SUM(ad_insights.spend_in_reporting_currency), 0) AS total'))
            ->value('total');
    }

    /**
     * Resolve billing tier name from a billable amount.
     * Returns the flat tier key ('starter'/'growth') or 'scale' for the metered tier.
     */
    private function resolveTierFromRevenue(float $revenue): string
    {
        /** @var array<string, array{revenue_limit: int}> $flatPlans */
        $flatPlans = config('billing.flat_plans', []);

        // Sort ascending by revenue_limit so we return the lowest qualifying tier.
        uasort($flatPlans, static fn ($a, $b) => $a['revenue_limit'] <=> $b['revenue_limit']);

        foreach ($flatPlans as $planName => $config) {
            if ($revenue <= (float) $config['revenue_limit']) {
                return $planName;
            }
        }

        // Scale is the metered tier. DB plan key = 'scale'.
        return 'scale';
    }

    /**
     * Return the Stripe Price ID for the given tier and billing interval.
     * Scale (metered) has no annual option.
     * Returns null if the price ID is not configured.
     */
    private function priceIdForTier(string $tier, string $interval): ?string
    {
        if ($tier === 'scale') {
            return config('billing.scale_plan.price_id') ?: null;
        }

        $flatPlans = config('billing.flat_plans', []);

        if (! isset($flatPlans[$tier])) {
            return null;
        }

        return $interval === 'annual'
            ? ($flatPlans[$tier]['price_id_annual'] ?: null)
            : ($flatPlans[$tier]['price_id_monthly'] ?: null);
    }

    /**
     * Return monthly/annual prices per tier for the billing UI.
     * Scale is metered: monthly/annual are null (computed dynamically from usage).
     *
     * @return array<string, array{monthly: int|null, annual: int|null, rate_gmv: float|null, rate_ad_spend: float|null}>
     */
    private function tierPrices(): array
    {
        return [
            'starter' => ['monthly' => 29,  'annual' => 290,  'rate_gmv' => null, 'rate_ad_spend' => null],
            'growth'  => ['monthly' => 59,  'annual' => 590,  'rate_gmv' => null, 'rate_ad_spend' => null],
            'scale'   => ['monthly' => null, 'annual' => null, 'rate_gmv' => 0.01, 'rate_ad_spend' => 0.02],
        ];
    }
}
