<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Store;
use App\Services\WorkspaceContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class CountriesController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $workspaceId = app(WorkspaceContext::class)->id();

        $validated = $request->validate([
            'from'      => 'nullable|date',
            'to'        => 'nullable|date',
            'country'   => 'nullable|string|size:2|alpha',
            'store_ids' => 'nullable|string',
            'sort_by'   => 'nullable|in:revenue,country_name',
            'sort_dir'  => 'nullable|in:asc,desc',
        ]);

        $from     = $validated['from']    ?? now()->subDays(29)->toDateString();
        $to       = $validated['to']      ?? now()->toDateString();
        $country  = isset($validated['country']) ? strtoupper($validated['country']) : null;
        $sortBy   = $validated['sort_by']  ?? 'revenue';
        $sortDir  = $validated['sort_dir'] ?? 'desc';
        $storeIds = $this->parseStoreIds($validated['store_ids'] ?? '', $workspaceId);

        $storeClause  = ! empty($storeIds)
            ? 'AND store_id IN (' . implode(',', array_map('intval', $storeIds)) . ')'
            : '';

        // Aggregate revenue_by_country JSONB across selected stores (or all)
        $rows = DB::select(
            "SELECT
                kv.key                   AS country_code,
                SUM(kv.value::numeric)   AS revenue
            FROM daily_snapshots,
                jsonb_each_text(revenue_by_country) AS kv
            WHERE workspace_id = ?
              AND date BETWEEN ? AND ?
              AND revenue_by_country IS NOT NULL
              {$storeClause}
            GROUP BY kv.key
            ORDER BY revenue DESC",
            [$workspaceId, $from, $to],
        );

        $totalRevenue = (float) array_sum(array_column($rows, 'revenue'));

        $countries = array_map(fn ($c) => [
            'country_code' => strtoupper((string) $c->country_code),
            'revenue'      => (float) $c->revenue,
            'share'        => $totalRevenue > 0
                ? round(((float) $c->revenue / $totalRevenue) * 100, 1)
                : 0.0,
        ], $rows);

        // Apply requested sort (SQL default is revenue DESC; re-sort in PHP for country_name)
        if ($sortBy === 'country_name') {
            usort($countries, function (array $a, array $b) use ($sortDir): int {
                $cmp = strcmp($a['country_code'], $b['country_code']);
                return $sortDir === 'asc' ? $cmp : -$cmp;
            });
        } elseif ($sortBy === 'revenue' && $sortDir === 'asc') {
            usort($countries, fn (array $a, array $b) => $a['revenue'] <=> $b['revenue']);
        }

        // Top products for selected country — only when ?country= is set
        $topProducts = [];
        if ($country !== null && $country !== '') {
            $topStoreClause = ! empty($storeIds)
                ? 'AND o.store_id IN (' . implode(',', array_map('intval', $storeIds)) . ')'
                : '';
            $topProducts = DB::select(
                "SELECT
                    oi.product_external_id,
                    MAX(oi.product_name)  AS product_name,
                    SUM(oi.quantity)      AS units,
                    SUM(
                        CASE
                            WHEN (o.total - o.tax - o.shipping + o.discount) > 0
                            THEN oi.line_total
                                 / (o.total - o.tax - o.shipping + o.discount)
                                 * o.total_in_reporting_currency
                            ELSE NULL
                        END
                    ) AS revenue
                FROM orders o
                JOIN order_items oi ON oi.order_id = o.id
                WHERE o.workspace_id = ?
                  AND o.customer_country = ?
                  AND o.occurred_at::date BETWEEN ? AND ?
                  AND o.status IN ('completed', 'processing')
                  AND o.total_in_reporting_currency IS NOT NULL
                  {$topStoreClause}
                GROUP BY oi.product_external_id
                ORDER BY revenue DESC NULLS LAST
                LIMIT 10",
                [$workspaceId, $country, $from, $to],
            );

            $topProducts = array_map(fn ($p) => [
                'product_external_id' => $p->product_external_id,
                'product_name'        => $p->product_name,
                'units'               => (int) $p->units,
                'revenue'             => $p->revenue !== null ? (float) $p->revenue : null,
            ], $topProducts);
        }

        return Inertia::render('Countries', [
            'countries'        => $countries,
            'top_products'     => $topProducts,
            'selected_country' => $country,
            'from'             => $from,
            'to'               => $to,
            'store_ids'        => $storeIds,
            'sort_by'          => $sortBy,
            'sort_dir'         => $sortDir,
        ]);
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
