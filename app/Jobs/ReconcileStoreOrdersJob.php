<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Actions\UpsertWooCommerceOrderAction;
use App\Exceptions\WooCommerceRateLimitException;
use App\Models\Alert;
use App\Models\Store;
use App\Models\SyncLog;
use App\Models\Workspace;
use App\Services\Integrations\WooCommerce\WooCommerceConnector;
use App\Services\WorkspaceContext;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Nightly order reconciliation job for a single WooCommerce store.
 *
 * Triggered by: Schedule (nightly 01:30 UTC, see routes/console.php)
 * Reads from:   WooCommerce REST API (all orders modified in last 7 days)
 * Writes to:    orders, order_items, order_coupons (via UpsertWooCommerceOrderAction)
 *               sync_logs, alerts
 *
 * Compares WooCommerce order IDs + updated_at against the local orders table.
 * Backfills missing orders and re-upserts orders that were modified on the WC side
 * after the local record was written (covers webhook delivery failures, reprocessing,
 * and partial outages).
 *
 * Alerts (is_silent=true) when:
 *   - Discrepancy rate > 5%  (missing + changed / total WC orders)
 *   - No webhooks received from this store in the last 48 hours
 *
 * Queue:   low
 * Timeout: 300 s
 * Tries:   2
 *
 * Related: app/Jobs/SyncStoreOrdersJob.php (hourly fallback; this is nightly deep check)
 * Related: app/Services/Integrations/WooCommerce/WooCommerceConnector.php
 * See: PLANNING.md "Webhook Reliability"
 */
class ReconcileStoreOrdersJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 300;
    public int $tries   = 2;

    public function __construct(
        private readonly int $storeId,
        private readonly int $workspaceId,
    ) {
        $this->onQueue('low');
    }

    public function handle(): void
    {
        app(WorkspaceContext::class)->set($this->workspaceId);

        $store = Store::find($this->storeId);

        if ($store === null || $store->status !== 'active') {
            return;
        }

        $since   = now()->subDays(7);
        $syncLog = SyncLog::create([
            'workspace_id'      => $this->workspaceId,
            'syncable_type'     => Store::class,
            'syncable_id'       => $this->storeId,
            'job_type'          => self::class,
            'status'            => 'running',
            'records_processed' => 0,
            'started_at'        => now(),
            'queue'             => 'low',
            'attempt'           => $this->attempts(),
            'timeout_seconds'   => $this->timeout,
        ]);

        try {
            [$backfilled, $updated, $deleted, $wcCount] = $this->reconcile($store, $since);

            $discrepancies = $backfilled + $updated;

            $syncLog->update([
                'status'            => 'completed',
                'records_processed' => $discrepancies,
                'completed_at'      => now(),
                'duration_seconds'  => (int) max(0, (int) now()->diffInSeconds($syncLog->started_at)),
            ]);

            Log::info('ReconcileStoreOrdersJob: completed', [
                'store_id'   => $this->storeId,
                'wc_count'   => $wcCount,
                'backfilled' => $backfilled,
                'updated'    => $updated,
                'deleted'    => $deleted,
            ]);

            // Alert if discrepancy rate >5% — suggests persistent webhook delivery issues.
            if ($wcCount > 0 && ($discrepancies / $wcCount) > 0.05) {
                $this->createDiscrepancyAlert($discrepancies, $backfilled, $updated, $wcCount);
            }

            // Alert if no webhook activity in last 48 hours — store is running on polling only.
            if ($this->webhooksStaleForHours($this->storeId, 48)) {
                $this->createWebhookStaleAlert($store);
            }
        } catch (WooCommerceRateLimitException $e) {
            $this->release($e->retryAfter);

            return;
        } catch (\Throwable $e) {
            $syncLog->update([
                'status'           => 'failed',
                'error_message'    => mb_substr($e->getMessage(), 0, 500),
                'completed_at'     => now(),
                'duration_seconds' => (int) max(0, (int) now()->diffInSeconds($syncLog->started_at)),
            ]);

            Log::error('ReconcileStoreOrdersJob: failed', [
                'store_id' => $this->storeId,
                'error'    => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Called after ALL retries are exhausted. Closes orphaned sync_log rows.
     */
    public function failed(\Throwable $exception): void
    {
        try {
            app(WorkspaceContext::class)->set($this->workspaceId);

            SyncLog::withoutGlobalScopes()
                ->where('syncable_type', Store::class)
                ->where('syncable_id', $this->storeId)
                ->where('workspace_id', $this->workspaceId)
                ->where('status', 'running')
                ->update([
                    'status'        => 'failed',
                    'error_message' => mb_substr($exception->getMessage(), 0, 500),
                    'completed_at'  => now(),
                ]);

            Log::error('ReconcileStoreOrdersJob::failed()', [
                'store_id' => $this->storeId,
                'error'    => $exception->getMessage(),
            ]);
        } catch (\Throwable $e) {
            Log::error('ReconcileStoreOrdersJob::failed(): could not record failure state', [
                'store_id' => $this->storeId,
                'error'    => $e->getMessage(),
            ]);
        }
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Fetch all WC orders for the last 7 days, compare to local DB, and:
     *   - Backfill orders in WC but missing locally (webhook missed).
     *   - Update orders whose WC date_modified is newer than our local updated_at.
     *   - Hard-delete orders in our DB but absent from WC (test orders, user-deleted).
     *
     * Hard-deletes are scoped to the same 7-day window so we only touch orders that
     * WC's response was authoritative for. Orders older than 7 days are never touched.
     *
     * @return array{int, int, int, int}  [backfilled, updated, deleted, wcCount]
     */
    private function reconcile(Store $store, Carbon $since): array
    {
        $connector = new WooCommerceConnector($store);
        $wcOrders  = $connector->fetchRawOrders($since);
        $wcCount   = count($wcOrders);

        $workspace    = Workspace::find($this->workspaceId);
        $reportingCcy = $workspace?->reporting_currency ?? 'EUR';

        // Build a set of external IDs returned by WC for the window.
        $wcExternalIds = [];
        foreach ($wcOrders as $wcOrder) {
            if (isset($wcOrder['id'])) {
                $wcExternalIds[] = (string) $wcOrder['id'];
            }
        }

        // Build local lookup: external_id → updated_at Carbon instance.
        // Uses DB::table to bypass WorkspaceScope (we're already scoped via store_id).
        $localOrders = DB::table('orders')
            ->where('store_id', $store->id)
            ->where('workspace_id', $this->workspaceId)
            ->where('occurred_at', '>=', $since)
            ->pluck('updated_at', 'external_id')
            ->map(fn (string $ts) => Carbon::parse($ts));

        // If WC returned nothing and we have nothing locally, skip all work.
        if ($wcCount === 0 && $localOrders->isEmpty()) {
            return [0, 0, 0, 0];
        }

        /** @var UpsertWooCommerceOrderAction $action */
        $action     = app(UpsertWooCommerceOrderAction::class);
        $backfilled = 0;
        $updated    = 0;

        foreach ($wcOrders as $wcOrder) {
            $externalId  = (string) ($wcOrder['id'] ?? '');
            $wcUpdatedAt = isset($wcOrder['date_modified_gmt'])
                ? Carbon::parse($wcOrder['date_modified_gmt'] . 'Z')
                : null;

            if (! isset($localOrders[$externalId])) {
                // In WC but missing locally — backfill.
                $action->handle($store, $reportingCcy, $wcOrder);
                $backfilled++;
            } elseif ($wcUpdatedAt !== null && $wcUpdatedAt->gt($localOrders[$externalId])) {
                // In both, but WC side is newer — re-upsert to pick up status/total changes.
                $action->handle($store, $reportingCcy, $wcOrder);
                $updated++;
            }
        }

        // Hard-delete orders in our DB that WC no longer returns for the same window.
        // These were deleted on the store (test orders, merchant-cancelled).
        // FK ON DELETE CASCADE removes order_items and order_coupons automatically.
        $disappearedIds = $localOrders->keys()
            ->diff($wcExternalIds)
            ->values()
            ->all();

        $deleted = 0;

        if (! empty($disappearedIds)) {
            $deleted = DB::table('orders')
                ->where('store_id', $store->id)
                ->where('workspace_id', $this->workspaceId)
                ->whereIn('external_id', $disappearedIds)
                ->delete();

            Log::info('ReconcileStoreOrdersJob: hard-deleted disappeared orders', [
                'store_id'    => $this->storeId,
                'deleted'     => $deleted,
                'external_ids' => $disappearedIds,
            ]);
        }

        return [$backfilled, $updated, $deleted, $wcCount];
    }

    /**
     * Returns true if no webhook was delivered to this store within the last $hours hours.
     * Uses DB::table to bypass WorkspaceScope — only needs a quick existence check.
     */
    private function webhooksStaleForHours(int $storeId, int $hours): bool
    {
        return ! DB::table('webhook_logs')
            ->where('store_id', $storeId)
            ->where('created_at', '>=', now()->subHours($hours))
            ->limit(1)
            ->exists();
    }

    /**
     * Create a silent discrepancy alert (deduped to once per 24 hours).
     */
    private function createDiscrepancyAlert(int $discrepancyCount, int $backfilled, int $updated, int $wcCount): void
    {
        $alreadyAlerted = Alert::withoutGlobalScopes()
            ->where('workspace_id', $this->workspaceId)
            ->where('store_id', $this->storeId)
            ->where('type', 'reconciliation_discrepancy')
            ->whereNull('resolved_at')
            ->where('created_at', '>=', now()->subHours(24))
            ->exists();

        if ($alreadyAlerted) {
            return;
        }

        $ratePct = round(($discrepancyCount / $wcCount) * 100, 1);

        Alert::create([
            'workspace_id' => $this->workspaceId,
            'store_id'     => $this->storeId,
            'type'         => 'reconciliation_discrepancy',
            'severity'     => 'warning',
            'is_silent'    => true,
            'data'         => [
                'discrepancy_count' => $discrepancyCount,
                'backfilled'        => $backfilled,
                'updated'           => $updated,
                'wc_count'          => $wcCount,
                'rate_pct'          => $ratePct,
            ],
        ]);
    }

    /**
     * Create a silent webhook-stale alert (deduped to once per 24 hours).
     */
    private function createWebhookStaleAlert(Store $store): void
    {
        $alreadyAlerted = Alert::withoutGlobalScopes()
            ->where('workspace_id', $this->workspaceId)
            ->where('store_id', $this->storeId)
            ->where('type', 'webhook_stale')
            ->whereNull('resolved_at')
            ->where('created_at', '>=', now()->subHours(24))
            ->exists();

        if ($alreadyAlerted) {
            return;
        }

        Alert::create([
            'workspace_id' => $this->workspaceId,
            'store_id'     => $this->storeId,
            'type'         => 'webhook_stale',
            'severity'     => 'warning',
            'is_silent'    => true,
            'data'         => [
                'store_name' => $store->name,
                'message'    => 'No webhook activity for >48 hours. Orders are being synced via the hourly polling fallback.',
            ],
        ]);
    }
}
