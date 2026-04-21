<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Creates channel_mappings table and seeds ~40 global rows.
 *
 * Maps (utm_source, utm_medium) pairs to named channels and channel types.
 * Global rows (workspace_id=NULL, is_global=true) are the seed defaults.
 * A workspace can override any global row by creating its own row with the same
 * (utm_source_pattern, utm_medium_pattern) — ChannelClassifierService prefers
 * the workspace row over the global row.
 *
 * utm_medium_pattern=NULL means "match any medium for this source".
 *
 * Unique behaviour: Postgres treats NULLs as distinct in unique indexes, so
 * multiple global rows (workspace_id=NULL) with the same source+medium are
 * prevented by a separate partial unique index on global rows.
 *
 * @see PLANNING.md section 5, 16.4
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('channel_mappings', function (Blueprint $table) {
            $table->id();

            // NULL = global seed row. FK cascade deletes workspace overrides when workspace deleted.
            $table->foreignId('workspace_id')->nullable()->constrained('workspaces')->cascadeOnDelete();

            $table->string('utm_source_pattern', 255);   // lowercase exact match
            $table->string('utm_medium_pattern', 255)->nullable();  // NULL = match any medium

            $table->string('channel_name', 120);
            $table->string('channel_type', 32);

            // true on seed rows; false on workspace overrides.
            $table->boolean('is_global')->default(false);

            $table->timestamps();

            $table->index(['workspace_id', 'utm_source_pattern']);
        });

        DB::statement("ALTER TABLE channel_mappings ADD CONSTRAINT channel_mappings_channel_type_check CHECK (channel_type IN ('email','paid_social','paid_search','organic_search','organic_social','direct','referral','affiliate','sms','other'))");

        // Workspace rows: one mapping per (workspace, source, medium) pair.
        DB::statement("CREATE UNIQUE INDEX channel_mappings_workspace_unique ON channel_mappings (workspace_id, utm_source_pattern, COALESCE(utm_medium_pattern, '')) WHERE workspace_id IS NOT NULL");

        // Global rows: one global mapping per (source, medium) pair.
        DB::statement("CREATE UNIQUE INDEX channel_mappings_global_unique ON channel_mappings (utm_source_pattern, COALESCE(utm_medium_pattern, '')) WHERE workspace_id IS NULL");

    }

    public function down(): void
    {
        Schema::dropIfExists('channel_mappings');
    }
};
