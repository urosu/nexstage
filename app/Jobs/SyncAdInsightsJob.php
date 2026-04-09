<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Exceptions\FacebookRateLimitException;
use App\Exceptions\FacebookTokenExpiredException;
use App\Exceptions\GoogleAccountDisabledException;
use App\Exceptions\GoogleRateLimitException;
use App\Exceptions\GoogleTokenExpiredException;
use App\Models\Ad;
use App\Models\AdAccount;
use App\Models\AdInsight;
use App\Models\Adset;
use App\Models\Alert;
use App\Models\Campaign;
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
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Syncs ad insights for a single Facebook ad account.
 *
 * Queue:   default
 * Timeout: 300 s
 * Tries:   3
 * Backoff: [60, 300, 900] s (default)
 *
 * On every run:
 *   1. Sync campaign / adset / ad structure (idempotent upsert).
 *   2. Fetch daily insights for the last 3 days at campaign level.
 *   3. Fetch daily insights for the last 3 days at ad level.
 *   4. Convert spend to reporting_currency using FxRateService (DB-first).
 *
 * Dispatched every 3 hours per active ad account via schedule closure in console.php.
 * Also dispatched immediately after a new Facebook ad account is connected.
 */
class SyncAdInsightsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 300;
    public int $tries   = 3;

    /** @var array<int, int> */
    public array $backoff = [60, 300, 900];

    public function __construct(
        private readonly int $adAccountId,
        private readonly int $workspaceId,
    ) {
        $this->onQueue('default');
    }

    public function handle(FxRateService $fxRates): void
    {
        app(WorkspaceContext::class)->set($this->workspaceId);

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

        $syncLog = SyncLog::create([
            'workspace_id'      => $this->workspaceId,
            'syncable_type'     => AdAccount::class,
            'syncable_id'       => $this->adAccountId,
            'job_type'          => self::class,
            'status'            => 'running',
            'started_at'        => now(),
        ]);

        try {
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
            $syncLog->update([
                'status'           => 'failed',
                'error_message'    => "Rate limited — retrying after {$e->retryAfter}s",
                'completed_at'     => now(),
                'duration_seconds' => max(0, (int) now()->diffInSeconds($syncLog->started_at)),
            ]);
            // Re-queue without consuming an attempt
            $this->release($e->retryAfter);
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
            $this->handleSyncFailure($account, $syncLog, $e);
            throw $e;
        }
    }

    // -------------------------------------------------------------------------
    // Platform dispatchers
    // -------------------------------------------------------------------------

    /**
     * Run the full Facebook Ads sync and return the number of records processed.
     */
    private function syncFacebook(AdAccount $account, FxRateService $fxRates): int
    {
        $accessToken = Crypt::decryptString($account->access_token_encrypted);
        $client      = new FacebookAdsClient($accessToken);

        $since = now()->subDays(3)->toDateString();
        $until = now()->toDateString();

        $this->syncStructure($client, $account, $this->workspaceId);

        $campaignInsights = $client->fetchInsights($account->external_id, 'campaign', $since, $until);
        $adInsights       = $client->fetchInsights($account->external_id, 'ad', $since, $until);

        return $this->upsertInsights($campaignInsights, 'campaign', $account, $fxRates)
             + $this->upsertInsights($adInsights, 'ad', $account, $fxRates);
    }

    /**
     * Run the full Google Ads sync (campaign-level only; no hourly data) and
     * return the number of records processed.
     */
    private function syncGoogle(AdAccount $account, FxRateService $fxRates): int
    {
        $client = GoogleAdsClient::forAccount($account);

        $since      = now()->subDays(3)->toDateString();
        $until      = now()->toDateString();
        $customerId = $account->external_id;

        // Sync campaign structure
        $this->syncGoogleCampaigns($client, $account, $customerId);

        // Sync campaign-level insights
        $rows = $client->fetchCampaignInsights($customerId, $since, $until);

        return $this->upsertGoogleInsights($rows, $account, $fxRates);
    }

    // -------------------------------------------------------------------------
    // Structure sync — campaigns, adsets, ads
    // -------------------------------------------------------------------------

    private function syncStructure(FacebookAdsClient $client, AdAccount $account, int $workspaceId): void
    {
        // Campaigns
        $campaigns = $client->fetchCampaigns($account->external_id);

        foreach ($campaigns as $row) {
            Campaign::withoutGlobalScopes()->updateOrCreate(
                ['ad_account_id' => $account->id, 'external_id' => (string) $row['id']],
                [
                    'workspace_id' => $workspaceId,
                    'name'         => (string) ($row['name'] ?? ''),
                    'status'       => (string) ($row['effective_status'] ?? ''),
                    'objective'    => isset($row['objective']) ? (string) $row['objective'] : null,
                ]
            );
        }

        // Adsets — keyed by external campaign_id so we can resolve the internal campaign FK
        $adsets = $client->fetchAdsets($account->external_id);

        // Build campaign external→internal map for this account
        $campaignMap = Campaign::withoutGlobalScopes()
            ->where('ad_account_id', $account->id)
            ->pluck('id', 'external_id');

        foreach ($adsets as $row) {
            $campaignId = $campaignMap[(string) $row['campaign_id']] ?? null;

            if ($campaignId === null) {
                continue;
            }

            Adset::withoutGlobalScopes()->updateOrCreate(
                ['campaign_id' => $campaignId, 'external_id' => (string) $row['id']],
                [
                    'workspace_id' => $workspaceId,
                    'name'         => (string) ($row['name'] ?? ''),
                    'status'       => (string) ($row['effective_status'] ?? ''),
                ]
            );
        }

        // Ads — keyed by external adset_id
        $ads = $client->fetchAds($account->external_id);

        // Build adset external→internal map for all adsets in this account
        $adsetMap = Adset::withoutGlobalScopes()
            ->whereIn('campaign_id', $campaignMap->values())
            ->pluck('id', 'external_id');

        foreach ($ads as $row) {
            $adsetId = $adsetMap[(string) $row['adset_id']] ?? null;

            if ($adsetId === null) {
                continue;
            }

            $destinationUrl = $row['creative']['object_url'] ?? null;

            Ad::withoutGlobalScopes()->updateOrCreate(
                ['adset_id' => $adsetId, 'external_id' => (string) $row['id']],
                [
                    'workspace_id'    => $workspaceId,
                    'name'            => isset($row['name']) ? (string) $row['name'] : null,
                    'status'          => (string) ($row['effective_status'] ?? ''),
                    'destination_url' => $destinationUrl !== null ? (string) $destinationUrl : null,
                ]
            );
        }
    }

    // -------------------------------------------------------------------------
    // Google — structure sync
    // -------------------------------------------------------------------------

    /**
     * Upsert campaigns returned from Google Ads GAQL into the campaigns table.
     * Google Ads has no adsets or ads at this tier (campaign-level only per spec).
     */
    private function syncGoogleCampaigns(
        GoogleAdsClient $client,
        AdAccount $account,
        string $customerId,
    ): void {
        $rows = $client->fetchCampaigns($customerId);

        foreach ($rows as $row) {
            $campaign = $row['campaign'] ?? [];
            $external = (string) ($campaign['id'] ?? '');

            if ($external === '') {
                continue;
            }

            Campaign::withoutGlobalScopes()->updateOrCreate(
                ['ad_account_id' => $account->id, 'external_id' => $external],
                [
                    'workspace_id' => $this->workspaceId,
                    'name'         => (string) ($campaign['name'] ?? ''),
                    'status'       => (string) ($campaign['status'] ?? ''),
                    'objective'    => isset($campaign['advertisingChannelType'])
                        ? (string) $campaign['advertisingChannelType']
                        : null,
                ]
            );
        }
    }

    // -------------------------------------------------------------------------
    // Google — insight upsert
    // -------------------------------------------------------------------------

    /**
     * Map Google Ads GAQL insight rows to ad_insights and upsert.
     *
     * Per spec:
     *   - campaign level only
     *   - hour is always NULL (Google has no hourly data)
     *   - reach and platform_roas are always NULL
     *   - spend = metrics.cost_micros ÷ 1,000,000
     *   - cpc  = metrics.average_cpc  ÷ 1,000,000
     *
     * @param  list<array<string, mixed>> $rows
     */
    private function upsertGoogleInsights(
        array $rows,
        AdAccount $account,
        FxRateService $fxRates,
    ): int {
        if (empty($rows)) {
            return 0;
        }

        $workspace         = Workspace::withoutGlobalScopes()->find($this->workspaceId);
        $reportingCurrency = $workspace?->reporting_currency ?? 'EUR';

        $campaignMap = Campaign::withoutGlobalScopes()
            ->where('ad_account_id', $account->id)
            ->pluck('id', 'external_id');

        $count = 0;

        foreach ($rows as $row) {
            $campaign = $row['campaign'] ?? [];
            $metrics  = $row['metrics'] ?? [];
            $segments = $row['segments'] ?? [];

            $externalCampaignId = (string) ($campaign['id'] ?? '');
            $dateStr            = (string) ($segments['date'] ?? '');

            if ($externalCampaignId === '' || $dateStr === '') {
                continue;
            }

            $campaignId = $campaignMap[$externalCampaignId] ?? null;

            if ($campaignId === null) {
                continue;
            }

            $date    = Carbon::parse($dateStr);
            $spend   = (float) ($metrics['costMicros'] ?? 0) / 1_000_000;
            $currency = $account->currency;

            $spendConverted = null;
            try {
                $spendConverted = $fxRates->convert($spend, $currency, $reportingCurrency, $date);
            } catch (\App\Exceptions\FxRateNotFoundException $e) {
                Log::warning('SyncAdInsightsJob (Google): FX rate not found, leaving spend_in_reporting_currency NULL', [
                    'currency'    => $currency,
                    'date'        => $dateStr,
                    'ad_account'  => $account->id,
                ]);
            }

            $ctr = isset($metrics['ctr']) ? (float) $metrics['ctr'] : null;
            // average_cpc comes in micros
            $cpc = isset($metrics['averageCpc'])
                ? (float) $metrics['averageCpc'] / 1_000_000
                : null;

            AdInsight::withoutGlobalScopes()->updateOrCreate(
                [
                    'level'       => 'campaign',
                    'campaign_id' => $campaignId,
                    'date'        => $dateStr,
                    'hour'        => null,
                ],
                [
                    'workspace_id'                => $this->workspaceId,
                    'ad_account_id'               => $account->id,
                    'adset_id'                    => null,
                    'ad_id'                       => null,
                    'spend'                       => $spend,
                    'spend_in_reporting_currency' => $spendConverted,
                    'impressions'                 => (int) ($metrics['impressions'] ?? 0),
                    'clicks'                      => (int) ($metrics['clicks'] ?? 0),
                    'reach'                       => null,  // not available in Google Ads
                    'ctr'                         => $ctr,
                    'cpc'                         => $cpc,
                    'platform_roas'               => null,  // not available in Google Ads
                    'currency'                    => $currency,
                ]
            );

            $count++;
        }

        return $count;
    }

    // -------------------------------------------------------------------------
    // Insight upsert
    // -------------------------------------------------------------------------

    /**
     * Upsert a batch of insight rows and return the count processed.
     *
     * Facebook insight rows never have an hour value (daily only at this level).
     * Partial indexes on ad_insights require per-row updateOrCreate rather than
     * bulk upsert(), since Laravel's upsert() cannot target PostgreSQL partial indexes.
     *
     * spend_in_reporting_currency is computed here using FxRateService (DB-first).
     * If an FX rate is unavailable, the field is left NULL and RetryMissingConversionJob
     * will back-fill it nightly.
     *
     * @param  array<int, array<string, mixed>> $rows
     * @param  string  $level  'campaign' or 'ad'
     * @return int  Number of rows processed
     */
    private function upsertInsights(
        array $rows,
        string $level,
        AdAccount $account,
        FxRateService $fxRates,
    ): int {
        if (empty($rows)) {
            return 0;
        }

        $workspace         = Workspace::withoutGlobalScopes()->find($this->workspaceId);
        $reportingCurrency = $workspace?->reporting_currency ?? 'EUR';

        // Build structure maps for FK resolution
        $campaignMap = Campaign::withoutGlobalScopes()
            ->where('ad_account_id', $account->id)
            ->pluck('id', 'external_id');

        $adsetMap = Adset::withoutGlobalScopes()
            ->whereIn('campaign_id', $campaignMap->values())
            ->pluck('id', 'external_id');

        $adMap = Ad::withoutGlobalScopes()
            ->whereIn('adset_id', $adsetMap->values())
            ->pluck('id', 'external_id');

        $count = 0;

        foreach ($rows as $row) {
            $date    = Carbon::parse((string) $row['date_start']);
            $spend   = (float) ($row['spend'] ?? 0);
            $currency = strtoupper((string) ($row['account_currency'] ?? $account->currency));

            // FX conversion (DB-first; NULL on missing rate)
            $spendConverted = null;
            try {
                $spendConverted = $fxRates->convert($spend, $currency, $reportingCurrency, $date);
            } catch (\App\Exceptions\FxRateNotFoundException $e) {
                Log::warning('SyncAdInsightsJob: FX rate not found, leaving spend_in_reporting_currency NULL', [
                    'currency'    => $currency,
                    'date'        => $date->toDateString(),
                    'ad_account'  => $account->id,
                ]);
            }

            // Resolve FKs
            $campaignId = isset($row['campaign_id'])
                ? ($campaignMap[(string) $row['campaign_id']] ?? null)
                : null;

            $adsetId = isset($row['adset_id'])
                ? ($adsetMap[(string) $row['adset_id']] ?? null)
                : null;

            $adId = isset($row['ad_id'])
                ? ($adMap[(string) $row['ad_id']] ?? null)
                : null;

            // Build the unique-key conditions that match the partial index
            $uniqueKeys = match ($level) {
                'campaign' => [
                    'level'       => 'campaign',
                    'campaign_id' => $campaignId,
                    'date'        => $date->toDateString(),
                    'hour'        => null,
                ],
                'ad' => [
                    'level'  => 'ad',
                    'ad_id'  => $adId,
                    'date'   => $date->toDateString(),
                    'hour'   => null,
                ],
                default => [],
            };

            if (empty($uniqueKeys) || ($level === 'campaign' && $campaignId === null)) {
                continue;
            }

            if ($level === 'ad' && $adId === null) {
                continue;
            }

            AdInsight::withoutGlobalScopes()->updateOrCreate(
                $uniqueKeys,
                [
                    'workspace_id'                => $this->workspaceId,
                    'ad_account_id'               => $account->id,
                    'campaign_id'                 => $campaignId,
                    'adset_id'                    => $adsetId,
                    'ad_id'                       => $adId,
                    'spend'                       => $spend,
                    'spend_in_reporting_currency' => $spendConverted,
                    'impressions'                 => (int) ($row['impressions'] ?? 0),
                    'clicks'                      => (int) ($row['clicks'] ?? 0),
                    'reach'                       => isset($row['reach']) ? (int) $row['reach'] : null,
                    'ctr'                         => isset($row['ctr']) ? (float) $row['ctr'] : null,
                    'cpc'                         => isset($row['cpc']) ? (float) $row['cpc'] : null,
                    'platform_roas'               => $this->extractRoas($row),
                    'currency'                    => $currency,
                ]
            );

            $count++;
        }

        return $count;
    }

    /**
     * Extract the purchase_roas value from the insight row.
     *
     * Facebook returns purchase_roas as an array: [{"action_type":"omni_purchase","value":"3.14"}]
     */
    private function extractRoas(array $row): ?float
    {
        if (! isset($row['purchase_roas']) || ! is_array($row['purchase_roas'])) {
            return null;
        }

        foreach ($row['purchase_roas'] as $entry) {
            if (isset($entry['value'])) {
                return (float) $entry['value'];
            }
        }

        return null;
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

        // Close ALL running sync logs for this ad account — not just the most recent.
        // When MaxAttemptsExceededException fires before handle() can run, no new sync log
        // is created for that phantom attempt, so only previously-opened logs need closing.
        // Using withoutGlobalScopes() to be safe: WorkspaceContext is set above, but
        // the scope's WHERE would be redundant given the explicit workspace_id filter.
        SyncLog::withoutGlobalScopes()
            ->where('syncable_type', AdAccount::class)
            ->where('syncable_id', $this->adAccountId)
            ->where('workspace_id', $this->workspaceId)
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

    private function markTokenExpired(AdAccount $account, SyncLog $syncLog, Throwable $e): void
    {
        $account->update(['status' => 'token_expired']);

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
            'duration_seconds' => max(0, (int) now()->diffInSeconds($syncLog->started_at)),
        ]);

        Log::error('SyncAdInsightsJob: token expired', [
            'platform'      => $account->platform,
            'ad_account_id' => $this->adAccountId,
        ]);
    }

    private function markAccountDisabled(AdAccount $account, SyncLog $syncLog, Throwable $e): void
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

        $syncLog->update([
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
}
