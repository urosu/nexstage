<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sync_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained('workspaces')->cascadeOnDelete();
            $table->string('syncable_type');
            $table->unsignedBigInteger('syncable_id');
            $table->string('job_type', 100);
            $table->string('status', 50);
            $table->integer('records_processed')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->integer('duration_seconds')->nullable();
            $table->timestamps();

            $table->index(['workspace_id', 'syncable_type', 'syncable_id']);
            $table->index(['status', 'created_at']);
        });

        DB::statement("ALTER TABLE sync_logs ADD CONSTRAINT sync_logs_status_check CHECK (status IN ('running','completed','failed'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_logs');
    }
};
