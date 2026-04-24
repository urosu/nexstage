<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Seeds static peer-bucket CTR benchmarks.
 *
 * Values from published GSC industry averages. Used by:
 *   - §F15 "Needs attention" rule
 *   - §F17 Opportunity badge "Leaking"
 *
 * Safe to re-run: truncates then inserts.
 *
 * @see PROGRESS.md §M5 Peer-bucket CTR benchmarks
 */
class GscCtrBenchmarksSeeder extends Seeder
{
    public function run(): void
    {
        DB::table('gsc_ctr_benchmarks')->truncate();

        $now = now()->toDateTimeString();

        DB::table('gsc_ctr_benchmarks')->insert([
            ['position_bucket' => '1',     'expected_ctr' => 0.2700, 'created_at' => $now, 'updated_at' => $now],
            ['position_bucket' => '2',     'expected_ctr' => 0.1500, 'created_at' => $now, 'updated_at' => $now],
            ['position_bucket' => '3',     'expected_ctr' => 0.1100, 'created_at' => $now, 'updated_at' => $now],
            ['position_bucket' => '4-5',   'expected_ctr' => 0.0750, 'created_at' => $now, 'updated_at' => $now],
            ['position_bucket' => '6-10',  'expected_ctr' => 0.0350, 'created_at' => $now, 'updated_at' => $now],
            ['position_bucket' => '11-20', 'expected_ctr' => 0.0120, 'created_at' => $now, 'updated_at' => $now],
            ['position_bucket' => '21+',   'expected_ctr' => 0.0050, 'created_at' => $now, 'updated_at' => $now],
        ]);
    }
}
