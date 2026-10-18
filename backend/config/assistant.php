<?php

return [
    'enabled' => (bool) env('ASSISTANT_ENABLED', false),
    'read_only' => (bool) env('ASSISTANT_READ_ONLY', true),
    'retention_days' => max(1, (int) env('ASSISTANT_RETENTION_DAYS', 90)),
    'cache_seconds' => max(0, (int) env('ASSISTANT_CACHE_SECONDS', 60)),
    'rate_limit' => max(1, (int) env('ASSISTANT_RATE_LIMIT', 30)),
    'external_ai' => [
        'enabled' => (bool) env('ASSISTANT_AI_ENABLED', false),
        'api_url' => (string) env('ASSISTANT_AI_API_URL', ''),
        'model' => (string) env('ASSISTANT_AI_MODEL', ''),
        'api_key' => (string) env('ASSISTANT_AI_API_KEY', ''),
        'timeout_seconds' => min(120, max(3, (int) env('ASSISTANT_AI_TIMEOUT_SECONDS', 20))),
    ],
];
