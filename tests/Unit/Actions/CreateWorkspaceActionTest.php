<?php

declare(strict_types=1);

namespace Tests\Unit\Actions;

use App\Actions\CreateWorkspaceAction;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CreateWorkspaceActionTest extends TestCase
{
    use RefreshDatabase;

    private CreateWorkspaceAction $action;

    protected function setUp(): void
    {
        parent::setUp();
        $this->action = app(CreateWorkspaceAction::class);
    }

    public function test_creates_workspace_with_correct_defaults(): void
    {
        $user = User::factory()->create();

        $workspace = $this->action->handle($user, 'mystore.com');

        $this->assertSame('EUR', $workspace->reporting_currency);
        $this->assertSame('Europe/Berlin', $workspace->reporting_timezone);
        $this->assertSame('mystore.com', $workspace->name);
    }

    public function test_sets_trial_ends_at_14_days(): void
    {
        $user      = User::factory()->create();
        $workspace = $this->action->handle($user, 'trial-store.com');

        $this->assertNotNull($workspace->trial_ends_at);
        $this->assertTrue($workspace->trial_ends_at->between(
            now()->addDays(13),
            now()->addDays(15)
        ));
    }

    public function test_creates_owner_workspace_user(): void
    {
        $user      = User::factory()->create();
        $workspace = $this->action->handle($user, 'owner-test.com');

        $this->assertDatabaseHas('workspace_users', [
            'workspace_id' => $workspace->id,
            'user_id'      => $user->id,
            'role'         => 'owner',
        ]);
    }

    public function test_generates_unique_slug(): void
    {
        $user      = User::factory()->create();
        $workspace = $this->action->handle($user, 'mystore.com');

        // Slugs are random 32-char hex IDs (Cloudflare-style) — intentionally not
        // name-derived to avoid leaking customer info across workspaces.
        // @see CreateWorkspaceAction::generateUniqueSlug()
        $this->assertNotEmpty($workspace->slug);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $workspace->slug);
    }

    public function test_slug_collision_appends_random_suffix(): void
    {
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();

        $ws1 = $this->action->handle($user1, 'same.com');
        $ws2 = $this->action->handle($user2, 'same.com');

        $this->assertDatabaseCount('workspaces', 2);
        $this->assertNotSame($ws1->slug, $ws2->slug);
    }

    public function test_workspace_and_user_created_atomically(): void
    {
        $user = User::factory()->create();
        $this->action->handle($user, 'atomic-test.com');

        $this->assertDatabaseCount('workspaces', 1);
        $this->assertDatabaseCount('workspace_users', 1);
    }
}
