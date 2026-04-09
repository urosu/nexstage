<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when the Google API returns a non-recoverable error.
 */
class GoogleApiException extends RuntimeException {}
