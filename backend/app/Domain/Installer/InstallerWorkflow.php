<?php

namespace App\Domain\Installer;

use App\Models\User;
use App\Services\AuditLogger;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use RuntimeException;
use Throwable;

final class InstallerWorkflow
{
    public function __construct(
        private readonly InstallerEnvironment $environment,
        private readonly InstallState $state,
        private readonly AuditLogger $auditLogger,
    ) {}

    /**
     * @return array<int, string>
     */
    public function serverRequirementFailures(): array
    {
        $failures = [];

        if (version_compare(PHP_VERSION, '8.2.0', '<')) {
            $failures[] = 'PHP 8.2 or newer is required.';
        }

        foreach (['pdo', 'openssl', 'mbstring', 'tokenizer', 'json'] as $extension) {
            if (! extension_loaded($extension)) {
                $failures[] = "PHP extension [$extension] is required.";
            }
        }

        if (! extension_loaded('pdo_pgsql')) {
            $failures[] = 'PHP extension [pdo_pgsql] is required for the production PostgreSQL database.';
        }

        return $failures;
    }

    /**
     * @return array<int, string>
     */
    public function writablePathFailures(): array
    {
        $failures = [];

        foreach ([
            'storage' => storage_path(),
            'bootstrap/cache' => base_path('bootstrap/cache'),
        ] as $label => $path) {
            if (! is_dir($path) || ! is_writable($path)) {
                $failures[] = "Runtime path [$label] must be writable.";
            }
        }

        return $failures;
    }

    /**
     * @param  array{host: string, port: int|string, database: string, username: string, password?: string|null}  $data
     */
    public function saveDatabaseConfiguration(array $data): void
    {
        $this->environment->write([
            'DB_CONNECTION' => 'pgsql',
            'DB_HOST' => $data['host'],
            'DB_PORT' => (string) $data['port'],
            'DB_DATABASE' => $data['database'],
            'DB_USERNAME' => $data['username'],
            'DB_PASSWORD' => $data['password'] ?? '',
        ]);
    }

    public function testDatabaseConnection(): void
    {
        try {
            $this->applyDatabaseConfiguration();
            DB::connection()->getPdo();
            DB::select('select 1');
        } catch (Throwable) {
            throw new RuntimeException('Database connection failed. Verify the supplied host, port, database and credentials.');
        }
    }

    /**
     * @param  array{name: string, url: string, locale: string}  $data
     */
    public function savePlatformInformation(array $data): void
    {
        $this->environment->write([
            'APP_NAME' => $data['name'],
            'APP_URL' => rtrim($data['url'], '/'),
            'APP_LOCALE' => $data['locale'],
        ]);
    }

    /**
     * @param  array{name: string, email: string, password: string, locale: string}  $data
     * @return array{name: string, email: string, password_hash: string, locale: string}
     */
    public function prepareSuperAdmin(array $data): array
    {
        return [
            'name' => $data['name'],
            'email' => mb_strtolower($data['email']),
            'password_hash' => Hash::make($data['password']),
            'locale' => $data['locale'],
        ];
    }

    public function saveStorageConfiguration(string $disk): void
    {
        $this->environment->write(['FILESYSTEM_DISK' => $disk]);

        foreach ([storage_path('app/private'), storage_path('app/public')] as $path) {
            if (! is_dir($path) && ! @mkdir($path, 0750, true) && ! is_dir($path)) {
                throw new RuntimeException('Storage directories could not be prepared.');
            }
        }
    }

    /**
     * @param  array{cache: string, queue: string, redis_host?: string|null, redis_port?: int|string|null, redis_password?: string|null}  $data
     */
    public function saveCacheQueueConfiguration(array $data): void
    {
        $values = [
            'CACHE_STORE' => $data['cache'],
            'QUEUE_CONNECTION' => $data['queue'],
        ];

        if ($data['cache'] === 'redis' || $data['queue'] === 'redis') {
            $values += [
                'REDIS_HOST' => $data['redis_host'] ?? '127.0.0.1',
                'REDIS_PORT' => (string) ($data['redis_port'] ?? 6379),
                'REDIS_PASSWORD' => $data['redis_password'] ?? 'null',
            ];
        }

        $this->environment->write($values);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function saveMailConfiguration(array $data): void
    {
        $values = [
            'MAIL_MAILER' => (string) $data['mailer'],
            'MAIL_FROM_ADDRESS' => (string) $data['from_address'],
            'MAIL_FROM_NAME' => (string) $data['from_name'],
        ];

        if ($data['mailer'] === 'smtp') {
            $values += [
                'MAIL_HOST' => (string) $data['host'],
                'MAIL_PORT' => (string) $data['port'],
                'MAIL_USERNAME' => (string) ($data['username'] ?? ''),
                'MAIL_PASSWORD' => (string) ($data['password'] ?? ''),
                'MAIL_SCHEME' => (string) ($data['scheme'] ?? ''),
            ];
        }

        $this->environment->write($values);
    }

    public function runMigrations(): void
    {
        $this->applyDatabaseConfiguration();

        if (Artisan::call('migrate', ['--force' => true]) !== 0) {
            throw new RuntimeException('Database migrations failed.');
        }
    }

    public function seedRequiredCoreData(): void
    {
        $this->applyDatabaseConfiguration();

        if (Artisan::call('db:seed', ['--class' => DatabaseSeeder::class, '--force' => true]) !== 0) {
            throw new RuntimeException('Required core data could not be seeded.');
        }
    }

    public function generateApplicationKey(): void
    {
        $key = 'base64:'.base64_encode(random_bytes(32));
        $this->environment->write(['APP_KEY' => $key]);
        config(['app.key' => $key]);
    }

    /**
     * @return array<string, bool>
     */
    public function healthCheck(): array
    {
        $this->applyRuntimeConfiguration();
        $checks = [
            'database' => false,
            'storage' => false,
            'cache' => false,
            'queue' => false,
        ];

        try {
            DB::select('select 1');
            $checks['database'] = true;

            $probeDirectory = storage_path('app/system');
            if (! is_dir($probeDirectory) && ! @mkdir($probeDirectory, 0750, true) && ! is_dir($probeDirectory)) {
                throw new RuntimeException('Storage health check failed.');
            }

            $probe = $probeDirectory.'/health-'.bin2hex(random_bytes(5));
            if (file_put_contents($probe, 'ok', LOCK_EX) === false) {
                throw new RuntimeException('Storage health check failed.');
            }
            @unlink($probe);
            $checks['storage'] = true;

            $cacheStore = (string) config('cache.default');
            $cacheKey = 'foodex-installer-health-'.bin2hex(random_bytes(5));
            Cache::store($cacheStore)->put($cacheKey, 'ok', 30);
            $checks['cache'] = Cache::store($cacheStore)->get($cacheKey) === 'ok';
            Cache::store($cacheStore)->forget($cacheKey);

            $queue = (string) config('queue.default');
            if ($queue === 'redis' && $cacheStore !== 'redis') {
                $queueProbe = 'foodex-installer-queue-health-'.bin2hex(random_bytes(5));
                Cache::store('redis')->put($queueProbe, 'ok', 30);
                if (Cache::store('redis')->get($queueProbe) !== 'ok') {
                    throw new RuntimeException('Queue Redis health check failed.');
                }
                Cache::store('redis')->forget($queueProbe);
            }
            $checks['queue'] = in_array($queue, ['redis', 'sync'], true);
        } catch (Throwable) {
            throw new RuntimeException('Installer health check failed. Verify database, storage, cache and queue services.');
        }

        if (in_array(false, $checks, true)) {
            throw new RuntimeException('Installer health check failed. Verify database, storage, cache and queue services.');
        }

        return $checks;
    }

    /**
     * @param  array{name: string, email: string, password_hash: string, locale: string}  $admin
     */
    public function finish(array $admin): string
    {
        $this->applyRuntimeConfiguration();
        $version = trim((string) @file_get_contents(base_path('../VERSION')));

        if ($version === '') {
            throw new RuntimeException('Platform version could not be determined.');
        }

        DB::transaction(function () use ($admin, $version): void {
            $roleId = DB::table('roles')->where('code', 'SUPER_ADMIN')->value('id');

            if ($roleId === null) {
                throw new RuntimeException('SUPER_ADMIN role is missing. Run the core seed step first.');
            }

            $userId = DB::table('users')->where('email', $admin['email'])->value('id');
            $userValues = [
                'name' => $admin['name'],
                'email' => $admin['email'],
                'password' => $admin['password_hash'],
                'locale' => $admin['locale'],
                'is_active' => true,
                'updated_at' => now(),
            ];

            if ($userId === null) {
                $userId = DB::table('users')->insertGetId([
                    ...$userValues,
                    'created_at' => now(),
                ]);
            } else {
                DB::table('users')->where('id', $userId)->update($userValues);
            }

            DB::table('role_user')->insertOrIgnore([
                'role_id' => (int) $roleId,
                'user_id' => (int) $userId,
            ]);

            DB::table('system_versions')->updateOrInsert(
                ['version' => $version],
                ['installed_at' => now(), 'package_hash' => null, 'updated_at' => now(), 'created_at' => now()],
            );

            $user = User::query()->findOrFail((int) $userId);
            $this->auditLogger->record(
                'installer.completed',
                $user,
                $user,
                null,
                ['version' => $version, 'role' => 'SUPER_ADMIN'],
            );
        });

        $this->environment->write([
            'APP_ENV' => 'production',
            'APP_DEBUG' => 'false',
        ]);

        $this->state->writeLock($version);
        $this->state->clearProgress();

        return $version;
    }

    private function applyRuntimeConfiguration(): void
    {
        $this->applyDatabaseConfiguration();
        $values = $this->environment->read([
            'CACHE_STORE', 'QUEUE_CONNECTION', 'REDIS_HOST', 'REDIS_PASSWORD', 'REDIS_PORT',
        ]);

        if ($values['CACHE_STORE']) {
            config(['cache.default' => $values['CACHE_STORE']]);
        }
        if ($values['QUEUE_CONNECTION']) {
            config(['queue.default' => $values['QUEUE_CONNECTION']]);
        }
        if ($values['REDIS_HOST']) {
            config([
                'database.redis.default.host' => $values['REDIS_HOST'],
                'database.redis.cache.host' => $values['REDIS_HOST'],
            ]);
        }
        if ($values['REDIS_PORT']) {
            config([
                'database.redis.default.port' => $values['REDIS_PORT'],
                'database.redis.cache.port' => $values['REDIS_PORT'],
            ]);
        }
        if ($values['REDIS_PASSWORD'] !== null) {
            $password = in_array($values['REDIS_PASSWORD'], ['', 'null'], true) ? null : $values['REDIS_PASSWORD'];
            config([
                'database.redis.default.password' => $password,
                'database.redis.cache.password' => $password,
            ]);
        }
    }

    private function applyDatabaseConfiguration(): void
    {
        $values = $this->environment->read([
            'DB_CONNECTION', 'DB_HOST', 'DB_PORT', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD',
        ]);
        $connection = $values['DB_CONNECTION'] ?: (string) config('database.default', 'pgsql');

        if ($connection === 'sqlite') {
            $database = $values['DB_DATABASE'] ?: (string) config('database.connections.sqlite.database');
            $unchanged = config('database.default') === 'sqlite'
                && config('database.connections.sqlite.database') === $database;

            config([
                'database.default' => 'sqlite',
                'database.connections.sqlite.database' => $database,
            ]);

            if (! $unchanged) {
                DB::purge('sqlite');
            }

            return;
        }

        if ($connection !== 'pgsql') {
            throw new RuntimeException('Unsupported installer database driver.');
        }

        config([
            'database.default' => 'pgsql',
            'database.connections.pgsql.host' => $values['DB_HOST'] ?: '127.0.0.1',
            'database.connections.pgsql.port' => $values['DB_PORT'] ?: '5432',
            'database.connections.pgsql.database' => $values['DB_DATABASE'] ?: 'foodex',
            'database.connections.pgsql.username' => $values['DB_USERNAME'] ?: 'foodex',
            'database.connections.pgsql.password' => $values['DB_PASSWORD'] ?? '',
        ]);
        DB::purge('pgsql');
    }
}
