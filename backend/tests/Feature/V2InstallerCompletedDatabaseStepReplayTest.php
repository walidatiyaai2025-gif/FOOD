<?php

namespace Tests\Feature;

use App\Domain\Installer\InstallerEnvironment;
use App\Domain\Installer\InstallState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class V2InstallerCompletedDatabaseStepReplayTest extends TestCase
{
    use RefreshDatabase;

    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = storage_path('framework/testing/v2-install-db-replay-'.bin2hex(random_bytes(6)));
        mkdir($this->directory, 0750, true);

        file_put_contents($this->directory.'/.env', implode(PHP_EOL, [
            'APP_NAME=FOODEX',
            'APP_ENV=testing',
            'APP_KEY=',
            'APP_DEBUG=true',
            'APP_URL=http://localhost',
            'APP_LOCALE=ar',
            'DB_CONNECTION=sqlite',
            'DB_DATABASE=:memory:',
            'FILESYSTEM_DISK=local',
            'CACHE_STORE=array',
            'QUEUE_CONNECTION=sync',
        ]).PHP_EOL);

        config([
            'foodex.installer_env_path' => $this->directory.'/.env',
            'foodex.install_lock' => $this->directory.'/installed.lock',
            'foodex.install_progress' => $this->directory.'/install-progress.json',
            'foodex.scheduler_heartbeat' => false,
            'foodex.scheduler_auto_provision' => false,
        ]);
    }

    protected function tearDown(): void
    {
        if (isset($this->directory) && is_dir($this->directory)) {
            foreach (['.env', 'installed.lock', 'install-progress.json'] as $file) {
                $path = $this->directory.'/'.$file;

                if (is_file($path)) {
                    unlink($path);
                }
            }

            rmdir($this->directory);
        }

        parent::tearDown();
    }

    public function test_completed_migration_step_does_not_run_artisan_again(): void
    {
        $state = app(InstallState::class);
        $state->markStepComplete(10);

        Artisan::shouldReceive('call')
            ->once()
            ->with('migrate', ['--force' => true])
            ->andReturn(0);

        $token = $state->token();
        $this->post('/install/step/11', ['installer_token' => $token])
            ->assertRedirect(route('install.index', ['step' => 12]));

        $this->post('/install/step/11', ['installer_token' => $token])
            ->assertRedirect(route('install.index', ['step' => 12]));

        $this->assertSame(11, $state->progress()['completed_step']);
        $this->assertFalse($state->isInstalled());
    }

    public function test_failed_migration_can_retry_but_does_not_run_after_completion(): void
    {
        $state = app(InstallState::class);
        $state->markStepComplete(10);

        Artisan::shouldReceive('call')
            ->twice()
            ->with('migrate', ['--force' => true])
            ->andReturn(1, 0);

        $token = $state->token();
        $this->post('/install/step/11', ['installer_token' => $token])
            ->assertUnprocessable()
            ->assertSee('Database migrations failed.', false);
        $this->assertSame(10, $state->progress()['completed_step']);

        $this->post('/install/step/11', ['installer_token' => $token])
            ->assertRedirect(route('install.index', ['step' => 12]));
        $this->post('/install/step/11', ['installer_token' => $token])
            ->assertRedirect(route('install.index', ['step' => 12]));

        $this->assertSame(11, $state->progress()['completed_step']);
    }

    public function test_completed_seed_step_preserves_changes_to_original_reference_values(): void
    {
        $state = app(InstallState::class);
        $state->markStepComplete(11);

        Artisan::shouldReceive('call')
            ->once()
            ->with('db:seed', ['--class' => \Database\Seeders\DatabaseSeeder::class, '--force' => true])
            ->andReturn(0);

        $token = $state->token();
        $this->post('/install/step/12', ['installer_token' => $token])
            ->assertRedirect(route('install.index', ['step' => 13]));

        $this->assertSame('true', DB::table('settings')
            ->whereNull('store_id')
            ->where('key', 'commercial_rules_enabled')
            ->value('value'));

        DB::table('settings')
            ->whereNull('store_id')
            ->where('key', 'commercial_rules_enabled')
            ->update(['value' => 'false']);

        $this->post('/install/step/12', ['installer_token' => $token])
            ->assertRedirect(route('install.index', ['step' => 13]));

        $this->assertSame('false', DB::table('settings')
            ->whereNull('store_id')
            ->where('key', 'commercial_rules_enabled')
            ->value('value'));
        $this->assertSame(12, $state->progress()['completed_step']);
    }

    public function test_failed_seed_step_is_retryable_and_successful_replay_is_no_op(): void
    {
        $state = app(InstallState::class);
        $state->markStepComplete(11);

        Artisan::shouldReceive('call')
            ->twice()
            ->with('db:seed', ['--class' => \Database\Seeders\DatabaseSeeder::class, '--force' => true])
            ->andReturn(1, 0);

        $token = $state->token();

        $this->post('/install/step/12', ['installer_token' => $token])
            ->assertUnprocessable()
            ->assertSee('Required core data could not be seeded.', false);
        $this->assertSame(11, $state->progress()['completed_step']);

        $this->post('/install/step/12', ['installer_token' => $token])
            ->assertRedirect(route('install.index', ['step' => 13]));
        $this->post('/install/step/12', ['installer_token' => $token])
            ->assertRedirect(route('install.index', ['step' => 13]));

        $this->assertSame(12, $state->progress()['completed_step']);
    }

    public function test_invalid_token_and_out_of_order_seed_request_do_not_execute_database_commands(): void
    {
        $state = app(InstallState::class);
        $state->markStepComplete(10);

        Artisan::shouldReceive('call')->never();

        $this->post('/install/step/11', ['installer_token' => 'invalid'])
            ->assertStatus(419);

        $this->post('/install/step/12', ['installer_token' => $state->token()])
            ->assertStatus(409);

        $this->assertSame(10, $state->progress()['completed_step']);
        $this->assertFalse($state->isInstalled());
        $this->assertSame('sqlite', app(InstallerEnvironment::class)->read(['DB_CONNECTION'])['DB_CONNECTION']);
    }
}
