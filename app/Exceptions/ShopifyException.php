<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown by ShopifyGraphQlClient and ShopifyConnector when the Shopify API returns
 * an error, the access token is invalid, or a required operation fails.
 *
 * Callers: ConnectShopifyStoreAction, ShopifyConnector sync methods.
 */
class ShopifyException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?int $httpStatus = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
