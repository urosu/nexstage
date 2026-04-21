<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Actions\AcceptWorkspaceInvitationAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Models\WorkspaceInvitation;
use App\Services\GeoDetectionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Inertia\Response;

class AuthenticatedSessionController extends Controller
{
    /**
     * Display the login view.
     *
     * Passes the invitation token (if present in the URL) as a prop so the
     * form can include it and we can process it after authentication.
     */
    public function create(Request $request): Response
    {
        return Inertia::render('Auth/Login', [
            'canResetPassword' => Route::has('password.request'),
            'status'           => session('status'),
            'invitation_token' => $request->query('invitation'),
        ]);
    }

    /**
     * Handle an incoming authentication request.
     *
     * Existing-user invitation path (per CLAUDE.md §Workspace Invitations):
     * after login, validate token (not expired, not accepted, email matches),
     * create workspace_users, set active workspace, redirect /dashboard.
     */
    public function store(
        LoginRequest $request,
        AcceptWorkspaceInvitationAction $action,
        GeoDetectionService $geo,
    ): RedirectResponse {
        $request->authenticate();

        $request->session()->regenerate();

        /** @var \App\Models\User $authUser */
        $authUser = Auth::user();

        // IP geolocation — only on first-ever login (last_login_at is null).
        // Stores the detected country in the session for use by OnboardingController
        // as a lowest-priority pre-fill hint for stores.primary_country_code.
        // @see PLANNING.md section 10 (Country auto-detection)
        if ($authUser->last_login_at === null) {
            $geo->detect($request);
        }

        $authUser->forceFill(['last_login_at' => now()])->save();

        // Process invitation token submitted from login form (existing user path)
        $token = $request->input('invitation_token');

        if ($token) {
            $invitation = WorkspaceInvitation::where('token', $token)->first();

            if ($invitation && $invitation->isPending()) {
                try {
                    $action->handle($invitation, $authUser);
                    $request->session()->put('active_workspace_id', $invitation->workspace_id);

                    return $this->toDashboard($invitation->workspace_id)
                        ->with('success', 'You have joined the workspace.');
                } catch (\RuntimeException $e) {
                    Log::warning('Invitation acceptance failed after login', [
                        'user_id' => Auth::id(),
                        'token'   => substr($token, 0, 8).'...',
                        'error'   => $e->getMessage(),
                    ]);
                }
            }
        }

        // Why: redirect to onboarding so it can decide whether to send the user
        // to the dashboard (import completed) or to the appropriate onboarding step.
        // Users who have completed onboarding are transparently forwarded to /dashboard.
        return redirect()->intended(route('onboarding', absolute: false));
    }

    /**
     * Destroy an authenticated session.
     */
    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();

        $request->session()->regenerateToken();

        return redirect('/');
    }
}
