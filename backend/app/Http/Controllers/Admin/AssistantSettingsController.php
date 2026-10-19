<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Analytics\Signals\SignalDetectionEngine;
use App\Domain\Assistant\Agent\AssistantAgentOrchestrator;
use App\Domain\Assistant\Intelligence\AssistantIntelligenceAuthorization;
use App\Domain\Assistant\Intelligence\AssistantIntelligenceService;
use App\Domain\Assistant\Security\AssistantAiSecurityGuardrails;
use App\Domain\Assistant\Tools\AssistantToolRegistry;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AssistantExternalAiClient;
use App\Services\AssistantObservability;
use App\Services\AssistantProviderSettings;
use App\Services\AssistantRuntimeSettings;
use App\Services\AuditLogger;
use App\Support\AdminNavigation;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class AssistantSettingsController extends Controller
{
    public function __construct(
        private readonly AssistantRuntimeSettings $settings,
        private readonly AssistantProviderSettings $providerSettings,
        private readonly AssistantExternalAiClient $externalAi,
        private readonly AssistantObservability $observability,
        private readonly AssistantToolRegistry $tools,
        private readonly AssistantIntelligenceAuthorization $intelligenceAuthorization,
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

        $canManage = $actor->hasPermission('settings.manage');
        $traceId = $canManage ? trim((string) $request->query('trace', '')) : '';

        return view('admin.assistant-settings', [
            'user' => $actor,
            'navGroups' => $this->navigation->groupsFor($actor),
            'navContext' => 'administration_hub',
            'assistantSettings' => $this->settings->snapshot(),
            'providerSettings' => $this->providerSettings->snapshot(),
            'canManage' => $canManage,
            'observabilitySummary' => $this->observability->summary(),
            'recentTraces' => $canManage ? $this->observability->recentTraces() : [],
            'selectedTraceId' => $traceId,
            'selectedTrace' => $traceId === '' ? [] : $this->observability->trace($traceId),
            'assistantToolKeys' => $this->tools->keys(),
        ]);
    }

    public function overview(Request $request): View
    {
        $actor = $this->actor($request);
        abort_unless(
            $actor->hasPermission('settings.view') || $actor->hasPermission('settings.manage'),
            403,
        );

        $assistantSettings = $this->settings->snapshot();
        $providerSettings = $this->providerSettings->snapshot();
        $assistantToolKeys = $this->tools->keys();
        $observabilitySummary = $this->observability->summary();

        $providerConfigured = $providerSettings['api_url'] !== '' && $providerSettings['model'] !== '';
        $providerGateEnabled = (bool) config('assistant.security.provider_enabled', true);
        $toolsGateEnabled = (bool) config('assistant.security.tools_enabled', true);
        $writeActionsEnabled = (bool) config('assistant.security.write_actions_enabled', false);
        $assistantEnabled = (bool) $assistantSettings['enabled'];
        $providerActive = $assistantEnabled
            && $providerGateEnabled
            && (bool) $providerSettings['enabled']
            && $providerConfigured;
        $toolsActive = $assistantEnabled && $toolsGateEnabled && $assistantToolKeys !== [];

        $capabilities = [
            [
                'key' => 'agent',
                'state' => class_exists(AssistantAgentOrchestrator::class)
                    ? ($toolsActive ? 'active' : 'ready')
                    : 'unavailable',
            ],
            [
                'key' => 'analytics',
                'state' => in_array('analytics.diagnose', $assistantToolKeys, true) ? 'ready' : 'unavailable',
            ],
            [
                'key' => 'knowledge',
                'state' => in_array('knowledge.search', $assistantToolKeys, true) ? 'ready' : 'unavailable',
            ],
            [
                'key' => 'wholesale',
                'state' => in_array('executive.wholesale', $assistantToolKeys, true) ? 'ready' : 'unavailable',
            ],
            [
                'key' => 'retailer360',
                'state' => in_array('retailers.360', $assistantToolKeys, true) ? 'ready' : 'unavailable',
            ],
            [
                'key' => 'forecasting',
                'state' => in_array('forecast.replenishment', $assistantToolKeys, true) ? 'ready' : 'unavailable',
            ],
            [
                'key' => 'signals',
                'state' => class_exists(SignalDetectionEngine::class) ? 'ready' : 'unavailable',
            ],
            [
                'key' => 'proactive',
                'state' => class_exists(AssistantIntelligenceService::class) ? 'ready' : 'unavailable',
            ],
            [
                'key' => 'security',
                'state' => class_exists(AssistantAiSecurityGuardrails::class) ? 'active' : 'unavailable',
            ],
            [
                'key' => 'safe_actions',
                'state' => in_array('procurement.purchase_request.create_draft', $assistantToolKeys, true)
                    ? ($writeActionsEnabled ? 'active' : 'guarded')
                    : 'unavailable',
            ],
        ];

        $providerState = match (true) {
            $providerActive => 'active',
            $providerConfigured => 'configured',
            default => 'off',
        };

        return view('admin.assistant-overview', [
            'user' => $actor,
            'navGroups' => $this->navigation->groupsFor($actor),
            'navContext' => 'administration_hub',
            'assistantSettings' => $assistantSettings,
            'providerSettings' => $providerSettings,
            'assistantToolCount' => count($assistantToolKeys),
            'observabilitySummary' => $observabilitySummary,
            'capabilities' => $capabilities,
            'assistantState' => $assistantEnabled ? 'active' : 'off',
            'providerState' => $providerState,
            'toolsState' => $toolsActive ? 'active' : ($toolsGateEnabled && $assistantToolKeys !== [] ? 'ready' : 'off'),
            'observabilityState' => ($observabilitySummary['available'] ?? false) ? 'active' : 'degraded',
            'actionsState' => $writeActionsEnabled ? 'active' : 'guarded',
            'operatingMode' => ! $assistantEnabled
                ? 'disabled'
                : ($providerActive ? 'agentic' : 'deterministic'),
            'canOpenIntelligence' => $this->intelligenceAuthorization->canUse($actor),
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
                    __('assistant_settings.errors.read_only_blocked'),
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
                __('assistant_settings.assistant_saved'),
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
                __('assistant_settings.provider_saved'),
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
                    __('assistant_settings.errors.save_before_test'),
                ],
            ]);
        }

        $this->externalAi->assertSafeEndpoint($config['api_url']);

        $result = $this->externalAi->probe(
            [
                'api_url' => $config['api_url'],
                'model' => $config['model'],
                'timeout_seconds' => $config['timeout_seconds'],
                'context_window_tokens' => $config['context_window_tokens'],
                'max_tokens' => $config['max_tokens'],
            ],
            $this->providerSettings->apiKey(),
        );

        return redirect()
            ->route('admin.assistant-settings.index')
            ->with(
                $result['ok'] ? 'provider_test_success' : 'provider_test_error',
                $result['message'],
            )
            ->with('provider_probe', $result);
    }

    public function exportDiagnostics(Request $request): StreamedResponse
    {
        $actor = $this->actor($request);
        abort_unless($actor->hasPermission('settings.manage'), 403);

        $rows = $this->observability->exportRows();
        $filename = 'foodex-ai-diagnostics-'.now()->format('Ymd-His').'.csv';

        return response()->streamDownload(function () use ($rows): void {
            $handle = fopen('php://output', 'wb');
            if ($handle === false) {
                return;
            }

            fputcsv($handle, [
                'trace_id',
                'event_time',
                'phase',
                'event',
                'provider',
                'model',
                'tool',
                'outcome',
                'status_code',
                'latency_ms',
                'fallback',
                'prompt_tokens',
                'completion_tokens',
                'total_tokens',
            ]);

            foreach ($rows as $row) {
                fputcsv($handle, [
                    $row['trace_id'],
                    $row['created_at'],
                    $row['phase'],
                    $row['event_name'],
                    $row['provider'],
                    $row['model'],
                    $row['tool_key'],
                    $row['outcome'],
                    $row['status_code'],
                    $row['latency_ms'],
                    $row['fallback'] ? '1' : '0',
                    $row['prompt_tokens'],
                    $row['completion_tokens'],
                    $row['total_tokens'],
                ]);
            }

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'no-store, private',
        ]);
    }

    private function actor(Request $request): User
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);

        return $actor;
    }
}
