<?php

declare(strict_types=1);

namespace App\Jobs;

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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Hourly polling fallback for a single Shopify store.
 *
 * Queue:   sync-store
 * Timeout: 120 s
 * Tries:   3
 *
 * This job is dispatched every hour per active Shopify store.
 * It exists to catch orders missed by webhook delivery failures.
 *
 * Decision logic:
 *   1. Check store_webhooks.last_successful_delivery_at for any registered topic.
 *   2. If any topic received a delivery within the last 90 minutes → skip
 *      (ProcessShopifyWebhookJob is keeping data fresh via UpsertShopifyOrderAction).
 *   3. If all topics are stale (>90 min) → poll last 2 h via ShopifyConnector::syncOrders().
 *
 * Rate-limit / throttle handling: ShopifyGraphQlClient's leaky-bucket logic sleeps
 * internally when points run low — no release() needed here. If the job still exceeds
 * timeout, Horizon retries normally.
 *
 * Related: app/Jobs/PollStoreOrdersJob.php (WooCommerce equivalent)
 * Related: app/Jobs/SyncShopifyOrdersJob.php (on-demand / force-sync)
 * See: PLANNING.md "Phase 2 — Shopify" Step 7
 */
class PollShopifyOrdersJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 120;
    public int $tries   = 3;

    /**
     * Webhook freshness threshold. Same 90-minute window used by PollStoreOrdersJob.
     * @see PLANNING.md "Polling fallback"
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
            Log::info('PollShopifyOrdersJob: skipped — workspace trial expired', [
                'workspace_id' => $this->workspaceId,
            ]);
            return;
        }

        $store = Store::find($this->storeId);

        if ($store === null || $store->status !== 'active') {
            return;
        }

        if ($this->webhooksAreFresh()) {
            Log::info('PollShopifyOrdersJob: webhooks healthy, skipping API poll', [
                'store_id' => $this->storeId,
            ]);
            $store->update(['last_synced_at' => now()]);
            return;
        }

        Log::info('PollShopifyOrdersJob: webhooks stale, polling Shopify GraphQL', [
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
            $connector = new ShopifyConnector($store);
            $since     = Carbon::now()->subHours(2);
            $count     = $connector->syncOrders($since);

            $syncLog->update([
                'status'            => 'completed',
                'records_processed' => $count,
                'completed_at'      => now(),
                'duration_seconds'  => (int) max(0, (int) now()->diffInSeconds($syncLog->started_at)),
            ]);

            $store->update(['last_synced_at' => now()]);

            Log::info('PollShopifyOrdersJob: poll completed', [
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

            Log::error('PollShopifyOrdersJob: poll failed', [
                'store_id' => $this->storeId,
                'error'    => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    private function webhooksAreFresh(): bool
    {
        return DB::table('store_webhooks')
            ->where('store_id', $this->storeId)
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
