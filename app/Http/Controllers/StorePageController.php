<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Store;
use App\Services\NarrativeTemplateService;
use App\Services\WorkspaceContext;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * Unified Store destination — Phase 3.2.
 *
 * Consolidates /analytics/products, /analytics/daily, /countries into one
 * 5-tab page (/store?tab=products|customers|cohorts|countries|orders).
 * Adds new Customers (RFM + LTV) and Cohorts (M0–M11 retention) tabs.
 *
 * Reads: orders, order_items, refunds, daily_snapshots, daily_snapshot_products,
 *        ad_insights, gsc_daily_stats, products, daily_notes.
 * Writes: nothing (read-only analytics surface).
 *
 * @see PROGRESS.md Phase 3.2
 */
class StorePageController extends Controller
{
    public function __construct(
        private readonly NarrativeTemplateService $narrative,
    ) {}

    public function __invoke(Request $request): InertiaResponse
    {
        $workspaceId = app(WorkspaceContext::class)->id();

        $validated = $request->validate([
            'tab'        => ['sometimes', 'nullable', 'string'],
            'from'       => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'to'         => ['sometimes', 'nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'store_ids'  => ['sometimes', 'nullable', 'string'],
        ]);

        $validTabs = ['products', 'customers', 'cohorts', 'countries', 'orders'];
        $tab       = in_array($validated['tab'] ?? '', $validTabs, true) ? $validated['tab'] : 'products';
        $from     = $validated['from'] ?? now()->subDays(29)->toDateString();
        $to       = $validated['to'] ?? now()->toDateString();
        $storeIds = $this->parseStoreIds($validated['store_ids'] ?? '', $workspaceId);

        $common = compact('tab', 'from', 'to', 'storeIds');

        return match ($tab) {
            'customers' => $this->renderCustomers($request, $workspaceId, $common),
            'cohorts'   => $this->renderCohorts($request, $workspaceId, $common),
            'countries' => $this->renderCountries($request, $workspaceId, $common),
            'orders'    => $this->renderOrders($request, $workspaceId, $common),
            default     => $this->renderProducts($request, $workspaceId, $common),
        };
    }

    // =========================================================================
    // Tab 1 — Products
    // =========================================================================

    private function renderProducts(Request $request, int $workspaceId, array $common): InertiaResponse
    {
        ['from' => $from, 'to' => $to, 'storeIds' => $storeIds] = $common;

        $extra = $request->validate([
            'sort_by'    => ['sometimes', 'nullable', 'in:revenue,units,contribution_margin,margin_pct,ad_spend,discount_pct,refund_rate,days_of_cover'],
            'sort_dir'   => ['sometimes', 'nullable', 'in:asc,desc'],
            'filter'     => ['sometimes', 'nullable', 'in:all,winners,losers,in_stock,unprofitable,slow_movers,stockout_risk,returns_over_10'],
            'classifier' => ['sometimes', 'nullable', 'in:peer,period'],
            'view'       => ['sometimes', 'nullable', 'in:table,scatter'],
        ]);

        $filter     = $extra['filter']     ?? 'all';
        $classifier = $extra['classifier'] ?? null;
        $view       = $extra['view']       ?? 'table';

        $storeClause    = $this->storeClause($storeIds, 'dsp');
        $storeClauseRaw = $this->storeClause($storeIds, 'o');

        // ── Main product query ───────────────────────────────────────────────
        $rows = DB::select("
            WITH current_p AS (
                SELECT
                    dsp.product_external_id,
                    MAX(dsp.product_name)    AS name,
                    SUM(dsp.units)::int      AS units,
                    SUM(dsp.revenue)         AS revenue
                FROM daily_snapshot_products dsp
                WHERE dsp.workspace_id = ?
                  AND dsp.snapshot_date BETWEEN ? AND ?
                  {$storeClause}
                GROUP BY dsp.product_external_id
            ),
            cogs AS (
                SELECT
                    oi.product_external_id,
                    SUM(COALESCE(oi.unit_cost, pc_lookup.unit_cost) * oi.quantity) AS total_cogs
                FROM order_items oi
                JOIN orders o ON o.id = oi.order_id
                LEFT JOIN LATERAL (
                    SELECT pc.unit_cost
                    FROM product_costs pc
                    WHERE pc.workspace_id = o.workspace_id
                      AND pc.store_id = o.store_id
                      AND pc.product_external_id = oi.product_external_id
                      AND (pc.effective_from IS NULL OR pc.effective_from <= o.occurred_at::date)
                      AND (pc.effective_to   IS NULL OR pc.effective_to   >= o.occurred_at::date)
                    ORDER BY pc.effective_from DESC
                    LIMIT 1
                ) pc_lookup ON TRUE
                WHERE o.workspace_id = ?
                  AND o.status IN ('completed', 'processing')
                  AND o.occurred_at BETWEEN ? AND ?
                  AND (oi.unit_cost IS NOT NULL OR pc_lookup.unit_cost IS NOT NULL)
                  {$storeClauseRaw}
                GROUP BY oi.product_external_id
            ),
            discounts AS (
                SELECT
                    oi.product_external_id,
                    CASE WHEN SUM(oi.line_total + COALESCE(oi.discount_amount, 0)) > 0
                         THEN SUM(COALESCE(oi.discount_amount, 0))
                              / SUM(oi.line_total + COALESCE(oi.discount_amount, 0))
                         ELSE NULL END AS discount_pct
                FROM order_items oi
                JOIN orders o ON o.id = oi.order_id
                WHERE o.workspace_id = ?
                  AND o.status IN ('completed', 'processing')
                  AND o.occurred_at BETWEEN ? AND ?
                  {$storeClauseRaw}
                GROUP BY oi.product_external_id
            ),
            refund_stats AS (
                SELECT
                    oi.product_external_id,
                    COUNT(DISTINCT oi.order_id)   AS total_orders,
                    COUNT(DISTINCT ref.order_id)  AS refunded_orders
                FROM order_items oi
                JOIN orders o ON o.id = oi.order_id
                LEFT JOIN refunds ref ON ref.order_id = oi.order_id
                WHERE o.workspace_id = ?
                  AND o.status IN ('completed', 'processing')
                  AND o.occurred_at BETWEEN ? AND ?
                  {$storeClauseRaw}
                GROUP BY oi.product_external_id
            )
            SELECT
                c.product_external_id AS external_id,
                c.name,
                c.units,
                c.revenue,
                cg.total_cogs,
                CASE WHEN cg.total_cogs IS NOT NULL
                     THEN c.revenue - cg.total_cogs
                     ELSE NULL END AS contribution_margin,
                CASE WHEN cg.total_cogs IS NOT NULL AND c.revenue > 0
                     THEN ROUND(((c.revenue - cg.total_cogs) / NULLIF(c.revenue, 0) * 100)::numeric, 1)
                     ELSE NULL END AS margin_pct,
                d.discount_pct,
                CASE WHEN rs.total_orders > 0
                     THEN ROUND((rs.refunded_orders::numeric / rs.total_orders), 4)
                     ELSE NULL END AS refund_rate
            FROM current_p c
            LEFT JOIN cogs       cg ON cg.product_external_id = c.product_external_id
            LEFT JOIN discounts  d  ON d.product_external_id  = c.product_external_id
            LEFT JOIN refund_stats rs ON rs.product_external_id = c.product_external_id
            ORDER BY c.revenue DESC NULLS LAST
            LIMIT 100
        ", [
            $workspaceId, $from, $to,
            $workspaceId, $from . ' 00:00:00', $to . ' 23:59:59',
            $workspaceId, $from . ' 00:00:00', $to . ' 23:59:59',
            $workspaceId, $from . ' 00:00:00', $to . ' 23:59:59',
        ]);

        $externalIds = array_column($rows, 'external_id');

        // ── Product images + stock ───────────────────────────────────────────
        $productInfo = $this->fetchProductInfo($externalIds, $workspaceId, $storeIds);

        // ── 14-day trend dots ────────────────────────────────────────────────
        [$trendDays, $trendMap] = $this->fetchTrendDots($workspaceId, $to, $storeClause);

        // ── 30-day velocity for days-of-cover (§F22) ────────────────────────
        $docFrom = Carbon::parse($to)->subDays(29)->toDateString();
        $docRows = DB::select("
            SELECT product_external_id, SUM(units) AS units_30d
            FROM daily_snapshot_products
            WHERE workspace_id = ?
              AND snapshot_date BETWEEN ? AND ?
              {$storeClause}
            GROUP BY product_external_id
        ", [$workspaceId, $docFrom, $to]);
        $docMap = [];
        foreach ($docRows as $dr) {
            $docMap[$dr->product_external_id] = (int) $dr->units_30d;
        }

        // ── Ad spend attributed (§F24) ───────────────────────────────────────
        $adSpendMap = $this->computeAttributedAdSpend($workspaceId, $from, $to, $storeClauseRaw);

        // ── CVR proxy (§F27): organic orders / GSC clicks for landing pages ──
        $cvrMap = $this->computeProductCvr($workspaceId, $from, $to, $storeClauseRaw);

        // ── Assemble ─────────────────────────────────────────────────────────
        $hasCogs = false;
        $products = array_map(function (object $r) use (
            $from, $to, $productInfo, $trendMap, $trendDays, $docMap,
            $adSpendMap, $cvrMap, &$hasCogs,
        ): array {
            $info        = $productInfo[$r->external_id] ?? null;
            $stockQty    = $info?->stock_quantity !== null ? (int) $info->stock_quantity : null;
            $margin      = $r->contribution_margin !== null ? (float) $r->contribution_margin : null;
            $marginPct   = $r->margin_pct !== null ? (float) $r->margin_pct : null;

            if ($margin !== null) {
                $hasCogs = true;
            }

            // Days of cover — 30d rolling (§F22)
            $daysOfCover = null;
            $units30d    = $docMap[$r->external_id] ?? 0;
            if ($stockQty !== null && $units30d > 0) {
                $avgDaily    = $units30d / 30;
                $daysOfCover = (int) round($stockQty / $avgDaily);
            }

            // Slow mover: current rate vs 30d avg
            $rangeDays = max(1, Carbon::parse($to)->diffInDays(Carbon::parse($from)) + 1);
            $avgDailyUnits = $units30d > 0 ? $units30d / 30 : null;
            $currentDailyUnits = (int) $r->units / $rangeDays;
            $isSlowMover = $avgDailyUnits !== null && $avgDailyUnits > 0
                && $currentDailyUnits < ($avgDailyUnits * 0.5);

            $dots = array_map(
                fn (string $day) => $trendMap[$r->external_id][$day] ?? null,
                $trendDays,
            );

            return [
                'external_id'         => $r->external_id,
                'name'                => $r->name,
                'image_url'           => $info?->image_url,
                'sku'                 => $info?->sku,
                'units'               => (int) $r->units,
                'revenue'             => $r->revenue !== null ? round((float) $r->revenue, 2) : null,
                'contribution_margin' => $margin !== null ? round($margin, 2) : null,
                'margin_pct'          => $marginPct,
                'ad_spend'            => $adSpendMap[$r->external_id] ?? null,
                'discount_pct'        => $r->discount_pct !== null ? round((float) $r->discount_pct * 100, 1) : null,
                'refund_rate'         => $r->refund_rate !== null ? round((float) $r->refund_rate * 100, 1) : null,
                'cvr'                 => $cvrMap[$r->external_id] ?? null,
                'stock_status'        => $info?->stock_status,
                'stock_quantity'      => $stockQty,
                'days_of_cover'       => $daysOfCover,
                'is_slow_mover'       => $isSlowMover,
                'trend_dots'          => $dots,
            ];
        }, $rows);

        // ── Sort ─────────────────────────────────────────────────────────────
        $sortBy  = $extra['sort_by'] ?? ($hasCogs ? 'contribution_margin' : 'revenue');
        $sortDir = strtoupper($extra['sort_dir'] ?? 'desc');
        usort($products, function (array $a, array $b) use ($sortBy, $sortDir): int {
            $aVal = $a[$sortBy] ?? null;
            $bVal = $b[$sortBy] ?? null;
            if ($aVal === null && $bVal === null) return 0;
            if ($aVal === null) return 1;
            if ($bVal === null) return -1;
            $cmp = $aVal <=> $bVal;
            return $sortDir === 'ASC' ? $cmp : -$cmp;
        });

        // ── Hero metrics ─────────────────────────────────────────────────────
        $totalUnits   = array_sum(array_column($products, 'units'));
        $totalRevenue = (float) array_sum(array_filter(array_column($products, 'revenue')));
        $withMargin   = array_filter($products, fn ($p) => $p['contribution_margin'] !== null);
        $totalMargin  = count($withMargin) > 0
            ? (float) array_sum(array_column($withMargin, 'contribution_margin'))
            : null;
        $avgMarginPct = ($totalMargin !== null && $totalRevenue > 0)
            ? round(($totalMargin / $totalRevenue) * 100, 1)
            : null;
        $stockoutRiskCount = count(array_filter($products, fn ($p) =>
            $p['days_of_cover'] !== null && $p['days_of_cover'] <= 7
        ));

        // ── Winners/Losers per §F23 ──────────────────────────────────────────
        $effectiveClassifier = in_array($classifier, ['peer', 'period'], true) ? $classifier : 'peer';
        $rankField           = $hasCogs ? 'contribution_margin' : 'revenue';

        $rankable = array_filter($products, fn ($p) =>
            ($p['stock_status'] ?? 'instock') !== 'outofstock'
            && $p[$rankField] !== null
        );
        $peerAvg = count($rankable) > 0
            ? array_sum(array_column($rankable, $rankField)) / count($rankable)
            : null;

        $prevRevenueMap = [];
        if ($effectiveClassifier === 'period') {
            $periodDays = Carbon::parse($from)->diffInDays(Carbon::parse($to));
            $prevTo     = Carbon::parse($from)->subDay()->toDateString();
            $prevFrom   = Carbon::parse($from)->subDays($periodDays + 1)->toDateString();
            $prevRows   = DB::select("
                SELECT product_external_id, SUM(revenue) AS revenue
                FROM daily_snapshot_products
                WHERE workspace_id = ? AND snapshot_date BETWEEN ? AND ?
                {$storeClause}
                GROUP BY product_external_id
            ", [$workspaceId, $prevFrom, $prevTo]);
            foreach ($prevRows as $pr) {
                $prevRevenueMap[$pr->product_external_id] = (float) $pr->revenue;
            }
        }

        $products = array_map(function (array $p) use ($effectiveClassifier, $peerAvg, $rankField, $prevRevenueMap): array {
            if (($p['stock_status'] ?? 'instock') === 'outofstock') {
                return array_merge($p, ['wl_tag' => null]);
            }
            $tag = match ($effectiveClassifier) {
                'peer' => ($peerAvg !== null && $p[$rankField] !== null)
                    ? ($p[$rankField] >= $peerAvg ? 'winner' : 'loser')
                    : null,
                'period' => (isset($prevRevenueMap[$p['external_id']]) && $p['revenue'] !== null)
                    ? ($p['revenue'] >= $prevRevenueMap[$p['external_id']] ? 'winner' : 'loser')
                    : null,
                default => null,
            };
            return array_merge($p, ['wl_tag' => $tag]);
        }, $products);

        // ── Winners/Losers dual-table (top/bottom 5 by CM Δ vs peer) ────────
        $winnerRows = [];
        $loserRows  = [];
        if ($peerAvg !== null && $hasCogs) {
            $deltaRanked = array_filter($rankable, fn ($p) => $p['contribution_margin'] !== null);
            usort($deltaRanked, fn ($a, $b) =>
                ($b['contribution_margin'] - $peerAvg) <=> ($a['contribution_margin'] - $peerAvg)
            );
            $deltaRanked = array_values($deltaRanked);
            $winnerRows  = array_slice($deltaRanked, 0, 5);
            $loserRows   = array_slice(array_reverse($deltaRanked), 0, 5);
        }

        // ── Narrative ────────────────────────────────────────────────────────
        $winnerCount   = count(array_filter($products, fn ($p) => $p['wl_tag'] === 'winner'));
        $loserCount    = count(array_filter($products, fn ($p) => $p['wl_tag'] === 'loser'));
        $pageNarrative = $this->narrative->forProducts($winnerCount, $loserCount, $stockoutRiskCount);

        // ── Apply filter chips ───────────────────────────────────────────────
        $totalProductCount = count($products);
        $products = match ($filter) {
            'winners'         => array_values(array_filter($products, fn ($p) => $p['wl_tag'] === 'winner')),
            'losers'          => array_values(array_filter($products, fn ($p) => $p['wl_tag'] === 'loser')),
            'in_stock'        => array_values(array_filter($products, fn ($p) =>
                                    ($p['stock_status'] ?? 'instock') !== 'outofstock')),
            'unprofitable'    => array_values(array_filter($products, fn ($p) =>
                                    $p['contribution_margin'] !== null && $p['contribution_margin'] < 0)),
            'slow_movers'     => array_values(array_filter($products, fn ($p) => $p['is_slow_mover'] === true)),
            'stockout_risk'   => array_values(array_filter($products, fn ($p) =>
                                    $p['days_of_cover'] !== null && $p['days_of_cover'] <= 7)),
            'returns_over_10' => array_values(array_filter($products, fn ($p) =>
                                    $p['refund_rate'] !== null && $p['refund_rate'] > 10)),
            default           => $products,
        };

        return Inertia::render('Store/Index', array_merge($common, [
            'store_ids'           => $storeIds,
            'sort_by'             => strtolower($sortBy),
            'sort_dir'            => strtolower($sortDir),
            'view'                => $view,
            'filter'              => $filter,
            'classifier'          => $classifier,
            'active_classifier'   => $effectiveClassifier,
            'products'            => $products,
            'products_total_count' => $totalProductCount,
            'has_cogs'            => $hasCogs,
            'winner_rows'         => $winnerRows,
            'loser_rows'          => $loserRows,
            'hero'                => [
                'total_units'       => $totalUnits,
                'total_revenue'     => round($totalRevenue, 2),
                'total_margin'      => $totalMargin !== null ? round($totalMargin, 2) : null,
                'avg_margin_pct'    => $avgMarginPct,
                'stockout_risk_count' => $stockoutRiskCount,
            ],
            'narrative'           => $pageNarrative,
        ]));
    }

    // =========================================================================
    // Tab 2 — Customers
    // =========================================================================

    private function renderCustomers(Request $request, int $workspaceId, array $common): InertiaResponse
    {
        ['from' => $from, 'to' => $to, 'storeIds' => $storeIds] = $common;

        $storeClauseRaw = $this->storeClause($storeIds, 'o');

        // ── North-star metrics ───────────────────────────────────────────────
        // §F28 orders per customer, §F29 LTV, §F30 returning order %
        $metricsRow = DB::selectOne("
            SELECT
                COUNT(*)                                        AS total_orders,
                COUNT(DISTINCT o.customer_email_hash)           AS unique_customers,
                SUM(o.total_in_reporting_currency)              AS total_revenue_range,
                COUNT(CASE WHEN o.is_first_for_customer = false THEN 1 END)  AS returning_orders
            FROM orders o
            WHERE o.workspace_id = ?
              AND o.occurred_at::date BETWEEN ? AND ?
              AND o.status IN ('completed', 'processing')
              AND o.customer_email_hash IS NOT NULL
              {$storeClauseRaw}
        ", [$workspaceId, $from, $to]);

        $ordersPerCustomer = ($metricsRow && $metricsRow->unique_customers > 0)
            ? round($metricsRow->total_orders / $metricsRow->unique_customers, 2)
            : null;
        $returningPct = ($metricsRow && $metricsRow->total_orders > 0)
            ? round($metricsRow->returning_orders / $metricsRow->total_orders * 100, 1)
            : null;

        // All-time LTV (§F29 — all-time, not range-limited)
        $ltvRow = DB::selectOne("
            SELECT
                SUM(o.total_in_reporting_currency)                AS total_revenue,
                COUNT(DISTINCT o.customer_email_hash)             AS unique_customers
            FROM orders o
            WHERE o.workspace_id = ?
              AND o.status IN ('completed', 'processing')
              AND o.customer_email_hash IS NOT NULL
              {$storeClauseRaw}
        ", [$workspaceId]);

        $ltv = ($ltvRow && $ltvRow->unique_customers > 0)
            ? round($ltvRow->total_revenue / $ltvRow->unique_customers, 2)
            : null;

        // ── RFM grid data (§M4) ──────────────────────────────────────────────
        $now  = Carbon::now();
        $rfmRows = DB::select("
            SELECT
                o.customer_email_hash,
                MAX(o.occurred_at)               AS last_order_at,
                COUNT(*)                         AS orders_count,
                SUM(o.total_in_reporting_currency) AS lifetime_revenue
            FROM orders o
            WHERE o.workspace_id = ?
              AND o.status IN ('completed', 'processing')
              AND o.customer_email_hash IS NOT NULL
              {$storeClauseRaw}
            GROUP BY o.customer_email_hash
        ", [$workspaceId]);

        // Compute F+M score (workspace-percentile rank of orders_count × lifetime_revenue)
        $fmScores = array_map(fn ($r) => (float) $r->orders_count * (float) $r->lifetime_revenue, $rfmRows);
        sort($fmScores);
        $fmTotal = count($fmScores);

        // Build 5×5 RFM grid
        $grid = array_fill_keys(range(1, 5), array_fill_keys(range(1, 5), 0));

        foreach ($rfmRows as $r) {
            $daysSinceLast = $now->diffInDays(Carbon::parse($r->last_order_at));

            // Recency bucket (R=5 most recent)
            $r_bucket = match (true) {
                $daysSinceLast <= 30  => 5,
                $daysSinceLast <= 60  => 4,
                $daysSinceLast <= 120 => 3,
                $daysSinceLast <= 240 => 2,
                default               => 1,
            };

            // F+M bucket (percentile rank among all workspace customers)
            $fmValue = (float) $r->orders_count * (float) $r->lifetime_revenue;
            $rank    = $fmTotal > 0 ? array_search($fmValue, $fmScores, true) : 0;
            // Handle ties: use the highest index matching the value
            $rankIdx = (int) $rank;
            while (isset($fmScores[$rankIdx + 1]) && $fmScores[$rankIdx + 1] === $fmValue) {
                $rankIdx++;
            }
            $percentile = $fmTotal > 1 ? ($rankIdx / ($fmTotal - 1)) : 1;
            $fm_bucket  = match (true) {
                $percentile >= 0.80 => 5,
                $percentile >= 0.60 => 4,
                $percentile >= 0.40 => 3,
                $percentile >= 0.20 => 2,
                default             => 1,
            };

            $grid[$fm_bucket][$r_bucket] = ($grid[$fm_bucket][$r_bucket] ?? 0) + 1;
        }

        // Flatten grid to array of cells with segment names (§M4 lookup)
        $segmentNames = [
            5 => [5 => 'Champions', 4 => 'Champions', 3 => 'Loyal', 2 => 'At Risk', 1 => "Can't Lose Them"],
            4 => [5 => 'Loyal', 4 => 'Loyal', 3 => 'Loyal', 2 => 'At Risk', 1 => "Can't Lose Them"],
            3 => [5 => 'Potential Loyalists', 4 => 'Potential Loyalists', 3 => 'Needs Attention', 2 => 'About to Sleep', 1 => 'Hibernating'],
            2 => [5 => 'Promising', 4 => 'Promising', 3 => 'Needs Attention', 2 => 'About to Sleep', 1 => 'Hibernating'],
            1 => [5 => 'New', 4 => 'New', 3 => 'About to Sleep', 2 => 'Hibernating', 1 => 'Hibernating'],
        ];

        $rfmCells = [];
        foreach (range(1, 5) as $fm) {
            foreach (range(1, 5) as $r) {
                $rfmCells[] = [
                    'fm'      => $fm,
                    'r'       => $r,
                    'count'   => $grid[$fm][$r],
                    'segment' => $segmentNames[$fm][$r],
                ];
            }
        }

        // ── New vs Returning — daily series ─────────────────────────────────
        $nvr = DB::select("
            SELECT
                o.occurred_at::date                          AS day,
                COUNT(CASE WHEN o.is_first_for_customer THEN 1 END)  AS new_customers,
                COUNT(CASE WHEN NOT o.is_first_for_customer THEN 1 END) AS returning_customers
            FROM orders o
            WHERE o.workspace_id = ?
              AND o.occurred_at::date BETWEEN ? AND ?
              AND o.status IN ('completed', 'processing')
              AND o.customer_email_hash IS NOT NULL
              {$storeClauseRaw}
            GROUP BY o.occurred_at::date
            ORDER BY day ASC
        ", [$workspaceId, $from, $to]);

        $newVsReturning = array_map(fn ($r) => [
            'day'               => $r->day,
            'new_customers'     => (int) $r->new_customers,
            'returning_customers' => (int) $r->returning_customers,
        ], $nvr);

        $pageNarrative = $this->narrative->forCustomers(
            count(array_filter($rfmCells, fn ($c) => $c['segment'] === 'Champions'
                || $c['segment'] === "Can't Lose Them" || $c['segment'] === 'At Risk'
            )),
            (int) ($metricsRow?->unique_customers ?? 0),
            $returningPct ?? 0,
        );

        return Inertia::render('Store/Index', array_merge($common, [
            'store_ids'         => $storeIds,
            'hero'              => [
                'orders_per_customer' => $ordersPerCustomer,
                'ltv'                 => $ltv,
                'returning_pct'       => $returningPct,
            ],
            'rfm_cells'         => $rfmCells,
            'new_vs_returning'  => $newVsReturning,
            'narrative'         => $pageNarrative,
        ]));
    }

    // =========================================================================
    // Tab 3 — Cohorts
    // =========================================================================

    private function renderCohorts(Request $request, int $workspaceId, array $common): InertiaResponse
    {
        ['from' => $from, 'to' => $to, 'storeIds' => $storeIds] = $common;

        $extra = $request->validate([
            'channel' => ['sometimes', 'nullable', 'string'],
        ]);
        $channelFilter = $extra['channel'] ?? null;

        $storeClauseRaw = $this->storeClause($storeIds, 'o');

        // ── First-purchase month per customer ─────────────────────────────────
        // Only include customers whose first order falls in the last 12 months.
        $cutoff = Carbon::now()->subMonths(12)->startOfMonth()->toDateString();

        // Channel filter goes inside cohort_members where alias is `cf`.
        // Passed as a proper binding to avoid any injection risk.
        $channelClause = $channelFilter ? 'AND cf.first_touch_channel = ?' : '';
        $bindings = [$workspaceId, $cutoff];
        if ($channelFilter) {
            $bindings[] = $channelFilter;
        }
        $bindings[] = $workspaceId;

        $cohortRows = DB::select("
            WITH customer_first AS (
                SELECT
                    o.customer_email_hash,
                    MIN(o.occurred_at)                           AS first_order_at,
                    (o.attribution_first_touch->>'channel_type') AS first_touch_channel
                FROM orders o
                WHERE o.workspace_id = ?
                  AND o.status IN ('completed', 'processing')
                  AND o.customer_email_hash IS NOT NULL
                  {$storeClauseRaw}
                GROUP BY o.customer_email_hash,
                         (o.attribution_first_touch->>'channel_type')
            ),
            cohort_members AS (
                SELECT
                    cf.customer_email_hash,
                    date_trunc('month', cf.first_order_at)::date AS cohort_month,
                    cf.first_order_at
                FROM customer_first cf
                WHERE date_trunc('month', cf.first_order_at)::date >= ?
                  {$channelClause}
            ),
            all_orders AS (
                SELECT
                    o.customer_email_hash,
                    o.total_in_reporting_currency AS revenue,
                    o.occurred_at
                FROM orders o
                WHERE o.workspace_id = ?
                  AND o.status IN ('completed', 'processing')
                  AND o.customer_email_hash IS NOT NULL
                  {$storeClauseRaw}
            )
            SELECT
                cm.cohort_month::text              AS cohort_month,
                COUNT(DISTINCT cm.customer_email_hash) AS initial_customers,
                EXTRACT(YEAR FROM age(date_trunc('month', ao.occurred_at),
                                     cm.cohort_month))::int * 12
                + EXTRACT(MONTH FROM age(date_trunc('month', ao.occurred_at),
                                        cm.cohort_month))::int AS months_since,
                COALESCE(SUM(ao.revenue), 0)       AS revenue
            FROM cohort_members cm
            JOIN all_orders ao ON ao.customer_email_hash = cm.customer_email_hash
                               AND ao.occurred_at >= cm.first_order_at
            GROUP BY cm.cohort_month, months_since
            ORDER BY cm.cohort_month, months_since
        ", $bindings);

        // ── Reshape into row-per-month × M0–M11 ──────────────────────────────
        $cohortData = [];
        foreach ($cohortRows as $r) {
            $month = $r->cohort_month;
            if (! isset($cohortData[$month])) {
                $cohortData[$month] = [
                    'month'             => $month,
                    'initial_customers' => (int) $r->initial_customers,
                    'revenue'           => array_fill(0, 12, null),
                ];
            }
            $m = (int) $r->months_since;
            if ($m >= 0 && $m < 12) {
                $cohortData[$month]['revenue'][$m] = round((float) $r->revenue, 2);
            }
        }

        // Make cumulative
        foreach ($cohortData as &$row) {
            $cumRev = 0.0;
            for ($m = 0; $m < 12; $m++) {
                if ($row['revenue'][$m] !== null) {
                    $cumRev += $row['revenue'][$m];
                    $row['cumulative_revenue'][$m] = round($cumRev, 2);
                } else {
                    $row['cumulative_revenue'][$m] = $m === 0 ? null : null;
                }
            }
        }
        unset($row);

        // Sort cohorts newest-first
        krsort($cohortData);
        $cohortRows = array_values($cohortData);

        // Weighted-average row (Metorik pattern): initial_customers-weighted avg per month
        $weightedAvg = array_fill(0, 12, null);
        $totalCustomers = array_sum(array_column($cohortRows, 'initial_customers'));
        if ($totalCustomers > 0) {
            for ($m = 0; $m < 12; $m++) {
                $weightedSum = 0;
                $weightedN   = 0;
                foreach ($cohortRows as $row) {
                    if (isset($row['cumulative_revenue'][$m]) && $row['cumulative_revenue'][$m] !== null) {
                        $weightedSum += $row['cumulative_revenue'][$m] * $row['initial_customers'];
                        $weightedN   += $row['initial_customers'];
                    }
                }
                if ($weightedN > 0) {
                    $weightedAvg[$m] = round($weightedSum / $weightedN, 2);
                }
            }
        }

        // ── Available first-touch channels ────────────────────────────────────
        $channels = DB::select("
            SELECT DISTINCT (o.attribution_first_touch->>'channel_type') AS channel
            FROM orders o
            WHERE o.workspace_id = ?
              AND o.status IN ('completed', 'processing')
              AND o.attribution_first_touch IS NOT NULL
              AND (o.attribution_first_touch->>'channel_type') IS NOT NULL
        ", [$workspaceId]);

        $availableChannels = array_values(array_filter(
            array_column($channels, 'channel'),
        ));

        return Inertia::render('Store/Index', array_merge($common, [
            'store_ids'          => $storeIds,
            'cohort_rows'        => $cohortRows,
            'weighted_avg'       => $weightedAvg,
            'available_channels' => $availableChannels,
            'active_channel'     => $channelFilter,
            'narrative'          => null,
        ]));
    }

    // =========================================================================
    // Tab 4 — Countries (ported from CountriesController)
    // =========================================================================

    private function renderCountries(Request $request, int $workspaceId, array $common): InertiaResponse
    {
        ['from' => $from, 'to' => $to, 'storeIds' => $storeIds] = $common;

        $extra = $request->validate([
            'country'    => ['sometimes', 'nullable', 'string', 'size:2', 'alpha'],
            'sort_by'    => ['sometimes', 'nullable', 'in:revenue,orders,country_name,real_roas,real_profit,fb_spend,google_spend,gsc_clicks'],
            'sort_dir'   => ['sometimes', 'nullable', 'in:asc,desc'],
            'filter'     => ['sometimes', 'nullable', 'in:all,winners,losers'],
            'classifier' => ['sometimes', 'nullable', 'in:peer,period'],
        ]);

        $country    = isset($extra['country']) ? strtoupper($extra['country']) : null;
        $sortBy     = $extra['sort_by']    ?? 'revenue';
        $sortDir    = $extra['sort_dir']   ?? 'desc';
        $filter     = $extra['filter']     ?? 'all';
        $classifier = $extra['classifier'] ?? null;

        $storeClause = $this->storeClause($storeIds, 'o');

        // ── Revenue + orders by country ──────────────────────────────────────
        $orderRows = DB::select("
            SELECT o.shipping_country AS country_code,
                   COUNT(*) AS orders,
                   SUM(o.total_in_reporting_currency) AS revenue
            FROM orders o
            WHERE o.workspace_id = ? AND o.occurred_at::date BETWEEN ? AND ?
              AND o.status IN ('completed', 'processing')
              AND o.shipping_country IS NOT NULL
              AND o.total_in_reporting_currency IS NOT NULL
              {$storeClause}
            GROUP BY o.shipping_country
        ", [$workspaceId, $from, $to]);

        $byCountry = [];
        $totalRevenue = 0.0;
        foreach ($orderRows as $r) {
            $code = strtoupper((string) $r->country_code);
            $rev  = (float) $r->revenue;
            $byCountry[$code] = ['orders' => (int) $r->orders, 'revenue' => $rev];
            $totalRevenue += $rev;
        }

        // ── COGS by country ──────────────────────────────────────────────────
        $cogsRows = DB::select("
            SELECT o.shipping_country AS country_code,
                   SUM(oi.unit_cost * oi.quantity) AS total_cogs
            FROM order_items oi
            JOIN orders o ON o.id = oi.order_id
            WHERE o.workspace_id = ? AND o.status IN ('completed', 'processing')
              AND o.occurred_at BETWEEN ? AND ?
              AND o.shipping_country IS NOT NULL
              AND oi.unit_cost IS NOT NULL AND oi.unit_cost > 0
              {$storeClause}
            GROUP BY o.shipping_country
        ", [$workspaceId, $from . ' 00:00:00', $to . ' 23:59:59']);

        $cogsMap = [];
        foreach ($cogsRows as $r) {
            $cogsMap[strtoupper((string) $r->country_code)] = (float) $r->total_cogs;
        }

        // ── Ad spend by country (three-tier fallback, §PLANNING 5.7) ────────
        $adSpendRows = DB::select("
            SELECT UPPER(COALESCE(c.parsed_convention->>'country', s.primary_country_code, 'UNKNOWN')) AS country_code,
                   aa.platform,
                   SUM(ai.spend_in_reporting_currency) AS spend
            FROM ad_insights ai
            JOIN campaigns c    ON c.id  = ai.campaign_id
            JOIN ad_accounts aa ON aa.id = ai.ad_account_id
            LEFT JOIN stores s  ON s.workspace_id = ai.workspace_id
                               AND s.id = (SELECT id FROM stores WHERE workspace_id = ai.workspace_id ORDER BY id LIMIT 1)
            WHERE ai.workspace_id = ? AND ai.level = 'campaign' AND ai.hour IS NULL
              AND ai.date BETWEEN ? AND ?
            GROUP BY 1, aa.platform
        ", [$workspaceId, $from, $to]);

        $fbSpendMap = [];
        $googleSpendMap = [];
        foreach ($adSpendRows as $r) {
            $code  = (string) $r->country_code;
            $spend = (float) $r->spend;
            if ($r->platform === 'facebook') {
                $fbSpendMap[$code] = ($fbSpendMap[$code] ?? 0) + $spend;
            } else {
                $googleSpendMap[$code] = ($googleSpendMap[$code] ?? 0) + $spend;
            }
        }

        // ── GSC clicks by country ────────────────────────────────────────────
        $gscRows = DB::select("
            SELECT UPPER(gds.country) AS country_code, SUM(gds.clicks) AS clicks
            FROM gsc_daily_stats gds
            WHERE gds.workspace_id = ? AND gds.date BETWEEN ? AND ?
              AND gds.country <> 'ZZ' AND gds.device = 'all'
            GROUP BY UPPER(gds.country)
        ", [$workspaceId, $from, $to]);

        $gscMap = [];
        foreach ($gscRows as $r) {
            $gscMap[(string) $r->country_code] = (int) $r->clicks;
        }

        // ── Assemble ─────────────────────────────────────────────────────────
        $allCodes = array_unique(array_merge(
            array_keys($byCountry), array_keys($fbSpendMap),
            array_keys($googleSpendMap), array_keys($gscMap),
        ));

        $hasAds    = ! empty($fbSpendMap) || ! empty($googleSpendMap);
        $countries = [];
        foreach ($allCodes as $code) {
            $orders  = $byCountry[$code]['orders']  ?? 0;
            $revenue = $byCountry[$code]['revenue'] ?? 0.0;
            $cogs    = $cogsMap[$code] ?? null;
            $margin  = $cogs !== null ? $revenue - $cogs : null;
            $fb      = $fbSpendMap[$code] ?? null;
            $google  = $googleSpendMap[$code] ?? null;
            $gsc     = $gscMap[$code] ?? null;
            $totalAd = ($fb ?? 0) + ($google ?? 0);
            $realRoas = ($totalAd > 0 && $revenue > 0) ? round($revenue / $totalAd, 2) : null;
            $realProfit = ($margin !== null && $totalAd > 0) ? round($margin - $totalAd, 2) : null;

            $countries[] = [
                'country_code'        => $code,
                'orders'              => $orders,
                'revenue'             => round($revenue, 2),
                'share'               => $totalRevenue > 0 ? round(($revenue / $totalRevenue) * 100, 1) : 0.0,
                'gsc_clicks'          => $gsc,
                'fb_spend'            => $fb !== null && $fb > 0 ? round($fb, 2) : null,
                'google_spend'        => $google !== null && $google > 0 ? round($google, 2) : null,
                'real_roas'           => $realRoas,
                'contribution_margin' => $margin !== null ? round($margin, 2) : null,
                'real_profit'         => $realProfit,
            ];
        }

        // ── Sort ─────────────────────────────────────────────────────────────
        $sortDirU = strtoupper($sortDir);
        usort($countries, function (array $a, array $b) use ($sortBy, $sortDirU): int {
            if ($sortBy === 'country_name') {
                $cmp = strcmp($a['country_code'], $b['country_code']);
                return $sortDirU === 'ASC' ? $cmp : -$cmp;
            }
            $aVal = $a[$sortBy] ?? null;
            $bVal = $b[$sortBy] ?? null;
            if ($aVal === null && $bVal === null) return 0;
            if ($aVal === null) return 1;
            if ($bVal === null) return -1;
            $cmp = $aVal <=> $bVal;
            return $sortDirU === 'ASC' ? $cmp : -$cmp;
        });

        // ── Hero ─────────────────────────────────────────────────────────────
        $countriesWithOrders     = array_filter($countries, fn ($c) => $c['orders'] > 0);
        $topCountryShare         = ! empty($countries) ? max(array_column($countries, 'share')) : 0;
        $countriesAboveAvgMargin = 0;
        $profitableRoasCountries = 0;

        if (count($countriesWithOrders) > 0) {
            $margined = array_filter($countriesWithOrders, fn ($c) => $c['contribution_margin'] !== null);
            if (count($margined) > 0) {
                $avgMargin = array_sum(array_column($margined, 'contribution_margin')) / count($margined);
                $countriesAboveAvgMargin = count(array_filter($margined, fn ($c) => $c['contribution_margin'] >= $avgMargin));
            }
            $profitableRoasCountries = count(array_filter($countriesWithOrders, fn ($c) =>
                $c['real_roas'] !== null && $c['real_roas'] >= 1.0
            ));
        }

        // ── W/L ──────────────────────────────────────────────────────────────
        $effectiveClassifier = in_array($classifier, ['peer', 'period'], true) ? $classifier : 'peer';
        $rankable = array_filter($countries, fn ($c) => $c['real_roas'] !== null && $c['orders'] >= 20);
        $peerAvgRoas = count($rankable) > 0
            ? array_sum(array_column($rankable, 'real_roas')) / count($rankable)
            : null;

        $countries = array_map(function (array $c) use ($effectiveClassifier, $peerAvgRoas): array {
            if ($c['orders'] < 20 || $c['real_roas'] === null) {
                return array_merge($c, ['wl_tag' => null]);
            }
            $tag = match ($effectiveClassifier) {
                'peer' => ($peerAvgRoas !== null)
                    ? ($c['real_roas'] >= $peerAvgRoas ? 'winner' : 'loser')
                    : null,
                default => null,
            };
            return array_merge($c, ['wl_tag' => $tag]);
        }, $countries);

        $totalCount = count($countries);
        if ($filter !== 'all') {
            $filterTag = rtrim($filter, 's');
            $countries = array_values(array_filter($countries, fn ($c) => $c['wl_tag'] === $filterTag));
        }

        // ── Drill-down: top products for selected country ─────────────────────
        $topProducts = [];
        if ($country !== null && $country !== '') {
            $topProducts = DB::select(
                "WITH ranked AS (
                    SELECT oi.product_external_id, MAX(oi.product_name) AS product_name,
                           SUM(oi.quantity) AS units,
                           SUM(CASE WHEN (o.total - o.tax - o.shipping + o.discount) > 0
                                    THEN oi.line_total / (o.total - o.tax - o.shipping + o.discount)
                                         * o.total_in_reporting_currency
                                    ELSE NULL END) AS revenue
                    FROM orders o
                    JOIN order_items oi ON oi.order_id = o.id
                    WHERE o.workspace_id = ? AND o.shipping_country = ?
                      AND o.occurred_at::date BETWEEN ? AND ?
                      AND o.status IN ('completed', 'processing')
                      AND o.total_in_reporting_currency IS NOT NULL
                      {$storeClause}
                    GROUP BY oi.product_external_id
                    ORDER BY revenue DESC NULLS LAST LIMIT 10
                )
                SELECT r.*, (SELECT pr.image_url FROM products pr
                              WHERE pr.workspace_id = ? AND pr.external_id = r.product_external_id LIMIT 1) AS image_url
                FROM ranked r ORDER BY r.revenue DESC NULLS LAST",
                [$workspaceId, $country, $from, $to, $workspaceId],
            );
            $topProducts = array_map(fn ($p) => [
                'product_external_id' => $p->product_external_id,
                'product_name'        => $p->product_name,
                'units'               => (int) $p->units,
                'revenue'             => $p->revenue !== null ? (float) $p->revenue : null,
                'image_url'           => $p->image_url,
            ], $topProducts);
        }

        return Inertia::render('Store/Index', array_merge($common, [
            'store_ids'                    => $storeIds,
            'countries'                    => $countries,
            'countries_total_count'        => $totalCount,
            'has_ads'                      => $hasAds,
            'hero'                         => [
                'countries_with_orders'      => count($countriesWithOrders),
                'top_country_share'          => $topCountryShare,
                'countries_above_avg_margin' => $countriesAboveAvgMargin,
                'profitable_roas_countries'  => $profitableRoasCountries,
            ],
            'top_products'                 => $topProducts,
            'selected_country'             => $country,
            'sort_by'                      => $sortBy,
            'sort_dir'                     => $sortDir,
            'filter'                       => $filter,
            'classifier'                   => $classifier,
            'active_classifier'            => $effectiveClassifier,
            'narrative'                    => null,
        ]));
    }

    // =========================================================================
    // Tab 5 — Orders (ported from AnalyticsController::daily)
    // =========================================================================

    private function renderOrders(Request $request, int $workspaceId, array $common): InertiaResponse
    {
        ['from' => $from, 'to' => $to, 'storeIds' => $storeIds] = $common;

        $extra = $request->validate([
            'sort_by'    => ['sometimes', 'nullable', 'in:date,revenue,orders,items_sold,items_per_order,aov,ad_spend,roas,marketing_pct'],
            'sort_dir'   => ['sometimes', 'nullable', 'in:asc,desc'],
            'hide_empty' => ['sometimes', 'nullable', 'in:0,1'],
        ]);

        $sortBy    = $extra['sort_by']    ?? 'date';
        $sortDir   = $extra['sort_dir']   ?? 'desc';
        $hideEmpty = ($extra['hide_empty'] ?? '0') === '1';

        $rows   = $this->buildDailyRows($workspaceId, $from, $to, $storeIds, $sortBy, $sortDir, $hideEmpty);
        $totals = $this->buildDailyTotals($rows);

        $periodDays  = Carbon::parse($from)->diffInDays(Carbon::parse($to)) + 1;
        $priorTo     = Carbon::parse($from)->subDay()->toDateString();
        $priorFrom   = Carbon::parse($priorTo)->subDays($periodDays - 1)->toDateString();
        $priorRows   = $this->buildDailyRows($workspaceId, $priorFrom, $priorTo, $storeIds, 'date', 'desc');
        $priorTotals = $this->buildDailyTotals($priorRows);

        $pctDelta = static function (float|int|null $curr, float|int|null $prior): float|null {
            if ($curr === null || $prior === null || $prior == 0) {
                return null;
            }
            return round(($curr - $prior) / abs($prior) * 100, 1);
        };

        $comparison = [
            'revenue_current' => $totals['revenue'],
            'revenue_delta'   => $pctDelta($totals['revenue'], $priorTotals['revenue']),
            'orders_current'  => $totals['orders'],
            'orders_delta'    => $pctDelta($totals['orders'], $priorTotals['orders']),
            'aov_current'     => $totals['aov'],
            'aov_delta'       => $pctDelta($totals['aov'], $priorTotals['aov']),
            'roas_current'    => $totals['roas'],
            'roas_delta'      => $pctDelta($totals['roas'], $priorTotals['roas']),
        ];

        $hasAds = count(array_filter($rows, fn ($r) => $r['ad_spend'] !== null && $r['ad_spend'] > 0)) > 0;
        $rows   = $this->tagDailyWinnersLosers($rows, $workspaceId, $storeIds);

        $streak = null;
        if (! empty($rows)) {
            $sorted      = $rows;
            usort($sorted, fn ($a, $b) => strcmp($b['date'], $a['date']));
            $streakType  = null;
            $streakCount = 0;
            foreach ($sorted as $r) {
                if (! isset($r['wl_tag']) || $r['wl_tag'] === null) {
                    continue;
                }
                if ($streakType === null) {
                    $streakType  = $r['wl_tag'];
                    $streakCount = 1;
                } elseif ($r['wl_tag'] === $streakType) {
                    $streakCount++;
                } else {
                    break;
                }
            }
            if ($streakType !== null) {
                $streak = ['type' => $streakType, 'days' => $streakCount];
            }
        }

        $pageNarrative = $this->narrative->forDashboard(
            $totals['revenue'] > 0 ? $totals['revenue'] : null,
            ($priorTotals['revenue'] ?? 0) > 0 ? $priorTotals['revenue'] : null,
            'prior ' . $periodDays . '-day period',
            $totals['roas'] ?? null,
            $hasAds,
            false,
        );

        return Inertia::render('Store/Index', array_merge($common, [
            'store_ids'        => $storeIds,
            'rows'             => $rows,
            'rows_total_count' => count($rows),
            'totals'           => $totals,
            'hero'             => ['comparison' => $comparison, 'streak' => $streak],
            'has_ads'          => $hasAds,
            'sort_by'          => $sortBy,
            'sort_dir'         => $sortDir,
            'hide_empty'       => $hideEmpty,
            'narrative'        => $pageNarrative,
        ]));
    }

    // =========================================================================
    // Private helpers
    // =========================================================================

    private function storeClause(array $storeIds, string $alias): string
    {
        if (empty($storeIds)) {
            return '';
        }
        $ids = implode(',', array_map('intval', $storeIds));
        return "AND {$alias}.store_id IN ({$ids})";
    }

    /** @return array<string, object> keyed by external_id */
    private function fetchProductInfo(array $externalIds, int $workspaceId, array $storeIds): array
    {
        if (empty($externalIds)) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($externalIds), '?'));
        $storeFilter  = ! empty($storeIds)
            ? 'AND store_id IN (' . implode(',', array_map('intval', $storeIds)) . ')'
            : '';
        $pRows = DB::select("
            SELECT DISTINCT ON (external_id)
                external_id, image_url, stock_status, stock_quantity, sku
            FROM products
            WHERE workspace_id = ? AND external_id IN ({$placeholders}) {$storeFilter}
            ORDER BY external_id, updated_at DESC
        ", array_merge([$workspaceId], $externalIds));

        $map = [];
        foreach ($pRows as $p) {
            $map[$p->external_id] = $p;
        }
        return $map;
    }

    /** @return array{0: string[], 1: array<string, array<string, bool>>} [trendDays, trendMap] */
    private function fetchTrendDots(int $workspaceId, string $to, string $storeClause): array
    {
        $trendFrom = Carbon::parse($to)->subDays(13)->toDateString();
        $trendRows = DB::select("
            SELECT product_external_id, snapshot_date::text AS day, SUM(revenue) AS rev
            FROM daily_snapshot_products
            WHERE workspace_id = ? AND snapshot_date BETWEEN ? AND ?
              {$storeClause}
            GROUP BY product_external_id, snapshot_date
        ", [app(WorkspaceContext::class)->id(), $trendFrom, $to]);

        $trendMap = [];
        foreach ($trendRows as $tr) {
            $trendMap[$tr->product_external_id][$tr->day] = (float) $tr->rev > 0;
        }

        $trendDays = [];
        for ($d = Carbon::parse($trendFrom); $d->lte(Carbon::parse($to)); $d->addDay()) {
            $trendDays[] = $d->toDateString();
        }
        return [$trendDays, $trendMap];
    }

    /** Compute attributed ad spend per product (§F24). */
    private function computeAttributedAdSpend(int $workspaceId, string $from, string $to, string $storeClause): array
    {
        $rows = DB::select("
            WITH paid_orders AS (
                SELECT o.id AS order_id, o.total, o.occurred_at::date AS order_date
                FROM orders o
                WHERE o.workspace_id = ?
                  AND o.occurred_at BETWEEN ? AND ?
                  AND o.status IN ('completed', 'processing')
                  AND o.attribution_last_touch->>'channel_type' IN ('paid_search', 'paid_social')
                  AND o.total > 0
                  {$storeClause}
            ),
            daily_spend AS (
                SELECT date, SUM(spend_in_reporting_currency) AS spend
                FROM ad_insights
                WHERE workspace_id = ? AND level = 'campaign' AND hour IS NULL
                  AND date BETWEEN ? AND ?
                GROUP BY date
            )
            SELECT
                oi.product_external_id,
                SUM(
                    CASE WHEN po.total > 0 AND COALESCE(ds.spend, 0) > 0
                         THEN (oi.line_total / po.total) * ds.spend
                         ELSE 0 END
                ) AS attributed_spend
            FROM order_items oi
            JOIN paid_orders po ON po.order_id = oi.order_id
            LEFT JOIN daily_spend ds ON ds.date = po.order_date
            GROUP BY oi.product_external_id
            HAVING SUM(CASE WHEN po.total > 0 AND COALESCE(ds.spend, 0) > 0
                            THEN (oi.line_total / po.total) * ds.spend ELSE 0 END) > 0
        ", [$workspaceId, $from . ' 00:00:00', $to . ' 23:59:59', $workspaceId, $from, $to]);

        $map = [];
        foreach ($rows as $r) {
            $map[$r->product_external_id] = round((float) $r->attributed_spend, 2);
        }
        return $map;
    }

    /**
     * Compute product CVR proxy (§F27).
     * organic_product_orders / gsc_clicks_for_matching_landing_pages.
     * Returns null where GSC clicks cannot be matched.
     */
    private function computeProductCvr(int $workspaceId, string $from, string $to, string $storeClause): array
    {
        // Organic orders per product + their landing pages
        $organicRows = DB::select("
            SELECT
                oi.product_external_id,
                COUNT(DISTINCT o.id) AS organic_orders,
                json_agg(DISTINCT regexp_replace(
                    o.attribution_last_touch->>'landing_page', '\\?.*', ''
                )) FILTER (WHERE o.attribution_last_touch->>'landing_page' IS NOT NULL) AS landing_pages
            FROM order_items oi
            JOIN orders o ON o.id = oi.order_id
            WHERE o.workspace_id = ?
              AND o.occurred_at BETWEEN ? AND ?
              AND o.status IN ('completed', 'processing')
              AND o.attribution_last_touch->>'channel_type' = 'organic_search'
              {$storeClause}
            GROUP BY oi.product_external_id
        ", [$workspaceId, $from . ' 00:00:00', $to . ' 23:59:59']);

        if (empty($organicRows)) {
            return [];
        }

        // Collect all unique landing pages
        $productPageMap = [];
        $allPages       = [];
        foreach ($organicRows as $r) {
            $pages = $r->landing_pages ? (json_decode($r->landing_pages, true) ?? []) : [];
            $pages = array_values(array_filter($pages));
            $productPageMap[$r->product_external_id] = [
                'organic_orders' => (int) $r->organic_orders,
                'pages'          => $pages,
            ];
            $allPages = array_merge($allPages, $pages);
        }

        $allPages = array_values(array_unique($allPages));
        if (empty($allPages)) {
            return [];
        }

        // Fetch GSC clicks for these pages
        $placeholders = implode(',', array_fill(0, count($allPages), '?'));
        $gscRows      = DB::select("
            SELECT page, SUM(clicks) AS clicks
            FROM gsc_pages
            WHERE workspace_id = ? AND date BETWEEN ? AND ?
              AND device = 'all' AND page IN ({$placeholders})
            GROUP BY page
        ", array_merge([$workspaceId, $from, $to], $allPages));

        $gscClicksMap = [];
        foreach ($gscRows as $r) {
            $gscClicksMap[$r->page] = (int) $r->clicks;
        }

        // CVR per product
        $cvrMap = [];
        foreach ($productPageMap as $extId => $data) {
            $gscClicks = 0;
            foreach ($data['pages'] as $page) {
                $gscClicks += $gscClicksMap[$page] ?? 0;
            }
            if ($gscClicks > 0) {
                $cvrMap[$extId] = round($data['organic_orders'] / $gscClicks, 4);
            }
        }
        return $cvrMap;
    }

    /**
     * @param int[]  $storeIds
     * @return array<int, array<string, mixed>>
     */
    private function buildDailyRows(
        int $workspaceId,
        string $from,
        string $to,
        array $storeIds,
        string $sortBy,
        string $sortDir,
        bool $hideEmpty = false,
    ): array {
        $storeFilter = ! empty($storeIds)
            ? 'AND s.store_id IN (' . implode(',', array_map('intval', $storeIds)) . ')'
            : '';
        $sortDir  = strtoupper($sortDir) === 'ASC' ? 'ASC' : 'DESC';
        $allowed  = ['date', 'revenue', 'orders', 'items_sold', 'items_per_order', 'aov', 'ad_spend', 'roas', 'marketing_pct'];
        $orderCol = in_array($sortBy, $allowed, true) ? $sortBy : 'date';
        $orderClause  = $orderCol === 'date'
            ? "ORDER BY s.date {$sortDir}"
            : "ORDER BY {$orderCol} {$sortDir} NULLS LAST, s.date DESC";
        $havingClause = $hideEmpty ? 'HAVING COALESCE(SUM(s.revenue), 0) > 0 OR COALESCE(ai.ad_spend, 0) > 0' : '';

        $rows = DB::select("
            SELECT s.date::text AS date,
                   COALESCE(SUM(s.revenue), 0) AS revenue,
                   COALESCE(SUM(s.orders_count), 0) AS orders,
                   COALESCE(SUM(s.items_sold), 0) AS items_sold,
                   CASE WHEN SUM(s.orders_count) > 0
                        THEN SUM(s.items_sold)::numeric / SUM(s.orders_count)
                        ELSE NULL END AS items_per_order,
                   CASE WHEN SUM(s.orders_count) > 0
                        THEN SUM(s.revenue) / SUM(s.orders_count)
                        ELSE NULL END AS aov,
                   COALESCE(ai.ad_spend, 0) AS ad_spend,
                   CASE WHEN COALESCE(ai.ad_spend, 0) > 0
                        THEN SUM(s.revenue) / ai.ad_spend
                        ELSE NULL END AS roas,
                   CASE WHEN SUM(s.revenue) > 0 AND COALESCE(ai.ad_spend, 0) > 0
                        THEN ai.ad_spend / SUM(s.revenue) * 100
                        ELSE NULL END AS marketing_pct,
                   dn.note AS note
            FROM daily_snapshots s
            LEFT JOIN (
                SELECT date, SUM(spend_in_reporting_currency) AS ad_spend
                FROM ad_insights
                WHERE workspace_id = ? AND level = 'campaign' AND hour IS NULL
                GROUP BY date
            ) ai ON ai.date = s.date
            LEFT JOIN daily_notes dn ON dn.workspace_id = ? AND dn.date = s.date
            WHERE s.workspace_id = ? AND s.date BETWEEN ? AND ?
              {$storeFilter}
            GROUP BY s.date, ai.ad_spend, dn.note
            {$havingClause}
            {$orderClause}
        ", [$workspaceId, $workspaceId, $workspaceId, $from, $to]);

        return array_map(fn (object $r): array => [
            'date'            => $r->date,
            'revenue'         => (float) $r->revenue,
            'orders'          => (int) $r->orders,
            'items_sold'      => (int) $r->items_sold,
            'items_per_order' => $r->items_per_order !== null ? round((float) $r->items_per_order, 2) : null,
            'aov'             => $r->aov !== null ? round((float) $r->aov, 2) : null,
            'ad_spend'        => $r->ad_spend !== null && (float) $r->ad_spend > 0 ? round((float) $r->ad_spend, 2) : null,
            'roas'            => $r->roas !== null ? round((float) $r->roas, 2) : null,
            'marketing_pct'   => $r->marketing_pct !== null ? round((float) $r->marketing_pct, 1) : null,
            'note'            => $r->note,
        ], $rows);
    }

    /** @param array<int, array<string, mixed>> $rows */
    private function buildDailyTotals(array $rows): array
    {
        if (empty($rows)) {
            return ['revenue' => 0, 'orders' => 0, 'items_sold' => 0, 'items_per_order' => null, 'aov' => null, 'ad_spend' => null, 'roas' => null, 'marketing_pct' => null];
        }
        $revenue = array_sum(array_column($rows, 'revenue'));
        $orders  = array_sum(array_column($rows, 'orders'));
        $items   = array_sum(array_column($rows, 'items_sold'));
        $adSpend = array_sum(array_filter(array_column($rows, 'ad_spend')));

        return [
            'revenue'         => round($revenue, 2),
            'orders'          => $orders,
            'items_sold'      => $items,
            'items_per_order' => $orders > 0 ? round($items / $orders, 2) : null,
            'aov'             => $orders > 0 ? round($revenue / $orders, 2) : null,
            'ad_spend'        => $adSpend > 0 ? round($adSpend, 2) : null,
            'roas'            => ($adSpend > 0 && $revenue > 0) ? round($revenue / $adSpend, 2) : null,
            'marketing_pct'   => ($adSpend > 0 && $revenue > 0) ? round(($adSpend / $revenue) * 100, 1) : null,
        ];
    }

    /** @param array<int, array<string, mixed>> $rows */
    private function tagDailyWinnersLosers(array $rows, int $workspaceId, array $storeIds): array
    {
        if (empty($rows)) {
            return $rows;
        }
        $storeFilter = ! empty($storeIds)
            ? 'AND s.store_id IN (' . implode(',', array_map('intval', $storeIds)) . ')'
            : '';

        $dates    = array_column($rows, 'date');
        $earliest = min($dates);
        $lookback = Carbon::parse($earliest)->subWeeks(4)->toDateString();

        $avgRows = DB::select("
            SELECT EXTRACT(DOW FROM date)::int AS weekday, AVG(day_revenue) AS avg_revenue
            FROM (
                SELECT s.date, COALESCE(SUM(s.revenue), 0) AS day_revenue
                FROM daily_snapshots s
                WHERE s.workspace_id = ? AND s.date >= ? AND s.date < ?
                  {$storeFilter}
                GROUP BY s.date
            ) sub
            GROUP BY EXTRACT(DOW FROM date)
        ", [$workspaceId, $lookback, $earliest]);

        $weekdayAvg = [];
        foreach ($avgRows as $r) {
            $weekdayAvg[(int) $r->weekday] = (float) $r->avg_revenue;
        }

        $weekdaysPresent = array_unique(array_map(
            fn ($row) => Carbon::parse($row['date'])->dayOfWeek,
            $rows,
        ));
        $missing = array_filter($weekdaysPresent, fn ($d) => ! isset($weekdayAvg[$d]));
        if (! empty($missing)) {
            $latest      = max($dates);
            $fallbackRows = DB::select("
                SELECT EXTRACT(DOW FROM date)::int AS weekday, AVG(day_revenue) AS avg_revenue
                FROM (
                    SELECT s.date, COALESCE(SUM(s.revenue), 0) AS day_revenue
                    FROM daily_snapshots s
                    WHERE s.workspace_id = ? AND s.date >= ? AND s.date <= ?
                      {$storeFilter}
                    GROUP BY s.date
                ) sub
                WHERE EXTRACT(DOW FROM date) = ANY(?)
                GROUP BY EXTRACT(DOW FROM date)
            ", [$workspaceId, $earliest, $latest, '{' . implode(',', $missing) . '}']);
            foreach ($fallbackRows as $r) {
                $weekdayAvg[(int) $r->weekday] = (float) $r->avg_revenue;
            }
        }

        return array_map(function (array $row) use ($weekdayAvg): array {
            $weekday = Carbon::parse($row['date'])->dayOfWeek;
            $avg     = $weekdayAvg[$weekday] ?? null;
            $tag     = null;
            if ($avg !== null && $avg > 0 && $row['revenue'] > 0) {
                $tag = $row['revenue'] >= $avg ? 'winner' : 'loser';
            }
            return array_merge($row, ['wl_tag' => $tag]);
        }, $rows);
    }

    /** @return int[] */
    private function parseStoreIds(string $raw, int $workspaceId): array
    {
        if ($raw === '') {
            return [];
        }
        $ids = array_values(array_filter(
            array_map('intval', explode(',', $raw)),
            fn (int $id) => $id > 0,
        ));
        if (empty($ids)) {
            return [];
        }
        return Store::withoutGlobalScopes()
            ->where('workspace_id', $workspaceId)
            ->whereIn('id', $ids)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }
}
