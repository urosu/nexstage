<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Creates product_costs table for manual COGS fallback.
 *
 * Used when the store has no COGS plugin (WooCommerce) or pre-Shopify-snapshot
 * data is needed. Two entry paths: CSV upload or manual entry in UI.
 *
 * effective_from / effective_to: allows cost changes over time to be tracked
 * so historical contribution margin remains accurate. effective_to=NULL means
 * the row is current. CogsReaderService picks the row matching the order date.
 *
 * @see PLANNING.md section 5, 7
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_costs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained('workspaces')->cascadeOnDelete();
            $table->foreignId('store_id')->constrained('stores')->cascadeOnDelete();

            // External platform product ID (string — never assume integer).
            $table->string('product_external_id', 255);

            $table->decimal('unit_cost', 12, 4);
            $table->char('currency', 3);

            $table->date('effective_from');
            $table->date('effective_to')->nullable();  // NULL = currently active

            // csv / manual
            $table->string('source', 16);

            $table->timestamps();

            $table->index(['workspace_id', 'store_id', 'product_external_id', 'effective_from']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_costs');
    }
};
