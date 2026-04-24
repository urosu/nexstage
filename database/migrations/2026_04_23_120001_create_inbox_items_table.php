<?php

declare(strict_types=1);

use App\Models\AiSummary;
use App\Models\Alert;
use App\Models\DailyNote;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Polymorphic wrapper table aggregating all item types surfaced in /inbox:
 * Alerts, AiSummaries, DailyNotes. Recommendations are NOT wrapped — they
 * have their own status + snoozed_until columns and are queried directly.
 *
 * Why this exists: /inbox is a unified destination that needs a single query
 * path to list mixed item types with a shared lifecycle (done/snoozed/dismissed).
 * Each underlying model owns its domain state; inbox_items owns presentation
 * state (was this seen, snoozed, dismissed in the inbox context).
 *
 * Status lifecycle: open → done | dismissed; or snoozed_until set (auto-resurfaces).
 *
 * Backfill: seeds one inbox_items row per existing alert, ai_summary, daily_note
 * so users don't see an empty inbox after launch.
 *
 * @see PROGRESS.md §Phase 4.2 — Inbox destination
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inbox_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained('workspaces')->cascadeOnDelete();

            // Polymorphic discriminator: fully qualified model class name.
            // Values: 'App\Models\Alert', 'App\Models\AiSummary', 'App\Models\DailyNote'.
            $table->string('itemable_type');
            $table->unsignedBigInteger('itemable_id');

            // Status lifecycle — open items appear in the inbox, done/dismissed are hidden.
            $table->string('status', 16)->default('open');

            // Auto-resurface time. NULL = not snoozed. Past timestamp = snooze expired.
            $table->timestamp('snoozed_until')->nullable();

            $table->timestamps();

            // Primary query: list active items for a workspace ordered by recency.
            $table->index(['workspace_id', 'status', 'snoozed_until', 'created_at']);
            // Secondary: load inbox_item for a given underlying model (e.g. Alert#42).
            $table->index(['itemable_type', 'itemable_id']);
            // Uniqueness: one inbox_item per underlying model instance per workspace.
            $table->unique(['workspace_id', 'itemable_type', 'itemable_id'], 'inbox_items_unique_itemable');
        });

        DB::statement("ALTER TABLE inbox_items ADD CONSTRAINT inbox_items_status_check CHECK (status IN ('open','done','dismissed'))");

        $this->backfillExistingItems();
    }

    public function down(): void
    {
        Schema::dropIfExists('inbox_items');
    }

    /**
     * Seed inbox_items for existing alerts, ai_summaries, and daily_notes so the
     * /inbox is not empty on launch. Maps underlying state to inbox status:
     *   Alert.resolved_at NOT NULL → done (user already dismissed on old /insights)
     *   AiSummary / DailyNote always → open (no prior dismiss concept)
     */
    private function backfillExistingItems(): void
    {
        $now = now();

        // Alerts: read in chunks to avoid loading 100k rows at once.
        DB::table('alerts')->orderBy('id')->chunkById(1000, function ($alerts) use ($now): void {
            $rows = [];
            foreach ($alerts as $alert) {
                $rows[] = [
                    'workspace_id'  => $alert->workspace_id,
                    'itemable_type' => Alert::class,
                    'itemable_id'   => $alert->id,
                    'status'        => $alert->resolved_at !== null ? 'done' : 'open',
                    'snoozed_until' => null,
                    'created_at'    => $alert->created_at ?? $now,
                    'updated_at'    => $alert->updated_at ?? $now,
                ];
            }
            if ($rows !== []) {
                DB::table('inbox_items')->insert($rows);
            }
        });

        // AI summaries: always open (no prior dismiss state tracked).
        DB::table('ai_summaries')->orderBy('id')->chunkById(1000, function ($summaries) use ($now): void {
            $rows = [];
            foreach ($summaries as $summary) {
                $rows[] = [
                    'workspace_id'  => $summary->workspace_id,
                    'itemable_type' => AiSummary::class,
                    'itemable_id'   => $summary->id,
                    'status'        => 'open',
                    'snoozed_until' => null,
                    'created_at'    => $summary->created_at ?? $now,
                    'updated_at'    => $summary->updated_at ?? $now,
                ];
            }
            if ($rows !== []) {
                DB::table('inbox_items')->insert($rows);
            }
        });

        // Daily notes: always open.
        DB::table('daily_notes')->orderBy('id')->chunkById(1000, function ($notes) use ($now): void {
            $rows = [];
            foreach ($notes as $note) {
                $rows[] = [
                    'workspace_id'  => $note->workspace_id,
                    'itemable_type' => DailyNote::class,
                    'itemable_id'   => $note->id,
                    'status'        => 'open',
                    'snoozed_until' => null,
                    'created_at'    => $note->created_at ?? $now,
                    'updated_at'    => $note->updated_at ?? $now,
                ];
            }
            if ($rows !== []) {
                DB::table('inbox_items')->insert($rows);
            }
        });
    }
};
