<?php

declare(strict_types=1);

namespace Tests\Feature\Actions;

use App\Actions\InviteWorkspaceMemberAction;
use App\Mail\WorkspaceInvitationMail;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceInvitation;
use App\Models\WorkspaceUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class InviteWorkspaceMemberActionTest extends TestCase
{
    use RefreshDatabase;

    private InviteWorkspaceMemberAction $action;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->action = app(InviteWorkspaceMemberAction::class);
    }

    public function test_creates_invitation_for_new_email(): void
    {
        $workspace = Workspace::factory()->create();

        $this->action->handle($workspace, 'new@example.com', 'member');

        $this->assertDatabaseHas('workspace_invitations', [
            'workspace_id' => $workspace->id,
            'email'        => 'new@example.com',
            'role'         => 'member',
        ]);
    }

    public function test_sends_invitation_email(): void
    {
        $workspace = Workspace::factory()->create();

        $this->action->handle($workspace, 'invite@example.com', 'member');

        Mail::assertQueued(WorkspaceInvitationMail::class, function ($mail) {
            return $mail->hasTo('invite@example.com');
        });
    }

    public function test_refreshes_invitation_for_existing_pending(): void
    {
        $workspace = Workspace::factory()->create();

        // Create an existing (expired) invitation for the same email
        $original = WorkspaceInvitation::factory()->expired()->create([
            'workspace_id' => $workspace->id,
            'email'        => 'refresh@example.com',
            'role'         => 'member',
        ]);

        $this->action->handle($workspace, 'refresh@example.com', 'member');

        // Should only have one invitation row (upserted, not duplicated)
        $this->assertDatabaseCount('workspace_invitations', 1);

        // Expiry should now be in the future
        $fresh = WorkspaceInvitation::first();
        $this->assertTrue($fresh->expires_at->isFuture());
    }

    public function test_fails_if_user_already_member(): void
    {
        $user      = User::factory()->create(['email' => 'member@example.com']);
        $workspace = Workspace::factory()->create();
        WorkspaceUser::factory()->member()->create([
            'user_id'      => $user->id,
            'workspace_id' => $workspace->id,
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('already a member');

        $this->action->handle($workspace, 'member@example.com', 'member');
    }
}
