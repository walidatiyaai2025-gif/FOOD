<?php

return [
    // Never enabled by a browser request; only the deployment operator can opt in.
    'production_enabled' => (bool) env('FOODEX_LAUNCH_RESET_PRODUCTION_ENABLED', false),
    'preflight_path' => storage_path('app/private/launch-reset-preflight.json'),
    // Private operator metadata. Never serve this path from public storage.
    'snapshot_metadata_path' => env('FOODEX_LAUNCH_SNAPSHOT_METADATA_PATH', storage_path('app/private/production-dataset-snapshot.json')),
];
