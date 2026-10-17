<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Three-way match tolerance
    |--------------------------------------------------------------------------
    |
    | Quantity is measured in the line unit and value in transaction currency.
    | Zero quantity tolerance is the safe default. The small value tolerance only
    | absorbs decimal rounding; deployments may tighten it to zero.
    |
    */
    'three_way_match' => [
        'quantity_tolerance' => (float) env('PROCUREMENT_MATCH_QUANTITY_TOLERANCE', 0),
        'value_tolerance' => (float) env('PROCUREMENT_MATCH_VALUE_TOLERANCE', 0.010),
    ],

    /*
    |--------------------------------------------------------------------------
    | Contract expiry horizon
    |--------------------------------------------------------------------------
    */
    'contract_expiry_warning_days' => (int) env('PROCUREMENT_CONTRACT_EXPIRY_WARNING_DAYS', 30),
];
