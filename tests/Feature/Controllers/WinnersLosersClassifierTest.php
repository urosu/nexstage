<?php

declare(strict_types=1);

namespace Tests\Feature\Controllers;

use App\Models\AdAccount;
use App\Models\AdInsight;
use App\Models\Campaign;
use App\Models\Order;
use App\Models\Store;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceUser;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Feature tests for server-side Winners/Losers classifier (PLANNING.md section 15).
 *
 * Tests three classifiers (target, peer, period) across three endpoints:
 *   GET /{workspace}/campaigns
 *   GET /{workspace}/analytics/products
 *   GET /{workspace}/stores
 *
 * Each test verifies that filter=winners/losers correctly filters the server response
 * based on the requested classifier logic. Data is inserted directly to bypass the
 * sync pipeline, which is not under test here.
 *
 * @see PLANNING.md section 15 (Winners/Losers classifier)
 * @see app/Http/Controllers/CampaignsController.php
 * @see app/Http/Controllers/AnalyticsController.php
 * @see app/Http/Controllers/StoreController.php
 */
class WinnersLosersClassifierTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Workspace $workspace;
    private Store $store;
    private AdAccount $adAccount;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->workspace = Workspace::factory()->create(['owner_id' => $this->user->id]);

        WorkspaceUser::factory()->owner()->create([
            'user_id'      => $this->user->id,
            'workspace_id' => $this->workspace->id,
        ]);

        // Store with completed import satisfies EnsureOnboardingComplete (path 1).
        $this->store = Store::factory()->create([
            'workspace_id'             => $this->workspace->id,
            'historical_import_status' => 'completed',
        ]);

        // Shared ad account used for stores/spend tests; campaigns tests create their own.
        $this->adAccount = AdAccount::factory()->create([
            'workspace_id' => $this->workspace->id,
            'platform'     => 'facebook',
        ]);
    }

    // ─── Helpers ────────────────────────────────────────────────────────────────

    /** Make an authenticated Inertia GET request and return the response. */
    private function inertiaGet(string $path, array $params = []): \Illuminate\Testing\TestResponse
    {
        $url = "/{$this->workspace->slug}/{$path}";
        if ($params) {
            $url .= '?' . http_build_query($params);
        }

        // Do NOT send X-Inertia: true — that triggers Inertia's version-check middleware,
        // which returns 409 when a build manifest exists and no version header is supplied.
        // Instead, make a normal GET (returns full-page Inertia HTML) and extract props
        // via inertiaProps() which reads the embedded page data from the view.
        return $this->actingAs($this->user)->get($url);
    }

    /**
     * Create a campaign with ad_insights spend and a UTM-attributed order.
     * Returns the campaign model.
     */
    private function createCampaignWithData(
        AdAccount $adAccount,
        array $campaignOverrides,
        float $spend,
        float $revenue,
        string $date,
    ): Campaign {
        $campaign = Campaign::factory()->create(array_merge([
            'workspace_id'  => $this->workspace->id,
            'ad_account_id' => $adAccount->id,
        ], $campaignOverrides));

        DB::table('ad_insights')->insert([
            'workspace_id'                => $this->workspace->id,
            'ad_account_id'               => $adAccount->id,
            'campaign_id'                 => $campaign->id,
            'adset_id'                    => null,
            'ad_id'                       => null,
            'level'                       => 'campaign',
            'date'                        => $date,
            'hour'                        => null,
            'spend'                       => $spend,
            'spend_in_reporting_currency' => $spend,
            'impressions'                 => 1000,
            'clicks'                      => 50,
            'currency'                    => 'EUR',
            'created_at'                  => now(),
            'updated_at'                  => now(),
        ]);

        Order::factory()->create([
            'workspace_id'                => $this->workspace->id,
            'store_id'                    => $this->store->id,
            'attribution_source'          => 'wc_native',
            'attribution_last_touch'      => [
                'source'       => 'facebook',
                'medium'       => 'cpc',
                'campaign'     => $campaign->external_id,
                'channel'      => 'Paid — Facebook',
                'channel_type' => 'paid_social',
            ],
            'status'                      => 'completed',
            'total_in_reporting_currency' => $revenue,
            'occurred_at'                 => Carbon::parse($date)->startOfDay()->addHours(12),
        ]);

        return $campaign;
    }

    /** Insert a daily_snapshots row for store revenue. */
    private function insertStoreRevenue(int $storeId, float $revenue, string $date): void
    {
        DB::table('daily_snapshots')->insert([
            'workspace_id'        => $this->workspace->id,
            'store_id'            => $storeId,
            'date'                => $date,
            'revenue'             => $revenue,
            'revenue_native'      => $revenue,
            'orders_count'        => 10,
            'aov'                 => $revenue / 10,
            'items_sold'          => 10,
            'items_per_order'     => 1.0,
            'new_customers'       => 5,
            'returning_customers' => 5,
            'created_at'          => now(),
        ]);
    }

    /** Insert an ad_insights row for workspace-level spend (used by stores marketing % calc). */
    private function insertWorkspaceAdSpend(float $spend, string $date): void
    {
        // The ad_insights_level_fk_check constraint requires campaign_id IS NOT NULL
        // when level='campaign'. Create a throwaway campaign just to satisfy the FK.
        $campaign = Campaign::factory()->create([
            'workspace_id'  => $this->workspace->id,
            'ad_account_id' => $this->adAccount->id,
        ]);

        DB::table('ad_insights')->insert([
            'workspace_id'                => $this->workspace->id,
            'ad_account_id'               => $this->adAccount->id,
            'campaign_id'                 => $campaign->id,
            'adset_id'                    => null,
            'ad_id'                       => null,
            'level'                       => 'campaign',
            'date'                        => $date,
            'hour'                        => null,
            'spend'                       => $spend,
            'spend_in_reporting_currency' => $spend,
            'impressions'                 => 1000,
            'clicks'                      => 50,
            'currency'                    => 'EUR',
            'created_at'                  => now(),
            'updated_at'                  => now(),
        ]);
    }

    // ─── Campaign: target classifier ─────────────────────────────────────────────

    /**
     * Campaign with real_roas >= workspace target_roas → winner.
     * Campaign with real_roas < workspace target_roas → loser.
     *
     * Campaign A: spend=100, revenue=400, roas=4.0 (target=3.0 → winner)
     * Campaign B: spend=100, revenue=150, roas=1.5 (target=3.0 → loser)
     */
    public function test_campaigns_target_classifier_returns_winner(): void
    {
        $this->workspace->update(['target_roas' => 3.0]);

        $adAccount = AdAccount::factory()->create(['workspace_id' => $this->workspace->id]);
        $today     = now()->toDateString();

        $campaignA = $this->createCampaignWithData($adAccount, [], 100.0, 400.0, $today);
        $this->createCampaignWithData($adAccount, [], 100.0, 150.0, $today);

        $response = $this->inertiaGet('campaigns', ['filter' => 'winners', 'classifier' => 'target']);
        $response->assertStatus(200);

        $campaigns = $response->inertiaProps('campaigns');
        $this->assertCount(1, $campaigns, 'Only winner should be returned');
        $this->assertEquals($campaignA->id, $campaigns[0]['id']);
        $this->assertEquals('winner', $campaigns[0]['wl_tag']);
        $this->assertEquals('target', $response->inertiaProps('active_classifier'));
    }

    public function test_campaigns_target_classifier_returns_loser(): void
    {
        $this->workspace->update(['target_roas' => 3.0]);

        $adAccount = AdAccount::factory()->create(['workspace_id' => $this->workspace->id]);
        $today     = now()->toDateString();

        $this->createCampaignWithData($adAccount, [], 100.0, 400.0, $today);
        $campaignB = $this->createCampaignWithData($adAccount, [], 100.0, 150.0, $today);

        $response = $this->inertiaGet('campaigns', ['filter' => 'losers', 'classifier' => 'target']);
        $response->assertStatus(200);

        $campaigns = $response->inertiaProps('campaigns');
        $this->assertCount(1, $campaigns, 'Only loser should be returned');
        $this->assertEquals($campaignB->id, $campaigns[0]['id']);
        $this->assertEquals('loser', $campaigns[0]['wl_tag']);
    }

    // ─── Campaign: peer classifier ────────────────────────────────────────────────

    /**
     * When no workspace target is set, auto-selects peer classifier.
     * Peer avg = (4.0 + 1.5) / 2 = 2.75.
     * Campaign A (4.0 >= 2.75) → winner. Campaign B (1.5 < 2.75) → loser.
     */
    public function test_campaigns_peer_classifier_uses_workspace_average(): void
    {
        // No target_roas → auto = peer
        $adAccount = AdAccount::factory()->create(['workspace_id' => $this->workspace->id]);
        $today     = now()->toDateString();

        $campaignA = $this->createCampaignWithData($adAccount, [], 100.0, 400.0, $today);
        $this->createCampaignWithData($adAccount, [], 100.0, 150.0, $today);

        $response = $this->inertiaGet('campaigns', ['filter' => 'winners']);
        $response->assertStatus(200);

        $props = $response->inertiaProps();
        $this->assertEquals('peer', $props['active_classifier'], 'Should auto-select peer when no target set');
        $this->assertCount(1, $props['campaigns']);
        $this->assertEquals($campaignA->id, $props['campaigns'][0]['id']);
        $this->assertEquals(2.75, $props['wl_peer_avg_roas']);
    }

    // ─── Campaign: period classifier ─────────────────────────────────────────────

    /**
     * Campaign A: current ROAS 4.0, previous ROAS 2.0 → improved → winner.
     * Campaign B: current ROAS 1.5, previous ROAS 3.0 → declined → loser.
     */
    public function test_campaigns_period_classifier_tags_improving_campaign_as_winner(): void
    {
        $adAccount    = AdAccount::factory()->create(['workspace_id' => $this->workspace->id]);
        $today        = now()->toDateString();
        $prevDate     = now()->subDays(45)->toDateString(); // within default prev period (-29 to -59 offset from $from)

        // Current period
        $campaignA = $this->createCampaignWithData($adAccount, [], 100.0, 400.0, $today); // roas=4.0
        $campaignB = $this->createCampaignWithData($adAccount, [], 100.0, 150.0, $today); // roas=1.5

        // Previous period: campaign A roas=2.0, campaign B roas=3.0
        DB::table('ad_insights')->insert([
            'workspace_id'                => $this->workspace->id,
            'ad_account_id'               => $adAccount->id,
            'campaign_id'                 => $campaignA->id,
            'adset_id'                    => null,
            'ad_id'                       => null,
            'level'                       => 'campaign',
            'date'                        => $prevDate,
            'hour'                        => null,
            'spend'                       => 100.0,
            'spend_in_reporting_currency' => 100.0,
            'impressions'                 => 1000,
            'clicks'                      => 50,
            'currency'                    => 'EUR',
            'created_at'                  => now(),
            'updated_at'                  => now(),
        ]);
        DB::table('ad_insights')->insert([
            'workspace_id'                => $this->workspace->id,
            'ad_account_id'               => $adAccount->id,
            'campaign_id'                 => $campaignB->id,
            'adset_id'                    => null,
            'ad_id'                       => null,
            'level'                       => 'campaign',
            'date'                        => $prevDate,
            'hour'                        => null,
            'spend'                       => 100.0,
            'spend_in_reporting_currency' => 100.0,
            'impressions'                 => 1000,
            'clicks'                      => 50,
            'currency'                    => 'EUR',
            'created_at'                  => now(),
            'updated_at'                  => now(),
        ]);

        Order::factory()->create([
            'workspace_id'                => $this->workspace->id,
            'store_id'                    => $this->store->id,
            'attribution_source'          => 'wc_native',
            'attribution_last_touch'      => [
                'source'       => 'facebook',
                'medium'       => 'cpc',
                'campaign'     => $campaignA->external_id,
                'channel'      => 'Paid — Facebook',
                'channel_type' => 'paid_social',
            ],
            'status'                      => 'completed',
            'total_in_reporting_currency' => 200.0, // prev roas = 200/100 = 2.0 (improved)
            'occurred_at'                 => now()->subDays(45)->startOfDay()->addHours(12),
        ]);
        Order::factory()->create([
            'workspace_id'                => $this->workspace->id,
            'store_id'                    => $this->store->id,
            'attribution_source'          => 'wc_native',
            'attribution_last_touch'      => [
                'source'       => 'facebook',
                'medium'       => 'cpc',
                'campaign'     => $campaignB->external_id,
                'channel'      => 'Paid — Facebook',
                'channel_type' => 'paid_social',
            ],
            'status'                      => 'completed',
            'total_in_reporting_currency' => 300.0, // prev roas = 300/100 = 3.0 (declined)
            'occurred_at'                 => now()->subDays(45)->startOfDay()->addHours(13),
        ]);

        $response = $this->inertiaGet('campaigns', ['filter' => 'winners', 'classifier' => 'period']);
        $response->assertStatus(200);

        $campaigns = $response->inertiaProps('campaigns');
        $this->assertCount(1, $campaigns, 'Only improved campaign should be winner');
        $this->assertEquals($campaignA->id, $campaigns[0]['id']);
        $this->assertEquals('winner', $campaigns[0]['wl_tag']);
    }

    // ─── Products: peer classifier ────────────────────────────────────────────────

    /**
     * Peer avg revenue = (2000 + 500) / 2 = 1250.
     * Product A (2000 >= 1250) → winner. Product B (500 < 1250) → loser.
     */
    public function test_products_peer_classifier_returns_above_average_revenue_as_winner(): void
    {
        $today = now()->toDateString();

        DB::table('daily_snapshot_products')->insert([
            [
                'workspace_id'       => $this->workspace->id,
                'store_id'           => $this->store->id,
                'snapshot_date'      => $today,
                'product_external_id' => 'prod_a',
                'product_name'       => 'Product A',
                'revenue'            => 2000.0,
                'units'              => 20,
                'rank'               => 1,
                'created_at'         => now(),
            ],
            [
                'workspace_id'       => $this->workspace->id,
                'store_id'           => $this->store->id,
                'snapshot_date'      => $today,
                'product_external_id' => 'prod_b',
                'product_name'       => 'Product B',
                'revenue'            => 500.0,
                'units'              => 5,
                'rank'               => 2,
                'created_at'         => now(),
            ],
        ]);

        $response = $this->inertiaGet('analytics/products', ['filter' => 'winners', 'classifier' => 'peer']);
        $response->assertStatus(200);

        $props = $response->inertiaProps();
        $this->assertEquals('peer', $props['active_classifier']);
        $this->assertCount(1, $props['products']);
        $this->assertEquals('prod_a', $props['products'][0]['external_id']);
        $this->assertEquals('winner', $props['products'][0]['wl_tag']);
    }

    // ─── Products: period classifier ─────────────────────────────────────────────

    /**
     * Product A: revenue 1000→2000 (+100% delta) → winner.
     * Product B: revenue 800→500 (-37.5% delta) → loser.
     */
    public function test_products_period_classifier_uses_revenue_delta(): void
    {
        $today    = now()->toDateString();
        $prevDate = now()->subDays(45)->toDateString(); // within default prev period

        DB::table('daily_snapshot_products')->insert([
            // Current period
            ['workspace_id' => $this->workspace->id, 'store_id' => $this->store->id, 'snapshot_date' => $today,    'product_external_id' => 'prod_a', 'product_name' => 'Product A', 'revenue' => 2000.0, 'units' => 20, 'rank' => 1, 'created_at' => now()],
            ['workspace_id' => $this->workspace->id, 'store_id' => $this->store->id, 'snapshot_date' => $today,    'product_external_id' => 'prod_b', 'product_name' => 'Product B', 'revenue' =>  500.0, 'units' =>  5, 'rank' => 2, 'created_at' => now()],
            // Previous period
            ['workspace_id' => $this->workspace->id, 'store_id' => $this->store->id, 'snapshot_date' => $prevDate, 'product_external_id' => 'prod_a', 'product_name' => 'Product A', 'revenue' => 1000.0, 'units' => 10, 'rank' => 1, 'created_at' => now()],
            ['workspace_id' => $this->workspace->id, 'store_id' => $this->store->id, 'snapshot_date' => $prevDate, 'product_external_id' => 'prod_b', 'product_name' => 'Product B', 'revenue' =>  800.0, 'units' =>  8, 'rank' => 2, 'created_at' => now()],
        ]);

        $response = $this->inertiaGet('analytics/products', ['filter' => 'winners', 'classifier' => 'period']);
        $response->assertStatus(200);

        $products = $response->inertiaProps('products');
        $this->assertCount(1, $products, 'Only product with positive revenue delta should be winner');
        $this->assertEquals('prod_a', $products[0]['external_id']);
        $this->assertEquals('winner', $products[0]['wl_tag']);
    }

    // ─── Stores: target classifier ────────────────────────────────────────────────

    /**
     * marketing_pct = workspace_ad_spend / store_revenue × 100 (lower = efficient = winner).
     *
     * workspace_ad_spend = 30, target_marketing_pct = 20
     * Store A revenue = 200 → mktg% = 15% < 20% target → winner
     * Store B revenue = 120 → mktg% = 25% > 20% target → loser
     */
    public function test_stores_target_classifier_returns_efficient_store_as_winner(): void
    {
        $this->workspace->update(['target_marketing_pct' => 20.0]);

        $storeB = Store::factory()->create([
            'workspace_id'             => $this->workspace->id,
            'historical_import_status' => 'completed',
        ]);

        $today = now()->toDateString();
        $this->insertWorkspaceAdSpend(30.0, $today);
        $this->insertStoreRevenue($this->store->id, 200.0, $today);
        $this->insertStoreRevenue($storeB->id, 120.0, $today);

        $response = $this->inertiaGet('stores', ['filter' => 'winners', 'classifier' => 'target']);
        $response->assertStatus(200);

        $props = $response->inertiaProps();
        $this->assertEquals('target', $props['active_classifier']);
        $this->assertCount(1, $props['stores']);
        $this->assertEquals($this->store->id, $props['stores'][0]['id']);
        $this->assertEquals('winner', $props['stores'][0]['wl_tag']);
    }

    // ─── Stores: peer classifier ──────────────────────────────────────────────────

    /**
     * Peer avg mktg% = (15 + 25) / 2 = 20%.
     * Store A (15% < 20% avg) → winner. Store B (25% >= 20% avg) → loser.
     */
    public function test_stores_peer_classifier_returns_below_average_as_winner(): void
    {
        // No target → auto-selects peer
        $storeB = Store::factory()->create([
            'workspace_id'             => $this->workspace->id,
            'historical_import_status' => 'completed',
        ]);

        $today = now()->toDateString();
        $this->insertWorkspaceAdSpend(30.0, $today);
        $this->insertStoreRevenue($this->store->id, 200.0, $today);
        $this->insertStoreRevenue($storeB->id, 120.0, $today);

        $response = $this->inertiaGet('stores', ['filter' => 'winners', 'classifier' => 'peer']);
        $response->assertStatus(200);

        $props = $response->inertiaProps();
        $this->assertEquals('peer', $props['active_classifier']);
        $this->assertCount(1, $props['stores']);
        $this->assertEquals($this->store->id, $props['stores'][0]['id']);
        $this->assertEquals('winner', $props['stores'][0]['wl_tag']);
    }

    // ─── Stores: period classifier ────────────────────────────────────────────────

    /**
     * Store A: current mktg%=15%, prev mktg%=20% → improved (15 < 20) → winner.
     * Store B: current mktg%=25%, prev mktg%=18% → declined (25 > 18) → loser.
     *
     * Prev period = subDays(30) to subDays(59) from today.
     */
    public function test_stores_period_classifier_returns_improved_store_as_winner(): void
    {
        $storeB = Store::factory()->create([
            'workspace_id'             => $this->workspace->id,
            'historical_import_status' => 'completed',
        ]);

        $today    = now()->toDateString();
        $prevDate = now()->subDays(45)->toDateString(); // within prev window (-30 to -59)

        // Current period: A=15%, B=25%
        $this->insertWorkspaceAdSpend(30.0, $today);
        $this->insertStoreRevenue($this->store->id, 200.0, $today); // 30/200*100=15%
        $this->insertStoreRevenue($storeB->id, 120.0, $today);       // 30/120*100=25%

        // Previous period: same total spend, different revenues
        // Store A prev: 30/150*100=20%; Store B prev: 30/166.67*100≈18%
        $this->insertWorkspaceAdSpend(30.0, $prevDate);
        $this->insertStoreRevenue($this->store->id, 150.0, $prevDate);
        $this->insertStoreRevenue($storeB->id, 166.67, $prevDate);

        $response = $this->inertiaGet('stores', ['filter' => 'winners', 'classifier' => 'period']);
        $response->assertStatus(200);

        $props = $response->inertiaProps();
        $this->assertEquals('period', $props['active_classifier']);
        $this->assertCount(1, $props['stores']);
        $this->assertEquals($this->store->id, $props['stores'][0]['id']);
        $this->assertEquals('winner', $props['stores'][0]['wl_tag']);
    }
}
