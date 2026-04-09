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

class AdvertisingController extends Controller
{
    public function index(Request $request): Response
    {
        $workspaceId = app(WorkspaceContext::class)->id();
        $params      = $this->validateParams($request);

        $adAccounts = AdAccount::withoutGlobalScopes()
            ->where('workspace_id', $workspaceId)
            ->select(['id', 'platform', 'name', 'status'])
            ->get();

        if ($adAccounts->isEmpty()) {
            return Inertia::render('Advertising/Index', [
                'has_ad_accounts'    => false,
                'has_facebook'       => false,
                'has_google'         => false,
                'metrics'            => null,
                'compare_metrics'    => null,
                'platform_breakdown' => [],
                'chart_data'         => [],
                'compare_chart_data' => null,
                'granularity'        => $params['granularity'],
                'from'               => $params['from'],
                'to'                 => $params['to'],
            ]);
        }

        $hasFacebook = $adAccounts->where('platform', 'facebook')->isNotEmpty();
        $hasGoogle   = $adAccounts->where('platform', 'google')->isNotEmpty();

        $metrics = $this->computeBlendedMetrics($workspaceId, $params['from'], $params['to']);

        $compareMetrics = ($params['compare_from'] && $params['compare_to'])
            ? $this->computeBlendedMetrics($workspaceId, $params['compare_from'], $params['compare_to'])
            : null;

        $platformBreakdown = $this->computePlatformBreakdown($workspaceId, $params['from'], $params['to']);

        $chartData = $this->buildSpendChart(
            $workspaceId, $params['from'], $params['to'], $params['granularity'],
        );

        $compareChartData = ($params['compare_from'] && $params['compare_to'])
            ? $this->buildSpendChart(
                $workspaceId, $params['compare_from'], $params['compare_to'], $params['granularity'],
            )
            : null;

        return Inertia::render('Advertising/Index', [
            'has_ad_accounts'    => true,
            'has_facebook'       => $hasFacebook,
            'has_google'         => $hasGoogle,
            'metrics'            => $metrics,
            'compare_metrics'    => $compareMetrics,
            'platform_breakdown' => $platformBreakdown,
            'chart_data'         => $chartData,
            'compare_chart_data' => $compareChartData,
            'granularity'        => $params['granularity'],
            'from'               => $params['from'],
            'to'                 => $params['to'],
        ]);
    }

    public function facebook(Request $request): Response
    {
        return $this->platformPage($request, 'facebook', 'Advertising/Facebook');
    }

    public function google(Request $request): Response
    {
        return $this->platformPage($request, 'google', 'Advertising/Google');
    }

    // ─── Shared ──────────────────────────────────────────────────────────────

    private function platformPage(Request $request, string $platform, string $component): Response
    {
        $workspaceId = app(WorkspaceContext::class)->id();
        $params      = $this->validateParams($request);

        $adAccounts = AdAccount::withoutGlobalScopes()
            ->where('workspace_id', $workspaceId)
            ->where('platform', $platform)
            ->select(['id', 'external_id', 'name', 'status', 'last_synced_at'])
            ->get();

        if ($adAccounts->isEmpty()) {
            return Inertia::render($component, [
                'has_ad_accounts' => false,
                'ad_accounts'     => [],
                'metrics'         => null,
                'compare_metrics' => null,
                'campaigns'       => [],
                'chart_data'      => [],
                'granularity'     => $params['granularity'],
                'from'            => $params['from'],
                'to'              => $params['to'],
            ]);
        }

        $adAccountIds = $adAccounts->pluck('id')->all();

        $metrics = $this->computePlatformMetrics($workspaceId, $adAccountIds, $params['from'], $params['to']);

        $compareMetrics = ($params['compare_from'] && $params['compare_to'])
            ? $this->computePlatformMetrics(
                $workspaceId, $adAccountIds, $params['compare_from'], $params['compare_to'],
            )
            : null;

        $campaigns = $this->computeCampaignTable($workspaceId, $adAccountIds, $params['from'], $params['to'], $platform);

        $chartData = $this->buildPlatformSpendChart(
            $workspaceId, $adAccountIds, $params['from'], $params['to'], $params['granularity'],
        );

        return Inertia::render($component, [
            'has_ad_accounts' => true,
            'ad_accounts'     => $adAccounts->map(fn ($a) => [
                'id'             => $a->id,
                'name'           => $a->name,
                'status'         => $a->status,
                'last_synced_at' => $a->last_synced_at?->toISOString(),
            ])->all(),
            'metrics'         => $metrics,
            'compare_metrics' => $compareMetrics,
            'campaigns'       => $campaigns,
            'chart_data'      => $chartData,
            'granularity'     => $params['granularity'],
            'from'            => $params['from'],
            'to'              => $params['to'],
        ]);
    }

    /** @return array{from:string,to:string,compare_from:string|null,compare_to:string|null,granularity:string} */
    private function validateParams(Request $request): array
    {
        $validated = $request->validate([
            'from'         => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'to'           => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'compare_from' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'compare_to'   => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'granularity'  => ['sometimes', 'nullable', 'in:hourly,daily,weekly'],
        ]);

        return [
            'from'         => $validated['from']         ?? now()->subDays(29)->toDateString(),
            'to'           => $validated['to']           ?? now()->toDateString(),
            'compare_from' => $validated['compare_from'] ?? null,
            'compare_to'   => $validated['compare_to']   ?? null,
            'granularity'  => $validated['granularity']  ?? 'daily',
        ];
    }

    /**
     * Blended metrics: revenue from all snapshots + spend from all campaign-level ad insights.
     * ROAS = revenue / spend_in_reporting_currency. CPO = spend / orders_count. null if either is zero.
     */
    private function computeBlendedMetrics(int $workspaceId, string $from, string $to): array
    {
        $revenue = $this->getRevenue($workspaceId, $from, $to);
        $orders  = $this->getOrdersCount($workspaceId, $from, $to);

        $adRow = AdInsight::withoutGlobalScopes()
            ->where('workspace_id', $workspaceId)
            ->where('level', 'campaign')
            ->whereNull('hour')
            ->whereBetween('date', [$from, $to])
            ->selectRaw('
                COALESCE(SUM(spend_in_reporting_currency), 0) AS total_spend,
                COALESCE(SUM(impressions), 0)                 AS total_impressions,
                COALESCE(SUM(clicks), 0)                      AS total_clicks
            ')
            ->first();

        return $this->buildMetricsArray(
            $revenue,
            (float) ($adRow->total_spend ?? 0),
            (int)   ($adRow->total_impressions ?? 0),
            (int)   ($adRow->total_clicks ?? 0),
            $orders,
        );
    }

    /**
     * Per-platform metrics (filtered to specific ad account IDs).
     * Revenue is still workspace-level — no FK exists between stores and ad_accounts.
     */
    private function computePlatformMetrics(int $workspaceId, array $adAccountIds, string $from, string $to): array
    {
        $revenue = $this->getRevenue($workspaceId, $from, $to);
        $orders  = $this->getOrdersCount($workspaceId, $from, $to);

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

        return $this->buildMetricsArray(
            $revenue,
            (float) ($adRow->total_spend ?? 0),
            (int)   ($adRow->total_impressions ?? 0),
            (int)   ($adRow->total_clicks ?? 0),
            $orders,
        );
    }

    private function getRevenue(int $workspaceId, string $from, string $to): float
    {
        return (float) (DailySnapshot::withoutGlobalScopes()
            ->where('workspace_id', $workspaceId)
            ->whereBetween('date', [$from, $to])
            ->sum('revenue') ?? 0);
    }

    private function getOrdersCount(int $workspaceId, string $from, string $to): int
    {
        return (int) (DailySnapshot::withoutGlobalScopes()
            ->where('workspace_id', $workspaceId)
            ->whereBetween('date', [$from, $to])
            ->sum('orders_count') ?? 0);
    }

    /** @return array{roas:float|null,cpo:float|null,spend:float|null,revenue:float|null,impressions:int,clicks:int,ctr:float|null,cpc:float|null} */
    private function buildMetricsArray(float $revenue, float $spend, int $impressions, int $clicks, int $orders = 0): array
    {
        return [
            'roas'        => ($spend > 0 && $revenue > 0) ? round($revenue / $spend, 2) : null,
            'cpo'         => ($spend > 0 && $orders > 0) ? round($spend / $orders, 2) : null,
            'spend'       => $spend > 0 ? $spend : null,
            'revenue'     => $revenue > 0 ? $revenue : null,
            'impressions' => $impressions,
            'clicks'      => $clicks,
            'ctr'         => $impressions > 0 ? round(($clicks / $impressions) * 100, 2) : null,
            'cpc'         => $clicks > 0 ? round($spend / $clicks, 4) : null,
        ];
    }

    /**
     * Per-platform spend/impressions/clicks breakdown for the index page.
     *
     * @return array<string, array{spend:float|null,impressions:int,clicks:int,ctr:float|null,cpc:float|null}>
     */
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
                'cpc'         => $clicks > 0 ? round($spend / $clicks, 4) : null,
            ];
        }

        return $breakdown;
    }

    /**
     * Campaign-level aggregation for per-platform pages.
     * Shows spend, impressions, clicks from ad_insights.
     * Also computes UTM-attributed real_roas, real_cpo, attributed_revenue, attributed_orders
     * by matching orders.utm_campaign (case-insensitive) to campaign name.
     *
     * @param  string  $platform  'facebook' or 'google' — used for UTM source filter
     * @return array<int, array{id:int,external_id:string,name:string,status:string|null,spend:float,impressions:int,clicks:int,ctr:float|null,cpc:float|null,platform_roas:float|null,real_roas:float|null,real_cpo:float|null,attributed_revenue:float|null,attributed_orders:int}>
     */
    private function computeCampaignTable(
        int $workspaceId,
        array $adAccountIds,
        string $from,
        string $to,
        string $platform = '',
    ): array {
        $placeholders = implode(',', array_fill(0, count($adAccountIds), '?'));

        $rows = DB::select("
            SELECT
                c.id,
                c.external_id,
                c.name,
                c.status,
                COALESCE(SUM(ai.spend_in_reporting_currency), 0) AS total_spend,
                COALESCE(SUM(ai.impressions), 0)                 AS total_impressions,
                COALESCE(SUM(ai.clicks), 0)                      AS total_clicks,
                AVG(ai.platform_roas)                            AS avg_platform_roas
            FROM ad_insights ai
            JOIN campaigns c ON c.id = ai.campaign_id
            WHERE ai.workspace_id = ?
              AND ai.ad_account_id IN ({$placeholders})
              AND ai.level = 'campaign'
              AND ai.hour IS NULL
              AND ai.date BETWEEN ? AND ?
            GROUP BY c.id, c.external_id, c.name, c.status
            ORDER BY total_spend DESC
        ", array_merge([$workspaceId], $adAccountIds, [$from, $to]));

        // Build UTM attribution map: lowercase campaign name → {revenue, orders}
        $utmAttribution = $this->buildUtmAttributionMap($workspaceId, $from, $to, $platform);

        return array_map(function (object $row) use ($utmAttribution): array {
            $spend       = (float) $row->total_spend;
            $impressions = (int)   $row->total_impressions;
            $clicks      = (int)   $row->total_clicks;

            $campaignKey         = mb_strtolower((string) ($row->name ?? ''));
            $attr                = $utmAttribution[$campaignKey] ?? null;
            $attributedRevenue   = $attr !== null ? (float) $attr['revenue'] : null;
            $attributedOrders    = $attr !== null ? (int)   $attr['orders']  : 0;

            return [
                'id'                  => (int)    $row->id,
                'external_id'         => (string) $row->external_id,
                'name'                => (string) ($row->name ?? ''),
                'status'              => $row->status,
                'spend'               => $spend,
                'impressions'         => $impressions,
                'clicks'              => $clicks,
                'ctr'                 => $impressions > 0 ? round(($clicks / $impressions) * 100, 2) : null,
                'cpc'                 => $clicks > 0 ? round($spend / $clicks, 4) : null,
                'platform_roas'       => $row->avg_platform_roas !== null
                    ? round((float) $row->avg_platform_roas, 2)
                    : null,
                'real_roas'           => ($spend > 0 && $attributedRevenue !== null && $attributedRevenue > 0)
                    ? round($attributedRevenue / $spend, 2)
                    : null,
                'real_cpo'            => ($spend > 0 && $attributedOrders > 0)
                    ? round($spend / $attributedOrders, 2)
                    : null,
                'attributed_revenue'  => $attributedRevenue,
                'attributed_orders'   => $attributedOrders,
            ];
        }, $rows);
    }

    /**
     * Build a map of lowercase campaign name → {revenue, orders} from UTM-tagged orders.
     *
     * Matches orders.utm_campaign (case-insensitive) to campaign names.
     * UTM source filter: facebook→('facebook','fb','ig','instagram'), google→('google','cpc','google-ads','ppc').
     * Only completed/processing orders with a non-null total_in_reporting_currency are included.
     *
     * @return array<string, array{revenue:float,orders:int}>
     */
    private function buildUtmAttributionMap(
        int $workspaceId,
        string $from,
        string $to,
        string $platform,
    ): array {
        if ($platform === '') {
            // Blended: match both platforms
            $sourceFilter = "AND LOWER(o.utm_source) IN ('facebook','fb','ig','instagram','google','cpc','google-ads','ppc')";
        } elseif ($platform === 'facebook') {
            $sourceFilter = "AND LOWER(o.utm_source) IN ('facebook','fb','ig','instagram')";
        } else {
            $sourceFilter = "AND LOWER(o.utm_source) IN ('google','cpc','google-ads','ppc')";
        }

        $rows = DB::select("
            SELECT
                LOWER(o.utm_campaign)                   AS campaign_key,
                SUM(o.total_in_reporting_currency)      AS attributed_revenue,
                COUNT(o.id)                             AS attributed_orders
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

    /**
     * Daily spend chart broken down by platform for the index page.
     * Returns [{date, facebook, google}, ...].
     *
     * @return array<int, array{date:string,facebook:float,google:float}>
     */
    private function buildSpendChart(int $workspaceId, string $from, string $to, string $granularity): array
    {
        $dateExpr = match ($granularity) {
            'weekly' => "DATE_TRUNC('week', ai.date)::date::text",
            default  => 'ai.date::text',
        };

        $rows = DB::select("
            SELECT
                {$dateExpr} AS date,
                aa.platform,
                COALESCE(SUM(ai.spend_in_reporting_currency), 0) AS spend
            FROM ad_insights ai
            JOIN ad_accounts aa ON aa.id = ai.ad_account_id
            WHERE ai.workspace_id = ?
              AND ai.level = 'campaign'
              AND ai.hour IS NULL
              AND ai.date BETWEEN ? AND ?
            GROUP BY {$dateExpr}, aa.platform
            ORDER BY date
        ", [$workspaceId, $from, $to]);

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

    /**
     * Daily spend chart for a single platform (simple value series).
     *
     * @param  int[]  $adAccountIds
     * @return array<int, array{date:string,value:float}>
     */
    private function buildPlatformSpendChart(
        int $workspaceId,
        array $adAccountIds,
        string $from,
        string $to,
        string $granularity,
    ): array {
        $dateExpr = match ($granularity) {
            'weekly' => "DATE_TRUNC('week', date)::date::text",
            default  => 'date::text',
        };

        return AdInsight::withoutGlobalScopes()
            ->where('workspace_id', $workspaceId)
            ->whereIn('ad_account_id', $adAccountIds)
            ->where('level', 'campaign')
            ->whereNull('hour')
            ->whereBetween('date', [$from, $to])
            ->selectRaw("{$dateExpr} AS date, COALESCE(SUM(spend_in_reporting_currency), 0) AS value")
            ->groupByRaw($dateExpr)
            ->orderByRaw($dateExpr)
            ->get()
            ->map(fn ($r) => ['date' => $r->date, 'value' => (float) $r->value])
            ->all();
    }
}
