<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Builds the data payload for the monthly PDF report.
 *
 * Reads from:
 *   - workspaces                         (name, reporting_currency)
 *   - daily_snapshots                    (revenue, orders, customers)
 *   - ad_insights (campaign level only)  (spend, platform ROAS)
 *   - orders + order_items               (contribution margin when COGS configured)
 *   - products                           (top product names for the top-5 list)
 *
 * Called by:
 *   - GenerateMonthlyReportJob  (scheduled 1st of month)
 *   - InsightsController::downloadMonthlyReport  (on-demand)
 *
 * Returns a plain array consumed by resources/views/reports/monthly.blade.php.
 * Never touches WorkspaceScope — queries are workspace_id-filtered explicitly
 * so the same code works from jobs and controllers.
 *
 * @see PLANNING.md section 12 (Monthly PDF reports)
 */
class MonthlyReportService
{
    /**
     * @return array<string, mixed>
     */
    public function build(int $workspaceId, CarbonImmutable $month): array
    {
        $workspace = Workspace::withoutGlobalScopes()->findOrFail($workspaceId);

        $start = $month->startOfMonth();
        $end = $month->endOfMonth();
        $from = $start->toDateString();
        $to = $end->toDateString();

        // ── Hero: revenue, orders, AOV, items, new vs returning ────────────
        $snapshotTotals = DB::table('daily_snapshots')
            ->where('workspace_id', $workspaceId)
            ->whereBetween('date', [$from, $to])
            ->selectRaw('
                COALESCE(SUM(revenue), 0)             AS revenue,
                COALESCE(SUM(orders_count), 0)        AS orders,
                COALESCE(SUM(items_sold), 0)          AS items,
                COALESCE(SUM(new_customers), 0)       AS new_customers,
                COALESCE(SUM(returning_customers), 0) AS returning_customers
            ')
            ->first();

        $revenue = (float) ($snapshotTotals->revenue ?? 0);
        $orders = (int) ($snapshotTotals->orders ?? 0);
        $aov = $orders > 0 ? $revenue / $orders : null;

        // ── Ad spend + platform ROAS (campaign level only — never SUM across levels) ──
        $adTotals = DB::table('ad_insights')
            ->where('workspace_id', $workspaceId)
            ->where('level', 'campaign')
            ->whereBetween('date', [$from, $to])
            ->selectRaw('
                COALESCE(SUM(spend_in_reporting_currency), 0)   AS spend,
                COALESCE(SUM(platform_conversions_value), 0)    AS platform_revenue
            ')
            ->first();

        $adSpend = (float) ($adTotals->spend ?? 0);
        $platformRev = (float) ($adTotals->platform_revenue ?? 0);
        $platformRoas = $adSpend > 0 ? $platformRev / $adSpend : null;
        $realRoas = $adSpend > 0 ? $revenue / $adSpend : null;

        // ── Contribution margin (only when COGS configured for this workspace) ──
        // Why: the report header switches between a gross-margin row and a
        // "COGS not configured" note; callers need to know which applies.
        $cogsRow = DB::table('order_items as oi')
            ->join('orders as o', 'o.id', '=', 'oi.order_id')
            ->where('o.workspace_id', $workspaceId)
            ->whereBetween('o.occurred_at', [$start, $end])
            ->whereNotNull('oi.unit_cost')
            ->selectRaw('COALESCE(SUM(oi.unit_cost * oi.quantity), 0) AS cogs')
            ->first();

        $totalCogs = (float) ($cogsRow->cogs ?? 0);
        $hasCogs = $totalCogs > 0;

        $contributionMargin = $hasCogs ? $revenue - $totalCogs : null;
        $marginPct = ($hasCogs && $revenue > 0)
            ? round(($contributionMargin / $revenue) * 100, 1)
            : null;

        // ── Top 5 products by revenue (fall back to revenue ranking — stable when COGS off) ──
        $topProducts = DB::table('order_items as oi')
            ->join('orders as o', 'o.id', '=', 'oi.order_id')
            ->where('o.workspace_id', $workspaceId)
            ->whereBetween('o.occurred_at', [$start, $end])
            ->selectRaw('
                oi.product_external_id,
                MAX(oi.product_name) AS product_name,
                SUM(oi.quantity)     AS units,
                SUM(oi.line_total)   AS revenue,
                SUM(CASE WHEN oi.unit_cost IS NOT NULL THEN oi.unit_cost * oi.quantity ELSE NULL END) AS cogs
            ')
            ->groupBy('oi.product_external_id')
            ->orderByDesc('revenue')
            ->limit(5)
            ->get()
            ->map(static function (object $r): array {
                $revenue = (float) $r->revenue;
                $cogs = $r->cogs !== null ? (float) $r->cogs : null;
                $margin = $cogs !== null ? $revenue - $cogs : null;

                return [
                    'name' => (string) $r->product_name,
                    'units' => (int) $r->units,
                    'revenue' => $revenue,
                    'margin' => $margin,
                ];
            })
            ->all();

        return [
            'workspace_name' => $workspace->name,
            'reporting_currency' => $workspace->reporting_currency,
            'month_label' => $start->format('F Y'),
            'period' => [
                'from' => $from,
                'to' => $to,
            ],
            'hero' => [
                'revenue' => $revenue,
                'orders' => $orders,
                'aov' => $aov,
                'items_sold' => (int) ($snapshotTotals->items ?? 0),
                'new_customers' => (int) ($snapshotTotals->new_customers ?? 0),
                'returning_customers' => (int) ($snapshotTotals->returning_customers ?? 0),
            ],
            'ads' => [
                'spend' => $adSpend,
                'real_roas' => $realRoas,
                'platform_roas' => $platformRoas,
            ],
            'cogs' => [
                'configured' => $hasCogs,
                'total_cogs' => $hasCogs ? $totalCogs : null,
                'contribution_margin' => $contributionMargin,
                'margin_pct' => $marginPct,
            ],
            'top_products' => $topProducts,
            'generated_at' => now()->toISOString(),
        ];
    }
}
