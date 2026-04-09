<?php

declare(strict_types=1);

namespace Tests\Feature\Controllers;

use App\Jobs\ProcessWebhookJob;
use App\Models\Store;
use App\Models\Workspace;
use App\Models\WorkspaceUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class WooCommerceWebhookControllerTest extends TestCase
{
    use RefreshDatabase;

    private string $plainSecret = 'test-webhook-secret-abc123';
    private Workspace $workspace;
    private Store $store;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();

        $this->workspace = Workspace::factory()->create();
        $this->store     = Store::factory()->create([
            'workspace_id'             => $this->workspace->id,
            'webhook_secret_encrypted' => Crypt::encryptString($this->plainSecret),
        ]);
    }

    /**
     * Generate a valid WooCommerce HMAC signature for the given raw body.
     */
    private function sign(string $rawBody): string
    {
        return base64_encode(hash_hmac('sha256', $rawBody, $this->plainSecret, true));
    }

    private function webhookRequest(array $payload, string $event = 'order.created', ?string $signature = null): \Illuminate\Testing\TestResponse
    {
        $body      = json_encode($payload);
        $signature = $signature ?? $this->sign($body);

        return $this->call(
            'POST',
            "/api/webhooks/woocommerce/{$this->store->id}",
            [],
            [],
            [],
            [
                'HTTP_X-WC-Webhook-Signature' => $signature,
                'HTTP_X-WC-Webhook-Topic'     => $event,
                'CONTENT_TYPE'                => 'application/json',
            ],
            $body,
        );
    }

    public function test_valid_signature_returns_200(): void
    {
        $response = $this->webhookRequest(['id' => 1]);
        $response->assertStatus(200);
    }

    public function test_valid_signature_dispatches_job(): void
    {
        $this->webhookRequest(['id' => 1]);

        Queue::assertPushed(ProcessWebhookJob::class);
    }

    public function test_invalid_signature_returns_401(): void
    {
        $response = $this->webhookRequest(['id' => 1], signature: 'invalid-signature');
        $response->assertStatus(401);
    }

    public function test_invalid_signature_does_not_dispatch_job(): void
    {
        $this->webhookRequest(['id' => 1], signature: 'bad-sig');

        Queue::assertNotPushed(ProcessWebhookJob::class);
    }

    public function test_webhook_log_created_on_valid_receipt(): void
    {
        $this->webhookRequest(['id' => 42]);

        $this->assertDatabaseHas('webhook_logs', [
            'store_id'        => $this->store->id,
            'event'           => 'order.created',
            'signature_valid' => true,
            'status'          => 'pending',
        ]);
    }

    public function test_invalid_signature_logs_to_webhook_logs(): void
    {
        $this->webhookRequest(['id' => 1], signature: 'bad-sig');

        $this->assertDatabaseHas('webhook_logs', [
            'store_id'        => $this->store->id,
            'signature_valid' => false,
            'status'          => 'failed',
        ]);
    }

    public function test_order_updated_event_dispatches_job(): void
    {
        $this->webhookRequest(['id' => 1], event: 'order.updated');

        Queue::assertPushed(ProcessWebhookJob::class);
    }

    public function test_order_deleted_event_dispatches_job(): void
    {
        $this->webhookRequest(['id' => 1], event: 'order.deleted');

        Queue::assertPushed(ProcessWebhookJob::class);
    }

    public function test_unknown_topic_returns_200_without_dispatching(): void
    {
        $response = $this->webhookRequest(['id' => 1], event: 'product.created');

        $response->assertStatus(200);
        Queue::assertNotPushed(ProcessWebhookJob::class);
    }

    public function test_returns_404_for_unknown_store(): void
    {
        $body = json_encode(['id' => 1]);
        $sig  = base64_encode(hash_hmac('sha256', $body, 'any-secret', true));

        $response = $this->call(
            'POST',
            '/api/webhooks/woocommerce/99999',
            [],
            [],
            [],
            [
                'HTTP_X-WC-Webhook-Signature' => $sig,
                'HTTP_X-WC-Webhook-Topic'     => 'order.created',
                'CONTENT_TYPE'                => 'application/json',
            ],
            $body,
        );

        $response->assertStatus(404);
    }
}
