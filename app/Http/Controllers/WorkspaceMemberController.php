<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\RemoveWorkspaceMemberAction;
use App\Actions\TransferWorkspaceOwnershipAction;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceUser;
use App\Services\WorkspaceContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class WorkspaceMemberController extends Controller
{
    /**
     * Update a member's role (PATCH /settings/team/members/{workspaceUser}).
     *
     * Owners cannot be demoted directly; use transfer endpoint instead.
     * Admins cannot promote others to owner.
     */
    public function update(Request $request, WorkspaceUser $workspaceUser): RedirectResponse
    {
        $workspace = Workspace::findOrFail(app(WorkspaceContext::class)->id());

        // Verify the workspace_user belongs to the current workspace
        if ($workspaceUser->workspace_id !== $workspace->id) {
            abort(404);
        }

        $this->authorize('removeMember', $workspace); // same permission level

        if ($workspaceUser->role === 'owner') {
            return back()->withErrors(['role' => 'Use ownership transfer to change the owner\'s role.']);
        }

        $myRole = WorkspaceUser::where('workspace_id', $workspace->id)
            ->where('user_id', $request->user()->id)
            ->value('role');

        // Admins cannot set role to owner
        $allowedRoles = $myRole === 'owner' ? ['admin', 'member'] : ['admin', 'member'];

        $validated = $request->validate([
            'role' => ['required', Rule::in($allowedRoles)],
        ]);

        $workspaceUser->update(['role' => $validated['role']]);

        return back()->with('success', 'Member role updated.');
    }

    /**
     * Remove a member (DELETE /settings/team/members/{workspaceUser}).
     */
    public function destroy(
        Request $request,
        WorkspaceUser $workspaceUser,
        RemoveWorkspaceMemberAction $action,
    ): RedirectResponse {
        $workspace = Workspace::findOrFail(app(WorkspaceContext::class)->id());

        if ($workspaceUser->workspace_id !== $workspace->id) {
            abort(404);
        }

        $this->authorize('removeMember', $workspace);

        // Prevent self-removal for owner
        if ($workspaceUser->user_id === $request->user()->id && $workspaceUser->role === 'owner') {
            return back()->withErrors(['member' => 'Transfer ownership before leaving the workspace.']);
        }

        try {
            $action->handle($workspaceUser);
        } catch (\RuntimeException $e) {
            return back()->withErrors(['member' => $e->getMessage()]);
        }

        return back()->with('success', 'Member removed.');
    }

    /**
     * Transfer workspace ownership (POST /settings/team/transfer).
     */
    public function transfer(
        Request $request,
        TransferWorkspaceOwnershipAction $action,
    ): RedirectResponse {
        $workspace = Workspace::findOrFail(app(WorkspaceContext::class)->id());

        $this->authorize('transferOwnership', $workspace);

        $validated = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
        ]);

        $newOwner = User::findOrFail($validated['user_id']);

        try {
            $action->handle($workspace, $newOwner);
        } catch (\RuntimeException $e) {
            return back()->withErrors(['transfer' => $e->getMessage()]);
        }

        return back()->with('success', 'Ownership transferred to ' . $newOwner->name . '.');
    }
}
