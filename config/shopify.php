<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Shopify App credentials
    |--------------------------------------------------------------------------
    |
    | Client ID and client secret from the Shopify Partner Dashboard. Both are
    | required for the OAuth 2.0 flow and for HMAC verification on webhooks.
    |
    | Note: Shopify HMAC on webhooks uses the *client_secret* (shared app-level
    | secret), NOT a per-store secret like WooCommerce. All Shopify stores
    | sending webhooks to this app are verified with the same key.
    |
    | See: VerifyShopifyWebhookSignature middleware (Step 5)
    */
    'client_id'     => env('SHOPIFY_CLIENT_ID'),
    'client_secret' => env('SHOPIFY_CLIENT_SECRET'),

    /*
    |--------------------------------------------------------------------------
    | OAuth scopes
    |--------------------------------------------------------------------------
    |
    | Minimum required scopes for Nexstage:
    |   - read_orders        — order sync + webhook delivery
    |   - read_customers     — customer ID on orders (repeat purchase tracking)
    |   - read_products      — product sync
    |   - read_inventory     — InventoryItem.unitCost for COGS snapshot
    |   - write_webhooks     — register / delete webhook subscriptions
    */
    'scopes' => 'read_orders,read_customers,read_products,read_inventory,write_webhooks',

    /*
    |--------------------------------------------------------------------------
    | Admin API version
    |--------------------------------------------------------------------------
    |
    | Pin to a specific quarterly release. Shopify deprecates versions every
    | 12 months. Check https://shopify.dev/api/admin-rest/latest/changelog before
    | bumping — some fields are added or removed between versions.
    |
    | Note per CLAUDE.md: API versions change every 1–3 months. Verify the
    | current supported version before touching sync jobs.
    */
    'api_version' => '2026-04',

    /*
    |--------------------------------------------------------------------------
    | OAuth callback URL
    |--------------------------------------------------------------------------
    |
    | Must be registered in the Shopify Partner Dashboard under "App setup →
    | Allowed redirection URL(s)". Must be HTTPS in production.
    */
    'redirect_uri' => env('SHOPIFY_REDIRECT_URI', env('APP_URL') . '/shopify/callback'),
];
