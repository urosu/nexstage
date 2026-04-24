<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Individual tag values within each creative_tag_categories dimension.
 *
 * Seeded by CreativeTagSeeder with 4–6 allowed slugs per category. The AI tagging
 * job's prompt enumerates the exact slugs so the model picks from this list rather
 * than inventing values. Any tag not on the list is returned as null and no row
 * is written to ad_creative_tags.
 *
 * @see database/seeders/CreativeTagSeeder.php
 * @see app/Jobs/TagCreativesWithAiJob.php
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('creative_tags', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('category_id')
                ->constrained('creative_tag_categories')
                ->cascadeOnDelete();
            $table->string('name', 100);   // slug: video, ugc, bold_claim, …
            $table->string('label', 100);  // display: "Video", "UGC", "Bold Claim", …
            $table->smallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['category_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('creative_tags');
    }
};
