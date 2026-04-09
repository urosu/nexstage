<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webhook_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained('stores')->cascadeOnDelete();
            $table->foreignId('workspace_id')->constrained('workspaces')->cascadeOnDelete();
            $table->string('event');
            $table->jsonb('payload');
            $table->boolean('signature_valid');
            $table->string('status', 50)->default('pending');
            $table->text('error_message')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->index(['store_id', 'created_at']);
        });

        DB::statement("ALTER TABLE webhook_logs ADD CONSTRAINT webhook_logs_status_check CHECK (status IN ('pending','processed','failed'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_logs');
    }
};
