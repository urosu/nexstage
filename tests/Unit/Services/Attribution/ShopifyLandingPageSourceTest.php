<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Attribution;

use App\Models\Order;
use App\Models\Store;
use App\Models\Workspace;
use App\Services\Attribution\Sources\ShopifyLandingPageSource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Unit tests for ShopifyLandingPageSource.
 *
 * The source is priority 3 (after ShopifyCustomerJourneySource). It reads the
 * landing page URL from customer_journey_summary and extracts attribution via:
 *   1. utm_* query params
 *   2. Click-ID params (gclid, fbclid, msclkid)
 *   3. Returns null if no trackable signal (falls through to WooCommerceNativeSource)
 */
class ShopifyLandingPageSourceTest extends TestCase
{
    use RefreshDatabase;

    private ShopifyLandingPageSource $source;
    private Workspace $workspace;
    private Store $store;

    protected function setUp(): void
    {
        parent::setUp();

        $this->source    = new ShopifyLandingPageSource();
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
    // Null fallthrough cases
    // -------------------------------------------------------------------------

    public function test_returns_null_when_platform_data_is_null(): void
    {
        $order = $this->makeOrder(['platform_data' => null]);

        $this->assertNull($this->source->tryParse($order));
    }

    public function test_returns_null_when_customer_journey_summary_absent(): void
    {
        $order = $this->makeOrder(['platform_data' => ['order_name' => '#1001']]);

        $this->assertNull($this->source->tryParse($order));
    }

    public function test_returns_null_when_customer_journey_summary_is_empty(): void
    {
        $order = $this->makeOrder(['platform_data' => ['customer_journey_summary' => []]]);

        $this->assertNull($this->source->tryParse($order));
    }

    public function test_returns_null_when_no_landing_page_in_either_visit(): void
    {
        $order = $this->makeOrder(['platform_data' => [
            'customer_journey_summary' => [
                'firstVisit' => ['utmParameters' => []],
                'lastVisit'  => ['utmParameters' => []],
            ],
        ]]);

        $this->assertNull($this->source->tryParse($order));
    }

    public function test_returns_null_when_landing_pages_have_no_trackable_signal(): void
    {
        $order = $this->makeOrder(['platform_data' => [
            'customer_journey_summary' => [
                'lastVisit' => ['landingPage' => 'https://example.com/products/shirt'],
            ],
        ]]);

        $this->assertNull($this->source->tryParse($order));
    }

    // -------------------------------------------------------------------------
    // UTM params in landing page URL
    // -------------------------------------------------------------------------

    public function test_extracts_utm_source_from_last_visit_landing_page(): void
    {
        $order = $this->makeOrder(['platform_data' => [
            'customer_journey_summary' => [
                'lastVisit' => [
                    'landingPage' => 'https://example.com/?utm_source=newsletter&utm_medium=email',
                ],
            ],
        ]]);

        $result = $this->source->tryParse($order);

        $this->assertNotNull($result);
        $this->assertSame('shopify_landing', $result->source_type);
        $this->assertSame('newsletter', $result->last_touch['source']);
        $this->assertSame('email', $result->last_touch['medium']);
    }

    public function test_extracts_full_utm_params_from_landing_page(): void
    {
        $url   = 'https://example.com/p?utm_source=google&utm_medium=cpc&utm_campaign=spring&utm_content=banner&utm_term=shoes';
        $order = $this->makeOrder(['platform_data' => [
            'customer_journey_summary' => [
                'lastVisit' => ['landingPage' => $url],
            ],
        ]]);

        $result = $this->source->tryParse($order);

        $this->assertNotNull($result);
        $this->assertSame('google', $result->last_touch['source']);
        $this->assertSame('cpc', $result->last_touch['medium']);
        $this->assertSame('spring', $result->last_touch['campaign']);
        $this->assertSame('banner', $result->last_touch['content']);
        $this->assertSame('shoes', $result->last_touch['term']);
        $this->assertSame($url, $result->last_touch['landing_page']);
    }

    public function test_first_touch_mirrors_last_touch_from_url(): void
    {
        $url   = 'https://example.com/?utm_source=tiktok&utm_medium=social';
        $order = $this->makeOrder(['platform_data' => [
            'customer_journey_summary' => [
                'lastVisit' => ['landingPage' => $url],
            ],
        ]]);

        $result = $this->source->tryParse($order);

        $this->assertNotNull($result);
        $this->assertSame($result->last_touch, $result->first_touch);
    }

    // -------------------------------------------------------------------------
    // Last-visit takes precedence over first-visit
    // -------------------------------------------------------------------------

    public function test_prefers_last_visit_landing_page_over_first_visit(): void
    {
        $order = $this->makeOrder(['platform_data' => [
            'customer_journey_summary' => [
                'firstVisit' => ['landingPage' => 'https://example.com/?utm_source=organic_first'],
                'lastVisit'  => ['landingPage' => 'https://example.com/?utm_source=paid_last'],
            ],
        ]]);

        $result = $this->source->tryParse($order);

        $this->assertNotNull($result);
        $this->assertSame('paid_last', $result->last_touch['source']);
    }

    public function test_falls_back_to_first_visit_when_last_visit_has_no_landing_page(): void
    {
        $order = $this->makeOrder(['platform_data' => [
            'customer_journey_summary' => [
                'firstVisit' => ['landingPage' => 'https://example.com/?utm_source=email_first'],
                'lastVisit'  => ['utmParameters' => []],
            ],
        ]]);

        $result = $this->source->tryParse($order);

        $this->assertNotNull($result);
        $this->assertSame('email_first', $result->last_touch['source']);
    }

    // -------------------------------------------------------------------------
    // Click-ID signals
    // -------------------------------------------------------------------------

    public function test_infers_google_cpc_from_gclid(): void
    {
        $url   = 'https://example.com/?gclid=abc123';
        $order = $this->makeOrder(['platform_data' => [
            'customer_journey_summary' => [
                'lastVisit' => ['landingPage' => $url],
            ],
        ]]);

        $result = $this->source->tryParse($order);

        $this->assertNotNull($result);
        $this->assertSame('google', $result->last_touch['source']);
        $this->assertSame('cpc', $result->last_touch['medium']);
    }

    public function test_infers_facebook_cpc_from_fbclid(): void
    {
        $url   = 'https://example.com/?fbclid=xyz789';
        $order = $this->makeOrder(['platform_data' => [
            'customer_journey_summary' => [
                'lastVisit' => ['landingPage' => $url],
            ],
        ]]);

        $result = $this->source->tryParse($order);

        $this->assertNotNull($result);
        $this->assertSame('facebook', $result->last_touch['source']);
        $this->assertSame('cpc', $result->last_touch['medium']);
    }

    public function test_infers_bing_cpc_from_msclkid(): void
    {
        $url   = 'https://example.com/?msclkid=def456';
        $order = $this->makeOrder(['platform_data' => [
            'customer_journey_summary' => [
                'lastVisit' => ['landingPage' => $url],
            ],
        ]]);

        $result = $this->source->tryParse($order);

        $this->assertNotNull($result);
        $this->assertSame('bing', $result->last_touch['source']);
        $this->assertSame('cpc', $result->last_touch['medium']);
    }

    // -------------------------------------------------------------------------
    // UTM takes precedence over click-IDs
    // -------------------------------------------------------------------------

    public function test_utm_source_takes_precedence_over_gclid(): void
    {
        $url   = 'https://example.com/?utm_source=brand&utm_medium=search&gclid=abc';
        $order = $this->makeOrder(['platform_data' => [
            'customer_journey_summary' => [
                'lastVisit' => ['landingPage' => $url],
            ],
        ]]);

        $result = $this->source->tryParse($order);

        $this->assertNotNull($result);
        $this->assertSame('brand', $result->last_touch['source']);
        $this->assertSame('search', $result->last_touch['medium']);
    }

    // -------------------------------------------------------------------------
    // Raw data and click_ids
    // -------------------------------------------------------------------------

    public function test_raw_data_contains_landing_page(): void
    {
        $url   = 'https://example.com/?utm_source=test';
        $order = $this->makeOrder(['platform_data' => [
            'customer_journey_summary' => [
                'lastVisit' => ['landingPage' => $url],
            ],
        ]]);

        $result = $this->source->tryParse($order);

        $this->assertNotNull($result);
        $this->assertSame($url, $result->raw_data['landing_page']);
    }

    public function test_click_ids_are_null(): void
    {
        $url   = 'https://example.com/?utm_source=pinterest';
        $order = $this->makeOrder(['platform_data' => [
            'customer_journey_summary' => [
                'lastVisit' => ['landingPage' => $url],
            ],
        ]]);

        $result = $this->source->tryParse($order);

        $this->assertNotNull($result);
        $this->assertNull($result->click_ids);
    }

    // -------------------------------------------------------------------------
    // WooCommerce passthrough
    // -------------------------------------------------------------------------

    public function test_passes_through_woocommerce_order_without_platform_data(): void
    {
        $order = $this->makeOrder([
            'platform_data' => null,
            'utm_source'    => 'google',
        ]);

        $this->assertNull($this->source->tryParse($order));
    }
}
