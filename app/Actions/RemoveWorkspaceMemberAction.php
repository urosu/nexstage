<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\WorkspaceUser;

class RemoveWorkspaceMemberAction
{
    /**
     * Remove a member from the workspace.
     *
     * The owner cannot be removed — transfer ownership first.
     * Per spec: "Membership is checked per-request via middleware, so no full
     * session invalidation needed." The SetActiveWorkspace middleware falls back
     * to the user's next workspace (or /onboarding) on the next request.
     *
     * @throws \RuntimeException if attempting to remove the owner
     */
    public function handle(WorkspaceUser $workspaceUser): void
    {
        if ($workspaceUser->role === 'owner') {
            throw new \RuntimeException('The workspace owner cannot be removed. Transfer ownership first.');
        }

        $workspaceUser->delete();
    }
}
