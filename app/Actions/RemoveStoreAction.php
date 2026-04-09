<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Store;
use App\Models\SyncLog;
use Illuminate\Support\Facades\DB;

class RemoveStoreAction
{
    /**
     * Permanently remove a store and all its associated data.
     *
     * FK ON DELETE CASCADE handles: orders, order_items, products,
     * daily_snapshots, hourly_snapshots, webhook_logs, alerts.
     * sync_logs is polymorphic (no FK), so we delete those explicitly.
     */
    public function handle(Store $store): void
    {
        DB::transaction(function () use ($store): void {
            SyncLog::where('syncable_type', Store::class)
                ->where('syncable_id', $store->id)
                ->delete();

            $store->delete();
        });
    }
}
