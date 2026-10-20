<?php

use Illuminate\Support\Str;

return [
    'default' => env('DB_CONNECTION', 'mysql'),

    // Emergency opt-in only. Persistent FOODEX databases prohibit destructive
    // Artisan database commands by default; disposable testing/SQLite is exempt.
    'allow_destructive_commands' => env('FOODEX_ALLOW_DESTRUCTIVE_DB_COMMANDS', false),
    'connections' => [
        'sqlite' => [
            'driver' => 'sqlite',
            'url' => env('DB_URL'),
            'database' => env('DB_DATABASE', database_path('database.sqlite')),
            'prefix' => '',
            'foreign_key_constraints' => env('DB_FOREIGN_KEYS', true),
        ],
        'mysql' => [
            'driver' => 'mysql',
            'url' => env('DB_URL'),
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '3306'),
            'database' => env('DB_DATABASE', 'foodex'),
            'username' => env('DB_USERNAME', 'foodex'),
            'password' => env('DB_PASSWORD', ''),
            'unix_socket' => env('DB_SOCKET', ''),
            'charset' => env('DB_CHARSET', 'utf8mb4'),
            'collation' => env('DB_COLLATION', 'utf8mb4_unicode_ci'),
            'prefix' => '',
            'prefix_indexes' => true,
            'strict' => true,
            'engine' => null,
        ],
        'foodex_legacy' => [
            'driver' => 'sqlsrv',
            'host' => env('LEGACY_DB_HOST', '127.0.0.1'),
            'port' => env('LEGACY_DB_PORT', '1433'),
            'database' => env('LEGACY_DB_DATABASE', 'Foodex'),
            'username' => env('LEGACY_DB_USERNAME'),
            'password' => env('LEGACY_DB_PASSWORD'),
            'charset' => 'utf8',
            'prefix' => '',
            'prefix_indexes' => true,
            'encrypt' => env('LEGACY_DB_ENCRYPT', 'no'),
            'trust_server_certificate' => env('LEGACY_DB_TRUST_CERTIFICATE', 'yes'),
            'query_timeout' => (int) env('LEGACY_DB_QUERY_TIMEOUT', 300),
            'detail_chunk' => (int) env('LEGACY_DB_DETAIL_CHUNK', 2000),
            'retry_attempts' => (int) env('LEGACY_DB_RETRY_ATTEMPTS', 5),
        ],
        'pgsql' => [
            'driver' => 'pgsql',
            'url' => env('DB_URL'),
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '5432'),
            'database' => env('DB_DATABASE', 'foodex'),
            'username' => env('DB_USERNAME', 'foodex'),
            'password' => env('DB_PASSWORD', ''),
            'charset' => 'utf8',
            'prefix' => '',
            'prefix_indexes' => true,
            'search_path' => 'public',
            'sslmode' => 'prefer',
        ],
    ],
    'migrations' => ['table' => 'migrations', 'update_date_on_publish' => true],
    'redis' => [
        'client' => env('REDIS_CLIENT', 'phpredis'),
        'options' => ['cluster' => env('REDIS_CLUSTER', 'redis'), 'prefix' => env('REDIS_PREFIX', Str::slug(env('APP_NAME', 'foodex'), '_').'_database_')],
        'default' => ['url' => env('REDIS_URL'), 'host' => env('REDIS_HOST', '127.0.0.1'), 'password' => env('REDIS_PASSWORD'), 'port' => env('REDIS_PORT', '6379'), 'database' => env('REDIS_DB', '0')],
        'cache' => ['url' => env('REDIS_URL'), 'host' => env('REDIS_HOST', '127.0.0.1'), 'password' => env('REDIS_PASSWORD'), 'port' => env('REDIS_PORT', '6379'), 'database' => env('REDIS_CACHE_DB', '1')],
    ],
];
