<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Exceptions\FacebookRateLimitException;
use App\Exceptions\FacebookTokenExpiredException;
use App\Exceptions\GoogleAccountDisabledException;
use App\Exceptions\GoogleRateLimitException;
use App\Exceptions\GoogleTokenExpiredException;
use App\Jobs\Concerns\SyncsAdInsights;
use App\Models\AdAccount;
use App\Models\Alert;
use App\Models\SyncLog;
use App\Models\Workspace;
use App\Services\Fx\FxRateService;
use App\Services\Integrations\Facebook\FacebookAdsClient;
use App\Services\Integrations\Google\GoogleAdsClient;
use App\Services\WorkspaceContext;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Imports the full ad insights history for a Facebook or Google Ads account.
 *
 * Queue:   imports
 * Timeout: 7200 s (2 hours)
 * Tries:   5
 * Backoff: default [60, 300, 900] s
 *
 * Design decisions:
 *  - Facebook: 90-day async chunks via AdReportRun API (POST /insights → report_run_id →
 *    poll until completed → paginate results). Reduces ~76 synchronous calls to ~13 async
 *    submissions for a 37-month import. Zero-impression filter applied to skip empty rows.
 *  - Google: 30-day synchronous chunks (Google Ads API has no async mode).
 *  - Facebook: three levels per chunk (campaign, adset, ad) via separate async jobs submitted
 *    sequentially. The checkpoint tracks each level independently so a retry after a rate-limit
 *    mid-chunk resumes from the failed level rather than restarting from the campaign level.
 *  - No FX prefetch per chunk — FxRateService is DB-first; missing rates leave
 *    spend_in_reporting_currency = NULL, filled nightly by RetryMissingConversionJob.
 *  - Checkpoint (historical_import_checkpoint) records the current chunk end date so
 *    retries resume without re-processing already-imported chunks.
 *  - Progress (0–99) is written after each chunk. 100 is written only on completion.
 *
 * Billing gate: same pattern as WooCommerceHistoricalImportJob.
 *
 * Caller responsibility (controller before dispatching):
 *  - Set historical_import_status = 'pending'
 *  - Set historical_import_from = now()->subMonths(37)->toDateString()
 */
class AdHistoricalImportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    use SyncsAdInsights;

    public int $timeout = 7200;
    public int $tries   = 5;

    // Explicit class-level default so deserialization of older payloads (dispatched before
    // this property existed) doesn't leave $syncLogId uninitialized and throw on access.
    private ?int $syncLogId = null;

    public function __construct(
        private readonly int $adAccountId,
        private readonly int $workspaceId,
        ?int $syncLogId = null,
    ) {
        $this->syncLogId = $syncLogId;
        $this->onQueue('imports');
    }

    public function handle(FxRateService $fxRates): void
    {
        app(WorkspaceContext::class)->set($this->workspaceId);

        /** @var AdAccount|null $account */
        $account = AdAccount::withoutGlobalScopes()->find($this->adAccountId);

        if ($account === null) {
            Log::warning('AdHistoricalImportJob: ad account not found', [
                'ad_account_id' => $this->adAccountId,
            ]);
            return;
        }

        // Guard: bail if the import was already completed by a concurrent job instance.
        // Why: on rate-limit recovery the job self-dispatches a fresh copy and deletes
        // itself. If two copies land in failed_jobs and are both retried, the first one
        // to succeed marks status='completed' and clears the checkpoint — the second would
        // otherwise restart the full 37-month import from scratch, wasting all API quota.
        if ($account->historical_import_status === 'completed') {
            Log::info('AdHistoricalImportJob: import already completed, discarding duplicate', [
                'ad_account_id' => $this->adAccountId,
            ]);
            $this->delete();
            return;
        }

        // Concurrency lock: only one import instance per account at a time.
        // TTL covers the 2-hour job timeout; Redis auto-releases if the process dies.
        $lock = Cache::lock("ad_historical_import:{$this->adAccountId}", 7200);
        if (! $lock->get()) {
            // Another instance is already running — release this job without consuming
            // an attempt. Re-queue with a short delay so it picks up after the lock drops.
            Log::info('AdHistoricalImportJob: another instance running, re-queuing', [
                'ad_account_id' => $this->adAccountId,
            ]);
            self::dispatch($this->adAccountId, $this->workspaceId, $this->syncLogId)
                ->delay(now()->addSeconds(60))
                ->onQueue('low');
            $this->delete();
            return;
        }

        try {
        // Billing gate — checked at runtime so expiry during a long import is caught on retry.
        $workspace = Workspace::withoutGlobalScopes()
            ->select(['id', 'trial_ends_at', 'billing_plan'])
            ->find($this->workspaceId);

        if ($workspace !== null && $this->isBillingExpired($workspace)) {
            $account->update(['historical_import_status' => 'failed']);

            $this->resolveSyncLog(AdAccount::class, $this->adAccountId, [
                'status'            => 'failed',
                'records_processed' => 0,
                'error_message'     => 'Import paused — subscription required.',
                'started_at'        => now(),
                'completed_at'      => now(),
                'duration_seconds'  => 0,
            ]);

            Log::warning('AdHistoricalImportJob: billing expired, import blocked', [
                'ad_account_id' => $this->adAccountId,
                'workspace_id'  => $this->workspaceId,
            ]);

            return;
        }

        // Why: Facebook API hard-limits history to 37 months (error #3018 if exceeded).
        // When the user picks no date, we default to the Facebook maximum.
        // We also clamp any user-supplied date that somehow bypasses the controller validation.
        //
        // Why string conversion: historical_import_from is cast as 'date' → Carbon instance.
        // Comparing Carbon < string with PHP's < operator does not perform chronological
        // comparison (object-to-string type juggling returns false), so the clamp silently
        // never fired. Use toDateString() on both sides for a reliable lexicographic compare.
        $earliestAllowed = now()->subMonths(37)->toDateString();
        $importFromDate  = $account->historical_import_from?->toDateString() ?? $earliestAllowed;

        if ($importFromDate < $earliestAllowed) {
            $importFromDate = $earliestAllowed;
        }

        if ($account->historical_import_from?->toDateString() !== $importFromDate) {
            $account->update(['historical_import_from' => $importFromDate]);
            $account->refresh();
        }

        // Preserve the original start time across retries.
        // Initialize progress to 0 (not null) so the progress bar renders immediately —
        // progress stays null until the first chunk completes without this.
        $account->update([
            'historical_import_status'     => 'running',
            'historical_import_started_at' => $account->historical_import_started_at ?? now(),
            'historical_import_progress'   => $account->historical_import_progress ?? 0,
        ]);

        $syncLog = $this->resolveSyncLog(AdAccount::class, $this->adAccountId, [
            'status'            => 'running',
            'records_processed' => 0,
            'started_at'        => now(),
        ]);

        try {
            $totalImported = $this->runImport($account, $fxRates, $syncLog);

            $account->refresh();

            $account->update([
                'historical_import_status'           => 'completed',
                'historical_import_progress'         => 100,
                'historical_import_checkpoint'       => null,
                'historical_import_completed_at'     => now(),
                'historical_import_duration_seconds' => (int) now()->diffInSeconds(
                    $account->historical_import_started_at ?? now()
                ),
                'last_synced_at' => now(),
            ]);

            $syncLog->update([
                'status'            => 'completed',
                'records_processed' => $totalImported,
                'error_message'     => null,
                'completed_at'      => now(),
                'duration_seconds'  => (int) max(0, (int) now()->diffInSeconds($syncLog->started_at)),
            ]);

            Log::info('AdHistoricalImportJob: completed', [
                'platform'       => $account->platform,
                'ad_account_id'  => $this->adAccountId,
                'total_imported' => $totalImported,
            ]);
        } catch (FacebookRateLimitException | GoogleRateLimitException $e) {
            // Checkpoint was saved in runImport() before re-throwing.
            // Dispatch a fresh job to continue from checkpoint; delete current to avoid
            // consuming an attempt (see runImport() for the Why).
            $usageStr = $e instanceof FacebookRateLimitException && $e->usagePct !== null
                ? " (usage: {$e->usagePct}%)" : '';
            // Mark as queued (not failed) — the import will continue after the rate limit window.
            // Pass syncLogId so the next job reuses this log row rather than creating a new one.
            $syncLog->update([
                'status'       => 'queued',
                'error_message' => "Rate limited — retrying after {$e->retryAfter}s{$usageStr}",
            ]);
            self::dispatch($this->adAccountId, $this->workspaceId, $syncLog->id)
                ->delay(now()->addSeconds($e->retryAfter ?? 60))
                ->onQueue('low');
            $this->delete();
            return;
        } catch (Throwable $e) {
            $account->update(['historical_import_status' => 'failed']);

            $syncLog->update([
                'status'           => 'failed',
                'error_message'    => mb_substr($e->getMessage(), 0, 500),
                'completed_at'     => now(),
                'duration_seconds' => (int) max(0, (int) now()->diffInSeconds($syncLog->started_at)),
            ]);

            Alert::withoutGlobalScopes()->create([
                'workspace_id'  => $this->workspaceId,
                'ad_account_id' => $this->adAccountId,
                'type'          => "{$account->platform}_import_failed",
                'severity'      => 'warning',
                'data'          => [
                    'ad_account_name' => $account->name,
                    'error'           => mb_substr($e->getMessage(), 0, 255),
                ],
            ]);

            Log::error('AdHistoricalImportJob: failed', [
                'platform'      => $account->platform,
                'ad_account_id' => $this->adAccountId,
                'error'         => $e->getMessage(),
            ]);

            throw $e;
        }
        } finally {
            // Always release the concurrency lock — covers the success path, billing
            // bail-out return, rate-limit dispatch+return, and exception paths.
            $lock->release();
        }
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Iterate chunks from historical_import_from through yesterday, newest → oldest.
     * Facebook: 90-day async chunks. Google: 30-day synchronous chunks.
     * Writes checkpoint + progress after each chunk.
     *
     * @return int Total insight rows upserted across all chunks.
     */
    private function runImport(AdAccount $account, FxRateService $fxRates, SyncLog $syncLog): int
    {
        $importFrom = Carbon::parse($account->historical_import_from)->startOfDay();

        // Why: historical_import_from was clamped at job start, but a long-running import
        // with many retries can span weeks. If the 37-month window drifts past the stored date
        // between retries, re-clamp here so chunk submissions never exceed the Facebook API
        // hard limit (error #3018). Defense-in-depth on top of the handle() clamp.
        $hardFloor = now()->subMonths(37)->startOfDay();
        if ($importFrom->lt($hardFloor)) {
            $importFrom = $hardFloor;
        }

        $importTo   = Carbon::yesterday()->startOfDay();

        if ($importFrom->gt($importTo)) {
            return 0;
        }

        $totalDays     = (int) $importFrom->diffInDays($importTo) + 1;
        $totalImported = 0;

        // Import newest → oldest so recent data is available as soon as possible.
        // Why: the full 37-month import takes many retries on dev tier. Starting from
        // yesterday means the dashboard has actionable recent data even if the import
        // is interrupted before reaching historical data.
        //
        // $checkpoint is kept in sync with every DB write below (via array_merge) so that
        // merges never lose previously-written fields (e.g. structure_synced + date_cursor).
        $checkpoint = $account->historical_import_checkpoint ?? [];
        $chunkEnd   = isset($checkpoint['date_cursor'])
            ? Carbon::parse($checkpoint['date_cursor'])->startOfDay()
            : $importTo->copy();

        $completedDays = (int) $chunkEnd->diffInDays($importTo);

        // Sync structure once — skip on retries if already completed (saves 3+ API calls).
        // Why: on dev tier (max 60 points, proactive pause at 50% = 30 points), re-running
        // syncStructure on every rate-limit retry burns quota before any insight data lands.
        // The structure_synced flag is preserved through all subsequent checkpoint merges.
        $fbClient = null;
        if ($account->platform === 'facebook') {
            $accessToken = Crypt::decryptString($account->access_token_encrypted);
            $fbClient    = new FacebookAdsClient($accessToken);

            if (! ($checkpoint['structure_synced'] ?? false)) {
                $this->syncStructure($fbClient, $account, $this->workspaceId);
                $checkpoint = array_merge($checkpoint, ['structure_synced' => true]);
                $account->update(['historical_import_checkpoint' => $checkpoint]);
            }
        } elseif ($account->platform === 'google') {
            $client = GoogleAdsClient::forAccount($account);
            $this->syncGoogleCampaigns($client, $account, $account->external_id);
        }

        // 30-day chunks for both platforms.
        // Why: Facebook dev-tier BUC budget is 60 points. A 90-day async submission
        // consumed ~57 % of the budget per call, triggering rate limits on every retry
        // and preventing the import from ever completing. 30-day chunks cost ~19 %,
        // leaving headroom for polling + result-fetch calls within the same budget window.
        // Google also uses 30-day synchronous chunks (no async mode available).
        $chunkDays = 29;
        $chunkStep = 30;

        while ($chunkEnd->gte($importFrom)) {
            $chunkStart = $chunkEnd->copy()->subDays($chunkDays);

            if ($chunkStart->lt($importFrom)) {
                $chunkStart = $importFrom->copy();
            }

            $since = $chunkStart->toDateString();
            $until = $chunkEnd->toDateString();

            try {
                $chunkImported = match ($account->platform) {
                    'facebook' => $this->importFacebookChunk(
                        $fbClient ?? throw new \LogicException('fbClient not initialised for facebook platform'),
                        $account, $fxRates, $since, $until, $checkpoint, $completedDays, $totalDays
                    ),
                    'google'   => $this->importGoogleChunk($account, $fxRates, $since, $until),
                    default    => throw new \RuntimeException("Unsupported platform: {$account->platform}"),
                };
            } catch (FacebookRateLimitException | GoogleRateLimitException $e) {
                // Save date_cursor so we resume this chunk on the next attempt.
                // report_run_id and report_ready are already in $checkpoint (saved inside
                // importFacebookChunk before re-throwing) so the next run skips re-submitting.
                $checkpoint = array_merge($checkpoint, ['date_cursor' => $until]);
                $account->update(['historical_import_checkpoint' => $checkpoint]);
                throw $e;
            } catch (FacebookTokenExpiredException | GoogleTokenExpiredException $e) {
                $account->update([
                    'historical_import_status' => 'failed',
                    'status'                   => 'token_expired',
                ]);

                Alert::withoutGlobalScopes()->create([
                    'workspace_id'  => $this->workspaceId,
                    'ad_account_id' => $this->adAccountId,
                    'type'          => "{$account->platform}_token_expired",
                    'severity'      => 'critical',
                    'data'          => ['ad_account_name' => $account->name],
                ]);

                $syncLog->update([
                    'status'           => 'failed',
                    'error_message'    => $e->getMessage(),
                    'completed_at'     => now(),
                    'duration_seconds' => (int) max(0, (int) now()->diffInSeconds($syncLog->started_at)),
                ]);

                Log::error('AdHistoricalImportJob: token expired mid-import', [
                    'platform'      => $account->platform,
                    'ad_account_id' => $this->adAccountId,
                ]);

                $this->fail($e);
                return $totalImported;
            } catch (GoogleAccountDisabledException $e) {
                $account->update([
                    'historical_import_status' => 'failed',
                    'status'                   => 'disabled',
                ]);

                Alert::withoutGlobalScopes()->create([
                    'workspace_id'  => $this->workspaceId,
                    'ad_account_id' => $this->adAccountId,
                    'type'          => 'google_account_disabled',
                    'severity'      => 'warning',
                    'data'          => [
                        'ad_account_name' => $account->name,
                        'reason'          => $e->getMessage(),
                    ],
                ]);

                $syncLog->update([
                    'status'           => 'failed',
                    'error_message'    => $e->getMessage(),
                    'completed_at'     => now(),
                    'duration_seconds' => (int) max(0, (int) now()->diffInSeconds($syncLog->started_at)),
                ]);

                $this->fail($e);
                return $totalImported;
            }

            $totalImported += $chunkImported;
            $completedDays += (int) $chunkStart->diffInDays($chunkEnd) + 1;
            $progress       = (int) min(99, round(($completedDays / $totalDays) * 100));

            // Merge date_cursor — must preserve structure_synced and any other checkpoint fields.
            $checkpoint = array_merge($checkpoint, ['date_cursor' => $until]);
            $account->update([
                'historical_import_checkpoint' => $checkpoint,
                'historical_import_progress'   => $progress,
            ]);

            $syncLog->update(['records_processed' => $totalImported]);

            $chunkEnd->subDays($chunkStep);
        }

        return $totalImported;
    }

    // -------------------------------------------------------------------------
    // Platform chunk importers
    // -------------------------------------------------------------------------

    /**
     * Import a single chunk for all three insight levels (campaign, adset, ad).
     *
     * Optimization: all three async jobs are submitted simultaneously so Facebook
     * can compute them in parallel. A single polling loop waits for all three,
     * cutting ~30 s of poll-wait per chunk compared to submitting sequentially.
     *
     * Results are streamed page-by-page so memory stays bounded at ≤ 500 rows
     * regardless of result set size (ad-level 90-day sets can reach 10 k+ rows).
     *
     * Checkpoint structure — three phases, each level tracked independently:
     *
     *   Phase 1 — submit:  {level}_run_id is set once submitted (null = not yet submitted)
     *   Phase 2 — poll:    {level}_ready  is true once Facebook confirms completion
     *   Phase 3 — fetch:   {level}_done   is true once results are streamed and upserted
     *
     * On any retry, already-done levels are skipped; already-submitted levels resume
     * polling; already-ready levels skip straight to result fetching.
     *
     * @param  int  $completedDays  Days already imported before this chunk (for progress calc)
     * @param  int  $totalDays      Total import range in days (for progress calc)
     * @param array<string, mixed> $checkpoint passed by reference; updated in-place
     */
    private function importFacebookChunk(
        FacebookAdsClient $client,
        AdAccount $account,
        FxRateService $fxRates,
        string $since,
        string $until,
        array &$checkpoint,
        int $completedDays,
        int $totalDays,
    ): int {
        $levels = ['campaign', 'adset', 'ad'];

        // Reset level state when starting a new chunk date range.
        if (($checkpoint['chunk_since'] ?? null) !== $since || ($checkpoint['chunk_until'] ?? null) !== $until) {
            $checkpoint = array_merge($checkpoint, [
                'chunk_since'     => $since,
                'chunk_until'     => $until,
                'campaign_run_id' => null, 'campaign_ready' => false, 'campaign_done' => false,
                'adset_run_id'    => null, 'adset_ready'    => false, 'adset_done'    => false,
                'ad_run_id'       => null, 'ad_ready'       => false, 'ad_done'       => false,
            ]);
            $account->update(['historical_import_checkpoint' => $checkpoint]);
        }

        // ── Phase 1: Submit all unsubmitted levels at once ────────────────────
        // Submitting in one burst lets Facebook begin computing all three levels
        // in parallel rather than waiting for each to complete before starting the next.
        foreach ($levels as $level) {
            if ($checkpoint["{$level}_done"] ?? false) {
                continue; // already fetched — skip entirely
            }

            if (($checkpoint["{$level}_run_id"] ?? null) !== null) {
                Log::info('AdHistoricalImportJob: resuming existing async job', [
                    'level'         => $level,
                    'report_run_id' => $checkpoint["{$level}_run_id"],
                    'ready'         => $checkpoint["{$level}_ready"] ?? false,
                ]);
                continue; // already submitted in a previous attempt — don't re-submit
            }

            $runId = $client->submitAsyncInsightsJob($account->external_id, $since, $until, $level);
            $checkpoint["{$level}_run_id"] = $runId;
            $checkpoint["{$level}_ready"]  = false;
            // Persist immediately after each submission — not batched at the end.
            // Why: submitAsyncInsightsJob calls checkUsageHeaders internally after a
            // successful POST. If the BUC threshold is hit mid-burst, a
            // FacebookRateLimitException is thrown before we reach the batch-save
            // below, and the run_id for THIS level is in $checkpoint in memory but
            // never written to the DB. The next retry would then re-submit a brand-new
            // async job to Facebook instead of resuming the existing one, wasting API
            // budget and creating orphaned jobs. Saving per-submission prevents this.
            $account->update(['historical_import_checkpoint' => $checkpoint]);

            Log::info('AdHistoricalImportJob: submitted async job', [
                'level'         => $level,
                'report_run_id' => $runId,
                'since'         => $since,
                'until'         => $until,
            ]);
        }

        // ── Phase 2: Poll all pending levels together ─────────────────────────
        // One polling loop checks every unready level on each tick.
        // Once all levels with a run_id are ready, the loop exits.
        $this->waitForAllAsyncJobs($client, $checkpoint, $account, $levels);

        // ── Phase 3: Stream and upsert results for each level ─────────────────
        $count      = 0;
        $chunkDays  = (int) \Carbon\Carbon::parse($since)->diffInDays(\Carbon\Carbon::parse($until)) + 1;
        $levelTotal = count($levels);

        foreach ($levels as $levelIndex => $level) {
            if ($checkpoint["{$level}_done"] ?? false) {
                continue;
            }

            $runId = $checkpoint["{$level}_run_id"] ?? null;

            if ($runId === null) {
                // Shouldn't happen — all levels were submitted in Phase 1.
                Log::warning('AdHistoricalImportJob: no run_id for level after polling phase', [
                    'level' => $level, 'since' => $since, 'until' => $until,
                ]);
                continue;
            }

            $levelUpserted = 0;
            $client->streamAsyncJobResults($runId, function (array $page) use ($level, $account, $fxRates, &$count, &$levelUpserted): void {
                $n              = $this->upsertInsights($page, $level, $account, $fxRates);
                $count         += $n;
                $levelUpserted += $n;
            });

            $checkpoint["{$level}_done"]   = true;
            $checkpoint["{$level}_run_id"] = null;
            $checkpoint["{$level}_ready"]  = false;
            $account->update(['historical_import_checkpoint' => $checkpoint]);

            // Update intra-chunk progress after each level so the bar moves during long chunks.
            if ($totalDays > 0) {
                $chunkFraction = ($levelIndex + 1) / $levelTotal;
                $progressDays  = $completedDays + (int) round($chunkFraction * $chunkDays);
                $account->update(['historical_import_progress' => (int) min(99, round(($progressDays / $totalDays) * 100))]);
            }

            Log::info('AdHistoricalImportJob: level completed', [
                'level'    => $level,
                'upserted' => $levelUpserted,
                'since'    => $since,
                'until'    => $until,
            ]);
        }

        return $count;
    }

    /**
     * Poll all pending async jobs together until every non-done level is ready.
     *
     * Why a combined loop: submitting all three levels in parallel lets Facebook
     * compute them simultaneously. Polling them together means we wait only as long
     * as the slowest job (typically ~15 s), instead of 3 × ~15 s = 45 s sequentially.
     *
     * Each tick polls every unready level in sequence, then sleeps. This costs
     * 3 API calls per tick vs 1, but polling is cheap (doesn't count the same way
     * against the BUC rate-limit score as submission or result-fetch calls).
     *
     * Max wait: 15 minutes total. Well within the 7200 s job timeout.
     *
     * @param  string[]             $levels     ['campaign', 'adset', 'ad']
     * @param array<string, mixed> $checkpoint  passed by reference; updated in-place
     *
     * @throws \RuntimeException          if any job fails/times out
     * @throws FacebookRateLimitException if polling triggers a rate limit
     */
    private function waitForAllAsyncJobs(
        FacebookAdsClient $client,
        array &$checkpoint,
        AdAccount $account,
        array $levels,
    ): void {
        $maxWaitSeconds = 900;
        $elapsed        = 0;
        $interval       = 5; // start at 5 s, double up to 30 s

        while ($elapsed < $maxWaitSeconds) {
            // Check if every active level is already ready — exit immediately if so.
            $allReady = true;
            foreach ($levels as $level) {
                if ($checkpoint["{$level}_done"] ?? false) {
                    continue;
                }
                if (! ($checkpoint["{$level}_ready"] ?? false)) {
                    $allReady = false;
                    break;
                }
            }
            if ($allReady) {
                return;
            }

            sleep($interval);
            $elapsed  += $interval;
            $interval  = min(30, $interval * 2); // 5 → 10 → 20 → 30 s

            foreach ($levels as $level) {
                if ($checkpoint["{$level}_done"]  ?? false) continue;
                if ($checkpoint["{$level}_ready"] ?? false) continue;

                $runId = $checkpoint["{$level}_run_id"] ?? null;
                if ($runId === null) continue; // not submitted (shouldn't happen here)

                $status      = $client->pollAsyncJob($runId);
                $asyncStatus = (string) ($status['async_status'] ?? '');

                Log::info('AdHistoricalImportJob: async job poll', [
                    'level'         => $level,
                    'report_run_id' => $runId,
                    'status'        => $asyncStatus,
                    'pct'           => $status['async_percent_completion'] ?? 0,
                    'elapsed_s'     => $elapsed,
                    'ad_account_id' => $account->id,
                ]);

                if ($asyncStatus === 'Job Completed') {
                    $checkpoint["{$level}_ready"] = true;
                    $account->update(['historical_import_checkpoint' => $checkpoint]);
                    continue;
                }

                if (in_array($asyncStatus, ['Job Failed', 'Job Skipped'], strict: true)) {
                    $errorMsg  = (string) ($status['error_message'] ?? '');
                    $errorCode = isset($status['error_code']) ? " (code {$status['error_code']})" : '';
                    $detail    = $errorMsg !== '' ? ": {$errorMsg}{$errorCode}" : '';
                    throw new \RuntimeException(
                        "Facebook async insights job ended with status '{$asyncStatus}' [{$level}]: {$runId}{$detail}"
                    );
                }

                if (! in_array($asyncStatus, ['Job Not Started', 'Job Started', 'Job Running', ''], strict: true)) {
                    Log::warning('AdHistoricalImportJob: unrecognised async_status — continuing to poll', [
                        'level'         => $level,
                        'async_status'  => $asyncStatus,
                        'report_run_id' => $runId,
                        'elapsed_s'     => $elapsed,
                    ]);
                }
            }
        }

        throw new \RuntimeException(
            'Facebook async insights jobs timed out after ' . $maxWaitSeconds . 's'
            . ' (since=' . ($checkpoint['chunk_since'] ?? '?')
            . ' until=' . ($checkpoint['chunk_until'] ?? '?') . ')'
        );
    }

    private function importGoogleChunk(
        AdAccount $account,
        FxRateService $fxRates,
        string $since,
        string $until,
    ): int {
        $client = GoogleAdsClient::forAccount($account);

        $rows = $client->fetchCampaignInsights($account->external_id, $since, $until);

        return $this->upsertGoogleInsights($rows, $account, $fxRates);
    }
    // -------------------------------------------------------------------------
    // Billing + failure
    // -------------------------------------------------------------------------

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
     * Closes any running SyncLog rows. Does NOT touch consecutive_sync_failures —
     * that counter tracks rolling sync health, not historical import state.
     */
    public function failed(Throwable $e): void
    {
        if ($e instanceof FacebookRateLimitException || $e instanceof GoogleRateLimitException) {
            return;
        }

        if ($e instanceof FacebookTokenExpiredException || $e instanceof GoogleTokenExpiredException) {
            return;
        }

        if ($e instanceof GoogleAccountDisabledException) {
            return;
        }

        app(WorkspaceContext::class)->set($this->workspaceId);

        // Why job_type filter: without it, SyncAdInsightsJob's running log for the same
        // ad account would be clobbered with this job's error message and vice versa.
        SyncLog::withoutGlobalScopes()
            ->where('syncable_type', AdAccount::class)
            ->where('syncable_id', $this->adAccountId)
            ->where('workspace_id', $this->workspaceId)
            ->where('job_type', self::class)
            ->where('status', 'running')
            ->update([
                'status'        => 'failed',
                'error_message' => mb_substr($e->getMessage(), 0, 500),
                'completed_at'  => now(),
            ]);

        // Mark the ad account import as failed so the UI doesn't stay stuck on "running".
        AdAccount::withoutGlobalScopes()
            ->where('id', $this->adAccountId)
            ->where('historical_import_status', 'running')
            ->update(['historical_import_status' => 'failed']);

        Log::error('AdHistoricalImportJob: final failure after all retries', [
            'ad_account_id' => $this->adAccountId,
            'error'         => $e->getMessage(),
        ]);
    }
}
