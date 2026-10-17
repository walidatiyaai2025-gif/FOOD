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
.finance-button.danger{border-color:var(--foodex-danger-text);color:var(--foodex-danger-text)}
.finance-table-wrap{overflow-x:auto;border:1px solid var(--foodex-border);border-radius:10px}
.finance-table{width:100%;border-collapse:collapse;min-width:760px}
.finance-table th,.finance-table td{padding:11px 12px;border-bottom:1px solid var(--foodex-border);text-align:start;vertical-align:top}
.finance-table th{font-size:.78rem;color:var(--foodex-muted);background:var(--foodex-background);white-space:nowrap}
.finance-table tr:last-child td{border-bottom:0}
.finance-money{font-variant-numeric:tabular-nums;direction:ltr;unicode-bidi:isolate;text-align:end}
.finance-status{display:inline-flex;align-items:center;min-height:28px;padding:2px 9px;border:1px solid var(--foodex-border);border-radius:999px;font-size:.78rem;font-weight:900;white-space:nowrap}
.finance-empty{padding:26px;text-align:center;color:var(--foodex-muted)}
.finance-flash{padding:12px 14px;margin-bottom:14px;border:1px solid var(--foodex-action-green);border-radius:10px;background:var(--foodex-surface)}
.finance-errors{padding:12px 14px;margin-bottom:14px;border:1px solid var(--foodex-danger-text);border-radius:10px;background:var(--foodex-surface)}
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
    $statusLabel = static fn ($value): string => __('finance_workbench.statuses.'.(string) $value);
@endphp
<div class="foodex-admin-layout" data-foodex-mobile-web-page="finance-workbench">
<aside class="sidebar">@include('admin._sidebar',['navGroups'=>$navGroups,'navContext'=>$navContext,'user'=>$user])</aside> {{-- localization-gate: allow Blade include expression --}}
<main class="foodex-admin-main foodex-admin-page finance-page" data-finance-workbench="true">
@include('admin._global-header')
<header class="finance-head foodex-page-toolbar">
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
<section class="foodex-module-overview" data-foodex-module-overview="finance">
<form method="get" action="{{ route('admin.erp.finance.index') }}" class="foodex-module-toolbar">
<input type="hidden" name="store_id" value="{{ $storeId }}"><input type="hidden" name="tab" value="overview">
<label class="foodex-module-filter-group"><span>{{ __('finance_workbench.common.period') }}</span><select name="period_id"><option value="">{{ __('finance_workbench.dashboard.latest_period') }}</option>@foreach($data['periods'] as $period)<option value="{{ $period->id }}" @selected((int)request('period_id')===(int)$period->id)>{{ $period->code }} · {{ $period->name }}</option>@endforeach</select></label>
<button class="finance-button primary" type="submit">{{ __('finance_workbench.actions.apply') }}</button>
<a class="finance-button" href="{{ route('admin.erp.finance.index',[...$routeBase,'tab'=>'reports']) }}">{{ __('finance_workbench.actions.open_reports') }}</a>
</form>
@if(($data['state'] ?? 'empty')!=='current')
@include('admin._module-dashboard-state',['state'=>$data['state'] ?? 'empty'])
@else
@php
$periodId=(int)$data['period']->id;
$kpis=[
 ['label'=>__('finance_workbench.metrics.revenue'),'value'=>$money($data['metrics']['revenue']),'drilldown_url'=>route('admin.erp.finance.index',[...$routeBase,'tab'=>'statements','statement'=>'profit_and_loss','period_id'=>$periodId])],
 ['label'=>__('finance_workbench.metrics.expense'),'value'=>$money($data['metrics']['expense']),'drilldown_url'=>route('admin.erp.finance.index',[...$routeBase,'tab'=>'statements','statement'=>'profit_and_loss','period_id'=>$periodId])],
 ['label'=>__('finance_workbench.metrics.result'),'value'=>$money($data['metrics']['result']),'drilldown_url'=>route('admin.erp.finance.index',[...$routeBase,'tab'=>'statements','statement'=>'profit_and_loss','period_id'=>$periodId])],
 ['label'=>__('finance_workbench.metrics.ar_outstanding'),'value'=>$money($data['metrics']['ar_outstanding']),'comparison'=>['value'=>$money($data['metrics']['ar_overdue']),'label'=>__('finance_workbench.metrics.overdue'),'trend'=>$data['metrics']['ar_overdue']>0?'down':'neutral'],'drilldown_url'=>route('admin.erp.finance.index',[...$routeBase,'tab'=>'receivables'])],
 ['label'=>__('finance_workbench.metrics.ap_outstanding'),'value'=>$money($data['metrics']['ap_outstanding']),'comparison'=>['value'=>$money($data['metrics']['ap_overdue']),'label'=>__('finance_workbench.metrics.overdue'),'trend'=>$data['metrics']['ap_overdue']>0?'down':'neutral'],'drilldown_url'=>route('admin.erp.finance.index',[...$routeBase,'tab'=>'payables'])],
 ['label'=>__('finance_workbench.metrics.unposted_journals'),'value'=>number_format($data['metrics']['unposted_journals']),'drilldown_url'=>route('admin.erp.finance.index',[...$routeBase,'tab'=>'journals','status'=>'draft'])],
 ['label'=>__('finance_workbench.metrics.pending_approvals'),'value'=>number_format($data['metrics']['pending_approvals']),'drilldown_url'=>route('admin.action-center.index')],
 ['label'=>__('finance_workbench.metrics.close_blockers'),'value'=>number_format($data['metrics']['close_blockers']),'status'=>['label'=>$data['metrics']['close_blockers']>0?__('finance_workbench.dashboard.blocked'):__('finance_workbench.dashboard.ready'),'state'=>$data['metrics']['close_blockers']>0?'danger':'success'],'drilldown_url'=>route('admin.erp.finance.index',[...$routeBase,'tab'=>'reports','report'=>'close_status','period_id'=>$periodId])],
 ['label'=>__('finance_workbench.metrics.budget_variance'),'value'=>$money($data['metrics']['budget_variance']),'comparison'=>['value'=>$money($data['metrics']['budget_actual']),'label'=>__('finance_workbench.metrics.actual'),'trend'=>abs((float)$data['metrics']['budget_variance'])>0.001?'down':'neutral'],'drilldown_url'=>route('admin.erp.finance.index',[...$routeBase,'tab'=>'reports','report'=>'budget_vs_actual','period_id'=>$periodId])],
 ['label'=>__('finance_workbench.metrics.tax_position'),'value'=>$money($data['metrics']['tax_position']),'drilldown_url'=>route('admin.erp.finance.index',[...$routeBase,'tab'=>'reports','report'=>'tax_vat','period_id'=>$periodId])],
];
$trendMax=max(1,max(array_map(fn($r)=>max(abs((float)$r['revenue']),abs((float)$r['expense'])), $data['trend'] ?: [['revenue'=>0,'expense'=>0]])));
@endphp
<div class="foodex-module-kpis">
@foreach($kpis as $metric) @include('admin._module-dashboard-kpi',['metric'=>$metric]) @endforeach
</div>
<div class="foodex-module-panels">
<article class="foodex-viz-card foodex-module-chart" data-foodex-module-chart="finance-pnl">
<div class="foodex-viz-header"><div><h2 class="foodex-viz-title">{{ __('finance_workbench.dashboard.pnl_trend') }}</h2><p class="foodex-viz-subtitle">{{ __('finance_workbench.dashboard.pnl_trend_help') }}</p></div></div>
@if($data['trend']===[])
<div class="foodex-viz-empty">{{ __('finance_workbench.common.no_data') }}</div>
@else
<div class="foodex-viz-bars" role="img" aria-label="{{ __('finance_workbench.dashboard.pnl_trend') }}">
@foreach($data['trend'] as $row)
<div style="display:grid;grid-template-columns:1fr 1fr;align-items:end;gap:3px;height:160px;flex:1" title="{{ $row['code'] }} · {{ $money($row['result']) }}">
<span class="foodex-viz-bar" style="height:{{ max(3,abs((float)$row['revenue'])/$trendMax*100) }}%" aria-hidden="true"></span>
<span class="foodex-viz-bar" data-series="2" style="height:{{ max(3,abs((float)$row['expense'])/$trendMax*100) }}%" aria-hidden="true"></span>
<small style="grid-column:1/-1;text-align:center">{{ $row['code'] }}</small>
</div>
@endforeach
</div>
<div class="foodex-viz-legend"><span class="foodex-viz-legend-item"><i class="foodex-viz-swatch"></i>{{ __('finance_workbench.metrics.revenue') }}</span><span class="foodex-viz-legend-item">{{ __('finance_workbench.metrics.expense') }}</span></div>
@endif
</article>
<article class="foodex-viz-card foodex-module-chart">
<div class="foodex-viz-header"><div><h2 class="foodex-viz-title">{{ __('finance_workbench.dashboard.aging') }}</h2><p class="foodex-viz-subtitle">{{ __('finance_workbench.dashboard.aging_help') }}</p></div></div>
<div class="foodex-module-chart-table-wrap"><table class="foodex-module-chart-table"><thead><tr><th>{{ __('finance_workbench.common.aging_bucket') }}</th><th>{{ __('finance_workbench.dashboard.ar') }}</th><th>{{ __('finance_workbench.dashboard.ap') }}</th></tr></thead><tbody>
@foreach(['current','1_30','31_60','61_90','91_plus'] as $bucket)
@php $arRow=collect($data['aging']['ar'])->firstWhere('bucket',$bucket); $apRow=collect($data['aging']['ap'])->firstWhere('bucket',$bucket); @endphp
<tr><td>{{ $statusLabel($bucket) }}</td><td class="finance-money">{{ $money($arRow['amount'] ?? 0) }}</td><td class="finance-money">{{ $money($apRow['amount'] ?? 0) }}</td></tr>
@endforeach
</tbody></table></div>
</article>
</div>
<div class="finance-subgrid">
<section class="finance-panel"><div class="finance-panel-head"><div><h2>{{ __('finance_workbench.dashboard.expense_centers') }}</h2><p>{{ __('finance_workbench.dashboard.expense_centers_help') }}</p></div></div>
<div class="finance-table-wrap"><table class="finance-table"><thead><tr><th>{{ __('finance_workbench.common.code') }}</th><th>{{ __('finance_workbench.common.name') }}</th><th>{{ __('finance_workbench.common.amount') }}</th></tr></thead><tbody>
@forelse($data['expenses'] as $row)<tr><td>{{ $row['code'] }}</td><td>{{ $row['name'] }}</td><td class="finance-money">{{ $money($row['amount']) }}</td></tr>@empty<tr><td colspan="3" class="finance-empty">{{ __('finance_workbench.common.no_data') }}</td></tr>@endforelse
</tbody></table></div></section>
<section class="finance-panel"><div class="finance-panel-head"><div><h2>{{ __('finance_workbench.dashboard.close_exceptions') }}</h2><p>{{ __('finance_workbench.dashboard.close_exceptions_help') }}</p></div></div>
<div class="finance-table-wrap"><table class="finance-table"><thead><tr><th>{{ __('finance_workbench.common.code') }}</th><th>{{ __('finance_workbench.common.status') }}</th><th>{{ __('finance_workbench.dashboard.exceptions') }}</th></tr></thead><tbody>
@forelse($data['exceptions'] as $row)<tr><td>{{ $row['code'] }}</td><td><span class="finance-status">{{ __('finance_workbench.statuses.'.$row['status']) }}</span></td><td>{{ number_format($row['count']) }}</td></tr>@empty<tr><td colspan="3" class="finance-empty">{{ __('finance_workbench.dashboard.no_close_exceptions') }}</td></tr>@endforelse
</tbody></table></div></section>
</div>
@endif
</section>
@endif

@if($tab==='accounts')
<section class="finance-panel">
<div class="finance-panel-head"><div><h2>{{ __('finance_workbench.accounts.title') }}</h2><p>{{ __('finance_workbench.accounts.help') }}</p></div></div>
<form method="get" action="{{ route('admin.erp.finance.index') }}" class="finance-filter">
<input type="hidden" name="store_id" value="{{ $storeId }}"><input type="hidden" name="tab" value="accounts">
<div class="finance-field"><label for="account-q">{{ __('finance_workbench.common.search') }}</label><input id="account-q" name="q" value="{{ $data['filters']['q'] ?? '' }}" maxlength="120"></div>
<div class="finance-field"><label for="account-type">{{ __('finance_workbench.common.type') }}</label><select id="account-type" name="type"><option value=""></option>@foreach(['asset','liability','equity','revenue','expense'] as $type)<option value="{{ $type }}" @selected(($data['filters']['type'] ?? '')===$type)>{{ __('finance_workbench.account_types.'.$type) }}</option>@endforeach</select></div>
<div class="finance-field"><label for="account-status">{{ __('finance_workbench.common.status') }}</label><select id="account-status" name="status"><option value=""></option>@foreach(['active','inactive'] as $value)<option value="{{ $value }}" @selected(($data['filters']['status'] ?? '')===$value)>{{ $statusLabel($value) }}</option>@endforeach</select></div>
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
<div class="finance-table-wrap"><table class="finance-table"><thead><tr><th>{{ __('finance_workbench.common.code') }}</th><th>{{ __('finance_workbench.common.name') }}</th><th>{{ __('finance_workbench.common.type') }}</th><th>{{ __('finance_workbench.common.normal_balance') }}</th><th>{{ __('finance_workbench.common.posting') }}</th><th>{{ __('finance_workbench.accounts.cash_flow') }}</th><th>{{ __('finance_workbench.common.status') }}</th>@if($access['foundation_manage'])<th>{{ __('finance_workbench.actions.save') }}</th>@endif</tr></thead><tbody>
@forelse($data['accounts'] as $account)
<tr><td><strong>{{ $account->code }}</strong></td><td>{{ $account->name }}</td><td>{{ __('finance_workbench.account_types.'.$account->type) }}</td><td>{{ __('finance_workbench.normal_balances.'.$account->normal_balance) }}</td><td>{{ $account->is_control ? __('finance_workbench.common.control') : ($account->allow_posting ? '✓' : '—') }}</td>
<td>@if($access['foundation_manage'])<form method="post" action="{{ route('admin.erp.finance.accounts.cash-flow',['account'=>$account->id]) }}" class="finance-filter">@csrf @method('PATCH')<input type="hidden" name="store_id" value="{{ $storeId }}"><select name="classification"><option value="">{{ __('finance_workbench.common.not_available') }}</option>@foreach(['operating','investing','financing'] as $classification)<option value="{{ $classification }}" @selected($account->cash_flow_classification===$classification)>{{ __('finance_workbench.cash_flow_classes.'.$classification) }}</option>@endforeach</select><button class="finance-button" type="submit">{{ __('finance_workbench.actions.save') }}</button></form>@else{{ $account->cash_flow_classification ? __('finance_workbench.cash_flow_classes.'.$account->cash_flow_classification) : '—' }}@endif</td>
<td><span class="finance-status">{{ $statusLabel($account->status) }}</span></td> {{-- localization-gate: allow status is rendered through the localized $status helper --}}
@if($access['foundation_manage'])<td><form method="post" action="{{ route('admin.erp.finance.accounts.status',['account'=>$account->id]) }}">@csrf @method('PATCH')<input type="hidden" name="store_id" value="{{ $storeId }}"><input type="hidden" name="status" value="{{ $account->status==='active' ? 'inactive' : 'active' }}"><button class="finance-button" type="submit">{{ $account->status==='active' ? __('finance_workbench.actions.deactivate') : __('finance_workbench.actions.activate') }}</button></form></td>@endif
</tr>
@empty<tr><td colspan="8" class="finance-empty">{{ __('finance_workbench.common.no_data') }}</td></tr>@endforelse
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
<div class="finance-field"><label>{{ __('finance_workbench.common.status') }}</label><select name="status"><option value=""></option>@foreach(['active','inactive'] as $value)<option value="{{ $value }}" @selected(($data['filters']['status'] ?? '')===$value)>{{ $statusLabel($value) }}</option>@endforeach</select></div>
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

@if($tab==='profit_centers')
<section class="finance-panel">
<div class="finance-panel-head"><div><h2>{{ __('finance_workbench.profit_centers.title') }}</h2><p>{{ __('finance_workbench.profit_centers.help') }}</p></div></div>
<form method="get" action="{{ route('admin.erp.finance.index') }}" class="finance-filter"><input type="hidden" name="store_id" value="{{ $storeId }}"><input type="hidden" name="tab" value="profit_centers">
<div class="finance-field"><label>{{ __('finance_workbench.common.search') }}</label><input name="q" value="{{ $data['filters']['q'] ?? '' }}" maxlength="120"></div>
<div class="finance-field"><label>{{ __('finance_workbench.common.status') }}</label><select name="status"><option value=""></option>@foreach(['active','inactive'] as $value)<option value="{{ $value }}" @selected(($data['filters']['status'] ?? '')===$value)>{{ $statusLabel($value) }}</option>@endforeach</select></div>
<button class="finance-button" type="submit">{{ __('finance_workbench.actions.filter') }}</button></form>
@if($access['foundation_manage'])
<details class="finance-details"><summary>{{ __('finance_workbench.profit_centers.new') }}</summary><div class="finance-details-body"><form method="post" action="{{ route('admin.erp.finance.profit-centers.store') }}" class="finance-form-grid">@csrf<input type="hidden" name="store_id" value="{{ $storeId }}">
<div class="finance-field"><label>{{ __('finance_workbench.common.code') }}</label><input name="code" required maxlength="64"></div><div class="finance-field"><label>{{ __('finance_workbench.common.name') }}</label><input name="name" required maxlength="190"></div>
<div class="finance-field"><label>{{ __('finance_workbench.common.parent') }}</label><select name="parent_id"><option value="">{{ __('finance_workbench.common.optional') }}</option>@foreach($data['parents'] as $parent)<option value="{{ $parent->id }}">{{ $parent->code }} · {{ $parent->name }}</option>@endforeach</select></div>
<button class="finance-button primary" type="submit">{{ __('finance_workbench.actions.create') }}</button></form></div></details>
@endif
<div class="finance-table-wrap"><table class="finance-table"><thead><tr><th>{{ __('finance_workbench.common.code') }}</th><th>{{ __('finance_workbench.common.name') }}</th><th>{{ __('finance_workbench.common.status') }}</th>@if($access['foundation_manage'])<th>{{ __('finance_workbench.actions.save') }}</th>@endif</tr></thead><tbody>
@forelse($data['profitCenters'] as $center)<tr><td><strong>{{ $center->code }}</strong></td><td>{{ $center->name }}</td><td><span class="finance-status">{{ $statusLabel($center->status) }}</span></td> {{-- localization-gate: allow status is rendered through the localized $status helper --}}
@if($access['foundation_manage'])<td><form method="post" action="{{ route('admin.erp.finance.profit-centers.status',['profitCenter'=>$center->id]) }}">@csrf @method('PATCH')<input type="hidden" name="store_id" value="{{ $storeId }}"><input type="hidden" name="status" value="{{ $center->status==='active'?'inactive':'active' }}"><button class="finance-button" type="submit">{{ $center->status==='active'?__('finance_workbench.actions.deactivate'):__('finance_workbench.actions.activate') }}</button></form></td>@endif {{-- localization-gate: allow hidden workflow status token; visible action label is localized --}}
</tr>@empty<tr><td colspan="4" class="finance-empty">{{ __('finance_workbench.common.no_data') }}</td></tr>@endforelse
</tbody></table></div><div class="finance-pagination">{{ $data['profitCenters']->links() }}</div>
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
@if($access['foundation_manage'])<td><div class="finance-inline-actions">@if($period->status==='open') {{-- localization-gate: allow technical status comparison; all visible labels use __() --}}<form method="post" action="{{ route('admin.erp.finance.fiscal-periods.close-evaluation',['fiscalPeriod'=>$period->id]) }}">@csrf<input type="hidden" name="store_id" value="{{ $storeId }}"><button class="finance-button" type="submit">{{ __('finance_workbench.actions.evaluate_close') }}</button></form>@endif<form method="post" action="{{ route('admin.erp.finance.fiscal-periods.state',['fiscalPeriod'=>$period->id]) }}">@csrf @method('PATCH')<input type="hidden" name="store_id" value="{{ $storeId }}"><input type="hidden" name="state" value="{{ $period->status==='open' ? 'closed' : 'open' }}"><button class="finance-button" type="submit">{{ $period->status==='open' ? __('finance_workbench.actions.close') : __('finance_workbench.actions.reopen') }}</button></form></div></td>@endif
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
@forelse($data['batches'] as $batch)<tr><td><strong>{{ $batch->batch_number }}</strong></td><td>{{ optional($batch->entry_date)->format('Y-m-d') }}</td><td class="finance-money">{{ $money($batch->total_debit) }}</td><td class="finance-money">{{ $money($batch->total_credit) }}</td><td><span class="finance-status">{{ $statusLabel($batch->status) }}</span></td><td><a class="finance-button" href="{{ route('admin.erp.finance.index',[...$routeBase,'tab'=>'opening_balances','batch'=>$batch->id]) }}">{{ __('finance_workbench.actions.view') }}</a></td></tr>
@empty<tr><td colspan="6" class="finance-empty">{{ __('finance_workbench.common.no_data') }}</td></tr>@endforelse
</tbody></table></div>
<div class="finance-pagination">{{ $data['batches']->links() }}</div>
</section>
@if($data['selectedBatch'])
<section class="finance-panel"><div class="finance-panel-head"><div><h3>{{ __('finance_workbench.opening.detail') }} · {{ $data['selectedBatch']->batch_number }}</h3></div><span class="finance-status">{{ $statusLabel($data['selectedBatch']->status) }}</span></div>
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
<div class="finance-field"><label>{{ __('finance_workbench.common.status') }}</label><select name="status"><option value=""></option>@foreach(['draft','posted','cancelled'] as $value)<option value="{{ $value }}" @selected(($data['filters']['status'] ?? '')===$value)>{{ $statusLabel($value) }}</option>@endforeach</select></div>
<div class="finance-field"><label>{{ __('finance_workbench.common.from') }}</label><input type="date" name="date_from" value="{{ $data['filters']['date_from'] ?? '' }}"></div><div class="finance-field"><label>{{ __('finance_workbench.common.to') }}</label><input type="date" name="date_to" value="{{ $data['filters']['date_to'] ?? '' }}"></div>
<button class="finance-button" type="submit">{{ __('finance_workbench.actions.filter') }}</button></form>
<div class="finance-table-wrap"><table class="finance-table"><thead><tr><th>{{ __('finance_workbench.receivables.document') }}</th><th>{{ __('finance_workbench.common.customer') }}</th><th>{{ __('finance_workbench.common.date') }}</th><th>{{ __('finance_workbench.common.due_date') }}</th><th>{{ __('finance_workbench.common.amount') }}</th><th>{{ __('finance_workbench.common.status') }}</th><th>{{ __('finance_workbench.actions.view_ledger') }}</th></tr></thead><tbody>
@forelse($data['documents'] as $document)<tr><td><strong>{{ $document->document_number }}</strong></td><td>{{ $document->customer?->name ?? '—' }}</td><td>{{ optional($document->entry_date)->format('Y-m-d') }}</td><td>{{ optional($document->due_date)->format('Y-m-d') }}</td><td class="finance-money">{{ $money($document->amount) }} {{ $document->currency_code }}</td><td><span class="finance-status">{{ $statusLabel($document->status) }}</span></td><td>@if($document->journal_id)<a class="finance-button" href="{{ route('admin.erp.finance.index',[...$routeBase,'tab'=>'journals','journal'=>$document->journal_id]) }}">{{ __('finance_workbench.actions.view_ledger') }}</a>@else—@endif</td></tr>
@empty<tr><td colspan="7" class="finance-empty">{{ __('finance_workbench.common.no_data') }}</td></tr>@endforelse
</tbody></table></div><div class="finance-pagination">{{ $data['documents']->links() }}</div>
<h3>{{ __('finance_workbench.common.aging_bucket') }}</h3>
<div class="finance-table-wrap"><table class="finance-table"><thead><tr><th>{{ __('finance_workbench.receivables.document') }}</th><th>{{ __('finance_workbench.common.due_date') }}</th><th>{{ __('finance_workbench.common.amount') }}</th><th>{{ __('finance_workbench.common.allocated') }}</th><th>{{ __('finance_workbench.common.outstanding') }}</th><th>{{ __('finance_workbench.common.aging_bucket') }}</th></tr></thead><tbody>
@forelse($data['aging'] as $row)<tr><td>{{ $row->document_number }}</td><td>{{ $row->due_date }}</td><td class="finance-money">{{ $money($row->amount) }} {{ $row->currency_code }}</td><td class="finance-money">{{ $money($row->allocated_amount) }}</td><td class="finance-money">{{ $money($row->outstanding_amount) }}</td><td><span class="finance-status">{{ $statusLabel($row->aging_bucket) }}</span></td></tr>
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
<div class="finance-field"><label>{{ __('finance_workbench.common.status') }}</label><select name="status"><option value=""></option>@foreach(['draft','posted','cancelled'] as $value)<option value="{{ $value }}" @selected(($data['filters']['status'] ?? '')===$value)>{{ $statusLabel($value) }}</option>@endforeach</select></div>
<div class="finance-field"><label>{{ __('finance_workbench.common.from') }}</label><input type="date" name="date_from" value="{{ $data['filters']['date_from'] ?? '' }}"></div><div class="finance-field"><label>{{ __('finance_workbench.common.to') }}</label><input type="date" name="date_to" value="{{ $data['filters']['date_to'] ?? '' }}"></div>
<button class="finance-button" type="submit">{{ __('finance_workbench.actions.filter') }}</button></form>
<div class="finance-table-wrap"><table class="finance-table"><thead><tr><th>{{ __('finance_workbench.payables.document') }}</th><th>{{ __('finance_workbench.common.supplier') }}</th><th>{{ __('finance_workbench.common.date') }}</th><th>{{ __('finance_workbench.common.due_date') }}</th><th>{{ __('finance_workbench.common.amount') }}</th><th>{{ __('finance_workbench.common.status') }}</th><th>{{ __('finance_workbench.actions.view_ledger') }}</th></tr></thead><tbody>
@forelse($data['documents'] as $document)<tr><td><strong>{{ $document->document_number }}</strong></td><td>{{ $document->supplier_name ?: $document->supplier_reference }}</td><td>{{ optional($document->entry_date)->format('Y-m-d') }}</td><td>{{ optional($document->due_date)->format('Y-m-d') }}</td><td class="finance-money">{{ $money($document->amount) }} {{ $document->currency_code }}</td><td><span class="finance-status">{{ $statusLabel($document->status) }}</span></td><td>@if($document->journal_id)<a class="finance-button" href="{{ route('admin.erp.finance.index',[...$routeBase,'tab'=>'journals','journal'=>$document->journal_id]) }}">{{ __('finance_workbench.actions.view_ledger') }}</a>@else—@endif</td></tr>
@empty<tr><td colspan="7" class="finance-empty">{{ __('finance_workbench.common.no_data') }}</td></tr>@endforelse
</tbody></table></div><div class="finance-pagination">{{ $data['documents']->links() }}</div>
<h3>{{ __('finance_workbench.common.aging_bucket') }}</h3>
<div class="finance-table-wrap"><table class="finance-table"><thead><tr><th>{{ __('finance_workbench.payables.document') }}</th><th>{{ __('finance_workbench.common.supplier') }}</th><th>{{ __('finance_workbench.common.due_date') }}</th><th>{{ __('finance_workbench.common.outstanding') }}</th><th>{{ __('finance_workbench.common.aging_bucket') }}</th></tr></thead><tbody>
@forelse($data['aging'] as $row)<tr><td>{{ $row->document_number }}</td><td>{{ $row->supplier_name ?: $row->supplier_reference }}</td><td>{{ $row->due_date }}</td><td class="finance-money">{{ $money($row->outstanding_amount) }} {{ $row->currency_code }}</td><td><span class="finance-status">{{ $statusLabel($row->aging_bucket) }}</span></td></tr>
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
@forelse($data['policies'] as $policy)<tr><td>{{ __('finance_workbench.subject_types.'.$policy->subject_type) }} · {{ $policy->subject_reference }}</td><td class="finance-money">{{ $policy->credit_limit===null ? '—' : $money($policy->credit_limit).' '.$policy->currency_code }}</td><td>{{ $policy->payment_terms_days }}</td><td>{{ optional($policy->valid_from)->format('Y-m-d') }}</td><td>{{ optional($policy->valid_until)->format('Y-m-d') ?: '—' }}</td><td><span class="finance-status">{{ $statusLabel($policy->status) }}</span></td>
@if($access['credit_manage'])<td><form method="post" action="{{ route('admin.erp.finance.credit-terms.status',['creditTerm'=>$policy->id]) }}">@csrf @method('PATCH')<input type="hidden" name="store_id" value="{{ $storeId }}"><input type="hidden" name="status" value="{{ $policy->status==='active' ? 'inactive' : 'active' }}"><button class="finance-button" type="submit">{{ $policy->status==='active' ? __('finance_workbench.actions.deactivate') : __('finance_workbench.actions.activate') }}</button></form></td>@endif
</tr>
@empty<tr><td colspan="7" class="finance-empty">{{ __('finance_workbench.common.no_data') }}</td></tr>@endforelse
</tbody></table></div><div class="finance-pagination">{{ $data['policies']->links() }}</div>
</section>
@endif

@if($tab==='budgets')
<section class="finance-panel">
<div class="finance-panel-head"><div><h2>{{ __('finance_workbench.budgets.title') }}</h2><p>{{ __('finance_workbench.budgets.help') }}</p></div></div>
@if($access['foundation_manage'])
<details class="finance-details"><summary>{{ __('finance_workbench.budgets.new') }}</summary><div class="finance-details-body"><form method="post" action="{{ route('admin.erp.finance.budgets.store') }}" class="finance-form-grid">@csrf<input type="hidden" name="store_id" value="{{ $storeId }}">
<div class="finance-field"><label>{{ __('finance_workbench.budgets.year') }}</label><select name="fiscal_year_id" required>@foreach($data['years'] as $year)<option value="{{ $year->id }}">{{ $year->code }} · {{ $year->name }}</option>@endforeach</select></div>
<div class="finance-field"><label>{{ __('finance_workbench.common.name') }}</label><input name="name" required maxlength="190"></div><div class="finance-field"><label>{{ __('finance_workbench.common.currency') }}</label><input name="currency_code" value="KWD" required maxlength="3"></div>
<div class="finance-field"><label>{{ __('finance_workbench.budgets.revision_note') }}</label><input name="revision_note" maxlength="2000"></div><button class="finance-button primary" type="submit">{{ __('finance_workbench.actions.create') }}</button>
</form></div></details>
@endif
<div class="finance-table-wrap"><table class="finance-table"><thead><tr><th>{{ __('finance_workbench.common.name') }}</th><th>{{ __('finance_workbench.budgets.year') }}</th><th>{{ __('finance_workbench.budgets.version') }}</th><th>{{ __('finance_workbench.common.currency') }}</th><th>{{ __('finance_workbench.common.status') }}</th><th>{{ __('finance_workbench.budgets.lines') }}</th><th>{{ __('finance_workbench.actions.view') }}</th></tr></thead><tbody>
@forelse($data['budgets'] as $budget) @php $year=$data['years']->firstWhere('id',$budget->fiscal_year_id); @endphp
<tr><td><strong>{{ $budget->name }}</strong></td><td>{{ $year?->code ?? '—' }}</td><td>{{ $budget->version }}</td><td>{{ $budget->currency_code }}</td><td><span class="finance-status">{{ $statusLabel($budget->status) }}</span></td><td>{{ $budget->lines_count }}</td><td><a class="finance-button" href="{{ route('admin.erp.finance.index',[...$routeBase,'tab'=>'budgets','budget'=>$budget->id]) }}">{{ __('finance_workbench.actions.view') }}</a></td></tr>
@empty<tr><td colspan="7" class="finance-empty">{{ __('finance_workbench.common.no_data') }}</td></tr>@endforelse
</tbody></table></div><div class="finance-pagination">{{ $data['budgets']->links() }}</div>
</section>
@if($data['selectedBudget'])
@php $budgetModel=$data['selectedBudget']['budget']; @endphp
<section class="finance-panel"><div class="finance-panel-head"><div><h3>{{ $budgetModel->name }} · v{{ $budgetModel->version }}</h3><p>{{ __('finance_workbench.budgets.lifecycle_help') }}</p></div><span class="finance-status">{{ $statusLabel($budgetModel->status) }}</span></div>
@if($access['foundation_manage'] && $budgetModel->status==='draft')
<details class="finance-details"><summary>{{ __('finance_workbench.budgets.add_line') }}</summary><div class="finance-details-body"><form method="post" action="{{ route('admin.erp.finance.budgets.lines.store',['budget'=>$budgetModel->id]) }}" class="finance-form-grid">@csrf<input type="hidden" name="store_id" value="{{ $storeId }}">
<div class="finance-field"><label>{{ __('finance_workbench.common.period') }}</label><select name="fiscal_period_id" required>@foreach($data['periods'] as $period)<option value="{{ $period->id }}">{{ $period->code }} · {{ $period->name }}</option>@endforeach</select></div>
<div class="finance-field"><label>{{ __('finance_workbench.statements.account') }}</label><select name="account_id" required>@foreach($data['accounts'] as $account)<option value="{{ $account->id }}">{{ $account->code }} · {{ $account->name }}</option>@endforeach</select></div>
<div class="finance-field"><label>{{ __('finance_workbench.statements.cost_center') }}</label><select name="cost_center_id"><option value="">{{ __('finance_workbench.common.optional') }}</option>@foreach($data['costCenters'] as $center)<option value="{{ $center->id }}">{{ $center->code }} · {{ $center->name }}</option>@endforeach</select></div>
<div class="finance-field"><label>{{ __('finance_workbench.profit_centers.title') }}</label><select name="profit_center_id"><option value="">{{ __('finance_workbench.common.optional') }}</option>@foreach($data['profitCenters'] as $center)<option value="{{ $center->id }}">{{ $center->code }} · {{ $center->name }}</option>@endforeach</select></div>
<div class="finance-field"><label>{{ __('finance_workbench.common.amount') }}</label><input inputmode="decimal" name="amount" required></div><div class="finance-field"><label>{{ __('finance_workbench.common.notes') }}</label><input name="notes" maxlength="2000"></div>
<button class="finance-button primary" type="submit">{{ __('finance_workbench.actions.save') }}</button></form></div></details>
@endif
<div class="finance-inline-actions">
@if($budgetModel->status==='draft' && $access['foundation_manage'])<form method="post" action="{{ route('admin.erp.finance.budgets.transition',['budget'=>$budgetModel->id]) }}">@csrf<input type="hidden" name="store_id" value="{{ $storeId }}"><input type="hidden" name="action" value="submit"><button class="finance-button primary" type="submit">{{ __('finance_workbench.actions.submit') }}</button></form>@endif
@if($budgetModel->status==='submitted' && $access['approval_decide'])<form method="post" action="{{ route('admin.erp.finance.budgets.transition',['budget'=>$budgetModel->id]) }}">@csrf<input type="hidden" name="store_id" value="{{ $storeId }}"><input type="hidden" name="action" value="approve"><button class="finance-button primary" type="submit">{{ __('finance_workbench.actions.approve') }}</button></form>@endif
@if($budgetModel->status==='approved' && $access['approval_decide'])<form method="post" action="{{ route('admin.erp.finance.budgets.transition',['budget'=>$budgetModel->id]) }}">@csrf<input type="hidden" name="store_id" value="{{ $storeId }}"><input type="hidden" name="action" value="lock"><button class="finance-button primary" type="submit">{{ __('finance_workbench.actions.lock') }}</button></form>@endif
</div>
@if(in_array($budgetModel->status,['approved','locked'],true) && $access['foundation_manage'])<details class="finance-details"><summary>{{ __('finance_workbench.budgets.revise') }}</summary><div class="finance-details-body"><form method="post" action="{{ route('admin.erp.finance.budgets.revise',['budget'=>$budgetModel->id]) }}" class="finance-filter">@csrf<input type="hidden" name="store_id" value="{{ $storeId }}"><div class="finance-field"><label>{{ __('finance_workbench.budgets.revision_note') }}</label><input name="revision_note" required maxlength="2000"></div><button class="finance-button" type="submit">{{ __('finance_workbench.budgets.new_revision') }}</button></form></div></details>@endif
<div class="finance-table-wrap"><table class="finance-table"><thead><tr><th>{{ __('finance_workbench.common.period') }}</th><th>{{ __('finance_workbench.statements.account') }}</th><th>{{ __('finance_workbench.statements.cost_center') }}</th><th>{{ __('finance_workbench.profit_centers.title') }}</th><th>{{ __('finance_workbench.common.amount') }}</th></tr></thead><tbody>
@forelse($data['selectedBudget']['lines'] as $line)<tr><td>{{ $line->period_code }}</td><td>{{ $line->account_code }} · {{ $line->account_name }}</td><td>{{ $line->cost_center_code ?: '—' }}</td><td>{{ $line->profit_center_code ?: '—' }}</td><td class="finance-money">{{ $money($line->amount) }}</td></tr>@empty<tr><td colspan="5" class="finance-empty">{{ __('finance_workbench.common.no_data') }}</td></tr>@endforelse
</tbody></table></div></section>
@endif
@endif

@if($tab==='tax')
<section class="finance-panel"><div class="finance-panel-head"><div><h2>{{ __('finance_workbench.tax.title') }}</h2><p>{{ __('finance_workbench.tax.help') }}</p></div></div>
@if($access['foundation_manage'])<details class="finance-details"><summary>{{ __('finance_workbench.tax.new') }}</summary><div class="finance-details-body"><form method="post" action="{{ route('admin.erp.finance.tax-codes.store') }}" class="finance-form-grid">@csrf<input type="hidden" name="store_id" value="{{ $storeId }}">
<div class="finance-field"><label>{{ __('finance_workbench.common.code') }}</label><input name="code" required maxlength="32"></div><div class="finance-field"><label>{{ __('finance_workbench.common.name') }}</label><input name="name" required maxlength="190"></div>
<div class="finance-field"><label>{{ __('finance_workbench.tax.rate') }}</label><input inputmode="decimal" name="rate_percent" required></div><div class="finance-field"><label>{{ __('finance_workbench.tax.direction') }}</label><select name="direction" required>@foreach(['input','output','both'] as $value)<option value="{{ $value }}">{{ __('finance_workbench.tax.directions.'.$value) }}</option>@endforeach</select></div>
<div class="finance-field"><label>{{ __('finance_workbench.tax.recoverable') }}</label><input inputmode="decimal" name="recoverable_percent" value="100"></div><div class="finance-field"><label>{{ __('finance_workbench.tax.input_account') }}</label><select name="input_account_id"><option value="">{{ __('finance_workbench.common.optional') }}</option>@foreach($data['accounts'] as $account)<option value="{{ $account->id }}">{{ $account->code }} · {{ $account->name }}</option>@endforeach</select></div>
<div class="finance-field"><label>{{ __('finance_workbench.tax.output_account') }}</label><select name="output_account_id"><option value="">{{ __('finance_workbench.common.optional') }}</option>@foreach($data['accounts'] as $account)<option value="{{ $account->id }}">{{ $account->code }} · {{ $account->name }}</option>@endforeach</select></div>
<div class="finance-field"><label>{{ __('finance_workbench.common.valid_from') }}</label><input type="date" name="effective_from" required value="{{ now()->toDateString() }}"></div><div class="finance-field"><label>{{ __('finance_workbench.common.valid_until') }}</label><input type="date" name="effective_to"></div>
<button class="finance-button primary" type="submit">{{ __('finance_workbench.actions.create') }}</button></form></div></details>@endif
<div class="finance-table-wrap"><table class="finance-table"><thead><tr><th>{{ __('finance_workbench.common.code') }}</th><th>{{ __('finance_workbench.common.name') }}</th><th>{{ __('finance_workbench.tax.rate') }}</th><th>{{ __('finance_workbench.tax.direction') }}</th><th>{{ __('finance_workbench.tax.recoverable') }}</th><th>{{ __('finance_workbench.common.valid_from') }}</th><th>{{ __('finance_workbench.common.status') }}</th></tr></thead><tbody>
@forelse($data['taxCodes'] as $code)<tr><td><strong>{{ $code->code }}</strong></td><td>{{ $code->name }}</td><td>{{ $money($code->rate_percent) }}%</td><td>{{ __('finance_workbench.tax.directions.'.$code->direction) }}</td><td>{{ $money($code->recoverable_percent) }}%</td><td>{{ optional($code->effective_from)->format('Y-m-d') }}</td><td><span class="finance-status">{{ $statusLabel($code->status) }}</span></td></tr>@empty<tr><td colspan="7" class="finance-empty">{{ __('finance_workbench.common.no_data') }}</td></tr>@endforelse
</tbody></table></div><div class="finance-pagination">{{ $data['taxCodes']->links() }}</div></section>
@endif

@if($tab==='allocations')
<section class="finance-panel"><div class="finance-panel-head"><div><h2>{{ __('finance_workbench.allocations.title') }}</h2><p>{{ __('finance_workbench.allocations.help') }}</p></div></div>
@if($access['foundation_manage'])<details class="finance-details"><summary>{{ __('finance_workbench.allocations.new') }}</summary><div class="finance-details-body"><form method="post" action="{{ route('admin.erp.finance.allocation-rules.store') }}" class="finance-form-grid">@csrf<input type="hidden" name="store_id" value="{{ $storeId }}">
<div class="finance-field"><label>{{ __('finance_workbench.common.code') }}</label><input name="code" required maxlength="64"></div><div class="finance-field"><label>{{ __('finance_workbench.common.name') }}</label><input name="name" required maxlength="190"></div>
<div class="finance-field"><label>{{ __('finance_workbench.statements.account') }}</label><select name="account_id" required>@foreach($data['accounts'] as $account)<option value="{{ $account->id }}">{{ $account->code }} · {{ $account->name }}</option>@endforeach</select></div>
<div class="finance-field"><label>{{ __('finance_workbench.allocations.percent') }}</label><input inputmode="decimal" name="allocation_percent" required></div>
<div class="finance-field"><label>{{ __('finance_workbench.allocations.source_cost') }}</label><select name="source_cost_center_id"><option value="">{{ __('finance_workbench.common.optional') }}</option>@foreach($data['costCenters'] as $center)<option value="{{ $center->id }}">{{ $center->code }} · {{ $center->name }}</option>@endforeach</select></div>
<div class="finance-field"><label>{{ __('finance_workbench.allocations.target_cost') }}</label><select name="target_cost_center_id"><option value="">{{ __('finance_workbench.common.optional') }}</option>@foreach($data['costCenters'] as $center)<option value="{{ $center->id }}">{{ $center->code }} · {{ $center->name }}</option>@endforeach</select></div>
<div class="finance-field"><label>{{ __('finance_workbench.allocations.source_profit') }}</label><select name="source_profit_center_id"><option value="">{{ __('finance_workbench.common.optional') }}</option>@foreach($data['profitCenters'] as $center)<option value="{{ $center->id }}">{{ $center->code }} · {{ $center->name }}</option>@endforeach</select></div>
<div class="finance-field"><label>{{ __('finance_workbench.allocations.target_profit') }}</label><select name="target_profit_center_id"><option value="">{{ __('finance_workbench.common.optional') }}</option>@foreach($data['profitCenters'] as $center)<option value="{{ $center->id }}">{{ $center->code }} · {{ $center->name }}</option>@endforeach</select></div>
<div class="finance-field" style="grid-column:1/-1"><label>{{ __('finance_workbench.allocations.basis') }}</label><textarea name="basis_description" required maxlength="2000"></textarea></div><button class="finance-button primary" type="submit">{{ __('finance_workbench.actions.create') }}</button>
</form></div></details>@endif
<div class="finance-table-wrap"><table class="finance-table"><thead><tr><th>{{ __('finance_workbench.common.code') }}</th><th>{{ __('finance_workbench.common.name') }}</th><th>{{ __('finance_workbench.allocations.percent') }}</th><th>{{ __('finance_workbench.allocations.basis') }}</th><th>{{ __('finance_workbench.common.status') }}</th>@if($access['foundation_manage'])<th>{{ __('finance_workbench.actions.apply') }}</th>@endif</tr></thead><tbody>
@forelse($data['rules'] as $rule)<tr><td><strong>{{ $rule->code }}</strong></td><td>{{ $rule->name }}</td><td>{{ $money($rule->allocation_percent) }}%</td><td>{{ $rule->basis_description }}</td><td><span class="finance-status">{{ $statusLabel($rule->status) }}</span></td> {{-- localization-gate: allow status is rendered through the localized $status helper --}}
@if($access['foundation_manage'])<td><form method="post" action="{{ route('admin.erp.finance.allocation-rules.apply',['allocationRule'=>$rule->id]) }}" class="finance-filter">@csrf<input type="hidden" name="store_id" value="{{ $storeId }}"><input type="date" name="entry_date" value="{{ now()->toDateString() }}" required><input inputmode="decimal" name="base_amount" placeholder="{{ __('finance_workbench.common.amount') }}" required><input type="hidden" name="idempotency_key" value="allocation-{{ $rule->id }}-{{ now()->format('Ymd-His') }}"><button class="finance-button" type="submit">{{ __('finance_workbench.allocations.create_journal') }}</button></form></td>@endif
</tr>@empty<tr><td colspan="6" class="finance-empty">{{ __('finance_workbench.common.no_data') }}</td></tr>@endforelse
</tbody></table></div><div class="finance-pagination">{{ $data['rules']->links() }}</div></section>
@endif

@if($tab==='assets')
<section class="finance-panel"><div class="finance-panel-head"><div><h2>{{ __('finance_workbench.assets.title') }}</h2><p>{{ __('finance_workbench.assets.help') }}</p></div></div>
<form method="get" action="{{ route('admin.erp.finance.index') }}" class="finance-filter"><input type="hidden" name="store_id" value="{{ $storeId }}"><input type="hidden" name="tab" value="assets"><div class="finance-field"><label>{{ __('finance_workbench.common.search') }}</label><input name="q" value="{{ $data['filters']['q'] ?? '' }}"></div><div class="finance-field"><label>{{ __('finance_workbench.common.status') }}</label><select name="status"><option value=""></option>@foreach(['active','disposal_pending','disposed'] as $value)<option value="{{ $value }}" @selected(($data['filters']['status'] ?? '')===$value)>{{ $statusLabel($value) }}</option>@endforeach</select></div><button class="finance-button" type="submit">{{ __('finance_workbench.actions.filter') }}</button></form>
@if($access['foundation_manage'])
<details class="finance-details"><summary>{{ __('finance_workbench.assets.new_category') }}</summary><div class="finance-details-body"><form method="post" action="{{ route('admin.erp.finance.asset-categories.store') }}" class="finance-form-grid">@csrf<input type="hidden" name="store_id" value="{{ $storeId }}">
<div class="finance-field"><label>{{ __('finance_workbench.common.code') }}</label><input name="code" required></div><div class="finance-field"><label>{{ __('finance_workbench.common.name') }}</label><input name="name" required></div><div class="finance-field"><label>{{ __('finance_workbench.assets.method') }}</label><select name="depreciation_method"><option value="straight_line">{{ __('finance_workbench.assets.straight_line') }}</option><option value="declining_balance">{{ __('finance_workbench.assets.declining_balance') }}</option><option value="none">{{ __('finance_workbench.assets.none') }}</option></select></div><div class="finance-field"><label>{{ __('finance_workbench.assets.life_months') }}</label><input type="number" min="1" name="default_useful_life_months" required></div>
<div class="finance-field"><label>{{ __('finance_workbench.assets.declining_rate') }}</label><input inputmode="decimal" name="default_declining_rate_percent"></div>
<div class="finance-field"><label>{{ __('finance_workbench.assets.asset_account') }}</label><select name="asset_account_id" required>@foreach($data['accounts'] as $a)<option value="{{ $a->id }}">{{ $a->code }} · {{ $a->name }}</option>@endforeach</select></div><div class="finance-field"><label>{{ __('finance_workbench.assets.accumulated_account') }}</label><select name="accumulated_depreciation_account_id" required>@foreach($data['accounts'] as $a)<option value="{{ $a->id }}">{{ $a->code }} · {{ $a->name }}</option>@endforeach</select></div><div class="finance-field"><label>{{ __('finance_workbench.assets.expense_account') }}</label><select name="depreciation_expense_account_id" required>@foreach($data['accounts'] as $a)<option value="{{ $a->id }}">{{ $a->code }} · {{ $a->name }}</option>@endforeach</select></div>
<div class="finance-field"><label>{{ __('finance_workbench.assets.disposal_gain_account') }}</label><select name="disposal_gain_account_id"><option value="">{{ __('finance_workbench.common.optional') }}</option>@foreach($data['accounts'] as $a)<option value="{{ $a->id }}">{{ $a->code }} · {{ $a->name }}</option>@endforeach</select></div><div class="finance-field"><label>{{ __('finance_workbench.assets.disposal_loss_account') }}</label><select name="disposal_loss_account_id"><option value="">{{ __('finance_workbench.common.optional') }}</option>@foreach($data['accounts'] as $a)<option value="{{ $a->id }}">{{ $a->code }} · {{ $a->name }}</option>@endforeach</select></div>
<button class="finance-button primary" type="submit">{{ __('finance_workbench.actions.create') }}</button></form></div></details>
<details class="finance-details"><summary>{{ __('finance_workbench.assets.new_asset') }}</summary><div class="finance-details-body"><form method="post" action="{{ route('admin.erp.finance.assets.store') }}" class="finance-form-grid">@csrf<input type="hidden" name="store_id" value="{{ $storeId }}"><input type="hidden" name="idempotency_key" value="asset-{{ now()->format('Ymd-His') }}">
<div class="finance-field"><label>{{ __('finance_workbench.assets.category') }}</label><select name="category_id" required>@foreach($data['categories'] as $category)<option value="{{ $category->id }}">{{ $category->code }} · {{ $category->name }}</option>@endforeach</select></div><div class="finance-field"><label>{{ __('finance_workbench.assets.asset_code') }}</label><input name="asset_code" required></div><div class="finance-field"><label>{{ __('finance_workbench.common.name') }}</label><input name="name" required></div>
<div class="finance-field"><label>{{ __('finance_workbench.assets.acquisition_date') }}</label><input type="date" name="acquisition_date" required></div><div class="finance-field"><label>{{ __('finance_workbench.assets.capitalization_date') }}</label><input type="date" name="capitalization_date" required></div><div class="finance-field"><label>{{ __('finance_workbench.assets.cost') }}</label><input inputmode="decimal" name="acquisition_amount" required></div><div class="finance-field"><label>{{ __('finance_workbench.assets.salvage') }}</label><input inputmode="decimal" name="salvage_value" value="0"></div><div class="finance-field"><label>{{ __('finance_workbench.assets.life_months') }}</label><input type="number" min="1" name="useful_life_months" required></div>
<div class="finance-field"><label>{{ __('finance_workbench.assets.offset_account') }}</label><select name="acquisition_offset_account_id" required>@foreach($data['accounts'] as $a)<option value="{{ $a->id }}">{{ $a->code }} · {{ $a->name }}</option>@endforeach</select></div><div class="finance-field"><label>{{ __('finance_workbench.statements.cost_center') }}</label><select name="cost_center_id"><option value=""></option>@foreach($data['costCenters'] as $center)<option value="{{ $center->id }}">{{ $center->code }} · {{ $center->name }}</option>@endforeach</select></div><div class="finance-field"><label>{{ __('finance_workbench.profit_centers.title') }}</label><select name="profit_center_id"><option value=""></option>@foreach($data['profitCenters'] as $center)<option value="{{ $center->id }}">{{ $center->code }} · {{ $center->name }}</option>@endforeach</select></div>
<button class="finance-button primary" type="submit">{{ __('finance_workbench.actions.create') }}</button></form></div></details>
@endif
<div class="finance-table-wrap"><table class="finance-table"><thead><tr><th>{{ __('finance_workbench.assets.asset_code') }}</th><th>{{ __('finance_workbench.common.name') }}</th><th>{{ __('finance_workbench.assets.category') }}</th><th>{{ __('finance_workbench.assets.cost') }}</th><th>{{ __('finance_workbench.common.status') }}</th>@if($access['foundation_manage'])<th>{{ __('finance_workbench.actions.manage') }}</th>@endif</tr></thead><tbody>
@forelse($data['assets'] as $asset)<tr><td><strong>{{ $asset->asset_code }}</strong></td><td>{{ $asset->name }}</td><td>{{ $asset->category?->code }} · {{ $asset->category?->name }}</td><td class="finance-money">{{ $money($asset->acquisition_amount) }}</td><td><span class="finance-status">{{ $statusLabel($asset->status) }}</span></td> {{-- localization-gate: allow status is rendered through the localized $status helper --}}
@if($access['foundation_manage'])<td>@if($asset->status==='active') {{-- localization-gate: allow technical status comparison; all visible labels use __() --}}<div class="finance-inline-actions"><form method="post" action="{{ route('admin.erp.finance.assets.depreciation',['asset'=>$asset->id]) }}">@csrf<input type="hidden" name="store_id" value="{{ $storeId }}"><select name="fiscal_period_id" required>@foreach($data['periods'] as $period)<option value="{{ $period->id }}">{{ $period->code }}</option>@endforeach</select><input type="hidden" name="idempotency_key" value="depreciation-{{ $asset->id }}-{{ now()->format('Ymd-His') }}"><button class="finance-button" type="submit">{{ __('finance_workbench.assets.depreciate') }}</button></form>
<details class="finance-details"><summary>{{ __('finance_workbench.assets.dispose') }}</summary><div class="finance-details-body"><form method="post" action="{{ route('admin.erp.finance.assets.disposal',['asset'=>$asset->id]) }}" class="finance-filter">@csrf<input type="hidden" name="store_id" value="{{ $storeId }}"><input type="hidden" name="idempotency_key" value="disposal-{{ $asset->id }}-{{ now()->format('Ymd-His') }}"><div class="finance-field"><label>{{ __('finance_workbench.assets.disposal_date') }}</label><input type="date" name="disposal_date" required></div><div class="finance-field"><label>{{ __('finance_workbench.assets.disposal_proceeds') }}</label><input inputmode="decimal" name="disposal_proceeds" value="0" required></div><div class="finance-field"><label>{{ __('finance_workbench.assets.settlement_account') }}</label><select name="settlement_account_id" required>@foreach($data['accounts'] as $a)<option value="{{ $a->id }}">{{ $a->code }} · {{ $a->name }}</option>@endforeach</select></div><button class="finance-button danger" type="submit">{{ __('finance_workbench.assets.prepare_disposal') }}</button></form></div></details></div>@else—@endif</td>@endif
</tr>@empty<tr><td colspan="6" class="finance-empty">{{ __('finance_workbench.common.no_data') }}</td></tr>@endforelse
</tbody></table></div><div class="finance-pagination">{{ $data['assets']->links() }}</div></section>
@endif

@if($tab==='reports')
<section class="finance-panel"><div class="finance-panel-head"><div><h2>{{ __('finance_workbench.reports.title') }}</h2><p>{{ __('finance_workbench.reports.help') }}</p></div><a class="finance-button" href="{{ route('admin.erp.bi') }}">{{ __('finance_workbench.reports.enterprise_reporting') }}</a></div>
<form method="get" action="{{ route('admin.erp.finance.index') }}" class="finance-form-grid"><input type="hidden" name="store_id" value="{{ $storeId }}"><input type="hidden" name="tab" value="reports">
<div class="finance-field"><label>{{ __('finance_workbench.reports.report') }}</label><select name="report">@foreach(['cash_flow','budget_vs_actual','tax_vat','consolidated','variance_exceptions','fixed_assets','close_status'] as $value)<option value="{{ $value }}" @selected($data['reportType']===$value)>{{ __('finance_workbench.reports.types.'.$value) }}</option>@endforeach</select></div>
<div class="finance-field"><label>{{ __('finance_workbench.common.period') }}</label><select name="period_id" required><option value=""></option>@foreach($data['periods'] as $period)<option value="{{ $period->id }}" @selected($data['periodId']===$period->id)>{{ $period->code }} · {{ $period->name }}</option>@endforeach</select></div>
<button class="finance-button primary" type="submit">{{ __('finance_workbench.actions.run_report') }}</button></form>
<div class="finance-notice"><strong>{{ __('finance_workbench.reports.export_policy') }}</strong><br>{{ __('finance_workbench.reports.export_help') }}</div>
@if($access['approval_decide'])
<details class="finance-details"><summary>{{ __('finance_workbench.reports.elimination_new') }}</summary><div class="finance-details-body">
<div class="finance-notice">{{ __('finance_workbench.reports.elimination_help') }}</div>
<form method="post" action="{{ route('admin.erp.finance.consolidation-eliminations.store') }}" class="finance-form-grid">@csrf<input type="hidden" name="store_id" value="{{ $storeId }}">
<div class="finance-field"><label>{{ __('finance_workbench.reports.fiscal_year_code') }}</label><input name="fiscal_year_code" required maxlength="64"></div>
<div class="finance-field"><label>{{ __('finance_workbench.reports.period_code') }}</label><input name="period_code" required maxlength="64"></div>
<div class="finance-field"><label>{{ __('finance_workbench.reports.account_type') }}</label><select name="account_type" required>@foreach(['asset','liability','equity','revenue','expense'] as $type)<option value="{{ $type }}">{{ __('finance_workbench.account_types.'.$type) }}</option>@endforeach</select></div>
<div class="finance-field"><label>{{ __('finance_workbench.common.debit') }}</label><input inputmode="decimal" name="debit" value="0" required></div>
<div class="finance-field"><label>{{ __('finance_workbench.common.credit') }}</label><input inputmode="decimal" name="credit" value="0" required></div>
<div class="finance-field" style="grid-column:1/-1"><label>{{ __('finance_workbench.common.description') }}</label><input name="description" required maxlength="255"></div>
<button class="finance-button primary" type="submit">{{ __('finance_workbench.reports.create_elimination') }}</button>
</form></div></details>
@endif
@if($data['report'])
<div class="finance-panel-head"><div><h3>{{ __('finance_workbench.reports.types.'.$data['report']['code']) }}</h3><p>{{ __('finance_workbench.reports.row_count',['count'=>$data['report']['row_count']]) }}</p></div></div>
@if($data['report']['lines']===[])<div class="finance-empty">{{ __('finance_workbench.common.no_data') }}</div>
@else
@php $columns=array_keys($data['report']['lines'][0]); @endphp
<div class="finance-table-wrap"><table class="finance-table"><thead><tr>@foreach($columns as $column)<th>{{ __('finance_workbench.report_columns.'.$column) }}</th>@endforeach</tr></thead><tbody>
@foreach($data['report']['lines'] as $row)<tr>@foreach($columns as $column)<td @class(['finance-money'=>is_numeric($row[$column] ?? null)])>@if(is_bool($row[$column] ?? null)){{ ($row[$column] ?? false)?__('finance_workbench.common.yes'):__('finance_workbench.common.no') }}@elseif(is_float($row[$column] ?? null)){{ $money($row[$column]) }}@else{{ $row[$column] ?? '—' }}@endif</td>@endforeach</tr>@endforeach
</tbody></table></div>
@endif
@endif
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
