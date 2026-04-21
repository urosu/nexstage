<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Store;
use App\Models\SyncLog;
use App\Services\StoreConnectorFactory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Permanently remove a store and all its associated data.
 *
 * Platform webhooks are cleaned up via StoreConnectorFactory before the DB delete
 * so the platform API still has valid credentials. Failure is non-fatal.
 *
 * FK ON DELETE CASCADE handles: orders, order_items, products,
 * daily_snapshots, hourly_snapshots, webhook_logs, alerts.
 * sync_logs is polymorphic (no FK), so deleted explicitly.
 *
 * Related: app/Actions/ConnectStoreAction.php, app/Actions/ConnectShopifyStoreAction.php
 * Related: app/Services/StoreConnectorFactory.php
 * See: PLANNING.md section 4 "Platform-Agnostic Discipline"
 */
class RemoveStoreAction
{
    public function handle(Store $store): void
    {
        $workspaceId = (int) $store->workspace_id;

        // Remove platform webhooks before deleting the store record.
        // The store credential must still exist at call time so the connector can authenticate.
        // Failure is non-fatal — an orphaned platform webhook does no damage.
        try {
            StoreConnectorFactory::make($store)->removeWebhooks();
        } catch (\InvalidArgumentException $e) {
            // Platform not yet supported in factory — skip webhook cleanup gracefully.
            Log::info('RemoveStoreAction: no connector for platform, skipping webhook cleanup', [
                'store_id' => $store->id,
                'platform' => $store->platform,
            ]);
        } catch (\Throwable $e) {
            Log::warning('RemoveStoreAction: webhook cleanup failed (non-fatal)', [
                'store_id' => $store->id,
                'platform' => $store->platform,
                'error'    => $e->getMessage(),
            ]);
        }

        DB::transaction(function () use ($store): void {
            SyncLog::where('syncable_type', Store::class)
                ->where('syncable_id', $store->id)
                ->delete();

            $store->delete();
        });

        // Why: has_store drives billing basis + nav visibility. Recompute after every removal.
        $remainingStores = Store::withoutGlobalScopes()
            ->where('workspace_id', $workspaceId)
            ->count();

        DB::table('workspaces')
            ->where('id', $workspaceId)
            ->update(['has_store' => $remainingStores > 0]);
    }
}
