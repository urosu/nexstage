<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\UpdateStoreAction;
use App\Models\DailyNote;
use App\Models\DailySnapshot;
use App\Models\GscDailyStat;
use App\Models\GscPage;
use App\Models\GscQuery;
use App\Models\HourlySnapshot;
use App\Models\Order;
use App\Models\SearchConsoleProperty;
use App\Models\Store;
use App\Services\WorkspaceContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class StoreController extends Controller
{
    public function index(): Response
    {
        $workspaceId = app(WorkspaceContext::class)->id();

        $stores = Store::withoutGlobalScopes()
            ->where('workspace_id', $workspaceId)
            ->select([
                'id', 'slug', 'name', 'domain', 'type', 'status', 'currency', 'timezone',
                'last_synced_at', 'historical_import_status',
            ])
            ->orderBy('created_at')
            ->get()
            ->map(fn ($s) => [
                'id'                       => $s->id,
                'slug'                     => $s->slug,
                'name'                     => $s->name,
                'domain'                   => $s->domain,
                'type'                     => $s->type,
                'status'                   => $s->status,
                'currency'                 => $s->currency,
                'timezone'                 => $s->timezone,
                'last_synced_at'           => $s->last_synced_at?->toISOString(),
                'historical_import_status' => $s->historical_import_status,
            ]);

        return Inertia::render('Stores/Index', [
            'stores' => $stores,
        ]);
    }

    public function overview(Request $request, string $slug): Response
    {
        $store = $this->resolveStore($slug);

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

        $notes = DailyNote::withoutGlobalScopes()
            ->where('workspace_id', $store->workspace_id)
            ->whereBetween('date', [$from, $to])
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

    public function products(Request $request, string $slug): Response
    {
        $store = $this->resolveStore($slug);

        $validated = $this->validateDateRange($request);
        $from      = $validated['from'] ?? now()->subDays(29)->toDateString();
        $to        = $validated['to']   ?? now()->toDateString();

        // Aggregate top_products JSONB across daily_snapshots for the period
        $products = DB::select(
            "SELECT
                elem->>'external_id'              AS external_id,
                MAX(elem->>'name')                AS name,
                SUM((elem->>'units')::int)        AS units,
                SUM((elem->>'revenue')::numeric)  AS revenue
            FROM daily_snapshots,
                jsonb_array_elements(top_products) AS elem
            WHERE store_id = ?
              AND date BETWEEN ? AND ?
              AND top_products IS NOT NULL
            GROUP BY elem->>'external_id'
            ORDER BY revenue DESC
            LIMIT 100",
            [$store->id, $from, $to],
        );

        return Inertia::render('Stores/Products', [
            'store'    => $this->storeProps($store),
            'products' => array_map(fn ($p) => [
                'external_id' => $p->external_id,
                'name'        => $p->name,
                'units'       => (int) $p->units,
                'revenue'     => (float) $p->revenue,
            ], $products),
            'from' => $from,
            'to'   => $to,
        ]);
    }

    public function countries(Request $request, string $slug): Response
    {
        $store = $this->resolveStore($slug);

        $validated = $this->validateDateRange($request);
        $from      = $validated['from'] ?? now()->subDays(29)->toDateString();
        $to        = $validated['to']   ?? now()->toDateString();

        // Aggregate revenue_by_country JSONB across daily_snapshots for the period
        $rows = DB::select(
            "SELECT
                kv.key                   AS country_code,
                SUM(kv.value::numeric)   AS revenue
            FROM daily_snapshots,
                jsonb_each_text(revenue_by_country) AS kv
            WHERE store_id = ?
              AND date BETWEEN ? AND ?
              AND revenue_by_country IS NOT NULL
            GROUP BY kv.key
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

    public function seo(Request $request, string $slug): Response
    {
        $store = $this->resolveStore($slug);

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
            ->selectRaw("
                query,
                SUM(clicks)      AS clicks,
                SUM(impressions) AS impressions,
                CASE WHEN SUM(impressions) > 0
                    THEN SUM(clicks)::numeric / SUM(impressions)
                    ELSE NULL END AS ctr,
                AVG(position)    AS position
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
            ->selectRaw("
                page,
                SUM(clicks)      AS clicks,
                SUM(impressions) AS impressions,
                CASE WHEN SUM(impressions) > 0
                    THEN SUM(clicks)::numeric / SUM(impressions)
                    ELSE NULL END AS ctr,
                AVG(position)    AS position
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

    public function settings(string $slug): Response
    {
        $store = $this->resolveStore($slug);

        $this->authorize('update', $store);

        return Inertia::render('Stores/Settings', [
            'store' => $this->storeProps($store),
        ]);
    }

    public function update(Request $request, string $slug): RedirectResponse
    {
        $store = $this->resolveStore($slug);

        $this->authorize('update', $store);

        $validated = $request->validate([
            'name'     => ['required', 'string', 'max:255'],
            'slug'     => ['required', 'string', 'max:255', 'regex:/^[a-z0-9\-]+$/'],
            'timezone' => ['required', 'string', 'max:100', 'timezone'],
        ]);

        $updated = (new UpdateStoreAction)->handle($store, $validated);

        // If the slug changed, redirect to the new URL so the browser stays in sync
        if ($updated->slug !== $slug) {
            return redirect()->route('stores.settings', $updated->slug)
                ->with('success', 'Store settings saved.');
        }

        return back()->with('success', 'Store settings saved.');
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
            'to'           => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'compare_from' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'compare_to'   => ['sometimes', 'nullable', 'date_format:Y-m-d'],
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
            'id'       => $store->id,
            'slug'     => $store->slug,
            'name'     => $store->name,
            'domain'   => $store->domain,
            'currency' => $store->currency,
            'timezone' => $store->timezone,
            'status'   => $store->status,
            'type'     => $store->type,
        ];
    }
}
