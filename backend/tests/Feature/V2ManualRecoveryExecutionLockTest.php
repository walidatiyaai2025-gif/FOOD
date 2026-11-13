<?php

namespace Tests\Feature;

use App\Domain\Updater\UpdateRuntime;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\SystemAdministrationRuntime;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Tests\TestCase;

final class V2ManualRecoveryExecutionLockTest extends TestCase
{
    use RefreshDatabase;

    private string $directory;

    private string $backupRoot;

    private string $lockPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = storage_path('framework/testing/v2-manual-recovery-lock-'.bin2hex(random_bytes(6)));
        $this->backupRoot = $this->directory.'/backups';
        $this->lockPath = $this->directory.'/execution.lock';
        File::ensureDirectoryExists($this->backupRoot);

        config()->set('foodex.updater_execution_lock', $this->lockPath);
        config()->set('foodex.update_backup_path', $this->backupRoot);
    }

    protected function tearDown(): void
    {
        if (isset($this->directory)) {
            File::deleteDirectory($this->directory);
        }

        parent::tearDown();
    }

    public function test_held_zip_updater_lock_rejects_manual_backup_and_restore_before_any_side_effect(): void
    {
        $actor = User::factory()->create();
        $request = $this->request($actor);
        $reference = $this->snapshotReference();

        $runtime = $this->createMock(UpdateRuntime::class);
        $runtime->expects($this->never())->method('backupDatabase');
        $runtime->expects($this->never())->method('verifyDatabaseBackup');
        $runtime->expects($this->never())->method('enterMaintenanceMode');
        $runtime->expects($this->never())->method('rollbackDatabase');
        $service = new SystemAdministrationRuntime($runtime, app(AuditLogger::class));

        $held = fopen($this->lockPath, 'c+b');
        $this->assertNotFalse($held);
        $this->assertTrue(flock($held, LOCK_EX | LOCK_NB));

        try {
            try {
                $service->createDatabaseBackup($actor, $request, 'Backup blocked by updater', '1.2.3');
                $this->fail('Manual backup must not race an active ZIP updater.');
            } catch (RuntimeException $exception) {
                $this->assertSame('Another system update is already running.', $exception->getMessage());
            }

            try {
                $service->restoreDatabaseBackup(
                    $actor,
                    $request,
                    $reference,
                    'Restore blocked by updater',
                    $reference,
                    null,
                    '1.2.3',
                );
                $this->fail('Manual restore must not race an active ZIP updater.');
            } catch (RuntimeException $exception) {
                $this->assertSame('Another system update is already running.', $exception->getMessage());
            }
        } finally {
            flock($held, LOCK_UN);
            fclose($held);
        }

        $this->assertDatabaseCount('audit_logs', 0);
        $this->assertDatabaseCount('update_history', 0);
        $this->assertFileDoesNotExist($this->backupRoot.'/'.$reference.'/foodex-backup.json');
    }

    public function test_sequential_manual_backup_and_restore_run_the_existing_recovery_pipeline(): void
    {
        $actor = User::factory()->create();
        $request = $this->request($actor);
        $reference = $this->snapshotReference();
        $runtime = $this->createMock(UpdateRuntime::class);

        $runtime->expects($this->once())->method('backupDatabase')
            ->with(true)->willReturn($this->backupRoot.'/'.$reference);
        $runtime->expects($this->once())->method('verifyDatabaseBackup');
        $runtime->expects($this->once())->method('enterMaintenanceMode');
        $runtime->expects($this->once())->method('rollbackDatabase');
        $runtime->expects($this->once())->method('runMigrations');
        $runtime->expects($this->once())->method('rebuildCaches');
        $runtime->expects($this->once())->method('healthCheck');
        $runtime->expects($this->once())->method('exitMaintenanceMode');

        $service = new SystemAdministrationRuntime($runtime, app(AuditLogger::class));
        $backup = $service->createDatabaseBackup($actor, $request, 'Manual verified backup', '1.2.3');

        $this->assertSame($reference, $backup['reference']);
        $this->assertSame('available', $backup['status']);
        $this->assertSame('manual_backup', $backup['origin']);

        $service->restoreDatabaseBackup(
            $actor,
            $request,
            $reference,
            'Manual verified restore',
            $reference,
            null,
            '1.2.3',
        );

        $this->assertDatabaseHas('audit_logs', ['event' => 'system.backup.created', 'user_id' => $actor->id]);
        $this->assertDatabaseHas('audit_logs', ['event' => 'system.restore.completed', 'user_id' => $actor->id]);

        $reacquired = fopen($this->lockPath, 'c+b');
        $this->assertNotFalse($reacquired);
        $this->assertTrue(flock($reacquired, LOCK_EX | LOCK_NB));
        flock($reacquired, LOCK_UN);
        fclose($reacquired);
    }

    public function test_failed_database_restore_preserves_audit_and_releases_shared_lock(): void
    {
        $actor = User::factory()->create();
        $request = $this->request($actor);
        $reference = $this->snapshotReference();
        $runtime = $this->createMock(UpdateRuntime::class);

        $runtime->expects($this->once())->method('verifyDatabaseBackup');
        $runtime->expects($this->once())->method('enterMaintenanceMode');
        $runtime->expects($this->once())->method('rollbackDatabase')
            ->willThrowException(new RuntimeException('Synthetic recovery failure'));
        $runtime->expects($this->never())->method('runMigrations');
        $runtime->expects($this->never())->method('exitMaintenanceMode');

        $service = new SystemAdministrationRuntime($runtime, app(AuditLogger::class));

        try {
            $service->restoreDatabaseBackup(
                $actor,
                $request,
                $reference,
                'Failure path retention test',
                $reference,
                null,
                '1.2.3',
            );
            $this->fail('Failed recovery must never return successful completion.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('restore_database', $exception->getMessage());
            $this->assertStringContainsString('Maintenance mode may remain enabled', $exception->getMessage());
            $this->assertStringNotContainsString('Synthetic recovery failure', $exception->getMessage());
        }

        $failed = AuditLog::query()->where('event', 'system.restore.failed')->firstOrFail();
        $this->assertSame('restore_database', $failed->after['stage']);
        $this->assertTrue($failed->after['maintenance_mode']);
        $this->assertDatabaseMissing('audit_logs', ['event' => 'system.restore.completed']);

        $reacquired = fopen($this->lockPath, 'c+b');
        $this->assertNotFalse($reacquired);
        $this->assertTrue(flock($reacquired, LOCK_EX | LOCK_NB));
        flock($reacquired, LOCK_UN);
        fclose($reacquired);
    }

    public function test_symlinked_execution_lock_is_rejected_without_changing_its_target(): void
    {
        $outside = $this->directory.'/protected.txt';
        file_put_contents($outside, 'do-not-modify');

        if (! function_exists('symlink') || ! @symlink($outside, $this->lockPath)) {
            $this->markTestSkipped('Symbolic links cannot be created on this runner.');
        }

        $actor = User::factory()->create();
        $runtime = $this->createMock(UpdateRuntime::class);
        $runtime->expects($this->never())->method('backupDatabase');
        $service = new SystemAdministrationRuntime($runtime, app(AuditLogger::class));

        try {
            $service->createDatabaseBackup($actor, $this->request($actor), 'Linked lock target test', '1.2.3');
            $this->fail('A symlinked execution lock must be rejected.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Updater execution lock path cannot be a symbolic link.', $exception->getMessage());
        }

        $this->assertSame('do-not-modify', file_get_contents($outside));
        $this->assertDatabaseCount('audit_logs', 0);
    }

    private function request(User $actor): Request
    {
        $request = Request::create('/admin/settings/system-update/backups', 'POST');
        $request->setUserResolver(static fn (): User => $actor);

        return $request;
    }

    private function snapshotReference(): string
    {
        $reference = '20261113-120000-database-aabbcc0011';
        $directory = $this->backupRoot.'/'.$reference;
        File::ensureDirectoryExists($directory);

        $snapshot = $directory.'/database.sqlite';
        file_put_contents($snapshot, 'synthetic-safe-database-backup');
        file_put_contents($directory.'/database.json', json_encode([
            'driver' => 'sqlite',
            'snapshot' => $snapshot,
        ], JSON_THROW_ON_ERROR));

        return $reference;
    }
}
