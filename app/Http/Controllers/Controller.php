<?php

namespace App\Http\Controllers;

use App\Models\Workspace;
use App\Services\WorkspaceContext;
use Illuminate\Http\RedirectResponse;

abstract class Controller
{
    use \Illuminate\Foundation\Auth\Access\AuthorizesRequests;

    /**
     * Redirect to a workspace's Home URL.
     *
     * Why: home is workspace-prefixed (/{slug}), so every redirect
     * to "the home" must know which workspace's slug to use.
     */
    protected function toDashboard(int $workspaceId): RedirectResponse
    {
        $slug = Workspace::select('slug')->findOrFail($workspaceId)->slug;
        return redirect("/{$slug}");
    }

    /**
     * Redirect to the currently active workspace's dashboard.
     * Falls back to /onboarding if no workspace is set.
     */
    protected function toActiveDashboard(): RedirectResponse
    {
        $workspaceId = app(WorkspaceContext::class)->id();

        if ($workspaceId === null) {
            return redirect('/onboarding');
        }

        return $this->toDashboard($workspaceId);
    }
}
