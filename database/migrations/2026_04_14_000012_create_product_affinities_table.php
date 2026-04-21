<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Creates product_affinities table for Frequently-Bought-Together output.
 *
 * Populated weekly by ComputeProductAffinitiesJob (Phase 1.6) using an
 * Apriori-style query over the last 90 days of order_items. Unique on
 * (workspace_id, store_id, product_a_id, product_b_id) — the job replaces
 * rows on each run by deleting and reinserting the current workspace batch.
 *
 * margin_lift: co-occurrence confidence weighted by contribution margin.
 * The differentiating metric vs naive FBT — requires COGS data.
 * NULL when COGS is not configured.
 *
 * @see PLANNING.md section 5
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_affinities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained('workspaces')->cascadeOnDelete();
            $table->foreignId('store_id')->constrained('stores')->cascadeOnDelete();

            // Both FKs reference products.id within the same store.
            $table->foreignId('product_a_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('product_b_id')->constrained('products')->cascadeOnDelete();

            // Apriori metrics.
            $table->decimal('support', 8, 6);        // fraction of orders containing both
            $table->decimal('confidence', 8, 6);     // P(B | A)
            $table->decimal('lift', 8, 4);            // confidence / P(B)

            // confidence × contribution margin. NULL when COGS not configured.
            $table->decimal('margin_lift', 12, 4)->nullable();

            $table->timestamp('calculated_at');

            $table->unique(['workspace_id', 'store_id', 'product_a_id', 'product_b_id']);
            $table->index(['workspace_id', 'store_id', 'product_a_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_affinities');
    }
};
