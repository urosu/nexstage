<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Exceptions\ShopifyException;
use App\Models\Store;
use App\Services\Integrations\Shopify\ShopifyGraphQlClient;
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
 * Fetches the current InventoryItem.unitCost for every Shopify product variant
 * and writes (or updates) unit_cost into daily_snapshot_products for today's date.
 *
 * Queue:    sync-store
 * Timeout:  600 s (10 min) — large catalogues can have thousands of variants
 * Tries:    3
 * Backoff:  [60, 300, 900] s
 *
 * Why this exists:
 *   Shopify does not embed per-order item costs in order data. The only way to
 *   know what a product cost at a point in time is to snapshot InventoryItem.unitCost
 *   daily. UpsertShopifyOrderAction reads the most recent snapshot ≤ the order date
 *   from daily_snapshot_products.unit_cost. When no snapshot precedes the order date,
 *   the action sets platform_data.cogs_note='pre_snapshot' so the frontend can show
 *   an "Est." badge.
 *
 * Upsert key: (store_id, product_external_id, snapshot_date).
 *   Only unit_cost (and stock_status, stock_quantity) are written — revenue / units
 *   are left as-is if a row already exists (created by ComputeDailySnapshotJob).
 *   If no ComputeDailySnapshotJob row exists yet, a minimal skeleton row is created
 *   so the COGS lookup can find a cost even before any orders were placed today.
 *
 * Scheduling: dispatched daily at 03:00 UTC per active Shopify store from console.php,
 *   after ComputeDailySnapshotJob has had a chance to run (which fires at 00:30).
 *
 * Dispatched by: console.php schedule ('sync-shopify-inventory-snapshots' at 03:00)
 * Reads from:   Shopify GraphQL Admin API (products → variants → inventoryItem)
 * Writes to:    daily_snapshot_products (unit_cost, stock_status, stock_quantity)
 *
 * Related: app/Jobs/ComputeDailySnapshotJob.php
 * See: PLANNING.md "Phase 2 — Shopify" Step 6
 */
class SyncShopifyInventorySnapshotJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 600;
    public int $tries   = 3;

    /** @var array<int, int> */
    public array $backoff = [60, 300, 900];

    public function __construct(
        private readonly int $storeId,
        private readonly int $workspaceId,
    ) {
        $this->onQueue('sync-store');
    }

    public function handle(): void
    {
        $store = DB::table('stores')->where('id', $this->storeId)->where('workspace_id', $this->workspaceId)->first();

        if ($store === null) {
            Log::warning('SyncShopifyInventorySnapshotJob: store not found', [
                'store_id' => $this->storeId,
            ]);
            return;
        }

        if ($store->status !== 'active') {
            Log::info('SyncShopifyInventorySnapshotJob: store not active, skipping', [
                'store_id' => $this->storeId,
                'status'   => $store->status,
            ]);
            return;
        }

        $accessToken = Crypt::decryptString($store->access_token_encrypted);
        $apiVersion  = config('shopify.api_version');

        $gql = new ShopifyGraphQlClient(
            domain:      $store->domain,
            accessToken: $accessToken,
            apiVersion:  $apiVersion,
        );

        $snapshotDate = Carbon::today()->toDateString();
        $now          = now()->toDateTimeString();
        $total        = 0;

        $query = <<<'GQL'
        query GetProductCosts($cursor: String) {
          products(first: 50, after: $cursor) {
            edges {
              node {
                id
                legacyResourceId
                title
                status
                variants(first: 100) {
                  edges {
                    node {
                      id
                      legacyResourceId
                      sku
                      price
                      inventoryQuantity
                      inventoryItem {
                        id
                        tracked
                        unitCost {
                          amount
                          currencyCode
                        }
                      }
                    }
                  }
                }
              }
            }
            pageInfo { hasNextPage endCursor }
          }
        }
        GQL;

        try {
            foreach ($gql->paginate($query, [], fn ($d) => $d['products']) as $edges) {
                $rows = [];

                foreach ($edges as $edge) {
                    $product   = $edge['node'];
                    $productId = (string) ($product['legacyResourceId'] ?? basename($product['id']));

                    if ($productId === '') {
                        continue;
                    }

                    // Use the first variant for representative price / stock.
                    // All variants share one product row in our schema.
                    $firstVariant = $product['variants']['edges'][0]['node'] ?? null;

                    $unitCost      = null;
                    $stockQuantity = null;
                    $stockStatus   = null;

                    if ($firstVariant !== null) {
                        $invItem = $firstVariant['inventoryItem'] ?? null;

                        if ($invItem !== null) {
                            // unitCost is null when no cost has been set in Shopify.
                            if (isset($invItem['unitCost']['amount'])) {
                                $unitCost = (float) $invItem['unitCost']['amount'];
                            }

                            if ($invItem['tracked'] ?? false) {
                                $stockQuantity = (int) ($firstVariant['inventoryQuantity'] ?? 0);
                                $stockStatus   = $stockQuantity > 0 ? 'instock' : 'outofstock';
                            }
                        }
                    }

                    $productStatus = strtolower((string) ($product['status'] ?? 'active'));
                    $productName   = mb_substr((string) ($product['title'] ?? ''), 0, 500);

                    $rows[] = [
                        'workspace_id'        => $this->workspaceId,
                        'store_id'            => $this->storeId,
                        'snapshot_date'       => $snapshotDate,
                        'product_external_id' => $productId,
                        'product_name'        => $productName,
                        // Revenue + units stay 0 if ComputeDailySnapshotJob hasn't run yet.
                        // If ComputeDailySnapshotJob already wrote a row, upsert preserves
                        // its revenue/units and only updates cost + stock columns.
                        'revenue'             => 0,
                        'units'               => 0,
                        'rank'                => 0,
                        'unit_cost'           => $unitCost,
                        'stock_status'        => $stockStatus,
                        'stock_quantity'      => $stockQuantity,
                        'created_at'          => $now,
                    ];

                    $total++;
                }

                if (! empty($rows)) {
                    DB::table('daily_snapshot_products')->upsert(
                        $rows,
                        uniqueBy: ['store_id', 'product_external_id', 'snapshot_date'],
                        update: ['unit_cost', 'stock_status', 'stock_quantity'],
                    );
                    $rows = [];
                }
            }
        } catch (ShopifyException $e) {
            Log::error('SyncShopifyInventorySnapshotJob: Shopify API error', [
                'store_id' => $this->storeId,
                'error'    => $e->getMessage(),
            ]);
            throw $e;
        }

        Log::info('SyncShopifyInventorySnapshotJob: completed', [
            'store_id'      => $this->storeId,
            'snapshot_date' => $snapshotDate,
            'total'         => $total,
        ]);
    }
}
