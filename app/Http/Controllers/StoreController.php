<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\UpdateStoreAction;
use App\Jobs\RunLighthouseCheckJob;
use App\Models\DailyNote;
use App\Models\LighthouseSnapshot;
use App\Models\DailySnapshot;
use App\Models\GscDailyStat;
use App\Models\GscPage;
use App\Models\GscQuery;
use App\Models\HourlySnapshot;
use App\Models\Order;
use App\Models\SearchConsoleProperty;
use App\Models\Store;
use App\Models\StoreUrl;
use App\Models\Workspace;
use App\Services\WorkspaceContext;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class StoreController extends Controller
{
    public function index(Request $request): Response
    {
        $workspaceId = app(WorkspaceContext::class)->id();

        $workspace = Workspace::withoutGlobalScopes()->find($workspaceId);

        $validated = $request->validate([
            'filter'     => ['sometimes', 'nullable', 'in:all,winners,losers'],
            'classifier' => ['sometimes', 'nullable', 'in:target,peer,period'],
        ]);
        $filter     = $validated['filter']     ?? 'all';
        $classifier = $validated['classifier'] ?? null;   // null → auto

        $stores = Store::withoutGlobalScopes()
            ->where('workspace_id', $workspaceId)
            ->select([
                'id', 'slug', 'name', 'domain', 'type', 'status', 'currency', 'timezone',
                'last_synced_at', 'historical_import_status', 'historical_import_progress',
            ])
            ->orderBy('created_at')
            ->get();

        // Current 30-day window
        $to      = now()->toDateString();
        $from    = now()->subDays(29)->toDateString();

        // Previous 30-day window (used by the 'period' classifier)
        $prevTo   = now()->subDays(30)->toDateString();
        $prevFrom = now()->subDays(59)->toDateString();

        // Revenue per store — current and previous windows
        //
        // Why workspace-level ad spend: ad_insights has no store_id, so spend is attributable
        // only at the workspace level. See: PLANNING.md "Winners/Losers" — /stores chip
        $revenueRows = DB::table('daily_snapshots')
            ->where('workspace_id', $workspaceId)
            ->whereIn('store_id', $stores->pluck('id'))
            ->whereBetween('date', [$from, $to])
            ->select(['store_id', DB::raw('SUM(revenue) as revenue')])
            ->groupBy('store_id')
            ->get()
            ->keyBy('store_id');

        $prevRevenueRows = DB::table('daily_snapshots')
            ->where('workspace_id', $workspaceId)
            ->whereIn('store_id', $stores->pluck('id'))
            ->whereBetween('date', [$prevFrom, $prevTo])
            ->select(['store_id', DB::raw('SUM(revenue) as revenue')])
            ->groupBy('store_id')
            ->get()
            ->keyBy('store_id');

        // Workspace-level ad spend — current and previous windows
        $workspaceAdSpend = (float) DB::table('ad_insights')
            ->where('workspace_id', $workspaceId)
            ->where('level', 'campaign')
            ->whereNull('hour')
            ->whereBetween('date', [$from, $to])
            ->sum('spend_in_reporting_currency');

        $prevWorkspaceAdSpend = (float) DB::table('ad_insights')
            ->where('workspace_id', $workspaceId)
            ->where('level', 'campaign')
            ->whereNull('hour')
            ->whereBetween('date', [$prevFrom, $prevTo])
            ->sum('spend_in_reporting_currency');

        $storeList = $stores->map(function ($s) use (
            $revenueRows, $prevRevenueRows, $workspaceAdSpend, $prevWorkspaceAdSpend,
        ) {
            $revenue30d = isset($revenueRows[$s->id])
                ? (float) $revenueRows[$s->id]->revenue
                : null;

            $prevRevenue30d = isset($prevRevenueRows[$s->id])
                ? (float) $prevRevenueRows[$s->id]->revenue
                : null;

            // marketing_pct = workspace ad spend / this store's revenue × 100 (lower is better)
            $marketingPct = ($revenue30d !== null && $revenue30d > 0 && $workspaceAdSpend > 0)
                ? round($workspaceAdSpend / $revenue30d * 100, 1)
                : null;

            $prevMarketingPct = ($prevRevenue30d !== null && $prevRevenue30d > 0 && $prevWorkspaceAdSpend > 0)
                ? round($prevWorkspaceAdSpend / $prevRevenue30d * 100, 1)
                : null;

            return [
                'id'                       => $s->id,
                'slug'                     => $s->slug,
                'name'                     => $s->name,
                'domain'                   => $s->domain,
                'type'                     => $s->type,
                'status'                   => $s->status,
                'currency'                 => $s->currency,
                'timezone'                 => $s->timezone,
                'last_synced_at'             => $s->last_synced_at?->toISOString(),
                'historical_import_status'   => $s->historical_import_status,
                'historical_import_progress' => $s->historical_import_progress !== null
                    ? (int) $s->historical_import_progress
                    : null,
                'revenue_30d'              => $revenue30d,
                'marketing_pct'            => $marketingPct,
                'prev_marketing_pct'       => $prevMarketingPct,
            ];
        })->all();

        // ── Winners / Losers server-side classification ──────────────────────
        // Metric for stores = marketing_pct (LOWER is better — below avg/target = winner).
        // See: PLANNING.md section 15
        $targetPct   = $workspace->target_marketing_pct !== null ? (float) $workspace->target_marketing_pct : null;
        $hasTarget   = $targetPct !== null;

        $effectiveClassifier = $classifier ?? ($hasTarget ? 'target' : 'peer');

        // Peer average: workspace-avg marketing_pct across stores with data
        $storesWithPct = array_filter($storeList, fn ($s) => $s['marketing_pct'] !== null);
        $peerAvgPct    = count($storesWithPct) > 0
            ? array_sum(array_column($storesWithPct, 'marketing_pct')) / count($storesWithPct)
            : null;

        $storeList = array_map(function (array $s) use ($effectiveClassifier, $targetPct, $peerAvgPct): array {
            $tag = match ($effectiveClassifier) {
                // Target: marketing_pct < target → efficient spend → winner
                'target' => ($targetPct !== null && $s['marketing_pct'] !== null)
                    ? ($s['marketing_pct'] < $targetPct ? 'winner' : 'loser')
                    : null,
                // Peer: marketing_pct < workspace avg → more efficient than average → winner
                'peer' => ($peerAvgPct !== null && $s['marketing_pct'] !== null)
                    ? ($s['marketing_pct'] < $peerAvgPct ? 'winner' : 'loser')
                    : null,
                // Period: marketing_pct decreased vs previous period → improved → winner
                'period' => ($s['marketing_pct'] !== null && $s['prev_marketing_pct'] !== null)
                    ? ($s['marketing_pct'] < $s['prev_marketing_pct'] ? 'winner' : 'loser')
                    : null,
                default => null,
            };
            return array_merge($s, ['wl_tag' => $tag]);
        }, $storeList);

        $totalStoreCount = count($storeList);

        // URL param is plural ('winners'/'losers'); wl_tag is singular ('winner'/'loser').
        if ($filter !== 'all') {
            $filterTag = rtrim($filter, 's');
            $storeList = array_values(
                array_filter($storeList, fn (array $s) => $s['wl_tag'] === $filterTag),
            );
        }

        return Inertia::render('Stores/Index', [
            'stores'                         => $storeList,
            'stores_total_count'             => $totalStoreCount,
            'workspace_target_marketing_pct' => $targetPct,
            'wl_has_target'                  => $hasTarget,
            'active_classifier'              => $effectiveClassifier,
            'filter'                         => $filter,
            'classifier'                     => $classifier,
        ]);
    }

    public function overview(Request $request, string $storeSlug): Response
    {
        $store = $this->resolveStore($storeSlug);

        $validated   = $this->validateDateRange($request);
        $from        = $validated['from']         ?? now()->subDays(29)->toDateString();
        $to          = $validated['to']           ?? now()->toDateString();
        $compareFrom = $validated['compare_from'] ?? null;
        $compareTo   = $validated['compare_to']   ?? null;
        $granularity = $validated['granularity']  ?? 'daily';

        $metrics        = $this->computeMetrics($store->id, $from, $to);
        $compareMetrics = ($compareFrom && $compareTo)
            ? $this->computeMetrics($store->id, $compareFrom, $compareTo)
            : null;

        $chartData        = $this->buildChartData($store->id, $from, $to, $granularity);
        $compareChartData = ($compareFrom && $compareTo)
            ? $this->buildChartData($store->id, $compareFrom, $compareTo, $granularity)
            : null;

        $hasNullFx = Order::withoutGlobalScopes()
            ->where('store_id', $store->id)
            ->whereBetween('occurred_at', [$from . ' 00:00:00', $to . ' 23:59:59'])
            ->whereIn('status', ['completed', 'processing'])
            ->whereNull('total_in_reporting_currency')
            ->exists();

        // Scope-aware: show workspace-wide notes + notes scoped to this specific store.
        $notes = DailyNote::withoutGlobalScopes()
            ->where('workspace_id', $store->workspace_id)
            ->whereBetween('date', [$from, $to])
            ->forAnnotationScope([$store->id])
            ->select(['date', 'note'])
            ->get()
            ->map(fn ($n) => ['date' => $n->date->toDateString(), 'note' => $n->note])
            ->all();

        return Inertia::render('Stores/Overview', [
            'store'              => $this->storeProps($store),
            'metrics'            => $metrics,
            'compare_metrics'    => $compareMetrics,
            'chart_data'         => $chartData,
            'compare_chart_data' => $compareChartData,
            'has_null_fx'        => $hasNullFx,
            'granularity'        => $granularity,
            'notes'              => $notes,
        ]);
    }

    public function products(Request $request, string $storeSlug): Response
    {
        $store = $this->resolveStore($storeSlug);

        $validated = $this->validateDateRange($request);
        $from      = $validated['from'] ?? now()->subDays(29)->toDateString();
        $to        = $validated['to']   ?? now()->toDateString();

        // Why: top_products JSONB dropped from daily_snapshots; query normalized table instead.
        // See: PLANNING.md "daily_snapshot_products"
        // Related: app/Jobs/ComputeDailySnapshotJob.php (writes this table)
        //
        // Previous period = same length, immediately before $from.
        // If the earliest snapshot falls after $compareFrom, deltas are returned as NULL
        // (PLANNING.md: "if compare_from falls before earliest_date, return null for deltas").
        $periodDays  = Carbon::parse($from)->diffInDays(Carbon::parse($to)) + 1;
        $compareTo   = Carbon::parse($from)->subDay()->toDateString();
        $compareFrom = Carbon::parse($compareTo)->subDays($periodDays - 1)->toDateString();

        $products = DB::select(
            "WITH current_p AS (
                SELECT product_external_id,
                       MAX(product_name) AS name,
                       SUM(units)::int   AS units,
                       SUM(revenue)      AS revenue
                FROM daily_snapshot_products
                WHERE store_id = ?
                  AND snapshot_date BETWEEN ? AND ?
                GROUP BY product_external_id
            ),
            prev_p AS (
                SELECT product_external_id,
                       SUM(units)::int AS prev_units,
                       SUM(revenue)    AS prev_revenue
                FROM daily_snapshot_products
                WHERE store_id = ?
                  AND snapshot_date BETWEEN ? AND ?
                GROUP BY product_external_id
            ),
            earliest AS (
                SELECT MIN(snapshot_date) AS earliest_date
                FROM daily_snapshot_products
                WHERE store_id = ?
            )
            SELECT
                c.product_external_id AS external_id,
                c.name,
                c.units,
                c.revenue,
                CASE
                    WHEN e.earliest_date IS NULL OR e.earliest_date > ?::date THEN NULL
                    WHEN p.prev_revenue IS NULL OR p.prev_revenue = 0          THEN NULL
                    ELSE ROUND(((c.revenue - p.prev_revenue) / p.prev_revenue * 100)::numeric, 1)
                END AS revenue_delta,
                CASE
                    WHEN e.earliest_date IS NULL OR e.earliest_date > ?::date THEN NULL
                    WHEN p.prev_units IS NULL OR p.prev_units = 0              THEN NULL
                    ELSE ROUND(((c.units - p.prev_units)::decimal / p.prev_units * 100)::numeric, 1)
                END AS units_delta,
                pr.stock_status,
                pr.stock_quantity,
                pr.image_url
            FROM current_p c
            CROSS JOIN earliest e
            LEFT JOIN prev_p p    ON p.product_external_id = c.product_external_id
            LEFT JOIN products pr ON pr.store_id = ? AND pr.external_id = c.product_external_id
            ORDER BY c.revenue DESC NULLS LAST
            LIMIT 100",
            [$store->id, $from, $to, $store->id, $compareFrom, $compareTo, $store->id, $compareFrom, $compareFrom, $store->id],
        );

        return Inertia::render('Stores/Products', [
            'store'    => $this->storeProps($store),
            'products' => array_map(fn ($p) => [
                'external_id'    => $p->external_id,
                'name'           => $p->name,
                'units'          => (int) $p->units,
                'revenue'        => (float) $p->revenue,
                'revenue_delta'  => $p->revenue_delta !== null ? (float) $p->revenue_delta : null,
                'units_delta'    => $p->units_delta   !== null ? (float) $p->units_delta   : null,
                'stock_status'   => $p->stock_status,
                'stock_quantity' => $p->stock_quantity !== null ? (int) $p->stock_quantity : null,
                'image_url'      => $p->image_url,
            ], $products),
            'from' => $from,
            'to'   => $to,
        ]);
    }

    public function countries(Request $request, string $storeSlug): Response
    {
        $store = $this->resolveStore($storeSlug);

        $validated = $this->validateDateRange($request);
        $from      = $validated['from'] ?? now()->subDays(29)->toDateString();
        $to        = $validated['to']   ?? now()->toDateString();

        // Why: revenue_by_country JSONB dropped from daily_snapshots; query orders directly.
        // shipping_country is indexed on (workspace_id, shipping_country).
        // See: PLANNING.md "Which table to query"
        $rows = DB::select(
            "SELECT
                shipping_country                   AS country_code,
                SUM(total_in_reporting_currency)   AS revenue
            FROM orders
            WHERE store_id = ?
              AND occurred_at::date BETWEEN ? AND ?
              AND status IN ('completed', 'processing')
              AND shipping_country IS NOT NULL
              AND total_in_reporting_currency IS NOT NULL
            GROUP BY shipping_country
            ORDER BY revenue DESC",
            [$store->id, $from, $to],
        );

        $totalRevenue = (float) array_sum(array_column($rows, 'revenue'));

        return Inertia::render('Stores/Countries', [
            'store'     => $this->storeProps($store),
            'countries' => array_map(fn ($c) => [
                'country_code' => $c->country_code,
                'revenue'      => (float) $c->revenue,
                'share'        => $totalRevenue > 0
                    ? round(((float) $c->revenue / $totalRevenue) * 100, 1)
                    : 0.0,
            ], $rows),
            'from' => $from,
            'to'   => $to,
        ]);
    }

    public function seo(Request $request, string $storeSlug): Response
    {
        $store = $this->resolveStore($storeSlug);

        $validated = $this->validateDateRange($request);
        $from      = $validated['from'] ?? now()->subDays(29)->toDateString();
        $to        = $validated['to']   ?? now()->toDateString();

        $property = SearchConsoleProperty::withoutGlobalScopes()
            ->where('store_id', $store->id)
            ->select(['id', 'property_url', 'status', 'last_synced_at'])
            ->first();

        if (! $property) {
            return Inertia::render('Stores/Seo', [
                'store'       => $this->storeProps($store),
                'property'    => null,
                'daily_stats' => [],
                'top_queries' => [],
                'top_pages'   => [],
                'from'        => $from,
                'to'          => $to,
            ]);
        }

        // GSC has a 2-3 day lag — mark last 3 days as potentially incomplete
        $lagCutoff = now()->subDays(3)->toDateString();

        $dailyStats = GscDailyStat::withoutGlobalScopes()
            ->where('property_id', $property->id)
            ->whereBetween('date', [$from, $to])
            // Why: filter to aggregate rows only — breakdown rows (per device/country)
            // were added in Phase 0 migration and would produce duplicate dates otherwise.
            ->where('device', 'all')
            ->where('country', 'ZZ')
            ->selectRaw('date::text AS date, clicks, impressions, ctr, position')
            ->orderBy('date')
            ->get()
            ->map(fn ($r) => [
                'date'        => $r->date,
                'clicks'      => (int) $r->clicks,
                'impressions' => (int) $r->impressions,
                'ctr'         => $r->ctr !== null ? round((float) $r->ctr, 4) : null,
                'position'    => $r->position !== null ? round((float) $r->position, 1) : null,
                'is_partial'  => $r->date >= $lagCutoff,
            ]);

        $topQueries = GscQuery::withoutGlobalScopes()
            ->where('property_id', $property->id)
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
            ->orderByRaw('SUM(clicks) DESC')
            ->limit(50)
            ->get()
            ->map(fn ($r) => [
                'query'       => $r->query,
                'clicks'      => (int) $r->clicks,
                'impressions' => (int) $r->impressions,
                'ctr'         => $r->ctr !== null ? round((float) $r->ctr, 4) : null,
                'position'    => $r->position !== null ? round((float) $r->position, 1) : null,
            ]);

        $topPages = GscPage::withoutGlobalScopes()
            ->where('property_id', $property->id)
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
            ->orderByRaw('SUM(clicks) DESC')
            ->limit(50)
            ->get()
            ->map(fn ($r) => [
                'page'        => $r->page,
                'clicks'      => (int) $r->clicks,
                'impressions' => (int) $r->impressions,
                'ctr'         => $r->ctr !== null ? round((float) $r->ctr, 4) : null,
                'position'    => $r->position !== null ? round((float) $r->position, 1) : null,
            ]);

        return Inertia::render('Stores/Seo', [
            'store'    => $this->storeProps($store),
            'property' => [
                'id'             => $property->id,
                'property_url'   => $property->property_url,
                'status'         => $property->status,
                'last_synced_at' => $property->last_synced_at?->toISOString(),
            ],
            'daily_stats' => $dailyStats,
            'top_queries' => $topQueries,
            'top_pages'   => $topPages,
            'from'        => $from,
            'to'          => $to,
        ]);
    }

    public function performance(string $storeSlug): Response
    {
        $store = $this->resolveStore($storeSlug);

        $this->authorize('update', $store);

        $storeUrls = StoreUrl::withoutGlobalScopes()
            ->where('store_id', $store->id)
            ->orderByRaw('is_homepage DESC')
            ->orderBy('created_at')
            ->get();

        // Attach latest mobile Lighthouse scores for each URL.
        $urlIds = $storeUrls->pluck('id')->all();
        $latestScores = collect();
        if (! empty($urlIds)) {
            $latestScores = LighthouseSnapshot::withoutGlobalScopes()
                ->where('workspace_id', $store->workspace_id)
                ->whereIn('store_url_id', $urlIds)
                ->where('strategy', 'mobile')
                ->selectRaw('DISTINCT ON (store_url_id) store_url_id, performance_score, lcp_ms, checked_at')
                ->orderByRaw('store_url_id, checked_at DESC')
                ->get()
                ->keyBy('store_url_id');
        }

        $urlData = $storeUrls->map(function (StoreUrl $su) use ($latestScores) {
            $snap = $latestScores->get($su->id);
            return [
                'id'                => $su->id,
                'url'               => $su->url,
                'label'             => $su->label,
                'is_homepage'       => $su->is_homepage,
                'is_active'         => $su->is_active,
                'performance_score' => $snap?->performance_score,
                'lcp_ms'            => $snap?->lcp_ms,
                'checked_at'        => $snap?->checked_at?->toISOString(),
            ];
        })->all();

        // GSC page suggestions (same as settings page)
        $workspace = Workspace::withoutGlobalScopes()->find($store->workspace_id);
        $gscSuggestions = [];
        if ($workspace?->has_gsc) {
            $existingUrls   = array_map(fn ($u) => rtrim($u, '/'), array_column($urlData, 'url'));
            $gscSuggestions = GscPage::withoutGlobalScopes()
                ->where('workspace_id', $store->workspace_id)
                ->when($existingUrls, fn ($q) => $q->whereRaw(
                    'RTRIM(page, \'/\') NOT IN (' . implode(',', array_fill(0, count($existingUrls), '?')) . ')',
                    $existingUrls
                ))
                ->groupBy(['workspace_id', 'page'])
                ->selectRaw('page, SUM(clicks) AS total_clicks')
                ->orderByDesc('total_clicks')
                ->limit(5)
                ->pluck('page')
                ->all();
        }

        return Inertia::render('Stores/Performance', [
            'store'           => $this->storeProps($store),
            'store_urls'      => $urlData,
            'gsc_suggestions' => $gscSuggestions,
        ]);
    }

    public function settings(string $storeSlug): Response
    {
        $store = $this->resolveStore($storeSlug);

        $this->authorize('update', $store);

        return Inertia::render('Stores/Settings', [
            'store' => $this->storeProps($store),
        ]);
    }

    public function addUrl(Request $request, string $storeSlug): RedirectResponse
    {
        $store = $this->resolveStore($storeSlug);

        $this->authorize('update', $store);

        $validated = $request->validate([
            'url'   => ['required', 'string', 'url', 'max:2048'],
            'label' => ['nullable', 'string', 'max:255'],
        ]);

        $count = StoreUrl::withoutGlobalScopes()
            ->where('store_id', $store->id)
            ->count();

        if ($count >= 10) {
            return back()->withErrors(['url' => 'Maximum of 10 monitored URLs per store.']);
        }

        $storeUrl = StoreUrl::withoutGlobalScopes()->updateOrCreate(
            ['store_id' => $store->id, 'url' => $validated['url']],
            [
                'workspace_id' => $store->workspace_id,
                'label'        => $validated['label'] ?? null,
                'is_homepage'  => false,
                'is_active'    => true,
            ]
        );

        // Dispatch Lighthouse checks immediately so the user sees data in minutes.
        // Both strategies are dispatched; mobile runs first (more important for SEO).
        // See: app/Jobs/RunLighthouseCheckJob.php
        foreach (['mobile', 'desktop'] as $strategy) {
            RunLighthouseCheckJob::dispatch(
                $storeUrl->id,
                $store->id,
                $store->workspace_id,
                $strategy,
            );
        }

        return back()->with('success', 'URL added. Lighthouse check queued — results appear within a few minutes.');
    }

    public function updateUrl(Request $request, string $storeSlug, int $urlId): RedirectResponse
    {
        $store = $this->resolveStore($storeSlug);

        $this->authorize('update', $store);

        $storeUrl = StoreUrl::withoutGlobalScopes()
            ->where('store_id', $store->id)
            ->findOrFail($urlId);

        $validated = $request->validate([
            'label'     => ['nullable', 'string', 'max:255'],
            'is_active' => ['required', 'boolean'],
        ]);

        $wasInactive = ! $storeUrl->is_active;
        $storeUrl->update($validated);

        // When resuming a paused URL, dispatch an immediate check so the user
        // doesn't wait until the next scheduled daily run to see fresh data.
        if ($wasInactive && $validated['is_active']) {
            foreach (['mobile', 'desktop'] as $strategy) {
                RunLighthouseCheckJob::dispatch($storeUrl->id, $store->id, $store->workspace_id, $strategy);
            }
        }

        return back()->with('success', 'URL updated.');
    }

    public function checkUrlNow(Request $request, string $storeSlug, int $urlId): RedirectResponse
    {
        $store = $this->resolveStore($storeSlug);

        $this->authorize('update', $store);

        $storeUrl = StoreUrl::withoutGlobalScopes()
            ->where('store_id', $store->id)
            ->findOrFail($urlId);

        if (! $storeUrl->is_active) {
            return back()->withErrors(['url' => 'Cannot check a paused URL.']);
        }

        foreach (['mobile', 'desktop'] as $strategy) {
            RunLighthouseCheckJob::dispatch($storeUrl->id, $store->id, $store->workspace_id, $strategy);
        }

        return back()->with('success', 'Lighthouse check queued — results appear within a few minutes.');
    }

    public function removeUrl(Request $request, string $storeSlug, int $urlId): RedirectResponse
    {
        $store = $this->resolveStore($storeSlug);

        $this->authorize('update', $store);

        $storeUrl = StoreUrl::withoutGlobalScopes()
            ->where('store_id', $store->id)
            ->findOrFail($urlId);

        if ($storeUrl->is_homepage) {
            return back()->withErrors(['url' => 'The homepage URL cannot be removed.']);
        }

        $storeUrl->delete();

        return back()->with('success', 'URL removed.');
    }

    public function update(Request $request, string $storeSlug): RedirectResponse
    {
        $store = $this->resolveStore($storeSlug);

        $this->authorize('update', $store);

        $validated = $request->validate([
            'name'     => ['required', 'string', 'max:255'],
            'slug'     => ['required', 'string', 'max:255', 'regex:/^[a-z0-9\-]+$/'],
            'timezone' => ['required', 'string', 'max:100', 'timezone'],
        ]);

        $updated = (new UpdateStoreAction)->handle($store, $validated);

        // If the slug changed, redirect to the new URL so the browser stays in sync
        if ($updated->slug !== $storeSlug) {
            return redirect()->route('stores.settings', ['slug' => $updated->slug])
                ->with('success', 'Store settings saved.');
        }

        return back()->with('success', 'Store settings saved.');
    }

    /**
     * Save or clear the store's primary country code.
     *
     * Separate endpoint so the settings page can persist the country without
     * revalidating the whole general settings form.
     *
     * @see PLANNING.md section 5.7
     */
    public function updateCountry(Request $request, string $storeSlug): RedirectResponse
    {
        $store = $this->resolveStore($storeSlug);

        $this->authorize('update', $store);

        $validated = $request->validate([
            'primary_country_code' => ['nullable', 'string', 'size:2', 'alpha'],
        ]);

        $store->update([
            'primary_country_code' => isset($validated['primary_country_code']) && $validated['primary_country_code'] !== ''
                ? strtoupper($validated['primary_country_code'])
                : null,
        ]);

        return back()->with('success', 'Primary country updated.');
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    private function resolveStore(string $slug): Store
    {
        $workspaceId = app(WorkspaceContext::class)->id();

        return Store::withoutGlobalScopes()
            ->where('workspace_id', $workspaceId)
            ->where('slug', $slug)
            ->firstOrFail();
    }

    private function validateDateRange(Request $request): array
    {
        return $request->validate([
            'from'         => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'to'           => ['sometimes', 'nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'compare_from' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'compare_to'   => ['sometimes', 'nullable', 'date_format:Y-m-d', 'after_or_equal:compare_from'],
            'granularity'  => ['sometimes', 'nullable', 'in:hourly,daily,weekly'],
        ]);
    }

    /**
     * @return array{revenue:float,orders:int,aov:float|null,items_per_order:float|null,items_sold:int,new_customers:int}
     */
    private function computeMetrics(int $storeId, string $from, string $to): array
    {
        $snap = DailySnapshot::withoutGlobalScopes()
            ->where('store_id', $storeId)
            ->whereBetween('date', [$from, $to])
            ->selectRaw('
                COALESCE(SUM(revenue), 0)       AS total_revenue,
                COALESCE(SUM(orders_count), 0)  AS total_orders,
                COALESCE(SUM(items_sold), 0)    AS total_items,
                COALESCE(SUM(new_customers), 0) AS total_new_customers
            ')
            ->first();

        $revenue = (float) ($snap->total_revenue       ?? 0);
        $orders  = (int)   ($snap->total_orders        ?? 0);
        $items   = (int)   ($snap->total_items         ?? 0);
        $newCust = (int)   ($snap->total_new_customers ?? 0);

        return [
            'revenue'         => $revenue,
            'orders'          => $orders,
            'aov'             => $orders > 0 ? round($revenue / $orders, 2) : null,
            'items_per_order' => $orders > 0 ? round($items / $orders, 2) : null,
            'items_sold'      => $items,
            'new_customers'   => $newCust,
        ];
    }

    /**
     * @return array<int, array{date: string, value: float}>
     */
    private function buildChartData(int $storeId, string $from, string $to, string $granularity): array
    {
        if ($granularity === 'hourly') {
            return HourlySnapshot::withoutGlobalScopes()
                ->where('store_id', $storeId)
                ->whereBetween('date', [$from, $to])
                ->selectRaw("
                    (date::text || 'T' || LPAD(hour::text, 2, '0') || ':00:00') AS date,
                    COALESCE(SUM(revenue), 0) AS value
                ")
                ->groupByRaw('date, hour')
                ->orderByRaw('date, hour')
                ->get()
                ->map(fn ($r) => ['date' => $r->date, 'value' => (float) $r->value])
                ->all();
        }

        if ($granularity === 'weekly') {
            return DailySnapshot::withoutGlobalScopes()
                ->where('store_id', $storeId)
                ->whereBetween('date', [$from, $to])
                ->selectRaw("DATE_TRUNC('week', date)::date::text AS date, COALESCE(SUM(revenue), 0) AS value")
                ->groupByRaw("DATE_TRUNC('week', date)")
                ->orderByRaw("DATE_TRUNC('week', date)")
                ->get()
                ->map(fn ($r) => ['date' => $r->date, 'value' => (float) $r->value])
                ->all();
        }

        // daily (default)
        return DailySnapshot::withoutGlobalScopes()
            ->where('store_id', $storeId)
            ->whereBetween('date', [$from, $to])
            ->selectRaw('date::text AS date, COALESCE(SUM(revenue), 0) AS value')
            ->groupBy('date')
            ->orderBy('date')
            ->get()
            ->map(fn ($r) => ['date' => $r->date, 'value' => (float) $r->value])
            ->all();
    }

    /**
     * @return array{id:int,slug:string,name:string,domain:string,currency:string,timezone:string,status:string,type:string}
     */
    private function storeProps(Store $store): array
    {
        return [
            'id'                   => $store->id,
            'slug'                 => $store->slug,
            'name'                 => $store->name,
            'domain'               => $store->domain,
            'currency'             => $store->currency,
            'timezone'             => $store->timezone,
            'status'               => $store->status,
            'type'                 => $store->type,
            'primary_country_code' => $store->primary_country_code,
            'website_url'          => $store->website_url,
        ];
    }
}
