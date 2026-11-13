<?php

namespace Tests\\Feature;

use App\\Domain\\Updater\\LaravelUpdateRuntime;
use Illuminate\\Support\\Facades\\File;
use RuntimeException;
use Tests\\TestCase;

final class V2UpdaterRollbackPreflightTest extends TestCase
{
    private string $releaseName;

    private string $releaseDirectory;

    private string $backupRoot;

    private string $backupDirectory;

    protected function setUp(): void
    {
        parent::setUp();

        $token = bin2hex(random_bytes(8));
        $this->releaseName = 'v2-rollback-preflight-'.$token;
        $this->releaseDirectory = dirname(base_path()).'/'.$this->releaseName;
        $this->backupRoot = storage_path('framework/testing/v2-rollback-preflight-'.$token);
        $this->backupDirectory = $this->backupRoot.'/fixture';

        File::ensureDirectoryExists($this->releaseDirectory);
        File::ensureDirectoryExists($this->backupDirectory);
        config()->set('foodex.update_backup_path', $this->backupRoot);
    }

    protected function tearDown(): void
    {
        if (isset($this->releaseDirectory)) {
            File::deleteDirectory($this->releaseDirectory);
        }
        if (isset($this->backupRoot)) {
            File::deleteDirectory($this->backupRoot);
        }

        parent::tearDown();
    }

    public function test_missing_later_backup_is_rejected_before_any_live_target_is_removed(): void
    {
        file_put_contents($this->releaseDirectory.'/first.txt', 'updated-first');
        file_put_contents($this->releaseDirectory.'/second.txt', 'updated-second');
        file_put_contents($this->backupDirectory.'/existing/'.$this->releaseName.'/first.txt', 'original-first');
        $this->manifest(
            [$this->path('first.txt'), $this->path('second.txt')],
            [$this->path('first.txt'), $this->path('second.txt')],
        );

        $this->assertRollbackRejected();

        $this->assertSame('updated-first', file_get_contents($this->releaseDirectory.'/first.txt'));
        $this->assertSame('updated-second', file_get_contents($this->releaseDirectory.'/second.txt'));
    }

    public function test_existing_entry_must_be_a_unique_member_of_targets(): void
    {
        file_put_contents($this->releaseDirectory.'/first.txt', 'updated');
        $this->manifest([$this->path('first.txt')], [$this->path('foreign.txt')]);

        $this->assertRollbackRejected();

        $this->assertSame('updated', file_get_contents($this->releaseDirectory.'/first.txt'));
    }

    public function test_symlink_backup_input_is_rejected_before_any_live_target_is_removed(): void
    {
        file_put_contents($this->releaseDirectory.'/first.txt', 'updated');
        File::ensureDirectoryExists($this->backupDirectory.'/existing/'.$this->releaseName);
        file_put_contents($this->backupRoot.'/outside.txt', 'secret fixture');

        if (! @symlink($this->backupRoot.'/outside.txt', $this->backupDirectory.'/existing/'.$this->releaseName.'/first.txt')) {
            $this->markTestSkipped('This filesystem does not support symlink test fixtures.');
        }

        $this->manifest([$this->path('first.txt')], [$this->path('first.txt')]);

        $this->assertRollbackRejected();

        $this->assertSame('updated', file_get_contents($this->releaseDirectory.'/first.txt'));
    }

    public function test_valid_backup_restores_existing_file_and_removes_new_file(): void
    {
        file_put_contents($this->releaseDirectory.'/first.txt', 'updated');
        file_put_contents($this->releaseDirectory.'/new.txt', 'new');
        File::ensureDirectoryExists($this->backupDirectory.'/existing/'.$this->releaseName);
        file_put_contents($this->backupDirectory.'/existing/'.$this->releaseName.'/first.txt', 'original');
        $this->manifest([$this->path('first.txt'), $this->path('new.txt')], [$this->path('first.txt')]);

        $this->runtime()->rollbackFiles($this->backupDirectory);

        $this->assertSame('original', file_get_contents($this->releaseDirectory.'/first.txt'));
        $this->assertFileDoesNotExist($this->releaseDirectory.'/new.txt');
    }

    /**
     * @param  array<int, string>  $targets
     * @param  array<int, string>  $existing
     */
    private function manifest(array $targets, array $existing): void
    {
        File::ensureDirectoryExists($this->backupDirectory);
        file_put_contents($this->backupDirectory.'/files.json', json_encode([
            'targets' => $targets,
            'existing' => $existing,
        ], JSON_THROW_ON_ERROR));
    }

    private function path(string $filename): string
    {
        return $this->releaseName.'/'.$filename;
    }

    private function runtime(): LaravelUpdateRuntime
    {
        return app(LaravelUpdateRuntime::class);
    }

    private function assertRollbackRejected(): void
    {
        try {
            $this->runtime()->rollbackFiles($this->backupDirectory);
            $this->fail('Invalid rollback manifest or backup source must fail before file mutation.');
        } catch (RuntimeException $exception) {
            $this->assertNotSame('', $exception->getMessage());
            $this->assertStringNotContainsString($this->backupRoot, $exception->getMessage());
        }
    }
}
