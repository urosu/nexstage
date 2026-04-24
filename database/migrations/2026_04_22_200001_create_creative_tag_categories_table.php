<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fixed taxonomy of creative analysis dimensions used to classify ad creatives.
 *
 * Eight system categories are seeded by CreativeTagSeeder (asset_type, visual_format,
 * hook_tactic, messaging_theme, intended_audience, seasonality, offer_type,
 * brand_specific). Each category has a fixed set of allowed tags in the
 * creative_tags table. The AI tagging job picks from those slugs — no dynamic
 * generation, so the Hit Rate × Spend Use Ratio QuadrantChart has consistent axes.
 *
 * @see PROGRESS.md §Phase 4.1 — creative_tag_categories + creative_tags + ad_creative_tags
 * @see app/Jobs/TagCreativesWithAiJob.php
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('creative_tag_categories', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 50)->unique();  // slug: asset_type, visual_format, …
            $table->string('label', 100);           // display: "Asset Type", "Visual Format", …
            $table->smallInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('creative_tag_categories');
    }
};
