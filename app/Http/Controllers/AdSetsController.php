<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\AdAccount;
use App\Models\Workspace;
use App\Services\WorkspaceContext;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Ad set performance page — shows adset-level insights.
 *
 * Triggered by: GET /campaigns/adsets
 * Reads from:   adsets, campaigns, ad_accounts, ad_insights (level='adset'), orders
 * Writes to:    nothing
 *
 * Query strategy: LEFT JOIN from adsets (structure) to ad_insights (metrics).
 * This shows all adsets — including those with zero spend in the period — rather
 * than only adsets that happen to have insight rows in the window. Campaign-level
 * insights are dense (high coverage) but adset/ad insights start sparse and grow
 * as more syncs accumulate.
 *
 * Winners/Losers: server-side classification using the same three classifiers as
 * CampaignsController (target / peer / period). The 'target' classifier uses
 * workspace_target_roas as the threshold (no per-adset target column exists).
 * Classification always uses real_roas, not platform_roas.
 *
 * Optional URL params:
 *   ?campaign_id=N  — drill-through from the campaigns table
 *
 * Related: app/Http/Controllers/CampaignsController.php
 * Related: app/Http/Controllers/AdsController.php
 *
 * @see PLANNING.md section 15
 */
class AdSetsController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $workspaceId = app(WorkspaceContext::class)->id();
        $workspace   = Workspace::withoutGlobalScopes()->findOrFail($workspaceId);

        $params = $this->validateParams($request);

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

        $workspaceTargetRoas = $workspace->target_roas ? (float) $workspace->target_roas : null;

        if ($adAccounts->isEmpty()) {
            return Inertia::render('Campaigns/AdSets', [
                'has_ad_accounts'      => false,
                'ad_accounts'          => [],
                'adsets'               => [],
                'adsets_total_count'   => 0,
                'active_classifier'    => $workspaceTargetRoas !== null ? 'target' : 'peer',
                'wl_has_target'        => $workspaceTargetRoas !== null,
                'wl_peer_avg_roas'     => null,
                'campaign_name'        => null,
                'workspace_target_roas' => $workspaceTargetRoas,
                ...$params,
            ]);
        }

        $filteredAccounts = $params['platform'] === 'all'
            ? $adAccounts
            : $adAccounts->where('platform', $params['platform']);

        $adAccountIds = $filteredAccounts->pluck('id')->all();

        $campaignName = null;
        if ($params['campaign_id'] !== null) {
            $campaign = DB::selectOne(
                "SELECT name FROM campaigns WHERE id = ? AND workspace_id = ?",
                [$params['campaign_id'], $workspaceId],
            );
            $campaignName = $campaign?->name;
        }

        $adsets = $this->computeAdSets(
            $workspaceId,
            $adAccountIds,
            $params['from'],
            $params['to'],
            $params['status'],
            $params['platform'],
            $params['campaign_id'],
        );

        // Sort in PHP — NULLs always last regardless of direction
        $sortKey   = $params['sort'];
        $direction = $params['direction'];
        usort($adsets, function (array $a, array $b) use ($sortKey, $direction): int {
            $aVal = $a[$sortKey];
            $bVal = $b[$sortKey];
            if ($aVal === null && $bVal === null) return 0;
            if ($aVal === null) return 1;
            if ($bVal === null) return -1;
            $cmp = $aVal <=> $bVal;
            return $direction === 'asc' ? $cmp : -$cmp;
        });

        $wl = $this->applyWinnersLosers($adsets, $params, $workspace, $adAccountIds);

        return Inertia::render('Campaigns/AdSets', [
            'has_ad_accounts'      => true,
            'ad_accounts'          => $adAccountList,
            'adsets'               => $wl['adsets'],
            'adsets_total_count'   => $wl['total_count'],
            'active_classifier'    => $wl['active_classifier'],
            'wl_has_target'        => $workspaceTargetRoas !== null,
            'wl_peer_avg_roas'     => $wl['peer_avg_roas'],
            'campaign_name'        => $campaignName,
            'workspace_target_roas' => $workspaceTargetRoas,
            ...$params,
        ]);
    }

    // ─── Parameter validation ─────────────────────────────────────────────────

    /**
     * @return array{from:string,to:string,platform:string,status:string,sort:string,direction:string,campaign_id:int|null,view:string,filter:string,classifier:string|null}
     */
    private function validateParams(Request $request): array
    {
        $v = $request->validate([
            'from'        => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'to'          => ['sometimes', 'nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'platform'    => ['sometimes', 'nullable', 'in:all,facebook,google'],
            'status'      => ['sometimes', 'nullable', 'in:all,active,paused'],
            'sort'        => ['sometimes', 'nullable', 'in:spend,impressions,clicks,ctr,cpc,platform_roas,real_roas'],
            'direction'   => ['sometimes', 'nullable', 'in:asc,desc'],
            'campaign_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'view'        => ['sometimes', 'nullable', 'in:table,quadrant'],
            'filter'      => ['sometimes', 'nullable', 'in:all,winners,losers'],
            'classifier'  => ['sometimes', 'nullable', 'in:target,peer,period'],
        ]);

        return [
            'from'        => $v['from']        ?? now()->subDays(29)->toDateString(),
            'to'          => $v['to']           ?? now()->toDateString(),
            'platform'    => $v['platform']     ?? 'all',
            'status'      => $v['status']       ?? 'all',
            'sort'        => $v['sort']         ?? 'spend',
            'direction'   => $v['direction']    ?? 'desc',
            'campaign_id' => isset($v['campaign_id']) ? (int) $v['campaign_id'] : null,
            'view'        => $v['view']         ?? 'table',
            'filter'      => $v['filter']       ?? 'all',
            'classifier'  => $v['classifier']   ?? null,
        ];
    }

    // ─── Winners / Losers classifier ─────────────────────────────────────────

    /**
     * Tags each adset with wl_tag ('winner'|'loser'|null), then filters based on $params['filter'].
     *
     * Three classifiers per PLANNING.md section 15 — mirrors CampaignsController::applyWinnersLosers():
     *   target — above/below workspace target_roas (no per-adset target column exists)
     *   peer   — above/below the workspace-average real_roas across all adsets with spend
     *   period — improved/declined vs the same-length period immediately before $params['from']
     *
     * Default: 'target' when workspace has target_roas set, 'peer' otherwise.
     * Dormant adsets (spend = 0) always receive wl_tag = null.
     * Classification always uses real_roas (not platform_roas).
     *
     * @param  array<int, array<string, mixed>>  $adsets
     * @param  array<string, mixed>              $params
     * @param  Workspace                         $workspace
     * @param  int[]                             $adAccountIds
     * @return array{adsets:array<int,array<string,mixed>>,total_count:int,active_classifier:string,peer_avg_roas:float|null}
     */
    private function applyWinnersLosers(
        array $adsets,
        array $params,
        Workspace $workspace,
        array $adAccountIds,
    ): array {
        $workspaceTargetRoas = $workspace->target_roas !== null ? (float) $workspace->target_roas : null;
        $hasTarget           = $workspaceTargetRoas !== null;

        $effectiveClassifier = $params['classifier']
            ?? ($hasTarget ? 'target' : 'peer');

        $adsetsWithRoas = array_filter(
            $adsets,
            fn (array $a) => $a['real_roas'] !== null && $a['spend'] > 0,
        );
        $peerAvgRoas = count($adsetsWithRoas) > 0
            ? array_sum(array_column($adsetsWithRoas, 'real_roas')) / count($adsetsWithRoas)
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
            $prevSpendMap = $this->buildAdSetSpendMap($adAccountIds, $prevFrom, $prevTo);
        }

        $tagged = array_map(function (array $a) use (
            $effectiveClassifier, $workspaceTargetRoas, $peerAvgRoas, $prevAttrMap, $prevSpendMap,
        ): array {
            if ($a['spend'] <= 0) {
                return array_merge($a, ['wl_tag' => null]);
            }

            $tag = match ($effectiveClassifier) {
                'target' => $this->wlTagByTarget($a, $workspaceTargetRoas),
                'peer'   => $this->wlTagByPeer($a, $peerAvgRoas),
                'period' => $this->wlTagByPeriod($a, $prevAttrMap, $prevSpendMap),
                default  => null,
            };

            return array_merge($a, ['wl_tag' => $tag]);
        }, $adsets);

        $totalCount = count($tagged);

        if ($params['filter'] !== 'all') {
            $filterTag = rtrim($params['filter'], 's');
            $tagged    = array_values(
                array_filter($tagged, fn (array $a) => $a['wl_tag'] === $filterTag),
            );
        }

        return [
            'adsets'            => $tagged,
            'total_count'       => $totalCount,
            'active_classifier' => $effectiveClassifier,
            'peer_avg_roas'     => $peerAvgRoas !== null ? round($peerAvgRoas, 2) : null,
        ];
    }

    /** Tag adset vs workspace target_roas. Null when no target exists. */
    private function wlTagByTarget(array $adset, ?float $workspaceTargetRoas): ?string
    {
        if ($workspaceTargetRoas === null || $adset['real_roas'] === null) {
            return null;
        }
        return $adset['real_roas'] >= $workspaceTargetRoas ? 'winner' : 'loser';
    }

    /** Tag adset vs workspace-average real_roas across all adsets with spend. */
    private function wlTagByPeer(array $adset, ?float $peerAvgRoas): ?string
    {
        if ($peerAvgRoas === null || $adset['real_roas'] === null) {
            return null;
        }
        return $adset['real_roas'] >= $peerAvgRoas ? 'winner' : 'loser';
    }

    /**
     * Tag adset by comparing current-period real_roas to previous-period real_roas.
     * Winner = improved (higher ROAS). Null when previous period has no data.
     */
    private function wlTagByPeriod(array $adset, array $prevAttrMap, array $prevSpendMap): ?string
    {
        $prevSpend = $prevSpendMap[$adset['id']] ?? 0.0;
        $prevAttr  = $prevAttrMap[$adset['id']]  ?? null;

        if ($prevAttr === null || $prevSpend <= 0) {
            return null;
        }

        $prevRoas = (float) $prevAttr['revenue'] / $prevSpend;

        if ($adset['real_roas'] === null) {
            return null;
        }

        return $adset['real_roas'] > $prevRoas ? 'winner' : 'loser';
    }

    // ─── UTM attribution ──────────────────────────────────────────────────────

    /**
     * Build a map of adset internal ID → {revenue, orders} from UTM-tagged orders.
     *
     * Matches utm_content against adsets.external_id (platform ID, common case) or
     * adsets.name (name-based fallback). Same pattern as CampaignsController::buildUtmAttributionMap()
     * but one level deeper: utm_campaign → campaign, utm_content → adset.
     *
     * Why utm_content: Facebook/Google ad URL builders write the adset ID into utm_content
     * (e.g. {{adset.id}} → "120241558531060383"). Name matching handles manual UTM setups.
     *
     * @return array<int, array{revenue:float,orders:int}>  Keyed by adsets.id
     */
    private function buildUtmAttributionMap(int $workspaceId, string $from, string $to, string $platform): array
    {
        $sourceFilter = match ($platform) {
            'facebook' => "AND LOWER(o.utm_source) IN ('facebook','fb','ig','instagram')",
            'google'   => "AND LOWER(o.utm_source) IN ('google','cpc','google-ads','ppc')",
            default    => "AND LOWER(o.utm_source) IN ('facebook','fb','ig','instagram','google','cpc','google-ads','ppc')",
        };

        $rows = DB::select("
            SELECT
                ads.id                             AS adset_id,
                SUM(o.total_in_reporting_currency) AS attributed_revenue,
                COUNT(o.id)                        AS attributed_orders
            FROM orders o
            JOIN adsets ads
              ON  ads.workspace_id = o.workspace_id
              AND (
                    o.utm_content = ads.external_id
                 OR LOWER(o.utm_content) = LOWER(ads.name)
              )
            WHERE o.workspace_id = ?
              AND o.status IN ('completed', 'processing')
              AND o.total_in_reporting_currency IS NOT NULL
              AND o.utm_content IS NOT NULL
              AND o.utm_content <> ''
              AND o.occurred_at BETWEEN ? AND ?
              {$sourceFilter}
            GROUP BY ads.id
        ", [$workspaceId, $from . ' 00:00:00', $to . ' 23:59:59']);

        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row->adset_id] = [
                'revenue' => (float) $row->attributed_revenue,
                'orders'  => (int)   $row->attributed_orders,
            ];
        }

        return $map;
    }

    /**
     * Build a map of adset internal ID → spend for a given date range.
     * Used by the 'period' W/L classifier to compute previous-period real_roas.
     *
     * @param  int[]  $adAccountIds
     * @return array<int, float>  adset_id => spend
     */
    private function buildAdSetSpendMap(array $adAccountIds, string $from, string $to): array
    {
        if (empty($adAccountIds)) {
            return [];
        }

        $workspaceId  = app(WorkspaceContext::class)->id();
        $placeholders = implode(',', array_fill(0, count($adAccountIds), '?'));

        $rows = DB::select("
            SELECT
                ads.id                                                AS adset_id,
                COALESCE(SUM(ai.spend_in_reporting_currency), 0)      AS total_spend
            FROM ad_insights ai
            JOIN adsets ads ON ads.id = ai.adset_id
            WHERE ai.workspace_id = ?
              AND ai.ad_account_id IN ({$placeholders})
              AND ai.level = 'adset'
              AND ai.hour IS NULL
              AND ai.date BETWEEN ? AND ?
            GROUP BY ads.id
        ", array_merge([$workspaceId], $adAccountIds, [$from, $to]));

        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row->adset_id] = (float) $row->total_spend;
        }

        return $map;
    }

    // ─── Ad set rows ──────────────────────────────────────────────────────────────

    /**
     * LEFT JOIN from adsets (structure) to ad_insights (metrics).
     *
     * Why LEFT JOIN: adset-level insight rows only exist for the last 3 days after the
     * first sync. Querying FROM ad_insights would hide all adsets with no spend in the
     * window. LEFT JOIN shows all adsets with zero spend where no rows exist.
     *
     * @param  int[]    $adAccountIds
     * @param  int|null $campaignId   Drill-through filter
     * @return array<int, array<string, mixed>>
     */
    private function computeAdSets(
        int $workspaceId,
        array $adAccountIds,
        string $from,
        string $to,
        string $status,
        string $platform,
        ?int $campaignId,
    ): array {
        if (empty($adAccountIds)) {
            return [];
        }

        $attributionMap = $this->buildUtmAttributionMap($workspaceId, $from, $to, $platform);

        $placeholders = implode(',', array_fill(0, count($adAccountIds), '?'));

        $statusFilter   = match ($status) {
            'active' => "AND LOWER(ads.status) IN ('active','enabled','delivering')",
            'paused' => "AND LOWER(ads.status) IN ('paused','inactive','disabled')",
            default  => '',
        };
        $campaignFilter = $campaignId !== null ? "AND c.id = ?" : '';

        $rows = DB::select("
            SELECT
                ads.id,
                ads.name,
                ads.status,
                c.id     AS campaign_id,
                c.name   AS campaign_name,
                aa.platform,
                COALESCE(SUM(ai.spend_in_reporting_currency), 0) AS total_spend,
                COALESCE(SUM(ai.impressions), 0)                 AS total_impressions,
                COALESCE(SUM(ai.clicks), 0)                      AS total_clicks,
                AVG(ai.platform_roas)                            AS avg_platform_roas
            FROM adsets ads
            JOIN campaigns c    ON c.id  = ads.campaign_id
            JOIN ad_accounts aa ON aa.id = c.ad_account_id
            LEFT JOIN ad_insights ai
                ON  ai.adset_id      = ads.id
                AND ai.level         = 'adset'
                AND ai.hour          IS NULL
                AND ai.date          BETWEEN ? AND ?
            WHERE ads.workspace_id = ?
              AND aa.id IN ({$placeholders})
              {$statusFilter}
              {$campaignFilter}
            GROUP BY ads.id, ads.name, ads.status, c.id, c.name, aa.platform
        ", array_merge([$from, $to, $workspaceId], $adAccountIds, $campaignId !== null ? [$campaignId] : []));

        return array_map(function (object $row) use ($attributionMap): array {
            $spend       = (float) $row->total_spend;
            $impressions = (int)   $row->total_impressions;
            $clicks      = (int)   $row->total_clicks;

            $attribution       = $attributionMap[(int) $row->id] ?? null;
            $attributedRevenue = $attribution ? $attribution['revenue'] : null;
            $attributedOrders  = $attribution ? $attribution['orders']  : 0;

            return [
                'id'                 => (int)    $row->id,
                'name'               => (string) ($row->name ?? ''),
                'status'             => $row->status,
                'platform'           => (string) $row->platform,
                'campaign_id'        => (int)    $row->campaign_id,
                'campaign_name'      => (string) ($row->campaign_name ?? ''),
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
                'attributed_revenue' => $attributedRevenue,
                'attributed_orders'  => $attributedOrders,
            ];
        }, $rows);
    }
}
