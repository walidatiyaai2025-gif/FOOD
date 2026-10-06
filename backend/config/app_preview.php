<?php

$appUrl = rtrim((string) env('APP_URL', 'http://localhost'), '/');
$appUrlParts = parse_url($appUrl);
$appOrigin = $appUrl;

if (is_array($appUrlParts)
    && is_string($appUrlParts['scheme'] ?? null)
    && is_string($appUrlParts['host'] ?? null)) {
    $appOrigin = $appUrlParts['scheme'].'://'.$appUrlParts['host'];
    if (isset($appUrlParts['port'])) {
        $appOrigin .= ':'.(int) $appUrlParts['port'];
    }
}

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
            'url' => env('FOODEX_CUSTOMER_PREVIEW_RUNTIME_URL', $appUrl.'/preview/customer/'),
            'contract_version' => env('FOODEX_CUSTOMER_PREVIEW_CONTRACT_VERSION', 'shared-flutter-v1'),
            'allowed_origin' => env('FOODEX_CUSTOMER_PREVIEW_ALLOWED_ORIGIN', $appOrigin),
        ],
        'driver' => [
            'url' => env('FOODEX_DRIVER_PREVIEW_RUNTIME_URL', $appUrl.'/preview/driver/'),
            'contract_version' => env('FOODEX_DRIVER_PREVIEW_CONTRACT_VERSION', 'shared-flutter-v1'),
            'allowed_origin' => env('FOODEX_DRIVER_PREVIEW_ALLOWED_ORIGIN', $appOrigin),
        ],
        'van' => [
            'url' => env('FOODEX_VAN_PREVIEW_RUNTIME_URL', $appUrl.'/preview/van/'),
            'contract_version' => env('FOODEX_VAN_PREVIEW_CONTRACT_VERSION', 'shared-flutter-v1'),
            'allowed_origin' => env('FOODEX_VAN_PREVIEW_ALLOWED_ORIGIN', $appOrigin),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Deterministic device profiles
    |--------------------------------------------------------------------------
    |
    | These values are runtime semantics, not decorative frames. The Dashboard
    | passes them to the shared Flutter runtime so responsive layout, SafeArea,
    | text scaling and inset-sensitive widgets are exercised deterministically.
    |
    */
    'device_profiles' => [
        'android_small' => [
            'label' => 'Small Android',
            'width' => 360,
            'height' => 640,
            'safe_area' => ['top' => 24, 'right' => 0, 'bottom' => 24, 'left' => 0],
            'orientation' => 'portrait',
            'text_scale' => 1.0,
            'view_insets' => ['bottom' => 0],
        ],
        'android_common' => [
            'label' => 'Android common',
            'width' => 390,
            'height' => 844,
            'safe_area' => ['top' => 24, 'right' => 0, 'bottom' => 24, 'left' => 0],
            'orientation' => 'portrait',
            'text_scale' => 1.0,
            'view_insets' => ['bottom' => 0],
        ],
        'android_large' => [
            'label' => 'Large Android',
            'width' => 430,
            'height' => 932,
            'safe_area' => ['top' => 24, 'right' => 0, 'bottom' => 24, 'left' => 0],
            'orientation' => 'portrait',
            'text_scale' => 1.0,
            'view_insets' => ['bottom' => 0],
        ],
        'iphone_common' => [
            'label' => 'iPhone common',
            'width' => 390,
            'height' => 844,
            'safe_area' => ['top' => 59, 'right' => 0, 'bottom' => 34, 'left' => 0],
            'orientation' => 'portrait',
            'text_scale' => 1.0,
            'view_insets' => ['bottom' => 0],
        ],
        'narrow_stress' => [
            'label' => 'Narrow stress',
            'width' => 320,
            'height' => 568,
            'safe_area' => ['top' => 24, 'right' => 0, 'bottom' => 16, 'left' => 0],
            'orientation' => 'portrait',
            'text_scale' => 1.15,
            'view_insets' => ['bottom' => 0],
        ],
        'tablet' => [
            'label' => 'Tablet portrait',
            'width' => 768,
            'height' => 1024,
            'safe_area' => ['top' => 24, 'right' => 0, 'bottom' => 20, 'left' => 0],
            'orientation' => 'portrait',
            'text_scale' => 1.0,
            'view_insets' => ['bottom' => 0],
        ],
    ],
];
