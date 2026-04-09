<?php

declare(strict_types=1);

namespace App\Providers;

use App\Listeners\SyncBillingPlanFromStripe;
use App\Models\Alert;
use App\Models\Store;
use App\Models\Workspace;
use App\Models\WorkspaceInvitation;
use App\Observers\AlertObserver;
use App\Policies\StorePolicy;
use App\Policies\WorkspaceInvitationPolicy;
use App\Policies\WorkspacePolicy;
use App\Services\WorkspaceContext;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;
use Laravel\Cashier\Events\WebhookReceived;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(WorkspaceContext::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Vite::prefetch(concurrency: 3);

        $this->registerPolicies();
        $this->registerRateLimiters();
        $this->registerListeners();
    }

    private function registerPolicies(): void
    {
        Gate::policy(Store::class, StorePolicy::class);
        // WorkspacePolicy covers all workspace management + billing abilities
        Gate::policy(Workspace::class, WorkspacePolicy::class);
        Gate::policy(WorkspaceInvitation::class, WorkspaceInvitationPolicy::class);
    }

    private function registerRateLimiters(): void
    {
        // Webhook endpoint: 100 requests per minute, keyed by store_id.
        RateLimiter::for('webhooks', static function (Request $request): Limit {
            return Limit::perMinute(100)->by((string) $request->route('id'));
        });
    }

    private function registerListeners(): void
    {
        Event::listen(WebhookReceived::class, SyncBillingPlanFromStripe::class);
        Alert::observe(AlertObserver::class);
    }
}
