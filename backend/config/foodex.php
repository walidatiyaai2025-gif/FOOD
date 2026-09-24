<?php

return [
    'install_lock' => env('FOODEX_INSTALL_LOCK', 'storage/app/system/installed.lock'),
    'install_progress' => env('FOODEX_INSTALL_PROGRESS', 'storage/app/system/install-progress.json'),
    'installer_env_path' => env('FOODEX_INSTALL_ENV_PATH', base_path('.env')),
    'update_backup_path' => env('FOODEX_UPDATE_BACKUP_PATH', storage_path('app/system/update-backups')),
    'pg_dump_binary' => env('FOODEX_PG_DUMP_BINARY', 'pg_dump'),
    'pg_restore_binary' => env('FOODEX_PG_RESTORE_BINARY', 'pg_restore'),
];
