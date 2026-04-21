<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Attribution;

use App\Models\Order;
use App\Models\Store;
use App\Models\Workspace;
use App\Services\Attribution\Sources\ShopifyCustomerJourneySource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Unit tests for ShopifyCustomerJourneySource.
 *
 * The source reads platform_data['customer_journey_summary'] from an order
 * and extracts first_touch + last_touch UTM parameters. It is the highest-priority
 * Shopify-specific attribution source (priority 2 overall, after PixelYourSiteSource).
 */
class ShopifyCustomerJourneySourceTest extends TestCase
{
    use RefreshDatabase;

    private ShopifyCustomerJourneySource $source;
    private Workspace $workspace;
    private Store $store;

    protected function setUp(): void
    {
        parent::setUp();

        $this->source    = new ShopifyCustomerJourneySource();
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
    // Null fallthrough cases — WooCommerce orders and missing data
    // -------------------------------------------------------------------------

    public function test_returns_null_when_platform_data_is_null(): void
    {
        $order = $this->makeOrder(['platform_data' => null]);

        $this->assertNull($this->source->tryParse($order));
    }

    public function test_returns_null_when_customer_journey_summary_is_absent(): void
    {
        $order = $this->makeOrder(['platform_data' => ['order_name' => '#1001']]);

        $this->assertNull($this->source->tryParse($order));
    }

    public function test_returns_null_when_customer_journey_summary_is_empty(): void
    {
        $order = $this->makeOrder(['platform_data' => ['customer_journey_summary' => []]]);

        $this->assertNull($this->source->tryParse($order));
    }

    public function test_returns_null_when_both_visits_have_no_utm_source(): void
    {
        $order = $this->makeOrder(['platform_data' => [
            'customer_journey_summary' => [
                'firstVisit' => ['landingPage' => 'https://example.com', 'utmParameters' => []],
                'lastVisit'  => ['landingPage' => 'https://example.com', 'utmParameters' => []],
            ],
        ]]);

        $this->assertNull($this->source->tryParse($order));
    }

    // -------------------------------------------------------------------------
    // Full UTM extraction
    // -------------------------------------------------------------------------

    public function test_extracts_full_utm_from_both_visits(): void
    {
        $order = $this->makeOrder(['platform_data' => [
            'customer_journey_summary' => [
                'firstVisit' => [
                    'utmParameters' => [
                        'source'   => 'facebook',
                        'medium'   => 'cpc',
                        'campaign' => 'summer_first',
                        'content'  => 'v1',
                        'term'     => 'shoes',
                    ],
                    'landingPage' => 'https://example.com/landing',
                    'referrerUrl' => 'https://facebook.com',
                ],
                'lastVisit' => [
                    'utmParameters' => [
                        'source'   => 'google',
                        'medium'   => 'cpc',
                        'campaign' => 'summer_retarget',
                    ],
                    'landingPage' => 'https://example.com/product',
                ],
            ],
        ]]);

        $result = $this->source->tryParse($order);

        $this->assertNotNull($result);
        $this->assertSame('shopify_journey', $result->source_type);

        // First touch
        $this->assertSame('facebook', $result->first_touch['source']);
        $this->assertSame('cpc', $result->first_touch['medium']);
        $this->assertSame('summer_first', $result->first_touch['campaign']);
        $this->assertSame('v1', $result->first_touch['content']);
        $this->assertSame('shoes', $result->first_touch['term']);
        $this->assertSame('https://example.com/landing', $result->first_touch['landing_page']);
        $this->assertSame('https://facebook.com', $result->first_touch['referrer_url']);

        // Last touch
        $this->assertSame('google', $result->last_touch['source']);
        $this->assertSame('cpc', $result->last_touch['medium']);
        $this->assertSame('summer_retarget', $result->last_touch['campaign']);
    }

    // -------------------------------------------------------------------------
    // Single-visit fallback (mirror whichever touch is available)
    // -------------------------------------------------------------------------

    public function test_mirrors_last_touch_when_first_visit_has_no_utm(): void
    {
        $order = $this->makeOrder(['platform_data' => [
            'customer_journey_summary' => [
                'firstVisit' => ['utmParameters' => [], 'landingPage' => null],
                'lastVisit'  => [
                    'utmParameters' => ['source' => 'instagram', 'medium' => 'social'],
                ],
            ],
        ]]);

        $result = $this->source->tryParse($order);

        $this->assertNotNull($result);
        // first_touch should be mirrored from last_touch
        $this->assertSame('instagram', $result->first_touch['source']);
        $this->assertSame('social', $result->first_touch['medium']);
        $this->assertSame('instagram', $result->last_touch['source']);
    }

    public function test_mirrors_first_touch_when_last_visit_has_no_utm(): void
    {
        $order = $this->makeOrder(['platform_data' => [
            'customer_journey_summary' => [
                'firstVisit' => [
                    'utmParameters' => ['source' => 'email', 'medium' => 'newsletter'],
                ],
                'lastVisit' => ['utmParameters' => []],
            ],
        ]]);

        $result = $this->source->tryParse($order);

        $this->assertNotNull($result);
        $this->assertSame('email', $result->first_touch['source']);
        $this->assertSame('email', $result->last_touch['source']);
    }

    // -------------------------------------------------------------------------
    // Raw data is preserved for debug route
    // -------------------------------------------------------------------------

    public function test_raw_data_contains_journey_summary(): void
    {
        $journey = [
            'firstVisit' => ['utmParameters' => ['source' => 'google']],
            'lastVisit'  => ['utmParameters' => ['source' => 'google']],
        ];

        $order  = $this->makeOrder(['platform_data' => ['customer_journey_summary' => $journey]]);
        $result = $this->source->tryParse($order);

        $this->assertNotNull($result);
        $this->assertSame($journey, $result->raw_data['customer_journey_summary']);
    }

    // -------------------------------------------------------------------------
    // click_ids are null (Shopify journey has no click IDs)
    // -------------------------------------------------------------------------

    public function test_click_ids_are_null(): void
    {
        $order = $this->makeOrder(['platform_data' => [
            'customer_journey_summary' => [
                'lastVisit' => ['utmParameters' => ['source' => 'google']],
            ],
        ]]);

        $result = $this->source->tryParse($order);

        $this->assertNull($result->click_ids);
    }

    // -------------------------------------------------------------------------
    // Does not read WooCommerce orders (no platform_data)
    // -------------------------------------------------------------------------

    public function test_passes_through_woocommerce_order_without_platform_data(): void
    {
        $order = $this->makeOrder([
            'platform_data' => null,
            'utm_source'    => 'google',
            'utm_medium'    => 'cpc',
        ]);

        $this->assertNull($this->source->tryParse($order));
    }
}
