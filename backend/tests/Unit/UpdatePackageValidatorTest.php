<?php

namespace Tests\Unit;

use App\Domain\Updater\UpdatePackageManifest;
use App\Domain\Updater\UpdatePackageValidator;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class UpdatePackageValidatorTest extends TestCase
{
    private string $packagePath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->packagePath = tempnam(sys_get_temp_dir(), 'foodex-update-');
        file_put_contents($this->packagePath, 'signed release payload');
    }

    protected function tearDown(): void
    {
        @unlink($this->packagePath);
        parent::tearDown();
    }

    public function test_valid_package_passes_integrity_and_compatibility_checks(): void
    {
        $manifest = new UpdatePackageManifest('1.2.0', '1.0.0', hash_file('sha256', $this->packagePath), 'Release', true);
        $result = (new UpdatePackageValidator)->validate($manifest, '1.1.0', $this->packagePath);
        $this->assertSame('1.2.0', $result['target_version']);
        $this->assertTrue($result['contains_migrations']);
    }

    public function test_tampered_package_is_rejected(): void
    {
        $manifest = new UpdatePackageManifest('1.2.0', '1.0.0', str_repeat('a', 64), 'Release', false);
        $this->expectException(InvalidArgumentException::class);
        (new UpdatePackageValidator)->validate($manifest, '1.1.0', $this->packagePath);
    }

    public function test_incompatible_current_version_is_rejected(): void
    {
        $manifest = new UpdatePackageManifest('2.0.0', '1.5.0', hash_file('sha256', $this->packagePath), 'Release', false);
        $this->expectException(InvalidArgumentException::class);
        (new UpdatePackageValidator)->validate($manifest, '1.4.9', $this->packagePath);
    }

    public function test_downgrade_or_same_version_is_rejected(): void
    {
        $manifest = new UpdatePackageManifest('1.1.0', '1.0.0', hash_file('sha256', $this->packagePath), 'Release', false);
        $this->expectException(InvalidArgumentException::class);
        (new UpdatePackageValidator)->validate($manifest, '1.1.0', $this->packagePath);
    }
}
