<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Store;
use App\Models\Workspace;
use App\Services\WorkspaceContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Countries analytics page — rewritten for Phase 1.6.
 *
 * Side-by-side integration columns: store orders + revenue, GSC organic clicks,
 * Facebook spend, Google spend, Real ROAS, contribution margin.
 *
 * @see PLANNING.md section 12.5 "/countries — rewrite (side-by-side)"
 */
class CountriesController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $workspaceId = app(WorkspaceContext::class)->id();
        $workspace   = Workspace::withoutGlobalScopes()->findOrFail($workspaceId);

        $validated = $request->validate([
            'from'       => 'nullable|date',
            'to'         => 'nullable|date',
            'country'    => 'nullable|string|size:2|alpha',
            'store_ids'  => 'nullable|string',
            'sort_by'    => 'nullable|in:revenue,orders,country_name,real_roas,real_profit,fb_spend,google_spend,gsc_clicks',
            'sort_dir'   => 'nullable|in:asc,desc',
            'filter'     => 'nullable|in:all,winners,losers',
            'classifier' => 'nullable|in:peer,period',
        ]);

        $from       = $validated['from']       ?? now()->subDays(29)->toDateString();
        $to         = $validated['to']         ?? now()->toDateString();
        $country    = isset($validated['country']) ? strtoupper($validated['country']) : null;
        $sortBy     = $validated['sort_by']    ?? 'revenue';
        $sortDir    = $validated['sort_dir']   ?? 'desc';
        $filter     = $validated['filter']     ?? 'all';
        $classifier = $validated['classifier'] ?? null;
        $storeIds   = $this->parseStoreIds($validated['store_ids'] ?? '', $workspaceId);

        $storeClause = ! empty($storeIds)
            ? 'AND o.store_id IN (' . implode(',', array_map('intval', $storeIds)) . ')'
            : '';

        // ── Store revenue + orders by country ────────────────────────────────
        $orderRows = DB::select("
            SELECT
                o.shipping_country                   AS country_code,
                COUNT(*)                             AS orders,
                SUM(o.total_in_reporting_currency)   AS revenue
            FROM orders o
            WHERE o.workspace_id = ?
              AND o.occurred_at::date BETWEEN ? AND ?
              AND o.status IN ('completed', 'processing')
              AND o.shipping_country IS NOT NULL
              AND o.total_in_reporting_currency IS NOT NULL
              {$storeClause}
            GROUP BY o.shipping_country
        ", [$workspaceId, $from, $to]);

        $byCountry = [];
        $totalRevenue = 0.0;
        foreach ($orderRows as $r) {
            $code = strtoupper((string) $r->country_code);
            $rev  = (float) $r->revenue;
            $byCountry[$code] = [
                'orders'  => (int) $r->orders,
                'revenue' => $rev,
            ];
            $totalRevenue += $rev;
        }

        // ── COGS / contribution margin by country ────────────────────────────
        $cogsStoreClause = ! empty($storeIds)
            ? 'AND o.store_id IN (' . implode(',', array_map('intval', $storeIds)) . ')'
            : '';
        $cogsRows = DB::select("
            SELECT
                o.shipping_country                         AS country_code,
                SUM(oi.unit_cost * oi.quantity)            AS total_cogs
            FROM order_items oi
            JOIN orders o ON o.id = oi.order_id
            WHERE o.workspace_id = ?
              AND o.status IN ('completed', 'processing')
              AND o.occurred_at BETWEEN ? AND ?
              AND o.shipping_country IS NOT NULL
              AND oi.unit_cost IS NOT NULL
              AND oi.unit_cost > 0
              {$cogsStoreClause}
            GROUP BY o.shipping_country
        ", [$workspaceId, $from . ' 00:00:00', $to . ' 23:59:59']);

        $cogsMap = [];
        foreach ($cogsRows as $r) {
            $cogsMap[strtoupper((string) $r->country_code)] = (float) $r->total_cogs;
        }

        // ── Ad spend by country (three-tier fallback) ────────────────────────
        // COALESCE(campaigns.parsed_convention->>'country', stores.primary_country_code, 'UNKNOWN')
        // @see PLANNING.md section 5.7
        $adSpendRows = DB::select("
            SELECT
                UPPER(COALESCE(
                    c.parsed_convention->>'country',
                    s.primary_country_code,
                    'UNKNOWN'
                )) AS country_code,
                aa.platform,
                SUM(ai.spend_in_reporting_currency) AS spend
            FROM ad_insights ai
            JOIN campaigns c    ON c.id  = ai.campaign_id
            JOIN ad_accounts aa ON aa.id = ai.ad_account_id
            LEFT JOIN stores s  ON s.workspace_id = ai.workspace_id AND s.id = (
                SELECT id FROM stores WHERE workspace_id = ai.workspace_id ORDER BY id LIMIT 1
            )
            WHERE ai.workspace_id = ?
              AND ai.level = 'campaign'
              AND ai.hour IS NULL
              AND ai.date BETWEEN ? AND ?
            GROUP BY 1, aa.platform
        ", [$workspaceId, $from, $to]);

        $fbSpendMap     = [];
        $googleSpendMap = [];
        foreach ($adSpendRows as $r) {
            $code  = (string) $r->country_code;
            $spend = (float) $r->spend;
            if ($r->platform === 'facebook') {
                $fbSpendMap[$code] = ($fbSpendMap[$code] ?? 0) + $spend;
            } else {
                $googleSpendMap[$code] = ($googleSpendMap[$code] ?? 0) + $spend;
            }
        }

        // ── GSC clicks by country ────────────────────────────────────────────
        $gscRows = DB::select("
            SELECT
                UPPER(gds.country) AS country_code,
                SUM(gds.clicks)    AS clicks
            FROM gsc_daily_stats gds
            WHERE gds.workspace_id = ?
              AND gds.date BETWEEN ? AND ?
              AND gds.country <> 'ZZ'
              AND gds.device = 'all'
            GROUP BY UPPER(gds.country)
        ", [$workspaceId, $from, $to]);

        $gscMap = [];
        foreach ($gscRows as $r) {
            $gscMap[(string) $r->country_code] = (int) $r->clicks;
        }

        // ── Assemble country rows ────────────────────────────────────────────
        // Merge all country codes from all sources
        $allCodes = array_unique(array_merge(
            array_keys($byCountry),
            array_keys($fbSpendMap),
            array_keys($googleSpendMap),
            array_keys($gscMap),
        ));

        $hasAds    = ! empty($fbSpendMap) || ! empty($googleSpendMap);
        $countries = [];
        foreach ($allCodes as $code) {
            $orders  = $byCountry[$code]['orders']  ?? 0;
            $revenue = $byCountry[$code]['revenue'] ?? 0.0;
            $cogs    = $cogsMap[$code] ?? null;
            $margin  = $cogs !== null ? $revenue - $cogs : null;
            $fb      = $fbSpendMap[$code] ?? null;
            $google  = $googleSpendMap[$code] ?? null;
            $gsc     = $gscMap[$code] ?? null;
            $totalAd = ($fb ?? 0) + ($google ?? 0);
            $realRoas = ($totalAd > 0 && $revenue > 0) ? round($revenue / $totalAd, 2) : null;
            $realProfit = $margin !== null && $totalAd > 0 ? round($margin - $totalAd, 2) : null;

            $countries[] = [
                'country_code'        => $code,
                'orders'              => $orders,
                'revenue'             => round($revenue, 2),
                'share'               => $totalRevenue > 0 ? round(($revenue / $totalRevenue) * 100, 1) : 0.0,
                'gsc_clicks'          => $gsc,
                'fb_spend'            => $fb !== null && $fb > 0 ? round($fb, 2) : null,
                'google_spend'        => $google !== null && $google > 0 ? round($google, 2) : null,
                'real_roas'           => $realRoas,
                'contribution_margin' => $margin !== null ? round($margin, 2) : null,
                'real_profit'         => $realProfit,
            ];
        }

        // ── Sort ─────────────────────────────────────────────────────────────
        $sortDirUpper = strtoupper($sortDir);
        usort($countries, function (array $a, array $b) use ($sortBy, $sortDirUpper): int {
            if ($sortBy === 'country_name') {
                $cmp = strcmp($a['country_code'], $b['country_code']);
                return $sortDirUpper === 'ASC' ? $cmp : -$cmp;
            }
            $aVal = $a[$sortBy] ?? null;
            $bVal = $b[$sortBy] ?? null;
            if ($aVal === null && $bVal === null) return 0;
            if ($aVal === null) return 1;
            if ($bVal === null) return -1;
            $cmp = $aVal <=> $bVal;
            return $sortDirUpper === 'ASC' ? $cmp : -$cmp;
        });

        // ── Hero metrics ─────────────────────────────────────────────────────
        $countriesWithOrders = array_filter($countries, fn ($c) => $c['orders'] > 0);
        $topCountryShare     = ! empty($countries) ? max(array_column($countries, 'share')) : 0;
        $countriesAboveAvgMargin = 0;
        $profitableRoasCountries = 0;

        if (count($countriesWithOrders) > 0) {
            $margined = array_filter($countriesWithOrders, fn ($c) => $c['contribution_margin'] !== null);
            if (count($margined) > 0) {
                $avgMargin = array_sum(array_column($margined, 'contribution_margin')) / count($margined);
                $countriesAboveAvgMargin = count(array_filter($margined, fn ($c) => $c['contribution_margin'] >= $avgMargin));
            }
            $profitableRoasCountries = count(array_filter($countriesWithOrders, fn ($c) =>
                $c['real_roas'] !== null && $c['real_roas'] >= 1.0
            ));
        }

        // ── Winners / Losers — peer avg Real ROAS, min ≥20 orders ────────────
        $effectiveClassifier = in_array($classifier, ['peer', 'period'], true) ? $classifier : 'peer';
        $rankable = array_filter($countries, fn ($c) => $c['real_roas'] !== null && $c['orders'] >= 20);
        $peerAvgRoas = count($rankable) > 0
            ? array_sum(array_column($rankable, 'real_roas')) / count($rankable)
            : null;

        $countries = array_map(function (array $c) use ($effectiveClassifier, $peerAvgRoas): array {
            if ($c['orders'] < 20 || $c['real_roas'] === null) {
                return array_merge($c, ['wl_tag' => null]);
            }
            $tag = match ($effectiveClassifier) {
                'peer' => ($peerAvgRoas !== null)
                    ? ($c['real_roas'] >= $peerAvgRoas ? 'winner' : 'loser')
                    : null,
                default => null,
            };
            return array_merge($c, ['wl_tag' => $tag]);
        }, $countries);

        $totalCount = count($countries);

        if ($filter !== 'all') {
            $filterTag  = rtrim($filter, 's');
            $countries  = array_values(
                array_filter($countries, fn (array $c) => $c['wl_tag'] === $filterTag),
            );
        }

        // ── Drill-down: top products for selected country ────────────────────
        $topProducts = [];
        if ($country !== null && $country !== '') {
            $topStoreClause = ! empty($storeIds)
                ? 'AND o.store_id IN (' . implode(',', array_map('intval', $storeIds)) . ')'
                : '';
            $topProducts = DB::select(
                "WITH ranked AS (
                    SELECT
                        oi.product_external_id,
                        MAX(oi.product_name)  AS product_name,
                        SUM(oi.quantity)      AS units,
                        SUM(
                            CASE
                                WHEN (o.total - o.tax - o.shipping + o.discount) > 0
                                THEN oi.line_total
                                     / (o.total - o.tax - o.shipping + o.discount)
                                     * o.total_in_reporting_currency
                                ELSE NULL
                            END
                        ) AS revenue
                    FROM orders o
                    JOIN order_items oi ON oi.order_id = o.id
                    WHERE o.workspace_id = ?
                      AND o.shipping_country = ?
                      AND o.occurred_at::date BETWEEN ? AND ?
                      AND o.status IN ('completed', 'processing')
                      AND o.total_in_reporting_currency IS NOT NULL
                      {$topStoreClause}
                    GROUP BY oi.product_external_id
                    ORDER BY revenue DESC NULLS LAST
                    LIMIT 10
                )
                SELECT r.*, (
                    SELECT pr.image_url
                    FROM products pr
                    WHERE pr.workspace_id = ?
                      AND pr.external_id = r.product_external_id
                    LIMIT 1
                ) AS image_url
                FROM ranked r
                ORDER BY r.revenue DESC NULLS LAST",
                [$workspaceId, $country, $from, $to, $workspaceId],
            );

            $topProducts = array_map(fn ($p) => [
                'product_external_id' => $p->product_external_id,
                'product_name'        => $p->product_name,
                'units'               => (int) $p->units,
                'revenue'             => $p->revenue !== null ? (float) $p->revenue : null,
                'image_url'           => $p->image_url,
            ], $topProducts);
        }

        return Inertia::render('Countries', [
            'countries'              => $countries,
            'countries_total_count'  => $totalCount,
            'has_ads'                => $hasAds,
            'hero'                   => [
                'countries_with_orders'    => count($countriesWithOrders),
                'top_country_share'        => $topCountryShare,
                'countries_above_avg_margin' => $countriesAboveAvgMargin,
                'profitable_roas_countries'  => $profitableRoasCountries,
            ],
            'top_products'           => $topProducts,
            'selected_country'       => $country,
            'from'                   => $from,
            'to'                     => $to,
            'store_ids'              => $storeIds,
            'sort_by'                => $sortBy,
            'sort_dir'               => $sortDir,
            'filter'                 => $filter,
            'classifier'             => $classifier,
            'active_classifier'      => $effectiveClassifier,
        ]);
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
