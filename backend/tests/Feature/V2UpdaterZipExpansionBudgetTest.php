<?php

namespace Tests\Feature;

use App\Domain\Updater\LaravelUpdateRuntime;
use App\Domain\Updater\UpdatePackageManifest;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Tests\TestCase;
use ZipArchive;

final class V2UpdaterZipExpansionBudgetTest extends TestCase
{
    private string $slug;

    private string $backupRoot;

    /** @var array<int, string> */
    private array $packages = [];

    protected function setUp(): void
    {
        parent::setUp();

        if (! class_exists(ZipArchive::class)) {
            $this->markTestSkipped('ZIP extension is required for Update ZIP expansion tests.');
        }

        $this->slug = 'v2-zip-expansion-'.bin2hex(random_bytes(7));
        $this->backupRoot = storage_path('framework/testing/'.$this->slug.'-backups');
        File::ensureDirectoryExists($this->backupRoot);
        File::ensureDirectoryExists($this->releaseFolder());
        $this->assertNotFalse(file_put_contents($this->releaseFolder().'/style.css', 'original'));

        config([
            'foodex.update_backup_path' => $this->backupRoot,
            'foodex.update_max_entry_uncompressed_bytes' => 1024,
            'foodex.update_max_total_uncompressed_bytes' => 4096,
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

    public function test_highly_compressible_oversized_member_fails_all_entry_points_without_mutations(): void
    {
        $package = $this->package([
            $this->relative('style.css') => 'replacement',
            $this->relative('bomb.txt') => str_repeat('X', 8192),
        ]);

        // ZIP compression must not make the expanded-size check ineffective.
        $this->assertLessThan(8192, filesize($package));

        $this->assertOversizedPackageFailsClosed($package);
    }

    public function test_aggregate_expansion_budget_rejects_multiple_individually_valid_members(): void
    {
        config(['foodex.update_max_total_uncompressed_bytes' => 1500]);
        $package = $this->package([
            $this->relative('style.css') => 'replacement',
            $this->relative('part-a.txt') => str_repeat('A', 900),
            $this->relative('part-b.txt') => str_repeat('B', 900),
        ]);

        $this->assertOversizedPackageFailsClosed($package);
    }

    public function test_safe_small_zip_preserves_backup_activation_and_rollback(): void
    {
        $package = $this->package([
            $this->relative('style.css') => 'replacement',
            $this->relative('new.txt') => 'a safe new file',
        ]);

        $runtime = $this->runtime();
        $runtime->preflight($this->manifest($package), $package);
        $backup = $runtime->backupFiles($package);
        $runtime->extractRelease($package);

        $this->assertSame('replacement', file_get_contents($this->releaseFolder().'/style.css'));
        $this->assertSame('a safe new file', file_get_contents($this->releaseFolder().'/new.txt'));

        $runtime->rollbackFiles($backup);

        $this->assertSame('original', file_get_contents($this->releaseFolder().'/style.css'));
        $this->assertFileDoesNotExist($this->releaseFolder().'/new.txt');
    }

    public function test_normal_directory_entries_do_not_consume_expanded_file_budget(): void
    {
        $package = $this->package([
            $this->relative('child/') => '',
            $this->relative('style.css') => 'replacement',
        ]);

        $this->runtime()->preflight($this->manifest($package), $package);
        $this->assertSame('original', file_get_contents($this->releaseFolder().'/style.css'));
        $this->assertSame([], glob($this->backupRoot.'/*') ?: []);
    }

    private function assertOversizedPackageFailsClosed(string $package): void
    {
        foreach ([
            fn (): mixed => $this->runtime()->preflight($this->manifest($package), $package),
            fn (): mixed => $this->runtime()->backupFiles($package),
            fn (): mixed => $this->runtime()->extractRelease($package),
        ] as $operation) {
            try {
                $operation();
                $this->fail('Expanded Update ZIP budget must reject before release-file writes.');
            } catch (RuntimeException $exception) {
                $this->assertSame('Update archive exceeds the allowed expanded size.', $exception->getMessage());
            }
        }

        $this->assertSame('original', file_get_contents($this->releaseFolder().'/style.css'));
        $this->assertFileDoesNotExist($this->releaseFolder().'/bomb.txt');
        $this->assertFileDoesNotExist($this->releaseFolder().'/part-a.txt');
        $this->assertFileDoesNotExist($this->releaseFolder().'/part-b.txt');
        $this->assertSame([], glob($this->backupRoot.'/*') ?: []);
    }

    /**
     * @param  array<string, string>  $entries
     */
    private function package(array $entries): string
    {
        $path = tempnam(sys_get_temp_dir(), 'foodex-zip-expansion-');
        if ($path === false) {
            throw new RuntimeException('Could not allocate synthetic ZIP.');
        }

        $this->packages[] = $path;
        $archive = new ZipArchive;
        $this->assertTrue($archive->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true);

        foreach ($entries as $name => $value) {
            $this->assertTrue($archive->addFromString($name, $value));
            if (! str_ends_with($name, '/')) {
                $this->assertTrue($archive->setCompressionName($name, ZipArchive::CM_DEFLATE));
            }
        }

        $this->assertTrue($archive->close());

        return $path;
    }

    private function releaseFolder(): string
    {
        return dirname(base_path()).'/assets/'.$this->slug;
    }

    private function relative(string $name): string
    {
        return 'assets/'.$this->slug.'/'.$name;
    }

    private function manifest(string $path): UpdatePackageManifest
    {
        return new UpdatePackageManifest(
            '99.0.0',
            '1.0.0',
            (string) hash_file('sha256', $path),
            'Synthetic Update ZIP expansion test',
            false,
        );
    }

    private function runtime(): LaravelUpdateRuntime
    {
        return app(LaravelUpdateRuntime::class);
    }
}
