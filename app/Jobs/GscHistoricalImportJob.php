<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Exceptions\GoogleRateLimitException;
use App\Exceptions\GoogleTokenExpiredException;
use App\Models\Alert;
use App\Models\GscDailyStat;
use App\Models\GscPage;
use App\Models\GscQuery;
use App\Models\SearchConsoleProperty;
use App\Models\SyncLog;
use App\Models\Workspace;
use App\Services\Integrations\SearchConsole\SearchConsoleClient;
use App\Services\WorkspaceContext;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Imports the full GSC history for a Search Console property.
 *
 * Queue:   imports
 * Timeout: 7200 s (2 hours)
 * Tries:   5
 * Backoff: default [60, 300, 900] s
 *
 * Design decisions:
 *  - Processes one day at a time. This is required to get up to 1,000 queries/pages
 *    PER DAY — querying a multi-day range returns the top-1,000 across the entire
 *    range, not per day.
 *  - 3 API calls per day: queryDailyStats, querySearchQueries, queryPages.
 *  - Imports newest → oldest so recent data is usable early if rate-limited.
 *  - Checkpoint (historical_import_checkpoint) records the last completed date
 *    so retries resume without re-fetching already-imported days.
 *  - Progress (0–99) is written after each day. 100 is written only on completion.
 *  - importTo is set to now()-3 days to avoid overlap with SyncSearchConsoleJob
 *    which covers the rolling last-5-days window.
 *  - No FX conversion — GSC has no currency data.
 *
 * Billing gate:
 *  If the workspace trial has expired and there is no billing plan, the job sets
 *  historical_import_status = 'failed' and returns without throwing.
 *
 * Caller responsibility (controller before dispatching):
 *  - Set historical_import_status = 'pending'
 *  - Set historical_import_from = now()->subMonths(16)->toDateString()
 */
class GscHistoricalImportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 7200;
    public int $tries   = 5;

    public function __construct(
        private readonly int $propertyId,
        private readonly int $workspaceId,
        private ?int         $syncLogId = null,
    ) {
        $this->onQueue('imports');
    }

    public function handle(): void
    {
        app(WorkspaceContext::class)->set($this->workspaceId);

        /** @var SearchConsoleProperty|null $property */
        $property = SearchConsoleProperty::withoutGlobalScopes()->find($this->propertyId);

        if ($property === null) {
            Log::warning('GscHistoricalImportJob: property not found', [
                'property_id' => $this->propertyId,
            ]);
            return;
        }

        // Billing gate — checked at runtime so expiry during a long import is caught on retry.
        $workspace = Workspace::withoutGlobalScopes()
            ->select(['id', 'trial_ends_at', 'billing_plan'])
            ->find($this->workspaceId);

        if ($workspace !== null && $this->isBillingExpired($workspace)) {
            $property->update(['historical_import_status' => 'failed']);

            $this->resolveSyncLog(SearchConsoleProperty::class, $this->propertyId, [
                'status'            => 'failed',
                'records_processed' => 0,
                'error_message'     => 'Import paused — subscription required.',
                'started_at'        => now(),
                'completed_at'      => now(),
                'duration_seconds'  => 0,
            ]);

            Log::warning('GscHistoricalImportJob: billing expired, import blocked', [
                'property_id'  => $this->propertyId,
                'workspace_id' => $this->workspaceId,
            ]);

            return;
        }

        // When historical_import_from is null the user chose "All available data".
        // Fall back to 2010-01-01 — GSC will cap its response at 16 months on its side.
        if ($property->historical_import_from === null) {
            $property->update(['historical_import_from' => '2010-01-01']);
            $property->refresh();
        }

        // Preserve the original start time across retries.
        $property->update([
            'historical_import_status'     => 'running',
            'historical_import_started_at' => $property->historical_import_started_at ?? now(),
        ]);

        $syncLog = $this->resolveSyncLog(SearchConsoleProperty::class, $this->propertyId, [
            'status'            => 'running',
            'records_processed' => 0,
            'started_at'        => now(),
        ]);

        try {
            $totalImported = $this->runImport($property, $syncLog);

            $property->refresh();

            $property->update([
                'historical_import_status'           => 'completed',
                'historical_import_progress'         => 100,
                'historical_import_checkpoint'       => null,
                'historical_import_completed_at'     => now(),
                'historical_import_duration_seconds' => (int) now()->diffInSeconds(
                    $property->historical_import_started_at ?? now()
                ),
                'last_synced_at' => now(),
            ]);

            $syncLog->update([
                'status'            => 'completed',
                'records_processed' => $totalImported,
                'completed_at'      => now(),
                'duration_seconds'  => (int) max(0, (int) now()->diffInSeconds($syncLog->started_at)),
            ]);

            Log::info('GscHistoricalImportJob: completed', [
                'property_id'    => $this->propertyId,
                'total_imported' => $totalImported,
            ]);
        } catch (Throwable $e) {
            $property->update(['historical_import_status' => 'failed']);

            $syncLog->update([
                'status'           => 'failed',
                'error_message'    => mb_substr($e->getMessage(), 0, 500),
                'completed_at'     => now(),
                'duration_seconds' => (int) max(0, (int) now()->diffInSeconds($syncLog->started_at)),
            ]);

            Alert::withoutGlobalScopes()->create([
                'workspace_id' => $this->workspaceId,
                'type'         => 'gsc_import_failed',
                'severity'     => 'warning',
                'data'         => [
                    'property_url' => $property->property_url,
                    'error'        => mb_substr($e->getMessage(), 0, 255),
                ],
            ]);

            Log::error('GscHistoricalImportJob: failed', [
                'property_id' => $this->propertyId,
                'error'       => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Iterate day-by-day from now()-3 days back to historical_import_from.
     * Each day makes 3 API calls: daily_stats, queries, pages.
     *
     * Why newest → oldest: if a rate-limit or error interrupts the import,
     * the most recent data (which the dashboard uses) is already in the DB.
     *
     * Writes checkpoint + progress after each day. Rate-limit exceptions re-queue
     * the job (attempt count unchanged) and return immediately.
     *
     * @return int Total rows upserted across all days.
     */
    private function runImport(SearchConsoleProperty $property, SyncLog $syncLog): int
    {
        $importFrom = Carbon::parse($property->historical_import_from)->startOfDay();
        // Stop 3 days before today — SyncSearchConsoleJob covers the rolling last-5-days.
        $importTo   = now()->subDays(3)->startOfDay();

        if ($importFrom->gt($importTo)) {
            return 0;
        }

        $totalDays     = (int) $importFrom->diffInDays($importTo) + 1;
        $totalImported = 0;

        $client = SearchConsoleClient::forProperty($property);

        // Import newest → oldest. Checkpoint stores the last successfully processed day
        // so retries resume from that day (reprocessing it once, which is safe — upserts).
        $checkpoint    = $property->historical_import_checkpoint;
        $dayCursor     = isset($checkpoint['date_cursor'])
            ? Carbon::parse($checkpoint['date_cursor'])->startOfDay()
            : $importTo->copy();

        // completedDays = how many days from the end (importTo) we have already processed.
        $completedDays = (int) $dayCursor->diffInDays($importTo);

        while ($dayCursor->gte($importFrom)) {
            $dateStr = $dayCursor->toDateString();

            try {
                $dailyRows = $client->queryDailyStats($property->property_url, $dateStr, $dateStr);
                $totalImported += $this->upsertDailyStats($dailyRows, $property);

                $queryRows = $client->querySearchQueries($property->property_url, $dateStr, $dateStr);
                $totalImported += $this->upsertQueries($queryRows, $property);

                $pageRows = $client->queryPages($property->property_url, $dateStr, $dateStr);
                $totalImported += $this->upsertPages($pageRows, $property);
            } catch (GoogleRateLimitException $e) {
                // Persist checkpoint so the next attempt resumes from this day.
                $property->update([
                    'historical_import_checkpoint' => ['date_cursor' => $dateStr],
                ]);
                $this->release($e->retryAfter ?? 60);
                return $totalImported;
            } catch (GoogleTokenExpiredException $e) {
                $property->update([
                    'historical_import_status' => 'failed',
                    'status'                   => 'token_expired',
                ]);

                Alert::withoutGlobalScopes()->create([
                    'workspace_id' => $this->workspaceId,
                    'type'         => 'gsc_token_expired',
                    'severity'     => 'critical',
                    'data'         => ['property_url' => $property->property_url],
                ]);

                $syncLog->update([
                    'status'           => 'failed',
                    'error_message'    => $e->getMessage(),
                    'completed_at'     => now(),
                    'duration_seconds' => (int) max(0, (int) now()->diffInSeconds($syncLog->started_at)),
                ]);

                Log::error('GscHistoricalImportJob: token expired mid-import', [
                    'property_id' => $this->propertyId,
                ]);

                $this->fail($e);
                return $totalImported;
            }

            $completedDays++;
            $progress = (int) min(99, round(($completedDays / $totalDays) * 100));

            $property->update([
                'historical_import_checkpoint' => ['date_cursor' => $dateStr],
                'historical_import_progress'   => $progress,
            ]);

            $syncLog->update(['records_processed' => $totalImported]);

            $dayCursor->subDay();
        }

        return $totalImported;
    }

    /**
     * Upsert daily aggregate stats. One row per (property_id, date).
     *
     * @param  list<array<string, mixed>> $rows
     */
    private function upsertDailyStats(array $rows, SearchConsoleProperty $property): int
    {
        $count = 0;

        foreach ($rows as $row) {
            $date = (string) ($row['date'] ?? '');

            if ($date === '') {
                continue;
            }

            GscDailyStat::withoutGlobalScopes()->updateOrCreate(
                [
                    'property_id' => $property->id,
                    'date'        => $date,
                ],
                [
                    'workspace_id' => $this->workspaceId,
                    'clicks'       => (int) ($row['clicks'] ?? 0),
                    'impressions'  => (int) ($row['impressions'] ?? 0),
                    'ctr'          => isset($row['ctr']) ? (float) $row['ctr'] : null,
                    'position'     => isset($row['position']) ? (float) $row['position'] : null,
                ]
            );

            $count++;
        }

        return $count;
    }

    /**
     * Upsert per-query stats. Top 1,000 per (property_id, date, query).
     *
     * @param  list<array<string, mixed>> $rows
     */
    private function upsertQueries(array $rows, SearchConsoleProperty $property): int
    {
        $count = 0;

        foreach ($rows as $row) {
            $date  = (string) ($row['date'] ?? '');
            $query = (string) ($row['query'] ?? '');

            if ($date === '' || $query === '') {
                continue;
            }

            GscQuery::withoutGlobalScopes()->updateOrCreate(
                [
                    'property_id' => $property->id,
                    'date'        => $date,
                    'query'       => $query,
                ],
                [
                    'workspace_id' => $this->workspaceId,
                    'clicks'       => (int) ($row['clicks'] ?? 0),
                    'impressions'  => (int) ($row['impressions'] ?? 0),
                    'ctr'          => isset($row['ctr']) ? (float) $row['ctr'] : null,
                    'position'     => isset($row['position']) ? (float) $row['position'] : null,
                ]
            );

            $count++;
        }

        return $count;
    }

    /**
     * Upsert per-page stats. Top 1,000 per (property_id, date, page).
     *
     * @param  list<array<string, mixed>> $rows
     */
    private function upsertPages(array $rows, SearchConsoleProperty $property): int
    {
        $count = 0;

        foreach ($rows as $row) {
            $date = (string) ($row['date'] ?? '');
            $page = (string) ($row['page'] ?? '');

            if ($date === '' || $page === '') {
                continue;
            }

            // Unique key uses page_hash (SHA-256) to avoid large B-tree index bloat.
            // Historical import pages are aggregate rows — device='all', country='ZZ'.
            $pageHash = hash('sha256', $page);

            GscPage::withoutGlobalScopes()->updateOrCreate(
                [
                    'property_id' => $property->id,
                    'date'        => $date,
                    'page_hash'   => $pageHash,
                    'device'      => 'all',
                    'country'     => 'ZZ',
                ],
                [
                    'workspace_id' => $this->workspaceId,
                    'page'         => $page,
                    'clicks'       => (int) ($row['clicks'] ?? 0),
                    'impressions'  => (int) ($row['impressions'] ?? 0),
                    'ctr'          => isset($row['ctr']) ? (float) $row['ctr'] : null,
                    'position'     => isset($row['position']) ? (float) $row['position'] : null,
                ]
            );

            $count++;
        }

        return $count;
    }

    /**
     * Returns true when the workspace billing is in a state that blocks imports.
     */
    private function isBillingExpired(Workspace $workspace): bool
    {
        return $workspace->trial_ends_at !== null
            && $workspace->trial_ends_at->lt(now())
            && $workspace->billing_plan === null;
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

    /**
     * Called by Laravel after all retry attempts are exhausted.
     * Closes any running SyncLog rows for this property.
     * Does NOT touch consecutive_sync_failures — that counter tracks rolling sync health.
     */
    public function failed(Throwable $e): void
    {
        if ($e instanceof GoogleRateLimitException) {
            return;
        }

        if ($e instanceof GoogleTokenExpiredException) {
            return;
        }

        app(WorkspaceContext::class)->set($this->workspaceId);

        SearchConsoleProperty::withoutGlobalScopes()
            ->where('id', $this->propertyId)
            ->where('historical_import_status', 'running')
            ->update(['historical_import_status' => 'failed']);

        SyncLog::withoutGlobalScopes()
            ->where('syncable_type', SearchConsoleProperty::class)
            ->where('syncable_id', $this->propertyId)
            ->where('workspace_id', $this->workspaceId)
            ->where('status', 'running')
            ->update([
                'status'        => 'failed',
                'error_message' => mb_substr($e->getMessage(), 0, 500),
                'completed_at'  => now(),
            ]);

        Log::error('GscHistoricalImportJob: final failure after all retries', [
            'property_id' => $this->propertyId,
            'error'       => $e->getMessage(),
        ]);
    }
}
