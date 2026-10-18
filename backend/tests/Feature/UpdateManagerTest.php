<?php

namespace Tests\Feature;

use App\Domain\Updater\UpdateManager;
use App\Domain\Updater\UpdatePackageManifest;
use App\Domain\Updater\UpdatePackageValidator;
use App\Domain\Updater\UpdateRuntime;
use App\Models\UpdateHistory;
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
            'verify_database_backup',
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
            'environment' => 'testing',
            'backup_status' => 'available',
            'rollback_status' => 'not_required',
            'maintenance_mode_remained' => false,
        ]);
        $history->refresh();
        $this->assertNotNull($history->health_verified_at);
        $this->assertSame('passed', $history->execution_evidence['package_integrity'] ?? null);
        $this->assertSame('passed', $history->execution_evidence['preflight'] ?? null);
        $this->assertSame('passed', $history->execution_evidence['backup_files'] ?? null);
        $this->assertSame('passed', $history->execution_evidence['backup_database'] ?? null);
        $this->assertSame('passed', $history->execution_evidence['backup_verification'] ?? null);
        $this->assertSame('passed', $history->execution_evidence['migrations'] ?? null);
        $this->assertSame('passed', $history->execution_evidence['health_check'] ?? null);
        $this->assertSame('files-backup', $history->execution_evidence['file_backup']['reference'] ?? null);
        $this->assertSame('database-backup', $history->execution_evidence['database_backup']['reference'] ?? null);
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
            'verify_database_backup',
            'enter_maintenance_mode',
            'extract_release',
            'run_migrations',
            'rebuild_caches',
            'health_check',
            'rollback_files',
            'rollback_database',
            'health_check',
            'exit_maintenance_mode',
        ], $runtime->calls);

        $this->assertDatabaseHas('update_history', [
            'to_version' => '1.1.0',
            'status' => 'failed',
            'failed_stage' => 'health_check',
            'backup_status' => 'available',
            'rollback_status' => 'succeeded',
            'maintenance_mode_remained' => false,
            'failure_reason' => 'Update failed during [health_check]. Rollback was attempted.',
        ]);
        $failedHistory = UpdateHistory::query()->where('to_version', '1.1.0')->firstOrFail();
        $this->assertSame('failed', $failedHistory->execution_evidence['health_check'] ?? null);
        $this->assertSame('passed', $failedHistory->execution_evidence['rollback_files'] ?? null);
        $this->assertSame('passed', $failedHistory->execution_evidence['rollback_database'] ?? null);
        $this->assertSame('passed', $failedHistory->execution_evidence['rollback_health_check'] ?? null);
        $this->assertDatabaseMissing('system_versions', ['version' => '1.1.0']);
        $this->assertDatabaseHas('audit_logs', ['event' => 'updater.failed']);
    }

    public function test_failed_file_rollback_keeps_maintenance_enabled(): void
    {
        $this->assertFailedRollbackKeepsMaintenance('rollback_files');
    }

    public function test_failed_database_rollback_keeps_maintenance_enabled(): void
    {
        $this->assertFailedRollbackKeepsMaintenance('rollback_database');
    }

    private function assertFailedRollbackKeepsMaintenance(string $rollbackStage): void
    {
        $runtime = new RecordingUpdateRuntime('health_check', $rollbackStage);
        $manager = new UpdateManager(
            new UpdatePackageValidator,
            $runtime,
            app(AuditLogger::class),
        );

        try {
            $manager->execute($this->manifest(true), '1.0.0', $this->packagePath);
            $this->fail('An incomplete rollback must not report success.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('manual recovery is required', $exception->getMessage());
            $this->assertStringContainsString('Maintenance mode remains enabled', $exception->getMessage());
            $this->assertStringNotContainsString('Simulated update runtime failure', $exception->getMessage());
        }

        $this->assertContains('rollback_files', $runtime->calls);
        $this->assertContains('rollback_database', $runtime->calls);
        $this->assertNotContains('exit_maintenance_mode', $runtime->calls);
        $this->assertDatabaseHas('update_history', [
            'to_version' => '1.1.0',
            'status' => 'failed',
            'failed_stage' => 'health_check',
            'rollback_status' => 'incomplete',
            'maintenance_mode_remained' => true,
            'failure_reason' => 'Update failed during [health_check]. Rollback was attempted. Rollback is incomplete; manual recovery is required. Maintenance mode remains enabled.',
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

    private bool $primaryFailureTriggered = false;

    public function __construct(
        private readonly ?string $failAt = null,
        private readonly ?string $failRollbackAt = null,
    ) {}

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

    public function verifyDatabaseBackup(string $backupReference): void
    {
        $this->record('verify_database_backup');
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

        if ($this->failRollbackAt === $stage) {
            throw new RuntimeException('Simulated update runtime failure.');
        }

        if ($this->failAt === $stage && ! $this->primaryFailureTriggered) {
            $this->primaryFailureTriggered = true;
            throw new RuntimeException('Simulated update runtime failure.');
        }
    }
}
