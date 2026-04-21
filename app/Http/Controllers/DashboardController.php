<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\AdInsight;
use App\Models\AiSummary;
use App\Models\Alert;
use App\Models\DailyNote;
use App\Models\DailySnapshot;
use App\Models\GscDailyStat;
use App\Models\Holiday;
use App\Models\HourlySnapshot;
use App\Models\LighthouseSnapshot;
use App\Models\Order;
use App\Models\Store;
use App\Models\StoreUrl;
use App\Models\Workspace;
use App\Models\WorkspaceEvent;
use App\Services\RevenueAttributionService;
use App\Services\WorkspaceContext;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Builds the cross-channel command center (Overview / Dashboard).
 *
 * Data flow:
 *   Triggered by: GET /dashboard
 *   Reads from: daily_snapshots, hourly_snapshots, ad_insights, gsc_daily_stats, orders, alerts
 *   Returns: priority-tier metrics for Hero row + Store/Paid/Organic/Site sections
 *
 * Related: resources/js/Pages/Dashboard.tsx (priority-tier layout)
 * See: PLANNING.md "Dashboard — Cross-Channel Command Center"
 */
class DashboardController extends Controller
{
    public function __construct(
        private readonly RevenueAttributionService $attribution,
    ) {}

    public function __invoke(Request $request): Response
    {
        $workspaceId = app(WorkspaceContext::class)->id();
        $workspace   = Workspace::withoutGlobalScopes()->findOrFail($workspaceId);

        $validated = $request->validate([
            'from'         => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'to'           => ['sometimes', 'nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'compare_from' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'compare_to'   => ['sometimes', 'nullable', 'date_format:Y-m-d', 'after_or_equal:compare_from'],
            'granularity'  => ['sometimes', 'nullable', 'in:hourly,daily,weekly'],
            'store_ids'    => ['sometimes', 'nullable', 'string'],
        ]);

        $from        = $validated['from']         ?? now()->subDays(29)->toDateString();
        $to          = $validated['to']           ?? now()->toDateString();
        $compareFrom = $validated['compare_from'] ?? null;
        $compareTo   = $validated['compare_to']   ?? null;
        $granularity = $validated['granularity']  ?? 'daily';
        $storeIds    = $this->parseStoreIds($validated['store_ids'] ?? '', $workspaceId);

        // Compute primary metrics (store + paid + attribution).
        // Always run even if has_store is false — user might have only ads connected.
        $metrics        = $this->computeMetrics($workspaceId, $from, $to, $storeIds);
        $compareMetrics = ($compareFrom && $compareTo)
            ? $this->computeMetrics($workspaceId, $compareFrom, $compareTo, $storeIds)
            : null;

        // GSC metrics: only useful when GSC is connected. Run regardless so the
        // organic row can show "not connected" state based on has_gsc flag.
        $gscMetrics        = $workspace->has_gsc
            ? $this->computeGscMetrics($workspaceId, $from, $to)
            : null;
        $compareGscMetrics = ($workspace->has_gsc && $compareFrom && $compareTo)
            ? $this->computeGscMetrics($workspaceId, $compareFrom, $compareTo)
            : null;

        // PSI metrics: latest mobile snapshot for any homepage URL in the workspace.
        // Phase 1 placeholder metrics are replaced by real data here.
        // See: PLANNING.md "Performance Monitoring"
        $psiMetrics = $workspace->has_psi
            ? $this->computePsiMetrics($workspaceId)
            : null;

        $chartData        = $this->buildChartData($workspaceId, $from, $to, $granularity, $storeIds);
        $compareChartData = ($compareFrom && $compareTo)
            ? $this->buildChartData($workspaceId, $compareFrom, $compareTo, $granularity, $storeIds)
            : null;

        // Attention indicator: highest-priority unresolved, visible alert.
        // Phase 2: this will be populated by DetectAnomaliesJob. Currently null until anomaly detection runs.
        $topAlert = Alert::withoutGlobalScopes()
            ->where('workspace_id', $workspaceId)
            ->whereNull('resolved_at')
            ->where('is_silent', false)
            ->select(['type', 'severity', 'created_at'])
            ->orderByRaw("CASE severity WHEN 'critical' THEN 1 WHEN 'warning' THEN 2 ELSE 3 END")
            ->orderBy('created_at', 'desc')
            ->first();

        // How many distinct dates of snapshot data we have — used for the
        // "anomaly detection learning your baseline: X/28 days" progress indicator.
        $daysOfData = DailySnapshot::withoutGlobalScopes()
            ->where('workspace_id', $workspaceId)
            ->distinct('date')
            ->count('date');

        $aiSummary = AiSummary::withoutGlobalScopes()
            ->where('workspace_id', $workspaceId)
            ->where('date', now()->toDateString())
            ->select(['summary_text', 'generated_at'])
            ->first();

        $nullFxQuery = Order::withoutGlobalScopes()
            ->where('workspace_id', $workspaceId)
            ->whereBetween('occurred_at', [$from . ' 00:00:00', $to . ' 23:59:59'])
            ->whereIn('status', ['completed', 'processing'])
            ->whereNull('total_in_reporting_currency');
        if (! empty($storeIds)) {
            $nullFxQuery->whereIn('store_id', $storeIds);
        }
        $hasNullFx = $nullFxQuery->exists();

        // Apply scope-aware annotation filtering: workspace-scoped notes always show;
        // store-scoped notes only show when the matching store is in the active filter.
        // @see PLANNING.md section 8 (scope-aware annotations)
        $notes = DailyNote::withoutGlobalScopes()
            ->where('workspace_id', $workspaceId)
            ->whereBetween('date', [$from, $to])
            ->forAnnotationScope($storeIds)
            ->select(['date', 'note'])
            ->get()
            ->map(fn ($n) => ['date' => $n->date->toDateString(), 'note' => $n->note])
            ->all();

        // Holiday overlays: fetch from global holidays table for workspace's country.
        // Why: chart shows a gray vertical marker on holiday dates so users can
        // explain traffic/revenue dips without reaching for anomaly detection.
        // When holiday_lead_days > 0, future holidays are shifted left so the marker
        // appears X days before the actual holiday (ad ramp-up window).
        $leadDays = $workspace->workspace_settings->holidayLeadDays;
        $today    = now()->toDateString();
        // Query window shifted forward by lead_days so holidays whose marker date (actual − lead_days)
        // falls within [$from, $to] are all captured.
        $queryFrom = $leadDays > 0 ? Carbon::parse($from)->addDays($leadDays)->toDateString() : $from;
        $queryTo   = $leadDays > 0 ? Carbon::parse($to)->addDays($leadDays)->toDateString()   : $to;
        $holidays = $workspace->country
            ? Holiday::whereBetween('date', [$queryFrom, $queryTo])
                ->where('country_code', $workspace->country)
                ->select(['date', 'name', 'type'])
                ->orderBy('date')
                ->get()
                ->map(function ($h) use ($leadDays, $today) {
                    $actualDate  = $h->date->toDateString();
                    $displayDate = $leadDays > 0
                        ? $h->date->copy()->subDays($leadDays)->toDateString()
                        : $actualDate;

                    return [
                        'date'        => $displayDate,
                        'name'        => $h->name,
                        'type'        => $h->type,
                        'is_upcoming' => $leadDays > 0 && $actualDate > $today,
                        'lead_days'   => $leadDays,
                        // Shown in the label so the user knows what they're advertising for.
                        'actual_date' => $leadDays > 0 ? $h->date->format('M j') : null,
                    ];
                })
                ->all()
            : [];

        // Workspace event overlays: promotions and expected spikes/drops created manually.
        // Fetch events overlapping the date range (can start before $from or end after $to).
        // Scope filter applied: workspace-wide events always show; store/integration events
        // only show when the matching entity is in the active filter selection.
        $workspaceEvents = WorkspaceEvent::withoutGlobalScopes()
            ->where('workspace_id', $workspaceId)
            ->where('date_from', '<=', $to)
            ->where('date_to', '>=', $from)
            ->forAnnotationScope($storeIds)
            ->select(['date_from', 'date_to', 'name', 'event_type'])
            ->orderBy('date_from')
            ->get()
            ->map(fn ($e) => [
                'date_from'  => $e->date_from->toDateString(),
                'date_to'    => $e->date_to->toDateString(),
                'name'       => $e->name,
                'event_type' => $e->event_type,
            ])
            ->all();

        // Advanced paid metrics for the "Show advanced" toggle (CPM, CPC, platform conversion rate).
        // Only computed when ads are connected — avoids unnecessary queries.
        $advancedPaidMetrics = $workspace->has_ads
            ? $this->computeAdvancedPaidMetrics($workspaceId, $from, $to)
            : null;
        $compareAdvancedPaidMetrics = ($workspace->has_ads && $compareFrom && $compareTo)
            ? $this->computeAdvancedPaidMetrics($workspaceId, $compareFrom, $compareTo)
            : null;

        // Workspace targets — used by the Real row MetricCards for target notation.
        // See: PLANNING.md "Source-Tagged MetricCard — UI Primitive"
        $targets = [
            'roas'           => $workspace->target_roas           ? (float) $workspace->target_roas           : null,
            'cpo'            => $workspace->target_cpo            ? (float) $workspace->target_cpo            : null,
            'marketing_pct'  => $workspace->target_marketing_pct  ? (float) $workspace->target_marketing_pct  : null,
        ];

        // Not Tracked banner: dismissed flag stored in user's view_preferences.
        // Banner fires once per workspace until user explicitly dismisses it.
        // Why: negative Not Tracked (>5% threshold) is the highest-value trust moment in the product.
        $user = auth()->user();
        $viewPrefs = $user?->view_preferences ?? [];
        $notTrackedBannerDismissed = (bool) ($viewPrefs["not_tracked_banner_dismissed_{$workspaceId}"] ?? false);

        // UTM coverage health — green/amber/red indicator near attribution metrics.
        // Populated by ComputeUtmCoverageJob (runs on connect + nightly).
        // Only shown when workspace has both store + ads.
        $utmCoverage = ($workspace->has_store && $workspace->has_ads) ? [
            'pct'                => $workspace->utm_coverage_pct    ? (float) $workspace->utm_coverage_pct : null,
            'status'             => $workspace->utm_coverage_status ?? null,
            'checked_at'         => $workspace->utm_coverage_checked_at?->toDateTimeString(),
            'unrecognized_sources' => $workspace->utm_unrecognized_sources ?? [],
        ] : null;

        // 14-day trend dots for Real row MetricCards (only computed when targets are set).
        $trendDots = $this->computeTrendDots($workspaceId, $targets, $storeIds);

        // "Last 7 days vs prior 7 days" delta widget — only when store is connected.
        $dailyAvgDelta = $workspace->has_store
            ? $this->computeDailyAvgDelta($workspaceId, $storeIds)
            : null;

        // Latest orders feed — webhook-gated; null when no stores connected.
        $recentOrders = $workspace->has_store
            ? $this->computeRecentOrders($workspaceId, $storeIds)
            : null;

        return Inertia::render('Dashboard', [
            'psi_metrics'                   => $psiMetrics,
            'metrics'                       => $metrics,
            'compare_metrics'               => $compareMetrics,
            'gsc_metrics'                   => $gscMetrics,
            'compare_gsc_metrics'           => $compareGscMetrics,
            'advanced_paid_metrics'         => $advancedPaidMetrics,
            'compare_advanced_paid_metrics' => $compareAdvancedPaidMetrics,
            'targets'                       => $targets,
            'utm_coverage'                  => $utmCoverage,
            'not_tracked_banner_dismissed'  => $notTrackedBannerDismissed,
            'chart_data'                    => $chartData,
            'compare_chart_data'            => $compareChartData,
            'top_alert'                     => $topAlert ? [
                'type'       => $topAlert->type,
                'severity'   => $topAlert->severity,
                'created_at' => $topAlert->created_at->toDateTimeString(),
            ] : null,
            'days_of_data'                  => $daysOfData,
            'ai_summary'                    => $aiSummary,
            'has_null_fx'                   => $hasNullFx,
            'granularity'                   => $granularity,
            'store_ids'                     => $storeIds,
            'notes'                         => $notes,
            'holidays'                      => $holidays,
            'workspace_events'              => $workspaceEvents,
            // Phase 1.4 additions
            'trend_dots'                    => $trendDots,
            'daily_avg_delta'               => $dailyAvgDelta,
            'recent_orders'                 => $recentOrders,
        ]);
    }

    /**
     * Dismiss the "Not Tracked" iOS14 banner for the current user + workspace.
     *
     * Stores `not_tracked_banner_dismissed_{workspaceId} = true` in user's view_preferences JSONB.
     * Why: banner fires once per workspace; this flag prevents it from reappearing on reload.
     * See: PLANNING.md "Not Tracked" — negative sign / iOS14 banner trigger
     */
    public function dismissNotTrackedBanner(Request $request): \Illuminate\Http\JsonResponse
    {
        $workspaceId = app(WorkspaceContext::class)->id();
        $user = $request->user();
        $prefs = $user->view_preferences ?? [];
        $prefs["not_tracked_banner_dismissed_{$workspaceId}"] = true;
        $user->update(['view_preferences' => $prefs]);

        return response()->json(['ok' => true]);
    }

    /**
     * Parse a comma-separated store_ids string, verify all IDs belong to this workspace,
     * and return a clean array of integers. Returns [] meaning "all stores".
     *
     * @return int[]
     */
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

    /**
     * Aggregate snapshot + ad spend + attribution metrics for the given date range.
     *
     * Revenue and order totals come from daily_snapshots (pre-aggregated).
     * Attribution uses orders.attribution_last_touch via RevenueAttributionService.
     * Ad metrics are always workspace-level (no FK between stores and ad_accounts).
     *
     * Return shape covers all four channel rows:
     *   Store:   revenue, orders, aov, new_customers
     *   Paid:    ad_spend, roas, attributed_revenue (paid), cpo
     *   Organic: not_tracked_revenue + not_tracked_pct (signed — can be negative for iOS14 inflation)
     *   Derived: items_per_order, marketing_spend_pct
     *
     * @param int[] $storeIds Empty = all stores
     */
    private function computeMetrics(int $workspaceId, string $from, string $to, array $storeIds = []): array
    {
        $snapQuery = DailySnapshot::withoutGlobalScopes()
            ->where('workspace_id', $workspaceId)
            ->whereBetween('date', [$from, $to]);

        if (! empty($storeIds)) {
            $snapQuery->whereIn('store_id', $storeIds);
        }

        $snap = $snapQuery->selectRaw('
                COALESCE(SUM(revenue), 0)      AS total_revenue,
                COALESCE(SUM(orders_count), 0) AS total_orders,
                COALESCE(SUM(items_sold), 0)   AS total_items
            ')
            ->first();

        $revenue = (float) ($snap->total_revenue ?? 0);
        $orders  = (int)   ($snap->total_orders  ?? 0);
        $items   = (int)   ($snap->total_items   ?? 0);

        // Campaign-level daily rows only — never mix levels; always workspace-wide.
        // Why: ad_insights has campaign + ad levels; summing both would double-count.
        $adSpendRow = AdInsight::withoutGlobalScopes()
            ->where('workspace_id', $workspaceId)
            ->where('level', 'campaign')
            ->whereBetween('date', [$from, $to])
            ->whereNull('hour')
            ->selectRaw('SUM(spend_in_reporting_currency) AS spend_reporting, SUM(spend) AS spend_native')
            ->first();

        $adSpendReporting = (float) ($adSpendRow->spend_reporting ?? 0);
        // Marketing % uses native spend per PLANNING.md §Formulas.
        $adSpendNative    = (float) ($adSpendRow->spend_native    ?? 0);

        // UTM-attributed revenue — total_tagged covers all paid + other sources.
        $fromCarbon = Carbon::parse($from)->startOfDay();
        $toCarbon   = Carbon::parse($to)->endOfDay();
        $storeIdForAttribution = count($storeIds) === 1 ? $storeIds[0] : null;
        $attributed = $this->attribution->getAttributedRevenue(
            $workspaceId,
            $fromCarbon,
            $toCarbon,
            $storeIdForAttribution,
        );

        $paidAttributedRevenue = $attributed['facebook'] + $attributed['google'];
        $unattributed          = $this->attribution->getUnattributedRevenue(
            $revenue,
            $attributed['total_tagged'],
        );

        // New customers: buyers who had no orders before this period.
        // Uses a NOT EXISTS subquery to identify first-time buyers.
        $newCustomers = (int) DB::selectOne("
            SELECT COUNT(DISTINCT o.customer_id) AS cnt
            FROM orders o
            WHERE o.workspace_id = ?
              AND o.status IN ('completed', 'processing')
              AND o.customer_id IS NOT NULL
              AND o.occurred_at BETWEEN ? AND ?
              AND NOT EXISTS (
                  SELECT 1 FROM orders prev
                  WHERE prev.workspace_id = o.workspace_id
                    AND prev.customer_id  = o.customer_id
                    AND prev.occurred_at  < ?
                    AND prev.customer_id IS NOT NULL
              )
        ", [
            $workspaceId,
            $from . ' 00:00:00',
            $to   . ' 23:59:59',
            $from . ' 00:00:00',
        ])?->cnt ?? 0;

        // Not Tracked % — signed (can be negative when platforms over-report).
        // Why: negative value indicates iOS14 attribution inflation.
        // See: PLANNING.md "Not Tracked" — sign-aware display logic
        $notTrackedPct = $revenue > 0
            ? round(($unattributed / $revenue) * 100, 1)
            : null;

        return [
            // Store row
            'revenue'              => $revenue,
            'orders'               => $orders,
            'aov'                  => $orders > 0 ? round($revenue / $orders, 2) : null,
            'new_customers'        => $newCustomers,
            // Paid row
            'ad_spend'             => $adSpendReporting > 0 ? $adSpendReporting : null,
            'roas'                 => ($adSpendReporting > 0 && $revenue > 0)
                ? round($revenue / $adSpendReporting, 2)
                : null,
            'attributed_revenue'   => $paidAttributedRevenue > 0 ? round($paidAttributedRevenue, 2) : null,
            'cpo'                  => ($adSpendReporting > 0 && $orders > 0)
                ? round($adSpendReporting / $orders, 2)
                : null,
            // Organic / Not Tracked row
            'not_tracked_revenue'  => round($unattributed, 2), // signed — can be negative
            'not_tracked_pct'      => $notTrackedPct,          // signed — can be negative
            // Additional derived metrics
            'items_per_order'      => $orders > 0 ? round($items / $orders, 2) : null,
            'marketing_spend_pct'  => ($revenue > 0 && $adSpendNative > 0)
                ? round(($adSpendNative / $revenue) * 100, 1)
                : null,
        ];
    }

    /**
     * Compute advanced paid metrics for the "Show advanced metrics" toggle.
     *
     * CPM / CPC / platform conversion rate are computed on the fly — never stored.
     * Uses campaign-level rows only (no ad-level rows — see ad_insights level check).
     * Platform conversion rate = platform_conversions / clicks (null when clicks=0).
     *
     * Related: resources/js/Pages/Dashboard.tsx (advanced metrics toggle in Paid Ads row)
     * See: PLANNING.md "Show advanced metrics toggle"
     *
     * @return array{cpm:float|null,cpc:float|null,platform_conversion_rate:float|null,platform_conversions:int,impressions:int,clicks:int}
     */
    private function computeAdvancedPaidMetrics(int $workspaceId, string $from, string $to): array
    {
        $row = AdInsight::withoutGlobalScopes()
            ->where('workspace_id', $workspaceId)
            ->where('level', 'campaign')
            ->whereBetween('date', [$from, $to])
            ->whereNull('hour')
            ->selectRaw('
                COALESCE(SUM(impressions), 0)          AS total_impressions,
                COALESCE(SUM(clicks), 0)               AS total_clicks,
                COALESCE(SUM(spend_in_reporting_currency), 0) AS total_spend,
                COALESCE(SUM(platform_conversions), 0) AS total_conversions
            ')
            ->first();

        $impressions   = (int)   ($row->total_impressions  ?? 0);
        $clicks        = (int)   ($row->total_clicks       ?? 0);
        $spend         = (float) ($row->total_spend        ?? 0);
        $conversions   = (int)   ($row->total_conversions  ?? 0);

        return [
            'impressions'               => $impressions,
            'clicks'                    => $clicks,
            'platform_conversions'      => $conversions,
            // CPM = spend / impressions * 1000. NULLIF prevents divide-by-zero.
            'cpm'                       => $impressions > 0 ? round(($spend / $impressions) * 1000, 2) : null,
            // CPC = spend / clicks.
            'cpc'                       => $clicks > 0 ? round($spend / $clicks, 2) : null,
            // Platform conversion rate = platform_conversions / clicks (as %).
            'platform_conversion_rate'  => $clicks > 0 ? round(($conversions / $clicks) * 100, 2) : null,
        ];
    }

    /**
     * Aggregate GSC metrics for the organic section.
     *
     * Uses only aggregate rows (device='all', country='ZZ') to avoid double-counting
     * breakdown rows. Weighted average position: SUM(position * impressions) / SUM(impressions).
     *
     * Sums across all GSC properties in the workspace (multi-property workspaces show combined).
     */
    private function computeGscMetrics(int $workspaceId, string $from, string $to): array
    {
        $row = GscDailyStat::withoutGlobalScopes()
            ->where('workspace_id', $workspaceId)
            ->whereBetween('date', [$from, $to])
            ->where('device', 'all')
            ->where('country', 'ZZ')
            ->selectRaw('
                COALESCE(SUM(clicks), 0)      AS total_clicks,
                COALESCE(SUM(impressions), 0) AS total_impressions,
                CASE WHEN COALESCE(SUM(impressions), 0) > 0
                     THEN SUM(position * impressions) / SUM(impressions)
                     ELSE NULL END AS avg_position
            ')
            ->first();

        return [
            'gsc_clicks'      => (int)   ($row->total_clicks      ?? 0),
            'gsc_impressions' => (int)   ($row->total_impressions  ?? 0),
            'avg_position'    => $row->avg_position !== null
                ? round((float) $row->avg_position, 1)
                : null,
        ];
    }

    /**
     * Build multi-series time-series chart data.
     *
     * Each point: {date, revenue, orders, aov, roas, ad_spend, gsc_clicks}
     * hourly  → hourly_snapshots (revenue + orders only; no ad or GSC data at hourly grain)
     * weekly  → daily_snapshots grouped by week + ad_insights + gsc_daily_stats
     * daily   → daily_snapshots per day + ad_insights + gsc_daily_stats
     *
     * Ad metrics: always workspace-level.
     * GSC clicks: sum across all properties, aggregate rows only (device='all', country='ZZ').
     * Revenue/orders: filtered by store selection.
     *
     * @param int[] $storeIds Empty = all stores
     * @return array<int, array{date:string,revenue:float,orders:int,aov:float|null,roas:float|null,ad_spend:float|null,gsc_clicks:int|null}>
     */
    private function buildChartData(
        int $workspaceId,
        string $from,
        string $to,
        string $granularity,
        array $storeIds = [],
    ): array {
        $storeFilter = ! empty($storeIds)
            ? 'AND s.store_id IN (' . implode(',', array_map('intval', $storeIds)) . ')'
            : '';

        if ($granularity === 'hourly') {
            // No ad or GSC data at hourly granularity — both are daily only.
            $q = HourlySnapshot::withoutGlobalScopes()
                ->where('workspace_id', $workspaceId)
                ->whereBetween('date', [$from, $to]);
            if (! empty($storeIds)) {
                $q->whereIn('store_id', $storeIds);
            }
            return $q->selectRaw("
                    (date::text || 'T' || LPAD(hour::text, 2, '0') || ':00:00') AS date,
                    COALESCE(SUM(revenue), 0)      AS revenue,
                    COALESCE(SUM(orders_count), 0) AS orders,
                    CASE WHEN SUM(orders_count) > 0
                         THEN SUM(revenue) / SUM(orders_count) ELSE NULL END AS aov,
                    NULL::numeric AS ad_spend,
                    NULL::numeric AS roas,
                    NULL::integer AS gsc_clicks
                ")
                ->groupByRaw('date, hour')
                ->orderByRaw('date, hour')
                ->get()
                ->map(fn ($r) => $this->mapChartRow($r))
                ->all();
        }

        if ($granularity === 'weekly') {
            // Why: daily_snapshots has one row per (store_id, date). Joining a daily-level
            // ad_insights subquery and then using SUM(ai.ad_spend) in the outer weekly GROUP BY
            // would multiply ad_spend by the number of stores (one ai row fans out to N store rows).
            // Fix: aggregate both subqueries to weekly level so they produce one row per week,
            // then include them in GROUP BY (same pattern as the daily query uses ai.ad_spend
            // in GROUP BY to avoid double-counting across store rows).
            $rows = DB::select("
                SELECT
                    DATE_TRUNC('week', s.date)::date::text AS date,
                    COALESCE(SUM(s.revenue), 0)            AS revenue,
                    COALESCE(SUM(s.orders_count), 0)       AS orders,
                    CASE WHEN SUM(s.orders_count) > 0
                         THEN SUM(s.revenue) / SUM(s.orders_count) ELSE NULL END AS aov,
                    COALESCE(ai.ad_spend, 0)               AS ad_spend,
                    CASE WHEN COALESCE(ai.ad_spend, 0) > 0
                         THEN SUM(s.revenue) / ai.ad_spend ELSE NULL END AS roas,
                    COALESCE(gsc.gsc_clicks, 0)            AS gsc_clicks
                FROM daily_snapshots s
                LEFT JOIN (
                    SELECT DATE_TRUNC('week', date)::date AS week,
                           SUM(spend_in_reporting_currency) AS ad_spend
                    FROM ad_insights
                    WHERE workspace_id = ? AND level = 'campaign' AND hour IS NULL
                    GROUP BY DATE_TRUNC('week', date)
                ) ai ON ai.week = DATE_TRUNC('week', s.date)::date
                LEFT JOIN (
                    SELECT DATE_TRUNC('week', date)::date AS week,
                           SUM(clicks) AS gsc_clicks
                    FROM gsc_daily_stats
                    WHERE workspace_id = ? AND device = 'all' AND country = 'ZZ'
                    GROUP BY DATE_TRUNC('week', date)
                ) gsc ON gsc.week = DATE_TRUNC('week', s.date)::date
                WHERE s.workspace_id = ?
                  AND s.date BETWEEN ? AND ?
                  {$storeFilter}
                GROUP BY DATE_TRUNC('week', s.date), ai.ad_spend, gsc.gsc_clicks
                ORDER BY DATE_TRUNC('week', s.date)
            ", [$workspaceId, $workspaceId, $workspaceId, $from, $to]);

            return array_map(fn ($r) => $this->mapChartRow($r), $rows);
        }

        // daily (default)
        $rows = DB::select("
            SELECT
                s.date::text AS date,
                COALESCE(SUM(s.revenue), 0)      AS revenue,
                COALESCE(SUM(s.orders_count), 0) AS orders,
                CASE WHEN SUM(s.orders_count) > 0
                     THEN SUM(s.revenue) / SUM(s.orders_count) ELSE NULL END AS aov,
                COALESCE(ai.ad_spend, 0)         AS ad_spend,
                CASE WHEN COALESCE(ai.ad_spend, 0) > 0
                     THEN SUM(s.revenue) / ai.ad_spend ELSE NULL END AS roas,
                COALESCE(gsc.gsc_clicks, 0)      AS gsc_clicks
            FROM daily_snapshots s
            LEFT JOIN (
                SELECT date, SUM(spend_in_reporting_currency) AS ad_spend
                FROM ad_insights
                WHERE workspace_id = ? AND level = 'campaign' AND hour IS NULL
                GROUP BY date
            ) ai ON ai.date = s.date
            LEFT JOIN (
                SELECT date, SUM(clicks) AS gsc_clicks
                FROM gsc_daily_stats
                WHERE workspace_id = ? AND device = 'all' AND country = 'ZZ'
                GROUP BY date
            ) gsc ON gsc.date = s.date
            WHERE s.workspace_id = ?
              AND s.date BETWEEN ? AND ?
              {$storeFilter}
            GROUP BY s.date, ai.ad_spend, gsc.gsc_clicks
            ORDER BY s.date
        ", [$workspaceId, $workspaceId, $workspaceId, $from, $to]);

        return array_map(fn ($r) => $this->mapChartRow($r), $rows);
    }

    /** @param object $r */
    private function mapChartRow(object $r): array
    {
        $adSpend   = $r->ad_spend   !== null ? (float) $r->ad_spend   : null;
        $gscClicks = $r->gsc_clicks !== null ? (int)   $r->gsc_clicks : null;

        return [
            'date'       => $r->date,
            'revenue'    => (float) $r->revenue,
            'orders'     => (int)   $r->orders,
            'aov'        => $r->aov  !== null ? round((float) $r->aov,  2) : null,
            'roas'       => $r->roas !== null ? round((float) $r->roas, 2) : null,
            'ad_spend'   => ($adSpend !== null && $adSpend > 0) ? round($adSpend, 2) : null,
            'gsc_clicks' => ($gscClicks !== null && $gscClicks > 0) ? $gscClicks : null,
        ];
    }

    /**
     * Return the latest mobile Lighthouse scores for the workspace.
     *
     * Picks the most recently checked active URL (preferring homepage) and returns
     * its latest mobile snapshot. Used to populate the "Site Performance" channel row
     * on the dashboard.
     *
     * @return array{performance_score:int|null,lcp_ms:int|null,cls_score:float|null,checked_at:string|null}|null
     */
    private function computePsiMetrics(int $workspaceId): ?array
    {
        // Find the homepage URL or fall back to most recently checked URL.
        $urlId = StoreUrl::withoutGlobalScopes()
            ->where('workspace_id', $workspaceId)
            ->where('is_active', true)
            ->orderByDesc('is_homepage')
            ->value('id');

        if ($urlId === null) {
            return null;
        }

        $snap = LighthouseSnapshot::withoutGlobalScopes()
            ->where('workspace_id', $workspaceId)
            ->where('store_url_id', $urlId)
            ->where('strategy', 'mobile')
            ->orderByDesc('checked_at')
            ->select(['performance_score', 'lcp_ms', 'cls_score', 'checked_at'])
            ->first();

        if ($snap === null) {
            return null;
        }

        return [
            'performance_score' => $snap->performance_score,
            'lcp_ms'            => $snap->lcp_ms,
            'cls_score'         => $snap->cls_score ? (float) $snap->cls_score : null,
            'checked_at'        => $snap->checked_at?->toISOString(),
        ];
    }

    /**
     * Compute 14-day binary target-relative dot strips for the Real row MetricCards.
     *
     * For each metric (revenue_vs_aov, roas, cpo, marketing_pct), returns an array of
     * 14 booleans (index 0 = 14 days ago, index 13 = yesterday) indicating whether
     * that day "hit" its target. null = no data for that day.
     *
     * Only computed when workspace targets exist; returns empty arrays otherwise.
     * Phase 1.4: binary hit/miss. Phase 2+: graded (near-miss threshold).
     *
     * See: PLANNING.md "14-day trend dot strip"
     * Related: resources/js/Pages/Dashboard.tsx (trendDots prop on MetricCards)
     *
     * @param array{roas:float|null,cpo:float|null,marketing_pct:float|null} $targets
     * @param int[] $storeIds
     * @return array{roas:array<bool|null>,cpo:array<bool|null>,marketing_pct:array<bool|null>}
     */
    private function computeTrendDots(int $workspaceId, array $targets, array $storeIds = []): array
    {
        $to      = now()->subDay()->toDateString();
        $from    = now()->subDays(14)->toDateString();
        $dates   = [];
        $current = Carbon::parse($from);
        while ($current->lte(Carbon::parse($to))) {
            $dates[] = $current->toDateString();
            $current->addDay();
        }

        $storeFilter = ! empty($storeIds)
            ? 'AND s.store_id IN (' . implode(',', array_map('intval', $storeIds)) . ')'
            : '';

        $rows = DB::select("
            SELECT
                s.date::text AS date,
                COALESCE(SUM(s.revenue), 0)      AS revenue,
                COALESCE(SUM(s.orders_count), 0) AS orders,
                COALESCE(ai.ad_spend, 0)         AS ad_spend
            FROM daily_snapshots s
            LEFT JOIN (
                SELECT date, SUM(spend_in_reporting_currency) AS ad_spend
                FROM ad_insights
                WHERE workspace_id = ? AND level = 'campaign' AND hour IS NULL
                GROUP BY date
            ) ai ON ai.date = s.date
            WHERE s.workspace_id = ?
              AND s.date BETWEEN ? AND ?
              {$storeFilter}
            GROUP BY s.date, ai.ad_spend
            ORDER BY s.date
        ", [$workspaceId, $workspaceId, $from, $to]);

        // Index by date for O(1) lookup
        $byDate = [];
        foreach ($rows as $r) {
            $byDate[$r->date] = [
                'revenue'  => (float) $r->revenue,
                'orders'   => (int)   $r->orders,
                'ad_spend' => (float) $r->ad_spend,
            ];
        }

        $roasDots    = [];
        $cpoDots     = [];
        $mktPctDots  = [];

        foreach ($dates as $date) {
            $d = $byDate[$date] ?? null;

            // ROAS dot: revenue / ad_spend >= target_roas
            if ($targets['roas'] !== null && $d !== null && $d['ad_spend'] > 0) {
                $roasDots[] = ($d['revenue'] / $d['ad_spend']) >= $targets['roas'];
            } else {
                $roasDots[] = null;
            }

            // CPO dot: ad_spend / orders <= target_cpo (lower is better)
            if ($targets['cpo'] !== null && $d !== null && $d['orders'] > 0 && $d['ad_spend'] > 0) {
                $cpoDots[] = ($d['ad_spend'] / $d['orders']) <= $targets['cpo'];
            } else {
                $cpoDots[] = null;
            }

            // Marketing % dot: ad_spend / revenue * 100 <= target_marketing_pct (lower is better)
            if ($targets['marketing_pct'] !== null && $d !== null && $d['revenue'] > 0) {
                $mktPctDots[] = (($d['ad_spend'] / $d['revenue']) * 100) <= $targets['marketing_pct'];
            } else {
                $mktPctDots[] = null;
            }
        }

        return [
            'roas'           => $roasDots,
            'cpo'            => $cpoDots,
            'marketing_pct'  => $mktPctDots,
        ];
    }

    /**
     * Compute the "Last 7 days vs prior 7 days" daily average delta widget data.
     *
     * Returns avg daily revenue and orders for the last 7 days and the prior 7 days,
     * plus the percentage change between them.
     *
     * Why: shows momentum ("is the last week trending up or down vs the week before?")
     * at a glance without the noise of the full date-range comparison.
     * See: PLANNING.md "Daily average delta block" (Phase 1.4 widget)
     *
     * @param int[] $storeIds
     * @return array{last7_avg_revenue:float|null,prev7_avg_revenue:float|null,revenue_delta_pct:float|null,last7_avg_orders:float|null,prev7_avg_orders:float|null,orders_delta_pct:float|null}
     */
    private function computeDailyAvgDelta(int $workspaceId, array $storeIds = []): array
    {
        $last7To   = now()->subDay()->toDateString();
        $last7From = now()->subDays(7)->toDateString();
        $prev7To   = now()->subDays(8)->toDateString();
        $prev7From = now()->subDays(14)->toDateString();

        $query = DailySnapshot::withoutGlobalScopes()
            ->where('workspace_id', $workspaceId);

        if (! empty($storeIds)) {
            $query->whereIn('store_id', $storeIds);
        }

        $last7 = (clone $query)
            ->whereBetween('date', [$last7From, $last7To])
            ->selectRaw('COALESCE(SUM(revenue),0)/7.0 AS avg_revenue, COALESCE(SUM(orders_count),0)/7.0 AS avg_orders')
            ->first();

        $prev7 = (clone $query)
            ->whereBetween('date', [$prev7From, $prev7To])
            ->selectRaw('COALESCE(SUM(revenue),0)/7.0 AS avg_revenue, COALESCE(SUM(orders_count),0)/7.0 AS avg_orders')
            ->first();

        $last7Rev  = $last7 ? (float) $last7->avg_revenue  : null;
        $prev7Rev  = $prev7 ? (float) $prev7->avg_revenue  : null;
        $last7Ord  = $last7 ? (float) $last7->avg_orders   : null;
        $prev7Ord  = $prev7 ? (float) $prev7->avg_orders   : null;

        return [
            'last7_avg_revenue'   => $last7Rev > 0 ? round($last7Rev, 2) : null,
            'prev7_avg_revenue'   => $prev7Rev > 0 ? round($prev7Rev, 2) : null,
            'revenue_delta_pct'   => ($last7Rev !== null && $prev7Rev > 0)
                ? round((($last7Rev - $prev7Rev) / $prev7Rev) * 100, 1)
                : null,
            'last7_avg_orders'    => $last7Ord > 0 ? round($last7Ord, 1) : null,
            'prev7_avg_orders'    => $prev7Ord > 0 ? round($prev7Ord, 1) : null,
            'orders_delta_pct'    => ($last7Ord !== null && $prev7Ord > 0)
                ? round((($last7Ord - $prev7Ord) / $prev7Ord) * 100, 1)
                : null,
        ];
    }

    /**
     * Fetch the latest orders for the "Latest orders feed" widget.
     *
     * Only shown when at least one connected store has active webhooks — polling stores
     * show a "Enable webhooks for live orders" nudge instead.
     * Returns null when no stores are connected.
     *
     * Why: webhook-gated to avoid showing stale data as "live." Honest labeling is the
     * trust mechanism. See: PLANNING.md "Latest orders feed" (Phase 1.4 widget)
     *
     * @param int[] $storeIds
     * @return array{orders:list<array{id:int,order_number:string|null,status:string,total:float,currency:string,occurred_at:string}>,feed_source:'webhook'|'polling'|null,last_synced_at:string|null}|null
     */
    private function computeRecentOrders(int $workspaceId, array $storeIds = []): ?array
    {
        // Find stores and their webhook status
        $storeQuery = Store::withoutGlobalScopes()
            ->where('workspace_id', $workspaceId)
            ->where('status', 'active');

        if (! empty($storeIds)) {
            $storeQuery->whereIn('id', $storeIds);
        }

        $stores = $storeQuery->select(['id', 'last_synced_at'])->get();

        if ($stores->isEmpty()) {
            return null;
        }

        // Check if any active store has registered webhooks
        // Why: we only show "Live via webhook" when webhooks are confirmed active.
        $hasWebhooks = \App\Models\StoreWebhook::withoutGlobalScopes()
            ->whereIn('store_id', $stores->pluck('id'))
            ->whereNull('deleted_at')
            ->exists();

        $feedSource = $hasWebhooks ? 'webhook' : 'polling';

        $lastSyncedAt = $stores->max('last_synced_at');

        $orderQuery = Order::withoutGlobalScopes()
            ->where('workspace_id', $workspaceId)
            ->whereIn('status', ['completed', 'processing'])
            ->orderByDesc('occurred_at');

        if (! empty($storeIds)) {
            $orderQuery->whereIn('store_id', $storeIds);
        }

        $orders = $orderQuery
            ->select(['id', 'external_number', 'status', 'total_in_reporting_currency', 'total', 'currency', 'occurred_at'])
            ->limit(10)
            ->get()
            ->map(fn ($o) => [
                'id'           => $o->id,
                'order_number' => $o->external_number,
                'status'       => $o->status,
                'total'        => $o->total_in_reporting_currency !== null
                    ? (float) $o->total_in_reporting_currency
                    : (float) $o->total,
                'currency'     => $o->currency,
                'occurred_at'  => $o->occurred_at->toISOString(),
            ])
            ->all();

        return [
            'orders'         => $orders,
            'feed_source'    => $feedSource,
            'last_synced_at' => $lastSyncedAt?->toISOString(),
        ];
    }
}
