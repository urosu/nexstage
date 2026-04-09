<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceUser;
use Illuminate\Support\Facades\DB;

class TransferWorkspaceOwnershipAction
{
    /**
     * Transfer ownership of a workspace to another current member.
     *
     * The current owner is demoted to 'admin'. The new owner must already be a
     * member (owner cannot leave without transferring first).
     *
     * @throws \RuntimeException if the new owner is not a current member
     */
    public function handle(Workspace $workspace, User $newOwner): void
    {
        $newOwnerMembership = WorkspaceUser::where('workspace_id', $workspace->id)
            ->where('user_id', $newOwner->id)
            ->first();

        if ($newOwnerMembership === null) {
            throw new \RuntimeException('The target user is not a member of this workspace.');
        }

        if ($newOwner->id === $workspace->owner_id) {
            throw new \RuntimeException('This user is already the workspace owner.');
        }

        DB::transaction(function () use ($workspace, $newOwner, $newOwnerMembership): void {
            // Demote current owner to admin
            WorkspaceUser::where('workspace_id', $workspace->id)
                ->where('user_id', $workspace->owner_id)
                ->update(['role' => 'admin']);

            // Promote new owner
            $newOwnerMembership->update(['role' => 'owner']);

            // Update workspace owner_id
            $workspace->update(['owner_id' => $newOwner->id]);
        });
    }
}
