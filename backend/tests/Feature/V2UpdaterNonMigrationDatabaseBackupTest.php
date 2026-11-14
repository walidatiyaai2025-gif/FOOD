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
use Tests\Support\UpdateZipFixture;
use Tests\TestCase;

final class V2UpdaterNonMigrationDatabaseBackupTest extends TestCase
{
    use RefreshDatabase;

    private string $packagePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->packagePath = tempnam(sys_get_temp_dir(), 'foodex-v2-no-migration-');
        UpdateZipFixture::write($this->packagePath);
    }

    protected function tearDown(): void
    {
        @unlink($this->packagePath);

        parent::tearDown();
    }

    public function test_migration_free_update_verifies_database_backup_before_mutation(): void
    {
        $runtime = new V2NonMigrationBackupRecordingRuntime;
        $history = $this->manager($runtime)->execute($this->manifest(), '1.0.0', $this->packagePath);

        $this->assertTrue($runtime->requiredBackupRequested);
        $this->assertSame([
            'preflight',
            'backup_files',
            'backup_database',
            'verify_database_backup',
            'enter_maintenance_mode',
            'extract_release',
            'rebuild_caches',
            'health_check',
            'exit_maintenance_mode',
        ], $runtime->calls);
        $this->assertSame('success', $history->status);
        $this->assertSame('available', $history->backup_status);
        $this->assertSame('passed', $history->execution_evidence['backup_database'] ?? null);
        $this->assertSame('passed', $history->execution_evidence['backup_verification'] ?? null);
        $this->assertSame('not_required', $history->execution_evidence['migrations'] ?? null);
        $this->assertSame('db-snapshot', $history->database_backup_reference);
        $this->assertDatabaseHas('audit_logs', ['event' => 'updater.completed']);
    }

    public function test_missing_required_snapshot_fails_before_maintenance_or_file_activation(): void
    {
        $runtime = new V2NonMigrationBackupRecordingRuntime(omitBackup: true);

        try {
            $this->manager($runtime)->execute($this->manifest(), '1.0.0', $this->packagePath);
            $this->fail('An update without a required database snapshot must abort.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString(
                'Database backup is required for every system update.',
                $exception->getMessage(),
            );
        }

        $this->assertTrue($runtime->requiredBackupRequested);
        $this->assertSame(['preflight', 'backup_files', 'backup_database'], $runtime->calls);

        $history = UpdateHistory::query()->where('to_version', '1.1.0')->firstOrFail();
        $this->assertSame('failed', $history->status);
        $this->assertSame('backup_database', $history->failed_stage);
        $this->assertSame('failed', $history->execution_evidence['backup_database'] ?? null);
        $this->assertSame('not_required', $history->rollback_status);
        $this->assertFalse((bool) $history->maintenance_mode_remained);
        $this->assertDatabaseMissing('system_versions', ['version' => '1.1.0']);
    }

    public function test_migration_free_cache_reconciliation_failure_restores_files_and_database(): void
    {
        $runtime = new V2NonMigrationBackupRecordingRuntime(failAt: 'rebuild_caches');

        try {
            $this->manager($runtime)->execute($this->manifest(), '1.0.0', $this->packagePath);
            $this->fail('Cache reconciliation failure must abort the update.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('Injected cache reconciliation failure.', $exception->getMessage());
        }

        $this->assertSame([
            'preflight',
            'backup_files',
            'backup_database',
            'verify_database_backup',
            'enter_maintenance_mode',
            'extract_release',
            'rebuild_caches',
            'rollback_files',
            'rollback_database',
            'health_check',
            'exit_maintenance_mode',
        ], $runtime->calls);

        $history = UpdateHistory::query()->where('to_version', '1.1.0')->firstOrFail();
        $this->assertSame('failed', $history->status);
        $this->assertSame('clear_and_rebuild_cache', $history->failed_stage);
        $this->assertSame('succeeded', $history->rollback_status);
        $this->assertSame('passed', $history->execution_evidence['rollback_files'] ?? null);
        $this->assertSame('passed', $history->execution_evidence['rollback_database'] ?? null);
        $this->assertSame('passed', $history->execution_evidence['rollback_health_check'] ?? null);
        $this->assertFalse((bool) $history->maintenance_mode_remained);
        $this->assertDatabaseMissing('system_versions', ['version' => '1.1.0']);
    }

    public function test_failed_database_restore_keeps_maintenance_and_requires_recovery(): void
    {
        $runtime = new V2NonMigrationBackupRecordingRuntime(
            failAt: 'rebuild_caches',
            failRollbackAt: 'rollback_database',
        );

        try {
            $this->manager($runtime)->execute($this->manifest(), '1.0.0', $this->packagePath);
            $this->fail('An incomplete database rollback must not be marked successful.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('manual recovery is required', $exception->getMessage());
            $this->assertStringContainsString('Maintenance mode remains enabled', $exception->getMessage());
        }

        $this->assertContains('rollback_files', $runtime->calls);
        $this->assertContains('rollback_database', $runtime->calls);
        $this->assertNotContains('exit_maintenance_mode', $runtime->calls);

        $history = UpdateHistory::query()->where('to_version', '1.1.0')->firstOrFail();
        $this->assertSame('failed', $history->status);
        $this->assertSame('incomplete', $history->rollback_status);
        $this->assertSame('failed', $history->execution_evidence['rollback_database'] ?? null);
        $this->assertTrue((bool) $history->maintenance_mode_remained);
        $this->assertDatabaseMissing('system_versions', ['version' => '1.1.0']);
    }

    private function manager(V2NonMigrationBackupRecordingRuntime $runtime): UpdateManager
    {
        return new UpdateManager(
            new UpdatePackageValidator,
            $runtime,
            app(AuditLogger::class),
        );
    }

    private function manifest(): UpdatePackageManifest
    {
        return new UpdatePackageManifest(
            '1.1.0',
            '1.0.0',
            hash_file('sha256', $this->packagePath),
            'Non-migration verified DB backup regression',
            false,
        );
    }
}

final class V2NonMigrationBackupRecordingRuntime implements UpdateRuntime
{
    /** @var list<string> */
    public array $calls = [];

    public bool $requiredBackupRequested = false;

    private bool $injectedFailure = false;

    public function __construct(
        private readonly ?string $failAt = null,
        private readonly ?string $failRollbackAt = null,
        private readonly bool $omitBackup = false,
    ) {}

    public function preflight(UpdatePackageManifest $manifest, string $packagePath): void
    {
        $this->record('preflight');
    }

    public function backupFiles(string $packagePath): string
    {
        $this->record('backup_files');

        return 'files-snapshot';
    }

    public function backupDatabase(bool $required): ?string
    {
        $this->requiredBackupRequested = $required;
        $this->record('backup_database');

        return $this->omitBackup ? null : 'db-snapshot';
    }

    public function verifyDatabaseBackup(string $backupReference): void
    {
        $this->record('verify_database_backup');
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

    private function record(string $step): void
    {
        $this->calls[] = $step;

        if ($this->failRollbackAt === $step) {
            throw new RuntimeException('Injected rollback failure.');
        }

        if (! $this->injectedFailure && $this->failAt === $step) {
            $this->injectedFailure = true;
            throw new RuntimeException('Injected cache reconciliation failure.');
        }
    }
}
