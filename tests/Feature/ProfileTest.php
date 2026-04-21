<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProfileTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: User, 1: Workspace}
     */
    private function makeUserWithWorkspace(): array
    {
        $user = User::factory()->create();
        // has_ads=true passes EnsureOnboardingComplete (ads-only onboarding path)
        // so profile routes (gated by 'onboarded' middleware) are accessible.
        $workspace = Workspace::factory()->create([
            'owner_id' => $user->id,
            'has_ads'  => true,
        ]);
        WorkspaceUser::factory()->owner()->create(['user_id' => $user->id, 'workspace_id' => $workspace->id]);

        return [$user, $workspace];
    }

    public function test_profile_page_is_displayed(): void
    {
        [$user, $workspace] = $this->makeUserWithWorkspace();

        $response = $this
            ->actingAs($user)
            ->get("/{$workspace->slug}/settings/profile");

        $response->assertOk();
    }

    public function test_profile_information_can_be_updated(): void
    {
        [$user] = $this->makeUserWithWorkspace();

        $response = $this
            ->actingAs($user)
            ->from('/profile')
            ->patch('/profile', [
                'name' => 'Test User',
                'email' => 'test@example.com',
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect('/profile');

        $user->refresh();

        $this->assertSame('Test User', $user->name);
        $this->assertSame('test@example.com', $user->email);
        $this->assertNull($user->email_verified_at);
    }

    public function test_email_verification_status_is_unchanged_when_the_email_address_is_unchanged(): void
    {
        [$user] = $this->makeUserWithWorkspace();

        $response = $this
            ->actingAs($user)
            ->from('/profile')
            ->patch('/profile', [
                'name' => 'Test User',
                'email' => $user->email,
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect('/profile');

        $this->assertNotNull($user->refresh()->email_verified_at);
    }

    public function test_user_can_delete_their_account(): void
    {
        [$user] = $this->makeUserWithWorkspace();

        $response = $this
            ->actingAs($user)
            ->delete('/profile', [
                'password' => 'password',
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect('/');

        $this->assertGuest();
        $this->assertNull($user->fresh());
    }

    public function test_correct_password_must_be_provided_to_delete_account(): void
    {
        [$user] = $this->makeUserWithWorkspace();

        $response = $this
            ->actingAs($user)
            ->from('/profile')
            ->delete('/profile', [
                'password' => 'wrong-password',
            ]);

        $response
            ->assertSessionHasErrors('password')
            ->assertRedirect('/profile');

        $this->assertNotNull($user->fresh());
    }
}
