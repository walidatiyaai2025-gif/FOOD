<?php

namespace Tests\Feature;

use App\Domain\Updater\UpdatePackageManifest;
use App\Domain\Updater\UpdatePackageValidator;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;
use ZipArchive;

final class V2UpdaterValidatorMemberBudgetTest extends TestCase
{
    /** @var list<string> */
    private array $packages = [];

    protected function setUp(): void
    {
        parent::setUp();

        if (! class_exists(ZipArchive::class)) {
            $this->markTestSkipped('ZIP extension is required for updater archive validator tests.');
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->packages as $package) {
            @unlink($package);
        }

        parent::tearDown();
    }

    public function test_zero_byte_and_directory_members_are_bounded_before_migration_scan(): void
    {
        config(['foodex.update_max_zip_members' => 2]);
        $package = $this->package([
            'assets/empty-dir/' => '',
            'assets/empty-a.txt' => '',
            'assets/empty-b.txt' => '',
        ]);

        $this->assertRejectedWithoutPackageMutation($package);
    }

    public function test_exact_member_budget_accepts_a_normal_migration_free_archive(): void
    {
        config(['foodex.update_max_zip_members' => 3]);
        $package = $this->package([
            'assets/empty-dir/' => '',
            'assets/empty-a.txt' => '',
            'assets/empty-b.txt' => '',
        ]);

        $result = (new UpdatePackageValidator)->validate($this->manifest($package), '1.0.0', $package);

        $this->assertSame('1.0.0', $result['current_version']);
        $this->assertSame('1.1.0', $result['target_version']);
        $this->assertSame(hash_file('sha256', $package), $result['package_hash']);
        $this->assertFalse($result['contains_migrations']);
    }

    public function test_nonpositive_budget_cannot_disable_member_count_guard(): void
    {
        $package = $this->package([
            'assets/empty-a.txt' => '',
            'assets/empty-b.txt' => '',
        ]);

        foreach ([0, -5] as $invalidLimit) {
            config(['foodex.update_max_zip_members' => $invalidLimit]);
            $this->assertRejectedWithoutPackageMutation($package);
        }
    }

    public function test_migration_under_count_limit_is_still_rejected_when_not_declared(): void
    {
        config(['foodex.update_max_zip_members' => 2]);
        $package = $this->package([
            'assets/style.css' => 'safe',
            'backend/database/migrations/2026_11_14_000001_add_example_table.php' => '<?php',
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Update package contains migrations but database backup was not declared.');

        (new UpdatePackageValidator)->validate($this->manifest($package), '1.0.0', $package);
    }

    private function assertRejectedWithoutPackageMutation(string $package): void
    {
        $originalHash = hash_file('sha256', $package);

        try {
            (new UpdatePackageValidator)->validate($this->manifest($package), '1.0.0', $package);
            $this->fail('An Update ZIP with too many central-directory records must be rejected.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('Update archive exceeds the allowed member count.', $exception->getMessage());
        }

        $this->assertSame($originalHash, hash_file('sha256', $package));
    }

    /**
     * @param  array<string, string>  $entries
     */
    private function package(array $entries): string
    {
        $path = tempnam(sys_get_temp_dir(), 'foodex-v2-validator-members-');
        if ($path === false) {
            throw new RuntimeException('Could not allocate synthetic update archive.');
        }

        $this->packages[] = $path;
        $archive = new ZipArchive;
        $this->assertTrue($archive->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true);

        foreach ($entries as $name => $value) {
            if (str_ends_with($name, '/')) {
                $this->assertTrue($archive->addEmptyDir($name));
            } else {
                $this->assertTrue($archive->addFromString($name, $value));
            }
        }

        $this->assertTrue($archive->close());

        return $path;
    }

    private function manifest(string $package): UpdatePackageManifest
    {
        return new UpdatePackageManifest(
            '1.1.0',
            '1.0.0',
            (string) hash_file('sha256', $package),
            'Issue 3451 ZIP validation member budget regression',
            false,
        );
    }
}
