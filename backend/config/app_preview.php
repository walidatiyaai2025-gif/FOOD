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
            'allowed_origin' => env('FOODEX_CUSTOMER_PREVIEW_ALLOWED_ORIGIN'),
        ],
        'driver' => [
            'url' => env('FOODEX_DRIVER_PREVIEW_RUNTIME_URL'),
            'contract_version' => env('FOODEX_DRIVER_PREVIEW_CONTRACT_VERSION'),
            'allowed_origin' => env('FOODEX_DRIVER_PREVIEW_ALLOWED_ORIGIN'),
        ],
    ],

    'device_profiles' => [
        'android_small' => [
            'label' => 'Small Android',
            'platform' => 'android',
            'width' => 360,
            'height' => 640,
            'safe_area' => ['top' => 24, 'right' => 0, 'bottom' => 24, 'left' => 0],
            'text_scale' => 1.0,
            'orientation' => 'portrait',
            'keyboard_inset_bottom' => 0,
        ],
        'android_common' => [
            'label' => 'Android common',
            'platform' => 'android',
            'width' => 390,
            'height' => 844,
            'safe_area' => ['top' => 24, 'right' => 0, 'bottom' => 24, 'left' => 0],
            'text_scale' => 1.0,
            'orientation' => 'portrait',
            'keyboard_inset_bottom' => 0,
        ],
        'android_large' => [
            'label' => 'Large Android',
            'platform' => 'android',
            'width' => 430,
            'height' => 932,
            'safe_area' => ['top' => 24, 'right' => 0, 'bottom' => 24, 'left' => 0],
            'text_scale' => 1.0,
            'orientation' => 'portrait',
            'keyboard_inset_bottom' => 0,
        ],
        'iphone_common' => [
            'label' => 'iPhone common',
            'platform' => 'ios',
            'width' => 390,
            'height' => 844,
            'safe_area' => ['top' => 47, 'right' => 0, 'bottom' => 34, 'left' => 0],
            'text_scale' => 1.0,
            'orientation' => 'portrait',
            'keyboard_inset_bottom' => 0,
        ],
        'narrow_stress' => [
            'label' => 'Narrow-width stress',
            'platform' => 'android',
            'width' => 320,
            'height' => 568,
            'safe_area' => ['top' => 24, 'right' => 0, 'bottom' => 16, 'left' => 0],
            'text_scale' => 1.15,
            'orientation' => 'portrait',
            'keyboard_inset_bottom' => 0,
        ],
        'tablet' => [
            'label' => 'Tablet',
            'platform' => 'android',
            'width' => 768,
            'height' => 1024,
            'safe_area' => ['top' => 24, 'right' => 0, 'bottom' => 24, 'left' => 0],
            'text_scale' => 1.0,
            'orientation' => 'portrait',
            'keyboard_inset_bottom' => 0,
        ],
    ],
];
