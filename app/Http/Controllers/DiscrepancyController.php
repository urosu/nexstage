<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Store;
use App\Services\RevenueAttributionService;
use App\Services\WorkspaceContext;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Discrepancy page — Platform vs Real investigation tool.
 *
 * Answers: "Where do my ad platforms disagree with my store data, and how much
 * revenue is at stake?"
 *
 * Destination of every "Why this number?" click on a ROAS metric.
 * No Winners/Losers — investigation tool, not ranking.
 *
 * Platform-reported revenue comes from ad_insights.platform_conversions_value.
 * Store-attributed revenue comes from orders.attribution_last_touch via
 * RevenueAttributionService::getCampaignAttributedRevenue().
 *
 * @see PLANNING.md section 12.5 "/analytics/discrepancy"
 */
class DiscrepancyController extends Controller
{
    public function __construct(
        private readonly RevenueAttributionService $attribution,
    ) {}

    public function __invoke(Request $request): Response
    {
        $workspaceId = app(WorkspaceContext::class)->id();

        $validated = $request->validate([
            'from'      => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'to'        => ['sometimes', 'nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'platform'  => ['sometimes', 'nullable', 'in:all,facebook,google'],
        ]);

        $from     = $validated['from']     ?? now()->subDays(29)->toDateString();
        $to       = $validated['to']       ?? now()->toDateString();
        $platform = $validated['platform'] ?? 'all';

        $platformFilter = $platform !== 'all'
            ? 'AND aa.platform = ' . DB::connection()->getPdo()->quote($platform)
            : '';

        // ── Per-campaign: platform-reported vs store-attributed ──────────────
        // previous_names included here to avoid a per-campaign sub-query later.
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

        // Enrich with store-attributed revenue via RevenueAttributionService (single batch query)
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
        );

        $campaigns = [];
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

        // ── Daily gap chart ─────────────────────────────────────────────────
        $dailyGap = DB::select("
            SELECT
                ai.date::text                                                  AS date,
                COALESCE(SUM(ai.platform_conversions_value), 0)                AS platform_revenue,
                COALESCE(SUM(ai.spend_in_reporting_currency), 0)               AS spend
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

        // Get daily store-attributed revenue for paid channels
        $dailyStoreRevenue = DB::select("
            SELECT
                o.occurred_at::date::text                                       AS date,
                COALESCE(SUM(o.total_in_reporting_currency), 0)                 AS attributed_revenue
            FROM orders o
            WHERE o.workspace_id = ?
              AND o.status IN ('completed', 'processing')
              AND o.total_in_reporting_currency IS NOT NULL
              AND o.occurred_at BETWEEN ? AND ?
              AND o.attribution_source IN ('pys', 'wc_native')
              AND o.attribution_last_touch->>'channel_type' IN ('paid_social', 'paid_search')
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
            'date'                => $row->date,
            'platform_revenue'    => round((float) $row->platform_revenue, 2),
            'attributed_revenue'  => round($storeRevenueByDate[$row->date] ?? 0, 2),
            'gap'                 => round((float) $row->platform_revenue - ($storeRevenueByDate[$row->date] ?? 0), 2),
        ], $dailyGap);

        // ── Hero ────────────────────────────────────────────────────────────
        $totalDelta    = $totalPlatformRevenue - $totalAttributedRevenue;
        $totalDeltaPct = $totalAttributedRevenue > 0
            ? round(($totalDelta / $totalAttributedRevenue) * 100, 1)
            : null;

        return Inertia::render('Analytics/Discrepancy', [
            'campaigns'  => $campaigns,
            'chart_data' => $chartData,
            'hero'       => [
                'total_spend'             => round($totalSpend, 2),
                'total_platform_revenue'  => round($totalPlatformRevenue, 2),
                'total_attributed_revenue' => round($totalAttributedRevenue, 2),
                'total_delta'             => round($totalDelta, 2),
                'total_delta_pct'         => $totalDeltaPct,
                'platform_roas'           => $totalSpend > 0 ? round($totalPlatformRevenue / $totalSpend, 2) : null,
                'real_roas'               => $totalSpend > 0 ? round($totalAttributedRevenue / $totalSpend, 2) : null,
            ],
            'from'       => $from,
            'to'         => $to,
            'platform'   => $platform,
            // Discrepancy page is absorbed into Acquisition in Phase 3.5; no narrative method yet.
            'narrative'  => null,
        ]);
    }
}
