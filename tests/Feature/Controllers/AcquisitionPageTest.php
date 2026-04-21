<?php

declare(strict_types=1);

namespace Tests\Feature\Controllers;

use App\Models\Store;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Feature test for GET /{workspace}/acquisition — flagship Phase 1.6 channel attribution page.
 *
 * Verifies:
 *   - Page renders 200 for an authenticated workspace member
 *   - Required Inertia props are present
 *   - Seeded orders with attribution_last_touch produce channel rows
 *   - Non-members are redirected
 *
 * @see app/Http/Controllers/AcquisitionController.php
 * @see PLANNING.md section 12.5
 */
class AcquisitionPageTest extends TestCase
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
            'discount'                    => 0.00,
            'total_in_reporting_currency' => 150.00,
            'occurred_at'                 => now()->subDays(3),
            'synced_at'                   => now(),
            'created_at'                  => now(),
            'updated_at'                  => now(),
        ], $overrides));

        return (int) DB::table('orders')->latest('id')->value('id');
    }

    // ── Tests ────────────────────────────────────────────────────────────────

    public function test_page_renders_for_workspace_member(): void
    {
        $this->visit()->assertOk();
    }

    public function test_required_inertia_props_are_present(): void
    {
        $response = $this->visit()->assertOk();

        $this->assertArrayHasKey('channels',              $response->inertiaProps());
        $this->assertArrayHasKey('hero',                  $response->inertiaProps());
        $this->assertArrayHasKey('has_cogs',              $response->inertiaProps());
        $this->assertArrayHasKey('other_tagged_detail',   $response->inertiaProps());
        $this->assertArrayHasKey('chart_data',            $response->inertiaProps());
    }

    public function test_order_with_attribution_produces_channel_row(): void
    {
        $this->insertOrder([
            'attribution_last_touch' => json_encode([
                'source'       => 'facebook',
                'medium'       => 'cpc',
                'channel'      => 'Facebook',
                'channel_type' => 'paid_social',
            ]),
        ]);

        $channels = $this->visit()->assertOk()->inertiaProps('channels');

        $channelTypes = array_column($channels, 'channel_type');
        $this->assertContains('paid_social', $channelTypes);
    }

    public function test_hero_reflects_total_orders_count(): void
    {
        $this->insertOrder([
            'attribution_last_touch' => json_encode([
                'source'       => 'google',
                'medium'       => 'cpc',
                'channel'      => 'Google Ads',
                'channel_type' => 'paid_search',
            ]),
        ]);

        $hero = $this->visit()->assertOk()->inertiaProps('hero');

        $this->assertGreaterThanOrEqual(1, $hero['total_orders']);
    }

    public function test_non_member_is_redirected(): void
    {
        $otherUser = User::factory()->create();

        $this->actingAs($otherUser)
            ->get("/{$this->workspace->slug}/acquisition")
            ->assertRedirect();
    }
}
