<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Jobs\ProcessShopifyWebhookJob;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Handles POST /api/webhooks/shopify/{id}.
 *
 * HMAC signature verification has already been performed by
 * VerifyShopifyWebhookSignature middleware, which also attaches the Store model
 * to $request->attributes as 'webhook_store'.
 *
 * This controller:
 *   1. Persists a WebhookLog row (status = 'pending').
 *   2. Dispatches ProcessShopifyWebhookJob to the critical-webhooks queue.
 *   3. Returns 200 immediately so Shopify stops retrying.
 *
 * Supported topics are queued for async processing; unknown topics are
 * acknowledged with 200 but not dispatched.
 *
 * Topic → X-Shopify-Topic header value (format: resource/verb, e.g. orders/create).
 *
 * Related: app/Http/Controllers/WooCommerceWebhookController.php
 * Related: app/Http/Middleware/VerifyShopifyWebhookSignature.php
 * See: PLANNING.md "Phase 2 — Shopify" Step 5
 */
class ShopifyWebhookController extends Controller
{
    private const SUPPORTED_TOPICS = [
        'orders/create',
        'orders/updated',
        'orders/cancelled',
        'products/update',
        'refunds/create',
    ];

    public function __invoke(Request $request): JsonResponse
    {
        /** @var \App\Models\Store $store */
        $store = $request->attributes->get('webhook_store');

        $topic   = (string) $request->header('X-Shopify-Topic', 'unknown');
        $payload = $request->json()->all();

        // Persist the log entry. Use DB::table() to bypass WorkspaceScope —
        // no session context exists in this request lifecycle.
        $logId = DB::table('webhook_logs')->insertGetId([
            'store_id'        => $store->id,
            'workspace_id'    => $store->workspace_id,
            'event'           => $topic,
            'payload'         => json_encode($payload),
            'signature_valid' => true,
            'status'          => 'pending',
            'error_message'   => null,
            'processed_at'    => null,
            'created_at'      => now()->toDateTimeString(),
            'updated_at'      => now()->toDateTimeString(),
        ]);

        if (in_array($topic, self::SUPPORTED_TOPICS, strict: true)) {
            ProcessShopifyWebhookJob::dispatch(
                webhookLogId: $logId,
                storeId:      $store->id,
                workspaceId:  $store->workspace_id,
                topic:        $topic,
                payload:      $payload,
            );
        }

        return response()->json(['status' => 'queued']);
    }
}
