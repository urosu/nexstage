<?php

use App\Http\Controllers\AdminController;
use App\Http\Controllers\AdvertisingController;
use App\Http\Controllers\BillingController;
use App\Http\Controllers\CampaignsController;
use App\Http\Controllers\SeoController;
use App\Http\Controllers\AnalyticsController;
use App\Http\Controllers\CountriesController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\FacebookOAuthController;
use App\Http\Controllers\GoogleOAuthController;
use App\Http\Controllers\ImportStatusController;
use App\Http\Controllers\InsightsController;
use App\Http\Controllers\IntegrationsController;
use App\Http\Controllers\OnboardingController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\StoreController;
use App\Http\Controllers\WinnersLosersController;
use App\Http\Controllers\WorkspaceInvitationController;
use App\Http\Controllers\WorkspaceMemberController;
use App\Http\Controllers\WorkspaceSettingsController;
use App\Http\Controllers\WorkspaceSwitchController;
use App\Http\Controllers\WorkspaceTeamController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

// ---------------------------------------------------------------------------
// Public
// ---------------------------------------------------------------------------

Route::get('/health', fn () => response()->json(['status' => 'ok']));

Route::get('/', function () {
    return auth()->check()
        ? redirect()->route('dashboard')
        : redirect()->route('login');
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
        Route::post('/onboarding/import', [OnboardingController::class, 'startImport'])->name('onboarding.import');
        Route::post('/onboarding/import/reset', [OnboardingController::class, 'resetImport'])->name('onboarding.import.reset');
        Route::post('/onboarding/reset', [OnboardingController::class, 'resetOnboarding'])->name('onboarding.reset');
    });

    // Dashboard
    Route::get('/dashboard', DashboardController::class)
        ->middleware('verified')
        ->name('dashboard');

    // Countries — workspace-level breakdown
    Route::get('/countries', CountriesController::class)
        ->middleware('verified')
        ->name('countries');

    // Analytics sub-pages
    Route::middleware('verified')->group(function (): void {
        Route::get('/analytics/products', [AnalyticsController::class, 'products'])->name('analytics.products');
        Route::get('/analytics/daily', [AnalyticsController::class, 'daily'])->name('analytics.daily');
        Route::post('/analytics/notes/{date}', [AnalyticsController::class, 'upsertNote'])
            ->where('date', '\d{4}-\d{2}-\d{2}')
            ->name('analytics.notes.upsert');
    });

    // Insights — AI summaries + alert feed
    Route::middleware('verified')->group(function (): void {
        Route::get('/insights', [InsightsController::class, 'index'])->name('insights');
        Route::post('/insights/alerts/{alert}/read', [InsightsController::class, 'markRead'])->name('insights.alerts.read');
        Route::post('/insights/alerts/{alert}/resolve', [InsightsController::class, 'resolve'])->name('insights.alerts.resolve');
    });

    // Campaigns (unified ad performance page)
    Route::middleware('verified')->group(function (): void {
        Route::get('/campaigns', CampaignsController::class)->name('campaigns.index');
        Route::get('/seo', SeoController::class)->name('seo.index');
    });

    // Legacy advertising redirects — 301 so browser + search engines update bookmarks/links
    Route::redirect('/advertising', '/campaigns', 301);
    Route::redirect('/advertising/facebook', '/campaigns?platform=facebook', 301);
    Route::redirect('/advertising/google', '/campaigns?platform=google', 301);
    Route::redirect('/advertising/winners-losers', '/campaigns?sort=real_roas', 301);

    // Stores
    Route::middleware('verified')->group(function (): void {
        Route::get('/stores', [StoreController::class, 'index'])->name('stores.index');
        Route::get('/stores/{slug}/overview', [StoreController::class, 'overview'])->name('stores.overview');
        Route::get('/stores/{slug}/products', [StoreController::class, 'products'])->name('stores.products');
        Route::get('/stores/{slug}/countries', [StoreController::class, 'countries'])->name('stores.countries');
        Route::get('/stores/{slug}/seo', [StoreController::class, 'seo'])->name('stores.seo');
        Route::get('/stores/{slug}/settings', [StoreController::class, 'settings'])->name('stores.settings');
        Route::patch('/stores/{slug}/settings', [StoreController::class, 'update'])->name('stores.update');
    });

    // Profile
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    // Workspace switching — verify membership before writing session
    Route::post('/workspaces/{workspace}/switch', WorkspaceSwitchController::class)
        ->name('workspaces.switch');

    // Accept invitation (authenticated user, arrived via /invitations/{token})
    Route::post('/invitations/{token}/accept', [WorkspaceInvitationController::class, 'accept'])
        ->name('invitations.accept');

    // -----------------------------------------------------------------------
    // Settings — require verified email
    // -----------------------------------------------------------------------

    Route::middleware('verified')->prefix('settings')->name('settings.')->group(function (): void {

        // Profile settings (GET only — update/destroy use the existing auth routes profile.update etc.)
        Route::get('/profile', [ProfileController::class, 'settingsPage'])->name('profile');

        // Workspace settings
        Route::get('/workspace', [WorkspaceSettingsController::class, 'edit'])->name('workspace');
        Route::patch('/workspace', [WorkspaceSettingsController::class, 'update'])->name('workspace.update');
        Route::delete('/workspace', [WorkspaceSettingsController::class, 'destroy'])->name('workspace.destroy');

        // Team management
        Route::get('/team', [WorkspaceTeamController::class, 'index'])->name('team');
        Route::post('/team/invite', [WorkspaceInvitationController::class, 'store'])->name('team.invite');
        Route::delete('/team/invitations/{token}', [WorkspaceInvitationController::class, 'destroy'])
            ->name('team.invitations.destroy');
        Route::patch('/team/members/{workspaceUser}', [WorkspaceMemberController::class, 'update'])
            ->name('team.members.update');
        Route::delete('/team/members/{workspaceUser}', [WorkspaceMemberController::class, 'destroy'])
            ->name('team.members.destroy');
        Route::post('/team/transfer', [WorkspaceMemberController::class, 'transfer'])
            ->name('team.transfer');

        // Integrations
        Route::get('/integrations', [IntegrationsController::class, 'show'])->name('integrations');
        Route::delete('/integrations/stores/{storeSlug}', [IntegrationsController::class, 'removeStore'])
            ->name('integrations.stores.disconnect');
        Route::delete('/integrations/ad-accounts/{adAccountId}', [IntegrationsController::class, 'removeAdAccount'])
            ->name('integrations.ad-accounts.disconnect');
        Route::delete('/integrations/gsc/{propertyId}', [IntegrationsController::class, 'removeGsc'])
            ->name('integrations.gsc.disconnect');
        Route::post('/integrations/stores/{storeSlug}/sync', [IntegrationsController::class, 'syncStore'])
            ->name('integrations.stores.sync');
        Route::post('/integrations/ad-accounts/{adAccountId}/sync', [IntegrationsController::class, 'syncAdAccount'])
            ->name('integrations.ad-accounts.sync');
        Route::post('/integrations/gsc/{propertyId}/sync', [IntegrationsController::class, 'syncGsc'])
            ->name('integrations.gsc.sync');

        // Billing — owner only (enforced in BillingController via BillingPolicy / WorkspacePolicy)
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

    // -----------------------------------------------------------------------
    // Import-status polling — session auth (SetActiveWorkspace + EnforceBillingAccess
    // are appended to the web group in bootstrap/app.php)
    // -----------------------------------------------------------------------

    Route::get('/api/stores/{slug}/import-status', ImportStatusController::class)
        ->name('api.stores.import-status');
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
// Callbacks arrive via the registered redirect URI (e.g. ngrok) and must work cross-domain.
Route::middleware(['throttle:10,1'])->prefix('oauth')->name('oauth.')->group(function (): void {
    Route::get('/facebook/callback', [FacebookOAuthController::class, 'callback'])->name('facebook.callback');
    Route::get('/google/callback', [GoogleOAuthController::class, 'callback'])->name('google.callback');
});

// ---------------------------------------------------------------------------
// Super admin panel — requires is_super_admin=true
// ---------------------------------------------------------------------------

Route::middleware(['auth', 'verified', 'super_admin'])->prefix('admin')->name('admin.')->group(function (): void {
    Route::get('/overview', [AdminController::class, 'overview'])->name('overview');
    Route::get('/logs',     [AdminController::class, 'logs'])->name('logs');
    Route::get('/workspaces', [AdminController::class, 'workspaces'])->name('workspaces');
    Route::get('/users', [AdminController::class, 'users'])->name('users');
    Route::post('/workspaces/{workspace}/sync', [AdminController::class, 'triggerSync'])->name('workspaces.sync');
    Route::patch('/workspaces/{workspace}/plan', [AdminController::class, 'setPlan'])->name('workspaces.plan');
    Route::post('/users/{user}/impersonate', [AdminController::class, 'impersonate'])->name('users.impersonate');
    Route::get('/dev/snippets', [AdminController::class, 'devSnippets'])->name('dev.snippets');
    Route::get('/dev/debug',    [AdminController::class, 'devDebug'])->name('dev.debug');
});

// Stop impersonation — auth only, no super_admin check (current user is the impersonated user)
Route::middleware(['auth'])->post('/admin/impersonation/stop', [AdminController::class, 'stopImpersonating'])->name('admin.impersonation.stop');

require __DIR__.'/auth.php';
