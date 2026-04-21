<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\StoreConnector;
use App\Models\Store;
use App\Services\Integrations\Shopify\ShopifyConnector;
use App\Services\Integrations\WooCommerce\WooCommerceConnector;

/**
 * Resolves the correct StoreConnector implementation for a given store.
 *
 * Switching on `stores.platform` keeps all platform-dispatch logic in one place.
 * Code that operates on stores generically (webhook cleanup, lifecycle management,
 * capability checks) should go through this factory instead of hardcoding
 * `new WooCommerceConnector($store)`.
 *
 * Platform-specific sync jobs (SyncStoreOrdersJob, SyncShopifyOrdersJob) do NOT
 * use this factory — they are already platform-specific by construction.
 *
 * Platform identifiers match the CHECK constraint on stores.platform:
 *   woocommerce | shopify | magento | bigcommerce | prestashop | opencart
 *
 * See: PLANNING.md section 4 "Platform-Agnostic Discipline"
 * @see PLANNING.md section 4
 */
class StoreConnectorFactory
{
    /**
     * Return the platform-appropriate StoreConnector for the given store.
     *
     * @throws \InvalidArgumentException  If the store's platform is not supported.
     */
    public static function make(Store $store): StoreConnector
    {
        return match ($store->platform) {
            'woocommerce' => new WooCommerceConnector($store),
            'shopify'     => new ShopifyConnector($store),
            default       => throw new \InvalidArgumentException(
                "No StoreConnector implemented for platform '{$store->platform}' (store_id={$store->id})"
            ),
        };
    }
}
