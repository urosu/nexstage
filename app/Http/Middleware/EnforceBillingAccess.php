<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Workspace;
use App\Services\WorkspaceContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnforceBillingAccess
{
    private const EXEMPT_PATHS = [
        'settings/billing',
        'settings/profile',
        'logout',
        'onboarding',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user()) {
            return $next($request);
        }

        if ($this->isExempt($request)) {
            return $next($request);
        }

        $workspaceId = app(WorkspaceContext::class)->id();

        if ($workspaceId === null) {
            return $next($request);
        }

        $workspace = Workspace::withoutGlobalScopes()
            ->select(['id', 'trial_ends_at', 'billing_plan', 'billing_workspace_id'])
            ->find($workspaceId);

        if ($workspace === null) {
            return $next($request);
        }

        if ($this->isBillingRequired($workspace)) {
            return redirect('/settings/billing');
        }

        return $next($request);
    }

    private function isExempt(Request $request): bool
    {
        $path = $request->path();

        foreach (self::EXEMPT_PATHS as $exempt) {
            if ($path === $exempt || str_starts_with($path, $exempt . '/')) {
                return true;
            }
        }

        // oauth/* wildcard
        if (str_starts_with($path, 'oauth/')) {
            return true;
        }

        return false;
    }

    private function isBillingRequired(Workspace $workspace): bool
    {
        // Child workspaces under consolidated billing inherit owner's plan.
        if ($workspace->billing_workspace_id !== null) {
            $owner = Workspace::withoutGlobalScopes()
                ->select(['id', 'trial_ends_at', 'billing_plan'])
                ->find($workspace->billing_workspace_id);

            if ($owner !== null) {
                return $this->trialExpiredWithNoPlan($owner);
            }
        }

        return $this->trialExpiredWithNoPlan($workspace);
    }

    private function trialExpiredWithNoPlan(Workspace $workspace): bool
    {
        return $workspace->trial_ends_at !== null
            && $workspace->trial_ends_at->lt(now())
            && $workspace->billing_plan === null;
    }
}
