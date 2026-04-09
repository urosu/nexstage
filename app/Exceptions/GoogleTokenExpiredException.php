<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when the Google OAuth access token is expired or revoked and cannot be
 * refreshed (missing/invalid refresh token, or token revocation).
 *
 * The job should mark the integration as token_expired and call $this->fail($e).
 */
class GoogleTokenExpiredException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Google OAuth token has expired or been revoked and cannot be refreshed.');
    }
}
