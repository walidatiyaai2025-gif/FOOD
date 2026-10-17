<?php

return [
    'enabled' => (bool) env('WHATSAPP_AUTH_ENABLED', true),
    'default_country_code' => (string) env('WHATSAPP_AUTH_DEFAULT_COUNTRY_CODE', '965'),
    'ttl_minutes' => max(1, (int) env('WHATSAPP_AUTH_TTL_MINUTES', 5)),
    'resend_cooldown_seconds' => max(10, (int) env('WHATSAPP_AUTH_RESEND_COOLDOWN_SECONDS', 60)),
    'max_attempts' => max(1, (int) env('WHATSAPP_AUTH_MAX_ATTEMPTS', 5)),
    'max_resends' => max(0, (int) env('WHATSAPP_AUTH_MAX_RESENDS', 3)),
];
