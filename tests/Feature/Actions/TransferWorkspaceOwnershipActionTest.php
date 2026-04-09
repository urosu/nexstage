<?php

declare(strict_types=1);

namespace Tests\Feature\Actions;

use App\Actions\TransferWorkspaceOwnershipAction;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TransferWorkspaceOwnershipActionTest extends TestCase
{
    use RefreshDatabase;

    private TransferWorkspaceOwnershipAction $action;

    protected function setUp(): void
    {
        parent::setUp();
        $this->action = app(TransferWorkspaceOwnershipAction::class);
    }

    private function setupWorkspace(): array
    {
        $owner    = User::factory()->create();
        $newOwner = User::factory()->create();

        $workspace = Workspace::factory()->create(['owner_id' => $owner->id]);

        WorkspaceUser::factory()->owner()->create([
            'user_id'      => $owner->id,
            'workspace_id' => $workspace->id,
        ]);
        WorkspaceUser::factory()->member()->create([
            'user_id'      => $newOwner->id,
            'workspace_id' => $workspace->id,
        ]);

        return [$workspace, $owner, $newOwner];
    }

    public function test_transfers_ownership_to_existing_member(): void
    {
        [$workspace, $owner, $newOwner] = $this->setupWorkspace();

        $this->action->handle($workspace, $newOwner);

        $this->assertDatabaseHas('workspace_users', [
            'workspace_id' => $workspace->id,
            'user_id'      => $newOwner->id,
            'role'         => 'owner',
        ]);
    }

    public function test_demotes_previous_owner_to_admin(): void
    {
        [$workspace, $owner, $newOwner] = $this->setupWorkspace();

        $this->action->handle($workspace, $newOwner);

        $this->assertDatabaseHas('workspace_users', [
            'workspace_id' => $workspace->id,
            'user_id'      => $owner->id,
            'role'         => 'admin',
        ]);
    }

    public function test_updates_workspace_owner_id(): void
    {
        [$workspace, $owner, $newOwner] = $this->setupWorkspace();

        $this->action->handle($workspace, $newOwner);

        $this->assertSame($newOwner->id, $workspace->fresh()->owner_id);
    }

    public function test_fails_if_new_owner_not_member(): void
    {
        [$workspace, $owner] = $this->setupWorkspace();
        $stranger = User::factory()->create();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('not a member');

        $this->action->handle($workspace, $stranger);
    }

    public function test_fails_if_same_user(): void
    {
        [$workspace, $owner] = $this->setupWorkspace();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('already the workspace owner');

        $this->action->handle($workspace, $owner);
    }
}
