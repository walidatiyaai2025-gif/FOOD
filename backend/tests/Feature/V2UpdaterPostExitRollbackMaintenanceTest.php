<?php

namespace Tests\Feature;

use App\Domain\Updater\UpdateManager;
use App\Domain\Updater\UpdatePackageManifest;
use App\Domain\Updater\UpdatePackageValidator;
use App\Domain\Updater\UpdateRuntime;
use App\Models\SystemVersion;
use App\Models\UpdateHistory;
use App\Services\AuditLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class V2UpdaterPostExitRollbackMaintenanceTest extends TestCase
{
    use RefreshDatabase;

    private string $packagePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->packagePath = tempnam(sys_get_temp_dir(), 'foodex-post-exit-');
        file_put_contents($this->packagePath, 'late updater metadata failure');
    }

    protected function tearDown(): void
    {
        @unlink($this->packagePath);

        parent::tearDown();
    }

    public function test_late_version_failure_reenters_maintenance_before_any_rollback_write(): void
    {
        [$runtime, $history] = $this->failAfterMaintenanceExit(false);

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
            'enter_maintenance_mode',
            'rollback_files',
            'rollback_database',
            'health_check',
            'exit_maintenance_mode',
        ], $runtime->calls);
        $this->assertFalse($runtime->unsafeRollbackAttempted);
        $this->assertFalse($runtime->maintenanceEnabled);
        $this->assertSame('record_version', $history->failed_stage);
        $this->assertSame('succeeded', $history->rollback_status);
        $this->assertFalse((bool) $history->maintenance_mode_remained);
        $this->assertSame('passed', $history->execution_evidence['rollback_files'] ?? null);
        $this->assertSame('passed', $history->execution_evidence['rollback_database'] ?? null);
        $this->assertSame('exited_after_rollback', $history->execution_evidence['maintenance_mode'] ?? null);
        $this->assertDatabaseHas('audit_logs', ['event' => 'updater.failed']);
    }

    public function test_failed_maintenance_reentry_skips_unsafe_file_and_database_rollback(): void
    {
        [$runtime, $history] = $this->failAfterMaintenanceExit(true);

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
            'enter_maintenance_mode',
        ], $runtime->calls);
        $this->assertFalse($runtime->unsafeRollbackAttempted);
        $this->assertFalse($runtime->maintenanceEnabled);
        $this->assertSame('record_version', $history->failed_stage);
        $this->assertSame('incomplete', $history->rollback_status);
        $this->assertFalse((bool) $history->maintenance_mode_remained);
        $this->assertSame('reentry_failed', $history->execution_evidence['maintenance_mode'] ?? null);
        $this->assertSame('skipped_unsafe', $history->execution_evidence['rollback_files'] ?? null);
        $this->assertSame('skipped_unsafe', $history->execution_evidence['rollback_database'] ?? null);
        $this->assertStringContainsString(
            'Rollback was skipped because maintenance mode could not be re-entered.',
            (string) $history->failure_reason,
        );
        $this->assertStringContainsString('manual recovery is required', (string) $history->failure_reason);
        $this->assertStringNotContainsString('Rollback was attempted.', (string) $history->failure_reason);
        $this->assertDatabaseHas('audit_logs', ['event' => 'updater.failed']);
    }

    /**
     * @return array{V2PostExitMaintenanceRuntime, UpdateHistory}
     */
    private function failAfterMaintenanceExit(bool $failReentry): array
    {
        $runtime = new V2PostExitMaintenanceRuntime($failReentry);
        $manager = new UpdateManager(
            new UpdatePackageValidator,
            $runtime,
            app(AuditLogger::class),
        );

        // The model write is intentionally after exitMaintenanceMode(). Inject
        // a deterministic failure without touching an actual release or DB.
        SystemVersion::creating(static function (): never {
            throw new RuntimeException('Injected post-exit version failure.');
        });

        try {
            try {
                $manager->execute(
                    new UpdatePackageManifest(
                        '1.1.0',
                        '1.0.0',
                        hash_file('sha256', $this->packagePath),
                        'Post-exit rollback safety fixture',
                        false,
                    ),
                    '1.0.0',
                    $this->packagePath,
                );
                $this->fail('A late failed version write must abort the update.');
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString('Injected post-exit version failure.', $exception->getMessage());
            }
        } finally {
            SystemVersion::flushEventListeners();
        }

        $history = UpdateHistory::query()->where('to_version', '1.1.0')->firstOrFail();
        $this->assertSame('failed', $history->status);
        $this->assertDatabaseMissing('system_versions', ['version' => '1.1.0']);

        return [$runtime, $history];
    }
}

final class V2PostExitMaintenanceRuntime implements UpdateRuntime
{
    /** @var list<string> */
    public array $calls = [];

    public bool $maintenanceEnabled = false;

    public bool $unsafeRollbackAttempted = false;

    private int $maintenanceEntrances = 0;

    public function __construct(private readonly bool $failReentry) {}

    public function preflight(UpdatePackageManifest $manifest, string $packagePath): void
    {
        $this->calls[] = 'preflight';
    }

    public function backupFiles(string $packagePath): string
    {
        $this->calls[] = 'backup_files';

        return 'files-backup';
    }

    public function backupDatabase(bool $required): ?string
    {
        $this->calls[] = 'backup_database';

        return $required ? 'database-backup' : null;
    }

    public function verifyDatabaseBackup(string $backupReference): void
    {
        $this->calls[] = 'verify_database_backup';
    }

    public function enterMaintenanceMode(): void
    {
        $this->calls[] = 'enter_maintenance_mode';
        $this->maintenanceEntrances++;

        if ($this->failReentry && $this->maintenanceEntrances === 2) {
            throw new RuntimeException('Injected maintenance reentry failure.');
        }

        $this->maintenanceEnabled = true;
    }

    public function extractRelease(string $packagePath): void
    {
        $this->calls[] = 'extract_release';
    }

    public function runMigrations(): void
    {
        $this->calls[] = 'run_migrations';
    }

    public function rebuildCaches(): void
    {
        $this->calls[] = 'rebuild_caches';
    }

    public function healthCheck(): void
    {
        $this->calls[] = 'health_check';
    }

    public function rollbackFiles(string $backupReference): void
    {
        $this->assertSafeRollback();
        $this->calls[] = 'rollback_files';
    }

    public function rollbackDatabase(string $backupReference): void
    {
        $this->assertSafeRollback();
        $this->calls[] = 'rollback_database';
    }

    public function exitMaintenanceMode(): void
    {
        $this->calls[] = 'exit_maintenance_mode';
        $this->maintenanceEnabled = false;
    }

    private function assertSafeRollback(): void
    {
        if (! $this->maintenanceEnabled) {
            $this->unsafeRollbackAttempted = true;
            throw new RuntimeException('Rollback cannot write while the site is live.');
        }
    }
}
