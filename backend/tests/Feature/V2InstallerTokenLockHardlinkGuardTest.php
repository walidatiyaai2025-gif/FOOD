<?php

namespace Tests\Feature;

use App\Domain\Installer\InstallState;
use RuntimeException;
use Tests\TestCase;

final class V2InstallerTokenLockHardlinkGuardTest extends TestCase
{
    private string $directory;

    private string $tokenLockPath;

    private string $progressPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = storage_path('framework/testing/v2-install-token-hardlink-'.bin2hex(random_bytes(7)));
        $this->assertTrue(mkdir($this->directory, 0750, true));

        $this->tokenLockPath = $this->directory.'/installer-token.lock';
        $this->progressPath = $this->directory.'/install-progress.json';

        config([
            'foodex.install_lock' => $this->directory.'/installed.lock',
            'foodex.install_progress' => $this->progressPath,
            'foodex.installer_token_lock_path' => $this->tokenLockPath,
        ]);
    }

    protected function tearDown(): void
    {
        if (isset($this->directory) && is_dir($this->directory)) {
            foreach (['installer-token.lock', 'install-progress.json', 'installed.lock', 'unrelated.txt'] as $name) {
                $path = $this->directory.'/'.$name;

                if (is_file($path) || is_link($path)) {
                    unlink($path);
                }
            }

            rmdir($this->directory);
        }

        parent::tearDown();
    }

    public function test_hardlinked_token_lock_cannot_change_an_unrelated_file_or_create_a_token(): void
    {
        $unrelated = $this->directory.'/unrelated.txt';
        $contents = 'unrelated synthetic data: leave permissions unchanged';
        $this->assertNotFalse(file_put_contents($unrelated, $contents));
        $this->assertTrue(chmod($unrelated, 0644));

        if (! function_exists('link') || ! @link($unrelated, $this->tokenLockPath)) {
            $this->markTestSkipped('Hard links are unsupported on the test filesystem.');
        }

        clearstatcache(true, $unrelated);
        $mode = fileperms($unrelated) & 0777;
        $this->assertSame(2, stat($unrelated)['nlink']);

        try {
            app(InstallState::class)->token();
            $this->fail('The installer must reject a hardlinked token lock.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Installer token lock path changed unexpectedly.', $exception->getMessage());
        }

        clearstatcache(true, $unrelated);
        $this->assertSame($mode, fileperms($unrelated) & 0777);
        $this->assertSame($contents, file_get_contents($unrelated));
        $this->assertSame(2, stat($unrelated)['nlink']);
        $this->assertFileDoesNotExist($this->progressPath);
    }

    public function test_regular_single_link_token_lock_remains_stable_across_replay(): void
    {
        $state = app(InstallState::class);

        $token = $state->token();
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $token);
        $this->assertSame($token, $state->token());

        clearstatcache(true, $this->tokenLockPath);
        $this->assertSame(1, stat($this->tokenLockPath)['nlink']);
        $this->assertSame(0640, fileperms($this->tokenLockPath) & 0777);
        $this->assertFileExists($this->progressPath);
        $this->assertSame($token, $state->progress()['token']);
        $this->assertSame(0, $state->progress()['completed_step']);
        $this->assertFalse($state->isInstalled());
    }
}
