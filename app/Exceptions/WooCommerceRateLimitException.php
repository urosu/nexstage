<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when the WooCommerce API returns HTTP 429 Too Many Requests.
 *
 * The job catches this and calls $this->release($e->retryAfter) so the attempt
 * count is NOT incremented — the job is simply re-queued after the backoff.
 */
class WooCommerceRateLimitException extends RuntimeException
{
    public function __construct(
        public readonly int $retryAfter = 60,
    ) {
        parent::__construct("WooCommerce API rate limit hit. Retry after {$retryAfter} seconds.");
    }
}
