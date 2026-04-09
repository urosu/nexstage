<?php

declare(strict_types=1);

namespace App\Services\Integrations\SearchConsole;

use App\Exceptions\GoogleApiException;
use App\Exceptions\GoogleRateLimitException;
use App\Exceptions\GoogleTokenExpiredException;
use App\Models\SearchConsoleProperty;
use Illuminate\Http\Client\Response;
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
    public function queryDailyStats(string $propertyUrl, string $startDate, string $endDate): array
    {
        return $this->searchAnalytics($propertyUrl, $startDate, $endDate, ['date']);
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
    public function querySearchQueries(string $propertyUrl, string $startDate, string $endDate): array
    {
        return $this->searchAnalytics($propertyUrl, $startDate, $endDate, ['date', 'query']);
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
    public function queryPages(string $propertyUrl, string $startDate, string $endDate): array
    {
        return $this->searchAnalytics($propertyUrl, $startDate, $endDate, ['date', 'page']);
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
    // Internal helpers
    // -------------------------------------------------------------------------

    /**
     * Call the searchAnalytics endpoint with the given dimensions.
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
    ): array {
        $encodedUrl = rawurlencode($propertyUrl);

        $response = Http::withToken($this->currentAccessToken)
            ->timeout(30)
            ->post(self::GSC_BASE . "/sites/{$encodedUrl}/searchAnalytics/query", [
                'startDate'  => $startDate,
                'endDate'    => $endDate,
                'dimensions' => $dimensions,
                'rowLimit'   => 1000,
                'dataState'  => 'all',
            ]);

        $this->assertSuccess($response);

        $rows = $response->json('rows', []);

        // Normalise each row: the 'keys' array maps positionally to $dimensions.
        $normalized = [];

        foreach ($rows as $row) {
            $keys = $row['keys'] ?? [];
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
            throw new GoogleRateLimitException($retryAfter ?: 60);
        }

        if ($status === 401 || $gStatus === 'UNAUTHENTICATED') {
            throw new GoogleTokenExpiredException();
        }

        throw new GoogleApiException("GSC API error ({$status}): {$reason}");
    }
}
