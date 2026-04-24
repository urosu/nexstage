<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\DailySnapshot;
use App\Models\GscDailyStat;
use App\Models\GscPage;
use App\Models\GscQuery;
use App\Models\SearchConsoleProperty;
use App\Models\Workspace;
use App\Services\NarrativeTemplateService;
use App\Services\RevenueAttributionService;
use App\Services\WorkspaceContext;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class SeoController extends Controller
{
    public function __construct(
        private readonly RevenueAttributionService $attribution,
        private readonly NarrativeTemplateService  $narrative,
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
                'opportunities'         => ['trending_up' => [], 'needs_attention' => []],
                'narrative'             => null,
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

        // ── Opportunity detection (Phase 3.3) ───────────────────────────────
        [$opportunities, $badgeMap, $counts] = $this->computeOpportunities(
            $activeIds, $from, $to, $workspaceId,
        );

        // Augment top_queries with opportunity badge
        $topQueriesAugmented = array_map(
            fn ($r) => [...$r, 'opportunity' => $badgeMap[$r['query']] ?? null],
            $topQueries,
        );

        // ── WoW clicks delta for narrative ───────────────────────────────────
        $recentWeekStart = Carbon::parse($to)->subDays(6)->toDateString();
        $prevWeekStart   = Carbon::parse($to)->subDays(13)->toDateString();
        $prevWeekEnd     = Carbon::parse($to)->subDays(7)->toDateString();

        $recentClicks = GscDailyStat::withoutGlobalScopes()
            ->whereIn('property_id', $activeIds)
            ->whereBetween('date', [$recentWeekStart, $to])
            ->where('device', 'all')->where('country', 'ZZ')
            ->sum('clicks');

        $prevClicks = GscDailyStat::withoutGlobalScopes()
            ->whereIn('property_id', $activeIds)
            ->whereBetween('date', [$prevWeekStart, $prevWeekEnd])
            ->where('device', 'all')->where('country', 'ZZ')
            ->sum('clicks');

        $clicksDeltaPct = ($prevClicks > 0)
            ? (($recentClicks - $prevClicks) / $prevClicks) * 100
            : null;

        // ── Narrative ────────────────────────────────────────────────────────
        $pageNarrative = $this->narrative->forSeo(
            $clicksDeltaPct,
            $counts['striking_distance'],
            $counts['leaking'],
        );

        return Inertia::render('Seo/Index', [
            'properties'           => $properties,
            'selected_property_ids'=> array_values($requestedIds),
            'daily_stats'          => $dailyStats,
            'top_queries'          => $topQueriesAugmented,
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
            'opportunities'        => $opportunities,
            'narrative'            => $pageNarrative,
        ]);
    }

    /**
     * Detect organic opportunities for all queries in the selected date range.
     *
     * Returns [panel, badgeMap, counts] where:
     *   - panel  = ['trending_up' => [...], 'needs_attention' => [...]]
     *   - badgeMap = ['query text' => 'badge_type'|null, ...]
     *   - counts = ['striking_distance' => N, 'leaking' => N, ...]
     *
     * Badge precedence per §F17: paid_organic_overlap > leaking > striking_distance > rising
     *
     * @param  int[]    $propertyIds
     * @return array{array, array<string, string|null>, array<string, int>}
     *
     * @see PROGRESS.md §F14, §F15, §F17
     */
    private function computeOpportunities(
        array $propertyIds,
        string $from,
        string $to,
        int $workspaceId,
    ): array {
        if (empty($propertyIds)) {
            return [['trending_up' => [], 'needs_attention' => []], [], []];
        }

        // Static benchmark lookup (7 rows, always seeded)
        $benchmarks = DB::table('gsc_ctr_benchmarks')->pluck('expected_ctr', 'position_bucket');

        // Active campaign targets for paid-organic overlap (§F17).
        // Use selectRaw + alias so pluck() can reference the column name (not a raw expr object).
        $campaignTargets = DB::table('campaigns')
            ->where('workspace_id', $workspaceId)
            ->where('status', 'ACTIVE')
            ->whereRaw("parsed_convention->>'target' IS NOT NULL")
            ->selectRaw("LOWER(parsed_convention->>'target') AS target_lower")
            ->pluck('target_lower')
            ->filter()
            ->values();

        // Recent 7d vs prior 7d windows for Rising / position-worsening detection
        $recentWeekStart = Carbon::parse($to)->subDays(6)->toDateString();
        $prevWeekStart   = Carbon::parse($to)->subDays(13)->toDateString();
        $prevWeekEnd     = Carbon::parse($to)->subDays(7)->toDateString();

        $inList  = implode(',', array_fill(0, count($propertyIds), '?'));

        // Binding order must match ? positions in the query: SELECT bindings first, then WHERE.
        // 4 × $recentWeekStart (one per date >= ? in SELECT) + 3 pairs of prev window + WHERE params.
        $bindings = [
            $recentWeekStart,             // recent_impressions: date >= ?
            $prevWeekStart, $prevWeekEnd, // prev_impressions: date BETWEEN ? AND ?
            $recentWeekStart,             // recent_position outer CASE: date >= ?
            $recentWeekStart,             // recent_position numerator SUM: date >= ?
            $recentWeekStart,             // recent_position NULLIF: date >= ?
            $prevWeekStart, $prevWeekEnd, // prev_position outer CASE: date BETWEEN ? AND ?
            $prevWeekStart, $prevWeekEnd, // prev_position numerator SUM: date BETWEEN ? AND ?
            $prevWeekStart, $prevWeekEnd, // prev_position NULLIF: date BETWEEN ? AND ?
            ...$propertyIds,              // WHERE property_id IN (...)
            $from, $to,                   // WHERE date BETWEEN ? AND ?
        ];

        /** @var array<object> $rows */
        $rows = DB::select("
            SELECT
                query,
                SUM(clicks)      AS clicks,
                SUM(impressions) AS impressions,
                CASE WHEN SUM(impressions) > 0
                    THEN SUM(clicks::numeric) / SUM(impressions)
                    ELSE NULL END AS ctr,
                CASE WHEN SUM(impressions) > 0
                    THEN SUM(position * impressions) / SUM(impressions)
                    ELSE NULL END AS position,
                SUM(CASE WHEN date >= ? THEN impressions ELSE 0 END)              AS recent_impressions,
                SUM(CASE WHEN date BETWEEN ? AND ? THEN impressions ELSE 0 END)   AS prev_impressions,
                CASE WHEN SUM(CASE WHEN date >= ? THEN impressions ELSE 0 END) > 0
                    THEN SUM(CASE WHEN date >= ? THEN position * impressions ELSE 0 END)::numeric
                         / NULLIF(SUM(CASE WHEN date >= ? THEN impressions ELSE 0 END), 0)
                    ELSE NULL END AS recent_position,
                CASE WHEN SUM(CASE WHEN date BETWEEN ? AND ? THEN impressions ELSE 0 END) > 0
                    THEN SUM(CASE WHEN date BETWEEN ? AND ? THEN position * impressions ELSE 0 END)::numeric
                         / NULLIF(SUM(CASE WHEN date BETWEEN ? AND ? THEN impressions ELSE 0 END), 0)
                    ELSE NULL END AS prev_position
            FROM gsc_queries
            WHERE property_id IN ({$inList})
              AND date BETWEEN ? AND ?
              AND device = 'all'
              AND country = 'ZZ'
            GROUP BY query
        ", $bindings);

        $badgeMap = [];
        $counts   = [
            'striking_distance'    => 0,
            'rising'               => 0,
            'leaking'              => 0,
            'paid_organic_overlap' => 0,
            'position_worsening'   => 0,
        ];

        foreach ($rows as $row) {
            $pos         = $row->position !== null ? (float) $row->position : null;
            $ctr         = $row->ctr !== null ? (float) $row->ctr : null;
            $impressions = (int) $row->impressions;
            $recentImp   = (int) $row->recent_impressions;
            $prevImp     = (int) $row->prev_impressions;
            $recentPos   = $row->recent_position !== null ? (float) $row->recent_position : null;
            $prevPos     = $row->prev_position   !== null ? (float) $row->prev_position   : null;

            $bucket       = $pos !== null ? $this->positionBucket($pos) : null;
            $benchmarkCtr = $bucket !== null ? (float) ($benchmarks[$bucket] ?? 0) : 0.0;

            // §F17 precedence: paid_organic_overlap > leaking > striking_distance > rising
            $badge = null;

            if ($pos !== null && $pos <= 5) {
                $queryLower = mb_strtolower($row->query);
                foreach ($campaignTargets as $target) {
                    if (str_contains((string) $target, $queryLower) || str_contains($queryLower, (string) $target)) {
                        $badge = 'paid_organic_overlap';
                        break;
                    }
                }
            }

            if ($badge === null && $pos !== null && $pos >= 1 && $pos <= 5 && $ctr !== null && $benchmarkCtr > 0 && $ctr < $benchmarkCtr * 0.7) {
                $badge = 'leaking';
            }

            if ($badge === null && $pos !== null && $pos >= 11 && $pos <= 20 && $impressions >= 100) {
                $badge = 'striking_distance';
            }

            if ($badge === null && $recentImp >= 100 && (
                ($prevImp > 0 && $recentImp >= $prevImp * 1.5)
                || ($recentPos !== null && $prevPos !== null && $prevPos - $recentPos >= 3)
            )) {
                $badge = 'rising';
            }

            $badgeMap[$row->query] = $badge;
            if ($badge !== null) {
                $counts[$badge]++;
            }

            // §F15 position worsening — panel only, no badge
            if ($recentPos !== null && $prevPos !== null && $recentPos - $prevPos >= 3) {
                $counts['position_worsening']++;
            }
        }

        // Build panel items (omit entries with 0 count)
        $trendingUp = [];
        if ($counts['rising'] > 0) {
            $n = $counts['rising'];
            $trendingUp[] = [
                'type'  => 'rising',
                'count' => $n,
                'label' => ($n === 1 ? '1 query' : "{$n} queries") . ' trending up (impressions +50% WoW)',
            ];
        }
        if ($counts['striking_distance'] > 0) {
            $n = $counts['striking_distance'];
            $trendingUp[] = [
                'type'  => 'striking_distance',
                'count' => $n,
                'label' => ($n === 1 ? '1 query' : "{$n} queries") . ' in striking distance (positions 11–20)',
            ];
        }

        $needsAttention = [];
        if ($counts['leaking'] > 0) {
            $n = $counts['leaking'];
            $needsAttention[] = [
                'type'  => 'leaking',
                'count' => $n,
                'label' => ($n === 1 ? '1 query' : "{$n} queries") . ' leaking CTR (top-5 rank, below-par CTR)',
            ];
        }
        if ($counts['position_worsening'] > 0) {
            $n = $counts['position_worsening'];
            $needsAttention[] = [
                'type'  => 'position_worsening',
                'count' => $n,
                'label' => ($n === 1 ? '1 query' : "{$n} queries") . ' losing position (≥3 places this week)',
            ];
        }
        if ($counts['paid_organic_overlap'] > 0) {
            $n = $counts['paid_organic_overlap'];
            $needsAttention[] = [
                'type'  => 'paid_organic_overlap',
                'count' => $n,
                'label' => ($n === 1 ? '1 query' : "{$n} queries") . ' overlap with active paid campaigns',
            ];
        }

        return [
            ['trending_up' => $trendingUp, 'needs_attention' => $needsAttention],
            $badgeMap,
            $counts,
        ];
    }

    /**
     * Map an impression-weighted average position to the §M5 benchmark bucket key.
     *
     * Buckets mirror the gsc_ctr_benchmarks.position_bucket values seeded by
     * GscCtrBenchmarksSeeder. Position is a float average — bucket cutoffs use
     * whole-number boundaries.
     *
     * @see PROGRESS.md §M5
     */
    private function positionBucket(float $position): string
    {
        if ($position < 2)  return '1';
        if ($position < 3)  return '2';
        if ($position < 4)  return '3';
        if ($position < 6)  return '4-5';
        if ($position < 11) return '6-10';
        if ($position < 21) return '11-20';
        return '21+';
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
