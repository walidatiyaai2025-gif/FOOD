<?php

namespace Tests\Feature;

use App\Domain\Installer\InstallerChecklist;
use App\Domain\Installer\InstallerWorkflow;
use App\Domain\Installer\InstallState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class InstallerWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private string $testDirectory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->testDirectory = storage_path('framework/testing/installer-'.bin2hex(random_bytes(6)));
        mkdir($this->testDirectory, 0750, true);

        $environmentPath = $this->testDirectory.'/.env';
        file_put_contents($environmentPath, implode(PHP_EOL, [
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
            'MAIL_MAILER=array',
            'MAIL_FROM_ADDRESS=noreply@example.com',
            'MAIL_FROM_NAME=FOODEX',
        ]).PHP_EOL);

        config([
            'foodex.installer_env_path' => $environmentPath,
            'foodex.install_lock' => $this->testDirectory.'/installed.lock',
            'foodex.install_progress' => $this->testDirectory.'/install-progress.json',
        ]);
    }

    protected function tearDown(): void
    {
        if (isset($this->testDirectory) && is_dir($this->testDirectory)) {
            $files = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->testDirectory, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST,
            );

            foreach ($files as $file) {
                $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
            }

            rmdir($this->testDirectory);
        }

        parent::tearDown();
    }

    public function test_installer_exposes_exactly_fifteen_ordered_steps(): void
    {
        $this->assertCount(15, InstallerChecklist::steps());
        $this->assertSame('Welcome', InstallerChecklist::step(1)['title']);
        $this->assertSame('Finish', InstallerChecklist::step(15)['title']);

        $this->get('/install')
            ->assertOk()
            ->assertSee('FOODEX')
            ->assertSee('Welcome')
            ->assertSee('Finish');
    }

    public function test_installer_post_requires_the_progress_token(): void
    {
        $this->post('/install/step/1')->assertStatus(419);
        $this->assertFalse(app(InstallState::class)->isInstalled());
    }

    public function test_step_validation_blocks_invalid_super_admin_input(): void
    {
        app(InstallState::class)->markStepComplete(6);

        $state = app(InstallState::class);

        $this->post('/install/step/7', ['installer_token' => $state->token()])
            ->assertStatus(422)
            ->assertSee('admin name field is required', false)
            ->assertSee('admin email field is required', false)
            ->assertSee('admin password field is required', false);

        $this->assertFalse(app(InstallState::class)->isInstalled());
    }

    public function test_failed_finish_does_not_write_install_lock(): void
    {
        app(InstallState::class)->markStepComplete(14);

        $state = app(InstallState::class);

        $this->post('/install/step/15', ['installer_token' => $state->token()])
            ->assertStatus(422)
            ->assertSee('Super Admin setup is incomplete', false);

        $this->assertFalse(app(InstallState::class)->isInstalled());
        $this->assertDatabaseCount('system_versions', 0);
    }

    public function test_successful_finish_creates_super_admin_version_and_lock(): void
    {
        $workflow = app(InstallerWorkflow::class);
        $state = app(InstallState::class);

        $workflow->runMigrations();
        $workflow->seedRequiredCoreData();
        $workflow->generateApplicationKey();
        $this->assertSame([
            'database' => true,
            'storage' => true,
            'cache' => true,
            'queue' => true,
        ], $workflow->healthCheck());

        $admin = $workflow->prepareSuperAdmin([
            'name' => 'Platform Owner',
            'email' => 'owner@example.com',
            'password' => 'SecurePassword!123',
            'locale' => 'ar',
        ]);
        $state->markStepComplete(14, $admin);

        $this->post('/install/step/15', ['installer_token' => $state->token()])->assertRedirect('/admin');

        $version = trim((string) file_get_contents(base_path('../VERSION')));
        $this->assertTrue($state->isInstalled());
        $this->assertDatabaseHas('system_versions', ['version' => $version]);
        $this->assertDatabaseHas('users', ['email' => 'owner@example.com', 'is_active' => 1]);
        $this->assertDatabaseHas('audit_logs', ['event' => 'installer.completed']);

        $user = DB::table('users')->where('email', 'owner@example.com')->first();
        $this->assertNotNull($user);
        $this->assertTrue(Hash::check('SecurePassword!123', (string) $user->password));

        $superAdminRoleId = DB::table('roles')->where('code', 'SUPER_ADMIN')->value('id');
        $this->assertDatabaseHas('role_user', [
            'user_id' => $user->id,
            'role_id' => $superAdminRoleId,
        ]);

        $this->get('/install')->assertNotFound();
        $this->post('/install/step/1', ['installer_token' => 'invalid'])->assertNotFound();
    }
}
