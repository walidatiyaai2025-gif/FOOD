<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Shared Flutter preview runtimes
    |--------------------------------------------------------------------------
    |
    | These URLs must point to the real Customer/Driver shared Flutter runtime.
    | Leaving a URL empty intentionally renders an unavailable state. Never
    | substitute a Blade/HTML clone or fake runtime data.
    |
    */
    'runtimes' => [
        'customer' => [
            'url' => env('FOODEX_CUSTOMER_PREVIEW_RUNTIME_URL'),
            'contract_version' => env('FOODEX_CUSTOMER_PREVIEW_CONTRACT_VERSION'),
        ],
        'driver' => [
            'url' => env('FOODEX_DRIVER_PREVIEW_RUNTIME_URL'),
            'contract_version' => env('FOODEX_DRIVER_PREVIEW_CONTRACT_VERSION'),
        ],
    ],

    'device_profiles' => [
        'phone_compact' => ['label' => 'Compact phone', 'width' => 360],
        'phone_standard' => ['label' => 'Standard phone', 'width' => 390],
        'phone_large' => ['label' => 'Large phone', 'width' => 430],
        'tablet' => ['label' => 'Tablet', 'width' => 768],
    ],
];
