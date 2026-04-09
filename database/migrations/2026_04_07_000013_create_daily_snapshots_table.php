<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('daily_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained('workspaces')->cascadeOnDelete();
            $table->foreignId('store_id')->constrained('stores')->cascadeOnDelete();
            $table->date('date');
            $table->integer('orders_count')->default(0);
            $table->decimal('revenue', 14, 4)->default(0);
            $table->decimal('revenue_native', 14, 4)->default(0);
            $table->decimal('aov', 10, 4)->nullable();
            $table->integer('items_sold')->default(0);
            $table->decimal('items_per_order', 6, 2)->nullable();
            $table->integer('new_customers')->default(0);
            $table->integer('returning_customers')->default(0);
            $table->jsonb('revenue_by_country')->nullable();
            $table->jsonb('top_products')->nullable();
            $table->timestamps();

            $table->unique(['store_id', 'date']);
            $table->index(['workspace_id', 'date']);
            $table->index(['workspace_id', 'store_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_snapshots');
    }
};
