<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;

/**
 * Provides a scope that filters workspace_events and daily_notes by the active
 * ScopeFilter selection (store_ids, integration_ids).
 *
 * Both models carry `scope_type` (workspace | store | integration) and
 * `scope_id` (nullable bigint). The filtering rule:
 *
 *   - scope_type='workspace' → always visible (applies to all stores/integrations)
 *   - scope_type='store'     → visible when either no store filter is active OR
 *                              scope_id is among the selected store_ids
 *   - scope_type='integration' → visible when either no integration filter is
 *                                active OR scope_id is among the selected
 *                                integration_ids
 *
 * This ensures that workspace-wide annotations are always shown, while store-
 * or integration-scoped annotations are hidden when the user is viewing a
 * different scope. Annotations with no scope (legacy rows created before
 * Phase 1.5) default to 'workspace' via the DB CHECK constraint default.
 *
 * @see PLANNING.md section 8 (Scope Filtering — scope-aware annotations)
 */
trait HasAnnotationScope
{
    /**
     * Filter annotations so only those matching the active scope are returned.
     *
     * @param  Builder  $query
     * @param  int[]    $storeIds        Currently selected store IDs (empty = all)
     * @param  int[]    $integrationIds  Currently selected integration IDs (empty = all)
     * @return Builder
     */
    public function scopeForAnnotationScope(
        Builder $query,
        array $storeIds = [],
        array $integrationIds = [],
    ): Builder {
        return $query->where(static function (Builder $q) use ($storeIds, $integrationIds): void {
            // Workspace-scoped annotations are always visible.
            $q->where('scope_type', 'workspace');

            // Store-scoped: show when no store filter is active, or when this
            // annotation's scope_id is one of the selected stores.
            $q->orWhere(static function (Builder $inner) use ($storeIds): void {
                $inner->where('scope_type', 'store');
                if (!empty($storeIds)) {
                    $inner->whereIn('scope_id', $storeIds);
                }
            });

            // Integration-scoped: same pattern for integration_ids.
            $q->orWhere(static function (Builder $inner) use ($integrationIds): void {
                $inner->where('scope_type', 'integration');
                if (!empty($integrationIds)) {
                    $inner->whereIn('scope_id', $integrationIds);
                }
            });
        });
    }
}
