<?php

declare(strict_types=1);

namespace App\Services\Integrations\WooCommerce;

use App\Actions\UpsertWooCommerceOrderAction;
use App\Contracts\StoreConnector;
use App\Models\Order;
use App\Models\Refund;
use App\Models\Store;
use App\Models\StoreWebhook;
use App\Models\Workspace;
use Carbon\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * WooCommerce implementation of StoreConnector.
 *
 * Encapsulates fetch + persist for all WooCommerce data types. Sync jobs are
 * thin wrappers around these methods, responsible only for lifecycle management
 * (sync_logs, failure chain, queue scheduling).
 *
 * Triggered by: SyncStoreOrdersJob, SyncProductsJob, SyncRecentRefundsJob,
 *               ConnectStoreAction (registerWebhooks/removeWebhooks, testConnection, getStoreInfo)
 * Reads from:   WooCommerce REST API v3
 * Writes to:    orders, order_items, products, refunds, store_webhooks
 *
 * Related: app/Contracts/StoreConnector.php
 * Related: app/Services/Integrations/WooCommerce/WooCommerceClient.php
 * See: PLANNING.md "StoreConnector Interface"
 */
class WooCommerceConnector implements StoreConnector
{
    private WooCommerceClient $client;

    public function __construct(private readonly Store $store)
    {
        $this->client = new WooCommerceClient(
            domain:         $store->domain,
            consumerKey:    Crypt::decryptString($store->auth_key_encrypted),
            consumerSecret: Crypt::decryptString($store->auth_secret_encrypted),
        );
    }

    // -------------------------------------------------------------------------
    // Connection / metadata
    // -------------------------------------------------------------------------

    public function testConnection(): bool
    {
        try {
            $this->client->validateAndGetMetadata();
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @return array{name: string, currency: string, timezone: string}
     */
    public function getStoreInfo(): array
    {
        return $this->client->validateAndGetMetadata();
    }

    // -------------------------------------------------------------------------
    // Webhooks
    // -------------------------------------------------------------------------

    /**
     * Register order (+ product) webhooks and write rows to store_webhooks.
     *
     * @return array<string, int>  Map of topic → platform webhook ID.
     */
    public function registerWebhooks(): array
    {
        $webhookSecret = Crypt::decryptString($this->store->webhook_secret_encrypted);
        $webhookIds    = $this->client->registerWebhooks($this->store->id, $webhookSecret);

        $now = now()->toDateTimeString();

        foreach ($webhookIds as $topic => $platformId) {
            StoreWebhook::create([
                'store_id'            => $this->store->id,
                'workspace_id'        => $this->store->workspace_id,
                'platform_webhook_id' => (string) $platformId,
                'topic'               => $topic,
                'created_at'          => $now,
            ]);
        }

        return $webhookIds;
    }

    /**
     * Delete all active webhooks from WooCommerce and soft-delete store_webhooks rows.
     */
    public function removeWebhooks(): void
    {
        $webhooks = StoreWebhook::where('store_id', $this->store->id)->get();

        $platformIds = $webhooks
            ->mapWithKeys(fn (StoreWebhook $wh) => [$wh->topic => (int) $wh->platform_webhook_id])
            ->all();

        // 404s are silently ignored by the client; other failures are logged as warnings.
        $this->client->deleteWebhooks($platformIds);

        foreach ($webhooks as $webhook) {
            $webhook->delete(); // soft-delete for audit trail
        }
    }

    // -------------------------------------------------------------------------
    // Order sync
    // -------------------------------------------------------------------------

    /**
     * Fetch raw WooCommerce orders modified since $since without upserting.
     * Used by ReconcileStoreOrdersJob to compare against the local DB.
     *
     * @return array<int, array<string, mixed>>
     */
    public function fetchRawOrders(Carbon $since): array
    {
        return $this->client->fetchModifiedOrders($since->utc()->toIso8601String());
    }

    /**
     * Fetch orders modified since $since and upsert each one.
     *
     * Uses full pagination — fetches all pages, sleeping 500 ms between them
     * per WooCommerce rate limit guidance. The UpsertWooCommerceOrderAction handles
     * FX conversion, order items, and coupon capture.
     *
     * @return int Number of orders processed.
     */
    public function syncOrders(Carbon $since, ?Carbon $until = null): int
    {
        $modifiedAfter = $since->utc()->toIso8601String();
        $orders        = $this->client->fetchModifiedOrders($modifiedAfter);

        if (empty($orders)) {
            return 0;
        }

        $workspace         = Workspace::find($this->store->workspace_id);
        $reportingCurrency = $workspace?->reporting_currency ?? 'EUR';

        /** @var UpsertWooCommerceOrderAction $action */
        $action = app(UpsertWooCommerceOrderAction::class);

        foreach ($orders as $wcOrder) {
            $action->handle($this->store, $reportingCurrency, $wcOrder);
        }

        return count($orders);
    }

    // -------------------------------------------------------------------------
    // Product sync
    // -------------------------------------------------------------------------

    /**
     * Full sync: fetch all products from WooCommerce and upsert.
     *
     * Why: Products are a small dataset (SMBs typically have <500). Always syncing
     * everything is simpler and avoids cursor conflicts with SyncStoreOrdersJob,
     * which also updates stores.last_synced_at (used only for order audit purposes).
     *
     * @return int Number of products upserted.
     */
    public function syncProducts(): int
    {
        // Always full sync — no date filter.
        $modifiedAfter = null;

        $page       = 1;
        $totalPages = 1;
        $total      = 0;

        do {
            $result     = $this->client->fetchProductsPage($modifiedAfter, $page);
            $products   = $result['products'];
            $totalPages = $result['total_pages'];

            if (empty($products)) {
                break;
            }

            $this->upsertProducts($products);
            $total += count($products);
            $page++;
        } while ($page <= $totalPages);

        return $total;
    }

    // -------------------------------------------------------------------------
    // Refund sync
    // -------------------------------------------------------------------------

    /**
     * Fetch refunds for orders modified since $since, upsert into the refunds table,
     * and update orders.refund_amount + orders.last_refunded_at.
     *
     * WooCommerce does not expose a global /refunds endpoint. Instead we:
     *   1. Fetch orders modified since $since.
     *   2. For orders that contain at least one refund in their refunds[] array,
     *      call /orders/{id}/refunds to get full refund objects (with date, user, etc.).
     *   3. Upsert into refunds table and update the parent order row.
     *
     * @return int Number of refund records upserted.
     */
    public function syncRefunds(Carbon $since): int
    {
        $modifiedAfter = $since->utc()->toIso8601String();
        $orders        = $this->client->fetchModifiedOrders($modifiedAfter);

        $total = 0;

        foreach ($orders as $wcOrder) {
            if (empty($wcOrder['refunds'])) {
                continue;
            }

            $externalOrderId = (string) $wcOrder['id'];

            // Find the local order PK — refunds must be linked to our order row.
            $orderId = Order::where('store_id', $this->store->id)
                ->where('external_id', $externalOrderId)
                ->value('id');

            if ($orderId === null) {
                Log::warning('WooCommerceConnector::syncRefunds: order not found locally, skipping refunds', [
                    'store_id'        => $this->store->id,
                    'external_order'  => $externalOrderId,
                ]);
                continue;
            }

            try {
                $refunds = $this->client->fetchRefundsForOrder($externalOrderId);
            } catch (\Throwable $e) {
                Log::warning('WooCommerceConnector::syncRefunds: could not fetch refunds for order', [
                    'store_id'       => $this->store->id,
                    'external_order' => $externalOrderId,
                    'error'          => $e->getMessage(),
                ]);
                continue;
            }

            if (empty($refunds)) {
                continue;
            }

            $this->upsertRefunds($orderId, $refunds);
            $this->updateOrderRefundTotals($orderId);
            $total += count($refunds);
        }

        return $total;
    }

    // -------------------------------------------------------------------------
    // Capability flags
    // -------------------------------------------------------------------------

    /**
     * Returns true once at least one order item has been synced with a non-null
     * unit_cost, indicating that one of the three supported WC COGS plugins is
     * writing cost data into order item meta.
     *
     * Detection is lazy: the flag becomes true after the first successful COGS
     * sync, not before. This avoids the overhead of querying the WC API on every
     * page load, and is reliable because CogsReaderService only writes positive
     * non-zero values.
     *
     * @see PLANNING.md section 7
     */
    public function supportsHistoricalCogs(): bool
    {
        return DB::table('order_items')
            ->join('orders', 'order_items.order_id', '=', 'orders.id')
            ->where('orders.store_id', $this->store->id)
            ->whereNotNull('order_items.unit_cost')
            ->exists();
    }

    /**
     * WooCommerce (single-touch baseline) provides last-touch UTMs, referrer URL,
     * and landing page. PYS additionally surfaces first-touch, but as a parser
     * source — the connector itself does not expose first-touch natively.
     *
     * @return list<string>
     * @see PLANNING.md section 6
     */
    public function supportedAttributionFeatures(): array
    {
        return ['last_touch', 'referrer_url', 'landing_page'];
    }

    /**
     * WooCommerce is single-touch only. PYS provides first + last but not a
     * full click-by-click journey, so multi-touch remains false.
     *
     * @see PLANNING.md section 6
     */
    public function supportsMultiTouch(): bool
    {
        return false;
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Map and upsert a batch of WooCommerce product objects, including stock fields
     * and category associations.
     *
     * Products are bulk-upserted for performance. Categories are then synced
     * per-product (WooCommerce returns the full category list on each response).
     * Products with no categories in the payload are skipped to avoid wiping
     * categories on partial responses.
     *
     * @param array<int, array<string, mixed>> $wcProducts
     */
    private function upsertProducts(array $wcProducts): void
    {
        $now  = now()->toDateTimeString();
        $rows = [];

        foreach ($wcProducts as $product) {
            $imageUrl = ! empty($product['images'][0]['src'])
                ? (string) $product['images'][0]['src']
                : null;

            $price = isset($product['price']) && $product['price'] !== ''
                ? (float) $product['price']
                : null;

            $stockQuantity = isset($product['stock_quantity']) && $product['stock_quantity'] !== null
                ? (int) $product['stock_quantity']
                : null;

            $platformUpdatedAt = ! empty($product['date_modified_gmt'])
                ? Carbon::parse($product['date_modified_gmt'])->utc()->toDateTimeString()
                : null;

            $rows[] = [
                'workspace_id'        => $this->store->workspace_id,
                'store_id'            => $this->store->id,
                'external_id'         => (string) $product['id'],
                'name'                => mb_substr((string) ($product['name'] ?? ''), 0, 500),
                'slug'                => $this->nullableString($product['slug'] ?? null),
                'sku'                 => $this->nullableString($product['sku'] ?? null),
                'price'               => $price,
                'status'              => $this->nullableString($product['status'] ?? null),
                'image_url'           => $imageUrl,
                'product_url'         => $this->nullableString($product['permalink'] ?? null),
                'stock_status'        => $this->nullableString($product['stock_status'] ?? null),
                'stock_quantity'      => $stockQuantity,
                'product_type'        => $this->nullableString($product['type'] ?? null),
                'platform_updated_at' => $platformUpdatedAt,
                'created_at'          => $now,
                'updated_at'          => $now,
            ];
        }

        DB::table('products')->upsert(
            $rows,
            uniqueBy: ['store_id', 'external_id'],
            update: [
                'name', 'slug', 'sku', 'price', 'status', 'image_url', 'product_url',
                'stock_status', 'stock_quantity', 'product_type',
                'platform_updated_at', 'updated_at',
            ],
        );

        // Sync categories per product after the bulk upsert. Skip products with
        // no categories in the payload to avoid wiping data on partial responses.
        foreach ($wcProducts as $product) {
            if (empty($product['categories'])) {
                continue;
            }

            $this->syncProductCategories(
                (string) $product['id'],
                $product['categories'],
                $now,
            );
        }
    }

    /**
     * Upsert product_categories rows and replace the product_category_product pivot
     * for a single product. Called after the bulk products upsert.
     *
     * @param array<int, array<string, mixed>> $wcCategories
     */
    private function syncProductCategories(string $externalProductId, array $wcCategories, string $now): void
    {
        // Upsert category rows (name + slug; parent hierarchy not in product payload).
        $categoryRows = array_map(fn (array $cat) => [
            'workspace_id'       => $this->store->workspace_id,
            'store_id'           => $this->store->id,
            'external_id'        => (string) $cat['id'],
            'name'               => mb_substr((string) ($cat['name'] ?? ''), 0, 255),
            'slug'               => mb_substr((string) ($cat['slug'] ?? ''), 0, 255),
            'parent_external_id' => null,
            'created_at'         => $now,
        ], $wcCategories);

        DB::table('product_categories')->upsert(
            $categoryRows,
            uniqueBy: ['store_id', 'external_id'],
            update: ['name', 'slug'],
        );

        $productId = DB::table('products')
            ->where('store_id', $this->store->id)
            ->where('external_id', $externalProductId)
            ->value('id');

        if ($productId === null) {
            return;
        }

        $externalCategoryIds = array_column($wcCategories, 'id');
        $localCategoryIds    = DB::table('product_categories')
            ->where('store_id', $this->store->id)
            ->whereIn('external_id', array_map('strval', $externalCategoryIds))
            ->pluck('id')
            ->all();

        DB::table('product_category_product')
            ->where('product_id', $productId)
            ->delete();

        if (! empty($localCategoryIds)) {
            $pivotRows = array_map(
                fn (int $catId) => ['product_id' => $productId, 'category_id' => $catId],
                $localCategoryIds,
            );

            DB::table('product_category_product')->insert($pivotRows);
        }
    }

    /**
     * Upsert a list of WooCommerce refund objects for a local order.
     *
     * @param array<int, array<string, mixed>> $wcRefunds
     */
    private function upsertRefunds(int $orderId, array $wcRefunds): void
    {
        $now  = now()->toDateTimeString();
        $rows = [];

        foreach ($wcRefunds as $refund) {
            $refundedAt = ! empty($refund['date_created_gmt'])
                ? Carbon::parse($refund['date_created_gmt'])->utc()->toDateTimeString()
                : $now;

            $rows[] = [
                'order_id'             => $orderId,
                'workspace_id'         => $this->store->workspace_id,
                'platform_refund_id'   => (string) $refund['id'],
                'amount'               => abs((float) ($refund['amount'] ?? 0)),
                'reason'               => $this->nullableString($refund['reason'] ?? null),
                'refunded_by_id'       => isset($refund['refunded_by']) ? (int) $refund['refunded_by'] : null,
                'refunded_at'          => $refundedAt,
                'raw_meta'             => json_encode($refund),
                'raw_meta_api_version' => 'wc/v3',
                'created_at'           => $now,
            ];
        }

        DB::table('refunds')->upsert(
            $rows,
            uniqueBy: ['order_id', 'platform_refund_id'],
            update: ['amount', 'reason', 'refunded_by_id', 'refunded_at', 'raw_meta'],
        );
    }

    /**
     * Recompute orders.refund_amount and orders.last_refunded_at from the refunds table.
     *
     * Called after upserting refunds for a single order. Keeps the denormalized
     * totals on the order row accurate without a separate job pass.
     */
    private function updateOrderRefundTotals(int $orderId): void
    {
        $result = DB::table('refunds')
            ->where('order_id', $orderId)
            ->selectRaw('SUM(amount) as total_refunded, MAX(refunded_at) as last_refunded_at')
            ->first();

        if ($result === null) {
            return;
        }

        DB::table('orders')
            ->where('id', $orderId)
            ->update([
                'refund_amount'    => (float) ($result->total_refunded ?? 0),
                'last_refunded_at' => $result->last_refunded_at,
                'updated_at'       => now()->toDateTimeString(),
            ]);
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (string) $value;
    }
}
