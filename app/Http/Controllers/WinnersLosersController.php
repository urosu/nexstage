<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\WorkspaceContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class WinnersLosersController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $workspaceId = app(WorkspaceContext::class)->id();

        $validated = $request->validate([
            'from'        => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'to'          => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'sort'        => ['sometimes', 'nullable', 'in:real_roas,real_cpo,spend,attributed_revenue'],
            'direction'   => ['sometimes', 'nullable', 'in:asc,desc'],
        ]);

        $from      = $validated['from']      ?? now()->subDays(29)->toDateString();
        $to        = $validated['to']        ?? now()->toDateString();
        $sort      = $validated['sort']      ?? 'real_roas';
        $direction = $validated['direction'] ?? 'desc';

        $campaigns = $this->computeAllCampaigns($workspaceId, $from, $to);

        // Sort in PHP after computing — avoids NULL-ordering complexity across DBs
        usort($campaigns, function (array $a, array $b) use ($sort, $direction): int {
            $aVal = $a[$sort];
            $bVal = $b[$sort];

            // NULLs always at the bottom regardless of direction
            if ($aVal === null && $bVal === null) return 0;
            if ($aVal === null) return 1;
            if ($bVal === null) return -1;

            $cmp = $aVal <=> $bVal;

            return $direction === 'asc' ? $cmp : -$cmp;
        });

        $topProducts = $this->computeTopProducts($workspaceId, $from, $to);

        return Inertia::render('Advertising/WinnersLosers', [
            'campaigns'    => $campaigns,
            'top_products' => $topProducts,
            'from'         => $from,
            'to'           => $to,
            'sort'         => $sort,
            'direction'    => $direction,
        ]);
    }

    /**
     * All campaigns across all ad accounts (both platforms) with spend + UTM attribution.
     *
     * @return array<int, array{id:int,name:string,platform:string,status:string|null,spend:float,impressions:int,clicks:int,platform_roas:float|null,real_roas:float|null,real_cpo:float|null,attributed_revenue:float|null,attributed_orders:int}>
     */
    private function computeAllCampaigns(int $workspaceId, string $from, string $to): array
    {
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
              AND ai.level = 'campaign'
              AND ai.hour IS NULL
              AND ai.date BETWEEN ? AND ?
            GROUP BY c.id, c.name, aa.platform, c.status
            ORDER BY total_spend DESC
        ", [$workspaceId, $from, $to]);

        // Single attribution query for all platforms at once
        $attrMap = $this->buildUtmAttributionMap($workspaceId, $from, $to);

        return array_map(function (object $row) use ($attrMap): array {
            $spend       = (float) $row->total_spend;
            $impressions = (int)   $row->total_impressions;
            $clicks      = (int)   $row->total_clicks;

            $campaignKey       = mb_strtolower((string) ($row->name ?? ''));
            $attr              = $attrMap[$campaignKey] ?? null;
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
     * UTM attribution map across all ad platforms (facebook + google combined).
     *
     * @return array<string, array{revenue:float,orders:int}>
     */
    private function buildUtmAttributionMap(int $workspaceId, string $from, string $to): array
    {
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
              AND LOWER(o.utm_source) IN ('facebook','fb','ig','instagram','google','cpc','google-ads','ppc')
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
     * Top 20 products by revenue for the date range, aggregated from daily_snapshots.top_products JSONB.
     *
     * @return array<int, array{external_id:string,name:string,units:int,revenue:float}>
     */
    private function computeTopProducts(int $workspaceId, string $from, string $to): array
    {
        $rows = DB::select("
            SELECT
                p->>'external_id'          AS external_id,
                p->>'name'                 AS name,
                SUM((p->>'units')::int)    AS total_units,
                SUM((p->>'revenue')::numeric) AS total_revenue
            FROM daily_snapshots,
                 jsonb_array_elements(top_products) AS p
            WHERE workspace_id = ?
              AND date BETWEEN ? AND ?
              AND top_products IS NOT NULL
            GROUP BY p->>'external_id', p->>'name'
            ORDER BY total_revenue DESC
            LIMIT 20
        ", [$workspaceId, $from, $to]);

        return array_map(fn (object $r) => [
            'external_id' => (string) $r->external_id,
            'name'        => (string) $r->name,
            'units'       => (int)    $r->total_units,
            'revenue'     => round((float) $r->total_revenue, 2),
        ], $rows);
    }
}
