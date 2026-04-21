<?php

declare(strict_types=1);

namespace Tests\Feature\Jobs;

use App\Actions\RemoveStoreAction;
use App\Actions\UpsertWooCommerceOrderAction;
use App\Jobs\PollStoreOrdersJob;
use App\Jobs\ProcessWebhookJob;
use App\Jobs\ReconcileStoreOrdersJob;
use App\Models\Store;
use App\Models\Workspace;
use App\Services\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Feature tests for Phase 1.5 Step 13 — Sync reliability.
 *
 * Covers:
 *   - PollStoreOrdersJob skips API call when store_webhooks.last_successful_delivery_at is fresh
 *   - PollStoreOrdersJob polls when all webhooks are stale (>90 min)
 *   - ReconcileStoreOrdersJob hard-deletes orders absent from WC 7-day response
 *   - RemoveStoreAction calls removeWebhooks() before deleting the store record
 *   - ProcessWebhookJob stamps store_webhooks.last_successful_delivery_at on success
 */
class SyncReliabilityTest extends TestCase
{
    use RefreshDatabase;

    private const STORE_DOMAIN = 'mystore.example.com';
    private const WC_ORDERS_URL = 'https://mystore.example.com/wp-json/wc/v3/orders*';
    private const WC_WEBHOOKS_URL = 'https://mystore.example.com/wp-json/wc/v3/webhooks*';

    private Workspace $workspace;
    private Store $store;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workspace = Workspace::factory()->create(['reporting_currency' => 'EUR']);

        $this->store = Store::factory()->create([
            'workspace_id'             => $this->workspace->id,
            'domain'                   => self::STORE_DOMAIN,
            'status'                   => 'active',
            'auth_key_encrypted'       => Crypt::encryptString('ck_test'),
            'auth_secret_encrypted'    => Crypt::encryptString('cs_test'),
            'webhook_secret_encrypted' => Crypt::encryptString('wh_test'),
        ]);

        app(WorkspaceContext::class)->set($this->workspace->id);
    }

    // =========================================================================
    // PollStoreOrdersJob
    // =========================================================================

    public function test_poll_skips_api_when_webhooks_are_fresh(): void
    {
        Http::fake(); // no requests should fire

        // Insert a store_webhook row with last_successful_delivery_at within 90 min.
        $this->insertStoreWebhook('order.created', now()->subMinutes(30));

        $job = new PollStoreOrdersJob($this->store->id, $this->workspace->id);
        $job->handle();

        Http::assertNothingSent();
    }

    public function test_poll_calls_wc_api_when_webhooks_are_stale(): void
    {
        Http::fake([
            self::WC_ORDERS_URL => Http::response(
                [],
                200,
                ['X-WP-Total' => '0', 'X-WP-TotalPages' => '0'],
            ),
        ]);

        // Insert a stale webhook row (last delivery >90 min ago).
        $this->insertStoreWebhook('order.created', now()->subHours(3));

        $job = new PollStoreOrdersJob($this->store->id, $this->workspace->id);
        $job->handle();

        Http::assertSentCount(1);
    }

    public function test_poll_calls_wc_api_when_no_webhooks_registered(): void
    {
        Http::fake([
            self::WC_ORDERS_URL => Http::response(
                [],
                200,
                ['X-WP-Total' => '0', 'X-WP-TotalPages' => '0'],
            ),
        ]);

        // No store_webhook rows — should poll.
        $job = new PollStoreOrdersJob($this->store->id, $this->workspace->id);
        $job->handle();

        Http::assertSentCount(1);
    }

    // =========================================================================
    // ReconcileStoreOrdersJob — hard-delete detection
    // =========================================================================

    public function test_reconcile_hard_deletes_orders_absent_from_wc_response(): void
    {
        $since = now()->subDays(7);

        // Order in DB within the 7-day window.
        $this->insertOrder('999', $since->clone()->addDay());

        // WC returns an empty list for the same window — the order was deleted on the store.
        Http::fake([
            self::WC_ORDERS_URL => Http::response(
                [],
                200,
                ['X-WP-Total' => '0', 'X-WP-TotalPages' => '0'],
            ),
        ]);

        $this->assertDatabaseHas('orders', [
            'store_id'    => $this->store->id,
            'external_id' => '999',
        ]);

        $job = new ReconcileStoreOrdersJob($this->store->id, $this->workspace->id);
        $job->handle();

        $this->assertDatabaseMissing('orders', [
            'store_id'    => $this->store->id,
            'external_id' => '999',
        ]);
    }

    public function test_reconcile_keeps_orders_that_wc_still_returns(): void
    {
        $since = now()->subDays(7);

        $this->insertOrder('100', $since->clone()->addDay());

        $wcOrder = $this->makeWcOrder(100);

        Http::fake([
            self::WC_ORDERS_URL => Http::sequence()
                ->push([$wcOrder], 200, ['X-WP-Total' => '1', 'X-WP-TotalPages' => '1'])
                ->push([], 200, ['X-WP-Total' => '0', 'X-WP-TotalPages' => '0']),
            'api.frankfurter.dev/*' => Http::response(['base' => 'EUR', 'date' => today()->toDateString(), 'rates' => []], 200),
        ]);

        $job = new ReconcileStoreOrdersJob($this->store->id, $this->workspace->id);
        $job->handle();

        // WC still has it → keep it.
        $this->assertDatabaseHas('orders', [
            'store_id'    => $this->store->id,
            'external_id' => '100',
        ]);
    }

    public function test_reconcile_does_not_delete_orders_outside_seven_day_window(): void
    {
        $since = now()->subDays(7);

        // Order older than 7 days — outside the reconciliation window.
        $this->insertOrder('555', $since->clone()->subDays(5));

        Http::fake([
            self::WC_ORDERS_URL => Http::response(
                [],
                200,
                ['X-WP-Total' => '0', 'X-WP-TotalPages' => '0'],
            ),
        ]);

        $job = new ReconcileStoreOrdersJob($this->store->id, $this->workspace->id);
        $job->handle();

        // Too old to be in WC's response window — must not be deleted.
        $this->assertDatabaseHas('orders', [
            'store_id'    => $this->store->id,
            'external_id' => '555',
        ]);
    }

    // =========================================================================
    // RemoveStoreAction — webhook cleanup before deletion
    // =========================================================================

    public function test_remove_store_calls_wc_delete_webhook_before_deleting_record(): void
    {
        $this->insertStoreWebhook('order.created', null, platformWebhookId: 42);

        Http::fake([
            self::WC_WEBHOOKS_URL => Http::response(['id' => 42], 200),
        ]);

        (new RemoveStoreAction)->handle($this->store);

        // Store record is gone.
        $this->assertDatabaseMissing('stores', ['id' => $this->store->id]);

        // WC DELETE endpoint was called.
        Http::assertSent(fn ($request) =>
            str_contains($request->url(), '/webhooks/42') && $request->method() === 'DELETE'
        );
    }

    public function test_remove_store_succeeds_even_when_wc_api_fails(): void
    {
        $this->insertStoreWebhook('order.created', null, platformWebhookId: 99);

        Http::fake([
            self::WC_WEBHOOKS_URL => Http::response('Server Error', 500),
        ]);

        // Should not throw — webhook cleanup failure is non-fatal.
        (new RemoveStoreAction)->handle($this->store);

        $this->assertDatabaseMissing('stores', ['id' => $this->store->id]);
    }

    // =========================================================================
    // ProcessWebhookJob — stamps last_successful_delivery_at
    // =========================================================================

    public function test_webhook_stamps_last_successful_delivery_at(): void
    {
        $webhookRow = $this->insertStoreWebhook('order.created', null);

        $logId = DB::table('webhook_logs')->insertGetId([
            'store_id'        => $this->store->id,
            'workspace_id'    => $this->workspace->id,
            'event'           => 'order.created',
            'payload'         => json_encode(['id' => 2001]),
            'signature_valid' => true,
            'status'          => 'pending',
            'created_at'      => now()->toDateTimeString(),
            'updated_at'      => now()->toDateTimeString(),
        ]);

        // Use Http::fake so the UpsertWooCommerceOrderAction doesn't hit FX rates endpoint.
        Http::fake([
            'api.frankfurter.dev/*' => Http::response(['base' => 'EUR', 'date' => today()->toDateString(), 'rates' => []], 200),
        ]);

        $job = new ProcessWebhookJob(
            webhookLogId: $logId,
            storeId:      $this->store->id,
            workspaceId:  $this->workspace->id,
            event:        'order.created',
            payload:      $this->makeWcOrder(2001),
        );
        $job->handle(
            app(UpsertWooCommerceOrderAction::class),
            app(\App\Actions\UpsertWooCommerceProductAction::class),
        );

        $stamped = DB::table('store_webhooks')
            ->where('id', $webhookRow)
            ->value('last_successful_delivery_at');

        $this->assertNotNull($stamped, 'last_successful_delivery_at should be stamped after successful delivery');
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function insertStoreWebhook(
        string $topic,
        ?\Carbon\Carbon $lastDeliveredAt,
        int $platformWebhookId = 1,
    ): int {
        return DB::table('store_webhooks')->insertGetId([
            'store_id'                    => $this->store->id,
            'workspace_id'                => $this->workspace->id,
            'platform_webhook_id'         => (string) $platformWebhookId,
            'topic'                       => $topic,
            'last_successful_delivery_at' => $lastDeliveredAt?->toDateTimeString(),
            'created_at'                  => now()->toDateTimeString(),
        ]);
    }

    private function insertOrder(string $externalId, \Carbon\Carbon $occurredAt): void
    {
        DB::table('orders')->insert([
            'workspace_id'  => $this->workspace->id,
            'store_id'      => $this->store->id,
            'external_id'   => $externalId,
            'status'        => 'completed',
            'currency'      => 'EUR',
            'total'         => 100.00,
            'subtotal'      => 100.00,
            'tax'           => 0,
            'shipping'      => 0,
            'discount'      => 0,
            'occurred_at'   => $occurredAt->toDateTimeString(),
            'synced_at'     => now()->toDateTimeString(),
            'created_at'    => now()->toDateTimeString(),
            'updated_at'    => now()->toDateTimeString(),
        ]);
    }

    private function makeWcOrder(int $externalId): array
    {
        return [
            'id'                  => $externalId,
            'number'              => (string) $externalId,
            'status'              => 'completed',
            'date_created_gmt'    => now()->toIso8601String(),
            'date_modified_gmt'   => now()->toIso8601String(),
            'currency'            => 'EUR',
            'total'               => '100.00',
            'subtotal'            => '100.00',
            'total_tax'           => '0.00',
            'shipping_total'      => '0.00',
            'discount_total'      => '0.00',
            'billing'             => ['email' => 'test@example.com', 'country' => 'DE'],
            'line_items'          => [],
            'meta_data'           => [],
        ];
    }
}
