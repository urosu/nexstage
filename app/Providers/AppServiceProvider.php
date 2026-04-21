<?php

declare(strict_types=1);

namespace App\Providers;

use App\Listeners\SyncBillingPlanFromStripe;
use App\Models\Alert;
use App\Models\Store;
use App\Models\Workspace;
use App\Models\WorkspaceInvitation;
use App\Observers\AlertObserver;
use App\Observers\WorkspaceObserver;
use App\Policies\StorePolicy;
use App\Policies\WorkspaceInvitationPolicy;
use App\Policies\WorkspacePolicy;
use App\Services\Attribution\AttributionParserService;
use App\Services\Attribution\ChannelClassifierService;
use App\Services\Attribution\Sources\PixelYourSiteSource;
use App\Services\Attribution\Sources\ReferrerHeuristicSource;
use App\Services\Attribution\Sources\ShopifyCustomerJourneySource;
use App\Services\Attribution\Sources\ShopifyLandingPageSource;
use App\Services\Attribution\Sources\WooCommerceNativeSource;
use App\Services\WorkspaceContext;
use Illuminate\Auth\Middleware\RedirectIfAuthenticated;
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

        // Attribution pipeline — singleton so the source instances are shared.
        // Source order is the priority order defined in PLANNING section 6:
        //   1. PixelYourSiteSource        (highest priority — corrects WC native misattribution)
        //   2. ShopifyCustomerJourneySource (Shopify UTMs from customerJourneySummary)
        //   3. ShopifyLandingPageSource    (Shopify fallback: utm params / click IDs in landing URL)
        //   4. WooCommerceNativeSource     (orders.utm_* columns from WC 8.5+ native attribution)
        //   5. ReferrerHeuristicSource     (lowest priority — heuristic fallback)
        // WC-specific sources (4) return null for Shopify orders (no utm_* data) — they fall through.
        // Shopify-specific sources (2, 3) return null for WC orders (no platform_data) — they fall through.
        $this->app->singleton(AttributionParserService::class, static function ($app): AttributionParserService {
            return new AttributionParserService(
                sources: [
                    $app->make(PixelYourSiteSource::class),
                    $app->make(ShopifyCustomerJourneySource::class),
                    $app->make(ShopifyLandingPageSource::class),
                    $app->make(WooCommerceNativeSource::class),
                    $app->make(ReferrerHeuristicSource::class),
                ],
                classifier: $app->make(ChannelClassifierService::class),
            );
        });

        $this->app->singleton(ChannelClassifierService::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Vite::prefetch(concurrency: 3);

        // Why: the `guest` middleware calls route('dashboard') by default, but our
        // dashboard route requires a {workspace} slug parameter — causing a 500 on
        // the login page when the user is already authenticated. Redirect to /onboarding
        // instead, which forwards to the correct workspace dashboard when setup is done.
        RedirectIfAuthenticated::redirectUsing(fn () => '/onboarding');

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
        Workspace::observe(WorkspaceObserver::class);
    }
}
