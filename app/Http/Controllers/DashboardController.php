<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\AdAccount;
use App\Models\AdInsight;
use App\Models\AiSummary;
use App\Models\DailyNote;
use App\Models\DailySnapshot;
use App\Models\HourlySnapshot;
use App\Models\Order;
use App\Models\Store;
use App\Services\WorkspaceContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $workspaceId = app(WorkspaceContext::class)->id();

        $validated = $request->validate([
            'from'         => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'to'           => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'compare_from' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'compare_to'   => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'granularity'  => ['sometimes', 'nullable', 'in:hourly,daily,weekly'],
            'store_ids'    => ['sometimes', 'nullable', 'string'],
        ]);

        $from        = $validated['from']         ?? now()->subDays(29)->toDateString();
        $to          = $validated['to']           ?? now()->toDateString();
        $compareFrom = $validated['compare_from'] ?? null;
        $compareTo   = $validated['compare_to']   ?? null;
        $granularity = $validated['granularity']  ?? 'daily';
        $storeIds    = $this->parseStoreIds($validated['store_ids'] ?? '', $workspaceId);

        $hasStores = Store::withoutGlobalScopes()
            ->where('workspace_id', $workspaceId)
            ->where('status', 'active')
            ->exists();

        $hasAdAccounts = AdAccount::withoutGlobalScopes()
            ->where('workspace_id', $workspaceId)
            ->exists();

        if (! $hasStores) {
            return Inertia::render('Dashboard', [
                'has_stores'              => false,
                'show_ad_accounts_banner' => false,
                'metrics'                 => null,
                'compare_metrics'         => null,
                'chart_data'              => [],
                'compare_chart_data'      => null,
                'ai_summary'              => null,
                'has_null_fx'             => false,
                'granularity'             => $granularity,
                'store_ids'               => $storeIds,
            ]);
        }

        $metrics        = $this->computeMetrics($workspaceId, $from, $to, $storeIds);
        $compareMetrics = ($compareFrom && $compareTo)
            ? $this->computeMetrics($workspaceId, $compareFrom, $compareTo, $storeIds)
            : null;

        $chartData        = $this->buildChartData($workspaceId, $from, $to, $granularity, $storeIds);
        $compareChartData = ($compareFrom && $compareTo)
            ? $this->buildChartData($workspaceId, $compareFrom, $compareTo, $granularity, $storeIds)
            : null;

        $aiSummary = AiSummary::withoutGlobalScopes()
            ->where('workspace_id', $workspaceId)
            ->where('date', now()->subDay()->toDateString())
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

        $notes = DailyNote::withoutGlobalScopes()
            ->where('workspace_id', $workspaceId)
            ->whereBetween('date', [$from, $to])
            ->select(['date', 'note'])
            ->get()
            ->map(fn ($n) => ['date' => $n->date->toDateString(), 'note' => $n->note])
            ->all();

        return Inertia::render('Dashboard', [
            'has_stores'              => true,
            'show_ad_accounts_banner' => ! $hasAdAccounts,
            'metrics'                 => $metrics,
            'compare_metrics'         => $compareMetrics,
            'chart_data'              => $chartData,
            'compare_chart_data'      => $compareChartData,
            'ai_summary'              => $aiSummary,
            'has_null_fx'             => $hasNullFx,
            'granularity'             => $granularity,
            'store_ids'               => $storeIds,
            'notes'                   => $notes,
        ]);
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
     * Aggregate snapshot + ad spend metrics for the given date range.
     *
     * Revenue and order totals come from daily_snapshots (pre-aggregated).
     * ROAS uses spend_in_reporting_currency per spec.
     * Marketing % uses native spend per spec.
     * Ad metrics are always workspace-level (no FK between stores and ad_accounts).
     *
     * @param int[] $storeIds Empty = all stores
     * @return array{revenue:float,orders:int,roas:float|null,cpo:float|null,aov:float|null,items_per_order:float|null,marketing_spend_pct:float|null,ad_spend:float|null}
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

        // campaign-level daily rows only — never mix levels; always workspace-wide
        $adSpendReporting = (float) (AdInsight::withoutGlobalScopes()
            ->where('workspace_id', $workspaceId)
            ->where('level', 'campaign')
            ->whereBetween('date', [$from, $to])
            ->whereNull('hour')
            ->sum('spend_in_reporting_currency') ?? 0);

        // Marketing % uses native spend per CLAUDE.md §Formulas
        $adSpendNative = (float) (AdInsight::withoutGlobalScopes()
            ->where('workspace_id', $workspaceId)
            ->where('level', 'campaign')
            ->whereBetween('date', [$from, $to])
            ->whereNull('hour')
            ->sum('spend') ?? 0);

        return [
            'revenue'             => $revenue,
            'orders'              => $orders,
            'roas'                => ($adSpendReporting > 0 && $revenue > 0)
                ? round($revenue / $adSpendReporting, 2)
                : null,
            'cpo'                 => ($adSpendReporting > 0 && $orders > 0)
                ? round($adSpendReporting / $orders, 2)
                : null,
            'aov'                 => $orders > 0
                ? round($revenue / $orders, 2)
                : null,
            'items_per_order'     => $orders > 0
                ? round($items / $orders, 2)
                : null,
            'marketing_spend_pct' => ($revenue > 0 && $adSpendNative > 0)
                ? round(($adSpendNative / $revenue) * 100, 1)
                : null,
            'ad_spend'            => $adSpendReporting > 0 ? $adSpendReporting : null,
        ];
    }

    /**
     * Build multi-series time-series chart data.
     *
     * Each point: {date, revenue, orders, aov, roas, ad_spend}
     * hourly → hourly_snapshots (revenue + orders only; ad data not available hourly)
     * weekly → daily_snapshots grouped by week + ad_insights
     * daily  → daily_snapshots per day + ad_insights
     *
     * Ad metrics always workspace-level; revenue/orders filtered by store selection.
     *
     * @param int[] $storeIds Empty = all stores
     * @return array<int, array{date:string,revenue:float,orders:int,aov:float|null,roas:float|null,ad_spend:float|null}>
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
            // No ad data at hourly granularity
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
                    NULL::numeric AS roas
                ")
                ->groupByRaw('date, hour')
                ->orderByRaw('date, hour')
                ->get()
                ->map(fn ($r) => $this->mapChartRow($r))
                ->all();
        }

        if ($granularity === 'weekly') {
            $rows = DB::select("
                SELECT
                    DATE_TRUNC('week', s.date)::date::text AS date,
                    COALESCE(SUM(s.revenue), 0)            AS revenue,
                    COALESCE(SUM(s.orders_count), 0)       AS orders,
                    CASE WHEN SUM(s.orders_count) > 0
                         THEN SUM(s.revenue) / SUM(s.orders_count) ELSE NULL END AS aov,
                    COALESCE(SUM(ai.ad_spend), 0)          AS ad_spend,
                    CASE WHEN COALESCE(SUM(ai.ad_spend), 0) > 0
                         THEN SUM(s.revenue) / SUM(ai.ad_spend) ELSE NULL END AS roas
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
                GROUP BY DATE_TRUNC('week', s.date)
                ORDER BY DATE_TRUNC('week', s.date)
            ", [$workspaceId, $workspaceId, $from, $to]);

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
                     THEN SUM(s.revenue) / ai.ad_spend ELSE NULL END AS roas
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

        return array_map(fn ($r) => $this->mapChartRow($r), $rows);
    }

    /** @param object $r */
    private function mapChartRow(object $r): array
    {
        return [
            'date'     => $r->date,
            'revenue'  => (float) $r->revenue,
            'orders'   => (int)   $r->orders,
            'aov'      => $r->aov      !== null ? round((float) $r->aov, 2)      : null,
            'roas'     => $r->roas     !== null ? round((float) $r->roas, 2)     : null,
            'ad_spend' => $r->ad_spend !== null && (float) $r->ad_spend > 0
                ? round((float) $r->ad_spend, 2)
                : null,
        ];
    }
}
