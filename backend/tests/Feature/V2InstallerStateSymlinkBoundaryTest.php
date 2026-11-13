<?php

namespace Tests\Feature;

use App\Domain\Installer\InstallState;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use Tests\TestCase;

class V2InstallerStateSymlinkBoundaryTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = storage_path('framework/testing/v2-installer-state-'.bin2hex(random_bytes(6)));
        mkdir($this->directory, 0750, true);

        config([
            'foodex.install_progress' => $this->directory.'/install-progress.json',
            'foodex.install_lock' => $this->directory.'/installed.lock',
        ]);
    }

    protected function tearDown(): void
    {
        if (isset($this->directory) && is_dir($this->directory)) {
            $files = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($this->directory, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST,
            );

            foreach ($files as $file) {
                if ($file->isLink() || ! $file->isDir()) {
                    unlink($file->getPathname());
                } else {
                    rmdir($file->getPathname());
                }
            }

            rmdir($this->directory);
        }

        parent::tearDown();
    }

    public function test_existing_progress_symlink_cannot_expose_token_or_change_external_file(): void
    {
        $outside = $this->directory.'/outside-progress.json';
        $original = '{"token":"'.str_repeat('a', 64).'","completed_step":14}';
        file_put_contents($outside, $original);

        $linked = $this->directory.'/install-progress.json';
        $this->linkPath($outside, $linked);

        $state = app(InstallState::class);
        $this->assertStateLinkRejected(fn () => $state->progress());
        $this->assertStateLinkRejected(fn () => $state->token());
        $this->assertStateLinkRejected(fn () => $state->markStepComplete(15));
        $this->assertStateLinkRejected(fn () => $state->clearProgress());

        $this->assertSame($original, file_get_contents($outside));
        $this->assertTrue(is_link($linked));
    }

    public function test_dangling_progress_symlink_is_rejected_without_creating_target(): void
    {
        $outside = $this->directory.'/missing-progress.json';
        $linked = $this->directory.'/install-progress.json';
        $this->linkPath($outside, $linked);

        $state = app(InstallState::class);
        $this->assertStateLinkRejected(fn () => $state->progress());
        $this->assertStateLinkRejected(fn () => $state->token());
        $this->assertStateLinkRejected(fn () => $state->markStepComplete(1));

        $this->assertFileDoesNotExist($outside);
        $this->assertTrue(is_link($linked));
    }

    public function test_existing_and_dangling_install_lock_symlinks_fail_closed(): void
    {
        $outside = $this->directory.'/outside-lock.json';
        $original = '{"installed_at":"external"}';
        file_put_contents($outside, $original);

        $linked = $this->directory.'/installed.lock';
        $this->linkPath($outside, $linked);

        $state = app(InstallState::class);
        $this->assertStateLinkRejected(fn () => $state->isInstalled());
        $this->assertStateLinkRejected(fn () => $state->writeLock('2.0.0'));
        $this->assertSame($original, file_get_contents($outside));

        unlink($outside);
        $this->assertStateLinkRejected(fn () => $state->isInstalled());
        $this->assertStateLinkRejected(fn () => $state->writeLock('2.0.0'));
        $this->assertFileDoesNotExist($outside);
        $this->assertTrue(is_link($linked));
    }

    public function test_symlinked_existing_parent_directory_is_rejected(): void
    {
        $outsideDirectory = $this->directory.'/outside';
        mkdir($outsideDirectory, 0750, true);

        $linkedDirectory = $this->directory.'/linked';
        $this->linkPath($outsideDirectory, $linkedDirectory);

        config([
            'foodex.install_progress' => $linkedDirectory.'/install-progress.json',
            'foodex.install_lock' => $linkedDirectory.'/installed.lock',
        ]);

        $state = app(InstallState::class);
        $this->assertStateLinkRejected(fn () => $state->progress());
        $this->assertStateLinkRejected(fn () => $state->token());
        $this->assertStateLinkRejected(fn () => $state->writeLock('2.0.0'));
        $this->assertStateLinkRejected(fn () => $state->isInstalled());

        $this->assertFileDoesNotExist($outsideDirectory.'/install-progress.json');
        $this->assertFileDoesNotExist($outsideDirectory.'/installed.lock');
    }

    public function test_regular_progress_and_lock_files_continue_to_work(): void
    {
        $state = app(InstallState::class);
        $this->assertFalse($state->isInstalled());

        $state->markStepComplete(3);
        $token = $state->token();

        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $token);
        $this->assertSame(3, $state->progress()['completed_step']);
        $this->assertSame($token, $state->progress()['token']);

        $state->writeLock('2.0.0');
        $this->assertTrue($state->isInstalled());

        $state->clearProgress();
        $this->assertFileDoesNotExist($this->directory.'/install-progress.json');
        $this->assertSame(0, $state->progress()['completed_step']);
    }

    private function linkPath(string $target, string $link): void
    {
        if (! function_exists('symlink') || ! @symlink($target, $link)) {
            $this->markTestSkipped('Symbolic links are unavailable on this runner.');
        }
    }

    private function assertStateLinkRejected(callable $action): void
    {
        try {
            $action();
        } catch (RuntimeException $exception) {
            $this->assertSame(
                'Installer state path cannot be a symbolic link.',
                $exception->getMessage(),
            );

            return;
        }

        $this->fail('Installer state operations must reject symbolic link traversal.');
    }
}
