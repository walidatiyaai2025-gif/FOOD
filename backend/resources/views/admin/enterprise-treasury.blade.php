<!doctype html>
<html lang="{{ app()->getLocale() }}" dir="{{ app()->getLocale()==='ar'?'rtl':'ltr' }}">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>{{ __('enterprise_treasury.title') }} · FOODEX</title>
@include('admin._brand-components')
<style>
.erp-treasury{display:grid;gap:16px}.erp-context{display:flex;gap:12px;align-items:end;justify-content:space-between;flex-wrap:wrap;padding:14px}.erp-context form,.erp-filter,.erp-form{display:flex;gap:10px;align-items:end;flex-wrap:wrap}.erp-form{display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));padding:14px}.erp-context label,.erp-filter label,.erp-form label{display:grid;gap:5px;font-weight:700;font-size:.82rem}.erp-actions{display:flex;gap:6px;flex-wrap:wrap;align-items:center}.erp-actions form{display:inline-flex;gap:6px}.erp-link{display:inline-flex;min-height:38px;align-items:center;padding:0 10px;border-radius:9px;border:1px solid var(--foodex-border);text-decoration:none;font-weight:800;background:transparent}.erp-danger{color:var(--foodex-danger-text)}.erp-table-wrap{overflow:auto}.erp-table td,.erp-table th{vertical-align:top}.erp-ref{white-space:nowrap;font-weight:900}.erp-create summary{cursor:pointer;font-weight:900;color:var(--foodex-green-dark);padding:12px 14px}.erp-create[open] summary{border-bottom:1px solid var(--foodex-border)}.erp-note{padding:12px 14px;color:var(--foodex-muted)}.erp-badge{white-space:nowrap}.erp-match{display:grid;gap:8px;min-width:260px}.erp-match select{max-width:280px}.erp-numeric{font-variant-numeric:tabular-nums;white-space:nowrap}.erp-dashboard-grid,.erp-report-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px}.erp-chart-card{padding:14px;min-width:0}.erp-chart-card h2,.erp-chart-card h3{margin:0 0 4px}.erp-chart-card .foodex-viz-bars{min-height:120px;height:150px}.erp-chart-legend{display:flex;gap:10px;flex-wrap:wrap;color:var(--foodex-muted);font-size:.78rem}.erp-report-actions{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px}.erp-report-actions form{display:grid;gap:8px;padding:14px}.erp-report-actions label{display:grid;gap:5px;font-weight:700}.erp-provenance{font-size:.78rem;color:var(--foodex-muted)}.erp-section-title{margin:0}.erp-section-copy{margin:4px 0 0;color:var(--foodex-muted)}.erp-positive{color:var(--foodex-green-dark)}.erp-negative{color:var(--foodex-danger-text)}
@media(max-width:1023px){.erp-dashboard-grid,.erp-report-grid{grid-template-columns:minmax(0,1fr)}}
@media(max-width:767px){.erp-context,.erp-filter{align-items:stretch}.erp-context form,.erp-filter{width:100%}.erp-table{min-width:820px}.erp-actions{min-width:220px}}
</style>
</head>
<body>
@php
$statusLabel=static fn($status)=>__('enterprise_treasury.statuses.'.($status?:'draft'));
$targetLabel=static fn($type,$id)=>$targetLabels[$type.':'.$id] ?? '—';
$targetOptions=[
    'cashbox'=>__('enterprise_treasury.cashbox'),
    'bank_account'=>__('enterprise_treasury.bank_account'),
];
@endphp
<div class="foodex-admin-layout">
<aside class="sidebar">@include('admin._sidebar',['navGroups'=>$navGroups,'navContext'=>$navContext,'user'=>$user])</aside> {{-- localization-gate: allow Blade include expression --}}
<main class="foodex-admin-main foodex-admin-page erp-treasury" data-erp-treasury-workbench="true">
<header class="foodex-page-header">
<div><div class="foodex-subtitle">{{ __('enterprise_treasury.eyebrow') }}</div><h1>{{ __('enterprise_treasury.title') }}</h1><p>{{ __('enterprise_treasury.description') }}</p></div>
<div class="erp-actions"><a class="foodex-primary" href="{{ route('admin.treasury.master.index',['store_id'=>$selectedStoreId]) }}">{{ __('enterprise_treasury.open_master_data') }}</a></div>
</header>

<section class="foodex-card erp-context">
<form method="get" action="{{ route('admin.erp.treasury.index') }}">
<input type="hidden" name="tab" value="{{ $activeTab }}">
<label>{{ __('enterprise_treasury.store') }}
<select name="store_id" onchange="this.form.submit()">
@foreach($storeOptions as $store)<option value="{{ $store['id'] }}" @selected($selectedStoreId===$store['id'])>{{ $store['name'] }} · {{ $store['code'] }}</option>@endforeach
</select></label>
<noscript><button class="foodex-primary">{{ __('enterprise_treasury.apply') }}</button></noscript>
</form>
<a class="erp-link" href="{{ route('admin.treasury.master.index',['store_id'=>$selectedStoreId]) }}">{{ __('enterprise_treasury.master_data') }}</a>
</section>

@if(session('status'))<div class="foodex-state" role="status">{{ session('status') }}</div>@endif
@if($errors->any())<div class="foodex-state" role="alert"><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
<div class="foodex-card erp-note">{{ __('enterprise_treasury.sod_notice') }}</div>

<nav class="foodex-tabs" aria-label="{{ __('enterprise_treasury.title') }}">
@foreach($tabs as $tab)
<a href="{{ route('admin.erp.treasury.index',['tab'=>$tab,'store_id'=>$selectedStoreId]) }}" class="{{ $activeTab===$tab?'active':'' }}" @if($activeTab===$tab) aria-current="page" @endif>{{ __('enterprise_treasury.tabs.'.$tab) }}</a>
@endforeach
</nav>

@if(in_array($activeTab,['receipts','payments','cheques','reconciliation','cash_closing'],true))
<form method="get" class="foodex-card erp-filter">
<input type="hidden" name="store_id" value="{{ $selectedStoreId }}"><input type="hidden" name="tab" value="{{ $activeTab }}">
@if(in_array($activeTab,['receipts','payments'],true))
<label>{{ __('enterprise_treasury.search') }}<input type="search" name="q" maxlength="120" value="{{ request('q') }}"></label>
<label>{{ __('enterprise_treasury.status') }}<select name="status"><option value="">{{ __('enterprise_treasury.all') }}</option>@foreach(['draft','posted','cancelled'] as $status)<option value="{{ $status }}" @selected(request('status')===$status)>{{ $statusLabel($status) }}</option>@endforeach</select></label>
<label>{{ __('enterprise_treasury.target_type') }}<select name="target_type"><option value="">{{ __('enterprise_treasury.all') }}</option>@foreach($targetOptions as $value=>$label)<option value="{{ $value }}" @selected(request('target_type')===$value)>{{ $label }}</option>@endforeach</select></label>
@elseif($activeTab==='cheques')
<label>{{ __('enterprise_treasury.status') }}<select name="status"><option value="">{{ __('enterprise_treasury.all') }}</option>@foreach(['issued','received','presented','deposited','cleared','bounced','cancelled'] as $status)<option value="{{ $status }}" @selected(request('status')===$status)>{{ $statusLabel($status) }}</option>@endforeach</select></label>
<label>{{ __('enterprise_treasury.cheques.direction') }}<select name="direction"><option value="">{{ __('enterprise_treasury.all') }}</option><option value="issued" @selected(request('direction')==='issued')>{{ __('enterprise_treasury.cheques.issued') }}</option><option value="received" @selected(request('direction')==='received')>{{ __('enterprise_treasury.cheques.received') }}</option></select></label>
<label>{{ __('enterprise_treasury.cheques.bank_account') }}<select name="bank_account_id"><option value="">{{ __('enterprise_treasury.all') }}</option>@foreach($bankAccounts as $bank)<option value="{{ $bank->id }}" @selected((int)request('bank_account_id')===$bank->id)>{{ $bank->code }} · {{ $bank->bank_name }} · ****{{ $bank->account_reference_last4 }}</option>@endforeach</select></label>
<label><span>{{ __('enterprise_treasury.cheques.overdue_only') }}</span><input type="checkbox" name="overdue" value="1" @checked(request()->boolean('overdue'))></label>
@elseif($activeTab==='reconciliation')
<label>{{ __('enterprise_treasury.status') }}<select name="status"><option value="">{{ __('enterprise_treasury.all') }}</option>@foreach(['draft','closed'] as $status)<option value="{{ $status }}" @selected(request('status')===$status)>{{ $statusLabel($status) }}</option>@endforeach</select></label>
<label>{{ __('enterprise_treasury.cheques.bank_account') }}<select name="bank_account_id"><option value="">{{ __('enterprise_treasury.all') }}</option>@foreach($bankAccounts as $bank)<option value="{{ $bank->id }}" @selected((int)request('bank_account_id')===$bank->id)>{{ $bank->code }} · {{ $bank->bank_name }} · ****{{ $bank->account_reference_last4 }}</option>@endforeach</select></label>
@elseif($activeTab==='cash_closing')
<label>{{ __('enterprise_treasury.status') }}<select name="status"><option value="">{{ __('enterprise_treasury.all') }}</option>@foreach(['draft','closed'] as $status)<option value="{{ $status }}" @selected(request('status')===$status)>{{ $statusLabel($status) }}</option>@endforeach</select></label>
<label>{{ __('enterprise_treasury.cashbox') }}<select name="cashbox_id"><option value="">{{ __('enterprise_treasury.all') }}</option>@foreach($cashboxes as $cashbox)<option value="{{ $cashbox->id }}" @selected((int)request('cashbox_id')===$cashbox->id)>{{ $cashbox->code }} · {{ $cashbox->name }}</option>@endforeach</select></label>
@endif
<label>{{ __('enterprise_treasury.date_from') }}<input type="date" name="date_from" value="{{ request('date_from') }}"></label>
<label>{{ __('enterprise_treasury.date_to') }}<input type="date" name="date_to" value="{{ request('date_to') }}"></label>
<button class="foodex-filter-action">{{ __('enterprise_treasury.apply') }}</button>
</form>
@endif

@if(in_array($activeTab,['overview','forecast','reports'],true))
@php
$moduleDashboardFilters=[
    'action'=>route('admin.erp.treasury.index'),
    'from_name'=>'from',
    'to_name'=>'to',
    'from_value'=>request('from'),
    'to_value'=>request('to'),
    'hidden'=>['store_id'=>$selectedStoreId,'tab'=>$activeTab],
    'reset_url'=>route('admin.erp.treasury.index',['store_id'=>$selectedStoreId,'tab'=>$activeTab]),
];
@endphp
@include('admin._module-dashboard-filters',['moduleDashboardFilters'=>$moduleDashboardFilters])
@endif

@if($activeTab==='financing')
<form method="get" class="foodex-card erp-filter">
<input type="hidden" name="store_id" value="{{ $selectedStoreId }}"><input type="hidden" name="tab" value="financing">
<label>{{ __('enterprise_treasury.search') }}<input type="search" name="q" maxlength="120" value="{{ request('q') }}"></label>
<label>{{ __('enterprise_treasury.status') }}<select name="status"><option value="">{{ __('enterprise_treasury.all') }}</option>@foreach(['active','closed','cancelled'] as $status)<option value="{{ $status }}" @selected(request('status')===$status)>{{ $statusLabel($status) }}</option>@endforeach</select></label>
<label>{{ __('enterprise_treasury.financing.facility') }}<select name="facility_id"><option value="">{{ __('enterprise_treasury.all') }}</option>@foreach($facilityOptions as $facility)<option value="{{ $facility->id }}" @selected((int)request('facility_id')===(int)$facility->id)>{{ $facility->code }} · {{ $facility->lender_name }}</option>@endforeach</select></label>
<label>{{ __('enterprise_treasury.financing.repayment_schedule') }}<select name="repayment_status"><option value="">{{ __('enterprise_treasury.all') }}</option>@foreach(['scheduled','paid','cancelled'] as $status)<option value="{{ $status }}" @selected(request('repayment_status')===$status)>{{ $statusLabel($status) }}</option>@endforeach</select></label>
<label>{{ __('enterprise_treasury.date_from') }}<input type="date" name="date_from" value="{{ request('date_from') }}"></label>
<label>{{ __('enterprise_treasury.date_to') }}<input type="date" name="date_to" value="{{ request('date_to') }}"></label>
<button class="foodex-filter-action">{{ __('enterprise_treasury.apply') }}</button>
</form>
@endif

@if($activeTab==='investments')
<form method="get" class="foodex-card erp-filter">
<input type="hidden" name="store_id" value="{{ $selectedStoreId }}"><input type="hidden" name="tab" value="investments">
<label>{{ __('enterprise_treasury.search') }}<input type="search" name="q" maxlength="120" value="{{ request('q') }}"></label>
<label>{{ __('enterprise_treasury.status') }}<select name="status"><option value="">{{ __('enterprise_treasury.all') }}</option>@foreach(['active','matured','settled','cancelled'] as $status)<option value="{{ $status }}" @selected(request('status')===$status)>{{ $statusLabel($status) }}</option>@endforeach</select></label>
<button class="foodex-filter-action">{{ __('enterprise_treasury.apply') }}</button>
</form>
@endif

@if($activeTab==='overview')
<section class="foodex-module-overview" data-foodex-module-overview data-state="{{ $dashboard['state'] ?? 'current' }}">
<div class="foodex-card erp-note">
<strong>{{ __('enterprise_treasury.dashboard.fx_policy') }}</strong>
<span class="erp-provenance">{{ __('enterprise_treasury.dashboard.as_of') }} {{ $dashboard['as_of'] ?? '—' }}</span>
</div>
<div class="foodex-module-kpis">
@foreach(($dashboard['kpis'] ?? []) as $kpi)
@include('admin._module-dashboard-kpi',['metric'=>$kpi])
@endforeach
</div>

<div class="erp-dashboard-grid">
@php
$positionRows=collect($dashboard['cash_position'] ?? []);
$maxPosition=max(1,$positionRows->map(fn($row)=>abs((int)($row['balance_mills'] ?? 0)))->max() ?? 1);
@endphp
<section class="foodex-card foodex-module-chart erp-chart-card" data-foodex-module-chart aria-labelledby="treasury-cash-position-title">
<h2 id="treasury-cash-position-title">{{ __('enterprise_treasury.dashboard.cash_position') }}</h2>
<p class="foodex-module-chart-summary">{{ __('enterprise_treasury.dashboard.cash_position_summary') }}</p>
@if($positionRows->isNotEmpty())
<div class="foodex-viz-bars" role="img" aria-label="{{ __('enterprise_treasury.dashboard.cash_position') }}">
@foreach($positionRows->take(12) as $row)<span class="foodex-viz-bar" data-series="{{ (int)$row['balance_mills']<0?4:1 }}" style="height:{{ max(8,(int)round(abs((int)$row['balance_mills'])/$maxPosition*100)) }}%" title="{{ $row['target'] }} · {{ $row['balance'] }} {{ $row['currency'] }}"></span>@endforeach
</div>
<div class="foodex-module-chart-table-wrap"><table class="foodex-module-chart-table"><thead><tr><th>{{ __('enterprise_treasury.target') }}</th><th>{{ __('enterprise_treasury.currency') }}</th><th>{{ __('enterprise_treasury.amount') }}</th></tr></thead><tbody>@foreach($positionRows as $row)<tr><td>{{ $row['target'] }}</td><td>{{ $row['currency'] }}</td><td class="erp-numeric {{ (int)$row['balance_mills']<0?'erp-negative':'erp-positive' }}">{{ $row['balance'] }}</td></tr>@endforeach</tbody></table></div>
@else
@include('admin._module-dashboard-state',['state'=>'empty'])
@endif
</section>

@php
$trendRows=collect($dashboard['actual_trend'] ?? [])->take(-14);
$maxTrend=max(1,$trendRows->map(fn($row)=>max((float)$row['inflow'],(float)$row['outflow']))->max() ?? 1);
@endphp
<section class="foodex-card foodex-module-chart erp-chart-card" data-foodex-module-chart aria-labelledby="treasury-trend-title">
<h2 id="treasury-trend-title">{{ __('enterprise_treasury.dashboard.inflow_outflow') }}</h2>
@if($trendRows->isNotEmpty())
<div class="foodex-viz-bars" role="img" aria-label="{{ __('enterprise_treasury.dashboard.inflow_outflow') }}">@foreach($trendRows as $row)<span class="foodex-viz-bar" data-series="1" style="height:{{ max(6,(int)round(((float)$row['inflow'])/$maxTrend*100)) }}%" title="{{ $row['date'] }} · {{ $row['inflow'] }} {{ $row['currency'] }}"></span><span class="foodex-viz-bar" data-series="2" style="height:{{ max(6,(int)round(((float)$row['outflow'])/$maxTrend*100)) }}%" title="{{ $row['date'] }} · {{ $row['outflow'] }} {{ $row['currency'] }}"></span>@endforeach</div>
<div class="erp-chart-legend"><span>● {{ __('enterprise_treasury.forecast.inflow') }}</span><span>● {{ __('enterprise_treasury.forecast.outflow') }}</span></div>
<div class="foodex-module-chart-table-wrap"><table class="foodex-module-chart-table"><thead><tr><th>{{ __('enterprise_treasury.value_date') }}</th><th>{{ __('enterprise_treasury.currency') }}</th><th>{{ __('enterprise_treasury.forecast.inflow') }}</th><th>{{ __('enterprise_treasury.forecast.outflow') }}</th></tr></thead><tbody>@foreach($trendRows as $row)<tr><td>{{ $row['date'] }}</td><td>{{ $row['currency'] }}</td><td>{{ $row['inflow'] }}</td><td>{{ $row['outflow'] }}</td></tr>@endforeach</tbody></table></div>
@else @include('admin._module-dashboard-state',['state'=>'empty']) @endif
</section>

<section class="foodex-card foodex-module-chart erp-chart-card" data-foodex-module-chart aria-labelledby="treasury-rec-title">
<h2 id="treasury-rec-title">{{ __('enterprise_treasury.dashboard.reconciliation') }}</h2>
@php $recRows=collect($dashboard['reconciliation_status'] ?? []); $maxRec=max(1,(int)($recRows->max('records') ?? 1)); @endphp
@if($recRows->isNotEmpty())<div class="foodex-viz-bars" role="img" aria-label="{{ __('enterprise_treasury.dashboard.reconciliation') }}">@foreach($recRows as $row)<span class="foodex-viz-bar" data-series="{{ $row['status']==='closed'?1:3 }}" style="height:{{ max(8,(int)round(((int)$row['records'])/$maxRec*100)) }}%" title="{{ $statusLabel($row['status']) }} · {{ $row['records'] }}"></span>@endforeach</div>
<div class="foodex-module-chart-table-wrap"><table class="foodex-module-chart-table"><thead><tr><th>{{ __('enterprise_treasury.status') }}</th><th>{{ __('enterprise_treasury.currency') }}</th><th>#</th></tr></thead><tbody>@foreach($recRows as $row)<tr><td>{{ $statusLabel($row['status']) }}</td><td>{{ $row['currency'] }}</td><td>{{ $row['records'] }}</td></tr>@endforeach</tbody></table></div>
@else @include('admin._module-dashboard-state',['state'=>'empty']) @endif
</section>

<section class="foodex-card foodex-module-chart erp-chart-card" data-foodex-module-chart aria-labelledby="treasury-cheque-title">
<h2 id="treasury-cheque-title">{{ __('enterprise_treasury.dashboard.cheque_maturity') }}</h2>
@php $chequeRows=collect($dashboard['cheque_maturity'] ?? []); $maxCheque=max(1,(int)($chequeRows->max('count') ?? 1)); @endphp
<div class="foodex-viz-bars" role="img" aria-label="{{ __('enterprise_treasury.dashboard.cheque_maturity') }}">@foreach($chequeRows as $row)<span class="foodex-viz-bar" data-series="{{ $row['bucket']==='overdue'?4:($row['bucket']==='next_7_days'?3:1) }}" style="height:{{ max(8,(int)round(((int)$row['count'])/$maxCheque*100)) }}%" title="{{ $row['bucket'] }} · {{ $row['count'] }}"></span>@endforeach</div>
<div class="foodex-module-chart-table-wrap"><table class="foodex-module-chart-table"><tbody>@foreach($chequeRows as $row)<tr><th>{{ __('enterprise_treasury.dashboard.maturity_buckets.'.$row['bucket']) }}</th><td>{{ $row['count'] }}</td></tr>@endforeach</tbody></table></div>
</section>

<section class="foodex-card foodex-module-chart erp-chart-card" data-foodex-module-chart aria-labelledby="treasury-forecast-title">
<h2 id="treasury-forecast-title">{{ __('enterprise_treasury.dashboard.forecast') }}</h2>
@php $forecastChart=collect($dashboard['forecast'] ?? [])->take(20); $maxForecast=max(1,$forecastChart->map(fn($row)=>max((float)$row['inflow'],(float)$row['outflow']))->max() ?? 1); @endphp
@if($forecastChart->isNotEmpty())<div class="foodex-viz-bars" role="img" aria-label="{{ __('enterprise_treasury.dashboard.forecast') }}">@foreach($forecastChart as $row)<span class="foodex-viz-bar" data-series="1" style="height:{{ max(6,(int)round(((float)$row['inflow'])/$maxForecast*100)) }}%" title="{{ $row['date'] }} · {{ $row['inflow'] }} {{ $row['currency'] }}"></span><span class="foodex-viz-bar" data-series="2" style="height:{{ max(6,(int)round(((float)$row['outflow'])/$maxForecast*100)) }}%" title="{{ $row['date'] }} · {{ $row['outflow'] }} {{ $row['currency'] }}"></span>@endforeach</div>
<div class="foodex-module-chart-table-wrap"><table class="foodex-module-chart-table"><thead><tr><th>{{ __('enterprise_treasury.value_date') }}</th><th>{{ __('enterprise_treasury.currency') }}</th><th>{{ __('enterprise_treasury.forecast.net') }}</th></tr></thead><tbody>@foreach($forecastChart as $row)<tr><td>{{ $row['date'] }}</td><td>{{ $row['currency'] }}</td><td>{{ $row['net'] }}</td></tr>@endforeach</tbody></table></div>
@else @include('admin._module-dashboard-state',['state'=>'empty']) @endif
</section>

<section class="foodex-card foodex-module-chart erp-chart-card" data-foodex-module-chart aria-labelledby="treasury-repayment-title">
<h2 id="treasury-repayment-title">{{ __('enterprise_treasury.dashboard.repayment') }}</h2>
@php $repaymentChart=collect($dashboard['repayment_profile'] ?? [])->take(20); $maxRepayment=max(1,$repaymentChart->map(fn($row)=>(float)$row['principal']+(float)$row['interest'])->max() ?? 1); @endphp
@if($repaymentChart->isNotEmpty())<div class="foodex-viz-bars" role="img" aria-label="{{ __('enterprise_treasury.dashboard.repayment') }}">@foreach($repaymentChart as $row)<span class="foodex-viz-bar" data-series="3" style="height:{{ max(8,(int)round((((float)$row['principal']+(float)$row['interest'])/$maxRepayment)*100)) }}%" title="{{ $row['facility'] }} · {{ $row['date'] }}"></span>@endforeach</div>
<div class="foodex-module-chart-table-wrap"><table class="foodex-module-chart-table"><thead><tr><th>{{ __('enterprise_treasury.financing.facility') }}</th><th>{{ __('enterprise_treasury.financing.due_date') }}</th><th>{{ __('enterprise_treasury.financing.principal') }}</th><th>{{ __('enterprise_treasury.financing.interest') }}</th></tr></thead><tbody>@foreach($repaymentChart as $row)<tr><td>{{ $row['facility'] }}</td><td>{{ $row['date'] }}</td><td>{{ $row['principal'] }} {{ $row['currency'] }}</td><td>{{ $row['interest'] }} {{ $row['currency'] }}</td></tr>@endforeach</tbody></table></div>
@else @include('admin._module-dashboard-state',['state'=>'empty']) @endif
</section>
</div>
</section>
@endif

@if($activeTab==='financing')
@if($canManage)
<details class="foodex-card erp-create"><summary>+ {{ __('enterprise_treasury.financing.new_facility') }}</summary>
<form method="post" action="{{ route('admin.erp.treasury.facilities.store') }}" class="erp-form">@csrf
<input type="hidden" name="store_id" value="{{ $selectedStoreId }}"><input type="hidden" name="_operation_key" value="{{ $facilityToken }}">
<label>{{ __('enterprise_treasury.financing.facility_code') }}<input name="code" maxlength="64" required></label>
<label>{{ __('enterprise_treasury.financing.lender') }}<input name="lender_name" maxlength="190" required></label>
<label>{{ __('enterprise_treasury.currency') }}<input name="currency_code" maxlength="3" minlength="3" required></label>
<label>{{ __('enterprise_treasury.financing.facility_limit') }}<input type="number" name="facility_limit" min="0.001" step="0.001" required></label>
<label>{{ __('enterprise_treasury.financing.annual_rate') }}<input type="number" name="annual_rate" min="0" max="100" step="0.000001" value="0" required></label>
<label>{{ __('enterprise_treasury.financing.margin_rate') }}<input type="number" name="margin_rate" min="0" max="100" step="0.000001" value="0" required></label>
<label>{{ __('enterprise_treasury.financing.start_date') }}<input type="date" name="start_date" value="{{ now()->toDateString() }}" required></label>
<label>{{ __('enterprise_treasury.financing.maturity_date') }}<input type="date" name="maturity_date" required></label>
<label>{{ __('enterprise_treasury.finance_journal') }} <span class="erp-provenance">({{ __('enterprise_treasury.optional') }})</span><select name="finance_journal_id"><option value="">—</option>@foreach($financeJournals as $journal)<option value="{{ $journal->id }}">{{ $journal->journal_number }} · {{ $journal->entry_date }} · {{ $journal->description ?: '—' }}</option>@endforeach</select></label>
<div><button class="foodex-primary">{{ __('enterprise_treasury.financing.new_facility') }}</button></div>
</form></details>

<details class="foodex-card erp-create"><summary>+ {{ __('enterprise_treasury.financing.new_drawdown') }}</summary>
<form method="post" action="{{ route('admin.erp.treasury.drawdowns.store') }}" class="erp-form">@csrf
<input type="hidden" name="store_id" value="{{ $selectedStoreId }}"><input type="hidden" name="_operation_key" value="{{ $drawdownToken }}">
<label>{{ __('enterprise_treasury.financing.facility') }}<select name="facility_id" required>@foreach($facilityOptions as $facility)<option value="{{ $facility->id }}">{{ $facility->code }} · {{ $facility->lender_name }} · {{ $facility->currency_code }}</option>@endforeach</select></label>
<label>{{ __('enterprise_treasury.financing.drawdown_reference') }}<input name="reference" maxlength="80" required></label>
<label>{{ __('enterprise_treasury.financing.principal') }}<input type="number" name="principal_amount" min="0.001" step="0.001" required></label>
<label>{{ __('enterprise_treasury.financing.scheduled_interest') }}<input type="number" name="scheduled_interest_total" min="0" step="0.001" value="0" required></label>
<label>{{ __('enterprise_treasury.financing.start_date') }}<input type="date" name="drawdown_date" value="{{ now()->toDateString() }}" required></label>
<label>{{ __('enterprise_treasury.financing.maturity_date') }}<input type="date" name="maturity_date" required></label>
<label>{{ __('enterprise_treasury.financing.installments') }}<input type="number" name="installment_count" min="1" max="120" value="12" required></label>
<label>{{ __('enterprise_treasury.finance_journal') }} <span class="erp-provenance">({{ __('enterprise_treasury.optional') }})</span><select name="finance_journal_id"><option value="">—</option>@foreach($financeJournals as $journal)<option value="{{ $journal->id }}">{{ $journal->journal_number }} · {{ $journal->entry_date }}</option>@endforeach</select></label>
<div><button class="foodex-primary">{{ __('enterprise_treasury.financing.new_drawdown') }}</button></div>
</form></details>
@endif

<section class="foodex-card erp-table-wrap"><table class="foodex-table erp-table"><thead><tr><th>{{ __('enterprise_treasury.financing.facility_code') }}</th><th>{{ __('enterprise_treasury.financing.lender') }}</th><th>{{ __('enterprise_treasury.financing.facility_limit') }}</th><th>{{ __('enterprise_treasury.financing.drawn') }}</th><th>{{ __('enterprise_treasury.financing.available') }}</th><th>{{ __('enterprise_treasury.financing.annual_rate') }}</th><th>{{ __('enterprise_treasury.financing.margin_rate') }}</th><th>{{ __('enterprise_treasury.financing.maturity_date') }}</th><th>{{ __('enterprise_treasury.status') }}</th></tr></thead><tbody>
@forelse($rows as $row)
@php $available=(float)$row->facility_limit-(float)$row->drawn_principal; @endphp
<tr><td class="erp-ref">{{ $row->code }}</td><td>{{ $row->lender_name }}</td><td class="erp-numeric">{{ $row->facility_limit }} {{ $row->currency_code }}</td><td class="erp-numeric">{{ $row->drawn_principal }} {{ $row->currency_code }}</td><td class="erp-numeric">{{ number_format($available,3,'.','') }} {{ $row->currency_code }}</td><td>{{ $row->annual_rate }}%</td><td>{{ $row->margin_rate }}%</td><td>{{ $row->maturity_date }}</td><td><span class="badge">{{ $statusLabel($row->status) }}</span></td></tr>
@empty<tr><td colspan="9"><div class="foodex-empty-state">{{ __('enterprise_treasury.empty') }}</div></td></tr>@endforelse
</tbody></table></section>
{{ $rows->links() }}

<section class="foodex-card"><div class="erp-note"><h2 class="erp-section-title">{{ __('enterprise_treasury.financing.repayment_schedule') }}</h2><p class="erp-section-copy">{{ __('enterprise_treasury.financing.journal_required') }}</p></div>
<div class="erp-table-wrap"><table class="foodex-table erp-table"><thead><tr><th>{{ __('enterprise_treasury.financing.facility') }}</th><th>{{ __('enterprise_treasury.financing.drawdown_reference') }}</th><th>{{ __('enterprise_treasury.financing.installment') }}</th><th>{{ __('enterprise_treasury.financing.due_date') }}</th><th>{{ __('enterprise_treasury.financing.principal') }}</th><th>{{ __('enterprise_treasury.financing.interest') }}</th><th>{{ __('enterprise_treasury.status') }}</th><th>{{ __('enterprise_treasury.actions') }}</th></tr></thead><tbody>
@forelse($repayments as $row)<tr><td>{{ $row->facility_code }} · {{ $row->lender_name }}</td><td>{{ $row->drawdown_reference }}</td><td>{{ $row->installment_number }}</td><td>{{ $row->due_date }}</td><td class="erp-numeric">{{ $row->principal_amount }} {{ $row->currency_code }}</td><td class="erp-numeric">{{ $row->interest_amount }} {{ $row->currency_code }}</td><td><span class="badge">{{ $statusLabel($row->status) }}</span></td><td>@if($canManage && $row->status==='scheduled')<form method="post" action="{{ route('admin.erp.treasury.repayments.paid',$row->id) }}" class="erp-actions">@csrf<input type="hidden" name="store_id" value="{{ $selectedStoreId }}"><select name="finance_journal_id" required><option value="">{{ __('enterprise_treasury.finance_journal') }}</option>@foreach($financeJournals as $journal)<option value="{{ $journal->id }}">{{ $journal->journal_number }} · {{ $journal->entry_date }}</option>@endforeach</select><button class="foodex-primary">{{ __('enterprise_treasury.financing.mark_paid') }}</button></form>@endif</td></tr>
@empty<tr><td colspan="8"><div class="foodex-empty-state">{{ __('enterprise_treasury.empty') }}</div></td></tr>@endforelse
</tbody></table></div></section>
{{ $repayments->links() }}
@endif

@if($activeTab==='investments')
@if($canManage)
<details class="foodex-card erp-create"><summary>+ {{ __('enterprise_treasury.investments.new') }}</summary>
<form method="post" action="{{ route('admin.erp.treasury.investments.store') }}" class="erp-form">@csrf
<input type="hidden" name="store_id" value="{{ $selectedStoreId }}"><input type="hidden" name="_operation_key" value="{{ $investmentToken }}">
<label>{{ __('enterprise_treasury.investments.code') }}<input name="code" maxlength="64" required></label>
<label>{{ __('enterprise_treasury.investments.institution') }}<input name="institution" maxlength="190" required></label>
<label>{{ __('enterprise_treasury.investments.instrument_type') }}<input name="instrument_type" maxlength="100" required></label>
<label>{{ __('enterprise_treasury.currency') }}<input name="currency_code" minlength="3" maxlength="3" required></label>
<label>{{ __('enterprise_treasury.investments.principal') }}<input type="number" name="principal_amount" min="0.001" step="0.001" required></label>
<label>{{ __('enterprise_treasury.investments.annual_yield_rate') }}<input type="number" name="annual_yield_rate" min="0" max="100" step="0.000001" value="0" required></label>
<label>{{ __('enterprise_treasury.investments.expected_return') }}<input type="number" name="expected_return_amount" min="0" step="0.001" value="0" required></label>
<label>{{ __('enterprise_treasury.investments.placement_date') }}<input type="date" name="placement_date" value="{{ now()->toDateString() }}" required></label>
<label>{{ __('enterprise_treasury.investments.maturity_date') }}<input type="date" name="maturity_date" required></label>
<label>{{ __('enterprise_treasury.finance_journal') }} <span class="erp-provenance">({{ __('enterprise_treasury.optional') }})</span><select name="finance_journal_id"><option value="">—</option>@foreach($financeJournals as $journal)<option value="{{ $journal->id }}">{{ $journal->journal_number }} · {{ $journal->entry_date }}</option>@endforeach</select></label>
<div><button class="foodex-primary">{{ __('enterprise_treasury.investments.new') }}</button></div>
</form></details>
@endif
<div class="foodex-card erp-note">{{ __('enterprise_treasury.investments.journal_required') }}</div>
<section class="foodex-card erp-table-wrap"><table class="foodex-table erp-table"><thead><tr><th>{{ __('enterprise_treasury.investments.code') }}</th><th>{{ __('enterprise_treasury.investments.institution') }}</th><th>{{ __('enterprise_treasury.investments.instrument_type') }}</th><th>{{ __('enterprise_treasury.investments.principal') }}</th><th>{{ __('enterprise_treasury.investments.expected_return') }}</th><th>{{ __('enterprise_treasury.investments.maturity_date') }}</th><th>{{ __('enterprise_treasury.status') }}</th><th>{{ __('enterprise_treasury.actions') }}</th></tr></thead><tbody>
@forelse($rows as $row)<tr><td class="erp-ref">{{ $row->code }}</td><td>{{ $row->institution }}</td><td>{{ $row->instrument_type }}</td><td class="erp-numeric">{{ $row->principal_amount }} {{ $row->currency_code }}</td><td class="erp-numeric">{{ $row->expected_return_amount }} {{ $row->currency_code }}</td><td>{{ $row->maturity_date }}</td><td><span class="badge">{{ $statusLabel($row->status) }}</span></td><td>@if($canManage && $row->status==='active')<form method="post" action="{{ route('admin.erp.treasury.investments.settle',$row->id) }}" class="erp-match">@csrf<input type="hidden" name="store_id" value="{{ $selectedStoreId }}"><label>{{ __('enterprise_treasury.investments.settlement_reference') }}<input name="settlement_reference" maxlength="120" required></label><label>{{ __('enterprise_treasury.finance_journal') }}<select name="finance_journal_id" required>@foreach($financeJournals as $journal)<option value="{{ $journal->id }}">{{ $journal->journal_number }} · {{ $journal->entry_date }}</option>@endforeach</select></label><button class="foodex-primary">{{ __('enterprise_treasury.investments.settle') }}</button></form>@endif</td></tr>
@empty<tr><td colspan="8"><div class="foodex-empty-state">{{ __('enterprise_treasury.empty') }}</div></td></tr>@endforelse
</tbody></table></section>
{{ $rows->links() }}
@endif

@if($activeTab==='forecast')
<section class="foodex-module-overview" data-foodex-module-overview data-state="{{ $forecast['state'] ?? 'current' }}">
<div class="foodex-card erp-note"><strong>{{ __('enterprise_treasury.forecast.title') }}</strong><p>{{ __('enterprise_treasury.forecast.description') }}</p><span>{{ __('enterprise_treasury.forecast.opening') }}: {{ $forecast['opening_by_currency'] ?? '—' }}</span><br><span class="erp-provenance">{{ __('enterprise_treasury.dashboard.fx_policy') }}</span></div>
<section class="foodex-card erp-table-wrap"><table class="foodex-table erp-table"><thead><tr><th>{{ __('enterprise_treasury.value_date') }}</th><th>{{ __('enterprise_treasury.forecast.source') }}</th><th>{{ __('enterprise_treasury.currency') }}</th><th>{{ __('enterprise_treasury.forecast.inflow') }}</th><th>{{ __('enterprise_treasury.forecast.outflow') }}</th><th>{{ __('enterprise_treasury.forecast.net') }}</th><th>{{ __('enterprise_treasury.forecast.running_balance') }}</th></tr></thead><tbody>
@forelse(($forecast['rows'] ?? []) as $row)<tr><td>{{ $row['date'] }}</td><td>{{ __('enterprise_treasury.forecast.sources.'.$row['source']) }}</td><td>{{ $row['currency'] }}</td><td class="erp-numeric">{{ $row['inflow'] }}</td><td class="erp-numeric">{{ $row['outflow'] }}</td><td class="erp-numeric">{{ $row['net'] }}</td><td class="erp-numeric">{{ $row['running_balance'] }}</td></tr>
@empty<tr><td colspan="7"><div class="foodex-empty-state">{{ __('enterprise_treasury.empty') }}</div></td></tr>@endforelse
</tbody></table></section>
</section>
@endif

@if($activeTab==='reports')
<section class="foodex-module-overview" data-foodex-module-overview data-state="current">
<div class="foodex-card erp-note"><h2 class="erp-section-title">{{ __('enterprise_treasury.reports.title') }}</h2><p class="erp-section-copy">{{ __('enterprise_treasury.reports.description') }}</p></div>
@if($canManage)
<div class="erp-report-actions">
<form method="post" action="{{ route('admin.erp.treasury.reports.export') }}" class="foodex-card">@csrf
<input type="hidden" name="store_id" value="{{ $selectedStoreId }}"><input type="hidden" name="_operation_key" value="{{ $reportToken }}-export">
<label>{{ __('enterprise_treasury.reports.title') }}<select name="report_code" required>@foreach(\App\Enterprise\Treasury\TreasuryAnalyticsService::REPORTS as $code=>$key)<option value="{{ $code }}">{{ __('enterprise_treasury.reports.'.$key) }}</option>@endforeach</select></label>
<label>{{ __('enterprise_treasury.date_from') }}<input type="date" name="from" value="{{ request('from') }}"></label><label>{{ __('enterprise_treasury.date_to') }}<input type="date" name="to" value="{{ request('to') }}"></label>
<button class="foodex-primary">{{ __('enterprise_treasury.reports.export') }}</button>
</form>
<form method="post" action="{{ route('admin.erp.treasury.reports.schedule') }}" class="foodex-card">@csrf
<input type="hidden" name="store_id" value="{{ $selectedStoreId }}"><input type="hidden" name="_operation_key" value="{{ $reportToken }}-schedule">
<label>{{ __('enterprise_treasury.reports.title') }}<select name="report_code" required>@foreach(\App\Enterprise\Treasury\TreasuryAnalyticsService::REPORTS as $code=>$key)<option value="{{ $code }}">{{ __('enterprise_treasury.reports.'.$key) }}</option>@endforeach</select></label>
<label>{{ __('enterprise_treasury.reports.next_run_at') }}<input type="datetime-local" name="next_run_at" required></label>
<label>{{ __('enterprise_treasury.reports.interval_minutes') }}<input type="number" name="interval_minutes" min="60" max="525600" value="1440" required></label>
<button class="foodex-primary">{{ __('enterprise_treasury.reports.schedule') }}</button>
</form>
</div>
@endif

<div class="erp-report-grid">
@foreach([
'daily_position'=>['target','currency','balance'],
'forecast'=>['date','source','currency','inflow','outflow','net'],
'bank_reconciliation'=>['reference','bank_name','period_from','period_to','currency','status'],
'cheque_maturity'=>['reference','bank_name','direction','due_date','amount','currency','status'],
'facility_repayment'=>['facility_code','drawdown_reference','due_date','principal_amount','interest_amount','currency','status'],
'investment_maturity'=>['code','institution','instrument_type','maturity_date','principal_amount','expected_return_amount','currency','status'],
'exceptions'=>['exception','count','severity'],
] as $reportKey=>$columns)
<section class="foodex-card erp-chart-card">
<h3>{{ __('enterprise_treasury.reports.'.$reportKey) }}</h3>
<div class="foodex-module-chart-table-wrap"><table class="foodex-module-chart-table"><thead><tr>@foreach($columns as $column)<th>{{ __('enterprise_treasury.reports.columns.'.$column) }}</th>@endforeach</tr></thead><tbody>
@forelse(collect($reports[$reportKey] ?? [])->take(20) as $row)<tr>@foreach($columns as $column)@php
$cell=$row[$column] ?? null;
if($column==='status' && is_string($cell)){$cell=$statusLabel($cell);}
elseif($column==='source' && is_string($cell)){$cell=__('enterprise_treasury.forecast.sources.'.$cell);}
elseif($column==='direction' && is_string($cell)){$cell=__('enterprise_treasury.cheques.'.$cell);}
elseif($column==='severity' && is_string($cell)){$cell=__('enterprise_treasury.reports.severity.'.$cell);}
elseif($column==='basis' && $cell==='posted_treasury_ledger'){$cell=__('enterprise_treasury.reports.posted_ledger');}
@endphp<td>{{ is_scalar($cell)?$cell:'—' }}</td>@endforeach</tr>@empty<tr><td colspan="{{ count($columns) }}">{{ __('enterprise_treasury.empty') }}</td></tr>@endforelse
</tbody></table></div>
</section>
@endforeach
</div>
</section>
@endif

@if($activeTab==='receipts')
@if($canManage)
<details class="foodex-card erp-create"><summary>+ {{ __('enterprise_treasury.receipts.new') }}</summary>
<form method="post" action="{{ route('admin.erp.treasury.receipts.store') }}" class="erp-form">@csrf
<input type="hidden" name="store_id" value="{{ $selectedStoreId }}"><input type="hidden" name="_operation_key" value="{{ $receiptToken }}">
<label>{{ __('enterprise_treasury.target') }}<select name="target_key" required><optgroup label="{{ __('enterprise_treasury.cashbox') }}">@foreach($cashboxes as $row)<option value="cashbox:{{ $row->id }}">{{ $row->code }} · {{ $row->name }} · {{ $row->currency_code }}</option>@endforeach</optgroup><optgroup label="{{ __('enterprise_treasury.bank_account') }}">@foreach($bankAccounts as $row)<option value="bank_account:{{ $row->id }}">{{ $row->code }} · {{ $row->bank_name }} · ****{{ $row->account_reference_last4 }}</option>@endforeach</optgroup></select></label>
<label>{{ __('enterprise_treasury.amount') }}<input type="number" name="amount" step="0.001" min="0.001" required></label>
<label>{{ __('enterprise_treasury.value_date') }}<input type="date" name="value_date" value="{{ now()->toDateString() }}" required></label>
<label>{{ __('enterprise_treasury.reference') }}<input name="reference" maxlength="120" required></label>
<label>{{ __('enterprise_treasury.description_label') }}<input name="description" maxlength="1000"></label>
<div><button class="foodex-primary">{{ __('enterprise_treasury.create') }}</button></div>
</form></details>
@endif
<section class="foodex-card erp-table-wrap"><table class="foodex-table erp-table"><thead><tr><th>{{ __('enterprise_treasury.receipts.number') }}</th><th>{{ __('enterprise_treasury.target') }}</th><th>{{ __('enterprise_treasury.amount') }}</th><th>{{ __('enterprise_treasury.value_date') }}</th><th>{{ __('enterprise_treasury.reference') }}</th><th>{{ __('enterprise_treasury.status') }}</th><th>{{ __('enterprise_treasury.actions') }}</th></tr></thead><tbody>
@forelse($rows as $row)<tr><td class="erp-ref">{{ $row->receipt_number }}</td><td>{{ $targetLabel($row->target_type,$row->target_id) }}</td><td class="erp-numeric">{{ $row->amount }} {{ $row->currency_code }}</td><td>{{ $row->value_date?->toDateString() ?? $row->value_date }}</td><td>{{ $row->reference }}</td><td><span class="badge erp-badge">{{ $statusLabel($row->status) }}</span></td><td><div class="erp-actions">@if($canManage && $row->status==='draft')<form method="post" action="{{ route('admin.erp.treasury.receipts.post',$row) }}">@csrf<input type="hidden" name="store_id" value="{{ $selectedStoreId }}"><button class="erp-link">{{ __('enterprise_treasury.post') }}</button></form><form method="post" action="{{ route('admin.erp.treasury.receipts.cancel',$row) }}">@csrf<input type="hidden" name="store_id" value="{{ $selectedStoreId }}"><button class="erp-link erp-danger">{{ __('enterprise_treasury.cancel') }}</button></form>@endif</div></td></tr> {{-- localization-gate: allow non-visible receipt status control flow; visible labels are translated --}}
@empty<tr><td colspan="7"><div class="foodex-empty-state">{{ __('enterprise_treasury.empty') }}</div></td></tr>@endforelse
</tbody></table></section>
@endif

@if($activeTab==='payments')
@if($canManage)
<details class="foodex-card erp-create"><summary>+ {{ __('enterprise_treasury.payments.new') }}</summary>
<form method="post" action="{{ route('admin.erp.treasury.payments.store') }}" class="erp-form">@csrf
<input type="hidden" name="store_id" value="{{ $selectedStoreId }}"><input type="hidden" name="_operation_key" value="{{ $paymentToken }}">
<label>{{ __('enterprise_treasury.target') }}<select name="target_key" required><optgroup label="{{ __('enterprise_treasury.cashbox') }}">@foreach($cashboxes as $row)<option value="cashbox:{{ $row->id }}">{{ $row->code }} · {{ $row->name }} · {{ $row->currency_code }}</option>@endforeach</optgroup><optgroup label="{{ __('enterprise_treasury.bank_account') }}">@foreach($bankAccounts as $row)<option value="bank_account:{{ $row->id }}">{{ $row->code }} · {{ $row->bank_name }} · ****{{ $row->account_reference_last4 }}</option>@endforeach</optgroup></select></label>
<label>{{ __('enterprise_treasury.amount') }}<input type="number" name="amount" step="0.001" min="0.001" required></label>
<label>{{ __('enterprise_treasury.value_date') }}<input type="date" name="value_date" value="{{ now()->toDateString() }}" required></label>
<label>{{ __('enterprise_treasury.reference') }}<input name="reference" maxlength="120" required></label>
<label>{{ __('enterprise_treasury.beneficiary') }}<input name="beneficiary" maxlength="190"></label>
<label>{{ __('enterprise_treasury.description_label') }}<input name="description" maxlength="1000"></label>
<div><button class="foodex-primary">{{ __('enterprise_treasury.create') }}</button></div>
</form></details>
@endif
<section class="foodex-card erp-table-wrap"><table class="foodex-table erp-table"><thead><tr><th>{{ __('enterprise_treasury.payments.number') }}</th><th>{{ __('enterprise_treasury.target') }}</th><th>{{ __('enterprise_treasury.beneficiary') }}</th><th>{{ __('enterprise_treasury.amount') }}</th><th>{{ __('enterprise_treasury.value_date') }}</th><th>{{ __('enterprise_treasury.status') }}</th><th>{{ __('enterprise_treasury.actions') }}</th></tr></thead><tbody>
@forelse($rows as $row)<tr><td class="erp-ref">{{ $row->payment_number }}</td><td>{{ $targetLabel($row->target_type,$row->target_id) }}</td><td>{{ $row->beneficiary ?: '—' }}</td><td class="erp-numeric">{{ $row->amount }} {{ $row->currency_code }}</td><td>{{ $row->value_date?->toDateString() ?? $row->value_date }}</td><td><span class="badge erp-badge">{{ $statusLabel($row->status) }}</span></td><td><div class="erp-actions">@if($canManage && $row->status==='draft')<form method="post" action="{{ route('admin.erp.treasury.payments.post',$row) }}">@csrf<input type="hidden" name="store_id" value="{{ $selectedStoreId }}"><button class="erp-link">{{ __('enterprise_treasury.post') }}</button></form><form method="post" action="{{ route('admin.erp.treasury.payments.cancel',$row) }}">@csrf<input type="hidden" name="store_id" value="{{ $selectedStoreId }}"><button class="erp-link erp-danger">{{ __('enterprise_treasury.cancel') }}</button></form>@endif</div></td></tr> {{-- localization-gate: allow non-visible payment status control flow; visible labels are translated --}}
@empty<tr><td colspan="7"><div class="foodex-empty-state">{{ __('enterprise_treasury.empty') }}</div></td></tr>@endforelse
</tbody></table></section>
@endif

@if($activeTab==='cheques')
@if($canManage)
<details class="foodex-card erp-create"><summary>+ {{ __('enterprise_treasury.cheques.new') }}</summary>
<form method="post" action="{{ route('admin.erp.treasury.cheques.store') }}" class="erp-form">@csrf
<input type="hidden" name="store_id" value="{{ $selectedStoreId }}"><input type="hidden" name="_operation_key" value="{{ $chequeToken }}">
<label>{{ __('enterprise_treasury.cheques.direction') }}<select name="direction" required><option value="issued">{{ __('enterprise_treasury.cheques.issued') }}</option><option value="received">{{ __('enterprise_treasury.cheques.received') }}</option></select></label>
<label>{{ __('enterprise_treasury.cheques.source') }}<select name="treasury_reference_key" required><optgroup label="{{ __('enterprise_treasury.reconciliation.receipt') }}">@foreach($postedReceipts as $source)<option value="receipt:{{ $source->id }}">{{ $source->receipt_number }} · {{ $source->amount }} {{ $source->currency_code }} · {{ $source->reference }}</option>@endforeach</optgroup><optgroup label="{{ __('enterprise_treasury.reconciliation.payment') }}">@foreach($postedPayments as $source)<option value="payment:{{ $source->id }}">{{ $source->payment_number }} · {{ $source->amount }} {{ $source->currency_code }} · {{ $source->reference }}</option>@endforeach</optgroup></select></label>
<label>{{ __('enterprise_treasury.cheques.bank_account') }}<select name="bank_account_id" required>@foreach($bankAccounts as $bank)<option value="{{ $bank->id }}">{{ $bank->code }} · {{ $bank->bank_name }} · ****{{ $bank->account_reference_last4 }}</option>@endforeach</select></label>
<label>{{ __('enterprise_treasury.cheques.instrument_number') }}<input name="instrument_number" maxlength="80" required></label>
<label>{{ __('enterprise_treasury.cheques.issue_date') }}<input type="date" name="issue_date" value="{{ now()->toDateString() }}" required></label>
<label>{{ __('enterprise_treasury.cheques.due_date') }}<input type="date" name="due_date" value="{{ now()->toDateString() }}" required></label>
<label>{{ __('enterprise_treasury.cheques.payee_or_drawer') }}<input name="payee_or_drawer" maxlength="190"></label>
<div><button class="foodex-primary">{{ __('enterprise_treasury.create') }}</button></div>
</form></details>
@endif
<section class="foodex-card erp-table-wrap"><table class="foodex-table erp-table"><thead><tr><th>{{ __('enterprise_treasury.cheques.instrument_number') }}</th><th>{{ __('enterprise_treasury.cheques.direction') }}</th><th>{{ __('enterprise_treasury.cheques.bank_account') }}</th><th>{{ __('enterprise_treasury.amount') }}</th><th>{{ __('enterprise_treasury.cheques.due_date') }}</th><th>{{ __('enterprise_treasury.status') }}</th><th>{{ __('enterprise_treasury.actions') }}</th></tr></thead><tbody>
@forelse($rows as $row)<tr><td class="erp-ref">{{ $row->instrument_number }}</td><td>{{ $row->direction==='issued'?__('enterprise_treasury.cheques.issued'):__('enterprise_treasury.cheques.received') }}</td><td>{{ $targetLabels['bank_account:'.$row->bank_account_id] ?? '—' }}</td><td class="erp-numeric">{{ $row->amount }} {{ $row->currency_code }}</td><td>{{ $row->due_date?->toDateString() ?? $row->due_date }}</td><td><span class="badge erp-badge">{{ $statusLabel($row->status) }}</span></td><td>@if($canManage && !in_array($row->status,['cleared','cancelled'],true))<form method="post" action="{{ route('admin.erp.treasury.cheques.transition',$row) }}" class="erp-match">@csrf<input type="hidden" name="store_id" value="{{ $selectedStoreId }}"><input type="hidden" name="_operation_key" value="{{ $transitionToken }}-{{ $row->id }}"><label>{{ __('enterprise_treasury.cheques.to_status') }}<select name="to_status">@foreach(['presented','deposited','cleared','bounced','cancelled'] as $status)<option value="{{ $status }}">{{ $statusLabel($status) }}</option>@endforeach</select></label><label>{{ __('enterprise_treasury.cheques.reason') }}<input name="reason" maxlength="255"></label><button class="erp-link">{{ __('enterprise_treasury.cheques.transition') }}</button></form>@endif</td></tr> {{-- localization-gate: allow non-visible cheque lifecycle control flow; visible labels are translated --}}
@empty<tr><td colspan="7"><div class="foodex-empty-state">{{ __('enterprise_treasury.empty') }}</div></td></tr>@endforelse
</tbody></table></section>
@endif

@if($activeTab==='reconciliation')
@if($canManage)
<details class="foodex-card erp-create"><summary>+ {{ __('enterprise_treasury.reconciliation.new') }}</summary>
<form method="post" action="{{ route('admin.erp.treasury.reconciliation.store') }}" class="erp-form">@csrf
<input type="hidden" name="store_id" value="{{ $selectedStoreId }}"><input type="hidden" name="_operation_key" value="{{ $reconciliationToken }}">
<label>{{ __('enterprise_treasury.cheques.bank_account') }}<select name="bank_account_id" required>@foreach($bankAccounts as $bank)<option value="{{ $bank->id }}">{{ $bank->code }} · {{ $bank->bank_name }} · ****{{ $bank->account_reference_last4 }}</option>@endforeach</select></label>
<label>{{ __('enterprise_treasury.reconciliation.period_from') }}<input type="date" name="period_from" required></label>
<label>{{ __('enterprise_treasury.reconciliation.period_to') }}<input type="date" name="period_to" required></label>
<label>{{ __('enterprise_treasury.reconciliation.statement_receipts_total') }}<input type="number" name="statement_receipts_total" step="0.001" min="0" required></label>
<label>{{ __('enterprise_treasury.reconciliation.statement_payments_total') }}<input type="number" name="statement_payments_total" step="0.001" min="0" required></label>
<div><button class="foodex-primary">{{ __('enterprise_treasury.create') }}</button></div>
</form></details>
@endif
<section class="foodex-card erp-table-wrap"><table class="foodex-table erp-table"><thead><tr><th>{{ __('enterprise_treasury.reconciliation.number') }}</th><th>{{ __('enterprise_treasury.cheques.bank_account') }}</th><th>{{ __('enterprise_treasury.reconciliation.period') }}</th><th>{{ __('enterprise_treasury.reconciliation.statement_receipts_total') }}</th><th>{{ __('enterprise_treasury.reconciliation.statement_payments_total') }}</th><th>{{ __('enterprise_treasury.status') }}</th><th>{{ __('enterprise_treasury.actions') }}</th></tr></thead><tbody>
@forelse($rows as $row)<tr><td class="erp-ref">{{ $row->reconciliation_number }}</td><td>{{ $targetLabels['bank_account:'.$row->bank_account_id] ?? '—' }}</td><td>{{ $row->period_from?->toDateString() ?? $row->period_from }} → {{ $row->period_to?->toDateString() ?? $row->period_to }}</td><td class="erp-numeric">{{ $row->statement_receipts_total }} {{ $row->currency_code }}</td><td class="erp-numeric">{{ $row->statement_payments_total }} {{ $row->currency_code }}</td><td><span class="badge erp-badge">{{ $statusLabel($row->status) }}</span></td><td><div class="erp-actions">@if($canManage && $row->status==='draft')<form method="post" action="{{ route('admin.erp.treasury.reconciliation.matches.store',$row) }}" class="erp-match">@csrf<input type="hidden" name="store_id" value="{{ $selectedStoreId }}"><label>{{ __('enterprise_treasury.reconciliation.transaction_id') }}<select name="transaction_key" required><optgroup label="{{ __('enterprise_treasury.reconciliation.receipt') }}">@foreach($postedReceipts as $source)<option value="receipt:{{ $source->id }}">{{ $source->receipt_number }} · {{ $source->amount }} {{ $source->currency_code }} · {{ $source->reference }}</option>@endforeach</optgroup><optgroup label="{{ __('enterprise_treasury.reconciliation.payment') }}">@foreach($postedPayments as $source)<option value="payment:{{ $source->id }}">{{ $source->payment_number }} · {{ $source->amount }} {{ $source->currency_code }} · {{ $source->reference }}</option>@endforeach</optgroup></select></label><button class="erp-link">{{ __('enterprise_treasury.reconciliation.add_match') }}</button></form><form method="post" action="{{ route('admin.erp.treasury.reconciliation.close',$row) }}">@csrf<input type="hidden" name="store_id" value="{{ $selectedStoreId }}"><button class="erp-link">{{ __('enterprise_treasury.reconciliation.close') }}</button></form>@endif</div></td></tr> {{-- localization-gate: allow non-visible reconciliation status control flow; visible labels are translated --}}
@empty<tr><td colspan="7"><div class="foodex-empty-state">{{ __('enterprise_treasury.empty') }}</div></td></tr>@endforelse
</tbody></table></section>
@endif

@if($activeTab==='cash_closing')
@if($canManage)
<details class="foodex-card erp-create"><summary>+ {{ __('enterprise_treasury.cash_closing.new') }}</summary>
<form method="post" action="{{ route('admin.erp.treasury.cash-closing.store') }}" class="erp-form">@csrf
<input type="hidden" name="store_id" value="{{ $selectedStoreId }}"><input type="hidden" name="_operation_key" value="{{ $cashClosingToken }}">
<label>{{ __('enterprise_treasury.cashbox') }}<select name="cashbox_id" required>@foreach($cashboxes as $cashbox)<option value="{{ $cashbox->id }}">{{ $cashbox->code }} · {{ $cashbox->name }} · {{ $cashbox->currency_code }}</option>@endforeach</select></label>
<label>{{ __('enterprise_treasury.cash_closing.business_date') }}<input type="date" name="business_date" value="{{ now()->toDateString() }}" required></label>
<label>{{ __('enterprise_treasury.cash_closing.opening_amount') }}<input type="number" name="opening_amount" step="0.001" min="0" required></label>
<label>{{ __('enterprise_treasury.cash_closing.declared_closing_amount') }}<input type="number" name="declared_closing_amount" step="0.001" min="0" required></label>
<div><button class="foodex-primary">{{ __('enterprise_treasury.create') }}</button></div>
</form></details>
@endif
<section class="foodex-card erp-table-wrap"><table class="foodex-table erp-table"><thead><tr><th>{{ __('enterprise_treasury.cash_closing.number') }}</th><th>{{ __('enterprise_treasury.cashbox') }}</th><th>{{ __('enterprise_treasury.cash_closing.business_date') }}</th><th>{{ __('enterprise_treasury.cash_closing.opening_amount') }}</th><th>{{ __('enterprise_treasury.cash_closing.receipt_total') }}</th><th>{{ __('enterprise_treasury.cash_closing.payment_total') }}</th><th>{{ __('enterprise_treasury.cash_closing.expected_closing_amount') }}</th><th>{{ __('enterprise_treasury.cash_closing.declared_closing_amount') }}</th><th>{{ __('enterprise_treasury.status') }}</th><th>{{ __('enterprise_treasury.actions') }}</th></tr></thead><tbody>
@forelse($rows as $row)<tr><td class="erp-ref">{{ $row->closing_number }}</td><td>{{ $targetLabels['cashbox:'.$row->cashbox_id] ?? '—' }}</td><td>{{ $row->business_date?->toDateString() ?? $row->business_date }}</td><td class="erp-numeric">{{ $row->opening_amount }} {{ $row->currency_code }}</td><td class="erp-numeric">{{ $row->receipt_total }} {{ $row->currency_code }}</td><td class="erp-numeric">{{ $row->payment_total }} {{ $row->currency_code }}</td><td class="erp-numeric">{{ $row->expected_closing_amount }} {{ $row->currency_code }}</td><td class="erp-numeric">{{ $row->declared_closing_amount }} {{ $row->currency_code }}</td><td><span class="badge erp-badge">{{ $statusLabel($row->status) }}</span></td><td>@if($canManage && $row->status==='draft')<form method="post" action="{{ route('admin.erp.treasury.cash-closing.close',$row) }}">@csrf<input type="hidden" name="store_id" value="{{ $selectedStoreId }}"><button class="erp-link">{{ __('enterprise_treasury.cash_closing.close') }}</button></form>@endif</td></tr> {{-- localization-gate: allow non-visible cash-closing status control flow; visible labels are translated --}}
@empty<tr><td colspan="10"><div class="foodex-empty-state">{{ __('enterprise_treasury.empty') }}</div></td></tr>@endforelse
</tbody></table></section>
@endif

@if($rows && method_exists($rows,'links'))<div>{{ $rows->links() }}</div>@endif
</main>
</div>
</body>
</html>
