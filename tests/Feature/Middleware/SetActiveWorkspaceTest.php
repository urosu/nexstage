<?php

declare(strict_types=1);

namespace Tests\Feature\Middleware;

use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceUser;
use App\Services\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SetActiveWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    private function makeUserWithWorkspace(): array
    {
        $user      = User::factory()->create();
        $workspace = Workspace::factory()->create(['owner_id' => $user->id]);

        WorkspaceUser::factory()->owner()->create([
            'user_id'      => $user->id,
            'workspace_id' => $workspace->id,
        ]);

        return [$user, $workspace];
    }

    public function test_sets_workspace_from_session(): void
    {
        [$user, $workspace] = $this->makeUserWithWorkspace();

        $response = $this->actingAs($user)
            ->withSession(['active_workspace_id' => $workspace->id])
            ->get('/dashboard');

        $this->assertStringNotContainsString('/onboarding', $response->headers->get('Location', ''));
    }

    public function test_falls_back_to_oldest_workspace_when_no_session(): void
    {
        $user = User::factory()->create();

        // Oldest workspace
        $ws1 = Workspace::factory()->create(['owner_id' => $user->id]);
        WorkspaceUser::factory()->owner()->create(['user_id' => $user->id, 'workspace_id' => $ws1->id]);

        // Newer workspace
        $ws2 = Workspace::factory()->create(['owner_id' => $user->id]);
        WorkspaceUser::factory()->owner()->create(['user_id' => $user->id, 'workspace_id' => $ws2->id]);

        $response = $this->actingAs($user)->get('/dashboard');

        // Should NOT redirect to /onboarding (middleware found a workspace)
        $this->assertNotSame('/onboarding', $response->headers->get('Location'));
    }

    public function test_redirects_to_no_workspace_when_user_has_none(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/dashboard');

        $response->assertRedirect('/onboarding');
    }

    public function test_login_route_not_redirected(): void
    {
        $user = User::factory()->create();

        // Login page is in the skip list — should not redirect to /onboarding
        $response = $this->actingAs($user)->get('/login');

        $this->assertNotSame('/onboarding', $response->headers->get('Location'));
    }

    public function test_register_route_not_redirected(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/register');

        $this->assertNotSame('/onboarding', $response->headers->get('Location'));
    }

    public function test_no_workspace_route_not_redirected(): void
    {
        $user = User::factory()->create();

        // /onboarding itself should not redirect back to /onboarding (infinite loop)
        $response = $this->actingAs($user)->get('/onboarding');

        $this->assertNotSame('/onboarding', $response->headers->get('Location'));
    }

    public function test_excludes_soft_deleted_workspaces(): void
    {
        $user      = User::factory()->create();
        $workspace = Workspace::factory()->create(['owner_id' => $user->id]);
        WorkspaceUser::factory()->owner()->create(['user_id' => $user->id, 'workspace_id' => $workspace->id]);

        // Soft-delete the workspace
        \Illuminate\Support\Facades\DB::table('workspaces')
            ->where('id', $workspace->id)
            ->update(['deleted_at' => now()]);

        $response = $this->actingAs($user)->get('/dashboard');

        $response->assertRedirect('/onboarding');
    }

    public function test_session_workspace_ignored_if_not_member(): void
    {
        [$user, $ws1] = $this->makeUserWithWorkspace();

        // A different workspace the user has no membership in
        $otherWorkspace = Workspace::factory()->create();

        // Session points to a workspace the user doesn't belong to
        $response = $this->actingAs($user)
            ->withSession(['active_workspace_id' => $otherWorkspace->id])
            ->get('/dashboard');

        // Falls back to ws1 — does not redirect to /onboarding
        $this->assertNotSame('/onboarding', $response->headers->get('Location'));

        $ctx = app(WorkspaceContext::class)->id();
        $this->assertSame($ws1->id, $ctx);
    }
}
