<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\WorkspaceUser;
use App\Services\WorkspaceContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SetActiveWorkspace
{
    // Paths where we skip setting workspace context entirely (no auth context needed).
    private const SKIP_PATHS = [
        'onboarding',
        'onboarding',
        'login',
        'register',
        'verify-email',
        'email/verify',
        'logout',
    ];

    // Paths where we set workspace context if available, but do NOT redirect if missing.
    private const NO_REDIRECT_PATHS = [
        'oauth/',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user()) {
            return $next($request);
        }

        if ($this->shouldSkip($request)) {
            return $next($request);
        }

        $user = $request->user();
        $sessionWorkspaceId = session('active_workspace_id');

        $workspaceId = $this->resolveWorkspaceId($user->id, $sessionWorkspaceId);

        if ($workspaceId === null) {
            // On OAuth paths, let the controller handle the missing workspace
            if ($this->shouldSkipRedirect($request)) {
                return $next($request);
            }

            return redirect('/onboarding');
        }

        app(WorkspaceContext::class)->set($workspaceId);

        return $next($request);
    }

    private function shouldSkip(Request $request): bool
    {
        $path = $request->path();

        foreach (self::SKIP_PATHS as $skip) {
            if ($path === $skip || str_starts_with($path, $skip . '/')) {
                return true;
            }
        }

        // password/* wildcard
        if (str_starts_with($path, 'password/')) {
            return true;
        }

        return false;
    }

    private function shouldSkipRedirect(Request $request): bool
    {
        $path = $request->path();

        foreach (self::NO_REDIRECT_PATHS as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return true;
            }
        }

        return false;
    }

    private function resolveWorkspaceId(int $userId, mixed $sessionWorkspaceId): ?int
    {
        if ($sessionWorkspaceId) {
            $exists = WorkspaceUser::where('user_id', $userId)
                ->where('workspace_id', $sessionWorkspaceId)
                ->whereHas('workspace', fn ($q) => $q->whereNull('deleted_at'))
                ->exists();

            if ($exists) {
                return (int) $sessionWorkspaceId;
            }
        }

        // Fallback: oldest workspace by workspace_users.created_at
        $oldest = WorkspaceUser::where('user_id', $userId)
            ->whereHas('workspace', fn ($q) => $q->whereNull('deleted_at'))
            ->orderBy('created_at', 'asc')
            ->value('workspace_id');

        return $oldest !== null ? (int) $oldest : null;
    }
}
