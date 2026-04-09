<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Actions\UpsertWooCommerceOrderAction;
use App\Exceptions\WooCommerceConnectionException;
use App\Exceptions\WooCommerceRateLimitException;
use App\Models\Alert;
use App\Models\Store;
use App\Models\SyncLog;
use App\Models\WebhookLog;
use App\Models\Workspace;
use App\Scopes\WorkspaceScope;
use App\Services\Integrations\WooCommerce\WooCommerceClient;
use App\Services\WorkspaceContext;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Hourly fallback sync for a single WooCommerce store.
 *
 * Queue:   default
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
 */
class SyncStoreOrdersJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 120;
    public int $tries   = 3;

    public function __construct(
        private readonly int  $storeId,
        private readonly int  $workspaceId,
        private readonly bool $force = false,
    ) {
        $this->onQueue('default');
    }

    public function handle(UpsertWooCommerceOrderAction $action): void
    {
        app(WorkspaceContext::class)->set($this->workspaceId);

        $store = Store::find($this->storeId);

        if ($store === null || $store->status !== 'active') {
            return;
        }

        // Skip if webhooks are arriving — they keep the data fresh.
        // Force mode (manual sync trigger) bypasses this check.
        if (!$this->force && $this->webhooksAreArriving($store->id)) {
            Log::info('SyncStoreOrdersJob: webhooks are active, skipping API fallback', [
                'store_id' => $this->storeId,
            ]);
            return;
        }

        $syncLog = SyncLog::create([
            'workspace_id'      => $this->workspaceId,
            'syncable_type'     => Store::class,
            'syncable_id'       => $this->storeId,
            'job_type'          => 'SyncStoreOrdersJob',
            'status'            => 'running',
            'records_processed' => 0,
            'started_at'        => now(),
        ]);

        try {
            $count = $this->fetchAndUpsertRecentOrders($store, $action);

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
                'status'        => 'failed',
                'error_message' => mb_substr($e->getMessage(), 0, 500),
                'completed_at'  => now(),
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

            // Close any orphaned running sync logs for this store (guard against
            // MaxAttemptsExceededException firing before handle() creates a new log).
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

            $updates = ['consecutive_sync_failures' => $failures];

            if ($failures >= 3) {
                $updates['status'] = 'error';
            }

            $store->update($updates);

            $severity = $failures >= 3 ? 'critical' : 'warning';

            Alert::create([
                'workspace_id' => $this->workspaceId,
                'store_id'     => $this->storeId,
                'type'         => 'sync_failure',
                'severity'     => $severity,
                'data'         => [
                    'job'       => 'SyncStoreOrdersJob',
                    'failures'  => $failures,
                    'error'     => mb_substr($exception->getMessage(), 0, 255),
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
        // Use DB::table() to bypass WorkspaceScope — WebhookLog has it applied
        // but we only need a quick existence check on a single store_id.
        return DB::table('webhook_logs')
            ->where('store_id', $storeId)
            ->where('created_at', '>=', now()->subMinutes(90))
            ->limit(1)
            ->exists();
    }

    /**
     * Fetch orders modified in the last 2 hours and upsert each one.
     *
     * @return int Number of orders processed.
     */
    private function fetchAndUpsertRecentOrders(Store $store, UpsertWooCommerceOrderAction $action): int
    {
        $consumerKey    = Crypt::decryptString($store->auth_key_encrypted);
        $consumerSecret = Crypt::decryptString($store->auth_secret_encrypted);

        $client = new WooCommerceClient(
            domain:         $store->domain,
            consumerKey:    $consumerKey,
            consumerSecret: $consumerSecret,
        );

        $modifiedAfter = now()->subHours(2)->utc()->toIso8601String();
        $orders        = $client->fetchModifiedOrders($modifiedAfter);

        $workspace          = Workspace::find($this->workspaceId);
        $reportingCurrency  = $workspace?->reporting_currency ?? 'EUR';

        foreach ($orders as $wcOrder) {
            $action->handle($store, $reportingCurrency, $wcOrder);
        }

        return count($orders);
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
