<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Attribution;

use App\Models\Order;
use App\Models\Store;
use App\Models\Workspace;
use App\Services\Attribution\Sources\ReferrerHeuristicSource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReferrerHeuristicSourceTest extends TestCase
{
    use RefreshDatabase;

    private ReferrerHeuristicSource $source;
    private Workspace $workspace;
    private Store $store;

    protected function setUp(): void
    {
        parent::setUp();

        $this->source    = new ReferrerHeuristicSource();
        $this->workspace = Workspace::factory()->create();
        $this->store     = Store::factory()->create(['workspace_id' => $this->workspace->id]);
    }

    private function makeOrder(array $overrides = []): Order
    {
        return Order::factory()->create(array_merge([
            'workspace_id' => $this->workspace->id,
            'store_id'     => $this->store->id,
            'utm_source'   => null,
        ], $overrides));
    }

    // -------------------------------------------------------------------------
    // No source_type
    // -------------------------------------------------------------------------

    public function test_returns_null_when_source_type_is_null(): void
    {
        $order = $this->makeOrder(['source_type' => null]);

        $this->assertNull($this->source->tryParse($order));
    }

    public function test_returns_null_when_source_type_is_empty(): void
    {
        $order = $this->makeOrder(['source_type' => '']);

        $this->assertNull($this->source->tryParse($order));
    }

    // -------------------------------------------------------------------------
    // Recognised source types
    // -------------------------------------------------------------------------

    public function test_direct_source_type_produces_direct_touch(): void
    {
        $order  = $this->makeOrder(['source_type' => 'direct']);
        $result = $this->source->tryParse($order);

        $this->assertNotNull($result);
        $this->assertSame('referrer', $result->source_type);
        $this->assertSame('direct', $result->last_touch['source']);
        $this->assertArrayNotHasKey('medium', $result->last_touch);
    }

    public function test_typein_source_type_produces_direct_touch(): void
    {
        $order  = $this->makeOrder(['source_type' => 'typein']);
        $result = $this->source->tryParse($order);

        $this->assertNotNull($result);
        $this->assertSame('direct', $result->last_touch['source']);
    }

    public function test_organic_search_produces_google_organic_touch(): void
    {
        $order  = $this->makeOrder(['source_type' => 'organic_search']);
        $result = $this->source->tryParse($order);

        $this->assertNotNull($result);
        $this->assertSame('google', $result->last_touch['source']);
        $this->assertSame('organic', $result->last_touch['medium']);
    }

    public function test_referral_source_type_produces_referral_touch(): void
    {
        $order  = $this->makeOrder(['source_type' => 'referral']);
        $result = $this->source->tryParse($order);

        $this->assertNotNull($result);
        $this->assertSame('referral', $result->last_touch['source']);
        $this->assertSame('referral', $result->last_touch['medium']);
    }

    public function test_link_source_type_produces_referral_touch(): void
    {
        $order  = $this->makeOrder(['source_type' => 'link']);
        $result = $this->source->tryParse($order);

        $this->assertNotNull($result);
        $this->assertSame('referral', $result->last_touch['source']);
    }

    // -------------------------------------------------------------------------
    // Unhandled source types
    // -------------------------------------------------------------------------

    public function test_returns_null_for_admin_source_type(): void
    {
        // admin-created orders have no real marketing attribution.
        $order = $this->makeOrder(['source_type' => 'admin']);

        $this->assertNull($this->source->tryParse($order));
    }

    public function test_returns_null_for_utm_source_type(): void
    {
        // utm is handled by WooCommerceNativeSource; should not reach this source.
        $order = $this->makeOrder(['source_type' => 'utm']);

        $this->assertNull($this->source->tryParse($order));
    }

    // -------------------------------------------------------------------------
    // Single-touch: first === last
    // -------------------------------------------------------------------------

    public function test_first_touch_equals_last_touch(): void
    {
        $order  = $this->makeOrder(['source_type' => 'organic_search']);
        $result = $this->source->tryParse($order);

        $this->assertNotNull($result);
        $this->assertSame($result->first_touch, $result->last_touch);
    }
}
