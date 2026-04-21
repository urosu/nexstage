<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Attribution;

use App\Models\Order;
use App\Models\Store;
use App\Models\Workspace;
use App\Services\Attribution\Sources\WooCommerceNativeSource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WooCommerceNativeSourceTest extends TestCase
{
    use RefreshDatabase;

    private WooCommerceNativeSource $source;
    private Workspace $workspace;
    private Store $store;

    protected function setUp(): void
    {
        parent::setUp();

        $this->source    = new WooCommerceNativeSource();
        $this->workspace = Workspace::factory()->create();
        $this->store     = Store::factory()->create(['workspace_id' => $this->workspace->id]);
    }

    private function makeOrder(array $overrides = []): Order
    {
        return Order::factory()->create(array_merge([
            'workspace_id' => $this->workspace->id,
            'store_id'     => $this->store->id,
        ], $overrides));
    }

    // -------------------------------------------------------------------------
    // No UTM data
    // -------------------------------------------------------------------------

    public function test_returns_null_when_utm_source_is_null(): void
    {
        $order = $this->makeOrder(['utm_source' => null]);

        $this->assertNull($this->source->tryParse($order));
    }

    public function test_returns_null_when_utm_source_is_empty(): void
    {
        $order = $this->makeOrder(['utm_source' => '']);

        $this->assertNull($this->source->tryParse($order));
    }

    // -------------------------------------------------------------------------
    // Full UTM set
    // -------------------------------------------------------------------------

    public function test_parses_full_utm_set(): void
    {
        $order = $this->makeOrder([
            'utm_source'   => 'facebook',
            'utm_medium'   => 'cpc',
            'utm_campaign' => 'summer_sale',
            'utm_content'  => 'video_v2',
            'utm_term'     => 'shoes',
        ]);

        $result = $this->source->tryParse($order);

        $this->assertNotNull($result);
        $this->assertSame('wc_native', $result->source_type);
        $this->assertSame('facebook', $result->first_touch['source']);
        $this->assertSame('cpc', $result->first_touch['medium']);
        $this->assertSame('summer_sale', $result->first_touch['campaign']);
        $this->assertSame('video_v2', $result->first_touch['content']);
        $this->assertSame('shoes', $result->first_touch['term']);
    }

    // -------------------------------------------------------------------------
    // Single-touch: first === last
    // -------------------------------------------------------------------------

    public function test_first_touch_equals_last_touch(): void
    {
        $order = $this->makeOrder([
            'utm_source' => 'google',
            'utm_medium' => 'cpc',
        ]);

        $result = $this->source->tryParse($order);

        $this->assertNotNull($result);
        $this->assertSame($result->first_touch, $result->last_touch);
    }

    // -------------------------------------------------------------------------
    // Partial UTM (only source present)
    // -------------------------------------------------------------------------

    public function test_parses_source_only_utm(): void
    {
        $order = $this->makeOrder([
            'utm_source'   => 'newsletter',
            'utm_medium'   => null,
            'utm_campaign' => null,
        ]);

        $result = $this->source->tryParse($order);

        $this->assertNotNull($result);
        $this->assertSame('newsletter', $result->first_touch['source']);
        $this->assertArrayNotHasKey('medium', $result->first_touch);
        $this->assertArrayNotHasKey('campaign', $result->first_touch);
    }

    // -------------------------------------------------------------------------
    // No click_ids
    // -------------------------------------------------------------------------

    public function test_click_ids_always_null(): void
    {
        $order  = $this->makeOrder(['utm_source' => 'google', 'utm_medium' => 'cpc']);
        $result = $this->source->tryParse($order);

        $this->assertNull($result->click_ids);
    }
}
