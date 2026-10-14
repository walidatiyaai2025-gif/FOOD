<?php

return [
    'transport' => [
        'queue' => env('WHATSAPP_QUEUE', 'whatsapp'),
        'retry_backoff_seconds' => [30, 120, 300],
        'max_attempts' => 4,
    ],

    'meta' => [
        'base_url' => env('WHATSAPP_META_BASE_URL', 'https://graph.facebook.com'),
        'graph_version' => env('WHATSAPP_META_GRAPH_VERSION', 'v26.0'),
        'timeout_seconds' => (int) env('WHATSAPP_META_TIMEOUT_SECONDS', 10),
    ],

    'supervisor' => [
        'sla_first_response_minutes' => (int) env('WHATSAPP_SLA_FIRST_RESPONSE_MINUTES', 5),
        'sla_response_minutes' => (int) env('WHATSAPP_SLA_RESPONSE_MINUTES', 15),
        'sla_resolution_minutes' => (int) env('WHATSAPP_SLA_RESOLUTION_MINUTES', 240),
        'campaign_conversion_window_days' => (int) env('WHATSAPP_CAMPAIGN_CONVERSION_WINDOW_DAYS', 7),
    ],
];
