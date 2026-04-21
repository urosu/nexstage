<?php

use App\Http\Controllers\AdminController;
use App\Http\Controllers\BillingController;
use App\Http\Controllers\AdSetsController;
use App\Http\Controllers\AdsController;
use App\Http\Controllers\CampaignsController;
use App\Http\Controllers\SeoController;
use App\Http\Controllers\AcquisitionController;
use App\Http\Controllers\AnalyticsController;
use App\Http\Controllers\CountriesController;
use App\Http\Controllers\DiscrepancyController;
use App\Http\Controllers\WinnersLosersController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\FacebookOAuthController;
use App\Http\Controllers\GoogleOAuthController;
use App\Http\Controllers\ShopifyOAuthController;
use App\Http\Controllers\ImportStatusController;
use App\Http\Controllers\InsightsController;
use App\Http\Controllers\ManageController;
use App\Http\Controllers\IntegrationsController;
use App\Http\Controllers\OnboardingController;
use App\Http\Controllers\OrdersController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\StoreController;
use App\Http\Controllers\StoreSetupController;
use App\Http\Controllers\WorkspaceInvitationController;
use App\Http\Controllers\WorkspaceMemberController;
use App\Http\Controllers\WorkspaceSettingsController;
use App\Http\Controllers\WorkspaceController;
use App\Http\Controllers\WorkspaceSwitchController;
use App\Http\Controllers\WorkspaceEventsController;
use App\Http\Controllers\HolidaysController;
use App\Http\Controllers\NotificationPreferencesController;
use App\Http\Controllers\WorkspaceTeamController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

// ---------------------------------------------------------------------------
// Public
// ---------------------------------------------------------------------------

Route::get('/health', fn () => response()->json(['status' => 'ok']));

Route::get('/', function () {
    if (! auth()->check()) {
        return redirect()->route('login');
    }

    // Redirect authenticated users to onboarding, which forwards to the workspace
    // dashboard when onboarding is already complete.
    return redirect('/onboarding');
});

// ---------------------------------------------------------------------------
// Invitation landing — resolves to login/register redirect or confirmation page
// ---------------------------------------------------------------------------

Route::get('/invitations/{token}', [WorkspaceInvitationController::class, 'show'])
    ->name('invitations.show');

// ---------------------------------------------------------------------------
// Authenticated routes
// ---------------------------------------------------------------------------

Route::middleware('auth')->group(function (): void {

    // Onboarding — SetActiveWorkspace skips 'onboarding/*' paths
    Route::middleware('verified')->group(function (): void {
        Route::get('/onboarding', [OnboardingController::class, 'show'])->name('onboarding');
        Route::post('/onboarding/store', [OnboardingController::class, 'connectStore'])->name('onboarding.store');
        Route::post('/onboarding/country', [OnboardingController::class, 'saveCountry'])->name('onboarding.country');
        Route::post('/onboarding/import', [OnboardingController::class, 'startImport'])->name('onboarding.import');
        Route::post('/onboarding/import/reset', [OnboardingController::class, 'resetImport'])->name('onboarding.import.reset');
        Route::post('/onboarding/reset', [OnboardingController::class, 'resetOnboarding'])->name('onboarding.reset');
    });

    // Workspace switching — POST to change active workspace; no workspace prefix here since
    // the user is switching *to* a workspace, not acting *within* one.
    Route::middleware('onboarded')->group(function (): void {
        Route::post('/workspaces/{workspace}/switch', WorkspaceSwitchController::class)
            ->name('workspaces.switch');
    });

    // Workspace creation and discard — outside the workspace prefix so they work
    // regardless of which workspace is active, and no 'onboarded' gate since
    // create() is specifically for users starting a fresh onboarding flow.
    Route::middleware('verified')->group(function (): void {
        Route::post('/workspaces', [WorkspaceController::class, 'create'])
            ->name('workspaces.create');
        Route::delete('/workspaces/{workspace}', [WorkspaceController::class, 'discard'])
            ->name('workspaces.discard');
    });

    // Accept invitation (authenticated user, arrived via /invitations/{token})
    // Not gated by onboarded — invitation acceptance is a separate entry path.
    Route::post('/invitations/{token}/accept', [WorkspaceInvitationController::class, 'accept'])
        ->name('invitations.accept');

    // Profile actions — user-level, not workspace-scoped. Kept outside the workspace
    // prefix so they work regardless of which workspace is active.
    Route::middleware('onboarded')->group(function (): void {
        Route::redirect('/profile', '/settings/profile')->name('profile.edit');
        Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
        Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
        Route::patch('/settings/view-preferences', [ProfileController::class, 'updateViewPreferences'])->name('view-preferences.update');
    });

    // -----------------------------------------------------------------------
    // Workspace-prefixed routes — all authenticated workspace pages live here.
    //
    // The {workspace:slug} segment is a 32-char hex ID (Cloudflare-style).
    // SetActiveWorkspace middleware resolves it and sets WorkspaceContext so
    // every controller and global scope gets the right tenant automatically.
    //
    // URLs look like: /{workspace-slug}/dashboard, /{workspace-slug}/campaigns, etc.
    // -----------------------------------------------------------------------

    Route::prefix('{workspace:slug}')->middleware(['verified', 'onboarded'])->group(function (): void {

        // Dashboard
        Route::get('/dashboard', DashboardController::class)->name('dashboard');
        Route::post('/dashboard/dismiss-not-tracked-banner', [DashboardController::class, 'dismissNotTrackedBanner'])
            ->name('dashboard.dismiss-not-tracked-banner');

        // Countries — workspace-level breakdown
        Route::get('/countries', CountriesController::class)->name('countries');

        // Acquisition — flagship Phase 1.6 page (channel-level attribution)
        Route::get('/acquisition', AcquisitionController::class)->name('acquisition');

        // Analytics sub-pages
        Route::get('/analytics/products', [AnalyticsController::class, 'products'])->name('analytics.products');
        Route::get('/analytics/products/{product}', [AnalyticsController::class, 'productShow'])->name('analytics.products.show');
        Route::get('/analytics/daily', [AnalyticsController::class, 'daily'])->name('analytics.daily');
        Route::get('/analytics/discrepancy', DiscrepancyController::class)->name('analytics.discrepancy');
        Route::get('/analytics/winners',     WinnersLosersController::class)->name('analytics.winners');
        Route::post('/analytics/notes/{date}', [AnalyticsController::class, 'upsertNote'])
            ->where('date', '\d{4}-\d{2}-\d{2}')
            ->name('analytics.notes.upsert');

        // Order detail — attribution journey for a single order
        Route::get('/orders/{order}', [OrdersController::class, 'show'])->name('orders.show');

        // Insights — AI summaries + alert feed
        Route::get('/insights', [InsightsController::class, 'index'])->name('insights');
        Route::post('/insights/alerts/{alert}/dismiss', [InsightsController::class, 'dismiss'])->name('insights.alerts.dismiss');
        Route::post('/insights/alerts/dismiss-all', [InsightsController::class, 'dismissAll'])->name('insights.alerts.dismiss-all');
        Route::get('/insights/monthly-report/{year}/{month}', [InsightsController::class, 'downloadMonthlyReport'])
            ->where(['year' => '\d{4}', 'month' => '\d{1,2}'])
            ->name('insights.monthly-report');

        // Campaigns (unified ad performance page)
        Route::get('/campaigns',        CampaignsController::class)->name('campaigns.index');
        Route::get('/campaigns/adsets', AdSetsController::class)->name('campaigns.adsets');
        Route::get('/campaigns/ads',    AdsController::class)->name('campaigns.ads');
        Route::get('/seo', SeoController::class)->name('seo.index');
        Route::get('/performance', \App\Http\Controllers\PerformanceController::class)->name('performance.index');

        // Manage — tools for improving data quality (UTM tag generator, etc.)
        // See: PLANNING.md "UTM Coverage Health Check + Tag Generator"
        Route::get('/manage/tag-generator', [ManageController::class, 'tagGenerator'])->name('manage.tag-generator');
        Route::get('/manage/naming-convention', [ManageController::class, 'namingConvention'])->name('manage.naming-convention');
        Route::get('/manage/channel-mappings', [ManageController::class, 'channelMappings'])->name('manage.channel-mappings');
        Route::post('/manage/channel-mappings', [ManageController::class, 'storeChannelMapping'])->name('manage.channel-mappings.store');
        Route::post('/manage/channel-mappings/import-defaults', [ManageController::class, 'importChannelMappingDefaults'])->name('manage.channel-mappings.import-defaults');
        Route::put('/manage/channel-mappings/{channelMapping}', [ManageController::class, 'updateChannelMapping'])->name('manage.channel-mappings.update');
        Route::delete('/manage/channel-mappings/{channelMapping}', [ManageController::class, 'destroyChannelMapping'])->name('manage.channel-mappings.destroy');

        // Product Costs — manual COGS entry fallback when no WC plugin or Shopify snapshot
        // template and import must come before {productCost} to avoid route binding conflict
        Route::get('/manage/product-costs', [ManageController::class, 'productCosts'])->name('manage.product-costs');
        Route::get('/manage/product-costs/template', [ManageController::class, 'productCostTemplate'])->name('manage.product-costs.template');
        Route::post('/manage/product-costs/import', [ManageController::class, 'importProductCosts'])->name('manage.product-costs.import');
        Route::post('/manage/product-costs', [ManageController::class, 'storeProductCost'])->name('manage.product-costs.store');
        Route::delete('/manage/product-costs', [ManageController::class, 'bulkDestroyProductCosts'])->name('manage.product-costs.bulk-destroy');
        Route::put('/manage/product-costs/{productCost}', [ManageController::class, 'updateProductCost'])->name('manage.product-costs.update');
        Route::delete('/manage/product-costs/{productCost}', [ManageController::class, 'destroyProductCost'])->name('manage.product-costs.destroy');

        // Holidays — reference calendar per workspace country
        Route::get('/holidays', [HolidaysController::class, 'index'])->name('holidays.index');

        // Help pages — static content
        Route::get('/help/data-accuracy', fn () => Inertia::render('Help/DataAccuracy'))->name('help.data-accuracy');

        // Stores
        Route::get('/stores', [StoreController::class, 'index'])->name('stores.index');
        Route::get('/stores/{slug}/overview', [StoreController::class, 'overview'])->name('stores.overview');
        Route::get('/stores/{slug}/products', [StoreController::class, 'products'])->name('stores.products');
        Route::get('/stores/{slug}/countries', [StoreController::class, 'countries'])->name('stores.countries');
        Route::get('/stores/{slug}/seo', [StoreController::class, 'seo'])->name('stores.seo');
        Route::get('/stores/{slug}/performance', [StoreController::class, 'performance'])->name('stores.performance');
        Route::get('/stores/{slug}/settings', [StoreController::class, 'settings'])->name('stores.settings');
        Route::patch('/stores/{slug}/settings', [StoreController::class, 'update'])->name('stores.update');
        Route::patch('/stores/{slug}/country', [StoreController::class, 'updateCountry'])->name('stores.country');
        Route::post('/stores/{slug}/urls', [StoreController::class, 'addUrl'])->name('stores.urls.add');
        Route::patch('/stores/{slug}/urls/{urlId}', [StoreController::class, 'updateUrl'])->name('stores.urls.update');
        Route::post('/stores/{slug}/urls/{urlId}/check', [StoreController::class, 'checkUrlNow'])->name('stores.urls.check');
        Route::delete('/stores/{slug}/urls/{urlId}', [StoreController::class, 'removeUrl'])->name('stores.urls.remove');

        // In-app store setup (adding a second/third store to an existing workspace).
        // Separate from /onboarding, which is only for brand-new users with no app shell.
        Route::get('/stores/connect',          [StoreSetupController::class, 'show'])        ->name('stores.connect');
        Route::post('/stores/connect',         [StoreSetupController::class, 'connect'])     ->name('stores.connect.store');
        Route::post('/stores/connect/country', [StoreSetupController::class, 'saveCountry']) ->name('stores.connect.country');
        Route::post('/stores/connect/import',  [StoreSetupController::class, 'startImport']) ->name('stores.connect.import');
        Route::post('/stores/connect/reset',   [StoreSetupController::class, 'reset'])       ->name('stores.connect.reset');

        // Import-status polling
        Route::get('/api/stores/{slug}/import-status', ImportStatusController::class)
            ->name('api.stores.import-status');

        // Settings
        Route::prefix('settings')->name('settings.')->group(function (): void {

            // Profile settings
            Route::get('/profile', [ProfileController::class, 'settingsPage'])->name('profile');

            // Workspace settings
            Route::get('/workspace', [WorkspaceSettingsController::class, 'edit'])->name('workspace');
            Route::patch('/workspace', [WorkspaceSettingsController::class, 'update'])->name('workspace.update');
            Route::delete('/workspace', [WorkspaceSettingsController::class, 'destroy'])->name('workspace.destroy');
            Route::get('/workspace/gdpr-export', [WorkspaceSettingsController::class, 'gdprExport'])->name('workspace.gdpr-export');

            // Team management
            Route::get('/team', [WorkspaceTeamController::class, 'index'])->name('team');
            Route::post('/team/invite', [WorkspaceInvitationController::class, 'store'])->name('team.invite');
            Route::delete('/team/invitations/{id}', [WorkspaceInvitationController::class, 'destroy'])
                ->name('team.invitations.destroy');
            Route::patch('/team/members/{workspaceUser}', [WorkspaceMemberController::class, 'update'])
                ->name('team.members.update');
            Route::delete('/team/members/{workspaceUser}', [WorkspaceMemberController::class, 'destroy'])
                ->name('team.members.destroy');
            Route::post('/team/transfer', [WorkspaceMemberController::class, 'transfer'])
                ->name('team.transfer');

            // Integrations
            Route::get('/integrations', [IntegrationsController::class, 'show'])->name('integrations');
            Route::delete('/integrations/stores/{slug}', [IntegrationsController::class, 'removeStore'])
                ->name('integrations.stores.disconnect');
            Route::delete('/integrations/ad-accounts/{adAccountId}', [IntegrationsController::class, 'removeAdAccount'])
                ->name('integrations.ad-accounts.disconnect');
            Route::delete('/integrations/gsc/{propertyId}', [IntegrationsController::class, 'removeGsc'])
                ->name('integrations.gsc.disconnect');
            Route::post('/integrations/stores/{slug}/sync', [IntegrationsController::class, 'syncStore'])
                ->name('integrations.stores.sync');
            Route::post('/integrations/ad-accounts/{adAccountId}/sync', [IntegrationsController::class, 'syncAdAccount'])
                ->name('integrations.ad-accounts.sync');
            Route::post('/integrations/gsc/{propertyId}/sync', [IntegrationsController::class, 'syncGsc'])
                ->name('integrations.gsc.sync');
            Route::post('/integrations/stores/{slug}/retry-import', [IntegrationsController::class, 'retryImportStore'])
                ->name('integrations.stores.retry-import');
            Route::post('/integrations/ad-accounts/{adAccountId}/retry-import', [IntegrationsController::class, 'retryImportAdAccount'])
                ->name('integrations.ad-accounts.retry-import');
            Route::post('/integrations/gsc/{propertyId}/retry-import', [IntegrationsController::class, 'retryImportGsc'])
                ->name('integrations.gsc.retry-import');
            Route::post('/integrations/stores/{slug}/reimport', [IntegrationsController::class, 'reimportStore'])
                ->name('integrations.stores.reimport');
            Route::post('/integrations/ad-accounts/{adAccountId}/reimport', [IntegrationsController::class, 'reimportAdAccount'])
                ->name('integrations.ad-accounts.reimport');
            Route::post('/integrations/gsc/{propertyId}/reimport', [IntegrationsController::class, 'reimportGsc'])
                ->name('integrations.gsc.reimport');

            // Notification preferences
            Route::get('/notifications', [NotificationPreferencesController::class, 'show'])->name('notifications');
            Route::post('/notifications', [NotificationPreferencesController::class, 'update'])->name('notifications.update');

            // Workspace events
            Route::get('/events', [WorkspaceEventsController::class, 'index'])->name('events');
            Route::post('/events', [WorkspaceEventsController::class, 'store'])->name('events.store');
            Route::patch('/events/{eventId}', [WorkspaceEventsController::class, 'update'])->name('events.update');
            Route::delete('/events/{eventId}', [WorkspaceEventsController::class, 'destroy'])->name('events.destroy');

            // Billing
            Route::get('/billing', [BillingController::class, 'show'])->name('billing');
            Route::post('/billing/subscribe', [BillingController::class, 'subscribe'])->name('billing.subscribe');
            Route::delete('/billing/cancel', [BillingController::class, 'cancel'])->name('billing.cancel');
            Route::post('/billing/resume', [BillingController::class, 'resume'])->name('billing.resume');
            Route::patch('/billing/details', [BillingController::class, 'updateDetails'])->name('billing.details');
            Route::post('/billing/payment-methods/setup-intent', [BillingController::class, 'createSetupIntent'])->name('billing.setup-intent');
            Route::post('/billing/payment-methods/{pmId}/confirm', [BillingController::class, 'confirmPaymentMethod'])->name('billing.payment-methods.confirm');
            Route::post('/billing/payment-methods/{pmId}/default', [BillingController::class, 'setDefaultPaymentMethod'])->name('billing.payment-methods.default');
            Route::delete('/billing/payment-methods/{pmId}', [BillingController::class, 'deletePaymentMethod'])->name('billing.payment-methods.delete');
            Route::get('/billing/invoices/{invoiceId}/download', [BillingController::class, 'downloadInvoice'])->name('billing.invoices.download');
        });

    }); // end workspace prefix

});

// ---------------------------------------------------------------------------
// OAuth — initiation and property selection require auth; callback does not.
// The callback is secured via HMAC-signed state (see GoogleOAuthController).
// ---------------------------------------------------------------------------

Route::middleware(['auth', 'throttle:10,1'])->prefix('oauth')->name('oauth.')->group(function (): void {
    // Facebook — redirect initiation + account selection
    Route::get('/facebook', [FacebookOAuthController::class, 'redirect'])->name('facebook.redirect');
    Route::post('/facebook/connect', [FacebookOAuthController::class, 'connectAdAccounts'])->name('facebook.connect');

    // Google — redirect initiation + account/property selection
    Route::get('/google/ads', [GoogleOAuthController::class, 'redirectGoogleAds'])->name('google.ads.redirect');
    Route::get('/google/gsc', [GoogleOAuthController::class, 'redirectGsc'])->name('google.gsc.redirect');
    Route::post('/google/ads/connect', [GoogleOAuthController::class, 'connectGoogleAdsAccounts'])->name('google.ads.connect');
    Route::post('/gsc/connect', [GoogleOAuthController::class, 'connectGscProperty'])->name('gsc.connect');
});

// OAuth callbacks — no auth middleware; HMAC-signed state verifies integrity.
Route::middleware(['throttle:10,1'])->prefix('oauth')->name('oauth.')->group(function (): void {
    Route::get('/facebook/callback', [FacebookOAuthController::class, 'callback'])->name('facebook.callback');
    Route::get('/google/callback',   [GoogleOAuthController::class, 'callback'])->name('google.callback');
});

// ---------------------------------------------------------------------------
// Shopify OAuth — install initiation requires auth; callback does not.
// Two HMAC checks: Shopify callback HMAC (prevents forged callbacks) +
// our own state HMAC (prevents CSRF). See ShopifyOAuthController.
// ---------------------------------------------------------------------------

Route::middleware(['auth', 'throttle:10,1'])->prefix('shopify')->name('shopify.')->group(function (): void {
    Route::get('/install', [ShopifyOAuthController::class, 'install'])->name('install');
});

Route::middleware(['throttle:10,1'])->prefix('shopify')->name('shopify.')->group(function (): void {
    Route::get('/callback', [ShopifyOAuthController::class, 'callback'])->name('callback');
});

// ---------------------------------------------------------------------------
// Super admin panel — requires is_super_admin=true
// ---------------------------------------------------------------------------

Route::middleware(['auth', 'verified', 'super_admin'])->prefix('admin')->name('admin.')->group(function (): void {
    Route::get('/overview', [AdminController::class, 'overview'])->name('overview');
    Route::get('/logs',     [AdminController::class, 'logs'])->name('logs');
    Route::delete('/logs',  [AdminController::class, 'clearLogs'])->name('logs.clear');
    Route::get('/queue',    [AdminController::class, 'queueJobs'])->name('queue');
    Route::get('/workspaces', [AdminController::class, 'workspaces'])->name('workspaces');
    Route::get('/users', [AdminController::class, 'users'])->name('users');
    Route::post('/workspaces/{workspace}/sync', [AdminController::class, 'triggerSync'])->name('workspaces.sync');
    Route::post('/workspaces/{workspace}/backfill-attribution', [AdminController::class, 'dispatchAttributionBackfill'])->name('workspaces.backfill_attribution');
    Route::patch('/workspaces/{workspace}/plan', [AdminController::class, 'setPlan'])->name('workspaces.plan');
    Route::post('/users/{user}/impersonate', [AdminController::class, 'impersonate'])->name('users.impersonate');
    Route::get('/system-health',  [AdminController::class, 'systemHealth'])->name('system_health');
    Route::get('/silent-alerts',  [AdminController::class, 'silentAlerts'])->name('silent_alerts');
    Route::patch('/alerts/{alert}/review', [AdminController::class, 'reviewAlert'])->name('alerts.review');
    if (! app()->environment('production')) {
        Route::get('/dev/snippets', [AdminController::class, 'devSnippets'])->name('dev.snippets');
        Route::get('/dev/debug',    [AdminController::class, 'devDebug'])->name('dev.debug');
    }
    Route::get('/attribution-debug/{orderId}', [AdminController::class, 'attributionDebug'])->name('attribution.debug');
    Route::get('/channel-mappings', [AdminController::class, 'channelMappings'])->name('channel_mappings');
    Route::post('/channel-mappings', [AdminController::class, 'storeChannelMapping'])->name('channel_mappings.store');
    Route::put('/channel-mappings/{channelMapping}', [AdminController::class, 'updateChannelMapping'])->name('channel_mappings.update');
    Route::delete('/channel-mappings/{channelMapping}', [AdminController::class, 'destroyChannelMapping'])->name('channel_mappings.destroy');
});

// Stop impersonation — auth only, no super_admin check (current user is the impersonated user)
Route::middleware(['auth'])->post('/admin/impersonation/stop', [AdminController::class, 'stopImpersonating'])->name('admin.impersonation.stop');

require __DIR__.'/auth.php';
