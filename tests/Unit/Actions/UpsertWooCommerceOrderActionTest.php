<?php

declare(strict_types=1);

namespace Tests\Unit\Actions;

use App\Actions\UpsertWooCommerceOrderAction;
use App\Models\FxRate;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Store;
use App\Models\Workspace;
use App\Services\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UpsertWooCommerceOrderActionTest extends TestCase
{
    use RefreshDatabase;

    private UpsertWooCommerceOrderAction $action;
    private Workspace $workspace;
    private Store $store;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workspace = Workspace::factory()->create(['reporting_currency' => 'EUR']);
        $this->store     = Store::factory()->create(['workspace_id' => $this->workspace->id]);

        app(WorkspaceContext::class)->set($this->workspace->id);

        $this->action = app(UpsertWooCommerceOrderAction::class);
    }

    private function makeWcOrder(array $overrides = []): array
    {
        return array_merge([
            'id'               => '12345',
            'number'           => '100',
            'status'           => 'completed',
            'date_created_gmt' => now()->toIso8601String(),
            'currency'         => 'EUR',
            'total'            => '100.00',
            'subtotal'         => '90.00',
            'total_tax'        => '10.00',
            'shipping_total'   => '5.00',
            'discount_total'   => '0.00',
            'billing'          => ['email' => 'Test@Example.COM', 'country' => 'DE'],
            'line_items'       => [],
            'meta_data'        => [],
        ], $overrides);
    }

    public function test_maps_woocommerce_fields_correctly(): void
    {
        $this->action->handle($this->store, 'EUR', $this->makeWcOrder());

        $order = Order::withoutGlobalScopes()->where('store_id', $this->store->id)->first();
        $this->assertNotNull($order);
        $this->assertSame('12345', $order->external_id);
        $this->assertSame('100', $order->external_number);
        $this->assertSame('completed', $order->status);
        $this->assertSame('EUR', $order->currency);
        $this->assertEquals(100.00, (float) $order->total);
        $this->assertEquals(90.00, (float) $order->subtotal);
        $this->assertEquals(10.00, (float) $order->tax);
        $this->assertEquals(5.00, (float) $order->shipping);
        $this->assertEquals(0.00, (float) $order->discount);
        $this->assertSame('DE', $order->customer_country);
    }

    public function test_hashes_customer_email(): void
    {
        $this->action->handle($this->store, 'EUR', $this->makeWcOrder([
            'billing' => ['email' => 'Test@Example.COM', 'country' => 'DE'],
        ]));

        $order = Order::withoutGlobalScopes()->where('store_id', $this->store->id)->first();
        $expected = hash('sha256', 'test@example.com');
        $this->assertSame($expected, $order->customer_email_hash);
    }

    public function test_null_email_stores_null_hash(): void
    {
        $this->action->handle($this->store, 'EUR', $this->makeWcOrder([
            'billing' => ['email' => '', 'country' => 'DE'],
        ]));

        $order = Order::withoutGlobalScopes()->where('store_id', $this->store->id)->first();
        $this->assertNull($order->customer_email_hash);
    }

    public function test_statuses_mapped_correctly(): void
    {
        $statuses = [
            'completed'  => 'completed',
            'processing' => 'processing',
            'refunded'   => 'refunded',
            'cancelled'  => 'cancelled',
            'on-hold'    => 'other',
            'pending'    => 'other',
            'failed'     => 'other',
        ];

        foreach ($statuses as $wcStatus => $expected) {
            $externalId = 'order-' . $wcStatus;
            $this->action->handle($this->store, 'EUR', $this->makeWcOrder([
                'id'     => $externalId,
                'status' => $wcStatus,
            ]));

            $order = Order::withoutGlobalScopes()
                ->where('store_id', $this->store->id)
                ->where('external_id', $externalId)
                ->first();

            $this->assertSame($expected, $order->status, "Status {$wcStatus} should map to {$expected}");
        }
    }

    public function test_utm_params_extracted_from_meta_data(): void
    {
        $this->action->handle($this->store, 'EUR', $this->makeWcOrder([
            'meta_data' => [
                ['key' => '_utm_source', 'value' => 'google'],
                ['key' => '_utm_medium', 'value' => 'cpc'],
                ['key' => '_utm_campaign', 'value' => 'summer_sale'],
                ['key' => '_utm_content', 'value' => 'banner_a'],
            ],
        ]));

        $order = Order::withoutGlobalScopes()->where('store_id', $this->store->id)->first();
        $this->assertSame('google', $order->utm_source);
        $this->assertSame('cpc', $order->utm_medium);
        $this->assertSame('summer_sale', $order->utm_campaign);
        $this->assertSame('banner_a', $order->utm_content);
    }

    public function test_order_items_upserted(): void
    {
        $this->action->handle($this->store, 'EUR', $this->makeWcOrder([
            'line_items' => [
                [
                    'product_id'   => 42,
                    'name'         => 'Test Product',
                    'sku'          => 'SKU-001',
                    'quantity'     => 2,
                    'price'        => '45.00',
                    'total'        => '90.00',
                    'variation_id' => 0,
                    'meta_data'    => [],
                ],
            ],
        ]));

        $this->assertDatabaseCount('order_items', 1);
        $this->assertDatabaseHas('order_items', [
            'product_external_id' => '42',
            'product_name'        => 'Test Product',
            'quantity'            => 2,
        ]);
    }

    public function test_idempotent_upsert(): void
    {
        $order = $this->makeWcOrder(['id' => '99999']);

        $this->action->handle($this->store, 'EUR', $order);
        $this->action->handle($this->store, 'EUR', $order);

        $this->assertDatabaseCount('orders', 1);
    }

    public function test_total_in_reporting_currency_null_when_fx_rate_missing(): void
    {
        // USD order, EUR reporting, but no FX rate in the DB
        $this->action->handle($this->store, 'EUR', $this->makeWcOrder([
            'currency' => 'USD',
            'total'    => '100.00',
        ]));

        $order = Order::withoutGlobalScopes()->where('store_id', $this->store->id)->first();
        $this->assertNull($order->total_in_reporting_currency);
    }

    public function test_total_in_reporting_currency_set_when_fx_rate_available(): void
    {
        FxRate::factory()->create([
            'base_currency'   => 'EUR',
            'target_currency' => 'USD',
            'rate'            => 1.08,
            'date'            => today(),
        ]);

        $this->action->handle($this->store, 'EUR', $this->makeWcOrder([
            'currency'         => 'USD',
            'total'            => '108.00',
            'date_created_gmt' => now()->toIso8601String(),
        ]));

        $order = Order::withoutGlobalScopes()->where('store_id', $this->store->id)->first();
        // USD 108 / rate(EUR→USD 1.08) = EUR 100
        $this->assertNotNull($order->total_in_reporting_currency);
        $this->assertEqualsWithDelta(100.0, (float) $order->total_in_reporting_currency, 0.01);
    }

    public function test_order_items_replaced_on_re_upsert(): void
    {
        $makeItems = fn (int $count) => array_map(fn ($i) => [
            'product_id'   => $i,
            'name'         => "Product {$i}",
            'sku'          => "SKU-{$i}",
            'quantity'     => 1,
            'price'        => '10.00',
            'total'        => '10.00',
            'variation_id' => 0,
            'meta_data'    => [],
        ], range(1, $count));

        // First call: 3 items
        $this->action->handle($this->store, 'EUR', $this->makeWcOrder(['line_items' => $makeItems(3)]));
        $this->assertDatabaseCount('order_items', 3);

        // Second call: 1 item — should replace all
        $this->action->handle($this->store, 'EUR', $this->makeWcOrder(['line_items' => $makeItems(1)]));
        $this->assertDatabaseCount('order_items', 1);
    }
}
