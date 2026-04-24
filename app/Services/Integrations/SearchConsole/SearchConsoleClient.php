<?php

declare(strict_types=1);

namespace App\Services\Integrations\SearchConsole;

use App\Exceptions\GoogleApiException;
use App\Exceptions\GoogleRateLimitException;
use App\Exceptions\GoogleTokenExpiredException;
use App\Models\SearchConsoleProperty;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * HTTP client for the Google Search Console API v3.
 *
 * Handles token refresh transparently when within 5 minutes of expiry
 * (only when constructed via forProperty() — withToken() never refreshes).
 *
 * Never called from the request cycle — only from queue jobs.
 */
class SearchConsoleClient
{
    private const GSC_BASE  = 'https://searchconsole.googleapis.com/webmasters/v3';
    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';

    /**
     * GSC searchAnalytics.query max rowLimit per request.
     * @see https://developers.google.com/webmaster-tools/v1/searchanalytics/query#rowLimit
     */
    private const ROW_LIMIT = 25000;

    private string $currentAccessToken;

    private function __construct(
        private readonly ?SearchConsoleProperty $property,
        string $accessToken,
    ) {
        $this->currentAccessToken = $accessToken;
    }

    // -------------------------------------------------------------------------
    // Named constructors
    // -------------------------------------------------------------------------

    /**
     * Build a client for a persisted SearchConsoleProperty, refreshing token if needed.
     *
     * @throws GoogleTokenExpiredException  when the refresh token is missing or revoked
     */
    public static function forProperty(SearchConsoleProperty $property): self
    {
        $token    = Crypt::decryptString((string) $property->access_token_encrypted);
        $instance = new self($property, $token);
        $instance->refreshIfNeeded();

        return $instance;
    }

    /**
     * Build a client with a raw access token.
     * Used during the OAuth callback before a SearchConsoleProperty row exists.
     * Token refresh is NOT performed.
     */
    public static function withToken(string $accessToken): self
    {
        return new self(null, $accessToken);
    }

    // -------------------------------------------------------------------------
    // Property discovery (used during OAuth callback)
    // -------------------------------------------------------------------------

    /**
     * List all Search Console properties accessible to the OAuth user.
     *
     * @return list<array{siteUrl: string, permissionLevel: string}>
     *
     * @throws GoogleTokenExpiredException
     * @throws GoogleRateLimitException
     * @throws GoogleApiException
     */
    public function listProperties(): array
    {
        $response = Http::withToken($this->currentAccessToken)
            ->timeout(15)
            ->get(self::GSC_BASE . '/sites');

        $this->assertSuccess($response);

        return $response->json('siteEntry', []);
    }

    // -------------------------------------------------------------------------
    // Data sync
    // -------------------------------------------------------------------------

    /**
     * Fetch aggregate daily performance (no dimension breakdown).
     *
     * Returns rows keyed by date: [{date, clicks, impressions, ctr, position}]
     *
     * @return list<array{date: string, clicks: int, impressions: int, ctr: float|null, position: float|null}>
     *
     * @throws GoogleTokenExpiredException
     * @throws GoogleRateLimitException
     * @throws GoogleApiException
     */
    public function queryDailyStats(string $propertyUrl, string $startDate, string $endDate, string $dataState = 'all'): array
    {
        return $this->searchAnalytics($propertyUrl, $startDate, $endDate, ['date'], $dataState);
    }

    /**
     * Fetch per-query performance (top 1,000 rows).
     *
     * @return list<array{query: string, date: string, clicks: int, impressions: int, ctr: float|null, position: float|null}>
     *
     * @throws GoogleTokenExpiredException
     * @throws GoogleRateLimitException
     * @throws GoogleApiException
     */
    public function querySearchQueries(string $propertyUrl, string $startDate, string $endDate, string $dataState = 'all'): array
    {
        return $this->searchAnalytics($propertyUrl, $startDate, $endDate, ['date', 'query'], $dataState);
    }

    /**
     * Fetch per-page performance (top 1,000 rows).
     *
     * @return list<array{page: string, date: string, clicks: int, impressions: int, ctr: float|null, position: float|null}>
     *
     * @throws GoogleTokenExpiredException
     * @throws GoogleRateLimitException
     * @throws GoogleApiException
     */
    public function queryPages(string $propertyUrl, string $startDate, string $endDate, string $dataState = 'all'): array
    {
        return $this->searchAnalytics($propertyUrl, $startDate, $endDate, ['date', 'page'], $dataState);
    }

    /**
     * Fetch daily stats broken down by device + country (top 1,000 rows).
     *
     * Why: Phase 0 data capture — store device/country breakdown for Phase 2 anomaly detection.
     * GSC returns device as 'MOBILE'/'DESKTOP'/'TABLET' (uppercase) and country as ISO 3166-1
     * alpha-3 lowercase (e.g., 'deu', 'usa'). Normalisation happens in the job.
     *
     * @return list<array{date: string, device: string, country: string, clicks: int, impressions: int, ctr: float|null, position: float|null}>
     *
     * @throws GoogleTokenExpiredException
     * @throws GoogleRateLimitException
     * @throws GoogleApiException
     */
    public function queryDailyStatsBreakdown(string $propertyUrl, string $startDate, string $endDate): array
    {
        return $this->searchAnalytics($propertyUrl, $startDate, $endDate, ['date', 'device', 'country']);
    }

    /**
     * Fetch per-query stats broken down by device + country (top 1,000 rows).
     *
     * Why: Phase 0 data capture — device/country breakdown for query-level analysis in Phase 2.
     *
     * @return list<array{date: string, query: string, device: string, country: string, clicks: int, impressions: int, ctr: float|null, position: float|null}>
     *
     * @throws GoogleTokenExpiredException
     * @throws GoogleRateLimitException
     * @throws GoogleApiException
     */
    public function querySearchQueriesBreakdown(string $propertyUrl, string $startDate, string $endDate): array
    {
        return $this->searchAnalytics($propertyUrl, $startDate, $endDate, ['date', 'query', 'device', 'country']);
    }

    /**
     * Fetch per-page stats broken down by device + country (top 1,000 rows).
     *
     * Why: Phase 0 data capture — device/country breakdown for page-level analysis in Phase 2.
     *
     * @return list<array{date: string, page: string, device: string, country: string, clicks: int, impressions: int, ctr: float|null, position: float|null}>
     *
     * @throws GoogleTokenExpiredException
     * @throws GoogleRateLimitException
     * @throws GoogleApiException
     */
    public function queryPagesBreakdown(string $propertyUrl, string $startDate, string $endDate): array
    {
        return $this->searchAnalytics($propertyUrl, $startDate, $endDate, ['date', 'page', 'device', 'country']);
    }

    // -------------------------------------------------------------------------
    // Token refresh
    // -------------------------------------------------------------------------

    /**
     * Refresh the access token when within 5 minutes of expiry.
     *
     * @throws GoogleTokenExpiredException
     */
    public function refreshIfNeeded(): void
    {
        if ($this->property === null) {
            return;
        }

        if ($this->property->token_expires_at === null) {
            return;
        }

        if ($this->property->token_expires_at->subMinutes(5)->isFuture()) {
            return;
        }

        $this->performRefresh();
    }

    /**
     * Force a token refresh regardless of expiry time.
     * Used by RefreshOAuthTokenJob.
     *
     * @throws GoogleTokenExpiredException
     */
    public function forceRefresh(): void
    {
        if ($this->property === null) {
            return;
        }

        $this->performRefresh();
    }

    private function performRefresh(): void
    {
        if ($this->property === null || $this->property->refresh_token_encrypted === null) {
            throw new GoogleTokenExpiredException();
        }

        $refreshToken = Crypt::decryptString((string) $this->property->refresh_token_encrypted);

        $response = Http::timeout(15)->post(self::TOKEN_URL, [
            'client_id'     => config('services.google.client_id'),
            'client_secret' => config('services.google.client_secret'),
            'refresh_token' => $refreshToken,
            'grant_type'    => 'refresh_token',
        ]);

        if ($response->failed()) {
            Log::error('SearchConsoleClient: token refresh failed', [
                'property_id' => $this->property->id,
                'http_status' => $response->status(),
                'error'       => $response->json('error', 'unknown'),
            ]);

            throw new GoogleTokenExpiredException();
        }

        $body      = $response->json();
        $newToken  = (string) ($body['access_token'] ?? '');
        $expiresIn = (int) ($body['expires_in'] ?? 3600);

        if ($newToken === '') {
            throw new GoogleTokenExpiredException();
        }

        $this->property->updateQuietly([
            'access_token_encrypted' => Crypt::encryptString($newToken),
            'token_expires_at'       => now()->addSeconds($expiresIn),
        ]);

        $this->currentAccessToken = $newToken;
    }

    // -------------------------------------------------------------------------
    // Pooled fetch
    // -------------------------------------------------------------------------

    /**
     * Fire a batch of searchAnalytics.query requests concurrently and return
     * normalised rows keyed by the caller-supplied request key.
     *
     * Uses Laravel's Http::pool which multiplexes over curl_multi. Combined with
     * HTTP/2 this lets us run 15+ in-flight GSC requests over a single TLS
     * connection, while staying well under the 1,200 QPM per-property cap.
     *
     * Why pooled: sequential per-day requests dominate the historical-import
     * wall time (30+ RTTs per day for a site with 3 dim-slices). Batching the
     * 3 per-day calls and batching 5 days at a time cuts the 20h+ backfill to
     * under 10 min.
     *
     * Each $requests entry: ['startDate' => ..., 'endDate' => ..., 'dimensions' => [...], 'dataState' => 'all'|'final']
     *
     * @param  array<string, array{startDate: string, endDate: string, dimensions: list<string>, dataState?: string}> $requests
     * @return array<string, list<array<string, mixed>>> Rows keyed by the same key as $requests.
     *
     * @throws GoogleTokenExpiredException
     * @throws GoogleRateLimitException
     * @throws GoogleApiException
     */
    public function searchAnalyticsPool(string $propertyUrl, array $requests): array
    {
        if ($requests === []) {
            return [];
        }

        $encodedUrl = rawurlencode($propertyUrl);
        $endpoint   = self::GSC_BASE . "/sites/{$encodedUrl}/searchAnalytics/query";
        $token      = $this->currentAccessToken;

        /** @var array<string, \Illuminate\Http\Client\Response> $responses */
        $responses = Http::pool(static function (Pool $pool) use ($requests, $endpoint, $token): array {
            $promises = [];

            foreach ($requests as $key => $req) {
                $promises[] = $pool->as((string) $key)
                    ->withToken($token)
                    ->withOptions(['version' => 2.0])
                    ->timeout(60)
                    ->connectTimeout(10)
                    ->retry(2, 500, throw: false)
                    ->post($endpoint, [
                        'startDate'  => $req['startDate'],
                        'endDate'    => $req['endDate'],
                        'dimensions' => $req['dimensions'],
                        'rowLimit'   => self::ROW_LIMIT,
                        'dataState'  => $req['dataState'] ?? 'all',
                    ]);
            }

            return $promises;
        });

        // First pass: detect token expiry / rate limits across the whole batch
        // so we surface a single typed exception and don't half-apply results.
        $maxRetryAfter = 0;
        $rateLimited   = false;

        foreach ($responses as $response) {
            if ($response instanceof \Throwable) {
                throw new GoogleApiException('GSC pool transport error: ' . $response->getMessage());
            }

            $status  = $response->status();
            $gStatus = $response->json('error.status', '');

            if ($status === 401 || $gStatus === 'UNAUTHENTICATED') {
                throw new GoogleTokenExpiredException();
            }

            if ($status === 429 || $gStatus === 'RESOURCE_EXHAUSTED') {
                $rateLimited   = true;
                $maxRetryAfter = max($maxRetryAfter, (int) $response->header('Retry-After', 60) ?: 60);
            }
        }

        if ($rateLimited) {
            Cache::put('gsc_throttled_until', now()->addSeconds($maxRetryAfter)->toISOString(), $maxRetryAfter + 60);
            Cache::put('gsc_last_throttle_at', now()->toISOString(), 86400);
            $hitKey = 'gsc_rate_limit_hits_' . now()->toDateString();
            Cache::add($hitKey, 0, 172800);
            Cache::increment($hitKey);

            throw new GoogleRateLimitException($maxRetryAfter);
        }

        $out        = [];
        $successful = 0;

        foreach ($responses as $key => $response) {
            if (! $response->successful()) {
                $status = $response->status();
                $reason = $response->json('error.message', "HTTP {$status}");
                throw new GoogleApiException("GSC API error ({$status}) for key {$key}: {$reason}");
            }

            $successful++;
            $out[(string) $key] = $this->normaliseRows(
                $response->json('rows', []),
                $requests[$key]['dimensions'],
            );
        }

        $this->recordSuccessfulCalls($successful);

        return $out;
    }

    // -------------------------------------------------------------------------
    // Internal helpers
    // -------------------------------------------------------------------------

    /**
     * Call the searchAnalytics endpoint with the given dimensions (single request).
     *
     * @param  list<string>  $dimensions  e.g. ['date'], ['date','query'], ['date','page']
     * @return list<array<string, mixed>>
     *
     * @throws GoogleTokenExpiredException
     * @throws GoogleRateLimitException
     * @throws GoogleApiException
     */
    private function searchAnalytics(
        string $propertyUrl,
        string $startDate,
        string $endDate,
        array $dimensions,
        string $dataState = 'all',
    ): array {
        $encodedUrl = rawurlencode($propertyUrl);

        $response = Http::withToken($this->currentAccessToken)
            ->withOptions(['version' => 2.0])
            ->timeout(60)
            ->connectTimeout(10)
            ->retry(2, 500, throw: false)
            ->post(self::GSC_BASE . "/sites/{$encodedUrl}/searchAnalytics/query", [
                'startDate'  => $startDate,
                'endDate'    => $endDate,
                'dimensions' => $dimensions,
                'rowLimit'   => self::ROW_LIMIT,
                'dataState'  => $dataState,
            ]);

        $this->assertSuccess($response);
        $this->recordSuccessfulCalls(1);

        return $this->normaliseRows($response->json('rows', []), $dimensions);
    }

    /**
     * Map GSC's positional 'keys' array onto the requested dimension names.
     *
     * @param  list<array<string, mixed>>  $rows
     * @param  list<string>                $dimensions
     * @return list<array<string, mixed>>
     */
    private function normaliseRows(array $rows, array $dimensions): array
    {
        $normalized = [];

        foreach ($rows as $row) {
            $keys  = $row['keys'] ?? [];
            $entry = [
                'clicks'      => (int) ($row['clicks'] ?? 0),
                'impressions' => (int) ($row['impressions'] ?? 0),
                'ctr'         => isset($row['ctr']) ? (float) $row['ctr'] : null,
                'position'    => isset($row['position']) ? (float) $row['position'] : null,
            ];

            foreach ($dimensions as $i => $dim) {
                $entry[$dim] = $keys[$i] ?? null;
            }

            $normalized[] = $entry;
        }

        return $normalized;
    }

    private function recordSuccessfulCalls(int $count): void
    {
        if ($count <= 0) {
            return;
        }

        Cache::put('gsc_last_success_at', now()->toISOString(), 86400);

        $callKey = 'gsc_calls_' . now()->toDateString();
        Cache::add($callKey, 0, 172800);
        Cache::increment($callKey, $count);
    }

    /**
     * Inspect the response and throw the appropriate typed exception.
     *
     * @throws GoogleTokenExpiredException
     * @throws GoogleRateLimitException
     * @throws GoogleApiException
     */
    private function assertSuccess(Response $response): void
    {
        if ($response->successful()) {
            return;
        }

        $status = $response->status();
        $reason = $response->json('error.message', "HTTP {$status}");
        $gStatus = $response->json('error.status', '');

        if ($status === 429 || $gStatus === 'RESOURCE_EXHAUSTED') {
            $retryAfter = (int) $response->header('Retry-After', 60);
            $retryAfter = $retryAfter ?: 60;

            // Record throttle event for admin visibility.
            Cache::put('gsc_throttled_until', now()->addSeconds($retryAfter)->toISOString(), $retryAfter + 60);
            Cache::put('gsc_last_throttle_at', now()->toISOString(), 86400);
            $hitKey = 'gsc_rate_limit_hits_' . now()->toDateString();
            Cache::add($hitKey, 0, 172800);
            Cache::increment($hitKey);

            throw new GoogleRateLimitException($retryAfter);
        }

        if ($status === 401 || $gStatus === 'UNAUTHENTICATED') {
            throw new GoogleTokenExpiredException();
        }

        throw new GoogleApiException("GSC API error ({$status}): {$reason}");
    }
}
