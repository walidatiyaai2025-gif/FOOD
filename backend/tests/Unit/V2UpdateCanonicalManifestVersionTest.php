<?php

namespace Tests\Unit;

use App\Domain\Updater\UpdatePackageManifest;
use App\Domain\Updater\UpdatePackageValidator;
use App\Domain\Updater\UpdateVersion;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class V2UpdateCanonicalManifestVersionTest extends TestCase
{
    private string $packagePath;

    protected function setUp(): void
    {
        parent::setUp();

        $temporary = tempnam(sys_get_temp_dir(), 'foodex-v2-manifest-');
        if ($temporary === false) {
            throw new RuntimeException('Could not create isolated update test package.');
        }

        $this->packagePath = $temporary;
        file_put_contents($this->packagePath, 'synthetic package for version verification');
    }

    protected function tearDown(): void
    {
        @unlink($this->packagePath);

        parent::tearDown();
    }

    public function test_canonical_version_check_rejects_padding_and_noncanonical_forms(): void
    {
        foreach (['0.0.0', '1.0.84', '12.345.678', '1.2.3'] as $version) {
            $this->assertTrue(UpdateVersion::isCanonical($version), $version);
        }

        foreach ([
            ' 1.2.3', '1.2.3 ', "\t1.2.3", "1.2.3\n", "1.2.3\r",
            '1.02.3', '01.2.3', '1.2.03', '1.2', 'v1.2.3', '1.2.3-rc1',
        ] as $version) {
            $this->assertFalse(UpdateVersion::isCanonical($version), json_encode($version, JSON_THROW_ON_ERROR));
        }
    }

    public function test_validator_rejects_padding_in_each_signed_manifest_version_field(): void
    {
        foreach ([
            [' 1.0.85', '1.0.84'],
            ['1.0.85 ', '1.0.84'],
            ["1.0.85\n", '1.0.84'],
            ['1.0.85', ' 1.0.84'],
            ['1.0.85', '1.0.84 '],
            ['1.0.85', "\t1.0.84"],
        ] as [$target, $minimum]) {
            $manifest = $this->manifest($target, $minimum);

            try {
                (new UpdatePackageValidator)->validate($manifest, '1.0.84', $this->packagePath);
                $this->fail('Manifest version padding must be rejected.');
            } catch (InvalidArgumentException $exception) {
                $this->assertSame(
                    'Update versions must use canonical major.minor.patch format.',
                    $exception->getMessage(),
                );
            }
        }
    }

    public function test_valid_manifest_retains_legacy_installed_normalization(): void
    {
        $this->assertSame('1.0.84', UpdateVersion::normalizeInstalled(" 1.084 "));

        $result = (new UpdatePackageValidator)->validate(
            $this->manifest('1.0.85', '1.0.84'),
            " 1.084 ",
            $this->packagePath,
        );

        $this->assertSame('1.0.84', $result['current_version']);
        $this->assertSame('1.0.85', $result['target_version']);
        $this->assertSame(hash_file('sha256', $this->packagePath), $result['package_hash']);
    }

    public function test_downgrade_and_unsupported_installed_version_still_fail(): void
    {
        foreach ([
            [$this->manifest('1.0.84', '1.0.80'), '1.0.84', 'Target version must be newer'],
            [$this->manifest('1.0.85', '1.0.84'), '1.0.83', 'Current version is not supported'],
        ] as [$manifest, $installed, $message]) {
            try {
                (new UpdatePackageValidator)->validate($manifest, $installed, $this->packagePath);
                $this->fail('Unsafe update should have been rejected.');
            } catch (InvalidArgumentException $exception) {
                $this->assertStringContainsString($message, $exception->getMessage());
            }
        }
    }

    private function manifest(string $target, string $minimum): UpdatePackageManifest
    {
        return new UpdatePackageManifest(
            $target,
            $minimum,
            hash_file('sha256', $this->packagePath),
            'Synthetic version-only regression',
            true,
        );
    }
}
