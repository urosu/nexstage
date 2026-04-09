<?php

declare(strict_types=1);

namespace App\Services\Integrations\Facebook;

use App\Exceptions\FacebookApiException;
use App\Exceptions\FacebookRateLimitException;
use App\Exceptions\FacebookTokenExpiredException;
use Illuminate\Http\Client\Response;
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
    private const BASE_URL    = 'https://graph.facebook.com/v25.0';
    private const RATE_LIMIT_CODES = [17, 80000, 80004];

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
     * @return array<int, array{id: string, name: string, status: string, objective: string|null}>
     *
     * @throws FacebookTokenExpiredException
     * @throws FacebookRateLimitException
     * @throws FacebookApiException
     */
    public function fetchCampaigns(string $externalAccountId): array
    {
        return $this->paginate("/act_{$externalAccountId}/campaigns", [
            'fields' => 'id,name,effective_status,objective',
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
        ]);
    }

    /**
     * Return all ads for the given ad account external ID.
     *
     * @return array<int, array{id: string, name: string, status: string, adset_id: string, creative: array|null}>
     *
     * @throws FacebookTokenExpiredException
     * @throws FacebookRateLimitException
     * @throws FacebookApiException
     */
    public function fetchAds(string $externalAccountId): array
    {
        return $this->paginate("/act_{$externalAccountId}/ads", [
            'fields' => 'id,name,effective_status,adset_id,creative{object_url}',
        ]);
    }

    /**
     * Fetch daily insights for a date range at campaign or ad level.
     *
     * Per spec: always pass time_increment=1 for daily rows.
     * Facebook only stores campaign and ad level rows (never account or adset).
     *
     * @param  string $level  'campaign' or 'ad'
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
    ): array {
        return $this->paginate("/act_{$externalAccountId}/insights", [
            'level'          => $level,
            'fields'         => 'spend,impressions,clicks,reach,ctr,cpc,purchase_roas,account_currency,campaign_id,adset_id,ad_id,date_start',
            'time_range'     => json_encode(['since' => $since, 'until' => $until]),
            'time_increment' => 1,
        ]);
    }

    // -------------------------------------------------------------------------
    // Internal helpers
    // -------------------------------------------------------------------------

    /**
     * Follow cursor-based pagination and return all rows from all pages.
     *
     * @param  array<string, mixed> $params
     * @return array<int, array<string, mixed>>
     */
    private function paginate(string $endpoint, array $params = []): array
    {
        $rows = [];
        $url  = self::BASE_URL . $endpoint;

        do {
            $response = Http::timeout(30)
                ->get($url, array_merge($params, ['access_token' => $this->accessToken]));

            $this->assertSuccess($response);

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
                // Respect Retry-After header if present, otherwise default 60 s
                $retryAfter = (int) $response->header('Retry-After', 60);
                throw new FacebookRateLimitException($retryAfter ?: 60);
            }

            throw new FacebookApiException("Facebook API error {$code}: {$message}");
        }

        if ($response->failed()) {
            throw new FacebookApiException(
                "Facebook API returned HTTP {$response->status()} with no error body."
            );
        }
    }
}
