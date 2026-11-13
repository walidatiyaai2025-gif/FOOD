<?php

namespace Tests\Feature;

use App\Domain\Installer\InstallerEnvironment;
use App\Domain\Installer\InstallerWorkflow;
use RuntimeException;
use Tests\TestCase;

/**
 * V2 #3367: do not accept foreign/dangling public storage links as upload storage.
 *
 * All symlink fixtures live under a dedicated temporary public path. This
 * suite does not overwrite the application's actual public/storage entry.
 */
final class V2InstallerPublicStorageLinkBoundaryTest extends TestCase
{
    private string $originalPublicPath;

    private string $fixtureRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalPublicPath = public_path();
        $this->fixtureRoot = storage_path('framework/testing/v2-installer-storage-'.bin2hex(random_bytes(6)));
        mkdir($this->fixtureRoot.'/public', 0750, true);
        app()->usePublicPath($this->fixtureRoot.'/public');

        config()->set('foodex.installer_env_path', $this->fixtureRoot.'/.env');
        file_put_contents($this->fixtureRoot.'/.env', "FILESYSTEM_DISK=local\n");
    }

    protected function tearDown(): void
    {
        app()->usePublicPath($this->originalPublicPath);

        if (isset($this->fixtureRoot) && is_dir($this->fixtureRoot)) {
            $files = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->fixtureRoot, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST,
            );

            foreach ($files as $file) {
                if ($file->isDir() && ! $file->isLink()) {
                    rmdir($file->getPathname());
                } else {
                    unlink($file->getPathname());
                }
            }

            rmdir($this->fixtureRoot);
        }

        parent::tearDown();
    }

    public function test_existing_foreign_directory_symlink_is_rejected_without_touching_target(): void
    {
        $this->requireSymlinks();

        $foreign = $this->fixtureRoot.'/foreign';
        mkdir($foreign, 0750, true);
        file_put_contents($foreign.'/unchanged.txt', 'protected');

        $link = public_path('storage');
        $this->assertTrue(@symlink($foreign, $link));
        $this->assertLinkRejected();

        $this->assertTrue(is_link($link));
        $this->assertSame($foreign, realpath($link));
        $this->assertSame('protected', file_get_contents($foreign.'/unchanged.txt'));
    }

    public function test_dangling_public_storage_symlink_is_rejected_and_preserved(): void
    {
        $this->requireSymlinks();

        $link = public_path('storage');
        $this->assertTrue(@symlink($this->fixtureRoot.'/missing', $link));
        $this->assertLinkRejected();

        $this->assertTrue(is_link($link));
        $this->assertFalse(realpath($link));
    }

    public function test_existing_relative_symlink_to_canonical_storage_is_accepted(): void
    {
        $this->requireSymlinks();

        $target = storage_path('app/public');
        if (! is_dir($target)) {
            mkdir($target, 0750, true);
        }

        // public is storage/framework/testing/<fixture>/public.
        $link = public_path('storage');
        $this->assertTrue(@symlink('../../../../app/public', $link));
        $this->assertSame(realpath($target), realpath($link));

        app(InstallerWorkflow::class)->saveStorageConfiguration('public');

        $this->assertSame('public', app(InstallerEnvironment::class)->read(['FILESYSTEM_DISK'])['FILESYSTEM_DISK']);
        $this->assertSame(realpath($target), realpath($link));
    }

    public function test_preexisting_real_public_directory_remains_supported_on_shared_hosting(): void
    {
        $directory = public_path('storage');
        mkdir($directory, 0750, true);

        app(InstallerWorkflow::class)->saveStorageConfiguration('public');

        $this->assertTrue(is_dir($directory));
        $this->assertFalse(is_link($directory));
        $this->assertSame('public', app(InstallerEnvironment::class)->read(['FILESYSTEM_DISK'])['FILESYSTEM_DISK']);
    }

    private function assertLinkRejected(): void
    {
        try {
            app(InstallerWorkflow::class)->saveStorageConfiguration('public');
            $this->fail('An untrusted public/storage symlink must not be accepted.');
        } catch (RuntimeException $exception) {
            $this->assertSame(
                'Public storage link does not point to the configured storage directory.',
                $exception->getMessage(),
            );
        }
    }

    private function requireSymlinks(): void
    {
        if (! function_exists('symlink')) {
            $this->markTestSkipped('Symlinks are not supported on this PHP installation.');
        }
    }
}
