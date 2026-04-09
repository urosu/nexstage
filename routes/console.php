<?php

declare(strict_types=1);

use App\Jobs\CleanupOldSyncLogsJob;
use App\Jobs\CleanupOldWebhookLogsJob;
use App\Jobs\ComputeDailySnapshotJob;
use App\Jobs\ComputeHourlySnapshotsJob;
use App\Jobs\DispatchDailySnapshots;
use App\Jobs\DispatchHourlySnapshots;
use App\Jobs\GenerateAiSummaryJob;
use App\Jobs\PurgeDeletedWorkspaceJob;
use App\Jobs\RefreshOAuthTokenJob;
use App\Jobs\ReportMonthlyRevenueToStripeJob;
use App\Jobs\RetryMissingConversionJob;
use App\Jobs\SyncAdInsightsJob;
use App\Jobs\SyncProductsJob;
use App\Jobs\SyncSearchConsoleJob;
use App\Jobs\SyncStoreOrdersJob;
use App\Jobs\UpdateFxRatesJob;
use App\Models\AdAccount;
use App\Models\SearchConsoleProperty;
use App\Models\Store;
use App\Models\Workspace;
use App\Scopes\WorkspaceScope;
use Carbon\Carbon;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// ---------------------------------------------------------------------------
// Snapshot aggregation
// ---------------------------------------------------------------------------

Schedule::call(new DispatchDailySnapshots)
    ->dailyAt('00:30')
    ->name('dispatch-daily-snapshots')
    ->withoutOverlapping(10);

Schedule::call(new DispatchHourlySnapshots)
    ->dailyAt('00:45')
    ->name('dispatch-hourly-snapshots')
    ->withoutOverlapping(10);

// ---------------------------------------------------------------------------
// AI summaries — staggered 01:00–02:00 UTC by (workspace_id % 60) minutes
// ---------------------------------------------------------------------------

Schedule::call(static function (): void {
    Workspace::withoutGlobalScope(WorkspaceScope::class)
        ->whereNull('deleted_at')
        ->select(['id'])
        ->each(static function (Workspace $workspace): void {
            $delayMinutes = $workspace->id % 60;
            GenerateAiSummaryJob::dispatch($workspace->id)
                ->delay(now()->addMinutes($delayMinutes));
        });
})->dailyAt('01:00')->name('dispatch-ai-summaries')->withoutOverlapping(10);

// ---------------------------------------------------------------------------
// Billing
// ---------------------------------------------------------------------------

Schedule::call(static function (): void {
    ReportMonthlyRevenueToStripeJob::dispatch();
})->monthlyOn(1, '06:00')->name('report-monthly-revenue-to-stripe')->withoutOverlapping(10);

// ---------------------------------------------------------------------------
// Store syncs
// ---------------------------------------------------------------------------

// Products — nightly per active WooCommerce store
Schedule::call(static function (): void {
    Store::withoutGlobalScope(WorkspaceScope::class)
        ->join('workspaces', 'stores.workspace_id', '=', 'workspaces.id')
        ->where('stores.type', 'woocommerce')
        ->where('stores.status', 'active')
        ->whereNull('workspaces.deleted_at')
        ->select(['stores.id', 'stores.workspace_id'])
        ->each(static function (Store $store): void {
            SyncProductsJob::dispatch($store->id, (int) $store->workspace_id);
        });
})->dailyAt('02:00')->name('sync-products-dispatch')->withoutOverlapping(10);

// Orders fallback — hourly per active WooCommerce store
Schedule::call(static function (): void {
    Store::withoutGlobalScope(WorkspaceScope::class)
        ->join('workspaces', 'stores.workspace_id', '=', 'workspaces.id')
        ->where('stores.type', 'woocommerce')
        ->where('stores.status', 'active')
        ->whereNull('workspaces.deleted_at')
        ->select(['stores.id', 'stores.workspace_id'])
        ->each(static function (Store $store): void {
            SyncStoreOrdersJob::dispatch($store->id, (int) $store->workspace_id);
        });
})->hourly()->name('sync-store-orders-dispatch')->withoutOverlapping(10);

// ---------------------------------------------------------------------------
// OAuth token refresh
// ---------------------------------------------------------------------------

Schedule::call(static function (): void {
    RefreshOAuthTokenJob::dispatch();
})->dailyAt('05:00')->name('refresh-oauth-tokens')->withoutOverlapping(10);

// ---------------------------------------------------------------------------
// FX rates
// ---------------------------------------------------------------------------

Schedule::job(new UpdateFxRatesJob)->dailyAt('06:00')->withoutOverlapping(10);

// ---------------------------------------------------------------------------
// Missing FX conversion retry
// ---------------------------------------------------------------------------

Schedule::call(static function (): void {
    RetryMissingConversionJob::dispatch();
})->dailyAt('07:00')->name('retry-missing-conversions')->withoutOverlapping(10);

// ---------------------------------------------------------------------------
// Ad insights — every 3 hours per active ad account
// ---------------------------------------------------------------------------

Schedule::call(static function (): void {
    AdAccount::withoutGlobalScope(WorkspaceScope::class)
        ->join('workspaces', 'ad_accounts.workspace_id', '=', 'workspaces.id')
        ->where('ad_accounts.status', 'active')
        ->whereNull('workspaces.deleted_at')
        ->select(['ad_accounts.id', 'ad_accounts.workspace_id'])
        ->each(static function (AdAccount $account): void {
            SyncAdInsightsJob::dispatch($account->id, (int) $account->workspace_id);
        });
})->everyThreeHours()->name('sync-ad-insights-dispatch')->withoutOverlapping(10);

// ---------------------------------------------------------------------------
// Search Console — every 6 hours per active property
// ---------------------------------------------------------------------------

Schedule::call(static function (): void {
    SearchConsoleProperty::withoutGlobalScope(WorkspaceScope::class)
        ->join('workspaces', 'search_console_properties.workspace_id', '=', 'workspaces.id')
        ->where('search_console_properties.status', 'active')
        ->whereNull('workspaces.deleted_at')
        ->select(['search_console_properties.id', 'search_console_properties.workspace_id'])
        ->each(static function (SearchConsoleProperty $property): void {
            SyncSearchConsoleJob::dispatch($property->id, (int) $property->workspace_id);
        });
})->everySixHours()->name('sync-search-console-dispatch')->withoutOverlapping(10);

// ---------------------------------------------------------------------------
// Weekly cleanup (Sunday)
// ---------------------------------------------------------------------------

Schedule::call(static function (): void {
    CleanupOldSyncLogsJob::dispatch();
})->weeklyOn(0, '03:00')->name('cleanup-old-sync-logs')->withoutOverlapping(10);

Schedule::call(static function (): void {
    CleanupOldWebhookLogsJob::dispatch();
})->weeklyOn(0, '03:15')->name('cleanup-old-webhook-logs')->withoutOverlapping(10);

Schedule::call(static function (): void {
    PurgeDeletedWorkspaceJob::dispatch();
})->weeklyOn(0, '05:00')->name('purge-deleted-workspaces')->withoutOverlapping(10);
