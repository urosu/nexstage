<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Exceptions\WooCommerceRateLimitException;
use App\Models\Alert;
use App\Models\Store;
use App\Models\SyncLog;
use App\Models\WebhookLog;
use App\Models\Workspace;
use App\Services\Integrations\WooCommerce\WooCommerceConnector;
use App\Services\WorkspaceContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Hourly fallback sync for a single WooCommerce store.
 *
 * Queue:   sync-store
 * Timeout: 120 s
 * Tries:   3
 * Backoff: [60, 300, 900] s (default)
 *
 * Behaviour:
 *   - If recent webhook deliveries exist (within the last 90 minutes), the
 *     job skips the API call — webhooks are keeping data fresh.
 *   - If no recent webhooks are found, it fetches all orders modified in the
 *     last 2 hours via the WooCommerce REST API and upserts them.
 *
 * Failure handling (applied once per dispatch, after all retries exhausted):
 *   - Increments consecutive_sync_failures.
 *   - Creates an alert (warning at first failure, critical at 3+).
 *   - Sets store status to 'error' at 3+ consecutive failures.
 *   - Resets on success: consecutive_sync_failures → 0,
 *     store status → 'active' ONLY if it was previously 'error'.
 *
 * Related: app/Services/Integrations/WooCommerce/WooCommerceConnector.php
 */
class SyncStoreOrdersJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout   = 120;
    public int $tries     = 3;
    public int $uniqueFor = 150;

    public function uniqueId(): string
    {
        return (string) $this->storeId;
    }

    public function __construct(
        private readonly int  $storeId,
        private readonly int  $workspaceId,
        private readonly bool $force = false,
    ) {
        $this->onQueue('sync-store');
    }

    public function handle(): void
    {
        app(WorkspaceContext::class)->set($this->workspaceId);

        // Discard jobs queued before trial expiry. Scheduler already filters frozen
        // workspaces on dispatch; this guard handles jobs already in the queue.
        if ($this->isWorkspaceFrozen()) {
            Log::info('SyncStoreOrdersJob: skipped — workspace trial expired', ['workspace_id' => $this->workspaceId]);
            return;
        }

        $store = Store::find($this->storeId);

        if ($store === null || $store->status !== 'active') {
            return;
        }

        // Skip if webhooks are arriving — they keep the data fresh.
        // Force mode (manual sync trigger) bypasses this check.
        if (! $this->force && $this->webhooksAreArriving($store->id)) {
            Log::info('SyncStoreOrdersJob: webhooks are active, skipping API fallback', [
                'store_id' => $this->storeId,
            ]);
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
            $since     = now()->subHours(2);
            $count     = $connector->syncOrders($since);

            $syncLog->update([
                'status'            => 'completed',
                'records_processed' => $count,
                'completed_at'      => now(),
                'duration_seconds'  => (int) max(0, (int) now()->diffInSeconds($syncLog->started_at)),
            ]);

            $store->update(['last_synced_at' => now()]);
            $this->onSuccess($store);
        } catch (WooCommerceRateLimitException $e) {
            // Why: release() increments the attempt counter, so 3 rate-limit hits exhaust
            // tries=3 and trigger failed(), falsely incrementing consecutive_sync_failures.
            // Dispatching a fresh job resets the counter so rate limits never pollute store health.
            $syncLog->update([
                'status'           => 'failed',
                'error_message'    => "Rate limited — retrying after {$e->retryAfter}s",
                'completed_at'     => now(),
                'duration_seconds' => (int) max(0, (int) now()->diffInSeconds($syncLog->started_at)),
            ]);
            self::dispatch($this->storeId, $this->workspaceId)
                ->delay(now()->addSeconds($e->retryAfter));
            $this->delete();
            return;
        } catch (\Throwable $e) {
            $syncLog->update([
                'status'           => 'failed',
                'error_message'    => mb_substr($e->getMessage(), 0, 500),
                'completed_at'     => now(),
                'duration_seconds' => (int) max(0, (int) now()->diffInSeconds($syncLog->started_at)),
            ]);

            Log::error('SyncStoreOrdersJob: sync failed', [
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
                ->where('job_type', self::class)
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
                    'job'      => 'SyncStoreOrdersJob',
                    'failures' => $failures,
                    'error'    => mb_substr($exception->getMessage(), 0, 255),
                ],
            ]);
        } catch (\Throwable $e) {
            Log::error('SyncStoreOrdersJob::failed(): could not record failure state', [
                'store_id' => $this->storeId,
                'error'    => $e->getMessage(),
            ]);
        }
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Returns true if any webhook was delivered to this store in the last 90 minutes.
     */
    private function webhooksAreArriving(int $storeId): bool
    {
        // Use DB::table() to bypass WorkspaceScope — only need a quick existence check.
        return DB::table('webhook_logs')
            ->where('store_id', $storeId)
            ->where('created_at', '>=', now()->subMinutes(90))
            ->limit(1)
            ->exists();
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

    private function isWorkspaceFrozen(): bool
    {
        $workspace = Workspace::withoutGlobalScopes()
            ->select(['id', 'trial_ends_at', 'billing_plan'])
            ->find($this->workspaceId);

        return $workspace !== null
            && $workspace->trial_ends_at !== null
            && $workspace->trial_ends_at->lt(now())
            && $workspace->billing_plan === null;
    }
}
