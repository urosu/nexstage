<?php

declare(strict_types=1);

namespace App\Services;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Computes attribution-based revenue breakdowns from orders.
 *
 * After Phase 1.5 Step 14 cutover, all reads come from the attribution_*
 * columns written by AttributionParserService + ChannelClassifierService.
 * The old utm_source / utm_campaign columns are no longer the source of truth here.
 *
 * "Tagged" orders = attribution_source IN ('pys', 'wc_native') — orders where an
 * explicit UTM-tagged URL was tracked. Referrer-heuristic orders (source='referrer')
 * and untracked orders (source='none') are excluded from all tagged buckets.
 *
 * Channel bucketing uses attribution_last_touch->>'channel_type':
 *   paid_social  → facebook bucket  (Meta Ads: Facebook, Instagram)
 *   paid_search  → google bucket    (Google Ads)
 *   anything else (email, organic_social, affiliate, sms, other, unrecognized) → other_tagged
 *
 * Consumed by: DashboardController, CampaignsController, SeoController,
 *              ComputeUtmCoverageJob.
 *
 * All returned values are in the workspace's reporting currency
 * (uses orders.total_in_reporting_currency, pre-converted at sync time).
 *
 * Orders with NULL total_in_reporting_currency are excluded — they failed FX
 * conversion and will be corrected by RetryMissingConversionJob.
 *
 * Only 'completed' and 'processing' orders are included (same scope as AOV/revenue).
 *
 * @see PLANNING.md section 6 (attribution parser + Step 14 cutover)
 * @see PLANNING.md "Business Logic to Preserve" → Formulas
 */
class RevenueAttributionService
{
    /**
     * Return UTM-attributed revenue broken down by channel for the given period.
     *
     * Return shape:
     * [
     *   'facebook'     => float,   // orders with channel_type = 'paid_social'
     *   'google'       => float,   // orders with channel_type = 'paid_search'
     *   'other_tagged' => float,   // explicitly tagged orders not in either paid bucket
     *   'total_tagged' => float,   // facebook + google + other_tagged
     * ]
     *
     * @param int             $workspaceId
     * @param CarbonInterface $from        Inclusive start (occurred_at)
     * @param CarbonInterface $to          Inclusive end (occurred_at)
     * @param int|null        $storeId     Optional: filter to a single store
     */
    public function getAttributedRevenue(
        int $workspaceId,
        CarbonInterface $from,
        CarbonInterface $to,
        ?int $storeId = null,
    ): array {
        $sql = <<<SQL
            SELECT
                SUM(
                    CASE WHEN attribution_source IN ('pys', 'wc_native')
                              AND attribution_last_touch->>'channel_type' = 'paid_social'
                         THEN total_in_reporting_currency ELSE 0 END
                ) AS facebook,
                SUM(
                    CASE WHEN attribution_source IN ('pys', 'wc_native')
                              AND attribution_last_touch->>'channel_type' = 'paid_search'
                         THEN total_in_reporting_currency ELSE 0 END
                ) AS google,
                SUM(
                    CASE WHEN attribution_source IN ('pys', 'wc_native')
                              AND (
                                  attribution_last_touch IS NULL
                                  OR attribution_last_touch->>'channel_type' IS NULL
                                  OR attribution_last_touch->>'channel_type' NOT IN ('paid_social', 'paid_search')
                              )
                         THEN total_in_reporting_currency ELSE 0 END
                ) AS other_tagged
            FROM orders
            WHERE workspace_id = ?
              AND status IN ('completed', 'processing')
              AND total_in_reporting_currency IS NOT NULL
              AND occurred_at BETWEEN ? AND ?
        SQL;

        $bindings = [
            $workspaceId,
            $from->toDateTimeString(),
            $to->toDateTimeString(),
        ];

        if ($storeId !== null) {
            $sql      .= ' AND store_id = ?';
            $bindings[] = $storeId;
        }

        $row = DB::selectOne($sql, $bindings);

        $facebook    = (float) ($row->facebook    ?? 0);
        $google      = (float) ($row->google      ?? 0);
        $otherTagged = (float) ($row->other_tagged ?? 0);

        return [
            'facebook'     => $facebook,
            'google'       => $google,
            'other_tagged' => $otherTagged,
            'total_tagged' => $facebook + $google + $otherTagged,
        ];
    }

    /**
     * Return revenue attributed to a specific campaign (case-insensitive name/external_id match).
     *
     * Used for Real ROAS calculation: attributed_revenue / campaign_spend.
     *
     * Reads from attribution_last_touch->>'campaign' — the campaign identifier written
     * by the attribution parser (UTM campaign value, typically the platform campaign ID or name).
     *
     * Facebook writes its numeric campaign ID (e.g. "120241558531060383") into utm_campaign, not
     * the human-readable name. We therefore match on externalId first, then fall back to name/
     * previous_names. This mirrors CampaignsController::buildAttributionMap.
     *
     * The $previousNames parameter handles campaign renames: if a campaign was called "Black Friday"
     * last month and is now "Q4 Retargeting", passing the old name(s) here ensures historical orders
     * that still carry the old UTM campaign value are included in the attribution total.
     * Populated from campaigns.previous_names JSONB column; callers that don't need this pass [].
     *
     * @see PLANNING.md "Business Logic to Preserve" → Real ROAS per campaign
     *
     * @param int             $workspaceId
     * @param string          $campaignName   Value from campaigns.name (case-insensitive match)
     * @param CarbonInterface $from
     * @param CarbonInterface $to
     * @param int|null        $storeId
     * @param string[]        $previousNames  Former campaign names to also match (rename fallback)
     * @param string|null     $externalId     campaigns.external_id — the numeric ID platforms write
     *                                        into utm_campaign (primary match for Facebook/Google)
     */
    public function getCampaignAttributedRevenue(
        int $workspaceId,
        string $campaignName,
        CarbonInterface $from,
        CarbonInterface $to,
        ?int $storeId = null,
        array $previousNames = [],
        ?string $externalId = null,
    ): float {
        $query = DB::table('orders')
            ->where('workspace_id', $workspaceId)
            ->whereIn('status', ['completed', 'processing'])
            ->whereNotNull('total_in_reporting_currency')
            ->whereBetween('occurred_at', [
                $from->toDateTimeString(),
                $to->toDateTimeString(),
            ]);

        // Match current name plus any previous names so renamed campaigns retain historical attribution.
        $allNames = array_values(array_unique(array_filter(
            array_merge([$campaignName], $previousNames),
            fn (string $n) => $n !== '',
        )));

        // Platforms typically write the numeric campaign ID into utm_campaign (primary key).
        // Name matching is the fallback for campaigns that use descriptive UTM values.
        // Using per-binding LOWER(?) instead of array literals to avoid addslashes / quoting issues.
        $query->where(function ($q) use ($allNames, $externalId) {
            $q->where(function ($inner) use ($allNames) {
                foreach ($allNames as $name) {
                    $inner->orWhereRaw("LOWER(attribution_last_touch->>'campaign') = LOWER(?)", [$name]);
                }
            });

            if ($externalId !== null && $externalId !== '') {
                $q->orWhereRaw("attribution_last_touch->>'campaign' = ?", [$externalId]);
            }
        });

        if ($storeId !== null) {
            $query->where('store_id', $storeId);
        }

        return (float) $query->sum('total_in_reporting_currency');
    }

    /**
     * Batch variant of getCampaignAttributedRevenue — resolves revenues for many campaigns
     * in a SINGLE query instead of one per campaign.
     *
     * Fetches all distinct (campaign_key_lower → revenue) pairs for the date window,
     * then maps each campaign's names / external_id to the pre-aggregated result in PHP.
     *
     * @param  array<int, array{id: int, name: string, previous_names: string[], external_id: string|null}> $campaigns
     * @return array<int, float>  Keyed by campaign id
     */
    public function batchGetCampaignAttributedRevenues(
        int $workspaceId,
        array $campaigns,
        CarbonInterface $from,
        CarbonInterface $to,
        string $attrColumn = 'attribution_last_touch',
    ): array {
        // One query: aggregate by the raw campaign key stored in the chosen attribution column.
        // $attrColumn is 'attribution_last_touch' (default) or 'attribution_first_touch' when
        // the caller is using first-touch attribution model.
        $rows = DB::table('orders')
            ->where('workspace_id', $workspaceId)
            ->whereIn('status', ['completed', 'processing'])
            ->whereNotNull('total_in_reporting_currency')
            ->whereNotNull(DB::raw("{$attrColumn}->>'campaign'"))
            ->whereBetween('occurred_at', [$from->toDateTimeString(), $to->toDateTimeString()])
            ->selectRaw(
                "LOWER({$attrColumn}->>'campaign') AS campaign_key, SUM(total_in_reporting_currency) AS revenue",
            )
            ->groupBy(DB::raw("LOWER({$attrColumn}->>'campaign')"))
            ->pluck('revenue', 'campaign_key');

        $result = [];

        foreach ($campaigns as $campaign) {
            $allNames = array_values(array_unique(array_filter(
                array_merge([$campaign['name']], $campaign['previous_names']),
                fn (string $n) => $n !== '',
            )));

            $revenue = 0.0;
            foreach ($allNames as $name) {
                $revenue += (float) ($rows->get(strtolower($name)) ?? 0);
            }

            // External ID is an exact (case-sensitive) match; cast to lower for the lookup key
            if ($campaign['external_id'] !== null && $campaign['external_id'] !== '') {
                $revenue += (float) ($rows->get(strtolower($campaign['external_id'])) ?? 0);
            }

            $result[$campaign['id']] = $revenue;
        }

        return $result;
    }

    /**
     * Return utm_source values in the "other_tagged" bucket that have no channel mapping.
     *
     * "Unrecognized" means: the order has explicit UTM attribution (source IN pys/wc_native)
     * but no channel_mappings row matched the source+medium pair — indicated by the absence
     * of a 'channel' key in the attribution_last_touch JSONB (ChannelClassifierService only
     * writes channel/channel_type when channel_name is non-null).
     *
     * Used by ComputeUtmCoverageJob to populate workspaces.utm_unrecognized_sources.
     * Surfaced on the Dashboard near attribution metrics to help users fix mistagged URLs.
     *
     * Return shape: array of ['source' => string, 'order_count' => int, 'revenue_pct' => float]
     * ordered by order_count DESC. Empty array when no unrecognized sources exist.
     *
     * @see PLANNING.md "UTM Coverage Health Check + Tag Generator" — unrecognized sources
     */
    public function getUnrecognizedSources(
        int $workspaceId,
        string $from,
        string $to,
        ?int $storeId = null,
    ): array {
        // Why: exclude NULL total_in_reporting_currency for consistency with getAttributedRevenue.
        // Orders with NULL FX conversion are pending RetryMissingConversionJob; their
        // revenue is unknown so they should not inflate order_count without revenue contribution.
        $sql = <<<SQL
            SELECT
                attribution_last_touch->>'source'                         AS source,
                COUNT(*)                                                  AS order_count,
                ROUND(
                    COALESCE(SUM(total_in_reporting_currency), 0) * 100.0
                    / NULLIF(SUM(SUM(total_in_reporting_currency)) OVER (), 0),
                    2
                )                                                         AS revenue_pct
            FROM orders
            WHERE workspace_id = ?
              AND status IN ('completed', 'processing')
              AND total_in_reporting_currency IS NOT NULL
              AND attribution_source IN ('pys', 'wc_native')
              AND attribution_last_touch IS NOT NULL
              AND attribution_last_touch->>'source' IS NOT NULL
              AND attribution_last_touch->>'channel' IS NULL
              AND occurred_at BETWEEN ? AND ?
        SQL;

        $bindings = [
            $workspaceId,
            $from . ' 00:00:00',
            $to . ' 23:59:59',
        ];

        if ($storeId !== null) {
            $sql      .= ' AND store_id = ?';
            $bindings[] = $storeId;
        }

        $sql .= " GROUP BY attribution_last_touch->>'source' ORDER BY order_count DESC LIMIT 10";

        $rows = DB::select($sql, $bindings);

        return array_map(fn ($row) => [
            'source'      => (string) $row->source,
            'order_count' => (int) $row->order_count,
            'revenue_pct' => (float) $row->revenue_pct,
        ], $rows);
    }

    /**
     * Compute unattributed ("Not Tracked") revenue = totalRevenue - total_tagged.
     *
     * Intentionally NOT clamped to 0 — a negative value means platforms over-reported
     * conversions relative to actual store revenue (iOS14 pixel inflation scenario).
     * The Dashboard "Not Tracked" banner surfaces this signed value so users can see
     * the discrepancy. See: PLANNING.md trust thesis.
     *
     * Pass the totalRevenue from daily_snapshots (not from orders directly).
     */
    public function getUnattributedRevenue(
        float $totalRevenue,
        float $totalTagged,
    ): float {
        return $totalRevenue - $totalTagged;
    }
}
