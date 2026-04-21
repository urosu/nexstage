<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Exceptions\FxRateNotFoundException;
use App\Models\Workspace;
use App\Services\Fx\FxRateService;
use App\Services\WorkspaceContext;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Stripe\StripeClient;

/**
 * Runs on the 1st of each month at 06:00 UTC. Two responsibilities:
 *
 * 1. Tier recalculation for flat-plan workspaces (starter/growth):
 *    Calculates previous month billable amount, resolves the correct tier,
 *    and calls $subscription->swap() if the tier has changed. Annual
 *    subscriptions are only upgraded mid-year, never downgraded.
 *    billing_plan is updated via the WebhookReceived listener after Stripe
 *    confirms the swap.
 *
 * 2. Scale tier usage reporting:
 *    Reports previous-month billable amount to Stripe for metered billing.
 *    Always in EUR. Floor: €149/month. Converts via fx_rates (last day of
 *    prev month).
 *
 * Billing basis (see PLANNING.md "Billing basis auto-derivation"):
 *   - has_store = true  → GMV from daily_snapshots.revenue (1% Scale rate)
 *   - has_store = false → ad spend from ad_insights at campaign level (2% Scale rate)
 *   - Both store AND ads → GMV only
 *
 * Queue:   low
 * Timeout: 300 s
 * Tries:   5
 * Backoff: [60, 300, 900, 1800, 3600] s
 */
class ReportMonthlyRevenueToStripeJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 300;
    public int $tries   = 5;

    /** @var array<int, int> */
    public array $backoff = [60, 300, 900, 1800, 3600];

    public function __construct()
    {
        $this->onQueue('low');
    }

    public function handle(FxRateService $fxRateService): void
    {
        $now           = now();
        $previousMonth = $now->copy()->subMonth();
        $startOfPrev   = $previousMonth->copy()->startOfMonth()->toDateString();
        $endOfPrev     = $previousMonth->copy()->endOfMonth()->toDateString();
        $fxDate        = Carbon::parse($endOfPrev);

        // ── 1. Flat-tier recalculation (starter / growth) ──────────────────
        $flatPlans = config('billing.flat_plans', []);

        if (! empty($flatPlans)) {
            $flatWorkspaces = Workspace::withoutGlobalScopes()
                ->whereIn('billing_plan', array_keys($flatPlans))
                ->whereNull('deleted_at')
                ->whereNotNull('stripe_id')
                ->select(['id', 'billing_plan', 'reporting_currency', 'stripe_id', 'has_store'])
                ->get();

            Log::info('ReportMonthlyRevenueToStripeJob: recalculating flat tiers', ['count' => $flatWorkspaces->count()]);

            foreach ($flatWorkspaces as $workspace) {
                $this->recalculateFlatTier($workspace, $flatPlans, $startOfPrev, $endOfPrev);
            }
        }

        // ── 2. Scale tier usage reporting (metered) ─────────────────────────
        $scaleConfig    = config('billing.scale_plan');
        $scalePriceId   = $scaleConfig['price_id'] ?? null;
        $minimumMonthly = (float) ($scaleConfig['minimum_monthly'] ?? 149);

        if ($scalePriceId === null) {
            Log::warning('ReportMonthlyRevenueToStripeJob: STRIPE_PRICE_SCALE not configured, skipping Scale reporting.');
            return;
        }

        $scaleWorkspaces = Workspace::withoutGlobalScopes()
            ->where('billing_plan', 'scale')
            ->whereNull('deleted_at')
            ->select(['id', 'reporting_currency', 'stripe_id', 'has_store'])
            ->get();

        Log::info('ReportMonthlyRevenueToStripeJob: reporting Scale usage', ['count' => $scaleWorkspaces->count()]);

        $stripe = new StripeClient(config('cashier.secret'));

        foreach ($scaleWorkspaces as $workspace) {
            $this->reportScaleUsage(
                $workspace,
                $stripe,
                $scalePriceId,
                $minimumMonthly,
                $scaleConfig,
                $startOfPrev,
                $endOfPrev,
                $fxDate,
                $fxRateService,
            );
        }

        Log::info('ReportMonthlyRevenueToStripeJob: completed');
    }

    /**
     * Recalculate the correct tier for a flat-plan workspace based on last
     * month's billable amount. Swaps the Stripe subscription if the tier has
     * changed. Annual subscriptions are only upgraded, never downgraded mid-year.
     *
     * @param array<string, array{revenue_limit: int, price_id_monthly: string, price_id_annual: string}> $flatPlans
     */
    private function recalculateFlatTier(
        Workspace $workspace,
        array $flatPlans,
        string $startOfPrev,
        string $endOfPrev,
    ): void {
        app(WorkspaceContext::class)->set($workspace->id);

        $billable    = $this->queryBillableAmount($workspace, $startOfPrev, $endOfPrev);
        $correctTier = $this->resolveTierFromRevenue($billable, $flatPlans);

        if ($correctTier === $workspace->billing_plan) {
            return;
        }

        $subscription = $workspace->subscription('default');

        if ($subscription === null || ! $subscription->valid()) {
            return;
        }

        $isAnnual    = $this->isAnnualSubscription($subscription, $flatPlans);
        $isDowngrade = $this->isTierDowngrade($workspace->billing_plan, $correctTier, $flatPlans);

        // Annual plans: upgrade only (never downgrade mid-year)
        if ($isAnnual && $isDowngrade) {
            Log::info('ReportMonthlyRevenueToStripeJob: skipping annual downgrade', [
                'workspace_id' => $workspace->id,
                'current_tier' => $workspace->billing_plan,
                'correct_tier' => $correctTier,
            ]);
            return;
        }

        $scaleConfig = config('billing.scale_plan');
        $newPriceId  = match ($correctTier) {
            'scale'  => $scaleConfig['price_id'] ?? null,
            default  => $isAnnual
                ? ($flatPlans[$correctTier]['price_id_annual'] ?? null)
                : ($flatPlans[$correctTier]['price_id_monthly'] ?? null),
        };

        if ($newPriceId === null) {
            Log::warning('ReportMonthlyRevenueToStripeJob: no price ID configured for tier', [
                'workspace_id' => $workspace->id,
                'tier'         => $correctTier,
            ]);
            return;
        }

        try {
            $subscription->swap($newPriceId);

            Log::info('ReportMonthlyRevenueToStripeJob: tier swapped', [
                'workspace_id'  => $workspace->id,
                'from_tier'     => $workspace->billing_plan,
                'to_tier'       => $correctTier,
                'billable'      => $billable,
                'billing_basis' => $workspace->has_store ? 'gmv' : 'ad_spend',
                'annual'        => $isAnnual,
            ]);
        } catch (\Stripe\Exception\ApiErrorException $e) {
            Log::error('ReportMonthlyRevenueToStripeJob: Stripe swap failed', [
                'workspace_id' => $workspace->id,
                'error'        => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Report previous-month usage to Stripe for a Scale-tier workspace.
     * Applies the appropriate rate (1% GMV or 2% ad spend) and enforces
     * the €149/month floor.
     *
     * @param array{price_id: string, gmv_rate: float, ad_spend_rate: float, minimum_monthly: int} $scaleConfig
     */
    private function reportScaleUsage(
        Workspace $workspace,
        StripeClient $stripe,
        string $priceId,
        float $minimumMonthly,
        array $scaleConfig,
        string $startOfPrev,
        string $endOfPrev,
        Carbon $fxDate,
        FxRateService $fxRateService,
    ): void {
        if ($workspace->stripe_id === null) {
            Log::warning('ReportMonthlyRevenueToStripeJob: workspace has no stripe_id, skipping', [
                'workspace_id' => $workspace->id,
            ]);
            return;
        }

        app(WorkspaceContext::class)->set($workspace->id);

        $billableInReportingCurrency = $this->queryBillableAmount($workspace, $startOfPrev, $endOfPrev);

        // Convert to EUR.
        $billableEur = $this->convertToEur(
            $billableInReportingCurrency,
            $workspace->reporting_currency,
            $fxDate,
            $fxRateService,
            $workspace->id,
        );

        if ($billableEur === null) {
            return;
        }

        // Apply rate: 1% GMV for ecom, 2% ad spend for non-ecom.
        $rate        = $workspace->has_store
            ? (float) ($scaleConfig['gmv_rate'] ?? 0.01)
            : (float) ($scaleConfig['ad_spend_rate'] ?? 0.02);
        $calculated  = $billableEur * $rate * 100; // cents at 1× (Stripe quantity = EUR cents of usage)

        // Apply €149 floor: reportable = max(billableEur, 149) for usage quantity.
        $reportableEur = max($billableEur, $minimumMonthly);

        $subscriptionItem = $this->findSubscriptionItem($workspace, $priceId, $stripe);

        if ($subscriptionItem === null) {
            Log::warning('ReportMonthlyRevenueToStripeJob: no active Scale subscription item found, skipping', [
                'workspace_id' => $workspace->id,
                'price_id'     => $priceId,
            ]);
            return;
        }

        // Report usage to Stripe. Quantity = reportable revenue in EUR cents
        // (Stripe multiplies by the per-unit price configured on the metered price).
        $quantityCents = (int) round($reportableEur * 100);

        try {
            $stripe->subscriptionItems->createUsageRecord($subscriptionItem, [
                'quantity'  => $quantityCents,
                'timestamp' => now()->timestamp,
                'action'    => 'set',
            ]);

            Log::info('ReportMonthlyRevenueToStripeJob: Scale usage reported', [
                'workspace_id'               => $workspace->id,
                'billing_basis'              => $workspace->has_store ? 'gmv' : 'ad_spend',
                'billable_reporting_currency' => $billableInReportingCurrency,
                'reporting_currency'          => $workspace->reporting_currency,
                'billable_eur'               => $billableEur,
                'rate'                       => $rate,
                'calculated_eur'             => $billableEur * $rate,
                'floor_applied'              => $billableEur < $minimumMonthly,
                'reportable_eur'             => $reportableEur,
                'quantity_cents'             => $quantityCents,
            ]);
        } catch (\Stripe\Exception\ApiErrorException $e) {
            Log::error('ReportMonthlyRevenueToStripeJob: Stripe API error', [
                'workspace_id' => $workspace->id,
                'error'        => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Query the billable amount for the given period based on billing basis.
     *
     * has_store = true  → GMV from daily_snapshots.revenue
     * has_store = false → ad spend from ad_insights at campaign level
     *
     * Why campaign level: PLANNING.md says "never SUM across levels" to avoid
     * double-counting. Campaign level gives the correct workspace spend total.
     */
    private function queryBillableAmount(Workspace $workspace, string $from, string $to): float
    {
        if ($workspace->has_store) {
            return (float) \App\Models\DailySnapshot::query()
                ->whereBetween('date', [$from, $to])
                ->select(DB::raw('COALESCE(SUM(revenue), 0) AS total'))
                ->value('total');
        }

        // Non-ecom: total ad spend from campaign-level insights.
        return (float) DB::table('ad_insights')
            ->join('ad_accounts', 'ad_insights.ad_account_id', '=', 'ad_accounts.id')
            ->where('ad_accounts.workspace_id', $workspace->id)
            ->where('ad_insights.workspace_id', $workspace->id)
            ->where('ad_insights.level', 'campaign')
            ->whereBetween('ad_insights.date', [$from, $to])
            ->select(DB::raw('COALESCE(SUM(ad_insights.spend_in_reporting_currency), 0) AS total'))
            ->value('total');
    }

    /**
     * Resolve billing tier name from a billable amount (in reporting currency).
     * Returns the flat tier key ('starter'/'growth') or 'scale' for the metered tier.
     *
     * @param array<string, array{revenue_limit: int}> $flatPlans
     */
    private function resolveTierFromRevenue(float $revenue, array $flatPlans): string
    {
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
     * Returns true if the subscription's current price is an annual price ID.
     *
     * @param array<string, array{price_id_annual: string}> $flatPlans
     */
    private function isAnnualSubscription(\Laravel\Cashier\Subscription $subscription, array $flatPlans): bool
    {
        $currentPriceId = $subscription->stripe_price;

        foreach ($flatPlans as $config) {
            if (($config['price_id_annual'] ?? null) === $currentPriceId) {
                return true;
            }
        }

        return false;
    }

    /**
     * Returns true if moving from $currentTier to $newTier is a downgrade.
     *
     * @param array<string, array{revenue_limit: int}> $flatPlans
     */
    private function isTierDowngrade(?string $currentTier, string $newTier, array $flatPlans): bool
    {
        if ($currentTier === 'scale') {
            // scale → flat is always a downgrade
            return $newTier !== 'scale';
        }

        if ($newTier === 'scale') {
            return false; // flat → scale is an upgrade
        }

        $currentLimit = (float) ($flatPlans[$currentTier]['revenue_limit'] ?? 0);
        $newLimit     = (float) ($flatPlans[$newTier]['revenue_limit'] ?? 0);

        return $newLimit < $currentLimit;
    }

    private function convertToEur(
        float $amount,
        string $reportingCurrency,
        Carbon $fxDate,
        FxRateService $fxRateService,
        int $workspaceId,
    ): ?float {
        if ($reportingCurrency === 'EUR') {
            return $amount;
        }

        try {
            return $fxRateService->convert($amount, $reportingCurrency, 'EUR', $fxDate);
        } catch (FxRateNotFoundException $e) {
            Log::warning('ReportMonthlyRevenueToStripeJob: FX rate unavailable, skipping workspace', [
                'workspace_id'       => $workspaceId,
                'reporting_currency' => $reportingCurrency,
                'fx_date'            => $fxDate->toDateString(),
            ]);
            return null;
        }
    }

    /**
     * Find the Stripe subscription item ID for the Scale price on this workspace.
     */
    private function findSubscriptionItem(Workspace $workspace, string $priceId, StripeClient $stripe): ?string
    {
        $localItem = \App\Models\SubscriptionItem::query()
            ->whereHas('subscription', function ($q) use ($workspace): void {
                $q->where('billable_type', Workspace::class)
                  ->where('billable_id', $workspace->id)
                  ->whereIn('stripe_status', ['active', 'trialing', 'past_due']);
            })
            ->where('stripe_price', $priceId)
            ->select(['stripe_id'])
            ->first();

        return $localItem?->stripe_id;
    }
}
