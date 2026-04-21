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
        // Workspace-specific promotions and expected spikes/drops for baseline adjustment.
        // NOT for holidays — those are in the global holidays table.
        //
        // Consumed by: DetectAnomaliesJob (suppress alerts during events where suppress_anomalies=true),
        //              ComputeMetricBaselinesJob (exclude event dates from rolling window),
        //              chart event overlay markers (Phase 1).
        // See: PLANNING.md "workspace_events"
        Schema::create('workspace_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained('workspaces')->cascadeOnDelete();
            $table->string('event_type', 50);  // promotion, expected_spike, expected_drop
            $table->string('name', 255);
            $table->date('date_from');
            $table->date('date_to');

            // Phase 2: auto-detected from coupon usage patterns.
            $table->boolean('is_auto_detected')->default(false);
            $table->boolean('needs_review')->default(false);

            $table->boolean('suppress_anomalies')->default(true);

            // Scope columns — events can be narrowed to a specific store or integration.
            // Scope-aware annotations render on charts only when the active scope matches.
            // DEFAULT 'workspace' preserves current behavior for all existing rows.
            // @see PLANNING.md section 5, 8
            $table->string('scope_type', 16)->default('workspace');
            $table->unsignedBigInteger('scope_id')->nullable();

            $table->timestamps();

            $table->index(['workspace_id', 'date_from', 'date_to']);
        });

        DB::statement("ALTER TABLE workspace_events ADD CONSTRAINT workspace_events_event_type_check CHECK (event_type IN ('promotion','expected_spike','expected_drop'))");
        DB::statement("ALTER TABLE workspace_events ADD CONSTRAINT workspace_events_scope_type_check CHECK (scope_type IN ('workspace','store','integration'))");
        DB::statement("CREATE INDEX workspace_events_scope_index ON workspace_events (workspace_id, scope_type, scope_id)");
    }

    public function down(): void
    {
        Schema::dropIfExists('workspace_events');
    }
};
