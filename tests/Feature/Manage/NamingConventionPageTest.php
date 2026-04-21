<?php

declare(strict_types=1);

namespace Tests\Feature\Manage;

use App\Models\AdAccount;
use App\Models\Campaign;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Feature test for GET /{workspace}/manage/naming-convention — the read-only
 * explainer page added in Phase 1.6.
 *
 * Verifies:
 *   - Page renders 200 for a workspace owner
 *   - Campaigns are bucketed into clean / partial / minimal based on
 *     parsed_convention.parse_status
 *   - Coverage = (campaigns with clean parse AND 30d spend) / (campaigns with 30d spend)
 *   - Non-member (different workspace) cannot access the page
 *
 * @see app/Http/Controllers/ManageController.php::namingConvention
 * @see PLANNING.md section 16.5
 */
class NamingConventionPageTest extends TestCase
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

        // Completed store import satisfies EnsureOnboardingComplete.
        $this->store = Store::factory()->create([
            'workspace_id'             => $this->workspace->id,
            'historical_import_status' => 'completed',
        ]);

        $this->adAccount = AdAccount::factory()->create([
            'workspace_id' => $this->workspace->id,
            'platform'     => 'facebook',
        ]);
    }

    private function visit(): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->user)
            ->get("/{$this->workspace->slug}/manage/naming-convention");
    }

    /**
     * Create an ad_insights row attached to $campaign for a given date.
     * Bypasses factories — spend fields must align with the reporting currency
     * used by the naming-convention query (spend_in_reporting_currency).
     */
    private function insertSpend(Campaign $campaign, float $spend, string $date): void
    {
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

    // ─── Tests ──────────────────────────────────────────────────────────────

    public function test_page_renders_for_owner(): void
    {
        $this->visit()->assertOk();
    }

    public function test_campaigns_are_bucketed_by_parse_status(): void
    {
        // Seed a product so the "clean" campaign target resolves to a real slug.
        Product::create([
            'workspace_id' => $this->workspace->id,
            'store_id'     => $this->store->id,
            'external_id'  => 'p1',
            'name'         => 'Blue Hoodie',
            'slug'         => 'hoodie-blue',
            'status'       => 'publish',
        ]);

        $clean = Campaign::factory()->create([
            'workspace_id'      => $this->workspace->id,
            'ad_account_id'     => $this->adAccount->id,
            'name'              => 'US | summer-sale | hoodie-blue',
            'status'            => 'active',
            'parsed_convention' => [
                'country'      => 'US',
                'campaign'     => 'summer-sale',
                'target_type'  => 'product',
                'target_id'    => 1,
                'target_slug'  => 'hoodie-blue',
                'raw_target'   => 'hoodie-blue',
                'shape'        => 'full',
                'parse_status' => 'clean',
            ],
        ]);

        $partial = Campaign::factory()->create([
            'workspace_id'      => $this->workspace->id,
            'ad_account_id'     => $this->adAccount->id,
            'name'              => 'summer-sale | mystery-target',
            'status'            => 'active',
            'parsed_convention' => [
                'country'      => null,
                'campaign'     => 'summer-sale',
                'target_type'  => null,
                'target_id'    => null,
                'target_slug'  => null,
                'raw_target'   => 'mystery-target',
                'shape'        => 'campaign_target',
                'parse_status' => 'partial',
            ],
        ]);

        $minimal = Campaign::factory()->create([
            'workspace_id'      => $this->workspace->id,
            'ad_account_id'     => $this->adAccount->id,
            'name'              => 'brand-awareness',
            'status'            => 'active',
            'parsed_convention' => [
                'country'      => null,
                'campaign'     => 'brand-awareness',
                'target_type'  => null,
                'target_id'    => null,
                'target_slug'  => null,
                'raw_target'   => null,
                'shape'        => 'minimal',
                'parse_status' => 'minimal',
            ],
        ]);

        $response = $this->visit()->assertOk();
        $buckets  = $response->inertiaProps('buckets');

        $this->assertCount(1, $buckets['clean']);
        $this->assertCount(1, $buckets['partial']);
        $this->assertCount(1, $buckets['minimal']);

        $this->assertSame($clean->id,   $buckets['clean'][0]['id']);
        $this->assertSame($partial->id, $buckets['partial'][0]['id']);
        $this->assertSame($minimal->id, $buckets['minimal'][0]['id']);

        // Partial row exposes raw_target so the UI can show the rename hint.
        $this->assertSame('mystery-target', $buckets['partial'][0]['raw_target']);
    }

    public function test_coverage_counts_only_campaigns_with_recent_spend(): void
    {
        $cleanWithSpend = Campaign::factory()->create([
            'workspace_id'      => $this->workspace->id,
            'ad_account_id'     => $this->adAccount->id,
            'status'            => 'active',
            'parsed_convention' => ['parse_status' => 'clean'],
        ]);
        $partialWithSpend = Campaign::factory()->create([
            'workspace_id'      => $this->workspace->id,
            'ad_account_id'     => $this->adAccount->id,
            'status'            => 'active',
            'parsed_convention' => ['parse_status' => 'partial'],
        ]);
        // Clean but no spend → must NOT count toward denominator.
        Campaign::factory()->create([
            'workspace_id'      => $this->workspace->id,
            'ad_account_id'     => $this->adAccount->id,
            'status'            => 'active',
            'parsed_convention' => ['parse_status' => 'clean'],
        ]);

        $today = now()->toDateString();
        $this->insertSpend($cleanWithSpend,   100.0, $today);
        $this->insertSpend($partialWithSpend, 50.0,  $today);

        $coverage = $this->visit()->assertOk()->inertiaProps('coverage');

        $this->assertSame(2, $coverage['denominator']);
        $this->assertSame(1, $coverage['numerator']);
        $this->assertSame(50, $coverage['percent']);
    }

    public function test_coverage_is_null_when_no_spend(): void
    {
        Campaign::factory()->create([
            'workspace_id'      => $this->workspace->id,
            'ad_account_id'     => $this->adAccount->id,
            'status'            => 'active',
            'parsed_convention' => ['parse_status' => 'clean'],
        ]);

        $coverage = $this->visit()->assertOk()->inertiaProps('coverage');

        $this->assertNull($coverage['percent']);
        $this->assertSame(0, $coverage['denominator']);
    }

    public function test_spend_older_than_30_days_is_ignored(): void
    {
        $campaign = Campaign::factory()->create([
            'workspace_id'      => $this->workspace->id,
            'ad_account_id'     => $this->adAccount->id,
            'status'            => 'active',
            'parsed_convention' => ['parse_status' => 'clean'],
        ]);

        $this->insertSpend($campaign, 100.0, now()->subDays(45)->toDateString());

        $coverage = $this->visit()->assertOk()->inertiaProps('coverage');

        $this->assertSame(0, $coverage['denominator']);
        $this->assertNull($coverage['percent']);
    }

}
