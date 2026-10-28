<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Negative Stock
    |--------------------------------------------------------------------------
    |
    | Dukavo refuses to take physical stock below zero by default. A merchant
    | who deliberately backorders can enable this, but the ledger still records
    | the real negative balance so the decision is visible rather than hidden.
    |
    */

    'allow_negative_stock' => (bool) env('DUKAVO_ALLOW_NEGATIVE_STOCK', false),

    /*
    |--------------------------------------------------------------------------
    | Demand Forecast
    |--------------------------------------------------------------------------
    |
    | Forecasts are deliberately simple and explainable: a weighted average of
    | recent and full-window daily demand. Nothing here is a prediction model
    | that hides its inputs. `minimum_history_days` is the point below which a
    | forecast is withheld rather than invented.
    |
    */

    'forecast' => [
        'window_days' => (int) env('DUKAVO_FORECAST_WINDOW_DAYS', 28),
        'recent_window_days' => (int) env('DUKAVO_FORECAST_RECENT_DAYS', 7),
        'recent_weight' => (float) env('DUKAVO_FORECAST_RECENT_WEIGHT', 0.6),
        'minimum_history_days' => (int) env('DUKAVO_FORECAST_MIN_HISTORY_DAYS', 14),
        'medium_confidence_days' => (int) env('DUKAVO_FORECAST_MEDIUM_DAYS', 28),
        'high_confidence_days' => (int) env('DUKAVO_FORECAST_HIGH_DAYS', 56),
        'medium_active_days' => (int) env('DUKAVO_FORECAST_MEDIUM_ACTIVE_DAYS', 3),
        'high_active_days' => (int) env('DUKAVO_FORECAST_HIGH_ACTIVE_DAYS', 7),
    ],

    /*
    |--------------------------------------------------------------------------
    | Replenishment Rules
    |--------------------------------------------------------------------------
    |
    | A suggestion is arithmetic, not a purchase order. Lead time is the delay
    | before a received purchase is sellable, safety days cover demand noise,
    | and the review period is how long the next order should last.
    |
    */

    'reorder' => [
        'lead_time_days' => (int) env('DUKAVO_REORDER_LEAD_DAYS', 7),
        'safety_days' => (int) env('DUKAVO_REORDER_SAFETY_DAYS', 3),
        'review_period_days' => (int) env('DUKAVO_REORDER_REVIEW_DAYS', 14),
    ],

    /*
    |--------------------------------------------------------------------------
    | Slow Moving
    |--------------------------------------------------------------------------
    |
    | A row only counts as slow moving when it has existed for this many days
    | without a sale. A new product is not labelled slow just because it has not
    | sold yet.
    |
    */

    'slow_moving_days' => (int) env('DUKAVO_SLOW_MOVING_DAYS', 60),

    /*
    |--------------------------------------------------------------------------
    | Analysis Range
    |--------------------------------------------------------------------------
    |
    | The default date window and the maximum a merchant may request. The cap
    | keeps one report from scanning an unbounded ledger.
    |
    */

    'analysis' => [
        'default_days' => (int) env('DUKAVO_INVENTORY_ANALYSIS_DAYS', 28),
        'max_days' => (int) env('DUKAVO_INVENTORY_ANALYSIS_MAX_DAYS', 366),
    ],

];
