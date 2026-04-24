<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Actions\UpsertWooCommerceOrderAction;
use App\Exceptions\WooCommerceRateLimitException;
use App\Models\Alert;
use App\Models\Store;
use App\Models\SyncLog;
use App\Models\Workspace;
use App\Services\Integrations\WooCommerce\WooCommerceClient;
use App\Services\WorkspaceContext;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;

/**
 * Imports the full order history for a WooCommerce store.
 *
 * Queue:   imports-store
 * Timeout: 7200 s (2 hours)
 * Tries:   3
 * Backoff: default [60, 300, 900] s
 *
 * Design decisions:
 *  - 30-day chunks prevent timeouts and keep per-request payloads small.
 *  - Checkpoint (stores.historical_import_checkpoint) records the current
 *    chunk start date so retries resume rather than restart from scratch.
 *  - FX rates for the full date range are prefetched synchronously before the
 *    first order is processed. UpdateFxRatesJob checks its DB cache first and
 *    only calls Frankfurter for missing dates.
 *  - Progress (0–99) is written after every page. 100 is written only on
 *    successful completion.
 *  - On completion, one ComputeDailySnapshotJob is dispatched per imported
 *    date so dashboard data is immediately available.
 *
 * Billing gate:
 *  If the workspace trial has expired and there is no billing plan, the job
 *  sets historical_import_status = 'failed' and writes an explanatory
 *  sync_log row without throwing, so Horizon does not retry.
 *
 * Caller responsibility (controller/action before dispatching):
 *  - Set stores.historical_import_status = 'pending'
 *  - Set stores.historical_import_from = chosen start date
 *  - Optionally set historical_import_total_orders (used for progress %).
 *    StartHistoricalImportAction does this via fetchOrderCount() on a best-effort
 *    basis — if the count call fails, total_orders is null and progress stays
 *    indeterminate rather than blocking the import.
 */
class WooCommerceHistoricalImportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 7200;
    public int $tries   = 3;

    /**
     * Estimated wall-clock seconds to compute one snapshot date (4 DB aggregates).
     * Used as a bootstrap value when we have no measured snapshot rate yet.
     * Once the first date completes, dispatchSnapshotJobs() uses the real measured rate instead.
     */
    private const SNAPSHOT_SECS_PER_DATE = 0.05;

    public function __construct(
        private readonly int  $storeId,
        private readonly int  $workspaceId,
        private ?int          $syncLogId = null,
    ) {
        $this->onQueue('imports-store');
    }

    public function handle(UpsertWooCommerceOrderAction $action): void
    {
        app(WorkspaceContext::class)->set($this->workspaceId);

        $store = Store::find($this->storeId);

        if ($store === null) {
            return;
        }

        // Billing gate — checked at runtime so expiry during a long import is caught on retry.
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

            Log::warning('WooCommerceHistoricalImportJob: billing expired, import blocked', [
                'store_id'     => $this->storeId,
                'workspace_id' => $this->workspaceId,
            ]);

            return; // Do not rethrow — no Horizon retry desired for billing blocks.
        }

        // When historical_import_from is null the user chose "All available data".
        // Fall back to 2010-01-01 — before WooCommerce existed — so the API returns
        // its full history. The platform itself is the effective lower bound.
        $importFrom = $store->historical_import_from ?? '2010-01-01';

        // Preserve the original start time across retries.
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
            $totalImported    = $this->runImport($store, $action, Carbon::parse($importFrom), $syncLog, $importPhaseStart);
            $importDuration   = microtime(true) - $importPhaseStart;

            // Reload to get the updated historical_import_started_at.
            $store->refresh();

            // Compute daily_snapshots synchronously before marking the import complete.
            // Why: the dashboard reads from daily_snapshots, not raw orders. If we mark
            // status='completed' before snapshots exist, the user lands on an empty dashboard.
            // Running them inline (dispatchSync) adds ~10–30 s for a 1-year import but ensures
            // data is ready the moment the user is redirected. Jobs are idempotent — safe to
            // re-run on retry. See: PLANNING.md "Job Dispatch Chain"
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

            Log::info('WooCommerceHistoricalImportJob: completed', [
                'store_id'       => $this->storeId,
                'total_imported' => $totalImported,
            ]);
        } catch (\Throwable $e) {
            // Checkpoint is already written after each page, so the next retry
            // will resume from the last completed chunk.
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

            Log::error('WooCommerceHistoricalImportJob: failed', [
                'store_id' => $this->storeId,
                'error'    => $e->getMessage(),
            ]);

            throw $e; // Allow Horizon to retry up to $tries times.
        }
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Iterate 30-day chunks from yesterday back to $importFrom, paginating each
     * chunk until exhausted. Writes checkpoint + progress after every page.
     *
     * Why newest → oldest: if a rate-limit or error interrupts the import,
     * the most recent orders (which the dashboard shows) are already in the DB.
     * Checkpoint stores $chunkEnd so retries re-process the last chunk from its
     * end boundary (idempotent — all writes are upserts).
     *
     * Rate-limit exceptions re-queue the job (attempt count unchanged) and return
     * without counting against $tries.
     *
     * @return int Total orders upserted across all chunks.
     */
    private function runImport(
        Store                        $store,
        UpsertWooCommerceOrderAction $action,
        Carbon                       $importFrom,
        SyncLog                      $syncLog,
        float                        $phaseStartedAt,
    ): int {
        $importTo   = Carbon::yesterday()->startOfDay();
        $totalDates = max(1, (int) $importFrom->diffInDays($importTo) + 1);

        if ($importFrom->gt($importTo)) {
            return 0;
        }

        $consumerKey    = Crypt::decryptString($store->auth_key_encrypted);
        $consumerSecret = Crypt::decryptString($store->auth_secret_encrypted);

        $client = new WooCommerceClient(
            domain:         $store->domain,
            consumerKey:    $consumerKey,
            consumerSecret: $consumerSecret,
        );

        $workspace         = Workspace::withoutGlobalScopes()->find($this->workspaceId);
        $reportingCurrency = $workspace?->reporting_currency ?? 'EUR';

        // FX rates are only needed when the store currency differs from the reporting currency.
        // When they match (e.g. EUR store + EUR workspace) no conversion is needed at all.
        // If store currency is null (shouldn't happen after a proper connect), treat as
        // "no FX needed" — UpsertWooCommerceOrderAction will leave total_in_reporting_currency
        // NULL for any orders it cannot convert, and RetryMissingConversionJob will fix them.
        $needsFx = $store->currency !== null && $store->currency !== $reportingCurrency;

        // Resume from checkpoint when retrying after a failure.
        // Checkpoint stores the chunkEnd of the last processed chunk so the retry
        // re-processes that chunk (safe — upserts) before continuing backward.
        $checkpoint = $store->historical_import_checkpoint;
        $chunkEnd   = isset($checkpoint['date_cursor'])
            ? Carbon::parse($checkpoint['date_cursor'])->startOfDay()
            : $importTo->copy();

        $totalOrders   = (int) ($store->historical_import_total_orders ?? 0);
        $totalImported = 0;

        while ($chunkEnd->gte($importFrom)) {
            $chunkStart = $chunkEnd->copy()->subDays(29);

            if ($chunkStart->lt($importFrom)) {
                $chunkStart = $importFrom->copy();
            }

            // Prefetch FX for this chunk only — avoids fetching decades of rates upfront
            // and skips the API call entirely when currencies already match.
            if ($needsFx) {
                UpdateFxRatesJob::dispatchSync($chunkStart->copy(), $chunkEnd->copy());
            }

            $page = 1;

            do {
                try {
                    $result = $client->fetchHistoricalOrdersPage(
                        after:  $chunkStart->copy()->startOfDay()->utc()->toIso8601String(),
                        before: $chunkEnd->copy()->endOfDay()->utc()->toIso8601String(),
                        page:   $page,
                    );
                } catch (WooCommerceRateLimitException $e) {
                    // Re-queue without consuming a retry attempt; checkpoint is persisted.
                    $this->release($e->retryAfter ?? 60);
                    return $totalImported;
                }

                $orders     = $result['orders'];
                $totalPages = $result['total_pages'];

                foreach ($orders as $wcOrder) {
                    $action->handle($store, $reportingCurrency, $wcOrder);
                    $totalImported++;
                }

                // Adaptive import cap: estimate what fraction of total job time order import
                // represents based on current measured throughput and expected snapshot cost.
                //
                // Why adaptive: at first we have no data, so we use a conservative estimate.
                // As orders are processed the measured rate converges to reality, and the cap
                // adjusts so the bar accurately reflects what fraction of time import will use.
                //
                // estimatedImportTotal: extrapolate current rate to full order count.
                // estimatedSnapshotTotal: total_dates × SNAPSHOT_SECS_PER_DATE (bootstrap;
                //   refined with real measurements once dispatchSnapshotJobs() starts).
                $elapsed = microtime(true) - $phaseStartedAt;
                if ($elapsed > 0 && $totalImported > 0) {
                    $ordersPerSec          = $totalImported / $elapsed;
                    $estimatedImportTotal  = $totalOrders > 0 ? ($totalOrders / $ordersPerSec) : $elapsed;
                } else {
                    // Bootstrap: no data yet, assume 60s total import (will self-correct quickly).
                    $estimatedImportTotal = max(60.0, (float) ($totalOrders / 100));
                }
                $estimatedSnapshotTotal = $totalDates * self::SNAPSHOT_SECS_PER_DATE;
                $importFrac = $estimatedImportTotal / max(0.001, $estimatedImportTotal + $estimatedSnapshotTotal);
                $importFrac = max(0.50, min(0.92, $importFrac));
                $importCap  = (int) round($importFrac * 99);

                $progress = $totalOrders > 0
                    ? (int) min($importCap, round(($totalImported / $totalOrders) * $importCap))
                    : null;

                $store->update([
                    'historical_import_checkpoint' => ['date_cursor' => $chunkEnd->toDateString()],
                    'historical_import_progress'   => $progress,
                ]);

                $syncLog->update(['records_processed' => $totalImported]);

                $page++;
            } while ($page <= $totalPages && ! empty($orders));

            $chunkEnd->subDays(30);
        }

        return $totalImported;
    }

    /**
     * Run ComputeDailySnapshotJob synchronously for every date in the imported range,
     * advancing historical_import_progress from wherever import left off to 99.
     *
     * The split between import and snapshot phases is computed from actual measured
     * durations — no hardcoded percentages:
     *  - $importDuration: wall-clock seconds the order import phase took (passed in).
     *  - snapshotRate: measured after the first date completes; extrapolated to total.
     *  - importFrac: importDuration / (importDuration + estimatedSnapshotTotal).
     *  - importBoundary: importFrac × 99 — where import ends on the 0-99 bar.
     *
     * Progress never goes backward (guarded by max($current, $new)).
     * Writes are throttled — at most ~20 DB updates regardless of date range size.
     *
     * Why 99 not 100: 100 is written by the caller when status='completed' is set,
     * keeping it as the unambiguous "fully done" signal for the frontend poller.
     *
     * Jobs are idempotent (INSERT … ON CONFLICT DO UPDATE) so re-dispatching is safe.
     */
    private function dispatchSnapshotJobs(Carbon $importFrom, Store $store, float $importDuration): void
    {
        $cursor          = $importFrom->copy()->startOfDay();
        $end             = Carbon::yesterday()->startOfDay();
        $totalDates      = max(1, (int) $importFrom->diffInDays($end) + 1);
        $done            = 0;
        $phaseStart      = microtime(true);
        $currentProgress = (int) ($store->historical_import_progress ?? 0);

        // Throttle: write at most ~20 times during the snapshot phase.
        $writeEvery = max(1, (int) ($totalDates / 20));

        while ($cursor->lte($end)) {
            ComputeDailySnapshotJob::dispatchSync($this->storeId, $cursor->copy());
            $done++;

            $snapshotElapsed = microtime(true) - $phaseStart;
            $snapshotRate    = $snapshotElapsed / $done; // measured seconds per date

            // Use real measured snapshot rate to compute the split.
            // Falls back to SNAPSHOT_SECS_PER_DATE only for the very first tick
            // (before we have a real rate), which self-corrects on the next date.
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

    /**
     * Returns true when the workspace billing is in a state that blocks imports.
     *
     * Mirrors EnforceBillingAccess::trialExpiredWithNoPlan() exactly.
     */
    private function isBillingExpired(Workspace $workspace): bool
    {
        if ($workspace->trial_ends_at !== null
            && $workspace->trial_ends_at->lt(now())
            && $workspace->billing_plan === null
        ) {
            return true;
        }

        return false;
    }

    /**
     * Finds and updates the pre-created queued sync log, or creates a new one.
     *
     * Why: when the job is dispatched, a 'queued' sync log is created so the admin
     * can see the import is waiting to be processed. Once the job runs, we update
     * that log instead of creating a new one.
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
