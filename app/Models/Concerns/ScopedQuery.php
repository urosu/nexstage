<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;

/**
 * Provides a reusable Eloquent scope that accepts the standard Phase 1.5 scope
 * filter parameters: store_ids, integration_ids (ad_account_ids), date_from,
 * date_to.
 *
 * Apply this trait to any model whose table has a filterable date column and
 * optionally a store_id or ad_account_id column. Controllers call
 * `::withScopeFilters(...)` instead of hand-writing the same WHERE clauses on
 * every endpoint — that eliminates a class of "scope mismatch" bugs where one
 * page forgets to filter by store and another adds a redundant column check.
 *
 * The trait is intentionally narrow: it does NOT know about workspace_id (that
 * is handled by WorkspaceScope on every tenant model). It only handles the
 * per-request scope dimensions a user can change via the ScopeFilter UI
 * component.
 *
 * Columns involved:
 *   - store_id       — present on orders, daily_snapshots, daily_snapshot_products
 *   - ad_account_id  — present on ad_insights, campaigns, adsets, ads
 *   - date column    — caller passes name via $dateColumn (default: 'date')
 *
 * @see PLANNING.md section 8 (Scope Filtering)
 */
trait ScopedQuery
{
    /**
     * Filter by scope parameters passed from the ScopeFilter UI component.
     *
     * Empty arrays mean "no filter" (= all). Null dates mean "no date boundary".
     * The $dateColumn parameter lets callers point at 'date', 'occurred_at',
     * 'created_at', etc. depending on the table shape.
     *
     * @param  Builder      $query
     * @param  int[]        $storeIds        Store IDs to include; empty = all
     * @param  int[]        $integrationIds  Ad account IDs to include; empty = all
     * @param  string|null  $dateFrom        Lower date bound (Y-m-d, inclusive)
     * @param  string|null  $dateTo          Upper date bound (Y-m-d, inclusive)
     * @param  string       $dateColumn      The column to apply the date filter on
     * @return Builder
     */
    public function scopeWithScopeFilters(
        Builder $query,
        array $storeIds = [],
        array $integrationIds = [],
        ?string $dateFrom = null,
        ?string $dateTo = null,
        string $dateColumn = 'date',
    ): Builder {
        if (!empty($storeIds)) {
            $query->whereIn('store_id', $storeIds);
        }

        if (!empty($integrationIds)) {
            $query->whereIn('ad_account_id', $integrationIds);
        }

        if ($dateFrom !== null) {
            $query->where($dateColumn, '>=', $dateFrom);
        }

        if ($dateTo !== null) {
            $query->where($dateColumn, '<=', $dateTo);
        }

        return $query;
    }

    /**
     * Parse scope filter parameters from a request array (as received from
     * Inertia/query-string). Returns normalised arrays ready for
     * scopeWithScopeFilters().
     *
     * Usage in a controller:
     *   [$storeIds, $integrationIds, $dateFrom, $dateTo] =
     *       Model::parseScopeFilters($request->all());
     *
     * @param  array<string, mixed>  $params
     * @return array{int[], int[], string|null, string|null}
     */
    public static function parseScopeFilters(array $params): array
    {
        $storeIds = [];
        if (!empty($params['store_ids'])) {
            $raw = is_array($params['store_ids'])
                ? $params['store_ids']
                : explode(',', (string) $params['store_ids']);
            $storeIds = array_map('intval', array_filter($raw));
        }

        $integrationIds = [];
        if (!empty($params['integration_ids'])) {
            $raw = is_array($params['integration_ids'])
                ? $params['integration_ids']
                : explode(',', (string) $params['integration_ids']);
            $integrationIds = array_map('intval', array_filter($raw));
        }

        $dateFrom = isset($params['from']) && $params['from'] !== '' ? (string) $params['from'] : null;
        $dateTo   = isset($params['to'])   && $params['to']   !== '' ? (string) $params['to']   : null;

        return [$storeIds, $integrationIds, $dateFrom, $dateTo];
    }
}
