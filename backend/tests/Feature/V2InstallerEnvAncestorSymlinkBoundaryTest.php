<?php

namespace Tests\Feature;

use App\Domain\Installer\InstallerEnvironment;
use RuntimeException;
use Tests\TestCase;

final class V2InstallerEnvAncestorSymlinkBoundaryTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = storage_path('framework/testing/installer-env-ancestor-'.bin2hex(random_bytes(6)));
        mkdir($this->directory, 0750, true);
    }

    protected function tearDown(): void
    {
        foreach ([
            $this->directory.'/real/.env',
            $this->directory.'/normal/nested/.env',
        ] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }

        foreach (['linked', 'dangling'] as $name) {
            $link = $this->directory.'/'.$name;

            if (is_link($link)) {
                unlink($link);
            }
        }

        foreach ([
            $this->directory.'/normal/nested',
            $this->directory.'/normal',
            $this->directory.'/real',
            $this->directory,
        ] as $directory) {
            if (is_dir($directory)) {
                rmdir($directory);
            }
        }

        parent::tearDown();
    }

    public function test_existing_environment_cannot_be_read_or_overwritten_through_a_symlinked_parent(): void
    {
        $realDirectory = $this->directory.'/real';
        mkdir($realDirectory, 0750);
        $outside = $realDirectory.'/.env';
        $original = "DB_PASSWORD=unchanged-test-only\n";
        file_put_contents($outside, $original);

        $this->linkDirectory($realDirectory, $this->directory.'/linked');
        config(['foodex.installer_env_path' => $this->directory.'/linked/.env']);

        $environment = app(InstallerEnvironment::class);
        $this->assertRejected(fn () => $environment->read(['DB_PASSWORD']));
        $this->assertRejected(fn () => $environment->write(['DB_PASSWORD' => 'should-not-overwrite']));

        $this->assertSame($original, file_get_contents($outside));
        $this->assertTrue(is_link($this->directory.'/linked'));
    }

    public function test_dangling_parent_symlink_is_rejected_before_environment_initialization(): void
    {
        $this->linkDirectory($this->directory.'/missing-target', $this->directory.'/dangling');
        config(['foodex.installer_env_path' => $this->directory.'/dangling/.env']);

        $environment = app(InstallerEnvironment::class);
        $this->assertRejected(fn () => $environment->read(['APP_NAME']));
        $this->assertRejected(fn () => $environment->write(['APP_NAME' => 'no-creation']));

        $this->assertDirectoryDoesNotExist($this->directory.'/missing-target');
        $this->assertTrue(is_link($this->directory.'/dangling'));
    }

    public function test_regular_nested_environment_path_still_supports_read_and_write(): void
    {
        $directory = $this->directory.'/normal/nested';
        mkdir($directory, 0750, true);
        config(['foodex.installer_env_path' => $directory.'/.env']);

        $environment = app(InstallerEnvironment::class);
        $environment->write(['APP_NAME' => 'FOODEX V2']);

        $this->assertSame('FOODEX V2', $environment->read(['APP_NAME'])['APP_NAME']);
        $this->assertFalse(is_link($directory));
        $this->assertFalse(is_link($directory.'/.env'));
    }

    private function linkDirectory(string $target, string $link): void
    {
        if (! function_exists('symlink')) {
            $this->markTestSkipped('Symbolic links are unavailable on this runner.');
        }

        if (! @symlink($target, $link)) {
            $this->markTestSkipped('Directory symbolic links are unavailable on this runner.');
        }
    }

    private function assertRejected(callable $operation): void
    {
        try {
            $operation();
        } catch (RuntimeException $exception) {
            $this->assertSame(
                'Environment configuration path cannot be a symbolic link.',
                $exception->getMessage(),
            );

            return;
        }

        $this->fail('An environment path under a symlinked parent must be rejected.');
    }
}
