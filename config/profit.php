<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Reporting Range
    |--------------------------------------------------------------------------
    |
    | Profit reports use calendar days in the tenant's timezone. The cap keeps
    | one request from scanning an unbounded order history.
    |
    */

    'analysis' => [
        'default_days' => (int) env('DUKAVO_PROFIT_ANALYSIS_DAYS', 30),
        'max_days' => (int) env('DUKAVO_PROFIT_ANALYSIS_MAX_DAYS', 366),
    ],

    /*
    |--------------------------------------------------------------------------
    | Recognition
    |--------------------------------------------------------------------------
    |
    | Sales are recognised when an order is placed, unless it was cancelled or
    | fully refunded. Payment status is reported separately: an unpaid order is
    | still a sale, but it is not cash. Refund amounts are not stored anywhere,
    | so a refunded order is excluded in full rather than guessed.
    |
    */

    'recognition' => [
        'excluded_order_statuses' => ['cancelled'],
        'excluded_payment_statuses' => ['refunded'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Product Breakdown
    |--------------------------------------------------------------------------
    */

    'products' => [
        'default_limit' => (int) env('DUKAVO_PROFIT_PRODUCT_LIMIT', 25),
        'max_limit' => (int) env('DUKAVO_PROFIT_PRODUCT_LIMIT_MAX', 100),
    ],

];
