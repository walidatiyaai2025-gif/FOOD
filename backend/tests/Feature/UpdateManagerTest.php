<?php

namespace Tests\Feature;

use App\Domain\Updater\UpdateManager;
use App\Domain\Updater\UpdatePackageManifest;
use App\Domain\Updater\UpdatePackageValidator;
use App\Domain\Updater\UpdateRuntime;
use App\Services\AuditLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class UpdateManagerTest extends TestCase
{
    use RefreshDatabase;

    private string $packagePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->packagePath = tempnam(sys_get_temp_dir(), 'foodex-update-manager-');
        file_put_contents($this->packagePath, 'validated update payload');
    }

    protected function tearDown(): void
    {
        @unlink($this->packagePath);

        parent::tearDown();
    }

    public function test_success_pipeline_records_history_version_and_audit(): void
    {
        $runtime = new RecordingUpdateRuntime;
        $manager = new UpdateManager(
            new UpdatePackageValidator,
            $runtime,
            app(AuditLogger::class),
        );

        $manifest = $this->manifest(true);
        $history = $manager->execute($manifest, '1.0.0', $this->packagePath);

        $this->assertSame('success', $history->status);
        $this->assertSame([
            'preflight',
            'backup_files',
            'backup_database',
            'enter_maintenance_mode',
            'extract_release',
            'run_migrations',
            'rebuild_caches',
            'health_check',
            'exit_maintenance_mode',
        ], $runtime->calls);

        $this->assertDatabaseHas('system_versions', [
            'version' => '1.1.0',
            'package_hash' => hash_file('sha256', $this->packagePath),
        ]);
        $this->assertDatabaseHas('update_history', [
            'to_version' => '1.1.0',
            'status' => 'success',
        ]);
        $this->assertDatabaseHas('audit_logs', ['event' => 'updater.completed']);
    }

    public function test_failure_runs_safe_rollback_and_records_generic_failure(): void
    {
        $runtime = new RecordingUpdateRuntime('health_check');
        $manager = new UpdateManager(
            new UpdatePackageValidator,
            $runtime,
            app(AuditLogger::class),
        );

        try {
            $manager->execute($this->manifest(true), '1.0.0', $this->packagePath);
            $this->fail('The update should have failed.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('health_check', $exception->getMessage());
        }

        $this->assertSame([
            'preflight',
            'backup_files',
            'backup_database',
            'enter_maintenance_mode',
            'extract_release',
            'run_migrations',
            'rebuild_caches',
            'health_check',
            'rollback_files',
            'rollback_database',
            'exit_maintenance_mode',
        ], $runtime->calls);

        $this->assertDatabaseHas('update_history', [
            'to_version' => '1.1.0',
            'status' => 'failed',
            'failure_reason' => 'Update failed during [health_check]. Rollback was attempted.',
        ]);
        $this->assertDatabaseMissing('system_versions', ['version' => '1.1.0']);
        $this->assertDatabaseHas('audit_logs', ['event' => 'updater.failed']);
    }

    private function manifest(bool $containsMigrations): UpdatePackageManifest
    {
        return new UpdatePackageManifest(
            '1.1.0',
            '1.0.0',
            hash_file('sha256', $this->packagePath),
            'Safe updater test release',
            $containsMigrations,
        );
    }
}

final class RecordingUpdateRuntime implements UpdateRuntime
{
    /**
     * @var array<int, string>
     */
    public array $calls = [];

    public function __construct(private readonly ?string $failAt = null) {}

    public function preflight(UpdatePackageManifest $manifest, string $packagePath): void
    {
        $this->record('preflight');
    }

    public function backupFiles(string $packagePath): string
    {
        $this->record('backup_files');

        return 'files-backup';
    }

    public function backupDatabase(bool $required): ?string
    {
        $this->record('backup_database');

        return $required ? 'database-backup' : null;
    }

    public function enterMaintenanceMode(): void
    {
        $this->record('enter_maintenance_mode');
    }

    public function extractRelease(string $packagePath): void
    {
        $this->record('extract_release');
    }

    public function runMigrations(): void
    {
        $this->record('run_migrations');
    }

    public function rebuildCaches(): void
    {
        $this->record('rebuild_caches');
    }

    public function healthCheck(): void
    {
        $this->record('health_check');
    }

    public function rollbackFiles(string $backupReference): void
    {
        $this->record('rollback_files');
    }

    public function rollbackDatabase(string $backupReference): void
    {
        $this->record('rollback_database');
    }

    public function exitMaintenanceMode(): void
    {
        $this->record('exit_maintenance_mode');
    }

    private function record(string $stage): void
    {
        $this->calls[] = $stage;

        if ($this->failAt === $stage) {
            throw new RuntimeException('Simulated update runtime failure.');
        }
    }
}
