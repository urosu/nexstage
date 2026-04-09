<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceUser;

/**
 * Authorization policy for all Workspace operations.
 *
 * Role requirements (per CLAUDE.md §User Roles):
 *   viewSettings / update (rename, reporting settings) → Owner or Admin
 *   invite / removeMembers                             → Owner or Admin
 *   transferOwnership / delete                         → Owner only
 *   viewBilling / manageBilling                        → Owner only
 *
 * Super admins bypass all checks via the `before()` hook.
 */
class WorkspacePolicy
{
    public function before(User $user, string $ability): ?bool
    {
        if ($user->is_super_admin) {
            return true;
        }

        return null;
    }

    /** View the workspace settings page (name, timezone, currency). */
    public function viewSettings(User $user, Workspace $workspace): bool
    {
        return $this->hasRole($user, $workspace->id, ['owner', 'admin']);
    }

    /** Update workspace settings (name, reporting_currency, reporting_timezone). */
    public function update(User $user, Workspace $workspace): bool
    {
        return $this->hasRole($user, $workspace->id, ['owner', 'admin']);
    }

    /** Invite new members to the workspace. */
    public function invite(User $user, Workspace $workspace): bool
    {
        return $this->hasRole($user, $workspace->id, ['owner', 'admin']);
    }

    /** Remove a member from the workspace. */
    public function removeMember(User $user, Workspace $workspace): bool
    {
        return $this->hasRole($user, $workspace->id, ['owner', 'admin']);
    }

    /** Transfer workspace ownership — Owner only. */
    public function transferOwnership(User $user, Workspace $workspace): bool
    {
        return $this->hasRole($user, $workspace->id, ['owner']);
    }

    /** Delete the workspace — Owner only. */
    public function delete(User $user, Workspace $workspace): bool
    {
        return $this->hasRole($user, $workspace->id, ['owner']);
    }

    /** View the billing settings page — Owner only. */
    public function viewBilling(User $user, Workspace $workspace): bool
    {
        return $this->hasRole($user, $workspace->id, ['owner']);
    }

    /** Manage billing (subscribe, change plan, cancel) — Owner only. */
    public function manageBilling(User $user, Workspace $workspace): bool
    {
        return $this->hasRole($user, $workspace->id, ['owner']);
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /** @param array<string> $roles */
    private function hasRole(User $user, int $workspaceId, array $roles): bool
    {
        return WorkspaceUser::where('workspace_id', $workspaceId)
            ->where('user_id', $user->id)
            ->whereIn('role', $roles)
            ->exists();
    }
}
