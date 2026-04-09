<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Order;
use App\Models\Store;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Order>
 */
class OrderFactory extends Factory
{
    protected $model = Order::class;

    public function definition(): array
    {
        $workspace = Workspace::factory()->create();
        $store     = Store::factory()->create(['workspace_id' => $workspace->id]);

        return [
            'workspace_id'                => $workspace->id,
            'store_id'                    => $store->id,
            'external_id'                 => (string) $this->faker->unique()->numberBetween(1000, 999999),
            'external_number'             => (string) $this->faker->numberBetween(100, 9999),
            'status'                      => 'completed',
            'currency'                    => 'EUR',
            'total'                       => 100.00,
            'subtotal'                    => 90.00,
            'tax'                         => 10.00,
            'shipping'                    => 5.00,
            'discount'                    => 0.00,
            'total_in_reporting_currency' => 100.00,
            'customer_email_hash'         => hash('sha256', $this->faker->unique()->safeEmail()),
            'customer_country'            => 'DE',
            'occurred_at'                 => now(),
            'synced_at'                   => now(),
        ];
    }
}
