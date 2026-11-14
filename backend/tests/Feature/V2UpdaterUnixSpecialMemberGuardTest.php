<?php

namespace Tests\Feature;

use App\Domain\Updater\LaravelUpdateRuntime;
use App\Domain\Updater\UpdatePackageManifest;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Tests\TestCase;
use ZipArchive;

final class V2UpdaterUnixSpecialMemberGuardTest extends TestCase
{
    private string $slug;

    private string $backupRoot;

    /** @var array<int, string> */
    private array $packages = [];

    protected function setUp(): void
    {
        parent::setUp();

        if (! class_exists(ZipArchive::class)) {
            $this->markTestSkipped('ZIP extension is required for Unix ZIP member mode tests.');
        }

        $this->slug = 'v2-unix-mode-'.bin2hex(random_bytes(7));
        $this->backupRoot = storage_path('framework/testing/'.$this->slug.'-backups');
        File::ensureDirectoryExists($this->backupRoot);
        File::ensureDirectoryExists($this->releaseFolder());
        config(['foodex.update_backup_path' => $this->backupRoot]);
        $this->assertNotFalse(file_put_contents($this->releaseFolder().'/style.css', 'original'));
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

    public function test_special_unix_file_modes_fail_before_backup_or_file_extraction(): void
    {
        foreach ([
            'fifo' => 0010000,
            'character_device' => 0020000,
            'block_device' => 0060000,
            'socket' => 0140000,
            'disguised_directory' => 0040000,
        ] as $kind => $mode) {
            $package = $this->package([
                $this->relative('style.css') => 'replacement',
                $this->relative('special.dat') => 'synthetic payload',
            ], $this->relative('special.dat'), $mode);

            foreach ([
                fn (): mixed => $this->runtime()->preflight($this->manifest($package), $package),
                fn (): mixed => $this->runtime()->backupFiles($package),
                fn (): mixed => $this->runtime()->extractRelease($package),
            ] as $action) {
                try {
                    $action();
                    $this->fail('Non-regular Unix ZIP member must fail closed: '.$kind);
                } catch (RuntimeException $exception) {
                    $this->assertSame('Update archive contains a non-regular file entry.', $exception->getMessage());
                }
            }

            $this->assertSame('original', file_get_contents($this->releaseFolder().'/style.css'));
            $this->assertFileDoesNotExist($this->releaseFolder().'/special.dat');
            $this->assertSame([], glob($this->backupRoot.'/*') ?: []);
        }
    }

    public function test_symlink_mode_preserves_the_existing_specific_failure(): void
    {
        $package = $this->package([
            $this->relative('symbolic.txt') => 'synthetic target',
        ], $this->relative('symbolic.txt'), 0120000);

        try {
            $this->runtime()->preflight($this->manifest($package), $package);
            $this->fail('Unix ZIP symlinks must fail closed.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Update archive contains a symbolic link.', $exception->getMessage());
        }

        $this->assertFileDoesNotExist($this->releaseFolder().'/symbolic.txt');
    }

    public function test_regular_and_unspecified_unix_modes_preserve_safe_update_and_rollback(): void
    {
        foreach ([0100000, 0] as $type) {
            $package = $this->package([
                $this->relative('style.css') => 'replacement',
                $this->relative('new.txt') => 'synthetic addition',
            ], $this->relative('new.txt'), $type);

            $runtime = $this->runtime();
            $runtime->preflight($this->manifest($package), $package);
            $backup = $runtime->backupFiles($package);
            $runtime->extractRelease($package);

            $this->assertSame('replacement', file_get_contents($this->releaseFolder().'/style.css'));
            $this->assertSame('synthetic addition', file_get_contents($this->releaseFolder().'/new.txt'));

            $runtime->rollbackFiles($backup);

            $this->assertSame('original', file_get_contents($this->releaseFolder().'/style.css'));
            $this->assertFileDoesNotExist($this->releaseFolder().'/new.txt');
        }
    }

    public function test_normal_unix_directory_entries_are_still_skipped(): void
    {
        $package = $this->package([
            $this->relative('folder/') => '',
            $this->relative('style.css') => 'replacement',
        ], $this->relative('folder/'), 0040000);

        $this->runtime()->preflight($this->manifest($package), $package);
        $this->assertSame('original', file_get_contents($this->releaseFolder().'/style.css'));
        $this->assertSame([], glob($this->backupRoot.'/*') ?: []);
    }

    /** @param array<string, string> $entries */
    private function package(array $entries, ?string $modePath = null, int $mode = 0): string
    {
        $path = tempnam(sys_get_temp_dir(), 'foodex-v2-unix-mode-');
        if ($path === false) {
            throw new RuntimeException('Could not allocate a synthetic ZIP.');
        }

        $this->packages[] = $path;
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true);

        foreach ($entries as $name => $value) {
            $this->assertTrue($zip->addFromString($name, $value));
        }

        if ($modePath !== null) {
            $this->assertTrue($zip->setExternalAttributesName(
                $modePath,
                ZipArchive::OPSYS_UNIX,
                ($mode | 0644) << 16,
            ));
        }

        $this->assertTrue($zip->close());

        return $path;
    }

    private function releaseFolder(): string
    {
        return dirname(base_path()).'/assets/'.$this->slug;
    }

    private function relative(string $file): string
    {
        return 'assets/'.$this->slug.'/'.$file;
    }

    private function manifest(string $path): UpdatePackageManifest
    {
        return new UpdatePackageManifest(
            '99.0.0',
            '1.0.0',
            (string) hash_file('sha256', $path),
            'Synthetic Unix mode rejection regression',
            false,
        );
    }

    private function runtime(): LaravelUpdateRuntime
    {
        return app(LaravelUpdateRuntime::class);
    }
}
