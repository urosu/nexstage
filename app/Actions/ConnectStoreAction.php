<?php

declare(strict_types=1);

namespace App\Actions;

use App\Exceptions\WooCommerceConnectionException;
use App\Models\Store;
use App\Models\Workspace;
use App\Services\Integrations\WooCommerce\WooCommerceClient;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Connects (or reconnects) a WooCommerce store to a workspace.
 *
 * Flow:
 *   1. Validate credentials via system_status — throws on failure (no DB write).
 *   2. Upsert store row with status='connecting'.
 *   3. If reconnecting: delete old webhooks from WooCommerce (ignore 404s).
 *   4. Register fresh webhooks; store secret + IDs.
 *   5. Flip status to 'active'.
 *
 * If webhook registration fails after the row is persisted, the store is left in
 * status='error' so the operator can retry. Existing data is never deleted on a
 * reconnect failure.
 *
 * Called from the onboarding controller and the settings/integrations controller.
 * Historical import is NOT dispatched here — the caller must prompt the user for
 * a date range and dispatch WooCommerceHistoricalImportJob separately.
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
        $client = new WooCommerceClient(
            domain:         $validated['domain'],
            consumerKey:    $validated['consumer_key'],
            consumerSecret: $validated['consumer_secret'],
        );

        // Step 1: Validate. Throws WooCommerceAuthException / WooCommerceConnectionException
        // before any DB write if credentials are bad or the site is unreachable.
        $metadata = $client->validateAndGetMetadata();

        // Step 2: Find an existing store for this domain so we know if this is a reconnect.
        // Query through the workspace relationship — workspace_id is always correct this way
        // and avoids any ambiguity with the WorkspaceScope global scope.
        $existingStore = $workspace->stores()
            ->withoutGlobalScopes()
            ->where('workspace_id', $workspace->id)
            ->where('domain', $validated['domain'])
            ->first();

        $webhookSecret = Str::random(32);

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
                'platform_webhook_ids'      => null,
                'consecutive_sync_failures' => 0,
            ];

            if ($existingStore !== null) {
                // On reconnect: preserve the existing slug — it may have been customised by the user.
                $existingStore->update($attributes);
                return $existingStore->fresh();
            }

            $attributes['slug'] = $this->generateUniqueSlug($workspace->id, $metadata['name']);

            return Store::create($attributes);
        });

        // Step 4: Delete old webhooks if reconnecting.
        // Done outside the DB transaction because these are HTTP calls.
        // 404s are silently ignored by WooCommerceClient::deleteWebhook().
        if ($existingStore !== null && ! empty($existingStore->platform_webhook_ids)) {
            $client->deleteWebhooks($existingStore->platform_webhook_ids);
        }

        // Step 5: Register fresh webhooks. On failure, mark the store as error and rethrow
        // so the controller can surface the message. We never delete an existing store row
        // on webhook failure — the operator can retry from settings.
        try {
            $webhookIds = $client->registerWebhooks($store->id, $webhookSecret);
        } catch (WooCommerceConnectionException $e) {
            $store->update(['status' => 'error']);
            throw $e;
        }

        // Step 6: Flip to active with the registered webhook IDs.
        $store->update([
            'status'              => 'active',
            'platform_webhook_ids' => $webhookIds,
        ]);

        return $store->fresh();
    }

    private function generateUniqueSlug(int $workspaceId, string $name): string
    {
        $base = Str::slug($name) ?: 'store';

        if (! Store::withoutGlobalScopes()->where('workspace_id', $workspaceId)->where('slug', $base)->exists()) {
            return $base;
        }

        do {
            $slug = $base . '-' . Str::lower(Str::random(4));
        } while (Store::withoutGlobalScopes()->where('workspace_id', $workspaceId)->where('slug', $slug)->exists());

        return $slug;
    }
}
