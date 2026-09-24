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

        $submittedToken = (string) $request->input('installer_token', '');
        abort_if($submittedToken === '' || ! hash_equals($state->token(), $submittedToken), 419);

        $progress = $state->progress();
        $allowedStep = min(InstallerChecklist::count(), $progress['completed_step'] + 1);
        abort_if($step > $allowedStep, 409, 'Installer steps must be completed in order.');

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
                        'cache_store' => ['required', 'in:redis,file'],
                        'queue_connection' => ['required', 'in:redis,sync'],
                        'redis_host' => ['nullable', 'required_if:cache_store,redis', 'required_if:queue_connection,redis', 'string', 'max:255'],
                        'redis_port' => ['nullable', 'required_if:cache_store,redis', 'required_if:queue_connection,redis', 'integer', 'between:1,65535'],
                        'redis_password' => ['nullable', 'string', 'max:512'],
                    ]);
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
                        'mail_mailer' => ['required', 'in:log,array,smtp'],
                        'mail_host' => ['nullable', 'required_if:mail_mailer,smtp', 'string', 'max:255'],
                        'mail_port' => ['nullable', 'required_if:mail_mailer,smtp', 'integer', 'between:1,65535'],
                        'mail_username' => ['nullable', 'string', 'max:255'],
                        'mail_password' => ['nullable', 'string', 'max:512'],
                        'mail_scheme' => ['nullable', 'in:smtp,smtps'],
                        'mail_from_address' => ['required', 'email', 'max:255'],
                        'mail_from_name' => ['required', 'string', 'max:120'],
                    ]);
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
                    $workflow->runMigrations();
                    break;

                case 12:
                    $workflow->seedRequiredCoreData();
                    break;

                case 13:
                    $workflow->generateApplicationKey();
                    break;

                case 14:
                    $workflow->healthCheck();
                    break;

                case 15:
                    $admin = $progress['admin'];
                    if ($admin === null) {
                        throw new RuntimeException('Super Admin setup is incomplete. Return to step 7 and provide the account details.');
                    }

                    $workflow->finish($admin);

                    return redirect('/admin');
            }

            $state->markStepComplete($step, $admin);

            return redirect()->route('install.index', [
                'step' => min(InstallerChecklist::count(), $step + 1),
            ]);
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
     * @param  array<string, array<int, string|string>  $rules
     * @return array<string, mixed>
     */
    private function validated(Request $request, array $rules): array
    {
        $validator = Validator::make($request->all(), $rules);

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

        return [
            'steps' => InstallerChecklist::details(),
            'currentStep' => $currentStep,
            'completedStep' => $progress['completed_step'],
            'allowedStep' => $allowedStep,
            'step' => InstallerChecklist::step($currentStep),
            'values' => $values,
            'input' => $input,
            'errorMessages' => $errorMessages,
            'installerToken' => $state->token(),
            'requirementFailures' => $currentStep === 2 ? $workflow->serverRequirementFailures() : [],
            'permissionFailures' => $currentStep === 3 ? $workflow->writablePathFailures() : [],
        ];
    }

    private function ensureInstallerOpen(InstallState $state): void
    {
        abort_if($state->isInstalled(), 404);
    }
}
