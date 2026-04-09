<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceUser;

/**
 * Authorization policy for WorkspaceInvitation operations.
 *
 * Role requirements (per CLAUDE.md §Workspace Invitations):
 *   create (invite) → Owner or Admin
 *   revoke          → Owner or Admin
 *
 * Admins may only invite as Member or Admin (not Owner).
 * The role restriction is enforced in InviteWorkspaceMemberAction.
 *
 * Super admins bypass all checks via the `before()` hook.
 */
class WorkspaceInvitationPolicy
{
    public function before(User $user, string $ability): ?bool
    {
        if ($user->is_super_admin) {
            return true;
        }

        return null;
    }

    /** Create (send) an invitation to join the workspace. */
    public function create(User $user, Workspace $workspace): bool
    {
        return $this->hasRole($user, $workspace->id, ['owner', 'admin']);
    }

    /** Revoke a pending invitation. */
    public function revoke(User $user, Workspace $workspace): bool
    {
        return $this->hasRole($user, $workspace->id, ['owner', 'admin']);
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
