<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Exceptions\FxRateNotFoundException;
use App\Models\DailySnapshot;
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
 * 1. Tier recalculation for flat-plan workspaces (starter/growth/scale):
 *    Calculates previous month revenue, resolves the correct tier, and calls
 *    $subscription->swap() if the tier has changed. Annual subscriptions are
 *    only upgraded mid-year, never downgraded. billing_plan is updated via
 *    the WebhookReceived listener after Stripe confirms the swap.
 *
 * 2. % tier revenue reporting:
 *    Reports previous-month revenue to Stripe for metered billing. Always in
 *    EUR. Floor: €149/month. Converts via fx_rates for last day of prev month.
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

        // ── 1. Flat-tier recalculation ────────────────────────────────────────
        $flatPlans = config('billing.flat_plans', []);

        if (! empty($flatPlans)) {
            $flatWorkspaces = Workspace::withoutGlobalScopes()
                ->whereIn('billing_plan', array_keys($flatPlans))
                ->whereNull('deleted_at')
                ->whereNotNull('stripe_id')
                ->select(['id', 'billing_plan', 'reporting_currency', 'stripe_id'])
                ->get();

            Log::info('ReportMonthlyRevenueToStripeJob: recalculating flat tiers', ['count' => $flatWorkspaces->count()]);

            foreach ($flatWorkspaces as $workspace) {
                $this->recalculateFlatTier($workspace, $flatPlans, $startOfPrev, $endOfPrev);
            }
        }

        // ── 2. Percentage-tier usage reporting ───────────────────────────────
        $percentageConfig = config('billing.percentage_plan');
        $priceId          = $percentageConfig['price_id'] ?? null;
        $minimumMonthly   = (float) ($percentageConfig['minimum_monthly'] ?? 149);

        if ($priceId === null) {
            Log::warning('ReportMonthlyRevenueToStripeJob: STRIPE_PRICE_PERCENTAGE not configured, skipping % reporting.');
            return;
        }

        $percentageWorkspaces = Workspace::withoutGlobalScopes()
            ->where('billing_plan', 'percentage')
            ->whereNull('deleted_at')
            ->select(['id', 'reporting_currency', 'stripe_id'])
            ->get();

        Log::info('ReportMonthlyRevenueToStripeJob: reporting % usage', ['count' => $percentageWorkspaces->count()]);

        $stripe = new StripeClient(config('cashier.secret'));

        foreach ($percentageWorkspaces as $workspace) {
            $this->reportForWorkspace(
                $workspace,
                $stripe,
                $priceId,
                $minimumMonthly,
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
     * month's revenue. Swaps the Stripe subscription if the tier has changed.
     * Annual subscriptions are only upgraded, never downgraded mid-year.
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

        $revenue = (float) DailySnapshot::query()
            ->whereBetween('date', [$startOfPrev, $endOfPrev])
            ->select(DB::raw('COALESCE(SUM(revenue), 0) AS total'))
            ->value('total');

        $correctTier = $this->resolveTierFromRevenue($revenue, $flatPlans);

        if ($correctTier === $workspace->billing_plan) {
            return;
        }

        $subscription = $workspace->subscription('default');

        if ($subscription === null || ! $subscription->valid()) {
            return;
        }

        $isAnnual   = $this->isAnnualSubscription($subscription, $flatPlans);
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

        $newPriceId = $isAnnual
            ? ($correctTier === 'percentage' ? config('billing.percentage_plan.price_id') : ($flatPlans[$correctTier]['price_id_annual'] ?? null))
            : ($correctTier === 'percentage' ? config('billing.percentage_plan.price_id') : ($flatPlans[$correctTier]['price_id_monthly'] ?? null));

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
                'workspace_id' => $workspace->id,
                'from_tier'    => $workspace->billing_plan,
                'to_tier'      => $correctTier,
                'revenue'      => $revenue,
                'annual'       => $isAnnual,
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
     * Resolve billing tier from revenue amount.
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

        return 'percentage';
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
     * Returns true if moving from $currentTier to $newTier is a downgrade
     * (lower revenue tier or from percentage back to flat).
     *
     * @param array<string, array{revenue_limit: int}> $flatPlans
     */
    private function isTierDowngrade(?string $currentTier, string $newTier, array $flatPlans): bool
    {
        if ($currentTier === 'percentage') {
            // percentage → flat is always a downgrade
            return $newTier !== 'percentage';
        }

        if ($newTier === 'percentage') {
            return false; // flat → percentage is an upgrade
        }

        $currentLimit = (float) ($flatPlans[$currentTier]['revenue_limit'] ?? 0);
        $newLimit     = (float) ($flatPlans[$newTier]['revenue_limit'] ?? 0);

        return $newLimit < $currentLimit;
    }

    private function reportForWorkspace(
        Workspace $workspace,
        StripeClient $stripe,
        string $priceId,
        float $minimumMonthly,
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

        // Set WorkspaceContext so WorkspaceScope is satisfied on DailySnapshot.
        app(WorkspaceContext::class)->set($workspace->id);

        $revenueInReportingCurrency = (float) DailySnapshot::query()
            ->whereBetween('date', [$startOfPrev, $endOfPrev])
            ->select(DB::raw('COALESCE(SUM(revenue), 0) AS total'))
            ->value('total');

        // Convert to EUR if needed.
        $revenueEur = $this->convertToEur(
            $revenueInReportingCurrency,
            $workspace->reporting_currency,
            $fxDate,
            $fxRateService,
            $workspace->id,
        );

        if ($revenueEur === null) {
            // FX conversion failed — logged inside convertToEur; skip this workspace.
            return;
        }

        // Apply €149 floor.
        $reportableRevenue = max($revenueEur, $minimumMonthly);

        // Find the active subscription item for the percentage price.
        $subscriptionItem = $this->findSubscriptionItem($workspace, $priceId, $stripe);

        if ($subscriptionItem === null) {
            Log::warning('ReportMonthlyRevenueToStripeJob: no active subscription item found, skipping', [
                'workspace_id' => $workspace->id,
                'price_id'     => $priceId,
            ]);
            return;
        }

        // Report usage to Stripe. Revenue in EUR cents × rate (1% = €0.01 per €1).
        // Stripe metered billing quantity = revenue in EUR (integer cents).
        $quantityCents = (int) round($reportableRevenue * 100);

        try {
            $stripe->subscriptionItems->createUsageRecord($subscriptionItem, [
                'quantity'  => $quantityCents,
                'timestamp' => now()->timestamp,
                'action'    => 'set',
            ]);

            Log::info('ReportMonthlyRevenueToStripeJob: usage reported', [
                'workspace_id'              => $workspace->id,
                'revenue_reporting_currency' => $revenueInReportingCurrency,
                'reporting_currency'         => $workspace->reporting_currency,
                'revenue_eur'               => $revenueEur,
                'floor_applied'             => $revenueEur < $minimumMonthly,
                'reportable_revenue_eur'    => $reportableRevenue,
                'quantity_cents'            => $quantityCents,
            ]);
        } catch (\Stripe\Exception\ApiErrorException $e) {
            Log::error('ReportMonthlyRevenueToStripeJob: Stripe API error', [
                'workspace_id' => $workspace->id,
                'error'        => $e->getMessage(),
            ]);
            throw $e;
        }
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
     * Find the Stripe subscription item ID for the percentage price on this workspace.
     * Returns the item ID string, or null if not found.
     */
    private function findSubscriptionItem(Workspace $workspace, string $priceId, StripeClient $stripe): ?string
    {
        // Use the local subscription_items table first (faster, avoids Stripe API call).
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
