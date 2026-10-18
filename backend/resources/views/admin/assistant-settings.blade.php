<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>{{ __('assistant_settings.title') }} · {{ __('assistant_settings.brand') }}</title>
@include('admin._brand-components')
@include('admin._settings-page-polish')
<style>
body{margin:0;background:var(--foodex-background);color:var(--foodex-ink)}
.assistant-settings-stack{display:grid;gap:16px;max-width:980px}
.assistant-settings-card{background:#fff;border:1px solid var(--foodex-border);border-radius:18px;padding:22px;box-shadow:var(--foodex-shadow-sm)}
.assistant-settings-grid{display:grid;gap:16px}
.assistant-status{display:flex;align-items:center;justify-content:space-between;gap:16px;padding:14px;border-radius:14px;background:var(--foodex-green-soft)}
.assistant-status strong{font-size:1.05rem}
.assistant-note{padding:13px;border:1px solid var(--foodex-border);border-radius:12px;background:var(--foodex-background)}
.assistant-switch{display:flex;align-items:center;gap:10px;font-weight:800}
.assistant-switch input{width:22px;height:22px}
.assistant-actions{display:flex;gap:10px;align-items:center;flex-wrap:wrap}
.assistant-provider-head{display:flex;justify-content:space-between;align-items:flex-start;gap:16px}
.assistant-provider-head h2{margin:0;font-size:1.05rem}.assistant-provider-head p{margin:4px 0 0;color:var(--foodex-muted);font-size:.8rem}
.assistant-provider-fields{display:grid;grid-template-columns:minmax(0,1.6fr) minmax(180px,.7fr);gap:12px}
.assistant-provider-fields label{display:grid;gap:6px;font-weight:700;font-size:.8rem}
.assistant-provider-fields .wide{grid-column:1/-1}
.assistant-provider-fields input{width:100%}
.assistant-secret-state{font-size:.74rem;color:var(--foodex-muted)}
.assistant-provider-test{display:flex;align-items:center;gap:10px;flex-wrap:wrap}
.assistant-capabilities{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px}
.assistant-capability{padding:12px;border:1px solid var(--foodex-border);border-radius:12px;background:#fff}
.assistant-capability span{display:block;color:var(--foodex-muted);font-size:.72rem;margin-bottom:4px}
.assistant-capability strong{font-size:.9rem}
.foodex-state{margin-bottom:16px}
.assistant-ops-kpis{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px}
.assistant-tool-list{display:flex;flex-wrap:wrap;gap:8px;margin-top:12px}
.assistant-tool-list code{padding:6px 8px;border:1px solid var(--foodex-border);border-radius:8px;background:var(--foodex-background);font-size:.72rem}
.assistant-ops-table-wrap{max-width:100%;overflow:auto;border:1px solid var(--foodex-border);border-radius:12px;background:#fff}
.assistant-ops-table{width:100%;min-width:840px;border-collapse:collapse;font-size:.78rem}
.assistant-ops-table th,.assistant-ops-table td{padding:10px 12px;border-bottom:1px solid var(--foodex-border);text-align:start;vertical-align:top;white-space:nowrap}
.assistant-ops-table th{background:var(--foodex-background);font-weight:800}
.assistant-ops-table tbody tr:last-child td{border-bottom:0}
.assistant-trace-link{font-weight:800}
.assistant-trace-detail{display:grid;gap:12px;padding-top:6px}
@media(max-width:900px){.assistant-ops-kpis{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media(max-width:767px){.assistant-settings-card{padding:16px}.assistant-provider-fields{grid-template-columns:1fr}.assistant-provider-fields .wide{grid-column:auto}.assistant-provider-head{flex-direction:column}.assistant-capabilities{grid-template-columns:1fr 1fr}.assistant-ops-kpis{grid-template-columns:1fr 1fr}}
</style>
</head>
<body>
<div class="foodex-admin-layout" data-foodex-utility="assistant-settings">
<aside class="sidebar">@include('admin._sidebar',['navGroups'=>$navGroups,'navContext'=>$navContext,'user'=>$user])</aside>
<main class="foodex-admin-main foodex-admin-page foodex-settings-surface">
@include('admin._global-header')
@include('admin._admin-center-switcher',['active'=>'settings'])
<div class="foodex-page-toolbar">
<div>
<span class="foodex-subtitle">{{ __('assistant_settings.subtitle') }}</span>
<h1>{{ __('assistant_settings.title') }}</h1>
<p>{{ __('assistant_settings.description') }}</p>
</div>
</div>

@if(session('status'))<div class="foodex-state" role="status">{{ session('status') }}</div>@endif
@if(session('provider_test_success'))<div class="foodex-state" role="status">{{ session('provider_test_success') }}</div>@endif
@if(session('provider_test_error'))<div class="foodex-state" role="alert">{{ session('provider_test_error') }}</div>@endif
@if(session('provider_probe'))
@php($probe = session('provider_probe'))
<div class="assistant-settings-card" data-provider-capability-probe>
<div class="assistant-settings-grid">
<div class="assistant-provider-head">
<div>
<h2>{{ __('assistant_settings.capability_title') }}</h2>
<p>{{ __('assistant_settings.capability_description') }}</p>
</div>
<span class="foodex-badge">{{ $probe['agent_capable'] ? __('assistant_settings.agent_capable') : __('assistant_settings.agent_not_capable') }}</span>
</div>
<div class="assistant-capabilities">
<div class="assistant-capability"><span>{{ __('assistant_settings.reachable') }}</span><strong>{{ $probe['reachable'] ? __('assistant_settings.yes') : __('assistant_settings.no') }}</strong></div>
<div class="assistant-capability"><span>{{ __('assistant_settings.provider_health') }}</span><strong>{{ data_get($probe,'capabilities.health') ? __('assistant_settings.pass') : __('assistant_settings.fail') }}</strong></div>
<div class="assistant-capability"><span>{{ __('assistant_settings.chat_contract') }}</span><strong>{{ data_get($probe,'capabilities.chat') ? __('assistant_settings.pass') : __('assistant_settings.fail') }}</strong></div>
<div class="assistant-capability"><span>{{ __('assistant_settings.structured_json') }}</span><strong>{{ data_get($probe,'capabilities.structured_json') ? __('assistant_settings.pass') : __('assistant_settings.fail') }}</strong></div>
<div class="assistant-capability"><span>{{ __('assistant_settings.tool_calls') }}</span><strong>{{ data_get($probe,'capabilities.tool_calls') ? __('assistant_settings.pass') : __('assistant_settings.fail') }}</strong></div>
<div class="assistant-capability"><span>{{ __('assistant_settings.context_budget') }}</span><strong>{{ number_format((int) data_get($probe,'capabilities.context_window_tokens',0)) }}</strong></div>
<div class="assistant-capability"><span>{{ __('assistant_settings.output_budget') }}</span><strong>{{ number_format((int) data_get($probe,'capabilities.max_output_tokens',0)) }}</strong></div>
<div class="assistant-capability"><span>{{ __('assistant_settings.timeout') }}</span><strong>{{ (int) data_get($probe,'capabilities.timeout_seconds',0) }}s</strong></div>
<div class="assistant-capability"><span>{{ __('assistant_settings.latency') }}</span><strong>{{ (int) ($probe['latency_ms'] ?? 0) }}ms</strong></div>
<div class="assistant-capability"><span>{{ __('assistant_settings.budget_contract') }}</span><strong>{{ data_get($probe,'capabilities.budget_contract') ? __('assistant_settings.pass') : __('assistant_settings.fail') }}</strong></div>
</div>
</div>
</div>
@endif
@if($errors->any())
<div class="foodex-state" role="alert">
<strong>{{ __('assistant_settings.save_error') }}</strong>
<ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
</div>
@endif

<div class="assistant-settings-stack">
<section class="assistant-settings-card">
<div class="assistant-settings-grid">
<div class="assistant-status">
<div>
<div>{{ __('assistant_settings.current_status') }}</div>
<strong>{{ $assistantSettings['enabled'] ? __('assistant_settings.enabled') : __('assistant_settings.disabled') }}</strong>
</div>
<span class="foodex-badge">
@if($assistantSettings['source']==='dashboard')
{{ __('assistant_settings.saved_setting') }}
@elseif($assistantSettings['source']==='fail_closed')
{{ __('assistant_settings.fail_closed') }}
@else
{{ __('assistant_settings.environment_default') }}
@endif
</span>
</div>

<div class="assistant-note">
<strong>{{ __('assistant_settings.read_only_title') }}</strong>
<p>{{ __('assistant_settings.read_only_description') }}</p>
<p>{{ __('assistant_settings.read_only_status') }} <strong>{{ $assistantSettings['read_only'] ? __('assistant_settings.on') : __('assistant_settings.off_blocked') }}</strong></p>
</div>

@if($canManage)
<form method="post" action="{{ route('admin.assistant-settings.update') }}" class="assistant-settings-grid">
@csrf
@method('PUT')
<input type="hidden" name="enabled" value="0">
<label class="assistant-switch">
<input type="checkbox" name="enabled" value="1" @checked($assistantSettings['configured_enabled']) @disabled(!$assistantSettings['read_only'])>
<span>{{ __('assistant_settings.enable_global') }}</span>
</label>
<div class="assistant-actions">
<button type="submit" class="foodex-primary">{{ __('assistant_settings.save_apply') }}</button>
<span>{{ __('assistant_settings.applies_immediately') }}</span>
</div>
</form>
@else
<div class="assistant-note">{{ __('assistant_settings.read_only_access') }}</div>
@endif
</div>
</section>

<section class="assistant-settings-card" data-assistant-provider-settings>
<div class="assistant-settings-grid">
<div class="assistant-provider-head">
<div>
<h2>{{ __('assistant_settings.provider_title') }}</h2>
<p>{{ __('assistant_settings.provider_description') }}</p>
</div>
<span class="foodex-badge">{{ $providerSettings['enabled'] ? __('assistant_settings.provider_enabled') : __('assistant_settings.provider_disabled') }}</span>
</div>

<div class="assistant-note">
<strong>{{ __('assistant_settings.safety_title') }}</strong>
<p>{{ __('assistant_settings.safety_description') }}</p>
</div>

<div class="assistant-note" data-assistant-model-policy>
<strong>{{ __('assistant_settings.model_policy_title') }}</strong>
<p>{{ __('assistant_settings.model_policy_description') }}</p>
<p><strong>{{ __('assistant_settings.model') }}:</strong> {{ $providerSettings['model'] ?: __('assistant_settings.not_available') }}</p>
<p><strong>{{ __('assistant_settings.fallback_models') }}:</strong> {{ $providerSettings['fallback_models'] === [] ? __('assistant_settings.none') : implode(', ', $providerSettings['fallback_models']) }}</p>
<p><strong>{{ __('assistant_settings.context_budget') }}:</strong> {{ number_format($providerSettings['context_window_tokens']) }} · <strong>{{ __('assistant_settings.output_budget') }}:</strong> {{ number_format($providerSettings['max_tokens']) }}</p>
<p><strong>{{ __('assistant_settings.resilience_policy') }}:</strong> {{ __('assistant_settings.resilience_summary', ['retries'=>$providerSettings['retry_attempts'],'failures'=>$providerSettings['circuit_breaker_failures'],'seconds'=>$providerSettings['circuit_breaker_seconds']]) }}</p>
</div>

@if($canManage)
<form method="post" action="{{ route('admin.assistant-settings.provider.update') }}" class="assistant-settings-grid" autocomplete="off">
@csrf
@method('PUT')
<input type="hidden" name="provider_enabled" value="0">
<label class="assistant-switch">
<input type="checkbox" name="provider_enabled" value="1" @checked(old('provider_enabled',$providerSettings['enabled']))>
<span>{{ __('assistant_settings.use_provider') }}</span>
</label>

<div class="assistant-provider-fields">
<label class="wide">
<span>{{ __('assistant_settings.api_url') }}</span>
<input type="url" name="api_url" value="{{ old('api_url',$providerSettings['api_url']) }}" placeholder="{{ __('assistant_settings.api_url_placeholder') }}" inputmode="url">
<small class="assistant-secret-state">{{ __('assistant_settings.api_url_help') }}</small>
</label>
<label>
<span>{{ __('assistant_settings.model') }}</span>
<input type="text" name="model" value="{{ old('model',$providerSettings['model']) }}" placeholder="{{ __('assistant_settings.model_placeholder') }}" maxlength="160">
</label>
<label>
<span>{{ __('assistant_settings.timeout') }}</span>
<input type="number" name="timeout_seconds" value="{{ old('timeout_seconds',$providerSettings['timeout_seconds']) }}" min="3" max="120">
</label>
<label class="wide">
<span>{{ __('assistant_settings.api_key') }}</span>
<input type="password" name="api_key" value="" autocomplete="new-password" placeholder="{{ $providerSettings['api_key_configured'] ? __('assistant_settings.api_key_placeholder_saved') : __('assistant_settings.api_key_placeholder_optional') }}">
<small class="assistant-secret-state">
{{ $providerSettings['api_key_configured'] ? __('assistant_settings.api_key_saved_hint') : __('assistant_settings.api_key_new_hint') }}
</small>
</label>
@if($providerSettings['api_key_configured'])
<label class="assistant-switch wide">
<input type="checkbox" name="clear_api_key" value="1">
<span>{{ __('assistant_settings.remove_api_key') }}</span>
</label>
@endif
</div>

<div class="assistant-actions">
<button type="submit" class="foodex-primary">{{ __('assistant_settings.save_ai') }}</button>
<span>{{ __('assistant_settings.current_source') }} <strong>{{ $providerSettings['source']==='dashboard' ? __('assistant_settings.source_dashboard') : __('assistant_settings.source_environment') }}</strong></span>
</div>
</form>

<form method="post" action="{{ route('admin.assistant-settings.provider.test') }}" class="assistant-provider-test">
@csrf
<button type="submit" class="secondary">{{ __('assistant_settings.test_capabilities') }}</button>
<span>{{ __('assistant_settings.test_help') }}</span>
</form>
@else
<div class="assistant-note">
<p><strong>{{ __('assistant_settings.api_url') }}:</strong> {{ $providerSettings['api_url'] ?: __('assistant_settings.not_available') }}</p>
<p><strong>{{ __('assistant_settings.model') }}:</strong> {{ $providerSettings['model'] ?: __('assistant_settings.not_available') }}</p>
<p><strong>{{ __('assistant_settings.api_key') }}:</strong> {{ $providerSettings['api_key_configured'] ? __('assistant_settings.configured_encrypted') : __('assistant_settings.not_configured') }}</p>
</div>
@endif
</div>
</section>

<section class="assistant-settings-card" data-assistant-operations-center>
@php($lastProvider = $observabilitySummary['last_provider'] ?? null)
<div class="assistant-settings-grid">
<div class="assistant-provider-head">
<div>
<h2>{{ __('assistant_settings.operations.title') }}</h2>
<p>{{ __('assistant_settings.operations.description') }}</p>
</div>
<span class="foodex-badge">{{ __('assistant_settings.operations.window', ['hours' => $observabilitySummary['window_hours']]) }}</span>
</div>

<div class="assistant-note">
<strong>{{ __('assistant_settings.operations.privacy_title') }}</strong>
<p>{{ __('assistant_settings.operations.privacy_description', ['days' => $observabilitySummary['retention_days']]) }}</p>
</div>

@if(!$observabilitySummary['available'])
<div class="foodex-state" role="status">{{ __('assistant_settings.operations.storage_unavailable') }}</div>
@endif

<div class="assistant-ops-kpis">
<div class="assistant-capability"><span>{{ __('assistant_settings.operations.requests') }}</span><strong>{{ number_format($observabilitySummary['request_count']) }}</strong></div>
<div class="assistant-capability"><span>{{ __('assistant_settings.operations.provider_requests') }}</span><strong>{{ number_format($observabilitySummary['provider_requests']) }}</strong></div>
<div class="assistant-capability"><span>{{ __('assistant_settings.operations.provider_errors') }}</span><strong>{{ number_format($observabilitySummary['provider_errors']) }}</strong></div>
<div class="assistant-capability"><span>{{ __('assistant_settings.operations.provider_latency') }}</span><strong>{{ number_format($observabilitySummary['avg_provider_latency_ms']) }} ms</strong></div>
<div class="assistant-capability"><span>{{ __('assistant_settings.operations.tool_calls') }}</span><strong>{{ number_format($observabilitySummary['tool_calls']) }}</strong></div>
<div class="assistant-capability"><span>{{ __('assistant_settings.operations.tool_errors') }}</span><strong>{{ number_format($observabilitySummary['tool_errors']) }}</strong></div>
<div class="assistant-capability"><span>{{ __('assistant_settings.operations.fallback_rate') }}</span><strong>{{ number_format($observabilitySummary['fallback_rate_percent'], 1) }}%</strong></div>
<div class="assistant-capability"><span>{{ __('assistant_settings.operations.tokens') }}</span><strong>{{ number_format($observabilitySummary['total_tokens']) }}</strong></div>
</div>

<div class="assistant-note">
<strong>{{ __('assistant_settings.operations.execution_budgets') }}</strong>
<p>{{ __('assistant_settings.operations.execution_budgets_help', [
    'tools' => (int) config('assistant.agent.max_tool_calls', 4),
    'bytes' => number_format(((int) config('assistant.agent.max_result_bytes', 65536)) / 1024),
    'seconds' => (int) config('assistant.agent.max_seconds', 12),
]) }}</p>
</div>

<div class="assistant-note">
<strong>{{ __('assistant_settings.operations.last_provider') }}</strong>
@if($lastProvider)
<p>{{ $lastProvider['provider'] ?? '—' }} · {{ $lastProvider['model'] ?? '—' }} · {{ $lastProvider['outcome'] ?? '—' }} · {{ $lastProvider['latency_ms'] === null ? '—' : number_format($lastProvider['latency_ms']).' ms' }}</p>
<small class="assistant-secret-state">{{ $lastProvider['created_at'] ?? '' }}</small>
@else
<p>{{ __('assistant_settings.operations.no_provider_events') }}</p>
@endif
</div>

<div class="assistant-note">
<strong>{{ __('assistant_settings.operations.registered_tools') }}</strong>
<p>{{ __('assistant_settings.operations.registered_tools_help', ['count' => count($assistantToolKeys)]) }}</p>
<details>
<summary>{{ __('assistant_settings.operations.show_tools') }}</summary>
<div class="assistant-tool-list">
@foreach($assistantToolKeys as $toolKey)
<code>{{ $toolKey }}</code>
@endforeach
</div>
</details>
</div>

@if($canManage)
<div class="assistant-provider-head">
<div>
<h2>{{ __('assistant_settings.operations.recent_traces') }}</h2>
<p>{{ __('assistant_settings.operations.recent_traces_help') }}</p>
</div>
<a href="{{ route('admin.assistant-settings.diagnostics.export') }}" class="secondary">{{ __('assistant_settings.operations.export') }}</a>
</div>
<p class="assistant-secret-state">{{ __('assistant_settings.operations.export_help') }}</p>

@if($recentTraces === [])
<div class="foodex-state" role="status">{{ __('assistant_settings.operations.no_traces') }}</div>
@else
<div class="assistant-ops-table-wrap" role="region" aria-label="{{ __('assistant_settings.operations.recent_traces') }}" tabindex="0">
<table class="assistant-ops-table" data-pagination-exempt="bounded-latest-25-ai-traces">
<thead>
<tr>
<th>{{ __('assistant_settings.operations.trace') }}</th>
<th>{{ __('assistant_settings.operations.outcome') }}</th>
<th>{{ __('assistant_settings.operations.provider_model') }}</th>
<th>{{ __('assistant_settings.operations.tools') }}</th>
<th>{{ __('assistant_settings.operations.latency') }}</th>
<th>{{ __('assistant_settings.operations.tokens') }}</th>
<th>{{ __('assistant_settings.operations.fallback') }}</th>
<th>{{ __('assistant_settings.operations.time') }}</th>
</tr>
</thead>
<tbody>
@foreach($recentTraces as $trace)
<tr>
<td><a class="assistant-trace-link" href="{{ route('admin.assistant-settings.index', ['trace' => $trace['trace_id']]).'#assistant-trace-detail' }}"><code>{{ Str::limit($trace['trace_id'], 18) }}</code></a></td>
<td>{{ $trace['outcome'] ?? '—' }}</td>
<td>{{ $trace['provider'] ?? '—' }} / {{ $trace['model'] ?? '—' }}</td>
<td>{{ $trace['tools'] === [] ? '—' : implode(', ', array_slice($trace['tools'], 0, 3)) }}</td>
<td>{{ $trace['latency_ms'] === null ? '—' : number_format($trace['latency_ms']).' ms' }}</td>
<td>{{ number_format($trace['total_tokens']) }}</td>
<td>{{ $trace['fallback'] ? __('assistant_settings.operations.yes') : __('assistant_settings.operations.no') }}</td>
<td>{{ $trace['last_event_at'] }}</td>
</tr>
@endforeach
</tbody>
</table>
</div>
@endif

@if($selectedTraceId !== '')
<div id="assistant-trace-detail" class="assistant-trace-detail">
<div class="assistant-provider-head">
<div>
<h2>{{ __('assistant_settings.operations.trace_detail') }}</h2>
<p>{{ __('assistant_settings.operations.trace_detail_help') }}</p>
</div>
<code>{{ $selectedTraceId }}</code>
</div>
@if($selectedTrace === [])
<div class="foodex-state" role="status">{{ __('assistant_settings.operations.no_traces') }}</div>
@else
<div class="assistant-ops-table-wrap" role="region" aria-label="{{ __('assistant_settings.operations.trace_detail') }}" tabindex="0">
<table class="assistant-ops-table" data-pagination-exempt="bounded-selected-trace-100-events">
<thead>
<tr>
<th>{{ __('assistant_settings.operations.time') }}</th>
<th>{{ __('assistant_settings.operations.phase_event') }}</th>
<th>{{ __('assistant_settings.operations.provider_model') }}</th>
<th>{{ __('assistant_settings.operations.tool') }}</th>
<th>{{ __('assistant_settings.operations.outcome') }}</th>
<th>{{ __('assistant_settings.operations.status') }}</th>
<th>{{ __('assistant_settings.operations.latency') }}</th>
<th>{{ __('assistant_settings.operations.tokens') }}</th>
<th>{{ __('assistant_settings.operations.fallback') }}</th>
</tr>
</thead>
<tbody>
@foreach($selectedTrace as $event)
<tr>
<td>{{ $event['created_at'] }}</td>
<td>{{ $event['phase'] ?? '—' }} / {{ $event['event_name'] }}</td>
<td>{{ $event['provider'] ?? '—' }} / {{ $event['model'] ?? '—' }}</td>
<td>{{ $event['tool_key'] ?? '—' }}</td>
<td>{{ $event['outcome'] ?? '—' }}</td>
<td>{{ $event['status_code'] ?? '—' }}</td>
<td>{{ $event['latency_ms'] === null ? '—' : number_format($event['latency_ms']).' ms' }}</td>
<td>{{ $event['total_tokens'] ?? '—' }}</td>
<td>{{ $event['fallback'] ? __('assistant_settings.operations.yes') : __('assistant_settings.operations.no') }}</td>
</tr>
@endforeach
</tbody>
</table>
</div>
@endif
</div>
@endif
@else
<div class="assistant-note">{{ __('assistant_settings.operations.manage_required') }}</div>
@endif
</div>
</section>
</div>
</main>
</div>
</body>
</html>
