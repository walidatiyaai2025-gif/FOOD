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
];
