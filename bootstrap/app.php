<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use App\Exceptions\FacebookRateLimitException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // SetActiveWorkspace must run BEFORE SubstituteBindings so that WorkspaceContext
        // is populated when implicit route model binding resolves workspace-scoped models
        // (e.g. Order, which carries WorkspaceScope). Without this ordering, SubstituteBindings
        // calls Order::find() before WorkspaceContext is set, throwing a RuntimeException.
        //
        // Strategy: remove SubstituteBindings from its default position (end of web group)
        // and re-append it after SetActiveWorkspace. Session is already started by this
        // point so SetActiveWorkspace's session-based fallback still works.
        $middleware->web(remove: [\Illuminate\Routing\Middleware\SubstituteBindings::class]);
        $middleware->web(append: [
            \App\Http\Middleware\SetActiveWorkspace::class,
            \Illuminate\Routing\Middleware\SubstituteBindings::class,
            \App\Http\Middleware\EnforceBillingAccess::class,
            \App\Http\Middleware\HandleInertiaRequests::class,
            \Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets::class,
        ]);

        $middleware->alias([
            'workspace'   => \App\Http\Middleware\SetActiveWorkspace::class,
            'billing'     => \App\Http\Middleware\EnforceBillingAccess::class,
            'super_admin' => \App\Http\Middleware\RequireSuperAdmin::class,
            'onboarded'   => \App\Http\Middleware\EnsureOnboardingComplete::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (ThrottleRequestsException $e, $request) {
            return redirect()->back()->with('error', 'Too many requests. Please wait a moment and try again.');
        });

        $exceptions->render(function (FacebookRateLimitException $e, $request) {
            return redirect()->back()->with('error', 'Facebook rate limit reached. Please try again in ' . $e->retryAfter . ' seconds.');
        });
    })->create();
