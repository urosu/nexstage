<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Workspace;
use App\Scopes\WorkspaceScope;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Invokable scheduler action. Runs every hour (see routes/console.php).
 *
 * Finds workspaces whose local clock reads exactly 5am, then dispatches one
 * SendDailyDigestJob per qualifying workspace. Cache-based dedup (25h TTL)
 * prevents double-sends across DST transitions or scheduler overlaps.
 *
 * Reads:  workspaces (timezone, deleted_at, billing state)
 * Writes: SendDailyDigestJob → queue; Cache for dedup keys
 *
 * @see App\Jobs\SendDailyDigestJob
 * @see PROGRESS.md Phase 3.7 — Daily digest
 */
class DispatchDailyDigestsJob
{
    public function __invoke(): void
    {
        Workspace::withoutGlobalScope(WorkspaceScope::class)
            ->whereNull('deleted_at')
            ->whereRaw('NOT (trial_ends_at < NOW() AND billing_plan IS NULL)')
            ->select(['id', 'timezone'])
            ->chunkById(200, function ($chunk): void {
                foreach ($chunk as $workspace) {
                    $tz    = $workspace->timezone ?: 'UTC';
                    $local = Carbon::now($tz);

                    if ($local->hour !== 5) {
                        continue;
                    }

                    $localDate = $local->toDateString();
                    $cacheKey  = "daily_digest:{$workspace->id}:{$localDate}";

                    if (Cache::has($cacheKey)) {
                        continue;
                    }

                    // Mark before dispatch so a second scheduler instance within the same
                    // minute cannot double-dispatch the same workspace.
                    Cache::put($cacheKey, true, now()->addHours(25));

                    SendDailyDigestJob::dispatch($workspace->id, $local->isMonday());

                    Log::debug('DispatchDailyDigestsJob: dispatched', [
                        'workspace_id' => $workspace->id,
                        'local_date'   => $localDate,
                        'is_monday'    => $local->isMonday(),
                    ]);
                }
            });
    }
}
