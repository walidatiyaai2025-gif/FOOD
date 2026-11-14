<?php

namespace Tests\Feature;

use App\Domain\Updater\LaravelUpdateRuntime;
use App\Domain\Updater\UpdatePackageManifest;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Tests\TestCase;
use ZipArchive;

final class V2UpdaterZipMemberCountBudgetTest extends TestCase
{
    private string $slug;

    private string $backupRoot;

    /** @var array<int, string> */
    private array $packages = [];

    protected function setUp(): void
    {
        parent::setUp();

        if (! class_exists(ZipArchive::class)) {
            $this->markTestSkipped('ZIP extension is required to inspect updater member budgets.');
        }

        $this->slug = 'v2-zip-members-'.bin2hex(random_bytes(7));
        $this->backupRoot = storage_path('framework/testing/'.$this->slug.'-backups');

        File::ensureDirectoryExists($this->backupRoot);
        File::ensureDirectoryExists($this->releaseFolder());
        file_put_contents($this->releaseFolder().'/style.css', 'original');

        config([
            'foodex.update_backup_path' => $this->backupRoot,
            'foodex.update_max_zip_members' => 3,
        ]);
    }

    protected function tearDown(): void
    {
        foreach ($this->packages as $package) {
            @unlink($package);
        }

        if (isset($this->slug)) {
            File::deleteDirectory($this->releaseFolder());
        }

        if (isset($this->backupRoot)) {
            File::deleteDirectory($this->backupRoot);
        }

        parent::tearDown();
    }

    public function test_zero_byte_files_over_member_limit_fail_before_any_release_mutation(): void
    {
        $package = $this->package([
            $this->relative('style.css') => 'modified',
            $this->relative('empty-a.txt') => '',
            $this->relative('empty-b.txt') => '',
            $this->relative('empty-c.txt') => '',
        ]);

        $this->assertOverBudgetRejectedAtEveryEntryPoint($package);
    }

    public function test_directory_entries_also_count_toward_archive_member_budget(): void
    {
        $package = $this->package([
            $this->relative('empty-a/') => '',
            $this->relative('empty-b/') => '',
            $this->relative('empty-c/') => '',
            $this->relative('style.css') => 'modified',
        ]);

        $this->assertOverBudgetRejectedAtEveryEntryPoint($package);
    }

    public function test_exact_member_limit_allows_backup_install_and_rollback(): void
    {
        $package = $this->package([
            $this->relative('style.css') => 'modified',
            $this->relative('empty-a.txt') => '',
            $this->relative('empty-b.txt') => '',
        ]);

        $runtime = app(LaravelUpdateRuntime::class);
        $runtime->preflight($this->manifest($package), $package);
        $backup = $runtime->backupFiles($package);
        $runtime->extractRelease($package);

        $this->assertSame('modified', file_get_contents($this->releaseFolder().'/style.css'));
        $this->assertSame(0, filesize($this->releaseFolder().'/empty-a.txt'));
        $this->assertSame(0, filesize($this->releaseFolder().'/empty-b.txt'));

        $runtime->rollbackFiles($backup);

        $this->assertSame('original', file_get_contents($this->releaseFolder().'/style.css'));
        $this->assertFileDoesNotExist($this->releaseFolder().'/empty-a.txt');
        $this->assertFileDoesNotExist($this->releaseFolder().'/empty-b.txt');
    }

    public function test_nonpositive_config_does_not_disable_member_budget(): void
    {
        config(['foodex.update_max_zip_members' => 0]);

        $package = $this->package([
            $this->relative('style.css') => 'modified',
            $this->relative('empty.txt') => '',
        ]);

        $this->assertOverBudgetRejectedAtEveryEntryPoint($package);
    }

    private function assertOverBudgetRejectedAtEveryEntryPoint(string $package): void
    {
        $runtime = app(LaravelUpdateRuntime::class);

        foreach ([
            fn (): mixed => $runtime->preflight($this->manifest($package), $package),
            fn (): mixed => $runtime->backupFiles($package),
            fn (): mixed => $runtime->extractRelease($package),
        ] as $operation) {
            try {
                $operation();
                $this->fail('An oversized ZIP member list must fail before mutating release data.');
            } catch (RuntimeException $exception) {
                $this->assertSame(
                    'Update archive exceeds the allowed member count.',
                    $exception->getMessage(),
                );
            }
        }

        $this->assertSame('original', file_get_contents($this->releaseFolder().'/style.css'));
        $this->assertFileDoesNotExist($this->releaseFolder().'/empty-a.txt');
        $this->assertFileDoesNotExist($this->releaseFolder().'/empty-b.txt');
        $this->assertFileDoesNotExist($this->releaseFolder().'/empty-c.txt');
        $this->assertSame([], glob($this->backupRoot.'/*') ?: []);
    }

    /**
     * @param  array<string, string>  $entries
     */
    private function package(array $entries): string
    {
        $path = tempnam(sys_get_temp_dir(), 'foodex-zip-members-');
        if ($path === false) {
            throw new RuntimeException('Unable to allocate synthetic update ZIP.');
        }

        $this->packages[] = $path;
        $archive = new ZipArchive;
        $this->assertTrue($archive->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true);

        foreach ($entries as $name => $contents) {
            $this->assertTrue($archive->addFromString($name, $contents));
        }

        $this->assertTrue($archive->close());

        return $path;
    }

    private function relative(string $name): string
    {
        return 'assets/'.$this->slug.'/'.$name;
    }

    private function releaseFolder(): string
    {
        return dirname(base_path()).'/assets/'.$this->slug;
    }

    private function manifest(string $package): UpdatePackageManifest
    {
        return new UpdatePackageManifest(
            '99.0.0',
            '1.0.0',
            (string) hash_file('sha256', $package),
            'Synthetic member count budget',
            false,
        );
    }
}
