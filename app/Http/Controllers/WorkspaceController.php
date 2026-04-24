<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Store;
use App\Models\Workspace;
use App\Models\WorkspaceUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Handles workspace lifecycle actions outside the workspace-prefixed routes.
 *
 * create()  — Creates a new empty workspace for an existing user and redirects
 *             to /onboarding. Called from the workspace switcher "New workspace" button.
 *             OnboardingController picks up the session's active_workspace_id and
 *             operates on this workspace instead of the user's oldest one.
 *
 * discard() — Soft-deletes an empty workspace (no stores) and switches the session
 *             to another workspace the user belongs to. Called when a user cancels
 *             onboarding for a freshly created workspace.
 *
 * @see PLANNING.md section 3 (workspace model)
 */
class WorkspaceController extends Controller
{
    /**
     * Create a new workspace and begin onboarding in it.
     *
     * Generates a placeholder name (renamed to the WC site title once a store connects).
     * Sets session['active_workspace_id'] so OnboardingController operates on this workspace.
     */
    public function create(Request $request): RedirectResponse
    {
        $user = $request->user();
        $name = trim($user->name) !== '' ? trim($user->name) . "'s Workspace" : 'My Workspace';

        // 32 lowercase hex chars = 128 bits entropy, same as Cloudflare account IDs.
        do {
            $slug = bin2hex(random_bytes(16));
        } while (Workspace::where('slug', $slug)->exists());

        $workspace = DB::transaction(function () use ($user, $name, $slug): Workspace {
            $workspace = Workspace::create([
                'name'               => $name,
                'slug'               => $slug,
                'owner_id'           => $user->id,
                'reporting_currency' => 'EUR',
                'reporting_timezone' => 'Europe/Berlin',
                'trial_ends_at'      => now()->addDays(14),
            ]);

            WorkspaceUser::create([
                'workspace_id' => $workspace->id,
                'user_id'      => $user->id,
                'role'         => 'owner',
            ]);

            return $workspace;
        });

        session(['active_workspace_id' => $workspace->id]);

        return redirect()->route('onboarding');
    }

    /**
     * Discard an empty workspace and switch the session to another workspace.
     *
     * Safety checks:
     *   - User must be the owner of the workspace.
     *   - Workspace must have no stores (nothing to lose).
     *   - User must have at least one other workspace to fall back to.
     */
    public function discard(Request $request, int $workspace): RedirectResponse
    {
        $user = $request->user();

        $workspaceModel = Workspace::whereNull('deleted_at')->find($workspace);

        if (! $workspaceModel) {
            abort(404);
        }

        $isOwner = WorkspaceUser::where('workspace_id', $workspaceModel->id)
            ->where('user_id', $user->id)
            ->where('role', 'owner')
            ->exists();

        if (! $isOwner) {
            abort(403);
        }

        $hasStores = Store::withoutGlobalScopes()
            ->where('workspace_id', $workspaceModel->id)
            ->exists();

        if ($hasStores) {
            return back()->with('error', 'Cannot discard a workspace that has stores connected.');
        }

        // Prefer a workspace that has completed onboarding (has a store with a finished import).
        // Falls back to the oldest membership so the user lands on their original workspace,
        // not another incomplete one. Without ORDER BY, first() is non-deterministic in Postgres.
        $nextWorkspaceUser = WorkspaceUser::where('user_id', $user->id)
            ->where('workspace_id', '!=', $workspaceModel->id)
            ->whereHas('workspace', fn ($q) => $q->whereNull('deleted_at'))
            ->orderByRaw('
                CASE WHEN EXISTS (
                    SELECT 1 FROM stores
                    WHERE stores.workspace_id = workspace_users.workspace_id
                      AND (stores.historical_import_status = ? OR stores.historical_import_completed_at IS NOT NULL)
                ) THEN 0 ELSE 1 END, id ASC
            ', ['completed'])
            ->with('workspace')
            ->first();

        if (! $nextWorkspaceUser) {
            return back()->with('error', 'Cannot discard your only workspace.');
        }

        $workspaceModel->delete();

        $next = $nextWorkspaceUser->workspace;
        session(['active_workspace_id' => $next->id]);

        return redirect("/{$next->slug}");
    }
}
