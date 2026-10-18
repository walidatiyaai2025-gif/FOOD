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
.system-admin-section{display:grid;gap:12px;min-width:0}
.system-admin-section>h2{margin:10px 0 0}
.system-admin-chart-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}
.system-admin-chart{padding:16px;min-width:0}
.system-admin-chart h3{margin:0 0 4px}
.system-admin-chart p{margin:0 0 12px;color:var(--foodex-muted)}
.system-admin-table-wrap{max-width:100%;overflow:auto;border:1px solid var(--foodex-border);border-radius:var(--foodex-radius-card);background:#fff}
.system-admin-table{width:100%;border-collapse:collapse;min-width:760px}
.system-admin-table th,.system-admin-table td{padding:10px 12px;text-align:start;border-bottom:1px solid var(--foodex-border);vertical-align:top}
.system-admin-table th{font-size:.78rem;color:var(--foodex-muted);background:var(--foodex-background)}
.system-admin-table tr:last-child td{border-bottom:0}
.system-admin-warning-list{display:grid;gap:8px;margin:0;padding:0;list-style:none}
.system-admin-warning{padding:12px;border:1px solid var(--foodex-border);border-radius:10px;background:#fff}
.system-admin-module-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}
.system-admin-module{padding:16px}
.system-admin-module__head{display:flex;justify-content:space-between;align-items:flex-start;gap:10px}
.system-admin-module__head h3{margin:0}
.system-admin-module-meta{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:8px;margin-top:12px}
.system-admin-module-meta>div{padding:9px;border:1px solid var(--foodex-border);border-radius:9px;background:var(--foodex-background)}
.system-admin-module-meta span{display:block;color:var(--foodex-muted);font-size:.75rem;margin-bottom:3px}
.system-admin-deps{display:flex;gap:6px;flex-wrap:wrap;margin-top:10px}
.system-admin-dep{padding:5px 8px;border:1px solid var(--foodex-border);border-radius:999px;font-size:.78rem}
.system-admin-bar-labels{display:grid;grid-template-columns:repeat(var(--bar-count,1),minmax(18px,1fr));gap:8px;margin-top:6px;font-size:.7rem;color:var(--foodex-muted);text-align:center}
.system-admin-note{color:var(--foodex-muted);font-size:.84rem;margin:0}
.system-admin-signals{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px}
.system-admin-signal{padding:14px}
.system-admin-signal>span{display:block;color:var(--foodex-muted);font-size:.78rem;margin-bottom:6px}
.system-admin-pills{display:flex;gap:6px;flex-wrap:wrap}
.system-admin-pill{display:inline-flex;padding:5px 8px;border:1px solid var(--foodex-border);border-radius:999px;background:#fff}
@media(max-width:1023px){.system-admin-chart-grid,.system-admin-module-grid{grid-template-columns:1fr}.system-admin-signals{grid-template-columns:1fr}}
@media(max-width:767px){.system-admin-head{flex-direction:column}.system-admin-actions{width:100%;display:grid;grid-template-columns:1fr}.system-admin-actions>a{width:100%;justify-content:center}.system-admin-module-meta{grid-template-columns:1fr}.system-admin-table{min-width:680px}}
</style>
</head>
<body>
<div class="foodex-admin-layout" data-foodex-mobile-web-page="enterprise-system-health">
<aside class="sidebar">@include('admin._sidebar',['navGroups'=>$navGroups,'navContext'=>$navContext,'user'=>$user])</aside>
<main class="foodex-admin-main foodex-admin-page system-admin-page foodex-module-overview" data-enterprise-system-health="true" data-foodex-module-overview data-state="current">
@include('admin._global-header')
@include('admin._admin-center-switcher',['active'=>'administration'])

<header class="system-admin-head foodex-page-header">
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
</header>

@php
$moduleDashboardFilters=[
    'action'=>route('admin.enterprise-system-health.index'),
    'from_value'=>$selectedFrom,
    'to_value'=>$selectedTo,
    'reset_url'=>route('admin.enterprise-system-health.index'),
    'export_url'=>route('admin.inspector.export'),
];
@endphp
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
<div class="system-admin-chart-grid">
    <article class="foodex-viz-card foodex-module-chart system-admin-chart" data-foodex-module-chart>
        <h3>{{ __('system_admin.sections.incidents') }}</h3>
        <p>{{ $selectedFrom }} → {{ $selectedTo }}</p>
        @php
            $incidentMax=max(1,collect($incidentTrend)->max('total')??1);
            $incidentCount=max(1,count($incidentTrend));
        @endphp
        @if($incidentTrend===[])
            @include('admin._module-dashboard-state',['state'=>'empty','message'=>__('system_admin.messages.no_incidents')])
        @else
            <div class="foodex-viz-bars" role="img" aria-label="{{ __('system_admin.sections.incidents') }}">
            @foreach($incidentTrend as $point)
                <span class="foodex-viz-bar" data-series="{{ $point['errors']>0?4:1 }}" style="height:{{ max(8,(int)round(($point['total']/$incidentMax)*100)) }}%" title="{{ $point['date'] }} · {{ $point['total'] }}"></span>
            @endforeach
            </div>
            <div class="system-admin-bar-labels" style="--bar-count:{{ $incidentCount }}">@foreach($incidentTrend as $point)<span>{{ substr($point['date'],5) }}</span>@endforeach</div>
            <div class="foodex-module-chart-summary">{{ __('system_admin.labels.count') }}: {{ collect($incidentTrend)->sum('total') }}</div>
            <div class="foodex-module-chart-table-wrap">
            <table class="foodex-module-chart-table"><thead><tr><th>{{ __('system_admin.labels.date') }}</th><th>{{ __('system_admin.labels.count') }}</th><th>{{ __('system_admin.states.error') }}</th></tr></thead>
            <tbody>@foreach($incidentTrend as $point)<tr><td>{{ $point['date'] }}</td><td>{{ $point['total'] }}</td><td>{{ $point['errors'] }}</td></tr>@endforeach</tbody></table>
            </div>
        @endif
    </article>

    <article class="foodex-viz-card foodex-module-chart system-admin-chart" data-foodex-module-chart>
        <h3>{{ __('system_admin.sections.failed_jobs') }}</h3>
        <p>{{ __('system_admin.labels.queue_driver') }}: {{ \Illuminate\Support\Str::headline($queueDriver ?: '—') }}</p>
        @php
            $jobTrend=$failedJobs['trend']??[];
            $jobMax=max(1,collect($jobTrend)->max('count')??1);
            $jobCount=max(1,count($jobTrend));
        @endphp
        @if($jobTrend===[])
            @include('admin._module-dashboard-state',['state'=>'empty','message'=>$failedJobs['available']?__('system_admin.messages.no_failed_jobs'):__('system_admin.messages.failed_jobs_unavailable')])
        @else
            <div class="foodex-viz-bars" role="img" aria-label="{{ __('system_admin.sections.failed_jobs') }}">
            @foreach($jobTrend as $point)
                <span class="foodex-viz-bar" data-series="4" style="height:{{ max(8,(int)round(($point['count']/$jobMax)*100)) }}%" title="{{ $point['date'] }} · {{ $point['count'] }}"></span>
            @endforeach
            </div>
            <div class="system-admin-bar-labels" style="--bar-count:{{ $jobCount }}">@foreach($jobTrend as $point)<span>{{ substr($point['date'],5) }}</span>@endforeach</div>
            <div class="foodex-module-chart-table-wrap"><table class="foodex-module-chart-table"><thead><tr><th>{{ __('system_admin.labels.date') }}</th><th>{{ __('system_admin.labels.count') }}</th></tr></thead><tbody>@foreach($jobTrend as $point)<tr><td>{{ $point['date'] }}</td><td>{{ $point['count'] }}</td></tr>@endforeach</tbody></table></div>
        @endif
    </article>

    <article class="foodex-viz-card foodex-module-chart system-admin-chart" data-foodex-module-chart>
        <h3>{{ __('system_admin.sections.updates') }}</h3>
        <p>{{ $selectedFrom }} → {{ $selectedTo }}</p>
        @php
            $updateMax=max(1,collect($updateTrend)->map(fn($row)=>$row['success']+$row['failed'])->max()??1);
            $updateCount=max(1,count($updateTrend));
        @endphp
        @if($updateTrend===[])
            @include('admin._module-dashboard-state',['state'=>'empty','message'=>__('system_admin.messages.no_updates')])
        @else
            <div class="foodex-viz-bars" role="img" aria-label="{{ __('system_admin.sections.updates') }}">
            @foreach($updateTrend as $point)
                @php($total=$point['success']+$point['failed'])
                <span class="foodex-viz-bar" data-series="{{ $point['failed']>0?4:1 }}" style="height:{{ max(8,(int)round(($total/$updateMax)*100)) }}%" title="{{ $point['date'] }} · {{ $total }}"></span>
            @endforeach
            </div>
            <div class="system-admin-bar-labels" style="--bar-count:{{ $updateCount }}">@foreach($updateTrend as $point)<span>{{ substr($point['date'],5) }}</span>@endforeach</div>
            <div class="foodex-module-chart-table-wrap"><table class="foodex-module-chart-table"><thead><tr><th>{{ __('system_admin.labels.date') }}</th><th>{{ __('system_admin.evidence.succeeded') }}</th><th>{{ __('system_admin.evidence.failed') }}</th></tr></thead><tbody>@foreach($updateTrend as $point)<tr><td>{{ $point['date'] }}</td><td>{{ $point['success'] }}</td><td>{{ $point['failed'] }}</td></tr>@endforeach</tbody></table></div>
        @endif
    </article>

    <article class="foodex-viz-card foodex-module-chart system-admin-chart" data-foodex-module-chart>
        <h3>{{ __('system_admin.sections.backups') }}</h3>
        <p>{{ __('system_admin.messages.snapshot_restore') }}</p>
        @php
            $backupStatuses=collect($backupRows)->groupBy('status')->map->count();
            $backupMax=max(1,$backupStatuses->max()??1);
            $backupCount=max(1,$backupStatuses->count());
        @endphp
        @if($backupRows===[])
            @include('admin._module-dashboard-state',['state'=>'empty','message'=>__('system_admin.messages.no_backups')])
        @else
            <div class="foodex-viz-bars" role="img" aria-label="{{ __('system_admin.sections.backups') }}">
            @foreach($backupStatuses as $status=>$count)
                <span class="foodex-viz-bar" data-series="1" style="height:{{ max(8,(int)round(($count/$backupMax)*100)) }}%" title="{{ $status }} · {{ $count }}"></span>
            @endforeach
            </div>
            <div class="system-admin-bar-labels" style="--bar-count:{{ $backupCount }}">@foreach($backupStatuses as $status=>$count)<span>{{ $status }}</span>@endforeach</div>
            <div class="foodex-module-chart-summary">{{ __('system_admin.labels.count') }}: {{ count($backupRows) }}</div>
        @endif
    </article>
</div>
</section>

<section class="system-admin-section" id="configuration-warnings">
<h2>{{ __('system_admin.sections.warnings') }}</h2>
@if($warnings===[])
    @include('admin._module-dashboard-state',['state'=>'current','message'=>__('system_admin.messages.no_warnings')])
@else
<ul class="system-admin-warning-list">@foreach($warnings as $warning)<li class="system-admin-warning" role="status">⚠ {{ $warning }}</li>@endforeach</ul>
@endif
</section>

<section class="system-admin-section" id="backup-history">
<h2>{{ __('system_admin.sections.backups') }}</h2>
<p class="system-admin-note">{{ __('system_admin.messages.update_restore') }} {{ __('system_admin.messages.snapshot_restore') }}</p>
@if($backupRows===[])
    @include('admin._module-dashboard-state',['state'=>'empty','message'=>__('system_admin.messages.no_backups')])
@else
<div class="system-admin-table-wrap"><table class="system-admin-table" data-pagination-required>
<thead><tr><th>{{ __('system_admin.labels.type') }}</th><th>{{ __('system_admin.labels.scope') }}</th><th>{{ __('system_admin.labels.created_at') }}</th><th>{{ __('system_admin.labels.size') }}</th><th>{{ __('system_admin.labels.hash') }}</th><th>{{ __('system_admin.labels.status') }}</th><th>{{ __('system_admin.labels.retention') }}</th><th>{{ __('system_admin.labels.reason') }}</th><th>{{ __('system_admin.labels.origin') }}</th><th></th></tr></thead>
<tbody>@foreach($backupRows as $row)<tr>
<td>{{ $row['type'] }}</td><td>{{ $row['scope'] }}</td><td dir="ltr">{{ $row['created_at']??'—' }}</td><td>{{ $row['size'] }}</td><td><code>{{ $row['reference'] }}</code></td><td>{{ $row['status'] }}</td><td>{{ $row['retention'] }}</td><td><code>{{ $row['reason'] }}</code></td><td>{{ $row['origin'] }}</td> {{-- localization-gate: allow — backup type/status/scope/retention labels are localized server-side in EnterpriseSystemHealthController. --}}
<td><a class="foodex-action-secondary" href="{{ $row['url'] }}">{{ __('system_admin.actions.update') }}</a></td>
</tr>@endforeach</tbody></table></div>
@endif
</section>

<section class="system-admin-section" id="update-history">
<h2>{{ __('system_admin.sections.updates') }}</h2>
@if($updateRows->isEmpty())
    @include('admin._module-dashboard-state',['state'=>'empty','message'=>__('system_admin.messages.no_updates')])
@else
<div class="system-admin-table-wrap"><table class="system-admin-table" data-pagination-required>
<thead><tr>
<th>{{ __('system_admin.labels.from') }} → {{ __('system_admin.labels.to') }}</th>
<th>{{ __('system_admin.labels.status') }}</th><th>{{ __('system_admin.labels.preflight') }}</th><th>{{ __('system_admin.labels.backup') }}</th>
<th>{{ __('system_admin.labels.migrations') }}</th><th>{{ __('system_admin.labels.health_check') }}</th><th>{{ __('system_admin.labels.rollback') }}</th>
<th>{{ __('system_admin.labels.failed_stage') }}</th><th>{{ __('system_admin.update_center.environment') }}</th><th>{{ __('system_admin.labels.reason') }}</th><th>{{ __('system_admin.labels.actor') }}</th>
</tr></thead><tbody>
@foreach($updateRows as $row)
@php
$ev=is_array($row->execution_evidence)?$row->execution_evidence:[];
$statusKey=match(strtolower((string)$row->status)){'success','completed','succeeded'=>'succeeded','failed','error'=>'failed',default=>'pending'};
$evidenceLabel=fn($value)=>__('system_admin.evidence.'.(in_array($value,['pending','passed','failed','not_required','available','partial','not_created','succeeded','incomplete','automatic','manual_recovery'],true)?$value:'unknown'));
@endphp
<tr>
<td><code>{{ $row->from_version?:'—' }}</code> → <code>{{ $row->to_version }}</code></td>
<td>{{ __('system_admin.evidence.'.$statusKey) }}</td>
<td>{{ $evidenceLabel($ev['preflight']??'pending') }}</td>
<td>{{ $evidenceLabel($row->backup_status?:'unknown') }}</td>
<td>{{ $evidenceLabel($ev['migrations']??'not_required') }}</td>
<td>{{ $evidenceLabel($ev['health_check']??'pending') }}</td>
<td>{{ $evidenceLabel($row->rollback_status?:'unknown') }}</td>
<td>{{ $row->failed_stage?:'—' }}</td><td>{{ $row->environment?:'—' }}</td><td><code>{{ $row->operation_reason?:'—' }}</code></td><td>{{ $row->actor?->name?:'—' }}</td>
</tr>
@endforeach
</tbody></table></div>
@endif
</section>

<section class="system-admin-section" id="failed-jobs">
<h2>{{ __('system_admin.sections.failed_jobs') }}</h2>
<p class="system-admin-note">{{ __('system_admin.messages.failed_job_retry_policy') }}</p>
@if(!$failedJobs['available'])
    @include('admin._module-dashboard-state',['state'=>'disabled','message'=>__('system_admin.messages.failed_jobs_unavailable')])
@elseif($failedJobs['rows']===[])
    @include('admin._module-dashboard-state',['state'=>'current','message'=>__('system_admin.messages.no_failed_jobs')])
@else
<div class="system-admin-table-wrap"><table class="system-admin-table">
<thead><tr><th>{{ __('system_admin.labels.hash') }}</th><th>{{ __('system_admin.labels.queue_driver') }}</th><th>{{ __('system_admin.labels.source') }}</th><th>{{ __('system_admin.labels.date') }}</th></tr></thead>
<tbody>@foreach($failedJobs['rows'] as $row)<tr><td><code>{{ \Illuminate\Support\Str::limit($row['reference'],24,'…') }}</code></td><td>{{ $row['connection']?:'—' }}</td><td>{{ $row['queue']?:'—' }}</td><td dir="ltr">{{ $row['failed_at']?:'—' }}</td></tr>@endforeach</tbody>
</table></div>
@endif
</section>

<section class="system-admin-section">
<h2>{{ __('enterprise_health.system_signals') }}</h2>
<div class="system-admin-signals">
<article class="foodex-card system-admin-signal"><span>{{ __('enterprise_health.outbox') }}</span>@if($outboxCounts===[])<strong>{{ __('enterprise_health.no_activity') }}</strong>@else<div class="system-admin-pills">@foreach($outboxCounts as $status=>$count)<span class="system-admin-pill">{{ \Illuminate\Support\Str::headline($status) }} · {{ $count }}</span>@endforeach</div>@endif</article>
<article class="foodex-card system-admin-signal"><span>{{ __('enterprise_health.workflow') }}</span>@if($workflowCounts===[])<strong>{{ __('enterprise_health.no_activity') }}</strong>@else<div class="system-admin-pills">@foreach($workflowCounts as $status=>$count)<span class="system-admin-pill">{{ \Illuminate\Support\Str::headline($status) }} · {{ $count }}</span>@endforeach</div>@endif</article>
<article class="foodex-card system-admin-signal"><span>{{ __('system_admin.labels.heartbeat') }}</span><strong>{{ $schedulerHealth['last_run']??__('system_admin.states.unknown') }}</strong><p class="system-admin-note">{{ __('system_admin.labels.queue_driver') }}: {{ \Illuminate\Support\Str::headline($queueDriver?:'—') }}</p></article>
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
<div class="system-admin-module__head"><div><h3>{{ $module['name'] }}</h3><div class="foodex-subtitle">{{ $module['category'] }}</div></div><span class="foodex-status-badge" data-state="{{ $module['health']==='HEALTHY'?'success':($module['health']==='DEGRADED'?'warning':'danger') }}">{{ __('enterprise_health.health_states.'.$module['health']) }}</span></div>
<div class="system-admin-module-meta">
<div><span>{{ __('enterprise_health.lifecycle') }}</span><strong>{{ __('enterprise_health.states.'.$module['lifecycle']) }}</strong></div>
<div><span>{{ __('system_admin.labels.checks') }}</span><strong>{{ $module['check_count'] }}</strong></div>
<div><span>{{ __('system_admin.labels.stale_checks') }}</span><strong>{{ $module['stale_count'] }}</strong></div>
</div>
<div class="system-admin-deps">@if($module['dependencies']===[])<span class="system-admin-dep">{{ __('enterprise_health.dependencies') }} · {{ __('enterprise_health.none') }}</span>@else @foreach($module['dependencies'] as $dependency)<span class="system-admin-dep">{{ $dependency['name'] }} · {{ __('enterprise_health.states.'.$dependency['state']) }}</span>@endforeach @endif</div>
</article>
@endforeach
</div>
@endif
</section>
</main>
</div>
</body>
</html>
