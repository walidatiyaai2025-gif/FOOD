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

class V2UpdaterMutationAwareRollbackTest extends TestCase
{
    use RefreshDatabase;

    private string $packagePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->packagePath = tempnam(sys_get_temp_dir(), 'foodex-v2-rollback-');
        file_put_contents($this->packagePath, 'mutation-aware rollback fixture');
    }

    protected function tearDown(): void
    {
        @unlink($this->packagePath);

        parent::tearDown();
    }

    public function test_failed_preflight_does_not_attempt_any_rollback(): void
    {
        [$runtime, $history] = $this->failAt('preflight');

        $this->assertSame(['preflight'], $runtime->calls);
        $this->assertSame('preflight', $history->failed_stage);
        $this->assertSame('not_required', $history->rollback_status);
        $this->assertFalse((bool) $history->maintenance_mode_remained);
        $this->assertStringNotContainsString('Rollback was attempted.', $history->failure_reason);
    }

    public function test_unverified_database_backup_cannot_be_restored_onto_untouched_live_data(): void
    {
        [$runtime, $history] = $this->failAt('verify_database_backup');

        $this->assertSame([
            'preflight',
            'backup_files',
            'backup_database',
            'verify_database_backup',
        ], $runtime->calls);
        $this->assertNotContains('rollback_files', $runtime->calls);
        $this->assertNotContains('rollback_database', $runtime->calls);
        $this->assertNotContains('enter_maintenance_mode', $runtime->calls);
        $this->assertSame('verify_database_backup', $history->failed_stage);
        $this->assertSame('not_required', $history->rollback_status);
        $this->assertSame('failed', $history->execution_evidence['backup_database'] ?? null);
        $this->assertSame('not_required', $history->execution_evidence['rollback_database'] ?? null);
        $this->assertStringNotContainsString('Rollback was attempted.', $history->failure_reason);
        $this->assertFalse((bool) $history->maintenance_mode_remained);
    }

    public function test_partially_failed_extraction_restores_release_files_but_not_untouched_database(): void
    {
        [$runtime, $history] = $this->failAt('extract_release');

        $this->assertSame([
            'preflight',
            'backup_files',
            'backup_database',
            'verify_database_backup',
            'enter_maintenance_mode',
            'extract_release',
            'rollback_files',
            'health_check',
            'exit_maintenance_mode',
        ], $runtime->calls);
        $this->assertNotContains('rollback_database', $runtime->calls);
        $this->assertSame('succeeded', $history->rollback_status);
        $this->assertSame('passed', $history->execution_evidence['rollback_files'] ?? null);
        $this->assertSame('not_required', $history->execution_evidence['rollback_database'] ?? null);
        $this->assertFalse((bool) $history->maintenance_mode_remained);
    }

    public function test_failed_migration_restores_files_and_database_and_keeps_health_guard(): void
    {
        [$runtime, $history] = $this->failAt('run_migrations');

        $this->assertSame([
            'preflight',
            'backup_files',
            'backup_database',
            'verify_database_backup',
            'enter_maintenance_mode',
            'extract_release',
            'run_migrations',
            'rollback_files',
            'rollback_database',
            'health_check',
            'exit_maintenance_mode',
        ], $runtime->calls);
        $this->assertSame('succeeded', $history->rollback_status);
        $this->assertSame('passed', $history->execution_evidence['rollback_database'] ?? null);
        $this->assertSame('passed', $history->execution_evidence['rollback_health_check'] ?? null);
        $this->assertFalse((bool) $history->maintenance_mode_remained);
    }

    /**
     * @return array{V2RecordingMutationUpdateRuntime, UpdateHistory}
     */
    private function failAt(string $stage): array
    {
        $runtime = new V2RecordingMutationUpdateRuntime($stage);
        $manager = new UpdateManager(
            new UpdatePackageValidator,
            $runtime,
            app(AuditLogger::class),
        );
        $manifest = new UpdatePackageManifest(
            '1.1.0',
            '1.0.0',
            hash_file('sha256', $this->packagePath),
            'Mutation-aware rollback regression',
            true,
        );

        try {
            $manager->execute($manifest, '1.0.0', $this->packagePath);
            $this->fail('Injected update failure must abort the deployment.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('Mutation rollback fixture failure.', $exception->getMessage());
        }

        $history = UpdateHistory::query()->where('to_version', '1.1.0')->firstOrFail();

        $this->assertSame('failed', $history->status);
        $this->assertDatabaseMissing('system_versions', ['version' => '1.1.0']);

        return [$runtime, $history];
    }
}

final class V2RecordingMutationUpdateRuntime implements UpdateRuntime
{
    /** @var list<string> */
    public array $calls = [];

    private bool $failed = false;

    public function __construct(private readonly string $failAt) {}

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

        if (! $this->failed && $this->failAt === $step) {
            $this->failed = true;
            throw new RuntimeException('Mutation rollback fixture failure.');
        }
    }
}
