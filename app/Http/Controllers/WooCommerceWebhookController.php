<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Jobs\ProcessWebhookJob;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Handles POST /api/webhooks/woocommerce/{id}.
 *
 * Signature verification has already been performed by VerifyWebhookSignature
 * middleware, which also attaches the Store model to $request->attributes.
 *
 * This controller:
 *   1. Persists a WebhookLog row (status = 'pending').
 *   2. Dispatches ProcessWebhookJob to the critical queue.
 *   3. Returns 200 immediately so WooCommerce stops retrying.
 *
 * Only order.created / order.updated / order.deleted are dispatched for
 * processing. Unknown topics are acknowledged with 200 but not queued.
 */
class WooCommerceWebhookController extends Controller
{
    private const SUPPORTED_EVENTS = [
        'order.created',
        'order.updated',
        'order.deleted',
    ];

    public function __invoke(Request $request): JsonResponse
    {
        /** @var \App\Models\Store $store */
        $store = $request->attributes->get('webhook_store');

        $event   = (string) $request->header('X-WC-Webhook-Topic', 'unknown');
        $payload = $request->json()->all();

        // Persist the log entry. Use DB::table() to bypass WorkspaceScope —
        // no session context exists in this request lifecycle.
        $logId = DB::table('webhook_logs')->insertGetId([
            'store_id'        => $store->id,
            'workspace_id'    => $store->workspace_id,
            'event'           => $event,
            'payload'         => json_encode($payload),
            'signature_valid' => true,
            'status'          => 'pending',
            'error_message'   => null,
            'processed_at'    => null,
            'created_at'      => now()->toDateTimeString(),
            'updated_at'      => now()->toDateTimeString(),
        ]);

        if (in_array($event, self::SUPPORTED_EVENTS, strict: true)) {
            ProcessWebhookJob::dispatch(
                webhookLogId: $logId,
                storeId:      $store->id,
                workspaceId:  $store->workspace_id,
                event:        $event,
                payload:      $payload,
            );
        }

        return response()->json(['status' => 'queued']);
    }
}
