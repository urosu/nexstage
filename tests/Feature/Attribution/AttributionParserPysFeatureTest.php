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
 * Feature test: PYS-enabled store with Klaviyo email order.
 *
 * Validates the documented real-world misattribution fix (PLANNING section 6.2):
 * WC native records utm_source=google (organic session before purchase), but PYS
 * records the actual source: utm_source=Klaviyo, utm_medium=email.
 *
 * PYS must win (priority 1) and the result must be email channel, not organic.
 */
class AttributionParserPysFeatureTest extends TestCase
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

    public function test_pys_store_klaviyo_email_wins_over_wc_native_organic(): void
    {
        // WC native misattributed this as organic Google (last session was a Google search).
        // PYS captures the actual channel: Klaviyo email.
        $order = Order::factory()->create([
            'workspace_id' => $this->workspace->id,
            'store_id'     => $this->store->id,
            'utm_source'   => 'google',       // WC native (wrong)
            'utm_medium'   => 'organic',
            'source_type'  => 'organic_search',
            'raw_meta'     => [
                'pys_enrich_data' => [
                    'pys_utm'      => 'utm_source:Klaviyo|utm_medium:email|utm_campaign:SPRING25|utm_content:undefined|utm_term:undefined',
                    'last_pys_utm' => 'utm_source:Klaviyo|utm_medium:email|utm_campaign:SPRING25mail201|utm_content:undefined|utm_term:undefined',
                    'pys_source'   => 'google.com',
                    'pys_landing'  => 'https://store.com/product/',
                ],
            ],
        ]);

        $result = $this->parser->parse($order);

        // PYS must be the winning source
        $this->assertSame('pys', $result->source_type);

        // Source must be Klaviyo, not google
        $this->assertSame('Klaviyo', $result->last_touch['source']);
        $this->assertSame('email', $result->last_touch['medium']);
        $this->assertSame('SPRING25mail201', $result->last_touch['campaign']);

        // Must be classified as email channel (global seed row exists for klaviyo/email)
        $this->assertSame('Email — Klaviyo', $result->channel);
        $this->assertSame('email', $result->channel_type);
    }

    public function test_pys_facebook_cpc_includes_click_ids(): void
    {
        $order = Order::factory()->create([
            'workspace_id' => $this->workspace->id,
            'store_id'     => $this->store->id,
            'raw_meta'     => [
                'pys_enrich_data' => [
                    'pys_utm'      => 'utm_source:facebook|utm_medium:cpc|utm_campaign:retarget',
                    'last_pys_utm' => 'utm_source:facebook|utm_medium:cpc|utm_campaign:retarget',
                ],
                'pys_fb_cookie' => [
                    'fbc' => 'fb.1.1686739200.AbCdEfGh',
                    'fbp' => 'fb.1.1686739200.12345678',
                ],
            ],
        ]);

        $result = $this->parser->parse($order);

        $this->assertSame('pys', $result->source_type);
        $this->assertSame('facebook', $result->last_touch['source']);
        $this->assertNotNull($result->click_ids);
        $this->assertSame('fb.1.1686739200.AbCdEfGh', $result->click_ids['fbc']);
    }

    public function test_fbc_only_infers_facebook_paid_social(): void
    {
        // Store had no UTMs configured; PYS only recorded the Facebook click cookie.
        // fbc is exclusively set on ad clicks — never on organic FB traffic.
        $order = Order::factory()->create([
            'workspace_id' => $this->workspace->id,
            'store_id'     => $this->store->id,
            'raw_meta'     => [
                'pys_enrich_data' => [],
                'pys_fb_cookie'   => [
                    'fbc' => 'fb.1.1686739200.AbCdEfGh',
                    'fbp' => 'fb.1.1686739200.12345678',
                ],
            ],
        ]);

        $result = $this->parser->parse($order);

        $this->assertSame('pys', $result->source_type);
        $this->assertSame('facebook', $result->first_touch['source']);
        $this->assertSame('paid_social', $result->first_touch['medium']);
        $this->assertSame('facebook', $result->last_touch['source']);
        $this->assertNotNull($result->click_ids);
        $this->assertSame('fb.1.1686739200.AbCdEfGh', $result->click_ids['fbc']);
    }

    public function test_gclid_only_infers_google_cpc(): void
    {
        // Store had no UTMs configured; PYS recorded the Google Ads click ID (gadid).
        $order = Order::factory()->create([
            'workspace_id' => $this->workspace->id,
            'store_id'     => $this->store->id,
            'raw_meta'     => [
                'pys_enrich_data' => [
                    'pys_utm_id' => 'gadid:Cj0KCQiA1ZGcBhCoARIsAGQ0kkI',
                ],
            ],
        ]);

        $result = $this->parser->parse($order);

        $this->assertSame('pys', $result->source_type);
        $this->assertSame('google', $result->first_touch['source']);
        $this->assertSame('cpc', $result->first_touch['medium']);
        $this->assertNotNull($result->click_ids);
        $this->assertSame('Cj0KCQiA1ZGcBhCoARIsAGQ0kkI', $result->click_ids['gclid']);
    }

    public function test_gclid_takes_priority_over_fbc(): void
    {
        // Both Google Ads (session-specific) and Facebook click cookie present.
        // gclid is more precise — it is tied to this exact session touch.
        $order = Order::factory()->create([
            'workspace_id' => $this->workspace->id,
            'store_id'     => $this->store->id,
            'raw_meta'     => [
                'pys_enrich_data' => [
                    'pys_utm_id' => 'gadid:Cj0KCQiA1ZGcBhCoARIsAGQ0kkI',
                ],
                'pys_fb_cookie' => [
                    'fbc' => 'fb.1.1686739200.AbCdEfGh',
                ],
            ],
        ]);

        $result = $this->parser->parse($order);

        $this->assertSame('pys', $result->source_type);
        $this->assertSame('google', $result->first_touch['source']);
        $this->assertSame('cpc', $result->first_touch['medium']);
        // Both click IDs should be stored
        $this->assertSame('Cj0KCQiA1ZGcBhCoARIsAGQ0kkI', $result->click_ids['gclid']);
        $this->assertSame('fb.1.1686739200.AbCdEfGh', $result->click_ids['fbc']);
    }

    public function test_pys_debug_pipeline_shows_pys_matched_and_others_skipped(): void
    {
        $order = Order::factory()->create([
            'workspace_id' => $this->workspace->id,
            'store_id'     => $this->store->id,
            'utm_source'   => 'google',
            'raw_meta'     => [
                'pys_enrich_data' => [
                    'pys_utm'      => 'utm_source:Klaviyo|utm_medium:email|utm_campaign:TEST',
                    'last_pys_utm' => 'utm_source:Klaviyo|utm_medium:email|utm_campaign:TEST',
                ],
            ],
        ]);

        $pipeline = $this->parser->debug($order);

        // First step (PYS) must be matched
        $this->assertTrue($pipeline[0]['matched']);
        $this->assertFalse($pipeline[0]['skipped']);

        // Subsequent steps must be skipped (first-hit-wins)
        foreach (array_slice($pipeline, 1) as $step) {
            $this->assertFalse($step['matched']);
            $this->assertTrue($step['skipped']);
        }
    }
}
