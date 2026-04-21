<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Store;
use App\Scopes\WorkspaceScope;
use Carbon\Carbon;

/**
 * Invokable dispatcher called by the scheduler at 00:30 UTC daily.
 *
 * Iterates every active store whose workspace has not been soft-deleted and
 * dispatches one ComputeDailySnapshotJob for yesterday's UTC date.
 */
class DispatchDailySnapshots
{
    public function __invoke(): void
    {
        $yesterday = Carbon::yesterday('UTC');

        Store::withoutGlobalScope(WorkspaceScope::class)
            ->join('workspaces', 'stores.workspace_id', '=', 'workspaces.id')
            ->where('stores.status', 'active')
            ->whereNull('workspaces.deleted_at')
            ->whereRaw('NOT (workspaces.trial_ends_at < NOW() AND workspaces.billing_plan IS NULL)')
            ->select(['stores.id', 'stores.workspace_id'])
            ->chunkById(500, static function ($stores) use ($yesterday): void {
                foreach ($stores as $store) {
                    ComputeDailySnapshotJob::dispatch($store->id, $yesterday);
                }
            }, 'stores.id');
    }
}
