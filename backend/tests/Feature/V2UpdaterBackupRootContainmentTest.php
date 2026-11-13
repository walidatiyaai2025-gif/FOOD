<?php

namespace Tests\Feature;

use App\Domain\Updater\LaravelUpdateRuntime;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Tests\TestCase;

final class V2UpdaterBackupRootContainmentTest extends TestCase
{
    private string $releaseName;

    private string $releaseDirectory;

    private string $backupRoot;

    private string $backupDirectory;

    private string $foreignDirectory;

    protected function setUp(): void
    {
        parent::setUp();

        $suffix = bin2hex(random_bytes(8));
        $this->releaseName = 'v2-rollback-root-'.$suffix;
        $this->releaseDirectory = dirname(base_path()).'/'.$this->releaseName;
        $this->backupRoot = storage_path('framework/testing/v2-trusted-backups-'.$suffix);
        $this->backupDirectory = $this->backupRoot.'/files';
        $this->foreignDirectory = storage_path('framework/testing/v2-foreign-backups-'.$suffix);

        File::ensureDirectoryExists($this->releaseDirectory);
        File::ensureDirectoryExists($this->backupDirectory);
        File::ensureDirectoryExists($this->foreignDirectory);

        config()->set('foodex.update_backup_path', $this->backupRoot);
    }

    protected function tearDown(): void
    {
        if (isset($this->backupRoot)) {
            $alias = $this->backupRoot.'/linked';
            if (is_link($alias)) {
                @unlink($alias);
            }

            File::deleteDirectory($this->backupRoot);
        }

        if (isset($this->foreignDirectory)) {
            File::deleteDirectory($this->foreignDirectory);
        }

        if (isset($this->releaseDirectory)) {
            File::deleteDirectory($this->releaseDirectory);
        }

        parent::tearDown();
    }

    public function test_foreign_complete_manifest_cannot_remove_live_release_target(): void
    {
        $target = $this->relative('live.txt');
        $this->writeLive('live.txt', 'still-live');
        $this->manifest($this->foreignDirectory, [$target], []);

        $this->assertRejected($this->foreignDirectory, 'File rollback backup location is invalid.');

        $this->assertSame('still-live', file_get_contents($this->releaseDirectory.'/live.txt'));
    }

    public function test_parent_segment_alias_under_backup_root_is_rejected_before_mutation(): void
    {
        $target = $this->relative('live.txt');
        $this->writeLive('live.txt', 'still-live');
        $this->manifest($this->backupDirectory, [$target], []);
        File::ensureDirectoryExists($this->backupRoot.'/nested');

        $this->assertRejected(
            $this->backupRoot.'/nested/../files',
            'File rollback backup location is invalid.',
        );

        $this->assertSame('still-live', file_get_contents($this->releaseDirectory.'/live.txt'));
    }

    public function test_linked_intermediate_backup_directory_is_rejected_before_mutation(): void
    {
        $target = $this->relative('live.txt');
        $this->writeLive('live.txt', 'still-live');
        $nested = $this->backupDirectory.'/child';
        $this->manifest($nested, [$target], []);

        if (! function_exists('symlink') || ! @symlink($this->backupDirectory, $this->backupRoot.'/linked')) {
            $this->markTestSkipped('Symlink support is unavailable in this test environment.');
        }

        $this->assertRejected(
            $this->backupRoot.'/linked/child',
            'File rollback backup location is invalid.',
        );

        $this->assertSame('still-live', file_get_contents($this->releaseDirectory.'/live.txt'));
    }

    public function test_object_shaped_targets_are_rejected_without_removing_live_file(): void
    {
        $target = $this->relative('live.txt');
        $this->writeLive('live.txt', 'still-live');
        $this->manifest($this->backupDirectory, ['not-an-array-index' => $target], []);

        $this->assertRejected($this->backupDirectory, 'File rollback manifest is invalid.');

        $this->assertSame('still-live', file_get_contents($this->releaseDirectory.'/live.txt'));
    }

    public function test_object_shaped_existing_backups_are_rejected_before_overwrite(): void
    {
        $target = $this->relative('live.txt');
        $this->writeLive('live.txt', 'still-live');
        $this->writeBackup($this->backupDirectory, $target, 'old-content');
        $this->manifest($this->backupDirectory, [$target], ['not-an-array-index' => $target]);

        $this->assertRejected($this->backupDirectory, 'File rollback manifest is invalid.');

        $this->assertSame('still-live', file_get_contents($this->releaseDirectory.'/live.txt'));
    }

    public function test_complete_trusted_backup_restores_empty_original_and_removes_new_file(): void
    {
        $old = $this->relative('old.txt');
        $empty = $this->relative('empty.txt');
        $new = $this->relative('new.txt');

        $this->writeLive('old.txt', 'updated-old');
        $this->writeLive('empty.txt', 'updated-empty');
        $this->writeLive('new.txt', 'new-file');
        $this->writeBackup($this->backupDirectory, $old, 'original');
        $this->writeBackup($this->backupDirectory, $empty, '');
        $this->manifest($this->backupDirectory, [$old, $empty, $new], [$old, $empty]);

        app(LaravelUpdateRuntime::class)->rollbackFiles($this->backupDirectory);

        $this->assertSame('original', file_get_contents($this->releaseDirectory.'/old.txt'));
        $this->assertSame('', file_get_contents($this->releaseDirectory.'/empty.txt'));
        $this->assertFileDoesNotExist($this->releaseDirectory.'/new.txt');
    }

    private function relative(string $name): string
    {
        return $this->releaseName.'/'.$name;
    }

    private function writeLive(string $name, string $content): void
    {
        file_put_contents($this->releaseDirectory.'/'.$name, $content);
    }

    private function writeBackup(string $directory, string $relativePath, string $content): void
    {
        $target = $directory.'/existing/'.$relativePath;
        File::ensureDirectoryExists(dirname($target));
        file_put_contents($target, $content);
    }

    /**
     * @param  array<int|string, string>  $targets
     * @param  array<int|string, string>  $existing
     */
    private function manifest(string $directory, array $targets, array $existing): void
    {
        File::ensureDirectoryExists($directory);
        file_put_contents($directory.'/files.json', json_encode([
            'targets' => $targets,
            'existing' => $existing,
        ], JSON_THROW_ON_ERROR));
    }

    private function assertRejected(string $directory, string $expectedMessage): void
    {
        try {
            app(LaravelUpdateRuntime::class)->rollbackFiles($directory);
        } catch (RuntimeException $exception) {
            $this->assertSame($expectedMessage, $exception->getMessage());
            $this->assertStringNotContainsString($directory, $exception->getMessage());

            return;
        }

        $this->fail('Unsafe or untrusted rollback input must be rejected before live mutation.');
    }
}
