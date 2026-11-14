<?php

namespace Tests\Feature;

use App\Domain\Installer\InstallerEnvironment;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

final class V2InstallerConcurrentEnvironmentWriteTest extends TestCase
{
    private string $directory;

    private string $environmentPath;

    private string $writeLockPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = storage_path('framework/testing/v2-installer-env-lock-'.bin2hex(random_bytes(6)));
        mkdir($this->directory, 0750, true);

        $this->environmentPath = $this->directory.'/.env';
        $this->writeLockPath = $this->directory.'/environment.write.lock';
        config([
            'foodex.installer_env_path' => $this->environmentPath,
            'foodex.installer_env_lock_path' => $this->writeLockPath,
        ]);

        file_put_contents($this->environmentPath, "UNMANAGED=keep\n");
    }

    protected function tearDown(): void
    {
        if (isset($this->directory) && is_dir($this->directory)) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->directory, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST,
            );

            foreach ($iterator as $entry) {
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

    public function test_multiple_independent_setup_workers_preserve_disjoint_environment_keys(): void
    {
        if (! function_exists('proc_open')) {
            $this->markTestSkipped('Independent PHP processes are not supported on this runner.');
        }

        $fields = [
            'APP_NAME' => 'FOODEX Concurrent Install',
            'APP_URL' => 'https://v2.example.test',
            'DB_HOST' => 'db.example.test',
            'DB_DATABASE' => 'foodex_v2',
            'DB_USERNAME' => 'synthetic_writer',
            'CACHE_STORE' => 'database',
            'QUEUE_CONNECTION' => 'database',
            'MAIL_HOST' => 'mail.example.test',
        ];

        $processes = [];
        foreach ($fields as $key => $value) {
            $worker = $this->worker($key, $value);
            $process = new Process([PHP_BINARY, '-r', $worker], base_path());
            $process->setTimeout(60);
            $process->start();
            $processes[] = $process;
        }

        foreach ($processes as $process) {
            $process->wait();
            $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
            $this->assertSame('updated', trim($process->getOutput()));
        }

        $environment = app(InstallerEnvironment::class);
        $this->assertSame($fields + ['UNMANAGED' => 'keep'], $environment->read(array_merge(array_keys($fields), ['UNMANAGED'])));
        $this->assertSame(0640, fileperms($this->writeLockPath) & 0777);
        $this->assertSame(0600, fileperms($this->environmentPath) & 0777);
    }

    public function test_sequential_retries_reuse_a_persistent_write_lock_without_losing_unmanaged_data(): void
    {
        $environment = app(InstallerEnvironment::class);
        $environment->write(['APP_NAME' => 'First']);
        $firstLock = stat($this->writeLockPath);

        $environment->write(['APP_NAME' => 'Second', 'DB_HOST' => 'db.test']);
        $secondLock = stat($this->writeLockPath);

        $this->assertSame($firstLock['ino'], $secondLock['ino']);
        $this->assertSame($firstLock['dev'], $secondLock['dev']);
        $this->assertSame([
            'APP_NAME' => 'Second',
            'DB_HOST' => 'db.test',
            'UNMANAGED' => 'keep',
        ], $environment->read(['APP_NAME', 'DB_HOST', 'UNMANAGED']));
    }

    public function test_symlinked_write_lock_does_not_mutate_external_target_or_environment(): void
    {
        if (! function_exists('symlink') || DIRECTORY_SEPARATOR === '\\') {
            $this->markTestSkipped('Symlink semantics are unavailable on this test platform.');
        }

        $external = $this->directory.'/outside-lock';
        file_put_contents($external, 'outside-sentinel');
        $this->assertTrue(symlink($external, $this->writeLockPath));

        try {
            app(InstallerEnvironment::class)->write(['DB_HOST' => 'should-not-write']);
            $this->fail('Symlinked lock must be rejected.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('symbolic link', $exception->getMessage());
        }

        $this->assertSame('outside-sentinel', file_get_contents($external));
        $this->assertSame("UNMANAGED=keep\n", file_get_contents($this->environmentPath));
    }

    public function test_hardlinked_write_lock_is_rejected_without_changing_external_file(): void
    {
        if (! function_exists('link') || DIRECTORY_SEPARATOR === '\\') {
            $this->markTestSkipped('Hardlink semantics are unavailable on this test platform.');
        }

        $external = $this->directory.'/outside-hardlink';
        file_put_contents($external, 'hardlink-sentinel');
        if (! @link($external, $this->writeLockPath)) {
            $this->markTestSkipped('Hardlinks are not supported by this filesystem.');
        }

        try {
            app(InstallerEnvironment::class)->write(['DB_HOST' => 'should-not-write']);
            $this->fail('Hardlinked lock must be rejected.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('lock path changed unexpectedly', $exception->getMessage());
        }

        $this->assertSame('hardlink-sentinel', file_get_contents($external));
        $this->assertSame("UNMANAGED=keep\n", file_get_contents($this->environmentPath));
    }

    private function worker(string $key, string $value): string
    {
        $source = <<<'PHP'
require %AUTOLOAD%;
$app = require %BOOTSTRAP%;
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
config([
    'foodex.installer_env_path' => %ENV_PATH%,
    'foodex.installer_env_lock_path' => %LOCK_PATH%,
]);
$app->make(\App\Domain\Installer\InstallerEnvironment::class)->write([%KEY% => %VALUE%]);
echo 'updated';
PHP;

        return str_replace(
            ['%AUTOLOAD%', '%BOOTSTRAP%', '%ENV_PATH%', '%LOCK_PATH%', '%KEY%', '%VALUE%'],
            [
                var_export(base_path('vendor/autoload.php'), true),
                var_export(base_path('bootstrap/app.php'), true),
                var_export($this->environmentPath, true),
                var_export($this->writeLockPath, true),
                var_export($key, true),
                var_export($value, true),
            ],
            $source,
        );
    }
}
