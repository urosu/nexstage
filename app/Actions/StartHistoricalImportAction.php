<?php

declare(strict_types=1);

namespace App\Actions;

use App\Jobs\ShopifyHistoricalImportJob;
use App\Jobs\WooCommerceHistoricalImportJob;
use App\Models\Store;
use App\Models\SyncLog;
use App\Services\Integrations\WooCommerce\WooCommerceClient;
use Carbon\Carbon;
use Illuminate\Support\Facades\Crypt;

/**
 * Prepares a store for historical import and dispatches the platform-specific job.
 *
 * Caller responsibility (per CLAUDE.md §Historical Import Flow):
 *  - Validates the date range choice (done in OnboardingController).
 *
 * WooCommerce flow:
 *  1. Fetches the total order count from the WC REST API (for progress bar).
 *  2. Writes historical_import_status = 'pending', historical_import_from,
 *     and historical_import_total_orders on the store row.
 *  3. Dispatches WooCommerceHistoricalImportJob to the 'low' queue.
 *
 * Shopify flow:
 *  1. Skips the order count pre-fetch (Shopify GraphQL cost makes this expensive).
 *  2. Sets historical_import_status = 'pending', historical_import_from.
 *  3. Dispatches ShopifyHistoricalImportJob (30-day chunks, 7200 s timeout).
 *     The job marks historical_import_status='completed' on success.
 */
class StartHistoricalImportAction
{
    public function handle(Store $store, Carbon $fromDate): void
    {
        if ($store->platform === 'shopify') {
            $this->startShopifyImport($store, $fromDate);
            return;
        }

        $this->startWooCommerceImport($store, $fromDate);
    }

    private function startShopifyImport(Store $store, Carbon $fromDate): void
    {
        $store->update([
            'historical_import_status'       => 'pending',
            'historical_import_from'         => $fromDate->toDateString(),
            'historical_import_total_orders' => null, // No cheap pre-count available for Shopify.
        ]);

        $syncLog = SyncLog::create([
            'workspace_id'  => $store->workspace_id,
            'syncable_type' => Store::class,
            'syncable_id'   => $store->id,
            'job_type'      => ShopifyHistoricalImportJob::class,
            'status'        => 'queued',
            'queue'         => 'imports',
            'scheduled_at'  => now(),
        ]);

        ShopifyHistoricalImportJob::dispatch($store->id, $store->workspace_id, $syncLog->id);
    }

    private function startWooCommerceImport(Store $store, Carbon $fromDate): void
    {
        $client = new WooCommerceClient(
            domain:         $store->domain,
            consumerKey:    Crypt::decryptString($store->auth_key_encrypted),
            consumerSecret: Crypt::decryptString($store->auth_secret_encrypted),
        );

        // Why: fetchOrderCount is a best-effort pre-fetch for the progress bar only.
        // If it fails (rate limit, temporary error, permission edge case), we still
        // dispatch the import — it will just show an indeterminate progress indicator.
        // The actual import will surface real errors through the sync_log failure chain.
        try {
            $totalOrders = $client->fetchOrderCount($fromDate->toIso8601String());
        } catch (\Throwable) {
            $totalOrders = null;
        }

        $store->update([
            'historical_import_status'       => 'pending',
            'historical_import_from'         => $fromDate->toDateString(),
            'historical_import_total_orders' => $totalOrders,
        ]);

        $syncLog = SyncLog::create([
            'workspace_id'  => $store->workspace_id,
            'syncable_type' => Store::class,
            'syncable_id'   => $store->id,
            'job_type'      => WooCommerceHistoricalImportJob::class,
            'status'        => 'queued',
            'queue'         => 'imports',
            'scheduled_at'  => now(),
        ]);

        WooCommerceHistoricalImportJob::dispatch($store->id, $store->workspace_id, $syncLog->id);
    }
}
