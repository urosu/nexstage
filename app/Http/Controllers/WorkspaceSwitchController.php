<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\WorkspaceUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class WorkspaceSwitchController extends Controller
{
    /**
     * Switch the authenticated user's active workspace.
     *
     * Verifies membership before writing to session to prevent workspace enumeration.
     */
    public function __invoke(Request $request, int $workspace): RedirectResponse
    {
        $isMember = WorkspaceUser::where('user_id', $request->user()->id)
            ->where('workspace_id', $workspace)
            ->whereHas('workspace', fn ($q) => $q->whereNull('deleted_at'))
            ->exists();

        if (! $isMember) {
            abort(403);
        }

        $request->session()->put('active_workspace_id', $workspace);

        return redirect()->route('dashboard');
    }
}
