<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Workspace;
use App\Models\WorkspaceInvitation;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<WorkspaceInvitation>
 */
class WorkspaceInvitationFactory extends Factory
{
    protected $model = WorkspaceInvitation::class;

    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'email'        => $this->faker->unique()->safeEmail(),
            'role'         => 'member',
            'token'        => Str::random(64),
            'expires_at'   => now()->addDays(7),
            'accepted_at'  => null,
        ];
    }

    public function expired(): static
    {
        return $this->state(fn (array $attributes) => [
            'expires_at'  => now()->subHour(),
            'accepted_at' => null,
        ]);
    }

    public function accepted(): static
    {
        return $this->state(fn (array $attributes) => [
            'accepted_at' => now()->subHour(),
            'expires_at'  => now()->subHour(),
        ]);
    }
}
