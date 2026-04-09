<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Exceptions\WooCommerceConnectionException;
use App\Exceptions\WooCommerceRateLimitException;
use App\Models\Alert;
use App\Models\Store;
use App\Models\SyncLog;
use App\Scopes\WorkspaceScope;
use App\Services\Integrations\WooCommerce\WooCommerceClient;
use App\Services\WorkspaceContext;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Nightly product sync for a single WooCommerce store.
 *
 * Queue:   default
 * Timeout: 300 s
 * Tries:   3
 * Backoff: default [60, 300, 900] s
 *
 * Fetches products modified after the store's last_synced_at timestamp and
 * upserts them into the products table. On first sync (no last_synced_at),
 * fetches all products.
 *
 * Scheduled daily at 02:00 UTC. Dispatched per active WooCommerce store via
 * a closure in routes/console.php.
 */
class SyncProductsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 300;
    public int $tries   = 3;

    public function __construct(
        private readonly int $storeId,
        private readonly int $workspaceId,
    ) {
        $this->onQueue('default');
    }

    public function handle(): void
    {
        app(WorkspaceContext::class)->set($this->workspaceId);

        $store = Store::find($this->storeId);

        if ($store === null || $store->status !== 'active') {
            return;
        }

        $syncLog = SyncLog::create([
            'workspace_id'      => $this->workspaceId,
            'syncable_type'     => Store::class,
            'syncable_id'       => $this->storeId,
            'job_type'          => 'SyncProductsJob',
            'status'            => 'running',
            'records_processed' => 0,
            'started_at'        => now(),
        ]);

        try {
            $count = $this->fetchAndUpsertProducts($store);

            $syncLog->update([
                'status'            => 'completed',
                'records_processed' => $count,
                'completed_at'      => now(),
                'duration_seconds'  => (int) max(0, (int) now()->diffInSeconds($syncLog->started_at)),
            ]);

            $store->update(['last_synced_at' => now()]);
            $this->onSuccess($store);

            Log::info('SyncProductsJob: completed', [
                'store_id' => $this->storeId,
                'count'    => $count,
            ]);
        } catch (WooCommerceRateLimitException $e) {
            $this->release($e->retryAfter);
            return;
        } catch (\Throwable $e) {
            $syncLog->update([
                'status'           => 'failed',
                'error_message'    => mb_substr($e->getMessage(), 0, 500),
                'completed_at'     => now(),
                'duration_seconds' => (int) max(0, (int) now()->diffInSeconds($syncLog->started_at)),
            ]);

            Log::error('SyncProductsJob: sync failed', [
                'store_id' => $this->storeId,
                'error'    => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    public function failed(\Throwable $exception): void
    {
        try {
            app(WorkspaceContext::class)->set($this->workspaceId);

            $store = Store::find($this->storeId);

            if ($store === null) {
                return;
            }

            $failures = $store->consecutive_sync_failures + 1;
            $updates  = ['consecutive_sync_failures' => $failures];

            if ($failures >= 3) {
                $updates['status'] = 'error';
            }

            $store->update($updates);

            Alert::create([
                'workspace_id'   => $this->workspaceId,
                'store_id'       => $this->storeId,
                'type'           => 'sync_failure',
                'severity'       => $failures >= 3 ? 'critical' : 'warning',
                'data'           => [
                    'job'      => 'SyncProductsJob',
                    'failures' => $failures,
                    'error'    => mb_substr($exception->getMessage(), 0, 255),
                ],
            ]);
        } catch (\Throwable $e) {
            Log::error('SyncProductsJob::failed(): could not record failure state', [
                'store_id' => $this->storeId,
                'error'    => $e->getMessage(),
            ]);
        }
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Fetch all pages of products and upsert them into the products table.
     *
     * @return int Number of products upserted.
     */
    private function fetchAndUpsertProducts(Store $store): int
    {
        $consumerKey    = Crypt::decryptString($store->auth_key_encrypted);
        $consumerSecret = Crypt::decryptString($store->auth_secret_encrypted);

        $client = new WooCommerceClient(
            domain:         $store->domain,
            consumerKey:    $consumerKey,
            consumerSecret: $consumerSecret,
        );

        // Use last_synced_at as the modified_after cursor; null = full sync.
        $modifiedAfter = $store->last_synced_at?->utc()->toIso8601String();

        $page       = 1;
        $totalPages = 1;
        $total      = 0;

        do {
            $result     = $client->fetchProductsPage($modifiedAfter, $page);
            $products   = $result['products'];
            $totalPages = $result['total_pages'];

            if (empty($products)) {
                break;
            }

            $this->upsertProducts($store, $products);
            $total += count($products);
            $page++;
        } while ($page <= $totalPages);

        return $total;
    }

    /**
     * Map and upsert a batch of WooCommerce product objects.
     *
     * @param array<int, array<string, mixed>> $wcProducts
     */
    private function upsertProducts(Store $store, array $wcProducts): void
    {
        $now  = now()->toDateTimeString();
        $rows = [];

        foreach ($wcProducts as $product) {
            $imageUrl = null;
            if (!empty($product['images'][0]['src'])) {
                $imageUrl = (string) $product['images'][0]['src'];
            }

            $price = null;
            if (isset($product['price']) && $product['price'] !== '') {
                $price = (float) $product['price'];
            }

            $platformUpdatedAt = null;
            if (!empty($product['date_modified_gmt'])) {
                $platformUpdatedAt = Carbon::parse($product['date_modified_gmt'])->utc()->toDateTimeString();
            }

            $rows[] = [
                'workspace_id'        => $store->workspace_id,
                'store_id'            => $store->id,
                'external_id'         => (string) $product['id'],
                'name'                => mb_substr((string) ($product['name'] ?? ''), 0, 500),
                'sku'                 => $this->nullableString($product['sku'] ?? null),
                'price'               => $price,
                'status'              => $this->nullableString($product['status'] ?? null),
                'image_url'           => $imageUrl,
                'product_url'         => $this->nullableString($product['permalink'] ?? null),
                'platform_updated_at' => $platformUpdatedAt,
                'created_at'          => $now,
                'updated_at'          => $now,
            ];
        }

        DB::table('products')->upsert(
            $rows,
            uniqueBy: ['store_id', 'external_id'],
            update: [
                'name', 'sku', 'price', 'status',
                'image_url', 'product_url', 'platform_updated_at', 'updated_at',
            ],
        );
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (string) $value;
    }

    private function onSuccess(Store $store): void
    {
        $updates = ['consecutive_sync_failures' => 0];

        if ($store->status === 'error') {
            $updates['status'] = 'active';
        }

        $store->update($updates);
    }
}
