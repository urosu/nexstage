<?php

declare(strict_types=1);

namespace App\Models;

use Laravel\Cashier\SubscriptionItem as CashierSubscriptionItem;

class SubscriptionItem extends CashierSubscriptionItem
{
    // Extends Cashier's SubscriptionItem — no custom logic needed in MVP.
}
