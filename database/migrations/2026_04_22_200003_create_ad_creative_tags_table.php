<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pivot between ads and creative_tags. One row per (ad, tag) pair.
 *
 * Written by TagCreativesWithAiJob after calling claude-sonnet-4-6 with the ad's
 * creative_data (thumbnail + headline) and the allowed tag slugs. Confidence is
 * always 1.0 for AI-constrained picks (model chose from the fixed list). Source
 * is 'ai' by default; future UI will allow 'manual' overrides.
 *
 * An ad will have at most one tag per category (enforced by the UNIQUE on the pivot's
 * (ad_id, creative_tag_id) and the fact that the job writes exactly one tag per category).
 *
 * @see app/Jobs/TagCreativesWithAiJob.php
 * @see PROGRESS.md §Phase 4.1
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ad_creative_tags', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('ad_id')
                ->constrained('ads')
                ->cascadeOnDelete();
            $table->foreignId('creative_tag_id')
                ->constrained('creative_tags')
                ->cascadeOnDelete();
            // 0.000–1.000. Always 1.0 for constrained AI picks.
            $table->decimal('confidence', 4, 3)->default(1.0);
            $table->string('source', 10)->default('ai');  // 'ai' | 'manual'
            $table->timestamp('tagged_at')->useCurrent();
            $table->timestamps();

            $table->unique(['ad_id', 'creative_tag_id']);
            $table->index('ad_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ad_creative_tags');
    }
};
