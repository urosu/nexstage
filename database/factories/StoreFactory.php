<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Store;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Crypt;

/**
 * @extends Factory<Store>
 */
class StoreFactory extends Factory
{
    protected $model = Store::class;

    public function definition(): array
    {
        return [
            'workspace_id'             => Workspace::factory(),
            'name'                     => $this->faker->company(),
            'slug'                     => $this->faker->unique()->slug(),
            'domain'                   => $this->faker->unique()->domainName(),
            'type'                     => 'woocommerce',
            'currency'                 => 'EUR',
            'timezone'                 => 'Europe/Berlin',
            'status'                   => 'active',
            'consecutive_sync_failures' => 0,
            'auth_key_encrypted'       => Crypt::encryptString('ck_test_key'),
            'auth_secret_encrypted'    => Crypt::encryptString('cs_test_secret'),
            'webhook_secret_encrypted' => Crypt::encryptString('wh_test_secret'),
        ];
    }

    public function error(): static
    {
        return $this->state(fn (array $attributes) => [
            'status'                    => 'error',
            'consecutive_sync_failures' => 3,
        ]);
    }

    public function disconnected(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'disconnected',
        ]);
    }

    public function connecting(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'connecting',
        ]);
    }
}
