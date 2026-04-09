<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Actions\AcceptWorkspaceInvitationAction;
use App\Http\Controllers\Controller;
use App\Models\WorkspaceInvitation;
use App\Models\WorkspaceUser;
use Illuminate\Auth\Events\Verified;
use Illuminate\Foundation\Auth\EmailVerificationRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Log;

class VerifyEmailController extends Controller
{
    /**
     * Mark the authenticated user's email address as verified.
     *
     * After verification: if an invitation token was stored in session (new user
     * path per CLAUDE.md §Workspace Invitations), accept that invitation, set
     * the active workspace, and redirect to /dashboard skipping onboarding.
     */
    public function __invoke(
        EmailVerificationRequest $request,
        AcceptWorkspaceInvitationAction $action,
    ): RedirectResponse {
        if ($request->user()->hasVerifiedEmail()) {
            return redirect()->intended(route('dashboard', absolute: false).'?verified=1');
        }

        if ($request->user()->markEmailAsVerified()) {
            event(new Verified($request->user()));
        }

        // Process pending invitation from session (new user registration path)
        $token = $request->session()->pull('invitation_token');

        if ($token) {
            $invitation = WorkspaceInvitation::where('token', $token)->first();

            if ($invitation && $invitation->isPending()) {
                try {
                    $action->handle($invitation, $request->user());
                    $request->session()->put('active_workspace_id', $invitation->workspace_id);

                    return redirect()->route('dashboard')->with('success', 'You have joined the workspace.');
                } catch (\RuntimeException $e) {
                    Log::warning('Invitation acceptance failed after email verification', [
                        'user_id'    => $request->user()->id,
                        'token'      => substr($token, 0, 8).'...',
                        'error'      => $e->getMessage(),
                    ]);
                }
            }
        }

        // No invitation processed — redirect to onboarding if the user has no workspace yet
        $hasWorkspace = WorkspaceUser::where('user_id', $request->user()->id)->exists();

        if (! $hasWorkspace) {
            return redirect()->route('onboarding');
        }

        return redirect()->intended(route('dashboard', absolute: false).'?verified=1');
    }
}
