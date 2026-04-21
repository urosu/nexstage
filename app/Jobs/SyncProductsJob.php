<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Exceptions\WooCommerceRateLimitException;
use App\Models\Alert;
use App\Models\Store;
use App\Models\SyncLog;
use App\Models\Workspace;
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
 * Nightly product sync for a single WooCommerce store.
 *
 * Queue:   sync-store
 * Timeout: 300 s
 * Tries:   3
 * Backoff: default [60, 300, 900] s
 *
 * Always fetches all products (full sync — no cursor). Products are a small
 * dataset for SMBs; full re-upsert nightly is cheaper than cursor management.
 *
 * Scheduled daily at 02:00 UTC. Dispatched per active WooCommerce store via
 * a closure in routes/console.php.
 *
 * Related: app/Services/Integrations/WooCommerce/WooCommerceConnector.php
 */
class SyncProductsJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout   = 300;
    public int $tries     = 3;
    public int $uniqueFor = 330;

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

        // Discard jobs queued before trial expiry.
        if ($this->isWorkspaceFrozen()) {
            Log::info('SyncProductsJob: skipped — workspace trial expired', ['workspace_id' => $this->workspaceId]);
            return;
        }

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
            $count     = $connector->syncProducts();

            $syncLog->update([
                'status'            => 'completed',
                'records_processed' => $count,
                'completed_at'      => now(),
                'duration_seconds'  => (int) max(0, (int) now()->diffInSeconds($syncLog->started_at)),
            ]);

            // Why: last_synced_at is owned by SyncStoreOrdersJob (order sync cursor).
            // SyncProductsJob always does a full sync and does not move that cursor.
            $this->onSuccess($store);

            Log::info('SyncProductsJob: completed', [
                'store_id' => $this->storeId,
                'count'    => $count,
            ]);
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

            Log::error('SyncProductsJob: sync failed', [
                'store_id' => $this->storeId,
                'error'    => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    public function failed(\Throwable $exception): void
    {
        try {
            app(WorkspaceContext::class)->set($this->workspaceId);

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
                    'job'      => 'SyncProductsJob',
                    'failures' => $failures,
                    'error'    => mb_substr($exception->getMessage(), 0, 255),
                ],
            ]);
        } catch (\Throwable $e) {
            Log::error('SyncProductsJob::failed(): could not record failure state', [
                'store_id' => $this->storeId,
                'error'    => $e->getMessage(),
            ]);
        }
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    private function onSuccess(Store $store): void
    {
        $updates = ['consecutive_sync_failures' => 0];

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
