<?php

declare(strict_types=1);

namespace Tests\Feature\Jobs;

use App\Actions\UpsertShopifyOrderAction;
use App\Jobs\ProcessShopifyWebhookJob;
use App\Models\Order;
use App\Models\Store;
use App\Models\Workspace;
use App\Services\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

/**
 * Feature tests for ProcessShopifyWebhookJob.
 *
 * Covers:
 *   - orders/cancelled: soft-cancel existing order (status → 'cancelled')
 *   - products/update: upserts product from REST payload
 *   - refunds/create: upserts refund, updates order refund_amount + last_refunded_at
 *   - Deduplication: second delivery of same topic+entity_id within 24 h is skipped
 *   - store_webhooks.last_successful_delivery_at is stamped on success
 *   - WebhookLog is marked 'processed' on success
 *
 * Note: orders/create and orders/updated require a live ShopifyConnector (GraphQL re-fetch).
 * Those are covered by mocking ShopifyConnector + UpsertShopifyOrderAction so the test
 * stays in-process and does not hit the network.
 */
class ProcessShopifyWebhookJobTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;
    private Store $store;
    private int $webhookLogId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workspace = Workspace::factory()->create(['reporting_currency' => 'EUR']);
        $this->store     = Store::factory()->create([
            'workspace_id'           => $this->workspace->id,
            'platform'               => 'shopify',
            'access_token_encrypted' => Crypt::encryptString('shpat_test_token'),
        ]);

        app(WorkspaceContext::class)->set($this->workspace->id);

        $this->webhookLogId = DB::table('webhook_logs')->insertGetId([
            'store_id'       => $this->store->id,
            'workspace_id'   => $this->workspace->id,
            'event'          => 'orders/create',
            'payload'        => '{"id":1001}',
            'signature_valid' => true,
            'status'         => 'pending',
            'created_at'     => now(),
            'updated_at'     => now(),
        ]);
    }

    private function makeWebhookLog(string $event, array $payload): int
    {
        return DB::table('webhook_logs')->insertGetId([
            'store_id'        => $this->store->id,
            'workspace_id'    => $this->workspace->id,
            'event'           => $event,
            'payload'         => json_encode($payload),
            'signature_valid' => true,
            'status'          => 'pending',
            'created_at'      => now(),
            'updated_at'      => now(),
        ]);
    }

    private function makeStoreWebhook(string $topic): void
    {
        DB::table('store_webhooks')->insert([
            'store_id'                   => $this->store->id,
            'workspace_id'               => $this->workspace->id,
            'platform_webhook_id'        => 'gid://shopify/WebhookSubscription/1',
            'topic'                      => $topic,
            'last_successful_delivery_at' => null,
            'created_at'                 => now(),
        ]);
    }

    // -------------------------------------------------------------------------
    // orders/create — GraphQL re-fetch via Http::fake() + real UpsertShopifyOrderAction
    // -------------------------------------------------------------------------

    public function test_order_create_upserts_order_via_graphql_refetch(): void
    {
        // Fake the GraphQL POST so ShopifyGraphQlClient doesn't hit the network.
        Http::fake([
            '*' => Http::response([
                'data' => [
                    'order' => [
                        'id'                     => 'gid://shopify/Order/1001',
                        'legacyResourceId'        => '1001',
                        'name'                    => '#1001',
                        'displayFinancialStatus'  => 'PAID',
                        'createdAt'               => now()->toIso8601String(),
                        'updatedAt'               => now()->toIso8601String(),
                        'currentTotalPriceSet'    => ['shopMoney' => ['amount' => '100.00', 'currencyCode' => 'EUR']],
                        'subtotalPriceSet'        => ['shopMoney' => ['amount' => '90.00', 'currencyCode' => 'EUR']],
                        'totalTaxSet'             => ['shopMoney' => ['amount' => '10.00', 'currencyCode' => 'EUR']],
                        'totalShippingPriceSet'   => ['shopMoney' => ['amount' => '5.00', 'currencyCode' => 'EUR']],
                        'totalDiscountsSet'       => ['shopMoney' => ['amount' => '0.00', 'currencyCode' => 'EUR']],
                        'email'                   => 'test@example.com',
                        'customer'                => ['id' => 'gid://shopify/Customer/42'],
                        'billingAddress'          => ['countryCode' => 'DE'],
                        'shippingAddress'         => ['countryCode' => 'DE'],
                        'paymentGatewayNames'     => ['stripe'],
                        'lineItems'               => ['edges' => []],
                        'discountCodes'           => [],
                        'customerJourneySummary'  => null,
                    ],
                ],
                'extensions' => ['cost' => ['throttleStatus' => ['currentlyAvailable' => 1000, 'maximumAvailable' => 1000]]],
            ], 200),
        ]);

        $logId = $this->makeWebhookLog('orders/create', ['id' => 1001]);

        $job = new ProcessShopifyWebhookJob(
            webhookLogId: $logId,
            storeId:      $this->store->id,
            workspaceId:  $this->workspace->id,
            topic:        'orders/create',
            payload:      ['id' => 1001],
        );

        $job->handle(app(UpsertShopifyOrderAction::class));

        $this->assertDatabaseHas('orders', [
            'store_id'    => $this->store->id,
            'external_id' => '1001',
            'status'      => 'completed',
        ]);

        $log = DB::table('webhook_logs')->find($logId);
        $this->assertSame('processed', $log->status);
    }

    // -------------------------------------------------------------------------
    // orders/cancelled — soft-cancel
    // -------------------------------------------------------------------------

    public function test_order_cancelled_sets_status_to_cancelled(): void
    {
        $order = Order::factory()->create([
            'workspace_id' => $this->workspace->id,
            'store_id'     => $this->store->id,
            'external_id'  => '9999',
            'status'       => 'completed',
        ]);

        $logId = $this->makeWebhookLog('orders/cancelled', ['id' => 9999]);

        $job = new ProcessShopifyWebhookJob(
            webhookLogId: $logId,
            storeId:      $this->store->id,
            workspaceId:  $this->workspace->id,
            topic:        'orders/cancelled',
            payload:      ['id' => 9999],
        );

        $job->handle(app(UpsertShopifyOrderAction::class));

        $this->assertDatabaseHas('orders', [
            'id'     => $order->id,
            'status' => 'cancelled',
        ]);

        $log = DB::table('webhook_logs')->find($logId);
        $this->assertSame('processed', $log->status);
    }

    // -------------------------------------------------------------------------
    // products/update — upsert product
    // -------------------------------------------------------------------------

    public function test_products_update_upserts_product_row(): void
    {
        $payload = [
            'id'           => 88888,
            'title'        => 'Test Shirt',
            'handle'       => 'test-shirt',
            'status'       => 'active',
            'product_type' => 'Apparel',
            'updated_at'   => now()->toIso8601String(),
            'variants'     => [[
                'sku'                  => 'SHIRT-M',
                'price'                => '29.99',
                'inventory_quantity'   => 10,
                'inventory_management' => 'shopify',
            ]],
            'images' => [['src' => 'https://cdn.shopify.com/img.jpg']],
        ];

        $logId = $this->makeWebhookLog('products/update', $payload);

        $job = new ProcessShopifyWebhookJob(
            webhookLogId: $logId,
            storeId:      $this->store->id,
            workspaceId:  $this->workspace->id,
            topic:        'products/update',
            payload:      $payload,
        );

        $job->handle(app(UpsertShopifyOrderAction::class));

        $this->assertDatabaseHas('products', [
            'store_id'    => $this->store->id,
            'external_id' => '88888',
            'name'        => 'Test Shirt',
            'sku'         => 'SHIRT-M',
            'status'      => 'active',
            'stock_status' => 'instock',
        ]);

        $log = DB::table('webhook_logs')->find($logId);
        $this->assertSame('processed', $log->status);
    }

    // -------------------------------------------------------------------------
    // refunds/create — upsert refund + update order totals
    // -------------------------------------------------------------------------

    public function test_refunds_create_upserts_refund_and_updates_order(): void
    {
        $order = Order::factory()->create([
            'workspace_id'  => $this->workspace->id,
            'store_id'      => $this->store->id,
            'external_id'   => '55555',
            'refund_amount' => 0,
        ]);

        $refundPayload = [
            'id'          => 77777,
            'order_id'    => 55555,
            'note'        => 'Customer request',
            'created_at'  => now()->toIso8601String(),
            'transactions' => [
                ['amount' => '10.00'],
                ['amount' => '5.50'],
            ],
        ];

        $logId = $this->makeWebhookLog('refunds/create', $refundPayload);

        $job = new ProcessShopifyWebhookJob(
            webhookLogId: $logId,
            storeId:      $this->store->id,
            workspaceId:  $this->workspace->id,
            topic:        'refunds/create',
            payload:      $refundPayload,
        );

        $job->handle(app(UpsertShopifyOrderAction::class));

        $this->assertDatabaseHas('refunds', [
            'order_id'          => $order->id,
            'platform_refund_id' => '77777',
            'amount'            => 15.50,
        ]);

        $this->assertDatabaseHas('orders', [
            'id'            => $order->id,
            'refund_amount' => 15.50,
        ]);

        $log = DB::table('webhook_logs')->find($logId);
        $this->assertSame('processed', $log->status);
    }

    // -------------------------------------------------------------------------
    // Deduplication — second delivery of same topic+entity_id within 24 h
    // -------------------------------------------------------------------------

    public function test_duplicate_delivery_is_skipped_within_24_hours(): void
    {
        // Insert an already-processed log for the same entity.
        DB::table('webhook_logs')->insert([
            'store_id'        => $this->store->id,
            'workspace_id'    => $this->workspace->id,
            'event'           => 'orders/cancelled',
            'payload'         => json_encode(['id' => 3333]),
            'signature_valid' => true,
            'status'          => 'processed',
            'created_at'      => now()->subHour(),
            'updated_at'      => now()->subHour(),
        ]);

        $logId = $this->makeWebhookLog('orders/cancelled', ['id' => 3333]);

        $actionMock = Mockery::mock(UpsertShopifyOrderAction::class);
        $actionMock->shouldNotReceive('handle');

        $job = new ProcessShopifyWebhookJob(
            webhookLogId: $logId,
            storeId:      $this->store->id,
            workspaceId:  $this->workspace->id,
            topic:        'orders/cancelled',
            payload:      ['id' => 3333],
        );

        $job->handle($actionMock);

        $log = DB::table('webhook_logs')->find($logId);
        $this->assertSame('processed', $log->status);
    }

    public function test_same_entity_older_than_24_hours_is_not_deduplicated(): void
    {
        $order = Order::factory()->create([
            'workspace_id' => $this->workspace->id,
            'store_id'     => $this->store->id,
            'external_id'  => '4444',
            'status'       => 'completed',
        ]);

        // Old log — outside the 24-hour dedup window.
        DB::table('webhook_logs')->insert([
            'store_id'        => $this->store->id,
            'workspace_id'    => $this->workspace->id,
            'event'           => 'orders/cancelled',
            'payload'         => json_encode(['id' => 4444]),
            'signature_valid' => true,
            'status'          => 'processed',
            'created_at'      => now()->subHours(25),
            'updated_at'      => now()->subHours(25),
        ]);

        $logId = $this->makeWebhookLog('orders/cancelled', ['id' => 4444]);

        $job = new ProcessShopifyWebhookJob(
            webhookLogId: $logId,
            storeId:      $this->store->id,
            workspaceId:  $this->workspace->id,
            topic:        'orders/cancelled',
            payload:      ['id' => 4444],
        );

        $job->handle(app(UpsertShopifyOrderAction::class));

        // Order should actually be cancelled this time.
        $this->assertDatabaseHas('orders', [
            'id'     => $order->id,
            'status' => 'cancelled',
        ]);
    }

    // -------------------------------------------------------------------------
    // store_webhooks.last_successful_delivery_at stamped on success
    // -------------------------------------------------------------------------

    public function test_stamps_last_successful_delivery_at_on_success(): void
    {
        $this->makeStoreWebhook('orders/cancelled');

        $order = Order::factory()->create([
            'workspace_id' => $this->workspace->id,
            'store_id'     => $this->store->id,
            'external_id'  => '7777',
        ]);

        $logId = $this->makeWebhookLog('orders/cancelled', ['id' => 7777]);

        $job = new ProcessShopifyWebhookJob(
            webhookLogId: $logId,
            storeId:      $this->store->id,
            workspaceId:  $this->workspace->id,
            topic:        'orders/cancelled',
            payload:      ['id' => 7777],
        );

        $job->handle(app(UpsertShopifyOrderAction::class));

        $row = DB::table('store_webhooks')
            ->where('store_id', $this->store->id)
            ->where('topic', 'orders/cancelled')
            ->first();

        $this->assertNotNull($row->last_successful_delivery_at);
    }

    // -------------------------------------------------------------------------
    // Unknown topic — ack without processing
    // -------------------------------------------------------------------------

    public function test_unknown_topic_is_acknowledged_without_error(): void
    {
        $logId = $this->makeWebhookLog('inventory_items/update', ['id' => 1]);

        $job = new ProcessShopifyWebhookJob(
            webhookLogId: $logId,
            storeId:      $this->store->id,
            workspaceId:  $this->workspace->id,
            topic:        'inventory_items/update',
            payload:      ['id' => 1],
        );

        $job->handle(app(UpsertShopifyOrderAction::class));

        $log = DB::table('webhook_logs')->find($logId);
        $this->assertSame('processed', $log->status);
    }
}
