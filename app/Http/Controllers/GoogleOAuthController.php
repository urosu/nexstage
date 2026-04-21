<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Exceptions\GoogleAccountDisabledException;
use App\Exceptions\GoogleApiException;
use App\Exceptions\GoogleTokenExpiredException;
use App\Jobs\ComputeUtmCoverageJob;
use App\Jobs\SyncAdInsightsJob;
use App\Jobs\SyncSearchConsoleJob;
use App\Models\AdAccount;
use App\Models\SearchConsoleProperty;
use App\Models\Store;
use App\Models\SyncLog;
use App\Models\WorkspaceUser;
use App\Services\Integrations\Google\GoogleAdsClient;
use App\Services\Integrations\SearchConsole\SearchConsoleClient;
use App\Services\WorkspaceContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Handles Google OAuth 2.0 flows for both Google Ads and Google Search Console.
 *
 * A single /oauth/google/callback route handles both integrations. The `type`
 * field in the base64url-encoded state JSON determines which flow to complete:
 *   - type="google_ads" → exchange tokens, list ad accounts, upsert ad_accounts rows
 *   - type="gsc"        → exchange tokens, list properties, store tokens in session,
 *                         redirect to integrations so user can pick a property
 *
 * The same GOOGLE_REDIRECT_URI is used for both (differentiated by state `type`).
 *
 * Redirect entry points:
 *   GET /oauth/google/ads  → redirectGoogleAds()
 *   GET /oauth/google/gsc  → redirectGsc()
 *   GET /oauth/google/callback → callback()  ← single handler for both
 *
 * GSC property selection (after callback):
 *   POST /oauth/gsc/connect → connectGscProperty()
 */
class GoogleOAuthController extends Controller
{
    private const AUTH_URL  = 'https://accounts.google.com/o/oauth2/v2/auth';
    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';

    private const SCOPE_ADS = 'https://www.googleapis.com/auth/adwords';
    private const SCOPE_GSC = 'https://www.googleapis.com/auth/webmasters.readonly';

    // -------------------------------------------------------------------------
    // Step 1a — redirect to Google Ads OAuth
    // -------------------------------------------------------------------------

    public function redirectGoogleAds(Request $request): RedirectResponse
    {
        $workspaceId = app(WorkspaceContext::class)->id();

        if ($workspaceId === null) {
            return redirect('/onboarding');
        }

        $this->authorizeWorkspaceAccess($request, $workspaceId);

        // Why: when initiated from onboarding tiles, return_to routes the callback back to onboarding.
        $returnTo = $request->query('from') === 'onboarding' ? 'onboarding' : 'integrations';

        $nonce = Str::random(32);

        // Store nonce in cache so the callback can verify and consume it exactly once,
        // preventing replay of a valid HMAC-signed state within the 15-minute window.
        cache()->put("oauth_nonce_{$nonce}", true, now()->addMinutes(15));

        $payload = [
            'workspace_id'   => $workspaceId,
            'workspace_slug' => app(WorkspaceContext::class)->slug(),
            'type'           => 'google_ads',
            'nonce'          => $nonce,
            'expires_at'     => now()->addMinutes(15)->timestamp,
            'return_to'      => $returnTo,
        ];

        // reconnect_id: if set, the callback will update-in-place instead of showing the account picker.
        $reconnectId = $request->query('reconnect_id');
        if ($reconnectId !== null) {
            $payload['reconnect_id'] = (int) $reconnectId;
        }

        $state = $this->encodeState($payload);

        return redirect($this->buildAuthUrl(self::SCOPE_ADS, $state));
    }

    // -------------------------------------------------------------------------
    // Step 1b — redirect to GSC OAuth
    // -------------------------------------------------------------------------

    public function redirectGsc(Request $request): RedirectResponse
    {
        $workspaceId = app(WorkspaceContext::class)->id();

        if ($workspaceId === null) {
            return redirect('/onboarding');
        }

        $this->authorizeWorkspaceAccess($request, $workspaceId);

        // Why: when initiated from onboarding tiles, return_to routes the callback back to onboarding.
        $returnTo = $request->query('from') === 'onboarding' ? 'onboarding' : 'integrations';

        $nonce = Str::random(32);

        // Store nonce in cache so the callback can verify and consume it exactly once,
        // preventing replay of a valid HMAC-signed state within the 15-minute window.
        cache()->put("oauth_nonce_{$nonce}", true, now()->addMinutes(15));

        // reconnect_id: if set, the callback will update-in-place instead of showing the property picker.
        $reconnectId = $request->query('reconnect_id');

        $payload = [
            'workspace_id'   => $workspaceId,
            'workspace_slug' => app(WorkspaceContext::class)->slug(),
            'type'           => 'gsc',
            'nonce'          => $nonce,
            'expires_at'     => now()->addMinutes(15)->timestamp,
            'return_to'      => $returnTo,
        ];

        if ($reconnectId !== null) {
            $payload['reconnect_id'] = (int) $reconnectId;
        }

        $state = $this->encodeState($payload);

        return redirect($this->buildAuthUrl(self::SCOPE_GSC, $state));
    }

    // -------------------------------------------------------------------------
    // Step 2 — single callback, routes by state `type`
    // -------------------------------------------------------------------------

    public function callback(Request $request): RedirectResponse
    {
        $stateRaw = (string) $request->query('state', '');
        $state    = $this->decodeState($stateRaw);

        if (
            $state === null
            || ! in_array($state['type'] ?? '', ['google_ads', 'gsc'], strict: true)
        ) {
            Log::warning('Google OAuth: invalid or expired state', [
                'state_type' => $state['type'] ?? null,
            ]);
            return redirect()->away(rtrim(config('app.url'), '/') . $this->oauthDest('integrations') . '?oauth_error=' . urlencode('Google connection failed: invalid or expired link. Please try again.'));
        }

        // Consume the nonce to prevent state replay within the 15-minute HMAC window.
        $nonce = $state['nonce'] ?? '';
        if (! $nonce || ! cache()->pull("oauth_nonce_{$nonce}")) {
            Log::warning('Google OAuth: nonce already used or missing', [
                'workspace_id' => $state['workspace_id'] ?? null,
                'type'         => $state['type'] ?? null,
            ]);
            return redirect()->away(rtrim(config('app.url'), '/') . $this->oauthDest('integrations') . '?oauth_error=' . urlencode('Google connection failed: link already used. Please try again.'));
        }

        $workspaceId   = (int) $state['workspace_id'];
        $workspaceSlug = $state['workspace_slug'] ?? null;
        $platform      = $state['type'] === 'gsc' ? 'gsc' : 'google_ads';
        $returnTo      = $state['return_to'] ?? 'integrations';

        if ($request->query('error')) {
            return redirect()->away(rtrim(config('app.url'), '/') . $this->oauthDest($returnTo, $workspaceSlug) . '?oauth_error=' . urlencode('Google connection was cancelled.') . '&oauth_platform=' . $platform);
        }

        $code = (string) $request->query('code', '');

        try {
            $tokens = $this->exchangeCode($code);
        } catch (GoogleApiException $e) {
            Log::error('Google OAuth: token exchange failed', [
                'workspace_id' => $workspaceId,
                'type'         => $state['type'],
                'error'        => $e->getMessage(),
            ]);
            return redirect()->away(rtrim(config('app.url'), '/') . $this->oauthDest($returnTo, $workspaceSlug) . '?oauth_error=' . urlencode('Google connection failed: ' . $e->getMessage()) . '&oauth_platform=' . $platform);
        }

        $reconnectId = isset($state['reconnect_id']) ? (int) $state['reconnect_id'] : null;

        return match ($state['type']) {
            'google_ads' => $this->handleGoogleAdsCallback($request, $workspaceId, $tokens, $returnTo, $workspaceSlug, $reconnectId),
            'gsc'        => $this->handleGscCallback($workspaceId, $tokens, $returnTo, $workspaceSlug, $reconnectId),
        };
    }

    // -------------------------------------------------------------------------
    // GSC property selection — POST /oauth/gsc/connect
    // -------------------------------------------------------------------------

    /**
     * Finalise GSC connection after the user picks a property from the list
     * stored in their session after the OAuth callback.
     */
    public function connectGscProperty(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'property_url'    => ['required', 'string', 'max:500'],
            'gsc_pending_key' => ['required', 'string'],
        ]);

        $workspaceId = app(WorkspaceContext::class)->id();

        if ($workspaceId === null) {
            return redirect('/onboarding');
        }

        $this->authorizeWorkspaceAccess($request, $workspaceId);

        $pendingKey  = $validated['gsc_pending_key'];
        $sessionData = cache()->get($pendingKey);

        if (
            $sessionData === null
            || (int) ($sessionData['workspace_id'] ?? 0) !== $workspaceId
        ) {
            return redirect($this->oauthDest('integrations'))
                ->with('error', 'Link expired. Please re-connect Google Search Console.');
        }

        $returnTo = $sessionData['return_to'] ?? 'integrations';

        cache()->forget($pendingKey);

        $propertyUrl  = $validated['property_url'];
        $accessToken  = Crypt::decryptString($sessionData['access_token']);
        $refreshToken = Crypt::decryptString($sessionData['refresh_token']);
        $expiresAt    = $sessionData['expires_at'];

        // Auto-link to a store if the property domain matches a store domain
        $storeId = $this->findMatchingStore($workspaceId, $propertyUrl);

        /** @var SearchConsoleProperty $property */
        $property = SearchConsoleProperty::withoutGlobalScopes()->updateOrCreate(
            [
                'workspace_id' => $workspaceId,
                'property_url' => $propertyUrl,
            ],
            [
                'store_id'                => $storeId,
                'access_token_encrypted'  => Crypt::encryptString($accessToken),
                'refresh_token_encrypted' => Crypt::encryptString($refreshToken),
                'token_expires_at'        => $expiresAt,
                'status'                  => 'active',
                'consecutive_sync_failures' => 0,
            ]
        );

        if ($property->wasRecentlyCreated || $property->historical_import_status === null) {
            $property->update([
                'historical_import_status'     => 'pending',
                'historical_import_from'       => now()->subMonths(16)->toDateString(),
                'historical_import_checkpoint' => null,
                'historical_import_progress'   => null,
            ]);

            $gscSyncLog = SyncLog::create([
                'workspace_id'  => $workspaceId,
                'syncable_type' => SearchConsoleProperty::class,
                'syncable_id'   => $property->id,
                'job_type'      => \App\Jobs\GscHistoricalImportJob::class,
                'status'        => 'queued',
                'queue'         => 'imports',
                'scheduled_at'  => now(),
            ]);

            \App\Jobs\GscHistoricalImportJob::dispatch($property->id, $workspaceId, $gscSyncLog->id);
        }

        // Why: has_gsc drives nav visibility. See: PLANNING.md "Workspace integration flags"
        DB::table('workspaces')
            ->where('id', $workspaceId)
            ->update(['has_gsc' => true]);

        SyncSearchConsoleJob::dispatch($property->id, $workspaceId);

        Log::info('GSC: property connected', [
            'workspace_id' => $workspaceId,
            'property_url' => $propertyUrl,
            'property_id'  => $property->id,
        ]);

        return redirect($this->oauthDest($returnTo))
            ->with('success', 'Google Search Console property connected successfully.');
    }

    // -------------------------------------------------------------------------
    // Google Ads account selection — POST /oauth/google/ads/connect
    // -------------------------------------------------------------------------

    public function connectGoogleAdsAccounts(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'gads_pending_key' => ['required', 'string'],
            'account_ids'      => ['required', 'array', 'min:1'],
            'account_ids.*'    => ['required', 'string'],
        ]);

        $workspaceId = app(WorkspaceContext::class)->id();

        if ($workspaceId === null) {
            return redirect('/onboarding');
        }

        $this->authorizeWorkspaceAccess($request, $workspaceId);

        $pending = cache()->get($validated['gads_pending_key']);

        if ($pending === null || (int) ($pending['workspace_id'] ?? 0) !== $workspaceId) {
            return redirect($this->oauthDest('integrations'))
                ->with('error', 'Link expired. Please re-connect Google Ads.');
        }

        $returnTo = $pending['return_to'] ?? 'integrations';

        cache()->forget($validated['gads_pending_key']);

        $selectedIds  = $validated['account_ids'];
        $accessToken  = Crypt::decryptString($pending['access_token']);
        $refreshToken = Crypt::decryptString($pending['refresh_token']);
        $expiresAt    = \Carbon\Carbon::createFromTimestamp($pending['expires_at']);
        $connected    = 0;

        foreach ($pending['accounts'] as $account) {
            if (! in_array($account['id'], $selectedIds, strict: true)) {
                continue;
            }

            /** @var \App\Models\AdAccount $adAccount */
            $adAccount = \App\Models\AdAccount::withoutGlobalScopes()->updateOrCreate(
                ['workspace_id' => $workspaceId, 'platform' => 'google', 'external_id' => $account['id']],
                [
                    'name'                      => $account['name'],
                    'currency'                  => $account['currency'],
                    'access_token_encrypted'    => Crypt::encryptString($accessToken),
                    'refresh_token_encrypted'   => Crypt::encryptString($refreshToken),
                    'token_expires_at'          => $expiresAt,
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

            \App\Jobs\SyncAdInsightsJob::dispatch($adAccount->id, $workspaceId, $adAccount->platform);
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

        Log::info('Google Ads OAuth: connected ad accounts', [
            'workspace_id' => $workspaceId,
            'count'        => $connected,
        ]);

        return redirect($this->oauthDest($returnTo))
            ->with('success', "{$connected} Google Ads account(s) connected successfully.");
    }

    // -------------------------------------------------------------------------
    // Reconnect handlers — update tokens in-place without showing pickers
    // -------------------------------------------------------------------------

    /**
     * Re-authorise an existing GSC property after its token expired.
     * Updates the token, resets failure counters, and re-queues a failed historical import.
     *
     * @param  array{access_token: string, refresh_token: string, expires_at: \Carbon\Carbon} $tokens
     */
    private function reconnectGscToken(
        int $workspaceId,
        array $tokens,
        int $propertyId,
        string $returnTo,
        ?string $workspaceSlug,
    ): RedirectResponse {
        $property = SearchConsoleProperty::withoutGlobalScopes()
            ->where('id', $propertyId)
            ->where('workspace_id', $workspaceId)
            ->first();

        if ($property === null) {
            return redirect($this->oauthDest($returnTo, $workspaceSlug))
                ->with('error', 'Property not found or access denied.');
        }

        $property->update([
            'access_token_encrypted'    => Crypt::encryptString($tokens['access_token']),
            'refresh_token_encrypted'   => Crypt::encryptString($tokens['refresh_token']),
            'token_expires_at'          => $tokens['expires_at'],
            'status'                    => 'active',
            'consecutive_sync_failures' => 0,
        ]);

        if ($property->historical_import_status === 'failed') {
            $property->update(['historical_import_status' => 'pending']);

            $syncLog = SyncLog::create([
                'workspace_id'  => $workspaceId,
                'syncable_type' => SearchConsoleProperty::class,
                'syncable_id'   => $property->id,
                'job_type'      => \App\Jobs\GscHistoricalImportJob::class,
                'status'        => 'queued',
                'queue'         => 'imports',
                'scheduled_at'  => now(),
            ]);

            \App\Jobs\GscHistoricalImportJob::dispatch($property->id, $workspaceId, $syncLog->id);
        }

        SyncSearchConsoleJob::dispatch($property->id, $workspaceId);

        $queueKey = "sync_queued_gsc_{$workspaceId}";
        $queued   = cache()->get($queueKey, []);
        if (! in_array($property->id, $queued)) {
            cache()->put($queueKey, [...$queued, $property->id], now()->addMinutes(5));
        }

        Log::info('GSC: property token reconnected', [
            'workspace_id' => $workspaceId,
            'property_id'  => $property->id,
            'property_url' => $property->property_url,
        ]);

        return redirect($this->oauthDest($returnTo, $workspaceSlug))
            ->with('success', 'Google Search Console reconnected successfully.');
    }

    /**
     * Re-authorise an existing Google Ads account after its token expired.
     * Updates the token, resets failure counters, and re-queues a failed historical import.
     *
     * @param  array{access_token: string, refresh_token: string, expires_at: \Carbon\Carbon} $tokens
     */
    private function reconnectGoogleAdsToken(
        int $workspaceId,
        array $tokens,
        int $accountId,
        string $returnTo,
        ?string $workspaceSlug,
    ): RedirectResponse {
        $account = AdAccount::withoutGlobalScopes()
            ->where('id', $accountId)
            ->where('workspace_id', $workspaceId)
            ->where('platform', 'google')
            ->first();

        if ($account === null) {
            return redirect($this->oauthDest($returnTo, $workspaceSlug))
                ->with('error', 'Account not found or access denied.');
        }

        $account->update([
            'access_token_encrypted'    => Crypt::encryptString($tokens['access_token']),
            'refresh_token_encrypted'   => Crypt::encryptString($tokens['refresh_token']),
            'token_expires_at'          => $tokens['expires_at'],
            'status'                    => 'active',
            'consecutive_sync_failures' => 0,
        ]);

        if ($account->historical_import_status === 'failed') {
            $account->update(['historical_import_status' => 'pending']);

            $syncLog = SyncLog::create([
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

        \App\Jobs\SyncAdInsightsJob::dispatch($account->id, $workspaceId, 'google');

        Log::info('Google Ads: account token reconnected', [
            'workspace_id' => $workspaceId,
            'account_id'   => $account->id,
        ]);

        return redirect($this->oauthDest($returnTo, $workspaceSlug))
            ->with('success', 'Google Ads account reconnected successfully.');
    }

    // -------------------------------------------------------------------------
    // Per-type callback handlers
    // -------------------------------------------------------------------------

    /**
     * Handle the Google Ads OAuth callback.
     *
     * Lists accessible customer accounts, upserts ad_accounts rows, dispatches sync.
     *
     * @param  array{access_token: string, refresh_token: string, expires_at: \Carbon\Carbon} $tokens
     */
    private function handleGoogleAdsCallback(
        Request $request,
        int $workspaceId,
        array $tokens,
        string $returnTo = 'integrations',
        ?string $workspaceSlug = null,
        ?int $reconnectId = null,
    ): RedirectResponse {
        if ($reconnectId !== null) {
            return $this->reconnectGoogleAdsToken($workspaceId, $tokens, $reconnectId, $returnTo, $workspaceSlug);
        }

        $client = GoogleAdsClient::withToken($tokens['access_token']);

        try {
            $customerIds = $client->listAccessibleCustomers();
        } catch (GoogleAccountDisabledException $e) {
            Log::warning('Google Ads OAuth: account not enabled or developer token in test mode', [
                'workspace_id' => $workspaceId,
                'error'        => $e->getMessage(),
            ]);
            return redirect($this->oauthDest($returnTo, $workspaceSlug))
                ->with('error', 'Google Ads account cannot be accessed. The account may be inactive, or the app is still in test mode and cannot connect to live accounts.');
        } catch (GoogleApiException | GoogleTokenExpiredException $e) {
            Log::error('Google Ads OAuth: failed to list accessible customers', [
                'workspace_id' => $workspaceId,
                'error'        => $e->getMessage(),
            ]);
            return redirect($this->oauthDest($returnTo, $workspaceSlug))
                ->with('error', 'Could not retrieve Google Ads accounts: ' . $e->getMessage());
        }

        if (empty($customerIds)) {
            $msg = urlencode('No Google Ads accounts were found for this user.');
            return redirect()->away(rtrim(config('app.url'), '/') . $this->oauthDest($returnTo, $workspaceSlug) . '?oauth_error=' . $msg . '&oauth_platform=google_ads');
        }

        // Fetch account name, currency, and manager flag for each customer.
        // Manager Accounts (MCCs) are excluded — they cannot sync ad insights directly.
        // Their client accounts appear as separate entries in listAccessibleCustomers().
        $accounts = [];

        foreach ($customerIds as $customerId) {
            try {
                $info = $client->getCustomerInfo($customerId);
            } catch (GoogleAccountDisabledException $e) {
                Log::warning('Google Ads OAuth: skipping disabled/inaccessible customer', [
                    'customer_id' => $customerId,
                    'error'       => $e->getMessage(),
                ]);
                continue;
            } catch (GoogleApiException $e) {
                $info = ['name' => $customerId, 'currency' => 'USD', 'is_manager' => false];
                Log::warning('Google Ads OAuth: could not fetch customer info', [
                    'customer_id' => $customerId,
                    'error'       => $e->getMessage(),
                ]);
            }

            if ($info['is_manager']) {
                Log::info('Google Ads OAuth: skipping Manager Account (MCC)', [
                    'customer_id' => $customerId,
                    'name'        => $info['name'],
                ]);
                continue;
            }

            $accounts[] = [
                'id'       => $customerId,
                'name'     => $info['name'],
                'currency' => $info['currency'],
            ];
        }

        if (empty($accounts)) {
            $msg = urlencode('No connectable Google Ads accounts were found. Manager accounts (MCCs) and disabled accounts are not supported.');
            return redirect()->away(rtrim(config('app.url'), '/') . $this->oauthDest($returnTo, $workspaceSlug) . '?oauth_error=' . $msg . '&oauth_platform=google_ads');
        }

        $pendingKey = 'gads_pending_' . Str::uuid();

        // Encrypt tokens at rest in cache so Redis access alone does not expose
        // live Google Ads credentials. Decrypted in connectGoogleAdsAccounts().
        cache()->put($pendingKey, [
            'workspace_id'  => $workspaceId,
            'access_token'  => Crypt::encryptString($tokens['access_token']),
            'refresh_token' => Crypt::encryptString($tokens['refresh_token']),
            'expires_at'    => $tokens['expires_at']->timestamp,
            'accounts'      => $accounts,
            'return_to'     => $returnTo,
        ], now()->addMinutes(15));

        $redirectUrl = rtrim(config('app.url'), '/') . $this->oauthDest($returnTo, $workspaceSlug) . '?gads_pending=' . urlencode($pendingKey);

        return redirect()->away($redirectUrl);
    }

    /**
     * Handle the GSC OAuth callback.
     *
     * Lists available properties, stores tokens in session, redirects to
     * integrations page where the user selects which property to connect.
     *
     * @param  array{access_token: string, refresh_token: string, expires_at: \Carbon\Carbon} $tokens
     */
    private function handleGscCallback(
        int $workspaceId,
        array $tokens,
        string $returnTo = 'integrations',
        ?string $workspaceSlug = null,
        ?int $reconnectId = null,
    ): RedirectResponse {
        if ($reconnectId !== null) {
            return $this->reconnectGscToken($workspaceId, $tokens, $reconnectId, $returnTo, $workspaceSlug);
        }

        $client = SearchConsoleClient::withToken($tokens['access_token']);

        try {
            $properties = $client->listProperties();
        } catch (GoogleApiException | GoogleTokenExpiredException $e) {
            Log::error('GSC OAuth: failed to list properties', [
                'workspace_id' => $workspaceId,
                'error'        => $e->getMessage(),
            ]);
            return redirect($this->oauthDest($returnTo, $workspaceSlug))
                ->with('error', 'Could not retrieve Search Console properties: ' . $e->getMessage());
        }

        if (empty($properties)) {
            return redirect()->away(rtrim(config('app.url'), '/') . $this->oauthDest($returnTo, $workspaceSlug) . '?oauth_error=' . urlencode('No Search Console properties were found for this account.') . '&oauth_platform=gsc');
        }

        // Store tokens in cache — session can't be used here since the callback
        // arrives on a different domain (ngrok) than where the user's session lives.
        // Encrypt tokens at rest so Redis access alone does not expose live GSC credentials.
        // Decrypted in connectGscProperty().
        $pendingKey = 'gsc_pending_' . Str::uuid();

        cache()->put($pendingKey, [
            'workspace_id'  => $workspaceId,
            'access_token'  => Crypt::encryptString($tokens['access_token']),
            'refresh_token' => Crypt::encryptString($tokens['refresh_token']),
            'expires_at'    => $tokens['expires_at'],
            'properties'    => array_map(static fn (array $p): string => $p['siteUrl'], $properties),
            'return_to'     => $returnTo,
        ], now()->addMinutes(15));

        $redirectUrl = rtrim(config('app.url'), '/') . $this->oauthDest($returnTo, $workspaceSlug) . '?gsc_pending=' . urlencode($pendingKey);

        return redirect()->away($redirectUrl);
    }

    // -------------------------------------------------------------------------
    // Token exchange
    // -------------------------------------------------------------------------

    /**
     * Exchange an authorization code for access + refresh tokens.
     *
     * @return array{access_token: string, refresh_token: string, expires_at: \Carbon\Carbon}
     *
     * @throws GoogleApiException
     */
    private function exchangeCode(string $code): array
    {
        $response = Http::timeout(15)->post(self::TOKEN_URL, [
            'code'          => $code,
            'client_id'     => config('services.google.client_id'),
            'client_secret' => config('services.google.client_secret'),
            'redirect_uri'  => config('services.google.redirect'),
            'grant_type'    => 'authorization_code',
        ]);

        if ($response->failed()) {
            throw new GoogleApiException(
                'Token exchange failed: HTTP ' . $response->status()
            );
        }

        $body = $response->json();

        if (isset($body['error'])) {
            throw new GoogleApiException(
                'Token exchange error: ' . ($body['error_description'] ?? $body['error'])
            );
        }

        $accessToken  = (string) ($body['access_token'] ?? '');
        $refreshToken = (string) ($body['refresh_token'] ?? '');
        $expiresIn    = (int) ($body['expires_in'] ?? 3600);

        if ($accessToken === '' || $refreshToken === '') {
            throw new GoogleApiException('Token exchange returned empty access or refresh token.');
        }

        return [
            'access_token'  => $accessToken,
            'refresh_token' => $refreshToken,
            'expires_at'    => now()->addSeconds($expiresIn),
        ];
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function buildAuthUrl(string $scope, string $state): string
    {
        $params = http_build_query([
            'client_id'     => config('services.google.client_id'),
            'redirect_uri'  => config('services.google.redirect'),
            'response_type' => 'code',
            'scope'         => $scope,
            'access_type'   => 'offline',
            'prompt'        => 'consent',  // force refresh_token issuance
            'state'         => $state,
        ]);

        return self::AUTH_URL . '?' . $params;
    }

    /**
     * Find a store whose domain matches the property URL (e.g. sc-domain:example.com
     * or https://www.example.com/).
     */
    private function findMatchingStore(int $workspaceId, string $propertyUrl): ?int
    {
        // Normalise: strip scheme, trailing slash, 'sc-domain:' prefix
        $domain = preg_replace('#^(https?://|sc-domain:)#i', '', $propertyUrl);
        $domain = rtrim((string) $domain, '/');

        if ($domain === '') {
            return null;
        }

        $store = Store::withoutGlobalScopes()
            ->where('workspace_id', $workspaceId)
            ->where('domain', 'LIKE', '%' . addcslashes($domain, '%_') . '%')
            ->select(['id'])
            ->first();

        return $store?->id;
    }

    /**
     * base64url-encode a JSON payload and append an HMAC-SHA256 signature.
     *
     * Format: {base64url_payload}.{hex_signature}
     *
     * This makes the state tamper-evident without requiring a server-side session,
     * allowing the OAuth callback to be processed from any domain (e.g. ngrok).
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

    /**
     * Abort 403 unless the authenticated user is an owner or admin of the workspace.
     */
    private function authorizeWorkspaceAccess(Request $request, int $workspaceId): void
    {
        $allowed = WorkspaceUser::where('user_id', $request->user()?->id)
            ->where('workspace_id', $workspaceId)
            ->whereIn('role', ['owner', 'admin'])
            ->exists();

        abort_unless($allowed, 403, 'You do not have permission to connect integrations for this workspace.');
    }

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
}
