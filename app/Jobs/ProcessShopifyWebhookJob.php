<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Actions\UpsertShopifyOrderAction;
use App\Models\Order;
use App\Models\Store;
use App\Models\WebhookLog;
use App\Services\Integrations\Shopify\ShopifyConnector;
use App\Services\WorkspaceContext;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Processes a single Shopify webhook delivery.
 *
 * Queue:   critical-webhooks
 * Timeout: 30 s
 * Tries:   5
 * Backoff: [5, 15, 30, 60, 120] s
 *
 * Flow:
 *   1. Set WorkspaceContext so workspace-scoped models resolve correctly.
 *   2. Deduplication: skip if an identical topic+entity_id was processed within 24 h.
 *   3. Load Store and build ShopifyConnector (needs decrypted access token).
 *   4. Route to the appropriate handler by Shopify topic.
 *   5. Stamp store_webhooks.last_successful_delivery_at on success.
 *   6. Mark WebhookLog as 'processed' or 'failed'.
 *
 * Topic routing:
 *   orders/create, orders/updated → re-fetch via GraphQL → UpsertShopifyOrderAction
 *     (Shopify REST webhook payloads lack customerJourneySummary; we always re-fetch.)
 *   orders/cancelled → soft-cancel: update orders.status = 'cancelled'
 *   products/update  → upsert product from REST payload (field set is sufficient)
 *   refunds/create   → upsert refund from REST payload + update order totals
 *
 * Failures are re-thrown so Horizon retries. WebhookLog is always updated (never lost).
 *
 * Called by: ShopifyWebhookController
 * Reads from: Shopify REST webhook payload + GraphQL API (for orders)
 * Writes to: orders, order_items, order_coupons, products, refunds, webhook_logs, store_webhooks
 *
 * Related: app/Jobs/ProcessWebhookJob.php (WooCommerce equivalent)
 * See: PLANNING.md "Phase 2 — Shopify" Step 5
 */
class ProcessShopifyWebhookJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 30;
    public int $tries   = 5;

    /** @var array<int, int> */
    public array $backoff = [5, 15, 30, 60, 120];

    public function __construct(
        private readonly int    $webhookLogId,
        private readonly int    $storeId,
        private readonly int    $workspaceId,
        private readonly string $topic,
        /** @var array<string, mixed> */
        private readonly array  $payload,
    ) {
        $this->onQueue('critical-webhooks');
    }

    public function handle(UpsertShopifyOrderAction $orderAction): void
    {
        app(WorkspaceContext::class)->set($this->workspaceId);

        $externalEntityId = (string) ($this->payload['id'] ?? '');

        // --- Deduplication ---------------------------------------------------
        if ($externalEntityId !== '' && $this->isDuplicate($externalEntityId)) {
            Log::info('ProcessShopifyWebhookJob: duplicate delivery skipped', [
                'store_id'  => $this->storeId,
                'topic'     => $this->topic,
                'entity_id' => $externalEntityId,
                'log_id'    => $this->webhookLogId,
            ]);
            $this->markLog('processed', null);
            return;
        }

        // --- Load store ------------------------------------------------------
        $store = Store::find($this->storeId);

        if ($store === null) {
            $this->markLog('failed', 'Store not found');
            Log::error('ProcessShopifyWebhookJob: store not found', ['store_id' => $this->storeId]);
            return;
        }

        // --- Process topic ---------------------------------------------------
        try {
            match ($this->topic) {
                'orders/create',
                'orders/updated'  => $this->handleOrderUpsert($store, $orderAction, $externalEntityId),
                'orders/cancelled' => $this->handleOrderCancelled($externalEntityId),
                'products/update'  => $this->handleProductUpdate($store),
                'refunds/create'   => $this->handleRefundCreate($store, $externalEntityId),
                default            => null, // Unknown topic: acked but not processed.
            };

            Store::withoutGlobalScopes()
                ->where('id', $this->storeId)
                ->update(['last_synced_at' => now()]);

            $this->stampWebhookDelivery();
            $this->markLog('processed', null);
        } catch (\Throwable $e) {
            Log::error('ProcessShopifyWebhookJob: processing failed', [
                'store_id'  => $this->storeId,
                'topic'     => $this->topic,
                'entity_id' => $externalEntityId,
                'log_id'    => $this->webhookLogId,
                'error'     => $e->getMessage(),
            ]);

            $this->markLog('failed', mb_substr($e->getMessage(), 0, 500));

            throw $e; // rethrow so Horizon retries
        }
    }

    // -------------------------------------------------------------------------
    // Topic handlers
    // -------------------------------------------------------------------------

    /**
     * Re-fetch the order via GraphQL (to get customerJourneySummary + full field set)
     * and upsert via UpsertShopifyOrderAction.
     *
     * Shopify REST webhook payloads do NOT include customerJourneySummary. We always
     * re-fetch the complete GraphQL order node so attribution is populated correctly.
     */
    private function handleOrderUpsert(Store $store, UpsertShopifyOrderAction $action, string $numericOrderId): void
    {
        if ($numericOrderId === '') {
            Log::warning('ProcessShopifyWebhookJob: missing order ID', [
                'store_id' => $this->storeId,
                'topic'    => $this->topic,
            ]);
            return;
        }

        $connector = new ShopifyConnector($store);
        $orderNode = $connector->fetchOrderNode($numericOrderId);

        if ($orderNode === null) {
            Log::warning('ProcessShopifyWebhookJob: order not found on Shopify', [
                'store_id'  => $this->storeId,
                'entity_id' => $numericOrderId,
            ]);
            return;
        }

        $reportingCurrency = $store->workspace->reporting_currency ?? 'EUR';

        $action->handle($store, $reportingCurrency, $orderNode);
    }

    /**
     * Soft-cancel an order when Shopify fires orders/cancelled.
     * Never hard-deletes — the order row is preserved with status = 'cancelled'.
     */
    private function handleOrderCancelled(string $numericOrderId): void
    {
        if ($numericOrderId === '') {
            return;
        }

        Order::where('store_id', $this->storeId)
            ->where('external_id', $numericOrderId)
            ->update([
                'status'     => 'cancelled',
                'updated_at' => now()->toDateTimeString(),
            ]);
    }

    /**
     * Upsert a product from the Shopify REST products/update webhook payload.
     *
     * The REST payload has enough fields for a correct upsert: id, title, handle,
     * status, product_type, variants (price, sku, inventory_quantity), images.
     * We do NOT re-fetch via GraphQL for products (no attribution complexity).
     */
    private function handleProductUpdate(Store $store): void
    {
        $p = $this->payload;

        $externalId = (string) ($p['id'] ?? '');
        if ($externalId === '') {
            return;
        }

        $variant = ($p['variants'][0] ?? null);

        $price = $variant !== null && isset($variant['price'])
            ? (float) $variant['price']
            : null;

        $stockQuantity = $variant !== null && isset($variant['inventory_quantity'])
            ? (int) $variant['inventory_quantity']
            : null;

        $inventoryManagement = $variant['inventory_management'] ?? null;
        $stockStatus = null;

        if ($inventoryManagement !== null && $inventoryManagement !== 'none') {
            $stockStatus = ($stockQuantity !== null && $stockQuantity > 0) ? 'instock' : 'outofstock';
        }

        $imageUrl = null;
        if (! empty($p['images'][0]['src'])) {
            $imageUrl = (string) $p['images'][0]['src'];
        }

        $platformUpdatedAt = ! empty($p['updated_at'])
            ? Carbon::parse($p['updated_at'])->utc()->toDateTimeString()
            : null;

        $now = now()->toDateTimeString();

        DB::table('products')->upsert(
            [[
                'workspace_id'        => $store->workspace_id,
                'store_id'            => $store->id,
                'external_id'         => $externalId,
                'name'                => mb_substr((string) ($p['title'] ?? ''), 0, 500),
                'slug'                => mb_substr((string) ($p['handle'] ?? ''), 0, 500) ?: null,
                'sku'                 => $variant !== null ? (mb_substr((string) ($variant['sku'] ?? ''), 0, 255) ?: null) : null,
                'price'               => $price,
                'status'              => strtolower((string) ($p['status'] ?? 'active')),
                'image_url'           => $imageUrl,
                'product_url'         => $p['online_store_url'] ?? null,
                'stock_status'        => $stockStatus,
                'stock_quantity'      => $stockQuantity,
                'product_type'        => mb_substr((string) ($p['product_type'] ?? ''), 0, 255) ?: null,
                'platform_updated_at' => $platformUpdatedAt,
                'created_at'          => $now,
                'updated_at'          => $now,
            ]],
            uniqueBy: ['store_id', 'external_id'],
            update: [
                'name', 'slug', 'sku', 'price', 'status', 'image_url', 'product_url',
                'stock_status', 'stock_quantity', 'product_type',
                'platform_updated_at', 'updated_at',
            ],
        );
    }

    /**
     * Upsert a refund from the Shopify REST refunds/create webhook payload.
     *
     * REST refund payload has: id, order_id, created_at, note, transactions[].
     * We sum transaction amounts as the refund total (REST doesn't have totalRefundedSet).
     * Updates the parent order's refund_amount + last_refunded_at.
     */
    private function handleRefundCreate(Store $store, string $numericRefundId): void
    {
        if ($numericRefundId === '') {
            return;
        }

        $r = $this->payload;

        $numericOrderId = (string) ($r['order_id'] ?? '');

        if ($numericOrderId === '') {
            Log::warning('ProcessShopifyWebhookJob: refunds/create payload missing order_id', [
                'store_id'   => $this->storeId,
                'refund_id'  => $numericRefundId,
            ]);
            return;
        }

        // Find the local order row.
        $orderId = Order::withoutGlobalScopes()
            ->where('store_id', $this->storeId)
            ->where('external_id', $numericOrderId)
            ->value('id');

        if ($orderId === null) {
            Log::warning('ProcessShopifyWebhookJob: refunds/create — parent order not found locally', [
                'store_id'   => $this->storeId,
                'order_id'   => $numericOrderId,
                'refund_id'  => $numericRefundId,
            ]);
            return;
        }

        // Sum transaction amounts as refund total.
        // REST webhook doesn't have totalRefundedSet; transactions are the source of truth.
        $amount = 0.0;
        foreach ($r['transactions'] ?? [] as $tx) {
            $amount += (float) ($tx['amount'] ?? 0);
        }

        $refundedAt = ! empty($r['created_at'])
            ? Carbon::parse($r['created_at'])->utc()->toDateTimeString()
            : now()->toDateTimeString();

        $now = now()->toDateTimeString();

        DB::table('refunds')->upsert(
            [[
                'order_id'          => $orderId,
                'workspace_id'      => $store->workspace_id,
                'platform_refund_id' => $numericRefundId,
                'amount'            => $amount,
                'reason'            => mb_substr((string) ($r['note'] ?? ''), 0, 500) ?: null,
                'refunded_at'       => $refundedAt,
                'created_at'        => $now,
            ]],
            uniqueBy: ['order_id', 'platform_refund_id'],
            update: ['amount', 'reason', 'refunded_at'],
        );

        // Update parent order totals by summing all refunds for this order.
        $totals = DB::table('refunds')
            ->where('order_id', $orderId)
            ->selectRaw('SUM(amount) as total_refunded, MAX(refunded_at) as last_refunded_at')
            ->first();

        DB::table('orders')
            ->where('id', $orderId)
            ->update([
                'refund_amount'    => (float) ($totals->total_refunded ?? 0),
                'last_refunded_at' => $totals->last_refunded_at,
                'updated_at'       => $now,
            ]);
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Check if this topic+entity_id combination was already successfully processed
     * within the last 24 hours (per-spec deduplication window).
     */
    private function isDuplicate(string $externalEntityId): bool
    {
        return WebhookLog::where('store_id', $this->storeId)
            ->where('event', $this->topic)
            ->whereRaw("payload->>'id' = ?", [$externalEntityId])
            ->where('status', 'processed')
            ->where('created_at', '>=', now()->subHours(24))
            ->exists();
    }

    /**
     * Stamp store_webhooks.last_successful_delivery_at for the topic that just fired.
     * Used by PollShopifyOrdersJob (Step 7) to skip API polling when webhooks are live.
     */
    private function stampWebhookDelivery(): void
    {
        try {
            DB::table('store_webhooks')
                ->where('store_id', $this->storeId)
                ->where('topic', $this->topic)
                ->whereNull('deleted_at')
                ->update(['last_successful_delivery_at' => now()->toDateTimeString()]);
        } catch (\Throwable $e) {
            Log::warning('ProcessShopifyWebhookJob: could not stamp last_successful_delivery_at', [
                'store_id' => $this->storeId,
                'topic'    => $this->topic,
                'error'    => $e->getMessage(),
            ]);
        }
    }

    /**
     * Update the WebhookLog row. Uses DB::table() to bypass WorkspaceScope.
     */
    private function markLog(string $status, ?string $errorMessage): void
    {
        try {
            DB::table('webhook_logs')
                ->where('id', $this->webhookLogId)
                ->update([
                    'status'        => $status,
                    'error_message' => $errorMessage,
                    'processed_at'  => $status === 'processed' ? now()->toDateTimeString() : null,
                    'updated_at'    => now()->toDateTimeString(),
                ]);
        } catch (\Throwable $e) {
            Log::error('ProcessShopifyWebhookJob: failed to update webhook_log', [
                'log_id' => $this->webhookLogId,
                'error'  => $e->getMessage(),
            ]);
        }
    }
}
