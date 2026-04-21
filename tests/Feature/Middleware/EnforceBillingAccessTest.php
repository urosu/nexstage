<?php

declare(strict_types=1);

namespace Tests\Feature\Middleware;

use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EnforceBillingAccessTest extends TestCase
{
    use RefreshDatabase;

    private function makeUserWithWorkspace(array $workspaceAttributes = []): array
    {
        $user = User::factory()->create();

        $workspace = Workspace::factory()->create(array_merge(
            ['owner_id' => $user->id],
            $workspaceAttributes,
        ));

        WorkspaceUser::factory()->owner()->create([
            'user_id'      => $user->id,
            'workspace_id' => $workspace->id,
        ]);

        return [$user, $workspace];
    }

    public function test_redirects_to_billing_when_trial_expired_and_no_plan(): void
    {
        [$user] = $this->makeUserWithWorkspace([
            'trial_ends_at' => now()->subDay(),
            'billing_plan'  => null,
        ]);

        // Use a real session-based route (not workspace-prefixed) so SetActiveWorkspace
        // can resolve the workspace context before the billing middleware checks it.
        $response = $this->actingAs($user)->get('/profile');

        $response->assertRedirect('/settings/billing');
    }

    public function test_allows_access_during_active_trial(): void
    {
        [$user] = $this->makeUserWithWorkspace([
            'trial_ends_at' => now()->addDays(7),
            'billing_plan'  => null,
        ]);

        $response = $this->actingAs($user)->get('/profile');

        $this->assertStringNotContainsString('/settings/billing', $response->headers->get('Location', ''));
    }

    public function test_allows_access_with_active_plan(): void
    {
        [$user] = $this->makeUserWithWorkspace([
            'trial_ends_at' => now()->subDays(5),
            'billing_plan'  => 'starter',
        ]);

        $response = $this->actingAs($user)->get('/profile');

        $this->assertStringNotContainsString('/settings/billing', $response->headers->get('Location', ''));
    }

    public function test_billing_page_exempt_from_redirect(): void
    {
        [$user] = $this->makeUserWithWorkspace([
            'trial_ends_at' => now()->subDay(),
            'billing_plan'  => null,
        ]);

        $response = $this->actingAs($user)->get('/settings/billing');

        $this->assertStringNotContainsString('/settings/billing', $response->headers->get('Location', ''));
    }

    public function test_profile_page_exempt_from_redirect(): void
    {
        [$user] = $this->makeUserWithWorkspace([
            'trial_ends_at' => now()->subDay(),
            'billing_plan'  => null,
        ]);

        $response = $this->actingAs($user)->get('/settings/profile');

        $this->assertStringNotContainsString('/settings/billing', $response->headers->get('Location', ''));
    }

    public function test_consolidated_billing_uses_parent_plan(): void
    {
        // Parent workspace with an active plan
        $ownerUser = User::factory()->create();
        $parentWs  = Workspace::factory()->create([
            'owner_id'      => $ownerUser->id,
            'trial_ends_at' => now()->subDays(30),
            'billing_plan'  => 'starter',
        ]);

        // Child workspace with expired trial and no plan of its own
        $childUser = User::factory()->create();
        $childWs   = Workspace::factory()->create([
            'owner_id'             => $childUser->id,
            'trial_ends_at'        => now()->subDay(),
            'billing_plan'         => null,
            'billing_workspace_id' => $parentWs->id,
        ]);
        WorkspaceUser::factory()->owner()->create([
            'user_id'      => $childUser->id,
            'workspace_id' => $childWs->id,
        ]);

        // Request as child user — should NOT redirect to billing
        $response = $this->actingAs($childUser)
            ->withSession(['active_workspace_id' => $childWs->id])
            ->get('/dashboard');

        $this->assertStringNotContainsString('/settings/billing', $response->headers->get('Location', ''));
    }
}
