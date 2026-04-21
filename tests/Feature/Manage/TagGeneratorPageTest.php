<?php

declare(strict_types=1);

namespace Tests\Feature\Manage;

use App\Models\AdAccount;
use App\Models\Campaign;
use App\Models\Store;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Feature test for GET /{workspace}/manage/tag-generator — UTM tag + campaign name
 * generator extended in Phase 1.6 with a right-panel name builder.
 *
 * Verifies:
 *   - Page renders 200 for a workspace member
 *   - `campaigns` Inertia prop is present and contains active/paused campaigns
 *
 * @see app/Http/Controllers/ManageController::tagGenerator
 * @see PLANNING.md section 16.6 (Tag Generator extension)
 */
class TagGeneratorPageTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Workspace $workspace;
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

        Store::factory()->create([
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
            ->get("/{$this->workspace->slug}/manage/tag-generator");
    }

    // ── Tests ────────────────────────────────────────────────────────────────

    public function test_page_renders_for_workspace_member(): void
    {
        $this->visit()->assertOk();
    }

    public function test_campaigns_prop_is_present(): void
    {
        $response = $this->visit()->assertOk();

        $this->assertArrayHasKey('campaigns', $response->inertiaProps());
        $this->assertIsArray($response->inertiaProps('campaigns'));
    }

    public function test_active_campaign_appears_in_campaigns_prop(): void
    {
        Campaign::factory()->create([
            'workspace_id'  => $this->workspace->id,
            'ad_account_id' => $this->adAccount->id,
            'name'          => 'Summer Sale Campaign',
            'status'        => 'active',
        ]);

        $campaigns = $this->visit()->assertOk()->inertiaProps('campaigns');

        $names = array_column($campaigns, 'name');
        $this->assertContains('Summer Sale Campaign', $names);
    }

    public function test_campaign_row_has_required_fields(): void
    {
        Campaign::factory()->create([
            'workspace_id'  => $this->workspace->id,
            'ad_account_id' => $this->adAccount->id,
            'name'          => 'Test Campaign',
            'status'        => 'active',
        ]);

        $campaigns = $this->visit()->assertOk()->inertiaProps('campaigns');

        $this->assertNotEmpty($campaigns);
        $first = $campaigns[0];
        $this->assertArrayHasKey('id',       $first);
        $this->assertArrayHasKey('name',     $first);
        $this->assertArrayHasKey('platform', $first);
    }
}
