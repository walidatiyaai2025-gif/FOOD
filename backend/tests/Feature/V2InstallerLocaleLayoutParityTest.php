<?php

namespace Tests\Feature;

use App\Domain\Installer\InstallerChecklist;
use App\Domain\Installer\InstallState;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class V2InstallerLocaleLayoutParityTest extends TestCase
{
    private string $fixtureDirectory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fixtureDirectory = storage_path('framework/testing/v2-installer-locale-'.bin2hex(random_bytes(6)));
        mkdir($this->fixtureDirectory, 0750, true);
        file_put_contents($this->fixtureDirectory.'/.env', implode(PHP_EOL, [
            'APP_NAME=FOODEX',
            'APP_ENV=testing',
            'APP_KEY=',
            'APP_URL=http://localhost',
            'APP_LOCALE=ar',
            'DB_CONNECTION=sqlite',
            'DB_DATABASE=:memory:',
            'FILESYSTEM_DISK=local',
            'CACHE_STORE=array',
            'QUEUE_CONNECTION=sync',
            'MAIL_MAILER=array',
        ]).PHP_EOL);

        config([
            'foodex.installer_env_path' => $this->fixtureDirectory.'/.env',
            'foodex.install_lock' => $this->fixtureDirectory.'/installed.lock',
            'foodex.install_progress' => $this->fixtureDirectory.'/install-progress.json',
            'foodex.installer_token_lock_path' => $this->fixtureDirectory.'/installer-token.lock',
            'foodex.scheduler_auto_provision' => false,
            'foodex.scheduler_heartbeat' => false,
        ]);
    }

    protected function tearDown(): void
    {
        if (isset($this->fixtureDirectory) && is_dir($this->fixtureDirectory)) {
            foreach (['.env', 'installed.lock', 'install-progress.json', 'installer-token.lock'] as $file) {
                $path = $this->fixtureDirectory.'/'.$file;
                if (is_file($path)) {
                    unlink($path);
                }
            }

            rmdir($this->fixtureDirectory);
        }

        parent::tearDown();
    }

    public function test_arabic_and_english_render_correct_direction_and_all_fifteen_step_titles(): void
    {
        $initialEnv = file_get_contents($this->fixtureDirectory.'/.env');

        $this->get('/install?lang=ar')
            ->assertOk()
            ->assertSee('<html lang="ar" dir="rtl">', false)
            ->assertSee('الخطوة 1 من 15')
            ->assertSee('مرحبًا')
            ->assertSee('إنهاء التثبيت')
            ->assertDontSee('First-run Setup Wizard');

        $this->get('/install?lang=en')
            ->assertOk()
            ->assertSee('<html lang="en" dir="ltr">', false)
            ->assertSee('Step 1 of 15')
            ->assertSee('Welcome')
            ->assertSee('Finish')
            ->assertDontSee('معالج الإعداد الأولي');

        foreach (InstallerChecklist::details() as $item) {
            foreach (['ar', 'en'] as $locale) {
                foreach (['title', 'description'] as $part) {
                    $key = 'installer.steps.'.$item['slug'].'.'.$part;
                    $translated = __($key, [], $locale);

                    $this->assertIsString($translated);
                    $this->assertNotSame($key, $translated);
                    $this->assertNotSame('', trim($translated));
                }
            }
        }

        $this->assertSame($initialEnv, file_get_contents($this->fixtureDirectory.'/.env'));
        $this->assertFalse(app(InstallState::class)->isInstalled());
    }

    public function test_explicit_language_survives_links_and_post_redirect_without_changing_platform_locale(): void
    {
        $state = app(InstallState::class);
        $token = $state->token();

        $this->get('/install?lang=en')
            ->assertOk()
            ->assertSee('lang=en', false)
            ->assertSee('name="installer_token"', false);

        $this->post('/install/step/1?lang=en', ['installer_token' => $token])
            ->assertRedirect(route('install.index', ['step' => 2, 'lang' => 'en']));

        $this->get('/install?step=2&lang=en')
            ->assertOk()
            ->assertSee('<html lang="en" dir="ltr">', false)
            ->assertSee('Server Requirements');

        $this->assertSame(1, $state->progress()['completed_step']);
        $this->assertStringContainsString('APP_LOCALE=ar', file_get_contents($this->fixtureDirectory.'/.env'));
        $this->assertFalse($state->isInstalled());
    }

    public function test_saved_language_is_default_and_invalid_query_values_fail_safely_to_that_locale(): void
    {
        $path = $this->fixtureDirectory.'/.env';
        file_put_contents($path, str_replace('APP_LOCALE=ar', 'APP_LOCALE=en', file_get_contents($path)));
        $initialEnv = file_get_contents($path);

        $this->get('/install')->assertOk()
            ->assertSee('<html lang="en" dir="ltr">', false)
            ->assertSee('Secure first-run setup wizard');

        $this->get('/install?lang=invalid')->assertOk()
            ->assertSee('<html lang="en" dir="ltr">', false);

        $this->get('/install?lang%5B%5D=ar')->assertOk()
            ->assertSee('<html lang="en" dir="ltr">', false);

        $this->assertSame($initialEnv, file_get_contents($path));
    }

    public function test_admin_validation_messages_follow_selected_language_without_progress_mutation(): void
    {
        $state = app(InstallState::class);
        $state->markStepComplete(6);
        $token = $state->token();

        $this->post('/install/step/7?lang=ar', ['installer_token' => $token])
            ->assertUnprocessable()
            ->assertSee('حقل الاسم مطلوب.', false)
            ->assertSee('حقل البريد الإلكتروني مطلوب.', false);

        $this->post('/install/step/7?lang=en', ['installer_token' => $token])
            ->assertUnprocessable()
            ->assertSee('The Name field is required.', false)
            ->assertSee('The Email field is required.', false);

        $this->assertSame(6, $state->progress()['completed_step']);
        $this->assertFalse($state->isInstalled());
    }

    public function test_failed_migration_shows_localized_error_without_advancing_or_executing_twice(): void
    {
        $state = app(InstallState::class);
        $state->markStepComplete(10);
        $token = $state->token();

        Artisan::shouldReceive('call')
            ->once()
            ->with('migrate', ['--force' => true])
            ->andReturn(1);

        $this->post('/install/step/11?lang=ar', ['installer_token' => $token])
            ->assertUnprocessable()
            ->assertSee('فشل تنفيذ تحديثات قاعدة البيانات.', false);

        $this->assertSame(10, $state->progress()['completed_step']);
        $this->assertFalse($state->isInstalled());
    }

    public function test_bad_token_is_rejected_in_both_languages_without_changing_progress(): void
    {
        $state = app(InstallState::class);

        foreach (['ar', 'en'] as $locale) {
            $this->post('/install/step/1?lang='.$locale, [
                'installer_token' => str_repeat('0', 64),
            ])->assertStatus(419);
        }

        $this->assertSame(0, $state->progress()['completed_step']);
        $this->assertFalse($state->isInstalled());
    }
}
