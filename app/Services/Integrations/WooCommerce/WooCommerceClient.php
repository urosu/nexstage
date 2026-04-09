<?php

declare(strict_types=1);

namespace App\Services\Integrations\WooCommerce;

use App\Exceptions\WooCommerceAuthException;
use App\Exceptions\WooCommerceConnectionException;
use App\Exceptions\WooCommerceRateLimitException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * HTTP client for the WooCommerce REST API v3.
 *
 * Handles authentication (Basic auth: consumer key + secret), store metadata
 * extraction from system_status, and webhook lifecycle (register / delete).
 *
 * All HTTP calls are synchronous and intended to be called from queue jobs or
 * actions that are themselves dispatched as jobs — never in the request cycle.
 */
class WooCommerceClient
{
    public function __construct(
        private readonly string $domain,
        private readonly string $consumerKey,
        private readonly string $consumerSecret,
    ) {}

    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    /**
     * Validate WooCommerce credentials and return store metadata.
     *
     * Calls GET /wp-json/wc/v3/system_status with Basic auth.
     *
     * @return array{name: string, currency: string, timezone: string}
     *
     * @throws WooCommerceAuthException       On HTTP 401 (invalid credentials).
     * @throws WooCommerceConnectionException On any other API failure.
     */
    public function validateAndGetMetadata(): array
    {
        try {
            $response = $this->http()->get($this->baseUrl() . '/system_status');
        } catch (ConnectionException $e) {
            throw new WooCommerceConnectionException(
                "Could not reach {$this->domain}. Check that the URL is correct and the site is reachable.",
                previous: $e,
            );
        }

        if ($response->status() === 401) {
            throw new WooCommerceAuthException(
                "WooCommerce authentication failed for {$this->domain}. Check consumer key and secret."
            );
        }

        if ($response->failed()) {
            throw new WooCommerceConnectionException(
                "WooCommerce system_status returned HTTP {$response->status()} for {$this->domain}."
            );
        }

        $data = $response->json();

        return [
            'name'     => $data['environment']['site_title'] ?? $this->domain,
            'currency' => $data['settings']['currency']      ?? 'EUR',
            'timezone' => $data['environment']['timezone']   ?? 'Europe/Berlin',
        ];
    }

    /**
     * Register order webhooks for the given store.
     *
     * Creates three webhooks (order.created, order.updated, order.deleted) pointing
     * to `{APP_URL}/api/webhooks/woocommerce/{storeId}`. The caller-supplied
     * $webhookSecret is passed to WooCommerce so both sides share it for HMAC
     * verification (X-WC-Webhook-Signature).
     *
     * @return array<string, int>  Map of event name → WooCommerce webhook ID.
     *                             e.g. ["order.created" => 42, "order.updated" => 43, "order.deleted" => 44]
     *
     * @throws WooCommerceConnectionException On API failure for any event.
     */
    public function registerWebhooks(int $storeId, string $webhookSecret): array
    {
        $events     = ['order.created', 'order.updated', 'order.deleted'];
        $appUrl     = rtrim((string) config('app.url'), '/');
        $deliveryUrl = "{$appUrl}/api/webhooks/woocommerce/{$storeId}";
        $webhookIds  = [];

        foreach ($events as $event) {
            try {
                $response = $this->http()->post($this->baseUrl() . '/webhooks', [
                    'name'         => 'Nexstage ' . $event,
                    'topic'        => $event,
                    'delivery_url' => $deliveryUrl,
                    'secret'       => $webhookSecret,
                    'status'       => 'active',
                ]);
            } catch (ConnectionException $e) {
                throw new WooCommerceConnectionException(
                    "Could not reach {$this->domain} while registering webhook for '{$event}'.",
                    previous: $e,
                );
            }

            if ($response->failed()) {
                throw new WooCommerceConnectionException(
                    "Failed to register WooCommerce webhook for event '{$event}' on {$this->domain}: HTTP {$response->status()}."
                );
            }

            $webhookIds[$event] = (int) $response->json('id');
        }

        return $webhookIds;
    }

    /**
     * Delete all webhooks listed in $platformWebhookIds.
     *
     * $platformWebhookIds is the JSON-decoded value of stores.platform_webhook_ids:
     *   ["order.created" => 42, "order.updated" => 43, "order.deleted" => 44]
     *
     * 404 responses are silently ignored (webhook already gone).
     * Other failures are logged as warnings but do not throw.
     *
     * @param array<string, int> $platformWebhookIds
     */
    public function deleteWebhooks(array $platformWebhookIds): void
    {
        foreach ($platformWebhookIds as $event => $webhookId) {
            $this->deleteWebhook((int) $webhookId, $event);
        }
    }

    /**
     * Delete a single WooCommerce webhook by its ID.
     *
     * 404 is silently ignored. Other failures are logged as warnings.
     */
    public function deleteWebhook(int $webhookId, string $event = ''): void
    {
        $response = $this->http()->delete(
            $this->baseUrl() . "/webhooks/{$webhookId}",
            ['force' => true],
        );

        if ($response->status() === 404) {
            return;
        }

        if ($response->failed()) {
            Log::warning('WooCommerceClient: failed to delete webhook', [
                'domain'     => $this->domain,
                'event'      => $event,
                'webhook_id' => $webhookId,
                'status'     => $response->status(),
            ]);
        }
    }

    // -------------------------------------------------------------------------
    // Order fetching
    // -------------------------------------------------------------------------

    /**
     * Return the total number of orders created after $afterDate.
     *
     * Sends a lightweight request (per_page=1) and reads the X-WP-Total response
     * header. Used to populate historical_import_total_orders before dispatch so
     * the UI can show a time estimate.
     *
     * @throws WooCommerceRateLimitException  On HTTP 429.
     * @throws WooCommerceConnectionException On any other API failure.
     */
    public function fetchOrderCount(string $afterDate): int
    {
        $response = $this->httpLong()->get($this->baseUrl() . '/orders', [
            'after'    => $afterDate,
            'per_page' => 1,
        ]);

        if ($response->status() === 429) {
            $retryAfter = (int) $response->header('Retry-After', 60);
            throw new WooCommerceRateLimitException($retryAfter);
        }

        if ($response->failed()) {
            throw new WooCommerceConnectionException(
                "Failed to fetch order count from {$this->domain}: HTTP {$response->status()}."
            );
        }

        return (int) $response->header('X-WP-Total', 0);
    }

    /**
     * Fetch a single page of orders within a date range for historical import.
     *
     * Sleeps 500 ms before fetching page > 1 to stay within WooCommerce's
     * undocumented rate limit.
     *
     * @return array{orders: array<int, array<string, mixed>>, total_pages: int, total: int}
     *
     * @throws WooCommerceRateLimitException  On HTTP 429.
     * @throws WooCommerceConnectionException On any other API failure.
     */
    public function fetchHistoricalOrdersPage(string $after, string $before, int $page = 1): array
    {
        if ($page > 1) {
            usleep(500_000); // 500 ms minimum between requests
        }

        $response = $this->httpLong()->get($this->baseUrl() . '/orders', [
            'after'    => $after,
            'before'   => $before,
            'orderby'  => 'date',
            'order'    => 'asc',
            'per_page' => 100,
            'page'     => $page,
        ]);

        if ($response->status() === 429) {
            $retryAfter = (int) $response->header('Retry-After', 60);
            throw new WooCommerceRateLimitException($retryAfter);
        }

        if ($response->failed()) {
            throw new WooCommerceConnectionException(
                "Failed to fetch historical orders from {$this->domain}: HTTP {$response->status()}."
            );
        }

        return [
            'orders'      => $response->json() ?? [],
            'total_pages' => (int) $response->header('X-WP-TotalPages', 1),
            'total'       => (int) $response->header('X-WP-Total', 0),
        ];
    }

    /**
     * Fetch orders modified after the given ISO 8601 UTC timestamp.
     *
     * Paginates until all pages are exhausted, sleeping 500 ms between requests
     * (per spec minimum delay). Returns the full flat list of order objects.
     *
     * @return array<int, array<string, mixed>>
     *
     * @throws WooCommerceConnectionException On any non-200 API response.
     */
    public function fetchModifiedOrders(string $modifiedAfter): array
    {
        $orders     = [];
        $page       = 1;
        $totalPages = 1;

        do {
            if ($page > 1) {
                usleep(500_000); // 500 ms minimum between requests
            }

            $response = $this->httpLong()->get($this->baseUrl() . '/orders', [
                'orderby'  => 'modified',
                'order'    => 'desc',
                'per_page' => 100,
                'after'    => $modifiedAfter,
                'page'     => $page,
            ]);

            if ($response->status() === 429) {
                $retryAfter = (int) $response->header('Retry-After', 60);
                throw new WooCommerceRateLimitException($retryAfter);
            }

            if ($response->failed()) {
                throw new WooCommerceConnectionException(
                    "Failed to fetch modified orders from {$this->domain}: HTTP {$response->status()}."
                );
            }

            $batch = $response->json() ?? [];

            if (empty($batch)) {
                break;
            }

            $orders     = array_merge($orders, $batch);
            $totalPages = (int) $response->header('X-WP-TotalPages', 1);
            $page++;
        } while ($page <= $totalPages);

        return $orders;
    }

    // -------------------------------------------------------------------------
    // Product fetching
    // -------------------------------------------------------------------------

    /**
     * Fetch a single page of products, optionally filtered by modification date.
     *
     * Sleeps 500 ms before fetching page > 1 to respect WooCommerce rate limits.
     *
     * @return array{products: array<int, array<string, mixed>>, total_pages: int}
     *
     * @throws WooCommerceRateLimitException  On HTTP 429.
     * @throws WooCommerceConnectionException On any other API failure.
     */
    public function fetchProductsPage(?string $modifiedAfter, int $page = 1): array
    {
        if ($page > 1) {
            usleep(500_000); // 500 ms minimum between requests
        }

        $params = ['per_page' => 100, 'page' => $page];

        if ($modifiedAfter !== null) {
            $params['modified_after'] = $modifiedAfter;
        }

        $response = $this->httpLong()->get($this->baseUrl() . '/products', $params);

        if ($response->status() === 429) {
            $retryAfter = (int) $response->header('Retry-After', 60);
            throw new WooCommerceRateLimitException($retryAfter);
        }

        if ($response->failed()) {
            throw new WooCommerceConnectionException(
                "Failed to fetch products from {$this->domain}: HTTP {$response->status()}."
            );
        }

        return [
            'products'    => $response->json() ?? [],
            'total_pages' => (int) $response->header('X-WP-TotalPages', 1),
        ];
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Build and validate the base URL, guarding against SSRF.
     *
     * Rules enforced:
     *  - Scheme is always forced to HTTPS (http:// inputs are rejected).
     *  - Host must be a valid public hostname (labels of a-z, 0-9, hyphen, dot).
     *  - IPv4 literals, IPv6 literals, and bare numeric octets are rejected.
     *  - Known loopback/private names (localhost, *.local, *.internal) are rejected.
     *  - No port number, path, query string, or fragment is allowed in the input.
     *
     * @throws WooCommerceConnectionException  If the domain fails any SSRF check.
     */
    private function baseUrl(): string
    {
        $isLocal = app()->environment('local');
        $input   = trim($this->domain, " \t\n\r\0\x0B/");

        // Detect and strip explicit scheme, enforcing HTTPS in production.
        $scheme = 'https';
        if (preg_match('#^(https?)://#i', $input, $m)) {
            if (! $isLocal && strtolower($m[1]) !== 'https') {
                throw new WooCommerceConnectionException(
                    "WooCommerce domain must use HTTPS, not HTTP: {$input}"
                );
            }
            $scheme = strtolower($m[1]);
            $input  = substr($input, strlen($m[0]));
        }

        // Reject any other scheme (file://, ftp://, etc.).
        if (str_contains($input, '://')) {
            throw new WooCommerceConnectionException(
                "WooCommerce domain contains an unsupported scheme: {$this->domain}"
            );
        }

        // Isolate the host (strip path/query/fragment if caller included them).
        $host = strtolower(explode('/', $input, 2)[0]);
        $host = strtolower(explode('?', $host, 2)[0]);
        $host = strtolower(explode('#', $host, 2)[0]);

        // Reject port numbers.
        if (str_contains($host, ':')) {
            throw new WooCommerceConnectionException(
                "WooCommerce domain must not include a port number: {$this->domain}"
            );
        }

        // Reject IPv4 literals (e.g. 127.0.0.1, 192.168.1.1).
        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            throw new WooCommerceConnectionException(
                "WooCommerce domain must be a hostname, not an IP address: {$this->domain}"
            );
        }

        // Reject IPv6 literals (e.g. [::1]).
        if (filter_var(trim($host, '[]'), FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            throw new WooCommerceConnectionException(
                "WooCommerce domain must be a hostname, not an IPv6 address: {$this->domain}"
            );
        }

        // Reject loopback / link-local / private hostnames in production.
        // In local dev, .localhost TLD and bare localhost are allowed for test stores.
        $isPrivateHost = $host === 'localhost'
            || str_ends_with($host, '.localhost')
            || str_ends_with($host, '.local')
            || str_ends_with($host, '.internal')
            || str_ends_with($host, '.localdomain');

        if ($isPrivateHost && ! $isLocal) {
            throw new WooCommerceConnectionException(
                "WooCommerce domain resolves to a private/internal host: {$this->domain}"
            );
        }

        // Ensure the host contains only valid DNS label characters.
        if (! preg_match('/^[a-z0-9]([a-z0-9\-]{0,61}[a-z0-9])?(\.[a-z0-9]([a-z0-9\-]{0,61}[a-z0-9])?)*$/i', $host)) {
            throw new WooCommerceConnectionException(
                "WooCommerce domain contains invalid characters: {$this->domain}"
            );
        }

        return $scheme . '://' . $host . '/wp-json/wc/v3';
    }

    /**
     * Pre-configured HTTP client with Basic auth.
     *
     * 5-second timeout — used in the request cycle (credential ping + webhook
     * registration). A dead store must not hang a PHP worker.
     */
    private function http(): PendingRequest
    {
        return Http::withBasicAuth($this->consumerKey, $this->consumerSecret)
            ->timeout(5)
            ->acceptJson();
    }

    /**
     * Long-timeout HTTP client for background job usage (order fetching).
     */
    private function httpLong(): PendingRequest
    {
        return Http::withBasicAuth($this->consumerKey, $this->consumerSecret)
            ->timeout(30)
            ->acceptJson();
    }
}
