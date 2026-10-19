<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>{{ __('assistant_overview.title') }} · {{ __('assistant_overview.brand') }}</title>
@include('admin._brand-components')
@include('admin._settings-page-polish')
<style>
body{margin:0;background:var(--foodex-background);color:var(--foodex-ink)}
.ai-overview{display:grid;gap:16px}
.ai-overview-card{background:var(--foodex-surface);border:1px solid var(--foodex-border);border-radius:16px;padding:18px;box-shadow:var(--foodex-shadow-sm)}
.ai-overview-hero{display:flex;justify-content:space-between;align-items:flex-start;gap:16px}
.ai-overview-hero h2,.ai-overview-section-head h2,.ai-capability h3{margin:0}
.ai-overview-hero p,.ai-overview-section-head p,.ai-capability p{margin:5px 0 0;color:var(--foodex-muted);line-height:1.55}
.ai-state{display:inline-flex;align-items:center;min-height:30px;padding:4px 10px;border:1px solid var(--foodex-border);border-radius:999px;background:var(--foodex-background);font-size:.78rem;font-weight:800;white-space:nowrap}
.ai-state[data-state="active"],.ai-state[data-state="ready"]{border-color:var(--foodex-green);background:var(--foodex-green-soft);color:var(--foodex-green-dark)}
.ai-state[data-state="degraded"],.ai-state[data-state="unavailable"]{border-color:var(--foodex-red);color:var(--foodex-red)}
.ai-overview-kpis{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px}
.ai-kpi{display:grid;gap:8px;align-content:start;min-height:150px}
.ai-kpi-head{display:flex;justify-content:space-between;align-items:flex-start;gap:10px}
.ai-kpi h3{margin:0;font-size:.95rem}
.ai-kpi p{margin:0;color:var(--foodex-muted);font-size:.82rem;line-height:1.5}
.ai-kpi strong{font-size:1.5rem;line-height:1}
.ai-overview-section-head{display:flex;justify-content:space-between;gap:14px;align-items:flex-start;margin-bottom:14px}
.ai-health-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px}
.ai-health-item{padding:12px;border:1px solid var(--foodex-border);border-radius:12px;background:var(--foodex-background)}
.ai-health-item span{display:block;color:var(--foodex-muted);font-size:.74rem;margin-bottom:5px}
.ai-health-item strong{font-size:1rem}
.ai-capability-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}
.ai-capability{display:grid;grid-template-columns:minmax(0,1fr) auto;gap:12px;align-items:start;padding:15px;border:1px solid var(--foodex-border);border-radius:14px;background:var(--foodex-background)}
.ai-runtime-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px;margin:0}
.ai-runtime-grid div{padding:12px;border:1px solid var(--foodex-border);border-radius:12px;background:var(--foodex-background);min-width:0}
.ai-runtime-grid dt{font-size:.74rem;color:var(--foodex-muted);font-weight:700}
.ai-runtime-grid dd{margin:5px 0 0;font-weight:800;overflow-wrap:anywhere}
.ai-safety{display:grid;gap:6px;background:var(--foodex-green-soft)}
.ai-safety strong{font-size:.95rem}
.ai-safety p{margin:0;line-height:1.55}
@media(max-width:1100px){.ai-overview-kpis,.ai-health-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.ai-runtime-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media(max-width:767px){.ai-overview-card{padding:14px}.ai-overview-hero,.ai-overview-section-head{flex-direction:column;align-items:stretch}.ai-overview-kpis,.ai-health-grid,.ai-capability-grid,.ai-runtime-grid{grid-template-columns:1fr}.ai-kpi{min-height:auto}.ai-capability{grid-template-columns:1fr}.foodex-header-actions>*{flex:1}}
</style>
</head>
<body>
<div class="foodex-admin-layout" data-foodex-utility="assistant-overview">
<aside class="sidebar">@include('admin._sidebar',['navGroups'=>$navGroups,'navContext'=>$navContext,'user'=>$user])</aside>
<main class="foodex-admin-main foodex-admin-page foodex-settings-surface">
@include('admin._global-header')
@include('admin._admin-center-switcher',['active'=>'settings'])
<div class="foodex-page-toolbar">
<div>
<span class="foodex-subtitle">{{ __('assistant_overview.subtitle') }}</span>
<h1>{{ __('assistant_overview.title') }}</h1>
<p>{{ __('assistant_overview.description') }}</p>
</div>
<div class="foodex-header-actions">
<a class="secondary" href="{{ route('admin.assistant-settings.index') }}">{{ __('assistant_overview.settings') }}</a>
<a class="secondary" href="{{ route('admin.assistant-intelligence.index') }}">{{ __('assistant_overview.intelligence') }}</a>
</div>
</div>

<div class="ai-overview">
<section class="ai-overview-card ai-overview-hero" aria-labelledby="ai-overview-mode-title">
<div>
<h2 id="ai-overview-mode-title">{{ __('assistant_overview.mode') }}</h2>
<p>{{ __('assistant_overview.mode_help') }}</p>
</div>
<span class="ai-state" data-state="{{ $assistantState }}">{{ __('assistant_overview.modes.'.$operatingMode) }}</span>
</section>

<section class="ai-overview-kpis" aria-label="{{ __('assistant_overview.title') }}">
<article class="ai-overview-card ai-kpi">
<div class="ai-kpi-head">
<h3>{{ __('assistant_overview.runtime') }}</h3>
<span class="ai-state" data-state="{{ $assistantState }}">{{ __('assistant_overview.states.'.$assistantState) }}</span>
</div>
<p>{{ __('assistant_overview.runtime_help') }}</p>
<strong>{{ $assistantSettings['enabled'] ? __('assistant_overview.yes') : __('assistant_overview.no') }}</strong>
</article>
<article class="ai-overview-card ai-kpi">
<div class="ai-kpi-head">
<h3>{{ __('assistant_overview.provider') }}</h3>
<span class="ai-state" data-state="{{ $providerState }}">{{ __('assistant_overview.states.'.$providerState) }}</span>
</div>
<p>{{ __('assistant_overview.provider_help') }}</p>
<strong>{{ $providerSettings['model'] !== '' ? $providerSettings['model'] : __('assistant_overview.not_configured') }}</strong>
</article>
<article class="ai-overview-card ai-kpi">
<div class="ai-kpi-head">
<h3>{{ __('assistant_overview.tools') }}</h3>
<span class="ai-state" data-state="{{ $toolsState }}">{{ __('assistant_overview.states.'.$toolsState) }}</span>
</div>
<p>{{ __('assistant_overview.tools_help',['count'=>$assistantToolCount]) }}</p>
<strong>{{ number_format($assistantToolCount) }}</strong>
</article>
<article class="ai-overview-card ai-kpi">
<div class="ai-kpi-head">
<h3>{{ __('assistant_overview.observability') }}</h3>
<span class="ai-state" data-state="{{ $observabilityState }}">{{ __('assistant_overview.states.'.$observabilityState) }}</span>
</div>
<p>{{ __('assistant_overview.observability_help') }}</p>
<strong>{{ (int) ($observabilitySummary['window_hours'] ?? 0) }}h</strong>
</article>
</section>

<section class="ai-overview-card" aria-labelledby="ai-health-title">
<div class="ai-overview-section-head">
<div>
<h2 id="ai-health-title">{{ __('assistant_overview.health_title') }}</h2>
<p>{{ __('assistant_overview.health_description') }}</p>
</div>
</div>
<div class="ai-health-grid">
<div class="ai-health-item"><span>{{ __('assistant_overview.requests') }}</span><strong>{{ number_format((int) ($observabilitySummary['request_count'] ?? 0)) }}</strong></div>
<div class="ai-health-item"><span>{{ __('assistant_overview.provider_requests') }}</span><strong>{{ number_format((int) ($observabilitySummary['provider_requests'] ?? 0)) }}</strong></div>
<div class="ai-health-item"><span>{{ __('assistant_overview.provider_latency') }}</span><strong>{{ number_format((int) ($observabilitySummary['avg_provider_latency_ms'] ?? 0)) }} ms</strong></div>
<div class="ai-health-item"><span>{{ __('assistant_overview.fallback_rate') }}</span><strong>{{ number_format((float) ($observabilitySummary['fallback_rate_percent'] ?? 0),1) }}%</strong></div>
</div>
</section>

<section class="ai-overview-card" aria-labelledby="ai-capabilities-title">
<div class="ai-overview-section-head">
<div>
<h2 id="ai-capabilities-title">{{ __('assistant_overview.capabilities_title') }}</h2>
<p>{{ __('assistant_overview.capabilities_description') }}</p>
</div>
<span class="ai-state" data-state="{{ $actionsState }}">{{ __('assistant_overview.actions') }} · {{ __('assistant_overview.states.'.$actionsState) }}</span>
</div>
<div class="ai-capability-grid">
@foreach($capabilities as $capability)
<article class="ai-capability">
<div>
<h3>{{ __('assistant_overview.capabilities.'.$capability['key'].'.title') }}</h3>
<p>{{ __('assistant_overview.capabilities.'.$capability['key'].'.description') }}</p>
</div>
<span class="ai-state" data-state="{{ $capability['state'] }}">{{ __('assistant_overview.states.'.$capability['state']) }}</span>
</article>
@endforeach
</div>
</section>

<section class="ai-overview-card" aria-labelledby="ai-runtime-title">
<div class="ai-overview-section-head">
<div>
<h2 id="ai-runtime-title">{{ __('assistant_overview.runtime_title') }}</h2>
<p>{{ __('assistant_overview.runtime_description') }}</p>
</div>
</div>
<dl class="ai-runtime-grid">
<div><dt>{{ __('assistant_overview.model') }}</dt><dd>{{ $providerSettings['model'] !== '' ? $providerSettings['model'] : __('assistant_overview.not_configured') }}</dd></div>
<div><dt>{{ __('assistant_overview.fallback_models') }}</dt><dd>{{ $providerSettings['fallback_models'] === [] ? __('assistant_overview.not_configured') : implode(', ',$providerSettings['fallback_models']) }}</dd></div>
<div><dt>{{ __('assistant_overview.source') }}</dt><dd>{{ __('assistant_overview.source_'.$assistantSettings['source']) }}</dd></div>
<div><dt>{{ __('assistant_overview.read_only') }}</dt><dd>{{ $assistantSettings['read_only'] ? __('assistant_overview.yes') : __('assistant_overview.no') }}</dd></div>
<div><dt>{{ __('assistant_overview.write_fence') }}</dt><dd><span class="ai-state" data-state="{{ $actionsState }}">{{ __('assistant_overview.states.'.$actionsState) }}</span></dd></div>
<div><dt>{{ __('assistant_overview.agent_budget') }}</dt><dd>{{ __('assistant_overview.agent_budget_value',['tools'=>(int) config('assistant.agent.max_tool_calls',4),'seconds'=>(int) config('assistant.agent.max_seconds',12)]) }}</dd></div>
<div><dt>{{ __('assistant_overview.trace_retention') }}</dt><dd>{{ __('assistant_overview.trace_retention_value',['days'=>(int) ($observabilitySummary['retention_days'] ?? 0)]) }}</dd></div>
</dl>
</section>

<section class="ai-overview-card ai-safety" aria-labelledby="ai-safety-title">
<strong id="ai-safety-title">{{ __('assistant_overview.safety_title') }}</strong>
<p>{{ __('assistant_overview.safety_description') }}</p>
<p>{{ __('assistant_overview.provider_privacy') }}</p>
</section>
</div>
</main>
</div>
</body>
</html>
