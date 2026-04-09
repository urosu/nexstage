<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ad_insights', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained('workspaces')->cascadeOnDelete();
            $table->foreignId('ad_account_id')->nullable()->constrained('ad_accounts')->nullOnDelete();
            $table->string('level', 20);
            $table->foreignId('campaign_id')->nullable()->constrained('campaigns')->nullOnDelete();
            $table->foreignId('adset_id')->nullable()->constrained('adsets')->nullOnDelete();
            $table->foreignId('ad_id')->nullable()->constrained('ads')->nullOnDelete();
            $table->date('date');
            $table->smallInteger('hour')->nullable();
            $table->decimal('spend', 12, 4)->default(0);
            $table->decimal('spend_in_reporting_currency', 12, 4)->nullable();
            $table->bigInteger('impressions')->default(0);
            $table->bigInteger('clicks')->default(0);
            $table->bigInteger('reach')->nullable();
            $table->decimal('ctr', 8, 6)->nullable();
            $table->decimal('cpc', 10, 4)->nullable();
            $table->decimal('platform_roas', 10, 4)->nullable();
            $table->char('currency', 3);
            $table->timestamps();

            $table->index(['workspace_id', 'ad_account_id', 'date']);
            $table->index(['workspace_id', 'campaign_id', 'date']);
            $table->index(['workspace_id', 'ad_id', 'date']);
            $table->index(['workspace_id', 'date']);
        });

        DB::statement("ALTER TABLE ad_insights ADD CONSTRAINT ad_insights_level_check CHECK (level IN ('campaign','ad'))");

        DB::statement("CREATE UNIQUE INDEX ai_campaign_daily_unique  ON ad_insights (campaign_id, date)       WHERE level='campaign' AND hour IS NULL");
        DB::statement("CREATE UNIQUE INDEX ai_campaign_hourly_unique ON ad_insights (campaign_id, date, hour) WHERE level='campaign' AND hour IS NOT NULL");
        DB::statement("CREATE UNIQUE INDEX ai_ad_daily_unique        ON ad_insights (ad_id, date)             WHERE level='ad' AND hour IS NULL");
        DB::statement("CREATE UNIQUE INDEX ai_ad_hourly_unique       ON ad_insights (ad_id, date, hour)       WHERE level='ad' AND hour IS NOT NULL");
    }

    public function down(): void
    {
        Schema::dropIfExists('ad_insights');
    }
};
