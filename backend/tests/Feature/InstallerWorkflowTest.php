<?php

namespace Tests\Feature;

use App\Domain\Installer\InstallerChecklist;
use App\Domain\Installer\InstallerEnvironment;
use App\Domain\Installer\InstallerWorkflow;
use App\Domain\Installer\InstallState;
use App\Services\CommercialFeatureFlags;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class InstallerWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private string $testDirectory;

    public function test_erp_evidence_crypto_prerequisite_is_independent_from_core_install_requirements(): void
    {
        $workflow = app(InstallerWorkflow::class);
        $allExceptSodium = static fn (string $extension): bool => $extension !== 'sodium';
        $allAvailable = static fn (string $value): bool => true;
        $noneAvailable = static fn (string $value): bool => false;

        $this->assertFalse($workflow->hasErpEvidenceVerifier($allExceptSodium, $allAvailable));
        $this->assertFalse($workflow->hasErpEvidenceVerifier($allAvailable, $noneAvailable));
        $this->assertTrue($workflow->hasErpEvidenceVerifier($allAvailable, $allAvailable));
        $this->assertSame([], $workflow->serverRequirementFailures($allExceptSodium));
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->testDirectory = storage_path('framework/testing/installer-'.bin2hex(random_bytes(6)));
        mkdir($this->testDirectory, 0750, true);

        $environmentPath = $this->testDirectory.'/.env';
        file_put_contents($environmentPath, implode(PHP_EOL, [
            'APP_NAME=FOODEX',
            'APP_ENV=testing',
            'APP_KEY=',
            'APP_DEBUG=true',
            'APP_URL=http://localhost',
            'APP_LOCALE=ar',
            'DB_CONNECTION=sqlite',
            'DB_DATABASE=:memory:',
            'FILESYSTEM_DISK=local',
            'CACHE_STORE=array',
            'QUEUE_CONNECTION=sync',
            'MAIL_MAILER=array',
            'MAIL_FROM_ADDRESS=noreply@example.com',
            'MAIL_FROM_NAME=FOODEX',
        ]).PHP_EOL);

        config([
            'foodex.installer_env_path' => $environmentPath,
            'foodex.install_lock' => $this->testDirectory.'/installed.lock',
            'foodex.install_progress' => $this->testDirectory.'/install-progress.json',
            'foodex.scheduler_auto_provision' => false,
            'foodex.scheduler_heartbeat' => false,
        ]);
    }

    protected function tearDown(): void
    {
        if (isset($this->testDirectory) && is_dir($this->testDirectory)) {
            $files = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->testDirectory, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST,
            );

            foreach ($files as $file) {
                if ($file->isDir() && ! $file->isLink()) {
                    rmdir($file->getPathname());
                } else {
                    unlink($file->getPathname());
                }
            }

            rmdir($this->testDirectory);
        }

        parent::tearDown();
    }

    public function test_permission_preflight_rejects_non_directory_environment_and_lock_parents_without_writing(): void
    {
        $envPath = $this->testDirectory.'/.env';
        $before = file_get_contents($envPath);
        $blocker = $this->testDirectory.'/not-a-directory';
        file_put_contents($blocker, 'sentinel');

        config([
            'foodex.installer_env_path' => $blocker.'/.env',
            'foodex.installer_env_lock_path' => $blocker.'/locks/env.lock',
        ]);

        $failures = app(InstallerWorkflow::class)->writablePathFailures();
        $this->assertContains(__('installer.errors.env_directory_writable'), $failures);
        $this->assertContains(__('installer.errors.env_lock_directory_writable'), $failures);
        $this->assertSame($before, file_get_contents($envPath));
        $this->assertSame('sentinel', file_get_contents($blocker));
        $this->assertFileDoesNotExist($blocker.'/.env');
    }

    public function test_permission_preflight_allows_creatable_private_lock_directory_without_creating_it(): void
    {
        $lockDirectory = $this->testDirectory.'/new-lock-directory';
        config(['foodex.installer_env_lock_path' => $lockDirectory.'/env.lock']);

        $failures = app(InstallerWorkflow::class)->writablePathFailures();
        $this->assertNotContains(__('installer.errors.env_directory_writable'), $failures);
        $this->assertNotContains(__('installer.errors.env_lock_directory_writable'), $failures);
        $this->assertDirectoryDoesNotExist($lockDirectory);
    }

    public function test_permission_preflight_rejects_posix_readonly_parent_even_when_privileged_php_can_write(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('POSIX directory permissions are not available on Windows.');
        }

        $readonly = $this->testDirectory.'/readonly-installer-locks';
        mkdir($readonly, 0750);
        $environment = $this->testDirectory.'/.env';
        $before = file_get_contents($environment);
        $lock = $readonly.'/env.lock';

        config(['foodex.installer_env_lock_path' => $lock]);

        chmod($readonly, 0555);
        try {
            clearstatcache(true, $readonly);
            $this->assertSame(0, fileperms($readonly) & 0222);
            $failures = app(InstallerWorkflow::class)->writablePathFailures();

            $this->assertContains(__('installer.errors.env_lock_directory_writable'), $failures);
            $this->assertNotContains(__('installer.errors.env_directory_writable'), $failures);
            $this->assertSame($before, file_get_contents($environment));
            $this->assertFileDoesNotExist($lock);
        } finally {
            chmod($readonly, 0750);
        }
    }

    public function test_permission_preflight_rejects_directory_at_each_existing_target_without_mutation(): void
    {
        $initialEnv = file_get_contents($this->testDirectory.'/.env');
        $targets = [
            'foodex.installer_env_path' => $this->testDirectory.'/env-as-directory',
            'foodex.installer_env_lock_path' => $this->testDirectory.'/env-lock-as-directory',
            'foodex.install_lock' => $this->testDirectory.'/installed-lock-as-directory',
            'foodex.install_progress' => $this->testDirectory.'/progress-as-directory',
            'foodex.installer_token_lock_path' => $this->testDirectory.'/token-lock-as-directory',
        ];
        foreach ($targets as $target) {
            mkdir($target, 0750);
        }
        config($targets);

        $failures = app(InstallerWorkflow::class)->writablePathFailures();

        foreach ([
            'env_directory_writable',
            'env_lock_directory_writable',
            'install_state_directory_writable',
            'install_progress_directory_writable',
            'installer_token_lock_directory_writable',
        ] as $key) {
            $this->assertContains(__('installer.errors.'.$key), $failures);
        }
        $this->assertSame($initialEnv, file_get_contents($this->testDirectory.'/.env'));
        foreach ($targets as $target) {
            $this->assertDirectoryExists($target);
        }
    }

    public function test_permission_preflight_rejects_unreadable_existing_env_and_progress_and_readonly_locks(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('POSIX file modes required.');
        }

        $env = $this->testDirectory.'/.env';
        $progress = $this->testDirectory.'/existing-progress.json';
        $envLock = $this->testDirectory.'/existing-env.lock';
        $tokenLock = $this->testDirectory.'/existing-token.lock';
        foreach ([$progress, $envLock, $tokenLock] as $path) {
            file_put_contents($path, 'sentinel');
        }
        $before = file_get_contents($env);
        config([
            'foodex.install_progress' => $progress,
            'foodex.installer_env_lock_path' => $envLock,
            'foodex.installer_token_lock_path' => $tokenLock,
        ]);

        try {
            foreach ([$env, $progress] as $path) {
                chmod($path, 0000);
            }
            foreach ([$envLock, $tokenLock] as $path) {
                chmod($path, 0444);
            }
            $failures = app(InstallerWorkflow::class)->writablePathFailures();
            foreach ([
                'env_directory_writable',
                'env_lock_directory_writable',
                'install_progress_directory_writable',
                'installer_token_lock_directory_writable',
            ] as $key) {
                $this->assertContains(__('installer.errors.'.$key), $failures);
            }
        } finally {
            foreach ([$env, $progress, $envLock, $tokenLock] as $path) {
                chmod($path, 0640);
            }
        }

        $this->assertSame($before, file_get_contents($env));
        foreach ([$progress, $envLock, $tokenLock] as $path) {
            $this->assertSame('sentinel', file_get_contents($path));
        }
    }

    public function test_permission_preflight_rejects_multiply_hardlinked_lock_without_data_mutation(): void
    {
        if (! function_exists('link')) {
            $this->markTestSkipped('Hardlinks unavailable.');
        }
        $original = $this->testDirectory.'/original-private-lock';
        $linked = $this->testDirectory.'/hardlinked-private-lock';
        file_put_contents($original, 'untouched');
        if (! @link($original, $linked)) {
            $this->markTestSkipped('Filesystem does not support hardlinks.');
        }
        config(['foodex.installer_env_lock_path' => $linked]);

        $this->assertContains(
            __('installer.errors.env_lock_directory_writable'),
            app(InstallerWorkflow::class)->writablePathFailures(),
        );
        $this->assertSame('untouched', file_get_contents($original));
        $this->assertSame('untouched', file_get_contents($linked));
    }

    public function test_permission_preflight_accepts_regular_existing_private_files_without_modification(): void
    {
        $progress = $this->testDirectory.'/existing-progress.json';
        $envLock = $this->testDirectory.'/existing-env.lock';
        $tokenLock = $this->testDirectory.'/existing-token.lock';
        foreach ([$progress, $envLock, $tokenLock] as $path) {
            file_put_contents($path, 'untouched');
        }
        config([
            'foodex.install_progress' => $progress,
            'foodex.installer_env_lock_path' => $envLock,
            'foodex.installer_token_lock_path' => $tokenLock,
        ]);
        $failures = app(InstallerWorkflow::class)->writablePathFailures();
        foreach ([
            'env_directory_writable',
            'env_lock_directory_writable',
            'install_progress_directory_writable',
            'installer_token_lock_directory_writable',
        ] as $key) {
            $this->assertNotContains(__('installer.errors.'.$key), $failures);
        }
        foreach ([$progress, $envLock, $tokenLock] as $path) {
            $this->assertSame('untouched', file_get_contents($path));
        }
    }

    public function test_permission_preflight_rejects_environment_and_lock_symlinks_without_mutation(): void
    {
        if (! function_exists('symlink')) {
            $this->markTestSkipped('Symlinks are not supported.');
        }

        $environmentPath = $this->testDirectory.'/.env';
        $before = file_get_contents($environmentPath);
        $environmentLink = $this->testDirectory.'/env-link';
        $lockTarget = $this->testDirectory.'/lock-target';
        $lockLink = $this->testDirectory.'/env-lock-link';
        file_put_contents($lockTarget, 'lock-sentinel');

        if (! @symlink($environmentPath, $environmentLink) || ! @symlink($lockTarget, $lockLink)) {
            $this->markTestSkipped('Filesystem cannot create symlinks.');
        }

        config([
            'foodex.installer_env_path' => $environmentLink,
            'foodex.installer_env_lock_path' => $lockLink,
        ]);

        $failures = app(InstallerWorkflow::class)->writablePathFailures();
        $this->assertContains(__('installer.errors.env_directory_writable'), $failures);
        $this->assertContains(__('installer.errors.env_lock_directory_writable'), $failures);
        $this->assertSame($before, file_get_contents($environmentPath));
        $this->assertSame('lock-sentinel', file_get_contents($lockTarget));
        $this->assertTrue(is_link($environmentLink));
        $this->assertTrue(is_link($lockLink));
    }

    public function test_permission_preflight_rejects_blocked_install_state_and_token_lock_parents_without_writing(): void
    {
        $envPath = $this->testDirectory.'/.env';
        $before = file_get_contents($envPath);
        $blocker = $this->testDirectory.'/blocked-state-parent';
        file_put_contents($blocker, 'keep-sentinel');

        config([
            'foodex.install_lock' => $blocker.'/installed.lock',
            'foodex.install_progress' => $blocker.'/progress.json',
            'foodex.installer_token_lock_path' => $blocker.'/locks/token.lock',
        ]);

        $failures = app(InstallerWorkflow::class)->writablePathFailures();

        foreach ([
            'install_state_directory_writable',
            'install_progress_directory_writable',
            'installer_token_lock_directory_writable',
        ] as $key) {
            $this->assertContains(__('installer.errors.'.$key), $failures);
        }
        $this->assertSame($before, file_get_contents($envPath));
        $this->assertSame('keep-sentinel', file_get_contents($blocker));
        $this->assertFileDoesNotExist($blocker.'/installed.lock');
    }

    public function test_permission_preflight_rejects_symlinked_installer_state_paths_without_mutation(): void
    {
        if (! function_exists('symlink')) {
            $this->markTestSkipped('Symlinks are not supported.');
        }

        $sentinel = $this->testDirectory.'/state-sentinel';
        file_put_contents($sentinel, 'do-not-touch');
        $links = [
            'foodex.install_lock' => $this->testDirectory.'/lock-link',
            'foodex.install_progress' => $this->testDirectory.'/progress-link',
            'foodex.installer_token_lock_path' => $this->testDirectory.'/token-link',
        ];
        foreach ($links as $link) {
            if (! @symlink($sentinel, $link)) {
                $this->markTestSkipped('Filesystem cannot create symlinks.');
            }
        }
        config($links);

        $failures = app(InstallerWorkflow::class)->writablePathFailures();

        foreach ([
            'install_state_directory_writable',
            'install_progress_directory_writable',
            'installer_token_lock_directory_writable',
        ] as $key) {
            $this->assertContains(__('installer.errors.'.$key), $failures);
        }
        $this->assertSame('do-not-touch', file_get_contents($sentinel));
        foreach ($links as $link) {
            $this->assertTrue(is_link($link));
        }
    }

    public function test_permission_preflight_accepts_creatable_state_directories_without_creating_them(): void
    {
        $root = $this->testDirectory.'/private-host-state';
        config([
            'foodex.install_lock' => $root.'/install/installed.lock',
            'foodex.install_progress' => $root.'/progress/install-progress.json',
            'foodex.installer_token_lock_path' => $root.'/locks/token.lock',
        ]);

        $failures = app(InstallerWorkflow::class)->writablePathFailures();

        foreach ([
            'install_state_directory_writable',
            'install_progress_directory_writable',
            'installer_token_lock_directory_writable',
        ] as $key) {
            $this->assertNotContains(__('installer.errors.'.$key), $failures);
        }
        $this->assertDirectoryDoesNotExist($root);
    }

    public function test_fresh_seed_preserves_admin_managed_global_flags_without_creating_demo_business_data(): void
    {
        // Disposable RefreshDatabase fixture only: never reseed a live site.
        $workflow = app(InstallerWorkflow::class);
        $flags = app(CommercialFeatureFlags::class);
        $workflow->seedRequiredCoreData();

        $this->assertSame([
            'commercial_rules_enabled' => true,
            'flash_offers_enabled' => true,
            'customer_flash_popup_enabled' => true,
            'van_offers_enabled' => true,
        ], $flags->snapshot());

        // Same authoritative service used by the existing Admin feature-flags form.
        $overrides = [
            'commercial_rules_enabled' => false,
            'flash_offers_enabled' => false,
            'customer_flash_popup_enabled' => false,
            'van_offers_enabled' => false,
        ];
        $this->assertSame($overrides, $flags->persist($overrides));
        $snapshot = DB::table('settings')->whereNull('store_id')->orderBy('key')
            ->get(['id', 'key', 'value', 'is_secret'])->toArray();

        // A repeated pre-install step on isolated test storage must not revert
        // operator-owned overrides or invent business entities.
        $workflow->seedRequiredCoreData();

        $this->assertSame($overrides, $flags->snapshot());
        $this->assertEquals($snapshot, DB::table('settings')->whereNull('store_id')->orderBy('key')
            ->get(['id', 'key', 'value', 'is_secret'])->toArray());
        foreach (['users', 'stores', 'products', 'orders'] as $table) {
            $this->assertSame(0, DB::table($table)->count(), 'Unexpected demo data in '.$table);
        }
    }

    public function test_direct_core_seed_refuses_existing_operator_and_preserves_admin_changes(): void
    {
        // This fixture is disposable. Never use db:seed to audit a live tenant.
        $workflow = app(InstallerWorkflow::class);
        $workflow->seedRequiredCoreData();

        $flags = app(CommercialFeatureFlags::class);
        $adminSettings = [
            'commercial_rules_enabled' => false,
            'flash_offers_enabled' => false,
            'customer_flash_popup_enabled' => false,
            'van_offers_enabled' => false,
        ];
        $flags->persist($adminSettings);

        $roleId = (int) DB::table('roles')->where('code', 'B2B_ADMIN')->value('id');
        $this->assertGreaterThan(0, $roleId);
        $beforeGrants = DB::table('permission_role')->where('role_id', $roleId)
            ->orderBy('permission_id')->pluck('permission_id')->all();

        DB::table('users')->insert([
            'name' => 'Existing Operator',
            'email' => 'untouched-seed-operator@example.test',
            'password' => 'not-an-actual-password-hash',
            'locale' => 'en',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Artisan::shouldReceive('call')->never();

        try {
            $workflow->seedRequiredCoreData();
            $this->fail('A live database must never be reseeded through /install.');
        } catch (\RuntimeException $exception) {
            $this->assertSame((string) __('installer.errors.existing_business_data'), $exception->getMessage());
        }

        $this->assertDatabaseHas('users', [
            'email' => 'untouched-seed-operator@example.test',
            'password' => 'not-an-actual-password-hash',
        ]);
        $this->assertSame($beforeGrants, DB::table('permission_role')->where('role_id', $roleId)
            ->orderBy('permission_id')->pluck('permission_id')->all());
        $this->assertSame($adminSettings, $flags->snapshot());
    }

    public function test_fresh_setup_refuses_existing_store_master_without_reseeding_or_mutating_settings(): void
    {
        // Disposable test database only: an existing store is genuine business
        // master data even when no user/order/warehouse/product has been added.
        $storeTypeId = DB::table('store_types')->insertGetId([
            'code' => 'B2B',
            'name' => 'Wholesale',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('stores')->insert([
            'store_type_id' => $storeTypeId,
            'code' => 'SANDBOX_EXISTING',
            'name' => 'Existing sandbox store',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('settings')->insert([
            'store_id' => null,
            'key' => 'commercial_rules_enabled',
            'value' => 'false',
            'is_secret' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $storeBefore = DB::table('stores')->first();
        $settingBefore = DB::table('settings')->where('key', 'commercial_rules_enabled')->first();
        $rolesBefore = DB::table('roles')->orderBy('id')->get()->all();

        Artisan::shouldReceive('call')->never();

        foreach (['testDatabaseConnection', 'seedRequiredCoreData'] as $method) {
            try {
                app(InstallerWorkflow::class)->{$method}();
                $this->fail('Existing store master data must block '.$method.' before setup mutation.');
            } catch (\RuntimeException $exception) {
                $this->assertSame((string) __('installer.errors.existing_business_data'), $exception->getMessage());
            }
        }

        $this->assertEquals($storeBefore, DB::table('stores')->first());
        $this->assertEquals($settingBefore, DB::table('settings')->where('key', 'commercial_rules_enabled')->first());
        $this->assertEquals($rolesBefore, DB::table('roles')->orderBy('id')->get()->all());
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_fresh_install_seed_refuses_existing_supplier_without_touching_admin_configuration(): void
    {
        // Disposable SQLite fixture: no real tenant or production seeding.
        $organizationId = DB::table('enterprise_organizations')->value('id');
        $companyId = DB::table('enterprise_companies')->value('id');
        $this->assertNotNull($organizationId);
        $this->assertNotNull($companyId);

        DB::table('enterprise_suppliers')->insert([
            'organization_id' => $organizationId,
            'company_id' => $companyId,
            'scope_type' => 'company',
            'code' => 'TEST_VENDOR',
            'name_en' => 'Sandbox supplier',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('settings')->insert([
            'store_id' => null,
            'key' => 'commercial_rules_enabled',
            'value' => 'false',
            'is_secret' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $supplierBefore = DB::table('enterprise_suppliers')->first();
        $settingBefore = DB::table('settings')->where('key', 'commercial_rules_enabled')->first();
        $rolesBefore = DB::table('roles')->orderBy('id')->get()->all();
        Artisan::shouldReceive('call')->never();

        try {
            app(InstallerWorkflow::class)->seedRequiredCoreData();
            $this->fail('An existing supplier must block a new install even if users and orders are empty.');
        } catch (\RuntimeException $exception) {
            $this->assertSame((string) __('installer.errors.existing_business_data'), $exception->getMessage());
        }

        $this->assertEquals($supplierBefore, DB::table('enterprise_suppliers')->first());
        $this->assertEquals($settingBefore, DB::table('settings')->where('key', 'commercial_rules_enabled')->first());
        $this->assertEquals($rolesBefore, DB::table('roles')->orderBy('id')->get()->all());
    }

    public function test_fresh_install_seed_refuses_existing_finance_credit_terms_without_overwriting(): void
    {
        $organizationId = DB::table('enterprise_organizations')->value('id');
        $companyId = DB::table('enterprise_companies')->value('id');
        $legalEntityId = DB::table('enterprise_legal_entities')->value('id');
        $this->assertNotNull($organizationId);
        $this->assertNotNull($companyId);
        $this->assertNotNull($legalEntityId);

        DB::table('enterprise_finance_credit_terms')->insert([
            'organization_id' => $organizationId,
            'company_id' => $companyId,
            'legal_entity_id' => $legalEntityId,
            'subject_type' => 'customer',
            'subject_reference' => 'sandbox-customer',
            'credit_limit' => '100.000',
            'currency_code' => 'EGP',
            'payment_terms_days' => 15,
            'valid_from' => '2026-01-01',
            'status' => 'inactive',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $before = DB::table('enterprise_finance_credit_terms')->first();
        Artisan::shouldReceive('call')->never();

        try {
            app(InstallerWorkflow::class)->seedRequiredCoreData();
            $this->fail('Existing enterprise credit terms must block a fresh install before any seed.');
        } catch (\RuntimeException $exception) {
            $this->assertSame((string) __('installer.errors.existing_business_data'), $exception->getMessage());
        }

        $this->assertEquals($before, DB::table('enterprise_finance_credit_terms')->first());
    }

    public function test_direct_seed_refuses_configured_application_key_without_mutating_reference_data(): void
    {
        app(InstallerEnvironment::class)->write([
            'APP_KEY' => 'base64:'.base64_encode(str_repeat('K', 32)),
        ]);
        // Database test bootstrapping may pre-seed canonical roles. The
        // invariant is preservation of the exact existing data, not zero rows.
        $rolesBefore = DB::table('roles')->orderBy('id')->get()->all();
        $permissionsBefore = DB::table('permissions')->orderBy('id')->get()->all();
        $grantsBefore = DB::table('permission_role')
            ->orderBy('role_id')->orderBy('permission_id')->get()->all();
        $usersBefore = DB::table('users')->orderBy('id')->get()->all();

        Artisan::shouldReceive('call')->never();

        try {
            app(InstallerWorkflow::class)->seedRequiredCoreData();
            $this->fail('Configured sites must not be treated as first-install seeds.');
        } catch (\RuntimeException $exception) {
            $this->assertSame((string) __('installer.environment_already_configured'), $exception->getMessage());
        }

        $this->assertEquals($rolesBefore, DB::table('roles')->orderBy('id')->get()->all());
        $this->assertEquals($permissionsBefore, DB::table('permissions')->orderBy('id')->get()->all());
        $this->assertEquals($grantsBefore, DB::table('permission_role')
            ->orderBy('role_id')->orderBy('permission_id')->get()->all());
        $this->assertEquals($usersBefore, DB::table('users')->orderBy('id')->get()->all());
    }

    public function test_direct_seed_refuses_completed_installer_lock_without_db_writes(): void
    {
        app(InstallState::class)->writeLock('1.0.0');
        Artisan::shouldReceive('call')->never();

        $this->expectException(HttpException::class);
        $this->expectExceptionCode(0);

        app(InstallerWorkflow::class)->seedRequiredCoreData();
    }

    public function test_storage_configuration_rejects_foreign_symlink_without_changing_environment(): void
    {
        if (! function_exists('symlink')) {
            $this->markTestSkipped('Symlinks are not supported on this PHP installation.');
        }

        $originalPublicPath = app()->publicPath();
        $originalStoragePath = app()->storagePath();
        $publicPath = $this->testDirectory.'/cpanel-public';
        $storagePath = $this->testDirectory.'/cpanel-storage';
        $foreignPath = $this->testDirectory.'/cpanel-foreign';

        mkdir($publicPath, 0750, true);
        mkdir($storagePath.'/app/public', 0750, true);
        mkdir($foreignPath, 0750, true);

        $link = $publicPath.'/storage';
        if (! @symlink($foreignPath, $link)) {
            $this->markTestSkipped('The current filesystem cannot create symlinks.');
        }

        app()->usePublicPath($publicPath);
        app()->useStoragePath($storagePath);

        try {
            $environment = app(InstallerEnvironment::class);
            $before = file_get_contents($environment->path());

            try {
                app(InstallerWorkflow::class)->saveStorageConfiguration('public');
                $this->fail('A foreign public/storage symlink must not be accepted.');
            } catch (\RuntimeException $exception) {
                $this->assertSame(__('installer.public_storage_link_invalid'), $exception->getMessage());
            }

            $this->assertSame($before, file_get_contents($environment->path()));
            $this->assertTrue(is_link($link));
            $this->assertSame(realpath($foreignPath), realpath($link));
        } finally {
            app()->usePublicPath($originalPublicPath);
            app()->useStoragePath($originalStoragePath);
        }
    }

    public function test_installer_exposes_exactly_fifteen_ordered_steps(): void
    {
        $this->assertCount(15, InstallerChecklist::steps());
        $this->assertSame('Welcome', InstallerChecklist::step(1)['title']);
        $this->assertSame('Finish', InstallerChecklist::step(15)['title']);

        $this->get('/install')
            ->assertOk()
            ->assertSee('FOODEX')
            ->assertSee('مرحبًا')
            ->assertSee('إنهاء التثبيت');
    }

    public function test_database_connection_allows_empty_fresh_install_database(): void
    {
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('system_versions', 0);

        app(InstallerWorkflow::class)->testDatabaseConnection();

        $this->assertDatabaseCount('users', 0);
    }

    public function test_database_connection_rejects_existing_users_without_install_lock(): void
    {
        $this->assertFalse(app(InstallState::class)->isInstalled());
        DB::table('users')->insert([
            'name' => 'Existing Operator',
            'email' => 'existing-operator@example.test',
            'password' => 'existing-hash-not-for-auth',
            'locale' => 'en',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage((string) __('installer.errors.existing_business_data'));
        app(InstallerWorkflow::class)->testDatabaseConnection();
    }

    public function test_database_connection_rejects_existing_version_without_install_lock(): void
    {
        $this->assertFalse(app(InstallState::class)->isInstalled());
        DB::table('system_versions')->insert([
            'version' => '99.0.0',
            'installed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage((string) __('installer.errors.existing_business_data'));
        app(InstallerWorkflow::class)->testDatabaseConnection();
    }

    public function test_migrations_never_run_against_database_with_existing_user_data(): void
    {
        DB::table('users')->insert([
            'name' => 'Existing Operator',
            'email' => 'existing-before-migrate@example.test',
            'password' => 'existing-hash-not-for-auth',
            'locale' => 'ar',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Artisan::shouldReceive('call')->never();

        try {
            app(InstallerWorkflow::class)->runMigrations();
            $this->fail('Migrations must reject an existing FOODEX database.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString((string) __('installer.errors.existing_business_data'), $exception->getMessage());
        }
        $this->assertDatabaseHas('users', [
            'email' => 'existing-before-migrate@example.test',
            'password' => 'existing-hash-not-for-auth',
        ]);
    }

    public function test_failed_migration_records_only_redacted_first_failure_and_never_retries(): void
    {
        Artisan::shouldReceive('call')->once()
            ->with('migrate', ['--force' => true], \Mockery::type(BufferedOutput::class))
            ->andReturnUsing(static function (string $command, array $options, BufferedOutput $output): int {
                $output->writeln('2026_09_24_000100_create_identity_store_foundation FAILED SQLSTATE[42S01] password=secret-should-not-appear');

                return 1;
            });

        Log::shouldReceive('error')->once()
            ->with('Installer migration failed', [
                'sqlstate' => '42S01',
                'migration' => '2026_09_24_000100_create_identity_store_foundation',
            ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage((string) __('installer.errors.migrations'));

        app(InstallerWorkflow::class)->runMigrations();
    }

    public function test_thrown_migration_exception_records_only_redacted_first_failure_and_never_retries(): void
    {
        Artisan::shouldReceive('call')->once()
            ->with('migrate', ['--force' => true], \Mockery::type(BufferedOutput::class))
            ->andThrow(new \RuntimeException(
                'SQLSTATE[42S02]: Base table or view not found: 1146 2026_09_24_000100_create_identity_store_foundation password=secret-should-not-appear'
            ));

        Log::shouldReceive('error')->once()
            ->with('Installer migration failed', [
                'sqlstate' => '42S02',
                'migration' => '2026_09_24_000100_create_identity_store_foundation',
            ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage((string) __('installer.errors.migrations'));

        app(InstallerWorkflow::class)->runMigrations();
    }

    public function test_database_step_refuses_to_replace_unlocked_live_environment_and_preserves_secrets(): void
    {
        $environment = app(InstallerEnvironment::class);
        $environment->write([
            'APP_ENV' => 'production',
            'APP_KEY' => 'base64:'.base64_encode(random_bytes(32)),
            'DB_CONNECTION' => 'mysql',
            'DB_HOST' => 'previous-production-host.invalid',
            'DB_DATABASE' => 'live_foodex',
            'DB_USERNAME' => 'previous_owner',
            'DB_PASSWORD' => 'previous-secret-test-only',
        ]);
        $original = file_get_contents($environment->path());
        $this->assertFalse(app(InstallState::class)->isInstalled());

        $state = app(InstallState::class);
        $state->markStepComplete(3);
        $this->post('/install/step/4', [
            'installer_token' => $state->token(),
            'db_host' => 'attacker.invalid',
            'db_port' => 3306,
            'db_database' => 'new_database',
            'db_username' => 'new_operator',
            'db_password' => 'new-password-test-only',
        ])->assertStatus(422)
            ->assertSee(__('installer.environment_already_configured'), false)
            ->assertDontSee('previous-secret-test-only')
            ->assertDontSee('new-password-test-only');

        $this->assertSame($original, file_get_contents($environment->path()));
        $this->assertSame(3, $state->progress()['completed_step']);
    }

    public function test_database_configuration_refuses_nonempty_app_key_even_when_env_is_not_production(): void
    {
        app()->setLocale('en');
        $environment = app(InstallerEnvironment::class);
        $environment->write([
            'APP_ENV' => 'local',
            'APP_KEY' => 'base64:'.base64_encode(random_bytes(32)),
        ]);
        $original = file_get_contents($environment->path());

        try {
            app(InstallerWorkflow::class)->saveDatabaseConfiguration([
                'host' => 'localhost',
                'port' => 3306,
                'database' => 'new_foodex',
                'username' => 'fresh_user',
                'password' => 'new-password-test-only',
            ]);
            $this->fail('An existing APP_KEY must protect .env before DB configuration writes.');
        } catch (\RuntimeException $exception) {
            $this->assertSame(__('installer.environment_already_configured'), $exception->getMessage());
        }

        $this->assertSame($original, file_get_contents($environment->path()));
    }

    public function test_unlocked_production_env_with_empty_key_rejects_existing_database_data_before_write(): void
    {
        DB::table('users')->insert([
            'name' => 'Existing Live Operator',
            'email' => 'existing-unlocked-user@example.test',
            'password' => 'existing-hash-not-for-auth',
            'locale' => 'ar',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $environment = app(InstallerEnvironment::class);
        $environment->write(['APP_ENV' => 'production', 'APP_KEY' => '']);
        $original = file_get_contents($environment->path());

        try {
            app(InstallerWorkflow::class)->saveDatabaseConfiguration([
                'host' => 'localhost',
                'port' => 3306,
                'database' => 'new_foodex',
                'username' => 'fresh_user',
                'password' => 'new-password-test-only',
            ]);
            $this->fail('An unlocked production .env with existing FOODEX user data must not be replaced.');
        } catch (\RuntimeException $exception) {
            $this->assertSame(__('installer.environment_already_configured'), $exception->getMessage());
        }

        $this->assertSame($original, file_get_contents($environment->path()));
    }

    public function test_clean_production_setup_template_with_empty_key_can_save_database_settings(): void
    {
        $environment = app(InstallerEnvironment::class);
        $environment->write(['APP_ENV' => 'production', 'APP_KEY' => '']);
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('system_versions', 0);

        app(InstallerWorkflow::class)->saveDatabaseConfiguration([
            'host' => 'localhost',
            'port' => 3306,
            'database' => 'foodex_fresh_setup',
            'username' => 'setup_operator',
            'password' => 'test-only-password',
        ]);

        $stored = $environment->read(['APP_ENV', 'APP_KEY', 'DB_CONNECTION', 'DB_DATABASE', 'DB_USERNAME']);
        $this->assertSame('production', $stored['APP_ENV']);
        $this->assertSame('', $stored['APP_KEY']);
        $this->assertSame('mysql', $stored['DB_CONNECTION']);
        $this->assertSame('foodex_fresh_setup', $stored['DB_DATABASE']);
        $this->assertSame('setup_operator', $stored['DB_USERNAME']);
    }

    public function test_database_configuration_persists_mysql_production_defaults(): void
    {
        $workflow = app(InstallerWorkflow::class);
        $workflow->saveDatabaseConfiguration([
            'host' => 'localhost',
            'port' => 3306,
            'database' => 'solscool_foodex',
            'username' => 'solscool_foodex',
            'password' => 'test-only-password',
        ]);

        $values = app(InstallerEnvironment::class)->read([
            'DB_CONNECTION', 'DB_HOST', 'DB_PORT', 'DB_DATABASE', 'DB_USERNAME',
        ]);

        $this->assertSame('mysql', $values['DB_CONNECTION']);
        $this->assertSame('localhost', $values['DB_HOST']);
        $this->assertSame('3306', $values['DB_PORT']);
        $this->assertSame('solscool_foodex', $values['DB_DATABASE']);
        $this->assertSame('solscool_foodex', $values['DB_USERNAME']);
    }

    public function test_installer_post_requires_the_progress_token(): void
    {
        $this->post('/install/step/1')->assertStatus(419);
        $this->assertFalse(app(InstallState::class)->isInstalled());
    }

    public function test_step_validation_blocks_invalid_super_admin_input(): void
    {
        app(InstallState::class)->markStepComplete(6);

        $state = app(InstallState::class);

        $this->post('/install/step/7?lang=en', ['installer_token' => $state->token()])
            ->assertStatus(422)
            ->assertSee('The Name field is required.', false)
            ->assertSee('The Email field is required.', false)
            ->assertSee('The Password field is required.', false);

        $this->assertFalse(app(InstallState::class)->isInstalled());
    }

    public function test_failed_finish_does_not_write_install_lock(): void
    {
        app(InstallState::class)->markStepComplete(14);

        $state = app(InstallState::class);

        $this->post('/install/step/15?lang=en', ['installer_token' => $state->token()])
            ->assertStatus(422)
            ->assertSee('Super Admin setup is incomplete', false);

        $this->assertFalse(app(InstallState::class)->isInstalled());
        $this->assertDatabaseCount('system_versions', 0);
    }

    public function test_successful_finish_creates_super_admin_version_and_lock(): void
    {
        $workflow = app(InstallerWorkflow::class);
        $state = app(InstallState::class);

        $workflow->runMigrations();
        $workflow->seedRequiredCoreData();
        $workflow->generateApplicationKey();
        $this->assertSame([
            'database' => true,
            'storage' => true,
            'cache' => true,
            'queue' => true,
        ], $workflow->healthCheck());

        $admin = $workflow->prepareSuperAdmin([
            'name' => 'Platform Owner',
            'email' => 'owner@example.com',
            'password' => 'SecurePassword!123',
            'locale' => 'ar',
        ]);
        $state->markStepComplete(14, $admin);

        $this->post('/install/step/15', ['installer_token' => $state->token()])
            ->assertRedirect(route('admin.b2c.login'));

        $version = trim((string) file_get_contents(base_path('../VERSION')));
        $this->assertTrue($state->isInstalled());
        $this->assertDatabaseHas('system_versions', ['version' => $version]);
        $this->assertDatabaseHas('users', ['email' => 'owner@example.com', 'is_active' => 1]);
        $this->assertDatabaseHas('audit_logs', ['event' => 'installer.completed']);

        $user = DB::table('users')->where('email', 'owner@example.com')->first();
        $this->assertNotNull($user);
        $this->assertTrue(Hash::check('SecurePassword!123', (string) $user->password));

        $superAdminRoleId = DB::table('roles')->where('code', 'SUPER_ADMIN')->value('id');
        $this->assertDatabaseHas('role_user', [
            'user_id' => $user->id,
            'role_id' => $superAdminRoleId,
        ]);

        $this->get('/install')->assertNotFound();
        $this->post('/install/step/1', ['installer_token' => 'invalid'])->assertNotFound();
    }
}
