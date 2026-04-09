<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\DailyNote;
use App\Models\Store;
use App\Services\WorkspaceContext;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class AnalyticsController extends Controller
{
    // -------------------------------------------------------------------------
    // By Product
    // -------------------------------------------------------------------------

    public function products(Request $request): InertiaResponse
    {
        $workspaceId = app(WorkspaceContext::class)->id();

        $validated = $request->validate([
            'from'      => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'to'        => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'store_ids' => ['sometimes', 'nullable', 'string'],
            'sort_by'   => ['sometimes', 'nullable', 'in:revenue,units'],
            'sort_dir'  => ['sometimes', 'nullable', 'in:asc,desc'],
        ]);

        $from     = $validated['from']   ?? now()->subDays(29)->toDateString();
        $to       = $validated['to']     ?? now()->toDateString();
        $sortBy   = $validated['sort_by']  ?? 'revenue';
        $sortDir  = strtoupper($validated['sort_dir'] ?? 'desc');
        $storeIds = $this->parseStoreIds($validated['store_ids'] ?? '', $workspaceId);

        $storeClause = ! empty($storeIds)
            ? 'AND store_id IN (' . implode(',', array_map('intval', $storeIds)) . ')'
            : '';

        $orderClause = match ($sortBy) {
            'units'   => "ORDER BY units {$sortDir} NULLS LAST, revenue DESC NULLS LAST",
            default   => "ORDER BY revenue {$sortDir} NULLS LAST, units DESC",
        };

        $rows = DB::select(
            "SELECT
                elem->>'external_id' AS external_id,
                MAX(elem->>'name')   AS name,
                SUM((elem->>'units')::integer)   AS units,
                SUM((elem->>'revenue')::numeric) AS revenue
            FROM daily_snapshots,
                jsonb_array_elements(top_products) AS elem
            WHERE workspace_id = ?
              AND date BETWEEN ? AND ?
              AND top_products IS NOT NULL
              {$storeClause}
            GROUP BY elem->>'external_id'
            {$orderClause}
            LIMIT 50",
            [$workspaceId, $from, $to],
        );

        $products = array_map(fn ($r) => [
            'external_id' => $r->external_id,
            'name'        => $r->name,
            'units'       => (int) $r->units,
            'revenue'     => $r->revenue !== null ? (float) $r->revenue : null,
        ], $rows);

        return Inertia::render('Analytics/Products', [
            'products'  => $products,
            'from'      => $from,
            'to'        => $to,
            'store_ids' => $storeIds,
            'sort_by'   => $sortBy,
            'sort_dir'  => strtolower($sortDir),
        ]);
    }

    // -------------------------------------------------------------------------
    // Daily report
    // -------------------------------------------------------------------------

    public function daily(Request $request): InertiaResponse
    {
        $workspaceId = app(WorkspaceContext::class)->id();

        $validated = $request->validate([
            'from'       => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'to'         => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'store_ids'  => ['sometimes', 'nullable', 'string'],
            'sort_by'    => ['sometimes', 'nullable', 'in:date,revenue,orders,items_sold,items_per_order,aov,ad_spend,roas,marketing_pct'],
            'sort_dir'   => ['sometimes', 'nullable', 'in:asc,desc'],
            'hide_empty' => ['sometimes', 'nullable', 'in:0,1'],
        ]);

        // Default: current month
        $from      = $validated['from']       ?? now()->startOfMonth()->toDateString();
        $to        = $validated['to']         ?? now()->toDateString();
        $sortBy    = $validated['sort_by']    ?? 'date';
        $sortDir   = $validated['sort_dir']   ?? 'desc';
        $hideEmpty = ($validated['hide_empty'] ?? '0') === '1';
        $storeIds  = $this->parseStoreIds($validated['store_ids'] ?? '', $workspaceId);

        $rows   = $this->buildDailyRows($workspaceId, $from, $to, $storeIds, $sortBy, $sortDir, $hideEmpty);
        $totals = $this->buildDailyTotals($rows);

        return Inertia::render('Analytics/Daily', [
            'rows'       => $rows,
            'totals'     => $totals,
            'from'       => $from,
            'to'         => $to,
            'store_ids'  => $storeIds,
            'sort_by'    => $sortBy,
            'sort_dir'   => $sortDir,
            'hide_empty' => $hideEmpty,
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
            $existing = DailyNote::withoutGlobalScopes()
                ->where('workspace_id', $workspaceId)
                ->where('date', $date)
                ->first();

            if ($existing) {
                $existing->update(['note' => $note, 'updated_by' => $userId]);
            } else {
                DailyNote::withoutGlobalScopes()->create([
                    'workspace_id' => $workspaceId,
                    'date'         => $date,
                    'note'         => $note,
                    'created_by'   => $userId,
                    'updated_by'   => $userId,
                ]);
            }
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
        $havingClause = $hideEmpty ? 'HAVING COALESCE(SUM(s.orders_count), 0) > 0' : '';

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
