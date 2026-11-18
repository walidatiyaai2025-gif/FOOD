<?php

namespace App\Domain\Installer;

use App\Models\User;
use App\Services\AuditLogger;
use App\Services\SchedulerRuntime;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Symfony\Component\Console\Output\BufferedOutput;
use Throwable;

final class InstallerWorkflow
{
    /** @var array<int, string> */
    public const REQUIRED_EXTENSIONS = ['pdo', 'openssl', 'mbstring', 'tokenizer', 'json', 'zip', 'fileinfo'];

    public function __construct(
        private readonly InstallerEnvironment $environment,
        private readonly InstallState $state,
        private readonly AuditLogger $auditLogger,
        private readonly SchedulerRuntime $scheduler,
    ) {}

    /**
     * @return array<int, string>
     */
    public function serverRequirementFailures(?callable $extensionLoaded = null): array
    {
        $extensionLoaded ??= static fn (string $extension): bool => extension_loaded($extension);
        $failures = [];

        if (version_compare(PHP_VERSION, '8.2.0', '<')) {
            $failures[] = (string) __('installer.errors.php_version');
        }

        foreach (self::REQUIRED_EXTENSIONS as $extension) {
            if (! $extensionLoaded($extension)) {
                $failures[] = (string) __('installer.errors.extension', ['extension' => $extension]);
            }
        }

        if (! $extensionLoaded('pdo_mysql')) {
            $failures[] = (string) __('installer.errors.pdo_mysql');
        }

        return $failures;
    }

    /**
     * ERP operational evidence requires detached Ed25519 verification.
     * Missing crypto support blocks module certification, not core setup.
     */
    public function hasErpEvidenceVerifier(
        ?callable $extensionLoaded = null,
        ?callable $functionExists = null,
    ): bool {
        $extensionLoaded ??= static fn (string $extension): bool => extension_loaded($extension);
        $functionExists ??= static fn (string $function): bool => function_exists($function);

        return $extensionLoaded('sodium') && $functionExists('sodium_crypto_sign_verify_detached');
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
            if (! $this->isWritableInstallerDirectory($path)) {
                $failures[] = (string) __('installer.errors.path_writable', ['path' => $label]);
            }
        }

        // The .env writer uses a temporary sibling + atomic rename; writing
        // storage/cache alone does not establish the required parent rights.
        $environmentPath = $this->environment->path();
        if ($this->hasInstallerSymlinkComponent($environmentPath)
            || ! $this->writableOrCreatableInstallerTarget($environmentPath, readExisting: true)) {
            $failures[] = (string) __('installer.errors.env_directory_writable');
        }

        // The persistent lock path is separate from the replaceable .env.
        // Inspect only: the preflight must not create files or mutate secrets.
        $lockPath = (string) config(
            'foodex.installer_env_lock_path',
            storage_path('framework/installer-env-locks/'.hash('sha256', $environmentPath).'.lock'),
        );
        if ($this->hasInstallerSymlinkComponent($lockPath)
            || ! $this->writableOrCreatableInstallerTarget($lockPath, writeExisting: true)) {
            $failures[] = (string) __('installer.errors.env_lock_directory_writable');
        }

        // InstallState creates the progress and final installed lock using
        // sibling temporary files + rename. It also needs a persistent token
        // lock before the first POST. Check their actual configured parents,
        // without creating folders/files or touching an existing live site.
        $progressPath = $this->state->progressPath();
        $tokenLockPath = (string) config(
            'foodex.installer_token_lock_path',
            storage_path('framework/installer-token-locks/'.hash('sha256', $progressPath).'.lock'),
        );
        foreach ([
            'install_state_directory_writable' => $this->state->lockPath(),
            'install_progress_directory_writable' => $progressPath,
            'installer_token_lock_directory_writable' => $tokenLockPath,
        ] as $error => $path) {
            if ($this->hasInstallerSymlinkComponent($path)
                || ! $this->writableOrCreatableInstallerTarget(
                    $path,
                    readExisting: $error === 'install_progress_directory_writable',
                    writeExisting: $error === 'installer_token_lock_directory_writable',
                )) {
                $failures[] = (string) __('installer.errors.'.$error);
            }
        }

        return $failures;
    }

    /**
     * Read-only host preflight. A writable parent supports atomic rename but
     * does not prove an existing leaf is a readable file or usable lock.
     * Never create, chmod, unlink, read contents or print absolute host paths.
     */
    private function writableOrCreatableInstallerTarget(
        string $path,
        bool $readExisting = false,
        bool $writeExisting = false,
    ): bool {
        if (! $this->writableOrCreatableInstallerDirectory(dirname($path))) {
            return false;
        }

        if (! file_exists($path)) {
            return ! is_link($path);
        }

        if (! is_file($path)) {
            return false;
        }

        $metadata = @lstat($path);
        if ($metadata === false
            || ($metadata['mode'] & 0170000) !== 0100000
            || $metadata['nlink'] !== 1) {
            return false;
        }

        if ($readExisting && ! is_readable($path)) {
            return false;
        }
        if ($writeExisting && ! is_writable($path)) {
            return false;
        }

        // A privileged test runner may access files that a shared-host PHP
        // worker cannot. Zero POSIX read/write bits are never "Ready".
        if (PHP_OS_FAMILY !== 'Windows') {
            if ($readExisting && ($metadata['mode'] & 0444) === 0) {
                return false;
            }
            if ($writeExisting && ($metadata['mode'] & 0222) === 0) {
                return false;
            }
        }

        return true;
    }

    private function writableOrCreatableInstallerDirectory(string $directory): bool
    {
        $candidate = $directory;

        while (! is_dir($candidate)) {
            if (file_exists($candidate) || is_link($candidate)) {
                return false;
            }

            $parent = dirname($candidate);
            if ($parent === $candidate) {
                return false;
            }

            $candidate = $parent;
        }

        return $this->isWritableInstallerDirectory($candidate);
    }

    /**
     * Checking is_writable() alone can misreport a 0555 cPanel directory as
     * ready when CI or a PHP process runs with elevated permissions. Do not
     * probe by creating files or changing host ACLs during installer preflight.
     */
    private function isWritableInstallerDirectory(string $directory): bool
    {
        if (! is_dir($directory) || ! is_writable($directory)) {
            return false;
        }

        if (PHP_OS_FAMILY !== 'Windows') {
            $mode = @fileperms($directory);
            if ($mode === false || ($mode & 0222) === 0) {
                return false;
            }
        }

        return true;
    }

    private function hasInstallerSymlinkComponent(string $path): bool
    {
        $candidate = $path;

        while (true) {
            if (is_link($candidate)) {
                return true;
            }

            $parent = dirname($candidate);
            if ($parent === $candidate) {
                return false;
            }

            $candidate = $parent;
        }
    }

    /**
     * @param  array{host: string, port: int|string, database: string, username: string, password?: string|null}  $data
     */
    public function saveDatabaseConfiguration(array $data): void
    {
        // Losing installed.lock must never turn a configured live site back
        // into a first-install wizard with permission to replace its .env.
        // Inspect BEFORE the first write; the next step's DB probe is too
        // late to preserve the original database credentials and APP_KEY.
        $existing = $this->environment->read(['APP_ENV', 'APP_KEY']);
        abort_if($this->state->isInstalled(), 404);
        if (trim((string) ($existing['APP_KEY'] ?? '')) !== '') {
            throw new RuntimeException((string) __('installer.environment_already_configured'));
        }

        // A genuine first-install ZIP starts in APP_ENV=production with an
        // empty APP_KEY. Treat that as uninitialized, not as proof of a live
        // site, but examine the previously configured database BEFORE
        // changing its credentials whenever the old connection is reachable.
        if (strtolower(trim((string) ($existing['APP_ENV'] ?? ''))) === 'production') {
            $existingConnectionAvailable = false;
            try {
                $this->applyDatabaseConfiguration();
                DB::connection()->getPdo();
                $existingConnectionAvailable = true;
            } catch (Throwable) {
                // The stock production template may name an unavailable
                // database. An empty key/absent lock may still configure it.
            }

            if ($existingConnectionAvailable) {
                try {
                    $this->assertFreshInstallDatabase();
                } catch (RuntimeException) {
                    // Existing FOODEX business data or uncertain introspection
                    // must never be overwritten through a fresh setup.
                    throw new RuntimeException((string) __('installer.environment_already_configured'));
                }
            }
        }

        $this->environment->write([
            'DB_CONNECTION' => 'mysql',
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
            throw new RuntimeException((string) __('installer.errors.db_connection'));
        }

        // A missing installer lock does not make an already populated FOODEX
        // database safe to initialize. Check before any migrations or seeding.
        $this->assertFreshInstallDatabase();
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
        foreach ([storage_path('app/private'), storage_path('app/public')] as $path) {
            if (! is_dir($path) && ! @mkdir($path, 0750, true) && ! is_dir($path)) {
                throw new RuntimeException((string) __('installer.errors.storage_dir'));
            }
        }

        $publicLink = public_path('storage');

        // Shared hosts may pre-provision a real public/storage directory and
        // map it at the web-server layer. Preserve that compatibility. When
        // the entry is a symlink, however, require its exact canonical target.
        if (! is_link($publicLink) && ! is_dir($publicLink)) {
            if (! function_exists('symlink')) {
                throw new RuntimeException((string) __('installer.errors.public_storage_link'));
            }

            try {
                Artisan::call('storage:link');
            } catch (Throwable) {
                throw new RuntimeException((string) __('installer.errors.public_storage_link'));
            }
        }

        if (is_link($publicLink)) {
            // Never accept a foreign or dangling symlink as public uploads.
            $expected = realpath(storage_path('app/public'));
            $actual = realpath($publicLink);

            if ($expected === false || $actual === false || $actual !== $expected) {
                throw new RuntimeException((string) __('installer.public_storage_link_invalid'));
            }
        } elseif (! is_dir($publicLink)) {
            throw new RuntimeException((string) __('installer.errors.public_storage_link'));
        }

        // Persist the selected disk only after symlink validation (or after
        // preserving a pre-existing shared-host directory mapping).
        $this->environment->write(['FILESYSTEM_DISK' => $disk]);
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
                // A missing Redis password is an intentional dotenv null, not
                // the operator-entered literal string "null".
                'REDIS_PASSWORD' => $data['redis_password'] ?? null,
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
        $this->assertFreshInstallDatabase();

        // Capture the FIRST migration failure. MySQL DDL is not transactional:
        // rerunning migrate merely to diagnose a 422 can hit a partially
        // created table and disguise the original failure (or mutate data).
        $output = new BufferedOutput;

        try {
            $exitCode = Artisan::call('migrate', ['--force' => true], $output);
        } catch (Throwable $exception) {
            // Some database/console failures throw before Artisan returns an
            // exit code. Preserve only the same allowlisted metadata; never
            // expose the exception, SQL, connection details or credentials.
            $this->logSanitizedMigrationFailure($output->fetch()."\n".$exception->getMessage());

            throw new RuntimeException((string) __('installer.errors.migrations'));
        }

        if ($exitCode !== 0) {
            $this->logSanitizedMigrationFailure($output->fetch());

            throw new RuntimeException((string) __('installer.errors.migrations'));
        }
    }

    private function logSanitizedMigrationFailure(string $diagnostic): void
    {
        preg_match('/SQLSTATE\\[([A-Z0-9]{5})\\]/', $diagnostic, $sqlState);
        preg_match('/\\b([0-9]{4}_[0-9]{2}_[0-9]{2}_[0-9]{6}_[a-z0-9_]+)\\b/i', $diagnostic, $migration);

        // Persist only a fixed SQLSTATE and migration filename. Never log raw
        // SQL, connection details, passwords or command/exception contents.
        Log::error('Installer migration failed', [
            'sqlstate' => $sqlState[1] ?? 'unknown',
            'migration' => $migration[1] ?? 'unknown',
        ]);
    }

    public function seedRequiredCoreData(): void
    {
        // A direct invocation is not entitled to replay the fresh-install
        // seeder against a configured site. CoreReferenceSeeder intentionally
        // reconciles built-in permission grants, so even a second successful
        // Artisan call can overwrite administrative role changes.
        abort_if($this->state->isInstalled(), 404);
        $existing = $this->environment->read(['APP_KEY']);
        if (trim((string) ($existing['APP_KEY'] ?? '')) !== '') {
            throw new RuntimeException((string) __('installer.environment_already_configured'));
        }

        $this->applyDatabaseConfiguration();
        // Permit an interrupted, not-yet-live installation to retry its core
        // seed, but refuse user/order/version data before *any* seed write.
        $this->assertFreshInstallDatabase();

        if (Artisan::call('db:seed', ['--class' => DatabaseSeeder::class, '--force' => true]) !== 0) {
            throw new RuntimeException((string) __('installer.errors.seed'));
        }

        $this->seedFreshInstallVanReleaseDefaults();
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
                throw new RuntimeException((string) __('installer.errors.storage_health'));
            }

            $probe = $probeDirectory.'/health-'.bin2hex(random_bytes(5));
            if (file_put_contents($probe, 'ok', LOCK_EX) === false) {
                throw new RuntimeException((string) __('installer.errors.storage_health'));
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
                    throw new RuntimeException((string) __('installer.errors.redis_queue_health'));
                }
                Cache::store('redis')->forget($queueProbe);
            }
            $checks['queue'] = in_array($queue, ['redis', 'sync'], true);
        } catch (Throwable) {
            throw new RuntimeException((string) __('installer.errors.health'));
        }

        if (in_array(false, $checks, true)) {
            throw new RuntimeException((string) __('installer.errors.health'));
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
            throw new RuntimeException((string) __('installer.errors.version'));
        }

        DB::transaction(function () use ($admin, $version): void {
            $roleId = DB::table('roles')->where('code', 'SUPER_ADMIN')->value('id');

            if ($roleId === null) {
                throw new RuntimeException((string) __('installer.errors.super_admin_role'));
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

        // Scheduling is a platform responsibility: provision cron automatically when the host allows it.
        // The runtime heartbeat remains active as a no-manual-setup fallback on restricted hosting.
        $this->scheduler->provisionCron();

        $this->state->writeLock($version);
        $this->state->clearProgress();

        return $version;
    }

    private function seedFreshInstallVanReleaseDefaults(): void
    {
        if ($this->state->isInstalled()) {
            return;
        }

        foreach ([
            'commercial_rules_enabled',
            'flash_offers_enabled',
            'customer_flash_popup_enabled',
            'van_offers_enabled',
        ] as $key) {
            if (DB::table('settings')->whereNull('store_id')->where('key', $key)->exists()) {
                continue;
            }

            DB::table('settings')->insert([
                'store_id' => null,
                'key' => $key,
                'value' => json_encode(true, JSON_THROW_ON_ERROR),
                'is_secret' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
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

    /**
     * Refuse to treat an existing live database as an empty first install.
     * These are durable business/version markers; empty migrated tables are
     * allowed so an interrupted migration can be safely retried.
     */
    private function assertFreshInstallDatabase(): void
    {
        // Core references and the migration-created provisional company hierarchy
        // are allowed during an interrupted *fresh* install. Real ERP masters,
        // inventory, financial policies and custody records are not: a DB can
        // contain them without having any users, orders or legacy products.
        foreach ([
            'system_versions', 'users', 'stores', 'orders', 'invoices', 'products', 'b2b_accounts',
            'b2b_customers', 'b2c_customers', 'warehouses', 'enterprise_suppliers',
            'enterprise_purchase_requests', 'enterprise_purchase_orders',
            'enterprise_goods_receipts', 'enterprise_inventory_lots',
            'enterprise_finance_credit_terms', 'collection_accounts',
            'collection_transactions', 'payments',
        ] as $table) {
            try {
                $containsData = Schema::hasTable($table) && DB::table($table)->exists();
            } catch (Throwable) {
                // Do not continue when the selected database cannot be inspected.
                throw new RuntimeException((string) __('installer.errors.database_safety_unverifiable'));
            }

            if ($containsData) {
                throw new RuntimeException((string) __('installer.errors.existing_business_data'));
            }
        }
    }

    private function applyDatabaseConfiguration(): void
    {
        $values = $this->environment->read([
            'DB_CONNECTION', 'DB_HOST', 'DB_PORT', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD',
        ]);
        $connection = $values['DB_CONNECTION'] ?: (string) config('database.default', 'mysql');

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

        if ($connection === 'mysql') {
            config([
                'database.default' => 'mysql',
                'database.connections.mysql.host' => $values['DB_HOST'] ?: 'localhost',
                'database.connections.mysql.port' => $values['DB_PORT'] ?: '3306',
                'database.connections.mysql.database' => $values['DB_DATABASE'] ?: 'foodex',
                'database.connections.mysql.username' => $values['DB_USERNAME'] ?: 'foodex',
                'database.connections.mysql.password' => $values['DB_PASSWORD'] ?? '',
            ]);
            DB::purge('mysql');

            return;
        }

        if ($connection === 'pgsql') {
            config([
                'database.default' => 'pgsql',
                'database.connections.pgsql.host' => $values['DB_HOST'] ?: '127.0.0.1',
                'database.connections.pgsql.port' => $values['DB_PORT'] ?: '5432',
                'database.connections.pgsql.database' => $values['DB_DATABASE'] ?: 'foodex',
                'database.connections.pgsql.username' => $values['DB_USERNAME'] ?: 'foodex',
                'database.connections.pgsql.password' => $values['DB_PASSWORD'] ?? '',
            ]);
            DB::purge('pgsql');

            return;
        }

        throw new RuntimeException((string) __('installer.errors.unsupported_driver'));
    }
}
