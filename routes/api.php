<?php

declare(strict_types=1);

use App\Http\Controllers\ShopifyWebhookController;
use App\Http\Controllers\WooCommerceWebhookController;
use App\Http\Middleware\VerifyShopifyWebhookSignature;
use App\Http\Middleware\VerifyWebhookSignature;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Webhook ingestion and import-status polling only.
| All other application routes are Inertia page loads in routes/web.php.
|
| Webhook rate limit: 100 req/min keyed by store_id ('webhooks' limiter,
| defined in AppServiceProvider).
|
*/

Route::post(
    '/webhooks/woocommerce/{id}',
    WooCommerceWebhookController::class,
)->middleware([
    'throttle:webhooks',
    VerifyWebhookSignature::class,
])->name('webhooks.woocommerce');

// Shopify webhook endpoint — HMAC verified using the app-level client_secret
// (not a per-store secret). See VerifyShopifyWebhookSignature middleware (Step 5).
Route::post(
    '/webhooks/shopify/{id}',
    ShopifyWebhookController::class,
)->middleware([
    'throttle:webhooks',
    VerifyShopifyWebhookSignature::class,
])->name('webhooks.shopify');
