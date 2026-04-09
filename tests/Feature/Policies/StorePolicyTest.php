<?php

declare(strict_types=1);

namespace Tests\Feature\Policies;

use App\Models\Store;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceUser;
use App\Services\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StorePolicyTest extends TestCase
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

    public function test_owner_can_connect_store(): void
    {
        [$owner, $workspace] = $this->makeWorkspaceWithUser('owner');
        $this->assertTrue($owner->can('create', [Store::class, $workspace]));
    }

    public function test_admin_can_connect_store(): void
    {
        [$admin, $workspace] = $this->makeWorkspaceWithUser('admin');
        $this->assertTrue($admin->can('create', [Store::class, $workspace]));
    }

    public function test_member_cannot_connect_store(): void
    {
        [$member, $workspace] = $this->makeWorkspaceWithUser('member');
        $this->assertFalse($member->can('create', [Store::class, $workspace]));
    }

    public function test_any_member_can_view_store(): void
    {
        [$owner, $workspace] = $this->makeWorkspaceWithUser('owner');
        app(WorkspaceContext::class)->set($workspace->id);
        $store = Store::factory()->create(['workspace_id' => $workspace->id]);

        $this->assertTrue($owner->can('view', $store));

        // Admin
        $admin = User::factory()->create();
        WorkspaceUser::factory()->admin()->create(['user_id' => $admin->id, 'workspace_id' => $workspace->id]);
        $this->assertTrue($admin->can('view', $store));

        // Member
        $member = User::factory()->create();
        WorkspaceUser::factory()->member()->create(['user_id' => $member->id, 'workspace_id' => $workspace->id]);
        $this->assertTrue($member->can('view', $store));
    }

    public function test_non_member_cannot_view_store(): void
    {
        [$owner, $workspace] = $this->makeWorkspaceWithUser('owner');
        app(WorkspaceContext::class)->set($workspace->id);
        $store   = Store::factory()->create(['workspace_id' => $workspace->id]);
        $stranger = User::factory()->create();

        $this->assertFalse($stranger->can('view', $store));
    }

    public function test_owner_can_disconnect_store(): void
    {
        [$owner, $workspace] = $this->makeWorkspaceWithUser('owner');
        app(WorkspaceContext::class)->set($workspace->id);
        $store = Store::factory()->create(['workspace_id' => $workspace->id]);

        $this->assertTrue($owner->can('delete', $store));
    }

    public function test_member_cannot_disconnect_store(): void
    {
        $owner = User::factory()->create();
        $workspace = Workspace::factory()->create(['owner_id' => $owner->id]);
        WorkspaceUser::factory()->owner()->create(['user_id' => $owner->id, 'workspace_id' => $workspace->id]);
        app(WorkspaceContext::class)->set($workspace->id);
        $store = Store::factory()->create(['workspace_id' => $workspace->id]);

        $member = User::factory()->create();
        WorkspaceUser::factory()->member()->create(['user_id' => $member->id, 'workspace_id' => $workspace->id]);

        $this->assertFalse($member->can('delete', $store));
    }
}
