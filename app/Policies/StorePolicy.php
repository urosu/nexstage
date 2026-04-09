<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Store;
use App\Models\User;
use App\Models\WorkspaceUser;

/**
 * Authorization policy for Store operations.
 *
 * Role requirements (per CLAUDE.md §User Roles):
 *   connect / disconnect / manage integrations → Owner or Admin
 *   view (dashboards, metrics)                 → any workspace member
 *
 * Super admins bypass all checks via the `before()` hook.
 */
class StorePolicy
{
    /**
     * Super admins bypass all policy checks.
     */
    public function before(User $user, string $ability): ?bool
    {
        if ($user->is_super_admin) {
            return true;
        }

        return null;
    }

    /**
     * Connect (create) a new store or reconnect an existing one.
     * Requires Owner or Admin role on the workspace.
     */
    public function create(User $user, \App\Models\Workspace $workspace): bool
    {
        return $this->hasRole($user, $workspace->id, ['owner', 'admin']);
    }

    /**
     * Update store settings (name, slug, timezone).
     * Requires Owner or Admin role on the workspace.
     */
    public function update(User $user, Store $store): bool
    {
        return $this->hasRole($user, $store->workspace_id, ['owner', 'admin']);
    }

    /**
     * Disconnect or delete a store.
     * Requires Owner or Admin role on the workspace.
     */
    public function delete(User $user, Store $store): bool
    {
        return $this->hasRole($user, $store->workspace_id, ['owner', 'admin']);
    }

    /**
     * View store data (overview, products, countries, SEO).
     * Any workspace member may view.
     */
    public function view(User $user, Store $store): bool
    {
        return $this->hasRole($user, $store->workspace_id, ['owner', 'admin', 'member']);
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * @param array<string> $roles
     */
    private function hasRole(User $user, int $workspaceId, array $roles): bool
    {
        return WorkspaceUser::where('workspace_id', $workspaceId)
            ->where('user_id', $user->id)
            ->whereIn('role', $roles)
            ->exists();
    }
}
