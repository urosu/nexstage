<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\AdAccount;
use App\Models\DailySnapshot;
use App\Models\Workspace;
use App\Services\NarrativeTemplateService;
use App\Services\RevenueAttributionService;
use App\Services\WorkspaceContext;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Unified Campaigns & Creatives destination — Phase 4.1.
 *
 * Triggered by: GET /campaigns
 * Reads from:   campaigns, adsets, ads, ad_accounts, ad_insights (all levels), orders
 * Writes to:    nothing
 *
 * Replaces the three separate Campaign / AdSet / Ad controllers with a single page
 * that uses a level toggle (campaign → adset → ad) inside the hierarchy table.
 *
 * Right panel always shows the creative grid (ad-level cards with Motion Score §F11).
 * Pacing tab reads from campaigns.daily_budget / lifetime_budget vs. actual spend.
 *
 * New columns vs Phase 1: CPA (§F9), First-order ROAS (§F7), Day-30 ROAS (§F8),
 * Motion Score + Verdict (§F11). All old columns preserved.
 *
 * @see PLANNING.md section 10 (W/L classifier), section 15 (Motion Score / §F11)
 * @see PROGRESS.md Phase 4.1
 */
class CampaignsController extends Controller
{
    public function __construct(
        private readonly RevenueAttributionService $attribution,
        private readonly NarrativeTemplateService  $narrative,
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

        $workspaceTargetRoas = $workspace->target_roas !== null
            ? (float) $workspace->target_roas
            : null;

        if ($adAccounts->isEmpty()) {
            return Inertia::render('Campaigns/Index', [
                'has_ad_accounts'       => false,
                'ad_accounts'           => [],
                'level'                 => $params['level'],
                'metrics'               => null,
                'compare_metrics'       => null,
                'rows'                  => [],
                'rows_total_count'      => 0,
                'creative_cards'        => [],
                'pacing_data'           => [],
                'platform_breakdown'    => [],
                'chart_data'            => [],
                'campaign_name'         => null,
                'adset_name'            => null,
                'total_revenue'         => $totalRevenue,
                'unattributed_revenue'  => $unattributedRevenue,
                'not_tracked_pct'       => null,
                'workspace_target_roas' => $workspaceTargetRoas,
                'wl_has_target'         => $workspaceTargetRoas !== null,
                'active_classifier'     => $workspaceTargetRoas !== null ? 'target' : 'peer',
                'wl_peer_avg_roas'      => null,
                'narrative'             => null,
                'tag_categories'        => $this->buildTagCategoryData(),
                'hit_rate_data'         => [],
                ...$params,
            ]);
        }

        // Resolve filtered ad accounts
        $filteredAccounts = $params['platform'] === 'all'
            ? $adAccounts
            : $adAccounts->where('platform', $params['platform']);

        if (! empty($params['ad_account_ids'])) {
            $filteredAccounts = $filteredAccounts->whereIn('id', $params['ad_account_ids']);
        }

        $adAccountIds = $filteredAccounts->pluck('id')->all();

        // Breadcrumb context names for adset/ad levels
        $campaignName = $this->resolveName($workspaceId, 'campaigns', $params['campaign_id']);
        $adsetName    = $this->resolveName($workspaceId, 'adsets',    $params['adset_id']);

        // Compute rows for the hierarchy table based on the active level
        $rows = match ($params['level']) {
            'adset' => $this->computeAdsetRows(
                $workspaceId, $adAccounts, $params['from'], $params['to'],
                $params['platform'], $params['status'], $params['ad_account_ids'],
                $params['campaign_id'],
            ),
            'ad' => $this->computeAdRows(
                $workspaceId, $adAccounts, $params['from'], $params['to'],
                $params['platform'], $params['status'], $params['ad_account_ids'],
                $params['campaign_id'], $params['adset_id'],
            ),
            default => $this->computeCampaignRows(
                $workspaceId, $adAccounts, $params['from'], $params['to'],
                $params['platform'], $params['status'], $params['ad_account_ids'],
                $workspaceTargetRoas,
            ),
        };

        // Sort — NULLs always last
        $sortKey   = $params['sort'];
        $direction = $params['direction'];
        usort($rows, function (array $a, array $b) use ($sortKey, $direction): int {
            $aVal = $a[$sortKey] ?? null;
            $bVal = $b[$sortKey] ?? null;
            if ($aVal === null && $bVal === null) return 0;
            if ($aVal === null) return 1;
            if ($bVal === null) return -1;
            $cmp = $aVal <=> $bVal;
            return $direction === 'asc' ? $cmp : -$cmp;
        });

        $metrics = $this->metricsFromRows($rows, $workspaceId, $params['from'], $params['to']);

        $compareMetrics = ($params['compare_from'] && $params['compare_to'])
            ? $this->computeMetrics($workspaceId, $adAccountIds, $params['compare_from'], $params['compare_to'], $params['platform'])
            : null;

        // W/L tagging and optional filter
        $wl = $this->applyWinnersLosers($rows, $params, $workspace, $adAccountIds);

        $platformBreakdown = $this->computePlatformBreakdown($workspaceId, $params['from'], $params['to']);

        $chartData = $this->buildSpendChart(
            $workspaceId, $adAccountIds, $params['from'], $params['to'], $params['granularity'],
        );

        $compareChartData = ($params['compare_from'] && $params['compare_to'])
            ? $this->buildSpendChart($workspaceId, $adAccountIds, $params['compare_from'], $params['compare_to'], $params['granularity'])
            : null;

        $creativeCards = $this->buildCreativeGrid(
            $workspaceId, $adAccountIds, $params['from'], $params['to'],
            $params['campaign_id'], $params['adset_id'],
            $workspaceTargetRoas,
        );

        $tagCategories = $this->buildTagCategoryData();
        $hitRateData   = $this->buildHitRateData($creativeCards, $tagCategories);

        $pacingData = $params['tab'] === 'pacing'
            ? $this->buildPacingData($workspaceId, $adAccountIds, $params['from'], $params['to'])
            : [];

        $notTrackedPct = ($totalRevenue !== null && $totalRevenue > 0 && $unattributedRevenue !== null)
            ? round(($unattributedRevenue / $totalRevenue) * 100, 1)
            : null;

        // Narrative from the campaign-level rows (irrespective of active level)
        $aboveTarget = 0;
        $belowTarget = 0;
        $topSpender  = null;
        foreach ($wl['rows'] as $r) {
            $effectiveTarget = ($r['target_roas'] ?? null) ?? $workspaceTargetRoas;
            if ($effectiveTarget !== null && ($r['real_roas'] ?? null) !== null) {
                $r['real_roas'] >= $effectiveTarget ? $aboveTarget++ : $belowTarget++;
            }
            if ($topSpender === null || ($r['spend'] ?? 0) > ($topSpender['spend'] ?? 0)) {
                $topSpender = $r;
            }
        }

        $pageNarrative = $this->narrative->forCampaigns(
            $aboveTarget,
            $belowTarget,
            $topSpender ? ($topSpender['name'] ?: null) : null,
            $topSpender ? ($topSpender['real_roas'] ?? null) : null,
            $topSpender ? ($topSpender['status'] ?? null) : null,
        );

        return Inertia::render('Campaigns/Index', [
            'has_ad_accounts'       => true,
            'ad_accounts'           => $adAccountList,
            'level'                 => $params['level'],
            'metrics'               => $metrics,
            'compare_metrics'       => $compareMetrics,
            'rows'                  => $wl['rows'],
            'rows_total_count'      => $wl['total_count'],
            'creative_cards'        => $creativeCards,
            'pacing_data'           => $pacingData,
            'platform_breakdown'    => $platformBreakdown,
            'chart_data'            => $chartData,
            'compare_chart_data'    => $compareChartData,
            'campaign_name'         => $campaignName,
            'adset_name'            => $adsetName,
            'total_revenue'         => $totalRevenue,
            'unattributed_revenue'  => $unattributedRevenue,
            'not_tracked_pct'       => $notTrackedPct,
            'workspace_target_roas' => $workspaceTargetRoas,
            'wl_has_target'         => $workspaceTargetRoas !== null,
            'active_classifier'     => $wl['active_classifier'],
            'wl_peer_avg_roas'      => $wl['peer_avg_roas'],
            'narrative'             => $pageNarrative,
            'tag_categories'        => $tagCategories,
            'hit_rate_data'         => $hitRateData,
            ...$params,
        ]);
    }

    // ─── Parameter validation ─────────────────────────────────────────────────

    /** @return array<string, mixed> */
    private function validateParams(Request $request): array
    {
        $v = $request->validate([
            'from'           => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'to'             => ['sometimes', 'nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'compare_from'   => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'compare_to'     => ['sometimes', 'nullable', 'date_format:Y-m-d', 'after_or_equal:compare_from'],
            'granularity'    => ['sometimes', 'nullable', 'in:hourly,daily,weekly'],
            'platform'       => ['sometimes', 'nullable', 'in:all,facebook,google'],
            'status'         => ['sometimes', 'nullable', 'in:all,active,paused'],
            'level'          => ['sometimes', 'nullable', 'in:campaign,adset,ad'],
            'tab'            => ['sometimes', 'nullable', 'in:performance,pacing'],
            'view'           => ['sometimes', 'nullable', 'in:table,quadrant,format_analysis'],
            'sort'           => ['sometimes', 'nullable', 'in:real_roas,real_cpo,spend,attributed_revenue,attributed_orders,spend_velocity,platform_roas,impressions,clicks,ctr,cpc,cpa,first_order_roas,day30_roas'],
            'direction'      => ['sometimes', 'nullable', 'in:asc,desc'],
            'ad_account_ids' => ['sometimes', 'nullable', 'string'],
            'filter'         => ['sometimes', 'nullable', 'in:all,winners,losers'],
            'classifier'     => ['sometimes', 'nullable', 'in:target,peer,period'],
            'campaign_id'    => ['sometimes', 'nullable', 'integer', 'min:1'],
            'adset_id'       => ['sometimes', 'nullable', 'integer', 'min:1'],
            'hide_inactive'  => ['sometimes', 'nullable', 'boolean'],
        ]);

        $adAccountIds = array_values(array_filter(
            array_map('intval', explode(',', $v['ad_account_ids'] ?? '')),
            fn ($id) => $id > 0
        ));

        return [
            'from'           => $v['from']          ?? now()->subDays(29)->toDateString(),
            'to'             => $v['to']             ?? now()->toDateString(),
            'compare_from'   => $v['compare_from']   ?? null,
            'compare_to'     => $v['compare_to']     ?? null,
            'granularity'    => $v['granularity']    ?? 'daily',
            'platform'       => $v['platform']       ?? 'all',
            'status'         => $v['status']         ?? 'all',
            'level'          => $v['level']          ?? 'campaign',
            'tab'            => $v['tab']            ?? 'performance',
            'view'           => $v['view']           ?? 'table',
            'sort'           => $v['sort']           ?? 'spend',
            'direction'      => $v['direction']      ?? 'desc',
            'ad_account_ids' => $adAccountIds,
            'filter'         => $v['filter']         ?? 'all',
            'classifier'     => $v['classifier']     ?? null,
            'campaign_id'    => isset($v['campaign_id']) ? (int) $v['campaign_id'] : null,
            'adset_id'       => isset($v['adset_id'])    ? (int) $v['adset_id']    : null,
            'hide_inactive'  => $v['hide_inactive']  ?? true,
        ];
    }

    // ─── Campaign rows ────────────────────────────────────────────────────────

    /**
     * Campaign-level rows with all Phase 4.1 columns.
     *
     * New vs Phase 1: platform_conversions (for CPA §F9), video metric aggregates
     * (for Motion Score §F11), first_order_roas (§F7), day30_roas (§F8).
     *
     * @param  \Illuminate\Support\Collection  $adAccounts
     * @param  int[]                           $adAccountIds
     * @return array<int, array<string, mixed>>
     */
    private function computeCampaignRows(
        int $workspaceId,
        $adAccounts,
        string $from,
        string $to,
        string $platform,
        string $status,
        array $adAccountIds = [],
        ?float $workspaceTargetRoas = null,
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

        $statusFilter = match ($status) {
            'active' => "AND LOWER(c.status) IN ('active','enabled','delivering')",
            'paused' => "AND LOWER(c.status) IN ('paused','inactive','disabled')",
            default  => '',
        };

        // Video metric aggregation via correlated JSONB subqueries.
        // Sums the 'value' field across all array elements per daily row, then across days.
        // Returns 0 for rows where the field is absent (static image ads, pre-sync rows).
        $v = fn (string $field) => "COALESCE(SUM(
            CASE WHEN ai.raw_insights IS NOT NULL AND (ai.raw_insights->'{$field}') IS NOT NULL
            THEN (
                SELECT COALESCE(SUM((elem->>'value')::numeric), 0)
                FROM jsonb_array_elements(ai.raw_insights->'{$field}') AS elem
            )
            ELSE 0 END
        ), 0)";

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
                COALESCE(SUM(ai.spend_in_reporting_currency), 0)  AS total_spend,
                COALESCE(SUM(ai.impressions), 0)                  AS total_impressions,
                COALESCE(SUM(ai.clicks), 0)                       AS total_clicks,
                COALESCE(SUM(ai.platform_conversions), 0)         AS total_platform_conversions,
                AVG(ai.platform_roas)                             AS avg_platform_roas,
                {$v('video_continuous_2_sec_watched_actions')}              AS video_3s_plays,
                {$v('video_15_sec_watched_actions')}              AS video_15s_plays,
                {$v('video_p25_watched_actions')}                 AS video_p25_plays,
                {$v('video_p50_watched_actions')}                 AS video_p50_plays,
                {$v('video_p75_watched_actions')}                 AS video_p75_plays,
                {$v('video_p100_watched_actions')}                AS video_p100_plays,
                {$v('outbound_clicks')}                           AS outbound_clicks_count
            FROM ad_insights ai
            JOIN campaigns c    ON c.id  = ai.campaign_id
            JOIN ad_accounts aa ON aa.id = ai.ad_account_id
            WHERE ai.workspace_id = ?
              AND ai.ad_account_id IN ({$placeholders})
              AND ai.level = 'campaign'
              AND ai.hour IS NULL
              AND ai.date BETWEEN ? AND ?
              {$statusFilter}
            GROUP BY c.id, c.name, aa.platform, c.status,
                     c.daily_budget, c.lifetime_budget, c.budget_type, c.target_roas
        ", array_merge([$workspaceId], $adAccountIds, [$from, $to]));

        $daysInPeriod = Carbon::parse($from)->diffInDays(Carbon::parse($to)) + 1;
        $daysElapsed  = min(Carbon::parse($from)->diffInDays(Carbon::today()) + 1, $daysInPeriod);

        $utmPlatform     = $platform === 'all' ? '' : $platform;
        $attrMap         = $this->buildUtmAttributionMap($workspaceId, $from, $to, $utmPlatform);
        $firstOrderMap   = $this->buildFirstOrderRoasMap($workspaceId, $from, $to, $utmPlatform);
        $day30Map        = $this->buildDay30RoasMap($workspaceId, $from, $to, $utmPlatform);
        $day30IsPending  = Carbon::parse($to)->addDays(30)->isAfter(Carbon::today());
        $day30LocksInDays = $day30IsPending
            ? (int) Carbon::today()->diffInDays(Carbon::parse($to)->addDays(30), false)
            : null;

        return array_map(function (object $row) use (
            $attrMap, $firstOrderMap, $day30Map, $day30IsPending, $day30LocksInDays,
            $daysInPeriod, $daysElapsed, $workspaceTargetRoas,
        ): array {
            $spend       = (float) $row->total_spend;
            $impressions = (int)   $row->total_impressions;
            $clicks      = (int)   $row->total_clicks;
            $platformCvs = (float) $row->total_platform_conversions;

            $attr              = $attrMap[(int) $row->id] ?? null;
            $attributedRevenue = $attr !== null ? (float) $attr['revenue'] : null;
            $attributedOrders  = $attr !== null ? (int)   $attr['orders']  : 0;

            // First-order ROAS (§F7): new customers attributed to this campaign / spend
            $firstOrderData  = $firstOrderMap[(int) $row->id] ?? null;
            $firstOrderRoas  = ($firstOrderData !== null && $spend > 0)
                ? round((float) $firstOrderData['revenue'] / $spend, 2)
                : null;

            // Day-30 ROAS (§F8): 30-day cohort revenue / spend
            $day30Roas = null;
            if (! $day30IsPending) {
                $day30Data = $day30Map[(int) $row->id] ?? null;
                if ($day30Data !== null && $spend > 0) {
                    $day30Roas = round((float) $day30Data['revenue'] / $spend, 2);
                }
            }

            // Spend velocity (§F10)
            $spendVelocity = null;
            if ($daysInPeriod > 0 && $daysElapsed > 0) {
                $budgetForPeriod = match ($row->budget_type) {
                    'daily'    => $row->daily_budget !== null
                        ? (float) $row->daily_budget * $daysInPeriod : null,
                    'lifetime' => $row->lifetime_budget !== null
                        ? (float) $row->lifetime_budget : null,
                    default    => null,
                };
                if ($budgetForPeriod !== null && $budgetForPeriod > 0) {
                    $expectedPace  = $daysElapsed / $daysInPeriod;
                    $actualPace    = $spend / $budgetForPeriod;
                    $spendVelocity = round($actualPace / $expectedPace, 3);
                }
            }

            // Motion Score (§F11) from aggregated video metrics
            $campaignTargetRoas = $row->target_roas !== null
                ? (float) $row->target_roas
                : $workspaceTargetRoas;
            $realRoas = ($spend > 0 && $attributedRevenue !== null && $attributedRevenue > 0)
                ? round($attributedRevenue / $spend, 2)
                : null;
            $motionScore = $this->computeMotionScore(
                video3s:          (float) $row->video_3s_plays,
                video15s:         (float) $row->video_15s_plays,
                outboundClicks:   (float) $row->outbound_clicks_count,
                impressions:      $impressions,
                clicks:           $clicks,
                attributedOrders: $attributedOrders,
                realRoas:         $realRoas,
                targetRoas:       $campaignTargetRoas,
            );

            return [
                'id'                  => (int)    $row->id,
                'name'                => (string) ($row->name ?? ''),
                'platform'            => (string) $row->platform,
                'status'              => $row->status,
                'spend'               => $spend,
                'impressions'         => $impressions,
                'clicks'              => $clicks,
                'ctr'                 => $impressions > 0 ? round(($clicks / $impressions) * 100, 2) : null,
                'cpc'                 => $clicks > 0 ? round($spend / $clicks, 4) : null,
                'cpa'                 => ($platformCvs > 0 && $spend > 0) ? round($spend / $platformCvs, 2) : null,
                'platform_roas'       => $row->avg_platform_roas !== null
                    ? round((float) $row->avg_platform_roas, 2) : null,
                'real_roas'           => $realRoas,
                'real_cpo'            => ($spend > 0 && $attributedOrders > 0)
                    ? round($spend / $attributedOrders, 2) : null,
                'attributed_revenue'  => $attributedRevenue,
                'attributed_orders'   => $attributedOrders,
                'first_order_roas'    => $firstOrderRoas,
                'day30_roas'          => $day30Roas,
                'day30_pending'       => $day30IsPending,
                'day30_locks_in_days' => $day30LocksInDays,
                'spend_velocity'      => $spendVelocity,
                'motion_score'        => $motionScore,
                'verdict'             => $motionScore !== null ? $motionScore['verdict'] : null,
                'target_roas'         => $row->target_roas !== null ? round((float) $row->target_roas, 2) : null,
            ];
        }, $rows);
    }

    // ─── AdSet rows ───────────────────────────────────────────────────────────

    /**
     * Adset-level rows. Mirror of legacy AdSetsController, integrated here so the
     * level toggle stays on a single page without a full-page reload.
     *
     * @param  \Illuminate\Support\Collection  $adAccounts
     * @param  int[]                           $adAccountIds
     * @return array<int, array<string, mixed>>
     */
    private function computeAdsetRows(
        int $workspaceId,
        $adAccounts,
        string $from,
        string $to,
        string $platform,
        string $status,
        array $adAccountIds = [],
        ?int $campaignId = null,
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

        $statusFilter   = $this->statusFilter($status, 'a');
        $campaignFilter = $campaignId !== null ? 'AND a.campaign_id = ?' : '';
        $campaignArgs   = $campaignId !== null ? [$campaignId] : [];

        $rows = DB::select("
            SELECT
                a.id,
                a.name,
                a.status,
                c.id   AS campaign_id,
                c.name AS campaign_name,
                aa.platform,
                COALESCE(SUM(ai.spend_in_reporting_currency), 0) AS total_spend,
                COALESCE(SUM(ai.impressions), 0)                  AS total_impressions,
                COALESCE(SUM(ai.clicks), 0)                       AS total_clicks,
                COALESCE(SUM(ai.platform_conversions), 0)         AS total_platform_conversions,
                AVG(ai.platform_roas)                             AS avg_platform_roas
            FROM ad_insights ai
            JOIN adsets  a  ON a.id  = ai.adset_id
            JOIN campaigns c ON c.id = a.campaign_id
            JOIN ad_accounts aa ON aa.id = ai.ad_account_id
            WHERE ai.workspace_id = ?
              AND ai.ad_account_id IN ({$placeholders})
              AND ai.level = 'adset'
              AND ai.hour IS NULL
              AND ai.date BETWEEN ? AND ?
              {$statusFilter}
              {$campaignFilter}
            GROUP BY a.id, a.name, a.status, c.id, c.name, aa.platform
        ", array_merge([$workspaceId], $adAccountIds, [$from, $to], $campaignArgs));

        $utmPlatform = $platform === 'all' ? '' : $platform;
        $attrMap     = $this->buildUtmAttributionMap($workspaceId, $from, $to, $utmPlatform);

        return array_map(function (object $row) use ($attrMap): array {
            $spend       = (float) $row->total_spend;
            $impressions = (int)   $row->total_impressions;
            $clicks      = (int)   $row->total_clicks;
            $platformCvs = (float) $row->total_platform_conversions;

            // Adsets are attribution-matched via their parent campaign
            $attr              = $attrMap[(int) $row->campaign_id] ?? null;
            $attributedRevenue = $attr !== null ? (float) $attr['revenue'] : null;
            $attributedOrders  = $attr !== null ? (int)   $attr['orders']  : 0;

            return [
                'id'                 => (int)    $row->id,
                'name'               => (string) ($row->name ?? ''),
                'platform'           => (string) $row->platform,
                'status'             => $row->status,
                'campaign_id'        => (int) $row->campaign_id,
                'campaign_name'      => (string) ($row->campaign_name ?? ''),
                'spend'              => $spend,
                'impressions'        => $impressions,
                'clicks'             => $clicks,
                'ctr'                => $impressions > 0 ? round(($clicks / $impressions) * 100, 2) : null,
                'cpc'                => $clicks > 0 ? round($spend / $clicks, 4) : null,
                'cpa'                => ($platformCvs > 0 && $spend > 0) ? round($spend / $platformCvs, 2) : null,
                'platform_roas'      => $row->avg_platform_roas !== null ? round((float) $row->avg_platform_roas, 2) : null,
                'real_roas'          => ($spend > 0 && $attributedRevenue !== null && $attributedRevenue > 0)
                    ? round($attributedRevenue / $spend, 2) : null,
                'real_cpo'           => ($spend > 0 && $attributedOrders > 0)
                    ? round($spend / $attributedOrders, 2) : null,
                'attributed_revenue' => $attributedRevenue,
                'attributed_orders'  => $attributedOrders,
                'target_roas'        => null, // no per-adset target
            ];
        }, $rows);
    }

    // ─── Ad rows ─────────────────────────────────────────────────────────────

    /**
     * Ad-level rows. Mirror of legacy AdsController.
     *
     * @param  \Illuminate\Support\Collection  $adAccounts
     * @param  int[]                           $adAccountIds
     * @return array<int, array<string, mixed>>
     */
    private function computeAdRows(
        int $workspaceId,
        $adAccounts,
        string $from,
        string $to,
        string $platform,
        string $status,
        array $adAccountIds = [],
        ?int $campaignId = null,
        ?int $adsetId = null,
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

        $adAccountIds   = $filteredAccounts->pluck('id')->all();
        $placeholders   = implode(',', array_fill(0, count($adAccountIds), '?'));
        $statusFilter   = $this->statusFilter($status, 'ads');
        $campaignFilter = $campaignId !== null ? 'AND c.id = ?' : '';
        $adsetFilter    = $adsetId    !== null ? 'AND a.id = ?' : '';
        $filterArgs     = array_filter([$campaignId, $adsetId], fn ($v) => $v !== null);

        $v = fn (string $field) => "COALESCE(SUM(
            CASE WHEN ai.raw_insights IS NOT NULL AND (ai.raw_insights->'{$field}') IS NOT NULL
            THEN (
                SELECT COALESCE(SUM((elem->>'value')::numeric), 0)
                FROM jsonb_array_elements(ai.raw_insights->'{$field}') AS elem
            )
            ELSE 0 END
        ), 0)";

        $rows = DB::select("
            SELECT
                ads.id,
                ads.name,
                ads.status,
                ads.effective_status,
                ads.creative_data,
                a.id   AS adset_id,
                a.name AS adset_name,
                c.id   AS campaign_id,
                c.name AS campaign_name,
                c.target_roas,
                aa.platform,
                COALESCE(SUM(ai.spend_in_reporting_currency), 0)  AS total_spend,
                COALESCE(SUM(ai.impressions), 0)                  AS total_impressions,
                COALESCE(SUM(ai.clicks), 0)                       AS total_clicks,
                COALESCE(SUM(ai.platform_conversions), 0)         AS total_platform_conversions,
                AVG(ai.platform_roas)                             AS avg_platform_roas,
                {$v('video_continuous_2_sec_watched_actions')}              AS video_3s_plays,
                {$v('video_15_sec_watched_actions')}              AS video_15s_plays,
                {$v('outbound_clicks')}                           AS outbound_clicks_count
            FROM ad_insights ai
            JOIN ads         ON ads.id = ai.ad_id
            JOIN adsets  a   ON a.id   = ads.adset_id
            JOIN campaigns c ON c.id   = a.campaign_id
            JOIN ad_accounts aa ON aa.id = ai.ad_account_id
            WHERE ai.workspace_id = ?
              AND ai.ad_account_id IN ({$placeholders})
              AND ai.level = 'ad'
              AND ai.hour IS NULL
              AND ai.date BETWEEN ? AND ?
              {$statusFilter}
              {$campaignFilter}
              {$adsetFilter}
            GROUP BY ads.id, ads.name, ads.status, ads.effective_status, ads.creative_data,
                     a.id, a.name, c.id, c.name, c.target_roas, aa.platform
        ", array_merge([$workspaceId], $adAccountIds, [$from, $to], $filterArgs));

        $utmPlatform = $platform === 'all' ? '' : $platform;
        $attrMap     = $this->buildUtmAttributionMap($workspaceId, $from, $to, $utmPlatform);

        return array_map(function (object $row) use ($attrMap): array {
            $spend       = (float) $row->total_spend;
            $impressions = (int)   $row->total_impressions;
            $clicks      = (int)   $row->total_clicks;
            $platformCvs = (float) $row->total_platform_conversions;

            $attr              = $attrMap[(int) $row->campaign_id] ?? null;
            $attributedRevenue = $attr !== null ? (float) $attr['revenue'] : null;
            $attributedOrders  = $attr !== null ? (int)   $attr['orders']  : 0;

            $creative = is_string($row->creative_data)
                ? json_decode($row->creative_data, true)
                : $row->creative_data;

            return [
                'id'                 => (int)    $row->id,
                'name'               => (string) ($row->name ?? ''),
                'platform'           => (string) $row->platform,
                'status'             => $row->status,
                'effective_status'   => $row->effective_status,
                'campaign_id'        => (int)    $row->campaign_id,
                'campaign_name'      => (string) ($row->campaign_name ?? ''),
                'adset_id'           => (int)    $row->adset_id,
                'adset_name'         => (string) ($row->adset_name ?? ''),
                'thumbnail_url'      => $creative['thumbnail_url'] ?? null,
                'headline'           => $creative['title'] ?? null,
                'spend'              => $spend,
                'impressions'        => $impressions,
                'clicks'             => $clicks,
                'ctr'                => $impressions > 0 ? round(($clicks / $impressions) * 100, 2) : null,
                'cpc'                => $clicks > 0 ? round($spend / $clicks, 4) : null,
                'cpa'                => ($platformCvs > 0 && $spend > 0) ? round($spend / $platformCvs, 2) : null,
                'platform_roas'      => $row->avg_platform_roas !== null ? round((float) $row->avg_platform_roas, 2) : null,
                'real_roas'          => ($spend > 0 && $attributedRevenue !== null && $attributedRevenue > 0)
                    ? round($attributedRevenue / $spend, 2) : null,
                'real_cpo'           => ($spend > 0 && $attributedOrders > 0)
                    ? round($spend / $attributedOrders, 2) : null,
                'attributed_revenue' => $attributedRevenue,
                'attributed_orders'  => $attributedOrders,
                'target_roas'        => $row->target_roas !== null ? round((float) $row->target_roas, 2) : null,
            ];
        }, $rows);
    }

    // ─── Creative grid ────────────────────────────────────────────────────────

    /**
     * Top-50 ad-level creative cards for the right panel.
     *
     * Each card carries: thumbnail_url (from ads.creative_data), per-ad video metrics
     * (Thumbstop %, Hold Rate, Outbound CTR), and campaign-level attributed CVR / Real ROAS
     * as a proxy (UTM attribution cannot resolve to individual ad IDs — documented approximation).
     *
     * Motion Score is computed per-ad from individual ad-level raw_insights.
     * Verdict: Scale / Iterate / Watch / Kill per §F11.
     *
     * @param  int[]  $adAccountIds
     * @return array<int, array<string, mixed>>
     */
    private function buildCreativeGrid(
        int $workspaceId,
        array $adAccountIds,
        string $from,
        string $to,
        ?int $campaignId,
        ?int $adsetId,
        ?float $workspaceTargetRoas,
    ): array {
        if (empty($adAccountIds)) {
            return [];
        }

        $placeholders   = implode(',', array_fill(0, count($adAccountIds), '?'));
        $campaignFilter = $campaignId !== null ? 'AND c.id = ?' : '';
        $adsetFilter    = $adsetId    !== null ? 'AND a.id = ?' : '';
        $filterArgs     = array_filter([$campaignId, $adsetId], fn ($v) => $v !== null);

        $v = fn (string $field) => "COALESCE(SUM(
            CASE WHEN ai.raw_insights IS NOT NULL AND (ai.raw_insights->'{$field}') IS NOT NULL
            THEN (
                SELECT COALESCE(SUM((elem->>'value')::numeric), 0)
                FROM jsonb_array_elements(ai.raw_insights->'{$field}') AS elem
            )
            ELSE 0 END
        ), 0)";

        $rows = DB::select("
            SELECT
                ads.id                                            AS ad_id,
                ads.name                                          AS ad_name,
                ads.status,
                ads.effective_status,
                ads.creative_data,
                a.id                                              AS adset_id,
                c.id                                              AS campaign_id,
                c.name                                            AS campaign_name,
                c.target_roas,
                aa.platform,
                COALESCE(SUM(ai.spend_in_reporting_currency), 0)  AS ad_spend,
                COALESCE(SUM(ai.impressions), 0)                  AS ad_impressions,
                COALESCE(SUM(ai.clicks), 0)                       AS ad_clicks,
                {$v('video_continuous_2_sec_watched_actions')}              AS video_3s_plays,
                {$v('video_15_sec_watched_actions')}              AS video_15s_plays,
                {$v('video_p25_watched_actions')}                 AS video_p25_plays,
                {$v('video_p50_watched_actions')}                 AS video_p50_plays,
                {$v('video_p75_watched_actions')}                 AS video_p75_plays,
                {$v('video_p100_watched_actions')}                AS video_p100_plays,
                {$v('outbound_clicks')}                           AS outbound_clicks_count
            FROM ad_insights ai
            JOIN ads         ON ads.id = ai.ad_id
            JOIN adsets  a   ON a.id   = ads.adset_id
            JOIN campaigns c ON c.id   = a.campaign_id
            JOIN ad_accounts aa ON aa.id = ai.ad_account_id
            LEFT JOIN LATERAL (
                SELECT jsonb_object_agg(ctc.name, ct.name) AS tags
                FROM ad_creative_tags act
                JOIN creative_tags ct ON ct.id = act.creative_tag_id
                JOIN creative_tag_categories ctc ON ctc.id = ct.category_id
                WHERE act.ad_id = ads.id
            ) tag_agg ON true
            WHERE ai.workspace_id = ?
              AND ai.ad_account_id IN ({$placeholders})
              AND ai.level = 'ad'
              AND ai.hour IS NULL
              AND ai.date BETWEEN ? AND ?
              {$campaignFilter}
              {$adsetFilter}
            GROUP BY ads.id, ads.name, ads.status, ads.effective_status, ads.creative_data,
                     a.id, c.id, c.name, c.target_roas, aa.platform, tag_agg.tags
            ORDER BY ad_spend DESC
            LIMIT 60
        ", array_merge([$workspaceId], $adAccountIds, [$from, $to], $filterArgs));

        // Campaign-level attribution map — Real ROAS / CVR are campaign-wide proxies per §F11 caveat
        $utmAttrMap = $this->buildUtmAttributionMap($workspaceId, $from, $to, '');

        return array_map(function (object $row) use ($utmAttrMap, $workspaceTargetRoas): array {
            $spend       = (float) $row->ad_spend;
            $impressions = (int)   $row->ad_impressions;
            $clicks      = (int)   $row->ad_clicks;
            $video3s     = (float) $row->video_3s_plays;
            $video15s    = (float) $row->video_15s_plays;
            $outbound    = (float) $row->outbound_clicks_count;

            $creative = is_string($row->creative_data)
                ? json_decode($row->creative_data, true)
                : $row->creative_data;

            // Campaign-level attribution as proxy
            $attr              = $utmAttrMap[(int) $row->campaign_id] ?? null;
            $attributedRevenue = $attr !== null ? (float) $attr['revenue'] : null;
            $attributedOrders  = $attr !== null ? (int)   $attr['orders']  : 0;

            $realRoas = ($spend > 0 && $attributedRevenue !== null && $attributedRevenue > 0)
                ? round($attributedRevenue / $spend, 2) : null;

            $targetRoas = $row->target_roas !== null
                ? (float) $row->target_roas
                : $workspaceTargetRoas;

            $motionScore = $this->computeMotionScore(
                video3s:          $video3s,
                video15s:         $video15s,
                outboundClicks:   $outbound,
                impressions:      $impressions,
                clicks:           $clicks,
                attributedOrders: $attributedOrders,
                realRoas:         $realRoas,
                targetRoas:       $targetRoas,
            );

            // Video retention curve percentiles (relative to 3s_plays as 100% base)
            $retention = null;
            if ($video3s > 0) {
                $retention = [
                    'p25'  => round((float) $row->video_p25_plays  / $video3s * 100, 1),
                    'p50'  => round((float) $row->video_p50_plays  / $video3s * 100, 1),
                    'p75'  => round((float) $row->video_p75_plays  / $video3s * 100, 1),
                    'p100' => round((float) $row->video_p100_plays / $video3s * 100, 1),
                ];
            }

            return [
                'ad_id'            => (int)    $row->ad_id,
                'ad_name'          => (string) ($row->ad_name ?? ''),
                'campaign_id'      => (int)    $row->campaign_id,
                'campaign_name'    => (string) ($row->campaign_name ?? ''),
                'platform'         => (string) $row->platform,
                'status'           => $row->status,
                'effective_status' => $row->effective_status,
                'thumbnail_url'    => $creative['thumbnail_url'] ?? null,
                'headline'         => $creative['title'] ?? null,
                'spend'            => $spend,
                'impressions'      => $impressions,
                'clicks'           => $clicks,
                'real_roas'        => $realRoas,
                'attributed_orders' => $attributedOrders,
                // Video-derived metrics for the card display
                'thumbstop_pct'    => ($impressions > 0 && $video3s > 0) ? round($video3s / $impressions * 100, 1) : null,
                'hold_rate_pct'    => ($video3s > 0 && $video15s > 0) ? round($video15s / $video3s * 100, 1) : null,
                'outbound_ctr'     => ($impressions > 0 && $outbound > 0) ? round($outbound / $impressions * 100, 2) : null,
                'thumbstop_ctr'    => ($video3s > 0 && $outbound > 0) ? round($outbound / $video3s * 100, 2) : null,
                'cvr'              => ($clicks > 0 && $attributedOrders > 0) ? round($attributedOrders / $clicks * 100, 2) : null,
                'video_retention'  => $retention,
                'motion_score'     => $motionScore,
                'verdict'          => $motionScore !== null ? $motionScore['verdict'] : null,
                'tags'             => is_string($row->tags ?? null)
                    ? (json_decode($row->tags, true) ?? [])
                    : (is_array($row->tags ?? null) ? $row->tags : []),
            ];
        }, $rows);
    }

    // ─── Creative tag helpers ─────────────────────────────────────────────────

    /**
     * Load the seeded creative taxonomy for the Format Analysis view.
     *
     * @return list<array{name: string, label: string, tags: list<array{name: string, label: string}>}>
     */
    private function buildTagCategoryData(): array
    {
        return \App\Models\CreativeTagCategory::with('tags:id,category_id,name,label,sort_order')
            ->orderBy('sort_order')
            ->get(['id', 'name', 'label', 'sort_order'])
            ->map(fn ($cat) => [
                'name'  => $cat->name,
                'label' => $cat->label,
                'tags'  => $cat->tags
                    ->map(fn ($tag) => ['name' => $tag->name, 'label' => $tag->label])
                    ->values()
                    ->all(),
            ])
            ->all();
    }

    /**
     * Build Hit Rate × Spend Use Ratio data for the Format Analysis QuadrantChart.
     *
     * For each tag within each category: computes hit_rate (% of ads with verdict
     * 'scale' or 'iterate') and spend_use_ratio (tag spend ÷ total category spend).
     * Uses already-computed creative cards so no additional DB query is needed.
     *
     * Returns a map of category_slug → QuadrantPoint[].
     *
     * @param  list<array<string, mixed>>  $creativeCards
     * @param  list<array{name: string, label: string, tags: list<array{name: string, label: string}>}>  $tagCategories
     * @return array<string, list<array<string, mixed>>>
     */
    private function buildHitRateData(array $creativeCards, array $tagCategories): array
    {
        $result = [];

        foreach ($tagCategories as $category) {
            $slug = $category['name'];

            // Group cards by their tag slug within this category.
            $byTag = [];
            foreach ($creativeCards as $card) {
                $tagName = ($card['tags'] ?? [])[$slug] ?? null;
                if ($tagName === null) {
                    continue;
                }
                $byTag[$tagName][] = $card;
            }

            if (empty($byTag)) {
                $result[$slug] = [];
                continue;
            }

            $totalSpend = array_sum(array_map(fn ($c) => (float) ($c['spend'] ?? 0), array_merge(...array_values($byTag))));

            // Build label lookup from category tags.
            $tagLabels = [];
            foreach ($category['tags'] as $t) {
                $tagLabels[$t['name']] = $t['label'];
            }

            $points = [];
            foreach ($byTag as $tagName => $cards) {
                $totalAds   = count($cards);
                $winningAds = count(array_filter(
                    $cards,
                    fn ($c) => in_array($c['verdict'] ?? null, ['scale', 'iterate'], true),
                ));
                $tagSpend = array_sum(array_map(fn ($c) => (float) ($c['spend'] ?? 0), $cards));

                $points[] = [
                    'id'    => $tagName,
                    'label' => $tagLabels[$tagName] ?? $tagName,
                    'x'     => $totalAds > 0 ? round($winningAds / $totalAds, 3) : null,
                    'y'     => $totalSpend > 0 ? round($tagSpend / $totalSpend, 3) : null,
                    'size'  => $tagSpend,
                    'meta'  => [
                        'category'    => $slug,
                        'total_ads'   => $totalAds,
                        'winning_ads' => $winningAds,
                    ],
                ];
            }

            $result[$slug] = $points;
        }

        return $result;
    }

    // ─── Pacing tab ───────────────────────────────────────────────────────────

    /**
     * Budget burn rate per campaign for the Pacing tab.
     *
     * §F10: velocity = actual_mtd_spend ÷ (planned_budget × days_elapsed ÷ days_total)
     * >105% = over-pacing, <85% = under-pacing.
     *
     * @param  int[]  $adAccountIds
     * @return array<int, array<string, mixed>>
     */
    private function buildPacingData(int $workspaceId, array $adAccountIds, string $from, string $to): array
    {
        if (empty($adAccountIds)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($adAccountIds), '?'));

        // Daily spend breakdown per campaign for the chart
        $dailyRows = DB::select("
            SELECT
                c.id         AS campaign_id,
                c.name       AS campaign_name,
                c.daily_budget,
                c.lifetime_budget,
                c.budget_type,
                ai.date::text AS date,
                COALESCE(SUM(ai.spend_in_reporting_currency), 0) AS daily_spend
            FROM ad_insights ai
            JOIN campaigns c ON c.id = ai.campaign_id
            WHERE ai.workspace_id = ?
              AND ai.ad_account_id IN ({$placeholders})
              AND ai.level = 'campaign'
              AND ai.hour IS NULL
              AND ai.date BETWEEN ? AND ?
            GROUP BY c.id, c.name, c.daily_budget, c.lifetime_budget, c.budget_type, ai.date
            ORDER BY c.id, ai.date
        ", array_merge([$workspaceId], $adAccountIds, [$from, $to]));

        $daysInPeriod = Carbon::parse($from)->diffInDays(Carbon::parse($to)) + 1;
        $daysElapsed  = min(Carbon::parse($from)->diffInDays(Carbon::today()) + 1, $daysInPeriod);

        // Group by campaign
        $byCampaign = [];
        foreach ($dailyRows as $row) {
            $id = (int) $row->campaign_id;
            if (! isset($byCampaign[$id])) {
                $byCampaign[$id] = [
                    'campaign_id'     => $id,
                    'campaign_name'   => $row->campaign_name,
                    'daily_budget'    => $row->daily_budget !== null ? (float) $row->daily_budget : null,
                    'lifetime_budget' => $row->lifetime_budget !== null ? (float) $row->lifetime_budget : null,
                    'budget_type'     => $row->budget_type,
                    'daily_points'    => [],
                    'total_spend'     => 0.0,
                ];
            }
            $byCampaign[$id]['daily_points'][] = [
                'date'  => $row->date,
                'spend' => (float) $row->daily_spend,
            ];
            $byCampaign[$id]['total_spend'] += (float) $row->daily_spend;
        }

        return array_values(array_map(function (array $c) use ($daysInPeriod, $daysElapsed): array {
            $budgetForPeriod = match ($c['budget_type']) {
                'daily'    => $c['daily_budget'] !== null
                    ? $c['daily_budget'] * $daysInPeriod : null,
                'lifetime' => $c['lifetime_budget'],
                default    => null,
            };

            $velocity = null;
            $status   = 'no_budget';
            if ($budgetForPeriod !== null && $budgetForPeriod > 0 && $daysElapsed > 0) {
                $expectedPace = $daysElapsed / $daysInPeriod;
                $actualPace   = $c['total_spend'] / $budgetForPeriod;
                $velocity     = round($actualPace / $expectedPace, 3);
                $status = match (true) {
                    $velocity > 1.05 => 'over',
                    $velocity < 0.85 => 'under',
                    default          => 'on_pace',
                };
            }

            return [
                ...$c,
                'budget_for_period' => $budgetForPeriod,
                'velocity'          => $velocity,
                'pacing_status'     => $status,
            ];
        }, $byCampaign));
    }

    // ─── First-order ROAS map (§F7) ───────────────────────────────────────────

    /**
     * Map of campaign internal ID → {revenue, orders} for first-time customers only.
     * Used to compute First-order ROAS per §F7.
     *
     * @return array<int, array{revenue:float,orders:int}>
     */
    private function buildFirstOrderRoasMap(
        int $workspaceId,
        string $from,
        string $to,
        string $platform,
    ): array {
        $channelFilter = match ($platform) {
            'facebook' => "AND o.attribution_last_touch->>'channel_type' = 'paid_social'",
            'google'   => "AND o.attribution_last_touch->>'channel_type' = 'paid_search'",
            default    => "AND o.attribution_last_touch->>'channel_type' IN ('paid_social','paid_search')",
        };

        $rows = DB::select("
            SELECT
                c.id                               AS campaign_id,
                SUM(o.total_in_reporting_currency) AS first_order_revenue,
                COUNT(o.id)                        AS first_order_count
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
              AND o.is_first_for_customer = true
              AND o.status IN ('completed','processing')
              AND o.total_in_reporting_currency IS NOT NULL
              AND o.attribution_source IN ('pys','wc_native')
              AND o.occurred_at BETWEEN ? AND ?
              {$channelFilter}
            GROUP BY c.id
        ", [$workspaceId, $from . ' 00:00:00', $to . ' 23:59:59']);

        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row->campaign_id] = [
                'revenue' => (float) $row->first_order_revenue,
                'orders'  => (int)   $row->first_order_count,
            ];
        }

        return $map;
    }

    // ─── Day-30 ROAS map (§F8) ───────────────────────────────────────────────

    /**
     * Map of campaign internal ID → {revenue} for the Day-30 cohort window per §F8.
     *
     * For customers acquired (first order attributed to campaign) within the range,
     * sums all their orders within 30 days of that first order.
     *
     * Caller checks whether the 30-day window has elapsed before calling this
     * (returns null in the row when pending).
     *
     * @return array<int, array{revenue:float}>
     */
    private function buildDay30RoasMap(
        int $workspaceId,
        string $from,
        string $to,
        string $platform,
    ): array {
        $channelFilter = match ($platform) {
            'facebook' => "AND o.attribution_last_touch->>'channel_type' = 'paid_social'",
            'google'   => "AND o.attribution_last_touch->>'channel_type' = 'paid_search'",
            default    => "AND o.attribution_last_touch->>'channel_type' IN ('paid_social','paid_search')",
        };

        $rows = DB::select("
            WITH acquisition AS (
                SELECT
                    c.id                   AS campaign_id,
                    o.customer_email_hash,
                    o.occurred_at          AS acquired_at
                FROM orders o
                JOIN campaigns c
                  ON  c.workspace_id = o.workspace_id
                  AND (
                        o.attribution_last_touch->>'campaign' = c.external_id
                     OR LOWER(o.attribution_last_touch->>'campaign') = LOWER(c.name)
                  )
                WHERE o.workspace_id = ?
                  AND o.is_first_for_customer = true
                  AND o.status IN ('completed','processing')
                  AND o.total_in_reporting_currency IS NOT NULL
                  AND o.attribution_source IN ('pys','wc_native')
                  AND o.customer_email_hash IS NOT NULL
                  AND o.occurred_at BETWEEN ? AND ?
                  {$channelFilter}
            )
            SELECT
                aq.campaign_id,
                SUM(o2.total_in_reporting_currency) AS day30_revenue
            FROM acquisition aq
            JOIN orders o2 ON o2.customer_email_hash = aq.customer_email_hash
              AND o2.workspace_id = ?
              AND o2.status IN ('completed','processing')
              AND o2.total_in_reporting_currency IS NOT NULL
              AND o2.occurred_at BETWEEN aq.acquired_at
                                     AND (aq.acquired_at + INTERVAL '30 days')
            GROUP BY aq.campaign_id
        ", [$workspaceId, $from . ' 00:00:00', $to . ' 23:59:59', $workspaceId]);

        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row->campaign_id] = [
                'revenue' => (float) $row->day30_revenue,
            ];
        }

        return $map;
    }

    // ─── Motion Score (§F11) ─────────────────────────────────────────────────

    /**
     * Compute the 5-component Motion Score and verdict per §F11.
     *
     * Components in fixed customer-journey order: Hook → Hold → Click → Convert → Profit.
     * Each component maps to a letter grade A/B/C/D/F using linear interpolation
     * between the published A-threshold and F-threshold.
     *
     * Returns null when no meaningful data exists (zero impressions, no video, no spend).
     * Static image ads get null for Hook and Hold; Click falls back to Outbound CTR / impressions.
     *
     * @return array{hook:string|null,hold:string|null,click:string|null,convert:string|null,profit:string|null,verdict:string|null}|null
     */
    private function computeMotionScore(
        float $video3s,
        float $video15s,
        float $outboundClicks,
        int $impressions,
        int $clicks,
        int $attributedOrders,
        ?float $realRoas,
        ?float $targetRoas,
    ): ?array {
        // Require at least some impressions to produce a meaningful score
        if ($impressions === 0) {
            return null;
        }

        $hasVideo = $video3s > 0;

        // Hook: Thumbstop Ratio = 3s_plays / impressions. A ≥ 0.30, F < 0.15
        $hookScore = $hasVideo
            ? $this->gradeLinear($video3s / $impressions, 0.15, 0.30)
            : null;

        // Hold: Hold Rate = 15s_plays / 3s_plays. A ≥ 0.40, F < 0.20
        $holdScore = ($hasVideo && $video15s > 0)
            ? $this->gradeLinear($video15s / $video3s, 0.20, 0.40)
            : null;

        // Click: Thumbstop CTR (primary, video ads) or Outbound CTR (fallback, static)
        $clickScore = null;
        if ($hasVideo && $outboundClicks > 0) {
            // primary: outbound_clicks / 3s_plays. A ≥ 0.04, F < 0.01
            $clickScore = $this->gradeLinear($outboundClicks / $video3s, 0.01, 0.04);
        } elseif ($impressions > 0 && $outboundClicks > 0) {
            // fallback: outbound_clicks / impressions. A ≥ 0.015, F < 0.005
            $clickScore = $this->gradeLinear($outboundClicks / $impressions, 0.005, 0.015);
        }

        // Convert: CVR = store_orders / clicks. A ≥ 0.03, F < 0.005
        $convertScore = ($clicks > 0 && $attributedOrders > 0)
            ? $this->gradeLinear($attributedOrders / $clicks, 0.005, 0.03)
            : null;

        // Profit: Real ROAS vs target. A ≥ target×1.2, F < target×0.5
        $profitScore = null;
        if ($realRoas !== null && $targetRoas !== null && $targetRoas > 0) {
            $profitScore = $this->gradeLinear($realRoas, $targetRoas * 0.5, $targetRoas * 1.2);
        }

        // All null means we have nothing meaningful to show
        if ($hookScore === null && $holdScore === null && $clickScore === null
            && $convertScore === null && $profitScore === null) {
            return null;
        }

        $grades = ['F', 'D', 'C', 'B', 'A'];
        $toLetter = fn (?float $s): ?string => $s === null
            ? null
            : $grades[min(4, (int) round($s))];

        return [
            'hook'    => $toLetter($hookScore),
            'hold'    => $toLetter($holdScore),
            'click'   => $toLetter($clickScore),
            'convert' => $toLetter($convertScore),
            'profit'  => $toLetter($profitScore),
            'verdict' => $this->computeVerdict($profitScore, $hookScore, $holdScore, $clickScore, $convertScore),
        ];
    }

    /**
     * Linear interpolation between F-threshold (→ 0) and A-threshold (→ 4).
     * Clamped to [0, 4]. B/C/D fall between the thresholds.
     */
    private function gradeLinear(float $value, float $fThreshold, float $aThreshold): float
    {
        if ($value >= $aThreshold) return 4.0;
        if ($value <  $fThreshold) return 0.0;
        return ($value - $fThreshold) / ($aThreshold - $fThreshold) * 4.0;
    }

    /**
     * Compute the Verdict label from 5 component numeric scores (0–4 scale).
     *
     * Priority order per §F11:
     *   Kill    — Profit = F OR (Hook = F AND Hold = F)
     *   Scale   — Profit = A AND Hook+Hold average ≥ B
     *   Iterate — Hook or Hold = D/F but Click + Convert ≥ C average
     *   Watch   — everything else
     */
    private function computeVerdict(
        ?float $profitScore,
        ?float $hookScore,
        ?float $holdScore,
        ?float $clickScore,
        ?float $convertScore,
    ): ?string {
        if ($profitScore === null) {
            return null;
        }

        // Kill
        if ($profitScore < 0.5) return 'Kill';
        if ($hookScore !== null && $holdScore !== null && $hookScore < 0.5 && $holdScore < 0.5) return 'Kill';

        // Scale: Profit = A AND Hook+Hold ≥ B average (or no video data)
        if ($profitScore >= 3.5) {
            $hookHoldScores = array_filter([$hookScore, $holdScore], fn ($s) => $s !== null);
            $hookHoldAvg    = count($hookHoldScores) > 0
                ? array_sum($hookHoldScores) / count($hookHoldScores)
                : null;
            if ($hookHoldAvg === null || $hookHoldAvg >= 2.5) return 'Scale';
        }

        // Iterate: Hook or Hold weak but message resonates (Click + Convert ≥ C)
        $hookOrHoldWeak = ($hookScore !== null && $hookScore < 1.5) || ($holdScore !== null && $holdScore < 1.5);
        if ($hookOrHoldWeak) {
            $ccScores = array_filter([$clickScore, $convertScore], fn ($s) => $s !== null);
            if (count($ccScores) > 0 && array_sum($ccScores) / count($ccScores) >= 1.5) return 'Iterate';
        }

        return 'Watch';
    }

    // ─── UTM attribution map ──────────────────────────────────────────────────

    /**
     * Map of campaign internal ID → {revenue, orders} from attribution-tagged orders.
     *
     * Matches attribution_last_touch->>'campaign' against campaigns.external_id (primary)
     * and campaigns.name / previous_names (fallback for renamed campaigns).
     *
     * @param  string  $platform  '' = all, 'facebook' | 'google' = specific
     * @return array<int, array{revenue:float,orders:int}>
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
            default    => "AND o.attribution_last_touch->>'channel_type' IN ('paid_social','paid_search')",
        };

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
              AND o.status IN ('completed','processing')
              AND o.total_in_reporting_currency IS NOT NULL
              AND o.attribution_source IN ('pys','wc_native')
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
     * Tags each row with wl_tag ('winner'|'loser'|null), then filters on $params['filter'].
     *
     * Works for campaign, adset, and ad rows — all use real_roas as the classification metric.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array{rows:array<int,array<string,mixed>>,total_count:int,active_classifier:string,peer_avg_roas:float|null}
     */
    private function applyWinnersLosers(array $rows, array $params, Workspace $workspace, array $adAccountIds): array
    {
        $workspaceTargetRoas = $workspace->target_roas !== null ? (float) $workspace->target_roas : null;
        $hasTarget           = $workspaceTargetRoas !== null;

        $effectiveClassifier = $params['classifier'] ?? ($hasTarget ? 'target' : 'peer');

        $rowsWithRoas = array_filter($rows, fn (array $r) => ($r['real_roas'] ?? null) !== null && ($r['spend'] ?? 0) > 0);
        $peerAvgRoas  = count($rowsWithRoas) > 0
            ? array_sum(array_column($rowsWithRoas, 'real_roas')) / count($rowsWithRoas)
            : null;

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

        $tagged = array_map(function (array $r) use (
            $effectiveClassifier, $workspaceTargetRoas, $peerAvgRoas, $prevAttrMap, $prevSpendMap,
        ): array {
            if (($r['spend'] ?? 0) <= 0) {
                return array_merge($r, ['wl_tag' => null]);
            }

            $threshold = match ($effectiveClassifier) {
                'target' => $r['target_roas'] ?? $workspaceTargetRoas,
                default  => null,
            };

            $tag = match ($effectiveClassifier) {
                'target' => ($threshold !== null && ($r['real_roas'] ?? null) !== null)
                    ? ($r['real_roas'] >= $threshold ? 'winner' : 'loser')
                    : null,
                'peer' => ($peerAvgRoas !== null && ($r['real_roas'] ?? null) !== null)
                    ? ($r['real_roas'] >= $peerAvgRoas ? 'winner' : 'loser')
                    : null,
                'period' => $this->wlTagByPeriodRow($r, $prevAttrMap, $prevSpendMap),
                default  => null,
            };

            return array_merge($r, ['wl_tag' => $tag]);
        }, $rows);

        $totalCount = count($tagged);

        if ($params['filter'] !== 'all') {
            $filterTag = rtrim($params['filter'], 's');
            $tagged    = array_values(array_filter($tagged, fn (array $r) => ($r['wl_tag'] ?? null) === $filterTag));
        }

        return [
            'rows'              => $tagged,
            'total_count'       => $totalCount,
            'active_classifier' => $effectiveClassifier,
            'peer_avg_roas'     => $peerAvgRoas !== null ? round($peerAvgRoas, 2) : null,
        ];
    }

    private function wlTagByPeriodRow(array $row, array $prevAttrMap, array $prevSpendMap): ?string
    {
        $prevSpend = $prevSpendMap[$row['id']] ?? 0.0;
        $prevAttr  = $prevAttrMap[$row['id']]  ?? null;
        if ($prevAttr === null || $prevSpend <= 0 || ($row['real_roas'] ?? null) === null) return null;
        $prevRoas = (float) $prevAttr['revenue'] / $prevSpend;
        return $row['real_roas'] > $prevRoas ? 'winner' : 'loser';
    }

    /** @return array<int, float> campaign_id => spend */
    private function buildCampaignSpendMap(array $adAccountIds, string $from, string $to): array
    {
        if (empty($adAccountIds)) return [];

        $workspaceId  = app(WorkspaceContext::class)->id();
        $placeholders = implode(',', array_fill(0, count($adAccountIds), '?'));

        $rows = DB::select("
            SELECT c.id AS campaign_id, COALESCE(SUM(ai.spend_in_reporting_currency), 0) AS total_spend
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

    // ─── Metrics ─────────────────────────────────────────────────────────────

    /** Derive summary metrics from already-computed rows (same set as the table). */
    private function metricsFromRows(array $rows, int $workspaceId, string $from, string $to): array
    {
        $spend       = (float) array_sum(array_column($rows, 'spend'));
        $attrRevenue = (float) array_sum(array_map(fn ($r) => (float) ($r['attributed_revenue'] ?? 0), $rows));
        $attrOrders  = (int)   array_sum(array_map(fn ($r) => (int)   ($r['attributed_orders']  ?? 0), $rows));
        $impressions = (int)   array_sum(array_column($rows, 'impressions'));
        $clicks      = (int)   array_sum(array_column($rows, 'clicks'));

        $snapRow = DailySnapshot::withoutGlobalScopes()
            ->where('workspace_id', $workspaceId)
            ->whereBetween('date', [$from, $to])
            ->selectRaw('COALESCE(SUM(revenue), 0) AS total_revenue, COALESCE(SUM(orders_count), 0) AS total_orders')
            ->first();

        $revenue = (float) ($snapRow->total_revenue ?? 0);
        $orders  = (int)   ($snapRow->total_orders  ?? 0);

        return [
            'roas'               => ($spend > 0 && $revenue > 0)     ? round($revenue / $spend, 2)      : null,
            'cpo'                => ($spend > 0 && $orders > 0)      ? round($spend / $orders, 2)       : null,
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

    /**
     * Aggregate metrics queried directly (used for compare-period where we don't have row data).
     *
     * @param  int[]  $adAccountIds
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

        $channelFilter = match ($platform) {
            'facebook' => "AND attribution_last_touch->>'channel_type' = 'paid_social'",
            'google'   => "AND attribution_last_touch->>'channel_type' = 'paid_search'",
            default    => "AND attribution_last_touch->>'channel_type' IN ('paid_social','paid_search')",
        };

        $snapRow = DailySnapshot::withoutGlobalScopes()
            ->where('workspace_id', $workspaceId)
            ->whereBetween('date', [$from, $to])
            ->selectRaw('COALESCE(SUM(revenue), 0) AS total_revenue, COALESCE(SUM(orders_count), 0) AS total_orders')
            ->first();

        $revenue = (float) ($snapRow->total_revenue ?? 0);
        $orders  = (int)   ($snapRow->total_orders  ?? 0);

        $placeholders = implode(',', array_fill(0, count($adAccountIds), '?'));
        $adRow = DB::selectOne("
            SELECT COALESCE(SUM(spend_in_reporting_currency), 0) AS total_spend,
                   COALESCE(SUM(impressions), 0)                 AS total_impressions,
                   COALESCE(SUM(clicks), 0)                      AS total_clicks
            FROM ad_insights
            WHERE workspace_id = ?
              AND ad_account_id IN ({$placeholders})
              AND level = 'campaign'
              AND hour IS NULL
              AND date BETWEEN ? AND ?
        ", array_merge([$workspaceId], $adAccountIds, [$from, $to]));

        $spend       = (float) ($adRow->total_spend       ?? 0);
        $impressions = (int)   ($adRow->total_impressions ?? 0);
        $clicks      = (int)   ($adRow->total_clicks      ?? 0);

        $attrRow = DB::selectOne("
            SELECT COALESCE(SUM(total_in_reporting_currency), 0) AS attributed_revenue,
                   COUNT(id)                                      AS attributed_orders
            FROM orders
            WHERE workspace_id = ?
              AND status IN ('completed','processing')
              AND total_in_reporting_currency IS NOT NULL
              AND attribution_source IN ('pys','wc_native')
              AND attribution_last_touch->>'campaign' IS NOT NULL
              AND attribution_last_touch->>'campaign' <> ''
              {$channelFilter}
              AND occurred_at BETWEEN ? AND ?
        ", [$workspaceId, $from . ' 00:00:00', $to . ' 23:59:59']);

        $attributedRevenue = (float) ($attrRow->attributed_revenue ?? 0);
        $attributedOrders  = (int)   ($attrRow->attributed_orders  ?? 0);

        return [
            'roas'               => ($spend > 0 && $revenue > 0)          ? round($revenue / $spend, 2)          : null,
            'cpo'                => ($spend > 0 && $orders > 0)           ? round($spend / $orders, 2)           : null,
            'spend'              => $spend > 0 ? $spend : null,
            'revenue'            => $revenue > 0 ? $revenue : null,
            'attributed_revenue' => $attributedRevenue > 0 ? $attributedRevenue : null,
            'attributed_orders'  => $attributedOrders,
            'real_roas'          => ($spend > 0 && $attributedRevenue > 0) ? round($attributedRevenue / $spend, 2) : null,
            'real_cpo'           => ($spend > 0 && $attributedOrders > 0)  ? round($spend / $attributedOrders, 2)  : null,
            'impressions'        => $impressions,
            'clicks'             => $clicks,
            'ctr'                => $impressions > 0 ? round(($clicks / $impressions) * 100, 2) : null,
            'cpc'                => $clicks > 0 ? round($spend / $clicks, 4) : null,
        ];
    }

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

    // ─── Platform breakdown / chart ───────────────────────────────────────────

    private function computePlatformBreakdown(int $workspaceId, string $from, string $to): array
    {
        $rows = DB::select("
            SELECT aa.platform,
                   COALESCE(SUM(ai.spend_in_reporting_currency), 0) AS total_spend,
                   COALESCE(SUM(ai.impressions), 0)                  AS total_impressions,
                   COALESCE(SUM(ai.clicks), 0)                       AS total_clicks
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

    /** @param int[] $adAccountIds */
    private function buildSpendChart(
        int $workspaceId,
        array $adAccountIds,
        string $from,
        string $to,
        string $granularity,
    ): array {
        if (empty($adAccountIds)) return [];

        $dateExpr     = $granularity === 'weekly'
            ? "DATE_TRUNC('week', ai.date)::date::text"
            : 'ai.date::text';
        $placeholders = implode(',', array_fill(0, count($adAccountIds), '?'));

        $rows = DB::select("
            SELECT {$dateExpr} AS date, aa.platform,
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

    // ─── Revenue context ──────────────────────────────────────────────────────

    /** @return array{float|null, float|null} [total_revenue, unattributed_revenue] */
    private function computeRevenueContext(int $workspaceId, bool $hasStore, string $from, string $to): array
    {
        if (! $hasStore) return [null, null];

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

    // ─── Helpers ─────────────────────────────────────────────────────────────

    /**
     * Resolve the display name for a campaign or adset from its internal ID.
     * Returns null when no ID is provided or the record doesn't exist.
     */
    private function resolveName(int $workspaceId, string $table, ?int $id): ?string
    {
        if ($id === null) return null;
        $row = DB::selectOne("SELECT name FROM {$table} WHERE id = ? AND workspace_id = ?", [$id, $workspaceId]);
        return $row?->name;
    }

    /** Build a SQL WHERE fragment for status filtering with a configurable table alias. */
    private function statusFilter(string $status, string $alias): string
    {
        return match ($status) {
            'active' => "AND LOWER({$alias}.status) IN ('active','enabled','delivering')",
            'paused' => "AND LOWER({$alias}.status) IN ('paused','inactive','disabled')",
            default  => '',
        };
    }
}
