<?php

declare(strict_types=1);

namespace App\Services\Integrations\Google;

use App\Exceptions\GoogleAccountDisabledException;
use App\Exceptions\GoogleApiException;
use App\Exceptions\GoogleRateLimitException;
use App\Exceptions\GoogleTokenExpiredException;
use App\Models\AdAccount;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * HTTP client for the Google Ads API v23.
 *
 * Uses GAQL via the searchStream endpoint for all data queries.
 * Transparently refreshes the OAuth access token when within 5 minutes of expiry
 * (only when constructed via forAccount() — withToken() never refreshes).
 *
 * Never called from the request cycle — only from queue jobs.
 */
class GoogleAdsClient
{
    private const ADS_API_BASE  = 'https://googleads.googleapis.com/v23';
    private const TOKEN_URL     = 'https://oauth2.googleapis.com/token';

    private string $currentAccessToken;

    private function __construct(
        private readonly ?AdAccount $account,
        string $accessToken,
    ) {
        $this->currentAccessToken = $accessToken;
    }

    // -------------------------------------------------------------------------
    // Named constructors
    // -------------------------------------------------------------------------

    /**
     * Build a client for a persisted AdAccount, refreshing the token if needed.
     *
     * @throws GoogleTokenExpiredException  when the refresh token is missing or revoked
     */
    public static function forAccount(AdAccount $account): self
    {
        $token    = Crypt::decryptString((string) $account->access_token_encrypted);
        $instance = new self($account, $token);
        $instance->refreshIfNeeded();

        return $instance;
    }

    /**
     * Build a client with a raw (already-valid) access token.
     * Used during the OAuth callback before an AdAccount row exists.
     * Token refresh is NOT performed.
     */
    public static function withToken(string $accessToken): self
    {
        return new self(null, $accessToken);
    }

    // -------------------------------------------------------------------------
    // Account discovery (used during OAuth callback)
    // -------------------------------------------------------------------------

    /**
     * List the Google Ads customer resource names accessible to the OAuth user.
     *
     * Returns an array of customer IDs (numeric strings, no 'customers/' prefix).
     *
     * @return list<string>
     *
     * @throws GoogleTokenExpiredException
     * @throws GoogleRateLimitException
     * @throws GoogleApiException
     */
    public function listAccessibleCustomers(): array
    {
        $response = Http::withHeaders($this->buildHeaders())
            ->timeout(15)
            ->get(self::ADS_API_BASE . '/customers:listAccessibleCustomers');

        $this->assertSuccess($response);

        $resourceNames = $response->json('resourceNames', []);

        return array_map(
            static fn (string $name): string => str_replace('customers/', '', $name),
            $resourceNames,
        );
    }

    /**
     * Fetch basic customer metadata (name + currency) via GAQL.
     *
     * @return array{name: string, currency: string}
     *
     * @throws GoogleTokenExpiredException
     * @throws GoogleRateLimitException
     * @throws GoogleApiException
     */
    /**
     * Fetch basic customer metadata (name, currency, and manager flag) via GAQL.
     *
     * `is_manager = true` means this customer is a Manager Account (MCC).
     * MCCs cannot sync ad insights directly — they contain client accounts.
     *
     * @return array{name: string, currency: string, is_manager: bool}
     *
     * @throws GoogleTokenExpiredException
     * @throws GoogleRateLimitException
     * @throws GoogleApiException
     */
    public function getCustomerInfo(string $customerId): array
    {
        $gaql = 'SELECT customer.descriptive_name, customer.currency_code, customer.manager FROM customer LIMIT 1';
        $rows = $this->searchStream($customerId, $gaql);

        if (empty($rows)) {
            return ['name' => $customerId, 'currency' => 'USD', 'is_manager' => false];
        }

        $customer = $rows[0]['customer'] ?? [];

        return [
            'name'       => (string) ($customer['descriptiveName'] ?? $customerId),
            'currency'   => strtoupper((string) ($customer['currencyCode'] ?? 'USD')),
            'is_manager' => (bool) ($customer['manager'] ?? false),
        ];
    }

    // -------------------------------------------------------------------------
    // Structure sync
    // -------------------------------------------------------------------------

    /**
     * Fetch all non-removed campaigns for a customer.
     *
     * @return list<array{id: string, name: string, status: string, objective: string|null}>
     *
     * @throws GoogleTokenExpiredException
     * @throws GoogleRateLimitException
     * @throws GoogleApiException
     */
    public function fetchCampaigns(string $customerId): array
    {
        $gaql = <<<'GAQL'
            SELECT campaign.id, campaign.name, campaign.status, campaign.advertising_channel_type
            FROM campaign
            WHERE campaign.status != 'REMOVED'
            GAQL;

        return $this->searchStream($customerId, $gaql);
    }

    // -------------------------------------------------------------------------
    // Insights
    // -------------------------------------------------------------------------

    /**
     * Fetch campaign-level daily insights for the given date range.
     *
     * Per spec: Google Ads has no hourly data — hour is always NULL.
     * reach and platform_roas are always NULL for Google Ads.
     *
     * @return list<array<string, mixed>>
     *
     * @throws GoogleTokenExpiredException
     * @throws GoogleRateLimitException
     * @throws GoogleApiException
     */
    public function fetchCampaignInsights(string $customerId, string $since, string $until): array
    {
        $gaql = <<<GAQL
            SELECT campaign.id, campaign.name, campaign.status, campaign.advertising_channel_type,
                   metrics.cost_micros, metrics.impressions, metrics.clicks, metrics.ctr,
                   metrics.average_cpc, segments.date
            FROM campaign
            WHERE segments.date BETWEEN '{$since}' AND '{$until}'
              AND campaign.status != 'REMOVED'
            GAQL;

        return $this->searchStream($customerId, $gaql);
    }

    // -------------------------------------------------------------------------
    // searchStream
    // -------------------------------------------------------------------------

    /**
     * Execute a GAQL query via the searchStream endpoint.
     *
     * The endpoint returns newline-delimited JSON (JSONL). Each line contains
     * a `results` array. This method flattens all results into a single array.
     *
     * @return list<array<string, mixed>>
     *
     * @throws GoogleTokenExpiredException
     * @throws GoogleRateLimitException
     * @throws GoogleApiException
     */
    public function searchStream(string $customerId, string $gaql): array
    {
        $url = self::ADS_API_BASE . "/customers/{$customerId}/googleAds:searchStream";

        $response = Http::withHeaders($this->buildHeaders())
            ->timeout(60)
            ->post($url, ['query' => $gaql]);

        $this->assertSuccess($response);

        $rows = [];
        $body = $response->body();

        // Response is a JSON array wrapping JSONL-style chunks: [{results:[...]},{results:[...]}]
        // Google's searchStream actually returns a JSON array of batch objects.
        $decoded = json_decode($body, associative: true);

        if (! is_array($decoded)) {
            return [];
        }

        // Each element may have a 'results' key (array of row objects)
        foreach ($decoded as $batch) {
            if (isset($batch['results']) && is_array($batch['results'])) {
                foreach ($batch['results'] as $row) {
                    $rows[] = $row;
                }
            }
        }

        return $rows;
    }

    // -------------------------------------------------------------------------
    // Token refresh
    // -------------------------------------------------------------------------

    /**
     * Refresh the access token when within 5 minutes of expiry.
     * Updates the AdAccount row in DB and replaces the in-memory token.
     *
     * @throws GoogleTokenExpiredException  when refresh fails (revoked or missing)
     */
    public function refreshIfNeeded(): void
    {
        if ($this->account === null) {
            return;
        }

        if ($this->account->token_expires_at === null) {
            return;
        }

        // Still valid for > 5 minutes — nothing to do
        if ($this->account->token_expires_at->subMinutes(5)->isFuture()) {
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
        if ($this->account === null) {
            return;
        }

        $this->performRefresh();
    }

    private function performRefresh(): void
    {
        if ($this->account === null || $this->account->refresh_token_encrypted === null) {
            throw new GoogleTokenExpiredException();
        }

        $refreshToken = Crypt::decryptString((string) $this->account->refresh_token_encrypted);

        $response = Http::timeout(15)->post(self::TOKEN_URL, [
            'client_id'     => config('services.google.client_id'),
            'client_secret' => config('services.google.client_secret'),
            'refresh_token' => $refreshToken,
            'grant_type'    => 'refresh_token',
        ]);

        if ($response->failed()) {
            $body = $response->json();
            $error = $body['error'] ?? 'unknown';

            Log::error('GoogleAdsClient: token refresh failed', [
                'ad_account_id' => $this->account->id,
                'http_status'   => $response->status(),
                'error'         => $error,
            ]);

            throw new GoogleTokenExpiredException();
        }

        $body      = $response->json();
        $newToken  = (string) ($body['access_token'] ?? '');
        $expiresIn = (int) ($body['expires_in'] ?? 3600);

        if ($newToken === '') {
            throw new GoogleTokenExpiredException();
        }

        $this->account->updateQuietly([
            'access_token_encrypted' => Crypt::encryptString($newToken),
            'token_expires_at'       => now()->addSeconds($expiresIn),
        ]);

        $this->currentAccessToken = $newToken;
    }

    // -------------------------------------------------------------------------
    // Internal helpers
    // -------------------------------------------------------------------------

    /**
     * @return array<string, string>
     */
    private function buildHeaders(): array
    {
        return [
            'Authorization'           => 'Bearer ' . $this->currentAccessToken,
            'developer-token'         => (string) config('services.google.ads_developer_token'),
            'Content-Type'            => 'application/json',
        ];
    }

    /**
     * Inspect the response and throw the appropriate typed exception.
     *
     * @throws GoogleAccountDisabledException
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

        // searchStream returns [{...}], other endpoints return {...} — normalise to the inner object.
        $raw  = $response->json();
        $body = (is_array($raw) && array_is_list($raw) && isset($raw[0])) ? $raw[0] : $raw;

        $reason = data_get($body, 'error.message')
            ?? data_get($body, 'error.status')
            ?? "HTTP {$status}";

        if ($status === 429 || $this->hasGoogleStatus($body, 'RESOURCE_EXHAUSTED')) {
            $retryAfter = (int) $response->header('Retry-After', 60);
            throw new GoogleRateLimitException($retryAfter ?: 60);
        }

        if ($status === 401 || $this->hasGoogleStatus($body, 'UNAUTHENTICATED')) {
            throw new GoogleTokenExpiredException();
        }

        // Permanent 403 errors — retrying will never succeed.
        // CUSTOMER_NOT_ENABLED: account inactive.
        // DEVELOPER_TOKEN_NOT_APPROVED: developer token is in test mode and cannot access real accounts.
        if ($status === 403 && (
            $this->hasGoogleAuthError($body, 'CUSTOMER_NOT_ENABLED')
            || $this->hasGoogleAuthError($body, 'DEVELOPER_TOKEN_NOT_APPROVED')
        )) {
            $errorCode = $this->hasGoogleAuthError($body, 'DEVELOPER_TOKEN_NOT_APPROVED')
                ? 'DEVELOPER_TOKEN_NOT_APPROVED'
                : 'CUSTOMER_NOT_ENABLED';

            throw new GoogleAccountDisabledException(
                "Google Ads account cannot be accessed ({$errorCode}) — the account may be inactive or the developer token is in test mode and cannot access real accounts."
            );
        }

        Log::warning('GoogleAdsClient: API error', [
            'status' => $status,
            'reason' => $reason,
            'body'   => $response->body(),
        ]);

        throw new GoogleApiException("Google Ads API error ({$status}): {$reason}");
    }

    /**
     * Check whether the normalised error body contains a specific gRPC status string.
     *
     * @param  array<mixed>|null $body
     */
    private function hasGoogleStatus(?array $body, string $status): bool
    {
        return data_get($body, 'error.status') === $status;
    }

    /**
     * Check whether the error body contains a specific authorizationError code
     * inside the GoogleAdsFailure details array.
     *
     * @param  array<mixed>|null $body
     */
    private function hasGoogleAuthError(?array $body, string $errorCode): bool
    {
        foreach ((array) data_get($body, 'error.details', []) as $detail) {
            foreach ((array) data_get($detail, 'errors', []) as $error) {
                if (data_get($error, 'errorCode.authorizationError') === $errorCode) {
                    return true;
                }
            }
        }

        return false;
    }
}
