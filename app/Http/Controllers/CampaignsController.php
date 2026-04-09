<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\AdAccount;
use App\Models\AdInsight;
use App\Models\DailySnapshot;
use App\Services\WorkspaceContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class CampaignsController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $workspaceId = app(WorkspaceContext::class)->id();
        $params      = $this->validateParams($request);

        $adAccounts = AdAccount::withoutGlobalScopes()
            ->where('workspace_id', $workspaceId)
            ->select(['id', 'platform', 'name', 'status', 'last_synced_at'])
            ->get();

        $adAccountList = $adAccounts->map(fn ($a) => [
            'id'       => $a->id,
            'platform' => $a->platform,
            'name'     => $a->name,
        ])->values()->all();

        if ($adAccounts->isEmpty()) {
            return Inertia::render('Campaigns/Index', [
                'has_ad_accounts'    => false,
                'ad_accounts'        => [],
                'metrics'            => null,
                'compare_metrics'    => null,
                'campaigns'          => [],
                'platform_breakdown' => [],
                'chart_data'         => [],
                'compare_chart_data' => null,
                ...$params,
            ]);
        }

        // Filter ad accounts by selected platform, then optionally by specific account
        $filteredAccounts = $params['platform'] === 'all'
            ? $adAccounts
            : $adAccounts->where('platform', $params['platform']);

        if ($params['ad_account_id'] !== null) {
            $filteredAccounts = $filteredAccounts->where('id', $params['ad_account_id']);
        }

        $adAccountIds = $filteredAccounts->pluck('id')->all();

        $metrics = $this->computeMetrics(
            $workspaceId,
            $adAccountIds,
            $params['from'],
            $params['to'],
        );

        $compareMetrics = ($params['compare_from'] && $params['compare_to'])
            ? $this->computeMetrics(
                $workspaceId,
                $adAccountIds,
                $params['compare_from'],
                $params['compare_to'],
            )
            : null;

        $campaigns = $this->computeCampaigns(
            $workspaceId,
            $adAccounts,
            $params['from'],
            $params['to'],
            $params['platform'],
            $params['status'],
            $params['ad_account_id'],
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

        $platformBreakdown = $this->computePlatformBreakdown($workspaceId, $params['from'], $params['to']);

        $chartData = $this->buildSpendChart(
            $workspaceId, $adAccountIds, $params['from'], $params['to'], $params['granularity'],
        );

        $compareChartData = ($params['compare_from'] && $params['compare_to'])
            ? $this->buildSpendChart(
                $workspaceId, $adAccountIds, $params['compare_from'], $params['compare_to'], $params['granularity'],
            )
            : null;

        return Inertia::render('Campaigns/Index', [
            'has_ad_accounts'    => true,
            'ad_accounts'        => $adAccountList,
            'metrics'            => $metrics,
            'compare_metrics'    => $compareMetrics,
            'campaigns'          => $campaigns,
            'platform_breakdown' => $platformBreakdown,
            'chart_data'         => $chartData,
            'compare_chart_data' => $compareChartData,
            ...$params,
        ]);
    }

    // ─── Parameter validation ─────────────────────────────────────────────────

    /** @return array{from:string,to:string,compare_from:string|null,compare_to:string|null,granularity:string,platform:string,status:string,view:string,sort:string,direction:string,ad_account_id:int|null} */
    private function validateParams(Request $request): array
    {
        $v = $request->validate([
            'from'           => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'to'             => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'compare_from'   => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'compare_to'     => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'granularity'    => ['sometimes', 'nullable', 'in:hourly,daily,weekly'],
            'platform'       => ['sometimes', 'nullable', 'in:all,facebook,google'],
            'status'         => ['sometimes', 'nullable', 'in:all,active,paused'],
            'view'           => ['sometimes', 'nullable', 'in:table,quadrant'],
            'sort'           => ['sometimes', 'nullable', 'in:real_roas,real_cpo,spend,attributed_revenue'],
            'direction'      => ['sometimes', 'nullable', 'in:asc,desc'],
            'ad_account_id'  => ['sometimes', 'nullable', 'integer', 'min:1'],
        ]);

        return [
            'from'           => $v['from']          ?? now()->subDays(29)->toDateString(),
            'to'             => $v['to']             ?? now()->toDateString(),
            'compare_from'   => $v['compare_from']   ?? null,
            'compare_to'     => $v['compare_to']     ?? null,
            'granularity'    => $v['granularity']    ?? 'daily',
            'platform'       => $v['platform']       ?? 'all',
            'status'         => $v['status']         ?? 'all',
            'view'           => $v['view']           ?? 'table',
            'sort'           => $v['sort']           ?? 'real_roas',
            'direction'      => $v['direction']      ?? 'desc',
            'ad_account_id'  => isset($v['ad_account_id']) ? (int) $v['ad_account_id'] : null,
        ];
    }

    // ─── Metrics ─────────────────────────────────────────────────────────────

    /** @param int[] $adAccountIds */
    private function computeMetrics(int $workspaceId, array $adAccountIds, string $from, string $to): array
    {
        $revenue = (float) (DailySnapshot::withoutGlobalScopes()
            ->where('workspace_id', $workspaceId)
            ->whereBetween('date', [$from, $to])
            ->sum('revenue') ?? 0);

        $orders = (int) (DailySnapshot::withoutGlobalScopes()
            ->where('workspace_id', $workspaceId)
            ->whereBetween('date', [$from, $to])
            ->sum('orders_count') ?? 0);

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

        // Also compute UTM-attributed revenue/orders for a more accurate "real" metric set
        $attrRow = DB::selectOne("
            SELECT
                COALESCE(SUM(total_in_reporting_currency), 0) AS attributed_revenue,
                COUNT(id)                                      AS attributed_orders
            FROM orders
            WHERE workspace_id = ?
              AND status IN ('completed', 'processing')
              AND total_in_reporting_currency IS NOT NULL
              AND utm_campaign IS NOT NULL AND utm_campaign <> ''
              AND occurred_at BETWEEN ? AND ?
              AND LOWER(utm_source) IN ('facebook','fb','ig','instagram','google','cpc','google-ads','ppc')
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
            'impressions'         => $impressions,
            'clicks'              => $clicks,
            'ctr'                 => $impressions > 0 ? round(($clicks / $impressions) * 100, 2) : null,
            'cpc'                 => $clicks > 0 ? round($spend / $clicks, 4) : null,
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
    private function computeCampaigns(
        int $workspaceId,
        $adAccounts,
        string $from,
        string $to,
        string $platform,
        string $status,
        ?int $adAccountId = null,
    ): array {
        $filteredAccounts = $platform === 'all'
            ? $adAccounts
            : $adAccounts->where('platform', $platform);

        if ($adAccountId !== null) {
            $filteredAccounts = $filteredAccounts->where('id', $adAccountId);
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
                COALESCE(SUM(ai.spend_in_reporting_currency), 0) AS total_spend,
                COALESCE(SUM(ai.impressions), 0)                 AS total_impressions,
                COALESCE(SUM(ai.clicks), 0)                      AS total_clicks,
                AVG(ai.platform_roas)                            AS avg_platform_roas
            FROM ad_insights ai
            JOIN campaigns c   ON c.id  = ai.campaign_id
            JOIN ad_accounts aa ON aa.id = ai.ad_account_id
            WHERE ai.workspace_id = ?
              AND ai.ad_account_id IN ({$placeholders})
              AND ai.level = 'campaign'
              AND ai.hour IS NULL
              AND ai.date BETWEEN ? AND ?
              {$statusFilter}
            GROUP BY c.id, c.name, aa.platform, c.status
        ", array_merge([$workspaceId], $adAccountIds, [$from, $to]));

        // Build UTM attribution map for matched platforms
        $utmPlatform = $platform === 'all' ? '' : $platform;
        $attrMap     = $this->buildUtmAttributionMap($workspaceId, $from, $to, $utmPlatform);

        return array_map(function (object $row) use ($attrMap, $from, $to): array {
            $spend       = (float) $row->total_spend;
            $impressions = (int)   $row->total_impressions;
            $clicks      = (int)   $row->total_clicks;

            $key               = mb_strtolower((string) ($row->name ?? ''));
            $attr              = $attrMap[$key] ?? null;
            $attributedRevenue = $attr !== null ? (float) $attr['revenue'] : null;
            $attributedOrders  = $attr !== null ? (int)   $attr['orders']  : 0;

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
            ];
        }, $rows);
    }

    /**
     * Build a map of lowercase campaign name → {revenue, orders} from UTM-tagged orders.
     *
     * @param  string  $platform  '' = all platforms, 'facebook' | 'google' = specific
     * @return array<string, array{revenue:float,orders:int}>
     */
    private function buildUtmAttributionMap(
        int $workspaceId,
        string $from,
        string $to,
        string $platform,
    ): array {
        $sourceFilter = match ($platform) {
            'facebook' => "AND LOWER(o.utm_source) IN ('facebook','fb','ig','instagram')",
            'google'   => "AND LOWER(o.utm_source) IN ('google','cpc','google-ads','ppc')",
            default    => "AND LOWER(o.utm_source) IN ('facebook','fb','ig','instagram','google','cpc','google-ads','ppc')",
        };

        $rows = DB::select("
            SELECT
                LOWER(o.utm_campaign)              AS campaign_key,
                SUM(o.total_in_reporting_currency) AS attributed_revenue,
                COUNT(o.id)                        AS attributed_orders
            FROM orders o
            WHERE o.workspace_id = ?
              AND o.status IN ('completed', 'processing')
              AND o.total_in_reporting_currency IS NOT NULL
              AND o.utm_campaign IS NOT NULL
              AND o.utm_campaign <> ''
              AND o.occurred_at BETWEEN ? AND ?
              {$sourceFilter}
            GROUP BY LOWER(o.utm_campaign)
        ", [$workspaceId, $from . ' 00:00:00', $to . ' 23:59:59']);

        $map = [];
        foreach ($rows as $row) {
            $map[(string) $row->campaign_key] = [
                'revenue' => (float) $row->attributed_revenue,
                'orders'  => (int)   $row->attributed_orders,
            ];
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
