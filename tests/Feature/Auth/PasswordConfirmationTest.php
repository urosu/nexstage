<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PasswordConfirmationTest extends TestCase
{
    use RefreshDatabase;

    private function makeUserWithWorkspace(): User
    {
        $user      = User::factory()->create();
        $workspace = Workspace::factory()->create(['owner_id' => $user->id]);
        WorkspaceUser::factory()->owner()->create(['user_id' => $user->id, 'workspace_id' => $workspace->id]);

        return $user;
    }

    public function test_confirm_password_screen_can_be_rendered(): void
    {
        $user = $this->makeUserWithWorkspace();

        $response = $this->actingAs($user)->get('/confirm-password');

        $response->assertStatus(200);
    }

    public function test_password_can_be_confirmed(): void
    {
        $user = $this->makeUserWithWorkspace();

        $response = $this->actingAs($user)->post('/confirm-password', [
            'password' => 'password',
        ]);

        $response->assertRedirect();
        $response->assertSessionHasNoErrors();
    }

    public function test_password_is_not_confirmed_with_invalid_password(): void
    {
        $user = $this->makeUserWithWorkspace();

        $response = $this->actingAs($user)->post('/confirm-password', [
            'password' => 'wrong-password',
        ]);

        $response->assertSessionHasErrors();
    }
}
