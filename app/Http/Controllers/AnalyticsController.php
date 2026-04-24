<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\DailyNote;
use App\Models\Product;
use App\Models\Store;
use App\Models\Workspace;
use App\Services\NarrativeTemplateService;
use App\Services\WorkspaceContext;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class AnalyticsController extends Controller
{
    public function __construct(
        private readonly NarrativeTemplateService $narrative,
    ) {}

    // -------------------------------------------------------------------------
    // By Product
    // -------------------------------------------------------------------------

    /**
     * Products analytics page — rewrite for Phase 1.6.
     *
     * Shows contribution margin, Real profit, stock status, days-of-cover,
     * and scatter view via generalised QuadrantChart.
     *
     * @see PLANNING.md section 12.5 "/analytics/products — rewrite"
     */
    public function products(Request $request): InertiaResponse
    {
        $workspaceId = app(WorkspaceContext::class)->id();

        $validated = $request->validate([
            'from'       => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'to'         => ['sometimes', 'nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'store_ids'  => ['sometimes', 'nullable', 'string'],
            'sort_by'    => ['sometimes', 'nullable', 'in:revenue,units,contribution_margin,margin_pct'],
            'sort_dir'   => ['sometimes', 'nullable', 'in:asc,desc'],
            'filter'     => ['sometimes', 'nullable', 'in:all,winners,losers'],
            'classifier' => ['sometimes', 'nullable', 'in:peer,period'],
            'view'       => ['sometimes', 'nullable', 'in:table,scatter'],
        ]);

        $from       = $validated['from']       ?? now()->subDays(29)->toDateString();
        $to         = $validated['to']         ?? now()->toDateString();
        $filter     = $validated['filter']     ?? 'all';
        $classifier = $validated['classifier'] ?? null;
        $view       = $validated['view']       ?? 'table';
        $storeIds   = $this->parseStoreIds($validated['store_ids'] ?? '', $workspaceId);

        $storeClause = ! empty($storeIds)
            ? 'AND dsp.store_id IN (' . implode(',', array_map('intval', $storeIds)) . ')'
            : '';

        // ── Main product query — revenue, units, COGS ────────────────────────
        // Unit cost source priority: order_items.unit_cost (from CogsReaderService) →
        // product_costs table (manual/CSV fallback entered via /manage/product-costs).
        // The LATERAL subquery picks the product_costs row whose date range covers the
        // order date, preferring the most-recently-effective row when ranges overlap.
        // Contribution margin = revenue - total COGS.
        // @see PLANNING.md section 7, 12.5
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
                    SUM(COALESCE(oi.unit_cost, pc_lookup.unit_cost) * oi.quantity) AS total_cogs,
                    COUNT(DISTINCT oi.order_id)                                     AS order_count
                FROM order_items oi
                JOIN orders o ON o.id = oi.order_id
                LEFT JOIN LATERAL (
                    SELECT pc.unit_cost
                    FROM product_costs pc
                    WHERE pc.workspace_id = o.workspace_id
                      AND pc.store_id = o.store_id
                      AND pc.product_external_id = oi.product_external_id
                      AND (pc.effective_from IS NULL OR pc.effective_from <= o.occurred_at::date)
                      AND (pc.effective_to IS NULL OR pc.effective_to >= o.occurred_at::date)
                    ORDER BY pc.effective_from DESC
                    LIMIT 1
                ) pc_lookup ON TRUE
                WHERE o.workspace_id = ?
                  AND o.status IN ('completed', 'processing')
                  AND o.occurred_at BETWEEN ? AND ?
                  AND (
                      (oi.unit_cost IS NOT NULL AND oi.unit_cost > 0)
                      OR pc_lookup.unit_cost IS NOT NULL
                  )
                GROUP BY oi.product_external_id
            )
            SELECT
                c.product_external_id AS external_id,
                c.name,
                c.units,
                c.revenue,
                cg.total_cogs,
                cg.order_count AS cogs_orders,
                CASE WHEN cg.total_cogs IS NOT NULL
                     THEN c.revenue - cg.total_cogs
                     ELSE NULL END AS contribution_margin,
                CASE WHEN cg.total_cogs IS NOT NULL AND c.revenue > 0
                     THEN ROUND(((c.revenue - cg.total_cogs) / NULLIF(c.revenue, 0) * 100)::numeric, 1)
                     ELSE NULL END AS margin_pct
            FROM current_p c
            LEFT JOIN cogs cg ON cg.product_external_id = c.product_external_id
            ORDER BY c.revenue DESC NULLS LAST
            LIMIT 50
        ", [
            $workspaceId, $from, $to,
            $workspaceId, $from . ' 00:00:00', $to . ' 23:59:59',
        ]);

        // ── Enrich with product images + stock status from products table ─────
        $externalIds = array_column($rows, 'external_id');
        $productInfo = [];
        if (! empty($externalIds)) {
            $placeholders = implode(',', array_fill(0, count($externalIds), '?'));
            $storeFilter  = ! empty($storeIds)
                ? 'AND store_id IN (' . implode(',', array_map('intval', $storeIds)) . ')'
                : '';
            $pRows = DB::select("
                SELECT DISTINCT ON (external_id)
                    id,
                    external_id,
                    image_url,
                    stock_status,
                    stock_quantity
                FROM products
                WHERE workspace_id = ?
                  AND external_id IN ({$placeholders})
                  {$storeFilter}
                ORDER BY external_id, updated_at DESC
            ", array_merge([$workspaceId], $externalIds));
            foreach ($pRows as $p) {
                $productInfo[$p->external_id] = $p;
            }
        }

        // ── 14-day trend dots (daily revenue > 0 = hit) ──────────────────────
        $trendFrom = Carbon::parse($to)->subDays(13)->toDateString();
        $trendRows = DB::select("
            SELECT product_external_id, snapshot_date::text AS day, SUM(revenue) AS rev
            FROM daily_snapshot_products
            WHERE workspace_id = ?
              AND snapshot_date BETWEEN ? AND ?
              {$storeClause}
            GROUP BY product_external_id, snapshot_date
        ", [$workspaceId, $trendFrom, $to]);

        $trendMap = [];
        foreach ($trendRows as $tr) {
            $trendMap[$tr->product_external_id][$tr->day] = (float) $tr->rev > 0;
        }

        // Build 14-day dot array (oldest first)
        $trendDays = [];
        for ($d = Carbon::parse($trendFrom); $d->lte(Carbon::parse($to)); $d->addDay()) {
            $trendDays[] = $d->toDateString();
        }

        // ── Days-of-cover: stock_quantity / avg daily units last 14 days ──────
        $docFrom = Carbon::parse($to)->subDays(13)->toDateString();
        $docRows = DB::select("
            SELECT product_external_id, SUM(units) AS total_units_14d
            FROM daily_snapshot_products
            WHERE workspace_id = ?
              AND snapshot_date BETWEEN ? AND ?
              {$storeClause}
            GROUP BY product_external_id
        ", [$workspaceId, $docFrom, $to]);
        $docMap = [];
        foreach ($docRows as $dr) {
            $docMap[$dr->product_external_id] = (int) $dr->total_units_14d;
        }

        // ── Assemble product rows ────────────────────────────────────────────
        $hasCogs = false;
        $products = array_map(function (object $r) use ($productInfo, $trendMap, $trendDays, $docMap, &$hasCogs): array {
            $info         = $productInfo[$r->external_id] ?? null;
            $stockStatus  = $info?->stock_status;
            $stockQty     = $info?->stock_quantity !== null ? (int) $info->stock_quantity : null;
            $totalCogs    = $r->total_cogs !== null ? (float) $r->total_cogs : null;
            $margin       = $r->contribution_margin !== null ? (float) $r->contribution_margin : null;
            $marginPct    = $r->margin_pct !== null ? (float) $r->margin_pct : null;

            if ($totalCogs !== null) {
                $hasCogs = true;
            }

            // Days of cover
            $daysOfCover  = null;
            $units14d     = $docMap[$r->external_id] ?? 0;
            if ($stockQty !== null && $units14d > 0) {
                $avgDailyUnits = $units14d / 14;
                $daysOfCover   = (int) round($stockQty / $avgDailyUnits);
            }

            // Trend dots
            $dots = array_map(
                fn (string $day) => $trendMap[$r->external_id][$day] ?? null,
                $trendDays,
            );

            return [
                'id'                  => $info?->id !== null ? (int) $info->id : null,
                'external_id'         => $r->external_id,
                'name'                => $r->name,
                'image_url'           => $info?->image_url,
                'units'               => (int) $r->units,
                'orders'              => $r->cogs_orders !== null ? (int) $r->cogs_orders : null,
                'revenue'             => $r->revenue !== null ? (float) $r->revenue : null,
                'total_cogs'          => $totalCogs,
                'contribution_margin' => $margin,
                'margin_pct'          => $marginPct,
                'stock_status'        => $stockStatus,
                'stock_quantity'      => $stockQty,
                'days_of_cover'       => $daysOfCover,
                'trend_dots'          => $dots,
            ];
        }, $rows);

        // ── Sort ─────────────────────────────────────────────────────────────
        // Default: contribution_margin desc when COGS configured, revenue desc otherwise.
        $sortBy  = $validated['sort_by'] ?? ($hasCogs ? 'contribution_margin' : 'revenue');
        $sortDir = strtoupper($validated['sort_dir'] ?? 'desc');
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
        $totalRevenue = array_sum(array_filter(array_column($products, 'revenue')));
        $productsWithMargin = array_filter($products, fn ($p) => $p['contribution_margin'] !== null);
        $totalMargin  = count($productsWithMargin) > 0
            ? array_sum(array_column($productsWithMargin, 'contribution_margin'))
            : null;
        $avgMarginPct = ($totalMargin !== null && $totalRevenue > 0)
            ? round(($totalMargin / $totalRevenue) * 100, 1)
            : null;

        // ── Winners / Losers — peer average on margin (or revenue if no COGS) ──
        // OOS products excluded from ranking per PLANNING 12.5.
        $effectiveClassifier = in_array($classifier, ['peer', 'period'], true) ? $classifier : 'peer';
        $rankField = $hasCogs ? 'contribution_margin' : 'revenue';

        $rankable = array_filter($products, fn ($p) =>
            ($p['stock_status'] ?? 'instock') !== 'outofstock'
            && $p[$rankField] !== null
        );
        $peerAvg = count($rankable) > 0
            ? array_sum(array_column($rankable, $rankField)) / count($rankable)
            : null;

        // ── Period classifier: compare current-period revenue vs. previous period ──
        // Previous period = same number of days immediately preceding the current period.
        $prevRevenueMap = [];
        if ($effectiveClassifier === 'period') {
            $periodDays  = Carbon::parse($from)->diffInDays(Carbon::parse($to));
            $prevTo      = Carbon::parse($from)->subDay()->toDateString();
            $prevFrom    = Carbon::parse($from)->subDays($periodDays + 1)->toDateString();

            $storeClauseNoAlias = ! empty($storeIds)
                ? 'AND store_id IN (' . implode(',', array_map('intval', $storeIds)) . ')'
                : '';

            $prevRows = DB::select("
                SELECT product_external_id, SUM(revenue) AS revenue
                FROM daily_snapshot_products
                WHERE workspace_id = ?
                  AND snapshot_date BETWEEN ? AND ?
                  {$storeClauseNoAlias}
                GROUP BY product_external_id
            ", [$workspaceId, $prevFrom, $prevTo]);

            foreach ($prevRows as $pr) {
                $prevRevenueMap[$pr->product_external_id] = (float) $pr->revenue;
            }
        }

        $products = array_map(function (array $p) use ($effectiveClassifier, $peerAvg, $rankField, $prevRevenueMap): array {
            // OOS products cannot be meaningfully ranked
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

        $totalProductCount = count($products);

        // ── Narrative ────────────────────────────────────────────────────────
        // Counts from the full tagged list before filter so the sentence describes
        // the whole period, not just the filtered view. Phase 3.3 adds stockout-risk count.
        $winnerCount   = count(array_filter($products, fn ($p) => $p['wl_tag'] === 'winner'));
        $loserCount    = count(array_filter($products, fn ($p) => $p['wl_tag'] === 'loser'));
        $pageNarrative = $this->narrative->forProducts($winnerCount, $loserCount, 0);

        if ($filter !== 'all') {
            $filterTag = rtrim($filter, 's');
            $products  = array_values(
                array_filter($products, fn (array $p) => $p['wl_tag'] === $filterTag),
            );
        }

        return Inertia::render('Analytics/Products', [
            'products'              => $products,
            'products_total_count'  => $totalProductCount,
            'has_cogs'              => $hasCogs,
            'hero'                  => [
                'total_units'    => $totalUnits,
                'total_revenue'  => round($totalRevenue, 2),
                'total_margin'   => $totalMargin !== null ? round($totalMargin, 2) : null,
                'avg_margin_pct' => $avgMarginPct,
            ],
            'from'                  => $from,
            'to'                    => $to,
            'store_ids'             => $storeIds,
            'sort_by'               => strtolower($sortBy),
            'sort_dir'              => strtolower($sortDir),
            'view'                  => $view,
            'filter'                => $filter,
            'classifier'            => $classifier,
            'active_classifier'     => $effectiveClassifier,
            'narrative'             => $pageNarrative,
        ]);
    }

    // -------------------------------------------------------------------------
    // Product detail
    // -------------------------------------------------------------------------

    /**
     * Single product detail page — drill-down from /analytics/products.
     *
     * Shows: 90-day revenue/units/orders/margin hero, variation breakdown,
     * attributed source mix, recent orders, and Frequently-Bought-Together
     * pairs computed by ComputeProductAffinitiesJob.
     *
     * Route model binding through WorkspaceScope ensures cross-workspace 404.
     *
     * @see PLANNING.md section 12.5 ("Drill-down: product detail page…")
     * @see PLANNING.md section 19 (Frequently-Bought-Together)
     */
    public function productShow(Product $product): InertiaResponse
    {
        // Use WorkspaceContext rather than implicit Workspace model binding —
        // SetActiveWorkspace calls forgetParameter('workspace') before SubstituteBindings runs.
        $workspaceId = app(WorkspaceContext::class)->id();
        $product->load('store:id,name,slug');

        $from = now()->subDays(89)->toDateString();
        $to   = now()->toDateString();

        // ── Hero stats: 90-day units / revenue / orders / COGS / margin ──────
        // Revenue/units come from daily_snapshot_products (pre-aggregated, fast).
        // COGS comes from order_items where status is real (completed/processing).
        $stats = DB::selectOne(<<<'SQL'
            WITH rev AS (
                SELECT
                    COALESCE(SUM(units), 0)   AS units,
                    COALESCE(SUM(revenue), 0) AS revenue
                FROM daily_snapshot_products
                WHERE workspace_id = :workspace
                  AND store_id = :store
                  AND product_external_id = :ext
                  AND snapshot_date BETWEEN :from AND :to
            ),
            cogs AS (
                SELECT
                    COUNT(DISTINCT oi.order_id)               AS orders,
                    SUM(oi.unit_cost * oi.quantity) FILTER (WHERE oi.unit_cost IS NOT NULL AND oi.unit_cost > 0) AS total_cogs,
                    SUM(oi.quantity) FILTER (WHERE oi.unit_cost IS NOT NULL AND oi.unit_cost > 0) AS units_with_cogs
                FROM order_items oi
                JOIN orders o ON o.id = oi.order_id
                WHERE o.workspace_id = :workspace
                  AND o.store_id = :store
                  AND oi.product_external_id = :ext
                  AND o.status IN ('completed','processing')
                  AND o.occurred_at BETWEEN :fromTs AND :toTs
            )
            SELECT rev.units, rev.revenue, cogs.orders, cogs.total_cogs, cogs.units_with_cogs
            FROM rev, cogs
        SQL, [
            'workspace' => $workspaceId,
            'store'     => $product->store_id,
            'ext'       => $product->external_id,
            'from'      => $from,
            'to'        => $to,
            'fromTs'    => $from . ' 00:00:00',
            'toTs'      => $to . ' 23:59:59',
        ]);

        $units      = (int) ($stats->units ?? 0);
        $revenue    = (float) ($stats->revenue ?? 0);
        $orders     = (int) ($stats->orders ?? 0);
        $totalCogs  = $stats->total_cogs !== null ? (float) $stats->total_cogs : null;
        $margin     = $totalCogs !== null ? round($revenue - $totalCogs, 2) : null;
        $marginPct  = ($margin !== null && $revenue > 0) ? round(($margin / $revenue) * 100, 1) : null;

        // ── Variation breakdown: group order_items by variant_name ───────────
        $variants = DB::select(<<<'SQL'
            SELECT
                COALESCE(NULLIF(oi.variant_name, ''), '—') AS variant_name,
                oi.sku                                      AS sku,
                SUM(oi.quantity)::int                       AS units,
                SUM(oi.line_total)                          AS revenue
            FROM order_items oi
            JOIN orders o ON o.id = oi.order_id
            WHERE o.workspace_id = :workspace
              AND o.store_id = :store
              AND oi.product_external_id = :ext
              AND o.status IN ('completed','processing')
              AND o.occurred_at BETWEEN :from AND :to
            GROUP BY variant_name, sku
            ORDER BY revenue DESC NULLS LAST
        SQL, [
            'workspace' => $workspaceId,
            'store'     => $product->store_id,
            'ext'       => $product->external_id,
            'from'      => $from . ' 00:00:00',
            'to'        => $to . ' 23:59:59',
        ]);

        // ── Attributed source mix: group orders containing this product by
        //    parser channel_type. Reads attribution_last_touch JSONB. ─────────
        $sources = DB::select(<<<'SQL'
            SELECT
                COALESCE(o.attribution_last_touch->>'channel_type', 'not_tracked') AS channel_type,
                COUNT(DISTINCT o.id)::int                                          AS orders,
                COALESCE(SUM(oi.line_total), 0)                                    AS revenue
            FROM orders o
            JOIN order_items oi ON oi.order_id = o.id
            WHERE o.workspace_id = :workspace
              AND o.store_id = :store
              AND oi.product_external_id = :ext
              AND o.status IN ('completed','processing')
              AND o.occurred_at BETWEEN :from AND :to
            GROUP BY channel_type
            ORDER BY revenue DESC
        SQL, [
            'workspace' => $workspaceId,
            'store'     => $product->store_id,
            'ext'       => $product->external_id,
            'from'      => $from . ' 00:00:00',
            'to'        => $to . ' 23:59:59',
        ]);

        // ── Recent orders containing this product (last 10) ──────────────────
        $recentOrders = DB::select(<<<'SQL'
            SELECT
                o.id,
                o.external_number,
                o.external_id,
                o.occurred_at,
                o.total,
                o.currency,
                o.attribution_source,
                SUM(oi.quantity)::int AS qty,
                SUM(oi.line_total)    AS line_total
            FROM orders o
            JOIN order_items oi ON oi.order_id = o.id
            WHERE o.workspace_id = :workspace
              AND o.store_id = :store
              AND oi.product_external_id = :ext
            GROUP BY o.id
            ORDER BY o.occurred_at DESC
            LIMIT 10
        SQL, [
            'workspace' => $workspaceId,
            'store'     => $product->store_id,
            'ext'       => $product->external_id,
        ]);

        // ── FBT pairs: rows where this product is A, joined to B's product row.
        //    Sorted by margin_lift when COGS available, else by lift. ─────────
        $fbt = DB::select(<<<'SQL'
            SELECT
                pa.confidence,
                pa.support,
                pa.lift,
                pa.margin_lift,
                p.id           AS product_id,
                p.external_id  AS external_id,
                p.name         AS name,
                p.image_url    AS image_url
            FROM product_affinities pa
            JOIN products p ON p.id = pa.product_b_id
            WHERE pa.workspace_id = :workspace
              AND pa.store_id     = :store
              AND pa.product_a_id = :pid
            ORDER BY COALESCE(pa.margin_lift, 0) DESC, pa.lift DESC
            LIMIT 10
        SQL, [
            'workspace' => $workspaceId,
            'store'     => $product->store_id,
            'pid'       => $product->id,
        ]);

        return Inertia::render('Analytics/ProductShow', [
            'product' => [
                'id'             => $product->id,
                'external_id'    => $product->external_id,
                'name'           => $product->name,
                'sku'            => $product->sku,
                'image_url'      => $product->image_url,
                'product_url'    => $product->product_url,
                'price'          => (float) $product->price,
                'stock_status'   => $product->stock_status,
                'stock_quantity' => $product->stock_quantity,
                'store'          => $product->store ? [
                    'id'   => $product->store->id,
                    'name' => $product->store->name,
                    'slug' => $product->store->slug,
                ] : null,
            ],
            'hero' => [
                'units'      => $units,
                'orders'     => $orders,
                'revenue'    => round($revenue, 2),
                'total_cogs' => $totalCogs !== null ? round($totalCogs, 2) : null,
                'margin'     => $margin,
                'margin_pct' => $marginPct,
                'has_cogs'   => $totalCogs !== null,
                'window_days'=> 90,
            ],
            'variants' => array_map(fn (object $v) => [
                'variant_name' => $v->variant_name,
                'sku'          => $v->sku,
                'units'        => (int) $v->units,
                'revenue'      => $v->revenue !== null ? round((float) $v->revenue, 2) : null,
            ], $variants),
            'sources' => array_map(fn (object $s) => [
                'channel_type' => $s->channel_type,
                'orders'       => (int) $s->orders,
                'revenue'      => round((float) $s->revenue, 2),
            ], $sources),
            'recent_orders' => array_map(fn (object $o) => [
                'id'                 => (int) $o->id,
                'external_number'    => $o->external_number,
                'external_id'        => $o->external_id,
                'occurred_at'        => $o->occurred_at,
                'total'              => (float) $o->total,
                'currency'           => $o->currency,
                'attribution_source' => $o->attribution_source,
                'qty'                => (int) $o->qty,
                'line_total'         => round((float) $o->line_total, 2),
            ], $recentOrders),
            'fbt' => array_map(fn (object $f) => [
                'product_id'  => (int) $f->product_id,
                'external_id' => $f->external_id,
                'name'        => $f->name,
                'image_url'   => $f->image_url,
                'confidence'  => round((float) $f->confidence, 4),
                'support'     => round((float) $f->support, 4),
                'lift'        => round((float) $f->lift, 2),
                'margin_lift' => $f->margin_lift !== null ? round((float) $f->margin_lift, 2) : null,
            ], $fbt),
        ]);
    }

    // -------------------------------------------------------------------------
    // Daily report
    // -------------------------------------------------------------------------

    /**
     * Daily report page — refined for Phase 1.6.
     *
     * Adds hero row (yesterday's metrics + weekday avg delta) and
     * weekday-aware peer W/L classifier.
     *
     * @see PLANNING.md section 12.5 "/analytics/daily — migration"
     */
    public function daily(Request $request): InertiaResponse
    {
        $workspaceId = app(WorkspaceContext::class)->id();

        $validated = $request->validate([
            'from'       => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'to'         => ['sometimes', 'nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'store_ids'  => ['sometimes', 'nullable', 'string'],
            'sort_by'    => ['sometimes', 'nullable', 'in:date,revenue,orders,items_sold,items_per_order,aov,ad_spend,roas,marketing_pct'],
            'sort_dir'   => ['sometimes', 'nullable', 'in:asc,desc'],
            'hide_empty' => ['sometimes', 'nullable', 'in:0,1'],
        ]);

        $from      = $validated['from']       ?? now()->subDays(29)->toDateString();
        $to        = $validated['to']         ?? now()->toDateString();
        $sortBy    = $validated['sort_by']    ?? 'date';
        $sortDir   = $validated['sort_dir']   ?? 'desc';
        $hideEmpty = ($validated['hide_empty'] ?? '0') === '1';
        $storeIds  = $this->parseStoreIds($validated['store_ids'] ?? '', $workspaceId);

        $rows   = $this->buildDailyRows($workspaceId, $from, $to, $storeIds, $sortBy, $sortDir, $hideEmpty);
        $totals = $this->buildDailyTotals($rows);

        // ── Hero: period-over-period comparison ──────────────────────────────
        // Prior period = same number of days immediately before $from.
        $periodDays  = Carbon::parse($from)->diffInDays(Carbon::parse($to)) + 1;
        $priorTo     = Carbon::parse($from)->subDay()->toDateString();
        $priorFrom   = Carbon::parse($priorTo)->subDays($periodDays - 1)->toDateString();
        $priorRows   = $this->buildDailyRows($workspaceId, $priorFrom, $priorTo, $storeIds, 'date', 'desc');
        $priorTotals = $this->buildDailyTotals($priorRows);

        $pctDelta = static function (float|int|null $current, float|int|null $prior): float|null {
            if ($current === null || $prior === null || $prior == 0) {
                return null;
            }
            return round(($current - $prior) / abs($prior) * 100, 1);
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

        // Has ads = any row with ad_spend
        $hasAds = count(array_filter($rows, fn ($r) => $r['ad_spend'] !== null && $r['ad_spend'] > 0)) > 0;

        // ── W/L classifier: weekday-aware peer ──────────────────────────────
        // Each day is compared to the average of the same weekday in the last 4 weeks.
        // Winner = above weekday average, loser = below.
        $rows = $this->tagDailyWinnersLosers($rows, $workspaceId, $storeIds);

        // ── Streak: consecutive winner/loser days from the most recent date ──
        $streak = null;
        if (! empty($rows)) {
            $sorted = $rows;
            usort($sorted, fn (array $a, array $b): int => strcmp($b['date'], $a['date']));
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

        $hero = [
            'comparison' => $comparison,
            'streak'     => $streak,
        ];

        // ── Narrative ────────────────────────────────────────────────────────
        // forDashboard() is re-used here: pass revenue, prior-period revenue as comparison,
        // and the label "prior {n}-day period". ROAS and ads flags come from $totals.
        $dailyNarrative = $this->narrative->forDashboard(
            $totals['revenue'] > 0 ? $totals['revenue'] : null,
            ($priorTotals['revenue'] ?? 0) > 0 ? $priorTotals['revenue'] : null,
            'prior ' . $periodDays . '-day period',
            $totals['roas'] ?? null,
            $hasAds,
            false,
        );

        return Inertia::render('Analytics/Daily', [
            'rows'              => $rows,
            'rows_total_count'  => count($rows),
            'totals'            => $totals,
            'hero'              => $hero,
            'has_ads'           => $hasAds,
            'from'              => $from,
            'to'                => $to,
            'store_ids'         => $storeIds,
            'sort_by'           => $sortBy,
            'sort_dir'          => $sortDir,
            'hide_empty'        => $hideEmpty,
            'narrative'         => $dailyNarrative,
        ]);
    }

    // -------------------------------------------------------------------------
    // Upsert day note
    // -------------------------------------------------------------------------

    public function upsertNote(Request $request, string $date): Response
    {
        $workspaceId = app(WorkspaceContext::class)->id();

        $request->validate([
            'note' => ['present', 'nullable', 'string', 'max:1000'],
        ]);

        $userId = $request->user()->id;
        $note   = trim((string) $request->input('note'));

        if ($note === '') {
            // Delete the note if emptied
            DailyNote::withoutGlobalScopes()
                ->where('workspace_id', $workspaceId)
                ->where('date', $date)
                ->delete();
        } else {
            // Why: updateOrInsert avoids the race condition where two concurrent requests
            // both pass the first() check and then both attempt create(), causing a
            // unique-constraint violation on (workspace_id, date).
            DailyNote::withoutGlobalScopes()->updateOrInsert(
                ['workspace_id' => $workspaceId, 'date' => $date],
                ['note' => $note, 'updated_by' => $userId, 'created_by' => $userId],
            );
        }

        return response()->noContent();
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * @param int[]  $storeIds
     * @return array<int, array{date:string,revenue:float,orders:int,items_sold:int,items_per_order:float|null,aov:float|null,ad_spend:float|null,roas:float|null,marketing_pct:float|null,note:string|null}>
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

        $sortDir = strtoupper($sortDir) === 'ASC' ? 'ASC' : 'DESC';

        $allowedSort = [
            'date', 'revenue', 'orders', 'items_sold',
            'items_per_order', 'aov', 'ad_spend', 'roas', 'marketing_pct',
        ];
        $orderCol = in_array($sortBy, $allowedSort, true) ? $sortBy : 'date';
        $orderClause = match ($orderCol) {
            'date'    => "ORDER BY s.date {$sortDir}",
            default   => "ORDER BY {$orderCol} {$sortDir} NULLS LAST, s.date DESC",
        };
        $havingClause = $hideEmpty ? 'HAVING COALESCE(SUM(s.revenue), 0) > 0 OR COALESCE(ai.ad_spend, 0) > 0' : '';

        $rows = DB::select("
            SELECT
                s.date::text                                                          AS date,
                COALESCE(SUM(s.revenue), 0)                                           AS revenue,
                COALESCE(SUM(s.orders_count), 0)                                      AS orders,
                COALESCE(SUM(s.items_sold), 0)                                        AS items_sold,
                CASE WHEN SUM(s.orders_count) > 0
                     THEN SUM(s.items_sold)::numeric / SUM(s.orders_count)
                     ELSE NULL END                                                    AS items_per_order,
                CASE WHEN SUM(s.orders_count) > 0
                     THEN SUM(s.revenue) / SUM(s.orders_count)
                     ELSE NULL END                                                    AS aov,
                COALESCE(ai.ad_spend, 0)                                              AS ad_spend,
                CASE WHEN COALESCE(ai.ad_spend, 0) > 0
                     THEN SUM(s.revenue) / ai.ad_spend
                     ELSE NULL END                                                    AS roas,
                CASE WHEN SUM(s.revenue) > 0 AND COALESCE(ai.ad_spend, 0) > 0
                     THEN ai.ad_spend / SUM(s.revenue) * 100
                     ELSE NULL END                                                    AS marketing_pct,
                dn.note                                                               AS note
            FROM daily_snapshots s
            LEFT JOIN (
                SELECT date, SUM(spend_in_reporting_currency) AS ad_spend
                FROM ad_insights
                WHERE workspace_id = ? AND level = 'campaign' AND hour IS NULL
                GROUP BY date
            ) ai ON ai.date = s.date
            LEFT JOIN daily_notes dn
                ON dn.workspace_id = ? AND dn.date = s.date
            WHERE s.workspace_id = ?
              AND s.date BETWEEN ? AND ?
              {$storeFilter}
            GROUP BY s.date, ai.ad_spend, dn.note
            {$havingClause}
            {$orderClause}
        ", [$workspaceId, $workspaceId, $workspaceId, $from, $to]);

        return array_map(function (object $r): array {
            return [
                'date'             => $r->date,
                'revenue'          => (float) $r->revenue,
                'orders'           => (int)   $r->orders,
                'items_sold'       => (int)   $r->items_sold,
                'items_per_order'  => $r->items_per_order !== null
                    ? round((float) $r->items_per_order, 2) : null,
                'aov'              => $r->aov !== null
                    ? round((float) $r->aov, 2) : null,
                'ad_spend'         => $r->ad_spend !== null && (float) $r->ad_spend > 0
                    ? round((float) $r->ad_spend, 2) : null,
                'roas'             => $r->roas !== null
                    ? round((float) $r->roas, 2) : null,
                'marketing_pct'    => $r->marketing_pct !== null
                    ? round((float) $r->marketing_pct, 1) : null,
                'note'             => $r->note,
            ];
        }, $rows);
    }

    /**
     * Compute column totals/averages from the daily rows.
     *
     * @param  array<int, array{date:string,revenue:float,orders:int,items_sold:int,...}> $rows
     * @return array{revenue:float,orders:int,items_sold:int,items_per_order:float|null,aov:float|null,ad_spend:float|null,roas:float|null,marketing_pct:float|null}
     */
    private function buildDailyTotals(array $rows): array
    {
        if (empty($rows)) {
            return [
                'revenue' => 0, 'orders' => 0, 'items_sold' => 0,
                'items_per_order' => null, 'aov' => null,
                'ad_spend' => null, 'roas' => null, 'marketing_pct' => null,
            ];
        }

        $revenue   = array_sum(array_column($rows, 'revenue'));
        $orders    = array_sum(array_column($rows, 'orders'));
        $items     = array_sum(array_column($rows, 'items_sold'));
        $adSpend   = array_sum(array_filter(array_column($rows, 'ad_spend')));

        return [
            'revenue'         => round($revenue, 2),
            'orders'          => $orders,
            'items_sold'      => $items,
            'items_per_order' => $orders > 0 ? round($items / $orders, 2) : null,
            'aov'             => $orders > 0 ? round($revenue / $orders, 2) : null,
            'ad_spend'        => $adSpend > 0 ? round($adSpend, 2) : null,
            'roas'            => ($adSpend > 0 && $revenue > 0)
                ? round($revenue / $adSpend, 2) : null,
            'marketing_pct'   => ($adSpend > 0 && $revenue > 0)
                ? round(($adSpend / $revenue) * 100, 1) : null,
        ];
    }

    /**
     * Tag daily rows with wl_tag based on weekday-aware peer comparison.
     *
     * Each day is compared to the average revenue for the same weekday
     * over the prior 4 weeks. Winner = above average, loser = below.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, array<string, mixed>>
     */
    private function tagDailyWinnersLosers(array $rows, int $workspaceId, array $storeIds): array
    {
        if (empty($rows)) {
            return $rows;
        }

        // Get 4-week weekday averages for all weekdays present in the data
        $storeFilter = ! empty($storeIds)
            ? 'AND s.store_id IN (' . implode(',', array_map('intval', $storeIds)) . ')'
            : '';

        // Find the date range — extend 4 weeks back from earliest row
        $dates    = array_column($rows, 'date');
        $earliest = min($dates);
        $lookback = Carbon::parse($earliest)->subWeeks(4)->toDateString();

        $avgRows = DB::select("
            SELECT
                EXTRACT(DOW FROM date)::int AS weekday,
                AVG(day_revenue)            AS avg_revenue
            FROM (
                SELECT s.date, COALESCE(SUM(s.revenue), 0) AS day_revenue
                FROM daily_snapshots s
                WHERE s.workspace_id = ?
                  AND s.date >= ?
                  AND s.date < ?
                  {$storeFilter}
                GROUP BY s.date
            ) sub
            GROUP BY EXTRACT(DOW FROM date)
        ", [$workspaceId, $lookback, $earliest]);

        $weekdayAvg = [];
        foreach ($avgRows as $r) {
            $weekdayAvg[(int) $r->weekday] = (float) $r->avg_revenue;
        }

        // Fallback for weekdays with no pre-period data (e.g. lifetime range where
        // the lookback window predates all stored data): compute the average from
        // within the range itself so those days still get a meaningful W/L tag.
        $weekdaysPresent = array_unique(array_map(
            fn (array $row) => Carbon::parse($row['date'])->dayOfWeek,
            $rows,
        ));
        $missingWeekdays = array_filter($weekdaysPresent, fn (int $d) => ! isset($weekdayAvg[$d]));

        if (! empty($missingWeekdays)) {
            $latest          = max($dates);
            $fallbackRows    = DB::select("
                SELECT
                    EXTRACT(DOW FROM date)::int AS weekday,
                    AVG(day_revenue)            AS avg_revenue
                FROM (
                    SELECT s.date, COALESCE(SUM(s.revenue), 0) AS day_revenue
                    FROM daily_snapshots s
                    WHERE s.workspace_id = ?
                      AND s.date >= ?
                      AND s.date <= ?
                      {$storeFilter}
                    GROUP BY s.date
                ) sub
                WHERE EXTRACT(DOW FROM date) = ANY(?)
                GROUP BY EXTRACT(DOW FROM date)
            ", [$workspaceId, $earliest, $latest, '{' . implode(',', $missingWeekdays) . '}']);

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
