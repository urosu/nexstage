<?php

declare(strict_types=1);

namespace Tests\Unit\Actions;

use App\Actions\UpsertWooCommerceOrderAction;
use App\Models\FxRate;
use App\Models\Order;
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

    public function test_shipping_country_extracted_from_shipping_address(): void
    {
        $this->action->handle($this->store, 'EUR', $this->makeWcOrder([
            'shipping' => ['country' => 'AT'],
        ]));

        $order = Order::withoutGlobalScopes()->where('store_id', $this->store->id)->first();
        // CHAR(3) column pads shorter codes with spaces — trim for comparison
        $this->assertSame('AT', trim($order->shipping_country));
    }

    public function test_shipping_country_null_when_missing(): void
    {
        // makeWcOrder has no 'shipping' key — should store null
        $this->action->handle($this->store, 'EUR', $this->makeWcOrder());

        $order = Order::withoutGlobalScopes()->where('store_id', $this->store->id)->first();
        $this->assertNull($order->shipping_country);
    }

    public function test_customer_id_stored(): void
    {
        $this->action->handle($this->store, 'EUR', $this->makeWcOrder([
            'customer_id' => 42,
        ]));

        $order = Order::withoutGlobalScopes()->where('store_id', $this->store->id)->first();
        $this->assertSame('42', $order->customer_id);
    }

    public function test_payment_method_stored(): void
    {
        $this->action->handle($this->store, 'EUR', $this->makeWcOrder([
            'payment_method'       => 'stripe',
            'payment_method_title' => 'Credit Card (Stripe)',
        ]));

        $order = Order::withoutGlobalScopes()->where('store_id', $this->store->id)->first();
        $this->assertSame('stripe', $order->payment_method);
        $this->assertSame('Credit Card (Stripe)', $order->payment_method_title);
    }

    public function test_utm_term_extracted_from_meta_data(): void
    {
        $this->action->handle($this->store, 'EUR', $this->makeWcOrder([
            'meta_data' => [
                ['key' => '_utm_term', 'value' => 'running shoes'],
            ],
        ]));

        $order = Order::withoutGlobalScopes()->where('store_id', $this->store->id)->first();
        $this->assertSame('running shoes', $order->utm_term);
    }

    public function test_coupons_inserted_into_order_coupons(): void
    {
        $this->action->handle($this->store, 'EUR', $this->makeWcOrder([
            'coupon_lines' => [
                ['code' => 'SUMMER20', 'discount' => '20.00'],
                ['code' => 'FREESHIP', 'discount' => '5.00'],
            ],
        ]));

        $order = Order::withoutGlobalScopes()->where('store_id', $this->store->id)->first();

        $this->assertDatabaseHas('order_coupons', [
            'order_id'        => $order->id,
            'coupon_code'     => 'summer20',
            'discount_amount' => 20.00,
            'discount_type'   => null,
        ]);
        $this->assertDatabaseHas('order_coupons', [
            'order_id'        => $order->id,
            'coupon_code'     => 'freeship',
            'discount_amount' => 5.00,
        ]);
    }

    public function test_coupons_replaced_on_re_upsert(): void
    {
        $order = $this->makeWcOrder([
            'coupon_lines' => [['code' => 'OLD10', 'discount' => '10.00']],
        ]);

        $this->action->handle($this->store, 'EUR', $order);
        $this->assertDatabaseCount('order_coupons', 1);

        // Re-upsert with different coupon
        $order['coupon_lines'] = [['code' => 'NEW15', 'discount' => '15.00']];
        $this->action->handle($this->store, 'EUR', $order);

        $this->assertDatabaseCount('order_coupons', 1);
        $this->assertDatabaseHas('order_coupons', ['coupon_code' => 'new15']);
        $this->assertDatabaseMissing('order_coupons', ['coupon_code' => 'old10']);
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

    public function test_payment_fee_summed_from_matching_fee_lines(): void
    {
        $this->action->handle($this->store, 'EUR', $this->makeWcOrder([
            'fee_lines' => [
                ['name' => 'Stripe Fee',   'total' => '1.50'],
                ['name' => 'Gift Wrapping', 'total' => '5.00'], // no keyword match — excluded
            ],
        ]));

        $order = Order::withoutGlobalScopes()->where('store_id', $this->store->id)->first();
        $this->assertEqualsWithDelta(1.50, (float) $order->payment_fee, 0.01);
    }

    public function test_payment_fee_zero_when_no_matching_fee_lines(): void
    {
        $this->action->handle($this->store, 'EUR', $this->makeWcOrder([
            'fee_lines' => [
                ['name' => 'Gift Wrap', 'total' => '5.00'],
            ],
        ]));

        $order = Order::withoutGlobalScopes()->where('store_id', $this->store->id)->first();
        $this->assertEquals(0.0, (float) $order->payment_fee);
    }

    public function test_is_first_for_customer_true_for_new_email(): void
    {
        $this->action->handle($this->store, 'EUR', $this->makeWcOrder([
            'billing' => ['email' => 'brand-new@example.com', 'country' => 'DE'],
        ]));

        $order = Order::withoutGlobalScopes()->where('store_id', $this->store->id)->first();
        $this->assertTrue((bool) $order->is_first_for_customer);
    }

    public function test_is_first_for_customer_false_for_second_order(): void
    {
        $email = 'returning@example.com';

        $this->action->handle($this->store, 'EUR', $this->makeWcOrder([
            'id'               => '2001',
            'date_created_gmt' => now()->subDays(5)->toIso8601String(),
            'billing'          => ['email' => $email, 'country' => 'DE'],
        ]));

        $this->action->handle($this->store, 'EUR', $this->makeWcOrder([
            'id'               => '2002',
            'date_created_gmt' => now()->toIso8601String(),
            'billing'          => ['email' => $email, 'country' => 'DE'],
        ]));

        $first  = Order::withoutGlobalScopes()->where('store_id', $this->store->id)->where('external_id', '2001')->first();
        $second = Order::withoutGlobalScopes()->where('store_id', $this->store->id)->where('external_id', '2002')->first();

        $this->assertTrue((bool) $first->is_first_for_customer);
        $this->assertFalse((bool) $second->is_first_for_customer);
    }
}
