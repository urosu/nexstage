<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\AdAccount;
use App\Models\Campaign;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Campaign>
 */
class CampaignFactory extends Factory
{
    protected $model = Campaign::class;

    public function definition(): array
    {
        return [
            'workspace_id'  => Workspace::factory(),
            'ad_account_id' => AdAccount::factory(),
            'external_id'   => 'campaign_' . $this->faker->unique()->numerify('#######'),
            'name'          => $this->faker->catchPhrase(),
            'status'        => 'ACTIVE',
            'objective'     => null,
        ];
    }
}
