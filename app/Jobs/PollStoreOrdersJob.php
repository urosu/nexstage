<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Exceptions\WooCommerceRateLimitException;
use App\Models\Store;
use App\Models\SyncLog;
use App\Models\Workspace;
use App\Services\Integrations\WooCommerce\WooCommerceConnector;
use App\Services\WorkspaceContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Hourly scheduled polling fallback for a single WooCommerce store.
 *
 * Queue:   sync-store
 * Timeout: 120 s
 * Tries:   3
 *
 * This job is dispatched by the scheduler every hour per active WooCommerce store.
 * It exists solely to catch orders that webhook delivery missed — it is NOT used
 * for on-demand or forced syncs (use SyncStoreOrdersJob with force=true for those).
 *
 * Decision logic:
 *   1. Check store_webhooks.last_successful_delivery_at for any registered topic.
 *   2. If any topic received a delivery within the last 90 minutes → skip (webhooks healthy).
 *   3. If all topics are stale (>90 min) or none are registered → poll last 2 h from WC API.
 *   4. Log the decision either way.
 *
 * Rate-limit handling: re-queues via $this->release() rather than consuming a retry.
 * No failure chain (no consecutive_sync_failures counter) — that lives in SyncStoreOrdersJob.
 *
 * Triggered by: routes/console.php (hourly schedule)
 * Related: app/Jobs/SyncStoreOrdersJob.php (on-demand forced sync)
 * Related: app/Jobs/ReconcileStoreOrdersJob.php (nightly deep check with hard-delete)
 * See: PLANNING.md "Polling fallback"
 */
class PollStoreOrdersJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 120;
    public int $tries   = 3;

    /**
     * Webhook freshness threshold in minutes.
     * Mirrors the 90-minute value in PLANNING.md "Polling fallback" section.
     */
    private const WEBHOOK_STALE_MINUTES = 90;

    public function __construct(
        private readonly int $storeId,
        private readonly int $workspaceId,
    ) {
        $this->onQueue('sync-store');
    }

    public function handle(): void
    {
        app(WorkspaceContext::class)->set($this->workspaceId);

        if ($this->isWorkspaceFrozen()) {
            Log::info('PollStoreOrdersJob: skipped — workspace trial expired', [
                'workspace_id' => $this->workspaceId,
            ]);
            return;
        }

        $store = Store::find($this->storeId);

        if ($store === null || $store->status !== 'active') {
            return;
        }

        if ($this->webhooksAreFresh($this->storeId)) {
            Log::info('PollStoreOrdersJob: webhooks healthy, skipping API poll', [
                'store_id' => $this->storeId,
            ]);
            // Webhooks are delivering, so store data is current — touch last_synced_at
            // so the DataFreshness indicator stays green even when no API poll runs.
            $store->update(['last_synced_at' => now()]);
            return;
        }

        Log::info('PollStoreOrdersJob: webhooks stale, polling WC API', [
            'store_id' => $this->storeId,
        ]);

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

            Log::info('PollStoreOrdersJob: poll completed', [
                'store_id' => $this->storeId,
                'orders'   => $count,
            ]);
        } catch (WooCommerceRateLimitException $e) {
            // Close the log so it doesn't stay stuck at 'running', then re-queue
            // without burning a retry attempt (release() would increment attempts).
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

            Log::error('PollStoreOrdersJob: poll failed', [
                'store_id' => $this->storeId,
                'error'    => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Called by Laravel after all retry attempts are exhausted.
     * Closes any orphaned 'running' sync logs so the UI never shows a stuck spinner.
     * PollStoreOrdersJob intentionally has no consecutive_sync_failures chain — that
     * lives in SyncStoreOrdersJob (on-demand / forced syncs).
     */
    public function failed(\Throwable $exception): void
    {
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
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Returns true if any registered webhook topic received a successful delivery
     * within the last 90 minutes.
     *
     * Checks store_webhooks.last_successful_delivery_at (stamped by ProcessWebhookJob
     * on every successful delivery) rather than webhook_logs — this tracks confirmed
     * successful deliveries only.
     */
    private function webhooksAreFresh(int $storeId): bool
    {
        return DB::table('store_webhooks')
            ->where('store_id', $storeId)
            ->whereNull('deleted_at')
            ->where('last_successful_delivery_at', '>=', now()->subMinutes(self::WEBHOOK_STALE_MINUTES))
            ->limit(1)
            ->exists();
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
