<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Flat Plans
    |--------------------------------------------------------------------------
    |
    | Each plan maps to monthly and annual Stripe Price IDs and declares the
    | maximum monthly revenue (in EUR) before a grace period is triggered.
    |
    */

    'flat_plans' => [
        'starter' => [
            'price_id_monthly' => env('STRIPE_PRICE_STARTER_M'),
            'price_id_annual'  => env('STRIPE_PRICE_STARTER_A'),
            'revenue_limit'    => 2000,
        ],
        'growth' => [
            'price_id_monthly' => env('STRIPE_PRICE_GROWTH_M'),
            'price_id_annual'  => env('STRIPE_PRICE_GROWTH_A'),
            'revenue_limit'    => 5000,
        ],
        'scale' => [
            'price_id_monthly' => env('STRIPE_PRICE_SCALE_M'),
            'price_id_annual'  => env('STRIPE_PRICE_SCALE_A'),
            'revenue_limit'    => 10000,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Percentage Plan
    |--------------------------------------------------------------------------
    |
    | Stripe metered billing. Revenue is always reported in EUR.
    | Floor of €149/month enforced in code before reporting usage.
    |
    */

    'percentage_plan' => [
        'price_id'            => env('STRIPE_PRICE_PERCENTAGE'),
        'rate'                => 0.01,
        'minimum_monthly'     => 149,
        'revenue_threshold'   => 10000,
        'enterprise_threshold' => 250000,
    ],

];
