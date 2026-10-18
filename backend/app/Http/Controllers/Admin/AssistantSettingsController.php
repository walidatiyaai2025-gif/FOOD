<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AssistantExternalAiClient;
use App\Services\AssistantProviderSettings;
use App\Services\AssistantRuntimeSettings;
use App\Services\AuditLogger;
use App\Support\AdminNavigation;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final class AssistantSettingsController extends Controller
{
    public function __construct(
        private readonly AssistantRuntimeSettings $settings,
        private readonly AssistantProviderSettings $providerSettings,
        private readonly AssistantExternalAiClient $externalAi,
        private readonly AuditLogger $audit,
        private readonly AdminNavigation $navigation,
    ) {}

    public function index(Request $request): View
    {
        $actor = $this->actor($request);
        abort_unless(
            $actor->hasPermission('settings.view') || $actor->hasPermission('settings.manage'),
            403,
        );

        return view('admin.assistant-settings', [
            'user' => $actor,
            'navGroups' => $this->navigation->groupsFor($actor),
            'navContext' => 'administration_hub',
            'assistantSettings' => $this->settings->snapshot(),
            'providerSettings' => $this->providerSettings->snapshot(),
            'canManage' => $actor->hasPermission('settings.manage'),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $actor = $this->actor($request);
        abort_unless($actor->hasPermission('settings.manage'), 403);

        $validated = $request->validate([
            'enabled' => ['required', 'boolean'],
        ]);

        $requestedEnabled = (bool) $validated['enabled'];

        if ($requestedEnabled && ! $this->settings->readOnly()) {
            throw ValidationException::withMessages([
                'enabled' => [
                    app()->getLocale() === 'ar'
                        ? 'لا يمكن تفعيل المساعد لأن سياج القراءة فقط غير مفعل على الخادم.'
                        : 'Assistant cannot be enabled while the server read-only safety fence is disabled.',
                ],
            ]);
        }

        $before = $this->settings->snapshot();
        $setting = $this->settings->persist($requestedEnabled);
        $after = $this->settings->snapshot();

        $this->audit->record(
            'assistant.settings.updated',
            $actor,
            $setting,
            $before,
            $after,
            $request,
        );

        return redirect()
            ->route('admin.assistant-settings.index')
            ->with(
                'status',
                app()->getLocale() === 'ar'
                    ? 'تم حفظ إعدادات مساعد FOODEX.'
                    : 'FOODEX Assistant settings saved.',
            );
    }

    public function updateProvider(Request $request): RedirectResponse
    {
        $actor = $this->actor($request);
        abort_unless($actor->hasPermission('settings.manage'), 403);

        $validated = $request->validate([
            'provider_enabled' => ['required', 'boolean'],
            'api_url' => ['nullable', 'required_if:provider_enabled,1', 'url', 'max:1000'],
            'model' => ['nullable', 'required_if:provider_enabled,1', 'string', 'max:160'],
            'api_key' => ['nullable', 'string', 'max:4096'],
            'clear_api_key' => ['nullable', 'boolean'],
            'timeout_seconds' => ['required', 'integer', 'min:3', 'max:120'],
        ]);

        $apiUrl = trim((string) ($validated['api_url'] ?? ''));
        if ($apiUrl !== '') {
            $this->externalAi->assertSafeEndpoint($apiUrl);
        }

        $before = $this->providerSettings->snapshot();
        $setting = $this->providerSettings->persist(
            config: [
                'enabled' => (bool) $validated['provider_enabled'],
                'api_url' => $apiUrl,
                'model' => trim((string) ($validated['model'] ?? '')),
                'timeout_seconds' => (int) $validated['timeout_seconds'],
            ],
            apiKey: isset($validated['api_key']) ? (string) $validated['api_key'] : null,
            clearApiKey: (bool) ($validated['clear_api_key'] ?? false),
        );
        $after = $this->providerSettings->snapshot();

        $this->audit->record(
            'assistant.provider.updated',
            $actor,
            $setting,
            $before,
            $after,
            $request,
        );

        return redirect()
            ->route('admin.assistant-settings.index')
            ->with(
                'status',
                app()->getLocale() === 'ar'
                    ? 'تم حفظ إعدادات مزود الذكاء الاصطناعي.'
                    : 'AI provider settings saved.',
            );
    }

    public function testProvider(Request $request): RedirectResponse
    {
        $actor = $this->actor($request);
        abort_unless($actor->hasPermission('settings.manage'), 403);

        $config = $this->providerSettings->snapshot();

        if ($config['api_url'] === '' || $config['model'] === '') {
            throw ValidationException::withMessages([
                'provider' => [
                    app()->getLocale() === 'ar'
                        ? 'احفظ رابط API والموديل أولاً ثم اختبر الاتصال.'
                        : 'Save the API URL and model before testing the connection.',
                ],
            ]);
        }

        $this->externalAi->assertSafeEndpoint($config['api_url']);

        $result = $this->externalAi->probe(
            [
                'api_url' => $config['api_url'],
                'model' => $config['model'],
                'timeout_seconds' => $config['timeout_seconds'],
            ],
            $this->providerSettings->apiKey(),
        );

        return redirect()
            ->route('admin.assistant-settings.index')
            ->with(
                $result['ok'] ? 'provider_test_success' : 'provider_test_error',
                app()->getLocale() === 'ar'
                    ? ($result['ok'] ? 'تم الاتصال بمزود الذكاء الاصطناعي بنجاح.' : 'فشل اختبار الاتصال. '.$result['message'])
                    : $result['message'],
            );
    }

    private function actor(Request $request): User
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);

        return $actor;
    }
}
