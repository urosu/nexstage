<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Exceptions\ShopifyException;
use App\Models\Alert;
use App\Models\Store;
use App\Models\SyncLog;
use App\Models\Workspace;
use App\Services\Integrations\Shopify\ShopifyConnector;
use App\Services\WorkspaceContext;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * On-demand / scheduled order sync for a single Shopify store.
 *
 * Queue:   sync-store
 * Timeout: 120 s
 * Tries:   3
 * Backoff: [60, 300, 900] s
 *
 * Behaviour:
 *   - When $force=false (scheduler): skips if webhooks delivered within 90 min
 *     (PollShopifyOrdersJob handles the webhook-healthy case).
 *   - When $force=true (manual / on-demand): always fetches regardless.
 *   - Default window: orders updated in the last 2 hours.
 *
 * Historical imports use ShopifyHistoricalImportJob (7200 s, 30-day chunks).
 *
 * Failure handling (consecutive_sync_failures counter on stores table):
 *   - First failure: warning alert.
 *   - Three+ consecutive failures: critical alert + store status → 'error'.
 *   - Success resets counter to 0; status → 'active' only if it was 'error'.
 *
 * Related: app/Jobs/SyncStoreOrdersJob.php (WooCommerce equivalent)
 * See: PLANNING.md "Phase 2 — Shopify" Step 7
 */
class SyncShopifyOrdersJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 120;
    public int $tries   = 3;

    /** @var array<int, int> */
    public array $backoff = [60, 300, 900];

    public function __construct(
        private readonly int     $storeId,
        private readonly int     $workspaceId,
        private readonly bool    $force = false,
        private readonly ?Carbon $since = null,
    ) {
        $this->onQueue('sync-store');
    }

    public function handle(): void
    {
        app(WorkspaceContext::class)->set($this->workspaceId);

        if ($this->isWorkspaceFrozen()) {
            Log::info('SyncShopifyOrdersJob: skipped — workspace trial expired', [
                'workspace_id' => $this->workspaceId,
            ]);
            return;
        }

        $store = Store::find($this->storeId);

        if ($store === null || $store->status !== 'active') {
            return;
        }

        if (! $this->force && $this->webhooksAreArriving()) {
            Log::info('SyncShopifyOrdersJob: webhooks healthy, skipping sync', [
                'store_id' => $this->storeId,
            ]);
            $store->update(['last_synced_at' => now()]);
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
            $connector  = new ShopifyConnector($store);
            $sinceDate  = $this->since ?? Carbon::now()->subHours(2);
            $count      = $connector->syncOrders($sinceDate);

            $syncLog->update([
                'status'            => 'completed',
                'records_processed' => $count,
                'completed_at'      => now(),
                'duration_seconds'  => (int) max(0, (int) now()->diffInSeconds($syncLog->started_at)),
            ]);

            $store->update([
                'last_synced_at'            => now(),
                'consecutive_sync_failures' => 0,
            ]);

            if ($store->status === 'error') {
                $store->update(['status' => 'active']);
            }

            Log::info('SyncShopifyOrdersJob: completed', [
                'store_id' => $this->storeId,
                'orders'   => $count,
            ]);
        } catch (\Throwable $e) {
            $syncLog->update([
                'status'           => 'failed',
                'error_message'    => mb_substr($e->getMessage(), 0, 500),
                'completed_at'     => now(),
                'duration_seconds' => (int) max(0, (int) now()->diffInSeconds($syncLog->started_at)),
            ]);

            $this->handleFailure($store, $e);

            throw $e;
        }
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    private function webhooksAreArriving(): bool
    {
        return \Illuminate\Support\Facades\DB::table('store_webhooks')
            ->where('store_id', $this->storeId)
            ->whereNull('deleted_at')
            ->where('last_successful_delivery_at', '>=', now()->subMinutes(90))
            ->limit(1)
            ->exists();
    }

    private function handleFailure(Store $store, \Throwable $e): void
    {
        $failures = ($store->consecutive_sync_failures ?? 0) + 1;

        $store->update(['consecutive_sync_failures' => $failures]);

        $severity = $failures >= 3 ? 'critical' : 'warning';

        Alert::create([
            'workspace_id' => $this->workspaceId,
            'store_id'     => $this->storeId,
            'type'         => 'sync_failure',
            'severity'     => $severity,
            'message'      => "Shopify order sync failed (attempt {$failures}): " . mb_substr($e->getMessage(), 0, 300),
            'context'      => ['job' => 'SyncShopifyOrdersJob', 'store_id' => $this->storeId],
        ]);

        if ($failures >= 3 && $store->status === 'active') {
            $store->update(['status' => 'error']);
        }

        Log::error('SyncShopifyOrdersJob: sync failed', [
            'store_id' => $this->storeId,
            'failures' => $failures,
            'error'    => $e->getMessage(),
        ]);
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
