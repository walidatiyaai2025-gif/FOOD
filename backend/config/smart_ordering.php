<?php

return [
    'base_domain' => env('SMART_ORDERING_BASE_DOMAIN', 'foodex.com'),
    'pilot_key' => (string) env('SMART_ORDERING_PILOT_KEY', ''),
    'internal_key' => (string) env('SMART_ORDERING_INTERNAL_KEY', ''),
    'whatsapp_reminder_lead_minutes' => (int) env('SMART_ORDERING_WHATSAPP_REMINDER_LEAD_MINUTES', 60),
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
    'customer_tags' => [
        'frequent_order_count' => 5,
        'vip_lifetime_spend' => 250.000,
        'high_spender_average_order' => 35.000,
        'dormant_days' => 60,
    ],
    'repeat_order' => [
        'draft_ttl_minutes' => 30,
        'history_limit' => 20,
    ],
    'loyalty' => [
        'default_points_per_currency_unit' => 1.0,
        'default_minimum_spend' => 0.0,
        'default_expiry_days' => null,
    ],
    'reviews' => [
        'low_rating_threshold' => 2,
        'external_review_min_rating' => 4,
    ],
    'analytics' => [
        'retention_days' => (int) env('SMART_ORDERING_ANALYTICS_RETENTION_DAYS', 400),
        'dashboard_max_days' => 366,
    ],
];
