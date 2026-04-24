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
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
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
 * Triggered by: schedule (every 6 hours per active property, routes/console.php)
 *               + immediately after a new property is connected
 * Reads from:   Google Search Console API v3
 * Writes to:    gsc_daily_stats, gsc_queries, gsc_pages
 *
 * Queue:   sync-google-search
 * Timeout: 300 s
 * Tries:   3
 * Backoff: [60, 300, 900] s
 *
 * On every run queries the last 5 days (covers GSC's 2-3 day data lag).
 *
 * Per-data-type this job makes TWO API calls:
 *   1. Aggregate (no device/country) → upserted with device='all', country='ZZ'
 *   2. Breakdown (device + country dimensions) → upserted with actual device/country values
 *
 * All 6 calls (3 data types × 2 slices) are fired concurrently via Http::pool,
 * making the whole sync one HTTP/2-multiplexed batch (~1s wall time).
 *
 * Why two calls instead of one breakdown-only call: aggregate rows are used by Phase 2 anomaly
 * detection (gsc_clicks baseline). Breakdown rows are Phase 0 data capture for future analysis.
 *
 * Related: app/Services/Integrations/SearchConsole/SearchConsoleClient.php (API client)
 * Related: app/Models/GscDailyStat.php, GscQuery.php, GscPage.php (models)
 * See: PLANNING.md "Integration-specific rules" + "Data Capture Strategy"
 */
class SyncSearchConsoleJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout   = 300;
    public int $tries     = 3;
    public int $uniqueFor = 330;

    /** @var array<int, int> */
    public array $backoff = [60, 300, 900];

    public function uniqueId(): string
    {
        return (string) $this->propertyId;
    }

    public function __construct(
        private readonly int $propertyId,
        private readonly int $workspaceId,
    ) {
        $this->onQueue('sync-google-search');
    }

    public function handle(): void
    {
        app(WorkspaceContext::class)->set($this->workspaceId);

        // Discard jobs queued before trial expiry.
        if ($this->isWorkspaceFrozen()) {
            Log::info('SyncSearchConsoleJob: skipped — workspace trial expired', ['workspace_id' => $this->workspaceId]);
            return;
        }

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
            'queue'         => $this->queue,
            'attempt'         => $this->attempts(),
            'timeout_seconds' => $this->timeout,
        ]);

        try {
            $client = SearchConsoleClient::forProperty($property);

            // Query last 5 days to cover the 2-3 day GSC data lag.
            // dataState='all' is kept so the edge days reflect the freshest
            // (partial) data — the rolling window overwrites them on each run.
            // See: PLANNING.md "Integration-specific rules"
            $endDate   = now()->subDay()->toDateString();
            $startDate = now()->subDays(5)->toDateString();

            $propertyUrl = $property->property_url;

            // Fire all 6 dim-slices concurrently — one HTTP/2 multiplexed batch.
            // All cover the same 5-day range, so GSC returns at most 5 * 25,000
            // rows per breakdown, which is plenty for SMB properties.
            $responses = $client->searchAnalyticsPool($propertyUrl, [
                'daily'            => ['startDate' => $startDate, 'endDate' => $endDate, 'dimensions' => ['date']],
                'daily_breakdown'  => ['startDate' => $startDate, 'endDate' => $endDate, 'dimensions' => ['date', 'device', 'country']],
                'queries'          => ['startDate' => $startDate, 'endDate' => $endDate, 'dimensions' => ['date', 'query']],
                'queries_breakdown'=> ['startDate' => $startDate, 'endDate' => $endDate, 'dimensions' => ['date', 'query', 'device', 'country']],
                'pages'            => ['startDate' => $startDate, 'endDate' => $endDate, 'dimensions' => ['date', 'page']],
                'pages_breakdown'  => ['startDate' => $startDate, 'endDate' => $endDate, 'dimensions' => ['date', 'page', 'device', 'country']],
            ]);

            $recordsProcessed  = 0;
            $recordsProcessed += $this->upsertDailyStats($responses['daily'] ?? [], $property);
            $recordsProcessed += $this->upsertDailyStats($responses['daily_breakdown'] ?? [], $property);
            $recordsProcessed += $this->upsertQueries($responses['queries'] ?? [], $property);
            $recordsProcessed += $this->upsertQueries($responses['queries_breakdown'] ?? [], $property);
            $recordsProcessed += $this->upsertPages($responses['pages'] ?? [], $property);
            $recordsProcessed += $this->upsertPages($responses['pages_breakdown'] ?? [], $property);

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
     *
     * Unique key: (property_id, date, device, country).
     * Rows from the aggregate API call carry no device/country → stored as 'all'/'ZZ'.
     * Rows from breakdown calls carry actual device + country values.
     *
     * Why NOT NULL sentinels instead of NULL: PostgreSQL treats NULL as distinct in unique
     * constraints, so nullable device/country would allow duplicate workspace-level rows.
     * See: PLANNING.md "gsc_daily_stats"
     *
     * Uses a single bulk upsert instead of per-row updateOrCreate to avoid N+1 DB calls.
     *
     * @param  list<array<string, mixed>> $rows
     */
    private function upsertDailyStats(array $rows, SearchConsoleProperty $property): int
    {
        $records = [];
        $now     = now();

        foreach ($rows as $row) {
            $date = (string) ($row['date'] ?? '');

            if ($date === '') {
                continue;
            }

            // GSC returns device as 'MOBILE'/'DESKTOP'/'TABLET' (uppercase) — normalise to lowercase.
            // Country comes as ISO 3166-1 alpha-3 lowercase (e.g., 'deu') — normalise to uppercase CHAR(3).
            // Aggregate rows have no device/country key → fall back to sentinel values.
            $device  = strtolower((string) ($row['device'] ?? '')) ?: 'all';
            $country = strtoupper((string) ($row['country'] ?? '')) ?: 'ZZ';

            $records[] = [
                'property_id'  => $property->id,
                'workspace_id' => $this->workspaceId,
                'date'         => $date,
                'device'       => $device,
                'country'      => $country,
                'clicks'       => (int) ($row['clicks'] ?? 0),
                'impressions'  => (int) ($row['impressions'] ?? 0),
                'ctr'          => isset($row['ctr']) ? (float) $row['ctr'] : null,
                'position'     => isset($row['position']) ? (float) $row['position'] : null,
                'created_at'   => $now,
                'updated_at'   => $now,
            ];
        }

        if (empty($records)) {
            return 0;
        }

        GscDailyStat::withoutGlobalScopes()->upsert(
            $records,
            ['property_id', 'date', 'device', 'country'],
            ['clicks', 'impressions', 'ctr', 'position', 'updated_at'],
        );

        return count($records);
    }

    /**
     * Upsert per-query stats (top 1,000 per date per property).
     *
     * Unique key: (property_id, date, query, device, country).
     * Same device/country sentinel logic as upsertDailyStats.
     *
     * Uses a single bulk upsert instead of per-row updateOrCreate to avoid N+1 DB calls.
     *
     * @param  list<array<string, mixed>> $rows
     */
    private function upsertQueries(array $rows, SearchConsoleProperty $property): int
    {
        $records = [];
        $now     = now();

        foreach ($rows as $row) {
            $date  = (string) ($row['date'] ?? '');
            $query = (string) ($row['query'] ?? '');

            if ($date === '' || $query === '') {
                continue;
            }

            $device  = strtolower((string) ($row['device'] ?? '')) ?: 'all';
            $country = strtoupper((string) ($row['country'] ?? '')) ?: 'ZZ';

            $records[] = [
                'property_id'  => $property->id,
                'workspace_id' => $this->workspaceId,
                'date'         => $date,
                'query'        => $query,
                'device'       => $device,
                'country'      => $country,
                'clicks'       => (int) ($row['clicks'] ?? 0),
                'impressions'  => (int) ($row['impressions'] ?? 0),
                'ctr'          => isset($row['ctr']) ? (float) $row['ctr'] : null,
                'position'     => isset($row['position']) ? (float) $row['position'] : null,
                'created_at'   => $now,
                'updated_at'   => $now,
            ];
        }

        if (empty($records)) {
            return 0;
        }

        GscQuery::withoutGlobalScopes()->upsert(
            $records,
            ['property_id', 'date', 'query', 'device', 'country'],
            ['clicks', 'impressions', 'ctr', 'position', 'updated_at'],
        );

        return count($records);
    }

    /**
     * Upsert per-page stats (top 1,000 per date per property).
     *
     * Unique key: (property_id, date, page_hash, device, country).
     * page_hash = SHA-256 of the full page URL stored as CHAR(64).
     * Why page_hash: VARCHAR(2000) unique index caused massive B-tree index bloat.
     * See: PLANNING.md "gsc_pages"
     *
     * Uses a single bulk upsert instead of per-row updateOrCreate to avoid N+1 DB calls.
     *
     * @param  list<array<string, mixed>> $rows
     */
    private function upsertPages(array $rows, SearchConsoleProperty $property): int
    {
        $records = [];
        $now     = now();

        foreach ($rows as $row) {
            $date = (string) ($row['date'] ?? '');
            $page = (string) ($row['page'] ?? '');

            if ($date === '' || $page === '') {
                continue;
            }

            $device  = strtolower((string) ($row['device'] ?? '')) ?: 'all';
            $country = strtoupper((string) ($row['country'] ?? '')) ?: 'ZZ';

            $records[] = [
                'property_id'  => $property->id,
                'workspace_id' => $this->workspaceId,
                'date'         => $date,
                'page'         => $page,
                'page_hash'    => hash('sha256', $page),
                'device'       => $device,
                'country'      => $country,
                'clicks'       => (int) ($row['clicks'] ?? 0),
                'impressions'  => (int) ($row['impressions'] ?? 0),
                'ctr'          => isset($row['ctr']) ? (float) $row['ctr'] : null,
                'position'     => isset($row['position']) ? (float) $row['position'] : null,
                'created_at'   => $now,
                'updated_at'   => $now,
            ];
        }

        if (empty($records)) {
            return 0;
        }

        GscPage::withoutGlobalScopes()->upsert(
            $records,
            ['property_id', 'date', 'page_hash', 'device', 'country'],
            ['page', 'clicks', 'impressions', 'ctr', 'position', 'updated_at'],
        );

        return count($records);
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
