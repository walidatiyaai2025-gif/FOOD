<?php

return [
    'base_domain' => env('SMART_ORDERING_BASE_DOMAIN', 'foodex.com'),
    'pilot_key' => (string) env('SMART_ORDERING_PILOT_KEY', ''),
    'internal_key' => (string) env('SMART_ORDERING_INTERNAL_KEY', ''),
    'reserved_slugs' => [
        'www', 'api', 'admin', 'app', 'apps', 'assets', 'auth', 'cart', 'checkout',
        'help', 'login', 'qr', 'static', 'status', 'support', 'webhooks',
    ],
    'runtime_states' => ['DISABLED', 'INTERNAL_TEST', 'PILOT', 'ENABLED', 'EMERGENCY_STOP'],
    'entitlements' => [
        'public_menu', 'ordering', 'dine_in', 'pickup', 'delivery', 'whatsapp',
        'chatbot', 'offers', 'campaign_qr', 'analytics', 'loyalty', 'reviews',
        'advanced_upsell', 'multi_branch', 'custom_domain', 'foodex_supply_intelligence',
    ],
];
