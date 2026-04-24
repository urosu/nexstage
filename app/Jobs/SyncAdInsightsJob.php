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
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Syncs ad insights for a single ad account (Facebook or Google).
 *
 * Queue:   sync-facebook | sync-google-ads (per $platform)
 * Timeout: 300 s
 * Tries:   3
 * Backoff: [60, 300, 900] s (default)
 *
 * On every run:
 *   1. Sync campaign / adset / ad structure (idempotent upsert, gated to once/23h).
 *   2. Fetch daily insights for the last 3 days at campaign level.
 *   3. Fetch daily insights for the last 3 days at adset level.
 *   4. Fetch daily insights for the last 3 days at ad level.
 *   5. Convert spend to reporting_currency using FxRateService (DB-first).
 *
 * Dispatched hourly per active ad account via schedule closure in console.php.
 * Also dispatched immediately after a new ad account is connected.
 *
 * @see PLANNING.md section 22
 */
class SyncAdInsightsJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    use SyncsAdInsights;

    public int $timeout    = 300;
    public int $tries      = 3;
    public int $uniqueFor  = 330; // seconds; slightly longer than timeout so lock outlives the job

    /** @var array<int, int> */
    public array $backoff = [60, 300, 900];

    public function uniqueId(): string
    {
        return "{$this->adAccountId}:{$this->platform}";
    }

    public function __construct(
        private readonly int $adAccountId,
        private readonly int $workspaceId,
        private readonly string $platform = 'facebook',
    ) {
        // Route to provider-specific queue so a rate-limited FB job cannot block Google Ads sync.
        // See PLANNING.md section 22.1.
        $queue = match ($this->platform) {
            'google'   => 'sync-google-ads',
            default    => 'sync-facebook',
        };
        $this->onQueue($queue);
    }

    public function handle(FxRateService $fxRates): void
    {
        app(WorkspaceContext::class)->set($this->workspaceId);

        // Why: jobs queued before trial expiry must be discarded on pickup.
        // Dispatch filters in console.php prevent NEW dispatches for frozen workspaces,
        // but jobs already in the queue need this in-job guard. See PLANNING.md "14-day free trial".
        if ($this->isWorkspaceFrozen()) {
            Log::info('SyncAdInsightsJob: skipped — workspace trial expired', ['workspace_id' => $this->workspaceId]);
            return;
        }

        /** @var AdAccount|null $account */
        $account = AdAccount::withoutGlobalScopes()->find($this->adAccountId);

        if ($account === null) {
            Log::warning('SyncAdInsightsJob: ad account not found', [
                'ad_account_id' => $this->adAccountId,
            ]);
            return;
        }

        if ($account->status !== 'active') {
            return;
        }

        // Skip regular sync while a historical import is pending or running.
        // Why: both jobs share the same rate-limit bucket. SyncAdInsightsJob runs on the
        // 'default' queue (higher priority than 'low'), so it executes before the historical
        // import and can exhaust the dev-tier quota (60 points) before the import starts.
        // 'pending' is included because the controller sets that status before dispatching,
        // so the import hasn't started yet but will start shortly.
        if (in_array($account->historical_import_status, ['pending', 'running'], strict: true)) {
            Log::info('SyncAdInsightsJob: skipping — historical import pending or in progress', [
                'ad_account_id' => $this->adAccountId,
                'import_status' => $account->historical_import_status,
            ]);
            return;
        }

        // Concurrency lock — prevent two SyncAdInsightsJob instances from running
        // simultaneously for the same ad account. Without this, the scheduler could
        // dispatch a second sync before the first completes, doubling API usage.
        $lock = Cache::lock("sync_ad_insights_{$this->adAccountId}", 600);
        if (! $lock->get()) {
            Log::info('SyncAdInsightsJob: skipping — another instance is already running', [
                'ad_account_id' => $this->adAccountId,
            ]);
            return;
        }

        // Initialise to null so catch blocks can use ?-> if SyncLog::create() itself throws
        // (e.g. DB unreachable). The finally block releases the lock in all cases.
        $syncLog = null;

        try {
            $syncLog = SyncLog::create([
                'workspace_id'      => $this->workspaceId,
                'syncable_type'     => AdAccount::class,
                'syncable_id'       => $this->adAccountId,
                'job_type'          => self::class,
                'status'            => 'running',
                'started_at'        => now(),
                'queue'             => $this->queue,
                'attempt'           => $this->attempts(),
                'timeout_seconds'   => $this->timeout,
            ]);

            $recordsProcessed = match ($account->platform) {
                'facebook' => $this->syncFacebook($account, $fxRates),
                'google'   => $this->syncGoogle($account, $fxRates),
                default    => throw new \RuntimeException("Unsupported platform: {$account->platform}"),
            };

            // Mark success
            $account->update([
                'consecutive_sync_failures' => 0,
                'last_synced_at'            => now(),
                // Only restore to 'active' if it was previously 'error'
                'status' => $account->status === 'error' ? 'active' : $account->status,
            ]);

            $syncLog->update([
                'status'            => 'completed',
                'records_processed' => $recordsProcessed,
                'completed_at'      => now(),
                'duration_seconds'  => max(0, (int) now()->diffInSeconds($syncLog->started_at)),
            ]);
        } catch (FacebookRateLimitException | GoogleRateLimitException $e) {
            // Close the sync log so it doesn't stay stuck at 'running'
            $usageStr = $e instanceof FacebookRateLimitException && $e->usagePct !== null
                ? " (usage: {$e->usagePct}%)" : '';
            $syncLog?->update([
                'status'           => 'failed',
                'error_message'    => "Rate limited — retrying after {$e->retryAfter}s{$usageStr}",
                'completed_at'     => now(),
                'duration_seconds' => max(0, (int) now()->diffInSeconds($syncLog->started_at)),
            ]);
            // Why: release() increments the attempt counter, so 3 rate-limit hits would
            // exhaust tries=3 and trigger failed() with MaxAttemptsExceededException, which
            // falsely increments consecutive_sync_failures. Dispatching a fresh job resets
            // the attempt counter so rate limits never pollute the failure health check.
            // Preserve the same provider queue so a re-queued FB job stays on sync-facebook.
            self::dispatch($this->adAccountId, $this->workspaceId, $this->platform)
                ->delay(now()->addSeconds($e->retryAfter));
            $this->delete();
            return;
        } catch (FacebookTokenExpiredException | GoogleTokenExpiredException $e) {
            $this->markTokenExpired($account, $syncLog, $e);
            $this->fail($e);
            return;
        } catch (GoogleAccountDisabledException $e) {
            $this->markAccountDisabled($account, $syncLog, $e);
            $this->fail($e);
            return;
        } catch (Throwable $e) {
            if ($syncLog !== null) {
                $this->handleSyncFailure($account, $syncLog, $e);
            }
            throw $e;
        } finally {
            $lock->release();
        }
    }

    // -------------------------------------------------------------------------
    // Platform dispatchers
    // -------------------------------------------------------------------------

    /**
     * Run the full Facebook Ads sync and return the number of records processed.
     *
     * Syncs structure on every run (fast — 3 calls) and fetches insights for all 3 levels
     * in parallel via pool to minimize wall time.
     */
    private function syncFacebook(AdAccount $account, FxRateService $fxRates): int
    {
        $accessToken = Crypt::decryptString($account->access_token_encrypted);
        $client      = new FacebookAdsClient($accessToken);

        $since = now()->subDays(3)->toDateString();
        $until = now()->toDateString();

        // Sync structure on every run. Why: with pooled insights fetches, the structure
        // cost (3 sequential calls) is negligible. Always syncing ensures campaigns/adsets/ads
        // are up-to-date without stale-data caveat from hourly gating.
        $this->syncStructure($client, $account, $this->workspaceId, includeCreative: false);

        // Pool all 3 insight levels in parallel (campaign, adset, ad).
        // Reduces 3 sequential calls to 1 HTTP/2 round-trip.
        // Campaign-level: powers the Campaigns page (queries level='campaign').
        // Adset-level: powers adset breakdowns.
        // Ad-level: powers Winners/Losers and ad-level breakdowns.
        // All three are needed — campaign_id IS NULL on ad rows per constraint,
        // so you can't derive campaign totals from ad rows.
        $insightsPool = $client->fetchInsightsPool(
            $account->external_id,
            [
                'campaign' => [
                    'level'                => 'campaign',
                    'since'                => $since,
                    'until'                => $until,
                    'filterZeroImpressions' => true,
                ],
                'adset' => [
                    'level'                => 'adset',
                    'since'                => $since,
                    'until'                => $until,
                    'filterZeroImpressions' => true,
                ],
                'ad' => [
                    'level'                => 'ad',
                    'since'                => $since,
                    'until'                => $until,
                    'filterZeroImpressions' => true,
                ],
            ],
        );

        $count  = $this->upsertInsights($insightsPool['campaign'] ?? [], 'campaign', $account, $fxRates);
        $count += $this->upsertInsights($insightsPool['adset'] ?? [], 'adset', $account, $fxRates);
        $count += $this->upsertInsights($insightsPool['ad'] ?? [], 'ad', $account, $fxRates);

        return $count;
    }

    /**
     * Run the full Google Ads sync (campaign-level only; no hourly data) and
     * return the number of records processed.
     *
     * Batches structure and insights queries via searchStreamPool for faster execution.
     */
    private function syncGoogle(AdAccount $account, FxRateService $fxRates): int
    {
        $client     = GoogleAdsClient::forAccount($account);
        $customerId = $account->external_id;
        $since      = now()->subDays(3)->toDateString();
        $until      = now()->toDateString();

        // Pool both structure and insights in one batch (2 GAQL queries, 1 HTTP/2 round-trip)
        $poolRequests = [
            'campaigns' => [
                'customerId' => $customerId,
                'gaql' => <<<'GAQL'
                    SELECT campaign.id, campaign.name, campaign.status, campaign.advertising_channel_type,
                           campaign_budget.amount_micros, campaign_budget.period,
                           campaign.bidding_strategy_type,
                           campaign.target_cpa.target_cpa_micros, campaign.target_roas.target_roas
                    FROM campaign
                    WHERE campaign.status != 'REMOVED'
                    GAQL,
            ],
            'insights' => [
                'customerId' => $customerId,
                'gaql' => <<<GAQL
                    SELECT campaign.id, campaign.name, campaign.status, campaign.advertising_channel_type,
                           metrics.cost_micros, metrics.impressions, metrics.clicks,
                           metrics.conversions, metrics.search_impression_share, segments.date
                    FROM campaign
                    WHERE segments.date BETWEEN '{$since}' AND '{$until}'
                      AND campaign.status != 'REMOVED'
                    GAQL,
            ],
        ];

        $poolResults = $client->searchStreamPool($poolRequests);

        // Sync campaign structure from pooled results
        if (isset($poolResults['campaigns'])) {
            $this->syncGoogleCampaignsFromRows($poolResults['campaigns'], $account);
        }

        // Upsert insights from pooled results
        $count = 0;
        if (isset($poolResults['insights'])) {
            $count = $this->upsertGoogleInsights($poolResults['insights'], $account, $fxRates);
        }

        return $count;
    }


    // -------------------------------------------------------------------------
    // Failure handling
    // -------------------------------------------------------------------------

    /**
     * Called by Laravel after all retry attempts are exhausted (including when
     * MaxAttemptsExceededException is thrown before handle() even runs, e.g.
     * when a failed job is retried from Horizon with a depleted attempt count).
     *
     * This is the single place where consecutive_sync_failures is incremented
     * and alerts are created — once per job dispatch, not once per retry.
     */
    public function failed(Throwable $e): void
    {
        // Rate-limit releases re-queue without consuming an attempt — not a true failure.
        if ($e instanceof FacebookRateLimitException || $e instanceof GoogleRateLimitException) {
            return;
        }

        // Token-expiry and disabled paths call $this->fail() inline and are already handled.
        if ($e instanceof FacebookTokenExpiredException || $e instanceof GoogleTokenExpiredException) {
            return;
        }

        if ($e instanceof GoogleAccountDisabledException) {
            return;
        }

        app(WorkspaceContext::class)->set($this->workspaceId);

        /** @var AdAccount|null $account */
        $account = AdAccount::withoutGlobalScopes()->find($this->adAccountId);

        if ($account === null) {
            return;
        }

        // Close ALL running sync logs for this job type — not just the most recent.
        // When MaxAttemptsExceededException fires before handle() can run, no new sync log
        // is created for that phantom attempt, so only previously-opened logs need closing.
        // Why job_type filter: without it, AdHistoricalImportJob's running log for the same
        // ad account would be clobbered with this job's error message.
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

        DB::transaction(function () use ($account, $e): void {
            $failures = $account->consecutive_sync_failures + 1;

            $account->update([
                'consecutive_sync_failures' => $failures,
                'status'                    => $failures >= 3 ? 'error' : $account->status,
            ]);

            Alert::withoutGlobalScopes()->create([
                'workspace_id'  => $this->workspaceId,
                'ad_account_id' => $this->adAccountId,
                'type'          => "{$account->platform}_sync_failure",
                'severity'      => $failures >= 3 ? 'critical' : 'warning',
                'data'          => [
                    'ad_account_name'      => $account->name,
                    'consecutive_failures' => $failures,
                    'error'                => $e->getMessage(),
                ],
            ]);
        });

        Log::error('SyncAdInsightsJob: final failure after all retries', [
            'platform'      => $account->platform,
            'ad_account_id' => $this->adAccountId,
            'error'         => $e->getMessage(),
        ]);
    }

    private function markTokenExpired(AdAccount $account, ?SyncLog $syncLog, Throwable $e): void
    {
        $account->update(['status' => 'token_expired']);

        Alert::withoutGlobalScopes()->create([
            'workspace_id'  => $this->workspaceId,
            'ad_account_id' => $this->adAccountId,
            'type'          => "{$account->platform}_token_expired",
            'severity'      => 'critical',
            'data'          => ['ad_account_name' => $account->name],
        ]);

        $syncLog?->update([
            'status'           => 'failed',
            'error_message'    => $e->getMessage(),
            'completed_at'     => now(),
            'duration_seconds' => max(0, (int) now()->diffInSeconds($syncLog->started_at)),
        ]);

        Log::error('SyncAdInsightsJob: token expired', [
            'platform'      => $account->platform,
            'ad_account_id' => $this->adAccountId,
        ]);
    }

    private function markAccountDisabled(AdAccount $account, ?SyncLog $syncLog, Throwable $e): void
    {
        $account->update(['status' => 'disabled']);

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

        $syncLog?->update([
            'status'           => 'failed',
            'error_message'    => $e->getMessage(),
            'completed_at'     => now(),
            'duration_seconds' => max(0, (int) now()->diffInSeconds($syncLog->started_at)),
        ]);

        Log::warning('SyncAdInsightsJob: Google account disabled (CUSTOMER_NOT_ENABLED)', [
            'ad_account_id' => $this->adAccountId,
            'ad_account'    => $account->name,
        ]);
    }

    /**
     * Update the sync log for this attempt. Account-level side effects
     * (consecutive_sync_failures, alerts) are handled in failed() after all retries.
     */
    private function handleSyncFailure(AdAccount $account, SyncLog $syncLog, Throwable $e): void
    {
        $syncLog->update([
            'status'           => 'failed',
            'error_message'    => $e->getMessage(),
            'completed_at'     => now(),
            'duration_seconds' => max(0, (int) now()->diffInSeconds($syncLog->started_at)),
        ]);

        Log::warning('SyncAdInsightsJob: attempt failed', [
            'platform'      => $account->platform,
            'ad_account_id' => $this->adAccountId,
            'attempt'       => $this->attempts(),
            'error'         => $e->getMessage(),
        ]);
    }

    /**
     * Returns true if the workspace's trial has expired and no paid plan is active.
     * Used to discard jobs that were queued before trial expiry.
     */
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
