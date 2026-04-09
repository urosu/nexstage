<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\AcceptWorkspaceInvitationAction;
use App\Actions\InviteWorkspaceMemberAction;
use App\Actions\RevokeWorkspaceInvitationAction;
use App\Models\Workspace;
use App\Models\WorkspaceInvitation;
use App\Models\WorkspaceUser;
use App\Services\WorkspaceContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class WorkspaceInvitationController extends Controller
{
    /**
     * Show the invitation landing page.
     *
     * - Unauthenticated users are redirected to login/register with the token.
     * - Authenticated users see a confirmation page or are auto-redirected.
     */
    public function show(Request $request, string $token): Response|RedirectResponse
    {
        $invitation = WorkspaceInvitation::where('token', $token)->firstOrFail();

        if (! $invitation->isPending()) {
            return redirect()->route('login')
                ->with('status', 'This invitation has expired or has already been used.');
        }

        if (! $request->user()) {
            // Redirect to login/register preserving the token
            $userExists = \App\Models\User::where('email', $invitation->email)->exists();
            $route      = $userExists ? 'login' : 'register';

            return redirect("/{$route}?invitation={$token}");
        }

        // Authenticated — render confirmation
        return Inertia::render('Invitations/Show', [
            'invitation' => [
                'token'          => $invitation->token,
                'workspace_name' => $invitation->workspace->name,
                'role'           => $invitation->role,
                'expires_at'     => $invitation->expires_at,
            ],
        ]);
    }

    /**
     * Accept an invitation — for authenticated users who arrived via the landing page.
     */
    public function accept(
        Request $request,
        string $token,
        AcceptWorkspaceInvitationAction $action,
    ): RedirectResponse {
        $invitation = WorkspaceInvitation::where('token', $token)->firstOrFail();

        try {
            $action->handle($invitation, $request->user());
        } catch (\RuntimeException $e) {
            return redirect()->route('dashboard')->withErrors(['invitation' => $e->getMessage()]);
        }

        $request->session()->put('active_workspace_id', $invitation->workspace_id);

        return redirect()->route('dashboard')->with('success', 'You have joined the workspace.');
    }

    /**
     * Send a new workspace invitation (POST /settings/team/invite).
     */
    public function store(
        Request $request,
        InviteWorkspaceMemberAction $action,
    ): RedirectResponse {
        $workspace = Workspace::findOrFail(app(WorkspaceContext::class)->id());

        $this->authorize('create', [WorkspaceInvitation::class, $workspace]);

        $myRole = WorkspaceUser::where('workspace_id', $workspace->id)
            ->where('user_id', $request->user()->id)
            ->value('role');

        // Admins may not invite as owner
        $allowedRoles = $myRole === 'owner' ? ['admin', 'member'] : ['admin', 'member'];

        $validated = $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
            'role'  => ['required', 'string', \Illuminate\Validation\Rule::in($allowedRoles)],
        ]);

        try {
            $action->handle($workspace, $validated['email'], $validated['role']);
        } catch (\RuntimeException $e) {
            return back()->withErrors(['email' => $e->getMessage()]);
        }

        return back()->with('success', 'Invitation sent.');
    }

    /**
     * Revoke a pending invitation (DELETE /settings/team/invitations/{token}).
     */
    public function destroy(
        Request $request,
        string $token,
        RevokeWorkspaceInvitationAction $action,
    ): RedirectResponse {
        $workspace = Workspace::findOrFail(app(WorkspaceContext::class)->id());

        $this->authorize('revoke', [WorkspaceInvitation::class, $workspace]);

        $invitation = WorkspaceInvitation::where('workspace_id', $workspace->id)
            ->where('token', $token)
            ->firstOrFail();

        $action->handle($invitation);

        return back()->with('success', 'Invitation revoked.');
    }
}
