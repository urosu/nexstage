<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Store;
use App\Scopes\WorkspaceScope;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Verifies the HMAC signature on incoming WooCommerce webhook requests.
 *
 * WooCommerce signs each delivery with:
 *   X-WC-Webhook-Signature: base64(HMAC-SHA256(rawBody, webhookSecret))
 *
 * On valid signature: attaches the Store model to $request->attributes as
 * 'webhook_store' and calls $next().
 *
 * On invalid signature: logs the attempt to webhook_logs (bypassing
 * WorkspaceScope since no session context is present on webhook routes)
 * and returns HTTP 401.
 *
 * Applied only to /api/webhooks/woocommerce/{id} — not globally.
 */
class VerifyWebhookSignature
{
    public function handle(Request $request, Closure $next): Response
    {
        $storeId = (int) $request->route('id');

        // Load the store without WorkspaceScope — webhook routes have no session,
        // so WorkspaceContext is never set in this request lifecycle.
        $store = Store::withoutGlobalScope(WorkspaceScope::class)->find($storeId);

        if ($store === null) {
            Log::warning('VerifyWebhookSignature: store not found', ['store_id' => $storeId]);
            return response()->json(['error' => 'Not found'], 404);
        }

        if (empty($store->webhook_secret_encrypted)) {
            Log::error('VerifyWebhookSignature: store has no webhook secret', ['store_id' => $storeId]);
            return response()->json(['error' => 'Webhook not configured'], 500);
        }

        $rawBody   = $request->getContent();
        $signature = (string) $request->header('X-WC-Webhook-Signature', '');
        $event     = (string) $request->header('X-WC-Webhook-Topic', 'unknown');

        try {
            $secret = Crypt::decryptString($store->webhook_secret_encrypted);
        } catch (\Exception $e) {
            Log::error('VerifyWebhookSignature: failed to decrypt webhook secret', [
                'store_id' => $storeId,
            ]);
            return response()->json(['error' => 'Internal error'], 500);
        }

        $expected = base64_encode(hash_hmac('sha256', $rawBody, $secret, true));
        $valid    = hash_equals($expected, $signature);

        if (! $valid) {
            // Log the rejected delivery. Use DB::table() to bypass WorkspaceScope.
            $this->logInvalidSignature($store->id, $store->workspace_id, $event, $rawBody);

            Log::warning('VerifyWebhookSignature: invalid signature rejected', [
                'store_id' => $storeId,
            ]);

            return response()->json(['error' => 'Invalid signature'], 401);
        }

        // Attach the store so the controller avoids a second DB lookup.
        $request->attributes->set('webhook_store', $store);

        return $next($request);
    }

    private function logInvalidSignature(
        int    $storeId,
        int    $workspaceId,
        string $event,
        string $rawBody,
    ): void {
        $payload = json_decode($rawBody, true);
        if (! is_array($payload)) {
            $payload = ['raw' => mb_substr($rawBody, 0, 500)];
        }

        try {
            DB::table('webhook_logs')->insert([
                'store_id'         => $storeId,
                'workspace_id'     => $workspaceId,
                'event'            => $event,
                'payload'          => json_encode($payload),
                'signature_valid'  => false,
                'status'           => 'failed',
                'error_message'    => 'Invalid HMAC signature',
                'processed_at'     => null,
                'created_at'       => now()->toDateTimeString(),
                'updated_at'       => now()->toDateTimeString(),
            ]);
        } catch (\Throwable $e) {
            Log::error('VerifyWebhookSignature: failed to write webhook_log for invalid signature', [
                'store_id' => $storeId,
                'error'    => $e->getMessage(),
            ]);
        }
    }
}
