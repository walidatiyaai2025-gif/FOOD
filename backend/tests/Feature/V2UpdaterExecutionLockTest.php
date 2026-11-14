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

class V2UpdaterExecutionLockTest extends TestCase
{
    use RefreshDatabase;

    private string $directory;

    private string $packagePath;

    private string $lockPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = storage_path('framework/testing/v2-updater-lock-'.bin2hex(random_bytes(6)));
        mkdir($this->directory, 0750, true);
        $this->lockPath = $this->directory.'/execution.lock';
        $this->packagePath = $this->directory.'/update.zip';
        file_put_contents($this->packagePath, 'synthetic update contents');

        config(['foodex.updater_execution_lock' => $this->lockPath]);
    }

    protected function tearDown(): void
    {
        if (isset($this->directory) && is_dir($this->directory)) {
            $entries = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->directory, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST,
            );

            foreach ($entries as $entry) {
                if ($entry->isLink() || ! $entry->isDir()) {
                    unlink($entry->getPathname());
                } else {
                    rmdir($entry->getPathname());
                }
            }

            rmdir($this->directory);
        }

        parent::tearDown();
    }

    public function test_second_update_is_rejected_before_creating_history_or_running_any_stage(): void
    {
        $heldLock = fopen($this->lockPath, 'c+b');
        $this->assertNotFalse($heldLock);
        $this->assertTrue(flock($heldLock, LOCK_EX | LOCK_NB));

        $runtime = $this->createMock(UpdateRuntime::class);
        $runtime->expects($this->never())->method('preflight');
        $manager = $this->manager($runtime);

        try {
            $manager->execute($this->manifest(), '1.0.0', $this->packagePath);
            $this->fail('A concurrent updater must never execute.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Another system update is already running.', $exception->getMessage());
        } finally {
            flock($heldLock, LOCK_UN);
            fclose($heldLock);
        }

        $this->assertDatabaseCount('update_history', 0);
        $this->assertDatabaseCount('system_versions', 0);
        $this->assertSame('success', $this->manager($this->successfulRuntime())
            ->execute($this->manifest(), '1.0.0', $this->packagePath)->status);
    }

    public function test_failed_update_releases_lock_after_failure_history_and_rollback_path(): void
    {
        $runtime = $this->createMock(UpdateRuntime::class);
        $runtime->method('preflight')->willThrowException(new RuntimeException('Synthetic preflight failure.'));

        try {
            $this->manager($runtime)->execute($this->manifest(), '1.0.0', $this->packagePath);
            $this->fail('The synthetic preflight must fail.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('preflight', $exception->getMessage());
        }

        $this->assertSame('failed', UpdateHistory::query()->latest('id')->firstOrFail()->status);
        $this->assertSame('success', $this->manager($this->successfulRuntime())
            ->execute($this->manifest(), '1.0.0', $this->packagePath)->status);
    }

    public function test_linked_lock_file_fails_closed_without_opening_external_target(): void
    {
        $outside = $this->directory.'/outside.txt';
        $original = 'external data must remain unchanged';
        file_put_contents($outside, $original);

        if (! function_exists('symlink') || ! @symlink($outside, $this->lockPath)) {
            $this->markTestSkipped('Symbolic links cannot be created on this runner.');
        }

        try {
            $this->manager($this->successfulRuntime())->execute($this->manifest(), '1.0.0', $this->packagePath);
            $this->fail('Linked updater lock path must be rejected.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Updater execution lock path cannot be a symbolic link.', $exception->getMessage());
        }

        $this->assertSame($original, file_get_contents($outside));
        $this->assertDatabaseCount('update_history', 0);
    }

    public function test_linked_lock_directory_cannot_redirect_lock_creation(): void
    {
        $outside = $this->directory.'/outside';
        mkdir($outside, 0750, true);
        $linked = $this->directory.'/linked';

        if (! function_exists('symlink') || ! @symlink($outside, $linked)) {
            $this->markTestSkipped('Symbolic links cannot be created on this runner.');
        }

        config(['foodex.updater_execution_lock' => $linked.'/execution.lock']);
        try {
            $this->manager($this->successfulRuntime())->execute($this->manifest(), '1.0.0', $this->packagePath);
            $this->fail('Linked updater lock ancestor must be rejected.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Updater execution lock path cannot be a symbolic link.', $exception->getMessage());
        }

        $this->assertFileDoesNotExist($outside.'/execution.lock');
        $this->assertDatabaseCount('update_history', 0);
    }

    private function manifest(): UpdatePackageManifest
    {
        return new UpdatePackageManifest(
            '1.1.0',
            '1.0.0',
            hash_file('sha256', $this->packagePath),
            'test-only lock protection',
            false,
        );
    }

    private function manager(UpdateRuntime $runtime): UpdateManager
    {
        return new UpdateManager(
            new UpdatePackageValidator,
            $runtime,
            app(AuditLogger::class),
        );
    }

    private function successfulRuntime(): UpdateRuntime
    {
        $runtime = $this->createMock(UpdateRuntime::class);
        $runtime->method('backupFiles')->willReturn('files-backup');
        $runtime->method('backupDatabase')->with(true)->willReturn('database-backup');

        return $runtime;
    }
}
