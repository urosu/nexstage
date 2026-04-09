<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\FxRate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FxRate>
 *
 * Note: FxRate has UPDATED_AT = null — updated_at is never written.
 */
class FxRateFactory extends Factory
{
    protected $model = FxRate::class;

    public function definition(): array
    {
        return [
            'base_currency'   => 'EUR',
            'target_currency' => 'USD',
            'rate'            => 1.08,
            'date'            => today(),
        ];
    }
}
