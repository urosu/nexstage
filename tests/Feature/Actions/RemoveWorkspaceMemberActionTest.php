<?php

declare(strict_types=1);

namespace Tests\Feature\Actions;

use App\Actions\RemoveWorkspaceMemberAction;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RemoveWorkspaceMemberActionTest extends TestCase
{
    use RefreshDatabase;

    private RemoveWorkspaceMemberAction $action;

    protected function setUp(): void
    {
        parent::setUp();
        $this->action = app(RemoveWorkspaceMemberAction::class);
    }

    public function test_removes_member_successfully(): void
    {
        $workspace = Workspace::factory()->create();
        $user      = User::factory()->create();
        $wu        = WorkspaceUser::factory()->member()->create([
            'workspace_id' => $workspace->id,
            'user_id'      => $user->id,
        ]);

        $this->action->handle($wu);

        $this->assertDatabaseMissing('workspace_users', ['id' => $wu->id]);
    }

    public function test_removes_admin_successfully(): void
    {
        $workspace = Workspace::factory()->create();
        $user      = User::factory()->create();
        $wu        = WorkspaceUser::factory()->admin()->create([
            'workspace_id' => $workspace->id,
            'user_id'      => $user->id,
        ]);

        $this->action->handle($wu);

        $this->assertDatabaseMissing('workspace_users', ['id' => $wu->id]);
    }

    public function test_cannot_remove_owner(): void
    {
        $workspace = Workspace::factory()->create();
        $user      = User::factory()->create();
        $wu        = WorkspaceUser::factory()->owner()->create([
            'workspace_id' => $workspace->id,
            'user_id'      => $user->id,
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Transfer ownership first');

        $this->action->handle($wu);
    }
}
