<?php

declare(strict_types=1);

namespace App\Jobs\Concerns;

use App\Exceptions\FxRateNotFoundException;
use App\Models\Ad;
use App\Models\AdAccount;
use App\Models\AdInsight;
use App\Models\Adset;
use App\Models\Campaign;
use App\Models\Workspace;
use App\Services\CampaignNameParserService;
use App\Services\Fx\FxRateService;
use App\Services\Integrations\Facebook\FacebookAdsClient;
use App\Services\Integrations\Google\GoogleAdsClient;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Shared ad insights sync logic used by SyncAdInsightsJob and AdHistoricalImportJob.
 *
 * Related: app/Jobs/SyncAdInsightsJob.php (hourly sync)
 * Related: app/Jobs/AdHistoricalImportJob.php (one-time full import)
 *
 * Consuming classes MUST declare: private readonly int $workspaceId
 */
trait SyncsAdInsights
{
    // -------------------------------------------------------------------------
    // Facebook — structure sync (campaigns, adsets, ads)
    // -------------------------------------------------------------------------

    /**
     * Upsert Facebook campaign/adset/ad structure for the given ad account.
     *
     * Budget amounts from Facebook are in account-currency cents (integer) — divide by 100.
     *
     * @param  bool $includeCreative  Fetch creative fields for ads.creative_data JSONB.
     *                                Pass false on hourly structure syncs to save API calls.
     *                                Pass true for historical imports. See PLANNING.md "ads.creative_data".
     */
    private function syncStructure(
        FacebookAdsClient $client,
        AdAccount $account,
        int $workspaceId,
        bool $includeCreative = true,
    ): void {
        // Campaigns
        $campaigns = $client->fetchCampaigns($account->external_id);

        foreach ($campaigns as $row) {
            // Budget: Facebook returns amounts in account currency cents (integer).
            // Only one of daily_budget/lifetime_budget will be non-zero per campaign.
            $rawDailyBudget    = (int) ($row['daily_budget'] ?? 0);
            $rawLifetimeBudget = (int) ($row['lifetime_budget'] ?? 0);
            $dailyBudget       = $rawDailyBudget > 0    ? $rawDailyBudget    / 100 : null;
            $lifetimeBudget    = $rawLifetimeBudget > 0 ? $rawLifetimeBudget / 100 : null;
            $budgetType        = $dailyBudget !== null ? 'daily' : ($lifetimeBudget !== null ? 'lifetime' : null);

            // target_cost_cap is a CPA target in account currency cents
            $rawTargetCost = isset($row['target_cost_cap']) ? (int) $row['target_cost_cap'] : null;
            $targetValue   = $rawTargetCost !== null && $rawTargetCost > 0
                ? $rawTargetCost / 100
                : null;

            $campaign = Campaign::withoutGlobalScopes()->updateOrCreate(
                ['ad_account_id' => $account->id, 'external_id' => (string) $row['id']],
                [
                    'workspace_id'    => $workspaceId,
                    'name'            => (string) ($row['name'] ?? ''),
                    'status'          => (string) ($row['effective_status'] ?? ''),
                    'objective'       => isset($row['objective']) ? (string) $row['objective'] : null,
                    'daily_budget'    => $dailyBudget,
                    'lifetime_budget' => $lifetimeBudget,
                    'budget_type'     => $budgetType,
                    'bid_strategy'    => isset($row['bid_strategy']) ? (string) $row['bid_strategy'] : null,
                    'target_value'    => $targetValue,
                ]
            );

            app(CampaignNameParserService::class)->parseAndSave($campaign);
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
        // Pass includeCreative: false on hourly syncs to avoid the nested Graph API call
        // cost (creative fields require an extra sub-request per ad).
        $ads = $client->fetchAds($account->external_id, includeCreative: $includeCreative);

        // Build adset external→internal map for all adsets in this account
        $adsetMap = Adset::withoutGlobalScopes()
            ->whereIn('campaign_id', $campaignMap->values())
            ->pluck('id', 'external_id');

        foreach ($ads as $row) {
            $adsetId = $adsetMap[(string) $row['adset_id']] ?? null;

            if ($adsetId === null) {
                continue;
            }

            $creative       = $row['creative'] ?? null;
            $destinationUrl = $creative['object_url'] ?? null;

            // Capture creative fields for ads.creative_data JSONB.
            // Phase 2: consumed by correlation engine to detect creative-level anomalies.
            // See PLANNING.md "ads.creative_data"
            $creativeData = null;
            if (is_array($creative) && count($creative) > 0) {
                $creativeData = array_filter([
                    'object_url'          => $creative['object_url'] ?? null,
                    'title'               => $creative['title'] ?? null,
                    'body'                => $creative['body'] ?? null,
                    'image_url'           => $creative['image_url'] ?? null,
                    'thumbnail_url'       => $creative['thumbnail_url'] ?? null,
                    'call_to_action_type' => $creative['call_to_action_type'] ?? null,
                ], static fn ($v) => $v !== null);
                if (empty($creativeData)) {
                    $creativeData = null;
                }
            }

            Ad::withoutGlobalScopes()->updateOrCreate(
                ['adset_id' => $adsetId, 'external_id' => (string) $row['id']],
                [
                    'workspace_id'              => $workspaceId,
                    'name'                      => isset($row['name']) ? (string) $row['name'] : null,
                    'status'                    => (string) ($row['effective_status'] ?? ''),
                    'effective_status'          => (string) ($row['effective_status'] ?? ''),
                    'destination_url'           => $destinationUrl !== null ? (string) $destinationUrl : null,
                    'creative_data'             => $creativeData,
                    'creative_data_api_version' => $creativeData !== null ? 'v25.0' : null,
                ]
            );
        }
    }

    // -------------------------------------------------------------------------
    // Google — structure sync
    // -------------------------------------------------------------------------

    /**
     * Upsert Google Ads campaigns into the campaigns table.
     *
     * Budget: returned in micros (1/1,000,000 of the currency unit). period='DAILY' → daily
     * budget; 'FIXED' → lifetime budget. See PLANNING.md "campaigns" schema.
     */
    private function syncGoogleCampaigns(
        GoogleAdsClient $client,
        AdAccount $account,
        string $customerId,
    ): void {
        $rows = $client->fetchCampaigns($customerId);

        foreach ($rows as $row) {
            $campaign       = $row['campaign'] ?? [];
            $campaignBudget = $row['campaignBudget'] ?? [];
            $external       = (string) ($campaign['id'] ?? '');

            if ($external === '') {
                continue;
            }

            // Budget: Google returns amount in micros (1/1,000,000 of the currency unit).
            // period = 'DAILY' → daily budget; 'FIXED' → lifetime budget.
            $budgetMicros = (float) ($campaignBudget['amountMicros'] ?? 0);
            $budgetAmount = $budgetMicros > 0 ? $budgetMicros / 1_000_000 : null;
            $period       = strtoupper((string) ($campaignBudget['period'] ?? ''));
            $budgetType   = match ($period) {
                'DAILY' => 'daily',
                'FIXED' => 'lifetime',
                default => null,
            };
            $dailyBudget    = $budgetType === 'daily'    ? $budgetAmount : null;
            $lifetimeBudget = $budgetType === 'lifetime' ? $budgetAmount : null;

            // target_value: prefer CPA target (in micros), then ROAS target (multiplier, e.g. 3.5).
            $targetCpaMicros = (float) ($campaign['targetCpa']['targetCpaMicros'] ?? 0);
            $targetRoas      = (float) ($campaign['targetRoas']['targetRoas'] ?? 0);
            $targetValue     = null;
            if ($targetCpaMicros > 0) {
                $targetValue = $targetCpaMicros / 1_000_000;
            } elseif ($targetRoas > 0) {
                $targetValue = $targetRoas;
            }

            $campaignModel = Campaign::withoutGlobalScopes()->updateOrCreate(
                ['ad_account_id' => $account->id, 'external_id' => $external],
                [
                    'workspace_id'    => $this->workspaceId,
                    'name'            => (string) ($campaign['name'] ?? ''),
                    'status'          => (string) ($campaign['status'] ?? ''),
                    'objective'       => isset($campaign['advertisingChannelType'])
                        ? (string) $campaign['advertisingChannelType']
                        : null,
                    'daily_budget'    => $dailyBudget,
                    'lifetime_budget' => $lifetimeBudget,
                    'budget_type'     => $budgetType,
                    'bid_strategy'    => isset($campaign['biddingStrategyType'])
                        ? (string) $campaign['biddingStrategyType']
                        : null,
                    'target_value'    => $targetValue,
                ]
            );

            app(CampaignNameParserService::class)->parseAndSave($campaignModel);
        }
    }

    // -------------------------------------------------------------------------
    // Facebook — insight upsert
    // -------------------------------------------------------------------------

    /**
     * Map Facebook Ads Insights API rows to ad_insights and upsert.
     *
     * Builds a batch array of all valid rows and upserts them in a single
     * INSERT … ON CONFLICT ON CONSTRAINT … DO UPDATE statement.
     * This replaces the previous per-row updateOrCreate() pattern which fired
     * SELECT + INSERT/UPDATE per row — a bottleneck on historical imports.
     *
     * FK maps are built lazily: only the maps required for the given level are
     * queried, saving 1–2 DB queries per call for campaign-level and adset-level calls.
     *
     * spend_in_reporting_currency is computed here using FxRateService (DB-first).
     * If an FX rate is unavailable, the field is left NULL and RetryMissingConversionJob
     * will back-fill it nightly.
     *
     * @param  array<int, array<string, mixed>> $rows
     * @param  string  $level  'campaign', 'adset', or 'ad'
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

        // Build only the FK maps needed for this level to avoid unnecessary DB queries.
        // Why: campaign-level calls never use adset/ad maps; adset-level never uses ad map.
        $campaignMap = Campaign::withoutGlobalScopes()
            ->where('ad_account_id', $account->id)
            ->pluck('id', 'external_id');

        $adsetMap = ($level === 'adset' || $level === 'ad')
            ? Adset::withoutGlobalScopes()
                ->whereIn('campaign_id', $campaignMap->values())
                ->pluck('id', 'external_id')
            : collect();

        $adMap = $level === 'ad'
            ? Ad::withoutGlobalScopes()
                ->whereIn('adset_id', $adsetMap->values())
                ->pluck('id', 'external_id')
            : collect();

        $now   = now()->toDateTimeString();
        $batch = [];

        foreach ($rows as $row) {
            $date     = Carbon::parse((string) $row['date_start']);
            $spend    = (float) ($row['spend'] ?? 0);
            $currency = strtoupper((string) ($row['account_currency'] ?? $account->currency));

            // FX conversion (DB-first; NULL on missing rate).
            // RetryMissingConversionJob back-fills spend_in_reporting_currency nightly.
            $spendConverted = null;
            try {
                $spendConverted = $fxRates->convert($spend, $currency, $reportingCurrency, $date);
            } catch (FxRateNotFoundException $e) {
                Log::warning('Ad insights sync: FX rate not found, leaving spend_in_reporting_currency NULL', [
                    'currency'    => $currency,
                    'date'        => $date->toDateString(),
                    'ad_account'  => $account->id,
                    'level'       => $level,
                    'job'         => static::class,
                ]);
            }

            // Resolve FKs — log when a lookup misses so sync gaps are visible in logs.
            $campaignId = null;
            if (isset($row['campaign_id'])) {
                $campaignId = $campaignMap[(string) $row['campaign_id']] ?? null;
                if ($campaignId === null && $level === 'campaign') {
                    Log::warning('Ad insights sync: campaign FK not found — skipping row', [
                        'external_campaign_id' => $row['campaign_id'],
                        'ad_account'           => $account->id,
                        'date'                 => $date->toDateString(),
                        'job'                  => static::class,
                    ]);
                }
            }

            $adsetId = null;
            if (isset($row['adset_id'])) {
                $adsetId = $adsetMap[(string) $row['adset_id']] ?? null;
                if ($adsetId === null && $level === 'adset') {
                    Log::warning('Ad insights sync: adset FK not found — skipping row', [
                        'external_adset_id' => $row['adset_id'],
                        'ad_account'        => $account->id,
                        'date'              => $date->toDateString(),
                        'job'               => static::class,
                    ]);
                }
            }

            $adId = null;
            if (isset($row['ad_id'])) {
                $adId = $adMap[(string) $row['ad_id']] ?? null;
                if ($adId === null && $level === 'ad') {
                    Log::warning('Ad insights sync: ad FK not found — skipping row', [
                        'external_ad_id' => $row['ad_id'],
                        'ad_account'     => $account->id,
                        'date'           => $date->toDateString(),
                        'job'            => static::class,
                    ]);
                }
            }

            // Skip rows where the required FK for this level is missing.
            if ($level === 'campaign' && $campaignId === null) {
                continue;
            }
            if ($level === 'adset' && $adsetId === null) {
                continue;
            }
            if ($level === 'ad' && $adId === null) {
                continue;
            }

            // Build raw_insights JSONB.
            // See PLANNING.md "ad_insights.raw_insights"
            $rawInsights = null;
            if (isset($row['actions']) || isset($row['action_values'])) {
                $rawInsights = array_filter([
                    'actions'       => $row['actions'] ?? null,
                    'action_values' => $row['action_values'] ?? null,
                ], static fn ($v) => $v !== null);
                if (empty($rawInsights)) {
                    $rawInsights = null;
                }
            }

            $batch[] = [
                'workspace_id'                => $this->workspaceId,
                'ad_account_id'               => $account->id,
                'level'                       => $level,
                // Constraint ad_insights_level_fk_check enforces:
                //   campaign: campaign_id NOT NULL, ad_id NULL
                //   adset:    adset_id NOT NULL, campaign_id NULL, ad_id NULL
                //   ad:       ad_id NOT NULL, campaign_id NULL
                'campaign_id'                 => $level === 'campaign' ? $campaignId : null,
                'adset_id'                    => $level === 'ad' ? $adsetId : ($level === 'adset' ? $adsetId : null),
                'ad_id'                       => $level === 'ad' ? $adId : null,
                'date'                        => $date->toDateString(),
                'hour'                        => null,
                'spend'                       => $spend,
                'spend_in_reporting_currency' => $spendConverted,
                'impressions'                 => (int) ($row['impressions'] ?? 0),
                'clicks'                      => (int) ($row['clicks'] ?? 0),
                'reach'                       => isset($row['reach']) ? (int) $row['reach'] : null,
                'frequency'                   => isset($row['frequency']) ? (float) $row['frequency'] : null,
                'platform_conversions'        => $this->extractPurchaseConversions($row),
                'platform_conversions_value'  => $this->extractPurchaseConversionsValue($row),
                'search_impression_share'     => null,
                'platform_roas'               => $this->extractRoas($row),
                'currency'                    => $currency,
                'raw_insights'                => $rawInsights !== null ? json_encode($rawInsights) : null,
                'raw_insights_api_version'    => $rawInsights !== null ? 'v25.0' : null,
                'created_at'                  => $now,
                'updated_at'                  => $now,
            ];
        }

        if (empty($batch)) {
            return 0;
        }

        $this->batchUpsertAdInsights($batch, $level);

        return count($batch);
    }

    /**
     * Execute a single INSERT … ON CONFLICT … DO UPDATE for a batch of ad_insights rows.
     *
     * Uses the inline partial-index conflict target (column list + WHERE predicate) instead
     * of ON CONFLICT ON CONSTRAINT, because the unique indexes are created as named indexes
     * (CREATE UNIQUE INDEX) rather than named constraints (ADD CONSTRAINT … UNIQUE). Postgres
     * only accepts ON CONFLICT ON CONSTRAINT for the latter.
     *
     * Conflict targets mirror the partial unique index definitions exactly:
     *   campaign: (campaign_id, date) WHERE level = 'campaign' AND hour IS NULL
     *   adset:    (adset_id,   date) WHERE level = 'adset'    AND hour IS NULL
     *   ad:       (ad_id,      date) WHERE level = 'ad'       AND hour IS NULL
     *
     * @param list<array<string, mixed>> $batch
     */
    private function batchUpsertAdInsights(array $batch, string $level): void
    {
        $conflictTarget = match ($level) {
            'campaign' => "(campaign_id, date) WHERE level = 'campaign' AND hour IS NULL",
            'adset'    => "(adset_id, date) WHERE level = 'adset' AND hour IS NULL",
            'ad'       => "(ad_id, date) WHERE level = 'ad' AND hour IS NULL",
            default    => throw new \InvalidArgumentException("Unknown level: {$level}"),
        };

        $columns = [
            'workspace_id', 'ad_account_id', 'level',
            'campaign_id', 'adset_id', 'ad_id',
            'date', 'hour',
            'spend', 'spend_in_reporting_currency',
            'impressions', 'clicks', 'reach', 'frequency',
            'platform_conversions', 'platform_conversions_value',
            'search_impression_share', 'platform_roas',
            'currency', 'raw_insights', 'raw_insights_api_version',
            'created_at', 'updated_at',
        ];

        $placeholderGroup = '(' . implode(', ', array_fill(0, count($columns), '?')) . ')';
        $placeholders     = implode(', ', array_fill(0, count($batch), $placeholderGroup));

        $updateSet = implode(', ', array_map(
            fn (string $col) => "{$col} = EXCLUDED.{$col}",
            ['workspace_id', 'ad_account_id', 'spend', 'spend_in_reporting_currency',
             'impressions', 'clicks', 'reach', 'frequency',
             'platform_conversions', 'platform_conversions_value',
             'search_impression_share', 'platform_roas',
             'currency', 'raw_insights', 'raw_insights_api_version', 'updated_at'],
        ));

        $columnList = implode(', ', $columns);
        $bindings   = [];

        foreach ($batch as $row) {
            foreach ($columns as $col) {
                $bindings[] = $row[$col] ?? null;
            }
        }

        DB::statement("
            INSERT INTO ad_insights ({$columnList})
            VALUES {$placeholders}
            ON CONFLICT {$conflictTarget}
            DO UPDATE SET {$updateSet}
        ", $bindings);
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
     *
     * Batches all rows into a single INSERT … ON CONFLICT … DO UPDATE.
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

        $now   = now()->toDateTimeString();
        $batch = [];

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
            } catch (FxRateNotFoundException $e) {
                Log::warning('Ad insights sync (Google): FX rate not found, leaving spend_in_reporting_currency NULL', [
                    'currency'    => $currency,
                    'date'        => $dateStr,
                    'ad_account'  => $account->id,
                    'job'         => static::class,
                ]);
            }

            // search_impression_share: Google returns as a 0.0–1.0 float.
            // Values like "> 0.9" are rounded by the API; we store as-is.
            $searchImpressionShare = isset($metrics['searchImpressionShare'])
                ? (float) $metrics['searchImpressionShare']
                : null;

            $batch[] = [
                'workspace_id'                => $this->workspaceId,
                'ad_account_id'               => $account->id,
                'level'                       => 'campaign',
                'campaign_id'                 => $campaignId,
                'adset_id'                    => null,
                'ad_id'                       => null,
                'date'                        => $dateStr,
                'hour'                        => null,
                'spend'                       => $spend,
                'spend_in_reporting_currency' => $spendConverted,
                'impressions'                 => (int) ($metrics['impressions'] ?? 0),
                'clicks'                      => (int) ($metrics['clicks'] ?? 0),
                'reach'                       => null,
                'frequency'                   => null,
                'platform_conversions'        => isset($metrics['conversions']) ? (float) $metrics['conversions'] : null,
                'platform_conversions_value'  => null,
                'search_impression_share'     => $searchImpressionShare,
                'platform_roas'               => null,
                'currency'                    => $currency,
                'raw_insights'                => null,
                'raw_insights_api_version'    => null,
                'created_at'                  => $now,
                'updated_at'                  => $now,
            ];
        }

        if (empty($batch)) {
            return 0;
        }

        $this->batchUpsertAdInsights($batch, 'campaign');

        return count($batch);
    }

    // -------------------------------------------------------------------------
    // Helpers — Facebook data extraction
    // -------------------------------------------------------------------------

    /**
     * Extract the blended ROAS value from the purchase_roas array.
     *
     * Facebook returns purchase_roas as an array of objects, one per purchase action_type:
     *   [{"action_type":"omni_purchase","value":"3.14"}, {"action_type":"offsite_conversion.fb_pixel_purchase","value":"2.80"}]
     *
     * We prefer omni_purchase (Meta's unified cross-channel metric), then fall back to the
     * first entry with a "value" key so we don't silently break if Meta reorders the array.
     *
     * Each entry may also contain attribution window variants alongside "value"
     * (e.g. "1d_click", "7d_click", "1d_view"). The "value" key always holds the default
     * window total (active attribution window at time of reporting).
     */
    private function extractRoas(array $row): ?float
    {
        if (! isset($row['purchase_roas']) || ! is_array($row['purchase_roas'])) {
            return null;
        }

        foreach (['omni_purchase', null] as $preferred) {
            foreach ($row['purchase_roas'] as $entry) {
                if (($preferred === null || ($entry['action_type'] ?? null) === $preferred) && isset($entry['value'])) {
                    return (float) $entry['value'];
                }
            }
        }

        return null;
    }

    /**
     * Extract purchase conversion count from the actions array.
     *
     * We look for action_type 'purchase' (unified default since June 2025 Meta attribution
     * change) or 'omni_purchase' (the pre-June 2025 unified metric). Both may appear
     * depending on account vintage and reporting window settings.
     *
     * See PLANNING.md "ad_insights.platform_conversions"
     */
    private function extractPurchaseConversions(array $row): ?float
    {
        if (! isset($row['actions']) || ! is_array($row['actions'])) {
            return null;
        }

        foreach ($row['actions'] as $action) {
            if (in_array($action['action_type'] ?? '', ['purchase', 'omni_purchase'], strict: true)) {
                return isset($action['value']) ? (float) $action['value'] : null;
            }
        }

        return null;
    }

    /**
     * Extract purchase conversion value from the action_values array.
     *
     * Mirrors the structure of the actions array but contains revenue amounts rather than counts.
     * Value is in account currency — stored raw alongside the currency column and converted
     * to reporting currency separately via FxRateService.
     */
    private function extractPurchaseConversionsValue(array $row): ?float
    {
        if (! isset($row['action_values']) || ! is_array($row['action_values'])) {
            return null;
        }

        foreach ($row['action_values'] as $action) {
            if (in_array($action['action_type'] ?? '', ['purchase', 'omni_purchase'], strict: true)) {
                return isset($action['value']) ? (float) $action['value'] : null;
            }
        }

        return null;
    }
}
