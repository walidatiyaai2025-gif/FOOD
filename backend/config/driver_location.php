<?php

return [
    'freshness_seconds_default' => (int) env('FOODEX_DRIVER_LOCATION_FRESHNESS_SECONDS', 90),
    'runtime_environment' => env('FOODEX_DRIVER_RUNTIME_ENVIRONMENT', 'production'),
];
