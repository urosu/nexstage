<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\Store;
use App\Models\Workspace;
use App\Services\RevenueAttributionService;
use App\Services\WorkspaceContext;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RevenueAttributionServiceTest extends TestCase
{
    use RefreshDatabase;

    private RevenueAttributionService $service;
    private Workspace $workspace;
    private Store $store;
    private Carbon $from;
    private Carbon $to;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workspace = Workspace::factory()->create(['reporting_currency' => 'EUR']);
        $this->store     = Store::factory()->create(['workspace_id' => $this->workspace->id]);
        $this->service   = app(RevenueAttributionService::class);
        $this->from      = Carbon::today()->startOfDay();
        $this->to        = Carbon::today()->endOfDay();

        app(WorkspaceContext::class)->set($this->workspace->id);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function insertOrder(array $overrides = []): void
    {
        DB::table('orders')->insert(array_merge([
            'workspace_id'                => $this->workspace->id,
            'store_id'                    => $this->store->id,
            'external_id'                 => uniqid('order-', true),
            'status'                      => 'completed',
            'currency'                    => 'EUR',
            'total'                       => 100.00,
            'subtotal'                    => 90.00,
            'tax'                         => 10.00,
            'shipping'                    => 5.00,
            'discount'                    => 0.00,
            'total_in_reporting_currency' => 100.00,
            'occurred_at'                 => Carbon::today()->midDay(),
            'synced_at'                   => now(),
            'created_at'                  => now(),
            'updated_at'                  => now(),
        ], $overrides));
    }

    /**
     * Build a minimal attribution_last_touch JSON blob.
     *
     * Pass channel_type and channel to simulate a recognized channel; omit them
     * to simulate an unrecognized source (no channel_mappings row matched).
     */
    private function touch(string $source, string $medium = 'cpc', ?string $channelType = null, ?string $channel = null, ?string $campaign = null): string
    {
        $data = ['source' => $source, 'medium' => $medium];
        if ($channelType !== null) {
            $data['channel_type'] = $channelType;
        }
        if ($channel !== null) {
            $data['channel'] = $channel;
        }
        if ($campaign !== null) {
            $data['campaign'] = $campaign;
        }
        return json_encode($data, JSON_THROW_ON_ERROR);
    }

    // -------------------------------------------------------------------------
    // getAttributedRevenue — channel bucketing
    // -------------------------------------------------------------------------

    public function test_paid_social_bucketed_as_facebook(): void
    {
        // Three different paid_social sources (all Meta variants in the channel seed)
        foreach ([
            ['source' => 'facebook',  'channel' => 'Paid — Facebook'],
            ['source' => 'instagram', 'channel' => 'Paid — Instagram'],
            ['source' => 'fb',        'channel' => 'Paid — Facebook'],
        ] as $entry) {
            $this->insertOrder([
                'external_id'                 => uniqid('order-'),
                'attribution_source'          => 'wc_native',
                'attribution_last_touch'      => $this->touch($entry['source'], 'cpc', 'paid_social', $entry['channel']),
                'total_in_reporting_currency' => 50.00,
            ]);
        }

        $result = $this->service->getAttributedRevenue($this->workspace->id, $this->from, $this->to);

        $this->assertEqualsWithDelta(150.00, $result['facebook'], 0.01);
        $this->assertEqualsWithDelta(0.00,   $result['google'],   0.01);
        $this->assertEqualsWithDelta(0.00,   $result['other_tagged'], 0.01);
    }

    public function test_paid_search_bucketed_as_google(): void
    {
        $this->insertOrder([
            'attribution_source'          => 'wc_native',
            'attribution_last_touch'      => $this->touch('google', 'cpc', 'paid_search', 'Paid — Google Ads', '12345'),
            'total_in_reporting_currency' => 100.00,
        ]);
        $this->insertOrder([
            'external_id'                 => uniqid('order-'),
            'attribution_source'          => 'pys',
            'attribution_last_touch'      => $this->touch('adwords', 'cpc', 'paid_search', 'Paid — Google Ads'),
            'total_in_reporting_currency' => 50.00,
        ]);

        $result = $this->service->getAttributedRevenue($this->workspace->id, $this->from, $this->to);

        $this->assertEqualsWithDelta(150.00, $result['google'],   0.01);
        $this->assertEqualsWithDelta(0.00,   $result['facebook'], 0.01);
    }

    public function test_email_channel_goes_to_other_tagged(): void
    {
        $this->insertOrder([
            'attribution_source'          => 'pys',
            'attribution_last_touch'      => $this->touch('klaviyo', 'email', 'email', 'Email — Klaviyo'),
            'total_in_reporting_currency' => 60.00,
        ]);

        $result = $this->service->getAttributedRevenue($this->workspace->id, $this->from, $this->to);

        $this->assertEqualsWithDelta(60.00, $result['other_tagged'], 0.01);
        $this->assertEqualsWithDelta(0.00,  $result['facebook'],     0.01);
        $this->assertEqualsWithDelta(0.00,  $result['google'],       0.01);
    }

    public function test_unrecognized_source_goes_to_other_tagged(): void
    {
        // No channel/channel_type = unrecognized source (no channel_mappings row matched)
        $this->insertOrder([
            'attribution_source'          => 'pys',
            'attribution_last_touch'      => $this->touch('snapchat', 'cpc'),
            'total_in_reporting_currency' => 60.00,
        ]);

        $result = $this->service->getAttributedRevenue($this->workspace->id, $this->from, $this->to);

        $this->assertEqualsWithDelta(60.00, $result['other_tagged'], 0.01);
        $this->assertEqualsWithDelta(0.00,  $result['facebook'],     0.01);
        $this->assertEqualsWithDelta(0.00,  $result['google'],       0.01);
    }

    public function test_referrer_heuristic_excluded_from_all_tagged_buckets(): void
    {
        // attribution_source='referrer' = heuristic, no UTM link clicked → excluded from tagged
        $this->insertOrder([
            'attribution_source'          => 'referrer',
            'attribution_last_touch'      => $this->touch('google', 'organic', 'organic_search', 'Organic — Google'),
            'total_in_reporting_currency' => 100.00,
        ]);

        $result = $this->service->getAttributedRevenue($this->workspace->id, $this->from, $this->to);

        $this->assertEqualsWithDelta(0.00, $result['total_tagged'], 0.01);
    }

    public function test_no_attribution_excluded_from_tagged(): void
    {
        $this->insertOrder([
            'attribution_source'          => null,
            'attribution_last_touch'      => null,
            'total_in_reporting_currency' => 100.00,
        ]);

        $result = $this->service->getAttributedRevenue($this->workspace->id, $this->from, $this->to);

        $this->assertEqualsWithDelta(0.00, $result['total_tagged'], 0.01);
    }

    public function test_total_tagged_is_sum_of_channels(): void
    {
        $this->insertOrder([
            'attribution_source'          => 'wc_native',
            'attribution_last_touch'      => $this->touch('facebook', 'cpc', 'paid_social', 'Paid — Facebook'),
            'total_in_reporting_currency' => 100.00,
        ]);
        $this->insertOrder([
            'external_id'                 => uniqid(),
            'attribution_source'          => 'wc_native',
            'attribution_last_touch'      => $this->touch('google', 'cpc', 'paid_search', 'Paid — Google Ads'),
            'total_in_reporting_currency' => 200.00,
        ]);
        $this->insertOrder([
            'external_id'                 => uniqid(),
            'attribution_source'          => 'pys',
            'attribution_last_touch'      => $this->touch('klaviyo', 'email', 'email', 'Email — Klaviyo'),
            'total_in_reporting_currency' => 50.00,
        ]);

        $result = $this->service->getAttributedRevenue($this->workspace->id, $this->from, $this->to);

        $this->assertEqualsWithDelta(350.00, $result['total_tagged'], 0.01);
        $this->assertEqualsWithDelta(
            $result['facebook'] + $result['google'] + $result['other_tagged'],
            $result['total_tagged'],
            0.01,
        );
    }

    public function test_null_total_in_reporting_currency_excluded(): void
    {
        $this->insertOrder([
            'attribution_source'          => 'wc_native',
            'attribution_last_touch'      => $this->touch('facebook', 'cpc', 'paid_social', 'Paid — Facebook'),
            'total_in_reporting_currency' => null,
        ]);
        $this->insertOrder([
            'external_id'                 => uniqid(),
            'attribution_source'          => 'wc_native',
            'attribution_last_touch'      => $this->touch('facebook', 'cpc', 'paid_social', 'Paid — Facebook'),
            'total_in_reporting_currency' => 50.00,
        ]);

        $result = $this->service->getAttributedRevenue($this->workspace->id, $this->from, $this->to);

        $this->assertEqualsWithDelta(50.00, $result['facebook'], 0.01);
    }

    public function test_only_completed_and_processing_counted(): void
    {
        // Should NOT be counted
        foreach (['refunded', 'cancelled', 'other'] as $status) {
            $this->insertOrder([
                'external_id'                 => uniqid("order-{$status}-"),
                'status'                      => $status,
                'attribution_source'          => 'wc_native',
                'attribution_last_touch'      => $this->touch('facebook', 'cpc', 'paid_social', 'Paid — Facebook'),
                'total_in_reporting_currency' => 999.00,
            ]);
        }
        // Should be counted
        $this->insertOrder([
            'status'                      => 'processing',
            'attribution_source'          => 'wc_native',
            'attribution_last_touch'      => $this->touch('facebook', 'cpc', 'paid_social', 'Paid — Facebook'),
            'total_in_reporting_currency' => 100.00,
        ]);

        $result = $this->service->getAttributedRevenue($this->workspace->id, $this->from, $this->to);

        $this->assertEqualsWithDelta(100.00, $result['facebook'], 0.01);
    }

    public function test_store_filter_isolates_to_single_store(): void
    {
        $store2 = Store::factory()->create(['workspace_id' => $this->workspace->id]);

        $this->insertOrder([
            'store_id'                    => $this->store->id,
            'attribution_source'          => 'wc_native',
            'attribution_last_touch'      => $this->touch('facebook', 'cpc', 'paid_social', 'Paid — Facebook'),
            'total_in_reporting_currency' => 100.00,
        ]);
        $this->insertOrder([
            'external_id'                 => uniqid(),
            'store_id'                    => $store2->id,
            'attribution_source'          => 'wc_native',
            'attribution_last_touch'      => $this->touch('facebook', 'cpc', 'paid_social', 'Paid — Facebook'),
            'total_in_reporting_currency' => 200.00,
        ]);

        $result = $this->service->getAttributedRevenue(
            $this->workspace->id, $this->from, $this->to, storeId: $this->store->id
        );

        $this->assertEqualsWithDelta(100.00, $result['facebook'], 0.01);
    }

    public function test_empty_date_range_returns_zeros(): void
    {
        $this->insertOrder([
            'attribution_source'          => 'wc_native',
            'attribution_last_touch'      => $this->touch('facebook', 'cpc', 'paid_social', 'Paid — Facebook'),
            'total_in_reporting_currency' => 100.00,
        ]);

        $yesterday = Carbon::yesterday();
        $result    = $this->service->getAttributedRevenue(
            $this->workspace->id,
            $yesterday->copy()->startOfDay(),
            $yesterday->copy()->endOfDay(),
        );

        $this->assertEqualsWithDelta(0.00, $result['total_tagged'], 0.01);
    }

    // -------------------------------------------------------------------------
    // getCampaignAttributedRevenue
    // -------------------------------------------------------------------------

    public function test_get_campaign_attributed_revenue_case_insensitive(): void
    {
        $this->insertOrder([
            'attribution_source'          => 'wc_native',
            'attribution_last_touch'      => $this->touch('google', 'cpc', 'paid_search', 'Paid — Google Ads', 'SUMMER20'),
            'total_in_reporting_currency' => 120.00,
        ]);
        $this->insertOrder([
            'external_id'                 => uniqid(),
            'attribution_source'          => 'pys',
            'attribution_last_touch'      => $this->touch('facebook', 'cpc', 'paid_social', 'Paid — Facebook', 'summer20'),
            'total_in_reporting_currency' => 80.00,
        ]);

        $result = $this->service->getCampaignAttributedRevenue(
            $this->workspace->id, 'summer20', $this->from, $this->to,
        );

        $this->assertEqualsWithDelta(200.00, $result, 0.01);
    }

    public function test_get_campaign_attributed_revenue_only_active_orders(): void
    {
        $this->insertOrder([
            'attribution_source'          => 'wc_native',
            'attribution_last_touch'      => $this->touch('google', 'cpc', 'paid_search', 'Paid — Google Ads', 'promo'),
            'status'                      => 'completed',
            'total_in_reporting_currency' => 100.00,
        ]);
        $this->insertOrder([
            'external_id'                 => uniqid(),
            'attribution_source'          => 'wc_native',
            'attribution_last_touch'      => $this->touch('google', 'cpc', 'paid_search', 'Paid — Google Ads', 'promo'),
            'status'                      => 'refunded',
            'total_in_reporting_currency' => 999.00,
        ]);

        $result = $this->service->getCampaignAttributedRevenue(
            $this->workspace->id, 'promo', $this->from, $this->to,
        );

        $this->assertEqualsWithDelta(100.00, $result, 0.01);
    }

    // -------------------------------------------------------------------------
    // getUnattributedRevenue
    // -------------------------------------------------------------------------

    public function test_get_unattributed_revenue_returns_difference(): void
    {
        $result = $this->service->getUnattributedRevenue(totalRevenue: 1000.0, totalTagged: 400.0);

        $this->assertEqualsWithDelta(600.0, $result, 0.01);
    }

    public function test_get_unattributed_revenue_can_be_negative(): void
    {
        // Tagged exceeds total → negative result indicates platform over-reporting (iOS14 inflation).
        // The value is intentionally NOT clamped so the Dashboard "Not Tracked" banner can surface it.
        $result = $this->service->getUnattributedRevenue(totalRevenue: 100.0, totalTagged: 150.0);

        $this->assertEqualsWithDelta(-50.0, $result, 0.01);
    }
}
