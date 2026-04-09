<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Workspace>
 */
class WorkspaceFactory extends Factory
{
    protected $model = Workspace::class;

    public function definition(): array
    {
        $name = $this->faker->unique()->company();

        return [
            'name'                => $name,
            'slug'                => Str::slug($name) . '-' . Str::lower(Str::random(4)),
            'owner_id'            => User::factory(),
            'reporting_currency'  => 'EUR',
            'reporting_timezone'  => 'Europe/Berlin',
            'trial_ends_at'       => now()->addDays(14),
            'billing_plan'        => null,
            'is_orphaned'         => false,
        ];
    }

    public function expired_trial(): static
    {
        return $this->state(fn (array $attributes) => [
            'trial_ends_at' => now()->subDay(),
            'billing_plan'  => null,
        ]);
    }

    public function with_plan(string $plan): static
    {
        return $this->state(fn (array $attributes) => [
            'billing_plan'  => $plan,
            'trial_ends_at' => now()->subMonth(),
        ]);
    }

    public function soft_deleted(): static
    {
        return $this->state(fn (array $attributes) => [
            'deleted_at' => now()->subDays(5),
        ]);
    }
}
