<?php

namespace Tests\Feature;

use App\Domain\Installer\InstallerEnvironment;
use RuntimeException;
use Tests\TestCase;

class InstallerEnvironmentSymlinkBoundaryTest extends TestCase
{
    private string $directory;

    private string $environmentPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = storage_path('framework/testing/installer-symlink-'.bin2hex(random_bytes(6)));
        mkdir($this->directory, 0750, true);
        $this->environmentPath = $this->directory.'/.env';

        config(['foodex.installer_env_path' => $this->environmentPath]);
    }

    protected function tearDown(): void
    {
        foreach ([$this->environmentPath, $this->directory.'/target.env'] as $path) {
            if (is_link($path) || is_file($path)) {
                unlink($path);
            }
        }

        rmdir($this->directory);
        parent::tearDown();
    }

    public function test_symlinked_environment_cannot_be_read(): void
    {
        $target = $this->directory.'/target.env';
        $secret = "DB_PASSWORD=outside-secret\n";
        file_put_contents($target, $secret);
        $this->linkEnvironmentTo($target);

        $this->assertSymbolicLinkRejected(fn () => app(InstallerEnvironment::class)->read(['DB_PASSWORD']));
        $this->assertSame($secret, file_get_contents($target));
        $this->assertTrue(is_link($this->environmentPath));
    }

    public function test_symlinked_environment_cannot_be_overwritten(): void
    {
        $target = $this->directory.'/target.env';
        $secret = "APP_NAME=outside-target\n";
        file_put_contents($target, $secret);
        $this->linkEnvironmentTo($target);

        $this->assertSymbolicLinkRejected(fn () => app(InstallerEnvironment::class)->write(['APP_NAME' => 'Changed']));
        $this->assertSame($secret, file_get_contents($target));
        $this->assertTrue(is_link($this->environmentPath));
    }

    public function test_dangling_environment_symlink_is_rejected_without_creating_its_target(): void
    {
        $target = $this->directory.'/target.env';
        $this->linkEnvironmentTo($target);

        $this->assertSymbolicLinkRejected(fn () => app(InstallerEnvironment::class)->read(['APP_NAME']));
        $this->assertSymbolicLinkRejected(fn () => app(InstallerEnvironment::class)->write(['APP_NAME' => 'Changed']));
        $this->assertFileDoesNotExist($target);
        $this->assertTrue(is_link($this->environmentPath));
    }

    public function test_regular_environment_write_and_read_remain_supported(): void
    {
        file_put_contents($this->environmentPath, "APP_NAME=Old\n");

        $environment = app(InstallerEnvironment::class);
        $environment->write(['APP_NAME' => 'FOODEX New']);

        $this->assertSame('FOODEX New', $environment->read(['APP_NAME'])['APP_NAME']);
        $this->assertFalse(is_link($this->environmentPath));
    }

    private function linkEnvironmentTo(string $target): void
    {
        if (! function_exists('symlink')) {
            $this->markTestSkipped('Symbolic links are unavailable on this runner.');
        }

        $this->assertTrue(@symlink($target, $this->environmentPath));
    }

    private function assertSymbolicLinkRejected(callable $action): void
    {
        try {
            $action();
        } catch (RuntimeException $exception) {
            $this->assertSame(
                'Environment configuration path cannot be a symbolic link.',
                $exception->getMessage(),
            );

            return;
        }

        $this->fail('The installer must fail closed when the environment path is a symbolic link.');
    }
}
