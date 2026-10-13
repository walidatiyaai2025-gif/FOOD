<?php

return [
    // Private metadata only. Backup payloads remain owned by the trusted updater runtime.
    'metadata_path' => env(
        'FOODEX_PRODUCTION_SNAPSHOT_METADATA',
        storage_path('app/private/production-launch/snapshot.json'),
    ),
];
