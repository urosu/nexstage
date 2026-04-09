<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class FxRateSeeder extends Seeder
{
    // Approximate rates vs EUR
    private const RATES = [
        'USD' => 1.08,
        'GBP' => 0.86,
        'CHF' => 0.97,
        'PLN' => 4.28,
        'CZK' => 25.2,
        'HUF' => 390.0,
    ];

    public function run(): void
    {
        $rows = [];
        $now  = now();

        for ($i = 0; $i <= 120; $i++) {
            $date = now()->subDays($i)->toDateString();

            foreach (self::RATES as $currency => $baseRate) {
                // Add tiny daily variance (±0.5%)
                $variance = 1 + (mt_rand(-50, 50) / 10000);
                $rows[] = [
                    'base_currency'   => 'EUR',
                    'target_currency' => $currency,
                    'rate'            => round($baseRate * $variance, 8),
                    'date'            => $date,
                    'created_at'      => $now,
                ];
            }
        }

        foreach (array_chunk($rows, 200) as $chunk) {
            DB::table('fx_rates')->insertOrIgnore($chunk);
        }
    }
}
