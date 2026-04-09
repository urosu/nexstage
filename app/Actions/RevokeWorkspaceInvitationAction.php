<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\WorkspaceInvitation;

class RevokeWorkspaceInvitationAction
{
    /**
     * Permanently delete a pending invitation.
     */
    public function handle(WorkspaceInvitation $invitation): void
    {
        $invitation->delete();
    }
}
