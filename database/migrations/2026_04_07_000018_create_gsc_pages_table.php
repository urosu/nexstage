<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gsc_pages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained('search_console_properties')->cascadeOnDelete();
            $table->foreignId('workspace_id')->constrained('workspaces')->cascadeOnDelete();
            $table->date('date');
            $table->string('page', 2000);
            $table->integer('clicks')->default(0);
            $table->integer('impressions')->default(0);
            $table->decimal('ctr', 8, 6)->nullable();
            $table->decimal('position', 6, 2)->nullable();
            $table->timestamps();

            $table->unique(['property_id', 'date', 'page']);
            $table->index(['property_id', 'date']);
            $table->index(['workspace_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gsc_pages');
    }
};
