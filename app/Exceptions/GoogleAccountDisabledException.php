<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when a Google Ads customer is not enabled (CUSTOMER_NOT_ENABLED).
 *
 * This is a permanent error — the account is inactive or the developer token
 * is in test mode and cannot access non-test accounts.
 *
 * The job should mark the integration as disabled and call $this->fail($e).
 */
class GoogleAccountDisabledException extends RuntimeException
{
    public function __construct(string $message = 'Google Ads account is not enabled.')
    {
        parent::__construct($message);
    }
}
