<?php

namespace Tests\Feature;

use App\Domain\Installer\InstallState;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

final class V2InstallerConcurrentProgressCheckpointTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = storage_path('framework/testing/v2-install-checkpoint-'.bin2hex(random_bytes(6)));
        $this->assertTrue(mkdir($this->directory, 0750, true));

        config([
            'foodex.install_progress' => $this->directory.'/progress.json',
            'foodex.install_lock' => $this->directory.'/installed.lock',
            'foodex.installer_token_lock_path' => $this->directory.'/token.lock',
        ]);
    }

    protected function tearDown(): void
    {
        if (isset($this->directory) && is_dir($this->directory)) {
            $entries = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->directory, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST,
            );

            foreach ($entries as $entry) {
                if ($entry->isLink() || ! $entry->isDir()) {
                    unlink($entry->getPathname());
                } else {
                    rmdir($entry->getPathname());
                }
            }

            rmdir($this->directory);
        }

        parent::tearDown();
    }

    public function test_concurrent_checkpoint_waits_for_persistent_token_lock_and_preserves_newer_state(): void
    {
        if (! function_exists('proc_open')) {
            $this->markTestSkipped('Parallel PHP CLI processes are not supported.');
        }

        $state = app(InstallState::class);
        $token = $state->token();
        $state->markStepComplete(3);
        $ready = $this->directory.'/worker-ready';

        $worker = <<<'PHP'
require %AUTOLOAD%;
$app = require %BOOTSTRAP%;
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
config([
    'foodex.install_progress' => %PROGRESS%,
    'foodex.install_lock' => %INSTALLED%,
    'foodex.installer_token_lock_path' => %TOKEN_LOCK%,
]);
file_put_contents(%READY%, 'ready');
$app->make(\App\Domain\Installer\InstallState::class)->markStepComplete(7);
echo 'finished';
PHP;
        $worker = str_replace(
            ['%AUTOLOAD%', '%BOOTSTRAP%', '%PROGRESS%', '%INSTALLED%', '%TOKEN_LOCK%', '%READY%'],
            [
                var_export(base_path('vendor/autoload.php'), true),
                var_export(base_path('bootstrap/app.php'), true),
                var_export($this->directory.'/progress.json', true),
                var_export($this->directory.'/installed.lock', true),
                var_export($this->directory.'/token.lock', true),
                var_export($ready, true),
            ],
            $worker,
        );

        $lock = fopen($this->directory.'/token.lock', 'c');
        $this->assertNotFalse($lock);
        $this->assertTrue(flock($lock, LOCK_EX));

        $process = new Process([PHP_BINARY, '-r', $worker], base_path());
        $admin = [
            'name' => 'Synthetic Setup Admin',
            'email' => 'setup-admin@example.test',
            'password_hash' => 'synthetic-hash',
            'locale' => 'ar',
        ];

        try {
            $process->start();

            $deadline = microtime(true) + 10;
            while (! is_file($ready) && microtime(true) < $deadline && $process->isRunning()) {
                usleep(20_000);
            }

            $this->assertFileExists($ready, $process->getErrorOutput());

            // The child has announced its checkpoint attempt, but the
            // persistent lock is held. An unlocked reader/writer would
            // modify the file at this point.
            usleep(250_000);
            $this->assertTrue($process->isRunning(), 'Checkpoint must block on the shared lock.');
            $this->assertSame(3, $state->progress()['completed_step']);

            // Simulate another already-authorized setup checkpoint written
            // while the child is queued: child must re-read under the lock.
            $this->assertNotFalse(file_put_contents(
                $this->directory.'/progress.json',
                json_encode([
                    'completed_step' => 6,
                    'admin' => $admin,
                    'token' => $token,
                ], JSON_THROW_ON_ERROR),
                LOCK_EX,
            ));
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }

        $process->wait();
        $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
        $this->assertSame('finished', trim($process->getOutput()));
        $this->assertSame([
            'completed_step' => 7,
            'admin' => $admin,
            'token' => $token,
        ], $state->progress());
    }

    public function test_initial_checkpoint_uses_same_lock_and_does_not_regenerate_token(): void
    {
        $state = app(InstallState::class);
        $this->assertFileDoesNotExist($this->directory.'/progress.json');

        $state->markStepComplete(1);
        $progress = $state->progress();

        $this->assertFileExists($this->directory.'/token.lock');
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $progress['token']);
        $this->assertSame(1, $progress['completed_step']);
        $this->assertSame($progress['token'], $state->token());
        $this->assertSame($progress, $state->progress());
    }

    public function test_replayed_older_step_never_drops_admin_or_rotates_token(): void
    {
        $state = app(InstallState::class);
        $token = $state->token();
        $admin = [
            'name' => 'Synthetic Admin',
            'email' => 'admin@example.test',
            'password_hash' => 'not-a-real-secret',
            'locale' => 'en',
        ];

        $state->markStepComplete(7, $admin);
        $state->markStepComplete(2);
        $state->markStepComplete(7);

        $this->assertSame([
            'completed_step' => 7,
            'admin' => $admin,
            'token' => $token,
        ], $state->progress());
    }

    public function test_hardlinked_lock_blocks_checkpoint_without_mutating_progress_or_target(): void
    {
        if (! function_exists('link')) {
            $this->markTestSkipped('Hard links are unavailable on this filesystem.');
        }

        $before = json_encode([
            'completed_step' => 3,
            'admin' => null,
            'token' => str_repeat('a', 64),
        ], JSON_THROW_ON_ERROR);
        $this->assertNotFalse(file_put_contents($this->directory.'/progress.json', $before));

        $other = $this->directory.'/unrelated.txt';
        $this->assertNotFalse(file_put_contents($other, 'untouched'));
        if (! @link($other, $this->directory.'/token.lock')) {
            $this->markTestSkipped('Hard links are unsupported on this filesystem.');
        }

        try {
            app(InstallState::class)->markStepComplete(4);
            $this->fail('A hardlinked persistent lock must reject installer checkpoint writes.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Installer token lock path changed unexpectedly.', $exception->getMessage());
        }

        $this->assertSame($before, file_get_contents($this->directory.'/progress.json'));
        $this->assertSame('untouched', file_get_contents($other));
    }
}
