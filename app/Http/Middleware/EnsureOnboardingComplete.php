<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Store;
use App\Models\Workspace;
use App\Services\WorkspaceContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Blocks access to content routes until onboarding is complete.
 *
 * Onboarding is considered complete when:
 *   1. The workspace has a store that has completed an import at least once
 *      (historical_import_status = 'completed' OR historical_import_completed_at is set), OR
 *   2. The workspace has no store but has ads or GSC connected (ads-only path).
 *
 * This mirrors the redirect-to-dashboard condition in OnboardingController::show().
 * Apply to all content routes (dashboard, analytics, campaigns, etc.) but NOT
 * to settings/integrations/oauth/onboarding routes — users need those during setup.
 *
 * Related: app/Http/Controllers/OnboardingController.php (step detection logic)
 */
class EnsureOnboardingComplete
{
    public function handle(Request $request, Closure $next): Response
    {
        $workspaceId = app(WorkspaceContext::class)->id();

        // No workspace context — SetActiveWorkspace already handles redirecting to /onboarding.
        if ($workspaceId === null) {
            return $next($request);
        }

        // Path 1: workspace has a store that has completed an import at least once,
        // is currently running one, or is queued to start one.
        // During a re-import, historical_import_status resets to "pending" but
        // historical_import_completed_at retains the timestamp from the previous
        // successful import — so the user isn't kicked back to onboarding.
        // "Continue to dashboard" during a first-time import also lands here;
        // the dashboard renders with partial data while the import finishes.
        $hasCompletedStore = Store::withoutGlobalScopes()
            ->where('workspace_id', $workspaceId)
            ->where(fn ($q) => $q
                ->whereIn('historical_import_status', ['completed', 'pending', 'running', 'failed'])
                ->orWhereNotNull('historical_import_completed_at')
            )
            ->exists();

        if ($hasCompletedStore) {
            return $next($request);
        }

        // Path 2: no store at all, but at least one integration connected (ads/GSC only path).
        $hasAnyStore = Store::withoutGlobalScopes()
            ->where('workspace_id', $workspaceId)
            ->exists();

        if (! $hasAnyStore) {
            $workspace = Workspace::select(['id', 'has_ads', 'has_gsc'])->find($workspaceId);

            if ($workspace && ($workspace->has_ads || $workspace->has_gsc)) {
                return $next($request);
            }
        }

        return redirect()->route('onboarding');
    }
}
