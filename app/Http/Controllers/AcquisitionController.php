<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Store;
use App\Services\NarrativeTemplateService;
use App\Services\ProfitCalculator;
use App\Services\RevenueAttributionService;
use App\Services\WorkspaceContext;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Acquisition page — Phase 3.5 synthesis.
 *
 * Merges /acquisition (channel attribution) + /analytics/discrepancy (platform vs real)
 * into one 3-tab destination with an opportunities sidebar.
 *
 * Tab 1 — Channels: channel matrix with full §M1 rollup and expandable campaign sub-rows.
 * Tab 2 — Platform vs Real: absorbs DiscrepancyController logic.
 * Tab 3 — Customer Journeys: sampled order list with first/last touch modal.
 *
 * Attribution model (first_touch vs last_touch) cascades into all channel and
 * discrepancy queries. Attribution window control is a UI placeholder only in Phase 3.5;
 * full implementation deferred to Phase 4 (attribution_last_touch.timestamp is sparsely
 * populated and filtering on it would silently drop orders).
 *
 * @see PLANNING.md section 12.5 "/acquisition"
 * @see PROGRESS.md Phase 3.5
 */
class AcquisitionController extends Controller
{
    /** §M1 rollup: maps raw channel_type → rollup key (LOCKED). */
    private const M1_ROLLUP_KEY = [
        'paid_search'    => 'paid_search',
        'paid_social'    => 'paid_social',
        'organic_search' => 'organic',
        'organic_social' => 'organic',
        'email'          => 'email',
        'sms'            => 'email',
        'direct'         => 'direct',
        'referral'       => 'direct',
        'affiliate'      => 'paid_social',
        'other'          => 'direct',
    ];

    /** Human-readable labels for §M1 rollup keys. */
    private const M1_ROLLUP_LABEL = [
        'paid_search' => 'Paid Search',
        'paid_social' => 'Paid Social',
        'organic'     => 'Organic',
        'email'       => 'Email',
        'direct'      => 'Direct',
    ];

    public function __construct(
        private readonly NarrativeTemplateService $narrative,
        private readonly RevenueAttributionService $attribution,
    ) {}

    public function __invoke(Request $request): Response
    {
        $workspaceId = app(WorkspaceContext::class)->id();

        $validated = $request->validate([
            'from'              => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'to'                => ['sometimes', 'nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'store_ids'         => ['sometimes', 'nullable', 'string'],
            'filter'            => ['sometimes', 'nullable', 'in:all,winners,losers'],
            'classifier'        => ['sometimes', 'nullable', 'in:peer,period'],
            'view'              => ['sometimes', 'nullable', 'in:table,scatter,line'],
            'tab'               => ['sometimes', 'nullable', 'in:channels,platform-vs-real,journeys'],
            'attribution_model' => ['sometimes', 'nullable', 'in:first_touch,last_touch'],
            'platform'          => ['sometimes', 'nullable', 'in:all,facebook,google'],
            'journey_filter'    => ['sometimes', 'nullable', 'in:all,new_customers,high_ltv'],
        ]);

        $from             = $validated['from']              ?? now()->subDays(29)->toDateString();
        $to               = $validated['to']                ?? now()->toDateString();
        $filter           = $validated['filter']            ?? 'all';
        $classifier       = $validated['classifier']        ?? 'peer';
        $view             = $validated['view']              ?? 'table';
        $tab              = $validated['tab']               ?? 'channels';
        $attributionModel = $validated['attribution_model'] ?? 'last_touch';
        $platform         = $validated['platform']          ?? 'all';
        $journeyFilter    = $validated['journey_filter']    ?? 'all';
        $storeIds         = $this->parseStoreIds($validated['store_ids'] ?? '', $workspaceId);

        // Attribution column — every SQL block that reads attribution data uses this.
        // Attribution window filtering is not applied here; the control is a UI placeholder
        // in Phase 3.5 to avoid silently dropping orders with null timestamps.
        $attrCol = $attributionModel === 'first_touch'
            ? 'attribution_first_touch'
            : 'attribution_last_touch';

        $storeClause = ! empty($storeIds)
            ? 'AND o.store_id IN (' . implode(',', array_map('intval', $storeIds)) . ')'
            : '';

        $stores      = Store::withoutGlobalScopes()->where('workspace_id', $workspaceId)->get();
        $costSettings = ProfitCalculator::settingsForStores($storeIds, $stores);

        // ── Build channels (Tab 1 data) ──────────────────────────────────────
        [$consolidated, $hasCogs, $adClickData, $adSpendMap, $platformRevenueMap] =
            $this->buildChannels($workspaceId, $from, $to, $attrCol, $storeClause, $storeIds, $costSettings);

        // ── Sort by real_profit desc ─────────────────────────────────────────
        usort($consolidated, function (array $a, array $b): int {
            $aVal = $a['real_profit'] ?? PHP_FLOAT_MIN;
            $bVal = $b['real_profit'] ?? PHP_FLOAT_MIN;
            return $bVal <=> $aVal;
        });

        // ── Attribution coverage ─────────────────────────────────────────────
        $coverageRow = DB::selectOne("
            SELECT
                COUNT(*)::int AS total_orders,
                COUNT(*) FILTER (
                    WHERE {$attrCol}->>'channel_type' IS NOT NULL
                      AND {$attrCol}->>'channel_type' <> 'not_tracked'
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

        // ── W/L classifier ───────────────────────────────────────────────────
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

        // ── "Other tagged" detail ─────────────────────────────────────────────
        $otherTaggedDetail = DB::select("
            SELECT
                {$attrCol}->>'source' AS utm_source,
                {$attrCol}->>'medium' AS utm_medium,
                COUNT(*)                                              AS orders,
                COALESCE(SUM(total_in_reporting_currency), 0)         AS revenue
            FROM orders o
            WHERE o.workspace_id = ?
              AND o.status IN ('completed', 'processing')
              AND o.total_in_reporting_currency IS NOT NULL
              AND o.occurred_at BETWEEN ? AND ?
              AND o.attribution_source IN ('pys', 'wc_native')
              AND o.{$attrCol} IS NOT NULL
              AND o.{$attrCol}->>'channel' IS NULL
              {$storeClause}
            GROUP BY {$attrCol}->>'source', {$attrCol}->>'medium'
            ORDER BY revenue DESC
            LIMIT 20
        ", [
            $workspaceId,
            $from . ' 00:00:00',
            $to . ' 23:59:59',
        ]);

        // ── Hero metrics ─────────────────────────────────────────────────────
        $totalRevenue = array_sum(array_column($consolidated, 'revenue'));
        $totalProfit  = array_sum(array_filter(array_column($consolidated, 'real_profit')));

        $topConverting = null;
        $topCvr        = 0;
        foreach ($consolidated as $ch) {
            if ($ch['cvr'] !== null && $ch['orders'] >= 5 && $ch['cvr'] > $topCvr) {
                $topCvr        = $ch['cvr'];
                $topConverting = $ch['channel_name'];
            }
        }

        // ── Narrative ────────────────────────────────────────────────────────
        $paidChannels = array_filter(
            $consolidated,
            fn ($ch) => ($ch['ad_spend'] ?? 0) > 0,
        );

        $topChannel = $topRevenuePaid = $topRoas = $worstChannel = $worstRoas = null;

        if (! empty($paidChannels)) {
            usort($paidChannels, function (array $a, array $b): int {
                $aRoas = $a['ad_spend'] > 0 ? $a['revenue'] / $a['ad_spend'] : 0.0;
                $bRoas = $b['ad_spend'] > 0 ? $b['revenue'] / $b['ad_spend'] : 0.0;
                return $bRoas <=> $aRoas;
            });
            $first          = $paidChannels[0];
            $topChannel     = $first['channel_name'];
            $topRevenuePaid = $first['revenue'] > 0 ? $first['revenue'] : null;
            $topRoas        = $first['ad_spend'] > 0 ? round($first['revenue'] / $first['ad_spend'], 2) : null;
            $last           = end($paidChannels);
            if ($last['channel_name'] !== $topChannel) {
                $worstChannel = $last['channel_name'];
                $worstRoas    = $last['ad_spend'] > 0 ? round($last['revenue'] / $last['ad_spend'], 2) : null;
            }
        }

        $pageNarrative = $this->narrative->forAcquisition(
            $topChannel,
            $topRevenuePaid,
            $topRoas,
            $worstChannel,
            $worstRoas,
        );

        // ── Daily chart ──────────────────────────────────────────────────────
        $chartData = $this->buildDailyChartData($workspaceId, $from, $to, $storeClause, $attrCol);

        // ── Discrepancy data (Tab 2) ─────────────────────────────────────────
        $discrepancyData = $this->buildDiscrepancy(
            $workspaceId, $from, $to, $platform, $attrCol,
        );

        // ── Attach campaign children to paid channel rows ────────────────────
        // Re-use campaign rows from discrepancy to build expandable sub-rows.
        $consolidated = $this->attachChannelChildren(
            $consolidated, $discrepancyData['campaigns'], $workspaceId, $from, $to,
        );

        // ── Journey orders (Tab 3) ───────────────────────────────────────────
        $journeyData = $this->buildJourneys($workspaceId, $from, $to, $journeyFilter, $storeClause);

        // ── Opportunities sidebar ────────────────────────────────────────────
        $opportunities = $this->buildOpportunities($workspaceId, $from, $to, $consolidated);

        return Inertia::render('Acquisition', [
            // Channels / hero (existing + extended)
            'channels'              => array_values($consolidated),
            'channels_total_count'  => $totalCount,
            'has_cogs'              => $hasCogs,
            'hero'                  => [
                'total_orders'      => $totalOrders,
                'total_revenue'     => round($totalRevenue, 2),
                'top_converting'    => $topConverting,
                'top_cvr'           => $topCvr > 0 ? $topCvr : null,
                'total_profit'      => round($totalProfit, 2),
                'attributed_orders' => $attributedOrders,
                'coverage_pct'      => $coveragePct,
            ],
            'other_tagged_detail'   => array_map(fn ($r) => [
                'utm_source' => $r->utm_source,
                'utm_medium' => $r->utm_medium,
                'orders'     => (int) $r->orders,
                'revenue'    => round((float) $r->revenue, 2),
            ], $otherTaggedDetail),
            'chart_data'            => $chartData,
            'from'                  => $from,
            'to'                    => $to,
            'store_ids'             => $storeIds,
            'view'                  => $view,
            'filter'                => $filter,
            'classifier'            => $classifier,
            'narrative'             => $pageNarrative,
            // Phase 3.5 additions
            'tab'                   => $tab,
            'attribution_model'     => $attributionModel,
            'discrepancy'           => $discrepancyData,
            'journeys'              => $journeyData,
            'opportunities'         => $opportunities,
        ]);
    }

    // ── Channel building ────────────────────────────────────────────────────

    /**
     * Build and consolidate channel rows with all Phase 3.5 columns.
     *
     * Returns [$consolidated, $hasCogs, $adClickData, $adSpendMap, $platformRevenueMap].
     * Runs 7 DB queries in sequence; all are workspace+range-scoped so they use existing indexes.
     *
     * @return array{0: array, 1: bool, 2: array, 3: array, 4: array}
     */
    private function buildChannels(
        int $workspaceId,
        string $from,
        string $to,
        string $attrCol,
        string $storeClause,
        array $storeIds,
        \App\ValueObjects\StoreCostSettings $costSettings,
    ): array {
        // 1. Revenue + order count per channel
        $channelRows = DB::select("
            SELECT
                COALESCE({$attrCol}->>'channel_type', 'not_tracked') AS channel_type,
                COALESCE({$attrCol}->>'channel', 'Not Tracked')      AS channel_name,
                COUNT(*)                                              AS orders,
                COALESCE(SUM(total_in_reporting_currency), 0)         AS revenue
            FROM orders o
            WHERE o.workspace_id = ?
              AND o.status IN ('completed', 'processing')
              AND o.total_in_reporting_currency IS NOT NULL
              AND o.occurred_at BETWEEN ? AND ?
              {$storeClause}
            GROUP BY {$attrCol}->>'channel_type', {$attrCol}->>'channel'
            ORDER BY revenue DESC
        ", [$workspaceId, $from . ' 00:00:00', $to . ' 23:59:59']);

        // 2. COGS per channel
        $cogsRows = DB::select("
            SELECT
                COALESCE(o.{$attrCol}->>'channel_type', 'not_tracked') AS channel_type,
                COALESCE(o.{$attrCol}->>'channel', 'Not Tracked')      AS channel_name,
                SUM(oi.unit_cost * oi.quantity)                         AS total_cogs
            FROM order_items oi
            JOIN orders o ON o.id = oi.order_id
            WHERE o.workspace_id = ?
              AND o.status IN ('completed', 'processing')
              AND o.occurred_at BETWEEN ? AND ?
              AND oi.unit_cost IS NOT NULL
              AND oi.unit_cost > 0
              {$storeClause}
            GROUP BY o.{$attrCol}->>'channel_type', o.{$attrCol}->>'channel'
        ", [$workspaceId, $from . ' 00:00:00', $to . ' 23:59:59']);

        $cogsMap = [];
        foreach ($cogsRows as $cr) {
            $cogsMap[$cr->channel_type . '|' . $cr->channel_name] = (float) $cr->total_cogs;
        }

        // 3. Payment fees, shipping, tax, refunds per channel (§F3 extended)
        $feeRows = DB::select("
            SELECT
                COALESCE({$attrCol}->>'channel_type', 'not_tracked')                   AS channel_type,
                COALESCE({$attrCol}->>'channel', 'Not Tracked')                        AS channel_name,
                COALESCE(SUM(payment_fee), 0)                                          AS total_payment_fees,
                COALESCE(SUM(shipping), 0)                                             AS total_shipping,
                COALESCE(SUM(tax), 0)                                                  AS total_tax,
                COALESCE(SUM(refund_amount), 0)                                        AS total_refunds,
                COALESCE(SUM(CASE WHEN tax = 0 THEN COALESCE(total_in_reporting_currency, total) ELSE 0 END), 0) AS revenue_no_tax,
                COUNT(*)                                                                AS order_count
            FROM orders o
            WHERE o.workspace_id = ?
              AND o.status IN ('completed', 'processing')
              AND o.occurred_at BETWEEN ? AND ?
              {$storeClause}
            GROUP BY {$attrCol}->>'channel_type', {$attrCol}->>'channel'
        ", [$workspaceId, $from . ' 00:00:00', $to . ' 23:59:59']);

        $feeMap = [];
        foreach ($feeRows as $fr) {
            $feeMap[$fr->channel_type . '|' . $fr->channel_name] = [
                'payment_fees'   => (float) $fr->total_payment_fees,
                'shipping'       => (float) $fr->total_shipping,
                'total_tax'      => (float) $fr->total_tax,
                'total_refunds'  => (float) $fr->total_refunds,
                'revenue_no_tax' => (float) $fr->revenue_no_tax,
                'order_count'    => (int)   $fr->order_count,
            ];
        }

        // 4. Ad clicks, spend, and platform_conversions_value per channel_type
        $adClickRows = DB::select("
            SELECT
                CASE WHEN aa.platform = 'facebook' THEN 'paid_social'
                     WHEN aa.platform = 'google'   THEN 'paid_search'
                     ELSE 'paid_other' END                            AS channel_type,
                COALESCE(SUM(ai.clicks), 0)                           AS clicks,
                COALESCE(SUM(ai.spend_in_reporting_currency), 0)      AS ad_spend,
                COALESCE(SUM(ai.platform_conversions_value), 0)       AS platform_revenue
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

        $adClickMap        = [];
        $adSpendMap        = [];
        $platformRevenueMap = [];
        foreach ($adClickRows as $ac) {
            $adClickMap[$ac->channel_type]         = (int) $ac->clicks;
            $adSpendMap[$ac->channel_type]         = (float) $ac->ad_spend;
            $platformRevenueMap[$ac->channel_type] = (float) $ac->platform_revenue;
        }

        // 5. GSC clicks for organic channel
        $gscClicks    = DB::selectOne("
            SELECT COALESCE(SUM(clicks), 0) AS clicks
            FROM gsc_daily_stats
            WHERE workspace_id = ?
              AND date BETWEEN ? AND ?
              AND device = 'all'
              AND country = 'ZZ'
        ", [$workspaceId, $from, $to]);
        $organicClicks = (int) ($gscClicks->clicks ?? 0);

        // 6. New customers per channel (for CAC)
        $newCustRows = DB::select("
            SELECT
                COALESCE({$attrCol}->>'channel_type', 'not_tracked') AS channel_type,
                COUNT(*)::int                                         AS new_customers
            FROM orders o
            WHERE o.workspace_id = ?
              AND o.status IN ('completed', 'processing')
              AND o.is_first_for_customer = true
              AND o.occurred_at BETWEEN ? AND ?
              {$storeClause}
            GROUP BY {$attrCol}->>'channel_type'
        ", [$workspaceId, $from . ' 00:00:00', $to . ' 23:59:59']);

        $newCustMap = [];
        foreach ($newCustRows as $nc) {
            $newCustMap[$nc->channel_type] = (int) $nc->new_customers;
        }

        // 7. First-order revenue per channel (for First-order ROAS)
        $firstOrderRows = DB::select("
            SELECT
                COALESCE({$attrCol}->>'channel_type', 'not_tracked') AS channel_type,
                COALESCE(SUM(total_in_reporting_currency), 0)         AS first_order_revenue
            FROM orders o
            WHERE o.workspace_id = ?
              AND o.status IN ('completed', 'processing')
              AND o.is_first_for_customer = true
              AND o.occurred_at BETWEEN ? AND ?
              {$storeClause}
            GROUP BY {$attrCol}->>'channel_type'
        ", [$workspaceId, $from . ' 00:00:00', $to . ' 23:59:59']);

        $firstOrderMap = [];
        foreach ($firstOrderRows as $fo) {
            $firstOrderMap[$fo->channel_type] = (float) $fo->first_order_revenue;
        }

        // 8. Day-30 ROAS — cohort query (only when the window has fully closed)
        $lockDate    = Carbon::parse($to)->addDays(30);
        $day30Pending     = now()->lt($lockDate);
        $day30LocksInDays = $day30Pending ? (int) now()->diffInDays($lockDate) : null;
        $day30Map         = [];

        if (! $day30Pending) {
            $outerStoreClause = ! empty($storeIds)
                ? 'AND all_orders.store_id IN (' . implode(',', array_map('intval', $storeIds)) . ')'
                : '';

            $day30Rows = DB::select("
                SELECT
                    acq.channel_type,
                    COALESCE(SUM(all_orders.total_in_reporting_currency), 0) AS day30_revenue
                FROM (
                    SELECT
                        customer_email_hash,
                        COALESCE(o.{$attrCol}->>'channel_type', 'not_tracked') AS channel_type,
                        MIN(occurred_at) AS first_order_at
                    FROM orders o
                    WHERE o.workspace_id = ?
                      AND o.status IN ('completed', 'processing')
                      AND o.is_first_for_customer = true
                      AND o.occurred_at BETWEEN ? AND ?
                      AND o.customer_email_hash IS NOT NULL
                      {$storeClause}
                    GROUP BY o.customer_email_hash, o.{$attrCol}->>'channel_type'
                ) acq
                JOIN orders all_orders ON
                    all_orders.workspace_id = ?
                    AND all_orders.customer_email_hash = acq.customer_email_hash
                    AND all_orders.status IN ('completed', 'processing')
                    AND all_orders.occurred_at >= acq.first_order_at
                    AND all_orders.occurred_at < acq.first_order_at + INTERVAL '30 days'
                    {$outerStoreClause}
                GROUP BY acq.channel_type
            ", [
                $workspaceId,
                $from . ' 00:00:00',
                $to . ' 23:59:59',
                $workspaceId,
            ]);

            foreach ($day30Rows as $dr) {
                $day30Map[$dr->channel_type] = (float) $dr->day30_revenue;
            }
        }

        // ── Assemble raw channel rows ────────────────────────────────────────
        $hasCogs  = false;
        $channels = [];

        foreach ($channelRows as $cr) {
            $key         = $cr->channel_type . '|' . $cr->channel_name;
            $revenue     = (float) $cr->revenue;
            $orders      = (int) $cr->orders;
            $totalCogs   = $cogsMap[$key] ?? null;
            $fees        = $feeMap[$key]  ?? null;
            $channelType = $cr->channel_type;

            if ($totalCogs !== null) {
                $hasCogs = true;
            }

            // Map channel_type to ad data bucket
            $clicks  = null;
            $adSpend = null;
            $cvr     = null;

            if ($channelType === 'paid_social') {
                $clicks  = $adClickMap['paid_social']  ?? null;
                $adSpend = $adSpendMap['paid_social']  ?? null;
            } elseif ($channelType === 'paid_search') {
                $clicks  = $adClickMap['paid_search']  ?? null;
                $adSpend = $adSpendMap['paid_search']  ?? null;
            } elseif ($channelType === 'affiliate') {
                // Affiliates share paid_social spend bucket per §M1
                $adSpend = $adSpendMap['paid_social']  ?? null;
            } elseif ($channelType === 'organic_search') {
                $clicks = $organicClicks > 0 ? $organicClicks : null;
            }

            if ($clicks !== null && $clicks > 0) {
                $cvr = round(($orders / $clicks) * 100, 2);
            }

            // §F3 LOCKED: Real Profit = Net Revenue − COGS − payment_fee − effective_shipping − ad_spend
            // Net revenue = revenue − tax − refunds (applied via ProfitCalculator)
            $profitAdj = ProfitCalculator::compute(
                revenue:       $revenue,
                totalTax:      (float) ($fees['total_tax']      ?? 0),
                revenueNoTax:  (float) ($fees['revenue_no_tax'] ?? 0),
                totalRefunds:  (float) ($fees['total_refunds']  ?? 0),
                totalShipping: (float) ($fees['shipping']       ?? 0),
                orderCount:    (int)   ($fees['order_count']    ?? 0),
                settings:      $costSettings,
            );

            $totalDeductions = ($totalCogs ?? 0)
                + ($fees['payment_fees']          ?? 0)
                + $profitAdj['effective_shipping'];

            $margin     = $totalCogs !== null ? $profitAdj['net_revenue'] - $totalCogs : null;
            $realProfit = $profitAdj['net_revenue'] - $totalDeductions - ($adSpend ?? 0);

            // Platform ROAS and Real ROAS (only meaningful for paid channels)
            $platformRevenue = null;
            $platformRoas    = null;
            $realRoas        = null;
            $platformRoasDelta = null;

            if ($channelType === 'paid_social') {
                $platformRevenue = $platformRevenueMap['paid_social'] ?? null;
            } elseif ($channelType === 'paid_search') {
                $platformRevenue = $platformRevenueMap['paid_search'] ?? null;
            }

            if ($adSpend !== null && $adSpend > 0) {
                $platformRoas = $platformRevenue !== null
                    ? round($platformRevenue / $adSpend, 2)
                    : null;
                $realRoas = round($revenue / $adSpend, 2);

                if ($platformRoas !== null && $realRoas > 0) {
                    $platformRoasDelta = round(($platformRoas - $realRoas) / $realRoas * 100, 1);
                }
            }

            // CAC and First-order ROAS (per §F6, §F7)
            $newCustomers    = $newCustMap[$channelType]    ?? null;
            $firstOrderRev   = $firstOrderMap[$channelType] ?? null;

            $cac             = ($adSpend !== null && $adSpend > 0 && $newCustomers !== null && $newCustomers > 0)
                ? round($adSpend / $newCustomers, 2)
                : null;
            $firstOrderRoas  = ($adSpend !== null && $adSpend > 0 && $firstOrderRev !== null)
                ? round($firstOrderRev / $adSpend, 2)
                : null;

            // Day-30 ROAS (per §F8 LOCKED — pending shown by flag)
            $day30Roas = null;
            if (! $day30Pending && $adSpend !== null && $adSpend > 0) {
                $day30Rev  = $day30Map[$channelType] ?? null;
                $day30Roas = $day30Rev !== null ? round($day30Rev / $adSpend, 2) : null;
            }

            $channels[] = [
                'channel_type'           => $channelType,
                'channel_name'           => $cr->channel_name,
                'clicks'                 => $clicks,
                'orders'                 => $orders,
                'new_customers'          => $newCustomers,
                'cvr'                    => $cvr,
                'revenue'                => round($revenue, 2),
                'ad_spend'               => $adSpend !== null ? round($adSpend, 2) : null,
                'total_cogs'             => $totalCogs !== null ? round($totalCogs, 2) : null,
                'contribution_margin'    => $margin !== null ? round($margin, 2) : null,
                'real_profit'            => round($realProfit, 2),
                'real_roas'              => $realRoas,
                'platform_roas'          => $platformRoas,
                'platform_roas_delta_pct'=> $platformRoasDelta,
                'cac'                    => $cac,
                'first_order_roas'       => $firstOrderRoas,
                'day_30_roas'            => $day30Roas,
                'day_30_roas_pending'    => $day30Pending,
                'day_30_roas_locks_in'   => $day30LocksInDays,
                'children'               => [],
            ];
        }

        // Consolidate by channel_type, then apply §M1 cross-type rollup
        $byType       = $this->consolidateByChannelType($channels);
        $consolidated = $this->applyM1Rollup($byType);

        return [$consolidated, $hasCogs, $adClickMap, $adSpendMap, $platformRevenueMap];
    }

    /**
     * Build discrepancy data (Platform vs Real) — absorbs DiscrepancyController.
     *
     * Uses RevenueAttributionService::batchGetCampaignAttributedRevenues with the
     * active attribution column so Tab 2 respects the attribution model switcher.
     *
     * @return array{campaigns: array, chart_data: array, hero: array, platform: string}
     */
    private function buildDiscrepancy(
        int $workspaceId,
        string $from,
        string $to,
        string $platform,
        string $attrCol,
    ): array {
        $platformFilter = $platform !== 'all'
            ? 'AND aa.platform = ' . DB::connection()->getPdo()->quote($platform)
            : '';

        $campaignRows = DB::select("
            SELECT
                c.id                                                          AS campaign_id,
                c.name                                                        AS campaign_name,
                c.external_id                                                 AS external_id,
                c.previous_names                                              AS previous_names,
                aa.platform                                                   AS platform,
                SUM(ai.spend_in_reporting_currency)                           AS spend,
                SUM(ai.platform_conversions_value)                            AS platform_revenue,
                SUM(ai.platform_conversions)                                  AS platform_conversions,
                CASE WHEN SUM(ai.spend_in_reporting_currency) > 0
                     THEN SUM(ai.platform_conversions_value) / SUM(ai.spend_in_reporting_currency)
                     ELSE NULL END                                            AS platform_roas
            FROM ad_insights ai
            JOIN campaigns c ON c.id = ai.campaign_id
            JOIN ad_accounts aa ON aa.id = c.ad_account_id
            WHERE ai.workspace_id = ?
              AND ai.level = 'campaign'
              AND ai.hour IS NULL
              AND ai.date BETWEEN ? AND ?
              {$platformFilter}
            GROUP BY c.id, c.name, c.external_id, c.previous_names, aa.platform
            HAVING SUM(ai.spend_in_reporting_currency) > 0
            ORDER BY SUM(ai.spend_in_reporting_currency) DESC
            LIMIT 50
        ", [$workspaceId, $from, $to]);

        $fromCarbon = Carbon::parse($from)->startOfDay();
        $toCarbon   = Carbon::parse($to)->endOfDay();

        $batchInput = array_map(static function ($cr): array {
            $prevNames = [];
            if ($cr->previous_names) {
                $decoded = json_decode($cr->previous_names, true);
                if (is_array($decoded)) {
                    $prevNames = $decoded;
                }
            }
            return [
                'id'             => $cr->campaign_id,
                'name'           => $cr->campaign_name,
                'previous_names' => $prevNames,
                'external_id'    => $cr->external_id ?? null,
            ];
        }, $campaignRows);

        $attrRevenueMap = $this->attribution->batchGetCampaignAttributedRevenues(
            $workspaceId,
            $batchInput,
            $fromCarbon,
            $toCarbon,
            $attrCol,
        );

        $campaigns              = [];
        $totalPlatformRevenue   = 0.0;
        $totalAttributedRevenue = 0.0;
        $totalSpend             = 0.0;

        foreach ($campaignRows as $cr) {
            $platRevenue = $cr->platform_revenue !== null ? (float) $cr->platform_revenue : 0.0;
            $spend       = (float) $cr->spend;
            $attrRevenue = $attrRevenueMap[$cr->campaign_id] ?? 0.0;

            $delta    = $platRevenue - $attrRevenue;
            $deltaPct = $attrRevenue > 0 ? round(($delta / $attrRevenue) * 100, 1) : null;
            $platRoas = $spend > 0 ? round($platRevenue / $spend, 2) : null;
            $realRoas = $spend > 0 ? round($attrRevenue / $spend, 2) : null;

            $campaigns[] = [
                'campaign_id'          => $cr->campaign_id,
                'campaign_name'        => $cr->campaign_name,
                'platform'             => $cr->platform,
                'spend'                => round($spend, 2),
                'platform_revenue'     => round($platRevenue, 2),
                'platform_conversions' => $cr->platform_conversions !== null ? (float) $cr->platform_conversions : null,
                'platform_roas'        => $platRoas,
                'attributed_revenue'   => round($attrRevenue, 2),
                'real_roas'            => $realRoas,
                'delta'                => round($delta, 2),
                'delta_pct'            => $deltaPct,
            ];

            $totalPlatformRevenue   += $platRevenue;
            $totalAttributedRevenue += $attrRevenue;
            $totalSpend             += $spend;
        }

        // Daily gap chart
        $dailyGap = DB::select("
            SELECT
                ai.date::text                                              AS date,
                COALESCE(SUM(ai.platform_conversions_value), 0)            AS platform_revenue,
                COALESCE(SUM(ai.spend_in_reporting_currency), 0)           AS spend
            FROM ad_insights ai
            JOIN campaigns c ON c.id = ai.campaign_id
            JOIN ad_accounts aa ON aa.id = c.ad_account_id
            WHERE ai.workspace_id = ?
              AND ai.level = 'campaign'
              AND ai.hour IS NULL
              AND ai.date BETWEEN ? AND ?
              {$platformFilter}
            GROUP BY ai.date
            ORDER BY ai.date
        ", [$workspaceId, $from, $to]);

        $dailyStoreRevenue = DB::select("
            SELECT
                o.occurred_at::date::text                                  AS date,
                COALESCE(SUM(o.total_in_reporting_currency), 0)            AS attributed_revenue
            FROM orders o
            WHERE o.workspace_id = ?
              AND o.status IN ('completed', 'processing')
              AND o.total_in_reporting_currency IS NOT NULL
              AND o.occurred_at BETWEEN ? AND ?
              AND o.attribution_source IN ('pys', 'wc_native')
              AND o.{$attrCol}->>'channel_type' IN ('paid_social', 'paid_search')
            GROUP BY o.occurred_at::date
            ORDER BY o.occurred_at::date
        ", [
            $workspaceId,
            $from . ' 00:00:00',
            $to . ' 23:59:59',
        ]);

        $storeRevenueByDate = [];
        foreach ($dailyStoreRevenue as $sr) {
            $storeRevenueByDate[$sr->date] = (float) $sr->attributed_revenue;
        }

        $chartData = array_map(fn ($row) => [
            'date'               => $row->date,
            'platform_revenue'   => round((float) $row->platform_revenue, 2),
            'attributed_revenue' => round($storeRevenueByDate[$row->date] ?? 0, 2),
            'gap'                => round((float) $row->platform_revenue - ($storeRevenueByDate[$row->date] ?? 0), 2),
        ], $dailyGap);

        $totalDelta    = $totalPlatformRevenue - $totalAttributedRevenue;
        $totalDeltaPct = $totalAttributedRevenue > 0
            ? round(($totalDelta / $totalAttributedRevenue) * 100, 1)
            : null;

        return [
            'campaigns'  => $campaigns,
            'chart_data' => $chartData,
            'hero'       => [
                'total_spend'              => round($totalSpend, 2),
                'total_platform_revenue'   => round($totalPlatformRevenue, 2),
                'total_attributed_revenue' => round($totalAttributedRevenue, 2),
                'total_delta'              => round($totalDelta, 2),
                'total_delta_pct'          => $totalDeltaPct,
                'platform_roas'            => $totalSpend > 0 ? round($totalPlatformRevenue / $totalSpend, 2) : null,
                'real_roas'                => $totalSpend > 0 ? round($totalAttributedRevenue / $totalSpend, 2) : null,
            ],
            'platform'   => $platform,
        ];
    }

    /**
     * Build sampled journey order rows for Tab 3.
     *
     * Returns last 100 orders (filtered by journey_filter), each with
     * attribution_first_touch, attribution_last_touch, and attribution_click_ids
     * for the journey modal.
     *
     * @return array{orders: array, filter: string}
     */
    private function buildJourneys(
        int $workspaceId,
        string $from,
        string $to,
        string $journeyFilter,
        string $storeClause,
    ): array {
        $filterClause = match ($journeyFilter) {
            'new_customers' => 'AND o.is_first_for_customer = true',
            'high_ltv'      => 'AND o.total_in_reporting_currency >= 200',
            default         => '',
        };

        $orderBy = $journeyFilter === 'high_ltv'
            ? 'ORDER BY o.total_in_reporting_currency DESC'
            : 'ORDER BY o.occurred_at DESC';

        $rows = DB::select("
            SELECT
                o.id,
                o.occurred_at::text          AS occurred_at,
                o.total_in_reporting_currency AS revenue,
                o.is_first_for_customer,
                o.customer_email_hash,
                o.attribution_first_touch::text AS attribution_first_touch,
                o.attribution_last_touch::text  AS attribution_last_touch,
                o.attribution_click_ids::text   AS attribution_click_ids
            FROM orders o
            WHERE o.workspace_id = ?
              AND o.status IN ('completed', 'processing')
              AND o.total_in_reporting_currency IS NOT NULL
              AND o.occurred_at BETWEEN ? AND ?
              {$storeClause}
              {$filterClause}
            {$orderBy}
            LIMIT 100
        ", [$workspaceId, $from . ' 00:00:00', $to . ' 23:59:59']);

        $orders = array_map(function ($r): array {
            return [
                'id'                     => $r->id,
                'occurred_at'            => $r->occurred_at,
                'revenue'                => round((float) $r->revenue, 2),
                'is_first_for_customer'  => (bool) $r->is_first_for_customer,
                'customer_email_hash'    => $r->customer_email_hash,
                'attribution_first_touch'=> $r->attribution_first_touch
                    ? json_decode($r->attribution_first_touch, true)
                    : null,
                'attribution_last_touch' => $r->attribution_last_touch
                    ? json_decode($r->attribution_last_touch, true)
                    : null,
                'attribution_click_ids'  => $r->attribution_click_ids
                    ? json_decode($r->attribution_click_ids, true)
                    : null,
            ];
        }, $rows);

        return ['orders' => $orders, 'filter' => $journeyFilter];
    }

    /**
     * Build opportunities sidebar items from DB recommendations + inline channel-reallocation.
     *
     * Inline items are generated when paid channel CPA differential > 20%.
     * Striking-distance items are generated when GSC queries in position 11–20 exist.
     *
     * @param  array $consolidatedChannels  Already-built §M1 channel rows (for CPA computation)
     * @return array<int, array{type: string, priority: int, title: string, body: string, impact_estimate: float|null, impact_currency: string|null, target_url: string|null}>
     */
    private function buildOpportunities(
        int $workspaceId,
        string $from,
        string $to,
        array $consolidatedChannels,
    ): array {
        // DB recommendations (Phase 3+ agents write here)
        $dbRecs = DB::select("
            SELECT id, type, priority, title, body, impact_estimate, impact_currency, target_url
            FROM recommendations
            WHERE workspace_id = ?
              AND status = 'open'
            ORDER BY priority ASC
            LIMIT 8
        ", [$workspaceId]);

        $items = array_map(fn ($r) => [
            'id'              => $r->id,
            'type'            => $r->type,
            'priority'        => $r->priority,
            'title'           => $r->title,
            'body'            => $r->body,
            'impact_estimate' => $r->impact_estimate !== null ? (float) $r->impact_estimate : null,
            'impact_currency' => $r->impact_currency,
            'target_url'      => $r->target_url,
        ], $dbRecs);

        // Inline: channel reallocation when CPA differential > 20%
        $paidSocial = null;
        $paidSearch = null;
        foreach ($consolidatedChannels as $ch) {
            if ($ch['channel_type'] === 'paid_social' || ($ch['rollup_key'] ?? '') === 'paid_social') {
                $paidSocial = $ch;
            }
            if ($ch['channel_type'] === 'paid_search' || ($ch['rollup_key'] ?? '') === 'paid_search') {
                $paidSearch = $ch;
            }
        }

        if (
            $paidSocial !== null && $paidSearch !== null
            && ($paidSocial['cac'] ?? 0) > 0 && ($paidSearch['cac'] ?? 0) > 0
            && $paidSocial['cac'] > $paidSearch['cac'] * 1.2
        ) {
            $days   = max(1, Carbon::parse($from)->diffInDays(Carbon::parse($to)));
            $avgDailyNewCusts = (($paidSocial['new_customers'] ?? 0) + ($paidSearch['new_customers'] ?? 0))
                / $days;
            $cpaDiff      = $paidSocial['cac'] - $paidSearch['cac'];
            $impact       = round($cpaDiff * $avgDailyNewCusts * 30, 0);

            $existingTypes = array_column($items, 'type');
            if (! in_array('channel_reallocation', $existingTypes, true)) {
                $items[] = [
                    'id'              => null,
                    'type'            => 'channel_reallocation',
                    'priority'        => 50,
                    'title'           => 'Shift budget from Paid Social to Paid Search',
                    'body'            => sprintf(
                        'Paid Social CAC is %s vs %s for Paid Search — a %s%% premium. Shifting daily budget could improve blended CAC.',
                        number_format((float) $paidSocial['cac'], 0),
                        number_format((float) $paidSearch['cac'], 0),
                        number_format(($cpaDiff / $paidSearch['cac']) * 100, 0),
                    ),
                    'impact_estimate' => $impact > 0 ? $impact : null,
                    'impact_currency' => null,
                    'target_url'      => '/acquisition?tab=platform-vs-real',
                ];
            }
        }

        // Inline: striking-distance GSC queries
        $strikingCount = DB::selectOne("
            SELECT COUNT(DISTINCT q.query) AS cnt
            FROM gsc_queries q
            JOIN search_console_properties p ON p.id = q.property_id
            WHERE p.workspace_id = ?
              AND q.date BETWEEN ? AND ?
              AND q.device = 'all'
              AND q.country = 'ZZ'
              AND q.impressions > 0
            GROUP BY q.query
            HAVING CASE WHEN SUM(q.impressions) > 0
                        THEN SUM(q.position * q.impressions) / SUM(q.impressions)
                        ELSE NULL END BETWEEN 11 AND 20
        ", [$workspaceId, $from, $to]);

        // The above query returns one row per striking-distance query; wrap in a count
        $strikingCount2 = DB::selectOne("
            SELECT COUNT(*) AS cnt FROM (
                SELECT q.query
                FROM gsc_queries q
                JOIN search_console_properties p ON p.id = q.property_id
                WHERE p.workspace_id = ?
                  AND q.date BETWEEN ? AND ?
                  AND q.device = 'all'
                  AND q.country = 'ZZ'
                  AND q.impressions > 0
                GROUP BY q.query
                HAVING CASE WHEN SUM(q.impressions) > 0
                            THEN SUM(q.position * q.impressions) / SUM(q.impressions)
                            ELSE NULL END BETWEEN 11 AND 20
            ) sub
        ", [$workspaceId, $from, $to]);

        $strikingN = (int) ($strikingCount2->cnt ?? 0);
        if ($strikingN > 0) {
            $existingTypes = array_column($items, 'type');
            if (! in_array('organic_to_paid', $existingTypes, true)) {
                $items[] = [
                    'id'              => null,
                    'type'            => 'organic_to_paid',
                    'priority'        => 60,
                    'title'           => "{$strikingN} " . ($strikingN === 1 ? 'query' : 'queries') . ' in striking distance',
                    'body'            => 'Organic positions 11–20 — a targeted ad campaign could capture these clicks at lower CPC than top-ranked queries.',
                    'impact_estimate' => null,
                    'impact_currency' => null,
                    'target_url'      => '/organic?tab=queries',
                ];
            }
        }

        // Sort by priority and return
        usort($items, fn ($a, $b) => $a['priority'] <=> $b['priority']);

        return array_values($items);
    }

    /**
     * Attach campaign children to paid channel rows for the expandable channel matrix.
     *
     * Reuses the already-fetched discrepancy campaign rows to avoid an extra DB query.
     * Organic channel gets top-10 GSC queries as sub-rows.
     */
    private function attachChannelChildren(
        array $channels,
        array $campaigns,
        int $workspaceId,
        string $from,
        string $to,
    ): array {
        // Build campaign sub-rows grouped by derived channel_type
        $campaignChildren = ['paid_social' => [], 'paid_search' => []];
        foreach ($campaigns as $camp) {
            $key = $camp['platform'] === 'facebook' ? 'paid_social' : 'paid_search';
            if (count($campaignChildren[$key]) < 10) {
                $campaignChildren[$key][] = [
                    'id'            => $camp['campaign_id'],
                    'name'          => $camp['campaign_name'],
                    'spend'         => $camp['spend'],
                    'revenue'       => $camp['attributed_revenue'],
                    'real_roas'     => $camp['real_roas'],
                    'platform_roas' => $camp['platform_roas'],
                ];
            }
        }

        // Organic sub-rows: top 10 GSC queries by clicks
        $gscQueryRows = DB::select("
            SELECT q.query, SUM(q.clicks) AS clicks, SUM(q.impressions) AS impressions,
                   CASE WHEN SUM(q.impressions) > 0
                        THEN SUM(q.position * q.impressions) / SUM(q.impressions)
                        ELSE NULL END AS position
            FROM gsc_queries q
            JOIN search_console_properties p ON p.id = q.property_id
            WHERE p.workspace_id = ?
              AND q.date BETWEEN ? AND ?
              AND q.device = 'all'
              AND q.country = 'ZZ'
            GROUP BY q.query
            ORDER BY SUM(q.clicks) DESC
            LIMIT 10
        ", [$workspaceId, $from, $to]);

        $organicChildren = array_map(fn ($r) => [
            'id'         => $r->query,
            'name'       => $r->query,
            'clicks'     => (int) $r->clicks,
            'impressions'=> (int) $r->impressions,
            'position'   => $r->position !== null ? round((float) $r->position, 1) : null,
            'spend'      => null,
            'revenue'    => null,
            'real_roas'  => null,
            'platform_roas' => null,
        ], $gscQueryRows);

        return array_map(function (array $ch) use ($campaignChildren, $organicChildren): array {
            $rollupKey = $ch['rollup_key'] ?? $ch['channel_type'];
            if ($rollupKey === 'paid_social') {
                $ch['children'] = $campaignChildren['paid_social'];
            } elseif ($rollupKey === 'paid_search') {
                $ch['children'] = $campaignChildren['paid_search'];
            } elseif ($rollupKey === 'organic') {
                $ch['children'] = $organicChildren;
            }
            return $ch;
        }, $channels);
    }

    // ── Consolidation helpers ───────────────────────────────────────────────

    /**
     * Consolidate multiple channel_names under the same channel_type into one row.
     *
     * E.g. "Paid — Facebook" and "Paid — Instagram" both have channel_type=paid_social
     * but produce separate rows from the GROUP BY. We keep the most-orders name.
     *
     * @param  array<int, array<string, mixed>> $channels
     * @return array<int, array<string, mixed>>
     */
    private function consolidateByChannelType(array $channels): array
    {
        $grouped = [];
        foreach ($channels as $ch) {
            $type = $ch['channel_type'];

            if ($type === 'not_tracked') {
                $grouped['__not_tracked'] = $ch;
                continue;
            }

            if (! isset($grouped[$type])) {
                $grouped[$type] = $ch;
                continue;
            }

            // Merge into existing row — keep the channel_name with more orders
            $existing = $grouped[$type];
            if ($ch['orders'] > $existing['orders']) {
                $grouped[$type]['channel_name'] = $ch['channel_name'];
            }
            $grouped[$type]['orders']               += $ch['orders'];
            $grouped[$type]['revenue']              += $ch['revenue'];
            $grouped[$type]['new_customers']         = ($grouped[$type]['new_customers'] ?? 0) + ($ch['new_customers'] ?? 0);
            $grouped[$type]['total_cogs']            = $grouped[$type]['total_cogs'] !== null || $ch['total_cogs'] !== null
                ? (($grouped[$type]['total_cogs'] ?? 0) + ($ch['total_cogs'] ?? 0))
                : null;
            $grouped[$type]['contribution_margin']   = $grouped[$type]['contribution_margin'] !== null || $ch['contribution_margin'] !== null
                ? (($grouped[$type]['contribution_margin'] ?? 0) + ($ch['contribution_margin'] ?? 0))
                : null;
        }

        // Recompute derived fields after consolidation
        foreach ($grouped as &$ch) {
            if (($ch['clicks'] ?? 0) > 0 && $ch['orders'] > 0) {
                $ch['cvr'] = round(($ch['orders'] / $ch['clicks']) * 100, 2);
            }
            $adSpend = $ch['ad_spend'] ?? null;
            $rev     = $ch['revenue'];
            $ch['real_profit'] = $rev
                - ($ch['total_cogs'] ?? 0)
                - ($adSpend ?? 0);

            if ($adSpend !== null && $adSpend > 0) {
                $ch['real_roas']  = round($rev / $adSpend, 2);
                $newCust = $ch['new_customers'] ?? 0;
                $ch['cac'] = $newCust > 0 ? round($adSpend / $newCust, 2) : null;
            }
        }
        unset($ch);

        return array_values($grouped);
    }

    /**
     * Apply §M1 cross-type rollup (LOCKED).
     *
     * Merges organic_search+organic_social → Organic, email+sms → Email,
     * direct+referral+other → Direct, affiliate → Paid Social.
     * not_tracked stays as its own row.
     */
    private function applyM1Rollup(array $channels): array
    {
        $rollupGroups = [];

        foreach ($channels as $ch) {
            $type = $ch['channel_type'];

            if ($type === 'not_tracked') {
                $rollupGroups['__not_tracked'] = array_merge($ch, [
                    'rollup_key'   => 'not_tracked',
                    'channel_name' => 'Not Tracked',
                ]);
                continue;
            }

            $rollupKey = self::M1_ROLLUP_KEY[$type] ?? 'direct';
            $label     = self::M1_ROLLUP_LABEL[$rollupKey] ?? 'Other';

            if (! isset($rollupGroups[$rollupKey])) {
                $rollupGroups[$rollupKey] = array_merge($ch, [
                    'rollup_key'   => $rollupKey,
                    'channel_name' => $label,
                    // Keep channel_type as the canonical type for the rollup key
                    'channel_type' => $rollupKey === 'organic' ? 'organic_search' : $type,
                ]);
                continue;
            }

            // Merge numeric fields
            $rollupGroups[$rollupKey]['orders']                 += $ch['orders'];
            $rollupGroups[$rollupKey]['revenue']                += $ch['revenue'];
            $rollupGroups[$rollupKey]['new_customers']           = ($rollupGroups[$rollupKey]['new_customers'] ?? 0) + ($ch['new_customers'] ?? 0);
            $rollupGroups[$rollupKey]['real_profit']            += ($ch['real_profit'] ?? 0);

            if ($ch['total_cogs'] !== null) {
                $rollupGroups[$rollupKey]['total_cogs'] = ($rollupGroups[$rollupKey]['total_cogs'] ?? 0) + $ch['total_cogs'];
            }
            if ($ch['contribution_margin'] !== null) {
                $rollupGroups[$rollupKey]['contribution_margin'] = ($rollupGroups[$rollupKey]['contribution_margin'] ?? 0) + $ch['contribution_margin'];
            }
            if ($ch['ad_spend'] !== null) {
                $rollupGroups[$rollupKey]['ad_spend'] = ($rollupGroups[$rollupKey]['ad_spend'] ?? 0) + $ch['ad_spend'];
            }
            if ($ch['clicks'] !== null) {
                $rollupGroups[$rollupKey]['clicks'] = ($rollupGroups[$rollupKey]['clicks'] ?? 0) + $ch['clicks'];
            }
            // Take the worse (higher) CAC and lower ROAS after merging
            if ($ch['platform_roas'] !== null && $rollupGroups[$rollupKey]['platform_roas'] !== null) {
                $totalSpend = $rollupGroups[$rollupKey]['ad_spend'] ?? 0;
                $rollupGroups[$rollupKey]['platform_roas'] = $totalSpend > 0
                    ? round(($rollupGroups[$rollupKey]['revenue'] / $totalSpend), 2)
                    : null;
            }
        }

        // Recompute derived fields after merge
        foreach ($rollupGroups as &$ch) {
            $spend = $ch['ad_spend'] ?? null;
            $rev   = $ch['revenue'];
            if ($spend !== null && $spend > 0) {
                $ch['real_roas'] = round($rev / $spend, 2);
                $newCust = $ch['new_customers'] ?? 0;
                $ch['cac'] = $newCust > 0 ? round($spend / $newCust, 2) : null;
                if ($ch['platform_roas'] !== null && $ch['real_roas'] > 0) {
                    $ch['platform_roas_delta_pct'] = round(
                        ($ch['platform_roas'] - $ch['real_roas']) / $ch['real_roas'] * 100, 1,
                    );
                }
            }
            if (($ch['clicks'] ?? 0) > 0) {
                $ch['cvr'] = round(($ch['orders'] / $ch['clicks']) * 100, 2);
            }
        }
        unset($ch);

        return array_values($rollupGroups);
    }

    /**
     * Build daily revenue chart data grouped by channel_type (top 5).
     *
     * @return array<int, array{date: string, channels: array<string, float>}>
     */
    private function buildDailyChartData(
        int $workspaceId,
        string $from,
        string $to,
        string $storeClause,
        string $attrCol,
    ): array {
        $rows = DB::select("
            SELECT
                o.occurred_at::date::text                                        AS date,
                COALESCE({$attrCol}->>'channel_type', 'not_tracked')             AS channel_type,
                COALESCE({$attrCol}->>'channel', 'Not Tracked')                  AS channel_name,
                COALESCE(SUM(total_in_reporting_currency), 0)                    AS revenue
            FROM orders o
            WHERE o.workspace_id = ?
              AND o.status IN ('completed', 'processing')
              AND o.total_in_reporting_currency IS NOT NULL
              AND o.occurred_at BETWEEN ? AND ?
              {$storeClause}
            GROUP BY o.occurred_at::date, {$attrCol}->>'channel_type', {$attrCol}->>'channel'
            ORDER BY date
        ", [
            $workspaceId,
            $from . ' 00:00:00',
            $to . ' 23:59:59',
        ]);

        $channelTotals = [];
        foreach ($rows as $r) {
            $name                   = $r->channel_name;
            $channelTotals[$name]   = ($channelTotals[$name] ?? 0) + (float) $r->revenue;
        }
        arsort($channelTotals);
        $topChannels = array_slice(array_keys($channelTotals), 0, 5);

        $dateMap = [];
        foreach ($rows as $r) {
            $name = $r->channel_name;
            if (! in_array($name, $topChannels, true)) {
                $name = 'Other';
            }
            $dateMap[$r->date][$name] = ($dateMap[$r->date][$name] ?? 0) + (float) $r->revenue;
        }

        $chartData   = [];
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
