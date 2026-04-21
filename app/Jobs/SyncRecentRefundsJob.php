<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Exceptions\WooCommerceRateLimitException;
use App\Models\Alert;
use App\Models\Store;
use App\Models\SyncLog;
use App\Services\Integrations\WooCommerce\WooCommerceConnector;
use App\Services\WorkspaceContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Nightly refund backfill for a single WooCommerce store.
 *
 * Triggered by: routes/console.php (nightly 03:30 UTC, per active WooCommerce store)
 * Reads from:   WooCommerce REST API (orders modified in last 7 days → /orders/{id}/refunds)
 * Writes to:    refunds table (upsert), orders.refund_amount + orders.last_refunded_at (denormalized)
 *
 * Queue:   sync-store
 * Timeout: 120 s
 * Tries:   3
 * Backoff: [60, 300, 900] s
 *
 * Why 7-day lookback: WooCommerce refunds can be issued days after order capture.
 * We rescan the last 7 days on every nightly run to catch late refunds.
 * See: PLANNING.md "SyncRecentRefundsJob"
 *
 * Failure handling (applied once per dispatch, after all retries exhausted):
 *   - Increments consecutive_sync_failures.
 *   - Creates an alert (warning at first failure, critical at 3+).
 *   - Sets store status to 'error' at 3+ consecutive failures.
 *   - Resets on success: consecutive_sync_failures → 0,
 *     store status → 'active' ONLY if it was previously 'error'.
 *
 * Related: app/Services/Integrations/WooCommerce/WooCommerceConnector.php (syncRefunds)
 * Related: app/Models/Refund.php
 */
class SyncRecentRefundsJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout   = 120;
    public int $tries     = 3;
    public int $uniqueFor = 150;

    /** @var int[] */
    public array $backoff = [60, 300, 900];

    public function uniqueId(): string
    {
        return (string) $this->storeId;
    }

    public function __construct(
        private readonly int $storeId,
        private readonly int $workspaceId,
    ) {
        $this->onQueue('sync-store');
    }

    public function handle(): void
    {
        app(WorkspaceContext::class)->set($this->workspaceId);

        $store = Store::find($this->storeId);

        if ($store === null || $store->status !== 'active') {
            return;
        }

        $syncLog = SyncLog::create([
            'workspace_id'      => $this->workspaceId,
            'syncable_type'     => Store::class,
            'syncable_id'       => $this->storeId,
            'job_type'          => self::class,
            'status'            => 'running',
            'records_processed' => 0,
            'started_at'        => now(),
            'queue'             => $this->queue,
            'attempt'           => $this->attempts(),
            'timeout_seconds'   => $this->timeout,
        ]);

        try {
            $connector = new WooCommerceConnector($store);

            // Why 7 days: covers late-issued refunds and webhook delivery gaps.
            $count = $connector->syncRefunds(now()->subDays(7));

            $syncLog->update([
                'status'            => 'completed',
                'records_processed' => $count,
                'completed_at'      => now(),
                'duration_seconds'  => (int) max(0, (int) now()->diffInSeconds($syncLog->started_at)),
            ]);

            $store->update(['last_synced_at' => now()]);
            $this->onSuccess($store);
        } catch (WooCommerceRateLimitException $e) {
            // Re-queue without consuming a retry attempt.
            $this->release($e->retryAfter);
            return;
        } catch (\Throwable $e) {
            $syncLog->update([
                'status'           => 'failed',
                'error_message'    => mb_substr($e->getMessage(), 0, 500),
                'completed_at'     => now(),
                'duration_seconds' => (int) max(0, (int) now()->diffInSeconds($syncLog->started_at)),
            ]);

            Log::error('SyncRecentRefundsJob: sync failed', [
                'store_id' => $this->storeId,
                'error'    => $e->getMessage(),
            ]);

            throw $e; // Horizon handles retries
        }
    }

    /**
     * Called by Laravel after ALL retries are exhausted.
     * Increments failure counters and creates an alert.
     */
    public function failed(\Throwable $exception): void
    {
        try {
            app(WorkspaceContext::class)->set($this->workspaceId);

            // Close any orphaned running sync logs for this store.
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

            $store = Store::find($this->storeId);

            if ($store === null) {
                return;
            }

            $failures = $store->consecutive_sync_failures + 1;
            $updates  = ['consecutive_sync_failures' => $failures];

            if ($failures >= 3) {
                $updates['status'] = 'error';
            }

            $store->update($updates);

            Alert::create([
                'workspace_id' => $this->workspaceId,
                'store_id'     => $this->storeId,
                'type'         => 'sync_failure',
                'severity'     => $failures >= 3 ? 'critical' : 'warning',
                'data'         => [
                    'job'      => 'SyncRecentRefundsJob',
                    'failures' => $failures,
                    'error'    => mb_substr($exception->getMessage(), 0, 255),
                ],
            ]);
        } catch (\Throwable $e) {
            Log::error('SyncRecentRefundsJob::failed(): could not record failure state', [
                'store_id' => $this->storeId,
                'error'    => $e->getMessage(),
            ]);
        }
    }

    /**
     * Reset failure state after a successful sync.
     */
    private function onSuccess(Store $store): void
    {
        $updates = ['consecutive_sync_failures' => 0];

        // Restore to 'active' ONLY if the store was previously in 'error' state.
        // Never restore a 'disconnected' store automatically.
        if ($store->status === 'error') {
            $updates['status'] = 'active';
        }

        $store->update($updates);
    }
}
