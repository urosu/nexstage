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
 * Queue:   low
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
 *  - Fetch X-WP-Total via WooCommerceClient::fetchOrderCount() and store it
 *    in historical_import_total_orders (used for progress and time estimates)
 */
class WooCommerceHistoricalImportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 7200;
    public int $tries   = 3;

    public function __construct(
        private readonly int $storeId,
        private readonly int $workspaceId,
    ) {
        $this->onQueue('low');
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

            SyncLog::create([
                'workspace_id'      => $this->workspaceId,
                'syncable_type'     => Store::class,
                'syncable_id'       => $this->storeId,
                'job_type'          => self::class,
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

        $importFrom = $store->historical_import_from;

        if ($importFrom === null) {
            Log::error('WooCommerceHistoricalImportJob: historical_import_from is null, nothing to import', [
                'store_id' => $this->storeId,
            ]);
            return;
        }

        // Preserve the original start time across retries.
        $store->update([
            'historical_import_status'     => 'running',
            'historical_import_started_at' => $store->historical_import_started_at ?? now(),
        ]);

        $syncLog = SyncLog::create([
            'workspace_id'      => $this->workspaceId,
            'syncable_type'     => Store::class,
            'syncable_id'       => $this->storeId,
            'job_type'          => self::class,
            'status'            => 'running',
            'records_processed' => 0,
            'started_at'        => now(),
        ]);

        try {
            $totalImported = $this->runImport($store, $action, Carbon::parse($importFrom), $syncLog);

            // Reload to get the updated historical_import_started_at.
            $store->refresh();

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

            $this->dispatchSnapshotJobs(Carbon::parse($importFrom));

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
     * Iterate 30-day chunks from $importFrom through yesterday, paginating each
     * chunk until exhausted. Writes checkpoint + progress after every page.
     *
     * Rate-limit exceptions re-queue the job (attempt count unchanged) and return
     * without counting against $tries.
     *
     * @return int Total orders upserted across all chunks.
     */
    private function runImport(
        Store                      $store,
        UpsertWooCommerceOrderAction $action,
        Carbon                     $importFrom,
        SyncLog                    $syncLog,
    ): int {
        $importTo = Carbon::yesterday()->startOfDay();

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
        $checkpoint = $store->historical_import_checkpoint;
        $chunkStart = isset($checkpoint['date_cursor'])
            ? Carbon::parse($checkpoint['date_cursor'])->startOfDay()
            : $importFrom->copy()->startOfDay();

        $totalOrders   = (int) ($store->historical_import_total_orders ?? 0);
        $totalImported = 0;

        while ($chunkStart->lte($importTo)) {
            $chunkEnd = $chunkStart->copy()->addDays(29);

            if ($chunkEnd->gt($importTo)) {
                $chunkEnd = $importTo->copy();
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

                // Persist checkpoint and progress so retries resume from here.
                $progress = $totalOrders > 0
                    ? (int) min(99, round(($totalImported / $totalOrders) * 100))
                    : null;

                $store->update([
                    'historical_import_checkpoint' => ['date_cursor' => $chunkStart->toDateString()],
                    'historical_import_progress'   => $progress,
                ]);

                $syncLog->update(['records_processed' => $totalImported]);

                $page++;
            } while ($page <= $totalPages && ! empty($orders));

            $chunkStart->addDays(30);
        }

        return $totalImported;
    }

    /**
     * Dispatch one ComputeDailySnapshotJob per date from $importFrom through yesterday.
     *
     * Jobs are idempotent (INSERT … ON CONFLICT DO UPDATE) so re-dispatching is safe.
     */
    private function dispatchSnapshotJobs(Carbon $importFrom): void
    {
        $cursor = $importFrom->copy()->startOfDay();
        $end    = Carbon::yesterday()->startOfDay();

        while ($cursor->lte($end)) {
            ComputeDailySnapshotJob::dispatch($this->storeId, $cursor->copy());
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
}
