<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\AdAccount;
use App\Models\AdInsight;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AdInsight>
 */
class AdInsightFactory extends Factory
{
    protected $model = AdInsight::class;

    public function definition(): array
    {
        return [
            'workspace_id'  => Workspace::factory(),
            'ad_account_id' => AdAccount::factory(),
            'level'         => 'campaign',
            'campaign_id'   => null,
            'adset_id'      => null,
            'ad_id'         => null,
            'date'          => today(),
            'hour'          => null,
            'spend'         => 50.00,
            'impressions'   => 1000,
            'clicks'        => 50,
            'currency'      => 'EUR',
        ];
    }
}
