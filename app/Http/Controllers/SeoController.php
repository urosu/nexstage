<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\DailySnapshot;
use App\Models\GscDailyStat;
use App\Models\GscPage;
use App\Models\GscQuery;
use App\Models\SearchConsoleProperty;
use App\Models\Workspace;
use App\Services\RevenueAttributionService;
use App\Services\WorkspaceContext;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class SeoController extends Controller
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
            'property_ids' => ['sometimes', 'nullable', 'string'],
            'sort'         => ['sometimes', 'nullable', 'in:clicks,impressions,ctr,position'],
            'sort_dir'     => ['sometimes', 'nullable', 'in:asc,desc'],
        ]);

        $from    = $validated['from']     ?? now()->subDays(29)->toDateString();
        $to      = $validated['to']       ?? now()->toDateString();
        $sort    = $validated['sort']     ?? 'clicks';
        $sortDir = $validated['sort_dir'] ?? 'desc';

        $properties = SearchConsoleProperty::withoutGlobalScopes()
            ->where('workspace_id', $workspaceId)
            ->select(['id', 'property_url', 'status', 'last_synced_at'])
            ->orderBy('created_at')
            ->get()
            ->map(fn ($p) => [
                'id'             => $p->id,
                'property_url'   => $p->property_url,
                'status'         => $p->status,
                'last_synced_at' => $p->last_synced_at?->toISOString(),
            ])
            ->all();

        if (empty($properties)) {
            [$totalRevenue, $unattributedRevenue] = $this->computeRevenueContext(
                $workspaceId, $workspace->has_store, $from, $to,
            );
            return Inertia::render('Seo/Index', [
                'properties'            => [],
                'selected_property_ids' => [],
                'daily_stats'           => [],
                'top_queries'           => [],
                'top_pages'             => [],
                'summary'               => null,
                'organic_revenue'       => null,
                'organic_orders'        => 0,
                'organic_cvr'           => null,
                'organic_aov'           => null,
                'total_revenue'         => $totalRevenue,
                'unattributed_revenue'  => $unattributedRevenue,
                'from'                  => $from,
                'to'                    => $to,
                'sort'                  => $sort,
                'sort_dir'              => $sortDir,
            ]);
        }

        // Parse the property_ids filter — a comma-separated list of valid IDs.
        // Empty or absent means "all properties".
        $allPropertyIds = array_column($properties, 'id');
        $requestedIds   = array_filter(
            array_map('intval', explode(',', $validated['property_ids'] ?? '')),
            fn ($id) => in_array($id, $allPropertyIds, true)
        );
        // Active filter: the valid subset, or all if none requested
        $activeIds = empty($requestedIds) ? $allPropertyIds : array_values($requestedIds);

        $lagCutoff = now()->subDays(3)->toDateString();

        // ── Daily stats ──────────────────────────────────────────────────────
        $dailyStats = GscDailyStat::withoutGlobalScopes()
            ->whereIn('property_id', $activeIds)
            ->whereBetween('date', [$from, $to])
            // Why: gsc_daily_stats stores both aggregate rows (device='all', country='ZZ')
            // and per-device/country breakdown rows. Without this filter, SUM() inflates
            // clicks/impressions by 3–4× and AVG(position) is corrupted.
            ->where('device', 'all')
            ->where('country', 'ZZ')
            ->selectRaw("
                date::text AS date,
                SUM(clicks)      AS clicks,
                SUM(impressions) AS impressions,
                CASE WHEN SUM(impressions) > 0
                    THEN SUM(clicks)::numeric / SUM(impressions)
                    ELSE NULL END AS ctr,
                CASE WHEN SUM(impressions) > 0
                    THEN SUM(position * impressions) / SUM(impressions)
                    ELSE NULL END AS position
            ")
            ->groupBy('date')
            ->orderBy('date')
            ->get()
            ->map(fn ($r) => [
                'date'        => $r->date,
                'clicks'      => (int) $r->clicks,
                'impressions' => (int) $r->impressions,
                'ctr'         => $r->ctr !== null ? round((float) $r->ctr, 4) : null,
                'position'    => $r->position !== null ? round((float) $r->position, 1) : null,
                'is_partial'  => $r->date >= $lagCutoff,
            ])
            ->all();

        // ── Summary ──────────────────────────────────────────────────────────
        $totals = GscDailyStat::withoutGlobalScopes()
            ->whereIn('property_id', $activeIds)
            ->whereBetween('date', [$from, $to])
            ->where('device', 'all')
            ->where('country', 'ZZ')
            ->selectRaw('
                COALESCE(SUM(clicks), 0)      AS total_clicks,
                COALESCE(SUM(impressions), 0) AS total_impressions,
                CASE WHEN SUM(impressions) > 0
                    THEN SUM(clicks)::numeric / SUM(impressions)
                    ELSE NULL END AS avg_ctr,
                CASE WHEN SUM(impressions) > 0
                    THEN SUM(position * impressions) / SUM(impressions)
                    ELSE NULL END AS avg_position
            ')
            ->first();

        $summary = $totals ? [
            'clicks'      => (int)   $totals->total_clicks,
            'impressions' => (int)   $totals->total_impressions,
            'ctr'         => $totals->avg_ctr      !== null ? round((float) $totals->avg_ctr, 4)      : null,
            'position'    => $totals->avg_position !== null ? round((float) $totals->avg_position, 1) : null,
        ] : null;

        // ── Top queries ──────────────────────────────────────────────────────
        $sortExpr = match ($sort) {
            'impressions' => 'SUM(impressions) ' . strtoupper($sortDir),
            'ctr'         => 'ctr ' . strtoupper($sortDir) . ' NULLS LAST',
            'position'    => 'position ' . strtoupper($sortDir) . ' NULLS LAST',
            default       => 'SUM(clicks) ' . strtoupper($sortDir),
        };

        $topQueries = GscQuery::withoutGlobalScopes()
            ->whereIn('property_id', $activeIds)
            ->whereBetween('date', [$from, $to])
            ->where('device', 'all')
            ->where('country', 'ZZ')
            ->selectRaw("
                query,
                SUM(clicks)      AS clicks,
                SUM(impressions) AS impressions,
                CASE WHEN SUM(impressions) > 0
                    THEN SUM(clicks)::numeric / SUM(impressions)
                    ELSE NULL END AS ctr,
                CASE WHEN SUM(impressions) > 0
                    THEN SUM(position * impressions) / SUM(impressions)
                    ELSE NULL END AS position
            ")
            ->groupBy('query')
            ->orderByRaw($sortExpr)
            ->limit(50)
            ->get()
            ->map(fn ($r) => [
                'query'       => $r->query,
                'clicks'      => (int)   $r->clicks,
                'impressions' => (int)   $r->impressions,
                'ctr'         => $r->ctr      !== null ? round((float) $r->ctr, 4)      : null,
                'position'    => $r->position !== null ? round((float) $r->position, 1) : null,
            ])
            ->all();

        // ── Top pages ────────────────────────────────────────────────────────
        $topPages = GscPage::withoutGlobalScopes()
            ->whereIn('property_id', $activeIds)
            ->whereBetween('date', [$from, $to])
            ->where('device', 'all')
            ->where('country', 'ZZ')
            ->selectRaw("
                page,
                SUM(clicks)      AS clicks,
                SUM(impressions) AS impressions,
                CASE WHEN SUM(impressions) > 0
                    THEN SUM(clicks)::numeric / SUM(impressions)
                    ELSE NULL END AS ctr,
                CASE WHEN SUM(impressions) > 0
                    THEN SUM(position * impressions) / SUM(impressions)
                    ELSE NULL END AS position
            ")
            ->groupBy('page')
            ->orderByRaw($sortExpr)
            ->limit(50)
            ->get()
            ->map(fn ($r) => [
                'page'        => $r->page,
                'clicks'      => (int)   $r->clicks,
                'impressions' => (int)   $r->impressions,
                'ctr'         => $r->ctr      !== null ? round((float) $r->ctr, 4)      : null,
                'position'    => $r->position !== null ? round((float) $r->position, 1) : null,
            ])
            ->all();

        [$totalRevenue, $unattributedRevenue] = $this->computeRevenueContext(
            $workspaceId, $workspace->has_store, $from, $to,
        );

        // ── Organic revenue from attribution data ────────────────────────────
        // Orders where channel_type = 'organic_search' in the attribution pipeline.
        // @see PLANNING.md section 12.5 "/seo — refinement"
        $organicRow = DB::selectOne("
            SELECT
                COALESCE(SUM(total_in_reporting_currency), 0) AS organic_revenue,
                COUNT(id) AS organic_orders
            FROM orders
            WHERE workspace_id = ?
              AND status IN ('completed', 'processing')
              AND total_in_reporting_currency IS NOT NULL
              AND attribution_last_touch->>'channel_type' = 'organic_search'
              AND occurred_at BETWEEN ? AND ?
        ", [$workspaceId, $from . ' 00:00:00', $to . ' 23:59:59']);

        $organicRevenue = (float) ($organicRow->organic_revenue ?? 0);
        $organicOrders  = (int) ($organicRow->organic_orders ?? 0);

        // Estimated organic revenue per query/page: clicks × CVR × AOV for organic orders.
        // Displayed as a range estimate.
        $organicCvr = ($summary && $summary['clicks'] > 0 && $organicOrders > 0)
            ? $organicOrders / (int) $summary['clicks']
            : null;
        $organicAov = $organicOrders > 0
            ? $organicRevenue / $organicOrders
            : null;

        return Inertia::render('Seo/Index', [
            'properties'           => $properties,
            'selected_property_ids'=> array_values($requestedIds),
            'daily_stats'          => $dailyStats,
            'top_queries'          => $topQueries,
            'top_pages'            => $topPages,
            'summary'              => $summary,
            'organic_revenue'      => $organicRevenue > 0 ? round($organicRevenue, 2) : null,
            'organic_orders'       => $organicOrders,
            'organic_cvr'          => $organicCvr !== null ? round($organicCvr, 6) : null,
            'organic_aov'          => $organicAov !== null ? round($organicAov, 2) : null,
            'total_revenue'        => $totalRevenue,
            'unattributed_revenue' => $unattributedRevenue,
            'from'                 => $from,
            'to'                   => $to,
            'sort'                 => $sort,
            'sort_dir'             => $sortDir,
        ]);
    }

    /**
     * Fetch total store revenue (from daily_snapshots) and unattributed revenue for the date range.
     *
     * Only runs when has_store is true — returns [null, null] otherwise so the
     * frontend can suppress the revenue cards entirely.
     *
     * See: PLANNING.md "Cross-Channel Page Enhancements" → SEO page
     *
     * @return array{float|null, float|null}  [total_revenue, unattributed_revenue]
     */
    private function computeRevenueContext(
        int $workspaceId,
        bool $hasStore,
        string $from,
        string $to,
    ): array {
        if (! $hasStore) {
            return [null, null];
        }

        // Revenue always from daily_snapshots, never aggregated raw orders at query time.
        // See: PLANNING.md "Key patterns"
        $snap = DailySnapshot::withoutGlobalScopes()
            ->where('workspace_id', $workspaceId)
            ->whereBetween('date', [$from, $to])
            ->selectRaw('COALESCE(SUM(revenue), 0) AS total_revenue')
            ->first();

        $totalRevenue = (float) ($snap->total_revenue ?? 0);

        $attributed = $this->attribution->getAttributedRevenue(
            $workspaceId,
            Carbon::parse($from)->startOfDay(),
            Carbon::parse($to)->endOfDay(),
        );

        $unattributed = $this->attribution->getUnattributedRevenue(
            $totalRevenue,
            $attributed['total_tagged'],
        );

        return [
            $totalRevenue > 0 ? round($totalRevenue, 2) : null,
            $unattributed > 0 ? round($unattributed, 2) : null,
        ];
    }
}
