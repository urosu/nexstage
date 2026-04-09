<?php

declare(strict_types=1);

namespace App\Actions;

use App\Mail\WorkspaceInvitationMail;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceInvitation;
use App\Models\WorkspaceUser;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class InviteWorkspaceMemberAction
{
    /**
     * Create or refresh an invitation and send the invitation email.
     *
     * Enforces role restriction: Admins may only invite 'member' or 'admin',
     * not 'owner'. Caller must verify the inviting user's role before calling.
     *
     * @throws \RuntimeException if the email is already a workspace member
     */
    public function handle(Workspace $workspace, string $email, string $role): WorkspaceInvitation
    {
        $normalizedEmail = strtolower(trim($email));

        // Block if already a member
        $alreadyMember = WorkspaceUser::where('workspace_id', $workspace->id)
            ->whereHas('user', fn ($q) => $q->where('email', $normalizedEmail))
            ->exists();

        if ($alreadyMember) {
            throw new \RuntimeException('This email address is already a member of the workspace.');
        }

        // Upsert invitation — refreshes token and expiry on re-invite
        $invitation = WorkspaceInvitation::updateOrCreate(
            ['workspace_id' => $workspace->id, 'email' => $normalizedEmail],
            [
                'role'        => $role,
                'token'       => Str::random(64),
                'expires_at'  => now()->addDays(7),
                'accepted_at' => null,
            ]
        );

        // Determine login vs register link
        $userExists = User::where('email', $normalizedEmail)->exists();

        Mail::to($normalizedEmail)->queue(
            new WorkspaceInvitationMail($invitation, $workspace, $userExists)
        );

        return $invitation;
    }
}
