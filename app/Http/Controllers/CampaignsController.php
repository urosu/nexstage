<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\AdAccount;
use App\Models\AdInsight;
use App\Models\DailySnapshot;
use App\Models\Workspace;
use App\Services\RevenueAttributionService;
use App\Services\WorkspaceContext;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class CampaignsController extends Controller
{
    public function __construct(
        private readonly RevenueAttributionService $attribution,
    ) {}

    public function __invoke(Request $request): Response
    {
        $workspaceId = app(WorkspaceContext::class)->id();
        $workspace   = Workspace::withoutGlobalScopes()->findOrFail($workspaceId);
        $params      = $this->validateParams($request);

        $adAccounts = AdAccount::withoutGlobalScopes()
            ->where('workspace_id', $workspaceId)
            ->select(['id', 'platform', 'name', 'status', 'last_synced_at'])
            ->get();

        $adAccountList = $adAccounts->map(fn ($a) => [
            'id'             => $a->id,
            'platform'       => $a->platform,
            'name'           => $a->name,
            'status'         => $a->status,
            'last_synced_at' => $a->last_synced_at,
        ])->values()->all();

        [$totalRevenue, $unattributedRevenue] = $this->computeRevenueContext(
            $workspaceId, $workspace->has_store, $params['from'], $params['to'],
        );

        if ($adAccounts->isEmpty()) {
            return Inertia::render('Campaigns/Index', [
                'has_ad_accounts'       => false,
                'ad_accounts'           => [],
                'metrics'               => null,
                'compare_metrics'       => null,
                'campaigns'             => [],
                'platform_breakdown'    => [],
                'chart_data'            => [],
                'compare_chart_data'    => null,
                'total_revenue'         => $totalRevenue,
                'unattributed_revenue'  => $unattributedRevenue,
                'not_tracked_pct'       => null,
                'workspace_target_roas' => $workspace->target_roas ? (float) $workspace->target_roas : null,
                ...$params,
            ]);
        }

        // Filter ad accounts by selected platform, then optionally by specific accounts
        $filteredAccounts = $params['platform'] === 'all'
            ? $adAccounts
            : $adAccounts->where('platform', $params['platform']);

        if (! empty($params['ad_account_ids'])) {
            $filteredAccounts = $filteredAccounts->whereIn('id', $params['ad_account_ids']);
        }

        $adAccountIds = $filteredAccounts->pluck('id')->all();

        // Campaigns are computed first so the summary cards can be derived directly
        // from the same filtered set (platform + status + ad_account_ids all applied).
        // This guarantees cards = sum of table for every filter combination.
        $campaigns = $this->computeCampaigns(
            $workspaceId,
            $adAccounts,
            $params['from'],
            $params['to'],
            $params['platform'],
            $params['status'],
            $params['ad_account_ids'],
        );

        // Sort in PHP to handle NULLs (always last regardless of direction)
        $sortKey   = $params['sort'];
        $direction = $params['direction'];
        usort($campaigns, function (array $a, array $b) use ($sortKey, $direction): int {
            $aVal = $a[$sortKey];
            $bVal = $b[$sortKey];
            if ($aVal === null && $bVal === null) return 0;
            if ($aVal === null) return 1;
            if ($bVal === null) return -1;
            $cmp = $aVal <=> $bVal;
            return $direction === 'asc' ? $cmp : -$cmp;
        });

        // Primary metrics are summed from $campaigns (pre-W/L-filter) so cards always match the table.
        // Compare metrics still use the aggregation query since there are no compare-period campaign rows.
        $metrics = $this->metricsFromCampaigns($campaigns, $workspaceId, $params['from'], $params['to']);

        $compareMetrics = ($params['compare_from'] && $params['compare_to'])
            ? $this->computeMetrics(
                $workspaceId,
                $adAccountIds,
                $params['compare_from'],
                $params['compare_to'],
                $params['platform'],
            )
            : null;

        // ── Winners / Losers server-side classification ──────────────────────
        // Runs after sorting so filter=winners returns winners in sort order.
        // See: PLANNING.md section 15 (Winners/Losers classifier)
        [
            'campaigns'           => $campaigns,
            'total_count'         => $totalCampaignCount,
            'active_classifier'   => $activeClassifier,
            'peer_avg_roas'       => $peerAvgRoas,
        ] = $this->applyWinnersLosers($campaigns, $params, $workspace, $adAccountIds);

        $platformBreakdown = $this->computePlatformBreakdown($workspaceId, $params['from'], $params['to']);

        $chartData = $this->buildSpendChart(
            $workspaceId, $adAccountIds, $params['from'], $params['to'], $params['granularity'],
        );

        $compareChartData = ($params['compare_from'] && $params['compare_to'])
            ? $this->buildSpendChart(
                $workspaceId, $adAccountIds, $params['compare_from'], $params['compare_to'], $params['granularity'],
            )
            : null;

        // Not Tracked %: max(0, total_revenue - total_tagged) / total_revenue * 100
        // See PLANNING.md section 13 — "Not Tracked" terminology, never "unattributed revenue"
        $notTrackedPct = ($totalRevenue !== null && $totalRevenue > 0 && $unattributedRevenue !== null)
            ? round(($unattributedRevenue / $totalRevenue) * 100, 1)
            : null;

        return Inertia::render('Campaigns/Index', [
            'has_ad_accounts'       => true,
            'ad_accounts'           => $adAccountList,
            'metrics'               => $metrics,
            'compare_metrics'       => $compareMetrics,
            'campaigns'             => $campaigns,
            'campaigns_total_count' => $totalCampaignCount,
            'platform_breakdown'    => $platformBreakdown,
            'chart_data'            => $chartData,
            'compare_chart_data'    => $compareChartData,
            'total_revenue'         => $totalRevenue,
            'unattributed_revenue'  => $unattributedRevenue,
            'not_tracked_pct'       => $notTrackedPct,
            'workspace_target_roas' => $workspace->target_roas ? (float) $workspace->target_roas : null,
            'wl_has_target'         => $workspace->target_roas !== null,
            'active_classifier'     => $activeClassifier,
            'wl_peer_avg_roas'      => $peerAvgRoas,
            ...$params,
        ]);
    }

    // ─── Parameter validation ─────────────────────────────────────────────────

    /** @return array{from:string,to:string,compare_from:string|null,compare_to:string|null,granularity:string,platform:string,status:string,view:string,sort:string,direction:string,ad_account_ids:int[],filter:string,classifier:string|null} */
    private function validateParams(Request $request): array
    {
        $v = $request->validate([
            'from'            => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'to'              => ['sometimes', 'nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'compare_from'    => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'compare_to'      => ['sometimes', 'nullable', 'date_format:Y-m-d', 'after_or_equal:compare_from'],
            'granularity'     => ['sometimes', 'nullable', 'in:hourly,daily,weekly'],
            'platform'        => ['sometimes', 'nullable', 'in:all,facebook,google'],
            'status'          => ['sometimes', 'nullable', 'in:all,active,paused'],
            'view'            => ['sometimes', 'nullable', 'in:table,quadrant'],
            'sort'            => ['sometimes', 'nullable', 'in:real_roas,real_cpo,spend,attributed_revenue,attributed_orders,spend_velocity,platform_roas,impressions,clicks,ctr,cpc'],
            'direction'       => ['sometimes', 'nullable', 'in:asc,desc'],
            'ad_account_ids'  => ['sometimes', 'nullable', 'string'],
            'filter'          => ['sometimes', 'nullable', 'in:all,winners,losers'],
            'classifier'      => ['sometimes', 'nullable', 'in:target,peer,period'],
        ]);

        // Parse comma-separated ad account IDs
        $adAccountIds = array_values(array_filter(
            array_map('intval', explode(',', $v['ad_account_ids'] ?? '')),
            fn ($id) => $id > 0
        ));

        return [
            'from'            => $v['from']          ?? now()->subDays(29)->toDateString(),
            'to'              => $v['to']             ?? now()->toDateString(),
            'compare_from'    => $v['compare_from']   ?? null,
            'compare_to'      => $v['compare_to']     ?? null,
            'granularity'     => $v['granularity']    ?? 'daily',
            'platform'        => $v['platform']       ?? 'all',
            'status'          => $v['status']         ?? 'all',
            'view'            => $v['view']           ?? 'table',
            'sort'            => $v['sort']           ?? 'real_roas',
            'direction'       => $v['direction']      ?? 'desc',
            'ad_account_ids'  => $adAccountIds,
            'filter'          => $v['filter']         ?? 'all',
            'classifier'      => $v['classifier']     ?? null,
        ];
    }

    // ─── Metrics ─────────────────────────────────────────────────────────────

    /**
     * @param  int[]  $adAccountIds
     * @param  string $platform  'all' | 'facebook' | 'google' — narrows attribution to the matching channel type
     *
     * Note: the status filter is intentionally NOT applied here. Campaign status is live state,
     * not historical — attributed revenue cannot be cleanly split by whether a campaign is
     * currently active or paused. The status filter only affects which rows appear in the table.
     */
    private function computeMetrics(
        int $workspaceId,
        array $adAccountIds,
        string $from,
        string $to,
        string $platform = 'all',
    ): array {
        if (empty($adAccountIds)) {
            return $this->emptyMetrics();
        }

        $snapRow = DailySnapshot::withoutGlobalScopes()
            ->where('workspace_id', $workspaceId)
            ->whereBetween('date', [$from, $to])
            ->selectRaw('COALESCE(SUM(revenue), 0) AS total_revenue, COALESCE(SUM(orders_count), 0) AS total_orders')
            ->first();

        $revenue = (float) ($snapRow->total_revenue ?? 0);
        $orders  = (int)   ($snapRow->total_orders  ?? 0);

        $adRow = AdInsight::withoutGlobalScopes()
            ->where('workspace_id', $workspaceId)
            ->whereIn('ad_account_id', $adAccountIds)
            ->where('level', 'campaign')
            ->whereNull('hour')
            ->whereBetween('date', [$from, $to])
            ->selectRaw('
                COALESCE(SUM(spend_in_reporting_currency), 0) AS total_spend,
                COALESCE(SUM(impressions), 0)                 AS total_impressions,
                COALESCE(SUM(clicks), 0)                      AS total_clicks
            ')
            ->first();

        $spend       = (float) ($adRow->total_spend       ?? 0);
        $impressions = (int)   ($adRow->total_impressions  ?? 0);
        $clicks      = (int)   ($adRow->total_clicks       ?? 0);

        // Narrow attributed revenue/orders to the selected platform's channel type so Real ROAS
        // and Real CPO are consistent with the spend shown in the cards.
        // paid_social = Meta/Facebook, paid_search = Google Ads.
        $channelFilter = match ($platform) {
            'facebook' => "AND attribution_last_touch->>'channel_type' = 'paid_social'",
            'google'   => "AND attribution_last_touch->>'channel_type' = 'paid_search'",
            default    => "AND attribution_last_touch->>'channel_type' IN ('paid_social', 'paid_search')",
        };

        $attrRow = DB::selectOne("
            SELECT
                COALESCE(SUM(total_in_reporting_currency), 0) AS attributed_revenue,
                COUNT(id)                                      AS attributed_orders
            FROM orders
            WHERE workspace_id = ?
              AND status IN ('completed', 'processing')
              AND total_in_reporting_currency IS NOT NULL
              AND attribution_source IN ('pys', 'wc_native')
              AND attribution_last_touch->>'campaign' IS NOT NULL
              AND attribution_last_touch->>'campaign' <> ''
              {$channelFilter}
              AND occurred_at BETWEEN ? AND ?
        ", [$workspaceId, $from . ' 00:00:00', $to . ' 23:59:59']);

        $attributedRevenue = (float) ($attrRow->attributed_revenue ?? 0);
        $attributedOrders  = (int)   ($attrRow->attributed_orders  ?? 0);

        return [
            'roas'                => ($spend > 0 && $revenue > 0) ? round($revenue / $spend, 2) : null,
            'cpo'                 => ($spend > 0 && $orders > 0)  ? round($spend / $orders, 2)  : null,
            'spend'               => $spend > 0 ? $spend : null,
            'revenue'             => $revenue > 0 ? $revenue : null,
            'attributed_revenue'  => $attributedRevenue > 0 ? $attributedRevenue : null,
            'attributed_orders'   => $attributedOrders,
            'real_roas'           => ($spend > 0 && $attributedRevenue > 0) ? round($attributedRevenue / $spend, 2) : null,
            'real_cpo'            => ($spend > 0 && $attributedOrders > 0)  ? round($spend / $attributedOrders, 2)  : null,
            'impressions'         => $impressions,
            'clicks'              => $clicks,
            'ctr'                 => $impressions > 0 ? round(($clicks / $impressions) * 100, 2) : null,
            'cpc'                 => $clicks > 0 ? round($spend / $clicks, 4) : null,
        ];
    }

    /** Zero-value metrics shape returned when no ad accounts match the current filter. */
    private function emptyMetrics(): array
    {
        return [
            'roas'               => null,
            'cpo'                => null,
            'spend'              => null,
            'revenue'            => null,
            'attributed_revenue' => null,
            'attributed_orders'  => 0,
            'real_roas'          => null,
            'real_cpo'           => null,
            'impressions'        => 0,
            'clicks'             => 0,
            'ctr'                => null,
            'cpc'                => null,
        ];
    }

    /**
     * Derive summary card metrics by summing the campaign rows already computed for the table.
     * This guarantees cards = sum of table regardless of platform/status/ad-account filters.
     * DailySnapshot revenue/orders are still queried directly (they are store-level totals
     * not present on individual campaign rows).
     *
     * @param  array<int, array<string, mixed>>  $campaigns  Pre-filtered, pre-sorted, pre-W/L campaign rows
     */
    private function metricsFromCampaigns(array $campaigns, int $workspaceId, string $from, string $to): array
    {
        $spend       = (float) array_sum(array_column($campaigns, 'spend'));
        $attrRevenue = (float) array_sum(array_map(fn ($c) => (float) ($c['attributed_revenue'] ?? 0), $campaigns));
        $attrOrders  = (int)   array_sum(array_column($campaigns, 'attributed_orders'));
        $impressions = (int)   array_sum(array_column($campaigns, 'impressions'));
        $clicks      = (int)   array_sum(array_column($campaigns, 'clicks'));

        $snapRow2 = DailySnapshot::withoutGlobalScopes()
            ->where('workspace_id', $workspaceId)
            ->whereBetween('date', [$from, $to])
            ->selectRaw('COALESCE(SUM(revenue), 0) AS total_revenue, COALESCE(SUM(orders_count), 0) AS total_orders')
            ->first();

        $revenue = (float) ($snapRow2->total_revenue ?? 0);
        $orders  = (int)   ($snapRow2->total_orders  ?? 0);

        return [
            'roas'               => ($spend > 0 && $revenue > 0)    ? round($revenue / $spend, 2)      : null,
            'cpo'                => ($spend > 0 && $orders > 0)     ? round($spend / $orders, 2)       : null,
            'spend'              => $spend > 0 ? $spend : null,
            'revenue'            => $revenue > 0 ? $revenue : null,
            'attributed_revenue' => $attrRevenue > 0 ? $attrRevenue : null,
            'attributed_orders'  => $attrOrders,
            'real_roas'          => ($spend > 0 && $attrRevenue > 0) ? round($attrRevenue / $spend, 2) : null,
            'real_cpo'           => ($spend > 0 && $attrOrders > 0)  ? round($spend / $attrOrders, 2)  : null,
            'impressions'        => $impressions,
            'clicks'             => $clicks,
            'ctr'                => $impressions > 0 ? round(($clicks / $impressions) * 100, 2) : null,
            'cpc'                => $clicks > 0 ? round($spend / $clicks, 4) : null,
        ];
    }

    // ─── Campaign rows ────────────────────────────────────────────────────────

    /**
     * All campaigns for the period, optionally filtered by platform and status.
     * Includes UTM-attributed real_roas and real_cpo.
     *
     * @param  \Illuminate\Support\Collection  $adAccounts
     * @return array<int, array<string, mixed>>
     */
    /** @param int[] $adAccountIds */
    private function computeCampaigns(
        int $workspaceId,
        $adAccounts,
        string $from,
        string $to,
        string $platform,
        string $status,
        array $adAccountIds = [],
    ): array {
        $filteredAccounts = $platform === 'all'
            ? $adAccounts
            : $adAccounts->where('platform', $platform);

        if (! empty($adAccountIds)) {
            $filteredAccounts = $filteredAccounts->whereIn('id', $adAccountIds);
        }

        if ($filteredAccounts->isEmpty()) {
            return [];
        }

        $adAccountIds = $filteredAccounts->pluck('id')->all();
        $placeholders = implode(',', array_fill(0, count($adAccountIds), '?'));

        // Optional status filter on campaigns
        $statusFilter = match ($status) {
            'active' => "AND LOWER(c.status) IN ('active','enabled','delivering')",
            'paused' => "AND LOWER(c.status) IN ('paused','inactive','disabled')",
            default  => '',
        };

        $rows = DB::select("
            SELECT
                c.id,
                c.name,
                aa.platform,
                c.status,
                c.daily_budget,
                c.lifetime_budget,
                c.budget_type,
                c.target_roas,
                COALESCE(SUM(ai.spend_in_reporting_currency), 0) AS total_spend,
                COALESCE(SUM(ai.impressions), 0)                 AS total_impressions,
                COALESCE(SUM(ai.clicks), 0)                      AS total_clicks,
                AVG(ai.platform_roas)                            AS avg_platform_roas
            FROM ad_insights ai
            JOIN campaigns c    ON c.id  = ai.campaign_id
            JOIN ad_accounts aa ON aa.id = ai.ad_account_id
            WHERE ai.workspace_id = ?
              AND ai.ad_account_id IN ({$placeholders})
              AND ai.level = 'campaign'
              AND ai.hour IS NULL
              AND ai.date BETWEEN ? AND ?
              {$statusFilter}
            GROUP BY c.id, c.name, aa.platform, c.status, c.daily_budget, c.lifetime_budget, c.budget_type, c.target_roas
        ", array_merge([$workspaceId], $adAccountIds, [$from, $to]));

        // Precompute period/elapsed day counts for spend velocity.
        // Velocity = (spend / budget_for_period) / (days_elapsed / days_in_period)
        // A value of 1.0 means perfectly on pace; >1.0 = ahead of budget; <1.0 = behind.
        $daysInPeriod = Carbon::parse($from)->diffInDays(Carbon::parse($to)) + 1;
        $daysElapsed  = min(Carbon::parse($from)->diffInDays(Carbon::today()) + 1, $daysInPeriod);

        // Build UTM attribution map for matched platforms
        $utmPlatform = $platform === 'all' ? '' : $platform;
        $attrMap     = $this->buildUtmAttributionMap($workspaceId, $from, $to, $utmPlatform);

        return array_map(function (object $row) use ($attrMap, $daysInPeriod, $daysElapsed): array {
            $spend       = (float) $row->total_spend;
            $impressions = (int)   $row->total_impressions;
            $clicks      = (int)   $row->total_clicks;

            // Keyed by campaigns.id — see buildUtmAttributionMap for why.
            $attr              = $attrMap[(int) $row->id] ?? null;
            $attributedRevenue = $attr !== null ? (float) $attr['revenue'] : null;
            $attributedOrders  = $attr !== null ? (int)   $attr['orders']  : 0;

            // Spend velocity: how fast the campaign is burning through its budget vs expected pace.
            // Null when no budget is set or the period hasn't started yet.
            $spendVelocity = null;
            if ($daysInPeriod > 0 && $daysElapsed > 0) {
                $budgetForPeriod = match ($row->budget_type) {
                    'daily'    => ($row->daily_budget !== null)
                        ? (float) $row->daily_budget * $daysInPeriod
                        : null,
                    'lifetime' => $row->lifetime_budget !== null
                        ? (float) $row->lifetime_budget
                        : null,
                    default    => null,
                };

                if ($budgetForPeriod !== null && $budgetForPeriod > 0) {
                    $expectedPace  = $daysElapsed / $daysInPeriod;
                    $actualPace    = $spend / $budgetForPeriod;
                    $spendVelocity = round($actualPace / $expectedPace, 3);
                }
            }

            return [
                'id'                 => (int)    $row->id,
                'name'               => (string) ($row->name ?? ''),
                'platform'           => (string) $row->platform,
                'status'             => $row->status,
                'spend'              => $spend,
                'impressions'        => $impressions,
                'clicks'             => $clicks,
                'ctr'                => $impressions > 0 ? round(($clicks / $impressions) * 100, 2) : null,
                'cpc'                => $clicks > 0 ? round($spend / $clicks, 4) : null,
                'platform_roas'      => $row->avg_platform_roas !== null
                    ? round((float) $row->avg_platform_roas, 2)
                    : null,
                'real_roas'          => ($spend > 0 && $attributedRevenue !== null && $attributedRevenue > 0)
                    ? round($attributedRevenue / $spend, 2)
                    : null,
                'real_cpo'           => ($spend > 0 && $attributedOrders > 0)
                    ? round($spend / $attributedOrders, 2)
                    : null,
                'attributed_revenue' => $attributedRevenue,
                'attributed_orders'  => $attributedOrders,
                'spend_velocity'     => $spendVelocity,
                // Per-campaign target, used by the 'target' W/L classifier.
                // Null means the campaign has no individual target — workspace target_roas is the fallback.
                'target_roas'        => $row->target_roas !== null ? round((float) $row->target_roas, 2) : null,
            ];
        }, $rows);
    }

    /**
     * Build a map of campaign internal ID → {revenue, orders} from attribution-tagged orders.
     *
     * Matches attribution_last_touch->>'campaign' against campaigns.external_id (the common
     * case — ad platforms write the campaign ID into utm_campaign, e.g. Facebook writes the
     * numeric campaign ID like "120241558531060383") and campaigns.name (name-based fallback).
     *
     * Why external_id match: Facebook/Google ad URL builders default to inserting the platform
     * campaign ID, not the human-readable name. Without this join the attribution query returns
     * zero matches even when UTM data is present.
     *
     * Platform filter now uses channel_type rather than hardcoded utm_source aliases so
     * new ad platforms (TikTok, Pinterest) are classified correctly without code changes.
     *
     * @param  string  $platform  '' = all platforms, 'facebook' | 'google' = specific
     * @return array<int, array{revenue:float,orders:int}>  Keyed by campaigns.id
     */
    private function buildUtmAttributionMap(
        int $workspaceId,
        string $from,
        string $to,
        string $platform,
    ): array {
        $channelFilter = match ($platform) {
            'facebook' => "AND o.attribution_last_touch->>'channel_type' = 'paid_social'",
            'google'   => "AND o.attribution_last_touch->>'channel_type' = 'paid_search'",
            default    => "AND o.attribution_last_touch->>'channel_type' IN ('paid_social', 'paid_search')",
        };

        // previous_names fallback: if a campaign was renamed, historical orders still carry the
        // old UTM campaign value. The third OR arm checks whether the UTM value matches any entry
        // in the campaigns.previous_names JSONB array (stored as lowercase strings at write time
        // so the comparison is safe without LOWER()). See PLANNING.md section 16 for rename logic.
        $rows = DB::select("
            SELECT
                c.id                               AS campaign_id,
                SUM(o.total_in_reporting_currency) AS attributed_revenue,
                COUNT(o.id)                        AS attributed_orders
            FROM orders o
            JOIN campaigns c
              ON  c.workspace_id = o.workspace_id
              AND (
                    o.attribution_last_touch->>'campaign' = c.external_id
                 OR LOWER(o.attribution_last_touch->>'campaign') = LOWER(c.name)
                 OR (
                      jsonb_array_length(COALESCE(c.previous_names, '[]'::jsonb)) > 0
                      AND LOWER(o.attribution_last_touch->>'campaign') IN (
                            SELECT LOWER(pn.value)
                            FROM jsonb_array_elements_text(COALESCE(c.previous_names, '[]'::jsonb)) AS pn(value)
                          )
                    )
              )
            WHERE o.workspace_id = ?
              AND o.status IN ('completed', 'processing')
              AND o.total_in_reporting_currency IS NOT NULL
              AND o.attribution_source IN ('pys', 'wc_native')
              AND o.attribution_last_touch->>'campaign' IS NOT NULL
              AND o.attribution_last_touch->>'campaign' <> ''
              AND o.occurred_at BETWEEN ? AND ?
              {$channelFilter}
            GROUP BY c.id
        ", [$workspaceId, $from . ' 00:00:00', $to . ' 23:59:59']);

        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row->campaign_id] = [
                'revenue' => (float) $row->attributed_revenue,
                'orders'  => (int)   $row->attributed_orders,
            ];
        }

        return $map;
    }

    // ─── Winners / Losers classifier ─────────────────────────────────────────

    /**
     * Tags each campaign with wl_tag ('winner'|'loser'|null), then filters based on $params['filter'].
     *
     * Three classifiers per PLANNING.md section 15:
     *   target — above/below the campaign's own target_roas (falls back to workspace target_roas)
     *   peer   — above/below the workspace-average real_roas across all campaigns with spend
     *   period — improved/declined vs the same-length period immediately before $params['from']
     *
     * Default: 'target' when workspace has target_roas set, 'peer' otherwise.
     * Dormant campaigns (spend = 0) always receive wl_tag = null.
     *
     * @param  array<int, array<string, mixed>>  $campaigns    Already sorted campaign rows
     * @param  array<string, mixed>              $params       Validated request params
     * @param  Workspace                         $workspace
     * @param  int[]                             $adAccountIds Filtered ad account IDs (same as current query)
     * @return array{campaigns:array<int,array<string,mixed>>,total_count:int,active_classifier:string,peer_avg_roas:float|null}
     */
    private function applyWinnersLosers(
        array $campaigns,
        array $params,
        Workspace $workspace,
        array $adAccountIds,
    ): array {
        $workspaceTargetRoas = $workspace->target_roas !== null ? (float) $workspace->target_roas : null;
        $hasTarget           = $workspaceTargetRoas !== null;

        // Resolve effective classifier: explicit param > default logic
        $effectiveClassifier = $params['classifier']
            ?? ($hasTarget ? 'target' : 'peer');

        // Peer average: workspace-avg real_roas across campaigns with spend and attribution
        $campaignsWithRoas = array_filter(
            $campaigns,
            fn (array $c) => $c['real_roas'] !== null && $c['spend'] > 0,
        );
        $peerAvgRoas = count($campaignsWithRoas) > 0
            ? array_sum(array_column($campaignsWithRoas, 'real_roas')) / count($campaignsWithRoas)
            : null;

        // Previous period data — only fetched when period classifier is active
        $prevAttrMap  = [];
        $prevSpendMap = [];
        if ($effectiveClassifier === 'period') {
            $periodDays  = Carbon::parse($params['from'])->diffInDays(Carbon::parse($params['to'])) + 1;
            $prevTo      = Carbon::parse($params['from'])->subDay()->toDateString();
            $prevFrom    = Carbon::parse($prevTo)->subDays($periodDays - 1)->toDateString();
            $utmPlatform = $params['platform'] === 'all' ? '' : $params['platform'];
            $workspaceId = app(WorkspaceContext::class)->id();
            $prevAttrMap  = $this->buildUtmAttributionMap($workspaceId, $prevFrom, $prevTo, $utmPlatform);
            $prevSpendMap = $this->buildCampaignSpendMap($adAccountIds, $prevFrom, $prevTo);
        }

        // Tag every campaign row
        $tagged = array_map(function (array $c) use (
            $effectiveClassifier, $workspaceTargetRoas, $peerAvgRoas, $prevAttrMap, $prevSpendMap,
        ): array {
            // Dormant campaigns (zero spend) cannot be meaningfully ranked
            if ($c['spend'] <= 0) {
                return array_merge($c, ['wl_tag' => null]);
            }

            $tag = match ($effectiveClassifier) {
                'target' => $this->wlTagByTarget($c, $workspaceTargetRoas),
                'peer'   => $this->wlTagByPeer($c, $peerAvgRoas),
                'period' => $this->wlTagByPeriod($c, $prevAttrMap, $prevSpendMap),
                default  => null,
            };

            return array_merge($c, ['wl_tag' => $tag]);
        }, $campaigns);

        $totalCount = count($tagged);

        // Filter when an explicit filter is set.
        // The URL param uses plural ('winners'/'losers') while the tag is singular ('winner'/'loser').
        // Normalise by stripping the trailing 's' for comparison.
        if ($params['filter'] !== 'all') {
            $filterTag = rtrim($params['filter'], 's');
            $tagged    = array_values(
                array_filter($tagged, fn (array $c) => $c['wl_tag'] === $filterTag),
            );
        }

        return [
            'campaigns'         => $tagged,
            'total_count'       => $totalCount,
            'active_classifier' => $effectiveClassifier,
            'peer_avg_roas'     => $peerAvgRoas !== null ? round($peerAvgRoas, 2) : null,
        ];
    }

    /** Tag campaign vs its own target_roas (or workspace fallback). Null when no target exists. */
    private function wlTagByTarget(array $campaign, ?float $workspaceTargetRoas): ?string
    {
        $threshold = $campaign['target_roas'] ?? $workspaceTargetRoas;
        if ($threshold === null || $campaign['real_roas'] === null) {
            return null;
        }
        return $campaign['real_roas'] >= $threshold ? 'winner' : 'loser';
    }

    /** Tag campaign vs workspace-average real_roas across all campaigns with spend. */
    private function wlTagByPeer(array $campaign, ?float $peerAvgRoas): ?string
    {
        if ($peerAvgRoas === null || $campaign['real_roas'] === null) {
            return null;
        }
        return $campaign['real_roas'] >= $peerAvgRoas ? 'winner' : 'loser';
    }

    /**
     * Tag campaign by comparing current-period real_roas to previous-period real_roas.
     * Winner = improved (higher ROAS). Null when previous period has no data.
     */
    private function wlTagByPeriod(array $campaign, array $prevAttrMap, array $prevSpendMap): ?string
    {
        $prevSpend = $prevSpendMap[$campaign['id']] ?? 0.0;
        $prevAttr  = $prevAttrMap[$campaign['id']]  ?? null;

        if ($prevAttr === null || $prevSpend <= 0) {
            return null;
        }

        $prevRoas = (float) $prevAttr['revenue'] / $prevSpend;

        if ($campaign['real_roas'] === null) {
            return null;
        }

        return $campaign['real_roas'] > $prevRoas ? 'winner' : 'loser';
    }

    /**
     * Build a map of campaign ID → spend for a given date range.
     * Used by the 'period' W/L classifier to compute previous-period real_roas.
     *
     * @param  int[]  $adAccountIds
     * @return array<int, float>  campaign_id => spend
     */
    private function buildCampaignSpendMap(array $adAccountIds, string $from, string $to): array
    {
        if (empty($adAccountIds)) {
            return [];
        }

        $workspaceId  = app(WorkspaceContext::class)->id();
        $placeholders = implode(',', array_fill(0, count($adAccountIds), '?'));

        $rows = DB::select("
            SELECT
                c.id                                                  AS campaign_id,
                COALESCE(SUM(ai.spend_in_reporting_currency), 0)      AS total_spend
            FROM ad_insights ai
            JOIN campaigns c ON c.id = ai.campaign_id
            WHERE ai.workspace_id = ?
              AND ai.ad_account_id IN ({$placeholders})
              AND ai.level = 'campaign'
              AND ai.hour IS NULL
              AND ai.date BETWEEN ? AND ?
            GROUP BY c.id
        ", array_merge([$workspaceId], $adAccountIds, [$from, $to]));

        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row->campaign_id] = (float) $row->total_spend;
        }

        return $map;
    }

    // ─── Platform breakdown ───────────────────────────────────────────────────

    /** @return array<string, array{spend:float|null,impressions:int,clicks:int,ctr:float|null}> */
    private function computePlatformBreakdown(int $workspaceId, string $from, string $to): array
    {
        $rows = DB::select("
            SELECT
                aa.platform,
                COALESCE(SUM(ai.spend_in_reporting_currency), 0) AS total_spend,
                COALESCE(SUM(ai.impressions), 0)                 AS total_impressions,
                COALESCE(SUM(ai.clicks), 0)                      AS total_clicks
            FROM ad_insights ai
            JOIN ad_accounts aa ON aa.id = ai.ad_account_id
            WHERE ai.workspace_id = ?
              AND ai.level = 'campaign'
              AND ai.hour IS NULL
              AND ai.date BETWEEN ? AND ?
            GROUP BY aa.platform
        ", [$workspaceId, $from, $to]);

        $breakdown = [];
        foreach ($rows as $row) {
            $spend       = (float) $row->total_spend;
            $impressions = (int)   $row->total_impressions;
            $clicks      = (int)   $row->total_clicks;
            $breakdown[$row->platform] = [
                'spend'       => $spend > 0 ? $spend : null,
                'impressions' => $impressions,
                'clicks'      => $clicks,
                'ctr'         => $impressions > 0 ? round(($clicks / $impressions) * 100, 2) : null,
            ];
        }

        return $breakdown;
    }

    // ─── Revenue context ──────────────────────────────────────────────────────

    /**
     * Total store revenue + unattributed revenue for the period.
     *
     * Only populated when has_store=true. Returns [null, null] otherwise so the
     * frontend can suppress the revenue context cards entirely.
     *
     * See: PLANNING.md "Cross-Channel Page Enhancements" → Campaigns page
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

        // Revenue from daily_snapshots (pre-aggregated); never aggregate raw orders at query time.
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

    // ─── Chart ───────────────────────────────────────────────────────────────

    /**
     * @param  int[]  $adAccountIds  Already filtered by platform and/or specific account
     * @return array<int, array{date:string,facebook:float,google:float}>
     */
    private function buildSpendChart(int $workspaceId, array $adAccountIds, string $from, string $to, string $granularity): array
    {
        if (empty($adAccountIds)) {
            return [];
        }

        $dateExpr     = match ($granularity) {
            'weekly' => "DATE_TRUNC('week', ai.date)::date::text",
            default  => 'ai.date::text',
        };
        $placeholders = implode(',', array_fill(0, count($adAccountIds), '?'));

        $rows = DB::select("
            SELECT
                {$dateExpr} AS date,
                aa.platform,
                COALESCE(SUM(ai.spend_in_reporting_currency), 0) AS spend
            FROM ad_insights ai
            JOIN ad_accounts aa ON aa.id = ai.ad_account_id
            WHERE ai.workspace_id = ?
              AND ai.ad_account_id IN ({$placeholders})
              AND ai.level = 'campaign'
              AND ai.hour IS NULL
              AND ai.date BETWEEN ? AND ?
            GROUP BY {$dateExpr}, aa.platform
            ORDER BY date
        ", array_merge([$workspaceId], $adAccountIds, [$from, $to]));

        $byDate = [];
        foreach ($rows as $row) {
            $byDate[$row->date][$row->platform] = (float) $row->spend;
        }

        return array_values(array_map(
            fn (string $date, array $platforms) => [
                'date'     => $date,
                'facebook' => $platforms['facebook'] ?? 0,
                'google'   => $platforms['google']   ?? 0,
            ],
            array_keys($byDate),
            $byDate,
        ));
    }
}
