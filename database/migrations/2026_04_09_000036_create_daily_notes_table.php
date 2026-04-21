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
        Schema::create('daily_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained('workspaces')->cascadeOnDelete();
            $table->date('date');
            $table->text('note');

            // Scope columns — notes written in a store-scoped view annotate only that store's charts.
            // Mirrors the workspace_events scope pattern — same columns, same CHECK constraint values.
            // DEFAULT 'workspace' means all existing notes remain workspace-wide.
            // @see PLANNING.md section 5, 8
            $table->string('scope_type', 16)->default('workspace');
            $table->unsignedBigInteger('scope_id')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // One note per workspace per day.
            $table->unique(['workspace_id', 'date']);
            $table->index(['workspace_id', 'date']);
        });

        DB::statement("ALTER TABLE daily_notes ADD CONSTRAINT daily_notes_scope_type_check CHECK (scope_type IN ('workspace','store','integration'))");
        DB::statement("CREATE INDEX daily_notes_scope_index ON daily_notes (workspace_id, scope_type, scope_id)");
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_notes');
    }
};
