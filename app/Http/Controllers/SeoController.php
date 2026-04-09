<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\GscDailyStat;
use App\Models\GscPage;
use App\Models\GscQuery;
use App\Models\SearchConsoleProperty;
use App\Services\WorkspaceContext;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SeoController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $workspaceId = app(WorkspaceContext::class)->id();

        $validated = $request->validate([
            'from'        => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'to'          => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'property_id' => ['sometimes', 'nullable', 'integer'],
            'sort'        => ['sometimes', 'nullable', 'in:clicks,impressions,ctr,position'],
            'sort_dir'    => ['sometimes', 'nullable', 'in:asc,desc'],
        ]);

        $from       = $validated['from']     ?? now()->subDays(29)->toDateString();
        $to         = $validated['to']       ?? now()->toDateString();
        $sort       = $validated['sort']     ?? 'clicks';
        $sortDir    = $validated['sort_dir'] ?? 'desc';

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
            return Inertia::render('Seo/Index', [
                'properties'         => [],
                'selected_property'  => null,
                'daily_stats'        => [],
                'top_queries'        => [],
                'top_pages'          => [],
                'summary'            => null,
                'from'               => $from,
                'to'                 => $to,
                'sort'               => $sort,
                'sort_dir'           => $sortDir,
            ]);
        }

        // Resolve selected property — default to null (all), or specific if provided and valid
        $propertyIds = array_column($properties, 'id');
        $selectedId  = isset($validated['property_id'])
            ? (in_array((int) $validated['property_id'], $propertyIds) ? (int) $validated['property_id'] : null)
            : null;

        $selectedProperty = $selectedId
            ? collect($properties)->firstWhere('id', $selectedId)
            : null;

        $lagCutoff = now()->subDays(3)->toDateString();

        // ── Daily stats ──────────────────────────────────────────────────────
        $dailyStats = GscDailyStat::withoutGlobalScopes()
            ->when($selectedId, fn ($q) => $q->where('property_id', $selectedId),
                   fn ($q) => $q->whereIn('property_id', $propertyIds))
            ->whereBetween('date', [$from, $to])
            ->selectRaw("
                date::text AS date,
                SUM(clicks)      AS clicks,
                SUM(impressions) AS impressions,
                CASE WHEN SUM(impressions) > 0
                    THEN SUM(clicks)::numeric / SUM(impressions)
                    ELSE NULL END AS ctr,
                AVG(position) AS position
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
            ->when($selectedId, fn ($q) => $q->where('property_id', $selectedId),
                   fn ($q) => $q->whereIn('property_id', $propertyIds))
            ->whereBetween('date', [$from, $to])
            ->selectRaw('
                COALESCE(SUM(clicks), 0)      AS total_clicks,
                COALESCE(SUM(impressions), 0) AS total_impressions,
                CASE WHEN SUM(impressions) > 0
                    THEN SUM(clicks)::numeric / SUM(impressions)
                    ELSE NULL END AS avg_ctr,
                AVG(position) AS avg_position
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
            ->when($selectedId, fn ($q) => $q->where('property_id', $selectedId),
                   fn ($q) => $q->whereIn('property_id', $propertyIds))
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
            ->when($selectedId, fn ($q) => $q->where('property_id', $selectedId),
                   fn ($q) => $q->whereIn('property_id', $propertyIds))
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

        return Inertia::render('Seo/Index', [
            'properties'        => $properties,
            'selected_property' => $selectedProperty,
            'daily_stats'       => $dailyStats,
            'top_queries'       => $topQueries,
            'top_pages'         => $topPages,
            'summary'           => $summary,
            'from'              => $from,
            'to'                => $to,
            'sort'              => $sort,
            'sort_dir'          => $sortDir,
        ]);
    }
}
