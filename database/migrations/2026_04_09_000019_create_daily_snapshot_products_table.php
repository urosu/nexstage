<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Replaces daily_snapshots.top_products JSONB — top 50 products per store per day by revenue.
        // Populated by: ComputeDailySnapshotJob (writes top 50 per store per day).
        // Read by: Products analytics page controller.
        // Related: app/Jobs/ComputeDailySnapshotJob.php
        Schema::create('daily_snapshot_products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained('workspaces')->cascadeOnDelete();
            $table->foreignId('store_id')->constrained('stores')->cascadeOnDelete();
            $table->date('snapshot_date');
            $table->string('product_external_id', 255);
            $table->string('product_name', 500);
            $table->decimal('revenue', 14, 4);
            $table->integer('units')->default(0);
            $table->smallInteger('rank');

            // COGS snapshot — for Shopify stores where unit cost is on InventoryItem, not the order.
            // A nightly job snapshots cost here so historical orders can look up cost from the
            // snapshot date. NULL for WC stores (cost read directly from order item meta).
            // @see PLANNING.md section 5, 7
            $table->decimal('unit_cost', 12, 4)->nullable();

            // Current stock state at snapshot time. Enables out-of-stock transition detection
            // (Phase 1.6) and days-of-cover calculation without a second table.
            // Populated from products.stock_status / stock_quantity at ComputeDailySnapshotJob time.
            // @see PLANNING.md section 5.8
            $table->string('stock_status', 20)->nullable();
            $table->integer('stock_quantity')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->unique(['store_id', 'snapshot_date', 'product_external_id']);
            $table->index(['workspace_id', 'snapshot_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_snapshot_products');
    }
};
