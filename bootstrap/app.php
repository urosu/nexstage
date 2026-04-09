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
        $middleware->web(append: [
            \App\Http\Middleware\HandleInertiaRequests::class,
            \Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets::class,
            \App\Http\Middleware\SetActiveWorkspace::class,
            \App\Http\Middleware\EnforceBillingAccess::class,
        ]);

        $middleware->alias([
            'workspace'   => \App\Http\Middleware\SetActiveWorkspace::class,
            'billing'     => \App\Http\Middleware\EnforceBillingAccess::class,
            'super_admin' => \App\Http\Middleware\RequireSuperAdmin::class,
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
