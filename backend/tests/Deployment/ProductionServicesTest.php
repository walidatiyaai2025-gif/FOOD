<?php

namespace Tests\Deployment;

use App\Domain\Installer\InstallerWorkflow;
use App\Domain\Installer\InstallState;
use App\Domain\Updater\UpdateManager;
use App\Domain\Updater\UpdatePackageManifest;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\TestCase;
use ZipArchive;

class ProductionServicesTest extends TestCase
{
    private string $directory;

    private string $originalVersion;

    private string $migrationPath;

    protected function setUp(): void
    {
        parent::setUp();

        // This suite is destructive and only runs against an explicitly isolated CI database.
        $this->assertSame('true', getenv('FOODEX_DISPOSABLE_SERVICES'));
        $this->assertSame('pgsql', DB::connection()->getDriverName());
        $this->assertSame('foodex_acceptance', DB::connection()->getDatabaseName());

        $this->directory = storage_path('framework/testing/deployment-'.bin2hex(random_bytes(6)));
        File::ensureDirectoryExists($this->directory);
        $this->originalVersion = (string) file_get_contents(base_path('../VERSION'));
        $this->migrationPath = database_path('migrations/2999_01_01_000000_deployment_failure_probe.php');
        $this->assertFileDoesNotExist($this->migrationPath);
        copy(base_path('.env'), $this->directory.'/.env');
        config([
            'foodex.installer_env_path' => $this->directory.'/.env',
            'foodex.install_lock' => $this->directory.'/installed.lock',
            'foodex.install_progress' => $this->directory.'/install-progress.json',
            'foodex.update_backup_path' => $this->directory.'/backups',
        ]);
        $this->assertSame(0, Artisan::call('migrate:fresh', ['--force' => true]));
    }

    protected function tearDown(): void
    {
        if (isset($this->originalVersion)) {
            file_put_contents(base_path('../VERSION'), $this->originalVersion);
            File::delete($this->migrationPath);
            Artisan::call('up');
            Artisan::call('optimize:clear');
        }
        if (isset($this->directory)) {
            File::deleteDirectory($this->directory);
        }

        parent::tearDown();
    }

    public function test_install_upgrade_and_migration_failure_restore_real_services(): void
    {
        $workflow = app(InstallerWorkflow::class);
        $workflow->saveDatabaseConfiguration([
            'host' => '127.0.0.1',
            'port' => '5432',
            'database' => 'foodex_acceptance',
            'username' => 'foodex',
            'password' => 'ci-only-password',
        ]);
        $workflow->saveCacheQueueConfiguration([
            'cache' => 'redis', 'queue' => 'redis',
            'redis_host' => '127.0.0.1', 'redis_port' => '6379',
        ]);
        $workflow->testDatabaseConnection();
        $workflow->runMigrations();
        $workflow->seedRequiredCoreData();
        $workflow->seedRequiredCoreData();
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('products', 0);
        $this->assertFalse(app(InstallState::class)->isInstalled());
        $this->assertSame([
            'database' => true, 'storage' => true, 'cache' => true, 'queue' => true,
        ], $workflow->healthCheck());

        $admin = $workflow->prepareSuperAdmin([
            'name' => 'Acceptance Owner', 'email' => 'owner@acceptance.test',
            'password' => 'Disposable-CI-only-123!', 'locale' => 'ar',
        ]);
        $installed = $workflow->finish($admin);
        $this->assertTrue(app(InstallState::class)->isInstalled());
        $this->assertDatabaseHas('audit_logs', ['event' => 'installer.completed']);
        $this->assertDatabaseCount('users', 1);

        Queue::connection('redis')->push(new DeploymentQueueProbe);
        $this->assertSame(0, Artisan::call('queue:work', [
            'connection' => 'redis', '--once' => true, '--tries' => 1, '--timeout' => 20,
        ]));
        $this->assertSame('processed', Cache::store('redis')->pull('deployment-queue-probe'));

        $package = $this->package('success.zip', ['VERSION' => "99.0.0\n"]);
        $history = app(UpdateManager::class)->execute(
            $this->manifest($package, '99.0.0', $installed), $installed, $package,
        );
        $this->assertSame('success', $history->status);
        $this->assertFalse(app()->isDownForMaintenance());
        $this->assertSame('99.0.0', trim((string) file_get_contents(base_path('../VERSION'))));

        $migration = <<<'PHP'
<?php
return new class extends \Illuminate\Database\Migrations\Migration {
    public $withinTransaction = false;
    public function up(): void {
        \Illuminate\Support\Facades\DB::table('users')->where('email', 'owner@acceptance.test')->update(['name' => 'Uncommitted release']);
        throw new \RuntimeException('Deliberate disposable migration failure');
    }
    public function down(): void {}
};
PHP;
        $failedPackage = $this->package('failure.zip', [
            'VERSION' => "99.0.1\n",
            'backend/database/migrations/'.basename($this->migrationPath) => $migration,
        ]);

        try {
            app(UpdateManager::class)->execute(
                $this->manifest($failedPackage, '99.0.1', '99.0.0'), '99.0.0', $failedPackage,
            );
            $this->fail('Injected migration failure must fail the update.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('run_migrations', $exception->getMessage());
        }

        $this->assertFalse(app()->isDownForMaintenance());
        $this->assertSame('99.0.0', trim((string) file_get_contents(base_path('../VERSION'))));
        $this->assertFileDoesNotExist($this->migrationPath);
        $this->assertDatabaseHas('users', ['email' => 'owner@acceptance.test', 'name' => 'Acceptance Owner']);
        $this->assertDatabaseMissing('system_versions', ['version' => '99.0.1']);
        $this->assertDatabaseHas('update_history', ['to_version' => '99.0.1', 'status' => 'failed']);
        $this->assertDatabaseHas('audit_logs', ['event' => 'updater.failed']);
        $this->assertNotEmpty(File::glob($this->directory.'/backups/*/database.dump'));
        $this->getJson('/api/v1/health')->assertOk();
    }

    /** @param array<string, string> $entries */
    private function package(string $name, array $entries): string
    {
        $path = $this->directory.'/'.$name;
        $archive = new ZipArchive;
        $this->assertTrue($archive->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE));
        foreach ($entries as $entry => $content) {
            $this->assertTrue($archive->addFromString($entry, $content));
        }
        $archive->close();

        return $path;
    }

    private function manifest(string $path, string $target, string $minimum): UpdatePackageManifest
    {
        return new UpdatePackageManifest($target, $minimum, hash_file('sha256', $path), 'Disposable acceptance fixture', true);
    }
}

class DeploymentQueueProbe implements ShouldQueue
{
    public function handle(): void
    {
        Cache::store('redis')->put('deployment-queue-probe', 'processed', 60);
    }
}
