<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Workspace;
use App\Models\WorkspaceUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class WorkspaceSwitchController extends Controller
{
    /**
     * Switch the authenticated user's active workspace and redirect to its dashboard.
     *
     * Verifies membership before writing to session to prevent workspace enumeration.
     * Redirects to /{workspace-slug}/dashboard so the URL reflects the new workspace.
     */
    public function __invoke(Request $request, string $workspace): RedirectResponse
    {
        $workspaceModel = Workspace::whereNull('deleted_at')->find($workspace);

        if (! $workspaceModel) {
            abort(404);
        }

        $isMember = WorkspaceUser::where('user_id', $request->user()->id)
            ->where('workspace_id', $workspaceModel->id)
            ->exists();

        if (! $isMember) {
            abort(403);
        }

        $request->session()->put('active_workspace_id', $workspaceModel->id);

        return redirect("/{$workspaceModel->slug}/dashboard");
    }
}
