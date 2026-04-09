<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained('workspaces')->cascadeOnDelete();
            $table->foreignId('store_id')->constrained('stores')->cascadeOnDelete();
            $table->string('external_id');
            $table->string('external_number')->nullable();
            $table->string('status', 100);
            $table->char('currency', 3);
            $table->decimal('total', 12, 4);
            $table->decimal('subtotal', 12, 4);
            $table->decimal('tax', 12, 4)->default(0);
            $table->decimal('shipping', 12, 4)->default(0);
            $table->decimal('discount', 12, 4)->default(0);
            $table->decimal('total_in_reporting_currency', 12, 4)->nullable();
            $table->char('customer_email_hash', 64)->nullable();
            $table->char('customer_country', 2)->nullable();
            $table->string('utm_source')->nullable();
            $table->string('utm_medium')->nullable();
            $table->string('utm_campaign')->nullable();
            $table->string('utm_content')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamp('synced_at');
            $table->timestamps();

            $table->unique(['store_id', 'external_id']);
            $table->index(['workspace_id', 'occurred_at']);
            $table->index(['workspace_id', 'store_id', 'occurred_at']);
            $table->index(['workspace_id', 'customer_country', 'occurred_at']);
            $table->index(['store_id', 'synced_at']);
        });

        DB::statement("ALTER TABLE orders ADD CONSTRAINT orders_status_check CHECK (status IN ('completed','processing','refunded','cancelled','other'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
