<?php

declare(strict_types=1);

namespace App\Services\Integrations\Shopify;

use App\Exceptions\ShopifyException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * GraphQL Admin API client for Shopify bulk data sync (orders, products, inventory).
 *
 * Responsibility: execute GraphQL queries against a single store's Admin API,
 * handle rate limiting, and provide a cursor-based pagination helper.
 *
 * Authentication: Shopify Admin GraphQL API is authenticated with
 * X-Shopify-Access-Token header (same token as REST API).
 *
 * Rate limiting: Shopify uses a "cost" model (Leaky Bucket). Each query returns
 * remaining/max cost in the throttleStatus extension. We sleep when approaching
 * the limit to avoid 429s. The `release` backoff on 429 is handled by the calling
 * sync job's retry logic.
 *
 * Line items: fetched with first:250 (Shopify max per nested connection) to avoid
 * nested pagination. SMBs with >250 line items per order are extremely rare.
 *
 * Called by: ShopifyConnector (syncOrders, syncProducts, syncRefunds)
 * Reads from: Shopify Admin GraphQL API v{api_version}
 *
 * See: PLANNING.md "Phase 2 — Shopify"
 * @see PLANNING.md section Phase 2
 */
class ShopifyGraphQlClient
{
    private string $endpoint;

    /** Minimum remaining query cost before we sleep to respect rate limits. */
    private const COST_THRESHOLD = 100;

    public function __construct(
        private readonly string $domain,
        private readonly string $accessToken,
        private readonly string $apiVersion,
    ) {
        $bare           = rtrim(preg_replace('#^https?://#i', '', $domain), '/');
        $this->endpoint = "https://{$bare}/admin/api/{$apiVersion}/graphql.json";
    }

    // -------------------------------------------------------------------------
    // Query execution
    // -------------------------------------------------------------------------

    /**
     * Execute a single GraphQL query or mutation.
     *
     * @param  array<string, mixed> $variables
     *
     * @return array<string, mixed>  The `data` key of the response.
     *
     * @throws ShopifyException  On HTTP error, GraphQL error, or connection failure.
     */
    public function query(string $gql, array $variables = []): array
    {
        try {
            $response = Http::withHeaders([
                'X-Shopify-Access-Token' => $this->accessToken,
                'Content-Type'           => 'application/json',
            ])
                ->timeout(30)
                ->post($this->endpoint, [
                    'query'     => $gql,
                    'variables' => empty($variables) ? new \stdClass() : $variables,
                ]);
        } catch (ConnectionException $e) {
            throw new ShopifyException(
                "GraphQL connection failed for {$this->domain}: {$e->getMessage()}",
                previous: $e,
            );
        }

        if ($response->status() === 401) {
            throw new ShopifyException(
                'Shopify GraphQL: access token invalid or revoked (401)',
                httpStatus: 401,
            );
        }

        if ($response->failed()) {
            throw new ShopifyException(
                "Shopify GraphQL returned HTTP {$response->status()} for {$this->domain}",
                httpStatus: $response->status(),
            );
        }

        $body = $response->json();

        // GraphQL errors are returned with HTTP 200 but contain an `errors` key.
        if (! empty($body['errors'])) {
            $message = collect($body['errors'])->pluck('message')->implode('; ');
            throw new ShopifyException("Shopify GraphQL errors: {$message}");
        }

        // Throttle: slow down when approaching the cost limit to prevent 429s.
        $this->respectThrottle($body['extensions']['cost'] ?? null);

        return $body['data'] ?? [];
    }

    // -------------------------------------------------------------------------
    // Pagination
    // -------------------------------------------------------------------------

    /**
     * Iterate pages of a paginated GraphQL connection, yielding each page's edges.
     *
     * @param  array<string, mixed>    $variables   Base variables; `cursor` is injected per page.
     * @param  callable(array): array  $edgeExtractor  Given $data, returns the connection array
     *                                               with `edges` and `pageInfo` keys.
     *
     * @return \Generator<int, array<int, array<string, mixed>>>  Yields the `edges` array per page.
     *
     * @throws ShopifyException
     */
    public function paginate(string $gql, array $variables, callable $edgeExtractor): \Generator
    {
        $cursor = null;

        do {
            $pageVars = array_merge($variables, ['cursor' => $cursor]);
            $data     = $this->query($gql, $pageVars);

            $connection = $edgeExtractor($data);
            $edges      = $connection['edges'] ?? [];
            $pageInfo   = $connection['pageInfo'] ?? [];

            if (! empty($edges)) {
                yield $edges;
            }

            $hasNextPage = (bool) ($pageInfo['hasNextPage'] ?? false);
            $cursor      = $pageInfo['endCursor'] ?? null;
        } while ($hasNextPage && $cursor !== null);
    }

    // -------------------------------------------------------------------------
    // Shop info
    // -------------------------------------------------------------------------

    /**
     * Fetch basic shop metadata (name, currency, timezone).
     *
     * @return array{name: string, currency: string, timezone: string}
     *
     * @throws ShopifyException
     */
    public function getShop(): array
    {
        $data = $this->query(<<<'GQL'
        query {
          shop {
            name
            currencyCode
            ianaTimezone
          }
        }
        GQL);

        $shop = $data['shop'] ?? [];

        return [
            'name'     => (string) ($shop['name'] ?? $this->domain),
            'currency' => strtoupper((string) ($shop['currencyCode'] ?? 'USD')),
            'timezone' => (string) ($shop['ianaTimezone'] ?? 'UTC'),
        ];
    }

    // -------------------------------------------------------------------------
    // Webhooks
    // -------------------------------------------------------------------------

    /**
     * Register a webhook subscription via the GraphQL Admin API.
     *
     * @param  string $topic       GraphQL enum value, e.g. "ORDERS_CREATE"
     * @param  string $callbackUrl HTTPS endpoint that will receive deliveries
     *
     * @return array{id: string, topic: string}  GID and topic of the created subscription.
     *
     * @throws ShopifyException
     */
    public function createWebhookSubscription(string $topic, string $callbackUrl): array
    {
        $data = $this->query(<<<'GQL'
        mutation webhookSubscriptionCreate($topic: WebhookSubscriptionTopic!, $webhookSubscription: WebhookSubscriptionInput!) {
          webhookSubscriptionCreate(topic: $topic, webhookSubscription: $webhookSubscription) {
            webhookSubscription { id topic }
            userErrors { field message }
          }
        }
        GQL, [
            'topic'               => $topic,
            'webhookSubscription' => ['callbackUrl' => $callbackUrl],
        ]);

        $result = $data['webhookSubscriptionCreate'] ?? [];

        if (! empty($result['userErrors'])) {
            $errors = collect($result['userErrors'])->pluck('message')->implode('; ');
            throw new ShopifyException("webhookSubscriptionCreate failed for {$topic}: {$errors}");
        }

        $sub = $result['webhookSubscription'] ?? null;

        if ($sub === null) {
            throw new ShopifyException("webhookSubscriptionCreate returned no subscription for topic {$topic}");
        }

        return [
            'id'    => (string) $sub['id'],
            'topic' => (string) ($sub['topic'] ?? $topic),
        ];
    }

    /**
     * Delete a webhook subscription by its GID.
     *
     * userErrors are thrown so the caller can decide whether to swallow them.
     * "Not found" will surface as a userError — callers should catch and log.
     *
     * @throws ShopifyException
     */
    public function deleteWebhookSubscription(string $webhookGid): void
    {
        $data = $this->query(<<<'GQL'
        mutation webhookSubscriptionDelete($id: ID!) {
          webhookSubscriptionDelete(id: $id) {
            deletedWebhookSubscriptionId
            userErrors { field message }
          }
        }
        GQL, ['id' => $webhookGid]);

        $result = $data['webhookSubscriptionDelete'] ?? [];

        if (! empty($result['userErrors'])) {
            $errors = collect($result['userErrors'])->pluck('message')->implode('; ');
            throw new ShopifyException("webhookSubscriptionDelete failed for {$webhookGid}: {$errors}");
        }
    }

    // -------------------------------------------------------------------------
    // Throttle helper
    // -------------------------------------------------------------------------

    /**
     * If the remaining query cost is below the threshold, sleep briefly to let
     * the bucket refill. Shopify's bucket restores at 100 points/second.
     *
     * @param  array<string, mixed>|null $costExtension
     */
    private function respectThrottle(?array $costExtension): void
    {
        if ($costExtension === null) {
            return;
        }

        $remaining = (float) ($costExtension['throttleStatus']['currentlyAvailable'] ?? PHP_INT_MAX);

        if ($remaining < self::COST_THRESHOLD) {
            $sleepMs = (int) (($costExtension['requestedQueryCost'] ?? 10) * 10);
            Log::debug('ShopifyGraphQlClient: throttling', [
                'domain'    => $this->domain,
                'remaining' => $remaining,
                'sleep_ms'  => $sleepMs,
            ]);
            usleep($sleepMs * 1000);
        }
    }
}
