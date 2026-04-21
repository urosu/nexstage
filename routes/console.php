<?php

declare(strict_types=1);

use App\Jobs\CleanupOldSyncLogsJob;
use App\Jobs\ComputeProductAffinitiesJob;
use App\Jobs\GenerateMonthlyReportJob;
use App\Jobs\SyncShopifyInventorySnapshotJob;
use App\Jobs\SyncShopifyOrdersJob;
use App\Jobs\SyncShopifyProductsJob;
use App\Jobs\SyncShopifyRefundsJob;
use App\Jobs\PollShopifyOrdersJob;
use App\Jobs\WooCommerceHistoricalImportJob;
use App\Models\Alert;
use App\Models\SyncLog;
use App\Jobs\RefreshHolidaysJob;
use App\Jobs\SeedCommercialEventsJob;
use App\Jobs\SendHolidayNotificationsJob;
use App\Jobs\CleanupOldWebhookLogsJob;
use App\Jobs\ComputeDailySnapshotJob;
use App\Jobs\ComputeHourlySnapshotsJob;
use App\Jobs\DetectStockTransitionsJob;
use App\Jobs\DispatchDailySnapshots;
use App\Jobs\DispatchHourlySnapshots;
use App\Jobs\GenerateAiSummaryJob;
use App\Jobs\PurgeDeletedWorkspaceJob;
use App\Jobs\RefreshOAuthTokenJob;
use App\Jobs\ReportMonthlyRevenueToStripeJob;
use App\Jobs\RetryMissingConversionJob;
use App\Jobs\RunLighthouseCheckJob;
use App\Jobs\SyncAdInsightsJob;
use App\Jobs\SyncProductsJob;
use App\Jobs\SyncSearchConsoleJob;
use App\Jobs\ReconcileStoreOrdersJob;
use App\Jobs\ComputeUtmCoverageJob;
use App\Jobs\SyncRecentRefundsJob;
use App\Jobs\PollStoreOrdersJob;
use App\Jobs\SyncStoreOrdersJob;
use App\Jobs\UpdateFxRatesJob;
use App\Models\AdAccount;
use App\Models\SearchConsoleProperty;
use App\Models\Store;
use App\Models\StoreUrl;
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
// Stock transition detection
//
// Runs 45 min after DispatchDailySnapshots so the per-store ComputeDailySnapshotJob
// pass has had time to write yesterday's stock_status / stock_quantity. Fires
// one DetectStockTransitionsJob per active store. The job is cheap (two-day
// join on top-50 snapshot rows), so no jitter is needed.
// See: PLANNING.md section 5.8 (Stock tracking), section 12.5 (/analytics/products)
// ---------------------------------------------------------------------------

Schedule::call(static function (): void {
    Store::withoutGlobalScope(WorkspaceScope::class)
        ->join('workspaces', 'stores.workspace_id', '=', 'workspaces.id')
        ->where('stores.status', 'active')
        ->whereNull('workspaces.deleted_at')
        ->whereRaw('NOT (workspaces.trial_ends_at < NOW() AND workspaces.billing_plan IS NULL)')
        ->select(['stores.id', 'stores.workspace_id'])
        ->each(static function (Store $store): void {
            DetectStockTransitionsJob::dispatch($store->id, (int) $store->workspace_id);
        });
})->dailyAt('01:15')->name('detect-stock-transitions-dispatch')->withoutOverlapping(10);

// ---------------------------------------------------------------------------
// AI summaries — staggered 01:00–02:00 UTC by (workspace_id % 60) minutes
// ---------------------------------------------------------------------------

Schedule::call(static function (): void {
    Workspace::withoutGlobalScope(WorkspaceScope::class)
        ->whereNull('deleted_at')
        // Why: skip frozen workspaces (trial expired + no paid plan). See PLANNING.md "14-day free trial".
        ->whereRaw('NOT (trial_ends_at < NOW() AND billing_plan IS NULL)')
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

// Monthly PDF report — renders the previous month for every active workspace
// with a store or ads. Runs two hours after the Stripe job so the month is
// well past its billing close. See PLANNING.md section 12.5.
Schedule::call(static function (): void {
    $monthStart = now()->subMonthNoOverflow()->startOfMonth()->toDateString();

    Workspace::withoutGlobalScopes()
        ->whereNull('deleted_at')
        ->whereRaw('NOT (trial_ends_at < NOW() AND billing_plan IS NULL)')
        ->where(function ($q): void {
            $q->where('has_store', true)->orWhere('has_ads', true);
        })
        ->select(['id'])
        ->each(static function (Workspace $workspace) use ($monthStart): void {
            $delayMinutes = $workspace->id % 30;
            GenerateMonthlyReportJob::dispatch($workspace->id, $monthStart)
                ->delay(now()->addMinutes($delayMinutes));
        });
})->monthlyOn(1, '08:00')->name('generate-monthly-reports')->withoutOverlapping(10);

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
        ->whereRaw('NOT (workspaces.trial_ends_at < NOW() AND workspaces.billing_plan IS NULL)')
        ->select(['stores.id', 'stores.workspace_id'])
        ->each(static function (Store $store): void {
            SyncProductsJob::dispatch($store->id, (int) $store->workspace_id);
        });
})->dailyAt('02:00')->name('sync-products-dispatch')->withoutOverlapping(10);

// Refunds — nightly per active WooCommerce store (last 7 days)
Schedule::call(static function (): void {
    Store::withoutGlobalScope(WorkspaceScope::class)
        ->join('workspaces', 'stores.workspace_id', '=', 'workspaces.id')
        ->where('stores.type', 'woocommerce')
        ->where('stores.status', 'active')
        ->whereNull('workspaces.deleted_at')
        ->whereRaw('NOT (workspaces.trial_ends_at < NOW() AND workspaces.billing_plan IS NULL)')
        ->select(['stores.id', 'stores.workspace_id'])
        ->each(static function (Store $store): void {
            SyncRecentRefundsJob::dispatch($store->id, (int) $store->workspace_id);
        });
})->dailyAt('03:30')->name('sync-recent-refunds-dispatch')->withoutOverlapping(10);

// Inventory cost snapshot — daily per active Shopify store (03:00 UTC, after ComputeDailySnapshotJob)
// Fetches InventoryItem.unitCost for every product variant and writes unit_cost into
// daily_snapshot_products so UpsertShopifyOrderAction can look up COGS by order date.
// See: PLANNING.md "Phase 2 — Shopify" Step 6
Schedule::call(static function (): void {
    Store::withoutGlobalScope(WorkspaceScope::class)
        ->join('workspaces', 'stores.workspace_id', '=', 'workspaces.id')
        ->where('stores.platform', 'shopify')
        ->where('stores.status', 'active')
        ->whereNull('workspaces.deleted_at')
        ->whereRaw('NOT (workspaces.trial_ends_at < NOW() AND workspaces.billing_plan IS NULL)')
        ->select(['stores.id', 'stores.workspace_id'])
        ->each(static function (Store $store): void {
            SyncShopifyInventorySnapshotJob::dispatch($store->id, (int) $store->workspace_id);
        });
})->dailyAt('03:00')->name('sync-shopify-inventory-snapshots')->withoutOverlapping(10);

// Reconciliation — nightly per active WooCommerce store (last 7 days vs. WC API)
// Why: catches any orders missed by webhook delivery failures or API outages.
// See: PLANNING.md "Webhook Reliability"
Schedule::call(static function (): void {
    Store::withoutGlobalScope(WorkspaceScope::class)
        ->join('workspaces', 'stores.workspace_id', '=', 'workspaces.id')
        ->where('stores.type', 'woocommerce')
        ->where('stores.status', 'active')
        ->whereNull('workspaces.deleted_at')
        ->whereRaw('NOT (workspaces.trial_ends_at < NOW() AND workspaces.billing_plan IS NULL)')
        ->select(['stores.id', 'stores.workspace_id'])
        ->each(static function (Store $store): void {
            ReconcileStoreOrdersJob::dispatch($store->id, (int) $store->workspace_id);
        });
})->dailyAt('01:30')->name('reconcile-store-orders-dispatch')->withoutOverlapping(10);

// Orders fallback — hourly per active WooCommerce store.
// PollStoreOrdersJob checks store_webhooks.last_successful_delivery_at and skips
// the API call when webhooks are arriving; only polls when quiet >90 min.
// SyncStoreOrdersJob (force=true) is used for on-demand manual syncs instead.
Schedule::call(static function (): void {
    Store::withoutGlobalScope(WorkspaceScope::class)
        ->join('workspaces', 'stores.workspace_id', '=', 'workspaces.id')
        ->where('stores.type', 'woocommerce')
        ->where('stores.status', 'active')
        ->whereNull('workspaces.deleted_at')
        ->whereRaw('NOT (workspaces.trial_ends_at < NOW() AND workspaces.billing_plan IS NULL)')
        ->select(['stores.id', 'stores.workspace_id'])
        ->each(static function (Store $store): void {
            PollStoreOrdersJob::dispatch($store->id, (int) $store->workspace_id);
        });
})->hourly()->name('poll-store-orders-dispatch')->withoutOverlapping(10);

// ---------------------------------------------------------------------------
// Shopify store syncs
// ---------------------------------------------------------------------------

// Products — nightly per active Shopify store (full sync via GraphQL)
Schedule::call(static function (): void {
    Store::withoutGlobalScope(WorkspaceScope::class)
        ->join('workspaces', 'stores.workspace_id', '=', 'workspaces.id')
        ->where('stores.platform', 'shopify')
        ->where('stores.status', 'active')
        ->whereNull('workspaces.deleted_at')
        ->whereRaw('NOT (workspaces.trial_ends_at < NOW() AND workspaces.billing_plan IS NULL)')
        ->select(['stores.id', 'stores.workspace_id'])
        ->each(static function (Store $store): void {
            SyncShopifyProductsJob::dispatch($store->id, (int) $store->workspace_id);
        });
})->dailyAt('02:00')->name('sync-shopify-products-dispatch')->withoutOverlapping(10);

// Refunds — nightly per active Shopify store (last 7 days)
Schedule::call(static function (): void {
    Store::withoutGlobalScope(WorkspaceScope::class)
        ->join('workspaces', 'stores.workspace_id', '=', 'workspaces.id')
        ->where('stores.platform', 'shopify')
        ->where('stores.status', 'active')
        ->whereNull('workspaces.deleted_at')
        ->whereRaw('NOT (workspaces.trial_ends_at < NOW() AND workspaces.billing_plan IS NULL)')
        ->select(['stores.id', 'stores.workspace_id'])
        ->each(static function (Store $store): void {
            SyncShopifyRefundsJob::dispatch($store->id, (int) $store->workspace_id);
        });
})->dailyAt('03:30')->name('sync-shopify-refunds-dispatch')->withoutOverlapping(10);

// Orders fallback — hourly per active Shopify store.
// PollShopifyOrdersJob checks last_successful_delivery_at and skips when webhooks are live.
Schedule::call(static function (): void {
    Store::withoutGlobalScope(WorkspaceScope::class)
        ->join('workspaces', 'stores.workspace_id', '=', 'workspaces.id')
        ->where('stores.platform', 'shopify')
        ->where('stores.status', 'active')
        ->whereNull('workspaces.deleted_at')
        ->whereRaw('NOT (workspaces.trial_ends_at < NOW() AND workspaces.billing_plan IS NULL)')
        ->select(['stores.id', 'stores.workspace_id'])
        ->each(static function (Store $store): void {
            PollShopifyOrdersJob::dispatch($store->id, (int) $store->workspace_id);
        });
})->hourly()->name('poll-shopify-orders-dispatch')->withoutOverlapping(10);

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
// Ad insights — hourly per active ad account
//
// Why jitter: without it every account fires simultaneously, producing a burst
// that exhausts the per-account API quota within seconds. Spreading over 20 min
// keeps the request rate flat across the hourly window.
//
// Why hourly (not every 3h): structure sync (campaigns/ads) is gated to once/23h,
// so each hourly run is insights-only (~1 API call). Hourly cadence gives 3×
// fresher data at the same total API usage as a 3h cadence with 4+ calls/sync.
// ---------------------------------------------------------------------------

Schedule::call(static function (): void {
    AdAccount::withoutGlobalScope(WorkspaceScope::class)
        ->join('workspaces', 'ad_accounts.workspace_id', '=', 'workspaces.id')
        ->where('ad_accounts.status', 'active')
        ->whereNull('workspaces.deleted_at')
        ->whereRaw('NOT (workspaces.trial_ends_at < NOW() AND workspaces.billing_plan IS NULL)')
        ->select(['ad_accounts.id', 'ad_accounts.workspace_id', 'ad_accounts.platform'])
        ->each(static function (AdAccount $account): void {
            SyncAdInsightsJob::dispatch($account->id, (int) $account->workspace_id, $account->platform)
                ->delay(now()->addSeconds(random_int(0, 1_200)));
        });
})->hourly()->name('sync-ad-insights-dispatch')->withoutOverlapping(10);

// ---------------------------------------------------------------------------
// Ad insights catch-up — hourly, for accounts that missed their sync
// ---------------------------------------------------------------------------
// Stale threshold: 90 min (1h cadence + up to 20 min jitter on regular dispatch).
// Skip window: minute >= 40 — within 20 min of the next :00 dispatch, so it's
// better to let the regular job run than double-dispatch now.
// ---------------------------------------------------------------------------

Schedule::call(static function (): void {
    // Skip if within 20 min of the next hourly dispatch.
    if (now()->minute >= 40) {
        return;
    }

    $threshold = now()->subMinutes(90);

    AdAccount::withoutGlobalScope(WorkspaceScope::class)
        ->join('workspaces', 'ad_accounts.workspace_id', '=', 'workspaces.id')
        ->where('ad_accounts.status', 'active')
        ->whereNull('workspaces.deleted_at')
        ->whereRaw('NOT (workspaces.trial_ends_at < NOW() AND workspaces.billing_plan IS NULL)')
        ->where('ad_accounts.last_synced_at', '<', $threshold)
        ->select(['ad_accounts.id', 'ad_accounts.workspace_id', 'ad_accounts.platform'])
        ->each(static function (AdAccount $account): void {
            SyncAdInsightsJob::dispatch($account->id, (int) $account->workspace_id, $account->platform);
        });
})->hourly()->name('ad-insights-catchup')->withoutOverlapping(10);

// ---------------------------------------------------------------------------
// Search Console — every 6 hours per active property
//
// Why jitter: same burst-prevention rationale as ad insights above.
// Spread over 30 min — GSC has a lower per-property quota than FB Ads.
// ---------------------------------------------------------------------------

Schedule::call(static function (): void {
    SearchConsoleProperty::withoutGlobalScope(WorkspaceScope::class)
        ->join('workspaces', 'search_console_properties.workspace_id', '=', 'workspaces.id')
        ->where('search_console_properties.status', 'active')
        ->whereNull('workspaces.deleted_at')
        ->whereRaw('NOT (workspaces.trial_ends_at < NOW() AND workspaces.billing_plan IS NULL)')
        ->select(['search_console_properties.id', 'search_console_properties.workspace_id'])
        ->each(static function (SearchConsoleProperty $property): void {
            SyncSearchConsoleJob::dispatch($property->id, (int) $property->workspace_id)
                ->delay(now()->addSeconds(random_int(0, 1_800)));
        });
})->everySixHours()->name('sync-search-console-dispatch')->withoutOverlapping(10);

// ---------------------------------------------------------------------------
// Search Console catch-up — hourly, for properties that missed their sync
// ---------------------------------------------------------------------------
// Stale threshold: 7h (6h cadence + buffer).
// Skip window: within 15 min of the next 6h boundary (00:00, 06:00, 12:00, 18:00 UTC)
// — the regular everySixHours dispatcher is about to fire, no need to catch up.
// ---------------------------------------------------------------------------

Schedule::call(static function (): void {
    // Skip if within 15 min before the next 6h boundary.
    $now          = now()->utc();
    $minuteInBlock = $now->minute + ($now->hour % 6) * 60;
    if ($minuteInBlock >= (6 * 60 - 15)) {
        return;
    }

    $threshold = now()->subHours(7);

    SearchConsoleProperty::withoutGlobalScope(WorkspaceScope::class)
        ->join('workspaces', 'search_console_properties.workspace_id', '=', 'workspaces.id')
        ->where('search_console_properties.status', 'active')
        ->whereNull('workspaces.deleted_at')
        ->whereRaw('NOT (workspaces.trial_ends_at < NOW() AND workspaces.billing_plan IS NULL)')
        ->where('search_console_properties.last_synced_at', '<', $threshold)
        ->select(['search_console_properties.id', 'search_console_properties.workspace_id'])
        ->each(static function (SearchConsoleProperty $property): void {
            SyncSearchConsoleJob::dispatch($property->id, (int) $property->workspace_id);
        });
})->hourly()->name('search-console-catchup')->withoutOverlapping(10);

// ---------------------------------------------------------------------------
// Holidays — January 1st, regenerate for all countries with active workspaces
// ---------------------------------------------------------------------------

Schedule::call(static function (): void {
    $year = (int) now()->format('Y');

    // Collect distinct, non-null country codes from all non-deleted workspaces.
    Workspace::withoutGlobalScope(WorkspaceScope::class)
        ->whereNull('deleted_at')
        ->whereNotNull('country')
        ->distinct()
        ->pluck('country')
        ->each(static function (string $countryCode) use ($year): void {
            RefreshHolidaysJob::dispatch($countryCode, $year)->onQueue('low');
        });
})->yearlyOn(1, 1, '00:15')->name('refresh-holidays')->withoutOverlapping(10);

// Curated ecommerce commercial events — 1st of every month at 00:20 UTC.
// Idempotent upsert, pure PHP, <1 s. Monthly cadence means a missed Jan 1st run
// (worker restart, deploy window) is recovered within 30 days at most.
// Always seeds current year + next year so upcoming events are always present.
Schedule::call(static function (): void {
    $year = (int) now()->format('Y');
    SeedCommercialEventsJob::dispatch($year)->onQueue('low');
    SeedCommercialEventsJob::dispatch($year + 1)->onQueue('low');
})->monthlyOn(1, '00:20')->name('seed-commercial-events')->withoutOverlapping(10);

// ---------------------------------------------------------------------------
// Holiday notifications — daily at 09:00 UTC
//
// For each workspace with holiday_notification_days > 0, check if any holiday
// for that workspace's country falls exactly N days from today. If so, email
// the workspace owner. Cache-based dedup prevents re-sends on retry.
// ---------------------------------------------------------------------------

Schedule::job(new SendHolidayNotificationsJob())
    ->dailyAt('09:00')
    ->name('send-holiday-notifications')
    ->withoutOverlapping(5);

// ---------------------------------------------------------------------------
// Stuck import detection — every 10 minutes
//
// Why: WooCommerceHistoricalImportJob writes updates after every page, so if
// a store's import_status has been "pending" >15 min or "running" >60 min
// without any DB update, the job was lost (Horizon restart, OOM, etc.).
// We mark it "failed" so the onboarding UI shows the "Try again" button
// instead of spinning forever.
// ---------------------------------------------------------------------------

Schedule::call(static function (): void {
    $now = now();

    // Pending: job was dispatched but Horizon never picked it up (or was lost).
    $pendingStuck = Store::withoutGlobalScope(WorkspaceScope::class)
        ->where('historical_import_status', 'pending')
        ->where('updated_at', '<', $now->copy()->subMinutes(15))
        ->get(['id', 'workspace_id']);

    // Running: job started but has not written a progress update in >60 minutes.
    $runningStuck = Store::withoutGlobalScope(WorkspaceScope::class)
        ->where('historical_import_status', 'running')
        ->where('updated_at', '<', $now->copy()->subMinutes(60))
        ->get(['id', 'workspace_id']);

    $stuck = $pendingStuck->merge($runningStuck);

    if ($stuck->isEmpty()) {
        return;
    }

    $errorMsg  = 'Import timed out — it may have been interrupted by a server restart. Click "Try again" to resume.';
    $stuckIds  = $stuck->pluck('id')->all();

    // Bulk-update all stuck stores in one query instead of N individual updates.
    Store::withoutGlobalScope(WorkspaceScope::class)
        ->whereIn('id', $stuckIds)
        ->update(['historical_import_status' => 'failed']);

    $syncLogRows = [];
    $alertRows   = [];

    foreach ($stuck as $store) {
        $syncLogRows[] = [
            'workspace_id'      => $store->workspace_id,
            'syncable_type'     => Store::class,
            'syncable_id'       => $store->id,
            'job_type'          => WooCommerceHistoricalImportJob::class,
            'status'            => 'failed',
            'records_processed' => 0,
            'error_message'     => $errorMsg,
            'started_at'        => $now,
            'completed_at'      => $now,
            'duration_seconds'  => 0,
            'created_at'        => $now,
            'updated_at'        => $now,
        ];

        $alertRows[] = [
            'workspace_id' => $store->workspace_id,
            'store_id'     => $store->id,
            'type'         => 'import_failed',
            'severity'     => 'warning',
            'data'         => json_encode(['job' => WooCommerceHistoricalImportJob::class, 'error' => $errorMsg]),
            'created_at'   => $now,
            'updated_at'   => $now,
        ];

        \Illuminate\Support\Facades\Log::warning('Stuck import detected and marked failed', [
            'store_id'     => $store->id,
            'workspace_id' => $store->workspace_id,
        ]);
    }

    SyncLog::insert($syncLogRows);
    Alert::insert($alertRows);
})->everyTenMinutes()->name('detect-stuck-imports')->withoutOverlapping(5);

// ---------------------------------------------------------------------------
// Lighthouse / PageSpeed Insights — daily per active store_url
// ---------------------------------------------------------------------------
// Staggered across a 4-hour window (04:00–08:00 UTC) using store_url_id % 240.
// Why: PSI quota is 25,000 req/day, and each check takes ~15–30 s.
//   Spreading checks avoids bursting the quota and keeps API latency manageable.
// Strategy: mobile only by default (see PLANNING.md "PSI Rate Limit Planning").
// ---------------------------------------------------------------------------

Schedule::call(static function (): void {
    StoreUrl::withoutGlobalScope(WorkspaceScope::class)
        ->join('workspaces', 'store_urls.workspace_id', '=', 'workspaces.id')
        ->where('store_urls.is_active', true)
        ->whereNull('workspaces.deleted_at')
        ->whereRaw('NOT (workspaces.trial_ends_at < NOW() AND workspaces.billing_plan IS NULL)')
        ->select(['store_urls.id', 'store_urls.store_id', 'store_urls.workspace_id'])
        ->each(static function (StoreUrl $storeUrl): void {
            // Stagger by store_url_id % 240 minutes within the 4-hour window.
            // Desktop is offset by 35 s so both strategies don't hit PSI simultaneously.
            $delayMinutes = $storeUrl->id % 240;
            RunLighthouseCheckJob::dispatch(
                $storeUrl->id,
                (int) $storeUrl->store_id,
                (int) $storeUrl->workspace_id,
                'mobile',
            )->delay(now()->addMinutes($delayMinutes));
            RunLighthouseCheckJob::dispatch(
                $storeUrl->id,
                (int) $storeUrl->store_id,
                (int) $storeUrl->workspace_id,
                'desktop',
            )->delay(now()->addMinutes($delayMinutes)->addSeconds(35));
        });
})->dailyAt('04:00')->name('dispatch-lighthouse-checks')->withoutOverlapping(10);

// ---------------------------------------------------------------------------
// Lighthouse catch-up — hourly, for URLs that missed today's check
// ---------------------------------------------------------------------------
// Why calendar-day instead of rolling 25h: a snapshot from yesterday afternoon
// satisfies a 25h window even when today's 04:00 UTC run was missed entirely.
// Checking against today's 04:00 UTC window start catches a missed run immediately.
//
// Skip window: 03:45–04:00 UTC — the regular dispatcher fires at 04:00 so
// there's no point catching up in that 15-min gap.
// ---------------------------------------------------------------------------

foreach (['mobile', 'desktop'] as $catchupStrategy) {
    Schedule::call(static function () use ($catchupStrategy): void {
        $now = now()->utc();

        // Skip if within 15 min before the 04:00 UTC daily dispatcher.
        $todayWindow = $now->copy()->startOfDay()->addHours(4);
        if ($now->between($todayWindow->copy()->subMinutes(15), $todayWindow)) {
            return;
        }

        // Threshold: start of today's 04:00 UTC dispatch window.
        // If it's before 04:00 UTC, look back to yesterday's window start.
        $threshold = $now->copy()->startOfDay()->addHours(4);
        if ($now->lt($threshold)) {
            $threshold->subDay();
        }

        StoreUrl::withoutGlobalScope(WorkspaceScope::class)
            ->join('workspaces', 'store_urls.workspace_id', '=', 'workspaces.id')
            ->where('store_urls.is_active', true)
            ->whereNull('workspaces.deleted_at')
            ->whereRaw('NOT (workspaces.trial_ends_at < NOW() AND workspaces.billing_plan IS NULL)')
            ->whereNotExists(static function ($query) use ($threshold, $catchupStrategy): void {
                $query->selectRaw('1')
                    ->from('lighthouse_snapshots')
                    ->whereColumn('lighthouse_snapshots.store_url_id', 'store_urls.id')
                    ->where('lighthouse_snapshots.strategy', $catchupStrategy)
                    ->where('lighthouse_snapshots.checked_at', '>=', $threshold);
            })
            ->select(['store_urls.id', 'store_urls.store_id', 'store_urls.workspace_id'])
            ->each(static function (StoreUrl $storeUrl) use ($catchupStrategy): void {
                RunLighthouseCheckJob::dispatch(
                    $storeUrl->id,
                    (int) $storeUrl->store_id,
                    (int) $storeUrl->workspace_id,
                    $catchupStrategy,
                );
            });
    })->hourly()->name("lighthouse-catchup-{$catchupStrategy}")->withoutOverlapping(10);
}

// ---------------------------------------------------------------------------
// UTM coverage — nightly per workspace with both store + ads (03:45 UTC, low queue)
// ---------------------------------------------------------------------------

Schedule::call(static function (): void {
    Workspace::withoutGlobalScopes()
        ->where('has_store', true)
        ->where('has_ads', true)
        ->whereNull('deleted_at')
        ->select('id')
        ->each(static function (Workspace $workspace): void {
            ComputeUtmCoverageJob::dispatch($workspace->id)->onQueue('low');
        });
})->dailyAt('03:45')->name('compute-utm-coverage-dispatch')->withoutOverlapping(10);

// ---------------------------------------------------------------------------
// Hourly self-healing: close sync logs orphaned by worker kills (SIGKILL, OOM,
// Docker restart). Uses each row's timeout_seconds so a 2-hour import job is
// not closed after 15 minutes. The 300 s buffer beyond timeout_seconds gives
// the finally-block / failed() handler time to close the log normally first.
// Only affects rows that explicitly recorded timeout_seconds — legacy rows and
// 'queued' placeholder logs are untouched.
// ---------------------------------------------------------------------------

Schedule::call(static function (): void {
    SyncLog::withoutGlobalScopes()
        ->where('status', 'running')
        ->whereNotNull('timeout_seconds')
        ->whereRaw("started_at + (timeout_seconds + 300) * interval '1 second' < now()")
        ->update([
            'status'        => 'failed',
            'error_message' => 'Job timed out or worker was killed before completing.',
            'completed_at'  => now(),
        ]);
})->hourly()->name('close-stuck-sync-logs')->withoutOverlapping(5);

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

// ---------------------------------------------------------------------------
// Frequently-Bought-Together — weekly Sunday per workspace (low queue)
//
// Apriori pair frequency over the last 90 days of order_items. Per-workspace
// dispatch keeps individual jobs bounded; replaces each workspace's snapshot
// atomically. Read by /analytics/products/{id} (Phase 1.6).
//
// See: PLANNING.md section 19
// ---------------------------------------------------------------------------

Schedule::call(static function (): void {
    Workspace::withoutGlobalScopes()
        ->where('has_store', true)
        ->whereNull('deleted_at')
        ->whereRaw('NOT (trial_ends_at < NOW() AND billing_plan IS NULL)')
        ->select('id')
        ->each(static function (Workspace $workspace): void {
            ComputeProductAffinitiesJob::dispatch($workspace->id)->onQueue('low');
        });
})->weeklyOn(0, '04:00')->name('compute-product-affinities-dispatch')->withoutOverlapping(10);
