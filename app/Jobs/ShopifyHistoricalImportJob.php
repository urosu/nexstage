<?php

declare(strict_types=1);

namespace App\Jobs;

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
 * Imports the full order history for a Shopify store.
 *
 * Queue:   imports
 * Timeout: 7200 s (2 hours)
 * Tries:   3
 *
 * Design decisions:
 *  - 30-day chunks (newest → oldest) prevent timeout and keep Shopify query costs low.
 *  - Checkpoint (stores.historical_import_checkpoint) records the current chunk end date
 *    so retries resume rather than restart.
 *  - FX rates for each chunk are prefetched synchronously (same pattern as WooCommerce).
 *  - Progress (0–99) is written after every chunk. 100 is written only on completion.
 *  - On completion, one ComputeDailySnapshotJob is dispatched synchronously per imported
 *    date so the dashboard has data immediately when the user is redirected.
 *
 * Billing gate:
 *  If the workspace trial has expired and there is no billing plan, the job sets
 *  historical_import_status = 'failed' without throwing, preventing Horizon retries.
 *
 * @see PLANNING.md "Phase 2 — Shopify"
 */
class ShopifyHistoricalImportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 7200;
    public int $tries   = 3;

    public function __construct(
        private readonly int  $storeId,
        private readonly int  $workspaceId,
        private ?int          $syncLogId = null,
    ) {
        $this->onQueue('imports');
    }

    public function handle(): void
    {
        app(WorkspaceContext::class)->set($this->workspaceId);

        $store = Store::find($this->storeId);

        if ($store === null) {
            return;
        }

        $workspace = Workspace::withoutGlobalScopes()
            ->select(['id', 'trial_ends_at', 'billing_plan'])
            ->find($this->workspaceId);

        if ($workspace !== null && $this->isBillingExpired($workspace)) {
            $store->update(['historical_import_status' => 'failed']);

            $this->resolveSyncLog(Store::class, $this->storeId, [
                'status'            => 'failed',
                'records_processed' => 0,
                'error_message'     => 'Import paused — subscription required.',
                'started_at'        => now(),
                'completed_at'      => now(),
                'duration_seconds'  => 0,
            ]);

            Log::warning('ShopifyHistoricalImportJob: billing expired, import blocked', [
                'store_id'     => $this->storeId,
                'workspace_id' => $this->workspaceId,
            ]);

            return;
        }

        // Fall back to 2010-01-01 when the user chose "all available data".
        // Shopify itself is the effective lower bound; we'll just get an empty
        // cursor for any period before the store's first order.
        $importFrom = $store->historical_import_from ?? '2010-01-01';

        $store->update([
            'historical_import_status'     => 'running',
            'historical_import_started_at' => $store->historical_import_started_at ?? now(),
        ]);

        $syncLog = $this->resolveSyncLog(Store::class, $this->storeId, [
            'status'            => 'running',
            'records_processed' => 0,
            'started_at'        => now(),
        ]);

        try {
            $importPhaseStart = microtime(true);
            $totalImported    = $this->runImport($store, Carbon::parse($importFrom), $syncLog);
            $importDuration   = microtime(true) - $importPhaseStart;

            $store->refresh();

            // Compute snapshots synchronously before marking complete — the dashboard
            // reads from daily_snapshots, not raw orders. Marking 'completed' first
            // would show an empty dashboard until the async jobs finish.
            $this->dispatchSnapshotJobs(Carbon::parse($importFrom), $store, $importDuration);

            $store->update([
                'historical_import_status'           => 'completed',
                'historical_import_progress'         => 100,
                'historical_import_checkpoint'       => null,
                'historical_import_completed_at'     => now(),
                'historical_import_duration_seconds' => (int) now()->diffInSeconds(
                    $store->historical_import_started_at ?? now()
                ),
                'last_synced_at' => now(),
            ]);

            $syncLog->update([
                'status'            => 'completed',
                'records_processed' => $totalImported,
                'completed_at'      => now(),
                'duration_seconds'  => (int) max(0, (int) now()->diffInSeconds($syncLog->started_at)),
            ]);

            Log::info('ShopifyHistoricalImportJob: completed', [
                'store_id'       => $this->storeId,
                'total_imported' => $totalImported,
            ]);
        } catch (\Throwable $e) {
            $store->update(['historical_import_status' => 'failed']);

            $syncLog->update([
                'status'           => 'failed',
                'error_message'    => mb_substr($e->getMessage(), 0, 500),
                'completed_at'     => now(),
                'duration_seconds' => (int) max(0, (int) now()->diffInSeconds($syncLog->started_at)),
            ]);

            Alert::create([
                'workspace_id' => $this->workspaceId,
                'store_id'     => $this->storeId,
                'type'         => 'import_failed',
                'severity'     => 'warning',
                'data'         => [
                    'job'   => self::class,
                    'error' => mb_substr($e->getMessage(), 0, 255),
                ],
            ]);

            Log::error('ShopifyHistoricalImportJob: failed', [
                'store_id' => $this->storeId,
                'error'    => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Iterate 30-day chunks from yesterday back to $importFrom, calling
     * ShopifyConnector::syncOrders() for each window. Writes checkpoint + progress
     * after every chunk.
     *
     * Why newest → oldest: if an error interrupts the import, the most recent orders
     * (which the dashboard shows first) are already in the DB.
     *
     * Progress is chunk-based (no order count pre-fetch — Shopify GraphQL cost is
     * prohibitive). Each completed chunk advances the bar proportionally.
     *
     * @return int Total orders upserted across all chunks.
     */
    private function runImport(Store $store, Carbon $importFrom, SyncLog $syncLog): int
    {
        $importTo    = Carbon::yesterday()->endOfDay();
        $totalChunks = max(1, (int) ceil($importFrom->diffInDays($importTo) / 30));

        if ($importFrom->gt($importTo)) {
            return 0;
        }

        $connector = new ShopifyConnector($store);

        $workspace         = Workspace::withoutGlobalScopes()->find($this->workspaceId);
        $reportingCurrency = $workspace?->reporting_currency ?? 'EUR';

        $needsFx = $store->currency !== null && $store->currency !== $reportingCurrency;

        // Resume from checkpoint on retry.
        $checkpoint = $store->historical_import_checkpoint;
        $chunkEnd   = isset($checkpoint['date_cursor'])
            ? Carbon::parse($checkpoint['date_cursor'])->endOfDay()
            : $importTo->copy();

        $chunksCompleted = 0;
        $totalImported   = 0;

        while ($chunkEnd->gte($importFrom)) {
            $chunkStart = $chunkEnd->copy()->subDays(29)->startOfDay();

            if ($chunkStart->lt($importFrom)) {
                $chunkStart = $importFrom->copy()->startOfDay();
            }

            if ($needsFx) {
                UpdateFxRatesJob::dispatchSync($chunkStart->copy(), $chunkEnd->copy());
            }

            $count = $connector->syncOrders($chunkStart, $chunkEnd);
            $totalImported += $count;
            $chunksCompleted++;

            $progress = (int) min(99, round(($chunksCompleted / $totalChunks) * 99));

            $store->update([
                'historical_import_checkpoint' => ['date_cursor' => $chunkEnd->toDateString()],
                'historical_import_progress'   => $progress,
            ]);

            $syncLog->update(['records_processed' => $totalImported]);

            $chunkEnd->subDays(30);
        }

        return $totalImported;
    }

    /**
     * Run ComputeDailySnapshotJob synchronously for every date in the imported range,
     * advancing historical_import_progress from wherever import left off to 99.
     *
     * Throttled to at most ~20 DB writes regardless of range size.
     * Progress never goes backward.
     */
    private function dispatchSnapshotJobs(Carbon $importFrom, Store $store, float $importDuration): void
    {
        $cursor          = $importFrom->copy()->startOfDay();
        $end             = Carbon::yesterday()->startOfDay();
        $totalDates      = max(1, (int) $importFrom->diffInDays($end) + 1);
        $done            = 0;
        $phaseStart      = microtime(true);
        $currentProgress = (int) ($store->historical_import_progress ?? 0);

        $writeEvery = max(1, (int) ($totalDates / 20));

        while ($cursor->lte($end)) {
            ComputeDailySnapshotJob::dispatchSync($this->storeId, $cursor->copy());
            $done++;

            $snapshotElapsed        = microtime(true) - $phaseStart;
            $snapshotRate           = $snapshotElapsed / $done;
            $estimatedSnapshotTotal = $snapshotRate * $totalDates;
            $totalEstimate          = $importDuration + $estimatedSnapshotTotal;
            $importFrac             = $totalEstimate > 0
                ? ($importDuration / $totalEstimate)
                : 0.80;
            $importFrac             = max(0.50, min(0.92, $importFrac));
            $importBoundary         = (int) round($importFrac * 99);

            $newProgress = max(
                $currentProgress,
                min(99, $importBoundary + (int) round(($done / $totalDates) * (99 - $importBoundary))),
            );

            if ($newProgress > $currentProgress && ($done % $writeEvery === 0 || $done === $totalDates)) {
                $currentProgress = $newProgress;
                $store->update(['historical_import_progress' => $newProgress]);
            }

            $cursor->addDay();
        }
    }

    private function isBillingExpired(Workspace $workspace): bool
    {
        return $workspace->trial_ends_at !== null
            && $workspace->trial_ends_at->lt(now())
            && $workspace->billing_plan === null;
    }

    /**
     * Finds and updates the pre-created queued sync log, or creates a new one.
     *
     * @param array<string, mixed> $fields
     */
    private function resolveSyncLog(string $syncableType, int $syncableId, array $fields): SyncLog
    {
        if ($this->syncLogId !== null) {
            $log = SyncLog::withoutGlobalScopes()->find($this->syncLogId);
            if ($log !== null) {
                $log->update(['attempt' => $this->attempts(), ...$fields]);
                return $log;
            }
        }

        return SyncLog::create([
            'workspace_id'    => $this->workspaceId,
            'syncable_type'   => $syncableType,
            'syncable_id'     => $syncableId,
            'job_type'        => self::class,
            'queue'           => $this->queue,
            'attempt'         => $this->attempts(),
            'timeout_seconds' => $this->timeout,
            ...$fields,
        ]);
    }
}
