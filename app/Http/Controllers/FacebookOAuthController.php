<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Exceptions\FacebookApiException;
use App\Jobs\ComputeUtmCoverageJob;
use App\Jobs\SyncAdInsightsJob;
use App\Models\AdAccount;
use App\Models\SyncLog;
use App\Models\WorkspaceUser;
use App\Services\Integrations\Facebook\FacebookAdsClient;
use App\Services\WorkspaceContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Handles the Facebook Ads OAuth 2.0 flow.
 *
 * Flow:
 *   1. GET /oauth/facebook         → redirect()  → Facebook dialog
 *   2. GET /oauth/facebook/callback → callback() → token exchange → ad_accounts upsert
 *
 * State parameter: base64url-encoded JSON {"csrf":"...","workspace_id":N,"type":"facebook"}
 * This avoids delimiter collision as specified in CLAUDE.md §Facebook Ads.
 *
 * No refresh token for Facebook — long-lived tokens expire in ~60 days.
 * Owner is alerted 7 days before expiry via SyncAdInsightsJob failure path.
 */
class FacebookOAuthController extends Controller
{
    private const GRAPH_URL = 'https://graph.facebook.com';

    // -------------------------------------------------------------------------
    // Step 1 — redirect to Facebook OAuth dialog
    // -------------------------------------------------------------------------

    public function redirect(Request $request): RedirectResponse
    {
        $workspaceId = app(WorkspaceContext::class)->id();

        if ($workspaceId === null) {
            return redirect('/onboarding');
        }

        $this->authorizeWorkspaceAccess($request, $workspaceId);

        // Why: when initiated from onboarding tiles, return_to=onboarding routes the callback
        // and account-selection redirect back to /onboarding instead of /settings/integrations.
        $returnTo = $request->query('from') === 'onboarding' ? 'onboarding' : 'integrations';

        $nonce = Str::random(32);

        // Store nonce in cache so the callback can verify and consume it exactly once,
        // preventing replay of a valid HMAC-signed state within the 15-minute window.
        cache()->put("oauth_nonce_{$nonce}", true, now()->addMinutes(15));

        // reconnect_id: if set, the callback will update-in-place instead of showing the account picker.
        $reconnectId = $request->query('reconnect_id');

        $payload = [
            'workspace_id'   => $workspaceId,
            'workspace_slug' => app(WorkspaceContext::class)->slug(),
            'type'           => 'facebook',
            'nonce'          => $nonce,
            'expires_at'     => now()->addMinutes(15)->timestamp,
            'return_to'      => $returnTo,
        ];

        if ($reconnectId !== null) {
            $payload['reconnect_id'] = (int) $reconnectId;
        }

        $state = $this->encodeState($payload);

        $params = http_build_query([
            'client_id'    => config('services.facebook.client_id'),
            'redirect_uri' => config('services.facebook.redirect'),
            'scope'        => 'ads_read,business_management',
            'state'        => $state,
            'response_type' => 'code',
        ]);

        return redirect('https://www.facebook.com/v25.0/dialog/oauth?' . $params);
    }

    // -------------------------------------------------------------------------
    // Step 2 — exchange code, store tokens, dispatch sync
    // -------------------------------------------------------------------------

    public function callback(Request $request): RedirectResponse
    {
        // Validate state + CSRF
        $stateRaw = (string) $request->query('state', '');
        $state    = $this->decodeState($stateRaw);

        if ($state === null || ($state['type'] ?? '') !== 'facebook') {
            Log::warning('Facebook OAuth: invalid or expired state', [
                'state_type' => $state['type'] ?? null,
            ]);
            return redirect()->away(rtrim(config('app.url'), '/') . $this->oauthDest('integrations') . '?oauth_error=' . urlencode('Facebook connection failed: invalid or expired link. Please try again.') . '&oauth_platform=facebook');
        }

        // Consume the nonce to prevent state replay within the 15-minute HMAC window.
        $nonce = $state['nonce'] ?? '';
        if (! $nonce || ! cache()->pull("oauth_nonce_{$nonce}")) {
            Log::warning('Facebook OAuth: nonce already used or missing', [
                'workspace_id' => $state['workspace_id'] ?? null,
            ]);
            return redirect()->away(rtrim(config('app.url'), '/') . $this->oauthDest('integrations') . '?oauth_error=' . urlencode('Facebook connection failed: link already used. Please try again.') . '&oauth_platform=facebook');
        }

        $workspaceId   = (int) $state['workspace_id'];
        $workspaceSlug = $state['workspace_slug'] ?? null;
        $returnTo      = $state['return_to'] ?? 'integrations';
        $reconnectId   = isset($state['reconnect_id']) ? (int) $state['reconnect_id'] : null;

        // Handle user-denied permission
        if ($request->query('error')) {
            return redirect()->away(rtrim(config('app.url'), '/') . $this->oauthDest($returnTo, $workspaceSlug) . '?oauth_error=' . urlencode('Facebook connection was cancelled.') . '&oauth_platform=facebook');
        }

        $code = (string) $request->query('code', '');

        try {
            $shortToken                   = $this->exchangeCodeForShortToken($code);
            ['token' => $longToken, 'expires_in' => $expiresIn] = $this->exchangeForLongLivedToken($shortToken);

            // Reconnect path: skip account picker, update existing account in-place.
            if ($reconnectId !== null) {
                return $this->reconnectFacebookToken($workspaceId, $longToken, $expiresIn, $reconnectId, $returnTo, $workspaceSlug);
            }

            $client     = new FacebookAdsClient($longToken);
            $adAccounts = $client->fetchAllAdAccounts();
        } catch (\App\Exceptions\FacebookRateLimitException $e) {
            Log::warning('Facebook OAuth: rate limit hit while fetching ad accounts', [
                'workspace_id' => $workspaceId,
                'retry_after'  => $e->retryAfter,
            ]);
            return redirect()->away(rtrim(config('app.url'), '/') . $this->oauthDest($returnTo, $workspaceSlug) . '?oauth_error=' . urlencode('Facebook is temporarily rate-limited. Please wait a minute and try connecting again.') . '&oauth_platform=facebook');
        } catch (FacebookApiException $e) {
            Log::error('Facebook OAuth: token exchange or account fetch failed', [
                'workspace_id' => $workspaceId,
                'error'        => $e->getMessage(),
            ]);
            return redirect()->away(rtrim(config('app.url'), '/') . $this->oauthDest($returnTo, $workspaceSlug) . '?oauth_error=' . urlencode('Facebook connection failed: ' . $e->getMessage()) . '&oauth_platform=facebook');
        }

        if (empty($adAccounts)) {
            return redirect()->away(rtrim(config('app.url'), '/') . $this->oauthDest($returnTo, $workspaceSlug) . '?oauth_error=' . urlencode('No Facebook ad accounts were found for this user.') . '&oauth_platform=facebook');
        }

        $pendingKey = 'fb_pending_' . Str::uuid();

        // Encrypt the access token at rest in cache so Redis access alone does not
        // expose live Facebook credentials.
        cache()->put($pendingKey, [
            'workspace_id' => $workspaceId,
            'access_token' => Crypt::encryptString($longToken),
            'token_expiry' => now()->addSeconds($expiresIn)->timestamp,
            'accounts'     => array_map(static fn (array $a): array => [
                'id'       => ltrim((string) $a['id'], 'act_'),
                'name'     => (string) ($a['name'] ?? ltrim((string) $a['id'], 'act_')),
                'currency' => strtoupper((string) ($a['currency'] ?? 'USD')),
            ], $adAccounts),
            'return_to'    => $returnTo,
        ], now()->addMinutes(15));

        $redirectUrl = rtrim(config('app.url'), '/') . $this->oauthDest($returnTo, $workspaceSlug) . '?fb_pending=' . urlencode($pendingKey);

        return redirect()->away($redirectUrl);
    }

    // -------------------------------------------------------------------------
    // Account selection — POST /oauth/facebook/connect
    // -------------------------------------------------------------------------

    public function connectAdAccounts(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'fb_pending_key' => ['required', 'string'],
            'account_ids'    => ['required', 'array', 'min:1'],
            'account_ids.*'  => ['required', 'string'],
        ]);

        $workspaceId = app(WorkspaceContext::class)->id();

        if ($workspaceId === null) {
            return redirect('/onboarding');
        }

        $this->authorizeWorkspaceAccess($request, $workspaceId);

        $pending = cache()->get($validated['fb_pending_key']);

        if ($pending === null || (int) ($pending['workspace_id'] ?? 0) !== $workspaceId) {
            return redirect($this->oauthDest('integrations'))
                ->with('error', 'Link expired. Please re-connect Facebook.');
        }

        $returnTo = $pending['return_to'] ?? 'integrations';

        cache()->forget($validated['fb_pending_key']);

        $selectedIds = $validated['account_ids'];
        $longToken   = Crypt::decryptString($pending['access_token']);
        $tokenExpiry = \Carbon\Carbon::createFromTimestamp($pending['token_expiry']);
        $connected   = 0;

        foreach ($pending['accounts'] as $account) {
            if (! in_array($account['id'], $selectedIds, strict: true)) {
                continue;
            }

            /** @var AdAccount $adAccount */
            $adAccount = AdAccount::withoutGlobalScopes()->updateOrCreate(
                ['workspace_id' => $workspaceId, 'platform' => 'facebook', 'external_id' => $account['id']],
                [
                    'name'                      => $account['name'],
                    'currency'                  => $account['currency'],
                    'access_token_encrypted'    => Crypt::encryptString($longToken),
                    'refresh_token_encrypted'   => null,
                    'token_expires_at'          => $tokenExpiry,
                    'status'                    => 'active',
                    'consecutive_sync_failures' => 0,
                ]
            );

            if ($adAccount->wasRecentlyCreated || $adAccount->historical_import_status === null) {
                $adAccount->update([
                    'historical_import_status'     => 'pending',
                    'historical_import_from'       => now()->subMonths(37)->toDateString(),
                    'historical_import_checkpoint' => null,
                    'historical_import_progress'   => null,
                ]);

                $adSyncLog = SyncLog::create([
                    'workspace_id'  => $workspaceId,
                    'syncable_type' => AdAccount::class,
                    'syncable_id'   => $adAccount->id,
                    'job_type'      => \App\Jobs\AdHistoricalImportJob::class,
                    'status'        => 'queued',
                    'queue'         => 'imports',
                    'scheduled_at'  => now(),
                ]);

                \App\Jobs\AdHistoricalImportJob::dispatch($adAccount->id, $workspaceId, $adSyncLog->id);
            }

            SyncAdInsightsJob::dispatch($adAccount->id, $workspaceId, $adAccount->platform);
            $connected++;
        }

        if ($connected > 0) {
            // Why: has_ads drives billing basis for non-ecom workspaces + nav visibility.
            // See: PLANNING.md "Billing basis auto-derivation"
            DB::table('workspaces')
                ->where('id', $workspaceId)
                ->update(['has_ads' => true]);

            // Recompute UTM coverage now that ads are connected.
            ComputeUtmCoverageJob::dispatch($workspaceId)->onQueue('low');
        }

        Log::info('Facebook OAuth: connected ad accounts', [
            'workspace_id' => $workspaceId,
            'count'        => $connected,
        ]);

        return redirect($this->oauthDest($returnTo))
            ->with('success', "{$connected} Facebook ad account(s) connected successfully.");
    }

    // -------------------------------------------------------------------------
    // Reconnect handler — update token in-place without showing the picker
    // -------------------------------------------------------------------------

    /**
     * Re-authorise an existing Facebook Ads account after its token expired.
     * Updates the long-lived token, resets failure counters, and re-queues a failed historical import.
     */
    private function reconnectFacebookToken(
        int $workspaceId,
        string $longToken,
        int $expiresIn,
        int $accountId,
        string $returnTo,
        ?string $workspaceSlug,
    ): RedirectResponse {
        $account = AdAccount::withoutGlobalScopes()
            ->where('id', $accountId)
            ->where('workspace_id', $workspaceId)
            ->where('platform', 'facebook')
            ->first();

        if ($account === null) {
            return redirect()->away(
                rtrim(config('app.url'), '/') . $this->oauthDest($returnTo, $workspaceSlug)
                . '?oauth_error=' . urlencode('Account not found or access denied.')
                . '&oauth_platform=facebook'
            );
        }

        $account->update([
            'access_token_encrypted'    => Crypt::encryptString($longToken),
            'refresh_token_encrypted'   => null,
            'token_expires_at'          => now()->addSeconds($expiresIn),
            'status'                    => 'active',
            'consecutive_sync_failures' => 0,
        ]);

        if ($account->historical_import_status === 'failed') {
            $account->update(['historical_import_status' => 'pending']);

            $syncLog = \App\Models\SyncLog::create([
                'workspace_id'  => $workspaceId,
                'syncable_type' => AdAccount::class,
                'syncable_id'   => $account->id,
                'job_type'      => \App\Jobs\AdHistoricalImportJob::class,
                'status'        => 'queued',
                'queue'         => 'imports',
                'scheduled_at'  => now(),
            ]);

            \App\Jobs\AdHistoricalImportJob::dispatch($account->id, $workspaceId, $syncLog->id);
        }

        SyncAdInsightsJob::dispatch($account->id, $workspaceId, 'facebook');

        Log::info('Facebook: ad account token reconnected', [
            'workspace_id' => $workspaceId,
            'account_id'   => $account->id,
        ]);

        return redirect($this->oauthDest($returnTo, $workspaceSlug))
            ->with('success', 'Facebook Ads account reconnected successfully.');
    }

    // -------------------------------------------------------------------------
    // Token exchange helpers
    // -------------------------------------------------------------------------

    /**
     * Exchange the authorization code for a short-lived user access token.
     *
     * @throws FacebookApiException
     */
    private function exchangeCodeForShortToken(string $code): string
    {
        $response = Http::timeout(10)->get(self::GRAPH_URL . '/oauth/access_token', [
            'client_id'     => config('services.facebook.client_id'),
            'client_secret' => config('services.facebook.client_secret'),
            'redirect_uri'  => config('services.facebook.redirect'),
            'code'          => $code,
        ]);

        if ($response->failed()) {
            throw new FacebookApiException(
                'Short-lived token exchange failed: HTTP ' . $response->status()
            );
        }

        $body = $response->json();

        if (isset($body['error'])) {
            throw new FacebookApiException(
                'Short-lived token exchange error: ' . ($body['error']['message'] ?? 'unknown')
            );
        }

        return (string) $body['access_token'];
    }

    /**
     * Exchange a short-lived token for a long-lived token (~60 days).
     *
     * @return array{token: string, expires_in: int}
     *
     * @throws FacebookApiException
     */
    private function exchangeForLongLivedToken(string $shortToken): array
    {
        $response = Http::timeout(10)->get(self::GRAPH_URL . '/oauth/access_token', [
            'grant_type'        => 'fb_exchange_token',
            'client_id'         => config('services.facebook.client_id'),
            'client_secret'     => config('services.facebook.client_secret'),
            'fb_exchange_token' => $shortToken,
        ]);

        if ($response->failed()) {
            throw new FacebookApiException(
                'Long-lived token exchange failed: HTTP ' . $response->status()
            );
        }

        $body = $response->json();

        if (isset($body['error'])) {
            throw new FacebookApiException(
                'Long-lived token exchange error: ' . ($body['error']['message'] ?? 'unknown')
            );
        }

        return [
            'token'      => (string) $body['access_token'],
            'expires_in' => (int) ($body['expires_in'] ?? 5_184_000), // 60 days fallback
        ];
    }

    // -------------------------------------------------------------------------
    // State encoding / decoding
    // -------------------------------------------------------------------------

    /**
     * base64url-encode a JSON payload and append an HMAC-SHA256 signature.
     *
     * @param  array<string, mixed> $data
     */
    private function encodeState(array $data): string
    {
        $payload   = rtrim(strtr(base64_encode((string) json_encode($data)), '+/', '-_'), '=');
        $signature = hash_hmac('sha256', $payload, config('app.key'));

        return $payload . '.' . $signature;
    }

    /**
     * Verify the HMAC signature and decode the state payload.
     *
     * Returns null if the signature is invalid, the payload is malformed,
     * or the state has expired.
     *
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
    // Authorization
    // -------------------------------------------------------------------------

    /**
     * Abort with 403 if the authenticated user is not a member of the workspace.
     *
     * Connecting integrations requires owner or admin role. Members cannot.
     */
    /**
     * Return the path to redirect to after OAuth, depending on where the flow was initiated.
     * When initiated from onboarding tiles, return '/onboarding'; otherwise '/settings/integrations'.
     */
    private function oauthDest(string $returnTo, ?string $workspaceSlug = null): string
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

    private function authorizeWorkspaceAccess(Request $request, int $workspaceId): void
    {
        $allowed = WorkspaceUser::where('user_id', $request->user()?->id)
            ->where('workspace_id', $workspaceId)
            ->whereIn('role', ['owner', 'admin'])
            ->exists();

        abort_unless($allowed, 403, 'You do not have permission to connect integrations for this workspace.');
    }
}
