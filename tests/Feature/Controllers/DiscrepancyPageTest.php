<?php

declare(strict_types=1);

namespace Tests\Feature\Controllers;

use App\Models\Store;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Verifies the Phase 3.8 redirect: GET /{workspace}/analytics/discrepancy → /acquisition?tab=platform-vs-real.
 *
 * Content tests for the discrepancy table now live in AcquisitionPageTest (Tab 2 "Platform vs Real").
 */
class DiscrepancyPageTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Workspace $workspace;

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
    }

    public function test_route_redirects_to_acquisition_platform_vs_real_tab(): void
    {
        $this->actingAs($this->user)
            ->get("/{$this->workspace->slug}/analytics/discrepancy")
            ->assertRedirect("/{$this->workspace->slug}/acquisition?tab=platform-vs-real")
            ->assertStatus(301);
    }

    public function test_non_member_cannot_access(): void
    {
        $otherUser = User::factory()->create();

        $this->actingAs($otherUser)
            ->get("/{$this->workspace->slug}/analytics/discrepancy")
            ->assertRedirect();
    }
}
