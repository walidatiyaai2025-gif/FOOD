<?php

return [
    'enabled' => (bool) env('ASSISTANT_ENABLED', false),
    'read_only' => (bool) env('ASSISTANT_READ_ONLY', true),
    'retention_days' => max(1, (int) env('ASSISTANT_RETENTION_DAYS', 90)),
    'cache_seconds' => max(0, (int) env('ASSISTANT_CACHE_SECONDS', 60)),
    'rate_limit' => max(1, (int) env('ASSISTANT_RATE_LIMIT', 30)),
];
