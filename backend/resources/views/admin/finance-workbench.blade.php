<!doctype html>
<html lang="{{ str_replace('_','-',app()->getLocale()) }}" dir="{{ app()->getLocale()==='ar' ? 'rtl' : 'ltr' }}">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>{{ __('finance_workbench.title') }} · FOODEX</title>
@include('admin._brand-components')
<style>
body{margin:0;background:var(--foodex-background);color:var(--foodex-ink)}
.finance-page{padding:clamp(16px,2.5vw,36px)}
.finance-head{display:flex;align-items:flex-start;justify-content:space-between;gap:18px;margin-block:18px 20px}
.finance-head h1{margin:4px 0 8px;font-size:clamp(1.55rem,2.5vw,2.2rem)}
.finance-head p{margin:0;max-width:900px;color:var(--foodex-muted);line-height:1.55}
.finance-context,.finance-panel,.finance-card,.finance-notice{background:var(--foodex-surface);border:1px solid var(--foodex-border);border-radius:var(--foodex-radius-card);box-shadow:var(--foodex-shadow-sm)}
.finance-context{padding:16px;margin-bottom:16px}
.finance-context-grid,.finance-form-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;align-items:end}
.finance-context-grid{grid-template-columns:minmax(220px,1fr) auto}
.finance-tabs{display:flex;gap:8px;overflow-x:auto;padding:2px 0 12px;scrollbar-width:thin}
.finance-tab{display:inline-flex;align-items:center;justify-content:center;min-height:44px;white-space:nowrap;padding:0 14px;border:1px solid var(--foodex-border);border-radius:10px;background:var(--foodex-surface);color:var(--foodex-ink);text-decoration:none;font-weight:800}
.finance-tab.is-active{background:var(--foodex-action-green);border-color:var(--foodex-action-green);color:#fff}
.finance-tab.is-disabled{opacity:.55;cursor:not-allowed}
.finance-metrics{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;margin-bottom:16px}
.finance-card{padding:16px}.finance-card strong{display:block;font-size:1.55rem;margin-top:7px}.finance-card span{color:var(--foodex-muted);font-size:.9rem}
.finance-notice{padding:14px 16px;margin-bottom:14px;color:var(--foodex-muted);line-height:1.6}
.finance-notice strong{color:var(--foodex-ink)}
.finance-panel{padding:18px;margin-bottom:16px;min-width:0}
.finance-panel-head{display:flex;align-items:flex-start;justify-content:space-between;gap:12px;margin-bottom:16px}
.finance-panel-head h2,.finance-panel-head h3{margin:0 0 6px}.finance-panel-head p{margin:0;color:var(--foodex-muted);line-height:1.5}
.finance-filter,.finance-inline-actions{display:flex;flex-wrap:wrap;gap:9px;align-items:end}
.finance-field{display:flex;flex-direction:column;gap:6px;min-width:0}.finance-field label{font-size:.82rem;font-weight:800;color:var(--foodex-muted)}
.finance-field input,.finance-field select,.finance-field textarea{width:100%;box-sizing:border-box;min-height:44px;border:1px solid var(--foodex-border);border-radius:9px;background:var(--foodex-surface);color:var(--foodex-ink);padding:9px 11px;font:inherit}
.finance-field textarea{min-height:86px;resize:vertical}
.finance-check{display:flex;align-items:center;gap:8px;min-height:44px}.finance-check input{inline-size:18px;block-size:18px}
.finance-button{display:inline-flex;align-items:center;justify-content:center;min-height:44px;padding:0 14px;border:1px solid var(--foodex-border);border-radius:9px;background:var(--foodex-surface);color:var(--foodex-ink);text-decoration:none;font:inherit;font-weight:800;cursor:pointer}
.finance-button.primary{background:var(--foodex-action-green);border-color:var(--foodex-action-green);color:#fff}
.finance-button.danger{border-color:var(--foodex-danger);color:var(--foodex-danger)}
.finance-table-wrap{overflow-x:auto;border:1px solid var(--foodex-border);border-radius:10px}
.finance-table{width:100%;border-collapse:collapse;min-width:760px}
.finance-table th,.finance-table td{padding:11px 12px;border-bottom:1px solid var(--foodex-border);text-align:start;vertical-align:top}
.finance-table th{font-size:.78rem;color:var(--foodex-muted);background:var(--foodex-background);white-space:nowrap}
.finance-table tr:last-child td{border-bottom:0}
.finance-money{font-variant-numeric:tabular-nums;direction:ltr;unicode-bidi:isolate;text-align:end}
.finance-status{display:inline-flex;align-items:center;min-height:28px;padding:2px 9px;border:1px solid var(--foodex-border);border-radius:999px;font-size:.78rem;font-weight:900;white-space:nowrap}
.finance-empty{padding:26px;text-align:center;color:var(--foodex-muted)}
.finance-flash{padding:12px 14px;margin-bottom:14px;border:1px solid var(--foodex-action-green);border-radius:10px;background:var(--foodex-surface)}
.finance-errors{padding:12px 14px;margin-bottom:14px;border:1px solid var(--foodex-danger);border-radius:10px;background:var(--foodex-surface)}
.finance-errors ul{margin:7px 0 0;padding-inline-start:20px}
.finance-details{border:1px solid var(--foodex-border);border-radius:10px;background:var(--foodex-surface);margin-bottom:14px}
.finance-details>summary{min-height:44px;display:flex;align-items:center;padding:0 14px;cursor:pointer;font-weight:900}
.finance-details[open]>summary{border-bottom:1px solid var(--foodex-border)}
.finance-details-body{padding:14px}
.finance-lines{display:grid;grid-template-columns:1fr 1fr;gap:12px}.finance-line{padding:12px;border:1px solid var(--foodex-border);border-radius:10px}
.finance-line h4{margin:0 0 10px}
.finance-kv{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px}
.finance-kv div{padding:10px;border:1px solid var(--foodex-border);border-radius:9px}.finance-kv dt{font-size:.78rem;color:var(--foodex-muted);font-weight:800}.finance-kv dd{margin:5px 0 0;font-weight:800;overflow-wrap:anywhere}
.finance-subgrid{display:grid;grid-template-columns:1fr 1fr;gap:14px}
.finance-muted{color:var(--foodex-muted)}
.finance-source-trace{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px;margin-bottom:14px}.finance-source-step{padding:12px;border:1px solid var(--foodex-border);border-radius:10px}.finance-source-step strong{display:block;margin-bottom:6px}
.finance-pagination{margin-top:14px}
@media(max-width:1050px){.finance-form-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.finance-metrics{grid-template-columns:repeat(2,minmax(0,1fr))}.finance-kv{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media(max-width:720px){.finance-page{padding:14px}.finance-head{flex-direction:column}.finance-context-grid,.finance-form-grid,.finance-lines,.finance-subgrid,.finance-source-trace,.finance-kv{grid-template-columns:1fr}.finance-metrics{grid-template-columns:1fr 1fr}.finance-panel{padding:14px}.finance-filter>*{flex:1 1 150px}.finance-button{width:100%}.finance-inline-actions .finance-button{width:auto}.finance-panel-head{flex-direction:column}}
@media(max-width:430px){.finance-metrics{grid-template-columns:1fr}}
</style>
</head>
<body>
@php
    $storeId = $selectedContext['id'] ?? null;
    $routeBase = $storeId ? ['store_id' => $storeId] : [];
    $money = static fn ($value): string => number_format((float) $value, 3, '.', ',');
    $mills = static fn ($value): string => number_format(((int) $value) / 1000, 3, '.', ',');
    $status = static fn ($value): string => __('finance_workbench.statuses.'.(string) $value);
@endphp
<div class="foodex-admin-layout" data-foodex-mobile-web-page="finance-workbench">
<aside class="sidebar">@include('admin._sidebar',['navGroups'=>$navGroups,'navContext'=>$navContext,'user'=>$user])</aside> {{-- localization-gate: allow Blade include expression --}}
<main class="foodex-admin-main foodex-admin-page finance-page" data-finance-workbench="true">
<header class="finance-head foodex-page-header">
<div>
<div class="foodex-subtitle">FOODEX · {{ __('finance_workbench.eyebrow') }}</div>
<h1>{{ __('finance_workbench.title') }}</h1>
<p>{{ __('finance_workbench.description') }}</p>
</div>
</header>

@if(session('success'))
<div class="finance-flash" role="status">{{ session('success') }}</div>
@endif
@if($errors->any())
<div class="finance-errors" role="alert">
<strong>{{ __('finance_workbench.errors_title') }}</strong>
<ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
</div>
@endif

<section class="finance-context" aria-labelledby="finance-context-title">
<div class="finance-panel-head">
<div><h2 id="finance-context-title">{{ __('finance_workbench.context_title') }}</h2><p>{{ __('finance_workbench.context_help') }}</p></div>
</div>
<form method="get" action="{{ route('admin.erp.finance.index') }}" class="finance-context-grid">
<div class="finance-field">
<label for="finance-context">{{ __('finance_workbench.choose_context') }}</label>
<select id="finance-context" name="store_id" required>
<option value="">{{ __('finance_workbench.choose_context') }}</option>
@foreach($contexts as $contextOption)
<option value="{{ $contextOption['id'] }}" @selected($storeId===$contextOption['id'])>
{{ __('finance_workbench.channel_'.$contextOption['channel']) }} · {{ $contextOption['code'] }} · {{ $contextOption['name'] }}
</option>
@endforeach
</select>
</div>
<input type="hidden" name="tab" value="{{ $tab }}">
<button class="finance-button primary" type="submit">{{ __('finance_workbench.open_context') }}</button>
</form>
</section>

@if($needsContext)
<section class="finance-notice"><strong>{{ __('finance_workbench.context_title') }}</strong><br>{{ __('finance_workbench.module_guard') }}</section>
@else
<section class="finance-notice">
<strong>{{ __('finance_workbench.current_context') }}:</strong>
{{ __('finance_workbench.channel_'.$selectedContext['channel']) }} · {{ $selectedContext['code'] }} · {{ $selectedContext['name'] }}
<br>{{ __('finance_workbench.module_guard') }}
</section>

<nav class="finance-tabs" aria-label="{{ __('finance_workbench.title') }}">
@foreach($tabs as $tabItem)
@if($tabItem['available'])
<a class="finance-tab {{ $tab===$tabItem['key'] ? 'is-active' : '' }}" href="{{ route('admin.erp.finance.index',[...$routeBase,'tab'=>$tabItem['key']]) }}" @if($tab===$tabItem['key']) aria-current="page" @endif>{{ __($tabItem['label']) }}</a>
@else
<span class="finance-tab is-disabled" aria-disabled="true">{{ __($tabItem['label']) }}</span>
@endif
@endforeach
</nav>

@if($tab==='overview')
<section class="finance-metrics" aria-label="{{ __('finance_workbench.tabs.overview') }}">
@foreach(['accounts','journals','receivables_open','payables_open'] as $metric)
<article class="finance-card">
<span>{{ __('finance_workbench.metrics.'.$metric) }}</span>
<strong>{{ $data['metrics'][$metric]===null ? __('finance_workbench.metrics.restricted') : number_format($data['metrics'][$metric]) }}</strong>
</article>
@endforeach
</section>
<section class="finance-subgrid">
<div class="finance-notice"><strong>{{ __('finance_workbench.source_truth') }}</strong></div>
<div class="finance-notice"><strong>{{ __('finance_workbench.maker_checker') }}</strong></div>
</section>
@endif

@if($tab==='accounts')
<section class="finance-panel">
<div class="finance-panel-head"><div><h2>{{ __('finance_workbench.accounts.title') }}</h2><p>{{ __('finance_workbench.accounts.help') }}</p></div></div>
<form method="get" action="{{ route('admin.erp.finance.index') }}" class="finance-filter">
<input type="hidden" name="store_id" value="{{ $storeId }}"><input type="hidden" name="tab" value="accounts">
<div class="finance-field"><label for="account-q">{{ __('finance_workbench.common.search') }}</label><input id="account-q" name="q" value="{{ $data['filters']['q'] ?? '' }}" maxlength="120"></div>
<div class="finance-field"><label for="account-type">{{ __('finance_workbench.common.type') }}</label><select id="account-type" name="type"><option value=""></option>@foreach(['asset','liability','equity','revenue','expense'] as $type)<option value="{{ $type }}" @selected(($data['filters']['type'] ?? '')===$type)>{{ __('finance_workbench.account_types.'.$type) }}</option>@endforeach</select></div>
<div class="finance-field"><label for="account-status">{{ __('finance_workbench.common.status') }}</label><select id="account-status" name="status"><option value=""></option>@foreach(['active','inactive'] as $value)<option value="{{ $value }}" @selected(($data['filters']['status'] ?? '')===$value)>{{ $status($value) }}</option>@endforeach</select></div>
<button class="finance-button" type="submit">{{ __('finance_workbench.actions.filter') }}</button>
</form>
@if($access['foundation_manage'])
<details class="finance-details">
<summary>{{ __('finance_workbench.accounts.new') }}</summary>
<div class="finance-details-body">
<form method="post" action="{{ route('admin.erp.finance.accounts.store') }}" class="finance-form-grid">
@csrf<input type="hidden" name="store_id" value="{{ $storeId }}">
<div class="finance-field"><label>{{ __('finance_workbench.common.code') }}</label><input name="code" required maxlength="64" autocomplete="off"></div>
<div class="finance-field"><label>{{ __('finance_workbench.common.name') }}</label><input name="name" required maxlength="190"></div>
<div class="finance-field"><label>{{ __('finance_workbench.common.type') }}</label><select name="type" required>@foreach(['asset','liability','equity','revenue','expense'] as $type)<option value="{{ $type }}">{{ __('finance_workbench.account_types.'.$type) }}</option>@endforeach</select></div>
<div class="finance-field"><label>{{ __('finance_workbench.common.normal_balance') }}</label><select name="normal_balance" required>@foreach(['debit','credit'] as $value)<option value="{{ $value }}">{{ __('finance_workbench.normal_balances.'.$value) }}</option>@endforeach</select></div>
<div class="finance-field"><label>{{ __('finance_workbench.common.parent') }}</label><select name="parent_id"><option value="">{{ __('finance_workbench.common.optional') }}</option>@foreach($data['parents'] as $parent)<option value="{{ $parent->id }}">{{ $parent->code }} · {{ $parent->name }}</option>@endforeach</select></div>
<div class="finance-check"><input id="account-control" type="checkbox" name="is_control" value="1"><label for="account-control">{{ __('finance_workbench.common.control') }}</label></div>
<div class="finance-check"><input id="account-posting" type="checkbox" name="allow_posting" value="1" checked><label for="account-posting">{{ __('finance_workbench.common.allow_posting') }}</label></div>
<button class="finance-button primary" type="submit">{{ __('finance_workbench.actions.create') }}</button>
</form>
</div>
</details>
@endif
<div class="finance-table-wrap"><table class="finance-table"><thead><tr><th>{{ __('finance_workbench.common.code') }}</th><th>{{ __('finance_workbench.common.name') }}</th><th>{{ __('finance_workbench.common.type') }}</th><th>{{ __('finance_workbench.common.normal_balance') }}</th><th>{{ __('finance_workbench.common.posting') }}</th><th>{{ __('finance_workbench.common.status') }}</th>@if($access['foundation_manage'])<th>{{ __('finance_workbench.actions.save') }}</th>@endif</tr></thead><tbody>
@forelse($data['accounts'] as $account)
<tr><td><strong>{{ $account->code }}</strong></td><td>{{ $account->name }}</td><td>{{ __('finance_workbench.account_types.'.$account->type) }}</td><td>{{ __('finance_workbench.normal_balances.'.$account->normal_balance) }}</td><td>{{ $account->is_control ? __('finance_workbench.common.control') : ($account->allow_posting ? '✓' : '—') }}</td><td><span class="finance-status">{{ $status($account->status) }}</span></td>
@if($access['foundation_manage'])<td><form method="post" action="{{ route('admin.erp.finance.accounts.status',['account'=>$account->id]) }}">@csrf @method('PATCH')<input type="hidden" name="store_id" value="{{ $storeId }}"><input type="hidden" name="status" value="{{ $account->status==='active' ? 'inactive' : 'active' }}"><button class="finance-button" type="submit">{{ $account->status==='active' ? __('finance_workbench.actions.deactivate') : __('finance_workbench.actions.activate') }}</button></form></td>@endif
</tr>
@empty<tr><td colspan="7" class="finance-empty">{{ __('finance_workbench.common.no_data') }}</td></tr>@endforelse
</tbody></table></div>
<div class="finance-pagination">{{ $data['accounts']->links() }}</div>
</section>
@endif

@if($tab==='cost_centers')
<section class="finance-panel">
<div class="finance-panel-head"><div><h2>{{ __('finance_workbench.cost_centers.title') }}</h2><p>{{ __('finance_workbench.cost_centers.help') }}</p></div></div>
<form method="get" action="{{ route('admin.erp.finance.index') }}" class="finance-filter">
<input type="hidden" name="store_id" value="{{ $storeId }}"><input type="hidden" name="tab" value="cost_centers">
<div class="finance-field"><label>{{ __('finance_workbench.common.search') }}</label><input name="q" value="{{ $data['filters']['q'] ?? '' }}" maxlength="120"></div>
<div class="finance-field"><label>{{ __('finance_workbench.common.status') }}</label><select name="status"><option value=""></option>@foreach(['active','inactive'] as $value)<option value="{{ $value }}" @selected(($data['filters']['status'] ?? '')===$value)>{{ $status($value) }}</option>@endforeach</select></div>
<button class="finance-button" type="submit">{{ __('finance_workbench.actions.filter') }}</button>
</form>
@if($access['foundation_manage'])
<details class="finance-details"><summary>{{ __('finance_workbench.cost_centers.new') }}</summary><div class="finance-details-body">
<form method="post" action="{{ route('admin.erp.finance.cost-centers.store') }}" class="finance-form-grid">
@csrf<input type="hidden" name="store_id" value="{{ $storeId }}">
<div class="finance-field"><label>{{ __('finance_workbench.common.code') }}</label><input name="code" required maxlength="64"></div>
<div class="finance-field"><label>{{ __('finance_workbench.common.name') }}</label><input name="name" required maxlength="190"></div>
<div class="finance-field"><label>{{ __('finance_workbench.common.parent') }}</label><select name="parent_id"><option value="">{{ __('finance_workbench.common.optional') }}</option>@foreach($data['parents'] as $parent)<option value="{{ $parent->id }}">{{ $parent->code }} · {{ $parent->name }}</option>@endforeach</select></div>
<button class="finance-button primary" type="submit">{{ __('finance_workbench.actions.create') }}</button>
</form></div></details>
@endif
<div class="finance-table-wrap"><table class="finance-table"><thead><tr><th>{{ __('finance_workbench.common.code') }}</th><th>{{ __('finance_workbench.common.name') }}</th><th>{{ __('finance_workbench.common.status') }}</th>@if($access['foundation_manage'])<th>{{ __('finance_workbench.actions.save') }}</th>@endif</tr></thead><tbody>
@forelse($data['costCenters'] as $center)
<tr><td><strong>{{ $center->code }}</strong></td><td>{{ $center->name }}</td><td><span class="finance-status">{{ __('finance_workbench.statuses.'.strtolower((string) $center->status)) }}</span> {{-- localization-gate: allow status rendered through localized $status helper --}}</td>
@if($access['foundation_manage'])<td><form method="post" action="{{ route('admin.erp.finance.cost-centers.status',['costCenter'=>$center->id]) }}">@csrf @method('PATCH')<input type="hidden" name="store_id" value="{{ $storeId }}"><input type="hidden" name="status" value="{{ $center->status==='active' ? 'inactive' : 'active' }}"><button class="finance-button" type="submit">{{ $center->status==='active' ? __('finance_workbench.actions.deactivate') : __('finance_workbench.actions.activate') }}</button></form></td>@endif
</tr>
@empty<tr><td colspan="4" class="finance-empty">{{ __('finance_workbench.common.no_data') }}</td></tr>@endforelse
</tbody></table></div>
<div class="finance-pagination">{{ $data['costCenters']->links() }}</div>
</section>
@endif

@if($tab==='periods')
<section class="finance-panel">
<div class="finance-panel-head"><div><h2>{{ __('finance_workbench.periods.title') }}</h2><p>{{ __('finance_workbench.periods.help') }}</p></div></div>
@if($access['foundation_manage'])
<details class="finance-details"><summary>{{ __('finance_workbench.periods.new_year') }}</summary><div class="finance-details-body">
<form method="post" action="{{ route('admin.erp.finance.fiscal-years.store') }}" class="finance-form-grid">
@csrf<input type="hidden" name="store_id" value="{{ $storeId }}">
<div class="finance-field"><label>{{ __('finance_workbench.common.code') }}</label><input name="code" required maxlength="64"></div>
<div class="finance-field"><label>{{ __('finance_workbench.common.name') }}</label><input name="name" required maxlength="190"></div>
<div class="finance-field"><label>{{ __('finance_workbench.common.starts_on') }}</label><input type="date" name="starts_on" required></div>
<div class="finance-field"><label>{{ __('finance_workbench.common.ends_on') }}</label><input type="date" name="ends_on" required></div>
<button class="finance-button primary" type="submit">{{ __('finance_workbench.actions.create') }}</button>
</form></div></details>
@endif
<h3>{{ __('finance_workbench.periods.years') }}</h3>
<div class="finance-table-wrap"><table class="finance-table"><thead><tr><th>{{ __('finance_workbench.common.code') }}</th><th>{{ __('finance_workbench.common.name') }}</th><th>{{ __('finance_workbench.common.from') }}</th><th>{{ __('finance_workbench.common.to') }}</th><th>{{ __('finance_workbench.common.status') }}</th>@if($access['foundation_manage'])<th>{{ __('finance_workbench.actions.save') }}</th>@endif</tr></thead><tbody>
@forelse($data['fiscalYears'] as $year)
<tr><td><strong>{{ $year->code }}</strong></td><td>{{ $year->name }}
@if($access['foundation_manage'] && $year->status==='open')
<details class="finance-details"><summary>{{ __('finance_workbench.periods.new_period') }}</summary><div class="finance-details-body">
<form method="post" action="{{ route('admin.erp.finance.fiscal-periods.store',['fiscalYear'=>$year->id]) }}" class="finance-form-grid">
@csrf<input type="hidden" name="store_id" value="{{ $storeId }}">
<div class="finance-field"><label>{{ __('finance_workbench.common.code') }}</label><input name="code" required maxlength="64"></div>
<div class="finance-field"><label>{{ __('finance_workbench.common.name') }}</label><input name="name" required maxlength="190"></div>
<div class="finance-field"><label>{{ __('finance_workbench.common.sequence') }}</label><input type="number" name="sequence" min="1" max="366" required></div>
<div class="finance-field"><label>{{ __('finance_workbench.common.starts_on') }}</label><input type="date" name="starts_on" required></div>
<div class="finance-field"><label>{{ __('finance_workbench.common.ends_on') }}</label><input type="date" name="ends_on" required></div>
<button class="finance-button primary" type="submit">{{ __('finance_workbench.actions.create') }}</button>
</form></div></details>
@endif
</td><td>{{ optional($year->starts_on)->format('Y-m-d') }}</td><td>{{ optional($year->ends_on)->format('Y-m-d') }}</td><td><span class="finance-status">{{ __('finance_workbench.statuses.'.strtolower((string) $year->status)) }}</span> {{-- localization-gate: allow status rendered through localized $status helper --}}</td>
@if($access['foundation_manage'])<td><form method="post" action="{{ route('admin.erp.finance.fiscal-years.state',['fiscalYear'=>$year->id]) }}">@csrf @method('PATCH')<input type="hidden" name="store_id" value="{{ $storeId }}"><input type="hidden" name="state" value="{{ $year->status==='open' ? 'closed' : 'open' }}"><button class="finance-button" type="submit">{{ $year->status==='open' ? __('finance_workbench.actions.close') : __('finance_workbench.actions.reopen') }}</button></form></td>@endif
</tr>
@empty<tr><td colspan="6" class="finance-empty">{{ __('finance_workbench.common.no_data') }}</td></tr>@endforelse
</tbody></table></div>
<div class="finance-pagination">{{ $data['fiscalYears']->links() }}</div>

<h3>{{ __('finance_workbench.periods.period_list') }}</h3>
<div class="finance-table-wrap"><table class="finance-table"><thead><tr><th>{{ __('finance_workbench.common.code') }}</th><th>{{ __('finance_workbench.common.name') }}</th><th>{{ __('finance_workbench.common.sequence') }}</th><th>{{ __('finance_workbench.common.from') }}</th><th>{{ __('finance_workbench.common.to') }}</th><th>{{ __('finance_workbench.common.status') }}</th>@if($access['foundation_manage'])<th>{{ __('finance_workbench.actions.save') }}</th>@endif</tr></thead><tbody>
@forelse($data['fiscalPeriods'] as $period)
<tr><td><strong>{{ $period->code }}</strong></td><td>{{ $period->name }}</td><td>{{ $period->sequence }}</td><td>{{ optional($period->starts_on)->format('Y-m-d') }}</td><td>{{ optional($period->ends_on)->format('Y-m-d') }}</td><td><span class="finance-status">{{ __('finance_workbench.statuses.'.strtolower((string) $period->status)) }}</span> {{-- localization-gate: allow status rendered through localized $status helper --}}</td>
@if($access['foundation_manage'])<td><form method="post" action="{{ route('admin.erp.finance.fiscal-periods.state',['fiscalPeriod'=>$period->id]) }}">@csrf @method('PATCH')<input type="hidden" name="store_id" value="{{ $storeId }}"><input type="hidden" name="state" value="{{ $period->status==='open' ? 'closed' : 'open' }}"><button class="finance-button" type="submit">{{ $period->status==='open' ? __('finance_workbench.actions.close') : __('finance_workbench.actions.reopen') }}</button></form></td>@endif
</tr>
@empty<tr><td colspan="7" class="finance-empty">{{ __('finance_workbench.common.no_data') }}</td></tr>@endforelse
</tbody></table></div>
<div class="finance-pagination">{{ $data['fiscalPeriods']->links() }}</div>
</section>
@endif

@if($tab==='journals')
<section class="finance-panel">
<div class="finance-panel-head"><div><h2>{{ __('finance_workbench.journals.title') }}</h2><p>{{ __('finance_workbench.journals.help') }}</p></div></div>
<div class="finance-notice">{{ __('finance_workbench.maker_checker') }}</div>
@if($access['gl_manage'])
<details class="finance-details"><summary>{{ __('finance_workbench.journals.new') }}</summary><div class="finance-details-body">
<form method="post" action="{{ route('admin.erp.finance.journals.store') }}">
@csrf<input type="hidden" name="store_id" value="{{ $storeId }}">
<div class="finance-form-grid">
<div class="finance-field"><label>{{ __('finance_workbench.common.reference') }}</label><input name="idempotency_key" required maxlength="128" value="manual-{{ now()->format('Ymd-His') }}"></div>
<div class="finance-field"><label>{{ __('finance_workbench.common.date') }}</label><input type="date" name="entry_date" required value="{{ now()->toDateString() }}"></div>
<div class="finance-field" style="grid-column:span 2"><label>{{ __('finance_workbench.common.description') }}</label><input name="description" maxlength="255"></div>
</div>
<div class="finance-lines">
@foreach([0,1] as $line)
<div class="finance-line"><h4>{{ __('finance_workbench.journals.line_'.($line+1)) }}</h4>
<div class="finance-field"><label>{{ __('finance_workbench.statements.account') }}</label><select name="lines[{{ $line }}][account_id]" required><option value=""></option>@foreach($data['postingAccounts'] as $account)<option value="{{ $account->id }}">{{ $account->code }} · {{ $account->name }}</option>@endforeach</select></div>
<div class="finance-form-grid" style="grid-template-columns:1fr 1fr"><div class="finance-field"><label>{{ __('finance_workbench.common.debit') }}</label><input inputmode="decimal" name="lines[{{ $line }}][debit]" value="{{ $line===0 ? '1.000' : '0.000' }}"></div><div class="finance-field"><label>{{ __('finance_workbench.common.credit') }}</label><input inputmode="decimal" name="lines[{{ $line }}][credit]" value="{{ $line===1 ? '1.000' : '0.000' }}"></div></div>
<div class="finance-field"><label>{{ __('finance_workbench.common.memo') }}</label><input name="lines[{{ $line }}][memo]" maxlength="255"></div>
</div>
@endforeach
</div>
<button class="finance-button primary" type="submit">{{ __('finance_workbench.actions.create') }}</button>
</form>
</div></details>
@endif
<div class="finance-table-wrap"><table class="finance-table"><thead><tr><th>{{ __('finance_workbench.journals.number') }}</th><th>{{ __('finance_workbench.common.date') }}</th><th>{{ __('finance_workbench.common.description') }}</th><th>{{ __('finance_workbench.common.debit') }}</th><th>{{ __('finance_workbench.common.credit') }}</th><th>{{ __('finance_workbench.common.status') }}</th><th>{{ __('finance_workbench.actions.view') }}</th></tr></thead><tbody>
@forelse($data['journals'] as $journal)
<tr><td><strong>{{ $journal->journal_number }}</strong></td><td>{{ optional($journal->entry_date)->format('Y-m-d') }}</td><td>{{ $journal->description ?: '—' }}</td><td class="finance-money">{{ $money($journal->total_debit) }}</td><td class="finance-money">{{ $money($journal->total_credit) }}</td><td><span class="finance-status">{{ __('finance_workbench.statuses.'.strtolower((string) $journal->status)) }}</span></td><td><a class="finance-button" href="{{ route('admin.erp.finance.index',[...$routeBase,'tab'=>'journals','journal'=>$journal->id]) }}">{{ __('finance_workbench.actions.view') }}</a></td></tr>
@empty<tr><td colspan="7" class="finance-empty">{{ __('finance_workbench.common.no_data') }}</td></tr>@endforelse
</tbody></table></div>
<div class="finance-pagination">{{ $data['journals']->links() }}</div>
</section>

@if($data['selectedJournal'])
<section class="finance-panel" id="journal-detail">
<div class="finance-panel-head"><div><h3>{{ __('finance_workbench.journals.detail') }} · {{ $data['selectedJournal']->journal_number }}</h3><p>{{ __('finance_workbench.journals.source_trace') }}</p></div><span class="finance-status">{{ __('finance_workbench.statuses.'.strtolower((string) $data['selectedJournal']->status)) }}</span></div>
<div class="finance-source-trace">
<div class="finance-source-step"><strong>{{ __('finance_workbench.common.source') }}</strong>@if($data['sourcePosting']){{ $data['sourcePosting']->source_reference ?: '—' }}<br><span class="finance-muted">{{ optional($data['sourcePosting']->occurred_at)->format('Y-m-d H:i') }}</span>@else<span class="finance-muted">{{ __('finance_workbench.journals.no_source') }}</span>@endif</div>
<div class="finance-source-step"><strong>{{ __('finance_workbench.common.journal') }}</strong>{{ $data['selectedJournal']->journal_number }}<br><span class="finance-muted">{{ $data['selectedJournal']->fiscalPeriod?->code ?? '—' }}</span></div>
<div class="finance-source-step"><strong>{{ __('finance_workbench.journals.ledger_entries') }}</strong>{{ number_format($data['ledgerEntries']->count()) }}</div>
</div>
<dl class="finance-kv">
<div><dt>{{ __('finance_workbench.common.date') }}</dt><dd>{{ optional($data['selectedJournal']->entry_date)->format('Y-m-d') }}</dd></div>
<div><dt>{{ __('finance_workbench.common.debit') }}</dt><dd class="finance-money">{{ $money($data['selectedJournal']->total_debit) }}</dd></div>
<div><dt>{{ __('finance_workbench.common.credit') }}</dt><dd class="finance-money">{{ $money($data['selectedJournal']->total_credit) }}</dd></div>
</dl>
<div class="finance-inline-actions" style="margin-top:14px">
@if($data['selectedJournal']->status==='draft' && $access['approval_request'])
<form method="post" action="{{ route('admin.erp.finance.journals.approval',['journal'=>$data['selectedJournal']->id]) }}">@csrf<input type="hidden" name="store_id" value="{{ $storeId }}"><button class="finance-button" type="submit">{{ __('finance_workbench.actions.request_approval') }}</button></form>
@endif
@if($data['selectedJournal']->status==='draft' && $access['gl_post'] && (int)$data['selectedJournal']->requested_by !== (int)$user->id)
<form method="post" action="{{ route('admin.erp.finance.journals.post',['journal'=>$data['selectedJournal']->id]) }}">@csrf<input type="hidden" name="store_id" value="{{ $storeId }}"><button class="finance-button primary" type="submit">{{ __('finance_workbench.actions.post') }}</button></form>
@endif
<a class="finance-button" href="{{ route('admin.action-center.index') }}">{{ __('finance_workbench.actions.open_action_center') }}</a>
</div>
<h3>{{ __('finance_workbench.journals.approval') }}</h3>
@if($data['approval'])
<div class="finance-notice"><span class="finance-status">{{ __('finance_workbench.statuses.'.strtolower((string) $data['approval']->status)) }}</span> {{-- localization-gate: allow status rendered through localized $status helper --}} · {{ optional($data['approval']->created_at)->format('Y-m-d H:i') }}</div>
@else<div class="finance-empty">{{ __('finance_workbench.journals.no_approval') }}</div>@endif

<h3>{{ __('finance_workbench.journals.ledger_entries') }}</h3>
<div class="finance-table-wrap"><table class="finance-table"><thead><tr><th>{{ __('finance_workbench.statements.account') }}</th><th>{{ __('finance_workbench.common.date') }}</th><th>{{ __('finance_workbench.common.debit') }}</th><th>{{ __('finance_workbench.common.credit') }}</th></tr></thead><tbody>
@forelse($data['ledgerEntries'] as $entry)<tr><td>{{ $entry->account?->code }} · {{ $entry->account?->name }}</td><td>{{ optional($entry->entry_date)->format('Y-m-d') }}</td><td class="finance-money">{{ $money($entry->debit) }}</td><td class="finance-money">{{ $money($entry->credit) }}</td></tr>
@empty<tr><td colspan="4" class="finance-empty">{{ __('finance_workbench.journals.no_ledger') }}</td></tr>@endforelse
</tbody></table></div>

<h3>{{ __('finance_workbench.journals.audit_timeline') }}</h3>
<div class="finance-table-wrap"><table class="finance-table"><thead><tr><th>{{ __('finance_workbench.common.date') }}</th><th>{{ __('finance_workbench.common.status') }}</th></tr></thead><tbody>
@forelse($data['audit'] as $auditRow)<tr><td>{{ optional($auditRow->created_at)->format('Y-m-d H:i') }}</td><td>{{ __('finance_workbench.journals.accounting_change') }}</td></tr>
@empty<tr><td colspan="2" class="finance-empty">{{ __('finance_workbench.common.no_data') }}</td></tr>@endforelse
</tbody></table></div>
</section>
@endif
@endif

@if($tab==='opening_balances')
<section class="finance-panel">
<div class="finance-panel-head"><div><h2>{{ __('finance_workbench.opening.title') }}</h2><p>{{ __('finance_workbench.opening.help') }}</p></div></div>
@if($access['opening_manage'])
<details class="finance-details"><summary>{{ __('finance_workbench.opening.new') }}</summary><div class="finance-details-body">
<form method="post" action="{{ route('admin.erp.finance.opening-balances.store') }}">
@csrf<input type="hidden" name="store_id" value="{{ $storeId }}">
<div class="finance-form-grid">
<div class="finance-field"><label>{{ __('finance_workbench.common.reference') }}</label><input name="idempotency_key" required maxlength="128" value="opening-{{ now()->format('Ymd-His') }}"></div>
<div class="finance-field"><label>{{ __('finance_workbench.common.period') }}</label><select name="fiscal_period_id" required><option value=""></option>@foreach($data['periodOptions'] as $period)<option value="{{ $period->id }}">{{ $period->code }} · {{ $period->name }}</option>@endforeach</select></div>
<div class="finance-field"><label>{{ __('finance_workbench.common.date') }}</label><input type="date" name="entry_date" required value="{{ now()->toDateString() }}"></div>
<div class="finance-field"><label>{{ __('finance_workbench.common.description') }}</label><input name="description" maxlength="255"></div>
</div>
<div class="finance-lines">
@foreach([0,1] as $line)
<div class="finance-line"><h4>{{ __('finance_workbench.journals.line_'.($line+1)) }}</h4>
<div class="finance-field"><label>{{ __('finance_workbench.statements.account') }}</label><select name="lines[{{ $line }}][account_id]" required><option value=""></option>@foreach($data['postingAccounts'] as $account)<option value="{{ $account->id }}">{{ $account->code }} · {{ $account->name }}</option>@endforeach</select></div>
<div class="finance-field"><label>{{ __('finance_workbench.statements.cost_center') }}</label><select name="lines[{{ $line }}][cost_center_id]"><option value=""></option>@foreach($data['costCenterOptions'] as $center)<option value="{{ $center->id }}">{{ $center->code }} · {{ $center->name }}</option>@endforeach</select></div>
<div class="finance-form-grid" style="grid-template-columns:1fr 1fr"><div class="finance-field"><label>{{ __('finance_workbench.common.debit') }}</label><input inputmode="decimal" name="lines[{{ $line }}][debit]" value="{{ $line===0 ? '1.000' : '0.000' }}"></div><div class="finance-field"><label>{{ __('finance_workbench.common.credit') }}</label><input inputmode="decimal" name="lines[{{ $line }}][credit]" value="{{ $line===1 ? '1.000' : '0.000' }}"></div></div>
<div class="finance-field"><label>{{ __('finance_workbench.common.memo') }}</label><input name="lines[{{ $line }}][memo]" maxlength="255"></div>
</div>
@endforeach
</div>
<button class="finance-button primary" type="submit">{{ __('finance_workbench.actions.create') }}</button>
</form></div></details>
@endif
<div class="finance-table-wrap"><table class="finance-table"><thead><tr><th>{{ __('finance_workbench.opening.number') }}</th><th>{{ __('finance_workbench.common.date') }}</th><th>{{ __('finance_workbench.common.debit') }}</th><th>{{ __('finance_workbench.common.credit') }}</th><th>{{ __('finance_workbench.common.status') }}</th><th>{{ __('finance_workbench.actions.view') }}</th></tr></thead><tbody>
@forelse($data['batches'] as $batch)<tr><td><strong>{{ $batch->batch_number }}</strong></td><td>{{ optional($batch->entry_date)->format('Y-m-d') }}</td><td class="finance-money">{{ $money($batch->total_debit) }}</td><td class="finance-money">{{ $money($batch->total_credit) }}</td><td><span class="finance-status">{{ $status($batch->status) }}</span></td><td><a class="finance-button" href="{{ route('admin.erp.finance.index',[...$routeBase,'tab'=>'opening_balances','batch'=>$batch->id]) }}">{{ __('finance_workbench.actions.view') }}</a></td></tr>
@empty<tr><td colspan="6" class="finance-empty">{{ __('finance_workbench.common.no_data') }}</td></tr>@endforelse
</tbody></table></div>
<div class="finance-pagination">{{ $data['batches']->links() }}</div>
</section>
@if($data['selectedBatch'])
<section class="finance-panel"><div class="finance-panel-head"><div><h3>{{ __('finance_workbench.opening.detail') }} · {{ $data['selectedBatch']->batch_number }}</h3></div><span class="finance-status">{{ $status($data['selectedBatch']->status) }}</span></div>
<div class="finance-inline-actions">
@if($data['selectedBatch']->status==='draft' && $access['approval_request'])
<form method="post" action="{{ route('admin.erp.finance.opening-balances.approval',['batch'=>$data['selectedBatch']->id]) }}">@csrf<input type="hidden" name="store_id" value="{{ $storeId }}"><button class="finance-button" type="submit">{{ __('finance_workbench.actions.request_approval') }}</button></form>
@endif
@if($data['selectedBatch']->status==='draft' && $access['opening_post'] && (int)$data['selectedBatch']->created_by !== (int)$user->id)
<form method="post" action="{{ route('admin.erp.finance.opening-balances.post',['batch'=>$data['selectedBatch']->id]) }}">@csrf<input type="hidden" name="store_id" value="{{ $storeId }}"><button class="finance-button primary" type="submit">{{ __('finance_workbench.actions.post') }}</button></form>
@endif
@if($data['selectedBatch']->status==='draft' && $access['opening_manage'])
<form method="post" action="{{ route('admin.erp.finance.opening-balances.cancel',['batch'=>$data['selectedBatch']->id]) }}">@csrf<input type="hidden" name="store_id" value="{{ $storeId }}"><button class="finance-button danger" type="submit">{{ __('finance_workbench.actions.cancel') }}</button></form>
@endif
<a class="finance-button" href="{{ route('admin.action-center.index') }}">{{ __('finance_workbench.actions.open_action_center') }}</a>
</div>
@if($data['approval'])<div class="finance-notice"><strong>{{ __('finance_workbench.journals.approval') }}:</strong> <span class="finance-status">{{ __('finance_workbench.statuses.'.strtolower((string) $data['approval']->status)) }}</span></div>@endif
</section>
@endif
@endif

@if($tab==='receivables')
<section class="finance-panel">
<div class="finance-panel-head"><div><h2>{{ __('finance_workbench.receivables.title') }}</h2><p>{{ __('finance_workbench.receivables.help') }}</p></div></div>
<div class="finance-notice"><strong>{{ __('finance_workbench.source_truth') }}</strong></div>
<form method="get" action="{{ route('admin.erp.finance.index') }}" class="finance-filter"><input type="hidden" name="store_id" value="{{ $storeId }}"><input type="hidden" name="tab" value="receivables">
<div class="finance-field"><label>{{ __('finance_workbench.common.status') }}</label><select name="status"><option value=""></option>@foreach(['draft','posted','cancelled'] as $value)<option value="{{ $value }}" @selected(($data['filters']['status'] ?? '')===$value)>{{ $status($value) }}</option>@endforeach</select></div>
<div class="finance-field"><label>{{ __('finance_workbench.common.from') }}</label><input type="date" name="date_from" value="{{ $data['filters']['date_from'] ?? '' }}"></div><div class="finance-field"><label>{{ __('finance_workbench.common.to') }}</label><input type="date" name="date_to" value="{{ $data['filters']['date_to'] ?? '' }}"></div>
<button class="finance-button" type="submit">{{ __('finance_workbench.actions.filter') }}</button></form>
<div class="finance-table-wrap"><table class="finance-table"><thead><tr><th>{{ __('finance_workbench.receivables.document') }}</th><th>{{ __('finance_workbench.common.customer') }}</th><th>{{ __('finance_workbench.common.date') }}</th><th>{{ __('finance_workbench.common.due_date') }}</th><th>{{ __('finance_workbench.common.amount') }}</th><th>{{ __('finance_workbench.common.status') }}</th><th>{{ __('finance_workbench.actions.view_ledger') }}</th></tr></thead><tbody>
@forelse($data['documents'] as $document)<tr><td><strong>{{ $document->document_number }}</strong></td><td>{{ $document->customer?->name ?? '—' }}</td><td>{{ optional($document->entry_date)->format('Y-m-d') }}</td><td>{{ optional($document->due_date)->format('Y-m-d') }}</td><td class="finance-money">{{ $money($document->amount) }} {{ $document->currency_code }}</td><td><span class="finance-status">{{ $status($document->status) }}</span></td><td>@if($document->journal_id)<a class="finance-button" href="{{ route('admin.erp.finance.index',[...$routeBase,'tab'=>'journals','journal'=>$document->journal_id]) }}">{{ __('finance_workbench.actions.view_ledger') }}</a>@else—@endif</td></tr>
@empty<tr><td colspan="7" class="finance-empty">{{ __('finance_workbench.common.no_data') }}</td></tr>@endforelse
</tbody></table></div><div class="finance-pagination">{{ $data['documents']->links() }}</div>
<h3>{{ __('finance_workbench.common.aging_bucket') }}</h3>
<div class="finance-table-wrap"><table class="finance-table"><thead><tr><th>{{ __('finance_workbench.receivables.document') }}</th><th>{{ __('finance_workbench.common.due_date') }}</th><th>{{ __('finance_workbench.common.amount') }}</th><th>{{ __('finance_workbench.common.allocated') }}</th><th>{{ __('finance_workbench.common.outstanding') }}</th><th>{{ __('finance_workbench.common.aging_bucket') }}</th></tr></thead><tbody>
@forelse($data['aging'] as $row)<tr><td>{{ $row->document_number }}</td><td>{{ $row->due_date }}</td><td class="finance-money">{{ $money($row->amount) }} {{ $row->currency_code }}</td><td class="finance-money">{{ $money($row->allocated_amount) }}</td><td class="finance-money">{{ $money($row->outstanding_amount) }}</td><td><span class="finance-status">{{ $status($row->aging_bucket) }}</span></td></tr>
@empty<tr><td colspan="6" class="finance-empty">{{ __('finance_workbench.common.no_data') }}</td></tr>@endforelse
</tbody></table></div>
</section>
@endif

@if($tab==='payables')
<section class="finance-panel">
<div class="finance-panel-head"><div><h2>{{ __('finance_workbench.payables.title') }}</h2><p>{{ __('finance_workbench.payables.help') }}</p></div></div>
<div class="finance-notice"><strong>{{ __('finance_workbench.source_truth') }}</strong></div>
<form method="get" action="{{ route('admin.erp.finance.index') }}" class="finance-filter"><input type="hidden" name="store_id" value="{{ $storeId }}"><input type="hidden" name="tab" value="payables">
<div class="finance-field"><label>{{ __('finance_workbench.payables.supplier_reference') }}</label><input name="supplier_reference" value="{{ $data['filters']['supplier_reference'] ?? '' }}" maxlength="128"></div>
<div class="finance-field"><label>{{ __('finance_workbench.common.status') }}</label><select name="status"><option value=""></option>@foreach(['draft','posted','cancelled'] as $value)<option value="{{ $value }}" @selected(($data['filters']['status'] ?? '')===$value)>{{ $status($value) }}</option>@endforeach</select></div>
<div class="finance-field"><label>{{ __('finance_workbench.common.from') }}</label><input type="date" name="date_from" value="{{ $data['filters']['date_from'] ?? '' }}"></div><div class="finance-field"><label>{{ __('finance_workbench.common.to') }}</label><input type="date" name="date_to" value="{{ $data['filters']['date_to'] ?? '' }}"></div>
<button class="finance-button" type="submit">{{ __('finance_workbench.actions.filter') }}</button></form>
<div class="finance-table-wrap"><table class="finance-table"><thead><tr><th>{{ __('finance_workbench.payables.document') }}</th><th>{{ __('finance_workbench.common.supplier') }}</th><th>{{ __('finance_workbench.common.date') }}</th><th>{{ __('finance_workbench.common.due_date') }}</th><th>{{ __('finance_workbench.common.amount') }}</th><th>{{ __('finance_workbench.common.status') }}</th><th>{{ __('finance_workbench.actions.view_ledger') }}</th></tr></thead><tbody>
@forelse($data['documents'] as $document)<tr><td><strong>{{ $document->document_number }}</strong></td><td>{{ $document->supplier_name ?: $document->supplier_reference }}</td><td>{{ optional($document->entry_date)->format('Y-m-d') }}</td><td>{{ optional($document->due_date)->format('Y-m-d') }}</td><td class="finance-money">{{ $money($document->amount) }} {{ $document->currency_code }}</td><td><span class="finance-status">{{ $status($document->status) }}</span></td><td>@if($document->journal_id)<a class="finance-button" href="{{ route('admin.erp.finance.index',[...$routeBase,'tab'=>'journals','journal'=>$document->journal_id]) }}">{{ __('finance_workbench.actions.view_ledger') }}</a>@else—@endif</td></tr>
@empty<tr><td colspan="7" class="finance-empty">{{ __('finance_workbench.common.no_data') }}</td></tr>@endforelse
</tbody></table></div><div class="finance-pagination">{{ $data['documents']->links() }}</div>
<h3>{{ __('finance_workbench.common.aging_bucket') }}</h3>
<div class="finance-table-wrap"><table class="finance-table"><thead><tr><th>{{ __('finance_workbench.payables.document') }}</th><th>{{ __('finance_workbench.common.supplier') }}</th><th>{{ __('finance_workbench.common.due_date') }}</th><th>{{ __('finance_workbench.common.outstanding') }}</th><th>{{ __('finance_workbench.common.aging_bucket') }}</th></tr></thead><tbody>
@forelse($data['aging'] as $row)<tr><td>{{ $row->document_number }}</td><td>{{ $row->supplier_name ?: $row->supplier_reference }}</td><td>{{ $row->due_date }}</td><td class="finance-money">{{ $money($row->outstanding_amount) }} {{ $row->currency_code }}</td><td><span class="finance-status">{{ $status($row->aging_bucket) }}</span></td></tr>
@empty<tr><td colspan="5" class="finance-empty">{{ __('finance_workbench.common.no_data') }}</td></tr>@endforelse
</tbody></table></div>
</section>
@endif

@if($tab==='credit_terms')
<section class="finance-panel">
<div class="finance-panel-head"><div><h2>{{ __('finance_workbench.credit_terms.title') }}</h2><p>{{ __('finance_workbench.credit_terms.help') }}</p></div></div>
@if($access['credit_manage'])
<details class="finance-details"><summary>{{ __('finance_workbench.credit_terms.new') }}</summary><div class="finance-details-body">
<form method="post" action="{{ route('admin.erp.finance.credit-terms.store') }}" class="finance-form-grid">
@csrf<input type="hidden" name="store_id" value="{{ $storeId }}">
<div class="finance-field"><label>{{ __('finance_workbench.credit_terms.subject_type') }}</label><select name="subject_type" required>@foreach(['customer','supplier'] as $value)<option value="{{ $value }}">{{ __('finance_workbench.subject_types.'.$value) }}</option>@endforeach</select></div>
<div class="finance-field"><label>{{ __('finance_workbench.credit_terms.subject_reference') }}</label><input name="subject_reference" required maxlength="128"></div>
<div class="finance-field"><label>{{ __('finance_workbench.common.credit_limit') }}</label><input inputmode="decimal" name="credit_limit"></div>
<div class="finance-field"><label>{{ __('finance_workbench.common.currency') }}</label><input name="currency_code" value="KWD" required maxlength="3"></div>
<div class="finance-field"><label>{{ __('finance_workbench.common.payment_terms_days') }}</label><input type="number" name="payment_terms_days" min="0" max="3650" required value="30"></div>
<div class="finance-field"><label>{{ __('finance_workbench.common.valid_from') }}</label><input type="date" name="valid_from" required value="{{ now()->toDateString() }}"></div>
<div class="finance-field"><label>{{ __('finance_workbench.common.valid_until') }}</label><input type="date" name="valid_until"></div>
<button class="finance-button primary" type="submit">{{ __('finance_workbench.actions.create') }}</button>
</form></div></details>
@endif
<div class="finance-table-wrap"><table class="finance-table"><thead><tr><th>{{ __('finance_workbench.common.subject') }}</th><th>{{ __('finance_workbench.common.credit_limit') }}</th><th>{{ __('finance_workbench.common.payment_terms_days') }}</th><th>{{ __('finance_workbench.common.valid_from') }}</th><th>{{ __('finance_workbench.common.valid_until') }}</th><th>{{ __('finance_workbench.common.status') }}</th>@if($access['credit_manage'])<th>{{ __('finance_workbench.actions.save') }}</th>@endif</tr></thead><tbody>
@forelse($data['policies'] as $policy)<tr><td>{{ __('finance_workbench.subject_types.'.$policy->subject_type) }} · {{ $policy->subject_reference }}</td><td class="finance-money">{{ $policy->credit_limit===null ? '—' : $money($policy->credit_limit).' '.$policy->currency_code }}</td><td>{{ $policy->payment_terms_days }}</td><td>{{ optional($policy->valid_from)->format('Y-m-d') }}</td><td>{{ optional($policy->valid_until)->format('Y-m-d') ?: '—' }}</td><td><span class="finance-status">{{ $status($policy->status) }}</span></td>
@if($access['credit_manage'])<td><form method="post" action="{{ route('admin.erp.finance.credit-terms.status',['creditTerm'=>$policy->id]) }}">@csrf @method('PATCH')<input type="hidden" name="store_id" value="{{ $storeId }}"><input type="hidden" name="status" value="{{ $policy->status==='active' ? 'inactive' : 'active' }}"><button class="finance-button" type="submit">{{ $policy->status==='active' ? __('finance_workbench.actions.deactivate') : __('finance_workbench.actions.activate') }}</button></form></td>@endif
</tr>
@empty<tr><td colspan="7" class="finance-empty">{{ __('finance_workbench.common.no_data') }}</td></tr>@endforelse
</tbody></table></div><div class="finance-pagination">{{ $data['policies']->links() }}</div>
</section>
@endif

@if($tab==='statements')
<section class="finance-panel">
<div class="finance-panel-head"><div><h2>{{ __('finance_workbench.statements.title') }}</h2><p>{{ __('finance_workbench.statements.help') }}</p></div></div>
<form method="get" action="{{ route('admin.erp.finance.index') }}" class="finance-form-grid">
<input type="hidden" name="store_id" value="{{ $storeId }}"><input type="hidden" name="tab" value="statements">
<div class="finance-field"><label>{{ __('finance_workbench.statements.statement') }}</label><select name="statement">@foreach(['trial_balance','profit_and_loss','balance_sheet'] as $value)<option value="{{ $value }}" @selected($data['statementType']===$value)>{{ __('finance_workbench.statements.'.$value) }}</option>@endforeach</select></div>
<div class="finance-field"><label>{{ __('finance_workbench.common.period') }}</label><select name="period_id" required><option value=""></option>@foreach($data['periods'] as $period)<option value="{{ $period->id }}" @selected($data['periodId']===$period->id)>{{ $period->code }} · {{ $period->name }}</option>@endforeach</select></div>
<div class="finance-field"><label>{{ __('finance_workbench.statements.comparative_period') }}</label><select name="comparative_period_id"><option value=""></option>@foreach($data['periods'] as $period)<option value="{{ $period->id }}" @selected($data['comparativePeriodId']===$period->id)>{{ $period->code }} · {{ $period->name }}</option>@endforeach</select></div>
<div class="finance-field"><label>{{ __('finance_workbench.statements.cost_center') }}</label><select name="cost_center_id"><option value=""></option>@foreach($data['costCenters'] as $center)<option value="{{ $center->id }}" @selected(request('cost_center_id')==$center->id)>{{ $center->code }} · {{ $center->name }}</option>@endforeach</select></div>
<button class="finance-button primary" type="submit">{{ __('finance_workbench.actions.run_statement') }}</button>
</form>
@if($data['statement'])
<div class="finance-notice"><strong>{{ __('finance_workbench.statements.'.$data['statementType']) }}</strong>@if(array_key_exists('reconciled',$data['statement'])) · {{ __('finance_workbench.statements.reconciled') }}: {{ $data['statement']['reconciled'] ? '✓' : '—' }}@endif</div>
<div class="finance-table-wrap"><table class="finance-table"><thead><tr><th>{{ __('finance_workbench.common.code') }}</th><th>{{ __('finance_workbench.statements.account') }}</th><th>{{ __('finance_workbench.common.type') }}</th><th>{{ __('finance_workbench.common.debit') }}</th><th>{{ __('finance_workbench.common.credit') }}</th><th>{{ __('finance_workbench.statements.balance') }}</th><th>{{ __('finance_workbench.statements.comparative') }}</th></tr></thead><tbody>
@forelse($data['statement']['lines'] as $line)
<tr><td>{{ $line['code'] ?? '—' }}</td><td>{{ ($line['code'] ?? '')==='CURRENT-EARNINGS' ? __('finance_workbench.statements.current_year_earnings') : ($line['name'] ?? '—') }}</td><td>{{ isset($line['type']) ? __('finance_workbench.account_types.'.$line['type']) : '—' }}</td><td class="finance-money">{{ $mills($line['debit_mills'] ?? 0) }}</td><td class="finance-money">{{ $mills($line['credit_mills'] ?? 0) }}</td><td class="finance-money">{{ $mills($line['balance_mills'] ?? 0) }}</td><td class="finance-money">{{ array_key_exists('comparative_balance_mills',$line) ? $mills($line['comparative_balance_mills']) : '—' }}</td></tr>
@empty<tr><td colspan="7" class="finance-empty">{{ __('finance_workbench.common.no_data') }}</td></tr>@endforelse
</tbody></table></div>
@endif
</section>
@endif
@endif
</main>
</div>
</body>
</html>
