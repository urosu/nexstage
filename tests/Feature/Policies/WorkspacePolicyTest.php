<?php

declare(strict_types=1);

namespace Tests\Feature\Policies;

use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkspacePolicyTest extends TestCase
{
    use RefreshDatabase;

    private function makeWorkspaceWithUser(string $role): array
    {
        $user      = User::factory()->create();
        $workspace = Workspace::factory()->create(['owner_id' => $user->id]);

        WorkspaceUser::factory()->create([
            'workspace_id' => $workspace->id,
            'user_id'      => $user->id,
            'role'         => $role,
        ]);

        return [$user, $workspace];
    }

    // ─── delete ───────────────────────────────────────────────────────────────

    public function test_owner_can_delete_workspace(): void
    {
        [$owner, $workspace] = $this->makeWorkspaceWithUser('owner');
        $this->assertTrue($owner->can('delete', $workspace));
    }

    public function test_admin_cannot_delete_workspace(): void
    {
        [$admin, $workspace] = $this->makeWorkspaceWithUser('admin');
        $this->assertFalse($admin->can('delete', $workspace));
    }

    public function test_member_cannot_delete_workspace(): void
    {
        [$member, $workspace] = $this->makeWorkspaceWithUser('member');
        $this->assertFalse($member->can('delete', $workspace));
    }

    // ─── transferOwnership ────────────────────────────────────────────────────

    public function test_owner_can_transfer_ownership(): void
    {
        [$owner, $workspace] = $this->makeWorkspaceWithUser('owner');
        $this->assertTrue($owner->can('transferOwnership', $workspace));
    }

    public function test_admin_cannot_transfer_ownership(): void
    {
        [$admin, $workspace] = $this->makeWorkspaceWithUser('admin');
        $this->assertFalse($admin->can('transferOwnership', $workspace));
    }

    // ─── viewBilling / manageBilling ──────────────────────────────────────────

    public function test_owner_can_view_billing(): void
    {
        [$owner, $workspace] = $this->makeWorkspaceWithUser('owner');
        $this->assertTrue($owner->can('viewBilling', $workspace));
    }

    public function test_admin_cannot_view_billing(): void
    {
        [$admin, $workspace] = $this->makeWorkspaceWithUser('admin');
        $this->assertFalse($admin->can('viewBilling', $workspace));
    }

    public function test_member_cannot_view_billing(): void
    {
        [$member, $workspace] = $this->makeWorkspaceWithUser('member');
        $this->assertFalse($member->can('viewBilling', $workspace));
    }

    // ─── update ───────────────────────────────────────────────────────────────

    public function test_owner_can_update_workspace(): void
    {
        [$owner, $workspace] = $this->makeWorkspaceWithUser('owner');
        $this->assertTrue($owner->can('update', $workspace));
    }

    public function test_admin_can_update_workspace(): void
    {
        [$admin, $workspace] = $this->makeWorkspaceWithUser('admin');
        $this->assertTrue($admin->can('update', $workspace));
    }

    public function test_member_cannot_update_workspace(): void
    {
        [$member, $workspace] = $this->makeWorkspaceWithUser('member');
        $this->assertFalse($member->can('update', $workspace));
    }

    // ─── super admin ──────────────────────────────────────────────────────────

    public function test_super_admin_bypasses_all_checks(): void
    {
        $superAdmin = User::factory()->create(['is_super_admin' => true]);
        $workspace  = Workspace::factory()->create();

        $this->assertTrue($superAdmin->can('delete', $workspace));
        $this->assertTrue($superAdmin->can('transferOwnership', $workspace));
        $this->assertTrue($superAdmin->can('viewBilling', $workspace));
        $this->assertTrue($superAdmin->can('update', $workspace));
    }

    // ─── non-member ───────────────────────────────────────────────────────────

    public function test_non_member_has_no_access(): void
    {
        $stranger  = User::factory()->create();
        $workspace = Workspace::factory()->create();

        $this->assertFalse($stranger->can('delete', $workspace));
        $this->assertFalse($stranger->can('update', $workspace));
        $this->assertFalse($stranger->can('transferOwnership', $workspace));
        $this->assertFalse($stranger->can('viewBilling', $workspace));
    }
}
