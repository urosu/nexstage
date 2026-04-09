<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\SearchConsoleProperty;
use App\Models\SyncLog;
use Illuminate\Support\Facades\DB;

class RemoveGscPropertyAction
{
    /**
     * Permanently remove a GSC property and all its data.
     *
     * FK ON DELETE CASCADE handles: gsc_daily_stats, gsc_queries, gsc_pages.
     * sync_logs is polymorphic (no FK), so we delete those explicitly.
     */
    public function handle(SearchConsoleProperty $property): void
    {
        DB::transaction(function () use ($property): void {
            SyncLog::where('syncable_type', SearchConsoleProperty::class)
                ->where('syncable_id', $property->id)
                ->delete();

            $property->delete();
        });
    }
}
