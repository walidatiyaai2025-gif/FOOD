<?php

return [
    // Never enabled by a browser request; only the deployment operator can opt in.
    'production_enabled' => (bool) env('FOODEX_LAUNCH_RESET_PRODUCTION_ENABLED', false),
    'preflight_path' => storage_path('app/private/launch-reset-preflight.json'),
];
