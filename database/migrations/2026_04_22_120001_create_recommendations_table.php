<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Recommendations surfaced on Home "Today's Attention" list, Acquisition
 * opportunities sidebar, and Inbox.
 *
 * Produced by nightly jobs (Phase 4.3 agents) and page controllers that detect
 * patterns across integrations. Each row is a single prescriptive suggestion with
 * a templated title/body, optional impact estimate, and a target URL the user
 * can click to act.
 *
 * Status lifecycle:
 *   open → done (user marked complete)
 *        → snoozed (hidden until snoozed_until)
 *        → dismissed (permanently hidden)
 *
 * data JSONB holds recommendation-type-specific payload consumed by templating
 * (e.g. {"channel_from":"facebook","channel_to":"google","daily_delta":200}).
 *
 * @see PROGRESS.md §Destination specs — Home/Acquisition/Inbox
 * @see PROGRESS.md §F-series formulas for impact_estimate computations
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recommendations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained('workspaces')->cascadeOnDelete();

            // Type identifies the recommendation generator (one row per type per condition).
            // Known types (Phase 3): organic_to_paid, paid_to_organic, channel_reallocation,
            // stock_aware_campaign, striking_distance, site_health_revenue_risk,
            // unprofitable_product, cohort_channel_quality.
            $table->string('type', 64);

            // Lower = higher priority. 0 reserved for critical. Default 100.
            $table->smallInteger('priority')->default(100);

            $table->string('title', 255);
            $table->text('body');

            // Monetary impact estimate displayed on the card ("~€340/mo upside").
            // NULL when no estimate is computable. Always shown as a range in UI (±30%).
            $table->decimal('impact_estimate', 14, 4)->nullable();
            $table->char('impact_currency', 3)->nullable();

            // Click-through target. Relative URL within the app.
            $table->string('target_url', 500)->nullable();

            // Recommendation-type-specific payload for templating + drill-down.
            $table->jsonb('data')->nullable();

            // Status lifecycle — see class docblock.
            $table->string('status', 16)->default('open');
            $table->timestamp('snoozed_until')->nullable();

            $table->timestamps();

            // Primary query pattern: list open recs for a workspace sorted by priority.
            $table->index(['workspace_id', 'status', 'priority']);
            // Secondary: dedupe/replace recs of same type for a workspace.
            $table->index(['workspace_id', 'type', 'created_at']);
        });

        DB::statement("ALTER TABLE recommendations ADD CONSTRAINT recommendations_status_check CHECK (status IN ('open','done','snoozed','dismissed'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('recommendations');
    }
};
