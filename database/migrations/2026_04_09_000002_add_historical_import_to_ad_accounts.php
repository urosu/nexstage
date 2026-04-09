<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ad_accounts', function (Blueprint $table) {
            $table->string('historical_import_status', 50)->nullable()->after('consecutive_sync_failures');
            $table->date('historical_import_from')->nullable()->after('historical_import_status');
            $table->jsonb('historical_import_checkpoint')->nullable()->after('historical_import_from');
            $table->smallInteger('historical_import_progress')->nullable()->after('historical_import_checkpoint');
            $table->timestamp('historical_import_started_at')->nullable()->after('historical_import_progress');
            $table->timestamp('historical_import_completed_at')->nullable()->after('historical_import_started_at');
            $table->integer('historical_import_duration_seconds')->nullable()->after('historical_import_completed_at');
        });

        DB::statement("ALTER TABLE ad_accounts ADD CONSTRAINT ad_accounts_historical_import_status_check CHECK (historical_import_status IN ('pending','running','completed','failed'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE ad_accounts DROP CONSTRAINT IF EXISTS ad_accounts_historical_import_status_check');

        Schema::table('ad_accounts', function (Blueprint $table) {
            $table->dropColumn([
                'historical_import_status',
                'historical_import_from',
                'historical_import_checkpoint',
                'historical_import_progress',
                'historical_import_started_at',
                'historical_import_completed_at',
                'historical_import_duration_seconds',
            ]);
        });
    }
};
