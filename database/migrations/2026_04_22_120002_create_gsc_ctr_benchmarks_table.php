<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Static peer-bucket CTR benchmarks used by Organic destination.
 *
 * Seeded by GscCtrBenchmarksSeeder from published GSC averages. Global
 * (no workspace_id) — same values for every workspace in Phase 3.
 * Future phases may add a workspace override layer if a customer's vertical
 * diverges meaningfully from the defaults.
 *
 * Used by:
 *   - §F15 "Needs attention" rule (CTR drop vs bucket benchmark)
 *   - §F17 Opportunity badge "Leaking" (position top 5 with underperforming CTR)
 *
 * @see PROGRESS.md §M5 Peer-bucket CTR benchmarks
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gsc_ctr_benchmarks', function (Blueprint $table): void {
            // Bucket labels: "1", "2", "3", "4-5", "6-10", "11-20", "21+"
            $table->string('position_bucket', 16)->primary();
            $table->decimal('expected_ctr', 6, 4);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gsc_ctr_benchmarks');
    }
};
