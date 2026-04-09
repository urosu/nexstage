<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when the Facebook Graph API returns a non-recoverable error.
 */
class FacebookApiException extends RuntimeException {}
