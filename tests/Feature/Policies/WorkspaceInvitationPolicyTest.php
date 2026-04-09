<?php

declare(strict_types=1);

namespace Tests\Feature\Policies;

use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceInvitation;
use App\Models\WorkspaceUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkspaceInvitationPolicyTest extends TestCase
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

    public function test_owner_can_create_invitation(): void
    {
        [$owner, $workspace] = $this->makeWorkspaceWithUser('owner');
        $this->assertTrue($owner->can('create', [WorkspaceInvitation::class, $workspace]));
    }

    public function test_admin_can_create_invitation(): void
    {
        [$admin, $workspace] = $this->makeWorkspaceWithUser('admin');
        $this->assertTrue($admin->can('create', [WorkspaceInvitation::class, $workspace]));
    }

    public function test_member_cannot_create_invitation(): void
    {
        [$member, $workspace] = $this->makeWorkspaceWithUser('member');
        $this->assertFalse($member->can('create', [WorkspaceInvitation::class, $workspace]));
    }

    public function test_owner_can_revoke_invitation(): void
    {
        [$owner, $workspace] = $this->makeWorkspaceWithUser('owner');
        $this->assertTrue($owner->can('revoke', [WorkspaceInvitation::class, $workspace]));
    }

    public function test_admin_can_revoke_invitation(): void
    {
        [$admin, $workspace] = $this->makeWorkspaceWithUser('admin');
        $this->assertTrue($admin->can('revoke', [WorkspaceInvitation::class, $workspace]));
    }

    public function test_member_cannot_revoke_invitation(): void
    {
        [$member, $workspace] = $this->makeWorkspaceWithUser('member');
        $this->assertFalse($member->can('revoke', [WorkspaceInvitation::class, $workspace]));
    }

    public function test_super_admin_bypasses_all_checks(): void
    {
        $superAdmin = User::factory()->create(['is_super_admin' => true]);
        $workspace  = Workspace::factory()->create();

        $this->assertTrue($superAdmin->can('create', [WorkspaceInvitation::class, $workspace]));
        $this->assertTrue($superAdmin->can('revoke', [WorkspaceInvitation::class, $workspace]));
    }
}
