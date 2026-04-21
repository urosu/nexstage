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
use Illuminate\Support\Facades\Log;

/**
 * Nightly refund sync for a single Shopify store (last 7 days).
 *
 * Queue:   sync-store
 * Timeout: 120 s
 * Tries:   3
 * Backoff: [60, 300, 900] s
 *
 * Fetches refunds for all Shopify orders updated in the last 7 days via GraphQL
 * and upserts them into the refunds table. Updates parent orders' refund_amount
 * and last_refunded_at. Shopify exposes refunds nested under orders — the
 * connector iterates orders and extracts their refunds.
 *
 * ShopifyConnector::syncRefunds(since) handles the pagination and upsert logic.
 *
 * Scheduled: daily at 03:30 UTC per active Shopify store.
 *
 * Related: app/Jobs/SyncRecentRefundsJob.php (WooCommerce equivalent)
 * See: PLANNING.md "Phase 2 — Shopify" Step 7
 */
class SyncShopifyRefundsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 120;
    public int $tries   = 3;

    /** @var array<int, int> */
    public array $backoff = [60, 300, 900];

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
            Log::info('SyncShopifyRefundsJob: skipped — workspace trial expired', [
                'workspace_id' => $this->workspaceId,
            ]);
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
            $connector = new ShopifyConnector($store);
            $since     = Carbon::now()->subDays(7);
            $count     = $connector->syncRefunds($since);

            $syncLog->update([
                'status'            => 'completed',
                'records_processed' => $count,
                'completed_at'      => now(),
                'duration_seconds'  => (int) max(0, (int) now()->diffInSeconds($syncLog->started_at)),
            ]);

            Log::info('SyncShopifyRefundsJob: completed', [
                'store_id' => $this->storeId,
                'refunds'  => $count,
            ]);
        } catch (\Throwable $e) {
            $syncLog->update([
                'status'           => 'failed',
                'error_message'    => mb_substr($e->getMessage(), 0, 500),
                'completed_at'     => now(),
                'duration_seconds' => (int) max(0, (int) now()->diffInSeconds($syncLog->started_at)),
            ]);

            Log::error('SyncShopifyRefundsJob: sync failed', [
                'store_id' => $this->storeId,
                'error'    => $e->getMessage(),
            ]);

            throw $e;
        }
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
