<?php

declare(strict_types=1);

namespace App\Models;

use Laravel\Cashier\Subscription as CashierSubscription;

class Subscription extends CashierSubscription
{
    // Extends Cashier's Subscription — no custom logic needed in MVP.
    // Cashier manages this model; custom columns can be added here if needed.
}
