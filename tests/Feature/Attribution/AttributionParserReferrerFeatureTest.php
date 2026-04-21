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
 * Feature test: referrer fallback case.
 *
 * Validates that when neither PYS nor WC native UTMs are present, the parser
 * falls through to ReferrerHeuristicSource and returns a heuristic result
 * derived from the WC source_type field.
 */
class AttributionParserReferrerFeatureTest extends TestCase
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

    private function makeOrder(array $overrides = []): Order
    {
        return Order::factory()->create(array_merge([
            'workspace_id' => $this->workspace->id,
            'store_id'     => $this->store->id,
            'utm_source'   => null,
            'utm_medium'   => null,
            'raw_meta'     => null,
        ], $overrides));
    }

    public function test_referrer_fallback_direct_returns_direct_source(): void
    {
        $order = $this->makeOrder(['source_type' => 'direct']);

        $result = $this->parser->parse($order);

        $this->assertSame('referrer', $result->source_type);
        $this->assertSame('direct', $result->last_touch['source']);
    }

    public function test_referrer_fallback_direct_classified_as_direct_channel(): void
    {
        $order = $this->makeOrder(['source_type' => 'direct']);

        $result = $this->parser->parse($order);

        $this->assertSame('Direct', $result->channel);
        $this->assertSame('direct', $result->channel_type);
    }

    public function test_referrer_fallback_organic_search_produces_google_organic(): void
    {
        $order = $this->makeOrder(['source_type' => 'organic_search']);

        $result = $this->parser->parse($order);

        $this->assertSame('referrer', $result->source_type);
        $this->assertSame('google', $result->last_touch['source']);
        $this->assertSame('organic', $result->last_touch['medium']);
    }

    public function test_referrer_fallback_organic_classified_as_organic_search(): void
    {
        $order = $this->makeOrder(['source_type' => 'organic_search']);

        $result = $this->parser->parse($order);

        $this->assertSame('Organic — Google', $result->channel);
        $this->assertSame('organic_search', $result->channel_type);
    }

    public function test_no_source_data_returns_not_tracked(): void
    {
        $order = $this->makeOrder(['source_type' => null]);

        $result = $this->parser->parse($order);

        $this->assertTrue($result->isNotTracked());
        $this->assertSame('none', $result->source_type);
        $this->assertNull($result->last_touch);
        $this->assertNull($result->channel);
    }

    public function test_debug_shows_all_sources_not_matched_when_not_tracked(): void
    {
        $order = $this->makeOrder(['source_type' => null]);

        $pipeline = $this->parser->debug($order);

        // Pipeline now has 5 sources: PYS, ShopifyJourney, ShopifyLanding, WcNative, Referrer.
        $this->assertCount(5, $pipeline);

        foreach ($pipeline as $step) {
            $this->assertFalse($step['matched']);
            $this->assertFalse($step['skipped']);
        }
    }
}
