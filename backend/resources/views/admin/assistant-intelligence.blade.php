<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>{{ __('assistant_intelligence.title') }} · FOODEX</title>
@include('admin._brand-components')
@include('admin._settings-page-polish')
<style>
body{margin:0;background:var(--foodex-background);color:var(--foodex-ink)}
.intelligence-layout{display:grid;grid-template-columns:minmax(0,1.45fr) minmax(300px,.75fr);gap:16px;align-items:start}
.intelligence-stack{display:grid;gap:14px}
.intelligence-card{background:var(--foodex-surface);border:1px solid var(--foodex-border);border-radius:16px;padding:18px;box-shadow:var(--foodex-shadow-sm)}
.intelligence-head{display:flex;justify-content:space-between;gap:14px;align-items:flex-start}
.intelligence-head h2,.intelligence-head h3{margin:0}.intelligence-head p{margin:5px 0 0;color:var(--foodex-muted)}
.intelligence-meta{display:flex;gap:8px;flex-wrap:wrap;margin-top:10px}
.intelligence-badge{display:inline-flex;align-items:center;min-height:30px;padding:4px 9px;border:1px solid var(--foodex-border);border-radius:999px;background:var(--foodex-background);font-size:.78rem;font-weight:700}
.intelligence-badge[data-severity="critical"],.intelligence-badge[data-severity="warning"]{border-color:var(--foodex-red);color:var(--foodex-red)}
.intelligence-badge[data-severity="opportunity"]{border-color:var(--foodex-green);color:var(--foodex-green-dark)}
.intelligence-copy{margin-top:12px;line-height:1.65}
.intelligence-evidence{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px;margin-top:12px}
.intelligence-evidence div{padding:9px 10px;background:var(--foodex-background);border-radius:10px}
.intelligence-evidence dt{font-size:.72rem;color:var(--foodex-muted);font-weight:700}
.intelligence-evidence dd{margin:3px 0 0;font-weight:700;overflow-wrap:anywhere}
.intelligence-actions{display:flex;gap:8px;flex-wrap:wrap;margin-top:14px}
.intelligence-actions form{margin:0}
.intelligence-actions button{min-height:44px}
.intelligence-form{display:grid;gap:12px}
.intelligence-check{display:flex;align-items:center;gap:10px;min-height:44px;font-weight:700}
.intelligence-check input{width:21px;height:21px}
.intelligence-field{display:grid;gap:6px;font-weight:700}
.intelligence-field select{min-height:44px;width:100%}
.intelligence-note{padding:12px;border:1px solid var(--foodex-border);border-radius:12px;background:var(--foodex-green-soft)}
.intelligence-brief{display:grid;gap:5px;padding:11px 0;border-bottom:1px solid var(--foodex-border)}
.intelligence-brief:last-child{border-bottom:0}
.intelligence-brief-row{display:flex;justify-content:space-between;gap:10px;align-items:center}
.intelligence-empty{padding:24px;text-align:center;border:1px dashed var(--foodex-border);border-radius:14px;color:var(--foodex-muted)}
.intelligence-pagination{margin-top:12px}
@media(max-width:1023px){.intelligence-layout{grid-template-columns:1fr}}
@media(max-width:767px){.intelligence-card{padding:14px}.intelligence-head,.intelligence-brief-row{flex-direction:column;align-items:stretch}.intelligence-evidence{grid-template-columns:1fr}.intelligence-actions>*{flex:1}.intelligence-actions button{width:100%}}

.analysis-shell{display:grid;gap:14px;margin-bottom:16px}
.analysis-top{display:grid;grid-template-columns:minmax(0,1fr) auto;gap:14px;align-items:start}
.analysis-status-line{display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-top:10px}
.analysis-source{display:inline-flex;align-items:center;min-height:28px;padding:3px 9px;border-radius:999px;border:1px solid var(--foodex-border);font-size:.74rem;font-weight:800;letter-spacing:.02em}
.analysis-source[data-source="DATABASE"]{background:#f3f6f8;color:#334155}.analysis-source[data-source="DETERMINISTIC"]{background:#edf6ff;color:#075985}.analysis-source[data-source="AGENT AI"]{background:#f4ecff;color:#6b21a8}.analysis-source[data-source="HYBRID"]{background:var(--foodex-green-soft);color:var(--foodex-green-dark)}
.analysis-progress{height:10px;background:var(--foodex-background);border-radius:999px;overflow:hidden;border:1px solid var(--foodex-border);margin-top:10px}.analysis-progress>span{display:block;height:100%;background:var(--foodex-green);width:var(--analysis-progress,0%)}
.analysis-kpis{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px}.analysis-kpi{padding:14px;border:1px solid var(--foodex-border);border-radius:14px;background:var(--foodex-surface)}.analysis-kpi small{display:block;color:var(--foodex-muted);font-weight:700}.analysis-kpi strong{display:block;font-size:1.35rem;margin-top:4px}
.analysis-agent{display:grid;grid-template-columns:minmax(0,1.4fr) minmax(260px,.6fr);gap:12px}.analysis-agent-copy{line-height:1.7;font-size:1rem}.analysis-lists{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}.analysis-lists ul{margin:6px 0 0;padding-inline-start:20px}
.analysis-domains{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px}.analysis-domain{padding:12px;border:1px solid var(--foodex-border);border-radius:12px;background:var(--foodex-background)}.analysis-domain strong{display:flex;justify-content:space-between;gap:8px}.analysis-domain small{display:block;color:var(--foodex-muted);margin-top:5px}
.analysis-opinions{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}.analysis-opinion{padding:13px;border:1px solid var(--foodex-border);border-radius:12px;background:var(--foodex-surface)}
.analysis-table-wrap{overflow:auto}.analysis-table{width:100%;border-collapse:collapse;min-width:780px}.analysis-table th,.analysis-table td{padding:9px 10px;border-bottom:1px solid var(--foodex-border);text-align:start;white-space:nowrap}.analysis-table th{font-size:.76rem;color:var(--foodex-muted)}
.analysis-empty{padding:18px;border:1px dashed var(--foodex-border);border-radius:12px;color:var(--foodex-muted);text-align:center}
@media(max-width:1023px){.analysis-top,.analysis-agent{grid-template-columns:1fr}.analysis-kpis,.analysis-domains{grid-template-columns:repeat(2,minmax(0,1fr))}}@media(max-width:767px){.analysis-kpis,.analysis-domains,.analysis-opinions,.analysis-lists{grid-template-columns:1fr}}

</style>
</head>
<body>
<div class="foodex-admin-layout" data-foodex-utility="assistant-intelligence">
<aside class="sidebar">@include('admin._sidebar',['navGroups'=>$navGroups,'navContext'=>$navContext,'user'=>$user])</aside>
<main class="foodex-admin-main foodex-admin-page">
@include('admin._global-header')
<div class="foodex-page-toolbar">
<div>
<span class="foodex-subtitle">{{ __('assistant_intelligence.subtitle') }}</span>
<h1>{{ __('assistant_intelligence.title') }}</h1>
<p>{{ __('assistant_intelligence.description') }}</p>
</div>
<div class="foodex-header-actions">
@if($canOpenSettings)
<a class="secondary" href="{{ route('admin.assistant-overview.index') }}">{{ __('assistant_overview.title') }}</a>
<a class="secondary" href="{{ route('admin.assistant-settings.index') }}">{{ __('assistant_intelligence.settings') }}</a>
@endif
</div>
</div>

@if(session('status'))<div class="foodex-state" role="status">{{ session('status') }}</div>@endif
@if($errors->any())
<div class="foodex-state" role="alert">
<ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
</div>
@endif


@if($analysis)
@php
$agent=(array)($analysis['agent']??[]);
$agentSource=strtoupper((string)($agent['source']??'DATABASE'));
$agentOpinion=(string)($agent['executive_opinion']??'');
$coverage=(float)($analysis['coverage_percent']??0);
$kpis=(array)($analysis['kpis']??[]);
@endphp
<section class="analysis-shell" aria-labelledby="foodex-analysis-title">
<div class="intelligence-card">
<div class="analysis-top">
<div>
<span class="foodex-subtitle">{{ __('assistant_intelligence.analysis.subtitle') }}</span>
<h2 id="foodex-analysis-title" style="margin:4px 0 0">{{ __('assistant_intelligence.analysis.title') }}</h2>
<p style="margin:6px 0 0;color:var(--foodex-muted)">{{ __('assistant_intelligence.analysis.description') }}</p>
<div class="analysis-status-line">
<span class="intelligence-badge">{{ strtoupper((string)($analysis['status']??'idle')) }}</span>
<span class="analysis-source" data-source="{{ $agentSource }}">{{ $agentSource }}</span>
@if(!empty($analysis['provider'])||!empty($analysis['model']))<span class="intelligence-badge">{{ trim(($analysis['provider']??'').' / '.($analysis['model']??''),' /') }}</span>@endif
</div>
</div>
<form method="post" action="{{ route('admin.assistant-intelligence.analyze') }}">@csrf<button type="submit" class="foodex-primary" data-submit-once>{{ __('assistant_intelligence.analysis.analyze_now') }}</button></form>
</div>
<div class="analysis-status-line" style="justify-content:space-between"><strong>{{ __('assistant_intelligence.analysis.coverage') }} · {{ number_format($coverage,1) }}%</strong>@if(!empty($analysis['last_progress_at']))<span>{{ __('assistant_intelligence.analysis.last_progress') }} · {{ $analysis['last_progress_at'] }}</span>@endif</div>
<div class="analysis-progress" style="--analysis-progress:{{ min(100,max(0,$coverage)) }}%"><span></span></div>
</div>

<div class="analysis-kpis">
<div class="analysis-kpi"><small>{{ __('assistant_intelligence.analysis.kpi_revenue') }}</small><strong>{{ number_format((float)($kpis['revenue']??0),2) }}</strong><span class="analysis-source" data-source="DATABASE">DATABASE</span></div>
<div class="analysis-kpi"><small>{{ __('assistant_intelligence.analysis.kpi_orders') }}</small><strong>{{ number_format((int)($kpis['orders']??0)) }}</strong><span class="analysis-source" data-source="DATABASE">DATABASE</span></div>
<div class="analysis-kpi"><small>{{ __('assistant_intelligence.analysis.kpi_customers') }}</small><strong>{{ number_format((int)($kpis['customers']??0)) }}</strong><span class="analysis-source" data-source="DATABASE">DATABASE</span></div>
<div class="analysis-kpi"><small>{{ __('assistant_intelligence.analysis.kpi_stockouts') }}</small><strong>{{ number_format((int)($kpis['stockouts']??0)) }}</strong><span class="analysis-source" data-source="DATABASE">DATABASE</span></div>
</div>

<div class="intelligence-card analysis-agent">
<div>
<div class="intelligence-head"><div><h2>{{ __('assistant_intelligence.analysis.agent_opinion') }}</h2></div><span class="analysis-source" data-source="{{ $agentSource }}">{{ $agentSource }}</span></div>
@if($agentOpinion!=='')<p class="analysis-agent-copy">{{ $agentOpinion }}</p>@else<div class="analysis-empty">{{ __('assistant_intelligence.analysis.agent_waiting') }}</div>@endif
@if($agentSource==='DATABASE')<p class="intelligence-note">{{ __('assistant_intelligence.analysis.database_only') }}</p>@endif
</div>
<div><dl class="intelligence-evidence">
<div><dt>{{ __('assistant_intelligence.analysis.provider') }}</dt><dd>{{ trim(($analysis['provider']??'—').' / '.($analysis['model']??''),' /')?:'—' }}</dd></div>
<div><dt>{{ __('assistant_intelligence.analysis.latency') }}</dt><dd>{{ isset($analysis['agent_latency_ms'])?number_format((int)$analysis['agent_latency_ms']).' ms':'—' }}</dd></div>
<div><dt>{{ __('assistant_intelligence.analysis.confidence') }}</dt><dd>{{ strtoupper((string)($agent['confidence']??'—')) }}</dd></div>
<div><dt>{{ __('assistant_intelligence.analysis.run') }}</dt><dd>#{{ $analysis['id']??'—' }}</dd></div>
</dl></div>
</div>

@if(!empty($agent['priorities'])||!empty($agent['recommendations']))
<div class="intelligence-card analysis-lists">
<div><strong>{{ __('assistant_intelligence.analysis.priorities') }}</strong><ul>@foreach((array)($agent['priorities']??[]) as $item)<li>{{ $item }}</li>@endforeach</ul></div>
<div><strong>{{ __('assistant_intelligence.analysis.recommendations') }}</strong><ul>@foreach((array)($agent['recommendations']??[]) as $item)<li>{{ $item }}</li>@endforeach</ul></div>
</div>
@endif

<div class="intelligence-card">
<div class="intelligence-head"><div><h2>{{ __('assistant_intelligence.analysis.pipeline') }}</h2><p>{{ __('assistant_intelligence.analysis.pipeline_help') }}</p></div></div>
<div class="analysis-domains" style="margin-top:12px">
@foreach((array)($analysis['domains']??[]) as $domain=>$state)
<div class="analysis-domain"><strong><span>{{ __('assistant_intelligence.analysis.domains.'.$domain) }}</span><span>{{ number_format((float)($state['coverage']??0),0) }}%</span></strong>
<div class="analysis-progress" style="--analysis-progress:{{ min(100,max(0,(float)($state['coverage']??0))) }}%"><span></span></div>
<small>{{ __('assistant_intelligence.analysis.completed') }} {{ (int)($state['complete']??0) }} · {{ __('assistant_intelligence.analysis.partial') }} {{ (int)($state['partial']??0) }} · {{ __('assistant_intelligence.analysis.running') }} {{ (int)($state['running']??0) }} · {{ __('assistant_intelligence.analysis.pending') }} {{ (int)($state['pending']??0) }}</small></div>
@endforeach
</div></div>

<div class="intelligence-card">
<div class="intelligence-head"><div><h2>{{ __('assistant_intelligence.analysis.chunk_opinions') }}</h2><p>{{ __('assistant_intelligence.analysis.chunk_opinions_help') }}</p></div></div>
@if(!empty($analysis['opinions']))<div class="analysis-opinions" style="margin-top:12px">
@foreach($analysis['opinions'] as $opinion)
<article class="analysis-opinion"><div class="analysis-status-line" style="margin-top:0"><strong>{{ __('assistant_intelligence.analysis.domains.'.$opinion['domain']) }}</strong><span class="analysis-source" data-source="{{ strtoupper($opinion['source']) }}">{{ strtoupper($opinion['source']) }}</span></div><p>{{ $opinion['opinion'] }}</p><small>{{ __('assistant_intelligence.analysis.stores') }}: {{ $opinion['store_count'] }} · {{ $opinion['model']?:'—' }} · {{ number_format((int)$opinion['latency_ms']) }} ms</small></article>
@endforeach
</div>@else<div class="analysis-empty" style="margin-top:12px">{{ __('assistant_intelligence.analysis.no_agent_chunks') }}</div>@endif
</div>

<div class="intelligence-card"><div class="intelligence-head"><div><h2>{{ __('assistant_intelligence.analysis.trace') }}</h2><p>{{ __('assistant_intelligence.analysis.trace_help') }}</p></div></div>
<div class="analysis-table-wrap" style="margin-top:10px"><table class="analysis-table"><thead><tr><th>ID</th><th>{{ __('assistant_intelligence.analysis.domain') }}</th><th>{{ __('assistant_intelligence.analysis.status') }}</th><th>{{ __('assistant_intelligence.analysis.source') }}</th><th>{{ __('assistant_intelligence.analysis.stores') }}</th><th>{{ __('assistant_intelligence.analysis.provider') }}</th><th>{{ __('assistant_intelligence.analysis.latency') }}</th><th>{{ __('assistant_intelligence.analysis.finished') }}</th></tr></thead><tbody>
@foreach((array)($analysis['trace']??[]) as $row)<tr><td>#{{ $row['id'] }}</td><td>{{ __('assistant_intelligence.analysis.domains.'.$row['domain']) }}</td><td>{{ strtoupper($row['status']) }}</td><td><span class="analysis-source" data-source="{{ strtoupper($row['source']) }}">{{ strtoupper($row['source']) }}</span></td><td>{{ $row['store_count'] }}</td><td>{{ trim(($row['provider']??'').' / '.($row['model']??''),' /')?:'—' }}</td><td>{{ $row['latency_ms']?number_format((int)$row['latency_ms']).' ms':'—' }}</td><td>{{ $row['completed_at']?:'—' }}</td></tr>@endforeach
</tbody></table></div></div>

<div class="intelligence-card"><div class="intelligence-head"><div><h2>{{ __('assistant_intelligence.analysis.run_history') }}</h2></div></div>
<div class="analysis-table-wrap" style="margin-top:10px"><table class="analysis-table"><thead><tr><th>{{ __('assistant_intelligence.analysis.run') }}</th><th>{{ __('assistant_intelligence.analysis.trigger') }}</th><th>{{ __('assistant_intelligence.analysis.status') }}</th><th>{{ __('assistant_intelligence.analysis.coverage') }}</th><th>{{ __('assistant_intelligence.analysis.source') }}</th><th>{{ __('assistant_intelligence.analysis.started') }}</th><th>{{ __('assistant_intelligence.analysis.finished') }}</th></tr></thead><tbody>
@foreach((array)($analysis['history']??[]) as $row)<tr><td>#{{ $row['id'] }}</td><td>{{ strtoupper($row['trigger']) }}</td><td>{{ strtoupper($row['status']) }}</td><td>{{ number_format((float)$row['coverage_percent'],1) }}%</td><td><span class="analysis-source" data-source="{{ strtoupper($row['source']) }}">{{ strtoupper($row['source']) }}</span></td><td>{{ $row['started_at']?:'—' }}</td><td>{{ $row['completed_at']?:'—' }}</td></tr>@endforeach
</tbody></table></div></div>
</section>
@endif

<div class="intelligence-layout">
<section class="intelligence-stack" aria-labelledby="intelligence-findings-title">
<div class="intelligence-card">
<div class="intelligence-head">
<div>
<h2 id="intelligence-findings-title">{{ __('assistant_intelligence.current_findings') }}</h2>
<p>{{ __('assistant_intelligence.current_findings_help') }}</p>
</div>
</div>
</div>

@forelse($findings as $finding)
@php
$evidence = (array) $finding->evidence;
$evidenceLabels = [
    'metric' => __('assistant_intelligence.metric'),
    'value' => __('assistant_intelligence.value'),
    'period' => __('assistant_intelligence.period'),
    'entity' => __('assistant_intelligence.entity'),
    'source' => __('assistant_intelligence.source'),
    'freshness' => __('assistant_intelligence.freshness'),
];
$channelLabel = match (strtolower((string) $finding->channel)) {
    'b2b' => __('assistant_intelligence.channel.b2b'),
    'b2c' => __('assistant_intelligence.channel.b2c'),
    default => null,
};
@endphp
<article class="intelligence-card">
<div class="intelligence-head">
<div>
<h3>{{ app()->getLocale()==='en' ? $finding->title_en : $finding->title_ar }}</h3>
<div class="intelligence-meta">
<span class="intelligence-badge" data-severity="{{ $finding->severity }}">{{ __('assistant_intelligence.severity.'.$finding->severity) }}</span>
<span class="intelligence-badge">{{ __('assistant_intelligence.status.'.$finding->status) }}</span>
@if($channelLabel)<span class="intelligence-badge">{{ $channelLabel }}</span>@endif
</div>
</div>
<time datetime="{{ optional($finding->snapshot_at)->toIso8601String() }}">
{{ __('assistant_intelligence.snapshot') }} · {{ optional($finding->snapshot_at)->setTimezone('Asia/Kuwait')->format('Y-m-d H:i') }}
</time>
</div>
<p class="intelligence-copy">{{ app()->getLocale()==='en' ? $finding->narrative_en : $finding->narrative_ar }}</p>

@if(collect(array_keys($evidenceLabels))->contains(fn($key)=>array_key_exists($key,$evidence)))
<dl class="intelligence-evidence">
@foreach($evidenceLabels as $key=>$label)
@if(array_key_exists($key,$evidence) && (is_scalar($evidence[$key]) || $evidence[$key]===null))
<div><dt>{{ $label }}</dt><dd>{{ $evidence[$key] ?? '—' }}</dd></div>
@endif
@endforeach
</dl>
@endif

@if(is_array($finding->proposed_action))
<div class="intelligence-note" style="margin-top:12px">
<strong>{{ __('assistant_intelligence.proposed_action') }}</strong>
<div>{{ app()->getLocale()==='en' ? ($finding->proposed_action['label_en'] ?? $finding->proposed_action['label'] ?? '—') : ($finding->proposed_action['label_ar'] ?? $finding->proposed_action['label'] ?? '—') }}</div>
</div>
@endif

@if($finding->status !== 'resolved')
<div class="intelligence-actions">
@if($finding->status === 'unread')
<form method="post" action="{{ route('admin.assistant-intelligence.findings.acknowledge',$finding->id) }}">
@csrf
<button class="secondary" type="submit" data-submit-once>{{ __('assistant_intelligence.acknowledge') }}</button>
</form>
@endif
<form method="post" action="{{ route('admin.assistant-intelligence.findings.resolve',$finding->id) }}">
@csrf
<button class="foodex-primary" type="submit" data-submit-once>{{ __('assistant_intelligence.resolve') }}</button>
</form>
</div>
@endif
</article>
@empty
<div class="intelligence-empty">{{ __('assistant_intelligence.no_findings') }}</div>
@endforelse

@if($findings->hasPages())
<div class="intelligence-pagination">{{ $findings->links() }}</div>
@endif
</section>

<aside class="intelligence-stack">
<section class="intelligence-card" aria-labelledby="intelligence-preferences-title">
<div class="intelligence-head">
<div>
<h2 id="intelligence-preferences-title">{{ __('assistant_intelligence.preferences') }}</h2>
<p>{{ __('assistant_intelligence.preferences_help') }}</p>
</div>
</div>
<div class="intelligence-note" style="margin-top:12px">
<strong>{{ __('assistant_intelligence.provider_independent') }}</strong>
<div>{{ __('assistant_intelligence.provider_independent_help') }}</div>
</div>
<form method="post" action="{{ route('admin.assistant-intelligence.subscription.update') }}" class="intelligence-form" style="margin-top:14px">
@csrf
@method('PUT')
<input type="hidden" name="enabled" value="0">
<label class="intelligence-check"><input type="checkbox" name="enabled" value="1" @checked(old('enabled',$subscription->enabled))><span>{{ __('assistant_intelligence.enabled') }}</span></label>
<input type="hidden" name="daily_enabled" value="0">
<label class="intelligence-check"><input type="checkbox" name="daily_enabled" value="1" @checked(old('daily_enabled',$subscription->daily_enabled))><span>{{ __('assistant_intelligence.daily_enabled') }}</span></label>
<input type="hidden" name="weekly_enabled" value="0">
<label class="intelligence-check"><input type="checkbox" name="weekly_enabled" value="1" @checked(old('weekly_enabled',$subscription->weekly_enabled))><span>{{ __('assistant_intelligence.weekly_enabled') }}</span></label>
<label class="intelligence-field">
<span>{{ __('assistant_intelligence.minimum_severity') }}</span>
<select name="minimum_severity">
@foreach($severities as $severity)
<option value="{{ $severity }}" @selected(old('minimum_severity',$subscription->minimum_severity)===$severity)>{{ __('assistant_intelligence.severity.'.$severity) }}</option>
@endforeach
</select>
</label>
<label class="intelligence-field">
<span>{{ __('assistant_intelligence.language') }}</span>
<select name="locale">
<option value="ar" @selected(old('locale',$subscription->locale)==='ar')>{{ __('assistant_intelligence.arabic') }}</option>
<option value="en" @selected(old('locale',$subscription->locale)==='en')>{{ __('assistant_intelligence.english') }}</option>
</select>
</label>
<div class="intelligence-field">
<span>{{ __('assistant_intelligence.delivery_channel') }}</span>
<div class="intelligence-note">{{ __('assistant_intelligence.in_app') }}</div>
</div>
<button type="submit" class="foodex-primary" data-submit-once>{{ __('assistant_intelligence.save_preferences') }}</button>
</form>
</section>

<section class="intelligence-card">
<div class="intelligence-head">
<div>
<h2>{{ __('assistant_intelligence.generate_now') }}</h2>
<p>{{ __('assistant_intelligence.provider_independent_help') }}</p>
</div>
</div>
<div class="intelligence-actions">
<form method="post" action="{{ route('admin.assistant-intelligence.generate') }}">
@csrf
<input type="hidden" name="cadence" value="daily">
<button type="submit" class="foodex-primary" data-submit-once>{{ __('assistant_intelligence.generate_daily') }}</button>
</form>
<form method="post" action="{{ route('admin.assistant-intelligence.generate') }}">
@csrf
<input type="hidden" name="cadence" value="weekly">
<button type="submit" class="secondary" data-submit-once>{{ __('assistant_intelligence.generate_weekly') }}</button>
</form>
</div>
</section>

<section class="intelligence-card" aria-labelledby="intelligence-history-title">
<div class="intelligence-head">
<div>
<h2 id="intelligence-history-title">{{ __('assistant_intelligence.brief_history') }}</h2>
<p>{{ __('assistant_intelligence.brief_history_help') }}</p>
</div>
</div>
<div style="margin-top:10px">
@forelse($briefHistory as $brief)
<div class="intelligence-brief">
<div class="intelligence-brief-row">
<strong>{{ __('assistant_intelligence.'.$brief->cadence) }}</strong>
<span class="intelligence-badge">{{ __('assistant_intelligence.state.'.$brief->state) }}</span>
</div>
<div>{{ __('assistant_intelligence.findings_count',['count'=>(int) data_get($brief->payload,'finding_count',0)]) }}</div>
<time datetime="{{ optional($brief->snapshot_at)->toIso8601String() }}">{{ __('assistant_intelligence.snapshot') }} · {{ optional($brief->snapshot_at)->setTimezone('Asia/Kuwait')->format('Y-m-d H:i') }}</time>
</div>
@empty
<div class="intelligence-empty">{{ __('assistant_intelligence.no_briefs') }}</div>
@endforelse
</div>
@if($briefHistory->hasPages())
<div class="intelligence-pagination">{{ $briefHistory->links() }}</div>
@endif
</section>
</aside>
</div>
</main>
</div>
</body>
</html>
