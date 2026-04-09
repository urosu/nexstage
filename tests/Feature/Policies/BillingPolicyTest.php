<?php

declare(strict_types=1);

namespace Tests\Feature\Policies;

use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceUser;
use App\Policies\BillingPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BillingPolicyTest extends TestCase
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

    public function test_owner_can_view_billing(): void
    {
        [$owner, $workspace] = $this->makeWorkspaceWithUser('owner');
        $policy = new BillingPolicy();
        $this->assertTrue($policy->view($owner, $workspace));
    }

    public function test_admin_cannot_view_billing(): void
    {
        [$admin, $workspace] = $this->makeWorkspaceWithUser('admin');
        $policy = new BillingPolicy();
        $this->assertFalse($policy->view($admin, $workspace));
    }

    public function test_member_cannot_view_billing(): void
    {
        [$member, $workspace] = $this->makeWorkspaceWithUser('member');
        $policy = new BillingPolicy();
        $this->assertFalse($policy->view($member, $workspace));
    }

    public function test_owner_can_manage_billing(): void
    {
        [$owner, $workspace] = $this->makeWorkspaceWithUser('owner');
        $policy = new BillingPolicy();
        $this->assertTrue($policy->manage($owner, $workspace));
    }

    public function test_admin_cannot_manage_billing(): void
    {
        [$admin, $workspace] = $this->makeWorkspaceWithUser('admin');
        $policy = new BillingPolicy();
        $this->assertFalse($policy->manage($admin, $workspace));
    }

    public function test_member_cannot_manage_billing(): void
    {
        [$member, $workspace] = $this->makeWorkspaceWithUser('member');
        $policy = new BillingPolicy();
        $this->assertFalse($policy->manage($member, $workspace));
    }
}
