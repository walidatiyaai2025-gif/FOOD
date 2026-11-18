<?php

namespace Tests\Feature;

use App\Domain\Installer\InstallerChecklist;
use App\Domain\Installer\InstallerEnvironment;
use App\Domain\Installer\InstallerWorkflow;
use App\Domain\Installer\InstallState;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;
use Mockery;
use Symfony\Component\Console\Output\BufferedOutput;
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
            ->assertSee('data-installer-master-data-readiness="review-required"', false)
            ->assertSee(__('installer.wizard.seed_admin_readiness', [], 'ar'))
            ->assertSee('إنهاء التثبيت')
            ->assertDontSee('First-run Setup Wizard');

        $this->get('/install?lang=en')
            ->assertOk()
            ->assertSee('<html lang="en" dir="ltr">', false)
            ->assertSee('Step 1 of 15')
            ->assertSee('Welcome')
            ->assertSee('data-installer-master-data-readiness="review-required"', false)
            ->assertSee(__('installer.wizard.seed_admin_readiness', [], 'en'))
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

    public function test_host_readiness_guidance_is_localized_and_does_not_mutate_environment(): void
    {
        $environment = $this->fixtureDirectory.'/.env';
        $before = file_get_contents($environment);

        foreach (['ar', 'en'] as $locale) {
            $this->get('/install?lang='.$locale)
                ->assertOk()
                ->assertSee(__('installer.hosting.document_root', [], $locale));

            foreach (['document_root', 'php_extensions', 'file_permissions', 'mysql', 'public_storage', 'scheduler'] as $key) {
                $message = __('installer.hosting.'.$key, [], $locale);
                $this->assertIsString($message);
                $this->assertNotSame('installer.hosting.'.$key, $message);
                $this->assertNotSame('', trim($message));
            }
        }

        $token = app(InstallState::class)->token();
        $this->post('/install/step/1?lang=en', ['installer_token' => $token])
            ->assertRedirect(route('install.index', ['step' => 2, 'lang' => 'en']));
        $this->get('/install?step=2&lang=en')
            ->assertOk()
            ->assertSee(__('installer.hosting.php_extensions', [], 'en'));
        $this->get('/install?step=2&lang=ar')
            ->assertOk()
            ->assertSee(__('installer.hosting.php_extensions', [], 'ar'));

        $this->assertSame($before, file_get_contents($environment));
        $this->assertFalse(app(InstallState::class)->isInstalled());
    }

    public function test_document_root_precheck_is_read_only_and_localized_for_safe_and_unsafe_host_states(): void
    {
        $state = app(InstallState::class);
        $beforeEnvironment = file_get_contents($this->fixtureDirectory.'/.env');
        // The first GET deliberately mints the install CSRF/progress token.
        // Establish the stable baseline after the legitimate one-time mint,
        // then assert the read-only Document Root precheck never advances it.
        $initialToken = $state->token();
        $beforeProgress = $state->progress();

        $cases = [
            'match' => public_path(),
            'mismatch' => storage_path(),
            'unverified' => $this->fixtureDirectory.'/absent-document-root',
        ];

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            foreach ($cases as $status => $documentRoot) {
                $response = $this->withServerVariables(['DOCUMENT_ROOT' => $documentRoot])
                    ->get('/install?lang='.$locale)
                    ->assertOk()
                    ->assertSee('<html lang="'.$locale.'" dir="'.$direction.'">', false)
                    ->assertSee('data-installer-document-root-readiness="'.$status.'"', false)
                    ->assertSee(__('installer.hosting.document_root_precheck_'.$status, [], $locale))
                    ->assertDontSee($documentRoot);

                foreach (array_keys($cases) as $other) {
                    if ($other !== $status) {
                        $response->assertDontSee(
                            __('installer.hosting.document_root_precheck_'.$other, [], $locale),
                        );
                    }
                }
            }
        }

        $this->assertSame($beforeEnvironment, file_get_contents($this->fixtureDirectory.'/.env'));
        $this->assertSame($beforeProgress, $state->progress());
        $this->assertFalse($state->isInstalled());
    }

    public function test_erp_sodium_status_is_localized_on_requirements_step_without_certifying_modules(): void
    {
        $environment = $this->fixtureDirectory.'/.env';
        $before = file_get_contents($environment);
        $token = app(InstallState::class)->token();

        $this->post('/install/step/1?lang=en', ['installer_token' => $token])
            ->assertRedirect(route('install.index', ['step' => 2, 'lang' => 'en']));

        $available = app(InstallerWorkflow::class)->hasErpEvidenceVerifier();
        $status = $available ? 'erp_evidence_crypto_available' : 'erp_evidence_crypto_missing';
        $otherStatus = $available ? 'erp_evidence_crypto_missing' : 'erp_evidence_crypto_available';

        foreach (['ar', 'en'] as $locale) {
            $this->get('/install?step=2&lang='.$locale)
                ->assertOk()
                ->assertSee(__('installer.hosting.'.$status, [], $locale))
                ->assertDontSee(__('installer.hosting.'.$otherStatus, [], $locale));

            $this->assertNotSame(
                __('installer.hosting.'.$status, [], $locale),
                __('installer.hosting.'.$otherStatus, [], $locale),
            );
        }

        $this->assertSame($before, file_get_contents($environment));
        $this->assertFalse(app(InstallState::class)->isInstalled());
    }

    public function test_configuration_help_and_readiness_are_localized_without_changing_secrets_or_progress(): void
    {
        $state = app(InstallState::class);
        $path = $this->fixtureDirectory.'/.env';
        $before = file_get_contents($path);
        $state->markStepComplete(3);

        foreach (['ar', 'en'] as $locale) {
            $this->get('/install?step=4&lang='.$locale)
                ->assertOk()
                ->assertSee(__('installer.wizard.help_summary', [], $locale))
                ->assertSee(__('installer.help.db_database', [], $locale))
                ->assertSee(__('installer.readiness.not_saved', [], $locale))
                ->assertSee(__('installer.wizard.save_continue', [], $locale));
        }

        $state->markStepComplete(4);
        $this->get('/install?step=5&lang=en')
            ->assertOk()
            ->assertSee(__('installer.readiness.db_saved', [], 'en'))
            ->assertSee(__('installer.wizard.test_database', [], 'en'));

        $state->markStepComplete(9);
        foreach (['ar', 'en'] as $locale) {
            $this->get('/install?step=10&lang='.$locale)
                ->assertOk()
                ->assertSee(__('installer.help.mail_from_address', [], $locale))
                ->assertSee(__('installer.readiness.not_saved', [], $locale));
        }

        $state->markStepComplete(10);
        $this->get('/install?step=10&lang=en')
            ->assertOk()
            ->assertSee(__('installer.readiness.mail_non_delivery', [], 'en'));

        $this->assertSame($before, file_get_contents($path));
        $this->assertFalse($state->isInstalled());
    }

    public function test_cache_queue_inputs_reuse_save_validation_without_persisting_in_ar_en(): void
    {
        $state = app(InstallState::class);
        $state->markStepComplete(8);
        $token = $state->token();
        $envPath = $this->fixtureDirectory.'/.env';
        $beforeEnvironment = file_get_contents($envPath);
        $beforeProgress = $state->progress();

        foreach (['ar', 'en'] as $locale) {
            $this->get('/install?step=9&lang='.$locale)
                ->assertOk()
                ->assertSee('<html lang="'.$locale.'" dir="'.($locale === 'ar' ? 'rtl' : 'ltr').'">', false)
                ->assertSee(__('installer.wizard.cache_queue_test_inputs_help', [], $locale))
                ->assertSee('name="cache_action" value="test_inputs"', false)
                ->assertSee(__('installer.wizard.cache_queue_test_inputs', [], $locale));

            $this->post('/install/step/9?lang='.$locale, [
                'installer_token' => $token,
                'cache_action' => 'test_inputs',
                'cache_store' => 'redis',
                'queue_connection' => 'redis',
                'redis_host' => 'redis.example.com',
                'redis_port' => 6379,
                'redis_password' => 'example-test-secret-never-persist',
            ])
                ->assertOk()
                ->assertSee(__('installer.wizard.cache_queue_test_inputs_passed', [], $locale))
                ->assertSee(__('installer.readiness.not_saved', [], $locale))
                ->assertDontSee('example-test-secret-never-persist')
                ->assertDontSee(__('installer.readiness.queue_checked', [], $locale));

            $this->assertSame($beforeEnvironment, file_get_contents($envPath));
            $this->assertSame($beforeProgress, $state->progress());
        }

        $this->assertFalse($state->isInstalled());
    }

    public function test_cache_queue_inputs_reject_missing_provider_invalid_action_and_forged_token(): void
    {
        $state = app(InstallState::class);
        $state->markStepComplete(8);
        $token = $state->token();
        $envPath = $this->fixtureDirectory.'/.env';
        $beforeEnvironment = file_get_contents($envPath);
        $beforeProgress = $state->progress();

        foreach (['ar', 'en'] as $locale) {
            $this->post('/install/step/9?lang='.$locale, [
                'installer_token' => $token,
                'cache_action' => 'test_inputs',
                'cache_store' => 'redis',
                'queue_connection' => 'redis',
                'redis_host' => '',
                'redis_port' => 0,
                'redis_password' => 'invalid-secret-never-persist',
            ])
                ->assertUnprocessable()
                ->assertSee(__('installer.wizard.error', [], $locale))
                ->assertDontSee('invalid-secret-never-persist');

            $this->assertSame($beforeEnvironment, file_get_contents($envPath));
            $this->assertSame($beforeProgress, $state->progress());
        }

        $this->post('/install/step/9?lang=en', [
            'installer_token' => $token,
            'cache_action' => 'bypass_save',
            'cache_store' => 'file',
            'queue_connection' => 'sync',
        ])->assertUnprocessable();

        $this->post('/install/step/9?lang=en', [
            'cache_action' => 'test_inputs',
            'cache_store' => 'file',
            'queue_connection' => 'sync',
        ])->assertStatus(419);

        $this->assertSame($beforeEnvironment, file_get_contents($envPath));
        $this->assertSame($beforeProgress, $state->progress());
        $this->assertFalse($state->isInstalled());
    }

    public function test_cache_queue_inputs_step_order_and_existing_save_behavior_are_preserved(): void
    {
        $state = app(InstallState::class);
        $state->markStepComplete(7);
        $token = $state->token();
        $envPath = $this->fixtureDirectory.'/.env';
        $beforeEnvironment = file_get_contents($envPath);

        $this->post('/install/step/9?lang=en', [
            'installer_token' => $token,
            'cache_action' => 'test_inputs',
            'cache_store' => 'file',
            'queue_connection' => 'sync',
        ])->assertStatus(409);

        $this->assertSame(7, $state->progress()['completed_step']);
        $this->assertSame($beforeEnvironment, file_get_contents($envPath));

        $state->markStepComplete(8);
        $this->post('/install/step/9?lang=en', [
            'installer_token' => $token,
            'cache_action' => 'save',
            'cache_store' => 'file',
            'queue_connection' => 'sync',
        ])->assertRedirect(route('install.index', ['step' => 10, 'lang' => 'en']));

        $this->assertSame(9, $state->progress()['completed_step']);
        $this->assertSame('file', app(InstallerEnvironment::class)->read(['CACHE_STORE'])['CACHE_STORE']);
        $this->assertSame('sync', app(InstallerEnvironment::class)->read(['QUEUE_CONNECTION'])['QUEUE_CONNECTION']);
        $this->assertFalse($state->isInstalled());
    }

    public function test_mail_test_inputs_uses_existing_validator_in_both_locales_without_saving_or_sending(): void
    {
        $state = app(InstallState::class);
        $state->markStepComplete(9);
        $token = $state->token();
        $envPath = $this->fixtureDirectory.'/.env';
        $beforeEnvironment = file_get_contents($envPath);
        $beforeProgress = $state->progress();

        Mail::fake();

        foreach (['ar', 'en'] as $locale) {
            $this->get('/install?step=10&lang='.$locale)
                ->assertOk()
                ->assertSee('<html lang="'.$locale.'" dir="'.($locale === 'ar' ? 'rtl' : 'ltr').'">', false)
                ->assertSee(__('installer.wizard.mail_test_inputs_help', [], $locale))
                ->assertSee('name="mail_action" value="test_inputs"', false)
                ->assertSee(__('installer.wizard.mail_test_inputs', [], $locale));

            $this->post('/install/step/10?lang='.$locale, [
                'installer_token' => $token,
                'mail_action' => 'test_inputs',
                'mail_mailer' => 'smtp',
                'mail_host' => 'smtp.example.com',
                'mail_port' => 587,
                'mail_scheme' => 'smtp',
                'mail_username' => 'sender@example.com',
                'mail_password' => 'private-mail-secret-test-only',
                'mail_from_address' => 'noreply@example.com',
                'mail_from_name' => 'Example',
            ])
                ->assertOk()
                ->assertSee(__('installer.wizard.mail_test_inputs_passed', [], $locale))
                ->assertSee(__('installer.readiness.not_saved', [], $locale))
                ->assertDontSee('private-mail-secret-test-only')
                ->assertDontSee(__('installer.readiness.smtp_unverified', [], $locale));

            $this->assertSame($beforeEnvironment, file_get_contents($envPath));
            $this->assertSame($beforeProgress, $state->progress());
        }

        Mail::assertNothingSent();
        $this->assertFalse($state->isInstalled());
    }

    public function test_mail_test_inputs_rejects_invalid_provider_values_and_fake_test_actions_without_state_mutation(): void
    {
        $state = app(InstallState::class);
        $state->markStepComplete(9);
        $token = $state->token();
        $envPath = $this->fixtureDirectory.'/.env';
        $beforeEnvironment = file_get_contents($envPath);
        $beforeProgress = $state->progress();

        Mail::fake();

        foreach (['ar', 'en'] as $locale) {
            $this->post('/install/step/10?lang='.$locale, [
                'installer_token' => $token,
                'mail_action' => 'test_inputs',
                'mail_mailer' => 'smtp',
                'mail_host' => '',
                'mail_port' => 0,
                'mail_from_address' => 'invalid',
                'mail_from_name' => '',
                'mail_password' => 'private-invalid-secret-test-only',
            ])
                ->assertUnprocessable()
                ->assertSee(__('installer.wizard.error', [], $locale))
                ->assertDontSee('private-invalid-secret-test-only');

            $this->assertSame($beforeEnvironment, file_get_contents($envPath));
            $this->assertSame($beforeProgress, $state->progress());
        }

        $this->post('/install/step/10?lang=en', [
            'installer_token' => $token,
            'mail_action' => 'bypass_save',
            'mail_mailer' => 'log',
            'mail_from_address' => 'noreply@example.com',
            'mail_from_name' => 'Example',
        ])->assertUnprocessable();

        $this->post('/install/step/10?lang=en', [
            'mail_action' => 'test_inputs',
            'mail_mailer' => 'log',
            'mail_from_address' => 'noreply@example.com',
            'mail_from_name' => 'Example',
        ])->assertStatus(419);

        $this->assertSame($beforeEnvironment, file_get_contents($envPath));
        $this->assertSame($beforeProgress, $state->progress());
        Mail::assertNothingSent();
    }

    public function test_mail_test_inputs_respects_step_order_and_normal_save_still_advances(): void
    {
        $state = app(InstallState::class);
        $state->markStepComplete(8);
        $token = $state->token();
        $payload = [
            'installer_token' => $token,
            'mail_action' => 'test_inputs',
            'mail_mailer' => 'log',
            'mail_from_address' => 'noreply@example.com',
            'mail_from_name' => 'Example',
        ];
        $before = file_get_contents($this->fixtureDirectory.'/.env');

        $this->post('/install/step/10?lang=en', $payload)->assertStatus(409);
        $this->assertSame(8, $state->progress()['completed_step']);
        $this->assertSame($before, file_get_contents($this->fixtureDirectory.'/.env'));

        $state->markStepComplete(9);
        $this->post('/install/step/10?lang=en', [
            ...$payload,
            'mail_action' => 'save',
        ])->assertRedirect(route('install.index', ['step' => 11, 'lang' => 'en']));

        $this->assertSame(10, $state->progress()['completed_step']);
        $this->assertSame('log', app(InstallerEnvironment::class)->read(['MAIL_MAILER'])['MAIL_MAILER']);
        $this->assertFalse($state->isInstalled());
    }

    public function test_shared_host_fallback_does_not_select_unprovisioned_redis(): void
    {
        app(InstallState::class)->markStepComplete(8);

        $this->get('/install?step=9&lang=en')
            ->assertOk()
            ->assertSee('value="file" selected', false)
            ->assertSee('value="sync" selected', false)
            ->assertSee(__('installer.help.cache_store', [], 'en'));
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
            ->with('migrate', ['--force' => true], Mockery::type(BufferedOutput::class))
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
