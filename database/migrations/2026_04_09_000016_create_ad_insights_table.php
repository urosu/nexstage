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

            // CTR, CPC, CPA computed on the fly with NULLIF — never stored as columns.
            // See: PLANNING.md "Key patterns — CPM/CPC/CPA"

            $table->decimal('platform_roas', 10, 4)->nullable();
            $table->char('currency', 3);

            // Facebook: average times a person saw the ad in the reporting period.
            $table->decimal('frequency', 5, 2)->nullable();

            // Platform-reported conversions (may differ from actual orders due to attribution windows).
            // Used for attribution break detection. See: PLANNING.md "correlateSignals()"
            $table->decimal('platform_conversions', 12, 2)->nullable();
            $table->decimal('platform_conversions_value', 14, 4)->nullable();

            // Google Ads: fraction of eligible impressions actually received (0.0-1.0).
            $table->decimal('search_impression_share', 5, 4)->nullable();

            // Facebook actions array, social_spend, placement breakdowns.
            // See: PLANNING.md "Data Capture Strategy — What to JSONB"
            $table->jsonb('raw_insights')->nullable();
            $table->string('raw_insights_api_version', 20)->nullable();

            $table->timestamps();

            $table->index(['workspace_id', 'ad_account_id', 'date']);
            $table->index(['workspace_id', 'campaign_id', 'date']);
            $table->index(['workspace_id', 'ad_id', 'date']);
            $table->index(['workspace_id', 'date']);
        });

        // level/FK integrity: campaign-level rows must have campaign_id set and ad_id NULL,
        // ad-level rows must have ad_id set and campaign_id NULL.
        // Why: prevents double-counting spend when querying. Never SUM across both levels.
        // Note: if adset-level insights are added later (Phase 3+), this CHECK must be extended.
        DB::statement("ALTER TABLE ad_insights ADD CONSTRAINT ad_insights_level_check CHECK (level IN ('campaign','adset','ad'))");
        DB::statement("ALTER TABLE ad_insights ADD CONSTRAINT ad_insights_level_fk_check CHECK (
            (level = 'campaign' AND campaign_id IS NOT NULL AND ad_id IS NULL)
            OR
            (level = 'adset' AND adset_id IS NOT NULL AND campaign_id IS NULL AND ad_id IS NULL)
            OR
            (level = 'ad' AND ad_id IS NOT NULL AND campaign_id IS NULL)
        )");

        // Partial unique indexes per level to enforce one row per (entity, date[, hour]).
        DB::statement("CREATE UNIQUE INDEX ai_campaign_daily_unique  ON ad_insights (campaign_id, date)       WHERE level='campaign' AND hour IS NULL");
        DB::statement("CREATE UNIQUE INDEX ai_campaign_hourly_unique ON ad_insights (campaign_id, date, hour) WHERE level='campaign' AND hour IS NOT NULL");
        DB::statement("CREATE UNIQUE INDEX ai_adset_daily_unique     ON ad_insights (adset_id, date)          WHERE level='adset' AND hour IS NULL");
        DB::statement("CREATE UNIQUE INDEX ai_ad_daily_unique        ON ad_insights (ad_id, date)             WHERE level='ad' AND hour IS NULL");
        DB::statement("CREATE UNIQUE INDEX ai_ad_hourly_unique       ON ad_insights (ad_id, date, hour)       WHERE level='ad' AND hour IS NOT NULL");

        // Composite index for adset controller queries
        DB::statement("CREATE INDEX ad_insights_workspace_id_adset_id_date_index ON ad_insights (workspace_id, adset_id, date)");
    }

    public function down(): void
    {
        Schema::dropIfExists('ad_insights');
    }
};
