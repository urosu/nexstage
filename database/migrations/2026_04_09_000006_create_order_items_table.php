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
        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();

            // workspace_id and store_id intentionally omitted — derivable via order_id.
            // Why: avoids data redundancy and removes the need for WorkspaceScope on this model.
            // IMPORTANT: All OrderItem queries MUST go through $order->items() or join through
            // the orders table to maintain tenant isolation. Direct OrderItem:: queries bypass
            // workspace scoping. See: PLANNING.md "order_items" schema changes.

            $table->string('product_external_id');
            $table->string('product_name', 500);
            $table->string('variant_name')->nullable();
            $table->string('sku')->nullable();
            $table->integer('quantity');
            $table->decimal('unit_price', 12, 4);

            // Written by CogsReaderService from WooCommerce order item meta (three priority-ordered
            // plugin sources) or from daily_snapshot_products for Shopify. NULL when no COGS
            // source is configured.
            // @see PLANNING.md section 5, 7
            $table->decimal('unit_cost', 12, 4)->nullable();

            // Per-line discount for contribution margin calculations.
            // On WC: proportional split of order discount. On Shopify: native per-line allocation.
            $table->decimal('discount_amount', 12, 4)->nullable();

            $table->decimal('line_total', 12, 4);
            $table->timestamps();

            $table->index('order_id');
        });

        // Functional unique index to handle upserts: (order_id, product_external_id, variant_name)
        // where variant_name may be NULL. COALESCE normalizes NULL to '' for uniqueness.
        DB::statement("CREATE UNIQUE INDEX order_items_upsert_key ON order_items (order_id, product_external_id, COALESCE(variant_name, ''))");
    }

    public function down(): void
    {
        Schema::dropIfExists('order_items');
    }
};
