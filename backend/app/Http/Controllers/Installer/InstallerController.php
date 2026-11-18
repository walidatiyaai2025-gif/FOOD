<?php

namespace App\Http\Controllers\Installer;

use App\Domain\Installer\InstallerChecklist;
use App\Domain\Installer\InstallerEnvironment;
use App\Domain\Installer\InstallerWorkflow;
use App\Domain\Installer\InstallState;
use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Validator;
use RuntimeException;

class InstallerController extends Controller
{
    public function show(
        Request $request,
        InstallState $state,
        InstallerEnvironment $environment,
        InstallerWorkflow $workflow,
    ): View {
        $this->ensureInstallerOpen($state);
        $this->setInstallerLocale($request, $environment);

        $progress = $state->progress();
        $allowedStep = min(InstallerChecklist::count(), $progress['completed_step'] + 1);
        $requestedStep = $request->integer('step', $allowedStep);
        $currentStep = $requestedStep >= 1 && $requestedStep <= $allowedStep
            ? $requestedStep
            : $allowedStep;

        return view('install.index', $this->viewData(
            $state,
            $environment,
            $workflow,
            $currentStep,
        ));
    }

    public function process(
        Request $request,
        int $step,
        InstallState $state,
        InstallerEnvironment $environment,
        InstallerWorkflow $workflow,
    ): RedirectResponse|Response {
        $this->ensureInstallerOpen($state);
        abort_unless($step >= 1 && $step <= InstallerChecklist::count(), 404);
        $this->setInstallerLocale($request, $environment);

        $submittedToken = (string) $request->input('installer_token', '');
        abort_if($submittedToken === '' || ! hash_equals($state->token(), $submittedToken), 419);

        $progress = $state->progress();
        $allowedStep = min(InstallerChecklist::count(), $progress['completed_step'] + 1);
        abort_if($step > $allowedStep, 409, (string) __('installer.errors.step_order'));

        try {
            $admin = null;

            switch ($step) {
                case 1:
                    break;

                case 2:
                    $failures = $workflow->serverRequirementFailures();
                    if ($failures !== []) {
                        throw new RuntimeException(implode(' ', $failures));
                    }
                    break;

                case 3:
                    $failures = $workflow->writablePathFailures();
                    if ($failures !== []) {
                        throw new RuntimeException(implode(' ', $failures));
                    }
                    break;

                case 4:
                    $validated = $this->validated($request, [
                        'db_host' => ['required', 'string', 'max:255'],
                        'db_port' => ['required', 'integer', 'between:1,65535'],
                        'db_database' => ['required', 'string', 'max:128'],
                        'db_username' => ['required', 'string', 'max:128'],
                        'db_password' => ['nullable', 'string', 'max:512'],
                    ]);
                    $workflow->saveDatabaseConfiguration([
                        'host' => (string) $validated['db_host'],
                        'port' => (int) $validated['db_port'],
                        'database' => (string) $validated['db_database'],
                        'username' => (string) $validated['db_username'],
                        'password' => (string) ($validated['db_password'] ?? ''),
                    ]);
                    break;

                case 5:
                    $workflow->testDatabaseConnection();
                    break;

                case 6:
                    $validated = $this->validated($request, [
                        'app_name' => ['required', 'string', 'max:120'],
                        'app_url' => ['required', 'url:http,https', 'max:255'],
                        'app_locale' => ['required', 'in:ar,en'],
                    ]);
                    $workflow->savePlatformInformation([
                        'name' => (string) $validated['app_name'],
                        'url' => (string) $validated['app_url'],
                        'locale' => (string) $validated['app_locale'],
                    ]);
                    break;

                case 7:
                    $validated = $this->validated($request, [
                        'admin_name' => ['required', 'string', 'max:120'],
                        'admin_email' => ['required', 'email', 'max:255'],
                        'admin_locale' => ['required', 'in:ar,en'],
                        'admin_password' => ['required', 'string', 'min:12', 'confirmed'],
                    ]);
                    $admin = $workflow->prepareSuperAdmin([
                        'name' => (string) $validated['admin_name'],
                        'email' => (string) $validated['admin_email'],
                        'locale' => (string) $validated['admin_locale'],
                        'password' => (string) $validated['admin_password'],
                    ]);
                    break;

                case 8:
                    $validated = $this->validated($request, [
                        'filesystem_disk' => ['required', 'in:local,public'],
                    ]);
                    $workflow->saveStorageConfiguration((string) $validated['filesystem_disk']);
                    break;

                case 9:
                    $validated = $this->validated($request, [
                        'cache_action' => ['sometimes', 'in:save,test_inputs'],
                        'cache_store' => ['required', 'in:redis,file'],
                        'queue_connection' => ['required', 'in:redis,sync'],
                        'redis_host' => ['nullable', 'required_if:cache_store,redis', 'required_if:queue_connection,redis', 'string', 'max:255'],
                        'redis_port' => ['nullable', 'required_if:cache_store,redis', 'required_if:queue_connection,redis', 'integer', 'between:1,65535'],
                        'redis_password' => ['nullable', 'string', 'max:512'],
                    ]);
                    // Test Inputs is intentionally write-free. It reuses the exact
                    // Save validator and installer token/step-order checks, but it
                    // does not probe Redis, start a worker, schedule Cron or certify
                    // any provider connection. Real runtime checks run at step 14.
                    if (($validated['cache_action'] ?? 'save') === 'test_inputs') {
                        return response()->view('install.index', [
                            ...$this->viewData(
                                $state,
                                $environment,
                                $workflow,
                                $step,
                                [],
                                $request->except([
                                    'installer_token',
                                    'db_password',
                                    'admin_password',
                                    'admin_password_confirmation',
                                    'redis_password',
                                    'mail_password',
                                ]),
                            ),
                            'cacheQueueInputTestPassed' => true,
                        ]);
                    }

                    $workflow->saveCacheQueueConfiguration([
                        'cache' => (string) $validated['cache_store'],
                        'queue' => (string) $validated['queue_connection'],
                        'redis_host' => isset($validated['redis_host']) ? (string) $validated['redis_host'] : null,
                        'redis_port' => isset($validated['redis_port']) ? (int) $validated['redis_port'] : null,
                        'redis_password' => isset($validated['redis_password']) ? (string) $validated['redis_password'] : null,
                    ]);
                    break;

                case 10:
                    $validated = $this->validated($request, [
                        'mail_action' => ['sometimes', 'in:save,test_inputs'],
                        'mail_mailer' => ['required', 'in:log,array,smtp'],
                        'mail_host' => ['nullable', 'required_if:mail_mailer,smtp', 'string', 'max:255'],
                        'mail_port' => ['nullable', 'required_if:mail_mailer,smtp', 'integer', 'between:1,65535'],
                        'mail_username' => ['nullable', 'string', 'max:255'],
                        'mail_password' => ['nullable', 'string', 'max:512'],
                        'mail_scheme' => ['nullable', 'in:smtp,smtps'],
                        'mail_from_address' => ['required', 'email', 'max:255'],
                        'mail_from_name' => ['required', 'string', 'max:120'],
                    ]);
                    // Test Inputs reuses the exact Save validator and installer token/order
                    // checks, but must never persist credentials, send SMTP mail or
                    // advance first-install progress. A syntactically valid SMTP host is
                    // NOT proof that the provider accepted or delivered a message.
                    if (($validated['mail_action'] ?? 'save') === 'test_inputs') {
                        return response()->view('install.index', [
                            ...$this->viewData(
                                $state,
                                $environment,
                                $workflow,
                                $step,
                                [],
                                $request->except([
                                    'installer_token',
                                    'db_password',
                                    'admin_password',
                                    'admin_password_confirmation',
                                    'redis_password',
                                    'mail_password',
                                ]),
                            ),
                            'mailInputTestPassed' => true,
                        ]);
                    }

                    $workflow->saveMailConfiguration([
                        'mailer' => (string) $validated['mail_mailer'],
                        'host' => (string) ($validated['mail_host'] ?? ''),
                        'port' => (int) ($validated['mail_port'] ?? 587),
                        'username' => (string) ($validated['mail_username'] ?? ''),
                        'password' => (string) ($validated['mail_password'] ?? ''),
                        'scheme' => (string) ($validated['mail_scheme'] ?? ''),
                        'from_address' => (string) $validated['mail_from_address'],
                        'from_name' => (string) $validated['mail_from_name'],
                    ]);
                    break;

                case 11:
                    // A completed step may be posted again after a refresh. Do not
                    // execute migrations twice; failed first attempts remain retryable.
                    if ($progress['completed_step'] < 11) {
                        $workflow->runMigrations();
                    }
                    break;

                case 12:
                    // Replaying a completed step must not reset seeded reference
                    // or administrator-managed data. First attempts can still retry.
                    if ($progress['completed_step'] < 12) {
                        $workflow->seedRequiredCoreData();
                    }
                    break;

                case 13:
                    // Step 13 is repeatable as an HTTP request, but key rotation is
                    // not. Keep ciphertext generated after the first completion
                    // readable when the installer form is refreshed or resubmitted.
                    if ($progress['completed_step'] < 13) {
                        $workflow->generateApplicationKey();
                    }
                    break;

                case 14:
                    $workflow->healthCheck();
                    break;

                case 15:
                    $admin = $progress['admin'];
                    if ($admin === null) {
                        throw new RuntimeException((string) __('installer.errors.admin_incomplete'));
                    }

                    $workflow->finish($admin);

                    return redirect()->route('admin.b2c.login');
            }

            $state->markStepComplete($step, $admin);

            $next = ['step' => min(InstallerChecklist::count(), $step + 1)];
            $queryLocale = $request->query('lang');
            if (is_string($queryLocale) && in_array($queryLocale, ['ar', 'en'], true)) {
                $next['lang'] = $queryLocale;
            }

            return redirect()->route('install.index', $next);
        } catch (RuntimeException $exception) {
            return response()->view('install.index', $this->viewData(
                $state,
                $environment,
                $workflow,
                $step,
                [$exception->getMessage()],
                $request->except([
                    'installer_token',
                    'db_password',
                    'admin_password',
                    'admin_password_confirmation',
                    'redis_password',
                    'mail_password',
                ]),
            ), 422);
        }
    }

    /**
     * @param  array<string, array<int, string>>  $rules
     * @return array<string, mixed>
     */
    private function validated(Request $request, array $rules): array
    {
        $validator = Validator::make(
            $request->all(),
            $rules,
            (array) __('installer.validation'),
            (array) __('installer.fields'),
        );

        if ($validator->fails()) {
            throw new RuntimeException(implode(' ', $validator->errors()->all()));
        }

        return $validator->validated();
    }

    /**
     * @param  array<int, string>  $errorMessages
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function viewData(
        InstallState $state,
        InstallerEnvironment $environment,
        InstallerWorkflow $workflow,
        int $currentStep,
        array $errorMessages = [],
        array $input = [],
    ): array {
        $progress = $state->progress();
        $allowedStep = min(InstallerChecklist::count(), $progress['completed_step'] + 1);
        $currentStep = max(1, min($currentStep, $allowedStep));
        $values = $environment->read([
            'APP_NAME', 'APP_URL', 'APP_LOCALE',
            'DB_HOST', 'DB_PORT', 'DB_DATABASE', 'DB_USERNAME',
            'FILESYSTEM_DISK', 'CACHE_STORE', 'QUEUE_CONNECTION', 'REDIS_HOST', 'REDIS_PORT',
            'MAIL_MAILER', 'MAIL_HOST', 'MAIL_PORT', 'MAIL_USERNAME', 'MAIL_SCHEME',
            'MAIL_FROM_ADDRESS', 'MAIL_FROM_NAME',
        ]);

        // The view is presentation-only: keep complex expressions outside Blade,
        // and never infer live readiness from a stored configuration alone.
        $completedStep = (int) $progress['completed_step'];
        $configurationFields = [
            4 => ['db_host', 'db_port', 'db_database', 'db_username', 'db_password'],
            6 => ['app_name', 'app_url', 'app_locale'],
            7 => ['admin_name', 'admin_email', 'admin_locale', 'admin_password', 'admin_password_confirmation'],
            8 => ['filesystem_disk'],
            9 => ['cache_store', 'queue_connection', 'redis_host', 'redis_port', 'redis_password'],
            10 => ['mail_mailer', 'mail_scheme', 'mail_host', 'mail_port', 'mail_username', 'mail_password', 'mail_from_address', 'mail_from_name'],
        ][$currentStep] ?? [];

        $readinessKey = match ($currentStep) {
            4, 5 => $completedStep >= 5 ? 'db_tested' : ($completedStep >= 4 ? 'db_saved' : 'not_saved'),
            6 => $completedStep >= 6 ? 'platform_saved' : 'not_saved',
            7 => $completedStep >= 7 ? 'admin_prepared' : 'not_saved',
            8 => $completedStep >= 14 ? 'storage_checked' : ($completedStep >= 8 ? 'storage_pending' : 'not_saved'),
            9 => $completedStep >= 14 ? 'queue_checked' : ($completedStep >= 9 ? 'queue_pending' : 'not_saved'),
            10 => $completedStep >= 10 ? (($values['MAIL_MAILER'] ?? 'log') === 'smtp' ? 'smtp_unverified' : 'mail_non_delivery') : 'not_saved',
            default => null,
        };

        $cacheChoice = $input['cache_store'] ?? ($values['CACHE_STORE'] ?? null);
        $queueChoice = $input['queue_connection'] ?? ($values['QUEUE_CONNECTION'] ?? null);
        $selectedCache = in_array($cacheChoice, ['file', 'redis'], true) ? $cacheChoice : 'file';
        $selectedQueue = in_array($queueChoice, ['sync', 'redis'], true) ? $queueChoice : 'sync';

        $actionKey = match ($currentStep) {
            4, 6, 7, 8, 9, 10 => 'save_continue',
            5 => 'test_database',
            14 => 'test_health',
            15 => 'finish',
            default => 'continue',
        };

        return [
            'configurationFields' => $configurationFields,
            'readinessKey' => $readinessKey,
            'selectedCache' => $selectedCache,
            'selectedQueue' => $selectedQueue,
            'actionKey' => $actionKey,
            'steps' => InstallerChecklist::details(),
            'currentStep' => $currentStep,
            'completedStep' => $progress['completed_step'],
            'allowedStep' => $allowedStep,
            'step' => InstallerChecklist::step($currentStep),
            'values' => $values,
            'input' => $input,
            'errorMessages' => $errorMessages,
            'installerToken' => $state->token(),
            'installerLocale' => app()->getLocale(),
            'languageQuery' => in_array(request()->query('lang'), ['ar', 'en'], true)
                ? request()->query('lang')
                : null,
            'requirementFailures' => $currentStep === 2 ? $workflow->serverRequirementFailures() : [],
            'erpEvidenceVerifierAvailable' => $currentStep === 2 && $workflow->hasErpEvidenceVerifier(),
            'documentRootPrecheck' => $currentStep === 1 ? $this->documentRootPrecheck() : null,
            'permissionFailures' => $currentStep === 3 ? $workflow->writablePathFailures() : [],
        ];
    }

    /**
     * Inspect the PHP request's Document Root without reading private files
     * or mutating the host. A matching realpath is only a preflight: it does
     * NOT establish real HTTP exposure, rewrite or cPanel ownership safety.
     *
     * @return 'match'|'mismatch'|'unverified'
     */
    private function documentRootPrecheck(): string
    {
        $root = request()->server('DOCUMENT_ROOT');
        if (! is_string($root) || $root === '' || str_contains($root, "\0")) {
            return 'unverified';
        }

        $absolute = str_starts_with($root, '/')
            || (strlen($root) >= 3
                && ctype_alpha($root[0])
                && $root[1] === ':'
                && in_array($root[2], ['/', '\\'], true));
        if (! $absolute) {
            return 'unverified';
        }

        $actualPath = realpath($root);
        $expectedPath = realpath(public_path());
        if ($actualPath === false || $expectedPath === false) {
            return 'unverified';
        }

        $actual = rtrim(str_replace('\\', '/', $actualPath), '/');
        $expected = rtrim(str_replace('\\', '/', $expectedPath), '/');
        if (PHP_OS_FAMILY === 'Windows') {
            $actual = strtolower($actual);
            $expected = strtolower($expected);
        }

        return $actual === $expected ? 'match' : 'mismatch';
    }

    private function setInstallerLocale(Request $request, InstallerEnvironment $environment): void
    {
        $requested = $request->query('lang');
        if (is_string($requested) && in_array($requested, ['ar', 'en'], true)) {
            app()->setLocale($requested);

            return;
        }

        // The saved platform locale is the default; requesting a different
        // display language must never write .env or mutate installer progress.
        $saved = $environment->read(['APP_LOCALE'])['APP_LOCALE'] ?? null;
        app()->setLocale($saved === 'en' ? 'en' : 'ar');
    }

    private function ensureInstallerOpen(InstallState $state): void
    {
        abort_if($state->isInstalled(), 404);
    }
}
