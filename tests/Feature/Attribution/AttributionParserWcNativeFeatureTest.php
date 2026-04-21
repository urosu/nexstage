<?php

declare(strict_types=1);

namespace Tests\Feature\Attribution;

use App\Models\Order;
use App\Models\Store;
use App\Models\Workspace;
use App\Services\Attribution\AttributionParserService;
use App\Services\WorkspaceContext;
use Database\Seeders\ChannelMappingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Feature test: WC-native-only store (no PYS plugin).
 *
 * Validates that when no PYS data is present, the parser falls through to
 * WooCommerceNativeSource and returns utm values from the utm_* columns.
 */
class AttributionParserWcNativeFeatureTest extends TestCase
{
    use RefreshDatabase;

    private AttributionParserService $parser;
    private Workspace $workspace;
    private Store $store;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ChannelMappingsSeeder::class);
        $this->workspace = Workspace::factory()->create();
        $this->store     = Store::factory()->create(['workspace_id' => $this->workspace->id]);
        $this->parser    = app(AttributionParserService::class);

        app(WorkspaceContext::class)->set($this->workspace->id);
    }

    public function test_wc_native_store_returns_utm_values_from_columns(): void
    {
        $order = Order::factory()->create([
            'workspace_id' => $this->workspace->id,
            'store_id'     => $this->store->id,
            'utm_source'   => 'facebook',
            'utm_medium'   => 'cpc',
            'utm_campaign' => 'summer_shoes',
            'raw_meta'     => null,  // no PYS
        ]);

        $result = $this->parser->parse($order);

        $this->assertSame('wc_native', $result->source_type);
        $this->assertSame('facebook', $result->last_touch['source']);
        $this->assertSame('cpc', $result->last_touch['medium']);
        $this->assertSame('summer_shoes', $result->last_touch['campaign']);
    }

    public function test_wc_native_classified_as_paid_social_for_facebook_cpc(): void
    {
        $order = Order::factory()->create([
            'workspace_id' => $this->workspace->id,
            'store_id'     => $this->store->id,
            'utm_source'   => 'facebook',
            'utm_medium'   => 'cpc',
            'raw_meta'     => null,
        ]);

        $result = $this->parser->parse($order);

        $this->assertSame('Paid — Facebook', $result->channel);
        $this->assertSame('paid_social', $result->channel_type);
    }

    public function test_wc_native_first_touch_equals_last_touch(): void
    {
        $order = Order::factory()->create([
            'workspace_id' => $this->workspace->id,
            'store_id'     => $this->store->id,
            'utm_source'   => 'google',
            'utm_medium'   => 'cpc',
            'raw_meta'     => null,
        ]);

        $result = $this->parser->parse($order);

        $this->assertSame('wc_native', $result->source_type);
        $this->assertSame($result->first_touch, $result->last_touch);
    }

    public function test_debug_shows_pys_skipped_when_no_pys_data_and_wc_native_matches(): void
    {
        $order = Order::factory()->create([
            'workspace_id' => $this->workspace->id,
            'store_id'     => $this->store->id,
            'utm_source'   => 'google',
            'utm_medium'   => 'cpc',
            'raw_meta'     => null,
        ]);

        $pipeline = $this->parser->debug($order);

        // PYS (step 0) must not match
        $this->assertFalse($pipeline[0]['matched']);
        $this->assertFalse($pipeline[0]['skipped']);

        // ShopifyJourney (step 1) — no platform_data on a WC order, must not match
        $this->assertFalse($pipeline[1]['matched']);
        $this->assertFalse($pipeline[1]['skipped']);

        // ShopifyLanding (step 2) — no platform_data on a WC order, must not match
        $this->assertFalse($pipeline[2]['matched']);
        $this->assertFalse($pipeline[2]['skipped']);

        // WC native (step 3) must match
        $this->assertTrue($pipeline[3]['matched']);
        $this->assertFalse($pipeline[3]['skipped']);

        // Referrer (step 4) must be skipped
        $this->assertFalse($pipeline[4]['matched']);
        $this->assertTrue($pipeline[4]['skipped']);
    }
}
