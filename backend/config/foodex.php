<?php

return [
    'install_lock' => env('FOODEX_INSTALL_LOCK', 'storage/app/system/installed.lock'),
    'install_progress' => env('FOODEX_INSTALL_PROGRESS', 'storage/app/system/install-progress.json'),
    'installer_env_path' => env('FOODEX_INSTALL_ENV_PATH', base_path('.env')),
];
