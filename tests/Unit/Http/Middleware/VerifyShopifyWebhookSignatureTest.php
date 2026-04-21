<?php

declare(strict_types=1);

namespace Tests\Unit\Http\Middleware;

use App\Http\Middleware\VerifyShopifyWebhookSignature;
use App\Models\Store;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Mockery;
use Tests\TestCase;

/**
 * Unit tests for VerifyShopifyWebhookSignature middleware.
 *
 * Verifies that:
 *   - A valid HMAC (base64(HMAC-SHA256(body, clientSecret))) passes through
 *   - A tampered body produces 401
 *   - A tampered signature produces 401
 *   - A missing X-Shopify-Hmac-Sha256 header produces 500
 *   - A non-existent or non-Shopify store produces 404
 *   - On success, the Store is attached to request attributes as 'webhook_store'
 */
class VerifyShopifyWebhookSignatureTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'test_client_secret_abc123';

    private Store $store;

    protected function setUp(): void
    {
        parent::setUp();

        config(['shopify.client_secret' => self::SECRET]);

        $workspace   = Workspace::factory()->create();
        $this->store = Store::factory()->create([
            'workspace_id' => $workspace->id,
            'platform'     => 'shopify',
        ]);
    }

    private function makeRequest(string $body, string $hmac, ?int $storeId = null): Request
    {
        $storeId = $storeId ?? $this->store->id;

        $request = Request::create(
            uri:    "/api/webhooks/shopify/{$storeId}",
            method: 'POST',
        );

        // Overwrite the body with raw content.
        $request->initialize(
            query:   [],
            request: [],
            attributes: [],
            cookies: [],
            files:   [],
            server:  ['CONTENT_TYPE' => 'application/json', 'REQUEST_METHOD' => 'POST'],
            content: $body,
        );

        $request->headers->set('X-Shopify-Hmac-Sha256', $hmac);

        // Mock the route resolver so $request->route('id') returns the store ID.
        $mockRoute = Mockery::mock();
        $mockRoute->shouldReceive('parameter')->with('id', null)->andReturn((string) $storeId);
        $request->setRouteResolver(fn () => $mockRoute);

        return $request;
    }

    private function validHmac(string $body): string
    {
        return base64_encode(hash_hmac('sha256', $body, self::SECRET, true));
    }

    private function callMiddleware(Request $request): \Symfony\Component\HttpFoundation\Response
    {
        $middleware = new VerifyShopifyWebhookSignature();

        return $middleware->handle($request, fn ($req) => new Response('ok', 200));
    }

    // -------------------------------------------------------------------------
    // Happy path
    // -------------------------------------------------------------------------

    public function test_passes_through_with_valid_hmac(): void
    {
        $body    = '{"id":12345,"topic":"orders/create"}';
        $request = $this->makeRequest($body, $this->validHmac($body));

        $response = $this->callMiddleware($request);

        $this->assertSame(200, $response->getStatusCode());
    }

    public function test_attaches_store_to_request_attributes_on_success(): void
    {
        $body    = '{"id":12345}';
        $request = $this->makeRequest($body, $this->validHmac($body));

        $this->callMiddleware($request);

        $attached = $request->attributes->get('webhook_store');
        $this->assertNotNull($attached);
        $this->assertSame($this->store->id, $attached->id);
    }

    // -------------------------------------------------------------------------
    // Tampered payload / signature → 401
    // -------------------------------------------------------------------------

    public function test_returns_401_when_body_is_tampered(): void
    {
        $originalBody = '{"id":12345,"topic":"orders/create"}';
        $tamperedBody = '{"id":99999,"topic":"orders/create"}';
        $request      = $this->makeRequest($tamperedBody, $this->validHmac($originalBody));

        $response = $this->callMiddleware($request);

        $this->assertSame(401, $response->getStatusCode());
    }

    public function test_returns_401_when_hmac_is_tampered(): void
    {
        $body    = '{"id":12345}';
        $request = $this->makeRequest($body, 'ZmFrZWhtYWM='); // base64('fakehmac')

        $response = $this->callMiddleware($request);

        $this->assertSame(401, $response->getStatusCode());
    }

    // -------------------------------------------------------------------------
    // Missing HMAC header → 500 (misconfiguration)
    // -------------------------------------------------------------------------

    public function test_returns_500_when_hmac_header_is_missing(): void
    {
        $body    = '{"id":12345}';
        $storeId = $this->store->id;

        $request = Request::create("/api/webhooks/shopify/{$storeId}", 'POST');
        $request->initialize(
            query:   [],
            request: [],
            attributes: [],
            cookies: [],
            files:   [],
            server:  ['CONTENT_TYPE' => 'application/json', 'REQUEST_METHOD' => 'POST'],
            content: $body,
        );
        // No HMAC header set.
        $mockRoute = Mockery::mock();
        $mockRoute->shouldReceive('parameter')->with('id', null)->andReturn((string) $storeId);
        $request->setRouteResolver(fn () => $mockRoute);

        $response = $this->callMiddleware($request);

        $this->assertSame(500, $response->getStatusCode());
    }

    // -------------------------------------------------------------------------
    // Store not found or wrong platform → 404
    // -------------------------------------------------------------------------

    public function test_returns_404_when_store_does_not_exist(): void
    {
        $body    = '{"id":12345}';
        $request = $this->makeRequest($body, $this->validHmac($body), storeId: 99999);

        $response = $this->callMiddleware($request);

        $this->assertSame(404, $response->getStatusCode());
    }

    public function test_returns_404_when_store_is_not_shopify_platform(): void
    {
        $workspace = Workspace::factory()->create();
        $wcStore   = Store::factory()->create([
            'workspace_id' => $workspace->id,
            'platform'     => 'woocommerce',
        ]);

        $body    = '{"id":12345}';
        $request = $this->makeRequest($body, $this->validHmac($body), storeId: $wcStore->id);

        $response = $this->callMiddleware($request);

        $this->assertSame(404, $response->getStatusCode());
    }
}
