<?php

declare(strict_types=1);

namespace Tests\Feature\Controllers;

use App\Models\Store;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Feature tests for Phase 1.5 Step 2 — StoreCountryPrompt.
 *
 * Covers POST /onboarding/country (OnboardingController::saveCountry):
 *   - Saves a valid 2-letter country code to stores.primary_country_code
 *   - Writing null/empty explicitly sets NULL (skip path)
 *   - Unauthenticated requests are rejected
 *   - User cannot save country for a store in another workspace
 */
class StoreCountryPromptTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Workspace $workspace;
    private Store $store;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user      = User::factory()->create();
        $this->workspace = Workspace::factory()->create();
        $this->store     = Store::factory()->create(['workspace_id' => $this->workspace->id]);

        WorkspaceUser::factory()->owner()->create([
            'user_id'      => $this->user->id,
            'workspace_id' => $this->workspace->id,
        ]);

        // Set active workspace in session (required by SetActiveWorkspace middleware).
        session(['active_workspace_id' => $this->workspace->id]);
    }

    public function test_saves_valid_country_code_to_store(): void
    {
        $this->actingAs($this->user)
            ->post(route('onboarding.country'), [
                'store_id'     => $this->store->id,
                'country_code' => 'DE',
            ])
            ->assertRedirect(route('onboarding'));

        $this->assertDatabaseHas('stores', [
            'id'                   => $this->store->id,
            'primary_country_code' => 'DE',
        ]);
    }

    public function test_uppercases_country_code_before_saving(): void
    {
        $this->actingAs($this->user)
            ->post(route('onboarding.country'), [
                'store_id'     => $this->store->id,
                'country_code' => 'nl',
            ])
            ->assertRedirect(route('onboarding'));

        $this->assertDatabaseHas('stores', [
            'id'                   => $this->store->id,
            'primary_country_code' => 'NL',
        ]);
    }

    public function test_skip_writes_null_explicitly(): void
    {
        // Pre-seed a non-null value to prove it gets cleared.
        $this->store->update(['primary_country_code' => 'FR']);

        $this->actingAs($this->user)
            ->post(route('onboarding.country'), [
                'store_id'     => $this->store->id,
                'country_code' => '',     // skip = empty string
            ])
            ->assertRedirect(route('onboarding'));

        $this->assertDatabaseHas('stores', [
            'id'                   => $this->store->id,
            'primary_country_code' => null,
        ]);
    }

    public function test_null_country_code_writes_null(): void
    {
        $this->actingAs($this->user)
            ->post(route('onboarding.country'), [
                'store_id' => $this->store->id,
                // country_code absent
            ])
            ->assertRedirect(route('onboarding'));

        $this->assertDatabaseHas('stores', [
            'id'                   => $this->store->id,
            'primary_country_code' => null,
        ]);
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $this->post(route('onboarding.country'), [
            'store_id'     => $this->store->id,
            'country_code' => 'DE',
        ])->assertRedirect(route('login'));
    }

    public function test_cannot_save_country_for_store_in_another_workspace(): void
    {
        $otherWorkspace = Workspace::factory()->create();
        $otherStore     = Store::factory()->create(['workspace_id' => $otherWorkspace->id]);

        $this->actingAs($this->user)
            ->post(route('onboarding.country'), [
                'store_id'     => $otherStore->id,
                'country_code' => 'DE',
            ])
            ->assertStatus(404);

        // Store in other workspace must remain untouched.
        $this->assertDatabaseHas('stores', [
            'id'                   => $otherStore->id,
            'primary_country_code' => null,
        ]);
    }
}
