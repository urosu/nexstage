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
            ->select(['stores.id', 'stores.workspace_id'])
            ->each(static function (Store $store) use ($yesterday): void {
                ComputeDailySnapshotJob::dispatch($store->id, $yesterday);
            });
    }
}
