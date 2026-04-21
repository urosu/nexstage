<?php

declare(strict_types=1);

namespace App\Contracts;

use Carbon\Carbon;

/**
 * Contract for all store platform connectors (WooCommerce, Shopify, etc.).
 *
 * Implemented by:
 *   - App\Services\Integrations\WooCommerce\WooCommerceConnector (Phase 0)
 *   - App\Services\Integrations\Shopify\ShopifyConnector (Phase 2)
 *
 * Each implementation encapsulates the full fetch + persist cycle for one platform.
 * Sync jobs are thin wrappers that handle lifecycle (logging, failure chain, scheduling).
 *
 * Related: app/Services/Integrations/WooCommerce/WooCommerceConnector.php
 * See: PLANNING.md "StoreConnector Interface"
 */
interface StoreConnector
{
    /**
     * Verify the platform credentials are valid and the store is reachable.
     */
    public function testConnection(): bool;

    /**
     * Fetch orders modified since (and optionally up to) the given timestamps and upsert them.
     * $until is used by historical import jobs to limit each 30-day chunk.
     *
     * @return int Number of orders processed.
     */
    public function syncOrders(Carbon $since, ?Carbon $until = null): int;

    /**
     * Fetch products (modified since store.last_synced_at, or all on first run) and upsert them.
     *
     * @return int Number of products upserted.
     */
    public function syncProducts(): int;

    /**
     * Fetch refunds created/modified since the given timestamp, upsert into refunds table,
     * and update orders.refund_amount + orders.last_refunded_at.
     *
     * @return int Number of refund records upserted.
     */
    public function syncRefunds(Carbon $since): int;

    /**
     * Register platform webhooks for this store and write entries to store_webhooks.
     *
     * @return array<string, int>  Map of topic → platform webhook ID.
     */
    public function registerWebhooks(): array;

    /**
     * Remove all active platform webhooks for this store and soft-delete store_webhooks rows.
     */
    public function removeWebhooks(): void;

    /**
     * Return store metadata from the platform (name, currency, timezone).
     *
     * @return array{name: string, currency: string, timezone: string}
     */
    public function getStoreInfo(): array;

    // -------------------------------------------------------------------------
    // Capability flags
    // -------------------------------------------------------------------------

    /**
     * Whether this platform snapshots COGS into order item meta at order time,
     * enabling accurate historical cost data.
     *
     * WooCommerce: true when any of the three supported COGS plugins is detected.
     * Shopify: false — only current InventoryItem.unitCost is exposed (Phase 2 snapshot fallback).
     *
     * @see PLANNING.md section 7
     */
    public function supportsHistoricalCogs(): bool;

    /**
     * Attribution features available from this platform.
     *
     * Returns a list of string identifiers, e.g.:
     *   'last_touch', 'first_touch', 'multi_touch_journey', 'referrer_url', 'landing_page'
     *
     * Used by the UI to show/hide attribution feature availability and by
     * AttributionParserService to inform source priority logic.
     *
     * @return list<string>
     * @see PLANNING.md section 6
     */
    public function supportedAttributionFeatures(): array;

    /**
     * Whether this platform provides multi-touch journey data.
     *
     * WooCommerce: false (single-touch only; PYS provides first+last but not full journey).
     * Shopify: false (Phase 2 baseline; Shopify Customer Journey API is multi-touch).
     *
     * @see PLANNING.md section 6
     */
    public function supportsMultiTouch(): bool;
}
