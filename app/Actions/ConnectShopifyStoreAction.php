<?php

declare(strict_types=1);

namespace App\Actions;

use App\Exceptions\ShopifyException;
use App\Jobs\ComputeUtmCoverageJob;
use App\Jobs\RunLighthouseCheckJob;
use App\Models\Store;
use App\Models\StoreUrl;
use App\Models\Workspace;
use App\Services\Integrations\Shopify\ShopifyConnector;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Connects (or reconnects) a Shopify store to a workspace via an OAuth access token.
 *
 * Flow (mirrors ConnectStoreAction for WooCommerce):
 *   1. Validate the access token by calling ShopifyConnector::testConnection().
 *   2. Upsert store row with status='connecting'.
 *   3. If reconnecting: remove old webhooks from Shopify + soft-delete store_webhooks rows.
 *   4. Register fresh webhook subscriptions; write store_webhooks rows.
 *   5. Flip status to 'active'.
 *   6. Post-connection tasks: has_store flag, StoreUrl, Lighthouse, UtmCoverage.
 *
 * Access token notes:
 *   - Shopify offline access tokens never expire — token_expires_at is stored as NULL.
 *   - Webhook HMAC uses the app-level client_secret, not a per-store secret.
 *   - We still generate and store a webhook_secret_encrypted to satisfy the stores
 *     table NOT NULL constraint, but ShopifyConnector ignores it.
 *
 * Historical import is NOT dispatched here — ShopifyOAuthController dispatches
 * it after this action returns and the user has confirmed the date range.
 *
 * Called by: ShopifyOAuthController::callback()
 *
 * Related: app/Actions/ConnectStoreAction.php (WooCommerce equivalent)
 * See: PLANNING.md "Phase 2 — Shopify"
 */
class ConnectShopifyStoreAction
{
    /**
     * @param  string $shopDomain  The myshopify.com domain, e.g. "my-store.myshopify.com"
     * @param  string $accessToken The offline access token from the OAuth exchange.
     *
     * @throws ShopifyException  If the access token is invalid or store info cannot be fetched.
     */
    public function handle(Workspace $workspace, string $shopDomain, string $accessToken): Store
    {
        // Normalise: strip scheme and trailing slash.
        $shopDomain = rtrim(preg_replace('#^https?://#i', '', $shopDomain), '/');

        // Step 1: Validate by fetching store metadata — throws before any DB write if invalid.
        $tempStore = new Store([
            'domain'                   => $shopDomain,
            'access_token_encrypted'   => Crypt::encryptString($accessToken),
            // Placeholder values — only needed to satisfy the connector constructor.
            'webhook_secret_encrypted' => Crypt::encryptString(Str::random(32)),
            'workspace_id'             => $workspace->id,
        ]);

        $connector = new ShopifyConnector($tempStore);
        $metadata  = $connector->getStoreInfo();

        // Step 2: Find an existing store for this domain (reconnect path).
        $existingStore = Store::withoutGlobalScopes()
            ->where('workspace_id', $workspace->id)
            ->where('domain', $shopDomain)
            ->first();

        // Step 3: Persist store row.
        $webhookSecret = Str::random(32); // stored to satisfy schema; not used for Shopify HMAC

        $store = DB::transaction(function () use ($workspace, $shopDomain, $metadata, $accessToken, $webhookSecret, $existingStore): Store {
            $attributes = [
                'workspace_id'              => $workspace->id,
                'name'                      => $metadata['name'],
                'type'                      => 'shopify',
                'platform'                  => 'shopify',
                'domain'                    => $shopDomain,
                'currency'                  => $metadata['currency'],
                'timezone'                  => $metadata['timezone'],
                'status'                    => 'connecting',
                'access_token_encrypted'    => Crypt::encryptString($accessToken),
                'token_expires_at'          => null, // Shopify offline tokens never expire
                'webhook_secret_encrypted'  => Crypt::encryptString($webhookSecret),
                'consecutive_sync_failures' => 0,
            ];

            if ($existingStore !== null) {
                // Reconnect: preserve slug and historical import state.
                $existingStore->update($attributes);
                return $existingStore->fresh();
            }

            // New store: generate slug from the shop domain.
            $attributes['slug'] = $this->generateUniqueSlug($workspace->id, $shopDomain);

            return Store::create($attributes);
        });

        // Step 4: Remove old webhooks if reconnecting (HTTP calls outside transaction).
        if ($existingStore !== null) {
            $realConnector = new ShopifyConnector($store);
            $realConnector->removeWebhooks();
        }

        // Step 5: Register fresh webhooks. On failure mark store as error and rethrow.
        try {
            $realConnector = new ShopifyConnector($store);
            $realConnector->registerWebhooks();
        } catch (ShopifyException $e) {
            $store->update(['status' => 'error']);
            throw $e;
        }

        // Step 6: Flip to active.
        $store->update(['status' => 'active']);

        // Step 7: Update workspace integration flag.
        $workspace->update(['has_store' => true]);

        // Step 8: Auto-create homepage store_url for Lighthouse / PSI monitoring.
        $homepageUrl = 'https://' . rtrim($shopDomain, '/');
        $storeUrl    = StoreUrl::withoutGlobalScopes()->firstOrCreate(
            ['store_id' => $store->id, 'url' => $homepageUrl],
            [
                'workspace_id' => $workspace->id,
                'label'        => 'Homepage',
                'is_homepage'  => true,
                'is_active'    => true,
            ]
        );

        RunLighthouseCheckJob::dispatch($storeUrl->id, $store->id, $workspace->id, 'mobile');
        RunLighthouseCheckJob::dispatch($storeUrl->id, $store->id, $workspace->id, 'desktop')
            ->delay(now()->addSeconds(35));

        ComputeUtmCoverageJob::dispatch($workspace->id)->onQueue('low');

        return $store->fresh();
    }

    private function generateUniqueSlug(int $workspaceId, string $domain): string
    {
        // Use the subdomain part (my-store from my-store.myshopify.com) as the base.
        $base = Str::slug(str_replace('.', '-', explode('.', $domain)[0])) ?: 'store';

        if (! Store::withoutGlobalScopes()->where('workspace_id', $workspaceId)->where('slug', $base)->exists()) {
            return $base;
        }

        do {
            $slug = $base . '-' . Str::lower(Str::random(4));
        } while (Store::withoutGlobalScopes()->where('workspace_id', $workspaceId)->where('slug', $slug)->exists());

        return $slug;
    }
}
