<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\ConnectShopifyStoreAction;
use App\Exceptions\ShopifyException;
use App\Models\Store;
use App\Models\WorkspaceUser;
use App\Services\WorkspaceContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Handles the Shopify OAuth 2.0 flow for store installation and disconnection.
 *
 * Flow:
 *   1. GET /shopify/install   → install()    — validate shop param, redirect to Shopify dialog
 *   2. GET /shopify/callback  → callback()   — verify HMAC + state, exchange code, connect store
 *   3. DELETE /{workspace}/stores/{store}/shopify → disconnect() — revoke + soft-delete (Step 8)
 *
 * Two separate HMAC verifications happen during the OAuth flow:
 *   a) Callback HMAC: Shopify appends `hmac=...` to ALL callback query params.
 *      Verification: sort params (excluding hmac), HMAC-SHA256 with client_secret.
 *   b) State integrity: our own base64url+HMAC-SHA256 state parameter using app.key,
 *      same pattern used by FacebookOAuthController to prevent CSRF.
 *
 * See: PLANNING.md "Phase 2 — Shopify"
 * Related: app/Http/Controllers/FacebookOAuthController.php (state encode/decode pattern)
 * Related: app/Actions/ConnectShopifyStoreAction.php
 */
class ShopifyOAuthController extends Controller
{
    // -------------------------------------------------------------------------
    // Step 1 — validate shop domain, redirect to Shopify OAuth dialog
    // -------------------------------------------------------------------------

    /**
     * Initiate the Shopify OAuth install flow.
     *
     * Accepts the shop domain as a query parameter (?shop=my-store.myshopify.com).
     * Redirects the browser to Shopify's OAuth authorization dialog.
     *
     * Called from: the "Connect Shopify" form on onboarding + integrations page.
     */
    public function install(Request $request): RedirectResponse
    {
        $workspaceId = app(WorkspaceContext::class)->id();

        // Prefer an explicit workspace_id param (sent from the onboarding page to
        // avoid relying on session state, which can be stale in multi-workspace flows
        // — a visit to any workspace-prefixed URL in another tab overwrites the session).
        $explicitId = (int) $request->query('workspace_id', 0);
        if ($explicitId > 0) {
            $workspaceId = $explicitId;
        }

        if ($workspaceId === null) {
            return redirect('/onboarding');
        }

        $this->authorizeWorkspaceAccess($request, $workspaceId);

        $shop = $this->normaliseShopDomain((string) $request->query('shop', ''));

        if ($shop === null) {
            return redirect()->back()->with(
                'error',
                'Invalid shop domain. Enter your myshopify.com domain (e.g. my-store.myshopify.com).'
            );
        }

        $returnTo = $request->query('from') === 'onboarding' ? 'onboarding' : 'integrations';

        $nonce = Str::random(32);
        cache()->put("oauth_nonce_{$nonce}", true, now()->addMinutes(15));

        $state = $this->encodeState([
            'workspace_id'   => $workspaceId,
            'workspace_slug' => app(WorkspaceContext::class)->slug(),
            'type'           => 'shopify',
            'shop'           => $shop,
            'nonce'          => $nonce,
            'expires_at'     => now()->addMinutes(15)->timestamp,
            'return_to'      => $returnTo,
        ]);

        $params = http_build_query([
            'client_id'    => config('shopify.client_id'),
            'scope'        => config('shopify.scopes'),
            'redirect_uri' => config('shopify.redirect_uri'),
            'state'        => $state,
        ]);

        return redirect("https://{$shop}/admin/oauth/authorize?{$params}");
    }

    // -------------------------------------------------------------------------
    // Step 2 — verify, exchange code, connect store
    // -------------------------------------------------------------------------

    /**
     * Handle the Shopify OAuth callback.
     *
     * Shopify redirects here with: code, hmac, shop, state, timestamp.
     *
     * Performs three checks before exchanging the code:
     *   1. Shopify callback HMAC verification (prevents forged callbacks).
     *   2. State HMAC + expiry check (prevents CSRF).
     *   3. Nonce consumption (prevents replay within the HMAC window).
     */
    public function callback(Request $request): RedirectResponse
    {
        // --- 1. Verify Shopify's callback HMAC ---
        if (! $this->verifyShopifyCallbackHmac($request)) {
            Log::warning('ShopifyOAuth: callback HMAC invalid', [
                'shop' => $request->query('shop'),
                'ip'   => $request->ip(),
            ]);
            return $this->failRedirect('integrations', null, 'Shopify connection failed: invalid request signature. Please try again.');
        }

        // --- 2. Verify our state parameter ---
        $stateRaw = (string) $request->query('state', '');
        $state    = $this->decodeState($stateRaw);

        if ($state === null || ($state['type'] ?? '') !== 'shopify') {
            Log::warning('ShopifyOAuth: invalid or expired state', [
                'state_type' => $state['type'] ?? null,
            ]);
            return $this->failRedirect('integrations', null, 'Shopify connection failed: invalid or expired link. Please try again.');
        }

        // --- 3. Consume nonce (one-time use) ---
        $nonce = $state['nonce'] ?? '';
        if (! $nonce || ! cache()->pull("oauth_nonce_{$nonce}")) {
            Log::warning('ShopifyOAuth: nonce already used or missing', [
                'workspace_id' => $state['workspace_id'] ?? null,
            ]);
            return $this->failRedirect('integrations', null, 'Shopify connection failed: link already used. Please try again.');
        }

        $workspaceId   = (int) $state['workspace_id'];
        $workspaceSlug = $state['workspace_slug'] ?? null;
        $returnTo      = $state['return_to'] ?? 'integrations';
        $shopDomain    = (string) ($state['shop'] ?? $request->query('shop', ''));

        // Handle user-denied permission on the Shopify dialog.
        if ($request->query('error')) {
            return $this->failRedirect($returnTo, $workspaceSlug, 'Shopify connection was cancelled.');
        }

        $code = (string) $request->query('code', '');

        // --- 4. Exchange code for access token ---
        try {
            $accessToken = $this->exchangeCodeForToken($shopDomain, $code);
        } catch (\Throwable $e) {
            Log::error('ShopifyOAuth: token exchange failed', [
                'workspace_id' => $workspaceId,
                'shop'         => $shopDomain,
                'error'        => $e->getMessage(),
            ]);
            return $this->failRedirect($returnTo, $workspaceSlug, 'Shopify connection failed: could not exchange authorisation code.');
        }

        // --- 5. Connect (or reconnect) the store ---
        $workspace = \App\Models\Workspace::find($workspaceId);

        if ($workspace === null) {
            return $this->failRedirect($returnTo, $workspaceSlug, 'Workspace not found.');
        }

        try {
            (new ConnectShopifyStoreAction())->handle($workspace, $shopDomain, $accessToken);
        } catch (ShopifyException $e) {
            Log::error('ShopifyOAuth: store connection failed', [
                'workspace_id' => $workspaceId,
                'shop'         => $shopDomain,
                'error'        => $e->getMessage(),
            ]);
            return $this->failRedirect($returnTo, $workspaceSlug, 'Shopify connection failed: ' . $e->getMessage());
        }

        Log::info('ShopifyOAuth: store connected successfully', [
            'workspace_id' => $workspaceId,
            'shop'         => $shopDomain,
        ]);

        return redirect($this->oauthDest($returnTo, $workspaceSlug))
            ->with('success', "Shopify store {$shopDomain} connected successfully.");
    }

    // -------------------------------------------------------------------------
    // Shopify HMAC verification
    // -------------------------------------------------------------------------

    /**
     * Verify the HMAC Shopify appends to every OAuth callback request.
     *
     * Algorithm: sort all query params except `hmac` and `signature`, join as
     * "key=value&key=value", compute HMAC-SHA256 with client_secret, hex-encode,
     * and compare with the `hmac` param in constant time.
     *
     * Reference: https://shopify.dev/docs/apps/auth/oauth/verify
     */
    private function verifyShopifyCallbackHmac(Request $request): bool
    {
        $receivedHmac = (string) $request->query('hmac', '');
        if ($receivedHmac === '') {
            return false;
        }

        $params = $request->query();
        unset($params['hmac'], $params['signature']);
        ksort($params);

        $message  = http_build_query($params);
        $expected = hash_hmac('sha256', $message, (string) config('shopify.client_secret'));

        return hash_equals($expected, $receivedHmac);
    }

    // -------------------------------------------------------------------------
    // Token exchange
    // -------------------------------------------------------------------------

    /**
     * Exchange the OAuth authorisation code for a permanent offline access token.
     *
     * Shopify offline tokens never expire — token_expires_at is stored as NULL.
     *
     * @throws \RuntimeException  If the Shopify token endpoint returns an error.
     */
    private function exchangeCodeForToken(string $shopDomain, string $code): string
    {
        $response = Http::timeout(15)->post(
            "https://{$shopDomain}/admin/oauth/access_token",
            [
                'client_id'     => config('shopify.client_id'),
                'client_secret' => config('shopify.client_secret'),
                'code'          => $code,
            ]
        );

        if ($response->failed()) {
            throw new \RuntimeException(
                "Shopify token exchange failed: HTTP {$response->status()}"
            );
        }

        $body = $response->json();

        if (! isset($body['access_token'])) {
            throw new \RuntimeException(
                'Shopify token exchange: no access_token in response'
            );
        }

        return (string) $body['access_token'];
    }

    // -------------------------------------------------------------------------
    // State encode / decode  (same pattern as FacebookOAuthController)
    // -------------------------------------------------------------------------

    /**
     * @param  array<string, mixed> $data
     */
    private function encodeState(array $data): string
    {
        $payload   = rtrim(strtr(base64_encode((string) json_encode($data)), '+/', '-_'), '=');
        $signature = hash_hmac('sha256', $payload, config('app.key'));

        return $payload . '.' . $signature;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decodeState(string $state): ?array
    {
        if ($state === '') {
            return null;
        }

        $parts = explode('.', $state, 2);
        if (count($parts) !== 2) {
            return null;
        }

        [$payload, $signature] = $parts;

        $expected = hash_hmac('sha256', $payload, config('app.key'));
        if (! hash_equals($expected, $signature)) {
            return null;
        }

        $json = base64_decode(strtr($payload, '-_', '+/'), strict: false);
        if ($json === false) {
            return null;
        }

        $decoded = json_decode($json, associative: true);
        if (! is_array($decoded)) {
            return null;
        }

        if (isset($decoded['expires_at']) && now()->timestamp > (int) $decoded['expires_at']) {
            return null;
        }

        return $decoded;
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Normalise and validate a shop domain.
     *
     * Accepts "my-store", "my-store.myshopify.com", "https://my-store.myshopify.com".
     * Returns the bare myshopify.com domain, or null if the input is invalid.
     */
    private function normaliseShopDomain(string $input): ?string
    {
        if ($input === '') {
            return null;
        }

        // Strip scheme and trailing slash.
        $domain = rtrim(preg_replace('#^https?://#i', '', $input), '/');

        // If the user entered just the subdomain (no dot), append myshopify.com.
        if (! str_contains($domain, '.')) {
            $domain = $domain . '.myshopify.com';
        }

        // Must match the *.myshopify.com pattern.
        if (! preg_match('/^[a-z0-9][a-z0-9\-]*\.myshopify\.com$/i', $domain)) {
            return null;
        }

        return strtolower($domain);
    }

    private function oauthDest(string $returnTo, ?string $workspaceSlug = null): string
    {
        if ($returnTo === 'onboarding') {
            return '/onboarding';
        }

        if ($workspaceSlug === null) {
            $workspaceSlug = app(WorkspaceContext::class)->slug();
        }

        // Redirect to the store connect flow so the user can pick country + import date range.
        // The StoreSetupController detects the in-progress store (historical_import_completed_at IS NULL)
        // and shows step 2 (country) → step 3 (import date picker) automatically.
        return $workspaceSlug
            ? "/{$workspaceSlug}/stores/connect"
            : '/stores/connect';
    }

    private function failDest(string $returnTo, ?string $workspaceSlug = null): string
    {
        if ($returnTo === 'onboarding') {
            return '/onboarding';
        }

        if ($workspaceSlug === null) {
            $workspaceSlug = app(WorkspaceContext::class)->slug();
        }

        return $workspaceSlug
            ? "/{$workspaceSlug}/settings/integrations"
            : '/settings/integrations';
    }

    private function failRedirect(string $returnTo, ?string $workspaceSlug, string $message): RedirectResponse
    {
        $dest = rtrim(config('app.url'), '/') . $this->failDest($returnTo, $workspaceSlug);

        return redirect()->away(
            $dest . '?oauth_error=' . urlencode($message) . '&oauth_platform=shopify'
        );
    }

    private function authorizeWorkspaceAccess(Request $request, int $workspaceId): void
    {
        $allowed = WorkspaceUser::where('user_id', $request->user()?->id)
            ->where('workspace_id', $workspaceId)
            ->whereIn('role', ['owner', 'admin'])
            ->exists();

        abort_unless($allowed, 403, 'You do not have permission to connect integrations for this workspace.');
    }
}
