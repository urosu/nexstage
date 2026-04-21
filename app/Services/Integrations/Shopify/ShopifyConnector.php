<?php

declare(strict_types=1);

namespace App\Services\Integrations\Shopify;

use App\Actions\UpsertShopifyOrderAction;
use App\Contracts\StoreConnector;
use App\Exceptions\ShopifyException;
use App\Models\Order;
use App\Models\Store;
use App\Models\StoreWebhook;
use App\Models\Workspace;
use Carbon\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Shopify implementation of StoreConnector.
 *
 * All Shopify API calls go through ShopifyGraphQlClient (GraphQL Admin API v2026-04+).
 * The REST Admin API is no longer used — Shopify deprecated it for new apps in 2024.
 *
 * Triggered by: ConnectShopifyStoreAction, SyncShopifyOrdersJob,
 *               SyncShopifyProductsJob, SyncShopifyRefundsJob
 * Reads from:   Shopify GraphQL Admin API
 * Writes to:    orders, order_items, products, refunds, store_webhooks
 *
 * Webhook verification: Shopify signs deliveries with the app-level client_secret,
 * not a per-store secret. See VerifyShopifyWebhookSignature.
 *
 * See: PLANNING.md "Phase 2 — Shopify"
 * @see PLANNING.md section Phase 2
 */
class ShopifyConnector implements StoreConnector
{
    private ShopifyGraphQlClient $gql;

    /** Maps our internal REST-style topic names to GraphQL enum values. */
    private const TOPIC_MAP = [
        'orders/create'    => 'ORDERS_CREATE',
        'orders/updated'   => 'ORDERS_UPDATED',
        'orders/cancelled' => 'ORDERS_CANCELLED',
        'products/update'  => 'PRODUCTS_UPDATE',
        'refunds/create'   => 'REFUNDS_CREATE',
    ];

    public function __construct(private readonly Store $store)
    {
        $this->gql = new ShopifyGraphQlClient(
            domain:      $store->domain,
            accessToken: Crypt::decryptString($store->access_token_encrypted),
            apiVersion:  config('shopify.api_version'),
        );
    }

    // -------------------------------------------------------------------------
    // Connection / metadata
    // -------------------------------------------------------------------------

    public function testConnection(): bool
    {
        try {
            $this->gql->getShop();
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @return array{name: string, currency: string, timezone: string}
     *
     * @throws ShopifyException
     */
    public function getStoreInfo(): array
    {
        return $this->gql->getShop();
    }

    // -------------------------------------------------------------------------
    // Webhooks
    // -------------------------------------------------------------------------

    /**
     * Register Shopify webhook subscriptions for order and product events.
     *
     * Shopify HMAC is verified with the app-level client_secret, not a per-store
     * secret. We still store the platform_webhook_id so we can delete webhooks on
     * store removal — but webhook_secret_encrypted is not used for Shopify stores.
     *
     * @return array<string, string>  Map of topic → platform webhook ID.
     *
     * @throws ShopifyException
     */
    public function registerWebhooks(): array
    {
        $callbackUrl = rtrim(config('app.url'), '/') . '/api/webhooks/shopify/' . $this->store->id;
        $registered  = [];

        foreach (self::TOPIC_MAP as $topic => $gqlTopic) {
            try {
                $webhook = $this->gql->createWebhookSubscription($gqlTopic, $callbackUrl);

                StoreWebhook::create([
                    'store_id'            => $this->store->id,
                    'workspace_id'        => $this->store->workspace_id,
                    'platform_webhook_id' => $webhook['id'], // GID, e.g. gid://shopify/WebhookSubscription/123
                    'topic'               => $topic,
                ]);

                $registered[$topic] = $webhook['id'];

                Log::info('ShopifyConnector: webhook registered', [
                    'store_id'   => $this->store->id,
                    'topic'      => $topic,
                    'webhook_id' => $webhook['id'],
                ]);
            } catch (ShopifyException $e) {
                Log::error('ShopifyConnector: failed to register webhook', [
                    'store_id' => $this->store->id,
                    'topic'    => $topic,
                    'error'    => $e->getMessage(),
                ]);
                throw $e;
            }
        }

        return $registered;
    }

    /**
     * Delete all active Shopify webhook subscriptions for this store and
     * soft-delete the store_webhooks rows.
     *
     * Failures are logged but do not throw — webhook cleanup should never block
     * store deletion. This mirrors the WooCommerce connector behaviour.
     */
    public function removeWebhooks(): void
    {
        $webhooks = StoreWebhook::withoutGlobalScopes()
            ->where('store_id', $this->store->id)
            ->whereNull('deleted_at')
            ->get();

        foreach ($webhooks as $webhook) {
            try {
                $this->gql->deleteWebhookSubscription($webhook->platform_webhook_id);
            } catch (\Throwable $e) {
                Log::warning('ShopifyConnector: failed to delete webhook from Shopify', [
                    'store_id'   => $this->store->id,
                    'webhook_id' => $webhook->platform_webhook_id,
                    'topic'      => $webhook->topic,
                    'error'      => $e->getMessage(),
                ]);
            }

            $webhook->delete();
        }
    }

    // -------------------------------------------------------------------------
    // Order sync
    // -------------------------------------------------------------------------

    /**
     * Fetch orders updated since $since via GraphQL and upsert each one.
     *
     * Uses cursor-based pagination (50 orders per page). Line items are fetched
     * in the same request (up to 250 per order — Shopify max per nested connection).
     *
     * Field mapping: see UpsertShopifyOrderAction (Step 3).
     *
     * @return int  Number of orders processed.
     *
     * @throws ShopifyException
     */
    public function syncOrders(Carbon $since, ?Carbon $until = null): int
    {
        $gql = <<<'GQL'
        query GetOrders($cursor: String, $query: String) {
          orders(first: 50, after: $cursor, query: $query, sortKey: UPDATED_AT) {
            edges {
              node {
                id
                name
                displayFinancialStatus
                createdAt
                updatedAt
                currentTotalPriceSet { shopMoney { amount currencyCode } }
                subtotalPriceSet     { shopMoney { amount currencyCode } }
                totalTaxSet          { shopMoney { amount currencyCode } }
                totalShippingPriceSet { shopMoney { amount currencyCode } }
                totalDiscountsSet    { shopMoney { amount currencyCode } }
                email
                billingAddress  { countryCode }
                shippingAddress { countryCode }
                paymentGatewayNames
                customerJourneySummary {
                  firstVisit {
                    utmParameters { source medium campaign content term }
                    landingPage
                    referrerUrl
                  }
                  lastVisit {
                    utmParameters { source medium campaign content term }
                    landingPage
                    referrerUrl
                  }
                }
                lineItems(first: 250) {
                  edges {
                    node {
                      id
                      title
                      quantity
                      sku
                      product { id legacyResourceId }
                      variant  { id legacyResourceId title }
                      originalUnitPriceSet { shopMoney { amount } }
                      discountAllocations {
                        allocatedAmountSet { shopMoney { amount } }
                      }
                    }
                  }
                }
                discountCodes
                refunds {
                  id
                  createdAt
                  note
                  totalRefundedSet { shopMoney { amount currencyCode } }
                  refundLineItems(first: 50) {
                    edges {
                      node {
                        quantity
                        lineItem { id }
                        subtotalSet { shopMoney { amount } }
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

        $queryFilter = 'updated_at:>' . $since->utc()->toIso8601String();
        if ($until !== null) {
            $queryFilter .= ' updated_at:<=' . $until->utc()->toIso8601String();
        }

        $workspace         = Workspace::find($this->store->workspace_id);
        $reportingCurrency = $workspace?->reporting_currency ?? 'EUR';

        /** @var UpsertShopifyOrderAction $action */
        $action = app(UpsertShopifyOrderAction::class);
        $total  = 0;

        foreach ($this->gql->paginate($gql, ['query' => $queryFilter], fn ($d) => $d['orders']) as $edges) {
            foreach ($edges as $edge) {
                $action->handle($this->store, $reportingCurrency, $edge['node']);
                $total++;
            }
        }

        return $total;
    }

    // -------------------------------------------------------------------------
    // Product sync
    // -------------------------------------------------------------------------

    /**
     * Full sync: fetch all Shopify products and upsert into the products table.
     *
     * Shopify products have variants; we map the first (or cheapest) variant's
     * price as the product price. All variants share the same product record in
     * our schema; variant IDs are stored on order_items, not on products.
     *
     * Product categories are not available in the standard GraphQL products query
     * (they require a separate productTaxonomyNode query). We skip category sync
     * for Shopify — the products table rows exist and are linkable to order_items.
     *
     * @return int  Number of products upserted.
     *
     * @throws ShopifyException
     */
    public function syncProducts(): int
    {
        $gql = <<<'GQL'
        query GetProducts($cursor: String) {
          products(first: 50, after: $cursor) {
            edges {
              node {
                id
                legacyResourceId
                title
                handle
                status
                onlineStoreUrl
                updatedAt
                featuredMedia {
                  ... on MediaImage {
                    image { url }
                  }
                }
                variants(first: 1) {
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
                      }
                    }
                  }
                }
                productType
              }
            }
            pageInfo { hasNextPage endCursor }
          }
        }
        GQL;

        $now   = now()->toDateTimeString();
        $total = 0;
        $rows  = [];

        foreach ($this->gql->paginate($gql, [], fn ($d) => $d['products']) as $edges) {
            foreach ($edges as $edge) {
                $product  = $edge['node'];
                $variant  = $edge['node']['variants']['edges'][0]['node'] ?? null;

                $imageUrl = null;
                if (isset($product['featuredMedia']['image']['url'])) {
                    $imageUrl = (string) $product['featuredMedia']['image']['url'];
                }

                $price = $variant !== null && isset($variant['price'])
                    ? (float) $variant['price']
                    : null;

                $stockQuantity = $variant !== null && isset($variant['inventoryQuantity'])
                    ? (int) $variant['inventoryQuantity']
                    : null;

                // Shopify statuses: ACTIVE, ARCHIVED, DRAFT
                $status = strtolower((string) ($product['status'] ?? 'active'));

                // Infer stock_status from stock_quantity when inventory is tracked.
                $stockStatus = null;
                if ($variant !== null && ($variant['inventoryItem']['tracked'] ?? false)) {
                    $stockStatus = ($stockQuantity !== null && $stockQuantity > 0) ? 'instock' : 'outofstock';
                }

                $platformUpdatedAt = ! empty($product['updatedAt'])
                    ? \Carbon\Carbon::parse($product['updatedAt'])->utc()->toDateTimeString()
                    : null;

                $rows[] = [
                    'workspace_id'        => $this->store->workspace_id,
                    'store_id'            => $this->store->id,
                    'external_id'         => (string) ($product['legacyResourceId'] ?? ltrim($product['id'], 'gid://shopify/Product/')),
                    'name'                => mb_substr((string) ($product['title'] ?? ''), 0, 500),
                    'slug'                => mb_substr((string) ($product['handle'] ?? ''), 0, 500) ?: null,
                    'sku'                 => $variant !== null ? (mb_substr((string) ($variant['sku'] ?? ''), 0, 255) ?: null) : null,
                    'price'               => $price,
                    'status'              => $status,
                    'image_url'           => $imageUrl,
                    'product_url'         => $product['onlineStoreUrl'] ?? null,
                    'stock_status'        => $stockStatus,
                    'stock_quantity'      => $stockQuantity,
                    'product_type'        => mb_substr((string) ($product['productType'] ?? ''), 0, 255) ?: null,
                    'platform_updated_at' => $platformUpdatedAt,
                    'created_at'          => $now,
                    'updated_at'          => $now,
                ];

                $total++;
            }

            // Bulk-upsert after each page to keep memory usage flat.
            if (! empty($rows)) {
                DB::table('products')->upsert(
                    $rows,
                    uniqueBy: ['store_id', 'external_id'],
                    update: [
                        'name', 'slug', 'sku', 'price', 'status', 'image_url', 'product_url',
                        'stock_status', 'stock_quantity', 'product_type',
                        'platform_updated_at', 'updated_at',
                    ],
                );
                $rows = [];
            }
        }

        // Upsert any remaining rows from the last partial page.
        if (! empty($rows)) {
            DB::table('products')->upsert(
                $rows,
                uniqueBy: ['store_id', 'external_id'],
                update: [
                    'name', 'slug', 'sku', 'price', 'status', 'image_url', 'product_url',
                    'stock_status', 'stock_quantity', 'product_type',
                    'platform_updated_at', 'updated_at',
                ],
            );
        }

        return $total;
    }

    // -------------------------------------------------------------------------
    // Refund sync
    // -------------------------------------------------------------------------

    /**
     * Fetch refunds for orders updated since $since and upsert into the refunds table.
     *
     * Shopify does not expose a top-level global refunds GraphQL endpoint.
     * We query orders modified since $since and extract their nested refunds,
     * mirroring the WooCommerce approach (syncRefunds iterates modified orders).
     *
     * Refund totals are upserted into the refunds table and the parent order's
     * refund_amount + last_refunded_at are updated to match.
     *
     * @return int  Number of refund records upserted.
     *
     * @throws ShopifyException
     */
    public function syncRefunds(Carbon $since): int
    {
        $gql = <<<'GQL'
        query GetOrderRefunds($cursor: String, $query: String) {
          orders(first: 50, after: $cursor, query: $query, sortKey: UPDATED_AT) {
            edges {
              node {
                id
                legacyResourceId
                refunds {
                  id
                  legacyResourceId
                  createdAt
                  note
                  totalRefundedSet { shopMoney { amount currencyCode } }
                }
              }
            }
            pageInfo { hasNextPage endCursor }
          }
        }
        GQL;

        $queryFilter = 'updated_at:>' . $since->utc()->toIso8601String();
        $now         = now()->toDateTimeString();
        $total       = 0;

        foreach ($this->gql->paginate($gql, ['query' => $queryFilter], fn ($d) => $d['orders']) as $edges) {
            foreach ($edges as $edge) {
                $orderNode = $edge['node'];
                $refunds   = $orderNode['refunds'] ?? [];

                if (empty($refunds)) {
                    continue;
                }

                $externalOrderId = (string) ($orderNode['legacyResourceId']
                    ?? ltrim($orderNode['id'], 'gid://shopify/Order/'));

                // Find the local order — refunds must link to our order row.
                $orderId = Order::withoutGlobalScopes()
                    ->where('workspace_id', $this->store->workspace_id)
                    ->where('store_id', $this->store->id)
                    ->where('external_id', $externalOrderId)
                    ->value('id');

                if ($orderId === null) {
                    Log::warning('ShopifyConnector::syncRefunds: order not found locally', [
                        'store_id'       => $this->store->id,
                        'external_order' => $externalOrderId,
                    ]);
                    continue;
                }

                $refundRows = [];
                $totalRefunded = 0.0;
                $lastRefundedAt = null;

                foreach ($refunds as $refund) {
                    $externalRefundId = (string) ($refund['legacyResourceId']
                        ?? ltrim($refund['id'], 'gid://shopify/Refund/'));

                    $amount = (float) ($refund['totalRefundedSet']['shopMoney']['amount'] ?? 0);
                    $refundedAt = ! empty($refund['createdAt'])
                        ? \Carbon\Carbon::parse($refund['createdAt'])->utc()->toDateTimeString()
                        : $now;

                    $refundRows[] = [
                        'order_id'          => $orderId,
                        'store_id'          => $this->store->id,
                        'workspace_id'      => $this->store->workspace_id,
                        'external_id'       => $externalRefundId,
                        'amount'            => $amount,
                        'reason'            => mb_substr((string) ($refund['note'] ?? ''), 0, 500) ?: null,
                        'refunded_at'       => $refundedAt,
                        'created_at'        => $now,
                        'updated_at'        => $now,
                    ];

                    $totalRefunded += $amount;
                    if ($lastRefundedAt === null || $refundedAt > $lastRefundedAt) {
                        $lastRefundedAt = $refundedAt;
                    }
                }

                if (! empty($refundRows)) {
                    DB::table('refunds')->upsert(
                        $refundRows,
                        uniqueBy: ['store_id', 'external_id'],
                        update: ['amount', 'reason', 'refunded_at', 'updated_at'],
                    );

                    // Update parent order refund totals.
                    DB::table('orders')
                        ->where('id', $orderId)
                        ->update([
                            'refund_amount'    => $totalRefunded,
                            'last_refunded_at' => $lastRefundedAt,
                            'updated_at'       => $now,
                        ]);

                    $total += count($refundRows);
                }
            }
        }

        return $total;
    }

    // -------------------------------------------------------------------------
    // Single-entity fetches (used by ProcessShopifyWebhookJob)
    // -------------------------------------------------------------------------

    /**
     * Fetch a single Shopify order by its numeric ID via GraphQL.
     *
     * Used by ProcessShopifyWebhookJob to re-fetch the full order node after a
     * webhook delivery. Shopify REST webhook payloads lack customerJourneySummary,
     * so we always re-fetch via GraphQL to get the complete shape that
     * UpsertShopifyOrderAction expects.
     *
     * @return array<string, mixed>|null  Raw GraphQL order node, or null if not found.
     *
     * @throws ShopifyException
     */
    public function fetchOrderNode(string $numericOrderId): ?array
    {
        $gid = "gid://shopify/Order/{$numericOrderId}";

        $gql = <<<'GQL'
        query FetchOrder($id: ID!) {
          order(id: $id) {
            id
            legacyResourceId
            name
            displayFinancialStatus
            createdAt
            updatedAt
            currentTotalPriceSet { shopMoney { amount currencyCode } }
            subtotalPriceSet     { shopMoney { amount currencyCode } }
            totalTaxSet          { shopMoney { amount currencyCode } }
            totalShippingPriceSet { shopMoney { amount currencyCode } }
            totalDiscountsSet    { shopMoney { amount currencyCode } }
            email
            billingAddress  { countryCode }
            shippingAddress { countryCode }
            paymentGatewayNames
            customerJourneySummary {
              firstVisit {
                utmParameters { source medium campaign content term }
                landingPage
                referrerUrl
              }
              lastVisit {
                utmParameters { source medium campaign content term }
                landingPage
                referrerUrl
              }
            }
            lineItems(first: 250) {
              edges {
                node {
                  id
                  title
                  quantity
                  sku
                  product { id legacyResourceId }
                  variant  { id legacyResourceId title }
                  originalUnitPriceSet { shopMoney { amount } }
                  discountAllocations {
                    allocatedAmountSet { shopMoney { amount } }
                  }
                }
              }
            }
            discountCodes
          }
        }
        GQL;

        $result = $this->gql->query($gql, ['id' => $gid]);

        return $result['order'] ?? null;
    }

    // -------------------------------------------------------------------------
    // Capability flags
    // -------------------------------------------------------------------------

    /**
     * Shopify does not expose historical per-order item costs. Only the current
     * InventoryItem.unitCost is available. COGS is approximated via daily snapshots.
     *
     * @see PLANNING.md section 7 "Shopify — daily snapshot fallback"
     */
    public function supportsHistoricalCogs(): bool
    {
        return false;
    }

    /**
     * Shopify's customerJourneySummary exposes first + last visit UTM parameters,
     * which is a superset of WooCommerce native single-touch attribution.
     *
     * @return list<string>
     */
    public function supportedAttributionFeatures(): array
    {
        return ['first_touch', 'last_touch', 'multi_touch_journey', 'referrer_url', 'landing_page'];
    }

    public function supportsMultiTouch(): bool
    {
        return true;
    }
}
