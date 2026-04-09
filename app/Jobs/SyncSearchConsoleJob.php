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
use App\Services\Integrations\SearchConsole\SearchConsoleClient;
use App\Services\WorkspaceContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Syncs GSC data for a single Search Console property.
 *
 * Queue:   default
 * Timeout: 300 s
 * Tries:   3
 * Backoff: [60, 300, 900] s (default)
 *
 * On every run queries the last 5 days (covers GSC's 2-3 day data lag).
 * Upserts:
 *   - gsc_daily_stats  (1 row per date)
 *   - gsc_queries      (top 1,000 per date)
 *   - gsc_pages        (top 1,000 per date)
 *
 * Dispatched every 6 hours per active property via schedule closure in console.php.
 * Also dispatched immediately after a new property is connected.
 */
class SyncSearchConsoleJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 300;
    public int $tries   = 3;

    /** @var array<int, int> */
    public array $backoff = [60, 300, 900];

    public function __construct(
        private readonly int $propertyId,
        private readonly int $workspaceId,
    ) {
        $this->onQueue('default');
    }

    public function handle(): void
    {
        app(WorkspaceContext::class)->set($this->workspaceId);

        /** @var SearchConsoleProperty|null $property */
        $property = SearchConsoleProperty::withoutGlobalScopes()->find($this->propertyId);

        if ($property === null) {
            Log::warning('SyncSearchConsoleJob: property not found', [
                'property_id' => $this->propertyId,
            ]);
            return;
        }

        if ($property->status !== 'active') {
            return;
        }

        $syncLog = SyncLog::create([
            'workspace_id'  => $this->workspaceId,
            'syncable_type' => SearchConsoleProperty::class,
            'syncable_id'   => $this->propertyId,
            'job_type'      => self::class,
            'status'        => 'running',
            'started_at'    => now(),
        ]);

        try {
            $client = SearchConsoleClient::forProperty($property);

            // Query last 5 days to cover the 2-3 day GSC data lag
            $endDate   = now()->subDay()->toDateString();
            $startDate = now()->subDays(5)->toDateString();

            $propertyUrl = $property->property_url;

            $recordsProcessed = 0;

            // 1. Daily aggregate stats (one row per date)
            $dailyRows = $client->queryDailyStats($propertyUrl, $startDate, $endDate);
            $recordsProcessed += $this->upsertDailyStats($dailyRows, $property);

            // 2. Per-query breakdown
            $queryRows = $client->querySearchQueries($propertyUrl, $startDate, $endDate);
            $recordsProcessed += $this->upsertQueries($queryRows, $property);

            // 3. Per-page breakdown
            $pageRows = $client->queryPages($propertyUrl, $startDate, $endDate);
            $recordsProcessed += $this->upsertPages($pageRows, $property);

            // Mark success
            $property->update([
                'consecutive_sync_failures' => 0,
                'last_synced_at'            => now(),
                'status' => $property->status === 'error' ? 'active' : $property->status,
            ]);

            $syncLog->update([
                'status'            => 'completed',
                'records_processed' => $recordsProcessed,
                'completed_at'      => now(),
                'duration_seconds'  => max(0, (int) now()->diffInSeconds($syncLog->started_at)),
            ]);
        } catch (GoogleRateLimitException $e) {
            $this->release($e->retryAfter);
            return;
        } catch (GoogleTokenExpiredException $e) {
            $this->markTokenExpired($property, $syncLog, $e);
            $this->fail($e);
            return;
        } catch (Throwable $e) {
            $this->handleSyncFailure($property, $syncLog, $e);
            throw $e;
        }
    }

    // -------------------------------------------------------------------------
    // Upsert helpers
    // -------------------------------------------------------------------------

    /**
     * Upsert daily aggregate stats.
     * One row per (property_id, date). UNIQUE(property_id, date).
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
     * Upsert per-query stats (top 1,000 per date per property).
     * UNIQUE(property_id, date, query).
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
     * Upsert per-page stats (top 1,000 per date per property).
     * UNIQUE(property_id, date, page).
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

            // Truncate URLs > 2000 chars (B-tree index limit on varchar(2000))
            if (strlen($page) > 2000) {
                $page = substr($page, 0, 2000);
            }

            GscPage::withoutGlobalScopes()->updateOrCreate(
                [
                    'property_id' => $property->id,
                    'date'        => $date,
                    'page'        => $page,
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

    // -------------------------------------------------------------------------
    // Failure handling
    // -------------------------------------------------------------------------

    /**
     * Called by Laravel after all retry attempts are exhausted (including when
     * MaxAttemptsExceededException is thrown before handle() even runs).
     *
     * Single place for consecutive_sync_failures increment and alert creation.
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

        /** @var SearchConsoleProperty|null $property */
        $property = SearchConsoleProperty::withoutGlobalScopes()->find($this->propertyId);

        if ($property === null) {
            return;
        }

        $syncLog = SyncLog::where('syncable_type', SearchConsoleProperty::class)
            ->where('syncable_id', $this->propertyId)
            ->where('workspace_id', $this->workspaceId)
            ->orderBy('started_at', 'desc')
            ->first();

        if ($syncLog !== null && $syncLog->status === 'running') {
            $syncLog->update([
                'status'           => 'failed',
                'error_message'    => $e->getMessage(),
                'completed_at'     => now(),
                'duration_seconds' => max(0, (int) now()->diffInSeconds($syncLog->started_at)),
            ]);
        }

        DB::transaction(function () use ($property, $e): void {
            $failures = $property->consecutive_sync_failures + 1;

            $property->update([
                'consecutive_sync_failures' => $failures,
                'status'                    => $failures >= 3 ? 'error' : $property->status,
            ]);

            Alert::withoutGlobalScopes()->create([
                'workspace_id' => $this->workspaceId,
                'type'         => 'gsc_sync_failure',
                'severity'     => $failures >= 3 ? 'critical' : 'warning',
                'data'         => [
                    'property_url'         => $property->property_url,
                    'consecutive_failures' => $failures,
                    'error'                => $e->getMessage(),
                ],
            ]);
        });

        Log::error('SyncSearchConsoleJob: final failure after all retries', [
            'property_id' => $this->propertyId,
            'error'       => $e->getMessage(),
        ]);
    }

    private function markTokenExpired(
        SearchConsoleProperty $property,
        SyncLog $syncLog,
        Throwable $e,
    ): void {
        $property->update(['status' => 'token_expired']);

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
            'duration_seconds' => max(0, (int) now()->diffInSeconds($syncLog->started_at)),
        ]);

        Log::error('SyncSearchConsoleJob: GSC token expired', [
            'property_id' => $this->propertyId,
        ]);
    }

    /**
     * Update the sync log for this attempt. Account-level side effects handled in failed().
     */
    private function handleSyncFailure(
        SearchConsoleProperty $property,
        SyncLog $syncLog,
        Throwable $e,
    ): void {
        $syncLog->update([
            'status'           => 'failed',
            'error_message'    => $e->getMessage(),
            'completed_at'     => now(),
            'duration_seconds' => max(0, (int) now()->diffInSeconds($syncLog->started_at)),
        ]);

        Log::warning('SyncSearchConsoleJob: attempt failed', [
            'property_id' => $this->propertyId,
            'attempt'     => $this->attempts(),
            'error'       => $e->getMessage(),
        ]);
    }
}
