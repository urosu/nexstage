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
            $table->string('sku')->nullable();
            $table->decimal('price', 12, 4)->nullable();
            $table->string('status', 100)->nullable();
            $table->text('image_url')->nullable();
            $table->text('product_url')->nullable();
            $table->timestamp('platform_updated_at')->nullable();
            $table->timestamps();

            $table->unique(['store_id', 'external_id']);
            $table->index(['workspace_id', 'store_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
