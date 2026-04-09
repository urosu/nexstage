<?php

declare(strict_types=1);

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Hard-deletes workspaces that were soft-deleted more than 30 days ago.
 *
 * Queue:   low
 * Timeout: 300 s
 * Tries:   3
 * Backoff: default [60, 300, 900] s
 *
 * Scheduled weekly on Sunday at 05:00 UTC (routes/console.php).
 *
 * Deletion flow (per spec):
 *  1. Find workspaces where deleted_at < now() - 30 days
 *  2. Hard-delete each in a DB transaction
 *  3. All related data cascades via FK ON DELETE CASCADE
 *  4. Log each purge for audit trail
 *
 * This job must never restore or modify workspaces that are within the 30-day
 * grace window — those remain soft-deleted so the owner can cancel deletion.
 */
class PurgeDeletedWorkspaceJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 300;
    public int $tries   = 3;

    public function __construct()
    {
        $this->onQueue('low');
    }

    public function handle(): void
    {
        $cutoff = now()->subDays(30)->toDateTimeString();

        $candidates = DB::table('workspaces')
            ->whereNotNull('deleted_at')
            ->where('deleted_at', '<', $cutoff)
            ->select(['id', 'name', 'deleted_at'])
            ->get();

        if ($candidates->isEmpty()) {
            Log::info('PurgeDeletedWorkspaceJob: no workspaces eligible for purge.');
            return;
        }

        $purged = 0;

        foreach ($candidates as $workspace) {
            DB::transaction(function () use ($workspace, &$purged): void {
                // ON DELETE CASCADE handles all child records.
                DB::table('workspaces')
                    ->where('id', $workspace->id)
                    ->whereNotNull('deleted_at') // double-check: never hard-delete a live workspace
                    ->delete();

                Log::info('Workspace purged', [
                    'workspace_id' => $workspace->id,
                    'name'         => $workspace->name,
                    'deleted_at'   => $workspace->deleted_at,
                ]);

                $purged++;
            });
        }

        Log::info('PurgeDeletedWorkspaceJob: completed', ['purged' => $purged]);
    }
}
