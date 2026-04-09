<?php

declare(strict_types=1);

namespace Tests\Feature\Actions;

use App\Actions\AcceptWorkspaceInvitationAction;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceInvitation;
use App\Models\WorkspaceUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AcceptWorkspaceInvitationActionTest extends TestCase
{
    use RefreshDatabase;

    private AcceptWorkspaceInvitationAction $action;

    protected function setUp(): void
    {
        parent::setUp();
        $this->action = app(AcceptWorkspaceInvitationAction::class);
    }

    public function test_creates_workspace_user_on_valid_invitation(): void
    {
        $workspace  = Workspace::factory()->create();
        $user       = User::factory()->create(['email' => 'invited@example.com']);
        $invitation = WorkspaceInvitation::factory()->create([
            'workspace_id' => $workspace->id,
            'email'        => 'invited@example.com',
            'role'         => 'member',
        ]);

        $this->action->handle($invitation, $user);

        $this->assertDatabaseHas('workspace_users', [
            'workspace_id' => $workspace->id,
            'user_id'      => $user->id,
            'role'         => 'member',
        ]);
    }

    public function test_sets_accepted_at_timestamp(): void
    {
        $workspace  = Workspace::factory()->create();
        $user       = User::factory()->create(['email' => 'ts@example.com']);
        $invitation = WorkspaceInvitation::factory()->create([
            'workspace_id' => $workspace->id,
            'email'        => 'ts@example.com',
        ]);

        $this->action->handle($invitation, $user);

        $this->assertNotNull($invitation->fresh()->accepted_at);
    }

    public function test_fails_if_invitation_expired(): void
    {
        $workspace  = Workspace::factory()->create();
        $user       = User::factory()->create(['email' => 'exp@example.com']);
        $invitation = WorkspaceInvitation::factory()->expired()->create([
            'workspace_id' => $workspace->id,
            'email'        => 'exp@example.com',
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('expired or has already been accepted');

        $this->action->handle($invitation, $user);
    }

    public function test_fails_if_already_accepted(): void
    {
        $workspace  = Workspace::factory()->create();
        $user       = User::factory()->create(['email' => 'acc@example.com']);
        $invitation = WorkspaceInvitation::factory()->accepted()->create([
            'workspace_id' => $workspace->id,
            'email'        => 'acc@example.com',
        ]);

        $this->expectException(\RuntimeException::class);

        $this->action->handle($invitation, $user);
    }

    public function test_fails_if_email_mismatch(): void
    {
        $workspace  = Workspace::factory()->create();
        $user       = User::factory()->create(['email' => 'different@example.com']);
        $invitation = WorkspaceInvitation::factory()->create([
            'workspace_id' => $workspace->id,
            'email'        => 'original@example.com',
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('different email address');

        $this->action->handle($invitation, $user);
    }

    public function test_idempotent_if_already_member(): void
    {
        $workspace  = Workspace::factory()->create();
        $user       = User::factory()->create(['email' => 'member@example.com']);
        $invitation = WorkspaceInvitation::factory()->create([
            'workspace_id' => $workspace->id,
            'email'        => 'member@example.com',
            'role'         => 'member',
        ]);

        // Already a member
        WorkspaceUser::create([
            'workspace_id' => $workspace->id,
            'user_id'      => $user->id,
            'role'         => 'member',
        ]);

        $this->action->handle($invitation, $user);

        // Should not duplicate
        $this->assertDatabaseCount('workspace_users', 1);
        $this->assertNotNull($invitation->fresh()->accepted_at);
    }
}
