<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Store;
use App\Services\WorkspaceContext;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Acquisition page — flagship Phase 1.6 page.
 *
 * Answers: "Which traffic sources bring me orders, not just visitors?"
 * Rows = channels (via ChannelClassifierService output on orders.attribution_last_touch).
 * Columns = volume + conversion + profit.
 *
 * Click data comes from ad_insights (paid) and gsc_daily_stats (organic search).
 * Order/revenue data comes from orders.attribution_last_touch JSONB.
 * COGS/profit comes from order_items.unit_cost.
 *
 * @see PLANNING.md section 12.5 "/acquisition"
 */
class AcquisitionController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $workspaceId = app(WorkspaceContext::class)->id();

        $validated = $request->validate([
            'from'       => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'to'         => ['sometimes', 'nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'store_ids'  => ['sometimes', 'nullable', 'string'],
            'filter'     => ['sometimes', 'nullable', 'in:all,winners,losers'],
            'classifier' => ['sometimes', 'nullable', 'in:peer,period'],
            'view'       => ['sometimes', 'nullable', 'in:table,scatter,line'],
        ]);

        $from      = $validated['from']       ?? now()->subDays(29)->toDateString();
        $to        = $validated['to']         ?? now()->toDateString();
        $filter    = $validated['filter']     ?? 'all';
        $classifier = $validated['classifier'] ?? 'peer';
        $view      = $validated['view']       ?? 'table';
        $storeIds  = $this->parseStoreIds($validated['store_ids'] ?? '', $workspaceId);

        $storeClause = ! empty($storeIds)
            ? 'AND o.store_id IN (' . implode(',', array_map('intval', $storeIds)) . ')'
            : '';

        // ── Channel rows from orders attribution ────────────────────────────
        // Groups orders by (channel_type, channel_name) from attribution_last_touch JSONB.
        // Orders without attribution or with source='none' become "Not Tracked".
        // Orders with attribution but no channel mapping become "Other tagged".
        $channelRows = DB::select("
            SELECT
                COALESCE(attribution_last_touch->>'channel_type', 'not_tracked') AS channel_type,
                COALESCE(attribution_last_touch->>'channel', 'Not Tracked')      AS channel_name,
                COUNT(*)                                                          AS orders,
                COALESCE(SUM(total_in_reporting_currency), 0)                     AS revenue
            FROM orders o
            WHERE o.workspace_id = ?
              AND o.status IN ('completed', 'processing')
              AND o.total_in_reporting_currency IS NOT NULL
              AND o.occurred_at BETWEEN ? AND ?
              {$storeClause}
            GROUP BY
                attribution_last_touch->>'channel_type',
                attribution_last_touch->>'channel'
            ORDER BY revenue DESC
        ", [
            $workspaceId,
            $from . ' 00:00:00',
            $to . ' 23:59:59',
        ]);

        // ── COGS per channel — contribution margin ──────────────────────────
        $cogsRows = DB::select("
            SELECT
                COALESCE(o.attribution_last_touch->>'channel_type', 'not_tracked') AS channel_type,
                COALESCE(o.attribution_last_touch->>'channel', 'Not Tracked')      AS channel_name,
                SUM(oi.unit_cost * oi.quantity)                                     AS total_cogs
            FROM order_items oi
            JOIN orders o ON o.id = oi.order_id
            WHERE o.workspace_id = ?
              AND o.status IN ('completed', 'processing')
              AND o.occurred_at BETWEEN ? AND ?
              AND oi.unit_cost IS NOT NULL
              AND oi.unit_cost > 0
              {$storeClause}
            GROUP BY
                o.attribution_last_touch->>'channel_type',
                o.attribution_last_touch->>'channel'
        ", [
            $workspaceId,
            $from . ' 00:00:00',
            $to . ' 23:59:59',
        ]);

        $cogsMap = [];
        foreach ($cogsRows as $cr) {
            $cogsMap[$cr->channel_type . '|' . $cr->channel_name] = (float) $cr->total_cogs;
        }

        // ── Click data: paid ads (by platform → channel_type) ───────────────
        $adClickRows = DB::select("
            SELECT
                CASE WHEN aa.platform = 'facebook' THEN 'paid_social'
                     WHEN aa.platform = 'google'   THEN 'paid_search'
                     ELSE 'paid_other' END AS channel_type,
                COALESCE(SUM(ai.clicks), 0)                        AS clicks,
                COALESCE(SUM(ai.spend_in_reporting_currency), 0)   AS ad_spend
            FROM ad_insights ai
            JOIN campaigns c ON c.id = ai.campaign_id
            JOIN ad_accounts aa ON aa.id = c.ad_account_id
            WHERE ai.workspace_id = ?
              AND ai.level = 'campaign'
              AND ai.hour IS NULL
              AND ai.date BETWEEN ? AND ?
            GROUP BY
                CASE WHEN aa.platform = 'facebook' THEN 'paid_social'
                     WHEN aa.platform = 'google'   THEN 'paid_search'
                     ELSE 'paid_other' END
        ", [$workspaceId, $from, $to]);

        $adClickMap  = [];
        $adSpendMap  = [];
        foreach ($adClickRows as $ac) {
            $adClickMap[$ac->channel_type] = (int) $ac->clicks;
            $adSpendMap[$ac->channel_type] = (float) $ac->ad_spend;
        }

        // ── Click data: organic search from GSC ─────────────────────────────
        $gscClicks = DB::selectOne("
            SELECT COALESCE(SUM(clicks), 0) AS clicks
            FROM gsc_daily_stats
            WHERE workspace_id = ?
              AND date BETWEEN ? AND ?
              AND device = 'all'
              AND country = 'ZZ'
        ", [$workspaceId, $from, $to]);
        $organicClicks = (int) ($gscClicks->clicks ?? 0);

        // ── "Other tagged" detail: unclassified (source, medium) combos ─────
        $otherTaggedDetail = DB::select("
            SELECT
                attribution_last_touch->>'source' AS utm_source,
                attribution_last_touch->>'medium' AS utm_medium,
                COUNT(*)                           AS orders,
                COALESCE(SUM(total_in_reporting_currency), 0) AS revenue
            FROM orders o
            WHERE o.workspace_id = ?
              AND o.status IN ('completed', 'processing')
              AND o.total_in_reporting_currency IS NOT NULL
              AND o.occurred_at BETWEEN ? AND ?
              AND o.attribution_source IN ('pys', 'wc_native')
              AND o.attribution_last_touch IS NOT NULL
              AND o.attribution_last_touch->>'channel' IS NULL
              {$storeClause}
            GROUP BY
                attribution_last_touch->>'source',
                attribution_last_touch->>'medium'
            ORDER BY revenue DESC
            LIMIT 20
        ", [
            $workspaceId,
            $from . ' 00:00:00',
            $to . ' 23:59:59',
        ]);

        // ── Assemble channel rows ───────────────────────────────────────────
        $hasCogs = false;
        $channels = [];
        foreach ($channelRows as $cr) {
            $key        = $cr->channel_type . '|' . $cr->channel_name;
            $revenue    = (float) $cr->revenue;
            $orders     = (int) $cr->orders;
            $totalCogs  = $cogsMap[$key] ?? null;
            $margin     = $totalCogs !== null ? $revenue - $totalCogs : null;

            if ($totalCogs !== null) {
                $hasCogs = true;
            }

            // Determine clicks + ad spend based on channel_type
            $clicks  = null;
            $adSpend = null;
            $cvr     = null;
            $channelType = $cr->channel_type;

            if ($channelType === 'paid_social') {
                $clicks  = $adClickMap['paid_social'] ?? null;
                $adSpend = $adSpendMap['paid_social'] ?? null;
            } elseif ($channelType === 'paid_search') {
                $clicks  = $adClickMap['paid_search'] ?? null;
                $adSpend = $adSpendMap['paid_search'] ?? null;
            } elseif ($channelType === 'organic_search') {
                $clicks = $organicClicks > 0 ? $organicClicks : null;
            }

            if ($clicks !== null && $clicks > 0) {
                $cvr = round(($orders / $clicks) * 100, 2);
            }

            // Real profit = margin - ad spend (when both available)
            $realProfit = null;
            if ($margin !== null) {
                $realProfit = $margin - ($adSpend ?? 0);
            } elseif ($adSpend !== null) {
                $realProfit = $revenue - $adSpend;
            } else {
                $realProfit = $revenue;
            }

            $channels[] = [
                'channel_type'        => $channelType,
                'channel_name'        => $cr->channel_name,
                'clicks'              => $clicks,
                'orders'              => $orders,
                'cvr'                 => $cvr,
                'revenue'             => round($revenue, 2),
                'ad_spend'            => $adSpend !== null ? round($adSpend, 2) : null,
                'total_cogs'          => $totalCogs !== null ? round($totalCogs, 2) : null,
                'contribution_margin' => $margin !== null ? round($margin, 2) : null,
                'real_profit'         => $realProfit !== null ? round($realProfit, 2) : null,
            ];
        }

        // Deduplicate: ad click data was assigned to the first row of each channel_type.
        // If multiple channel_names share the same channel_type (e.g. "Paid — Facebook" and
        // "Paid — Instagram" both are paid_social), only the first gets the aggregate clicks.
        // This is acceptable — the acquisition page groups by channel_type primarily.
        // However, let's consolidate rows by channel_type to avoid confusion.
        $consolidated = $this->consolidateByChannelType($channels);

        // ── Sort by real_profit desc (default) ──────────────────────────────
        usort($consolidated, function (array $a, array $b): int {
            $aVal = $a['real_profit'] ?? PHP_FLOAT_MIN;
            $bVal = $b['real_profit'] ?? PHP_FLOAT_MIN;
            return $bVal <=> $aVal;
        });

        // ── Attribution coverage — % of orders with a known channel ────────
        // "Not Tracked" orders have no PYS or UTM data at all. Direct is
        // PYS-confirmed (no referrer) and counts as attributed.
        $coverageRow = DB::selectOne("
            SELECT
                COUNT(*)::int AS total_orders,
                COUNT(*) FILTER (
                    WHERE attribution_last_touch->>'channel_type' IS NOT NULL
                      AND attribution_last_touch->>'channel_type' <> 'not_tracked'
                )::int AS attributed_orders
            FROM orders o
            WHERE o.workspace_id = ?
              AND o.status IN ('completed', 'processing')
              AND o.total_in_reporting_currency IS NOT NULL
              AND o.occurred_at BETWEEN ? AND ?
              {$storeClause}
        ", [$workspaceId, $from . ' 00:00:00', $to . ' 23:59:59']);

        $totalOrders      = (int) ($coverageRow->total_orders ?? 0);
        $attributedOrders = (int) ($coverageRow->attributed_orders ?? 0);
        $coveragePct      = $totalOrders > 0 ? (int) round($attributedOrders / $totalOrders * 100) : null;

        // ── Hero metrics ────────────────────────────────────────────────────
        $totalRevenue = array_sum(array_column($consolidated, 'revenue'));
        $totalProfit  = array_sum(array_filter(array_column($consolidated, 'real_profit')));

        // Top converting source: highest CVR above volume floor (min 5 orders)
        $topConverting = null;
        $topCvr = 0;
        foreach ($consolidated as $ch) {
            if ($ch['cvr'] !== null && $ch['orders'] >= 5 && $ch['cvr'] > $topCvr) {
                $topCvr = $ch['cvr'];
                $topConverting = $ch['channel_name'];
            }
        }

        // ── W/L classifier: peer average on CVR (for sources with click data) ──
        $withClicks = array_filter($consolidated, fn ($ch) => $ch['cvr'] !== null);
        $peerAvgCvr = count($withClicks) > 0
            ? array_sum(array_column($withClicks, 'cvr')) / count($withClicks)
            : null;

        $consolidated = array_map(function (array $ch) use ($peerAvgCvr): array {
            if ($ch['cvr'] === null || $peerAvgCvr === null) {
                return array_merge($ch, ['wl_tag' => null]);
            }
            return array_merge($ch, [
                'wl_tag' => $ch['cvr'] >= $peerAvgCvr ? 'winner' : 'loser',
            ]);
        }, $consolidated);

        $totalCount = count($consolidated);

        if ($filter !== 'all') {
            $filterTag    = rtrim($filter, 's');
            $consolidated = array_values(
                array_filter($consolidated, fn (array $ch) => ($ch['wl_tag'] ?? null) === $filterTag),
            );
        }

        // ── Line chart: daily revenue by top-5 channels ─────────────────────
        $chartData = $this->buildDailyChartData($workspaceId, $from, $to, $storeClause);

        return Inertia::render('Acquisition', [
            'channels'            => array_values($consolidated),
            'channels_total_count' => $totalCount,
            'has_cogs'            => $hasCogs,
            'hero'                => [
                'total_orders'      => $totalOrders,
                'total_revenue'     => round($totalRevenue, 2),
                'top_converting'    => $topConverting,
                'top_cvr'           => $topCvr > 0 ? $topCvr : null,
                'total_profit'      => round($totalProfit, 2),
                'attributed_orders' => $attributedOrders,
                'coverage_pct'      => $coveragePct,
            ],
            'other_tagged_detail' => array_map(fn ($r) => [
                'utm_source' => $r->utm_source,
                'utm_medium' => $r->utm_medium,
                'orders'     => (int) $r->orders,
                'revenue'    => round((float) $r->revenue, 2),
            ], $otherTaggedDetail),
            'chart_data'          => $chartData,
            'from'                => $from,
            'to'                  => $to,
            'store_ids'           => $storeIds,
            'view'                => $view,
            'filter'              => $filter,
            'classifier'          => $classifier,
        ]);
    }

    /**
     * Consolidate multiple channel_names under the same channel_type into a single row.
     *
     * E.g. "Paid — Facebook" and "Paid — Instagram" both have channel_type=paid_social
     * but separate channel_names. We keep the most descriptive name and sum metrics.
     *
     * @param  array<int, array<string, mixed>> $channels
     * @return array<int, array<string, mixed>>
     */
    private function consolidateByChannelType(array $channels): array
    {
        $grouped = [];
        foreach ($channels as $ch) {
            $type = $ch['channel_type'];

            // Special rows stay separate: "not_tracked" is one row, "other" (unclassified) is one row
            if ($type === 'not_tracked') {
                $grouped['__not_tracked'] = $ch;
                continue;
            }

            if (! isset($grouped[$type])) {
                $grouped[$type] = $ch;
                continue;
            }

            // Merge into existing — keep the name with more orders
            $existing = $grouped[$type];
            if ($ch['orders'] > $existing['orders']) {
                $grouped[$type]['channel_name'] = $ch['channel_name'];
            }
            $grouped[$type]['orders']  += $ch['orders'];
            $grouped[$type]['revenue'] += $ch['revenue'];
            if ($ch['total_cogs'] !== null) {
                $grouped[$type]['total_cogs'] = ($grouped[$type]['total_cogs'] ?? 0) + $ch['total_cogs'];
            }
            if ($ch['contribution_margin'] !== null) {
                $grouped[$type]['contribution_margin'] = ($grouped[$type]['contribution_margin'] ?? 0) + $ch['contribution_margin'];
            }
            // clicks / ad_spend / cvr / real_profit recomputed below
        }

        // Recompute derived fields after consolidation
        foreach ($grouped as $key => &$ch) {
            if ($ch['clicks'] !== null && $ch['clicks'] > 0 && $ch['orders'] > 0) {
                $ch['cvr'] = round(($ch['orders'] / $ch['clicks']) * 100, 2);
            }
            $margin  = $ch['contribution_margin'];
            $adSpend = $ch['ad_spend'];
            if ($margin !== null) {
                $ch['real_profit'] = round($margin - ($adSpend ?? 0), 2);
            } elseif ($adSpend !== null) {
                $ch['real_profit'] = round($ch['revenue'] - $adSpend, 2);
            } else {
                $ch['real_profit'] = round($ch['revenue'], 2);
            }
        }
        unset($ch);

        return array_values($grouped);
    }

    /**
     * Build daily revenue chart data grouped by channel_type (top 5).
     *
     * @return array<int, array{date: string, channels: array<string, float>}>
     */
    private function buildDailyChartData(int $workspaceId, string $from, string $to, string $storeClause): array
    {
        $rows = DB::select("
            SELECT
                o.occurred_at::date::text                                              AS date,
                COALESCE(attribution_last_touch->>'channel_type', 'not_tracked')       AS channel_type,
                COALESCE(attribution_last_touch->>'channel', 'Not Tracked')            AS channel_name,
                COALESCE(SUM(total_in_reporting_currency), 0)                          AS revenue
            FROM orders o
            WHERE o.workspace_id = ?
              AND o.status IN ('completed', 'processing')
              AND o.total_in_reporting_currency IS NOT NULL
              AND o.occurred_at BETWEEN ? AND ?
              {$storeClause}
            GROUP BY o.occurred_at::date, attribution_last_touch->>'channel_type', attribution_last_touch->>'channel'
            ORDER BY date
        ", [
            $workspaceId,
            $from . ' 00:00:00',
            $to . ' 23:59:59',
        ]);

        // Find top 5 channels by total revenue
        $channelTotals = [];
        foreach ($rows as $r) {
            $name = $r->channel_name;
            $channelTotals[$name] = ($channelTotals[$name] ?? 0) + (float) $r->revenue;
        }
        arsort($channelTotals);
        $topChannels = array_slice(array_keys($channelTotals), 0, 5);

        // Build date → channel → revenue map
        $dateMap = [];
        foreach ($rows as $r) {
            $name = $r->channel_name;
            if (! in_array($name, $topChannels, true)) {
                $name = 'Other';
            }
            $dateMap[$r->date][$name] = ($dateMap[$r->date][$name] ?? 0) + (float) $r->revenue;
        }

        $chartData = [];
        $allChannels = array_unique(array_merge($topChannels, ['Other']));
        foreach ($dateMap as $date => $channels) {
            $point = ['date' => $date];
            foreach ($allChannels as $ch) {
                $point[$ch] = round($channels[$ch] ?? 0, 2);
            }
            $chartData[] = $point;
        }

        return $chartData;
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
