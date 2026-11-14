<?php

namespace Tests\Feature;

use App\Domain\Installer\InstallState;
use RuntimeException;
use Tests\TestCase;

final class V2InstallerMalformedProgressFailClosedTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = storage_path('framework/testing/v2-install-malformed-state-'.bin2hex(random_bytes(6)));
        $this->assertTrue(mkdir($this->directory, 0750, true));
        $this->assertNotFalse(file_put_contents($this->directory.'/.env', "APP_NAME=FOODEX\nAPP_ENV=testing\nAPP_KEY=\nAPP_URL=http://localhost\n"));

        config([
            'foodex.installer_env_path' => $this->directory.'/.env',
            'foodex.install_lock' => $this->directory.'/installed.lock',
            'foodex.install_progress' => $this->directory.'/install-progress.json',
            'foodex.installer_token_lock_path' => $this->directory.'/installer-token.lock',
            'foodex.scheduler_heartbeat' => false,
            'foodex.scheduler_auto_provision' => false,
        ]);
    }

    protected function tearDown(): void
    {
        if (isset($this->directory) && is_dir($this->directory)) {
            foreach (['.env', 'installed.lock', 'install-progress.json', 'installer-token.lock'] as $filename) {
                $path = $this->directory.'/'.$filename;
                if (is_file($path)) {
                    unlink($path);
                }
            }

            rmdir($this->directory);
        }

        parent::tearDown();
    }

    public function test_missing_progress_still_initializes_one_first_run_token(): void
    {
        $state = app(InstallState::class);
        $this->assertSame(['completed_step' => 0, 'admin' => null, 'token' => null], $state->progress());

        $token = $state->token();

        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $token);
        $this->assertSame($token, $state->token());
        $this->assertSame($token, $state->progress()['token']);
        $this->assertFileExists($this->directory.'/installer-token.lock');
    }

    public function test_valid_persisted_step_admin_and_token_are_preserved_without_rewrite(): void
    {
        $admin = [
            'name' => 'Synthetic Admin',
            'email' => 'synthetic@example.test',
            'password_hash' => 'synthetic-hash',
            'locale' => 'en',
        ];
        $token = str_repeat('a', 64);
        $before = json_encode([
            'completed_step' => 12,
            'admin' => $admin,
            'token' => $token,
        ], JSON_THROW_ON_ERROR);

        $this->assertNotFalse(file_put_contents($this->directory.'/install-progress.json', $before));

        $state = app(InstallState::class);
        $this->assertSame(['completed_step' => 12, 'admin' => $admin, 'token' => $token], $state->progress());
        $this->assertSame($token, $state->token());
        $this->assertSame($before, file_get_contents($this->directory.'/install-progress.json'));
        $this->assertFileDoesNotExist($this->directory.'/installer-token.lock');
    }

    public function test_invalid_existing_progress_is_never_reset_or_overwritten_by_state_mutations(): void
    {
        $state = app(InstallState::class);
        $invalidDocuments = [
            '{"completed_step": 12',
            '{"completed_step":12,"token":',
            'null',
            'false',
            '"unexpected"',
            '[{"completed_step":12}]',
            '',
        ];

        foreach ($invalidDocuments as $before) {
            $this->assertNotFalse(file_put_contents($this->directory.'/install-progress.json', $before));

            $this->assertInvalidProgress(fn () => $state->progress());
            $this->assertInvalidProgress(fn () => $state->token());
            $this->assertInvalidProgress(fn () => $state->markStepComplete(13));

            $this->assertSame($before, file_get_contents($this->directory.'/install-progress.json'));
            $this->assertFileDoesNotExist($this->directory.'/installer-token.lock');
        }
    }

    public function test_corrupt_progress_blocks_web_setup_without_mutating_environment_or_state(): void
    {
        $before = '{"completed_step":12,"token":';
        $this->assertNotFalse(file_put_contents($this->directory.'/install-progress.json', $before));
        $environment = (string) file_get_contents($this->directory.'/.env');

        $this->get('/install')->assertStatus(500);

        $this->assertSame($before, file_get_contents($this->directory.'/install-progress.json'));
        $this->assertSame($environment, file_get_contents($this->directory.'/.env'));
        $this->assertFileDoesNotExist($this->directory.'/installer-token.lock');
        $this->assertFileDoesNotExist($this->directory.'/installed.lock');
    }

    /** @param  callable(): mixed  $operation */
    private function assertInvalidProgress(callable $operation): void
    {
        try {
            $operation();
            $this->fail('An invalid existing installer progress file must fail closed.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Installer progress state is invalid.', $exception->getMessage());
        }
    }
}
