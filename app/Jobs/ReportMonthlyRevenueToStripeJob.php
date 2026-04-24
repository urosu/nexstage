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
 * Runs on the 1st of each month at 06:00 UTC.
 *
 * Reports previous-month GMV (or ad spend for non-ecom workspaces) to Stripe
 * for metered billing on the standard plan: €39/mo minimum + 0.4% of GMV.
 *
 * Stripe setup assumed:
 *   - One metered price at €0.004 per EUR of GMV (config billing.plan.price_id).
 *   - Usage quantity = max(gmv_eur, floor_quantity) where floor enforces the €39 minimum.
 *     floor_quantity = minimum_monthly / gmv_rate = 39 / 0.004 = 9 750 EUR.
 *   - Stripe charges: quantity × €0.004/unit → min charge = €39, scales at 0.4% above that.
 *
 * Billing basis auto-derivation (PLANNING.md §9):
 *   - has_store = true  → GMV from daily_snapshots.revenue
 *   - has_store = false → ad spend from ad_insights at campaign level (no double-counting)
 *   - Both → GMV only
 *
 * Enterprise workspaces (billing_plan = 'enterprise') are skipped — invoiced manually.
 * Workspaces without a stripe_id or active subscription item are skipped with a warning.
 *
 * Queue:   low
 * Timeout: 300 s
 * Tries:   5
 * Backoff: [60, 300, 900, 1800, 3600] s
 *
 * @see PLANNING.md §9 Pricing
 * @see config/billing.php
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
        $planConfig = config('billing.plan');
        $priceId    = $planConfig['price_id'] ?? null;

        if ($priceId === null) {
            Log::warning('ReportMonthlyRevenueToStripeJob: STRIPE_PRICE_ID not configured, skipping.');
            return;
        }

        $now           = now();
        $previousMonth = $now->copy()->subMonth();
        $startOfPrev   = $previousMonth->copy()->startOfMonth()->toDateString();
        $endOfPrev     = $previousMonth->copy()->endOfMonth()->toDateString();
        $fxDate        = Carbon::parse($endOfPrev);

        $gmvRate        = (float) ($planConfig['gmv_rate'] ?? 0.004);
        $minimumMonthly = (float) ($planConfig['minimum_monthly'] ?? 39);
        // Minimum GMV (EUR) that produces the floor charge when multiplied by gmv_rate.
        $floorQuantity  = $minimumMonthly / $gmvRate;

        $workspaces = Workspace::withoutGlobalScopes()
            ->where('billing_plan', 'standard')
            ->whereNull('deleted_at')
            ->whereNotNull('stripe_id')
            ->select(['id', 'reporting_currency', 'stripe_id', 'has_store'])
            ->get();

        Log::info('ReportMonthlyRevenueToStripeJob: reporting standard plan usage', [
            'count'  => $workspaces->count(),
            'period' => "{$startOfPrev} → {$endOfPrev}",
        ]);

        $stripe = new StripeClient(config('cashier.secret'));

        foreach ($workspaces as $workspace) {
            $this->reportUsage(
                $workspace,
                $stripe,
                $priceId,
                $floorQuantity,
                $startOfPrev,
                $endOfPrev,
                $fxDate,
                $fxRateService,
            );
        }

        Log::info('ReportMonthlyRevenueToStripeJob: completed');
    }

    /**
     * Report previous-month usage to Stripe for one workspace.
     *
     * quantity = max(gmv_eur, floor_quantity)
     * Stripe bill = quantity × €0.004/unit → minimum charge = €39.
     */
    private function reportUsage(
        Workspace $workspace,
        StripeClient $stripe,
        string $priceId,
        float $floorQuantity,
        string $startOfPrev,
        string $endOfPrev,
        Carbon $fxDate,
        FxRateService $fxRateService,
    ): void {
        app(WorkspaceContext::class)->set($workspace->id);

        $billableInReportingCurrency = $this->queryBillableAmount($workspace, $startOfPrev, $endOfPrev);

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

        // Never report below floor_quantity so Stripe always charges at least €39.
        $quantity = (int) round(max($billableEur, $floorQuantity));

        $subscriptionItem = $this->findSubscriptionItem($workspace, $priceId, $stripe);

        if ($subscriptionItem === null) {
            Log::warning('ReportMonthlyRevenueToStripeJob: no active subscription item found, skipping', [
                'workspace_id' => $workspace->id,
                'price_id'     => $priceId,
            ]);
            return;
        }

        try {
            $stripe->subscriptionItems->createUsageRecord($subscriptionItem, [
                'quantity'  => $quantity,
                'timestamp' => now()->timestamp,
                'action'    => 'set',
            ]);

            Log::info('ReportMonthlyRevenueToStripeJob: usage reported', [
                'workspace_id'                => $workspace->id,
                'billing_basis'               => $workspace->has_store ? 'gmv' : 'ad_spend',
                'billable_reporting_currency'  => $billableInReportingCurrency,
                'reporting_currency'           => $workspace->reporting_currency,
                'billable_eur'                => $billableEur,
                'floor_applied'               => $billableEur < $floorQuantity,
                'reported_quantity'           => $quantity,
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
     * Query the billable amount for the given period.
     *
     * has_store = true  → GMV from daily_snapshots.revenue
     * has_store = false → ad spend from ad_insights at campaign level
     *
     * Campaign-level-only filter avoids double-counting (never SUM across levels).
     */
    private function queryBillableAmount(Workspace $workspace, string $from, string $to): float
    {
        if ($workspace->has_store) {
            return (float) \App\Models\DailySnapshot::query()
                ->whereBetween('date', [$from, $to])
                ->select(DB::raw('COALESCE(SUM(revenue), 0) AS total'))
                ->value('total');
        }

        return (float) DB::table('ad_insights')
            ->join('ad_accounts', 'ad_insights.ad_account_id', '=', 'ad_accounts.id')
            ->where('ad_accounts.workspace_id', $workspace->id)
            ->where('ad_insights.workspace_id', $workspace->id)
            ->where('ad_insights.level', 'campaign')
            ->whereNull('ad_insights.hour')
            ->whereBetween('ad_insights.date', [$from, $to])
            ->select(DB::raw('COALESCE(SUM(ad_insights.spend_in_reporting_currency), 0) AS total'))
            ->value('total');
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
        } catch (FxRateNotFoundException) {
            Log::warning('ReportMonthlyRevenueToStripeJob: FX rate unavailable, skipping workspace', [
                'workspace_id'       => $workspaceId,
                'reporting_currency' => $reportingCurrency,
                'fx_date'            => $fxDate->toDateString(),
            ]);
            return null;
        }
    }

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
