<?php

declare(strict_types=1);

namespace Tests\Feature\Controllers;

use App\Models\AdAccount;
use App\Models\Campaign;
use App\Models\Store;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Feature tests for Phase 3.5 synthesis additions to AcquisitionController.
 *
 * Verifies three non-obvious behaviours added in Phase 3.5:
 *   1. Attribution model switcher cascades into discrepancy (Tab 2) attribution query.
 *   2. Opportunities sidebar generates a channel_reallocation item from inline CPA logic.
 *   3. Customer journeys (Tab 3) returns orders with the attribution JSONB fields intact.
 *
 * @see app/Http/Controllers/AcquisitionController.php
 * @see PROGRESS.md Phase 3.5
 */
class AcquisitionSynthesisTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Workspace $workspace;
    private Store $store;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user      = User::factory()->create();
        $this->workspace = Workspace::factory()->create(['owner_id' => $this->user->id]);

        WorkspaceUser::factory()->owner()->create([
            'user_id'      => $this->user->id,
            'workspace_id' => $this->workspace->id,
        ]);

        $this->store = Store::factory()->create([
            'workspace_id'             => $this->workspace->id,
            'historical_import_status' => 'completed',
        ]);
    }

    private function visit(array $params = []): \Illuminate\Testing\TestResponse
    {
        $query = $params ? '?' . http_build_query($params) : '';

        return $this->actingAs($this->user)
            ->get("/{$this->workspace->slug}/acquisition{$query}");
    }

    private function insertOrder(array $overrides = []): int
    {
        DB::table('orders')->insert(array_merge([
            'workspace_id'                => $this->workspace->id,
            'store_id'                    => $this->store->id,
            'external_id'                 => (string) random_int(1000, 999999),
            'external_number'             => '101',
            'status'                      => 'completed',
            'currency'                    => 'EUR',
            'total'                       => 150.00,
            'subtotal'                    => 130.00,
            'tax'                         => 20.00,
            'shipping'                    => 5.00,
            'payment_fee'                 => 0.00,
            'discount'                    => 0.00,
            'total_in_reporting_currency' => 150.00,
            'is_first_for_customer'       => false,
            'occurred_at'                 => now()->subDays(3),
            'synced_at'                   => now(),
            'created_at'                  => now(),
            'updated_at'                  => now(),
        ], $overrides));

        return (int) DB::table('orders')->latest('id')->value('id');
    }

    private function insertAdInsights(Campaign $campaign, AdAccount $adAccount, array $overrides = []): void
    {
        DB::table('ad_insights')->insert(array_merge([
            'workspace_id'                => $this->workspace->id,
            'ad_account_id'               => $adAccount->id,
            'campaign_id'                 => $campaign->id,
            'adset_id'                    => null,
            'ad_id'                       => null,
            'level'                       => 'campaign',
            'date'                        => now()->subDays(3)->toDateString(),
            'hour'                        => null,
            'spend'                       => 100.00,
            'spend_in_reporting_currency' => 100.00,
            'impressions'                 => 2000,
            'clicks'                      => 100,
            'platform_conversions_value'  => 400.00,
            'currency'                    => 'EUR',
            'created_at'                  => now(),
            'updated_at'                  => now(),
        ], $overrides));
    }

    // ── Tests ────────────────────────────────────────────────────────────────

    /**
     * Attribution model param must flow into batchGetCampaignAttributedRevenues.
     *
     * The order has attribution_first_touch.campaign set but NO attribution_last_touch.
     * With first_touch model, the campaign row should show attributed_revenue > 0.
     * With last_touch model (default), attributed_revenue should be 0 for that campaign.
     */
    public function test_global_attribution_switcher_cascades_into_discrepancy_tab(): void
    {
        $adAccount = AdAccount::factory()->create([
            'workspace_id' => $this->workspace->id,
            'platform'     => 'facebook',
        ]);

        $campaign = Campaign::factory()->create([
            'workspace_id'  => $this->workspace->id,
            'ad_account_id' => $adAccount->id,
            'name'          => 'First Touch Only Campaign',
            'status'        => 'active',
        ]);

        $this->insertAdInsights($campaign, $adAccount);

        // Order tagged with first_touch attribution; last_touch intentionally null.
        $this->insertOrder([
            'attribution_source'       => 'pys',
            'attribution_first_touch'  => json_encode([
                'source'       => 'facebook',
                'medium'       => 'cpc',
                'campaign'     => 'First Touch Only Campaign',
                'channel'      => 'Paid Social',
                'channel_type' => 'paid_social',
            ]),
            'attribution_last_touch'   => null,
            'total_in_reporting_currency' => 150.00,
        ]);

        // With first_touch model: campaign should have attributed_revenue > 0
        $firstTouchResponse = $this->visit([
            'tab'               => 'platform-vs-real',
            'attribution_model' => 'first_touch',
        ])->assertOk();

        $discrepancy = $firstTouchResponse->inertiaProps('discrepancy');
        $row = collect($discrepancy['campaigns'])->firstWhere('campaign_id', $campaign->id);

        $this->assertNotNull($row, 'Campaign should appear in discrepancy data');
        $this->assertGreaterThan(0.0, (float) $row['attributed_revenue'],
            'first_touch model should match the order via attribution_first_touch.campaign'
        );

        // With last_touch model: same order has no attribution_last_touch → revenue = 0
        $lastTouchResponse = $this->visit([
            'tab'               => 'platform-vs-real',
            'attribution_model' => 'last_touch',
        ])->assertOk();

        $discrepancy2 = $lastTouchResponse->inertiaProps('discrepancy');
        $row2 = collect($discrepancy2['campaigns'])->firstWhere('campaign_id', $campaign->id);

        $this->assertNotNull($row2, 'Campaign should still appear (it has spend)');
        $this->assertSame(0.0, (float) $row2['attributed_revenue'],
            'last_touch model should not match the order since attribution_last_touch is null'
        );
    }

    /**
     * When paid_social CPA exceeds paid_search CPA by >20%, a channel_reallocation
     * opportunity must be generated inline by buildOpportunities().
     *
     * Setup: paid_social — spend=300, 3 new customers → CAC=100
     *        paid_search — spend=50,  5 new customers → CAC=10
     * 100 > 10 × 1.2 = 12 ✓ → expect channel_reallocation item.
     */
    public function test_opportunities_sidebar_generates_channel_reallocation_on_cpa_differential(): void
    {
        // ── Paid Social (Facebook) ────────────────────────────────────────────
        $fbAccount = AdAccount::factory()->create([
            'workspace_id' => $this->workspace->id,
            'platform'     => 'facebook',
        ]);

        $fbCampaign = Campaign::factory()->create([
            'workspace_id'  => $this->workspace->id,
            'ad_account_id' => $fbAccount->id,
            'name'          => 'FB Brand Campaign',
            'status'        => 'active',
        ]);

        // 3 spend rows → total spend = 300 for paid_social
        for ($i = 0; $i < 3; $i++) {
            $this->insertAdInsights($fbCampaign, $fbAccount, [
                'spend'                       => 100.00,
                'spend_in_reporting_currency' => 100.00,
                'platform_conversions_value'  => 200.00,
                'date'                        => now()->subDays(3 + $i)->toDateString(),
            ]);
        }

        // 3 new customers via paid_social
        for ($i = 0; $i < 3; $i++) {
            $this->insertOrder([
                'attribution_source'          => 'pys',
                'attribution_last_touch'      => json_encode([
                    'source'       => 'facebook',
                    'medium'       => 'cpc',
                    'campaign'     => 'FB Brand Campaign',
                    'channel'      => 'Paid Social',
                    'channel_type' => 'paid_social',
                ]),
                'is_first_for_customer'       => true,
                'customer_email_hash'         => hash('sha256', "paid_social_customer_{$i}"),
                'total_in_reporting_currency' => 80.00,
            ]);
        }

        // ── Paid Search (Google) ──────────────────────────────────────────────
        $gAccount = AdAccount::factory()->create([
            'workspace_id' => $this->workspace->id,
            'platform'     => 'google',
        ]);

        $gCampaign = Campaign::factory()->create([
            'workspace_id'  => $this->workspace->id,
            'ad_account_id' => $gAccount->id,
            'name'          => 'G Brand Search',
            'status'        => 'active',
        ]);

        $this->insertAdInsights($gCampaign, $gAccount, [
            'spend'                       => 50.00,
            'spend_in_reporting_currency' => 50.00,
            'platform_conversions_value'  => 300.00,
        ]);

        // 5 new customers via paid_search
        for ($i = 0; $i < 5; $i++) {
            $this->insertOrder([
                'attribution_source'          => 'pys',
                'attribution_last_touch'      => json_encode([
                    'source'       => 'google',
                    'medium'       => 'cpc',
                    'campaign'     => 'G Brand Search',
                    'channel'      => 'Paid Search',
                    'channel_type' => 'paid_search',
                ]),
                'is_first_for_customer'       => true,
                'customer_email_hash'         => hash('sha256', "paid_search_customer_{$i}"),
                'total_in_reporting_currency' => 90.00,
            ]);
        }

        $opportunities = $this->visit()->assertOk()->inertiaProps('opportunities');

        $types = array_column($opportunities, 'type');
        $this->assertContains('channel_reallocation', $types,
            'Expected channel_reallocation opportunity when paid_social CAC >> paid_search CAC'
        );

        $item = collect($opportunities)->firstWhere('type', 'channel_reallocation');
        $this->assertNotNull($item['title']);
        $this->assertNotNull($item['body']);
    }

    /**
     * Journeys tab must return orders with attribution JSONB decoded to arrays.
     *
     * Inserts an order with distinct first_touch and last_touch and verifies that
     * journeys.orders is non-empty and each order carries the three attribution keys.
     */
    public function test_customer_journeys_tab_returns_orders_with_attribution_fields(): void
    {
        $this->insertOrder([
            'attribution_source'         => 'pys',
            'attribution_first_touch'    => json_encode([
                'source'       => 'instagram',
                'medium'       => 'social',
                'campaign'     => 'Awareness Campaign',
                'channel'      => 'Paid Social',
                'channel_type' => 'paid_social',
                'timestamp'    => now()->subDays(5)->toISOString(),
                'landing_page' => 'https://example.com/products',
            ]),
            'attribution_last_touch'     => json_encode([
                'source'       => 'google',
                'medium'       => 'cpc',
                'campaign'     => 'Retargeting',
                'channel'      => 'Paid Search',
                'channel_type' => 'paid_search',
                'timestamp'    => now()->subDays(1)->toISOString(),
                'landing_page' => 'https://example.com/checkout',
            ]),
            'attribution_click_ids'      => json_encode([
                'gclid' => 'abc123xyz',
            ]),
            'customer_email_hash'        => hash('sha256', 'journey_test@example.com'),
            'is_first_for_customer'      => true,
            'total_in_reporting_currency' => 200.00,
        ]);

        $response = $this->visit(['tab' => 'journeys'])->assertOk();

        $journeys = $response->inertiaProps('journeys');

        $this->assertNotEmpty($journeys['orders'],
            'journeys.orders must be non-empty when an attributed order exists in the date range'
        );

        $order = $journeys['orders'][0];
        $this->assertArrayHasKey('attribution_first_touch',  $order);
        $this->assertArrayHasKey('attribution_last_touch',   $order);
        $this->assertArrayHasKey('attribution_click_ids',    $order);

        // Verify JSONB was decoded to arrays, not left as strings
        $this->assertIsArray($order['attribution_first_touch'],
            'attribution_first_touch should be decoded to an array'
        );
        $this->assertIsArray($order['attribution_last_touch'],
            'attribution_last_touch should be decoded to an array'
        );
        $this->assertIsArray($order['attribution_click_ids'],
            'attribution_click_ids should be decoded to an array'
        );

        $this->assertSame('Awareness Campaign', $order['attribution_first_touch']['campaign']);
        $this->assertSame('Retargeting',        $order['attribution_last_touch']['campaign']);
        $this->assertSame('abc123xyz',           $order['attribution_click_ids']['gclid']);
        $this->assertTrue($order['is_first_for_customer']);
    }
}
