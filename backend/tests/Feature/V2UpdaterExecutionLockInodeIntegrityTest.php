<?php

namespace Tests\Feature;

use App\Domain\Updater\UpdateManager;
use App\Domain\Updater\UpdatePackageValidator;
use App\Domain\Updater\UpdateRuntime;
use App\Services\AuditLogger;
use RuntimeException;
use Tests\TestCase;

final class V2UpdaterExecutionLockInodeIntegrityTest extends TestCase
{
    private string $directory;

    private string $lockPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = storage_path('framework/testing/v2-updater-lock-inode-'.bin2hex(random_bytes(6)));
        $this->assertTrue(mkdir($this->directory, 0750, true));
        $this->lockPath = $this->directory.'/execution.lock';

        config(['foodex.updater_execution_lock' => $this->lockPath]);
    }

    protected function tearDown(): void
    {
        if (isset($this->directory) && is_dir($this->directory)) {
            foreach (['execution.lock', 'protected.txt'] as $filename) {
                $path = $this->directory.'/'.$filename;
                if (is_file($path) || is_link($path)) {
                    unlink($path);
                }
            }

            rmdir($this->directory);
        }

        parent::tearDown();
    }

    public function test_hardlinked_lock_is_rejected_without_chmod_or_running_update_operation(): void
    {
        $protected = $this->directory.'/protected.txt';
        $contents = 'synthetic unrelated file — do not modify';
        $this->assertNotFalse(file_put_contents($protected, $contents));
        $this->assertTrue(chmod($protected, 0644));
        clearstatcache(true, $protected);
        $originalMode = fileperms($protected) & 0777;

        if (! function_exists('link') || ! @link($protected, $this->lockPath)) {
            $this->markTestSkipped('Hard links are not supported on the test filesystem.');
        }

        $called = false;
        try {
            $this->manager()->withExclusiveExecutionLock(static function () use (&$called): void {
                $called = true;
            });

            $this->fail('A hardlinked lock must not be accepted.');
        } catch (RuntimeException $exception) {
            $this->assertSame(
                'Updater execution lock path is not an exclusive regular file.',
                $exception->getMessage(),
            );
        }

        $this->assertFalse($called);
        clearstatcache(true, $protected);
        $this->assertSame($originalMode, fileperms($protected) & 0777);
        $this->assertSame($contents, file_get_contents($protected));
        $this->assertSame(2, stat($protected)['nlink']);
    }

    public function test_regular_private_lock_serializes_and_can_be_reacquired(): void
    {
        $manager = $this->manager();

        $this->assertSame('first', $manager->withExclusiveExecutionLock(
            static fn (): string => 'first',
        ));
        $this->assertTrue(is_file($this->lockPath));

        clearstatcache(true, $this->lockPath);
        $this->assertSame(1, stat($this->lockPath)['nlink']);
        $this->assertSame(0600, fileperms($this->lockPath) & 0777);

        $this->assertSame('second', $manager->withExclusiveExecutionLock(
            static fn (): string => 'second',
        ));
    }

    public function test_held_lock_rejects_second_request_and_failure_releases_ownership(): void
    {
        $held = fopen($this->lockPath, 'c+b');
        $this->assertNotFalse($held);
        $this->assertTrue(flock($held, LOCK_EX | LOCK_NB));

        $called = false;
        try {
            $this->manager()->withExclusiveExecutionLock(static function () use (&$called): void {
                $called = true;
            });

            $this->fail('A concurrently held lock must reject the updater.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Another system update is already running.', $exception->getMessage());
        } finally {
            flock($held, LOCK_UN);
            fclose($held);
        }

        $this->assertFalse($called);
        try {
            $this->manager()->withExclusiveExecutionLock(static function (): never {
                throw new RuntimeException('synthetic operation failure');
            });
            $this->fail('The synthetic callback must throw.');
        } catch (RuntimeException $exception) {
            $this->assertSame('synthetic operation failure', $exception->getMessage());
        }

        $this->assertTrue($this->manager()->withExclusiveExecutionLock(
            static fn (): bool => true,
        ));
    }

    private function manager(): UpdateManager
    {
        return new UpdateManager(
            new UpdatePackageValidator,
            $this->createMock(UpdateRuntime::class),
            app(AuditLogger::class),
        );
    }
}
