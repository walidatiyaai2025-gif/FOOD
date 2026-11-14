<?php

namespace Tests\Feature;

use App\Domain\Updater\UpdatePackageManifest;
use App\Domain\Updater\UpdatePackageValidator;
use InvalidArgumentException;
use Tests\TestCase;
use ZipArchive;

final class V2UpdateValidatorMemberCountPreflightTest extends TestCase
{
    private string $packagePath;

    private string $sentinelPath;

    protected function setUp(): void
    {
        parent::setUp();

        if (! class_exists(ZipArchive::class)) {
            $this->markTestSkipped('ZIP extension is required for updater package validation.');
        }

        $packagePath = tempnam(sys_get_temp_dir(), 'v2-update-validator-');
        $sentinelPath = tempnam(sys_get_temp_dir(), 'v2-update-sentinel-');

        if ($packagePath === false || $sentinelPath === false) {
            throw new \RuntimeException('Unable to create isolated updater test files.');
        }

        $this->packagePath = $packagePath;
        $this->sentinelPath = $sentinelPath;
        file_put_contents($this->sentinelPath, 'unchanged');
        config(['foodex.update_max_zip_members' => 3]);
    }

    protected function tearDown(): void
    {
        if (isset($this->packagePath)) {
            @unlink($this->packagePath);
        }

        if (isset($this->sentinelPath)) {
            @unlink($this->sentinelPath);
        }

        parent::tearDown();
    }

    public function test_validator_denies_four_zero_byte_members_before_migration_scan(): void
    {
        $this->createArchive([
            'assets/empty-a.txt' => '',
            'assets/empty-b.txt' => '',
            'assets/empty-c.txt' => '',
            'assets/empty-d.txt' => '',
        ]);

        $this->assertMemberBudgetDenied(false);
    }

    public function test_directory_members_count_and_declared_migrations_cannot_bypass_limit(): void
    {
        $this->createArchive([
            'assets/empty-a/' => '',
            'assets/empty-b/' => '',
            'assets/empty-c/' => '',
            'assets/empty-d/' => '',
        ]);

        $this->assertMemberBudgetDenied(true);
    }

    public function test_zero_config_is_clamped_to_one_and_cannot_disable_budget(): void
    {
        config(['foodex.update_max_zip_members' => 0]);

        $this->createArchive([
            'assets/first.txt' => '',
            'assets/second.txt' => '',
        ]);

        $this->assertMemberBudgetDenied(false);
    }

    public function test_exact_three_member_budget_preserves_normal_package_validation(): void
    {
        $this->createArchive([
            'assets/css/' => '',
            'assets/css/app.css' => 'body { color: green; }',
            'assets/app.js' => 'console.log("ready");',
        ]);

        $result = (new UpdatePackageValidator)->validate($this->manifest(false), '1.1.0', $this->packagePath);

        $this->assertSame('1.1.0', $result['current_version']);
        $this->assertSame('1.2.0', $result['target_version']);
        $this->assertSame(hash_file('sha256', $this->packagePath), $result['package_hash']);
        $this->assertFalse($result['contains_migrations']);
    }

    public function test_hidden_migration_detection_remains_enforced_under_budget(): void
    {
        $this->createArchive([
            'assets/app.js' => 'ready',
            ' backend\database\migrations\2026_11_14_000000_test.php ' => '<?php // fixture',
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('contains migrations');

        (new UpdatePackageValidator)->validate($this->manifest(false), '1.1.0', $this->packagePath);
    }

    public function test_non_zip_payload_is_rejected_even_with_matching_hash_and_valid_versions(): void
    {
        file_put_contents($this->packagePath, 'non-zip test payload');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('not a readable ZIP archive');

        (new UpdatePackageValidator)->validate($this->manifest(false), '1.1.0', $this->packagePath);
    }

    private function assertMemberBudgetDenied(bool $declaresMigrations): void
    {
        try {
            (new UpdatePackageValidator)->validate($this->manifest($declaresMigrations), '1.1.0', $this->packagePath);
            $this->fail('The validator must reject ZIP member lists over the configured count.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('Update archive exceeds the allowed member count.', $exception->getMessage());
        }

        $this->assertSame('unchanged', file_get_contents($this->sentinelPath));
    }

    private function manifest(bool $containsMigrations): UpdatePackageManifest
    {
        return new UpdatePackageManifest(
            '1.2.0',
            '1.0.0',
            (string) hash_file('sha256', $this->packagePath),
            'Synthetic ZIP member budget test',
            $containsMigrations,
        );
    }

    /**
     * @param  array<string, string>  $entries
     */
    private function createArchive(array $entries): void
    {
        $archive = new ZipArchive;
        $this->assertTrue($archive->open($this->packagePath, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true);

        foreach ($entries as $name => $contents) {
            if (str_ends_with($name, '/')) {
                $this->assertTrue($archive->addEmptyDir(rtrim($name, '/')));
            } else {
                $this->assertTrue($archive->addFromString($name, $contents));
            }
        }

        $this->assertTrue($archive->close());
    }
}
