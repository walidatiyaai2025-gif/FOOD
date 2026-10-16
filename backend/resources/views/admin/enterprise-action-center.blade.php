<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>{{ __('enterprise_action_center.title') }} · FOODEX</title>
@include('admin._brand-components')
<style>
body{margin:0;background:var(--foodex-background);color:var(--foodex-ink)}
.action-center{padding:clamp(18px,2.5vw,36px)}
.action-head{display:flex;justify-content:space-between;gap:18px;align-items:flex-start;margin-block:18px 24px}.action-head h1{margin:4px 0 8px;font-size:clamp(1.55rem,2.5vw,2.2rem)}.action-head p{margin:0;color:var(--foodex-muted);max-width:760px}
.action-card{background:var(--foodex-surface);border:1px solid var(--foodex-border);border-radius:var(--foodex-radius-card);padding:18px;box-shadow:var(--foodex-shadow-sm)}
.action-filters{display:grid;grid-template-columns:minmax(220px,1fr) minmax(160px,.35fr) auto auto;gap:10px;align-items:end}.action-filters label{display:grid;gap:5px;font-weight:700}.action-filters input,.action-filters select,.decision-form input{min-height:42px;border:1px solid var(--foodex-border);border-radius:9px;padding-inline:10px;font:inherit;background:var(--foodex-surface);color:var(--foodex-ink)}.action-reset{display:inline-flex;min-height:42px;align-items:center;justify-content:center;padding-inline:13px;border:1px solid var(--foodex-border);border-radius:9px;color:var(--foodex-green-dark);text-decoration:none;font-weight:800}
.action-state{padding:11px;border:1px solid var(--foodex-border);border-radius:9px;background:var(--foodex-surface);margin-bottom:12px}.action-state ul{margin-block:0}
.action-table-wrap{margin-top:16px;overflow:auto}.action-table{width:100%;border-collapse:collapse;min-width:760px}.action-table th,.action-table td{text-align:start;padding:12px;border-bottom:1px solid var(--foodex-border);vertical-align:top}.action-table th{font-size:.82rem;color:var(--foodex-muted)}.action-table small{color:var(--foodex-muted)}
.action-badge{display:inline-flex;align-items:center;min-height:30px;padding-inline:10px;border:1px solid var(--foodex-border);border-radius:999px;font-weight:800}.action-link{color:var(--foodex-green-dark);font-weight:800}.action-menu>summary{cursor:pointer;list-style:none;display:inline-flex;align-items:center;justify-content:center;min-width:42px;min-height:42px;border:1px solid var(--foodex-border);border-radius:10px;color:var(--foodex-green-dark);font-weight:900}.action-menu>summary::-webkit-details-marker{display:none}.action-menu[open] .decision-stack{margin-top:10px}
.decision-stack{display:grid;gap:10px;min-width:min(320px,70vw)}.decision-form{display:grid;grid-template-columns:minmax(160px,1fr) auto;gap:8px;align-items:end}.decision-form label{display:grid;gap:5px;font-size:.85rem;font-weight:700}.decision-form button{min-height:42px}.action-empty{padding:28px;text-align:center;color:var(--foodex-muted)}.action-pagination{margin-top:16px}
.work-summary{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px;margin-bottom:16px}.work-kpi{padding:16px}.work-kpi strong{display:block;font-size:clamp(1.35rem,2.4vw,1.9rem);font-variant-numeric:tabular-nums}.work-kpi span{color:var(--foodex-muted);font-weight:700}
.work-filters{display:grid;grid-template-columns:minmax(180px,1fr) repeat(4,minmax(130px,.55fr)) auto auto;gap:10px;align-items:end}.work-filters label{display:grid;gap:5px;font-weight:700}.work-filters input,.work-filters select{min-height:44px;border:1px solid var(--foodex-border);border-radius:10px;padding-inline:10px;font:inherit;background:var(--foodex-surface);color:var(--foodex-ink)}
.work-section{margin-top:16px}.work-section-head{display:flex;justify-content:space-between;gap:16px;align-items:flex-end;margin-bottom:12px}.work-section-head h2{margin:0;font-size:1.15rem}.work-section-head p{margin:3px 0 0;color:var(--foodex-muted)}
.work-state--overdue{border-color:#B42318;color:#B42318}.work-state--attention{border-color:var(--foodex-orange);color:#9a480f}.work-activity{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}.work-activity-item{display:flex;justify-content:space-between;gap:12px;align-items:center;padding:12px;border:1px solid var(--foodex-border);border-radius:10px}.work-activity-item small{color:var(--foodex-muted)}
@media(max-width:1100px){.work-filters{grid-template-columns:repeat(3,minmax(0,1fr))}.work-filters .foodex-filter-action,.work-filters .foodex-action-secondary{width:100%}}
@media(max-width:760px){.action-filters,.work-filters{grid-template-columns:1fr}.action-filters .foodex-filter-action,.action-reset{width:100%}.action-head{flex-direction:column}.decision-form{grid-template-columns:1fr}.action-card{padding:14px}.work-summary,.work-activity{grid-template-columns:1fr}.work-section-head{align-items:flex-start;flex-direction:column}}
</style>
</head><body>
<div class="foodex-admin-layout" data-foodex-mobile-web-page="enterprise-action-center">
<aside class="sidebar">@include('admin._sidebar',['navGroups'=>$navGroups,'navContext'=>$navContext,'user'=>$user])</aside> {{-- localization-gate: allow Blade include expression --}}
<main class="foodex-admin-main foodex-admin-page action-center" data-enterprise-action-center="true">
<header class="action-head foodex-page-header">
<div><div class="foodex-subtitle">FOODEX · {{ __('enterprise_my_work.eyebrow') }}</div><h1>{{ __('enterprise_my_work.title') }}</h1><p>{{ __('enterprise_my_work.description') }}</p></div>
</header>
@if(session('status'))<div class="action-state foodex-state" role="status">{{ session('status') }}</div>@endif
@if($errors->any())<div class="action-state foodex-state" role="alert"><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
@if($truncated)<div class="action-state foodex-state" role="status">{{ __('enterprise_action_center.bounded_notice') }}</div>@endif

<section class="work-summary" aria-label="{{ __('enterprise_my_work.title') }}">
<div class="work-kpi foodex-card"><strong>{{ $workSummary['total'] }}</strong><span>{{ __('enterprise_my_work.summary.total') }}</span></div>
<div class="work-kpi foodex-card"><strong>{{ $workSummary['overdue'] }}</strong><span>{{ __('enterprise_my_work.summary.overdue') }}</span></div>
<div class="work-kpi foodex-card"><strong>{{ $workSummary['attention'] }}</strong><span>{{ __('enterprise_my_work.summary.attention') }}</span></div>
</section>

<section class="action-card foodex-card">
<form method="get" class="work-filters">
<input type="hidden" name="state" value="{{ $state }}">
<label>{{ __('enterprise_action_center.search') }}<input type="search" name="q" maxlength="100" value="{{ $search }}" placeholder="{{ __('enterprise_action_center.search_placeholder') }}"></label>
<label>{{ __('enterprise_my_work.filters.module') }}<select name="module">
<option value="all" @selected($module==='all')>{{ __('enterprise_my_work.filters.all_modules') }}</option>
@foreach(['procurement','wms','finance','treasury','customer_service'] as $moduleOption)<option value="{{ $moduleOption }}" @selected($module===$moduleOption)>{{ __('enterprise_my_work.modules.'.$moduleOption) }}</option>@endforeach
</select></label>
<label>{{ __('enterprise_my_work.filters.state') }}<select name="work_state">
<option value="all" @selected($workState==='all')>{{ __('enterprise_my_work.filters.all_states') }}</option>
@foreach(['pending','overdue','attention'] as $stateOption)<option value="{{ $stateOption }}" @selected($workState===$stateOption)>{{ __('enterprise_my_work.states.'.$stateOption) }}</option>@endforeach
</select></label>
<label>{{ __('enterprise_my_work.filters.owner') }}<select name="owner"><option value="all" @selected($owner==='all')>{{ __('enterprise_my_work.filters.all_owners') }}</option><option value="me" @selected($owner==='me')>{{ __('enterprise_my_work.filters.mine') }}</option></select></label>
<label>{{ __('enterprise_my_work.filters.age') }}<select name="age"><option value="0" @selected($age===0)>{{ __('enterprise_my_work.filters.any_age') }}</option>@foreach([7,30,90] as $ageOption)<option value="{{ $ageOption }}" @selected($age===$ageOption)>{{ __('enterprise_my_work.filters.days',['days'=>$ageOption]) }}</option>@endforeach</select></label>
<button class="foodex-action-primary foodex-filter-action" type="submit">{{ __('enterprise_my_work.filters.apply') }}</button>
<a class="foodex-action-secondary" href="{{ route('admin.action-center.index',['state'=>$state!=='all'?$state:null]) }}">{{ __('enterprise_my_work.filters.reset') }}</a>
</form>
</section>

<section class="action-card foodex-card work-section">
<div class="work-section-head"><div><h2>{{ __('enterprise_my_work.title') }}</h2><p>{{ __('enterprise_my_work.description') }}</p></div></div>
@if($workItems->count()===0)
<div class="action-empty foodex-empty-state">{{ __('enterprise_my_work.empty') }}</div>
@else
<div class="foodex-table-wrap"><table class="action-table foodex-table">
<thead><tr><th>{{ __('enterprise_my_work.columns.module') }}</th><th>{{ __('enterprise_my_work.columns.work') }}</th><th>{{ __('enterprise_my_work.columns.state') }}</th><th>{{ __('enterprise_my_work.columns.due') }}</th><th>{{ __('enterprise_my_work.columns.updated') }}</th><th>{{ __('enterprise_my_work.columns.action') }}</th></tr></thead>
<tbody>@foreach($workItems as $item)
<tr data-my-work-row>
<td>{{ __('enterprise_my_work.modules.'.$item['module']) }}</td>
<td><strong>{{ $item['title'] }}</strong><br><small>{{ $item['reference'] }}</small></td>
<td><span class="action-badge work-state--{{ $item['state'] }}">{{ __('enterprise_my_work.states.'.$item['state']) }}</span></td>
<td>@if($item['due_at'])<time datetime="{{ $item['due_at']->toIso8601String() }}">{{ $item['due_at']->format('Y-m-d H:i') }}</time>@else{{ __('enterprise_my_work.no_due') }}@endif</td>
<td>@if($item['updated_at'])<time datetime="{{ $item['updated_at']->toIso8601String() }}">{{ $item['updated_at']->format('Y-m-d H:i') }}</time>@endif</td>
<td><a class="foodex-action-primary" href="{{ $item['deep_link'] }}">{{ __('enterprise_my_work.open') }}</a></td>
</tr>
@endforeach</tbody></table></div>
<div class="action-pagination">{{ $workItems->links() }}</div>
@endif
</section>

<section class="action-card foodex-card work-section">
<div class="work-section-head"><div><h2>{{ __('enterprise_my_work.recent_activity') }}</h2><p>{{ __('enterprise_my_work.recent_activity_hint') }}</p></div></div>
@if($activity->isEmpty())<div class="action-empty foodex-empty-state">{{ __('enterprise_my_work.empty') }}</div>
@else<div class="work-activity">@foreach($activity as $event)<a class="work-activity-item foodex-action-secondary" href="{{ $event['deep_link'] }}"><span><strong>{{ __('enterprise_my_work.modules.'.$event['module']) }} · {{ $event['title'] }}</strong><br><small>{{ $event['reference'] }}</small></span><small>{{ $event['updated_at']?->format('Y-m-d H:i') }}</small></a>@endforeach</div>@endif
</section>

<section class="work-section">
<div class="work-section-head"><div><h2>{{ __('enterprise_my_work.approvals_title') }}</h2><p>{{ __('enterprise_my_work.approvals_hint') }}</p></div></div>
</section>

<section class="action-card foodex-card">
<form method="get" class="action-filters">
<input type="hidden" name="module" value="{{ $module }}"><input type="hidden" name="work_state" value="{{ $workState }}"><input type="hidden" name="owner" value="{{ $owner }}"><input type="hidden" name="age" value="{{ $age }}">
<label>{{ __('enterprise_action_center.search') }}<input type="search" name="q" maxlength="100" value="{{ $search }}" placeholder="{{ __('enterprise_action_center.search_placeholder') }}"></label>
<label>{{ __('enterprise_action_center.state') }}<select name="state"><option value="all" @selected($state==='all')>{{ __('enterprise_action_center.all') }}</option><option value="pending" @selected($state==='pending')>{{ __('enterprise_action_center.pending') }}</option><option value="overdue" @selected($state==='overdue')>{{ __('enterprise_action_center.overdue') }}</option></select></label>
<button class="foodex-action-primary foodex-filter-action" type="submit">{{ __('enterprise_action_center.apply') }}</button>
<a class="action-reset foodex-action-secondary" href="{{ route('admin.action-center.index') }}">{{ __('enterprise_action_center.reset') }}</a>
</form>
</section>

<section class="action-card foodex-card action-table-wrap foodex-table-wrap">
@if($actions->count()===0)
<div class="action-empty foodex-empty-state">{{ __('enterprise_action_center.empty') }}</div>
@else
<table class="action-table foodex-table">
<thead><tr><th>{{ __('enterprise_action_center.request') }}</th><th>{{ __('enterprise_action_center.requested_by') }}</th><th>{{ __('enterprise_action_center.created') }}</th><th>{{ __('enterprise_action_center.due') }}</th><th>{{ __('enterprise_action_center.status') }}</th><th>{{ __('enterprise_action_center.actions') }}</th></tr></thead>
<tbody>
@foreach($actions as $action)
<tr data-action-center-row>
<td><strong>{{ $action['title'] ?: __('enterprise_action_center.default_title') }}</strong>@if($action['record_label'])<br><small>{{ $action['record_label'] }}</small>@endif @if($action['deep_link'])<br><a class="action-link" href="{{ $action['deep_link'] }}">{{ __('enterprise_action_center.open_record') }}</a>@endif</td>
<td>{{ $action['requester_name'] ?: __('enterprise_action_center.unknown_requester') }}</td>
<td><time datetime="{{ $action['created_at']?->toIso8601String() }}">{{ $action['created_at']?->format('Y-m-d H:i') }}</time></td>
<td>@if($action['due_at'])<time datetime="{{ $action['due_at']->toIso8601String() }}">{{ $action['due_at']->format('Y-m-d H:i') }}</time>@else{{ __('enterprise_action_center.no_due_date') }}@endif</td>
<td><span class="action-badge">{{ $action['overdue'] ? __('enterprise_action_center.overdue') : __('enterprise_action_center.pending') }}</span></td>
<td>
<details class="action-menu"><summary aria-label="{{ __('enterprise_action_center.more_actions') }}">⋯</summary>
<div class="decision-stack">
<form method="post" action="{{ route('admin.action-center.decide',['workflowRequest'=>$action['workflow_request_id'],'stepCode'=>$action['step_code']]) }}" class="decision-form" data-action-form>@csrf
<input type="hidden" name="decision" value="APPROVED"><input type="hidden" name="q" value="{{ $search }}"><input type="hidden" name="state" value="{{ $state }}"><input type="hidden" name="actions_page" value="{{ $actions->currentPage() }}">
<label>{{ __('enterprise_action_center.comment') }}<input name="comment" maxlength="1000" placeholder="{{ __('enterprise_action_center.comment_placeholder') }}"></label><button class="foodex-primary" type="submit">{{ __('enterprise_action_center.approve') }}</button>
</form>
<form method="post" action="{{ route('admin.action-center.decide',['workflowRequest'=>$action['workflow_request_id'],'stepCode'=>$action['step_code']]) }}" class="decision-form" data-action-form>@csrf
<input type="hidden" name="decision" value="REJECTED"><input type="hidden" name="q" value="{{ $search }}"><input type="hidden" name="state" value="{{ $state }}"><input type="hidden" name="actions_page" value="{{ $actions->currentPage() }}">
<label>{{ __('enterprise_action_center.comment') }}<input name="comment" maxlength="1000" placeholder="{{ __('enterprise_action_center.comment_placeholder') }}"></label><button class="foodex-action-secondary" type="submit">{{ __('enterprise_action_center.reject') }}</button>
</form>
</div></details>
</td>
</tr>
@endforeach
</tbody></table>
<div class="action-pagination">{{ $actions->links() }}</div>
@endif
</section>
</main></div>
<script>
document.querySelectorAll('[data-action-form]').forEach((form) => {
  form.addEventListener('submit', () => {
    form.setAttribute('aria-busy', 'true');
    form.querySelectorAll('button').forEach((button) => { button.disabled = true; });
  });
});
</script>
</body></html>
