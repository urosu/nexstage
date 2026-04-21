<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
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

            // When the job was dispatched — equals created_at for immediate jobs,
            // or a future timestamp for delayed dispatches (e.g. rate-limit backoff).
            $table->timestamp('scheduled_at')->nullable();

            // Which Horizon queue: critical | high | default | low
            $table->string('queue', 50)->nullable();

            // Retry attempt number. 1 = first run, 2+ = retries.
            $table->smallInteger('attempt')->default(1);

            // Job constructor arguments for debugging (store_id, date_range, etc.).
            $table->jsonb('payload')->nullable();

            $table->integer('duration_seconds')->nullable();
            $table->timestamps();

            $table->index(['workspace_id', 'syncable_type', 'syncable_id']);
            $table->index(['status', 'created_at']);
        });

        DB::statement("ALTER TABLE sync_logs ADD CONSTRAINT sync_logs_status_check CHECK (status IN ('queued','running','completed','failed'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_logs');
    }
};
