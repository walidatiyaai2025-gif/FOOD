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
        'fallback_models' => array_values(array_filter(array_map(
            static fn (string $model): string => trim($model),
            explode(',', (string) env('ASSISTANT_AI_FALLBACK_MODELS', '')),
        ))),
        'api_key' => (string) env('ASSISTANT_AI_API_KEY', ''),
        'temperature' => min(1.0, max(0.0, (float) env('ASSISTANT_AI_TEMPERATURE', 0.1))),
        'max_tokens' => min(4096, max(128, (int) env('ASSISTANT_AI_MAX_TOKENS', 512))),
        'context_window_tokens' => min(131072, max(2048, (int) env('ASSISTANT_AI_CONTEXT_WINDOW_TOKENS', 8192))),
        'timeout_seconds' => min(120, max(3, (int) env('ASSISTANT_AI_TIMEOUT_SECONDS', 20))),
        'retry_attempts' => min(2, max(0, (int) env('ASSISTANT_AI_RETRY_ATTEMPTS', 1))),
        'circuit_breaker_failures' => min(10, max(1, (int) env('ASSISTANT_AI_CIRCUIT_BREAKER_FAILURES', 3))),
        'circuit_breaker_seconds' => min(300, max(5, (int) env('ASSISTANT_AI_CIRCUIT_BREAKER_SECONDS', 30))),
    ],
];
