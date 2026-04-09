<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when a Google API call is rate-limited (HTTP 429 or RESOURCE_EXHAUSTED).
 *
 * The job should call $this->release($e->retryAfter) and return — the attempt
 * count is not consumed, matching the pattern in CLAUDE.md §Rate Limit Handling.
 */
class GoogleRateLimitException extends RuntimeException
{
    public function __construct(public readonly int $retryAfter = 60)
    {
        parent::__construct("Google API rate limit hit. Retry after {$retryAfter} seconds.");
    }
}
