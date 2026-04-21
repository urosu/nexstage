<?php

declare(strict_types=1);

namespace App\Actions;

use App\Exceptions\WooCommerceConnectionException;
use App\Jobs\ComputeUtmCoverageJob;
use App\Jobs\RunLighthouseCheckJob;
use App\Models\Store;
use App\Models\StoreUrl;
use App\Models\Workspace;
use App\Services\Integrations\WooCommerce\WooCommerceConnector;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Connects (or reconnects) a WooCommerce store to a workspace.
 *
 * Flow:
 *   1. Validate credentials via system_status — throws on failure (no DB write).
 *   2. Upsert store row with status='connecting'.
 *   3. If reconnecting: remove old webhooks from WooCommerce + soft-delete store_webhooks rows.
 *   4. Register fresh webhooks; write new store_webhooks rows.
 *   5. Flip status to 'active'.
 *
 * If webhook registration fails after the row is persisted, the store is left in
 * status='error' so the operator can retry. Existing data is never deleted on a
 * reconnect failure.
 *
 * Called from the onboarding controller and the settings/integrations controller.
 * Historical import is NOT dispatched here — the caller must prompt the user for
 * a date range and dispatch WooCommerceHistoricalImportJob separately.
 *
 * Related: app/Services/Integrations/WooCommerce/WooCommerceConnector.php
 */
class ConnectStoreAction
{
    /**
     * @param array{domain: string, consumer_key: string, consumer_secret: string} $validated
     *
     * @throws WooCommerceConnectionException  If credentials are invalid or API is unreachable.
     */
    public function handle(Workspace $workspace, array $validated): Store
    {
        // Normalize domain: strip any scheme (https://, http://) and trailing slashes.
        // Why: users often paste the full site URL into the domain field; storing a bare
        // domain prevents double-scheme issues when building URLs (e.g. store_urls).
        $validated['domain'] = rtrim(preg_replace('#^https?://#i', '', $validated['domain']), '/');

        // Build a temporary connector to validate credentials before any DB write.
        // We need a fake Store-like object just for the validation call — use the
        // client directly via a temporary Store stub.
        $webhookSecret = Str::random(32);

        // Step 1: Validate — throws WooCommerceAuthException / WooCommerceConnectionException
        // before any DB write if credentials are bad or the site is unreachable.
        $tempStore = new Store([
            'domain'                   => $validated['domain'],
            'auth_key_encrypted'       => Crypt::encryptString($validated['consumer_key']),
            'auth_secret_encrypted'    => Crypt::encryptString($validated['consumer_secret']),
            'webhook_secret_encrypted' => Crypt::encryptString($webhookSecret),
            'workspace_id'             => $workspace->id,
        ]);

        $connector = new WooCommerceConnector($tempStore);
        $metadata  = $connector->getStoreInfo();

        // Step 2: Find an existing store for this domain so we know if this is a reconnect.
        $existingStore = $workspace->stores()
            ->withoutGlobalScopes()
            ->where('workspace_id', $workspace->id)
            ->where('domain', $validated['domain'])
            ->first();

        // Step 3: Persist the store row. Use a transaction so the store is never left
        // half-written if the DB update fails mid-flight.
        $store = DB::transaction(function () use ($workspace, $validated, $metadata, $webhookSecret, $existingStore): Store {
            $attributes = [
                'workspace_id'              => $workspace->id,
                'name'                      => $metadata['name'],
                'type'                      => 'woocommerce',
                'domain'                    => $validated['domain'],
                'currency'                  => $metadata['currency'],
                'timezone'                  => $metadata['timezone'],
                'status'                    => 'connecting',
                'auth_key_encrypted'        => Crypt::encryptString($validated['consumer_key']),
                'auth_secret_encrypted'     => Crypt::encryptString($validated['consumer_secret']),
                'webhook_secret_encrypted'  => Crypt::encryptString($webhookSecret),
                'consecutive_sync_failures' => 0,
            ];

            if ($existingStore !== null) {
                // On reconnect: preserve the existing slug — it may have been customised by the user.
                $existingStore->update($attributes);
                return $existingStore->fresh();
            }

            // Use the normalised domain (scheme already stripped above) for the slug,
            // not the site title — titles can contain URLs or special chars; the domain
            // produces a clean, human-readable identifier (e.g. unikatna-keramika-si).
            $attributes['slug'] = $this->generateUniqueSlug($workspace->id, $validated['domain']);

            return Store::create($attributes);
        });

        // Step 4: Remove old webhooks if reconnecting. Done outside the DB transaction
        // because these are HTTP calls. 404s are silently ignored by the client.
        if ($existingStore !== null) {
            $realConnector = new WooCommerceConnector($store);
            $realConnector->removeWebhooks();
        }

        // Step 5: Register fresh webhooks. On failure, mark the store as error and rethrow
        // so the controller can surface the message. We never delete an existing store row
        // on webhook failure — the operator can retry from settings.
        try {
            $realConnector = new WooCommerceConnector($store);
            $realConnector->registerWebhooks();
        } catch (WooCommerceConnectionException $e) {
            $store->update(['status' => 'error']);
            throw $e;
        }

        // Step 6: Flip to active.
        $store->update(['status' => 'active']);

        // Step 7: Update workspace integration flags.
        // Why: has_store drives billing basis (GMV vs ad spend) and nav visibility.
        // See: PLANNING.md "Billing basis auto-derivation"
        $workspace->update(['has_store' => true]);

        // Step 8: Country auto-detection from ccTLD (runs once — skipped if already set).
        // Why: PLANNING.md requires ccTLD detection on store connect (highest-confidence signal).
        // See: PLANNING.md "Country auto-detection"
        if ($workspace->country === null) {
            $ccTld = $this->detectCcTld($store->domain);
            if ($ccTld !== null) {
                // Setting country triggers WorkspaceObserver → RefreshHolidaysJob (intentional).
                $workspace->update(['country' => $ccTld]);
            }
        }

        // Step 9: Auto-create homepage store_url for PSI monitoring.
        // Why: PLANNING.md "On store connection: auto-create store_urls row for homepage."
        // The homepage URL is the store's website_url (explicit) or derived from domain.
        // Uses firstOrCreate so reconnects don't duplicate the row.
        $homepageUrl = $store->website_url ?? 'https://' . rtrim($store->domain, '/');
        $storeUrl    = StoreUrl::withoutGlobalScopes()->firstOrCreate(
            ['store_id' => $store->id, 'url' => $homepageUrl],
            [
                'workspace_id' => $workspace->id,
                'label'        => 'Homepage',
                'is_homepage'  => true,
                'is_active'    => true,
            ]
        );

        // Kick off the first PSI check immediately (no delay) so the user sees
        // data without waiting for the next daily schedule window.
        // Both strategies dispatched: mobile first, desktop with a small offset so
        // they don't hit the PSI API simultaneously (responses take 15–30 s each).
        RunLighthouseCheckJob::dispatch($storeUrl->id, $store->id, $workspace->id, 'mobile');
        RunLighthouseCheckJob::dispatch($storeUrl->id, $store->id, $workspace->id, 'desktop')
            ->delay(now()->addSeconds(35));

        // Recompute UTM coverage now that a store is connected. The job itself
        // checks has_store + has_ads before running — safe to dispatch unconditionally.
        ComputeUtmCoverageJob::dispatch($workspace->id)->onQueue('low');

        return $store->fresh();
    }

    /**
     * Extract a country code from a domain's TLD, or return null if not a ccTLD.
     *
     * Why: Only fire on actual country-code TLDs (.de → DE, .fr → FR). Skip generic
     * 2-letter TLDs (.io, .co, .ai, etc.) that are commonly used without country intent.
     * See: PLANNING.md "Country auto-detection"
     */
    private function detectCcTld(string $domain): ?string
    {
        $tld = strtolower(ltrim(strrchr($domain, '.') ?: '', '.'));

        if (strlen($tld) !== 2) {
            return null;
        }

        // Generic 2-letter TLDs frequently used for non-country branding
        $genericTlds = ['io', 'co', 'ai', 'tv', 'me', 'cc', 'fm', 'gg', 'ws', 'bz', 'la'];

        if (in_array($tld, $genericTlds, true)) {
            return null;
        }

        return strtoupper($tld);
    }

    private function generateUniqueSlug(int $workspaceId, string $name): string
    {
        $base = Str::slug(str_replace('.', '-', $name)) ?: 'store';

        if (! Store::withoutGlobalScopes()->where('workspace_id', $workspaceId)->where('slug', $base)->exists()) {
            return $base;
        }

        do {
            $slug = $base . '-' . Str::lower(Str::random(4));
        } while (Store::withoutGlobalScopes()->where('workspace_id', $workspaceId)->where('slug', $slug)->exists());

        return $slug;
    }
}
