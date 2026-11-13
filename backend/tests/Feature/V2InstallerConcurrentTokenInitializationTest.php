<?php

namespace Tests\Feature;

use App\Domain\Installer\InstallState;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class V2InstallerConcurrentTokenInitializationTest extends TestCase
{
    private string $testDirectory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->testDirectory = storage_path('framework/testing/v2-installer-token-'.bin2hex(random_bytes(6)));
        mkdir($this->testDirectory, 0750, true);

        config([
            'foodex.install_progress' => $this->testDirectory.'/progress.json',
            'foodex.install_lock' => $this->testDirectory.'/installed.lock',
            'foodex.installer_env_path' => $this->testDirectory.'/.env',
        ]);

        file_put_contents($this->testDirectory.'/.env', "APP_NAME=FOODEX\nAPP_URL=http://localhost\n");
    }

    protected function tearDown(): void
    {
        if (isset($this->testDirectory) && is_dir($this->testDirectory)) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->testDirectory, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST,
            );

            foreach ($iterator as $entry) {
                if ($entry->isLink() || ! $entry->isDir()) {
                    unlink($entry->getPathname());
                } else {
                    rmdir($entry->getPathname());
                }
            }

            rmdir($this->testDirectory);
        }

        parent::tearDown();
    }

    public function test_repeated_first_run_http_requests_keep_one_token(): void
    {
        $state = app(InstallState::class);

        $this->get('/install')->assertOk();
        $first = $state->token();

        $this->get('/install')->assertOk();

        $this->assertSame($first, $state->token());
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $first);
        $this->assertSame(0, $state->progress()['completed_step']);
        $this->assertFalse($state->isInstalled());
        $this->assertSame(0640, fileperms($this->testDirectory.'/progress.json.token-lock') & 0777);
    }

    public function test_initialization_preserves_existing_progress_and_admin_fields(): void
    {
        $admin = [
            'name' => 'Synthetic Admin',
            'email' => 'synthetic@example.test',
            'password_hash' => 'synthetic-hash-not-a-secret',
            'locale' => 'en',
        ];

        file_put_contents(
            $this->testDirectory.'/progress.json',
            json_encode(['completed_step' => 7, 'admin' => $admin, 'token' => null], JSON_THROW_ON_ERROR),
        );

        $state = app(InstallState::class);
        $token = $state->token();

        $this->assertSame($token, $state->token());
        $this->assertSame(7, $state->progress()['completed_step']);
        $this->assertSame($admin, $state->progress()['admin']);
        $this->assertSame($token, $state->progress()['token']);
    }

    public function test_parallel_independent_initializers_return_same_authoritative_token(): void
    {
        if (! function_exists('proc_open')) {
            $this->markTestSkipped('Parallel CLI processes are not supported by this PHP environment.');
        }

        $worker = <<<'PHP'
require %AUTOLOAD%;
$app = require %BOOTSTRAP%;
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
config([
    'foodex.install_progress' => %PROGRESS%,
    'foodex.install_lock' => %LOCK%,
]);
echo $app->make(\App\Domain\Installer\InstallState::class)->token();
PHP;

        $worker = str_replace(
            ['%AUTOLOAD%', '%BOOTSTRAP%', '%PROGRESS%', '%LOCK%'],
            [
                var_export(base_path('vendor/autoload.php'), true),
                var_export(base_path('bootstrap/app.php'), true),
                var_export($this->testDirectory.'/progress.json', true),
                var_export($this->testDirectory.'/installed.lock', true),
            ],
            $worker,
        );

        $processes = [];

        for ($i = 0; $i < 6; $i++) {
            $process = new Process([PHP_BINARY, '-r', $worker], base_path());
            $process->start();
            $processes[] = $process;
        }

        $tokens = [];
        foreach ($processes as $process) {
            $process->wait();
            $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
            $tokens[] = trim($process->getOutput());
        }

        $this->assertCount(1, array_unique($tokens));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $tokens[0]);
        $this->assertSame($tokens[0], app(InstallState::class)->token());
        $this->assertSame(0, app(InstallState::class)->progress()['completed_step']);
    }

    public function test_dangling_symbolic_link_token_lock_is_rejected_without_creating_target(): void
    {
        if (! function_exists('symlink') || DIRECTORY_SEPARATOR === '\\') {
            $this->markTestSkipped('Symbolic links are not available on this filesystem.');
        }

        $nonexistentTarget = $this->testDirectory.'/missing-target';
        $this->assertTrue(symlink($nonexistentTarget, $this->testDirectory.'/progress.json.token-lock'));

        try {
            app(InstallState::class)->token();
            $this->fail('A dangling installer token lock must be rejected.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('symbolic link', $exception->getMessage());
        }

        $this->assertFileDoesNotExist($nonexistentTarget);
        $this->assertFileDoesNotExist($this->testDirectory.'/progress.json');
    }

    public function test_symbolic_link_token_lock_is_rejected_without_touching_external_file(): void
    {
        if (! function_exists('symlink') || DIRECTORY_SEPARATOR === '\\') {
            $this->markTestSkipped('Symbolic links are not available on this filesystem.');
        }

        $externalPath = $this->testDirectory.'/outside.txt';
        file_put_contents($externalPath, 'sentinel');
        $this->assertTrue(symlink($externalPath, $this->testDirectory.'/progress.json.token-lock'));

        try {
            app(InstallState::class)->token();
            $this->fail('The installer must reject an externally redirected token lock.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('symbolic link', $exception->getMessage());
        }

        $this->assertSame('sentinel', file_get_contents($externalPath));
        $this->assertFileDoesNotExist($this->testDirectory.'/progress.json');
    }
}
