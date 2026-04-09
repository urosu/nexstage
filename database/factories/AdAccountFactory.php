<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\AdAccount;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AdAccount>
 */
class AdAccountFactory extends Factory
{
    protected $model = AdAccount::class;

    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'platform'     => 'facebook',
            'external_id'  => 'act_' . $this->faker->unique()->numerify('#########'),
            'name'         => $this->faker->company() . ' Ads',
            'currency'     => 'EUR',
            'status'       => 'active',
        ];
    }
}
