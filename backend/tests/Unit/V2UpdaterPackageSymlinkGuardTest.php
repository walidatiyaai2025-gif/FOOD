<?php

namespace Tests\Unit;

use App\Domain\Updater\UpdatePackageManifest;
use App\Domain\Updater\UpdatePackageValidator;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class V2UpdaterPackageSymlinkGuardTest extends TestCase
{
    private string $fixtureRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fixtureRoot = sys_get_temp_dir().'/foodex-v2-update-path-'.bin2hex(random_bytes(8));
        $this->assertTrue(mkdir($this->fixtureRoot, 0700));
    }

    protected function tearDown(): void
    {
        foreach (['linked.zip', 'dangling.zip', 'linked-directory'] as $name) {
            $path = $this->fixtureRoot.'/'.$name;
            if (is_link($path)) {
                unlink($path);
            }
        }

        foreach (['original.zip', 'actual-directory/update.zip'] as $name) {
            $path = $this->fixtureRoot.'/'.$name;
            if (is_file($path)) {
                unlink($path);
            }
        }

        if (is_dir($this->fixtureRoot.'/actual-directory')) {
            rmdir($this->fixtureRoot.'/actual-directory');
        }

        if (is_dir($this->fixtureRoot)) {
            rmdir($this->fixtureRoot);
        }

        parent::tearDown();
    }

    public function test_regular_package_with_matching_hash_is_still_accepted(): void
    {
        $original = $this->originalPackage();

        $result = (new UpdatePackageValidator)->validate($this->manifest($original), '1.1.0', $original);

        $this->assertSame('1.2.0', $result['target_version']);
        $this->assertSame(hash_file('sha256', $original), $result['package_hash']);
    }

    public function test_symlinked_package_leaf_is_rejected_even_with_matching_hash(): void
    {
        $original = $this->originalPackage();
        $linked = $this->fixtureRoot.'/linked.zip';
        $this->assertTrue(symlink($original, $linked));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('symbolic link');

        (new UpdatePackageValidator)->validate($this->manifest($original), '1.1.0', $linked);
    }

    public function test_symlinked_parent_directory_is_rejected_even_with_matching_hash(): void
    {
        $actualDirectory = $this->fixtureRoot.'/actual-directory';
        $this->assertTrue(mkdir($actualDirectory, 0700));
        $original = $actualDirectory.'/update.zip';
        $this->assertNotFalse(file_put_contents($original, 'private fixture payload'));
        $linkedDirectory = $this->fixtureRoot.'/linked-directory';
        $this->assertTrue(symlink($actualDirectory, $linkedDirectory));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('symbolic link');

        (new UpdatePackageValidator)->validate(
            $this->manifest($original),
            '1.1.0',
            $linkedDirectory.'/update.zip',
        );
    }

    public function test_dangling_symlink_is_rejected_before_package_availability_check(): void
    {
        $linked = $this->fixtureRoot.'/dangling.zip';
        $this->assertTrue(symlink($this->fixtureRoot.'/does-not-exist.zip', $linked));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('symbolic link');

        (new UpdatePackageValidator)->validate(
            new UpdatePackageManifest('1.2.0', '1.0.0', str_repeat('a', 64), 'Release', true),
            '1.1.0',
            $linked,
        );
    }

    private function originalPackage(): string
    {
        $path = $this->fixtureRoot.'/original.zip';
        $this->assertNotFalse(file_put_contents($path, 'private fixture payload'));

        return $path;
    }

    private function manifest(string $path): UpdatePackageManifest
    {
        return new UpdatePackageManifest(
            '1.2.0',
            '1.0.0',
            hash_file('sha256', $path),
            'Release',
            true,
        );
    }
}
