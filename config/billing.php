<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Standard Plan (single plan, metered)
    |--------------------------------------------------------------------------
    |
    | One plan for everyone: €39/mo minimum + 0.4% of monthly GMV.
    | No tiers, no feature gates below Enterprise.
    |
    | Stripe setup:
    |   - One metered price at €0.004 per EUR of GMV (price_id below).
    |   - Usage reported monthly as max(gmv_eur, floor_quantity) where
    |     floor_quantity = minimum_monthly / gmv_rate = 39 / 0.004 = 9 750 EUR.
    |   - This ensures Stripe always charges at least €39 regardless of GMV.
    |
    | Billing basis (see ReportMonthlyRevenueToStripeJob):
    |   - has_store = true  → GMV from daily_snapshots.revenue
    |   - has_store = false → ad spend from ad_insights at campaign level
    |   - Both store AND ads → GMV only
    |
    | DB plan key: 'standard' (workspaces.billing_plan CHECK constraint).
    | Enterprise workspaces use billing_plan = 'enterprise' and are invoiced
    | manually — they do not go through this Stripe metered flow.
    |
    */

    'plan' => [
        'price_id'             => env('STRIPE_PRICE_ID'),
        'gmv_rate'             => 0.004,   // 0.4% of GMV per month
        'minimum_monthly'      => 39,      // €39/mo floor (enforced before reporting)
        'enterprise_threshold' => 250000,  // monthly GMV (EUR) where we reach out for Enterprise
    ],

];
