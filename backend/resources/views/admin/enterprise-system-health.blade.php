<!doctype html>
<html lang="{{ str_replace('_','-',app()->getLocale()) }}" dir="{{ app()->getLocale()==='ar' ? 'rtl' : 'ltr' }}">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>{{ __('system_admin.title') }} · FOODEX</title>
@include('admin._brand-components')
<style>
body{margin:0;background:var(--foodex-background);color:var(--foodex-ink)}
.system-admin-page{padding:clamp(14px,2.3vw,32px);min-width:0}
.system-admin-head{display:flex;justify-content:space-between;gap:16px;align-items:flex-start;margin-block:16px 22px}
.system-admin-head h1{margin:4px 0 8px;font-size:clamp(1.5rem,2.4vw,2.15rem)}
.system-admin-head p{margin:0;max-width:760px;color:var(--foodex-muted);line-height:1.55}
.system-admin-actions{display:flex;gap:8px;flex-wrap:wrap}
.system-admin-section{display:grid;gap:12px;min-width:0;margin-block:18px}
.system-admin-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}
.system-admin-card{padding:16px}
.system-admin-card h3{margin:0 0 8px}
.system-admin-card p{margin:0;color:var(--foodex-muted)}
.system-admin-module-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}
.system-admin-module{padding:16px}
.system-admin-module__head{display:flex;justify-content:space-between;align-items:flex-start;gap:10px}
.system-admin-module__head h3{margin:0}
.system-admin-module-meta{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:8px;margin-top:12px}
.system-admin-module-meta>div{padding:9px;border:1px solid var(--foodex-border);border-radius:9px;background:var(--foodex-background)}
.system-admin-module-meta span{display:block;color:var(--foodex-muted);font-size:.75rem;margin-bottom:3px}
.system-admin-deps{display:flex;gap:6px;flex-wrap:wrap;margin-top:10px}
.system-admin-dep{padding:5px 8px;border:1px solid var(--foodex-border);border-radius:999px;font-size:.78rem}
.system-admin-note{color:var(--foodex-muted);font-size:.84rem;margin:0}
@media(max-width:1023px){.system-admin-grid,.system-admin-module-grid{grid-template-columns:1fr}}
@media(max-width:767px){.system-admin-head{flex-direction:column}.system-admin-actions{width:100%;display:grid;grid-template-columns:1fr}.system-admin-actions>a{width:100%;justify-content:center}.system-admin-module-meta{grid-template-columns:1fr}}
</style>
</head>
<body>
<div class="foodex-admin-layout" data-foodex-mobile-web-page="enterprise-system-health">
<aside class="sidebar">@include('admin._sidebar',['navGroups'=>$navGroups,'navContext'=>$navContext,'user'=>$user])</aside> {{-- localization-gate: allow Blade include expression --}}
<main class="foodex-admin-main foodex-admin-page system-admin-page foodex-module-overview" data-enterprise-system-health="true" data-foodex-module-overview data-state="current">
@include('admin._global-header')
@include('admin._admin-center-switcher',['active'=>'administration'])

<div class="system-admin-head foodex-page-toolbar">
    <div>
        <div class="foodex-subtitle">FOODEX · {{ __('system_admin.eyebrow') }}</div>
        <h1>{{ __('system_admin.title') }}</h1>
        <p>{{ __('system_admin.description') }}</p>
    </div>
    <div class="system-admin-actions foodex-header-actions">
        <a class="foodex-action-primary" href="{{ route('admin.inspector.index') }}">{{ __('system_admin.actions.inspector') }}</a>
        <a class="foodex-action-secondary" href="{{ route('admin.system-update.index') }}">{{ __('system_admin.actions.update') }}</a>
        <a class="foodex-action-secondary" href="{{ route('admin.production-dataset-switch.index') }}">{{ __('system_admin.actions.restore') }}</a>
        <a class="foodex-action-secondary" href="{{ route('admin.administration.index') }}">{{ __('system_admin.actions.admin') }}</a>
    </div>
</div>

@php($moduleDashboardFilters = [
    'action' => route('admin.enterprise-system-health.index'),
    'from_value' => $selectedFrom,
    'to_value' => $selectedTo,
    'reset_url' => route('admin.enterprise-system-health.index'),
    'export_url' => route('admin.inspector.export'),
])
@include('admin._module-dashboard-filters',['moduleDashboardFilters'=>$moduleDashboardFilters])

<section class="system-admin-section" aria-labelledby="runtime-overview">
<h2 id="runtime-overview">{{ __('system_admin.sections.overview') }}</h2>
<div class="foodex-module-kpis">
@foreach($kpis as $metric)
    @include('admin._module-dashboard-kpi',['metric'=>$metric])
@endforeach
</div>
</section>

<section class="system-admin-section">
<h2>{{ __('system_admin.sections.incidents') }}</h2>
<div class="system-admin-grid">
    <article class="foodex-viz-card foodex-module-chart system-admin-card" data-foodex-module-chart>
        <h3>{{ __('system_admin.sections.incidents') }}</h3>
        <p>{{ $selectedFrom }} → {{ $selectedTo }}</p>
        <strong>{{ collect($incidentTrend)->sum('total') }}</strong>
    </article>
    <article class="foodex-viz-card foodex-module-chart system-admin-card" data-foodex-module-chart>
        <h3>{{ __('system_admin.sections.failed_jobs') }}</h3>
        <p>{{ __('system_admin.labels.queue_driver') }}: {{ $queueDriver ?: '—' }}</p> {{-- localization-gate: allow — queue driver is a technical runtime identifier. --}}
        <strong>{{ $failedJobs['count'] ?? 0 }}</strong>
    </article>
</div>
</section>

<section class="system-admin-section" id="configuration-warnings">
<h2>{{ __('system_admin.sections.warnings') }}</h2>
@if($warnings===[])
    @include('admin._module-dashboard-state',['state'=>'current','message'=>__('system_admin.messages.no_warnings')])
@else
    <div class="system-admin-grid">
    @foreach($warnings as $warning)
        <article class="foodex-card system-admin-card" role="status"><p>{{ $warning }}</p></article>
    @endforeach
    </div>
@endif
</section>

<section class="system-admin-section" id="backup-history">
<h2>{{ __('system_admin.sections.backups') }}</h2>
<p class="system-admin-note">{{ __('system_admin.messages.update_restore') }}</p>
@if($backupRows===[])
    @include('admin._module-dashboard-state',['state'=>'empty','message'=>__('system_admin.messages.no_backups')])
@else
    <div class="foodex-module-kpis">
        @include('admin._module-dashboard-kpi',['metric'=>[
            'label'=>__('system_admin.sections.backups'),
            'value'=>count($backupRows),
            'status'=>['state'=>'success','label'=>__('system_admin.states.available')],
            'last_refresh'=>now()->toIso8601String(),
            'drilldown_url'=>route('admin.system-update.index'),
        ]])
    </div>
@endif
</section>

<section class="system-admin-section" id="update-history">
<h2>{{ __('system_admin.sections.updates') }}</h2>
@if($updateRows->isEmpty())
    @include('admin._module-dashboard-state',['state'=>'empty','message'=>__('system_admin.messages.no_updates')])
@else
    <div class="foodex-module-kpis">
        @include('admin._module-dashboard-kpi',['metric'=>[
            'label'=>__('system_admin.sections.updates'),
            'value'=>$updateRows->count(),
            'status'=>['state'=>'current','label'=>__('system_admin.states.current')],
            'last_refresh'=>now()->toIso8601String(),
            'drilldown_url'=>route('admin.system-update.index'),
        ]])
    </div>
@endif
</section>

<section class="system-admin-section" id="failed-jobs">
<h2>{{ __('system_admin.sections.failed_jobs') }}</h2>
<p class="system-admin-note">{{ __('system_admin.messages.failed_job_retry_policy') }}</p>
@if(!($failedJobs['available'] ?? false))
    @include('admin._module-dashboard-state',['state'=>'disabled','message'=>__('system_admin.messages.failed_jobs_unavailable')])
@elseif(($failedJobs['rows'] ?? [])===[])
    @include('admin._module-dashboard-state',['state'=>'current','message'=>__('system_admin.messages.no_failed_jobs')])
@else
    <div class="foodex-module-kpis">
        @include('admin._module-dashboard-kpi',['metric'=>[
            'label'=>__('system_admin.sections.failed_jobs'),
            'value'=>$failedJobs['count'] ?? 0,
            'status'=>['state'=>'danger','label'=>__('system_admin.evidence.failed')],
            'last_refresh'=>now()->toIso8601String(),
            'drilldown_url'=>route('admin.inspector.index'),
        ]])
    </div>
@endif
</section>

<section class="system-admin-section">
<h2>{{ __('enterprise_health.system_signals') }}</h2>
<div class="system-admin-grid">
    <article class="foodex-card system-admin-card">
        <h3>{{ __('enterprise_health.outbox') }}</h3>
        @if(($outboxCounts['pending'] ?? 0) > 0)
            <p>{{ __('system_admin.evidence.pending') }} · {{ $outboxCounts['pending'] }}</p>
        @else
            <p>{{ __('enterprise_health.no_activity') }}</p>
        @endif
    </article>
    <article class="foodex-card system-admin-card">
        <h3>{{ __('enterprise_health.workflow') }}</h3>
        @if(($workflowCounts['PENDING'] ?? 0) > 0 || ($workflowCounts['pending'] ?? 0) > 0)
            <p>{{ __('system_admin.evidence.pending') }} · {{ ($workflowCounts['PENDING'] ?? 0) + ($workflowCounts['pending'] ?? 0) }}</p>
        @else
            <p>{{ __('enterprise_health.no_activity') }}</p>
        @endif
    </article>
</div>
</section>

<section class="system-admin-section" id="module-health">
<h2>{{ __('system_admin.sections.modules') }}</h2>
@if($modules->isEmpty())
    @include('admin._module-dashboard-state',['state'=>'empty'])
@else
<div class="system-admin-module-grid">
@foreach($modules as $module)
<article class="foodex-card system-admin-module">
    <div class="system-admin-module__head">
        <div><h3>{{ $module['name'] }}</h3><div class="foodex-subtitle">{{ $module['category'] }}</div></div>
        <span class="foodex-status-badge" data-state="{{ $module['health']==='HEALTHY'?'success':($module['health']==='DEGRADED'?'warning':'danger') }}">{{ __('enterprise_health.health_states.'.$module['health']) }}</span>
    </div>
    <div class="system-admin-module-meta">
        <div><span>{{ __('enterprise_health.lifecycle') }}</span><strong>{{ __('enterprise_health.states.'.$module['lifecycle']) }}</strong></div>
        <div><span>{{ __('system_admin.labels.checks') }}</span><strong>{{ $module['check_count'] }}</strong></div>
        <div><span>{{ __('system_admin.labels.stale_checks') }}</span><strong>{{ $module['stale_count'] }}</strong></div>
    </div>
    <div class="system-admin-deps">
    @if($module['dependencies']===[])
        <span class="system-admin-dep">{{ __('enterprise_health.dependencies') }} · {{ __('enterprise_health.none') }}</span>
    @else
        @foreach($module['dependencies'] as $dependency)
            <span class="system-admin-dep">{{ $dependency['name'] }} · {{ __('enterprise_health.states.'.$dependency['state']) }}</span>
        @endforeach
    @endif
    </div>
</article>
@endforeach
</div>
@endif
</section>

</main>
</div>
</body>
</html>
