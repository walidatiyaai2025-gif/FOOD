<?php

namespace Tests\Feature;

use App\Domain\Updater\LaravelUpdateRuntime;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Tests\TestCase;
use ZipArchive;

final class V2UpdaterFileBackupDigestTest extends TestCase
{
    private string $slug;

    private string $backupRoot;

    /** @var array<int, string> */
    private array $packages = [];

    protected function setUp(): void
    {
        parent::setUp();

        if (! class_exists(ZipArchive::class)) {
            $this->markTestSkipped('ZIP extension is required for file backup digest fixtures.');
        }

        $this->slug = 'v2-file-digest-'.bin2hex(random_bytes(7));
        $this->backupRoot = storage_path('framework/testing/'.$this->slug.'-backups');
        File::ensureDirectoryExists($this->releaseFolder());
        File::ensureDirectoryExists($this->backupRoot);
        config()->set('foodex.update_backup_path', $this->backupRoot);
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

    public function test_new_snapshot_hashes_actual_copied_regular_and_empty_files_and_restores_them(): void
    {
        $this->live('first.txt', 'original');
        $this->live('empty.txt', '');

        $package = $this->package([
            $this->relative('first.txt') => 'updated',
            $this->relative('empty.txt') => 'updated-empty',
            $this->relative('created.txt') => 'new',
        ]);

        $runtime = $this->runtime();
        $backup = $runtime->backupFiles($package);
        $manifest = $this->readManifest($backup);
        $this->assertSame(1, $manifest->file_snapshot_format);
        $this->assertInstanceOf(\stdClass::class, $manifest->file_sha256);
        $this->assertSame(
            hash('sha256', 'original'),
            $manifest->file_sha256->{$this->relative('first.txt')},
        );
        $this->assertSame(
            hash('sha256', ''),
            $manifest->file_sha256->{$this->relative('empty.txt')},
        );
        $this->assertCount(2, get_object_vars($manifest->file_sha256));

        $runtime->extractRelease($package);
        $runtime->rollbackFiles($backup);

        $this->assertSame('original', file_get_contents($this->releaseFolder().'/first.txt'));
        $this->assertSame('', file_get_contents($this->releaseFolder().'/empty.txt'));
        $this->assertFileDoesNotExist($this->releaseFolder().'/created.txt');
    }

    public function test_corrupt_backup_file_fails_before_removing_or_replacing_any_live_target(): void
    {
        [$package, $backup] = $this->preparedUpgrade();

        $source = $backup.'/existing/'.$this->relative('second.txt');
        file_put_contents($source, 'tampered-bytes');

        $this->assertRollbackRejected($backup, 'File rollback backup is incomplete or unsafe.');
        $this->assertUpdatedReleaseIntact();
        $this->assertFileExists($package);
    }

    public function test_truncated_backup_file_fails_before_removing_any_live_target(): void
    {
        [, $backup] = $this->preparedUpgrade();

        file_put_contents($backup.'/existing/'.$this->relative('first.txt'), '');

        $this->assertRollbackRejected($backup, 'File rollback backup is incomplete or unsafe.');
        $this->assertUpdatedReleaseIntact();
    }

    public function test_new_snapshot_with_missing_extra_or_malformed_hash_is_rejected_before_mutation(): void
    {
        [, $backup] = $this->preparedUpgrade();
        $original = $this->readManifest($backup);

        $variants = [];
        $missing = clone $original;
        $missing->file_sha256 = clone $original->file_sha256;
        unset($missing->file_sha256->{$this->relative('first.txt')});
        $variants[] = $missing;

        $extra = clone $original;
        $extra->file_sha256 = clone $original->file_sha256;
        $extra->file_sha256->{'assets/foreign.txt'} = str_repeat('a', 64);
        $variants[] = $extra;

        $invalid = clone $original;
        $invalid->file_sha256 = clone $original->file_sha256;
        $invalid->file_sha256->{$this->relative('second.txt')} = 'not-a-sha256';
        $variants[] = $invalid;

        $invalidType = clone $original;
        $invalidType->file_sha256 = [];
        $variants[] = $invalidType;

        $invalidVersion = clone $original;
        $invalidVersion->file_snapshot_format = 999;
        $variants[] = $invalidVersion;

        $missingMarker = clone $original;
        unset($missingMarker->file_snapshot_format);
        $variants[] = $missingMarker;

        $missingHashes = clone $original;
        unset($missingHashes->file_sha256);
        $variants[] = $missingHashes;

        foreach ($variants as $manifest) {
            $this->writeManifest($backup, $manifest);
            $this->assertRollbackRejected($backup, 'File rollback manifest is invalid.');
            $this->assertUpdatedReleaseIntact();
        }
    }

    public function test_preexisting_legacy_hashless_snapshot_can_still_restore_without_claiming_integrity(): void
    {
        [, $backup] = $this->preparedUpgrade();
        $manifest = $this->readManifest($backup);
        unset($manifest->file_snapshot_format, $manifest->file_sha256);
        $this->writeManifest($backup, $manifest);

        $this->runtime()->rollbackFiles($backup);
        $this->assertSame('first-original', file_get_contents($this->releaseFolder().'/first.txt'));
        $this->assertSame('second-original', file_get_contents($this->releaseFolder().'/second.txt'));
    }

    public function test_new_file_only_snapshot_emits_empty_digest_object_and_removes_new_file(): void
    {
        $package = $this->package([$this->relative('created.txt') => 'new']);
        $runtime = $this->runtime();
        $backup = $runtime->backupFiles($package);
        $manifest = $this->readManifest($backup);

        $this->assertSame(1, $manifest->file_snapshot_format);
        $this->assertInstanceOf(\stdClass::class, $manifest->file_sha256);
        $this->assertSame([], get_object_vars($manifest->file_sha256));

        $runtime->extractRelease($package);
        $this->assertFileExists($this->releaseFolder().'/created.txt');
        $runtime->rollbackFiles($backup);
        $this->assertFileDoesNotExist($this->releaseFolder().'/created.txt');
    }

    /** @return array{string, string} */
    private function preparedUpgrade(): array
    {
        $this->live('first.txt', 'first-original');
        $this->live('second.txt', 'second-original');

        $package = $this->package([
            $this->relative('first.txt') => 'first-updated',
            $this->relative('second.txt') => 'second-updated',
            $this->relative('created.txt') => 'newly-created',
        ]);

        $runtime = $this->runtime();
        $backup = $runtime->backupFiles($package);
        $runtime->extractRelease($package);

        $this->assertUpdatedReleaseIntact();

        return [$package, $backup];
    }

    private function assertUpdatedReleaseIntact(): void
    {
        $this->assertSame('first-updated', file_get_contents($this->releaseFolder().'/first.txt'));
        $this->assertSame('second-updated', file_get_contents($this->releaseFolder().'/second.txt'));
        $this->assertSame('newly-created', file_get_contents($this->releaseFolder().'/created.txt'));
    }

    private function assertRollbackRejected(string $backup, string $expected): void
    {
        try {
            $this->runtime()->rollbackFiles($backup);
            $this->fail('Corrupted versioned rollback must be rejected before any release mutation.');
        } catch (RuntimeException $exception) {
            $this->assertSame($expected, $exception->getMessage());
            $this->assertStringNotContainsString($backup, $exception->getMessage());
        }
    }

    private function readManifest(string $backup): \stdClass
    {
        $data = json_decode((string) file_get_contents($backup.'/files.json'), flags: JSON_THROW_ON_ERROR);
        $this->assertInstanceOf(\stdClass::class, $data);

        return $data;
    }

    private function writeManifest(string $backup, \stdClass $manifest): void
    {
        file_put_contents($backup.'/files.json', json_encode($manifest, JSON_THROW_ON_ERROR));
    }

    private function live(string $name, string $contents): void
    {
        file_put_contents($this->releaseFolder().'/'.$name, $contents);
    }

    private function relative(string $name): string
    {
        return 'assets/'.$this->slug.'/'.$name;
    }

    private function releaseFolder(): string
    {
        return dirname(base_path()).'/assets/'.$this->slug;
    }

    /** @param array<string, string> $entries */
    private function package(array $entries): string
    {
        $path = tempnam(sys_get_temp_dir(), 'foodex-v2-digest-');
        if (! is_string($path)) {
            throw new RuntimeException('Cannot allocate synthetic ZIP.');
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

    private function runtime(): LaravelUpdateRuntime
    {
        return app(LaravelUpdateRuntime::class);
    }
}
