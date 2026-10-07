@php
    $ar = app()->getLocale() === 'ar';
    $tab = $finance['tab'] ?? 'wallets';
    $rows = $finance['rows'] ?? [];
    $pagination = $finance['pagination'] ?? ['current_page'=>1,'last_page'=>1,'total'=>0];
    $statusLabel = static fn (string $status): string => __("van_finance_support.statuses.$status");
    $human = static fn (?string $value): string => $value === null || trim($value) === ''
        ? __('van_finance_support.not_available')
        : \Illuminate\Support\Str::headline($value);
@endphp
<!doctype html>
<html lang="{{ app()->getLocale() }}" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>{{ __('van_finance_support.title') }} · FOODEX</title>
@include('admin._brand-components')
<style>
body{margin:0;background:var(--foodex-page);color:var(--foodex-ink)}
.foodex-admin-layout{display:grid;grid-template-columns:minmax(0,1fr) var(--foodex-sidebar-width);min-height:100vh;width:100%}
html[dir=ltr] .foodex-admin-layout{grid-template-columns:var(--foodex-sidebar-width) minmax(0,1fr)}
.sidebar{grid-column:2;background:#fff;border-inline-start:1px solid var(--foodex-border)}
html[dir=ltr] .sidebar{grid-column:1;border-inline-start:0;border-inline-end:1px solid var(--foodex-border)}
.main{grid-column:1;min-width:0;padding:clamp(16px,2.4vw,34px)}
html[dir=ltr] .main{grid-column:2}
.header{display:flex;justify-content:space-between;gap:16px;align-items:flex-start;margin-bottom:18px}.header h1{margin:3px 0 7px}.muted{color:var(--foodex-muted)}
.card{background:#fff;border:1px solid var(--foodex-border);border-radius:var(--foodex-radius-card);box-shadow:var(--foodex-shadow-sm);padding:16px}
.tabs{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:14px}.tabs a{padding:9px 12px;border:1px solid var(--foodex-border);border-radius:10px;background:#fff;text-decoration:none;color:var(--foodex-ink);font-weight:800}.tabs a.active{background:var(--foodex-green);border-color:var(--foodex-green);color:#fff}
.filters{display:grid;grid-template-columns:minmax(220px,2fr) minmax(160px,1fr) 110px auto;gap:10px;align-items:end;margin-bottom:14px}.filters label{display:grid;gap:5px;font-weight:700}.filters input,.filters select{width:100%;min-height:42px;border:1px solid var(--foodex-border);border-radius:10px;padding:9px 11px;background:#fff}.filters button{min-height:42px;border:1px solid var(--foodex-green);border-radius:10px;padding:0 16px;background:var(--foodex-green);color:#fff;font-weight:800}
.table-wrap{overflow:auto}.finance-table{width:100%;border-collapse:collapse;min-width:920px}.finance-table th,.finance-table td{text-align:start;padding:10px;border-bottom:1px solid var(--foodex-border);white-space:nowrap}.finance-table th{font-size:.78rem;color:var(--foodex-muted);background:#fbfcfd}.state{display:inline-flex;padding:4px 8px;border-radius:999px;background:var(--foodex-green-soft);color:var(--foodex-green-dark);font-weight:800;font-size:.78rem}.empty{padding:28px;text-align:center;color:var(--foodex-muted)}
.footer{display:flex;justify-content:space-between;gap:12px;align-items:center;margin-top:14px;flex-wrap:wrap}.footer .links{display:flex;gap:10px;align-items:center}.footer a{color:var(--foodex-green-dark);font-weight:800}.action{display:inline-flex;align-items:center;justify-content:center;min-height:40px;padding:0 13px;border-radius:10px;background:#fff;color:var(--foodex-green-dark);border:1px solid #b9dfc5;text-decoration:none;font-weight:800}
@media(max-width:1023px){.foodex-admin-layout,html[dir=ltr] .foodex-admin-layout{grid-template-columns:1fr}.sidebar,.main,html[dir=ltr] .sidebar,html[dir=ltr] .main{grid-column:1}.sidebar{grid-row:1;border-inline:0;border-bottom:1px solid var(--foodex-border)}.main{grid-row:2}.filters{grid-template-columns:1fr 1fr}}
@media(max-width:680px){.header{flex-direction:column}.filters{grid-template-columns:1fr}.main{padding:14px}}
</style>
</head>
<body>
<div class="foodex-admin-layout" data-van-finance-support>
<aside class="sidebar">@include('admin._sidebar',['navGroups'=>$navGroups,'navContext'=>$navContext,'user'=>$user])</aside> {{-- localization-gate: allow Blade include expression --}}
<main class="main foodex-admin-main foodex-admin-page">
<header class="header foodex-page-header">
<div><span class="foodex-subtitle">FOODEX · {{ __('admin.administration_hub.van') }}</span><h1>{{ __('van_finance_support.title') }}</h1><p class="muted">{{ __('van_finance_support.description') }}</p></div>
<a class="action" href="{{ route('admin.field-operations.finance') }}">{{ __('van_finance_support.operational_link') }}</a>
</header>

<section class="card">
<nav class="tabs" aria-label="{{ __('van_finance_support.title') }}">
@foreach(['wallets','collections','remittances','reconciliation'] as $key)
<a href="{{ route('admin.van-finance-support.index', array_merge(request()->except(['ops_tab','ops_page']), ['ops_tab'=>$key])) }}" @class(['active'=>$tab===$key]) @if($tab===$key) aria-current="page" @endif>{{ __("van_finance_support.tabs.$key") }}</a>
@endforeach
</nav>

<form method="get" action="{{ route('admin.van-finance-support.index') }}" class="filters">
<input type="hidden" name="ops_tab" value="{{ $tab }}">
<label><span>{{ __('van_finance_support.search') }}</span><input type="search" name="ops_q" value="{{ $finance['q'] ?? '' }}" placeholder="{{ __('van_finance_support.search_placeholder') }}"></label>
<label><span>{{ __('van_finance_support.status') }}</span><select name="ops_status"><option value="">{{ __('van_finance_support.all_statuses') }}</option>@foreach(($finance['status_options'] ?? []) as $status)<option value="{{ $status }}" @selected(($finance['status'] ?? '')===$status)>{{ $statusLabel($status) }}</option>@endforeach</select></label>
<label><span>{{ __('van_finance_support.rows') }}</span><select name="ops_per_page">@foreach([10,25,50,100] as $size)<option value="{{ $size }}" @selected((int)($finance['per_page'] ?? 25)===$size)>{{ $size }}</option>@endforeach</select></label>
<button type="submit">{{ __('van_finance_support.apply') }}</button>
</form>

@if($rows===[])
<div class="empty">{{ __('van_finance_support.empty') }}</div>
@else
<div class="table-wrap"><table class="finance-table"><thead>
@if($tab==='wallets')
<tr><th>{{ __('van_finance_support.actor') }}</th><th>{{ __('van_finance_support.store') }}</th><th>{{ __('van_finance_support.currency') }}</th><th>{{ __('van_finance_support.custody') }}</th><th>{{ __('van_finance_support.pending_remittance') }}</th><th>{{ __('van_finance_support.available') }}</th><th>{{ __('van_finance_support.status') }}</th></tr>
@elseif($tab==='collections')
<tr><th>{{ __('van_finance_support.actor') }}</th><th>{{ __('van_finance_support.store') }}</th><th>{{ __('van_finance_support.amount') }}</th><th>{{ __('van_finance_support.source') }}</th><th>{{ __('van_finance_support.receipt_reference') }}</th><th>{{ __('van_finance_support.status') }}</th><th>{{ __('van_finance_support.time') }}</th></tr>
@else
<tr><th>{{ __('van_finance_support.actor') }}</th><th>{{ __('van_finance_support.store') }}</th><th>{{ __('van_finance_support.amount') }}</th><th>{{ __('van_finance_support.method') }}</th><th>{{ __('van_finance_support.reference') }}</th><th>{{ __('van_finance_support.status') }}</th>@if($tab==='reconciliation')<th>{{ __('van_finance_support.check') }}</th>@endif<th>{{ __('van_finance_support.reviewed_by') }}</th></tr>
@endif
</thead><tbody>
@foreach($rows as $row)
@if($tab==='wallets')
<tr><td>{{ $row['actor_display'] }}</td><td>{{ $row['store_display'] ?: __('van_finance_support.not_available') }}</td><td>{{ $row['currency'] }}</td><td>{{ number_format((float)$row['custody_balance'],3) }}</td><td>{{ number_format((float)$row['pending_remittance'],3) }}</td><td>{{ number_format((float)$row['available_to_remit'],3) }}</td><td><span class="state">{{ $statusLabel((string)$row['status']) }}</span></td></tr>
@elseif($tab==='collections')
<tr><td>{{ $row['actor_display'] }}</td><td>{{ $row['store_display'] ?: __('van_finance_support.not_available') }}</td><td>{{ $row['currency'] }} {{ number_format((float)$row['amount'],3) }}</td><td>{{ $human((string)($row['source'] ?? '')) }}</td><td>{{ $row['provider_reference'] ?: __('van_finance_support.receipt_unavailable') }}</td><td><span class="state">{{ $statusLabel((string)$row['status']) }}</span></td><td>{{ $row['created_at'] }}</td></tr>
@else
<tr><td>{{ $row['actor_display'] }}</td><td>{{ $row['store_display'] ?: __('van_finance_support.not_available') }}</td><td>{{ $row['currency'] }} {{ number_format((float)$row['amount'],3) }}</td><td>{{ $human((string)($row['method'] ?? '')) }}</td><td>{{ $row['reference'] ?: __('van_finance_support.not_available') }}</td><td><span class="state">{{ $statusLabel((string)$row['status']) }}</span></td>@if($tab==='reconciliation')<td>{{ (int)($row['has_exception'] ?? 0)===1 ? __('van_finance_support.custody_exception') : __('van_finance_support.clear') }}</td>@endif<td>{{ $row['reviewed_by_name'] ?: __('van_finance_support.not_available') }}</td></tr>
@endif
@endforeach
</tbody></table></div>
@endif

<div class="footer"><span class="muted">{{ __('van_finance_support.total') }}: {{ number_format((int)($pagination['total'] ?? 0)) }}</span><div class="links">@if(($pagination['current_page'] ?? 1)>1)<a href="{{ route('admin.van-finance-support.index', array_merge(request()->except('ops_page'), ['ops_page'=>(int)$pagination['current_page']-1])) }}">{{ __('van_finance_support.previous') }}</a>@endif<span>{{ (int)($pagination['current_page'] ?? 1) }} / {{ max(1,(int)($pagination['last_page'] ?? 1)) }}</span>@if(($pagination['current_page'] ?? 1)<($pagination['last_page'] ?? 1))<a href="{{ route('admin.van-finance-support.index', array_merge(request()->except('ops_page'), ['ops_page'=>(int)$pagination['current_page']+1])) }}">{{ __('van_finance_support.next') }}</a>@endif</div></div>
</section>
</main>
</div>
</body></html>
