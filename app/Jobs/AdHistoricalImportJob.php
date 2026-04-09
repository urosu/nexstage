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
 * Imports the full ad insights history for a Facebook or Google Ads account.
 *
 * Queue:   low
 * Timeout: 7200 s (2 hours)
 * Tries:   5
 * Backoff: default [60, 300, 900] s
 *
 * Design decisions:
 *  - Processes 30-day chunks — ad insight APIs are efficient with date ranges.
 *  - Fetches campaign-level and ad-level (Facebook only) insights per chunk.
 *  - No FX prefetch per chunk — FxRateService is DB-first; missing rates leave
 *    spend_in_reporting_currency = NULL, filled nightly by RetryMissingConversionJob.
 *  - Checkpoint (historical_import_checkpoint) records the current chunk start
 *    date so retries resume without re-processing already-imported chunks.
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

    public int $timeout = 7200;
    public int $tries   = 5;

    public function __construct(
        private readonly int $adAccountId,
        private readonly int $workspaceId,
    ) {
        $this->onQueue('low');
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

        // Billing gate — checked at runtime so expiry during a long import is caught on retry.
        $workspace = Workspace::withoutGlobalScopes()
            ->select(['id', 'trial_ends_at', 'billing_plan'])
            ->find($this->workspaceId);

        if ($workspace !== null && $this->isBillingExpired($workspace)) {
            $account->update(['historical_import_status' => 'failed']);

            SyncLog::create([
                'workspace_id'      => $this->workspaceId,
                'syncable_type'     => AdAccount::class,
                'syncable_id'       => $this->adAccountId,
                'job_type'          => self::class,
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

        if ($account->historical_import_from === null) {
            Log::error('AdHistoricalImportJob: historical_import_from is null, nothing to import', [
                'ad_account_id' => $this->adAccountId,
            ]);
            return;
        }

        // Preserve the original start time across retries.
        $account->update([
            'historical_import_status'     => 'running',
            'historical_import_started_at' => $account->historical_import_started_at ?? now(),
        ]);

        $syncLog = SyncLog::create([
            'workspace_id'      => $this->workspaceId,
            'syncable_type'     => AdAccount::class,
            'syncable_id'       => $this->adAccountId,
            'job_type'          => self::class,
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
                'completed_at'      => now(),
                'duration_seconds'  => (int) max(0, (int) now()->diffInSeconds($syncLog->started_at)),
            ]);

            Log::info('AdHistoricalImportJob: completed', [
                'platform'       => $account->platform,
                'ad_account_id'  => $this->adAccountId,
                'total_imported' => $totalImported,
            ]);
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
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Iterate 30-day chunks from historical_import_from through yesterday.
     * Writes checkpoint + progress after each chunk.
     *
     * @return int Total insight rows upserted across all chunks.
     */
    private function runImport(AdAccount $account, FxRateService $fxRates, SyncLog $syncLog): int
    {
        $importFrom = Carbon::parse($account->historical_import_from)->startOfDay();
        $importTo   = Carbon::yesterday()->startOfDay();

        if ($importFrom->gt($importTo)) {
            return 0;
        }

        $totalDays     = (int) $importFrom->diffInDays($importTo) + 1;
        $totalImported = 0;

        // Resume from checkpoint when retrying after a failure or rate-limit release.
        $checkpoint  = $account->historical_import_checkpoint;
        $chunkStart  = isset($checkpoint['date_cursor'])
            ? Carbon::parse($checkpoint['date_cursor'])->startOfDay()
            : $importFrom->copy();

        $completedDays = (int) $importFrom->diffInDays($chunkStart);

        while ($chunkStart->lte($importTo)) {
            $chunkEnd = $chunkStart->copy()->addDays(29);

            if ($chunkEnd->gt($importTo)) {
                $chunkEnd = $importTo->copy();
            }

            $since = $chunkStart->toDateString();
            $until = $chunkEnd->toDateString();

            try {
                $chunkImported = match ($account->platform) {
                    'facebook' => $this->importFacebookChunk($account, $fxRates, $since, $until),
                    'google'   => $this->importGoogleChunk($account, $fxRates, $since, $until),
                    default    => throw new \RuntimeException("Unsupported platform: {$account->platform}"),
                };
            } catch (FacebookRateLimitException | GoogleRateLimitException $e) {
                $account->update([
                    'historical_import_checkpoint' => ['date_cursor' => $since],
                ]);
                $this->release($e->retryAfter ?? 60);
                return $totalImported;
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

            $account->update([
                'historical_import_checkpoint' => ['date_cursor' => $since],
                'historical_import_progress'   => $progress,
            ]);

            $syncLog->update(['records_processed' => $totalImported]);

            $chunkStart->addDays(30);
        }

        return $totalImported;
    }

    // -------------------------------------------------------------------------
    // Platform chunk importers
    // -------------------------------------------------------------------------

    private function importFacebookChunk(
        AdAccount $account,
        FxRateService $fxRates,
        string $since,
        string $until,
    ): int {
        $accessToken = Crypt::decryptString($account->access_token_encrypted);
        $client      = new FacebookAdsClient($accessToken);

        $this->syncStructure($client, $account, $this->workspaceId);

        $campaignInsights = $client->fetchInsights($account->external_id, 'campaign', $since, $until);
        $adInsights       = $client->fetchInsights($account->external_id, 'ad', $since, $until);

        return $this->upsertInsights($campaignInsights, 'campaign', $account, $fxRates)
             + $this->upsertInsights($adInsights, 'ad', $account, $fxRates);
    }

    private function importGoogleChunk(
        AdAccount $account,
        FxRateService $fxRates,
        string $since,
        string $until,
    ): int {
        $client     = GoogleAdsClient::forAccount($account);
        $customerId = $account->external_id;

        $this->syncGoogleCampaigns($client, $account, $customerId);

        $rows = $client->fetchCampaignInsights($customerId, $since, $until);

        return $this->upsertGoogleInsights($rows, $account, $fxRates);
    }

    // -------------------------------------------------------------------------
    // Structure sync — copied from SyncAdInsightsJob (idempotent, no state)
    // -------------------------------------------------------------------------

    private function syncStructure(FacebookAdsClient $client, AdAccount $account, int $workspaceId): void
    {
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

        $adsets = $client->fetchAdsets($account->external_id);

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

        $ads = $client->fetchAds($account->external_id);

        $adsetMap = Adset::withoutGlobalScopes()
            ->whereIn('campaign_id', $campaignMap->values())
            ->pluck('id', 'external_id');

        foreach ($ads as $row) {
            $adsetId = $adsetMap[(string) $row['adset_id']] ?? null;

            if ($adsetId === null) {
                continue;
            }

            Ad::withoutGlobalScopes()->updateOrCreate(
                ['adset_id' => $adsetId, 'external_id' => (string) $row['id']],
                [
                    'workspace_id'    => $workspaceId,
                    'name'            => isset($row['name']) ? (string) $row['name'] : null,
                    'status'          => (string) ($row['effective_status'] ?? ''),
                    'destination_url' => isset($row['creative']['object_url'])
                        ? (string) $row['creative']['object_url']
                        : null,
                ]
            );
        }
    }

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
    // Insight upserts — copied from SyncAdInsightsJob
    // -------------------------------------------------------------------------

    /**
     * @param  array<int, array<string, mixed>> $rows
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
            $date     = Carbon::parse((string) $row['date_start']);
            $spend    = (float) ($row['spend'] ?? 0);
            $currency = strtoupper((string) ($row['account_currency'] ?? $account->currency));

            $spendConverted = null;
            try {
                $spendConverted = $fxRates->convert($spend, $currency, $reportingCurrency, $date);
            } catch (\App\Exceptions\FxRateNotFoundException $e) {
                Log::warning('AdHistoricalImportJob: FX rate not found, leaving spend_in_reporting_currency NULL', [
                    'currency'   => $currency,
                    'date'       => $date->toDateString(),
                    'ad_account' => $account->id,
                ]);
            }

            $campaignId = isset($row['campaign_id'])
                ? ($campaignMap[(string) $row['campaign_id']] ?? null)
                : null;

            $adsetId = isset($row['adset_id'])
                ? ($adsetMap[(string) $row['adset_id']] ?? null)
                : null;

            $adId = isset($row['ad_id'])
                ? ($adMap[(string) $row['ad_id']] ?? null)
                : null;

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

            $date     = Carbon::parse($dateStr);
            $spend    = (float) ($metrics['costMicros'] ?? 0) / 1_000_000;
            $currency = $account->currency;

            $spendConverted = null;
            try {
                $spendConverted = $fxRates->convert($spend, $currency, $reportingCurrency, $date);
            } catch (\App\Exceptions\FxRateNotFoundException $e) {
                Log::warning('AdHistoricalImportJob (Google): FX rate not found, leaving spend_in_reporting_currency NULL', [
                    'currency'   => $currency,
                    'date'       => $dateStr,
                    'ad_account' => $account->id,
                ]);
            }

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
                    'reach'                       => null,
                    'ctr'                         => isset($metrics['ctr']) ? (float) $metrics['ctr'] : null,
                    'cpc'                         => isset($metrics['averageCpc'])
                        ? (float) $metrics['averageCpc'] / 1_000_000
                        : null,
                    'platform_roas' => null,
                    'currency'      => $currency,
                ]
            );

            $count++;
        }

        return $count;
    }

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
    // Billing + failure
    // -------------------------------------------------------------------------

    private function isBillingExpired(Workspace $workspace): bool
    {
        return $workspace->trial_ends_at !== null
            && $workspace->trial_ends_at->lt(now())
            && $workspace->billing_plan === null;
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

        Log::error('AdHistoricalImportJob: final failure after all retries', [
            'ad_account_id' => $this->adAccountId,
            'error'         => $e->getMessage(),
        ]);
    }
}
