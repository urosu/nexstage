<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

class FxRateNotFoundException extends RuntimeException
{
    public function __construct(string $targetCurrency, string $date)
    {
        parent::__construct(
            "No FX rate found for EUR→{$targetCurrency} on or before {$date} (looked back 3 days)."
        );
    }
}
