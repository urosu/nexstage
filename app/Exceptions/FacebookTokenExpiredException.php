<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when the Facebook access token is expired or revoked (error code 190).
 *
 * The job should mark the integration as token_expired and call $this->fail($e).
 */
class FacebookTokenExpiredException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Facebook access token has expired or been revoked.');
    }
}
