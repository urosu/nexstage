<?php

declare(strict_types=1);

namespace App\Services\Integrations\Facebook;

use App\Exceptions\FacebookApiException;
use App\Exceptions\FacebookRateLimitException;
use App\Exceptions\FacebookTokenExpiredException;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * HTTP client for the Facebook Graph API v25.0.
 *
 * All methods perform cursor-based pagination internally and return flat arrays.
 * Rate limits and token expiry are surfaced as typed exceptions so jobs can
 * handle them per CLAUDE.md §Rate Limit Handling.
 *
 * Never called from the request cycle — only from queue jobs.
 */
class FacebookAdsClient
{
    private const BASE_URL = 'https://graph.facebook.com/v25.0';

    // Documented BUC throttling codes from Meta's Marketing API docs.
    // 17/613: app-level burst limits. 4: insights-specific. 80000/80003/80004/80014: ad-account throttling.
    private const RATE_LIMIT_CODES = [4, 17, 613, 80000, 80003, 80004, 80014];

    // Dev tier: max score 60, decay 300 s, blocked 300 s when reached.
    // Standard tier: ~190,000 + 400×active_ads calls/hour — orders of magnitude more headroom.
    // Set FB_API_TIER=standard in .env once App Review grants Advanced Access.
    //
    // Why 57% not 50%: structure sync (campaigns+adsets+ads) burns ~50% of dev quota.
    // A 50% threshold would fire immediately after structure sync, before any insights land.
    // 57% gives enough headroom to submit the async job + poll + fetch one results page.
    // Hard cap is 60%, so 57% still leaves a 3-point safety buffer.
    private const DEV_TIER_THRESHOLD      = 57;  // % — 3% below dev tier hard cap of 60
    private const STANDARD_TIER_THRESHOLD = 88;  // % — plenty of headroom on standard tier

    public function __construct(private readonly string $accessToken) {}

    // -------------------------------------------------------------------------
    // Ad account structure
    // -------------------------------------------------------------------------

    /**
     * Return all ad accounts accessible to the authenticated user.
     *
     * @return array<int, array{id: string, name: string, currency: string}>
     *
     * @throws FacebookTokenExpiredException
     * @throws FacebookRateLimitException
     * @throws FacebookApiException
     */
    public function fetchAdAccounts(): array
    {
        return $this->paginate('/me/adaccounts', [
            'fields' => 'id,name,currency',
        ]);
    }

    /**
     * Return all ad accounts accessible to the user, including accounts managed
     * via Facebook Business Manager.
     *
     * Merges /me/adaccounts (personal) with accounts from every business the
     * user belongs to, deduplicating by account ID.
     *
     * Business endpoint failures are non-fatal — the user may have no Business
     * Manager memberships, or a business may have restricted access.
     *
     * @return array<int, array{id: string, name: string, currency: string}>
     *
     * @throws FacebookTokenExpiredException
     * @throws FacebookRateLimitException
     * @throws FacebookApiException
     */
    public function fetchAllAdAccounts(): array
    {
        $accounts = [];
        $seen     = [];

        $addIfNew = static function (array $account) use (&$accounts, &$seen): void {
            $id = ltrim((string) ($account['id'] ?? ''), 'act_');
            if ($id !== '' && ! isset($seen[$id])) {
                $accounts[] = $account;
                $seen[$id]  = true;
            }
        };

        // Personal ad accounts (always fetched; throws on hard errors)
        foreach ($this->fetchAdAccounts() as $account) {
            $addIfNew($account);
        }

        // Business Manager ad accounts (non-fatal if the user has no businesses)
        try {
            $businesses = $this->paginate('/me/businesses', ['fields' => 'id,name']);

            foreach ($businesses as $business) {
                $businessId = (string) ($business['id'] ?? '');

                if ($businessId === '') {
                    continue;
                }

                try {
                    $bizAccounts = $this->paginate("/{$businessId}/adaccounts", [
                        'fields' => 'id,name,currency',
                    ]);

                    foreach ($bizAccounts as $account) {
                        $addIfNew($account);
                    }
                } catch (FacebookApiException $e) {
                    Log::warning('FacebookAdsClient: could not fetch ad accounts for business', [
                        'business_id' => $businessId,
                        'error'       => $e->getMessage(),
                    ]);
                }
            }
        } catch (FacebookApiException $e) {
            // Non-fatal: user may have no Business Manager memberships
            Log::info('FacebookAdsClient: /me/businesses fetch skipped or failed', [
                'error' => $e->getMessage(),
            ]);
        }

        return $accounts;
    }

    /**
     * Return all campaigns for the given ad account external ID.
     *
     * Includes budget and bid strategy fields needed for anomaly detection and
     * ad spend anomaly cross-reference. See PLANNING.md "campaigns" schema.
     *
     * Budget amounts are returned in account currency cents (integer).
     * Only one of daily_budget/lifetime_budget will be non-zero per campaign.
     *
     * @return array<int, array<string, mixed>>
     *
     * @throws FacebookTokenExpiredException
     * @throws FacebookRateLimitException
     * @throws FacebookApiException
     */
    public function fetchCampaigns(string $externalAccountId): array
    {
        return $this->paginate("/act_{$externalAccountId}/campaigns", [
            'fields' => 'id,name,effective_status,objective,daily_budget,lifetime_budget,bid_strategy,target_cost_cap',
            'limit'  => 200,
        ]);
    }

    /**
     * Return all adsets for the given ad account external ID.
     *
     * @return array<int, array{id: string, name: string, status: string, campaign_id: string}>
     *
     * @throws FacebookTokenExpiredException
     * @throws FacebookRateLimitException
     * @throws FacebookApiException
     */
    public function fetchAdsets(string $externalAccountId): array
    {
        return $this->paginate("/act_{$externalAccountId}/adsets", [
            'fields' => 'id,name,effective_status,campaign_id',
            'limit'  => 200,
        ]);
    }

    /**
     * Return all ads for the given ad account external ID.
     *
     * @param  bool $includeCreative  Whether to fetch creative fields (object_url, title, body, etc.)
     *                                Pass false for regular structure syncs to reduce API cost.
     *                                Pass true for historical imports where creative_data JSONB is populated.
     *                                Phase 2: creative_data consumed by correlation engine.
     *                                See PLANNING.md "ads.creative_data".
     *
     * @return array<int, array<string, mixed>>
     *
     * @throws FacebookTokenExpiredException
     * @throws FacebookRateLimitException
     * @throws FacebookApiException
     */
    public function fetchAds(string $externalAccountId, bool $includeCreative = true): array
    {
        $fields = $includeCreative
            ? 'id,name,effective_status,adset_id,creative{object_url,title,body,image_url,thumbnail_url,call_to_action_type}'
            : 'id,name,effective_status,adset_id';

        return $this->paginate("/act_{$externalAccountId}/ads", [
            'fields' => $fields,
            'limit'  => 200,
        ]);
    }

    /**
     * Fetch daily insights for a date range at campaign, adset, or ad level.
     *
     * Per spec: always pass time_increment=1 for daily rows.
     *
     * Fields captured:
     * - frequency: average times a person saw the ad (stored in ad_insights.frequency)
     * - actions/action_values: purchase conversions + value (platform_conversions, platform_conversions_value)
     *   stored in raw_insights JSONB for non-promoted action types
     * - video_*_watched_actions + outbound_clicks: stored in raw_insights for Motion Score (§F11)
     *   NOTE: video fields are only populated for video ads. Static image ads will have null values.
     *   3s and 10s video metrics NOT requested — both deprecated by Meta (10s: Jan 2026, 3s: Apr 2026).
     * - ctr/cpc are NOT requested — computed on the fly with NULLIF to avoid stale values
     *   See PLANNING.md "ad_insights — computed columns"
     *
     * @param  string $level                  'campaign', 'adset', or 'ad'
     * @param  bool   $filterZeroImpressions  Skip ad/day rows with zero impressions.
     *                                        Set true for regular syncs (3-day window) to reduce
     *                                        pagination on accounts with many inactive ads.
     *                                        Historical imports use submitAsyncInsightsJob() which
     *                                        has this filter baked in.
     *
     * @return array<int, array<string, mixed>>
     *
     * @throws FacebookTokenExpiredException
     * @throws FacebookRateLimitException
     * @throws FacebookApiException
     */
    public function fetchInsights(
        string $externalAccountId,
        string $level,
        string $since,
        string $until,
        bool $filterZeroImpressions = false,
    ): array {
        // limit=500 is the Facebook Ads Insights API maximum for time_increment=1 (daily rows).
        // Why: without an explicit limit, Facebook defaults to 25 rows/page. A 30-day window
        // with 100 ads = 3,000 rows = 120 HTTP requests at default vs 6 at limit=500.
        $params = [
            'level'          => $level,
            'fields'         => 'spend,impressions,clicks,reach,frequency,purchase_roas,actions,action_values,video_continuous_2_sec_watched_actions,video_15_sec_watched_actions,video_p25_watched_actions,video_p50_watched_actions,video_p75_watched_actions,video_p100_watched_actions,outbound_clicks,account_currency,campaign_id,adset_id,ad_id,date_start',
            'time_range'     => json_encode(['since' => $since, 'until' => $until]),
            'time_increment' => 1,
            'limit'          => 500,
        ];

        if ($filterZeroImpressions) {
            // Use level-specific field prefix — 'ad.impressions' only applies at ad level.
            // At campaign or adset level the field must be 'campaign.impressions' / 'adset.impressions'.
            // Using the wrong prefix causes a Facebook API error (or silently wrong filtering).
            $params['filtering'] = json_encode([
                ['field' => "{$level}.impressions", 'operator' => 'GREATER_THAN', 'value' => 0],
            ]);
        }

        return $this->paginate("/act_{$externalAccountId}/insights", $params);
    }

    /**
     * Fetch insights for multiple levels concurrently via pagination pool.
     *
     * Allows fetching campaign/adset/ad level insights in parallel instead of sequentially.
     * Each request is independent; parallelization is safe per Meta's rate-limit model.
     *
     * @param  array<string, array{level: string, since: string, until: string, filterZeroImpressions?: bool}> $requests
     * @return array<string, list<array<string, mixed>>> Rows keyed by the same key as $requests.
     *
     * @throws FacebookTokenExpiredException
     * @throws FacebookRateLimitException
     * @throws FacebookApiException
     */
    public function fetchInsightsPool(
        string $externalAccountId,
        array $requests,
    ): array {
        if ($requests === []) {
            return [];
        }

        $accessToken = $this->accessToken;
        $endpoint    = self::BASE_URL . "/act_{$externalAccountId}/insights";

        /** @var array<string, \Illuminate\Http\Client\Response> $responses */
        $responses = Http::pool(static function (Pool $pool) use ($requests, $endpoint, $accessToken): array {
            $promises = [];

            foreach ($requests as $key => $req) {
                $level                = (string) $req['level'];
                $since                = (string) $req['since'];
                $until                = (string) $req['until'];
                $filterZeroImpressions = (bool) ($req['filterZeroImpressions'] ?? false);

                $params = [
                    'level'          => $level,
                    'fields'         => 'spend,impressions,clicks,reach,frequency,purchase_roas,actions,action_values,video_continuous_2_sec_watched_actions,video_15_sec_watched_actions,video_p25_watched_actions,video_p50_watched_actions,video_p75_watched_actions,video_p100_watched_actions,outbound_clicks,account_currency,campaign_id,adset_id,ad_id,date_start',
                    'time_range'     => json_encode(['since' => $since, 'until' => $until]),
                    'time_increment' => 1,
                    'limit'          => 500,
                    'access_token'   => $accessToken,
                ];

                if ($filterZeroImpressions) {
                    $params['filtering'] = json_encode([
                        ['field' => "{$level}.impressions", 'operator' => 'GREATER_THAN', 'value' => 0],
                    ]);
                }

                $promises[] = $pool->as((string) $key)
                    ->timeout(120)
                    ->connectTimeout(10)
                    ->retry(3, 2000, function (\Throwable $e): bool {
                        return $e instanceof \Illuminate\Http\Client\ConnectionException;
                    }, throw: false)
                    ->get($endpoint, $params);
            }

            return $promises;
        });

        // First pass: detect token expiry / rate limits across the whole batch
        $maxRetryAfter = 0;
        $rateLimited   = false;

        foreach ($responses as $response) {
            if ($response instanceof \Throwable) {
                throw new FacebookApiException('Facebook pool transport error: ' . $response->getMessage());
            }

            $body = $response->json();

            if (isset($body['error'])) {
                $code = (int) ($body['error']['code'] ?? 0);

                if ($code === 190) {
                    throw new FacebookTokenExpiredException();
                }

                if (in_array($code, self::RATE_LIMIT_CODES, strict: true)) {
                    $rateLimited   = true;
                    $maxRetryAfter = max($maxRetryAfter, (int) $response->header('Retry-After', 300) ?: 300);
                }
            }

            if ($response->failed()) {
                $rateLimited   = true;
                $maxRetryAfter = max($maxRetryAfter, (int) $response->header('Retry-After', 300) ?: 300);
            }
        }

        if ($rateLimited) {
            Cache::put('facebook_api_throttled_until', now()->addSeconds($maxRetryAfter)->toISOString(), $maxRetryAfter + 60);
            Cache::put('facebook_api_last_throttle_at', now()->toISOString(), 86400);
            $hitKey = 'facebook_api_rate_limit_hits_' . now()->toDateString();
            Cache::add($hitKey, 0, 172800);
            Cache::increment($hitKey);

            throw new FacebookRateLimitException($maxRetryAfter, usagePct: null);
        }

        $out        = [];
        $successful = 0;

        foreach ($responses as $key => $response) {
            if (! $response->successful()) {
                $body = $response->json();

                if (isset($body['error'])) {
                    $code    = (int) ($body['error']['code'] ?? 0);
                    $message = (string) ($body['error']['message'] ?? "HTTP {$response->status()}");
                    throw new FacebookApiException("Facebook API error {$code} for {$key}: {$message}");
                }

                throw new FacebookApiException("Facebook API error ({$response->status()}) for {$key}");
            }

            $successful++;
            $out[(string) $key] = (array) ($response->json('data', []));
        }

        // Record successful calls for admin quota visibility
        if ($successful > 0) {
            Cache::put('facebook_api_last_success_at', now()->toISOString(), 86400);
            $callKey = 'facebook_api_calls_' . now()->toDateString();
            Cache::add($callKey, 0, 172800);
            Cache::increment($callKey, $successful);
        }

        return $out;
    }

    // -------------------------------------------------------------------------
    // Async insights (historical import)
    // -------------------------------------------------------------------------

    /**
     * Submit an async insights report job for the given level over a large date range.
     *
     * Why async: Meta recommends async for large date ranges — synchronous paginated calls
     * can time out and burn proportionally more rate-limit score per row. Async counts as
     * one API call regardless of result size. See PLANNING.md "AdHistoricalImportJob".
     *
     * AdHistoricalImportJob submits three separate jobs per chunk — one per level
     * (campaign, adset, ad) — so Facebook can compute them in parallel. The field set
     * returned by each level differs: campaign rows omit adset_id/ad_id, adset rows
     * omit ad_id, and ad rows include all parent IDs for FK map resolution.
     *
     * Poll the returned report_run_id with pollAsyncJob(), then read results with
     * fetchAsyncJobResults() once async_status = 'Job Completed'.
     *
     * @return string report_run_id
     *
     * @throws FacebookTokenExpiredException
     * @throws FacebookRateLimitException
     * @throws FacebookApiException
     */
    public function submitAsyncInsightsJob(
        string $externalAccountId,
        string $since,
        string $until,
        string $level = 'campaign',
    ): string {
        // Fields differ by level — adset/ad rows include their parent IDs so FK maps can be built.
        $videoFields = 'video_continuous_2_sec_watched_actions,video_15_sec_watched_actions,video_p25_watched_actions,video_p50_watched_actions,video_p75_watched_actions,video_p100_watched_actions,outbound_clicks';
        $fields = match ($level) {
            'adset'    => "spend,impressions,clicks,reach,frequency,purchase_roas,actions,action_values,{$videoFields},account_currency,campaign_id,adset_id,date_start",
            'ad'       => "spend,impressions,clicks,reach,frequency,purchase_roas,actions,action_values,{$videoFields},account_currency,campaign_id,adset_id,ad_id,date_start",
            default    => "spend,impressions,clicks,reach,frequency,purchase_roas,actions,action_values,{$videoFields},account_currency,campaign_id,date_start",
        };

        // POST triggers async job creation; Facebook returns report_run_id (not data rows).
        // Why async=true: without it, small date ranges may be returned synchronously (no report_run_id),
        // which would cause the caller to throw when it tries to read report_run_id from the response.
        // Explicit async=true guarantees consistent behavior across all date range sizes.
        $response = Http::timeout(60)
            ->post(self::BASE_URL . "/act_{$externalAccountId}/insights", [
                'access_token'   => $this->accessToken,
                'async'          => true,
                'level'          => $level,
                'fields'         => $fields,
                'time_range'     => json_encode(['since' => $since, 'until' => $until]),
                'time_increment' => 1,
                'limit'          => 500,
                // Skip rows with zero impressions. Field prefix must match the level.
                'filtering' => json_encode([
                    ['field' => "{$level}.impressions", 'operator' => 'GREATER_THAN', 'value' => 0],
                ]),
            ]);

        $this->assertSuccess($response);

        $body        = $response->json();
        $reportRunId = (string) ($body['report_run_id'] ?? '');

        if ($reportRunId === '') {
            throw new FacebookApiException('Async insights job returned no report_run_id.');
        }

        return $reportRunId;
    }

    /**
     * Poll an async report run for its current completion status.
     *
     * Callers should poll until async_status = 'Job Completed'. Back off
     * exponentially between polls (5 → 10 → 20 → 30s max) to avoid unnecessary calls.
     *
     * @return array{async_status: string, async_percent_completion: int}
     *
     * @throws FacebookTokenExpiredException
     * @throws FacebookRateLimitException
     * @throws FacebookApiException
     */
    public function pollAsyncJob(string $reportRunId): array
    {
        $response = Http::timeout(30)
            ->get(self::BASE_URL . "/{$reportRunId}", [
                'access_token' => $this->accessToken,
                'fields'       => 'async_status,async_percent_completion',
            ]);

        $this->assertSuccess($response);

        /** @var array{async_status: string, async_percent_completion: int} */
        return $response->json();
    }

    /**
     * Stream result pages from a completed async insights job, invoking $onPage for each page.
     *
     * Why stream instead of accumulate: ad-level 90-day result sets can reach tens of thousands
     * of rows. Loading them all into a single PHP array exhausts the 256 MB memory limit
     * (each row includes nested actions/action_values arrays). Streaming keeps memory usage
     * bounded to one page (≤ 500 rows) at a time regardless of total result size.
     *
     * Only call after pollAsyncJob() returns async_status = 'Job Completed'.
     *
     * @param callable(array<int, array<string, mixed>>): void $onPage  Called once per page
     *
     * @throws FacebookTokenExpiredException
     * @throws FacebookRateLimitException
     * @throws FacebookApiException
     */
    public function streamAsyncJobResults(string $reportRunId, callable $onPage): void
    {
        $url    = self::BASE_URL . "/{$reportRunId}/insights";
        $params = ['limit' => 500];

        do {
            $response = Http::timeout(120)
                ->retry(3, 2000, function (\Throwable $e): bool {
                    return $e instanceof \Illuminate\Http\Client\ConnectionException;
                }, throw: false)
                ->get($url, array_merge($params, ['access_token' => $this->accessToken]));

            $this->assertSuccess($response);

            // Record successful page fetch for admin quota visibility.
            Cache::put('facebook_api_last_success_at', now()->toISOString(), 86400);
            $callKey = 'facebook_api_calls_' . now()->toDateString();
            Cache::add($callKey, 0, 172800);
            Cache::increment($callKey);

            $body = $response->json();
            $page = $body['data'] ?? [];

            if (! empty($page)) {
                $onPage($page);
            }

            $url    = $body['paging']['next'] ?? null;
            $params = []; // subsequent pages use the full URL with cursor embedded
        } while ($url !== null);
    }

    // -------------------------------------------------------------------------
    // Internal helpers
    // -------------------------------------------------------------------------

    /**
     * Follow cursor-based pagination and return all rows from all pages.
     *
     * Why retry: Docker/WSL2 environments occasionally drop SSL connections mid-response
     * (CURLE_RECV_ERROR 56 / "unexpected eof while reading"). Laravel's Http::retry()
     * handles this transparently — up to 3 attempts with 2s delay between them.
     * The retry only fires on connection-level exceptions, not on Facebook API errors
     * (those are caught by assertSuccess and re-thrown as typed exceptions).
     *
     * @param  array<string, mixed> $params
     * @return array<int, array<string, mixed>>
     */
    private function paginate(string $endpoint, array $params = []): array
    {
        $rows = [];
        $url  = self::BASE_URL . $endpoint;

        do {
            // throw: false — prevents Laravel from auto-throwing RequestException on HTTP 4xx/5xx
            // before assertSuccess() can inspect the body and throw the right typed exception
            // (FacebookApiException, FacebookRateLimitException, etc.). Without this, a 400
            // from Facebook escapes as RequestException, bypassing all our error handling.
            $response = Http::timeout(120)
                ->retry(3, 2000, function (\Throwable $e): bool {
                    // Only retry on connection/network errors, not on API-level errors.
                    return $e instanceof \Illuminate\Http\Client\ConnectionException;
                }, throw: false)
                ->get($url, array_merge($params, ['access_token' => $this->accessToken]));

            $this->assertSuccess($response);

            // Record successful page fetch for admin quota visibility.
            Cache::put('facebook_api_last_success_at', now()->toISOString(), 86400);
            $callKey = 'facebook_api_calls_' . now()->toDateString();
            Cache::add($callKey, 0, 172800);
            Cache::increment($callKey);

            $body   = $response->json();
            $rows   = array_merge($rows, $body['data'] ?? []);
            $url    = $body['paging']['next'] ?? null;
            $params = []; // subsequent pages use the full URL with cursor embedded
        } while ($url !== null);

        return $rows;
    }

    /**
     * Inspect the response and throw the appropriate typed exception.
     *
     * Facebook often returns HTTP 200 with an error payload, and sometimes
     * HTTP 400/401 for token/rate errors. Both patterns are handled here.
     *
     * On success, reads X-Business-Use-Case-Usage to proactively back off
     * before Facebook enforces a hard limit. This prevents the reactive
     * rate-limit loop where we only slow down after getting a 429.
     *
     * @throws FacebookTokenExpiredException
     * @throws FacebookRateLimitException
     * @throws FacebookApiException
     */
    private function assertSuccess(Response $response): void
    {
        $body = $response->json();

        // Graph error object present in body (common pattern even on HTTP 200)
        if (isset($body['error'])) {
            $code    = (int) ($body['error']['code'] ?? 0);
            $message = (string) ($body['error']['message'] ?? 'Unknown Facebook API error');

            if ($code === 190) {
                throw new FacebookTokenExpiredException();
            }

            if (in_array($code, self::RATE_LIMIT_CODES, strict: true)) {
                // Respect Retry-After header if present, otherwise default based on tier.
                // Jitter ±20% to prevent thundering-herd when multiple accounts hit limits simultaneously.
                $base       = $this->isDevTier() ? 300 : 600;
                $retryAfter = (int) $response->header('Retry-After', $base);
                $retryAfter = $retryAfter ?: $base;
                $retryAfter = $this->withJitter($retryAfter);
                throw new FacebookRateLimitException($retryAfter, usagePct: null);
            }

            throw new FacebookApiException("Facebook API error {$code}: {$message}");
        }

        if ($response->failed()) {
            throw new FacebookApiException(
                "Facebook API returned HTTP {$response->status()} with no error body."
            );
        }

        // Proactive throttle: read X-Business-Use-Case-Usage before the next request.
        //
        // Why: Facebook enforces per-ad-account rate limits tracked via this header.
        // Each field (call_count, total_cputime, total_time) is a 0-100 percentage.
        // If we wait until FB returns error code 17/80000, we've already failed a request.
        // Backing off at 80 % keeps us in the safe zone across paginated syncs.
        //
        // Backoff tiers: >=95% → 120s, >=90% → 60s, >=80% → 30s.
        // The exception is caught by SyncAdInsightsJob / AdHistoricalImportJob which
        // re-queue with the appropriate delay without burning a retry attempt.
        $this->checkUsageHeaders($response);
    }

    /**
     * Proactive throttle check against both usage headers Facebook returns.
     *
     * X-Business-Use-Case-Usage: per-ad-account BUC score (call_count, total_cputime, total_time).
     * X-FB-Ads-Insights-Throttle: insights-specific capacity (app_id_util_pct, acc_id_util_pct).
     * Both headers throttle independently — we take the max across all values from both.
     *
     * Threshold depends on API tier (FB_API_TIER env var):
     *   dev      — pause at 50% (dev tier hard cap is 60; one burst can exhaust it)
     *   standard — pause at 88% (standard tier has enormous headroom, 88% is conservative)
     *
     * Always records the observed usage % to cache for admin visibility, regardless of
     * whether the threshold is exceeded. Cache key: facebook_api_usage (TTL 30 min).
     *
     * @throws FacebookRateLimitException
     */
    private function checkUsageHeaders(Response $response): void
    {
        $maxPct = 0;

        // X-Business-Use-Case-Usage
        $buc = $response->header('X-Business-Use-Case-Usage');
        if ($buc !== '' && $buc !== null) {
            $usage = json_decode($buc, associative: true);
            if (is_array($usage)) {
                foreach ($usage as $calls) {
                    if (! is_array($calls)) {
                        continue;
                    }
                    foreach ($calls as $entry) {
                        $maxPct = max(
                            $maxPct,
                            (int) ($entry['call_count']    ?? 0),
                            (int) ($entry['total_cputime'] ?? 0),
                            (int) ($entry['total_time']    ?? 0),
                        );
                    }
                }
            }
        }

        // X-FB-Ads-Insights-Throttle (insights endpoint only; absent on other endpoints)
        $insightsThrottle = $response->header('X-FB-Ads-Insights-Throttle');
        if ($insightsThrottle !== '' && $insightsThrottle !== null) {
            $throttle = json_decode($insightsThrottle, associative: true);
            if (is_array($throttle)) {
                $maxPct = max(
                    $maxPct,
                    (int) ($throttle['app_id_util_pct'] ?? 0),
                    (int) ($throttle['acc_id_util_pct'] ?? 0),
                );
            }
        }

        // Always persist the observed usage for admin visibility (30-min TTL).
        // Only written when a non-zero reading is present so stale header-less
        // endpoints (e.g. async poll) don't overwrite a real reading with 0.
        if ($maxPct > 0) {
            Cache::put('facebook_api_usage', [
                'pct'       => $maxPct,
                'tier'      => $this->isDevTier() ? 'dev' : 'standard',
                'threshold' => $this->isDevTier() ? self::DEV_TIER_THRESHOLD : self::STANDARD_TIER_THRESHOLD,
                'hard_cap'  => $this->isDevTier() ? 60 : null,
                'observed_at' => now()->toISOString(),
            ], 1800);
        }

        $threshold = $this->isDevTier() ? self::DEV_TIER_THRESHOLD : self::STANDARD_TIER_THRESHOLD;

        if ($maxPct >= $threshold) {
            $backoff = $this->isDevTier()
                // Dev tier: 300 s decay window. Wait 360 s (+ jitter) to ensure the window
                // has fully cleared before retrying — avoids the loop where 250 s jitter fires
                // before usage has decayed below the threshold.
                ? $this->withJitter(360)
                // Standard tier: tiered backoff based on how saturated we are.
                : $this->withJitter(match (true) {
                    $maxPct >= 99 => 1200,
                    $maxPct >= 95 => 600,
                    default       => 300,
                });

            Log::info('FacebookAdsClient: proactive rate limit backoff', [
                'usage_pct'  => $maxPct,
                'threshold'  => $threshold,
                'tier'       => $this->isDevTier() ? 'dev' : 'standard',
                'backoff_s'  => $backoff,
            ]);

            // Record the throttle event for admin visibility.
            // throttled_until lets the admin page show "throttled until HH:MM".
            // Date-scoped hit counter (2-day TTL) so yesterday's count auto-expires without extra logic.
            Cache::put('facebook_api_throttled_until', now()->addSeconds($backoff)->toISOString(), $backoff + 60);
            Cache::put('facebook_api_last_throttle_at', now()->toISOString(), 86400);
            $hitKey = 'facebook_api_rate_limit_hits_' . now()->toDateString();
            Cache::add($hitKey, 0, 172800); // initialize with 2-day TTL if new
            Cache::increment($hitKey);

            throw new FacebookRateLimitException($backoff, usagePct: $maxPct);
        }
    }

    private function isDevTier(): bool
    {
        return strtolower((string) config('services.facebook.api_tier', 'dev')) === 'dev';
    }

    /**
     * Apply ±20% jitter to a backoff value to prevent thundering-herd behaviour
     * when multiple ad accounts are rate-limited and retry simultaneously.
     */
    private function withJitter(int $seconds): int
    {
        $jitter = (int) round($seconds * 0.2);
        return $seconds + random_int(-$jitter, $jitter);
    }
}
