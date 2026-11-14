<?php

namespace Tests\Unit;

use App\Domain\Updater\UpdatePackageManifest;
use App\Domain\Updater\UpdatePackageValidator;
use App\Domain\Updater\UpdateVersion;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class V2LegacyInstalledVersionPrecisionTest extends TestCase
{
    private string $packagePath;

    protected function setUp(): void
    {
        parent::setUp();

        $path = tempnam(sys_get_temp_dir(), 'v2-versions-');
        self::assertIsString($path);
        $this->packagePath = $path;
        file_put_contents($path, 'test-only release content');
    }

    protected function tearDown(): void
    {
        @unlink($this->packagePath);
        parent::tearDown();
    }

    public function test_normal_compact_versions_remain_backwards_compatible(): void
    {
        self::assertSame('1.0.84', UpdateVersion::normalizeInstalled('1.084'));
        self::assertSame('1.0.124', UpdateVersion::normalizeInstalled('1.00124'));
        self::assertSame('1.0.0', UpdateVersion::normalizeInstalled('1.000'));
        self::assertSame('1.0.124', UpdateVersion::normalizeInstalled('1.0.124'));
    }

    public function test_oversized_legacy_major_and_patch_are_never_truncated_to_php_int_max(): void
    {
        $major = str_repeat('9', 30);
        $patch = str_repeat('9', 35);

        self::assertSame('1.0.'.$patch, UpdateVersion::normalizeInstalled('1.0'.$patch));
        self::assertSame($major.'.0.124', UpdateVersion::normalizeInstalled($major.'.0124'));
        self::assertSame('1.0.1', UpdateVersion::normalizeInstalled('1.0'.str_repeat('0', 25).'1'));
    }

    public function test_decimal_semver_comparison_is_exact_beyond_machine_integer_limits(): void
    {
        self::assertSame(-1, UpdateVersion::compareCanonical('1.0.9223372036854775807', '1.0.9223372036854775808'));
        self::assertSame(1, UpdateVersion::compareCanonical('1.0.9223372036854775808', '1.0.9223372036854775807'));
        self::assertSame(-1, UpdateVersion::compareCanonical('1.0.'.str_repeat('9', 30), '1.0.1'.str_repeat('0', 30)));
        self::assertSame(1, UpdateVersion::compareCanonical('1.'.str_repeat('9', 30).'.0', '1.'.str_repeat('8', 30).'.999'));
        self::assertSame(0, UpdateVersion::compareCanonical('1.0.127', '1.0.127'));
    }

    public function test_noncanonical_versions_cannot_be_compared(): void
    {
        $this->expectException(InvalidArgumentException::class);
        UpdateVersion::compareCanonical('1.0.0127', '1.0.127');
    }

    public function test_update_validator_accepts_true_forward_compatibility_for_huge_legacy_version(): void
    {
        $patch = str_repeat('9', 30);
        $manifest = $this->manifest('1.0.1'.str_repeat('0', 30), '1.0.'.$patch);

        $result = (new UpdatePackageValidator)->validate($manifest, '1.0'.$patch, $this->packagePath);

        self::assertSame('1.0.'.$patch, $result['current_version']);
        self::assertSame($manifest->targetVersion, $result['target_version']);
    }

    public function test_update_validator_rejects_minimum_greater_than_true_installed_version(): void
    {
        $patch = str_repeat('9', 30);
        $manifest = $this->manifest('1.0.2'.str_repeat('0', 30), '1.0.1'.str_repeat('0', 30));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Current version is not supported');

        (new UpdatePackageValidator)->validate($manifest, '1.0'.$patch, $this->packagePath);
    }

    public function test_update_validator_rejects_actual_downgrade_beyond_machine_integer_range(): void
    {
        $patch = str_repeat('9', 30);
        $manifest = $this->manifest('1.0.9223372036854775808', '1.0.0');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Target version must be newer');

        (new UpdatePackageValidator)->validate($manifest, '1.0'.$patch, $this->packagePath);
    }

    private function manifest(string $target, string $minimum): UpdatePackageManifest
    {
        return new UpdatePackageManifest(
            $target,
            $minimum,
            hash_file('sha256', $this->packagePath),
            'Test-only release',
            false,
        );
    }
}
