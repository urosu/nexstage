<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds timeout_seconds to sync_logs so the orphaned-log sweeper can use each
 * job's declared timeout as the cutoff rather than a hardcoded constant.
 *
 * Nullable because historical rows predate this column. The sweeper skips rows
 * where timeout_seconds IS NULL (they are safe to leave until manually reviewed).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sync_logs', function (Blueprint $table): void {
            $table->unsignedSmallInteger('timeout_seconds')->nullable()->after('attempt');
        });
    }

    public function down(): void
    {
        Schema::table('sync_logs', function (Blueprint $table): void {
            $table->dropColumn('timeout_seconds');
        });
    }
};
