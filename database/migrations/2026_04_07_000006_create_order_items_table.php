<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->foreignId('workspace_id')->constrained('workspaces')->cascadeOnDelete();
            $table->foreignId('store_id')->constrained('stores')->cascadeOnDelete();
            $table->string('product_external_id');
            $table->string('product_name', 500);
            $table->string('variant_name')->nullable();
            $table->string('sku')->nullable();
            $table->integer('quantity');
            $table->decimal('unit_price', 12, 4);
            $table->decimal('line_total', 12, 4);
            $table->timestamps();

            $table->index(['workspace_id', 'product_external_id']);
            $table->index('order_id');
        });

        DB::statement('CREATE UNIQUE INDEX order_items_upsert_key ON order_items (order_id, product_external_id, COALESCE(variant_name, \'\'))');
    }

    public function down(): void
    {
        Schema::dropIfExists('order_items');
    }
};
