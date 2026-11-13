<?php

namespace Tests\Feature;

use App\Domain\Installer\InstallerEnvironment;
use App\Domain\Installer\InstallState;
use Tests\TestCase;

final class V2InstallerProgressStepTypeGuardTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = storage_path('framework/testing/v2-install-progress-type-'.bin2hex(random_bytes(6)));
        $this->assertTrue(mkdir($this->directory, 0750, true));
        $this->assertNotFalse(file_put_contents($this->directory.'/.env', implode(PHP_EOL, [
            'APP_NAME=FOODEX',
            'APP_ENV=testing',
            'APP_KEY=',
            'APP_DEBUG=true',
            'APP_URL=http://localhost',
        ]).PHP_EOL));

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

    public function test_only_in_range_json_integers_are_accepted_as_completed_steps(): void
    {
        $state = app(InstallState::class);
        $token = str_repeat('a', 64);

        foreach ([0, 1, 11, 12, 13, 15] as $step) {
            $this->writeProgress($step, $token);
            $this->assertSame($step, $state->progress()['completed_step']);
            $this->assertSame($token, $state->progress()['token']);
        }

        foreach (['12', 12.9, true, false, [], ['step' => 12], -1, 16, 99] as $step) {
            $this->writeProgress($step, $token);
            $this->assertSame(0, $state->progress()['completed_step'], json_encode($step, JSON_THROW_ON_ERROR));
            $this->assertSame($token, $state->progress()['token']);
        }
    }

    public function test_forged_string_progress_cannot_skip_to_application_key_generation(): void
    {
        $token = str_repeat('b', 64);
        $this->writeProgress('12', $token);
        $before = (string) file_get_contents($this->directory.'/install-progress.json');
        $environment = app(InstallerEnvironment::class);

        $this->assertSame('', $environment->read(['APP_KEY'])['APP_KEY']);

        $this->post('/install/step/13', ['installer_token' => $token])
            ->assertStatus(409);

        $this->assertSame('', $environment->read(['APP_KEY'])['APP_KEY']);
        $this->assertSame($before, file_get_contents($this->directory.'/install-progress.json'));
        $this->assertSame(0, app(InstallState::class)->progress()['completed_step']);
        $this->assertFalse(app(InstallState::class)->isInstalled());
    }

    private function writeProgress(mixed $step, string $token): void
    {
        $this->assertNotFalse(file_put_contents(
            $this->directory.'/install-progress.json',
            json_encode(['completed_step' => $step, 'admin' => null, 'token' => $token], JSON_THROW_ON_ERROR),
        ));
    }
}
