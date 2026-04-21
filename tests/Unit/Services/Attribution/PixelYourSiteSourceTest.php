<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Attribution;

use App\Models\Order;
use App\Models\Store;
use App\Models\Workspace;
use App\Services\Attribution\Sources\PixelYourSiteSource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PixelYourSiteSourceTest extends TestCase
{
    use RefreshDatabase;

    private PixelYourSiteSource $source;
    private Workspace $workspace;
    private Store $store;

    protected function setUp(): void
    {
        parent::setUp();

        $this->source    = new PixelYourSiteSource();
        $this->workspace = Workspace::factory()->create();
        $this->store     = Store::factory()->create(['workspace_id' => $this->workspace->id]);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function makeOrder(array $overrides = []): Order
    {
        return Order::factory()->create(array_merge([
            'workspace_id' => $this->workspace->id,
            'store_id'     => $this->store->id,
        ], $overrides));
    }

    private function pysRawMeta(array $pysData, ?array $fbCookie = null): array
    {
        $meta = ['pys_enrich_data' => $pysData];

        if ($fbCookie !== null) {
            $meta['pys_fb_cookie'] = $fbCookie;
        }

        return $meta;
    }

    private function makePysUtm(
        string $source = 'Klaviyo',
        string $medium = 'email',
        string $campaign = 'SPRING25',
        string $content = 'undefined',
        string $term = 'undefined',
    ): string {
        return "utm_source:{$source}|utm_medium:{$medium}|utm_campaign:{$campaign}|utm_content:{$content}|utm_term:{$term}";
    }

    // -------------------------------------------------------------------------
    // No PYS data
    // -------------------------------------------------------------------------

    public function test_returns_null_when_raw_meta_is_null(): void
    {
        $order = $this->makeOrder(['raw_meta' => null]);

        $this->assertNull($this->source->tryParse($order));
    }

    public function test_returns_null_when_pys_enrich_data_key_absent(): void
    {
        $order = $this->makeOrder(['raw_meta' => ['fee_lines' => []]]);

        $this->assertNull($this->source->tryParse($order));
    }

    // -------------------------------------------------------------------------
    // Happy path — Klaviyo email
    // -------------------------------------------------------------------------

    public function test_parses_klaviyo_email_from_pys_utm(): void
    {
        $pys = [
            'pys_utm'      => $this->makePysUtm('Klaviyo', 'email', 'SPRING25'),
            'last_pys_utm' => $this->makePysUtm('Klaviyo', 'email', 'SPRING25mail201'),
            'pys_landing'  => 'https://store.com/product/',
        ];

        $order = $this->makeOrder(['raw_meta' => $this->pysRawMeta($pys)]);

        $result = $this->source->tryParse($order);

        $this->assertNotNull($result);
        $this->assertSame('pys', $result->source_type);
        $this->assertSame('Klaviyo', $result->first_touch['source']);
        $this->assertSame('email', $result->first_touch['medium']);
        $this->assertSame('SPRING25', $result->first_touch['campaign']);
        $this->assertSame('Klaviyo', $result->last_touch['source']);
        $this->assertSame('SPRING25mail201', $result->last_touch['campaign']);
    }

    // -------------------------------------------------------------------------
    // "undefined" literal handling
    // -------------------------------------------------------------------------

    public function test_treats_undefined_literal_as_null_in_utm(): void
    {
        $pys = [
            'pys_utm'      => $this->makePysUtm('Klaviyo', 'email', 'undefined', 'undefined', 'undefined'),
            'last_pys_utm' => $this->makePysUtm('Klaviyo', 'email', 'undefined'),
        ];

        $order = $this->makeOrder(['raw_meta' => $this->pysRawMeta($pys)]);

        $result = $this->source->tryParse($order);

        $this->assertNotNull($result);
        // campaign / content / term should be absent when value is "undefined"
        $this->assertArrayNotHasKey('campaign', $result->first_touch);
        $this->assertArrayNotHasKey('content', $result->first_touch);
        $this->assertArrayNotHasKey('term', $result->first_touch);
    }

    // -------------------------------------------------------------------------
    // pys_source fallback when utm_source absent
    // -------------------------------------------------------------------------

    public function test_falls_back_to_pys_source_when_utm_source_absent(): void
    {
        $pys = [
            'pys_utm'    => 'utm_medium:organic|utm_campaign:homepage',  // no utm_source key
            'pys_source' => 'google.com',
        ];

        $order = $this->makeOrder(['raw_meta' => $this->pysRawMeta($pys)]);

        $result = $this->source->tryParse($order);

        $this->assertNotNull($result);
        $this->assertSame('google.com', $result->first_touch['source']);
        $this->assertSame('organic', $result->first_touch['medium']);
    }

    // -------------------------------------------------------------------------
    // Returns null when both touches have no usable source
    // -------------------------------------------------------------------------

    public function test_returns_null_when_no_source_in_pys_data(): void
    {
        $pys = [
            // utm strings exist but source is "undefined" and pys_source is also absent
            'pys_utm'    => 'utm_source:undefined|utm_medium:email',
            'pys_source' => '',
        ];

        $order = $this->makeOrder(['raw_meta' => $this->pysRawMeta($pys)]);

        $result = $this->source->tryParse($order);

        $this->assertNull($result);
    }

    // -------------------------------------------------------------------------
    // Single-touch fallback (only last_pys_utm present)
    // -------------------------------------------------------------------------

    public function test_mirrors_last_touch_as_first_when_pys_utm_absent(): void
    {
        $pys = [
            'last_pys_utm' => $this->makePysUtm('tiktok', 'cpc', 'summer_sale'),
        ];

        $order = $this->makeOrder(['raw_meta' => $this->pysRawMeta($pys)]);

        $result = $this->source->tryParse($order);

        $this->assertNotNull($result);
        $this->assertSame('tiktok', $result->first_touch['source']);
        $this->assertSame('tiktok', $result->last_touch['source']);
    }

    // -------------------------------------------------------------------------
    // Click IDs (pys_fb_cookie)
    // -------------------------------------------------------------------------

    public function test_extracts_fbc_and_fbp_from_pys_fb_cookie(): void
    {
        $pys     = ['pys_utm' => $this->makePysUtm('facebook', 'cpc')];
        $fbCookie = ['fbc' => 'fb.1.123.abc', 'fbp' => 'fb.1.456.def'];

        $order = $this->makeOrder(['raw_meta' => $this->pysRawMeta($pys, $fbCookie)]);

        $result = $this->source->tryParse($order);

        $this->assertNotNull($result->click_ids);
        $this->assertSame('fb.1.123.abc', $result->click_ids['fbc']);
        $this->assertSame('fb.1.456.def', $result->click_ids['fbp']);
    }

    public function test_click_ids_null_when_pys_fb_cookie_absent(): void
    {
        $pys   = ['pys_utm' => $this->makePysUtm('facebook', 'cpc')];
        $order = $this->makeOrder(['raw_meta' => $this->pysRawMeta($pys)]);

        $result = $this->source->tryParse($order);

        $this->assertNull($result->click_ids);
    }

    public function test_click_ids_null_when_fb_cookie_values_are_undefined(): void
    {
        $pys      = ['pys_utm' => $this->makePysUtm('facebook', 'cpc')];
        $fbCookie = ['fbc' => 'undefined', 'fbp' => 'undefined'];

        $order = $this->makeOrder(['raw_meta' => $this->pysRawMeta($pys, $fbCookie)]);

        $result = $this->source->tryParse($order);

        $this->assertNull($result->click_ids);
    }

    // -------------------------------------------------------------------------
    // raw_data included for debug
    // -------------------------------------------------------------------------

    public function test_includes_raw_data_for_debug(): void
    {
        $pys   = ['pys_utm' => $this->makePysUtm('klaviyo', 'email')];
        $order = $this->makeOrder(['raw_meta' => $this->pysRawMeta($pys)]);

        $result = $this->source->tryParse($order);

        $this->assertNotNull($result->raw_data);
        $this->assertArrayHasKey('pys_enrich_data', $result->raw_data);
    }

    // -------------------------------------------------------------------------
    // landing_page included when present
    // -------------------------------------------------------------------------

    public function test_includes_landing_page_in_touch(): void
    {
        $pys = [
            'pys_utm'     => $this->makePysUtm('klaviyo', 'email'),
            'pys_landing' => 'https://store.com/product/',
        ];

        $order = $this->makeOrder(['raw_meta' => $this->pysRawMeta($pys)]);

        $result = $this->source->tryParse($order);

        $this->assertSame('https://store.com/product/', $result->first_touch['landing_page']);
    }
}
