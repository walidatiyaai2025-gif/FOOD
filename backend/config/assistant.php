<?php

return [
    'enabled' => (bool) env('ASSISTANT_ENABLED', false),
    'read_only' => (bool) env('ASSISTANT_READ_ONLY', true),
    'retention_days' => max(1, (int) env('ASSISTANT_RETENTION_DAYS', 90)),
    'cache_seconds' => max(0, (int) env('ASSISTANT_CACHE_SECONDS', 60)),
    'rate_limit' => max(1, (int) env('ASSISTANT_RATE_LIMIT', 30)),
    'agent' => [
        'max_steps' => min(4, max(1, (int) env('ASSISTANT_AGENT_MAX_STEPS', 2))),
        'max_tool_calls' => min(8, max(1, (int) env('ASSISTANT_AGENT_MAX_TOOL_CALLS', 4))),
        'max_result_bytes' => min(262144, max(8192, (int) env('ASSISTANT_AGENT_MAX_RESULT_BYTES', 65536))),
        'max_seconds' => min(30, max(3, (int) env('ASSISTANT_AGENT_MAX_SECONDS', 12))),
        'planning_tokens' => min(1024, max(128, (int) env('ASSISTANT_AGENT_PLANNING_TOKENS', 384))),
        'synthesis_tokens' => min(1536, max(128, (int) env('ASSISTANT_AGENT_SYNTHESIS_TOKENS', 640))),
    ],
    'knowledge' => [
        'max_results' => min(8, max(1, (int) env('ASSISTANT_KNOWLEDGE_MAX_RESULTS', 5))),
        'candidate_limit' => min(500, max(20, (int) env('ASSISTANT_KNOWLEDGE_CANDIDATE_LIMIT', 200))),
        'retrieval_threshold' => min(1.0, max(0.05, (float) env('ASSISTANT_KNOWLEDGE_RETRIEVAL_THRESHOLD', 0.45))),
        'chunk_chars' => min(4000, max(400, (int) env('ASSISTANT_KNOWLEDGE_CHUNK_CHARS', 1200))),
        'chunk_overlap_chars' => min(300, max(0, (int) env('ASSISTANT_KNOWLEDGE_CHUNK_OVERLAP_CHARS', 120))),
    ],
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
