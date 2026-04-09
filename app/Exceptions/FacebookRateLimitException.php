<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when the Facebook Graph API signals a rate limit (error codes 17, 80000, 80004).
 *
 * The job should call $this->release($e->retryAfter) and return — the attempt
 * count is not consumed, matching the pattern in CLAUDE.md §Rate Limit Handling.
 */
class FacebookRateLimitException extends RuntimeException
{
    public function __construct(public readonly int $retryAfter = 60)
    {
        parent::__construct("Facebook rate limit hit. Retry after {$retryAfter} seconds.");
    }
}
