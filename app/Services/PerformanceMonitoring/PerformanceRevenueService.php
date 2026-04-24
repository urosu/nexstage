<?php

declare(strict_types=1);

namespace App\Services\PerformanceMonitoring;

use Illuminate\Support\Facades\DB;

/**
 * Revenue-impact queries for the Performance page (Phase 3.4).
 *
 * Connects Lighthouse/CWV data to store revenue by computing:
 *   - §F18 monthly order counts per monitored URL
 *   - §F19 revenue-at-risk from site slowness (CVR degradation × sessions × AOV)
 *
 * Reads from: orders, gsc_pages, search_console_properties
 * Writes to:  nothing
 *
 * @see PLANNING.md section 23 (Performance Monitoring)
 * @see PROGRESS.md §F18, §F19
 */
class PerformanceRevenueService
{
    /**
     * §F18 — Monthly orders attributed to each monitored URL.
     *
     * Counts distinct orders this calendar month whose last-touch landing page
     * matches the URL path (fuzzy ILIKE on attribution_last_touch->>'landing_page').
     *
     * @param  array<int, array{id: int, url: string}> $storeUrls
     * @return array<int, int>  keyed by store_url_id
     */
    public function monthlyOrdersPerUrl(int $workspaceId, array $storeUrls): array
    {
        $result = [];

        foreach ($storeUrls as $su) {
            $path    = $this->urlPath($su['url']);
            $pattern = '%' . $path . '%';

            $count = DB::selectOne(
                "SELECT COUNT(DISTINCT id) AS cnt
                 FROM orders
                 WHERE workspace_id = ?
                   AND status IN ('completed','processing')
                   AND attribution_last_touch->>'landing_page' ILIKE ?
                   AND date_trunc('month', occurred_at AT TIME ZONE 'UTC')
                       = date_trunc('month', NOW() AT TIME ZONE 'UTC')",
                [$workspaceId, $pattern],
            );

            $result[$su['id']] = (int) ($count?->cnt ?? 0);
        }

        return $result;
    }

    /**
     * §F19 — Revenue at risk from site slowness.
     *
     * Per monitored URL: compares 7-day organic CVR against 28-day baseline CVR.
     * CVR = organic orders ÷ GSC clicks to matching landing page (session proxy per §F13).
     * Risk = MAX(0, baseline_cvr − current_cvr) × current_sessions × workspace_aov.
     * Floored at zero: CVR improvements are not negative risk.
     *
     * Returns both the workspace total and a per-URL breakdown.
     *
     * NOTE: gsc_pages.page has no trigram index today; ILIKE is a sequential scan.
     * Add a GIN index (pg_trgm) on gsc_pages.page once store count grows. See PLANNING §23.
     *
     * @param  array<int, array{id: int, url: string}> $storeUrls
     * @return array{total: float, per_url: array<int, float>}
     */
    public function revenueAtRisk(int $workspaceId, array $storeUrls): array
    {
        if (empty($storeUrls)) {
            return ['total' => 0.0, 'per_url' => []];
        }

        // Resolve active GSC property IDs for this workspace.
        $propertyIds = DB::table('search_console_properties')
            ->where('workspace_id', $workspaceId)
            ->where('status', 'active')
            ->pluck('id')
            ->all();

        if (empty($propertyIds)) {
            return ['total' => 0.0, 'per_url' => array_fill_keys(array_column($storeUrls, 'id'), 0.0)];
        }

        // Workspace organic AOV — baseline window (days -35 to -8).
        $aovRow = DB::selectOne(
            "SELECT COALESCE(SUM(total), 0) / NULLIF(COUNT(id), 0) AS aov
             FROM orders
             WHERE workspace_id = ?
               AND status IN ('completed','processing')
               AND attribution_last_touch->>'channel_type' = 'organic_search'
               AND occurred_at BETWEEN NOW() - INTERVAL '35 days' AND NOW() - INTERVAL '8 days'",
            [$workspaceId],
        );

        $aov = (float) ($aovRow?->aov ?? 0.0);

        if ($aov <= 0) {
            return ['total' => 0.0, 'per_url' => array_fill_keys(array_column($storeUrls, 'id'), 0.0)];
        }

        $perUrl = [];
        $total  = 0.0;

        $placeholders = implode(',', array_fill(0, count($propertyIds), '?'));

        foreach ($storeUrls as $su) {
            $path    = $this->urlPath($su['url']);
            $pattern = '%' . $path . '%';

            // Current window: last 7 days.
            $currentOrders = (int) DB::selectOne(
                "SELECT COUNT(DISTINCT id) AS cnt
                 FROM orders
                 WHERE workspace_id = ?
                   AND status IN ('completed','processing')
                   AND attribution_last_touch->>'channel_type' = 'organic_search'
                   AND attribution_last_touch->>'landing_page' ILIKE ?
                   AND occurred_at >= NOW() - INTERVAL '7 days'",
                [$workspaceId, $pattern],
            )?->cnt;

            $currentClicks = (int) DB::selectOne(
                "SELECT COALESCE(SUM(clicks), 0) AS cnt
                 FROM gsc_pages
                 WHERE property_id IN ({$placeholders})
                   AND device = 'all'
                   AND country = 'ZZ'
                   AND page ILIKE ?
                   AND date >= (NOW() - INTERVAL '7 days')::date",
                [...$propertyIds, $pattern],
            )?->cnt;

            // Baseline window: days -35 to -8.
            $baselineOrders = (int) DB::selectOne(
                "SELECT COUNT(DISTINCT id) AS cnt
                 FROM orders
                 WHERE workspace_id = ?
                   AND status IN ('completed','processing')
                   AND attribution_last_touch->>'channel_type' = 'organic_search'
                   AND attribution_last_touch->>'landing_page' ILIKE ?
                   AND occurred_at BETWEEN NOW() - INTERVAL '35 days' AND NOW() - INTERVAL '8 days'",
                [$workspaceId, $pattern],
            )?->cnt;

            $baselineClicks = (int) DB::selectOne(
                "SELECT COALESCE(SUM(clicks), 0) AS cnt
                 FROM gsc_pages
                 WHERE property_id IN ({$placeholders})
                   AND device = 'all'
                   AND country = 'ZZ'
                   AND page ILIKE ?
                   AND date BETWEEN (NOW() - INTERVAL '35 days')::date AND (NOW() - INTERVAL '8 days')::date",
                [...$propertyIds, $pattern],
            )?->cnt;

            $currentCvr  = $currentClicks  > 0 ? $currentOrders  / $currentClicks  : null;
            $baselineCvr = $baselineClicks > 0 ? $baselineOrders / $baselineClicks : null;

            // Risk = 0 if either CVR is missing, or if CVR improved.
            $risk = 0.0;
            if ($currentCvr !== null && $baselineCvr !== null && $baselineCvr > $currentCvr) {
                $risk = ($baselineCvr - $currentCvr) * $currentClicks * $aov;
            }

            $perUrl[$su['id']] = round($risk, 2);
            $total            += $risk;
        }

        return ['total' => round($total, 2), 'per_url' => $perUrl];
    }

    /**
     * Extract the URL path component for ILIKE matching.
     * Homepage URLs (path empty or '/') return '/' — matches all landing pages.
     */
    private function urlPath(string $url): string
    {
        $path = parse_url($url, PHP_URL_PATH) ?? '';

        return $path === '' ? '/' : rtrim($path, '/');
    }
}
