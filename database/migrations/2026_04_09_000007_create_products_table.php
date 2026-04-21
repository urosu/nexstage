<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained('workspaces')->cascadeOnDelete();
            $table->foreignId('store_id')->constrained('stores')->cascadeOnDelete();
            $table->string('external_id');
            $table->string('name', 500);
            $table->string('slug', 500)->nullable();
            $table->string('sku')->nullable();
            $table->decimal('price', 12, 4)->nullable();
            $table->string('status', 100)->nullable();

            // Stock fields populated by SyncProductsJob + product.updated webhook.
            // Related: app/Jobs/SyncProductsJob.php
            $table->string('stock_status', 50)->nullable();  // in_stock, out_of_stock, on_backorder
            $table->integer('stock_quantity')->nullable();
            $table->string('product_type', 50)->nullable();  // simple, variable, grouped, external

            $table->text('image_url')->nullable();
            $table->text('product_url')->nullable();
            $table->timestamp('platform_updated_at')->nullable();
            $table->timestamps();

            $table->unique(['store_id', 'external_id']);
            $table->index(['workspace_id', 'store_id']);
            $table->index(['workspace_id', 'store_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
