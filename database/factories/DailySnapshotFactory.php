<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\DailySnapshot;
use App\Models\Store;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DailySnapshot>
 */
class DailySnapshotFactory extends Factory
{
    protected $model = DailySnapshot::class;

    public function definition(): array
    {
        return [
            'workspace_id'       => Workspace::factory(),
            'store_id'           => Store::factory(),
            'date'               => today(),
            'orders_count'       => 10,
            'revenue'            => 1000.00,
            'revenue_native'     => 1000.00,
            'aov'                => 100.00,
            'items_sold'         => 15,
            'items_per_order'    => 1.50,
            'new_customers'      => 8,
            'returning_customers' => 2,
        ];
    }
}
