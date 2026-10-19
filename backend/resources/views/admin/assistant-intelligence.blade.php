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
