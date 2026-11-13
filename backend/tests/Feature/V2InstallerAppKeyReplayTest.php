<?php

namespace Tests\Feature;

use App\Domain\Installer\InstallerEnvironment;
use App\Domain\Installer\InstallState;
use Illuminate\Support\Facades\Crypt;
use Tests\TestCase;

final class V2InstallerAppKeyReplayTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = storage_path('framework/testing/v2-install-key-replay-'.bin2hex(random_bytes(6)));
        mkdir($this->directory, 0750, true);
        file_put_contents($this->directory.'/.env', implode(PHP_EOL, [
            'APP_NAME=FOODEX',
            'APP_ENV=testing',
            'APP_KEY=',
            'APP_DEBUG=true',
            'APP_URL=http://localhost',
        ]).PHP_EOL);

        config([
            'foodex.installer_env_path' => $this->directory.'/.env',
            'foodex.install_lock' => $this->directory.'/installed.lock',
            'foodex.install_progress' => $this->directory.'/install-progress.json',
            // The persistent first-run token lock belongs to this fixture, not
            // shared storage. Removing the lock is safe only after each test.
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

    public function test_first_step_thirteen_generates_key_and_replay_preserves_it_and_encrypted_values(): void
    {
        $state = app(InstallState::class);
        $environment = app(InstallerEnvironment::class);
        $state->markStepComplete(12);

        $token = $state->token();
        $this->assertSame('', $environment->read(['APP_KEY'])['APP_KEY']);

        $this->post('/install/step/13', ['installer_token' => $token])
            ->assertRedirect(route('install.index', ['step' => 14]));

        $initialKey = (string) $environment->read(['APP_KEY'])['APP_KEY'];
        $this->assertStringStartsWith('base64:', $initialKey);
        $this->assertSame(32, strlen((string) base64_decode(substr($initialKey, 7), true)));
        $this->assertSame(13, $state->progress()['completed_step']);

        $ciphertext = Crypt::encryptString('installer-key-replay-probe');

        $this->post('/install/step/13', ['installer_token' => $token])
            ->assertRedirect(route('install.index', ['step' => 14]));

        $this->assertSame($initialKey, $environment->read(['APP_KEY'])['APP_KEY']);
        $this->assertSame('installer-key-replay-probe', Crypt::decryptString($ciphertext));
        $this->assertSame(13, $state->progress()['completed_step']);
        $this->get('/install?step=14')->assertOk();
        $this->assertFalse($state->isInstalled());
    }

    public function test_invalid_installer_token_never_changes_key_or_progress_after_generation(): void
    {
        $state = app(InstallState::class);
        $environment = app(InstallerEnvironment::class);
        $state->markStepComplete(12);

        $this->post('/install/step/13', ['installer_token' => $state->token()])
            ->assertRedirect(route('install.index', ['step' => 14]));
        $key = $environment->read(['APP_KEY'])['APP_KEY'];

        $this->post('/install/step/13', ['installer_token' => str_repeat('0', 64)])
            ->assertStatus(419);

        $this->assertSame($key, $environment->read(['APP_KEY'])['APP_KEY']);
        $this->assertSame(13, $state->progress()['completed_step']);
        $this->assertFalse($state->isInstalled());
    }

    public function test_attempt_to_skip_to_key_generation_before_previous_steps_is_rejected(): void
    {
        $state = app(InstallState::class);
        $environment = app(InstallerEnvironment::class);
        $token = $state->token();

        $this->post('/install/step/13', ['installer_token' => $token])
            ->assertStatus(409);

        $this->assertSame('', $environment->read(['APP_KEY'])['APP_KEY']);
        $this->assertSame(0, $state->progress()['completed_step']);
    }
}
