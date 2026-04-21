<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Store;
use App\Scopes\WorkspaceScope;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Verifies the HMAC signature on incoming Shopify webhook requests.
 *
 * Shopify signs each delivery with:
 *   X-Shopify-Hmac-Sha256: base64(HMAC-SHA256(rawBody, clientSecret))
 *
 * Key difference from WooCommerce: the signing key is the app-level client_secret
 * from config (not a per-store secret). All Shopify stores sending webhooks to
 * this app share the same signing key.
 *
 * On valid signature: attaches the Store model to $request->attributes as
 * 'webhook_store' and calls $next().
 * On invalid signature: returns HTTP 401.
 *
 * Applied only to /api/webhooks/shopify/{id} — not globally.
 *
 * TODO Phase 2 Step 5: implement full HMAC verification body.
 *
 * See: PLANNING.md "Phase 2 — Shopify" Step 5
 * Related: app/Http/Middleware/VerifyWebhookSignature.php (WooCommerce equivalent)
 */
class VerifyShopifyWebhookSignature
{
    public function handle(Request $request, Closure $next): Response
    {
        $storeId = (int) $request->route('id');

        $store = Store::withoutGlobalScope(WorkspaceScope::class)->find($storeId);

        if ($store === null || $store->platform !== 'shopify') {
            Log::warning('VerifyShopifyWebhookSignature: store not found or wrong platform', [
                'store_id' => $storeId,
            ]);
            return response()->json(['error' => 'Not found'], 404);
        }

        $rawBody          = $request->getContent();
        $receivedHmac     = (string) $request->header('X-Shopify-Hmac-Sha256', '');
        $clientSecret     = (string) config('shopify.client_secret', '');

        if ($receivedHmac === '' || $clientSecret === '') {
            Log::error('VerifyShopifyWebhookSignature: missing HMAC header or client_secret not configured', [
                'store_id' => $storeId,
            ]);
            return response()->json(['error' => 'Webhook not configured'], 500);
        }

        $expected = base64_encode(hash_hmac('sha256', $rawBody, $clientSecret, true));

        if (! hash_equals($expected, $receivedHmac)) {
            Log::warning('VerifyShopifyWebhookSignature: invalid HMAC rejected', [
                'store_id' => $storeId,
            ]);
            return response()->json(['error' => 'Invalid signature'], 401);
        }

        $request->attributes->set('webhook_store', $store);

        return $next($request);
    }
}
