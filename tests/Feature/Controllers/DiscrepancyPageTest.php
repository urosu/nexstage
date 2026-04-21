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
 * Feature test for GET /{workspace}/analytics/discrepancy — Platform vs Real
 * revenue investigation tool added in Phase 1.6.
 *
 * Verifies:
 *   - Page renders 200 for workspace member
 *   - Required Inertia props are present
 *   - Delta calculation: platform_conversions_value with no attributed orders → full delta
 *   - Non-member is redirected
 *
 * @see app/Http/Controllers/DiscrepancyController.php
 * @see PLANNING.md section 12.5
 */
class DiscrepancyPageTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Workspace $workspace;
    private Store $store;
    private AdAccount $adAccount;

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

        $this->adAccount = AdAccount::factory()->create([
            'workspace_id' => $this->workspace->id,
            'platform'     => 'facebook',
        ]);
    }

    private function visit(array $params = []): \Illuminate\Testing\TestResponse
    {
        $query = $params ? '?' . http_build_query($params) : '';

        return $this->actingAs($this->user)
            ->get("/{$this->workspace->slug}/analytics/discrepancy{$query}");
    }

    // ── Tests ────────────────────────────────────────────────────────────────

    public function test_page_renders_for_workspace_member(): void
    {
        $this->visit()->assertOk();
    }

    public function test_required_inertia_props_are_present(): void
    {
        $response = $this->visit()->assertOk();

        $this->assertArrayHasKey('campaigns',   $response->inertiaProps());
        $this->assertArrayHasKey('hero',        $response->inertiaProps());
        $this->assertArrayHasKey('chart_data',  $response->inertiaProps());
        $this->assertArrayHasKey('from',        $response->inertiaProps());
        $this->assertArrayHasKey('to',          $response->inertiaProps());
        $this->assertArrayHasKey('platform',    $response->inertiaProps());
    }

    public function test_campaign_with_platform_revenue_and_no_store_orders_shows_delta(): void
    {
        $campaign = Campaign::factory()->create([
            'workspace_id' => $this->workspace->id,
            'ad_account_id' => $this->adAccount->id,
            'name'          => 'Test Campaign',
            'status'        => 'active',
        ]);

        // Insert ad_insights row with platform revenue, no matching store orders
        $date = now()->subDays(5)->toDateString();
        DB::table('ad_insights')->insert([
            'workspace_id'                => $this->workspace->id,
            'ad_account_id'               => $this->adAccount->id,
            'campaign_id'                 => $campaign->id,
            'adset_id'                    => null,
            'ad_id'                       => null,
            'level'                       => 'campaign',
            'date'                        => $date,
            'hour'                        => null,
            'spend'                       => 100.00,
            'spend_in_reporting_currency' => 100.00,
            'impressions'                 => 2000,
            'clicks'                      => 100,
            'platform_conversions_value'  => 500.00,
            'currency'                    => 'EUR',
            'created_at'                  => now(),
            'updated_at'                  => now(),
        ]);

        $response  = $this->visit()->assertOk();
        $campaigns = $response->inertiaProps('campaigns');

        $this->assertNotEmpty($campaigns);

        // Platform revenue reported = 500, attributed store orders = 0 → delta is non-zero
        $row = collect($campaigns)->firstWhere('campaign_id', $campaign->id);
        $this->assertNotNull($row);
        $this->assertSame(500.00, (float) $row['platform_revenue']);
        $this->assertNotSame(0.0, (float) $row['delta']);
    }

    public function test_hero_shows_aggregate_roas_metrics(): void
    {
        $response = $this->visit()->assertOk();
        $hero     = $response->inertiaProps('hero');

        $this->assertArrayHasKey('total_spend',             $hero);
        $this->assertArrayHasKey('total_platform_revenue',  $hero);
        $this->assertArrayHasKey('total_attributed_revenue', $hero);
        $this->assertArrayHasKey('platform_roas',           $hero);
        $this->assertArrayHasKey('real_roas',               $hero);
    }

    public function test_non_member_is_redirected(): void
    {
        $otherUser = User::factory()->create();

        $this->actingAs($otherUser)
            ->get("/{$this->workspace->slug}/analytics/discrepancy")
            ->assertRedirect();
    }
}
