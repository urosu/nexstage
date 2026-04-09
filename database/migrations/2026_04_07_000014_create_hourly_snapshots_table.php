<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hourly_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained('workspaces')->cascadeOnDelete();
            $table->foreignId('store_id')->constrained('stores')->cascadeOnDelete();
            $table->date('date');
            $table->smallInteger('hour');
            $table->integer('orders_count')->default(0);
            $table->decimal('revenue', 14, 4)->default(0);
            $table->timestamps();

            $table->unique(['store_id', 'date', 'hour']);
            $table->index(['workspace_id', 'store_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hourly_snapshots');
    }
};
