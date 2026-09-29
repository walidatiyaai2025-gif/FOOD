<?php

return [
    'install_lock' => env('FOODEX_INSTALL_LOCK', 'storage/app/system/installed.lock'),
    'install_progress' => env('FOODEX_INSTALL_PROGRESS', 'storage/app/system/install-progress.json'),
    'installer_env_path' => env('FOODEX_INSTALL_ENV_PATH', base_path('.env')),
    'update_backup_path' => env('FOODEX_UPDATE_BACKUP_PATH', storage_path('app/system/update-backups')),
    'mysql_dump_binary' => env('FOODEX_MYSQL_DUMP_BINARY', 'mysqldump'),
    'mysql_client_binary' => env('FOODEX_MYSQL_CLIENT_BINARY', 'mysql'),
    'pg_dump_binary' => env('FOODEX_PG_DUMP_BINARY', 'pg_dump'),
    'pg_restore_binary' => env('FOODEX_PG_RESTORE_BINARY', 'pg_restore'),
    // Temporary pilot convenience. Disable before public production rollout.
    'mobile_trial_username_login' => (bool) env('FOODEX_MOBILE_TRIAL_USERNAME_LOGIN', true),
];
