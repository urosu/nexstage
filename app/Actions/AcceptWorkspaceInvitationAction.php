<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\User;
use App\Models\WorkspaceInvitation;
use App\Models\WorkspaceUser;
use Illuminate\Support\Facades\DB;

class AcceptWorkspaceInvitationAction
{
    /**
     * Accept a workspace invitation for the given user.
     *
     * Validates that the invitation is still pending and the user's email matches.
     * If the user is already a member the invitation is marked accepted without
     * creating a duplicate workspace_users row.
     *
     * @throws \RuntimeException on expired, already-accepted, or email-mismatch
     */
    public function handle(WorkspaceInvitation $invitation, User $user): void
    {
        if (! $invitation->isPending()) {
            throw new \RuntimeException('This invitation has expired or has already been accepted.');
        }

        if (strtolower(trim($invitation->email)) !== strtolower(trim($user->email))) {
            throw new \RuntimeException('This invitation was sent to a different email address.');
        }

        DB::transaction(function () use ($invitation, $user): void {
            $alreadyMember = WorkspaceUser::where('workspace_id', $invitation->workspace_id)
                ->where('user_id', $user->id)
                ->exists();

            if (! $alreadyMember) {
                WorkspaceUser::create([
                    'workspace_id' => $invitation->workspace_id,
                    'user_id'      => $user->id,
                    'role'         => $invitation->role,
                ]);
            }

            $invitation->update(['accepted_at' => now()]);
        });
    }
}
