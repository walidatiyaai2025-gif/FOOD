<!doctype html>
<html lang="{{ str_replace('_','-',app()->getLocale()) }}" dir="{{ app()->getLocale()==='ar'?'rtl':'ltr' }}">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>{{ $customer->name }} · {{ __('customer_360.title') }} · FOODEX</title>
@include('admin._brand-components')
<link rel="stylesheet" href="{{ asset('assets/leaflet/1.9.4/leaflet.css') }}">
<style>
body{margin:0;background:var(--foodex-background);color:var(--foodex-ink)}
.c360-shell{display:grid;gap:16px}
.c360-topline{display:flex;align-items:flex-start;justify-content:space-between;gap:16px;flex-wrap:wrap}
.c360-breadcrumbs{display:flex;align-items:center;gap:8px;color:var(--foodex-muted);font-size:.86rem;margin-top:5px}
.c360-breadcrumbs a{color:inherit;text-decoration:none}.c360-breadcrumbs a:hover{color:var(--foodex-green)}
.c360-summary{display:flex;align-items:center;justify-content:space-between;gap:18px;padding:20px 22px}
.c360-person{display:flex;align-items:center;gap:14px;min-width:0}.c360-avatar{width:68px;height:68px;border-radius:50%;display:grid;place-items:center;background:#eef7ff;font-size:34px;flex:0 0 auto}
.c360-person-copy{min-width:0}.c360-person-copy h2{margin:0;font-size:1.35rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.c360-person-copy p{margin:5px 0 0;color:var(--foodex-muted)}
.c360-actions{display:flex;align-items:center;gap:8px;flex-wrap:wrap}
.c360-tabs-wrap{background:var(--foodex-surface);border:1px solid var(--foodex-border);border-radius:var(--foodex-radius-card);box-shadow:var(--foodex-shadow-sm);overflow-x:auto;scrollbar-width:thin}
.c360-tabs{display:flex;min-width:max-content}
.c360-tab{appearance:none;border:0;border-bottom:2px solid transparent;background:transparent;color:var(--foodex-ink);padding:14px 22px;font:inherit;font-weight:800;cursor:pointer;white-space:nowrap;display:flex;align-items:center;gap:8px;min-height:54px}
.c360-tab:hover{background:#f8fbf9}.c360-tab[aria-selected="true"]{color:var(--foodex-green-dark);border-bottom-color:var(--foodex-green);background:var(--foodex-green-soft)}
.c360-tab:focus-visible{outline:3px solid rgba(22,163,74,.18);outline-offset:-3px}
.c360-tab-icon{width:26px;height:26px;border:1px solid var(--foodex-border);border-radius:8px;display:grid;place-items:center;background:#fff;font-size:14px}
.c360-panel{display:none}.c360-panel.active{display:block}
.c360-card{padding:20px}.c360-card h2,.c360-card h3{margin:0}.c360-card p{color:var(--foodex-muted)}
.c360-section-head{display:flex;justify-content:space-between;align-items:center;gap:12px;margin-bottom:16px}
.c360-kpis{display:grid;grid-template-columns:repeat(var(--foodex-card-columns,6),minmax(0,1fr));gap:12px;margin-bottom:14px}
.c360-kpi{border:1px solid var(--foodex-border);border-radius:14px;padding:16px;background:#fff;display:grid;gap:6px;min-height:100px}
.c360-kpi small{color:var(--foodex-muted);font-weight:700}.c360-kpi strong{font-size:1.22rem;word-break:break-word}.c360-kpi.primary{background:linear-gradient(135deg,#f5fff8,#fff)}
.c360-credit-editor{display:flex;align-items:end;gap:8px;margin-top:4px}.c360-credit-editor label{display:grid;gap:5px;min-width:0;flex:1}.c360-credit-editor input{width:100%;box-sizing:border-box;border:1px solid var(--foodex-border);border-radius:9px;padding:9px 10px;background:#fff;font:inherit}.c360-credit-editor button{white-space:nowrap}
.c360-info{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px}.c360-info>div{padding:14px;border:1px solid var(--foodex-border);border-radius:12px;background:#fbfcfd}.c360-info small{display:block;color:var(--foodex-muted);margin-bottom:5px}.c360-info strong{word-break:break-word}
.c360-identity-admin{display:grid;grid-template-columns:minmax(0,1.25fr) minmax(0,.9fr);gap:14px;margin-top:16px}.c360-identity-admin>div{border:1px solid var(--foodex-border);border-radius:14px;padding:16px;background:var(--foodex-background)}.c360-identity-admin h3{margin:0 0 5px}.c360-identity-admin p{margin:0 0 14px}.c360-login-state{display:flex;align-items:center;justify-content:space-between;gap:10px;margin-bottom:12px;padding:10px 12px;border:1px solid var(--foodex-border);border-radius:10px;background:var(--foodex-surface)}
.c360-badge{display:inline-flex;align-items:center;gap:5px;border:1px solid var(--foodex-border);border-radius:999px;padding:5px 9px;font-size:12px;font-weight:800;background:#fff}.c360-badge.active{background:var(--foodex-green-soft);color:var(--foodex-green-dark);border-color:transparent}.c360-badge.b2b{background:#fff7ed;color:#9a3412}.c360-badge.b2c{background:#eefbf4;color:#166534}
.c360-list{display:flex;gap:8px;flex-wrap:wrap}.c360-store{padding:10px 13px;border:1px solid var(--foodex-border);border-radius:10px;background:#fff;font-weight:800}
.c360-address-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(300px,1fr));gap:12px}.c360-address-card{padding:15px;border:1px solid var(--foodex-border);border-radius:12px;background:#fff}.c360-address-actions{display:flex;gap:8px;flex-wrap:wrap;align-items:center}.c360-address-form{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}.c360-address-form .wide{grid-column:1/-1}.c360-address-form input,.c360-address-form textarea,.c360-address-form select{width:100%;box-sizing:border-box}.c360-authoritative-field{display:grid;gap:5px;align-content:start;padding:10px 12px;border:1px solid var(--foodex-border);border-radius:10px;background:var(--foodex-background)}.c360-authoritative-field small{color:var(--foodex-muted)}.c360-authoritative-field strong{font-size:1rem}
.c360-table-wrap{overflow:auto}.c360-table{width:100%;border-collapse:collapse;min-width:760px}.c360-table th,.c360-table td{padding:12px;border-bottom:1px solid var(--foodex-border);text-align:start;vertical-align:middle}.c360-table th{font-size:12px;color:var(--foodex-muted);background:#f8fafc}.c360-table tr:hover td{background:#fbfefc}
.c360-empty{padding:42px 20px;text-align:center;color:var(--foodex-muted);border:1px dashed var(--foodex-border);border-radius:12px;background:#fbfcfd}
.c360-report-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px}.c360-report-card{border:1px solid var(--foodex-border);border-radius:14px;padding:16px;background:var(--foodex-surface);display:grid;gap:12px}.c360-report-card h3{font-size:1rem}.c360-report-form{display:grid;grid-template-columns:1fr 1fr;gap:8px}.c360-report-form label{display:grid;gap:4px}.c360-report-form input{width:100%;box-sizing:border-box}.c360-report-form .wide{grid-column:1/-1}
.c360-finance-details{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:0;border:1px solid var(--foodex-border);border-radius:14px;overflow:hidden}.c360-finance-details>div{padding:15px;border-inline-end:1px solid var(--foodex-border);border-bottom:1px solid var(--foodex-border)}.c360-finance-details>div:nth-child(3n){border-inline-end:0}.c360-finance-details small{display:block;color:var(--foodex-muted);margin-bottom:5px}
.c360-form-details{margin-top:16px;border-top:1px solid var(--foodex-border);padding-top:14px}.c360-form-details summary{cursor:pointer;font-weight:800}
.c360-invoice-picker{position:relative;display:grid;gap:5px}.c360-invoice-picker-row{display:flex;gap:7px;align-items:center}.c360-invoice-picker-row input[type="search"]{flex:1;min-width:0}.c360-invoice-results{position:absolute;z-index:80;inset-inline:0;top:100%;margin-top:4px;max-height:280px;overflow:auto;background:var(--foodex-surface);border:1px solid var(--foodex-border);border-radius:10px;box-shadow:var(--foodex-shadow-raised)}.c360-invoice-results[hidden]{display:none}.c360-invoice-option{display:grid;gap:3px;width:100%;padding:10px 12px;border:0;border-bottom:1px solid var(--foodex-border);background:var(--foodex-surface);text-align:start;cursor:pointer;font:inherit}.c360-invoice-option:last-child{border-bottom:0}.c360-invoice-option[aria-selected="true"],.c360-invoice-option[data-active="1"]{background:var(--foodex-green-soft)}.c360-invoice-option small,.c360-invoice-picker-help,.c360-invoice-picker-status{color:var(--foodex-muted);font-size:.78rem}.c360-invoice-picker-status{min-height:1.2em}.c360-invoice-clear[hidden]{display:none}
.c360-map-picker-row{display:flex;align-items:center;gap:10px;flex-wrap:wrap;padding:12px;border:1px dashed var(--foodex-border);border-radius:12px;background:#fbfcfd}
.c360-map-picked{color:var(--foodex-muted);font-size:.82rem;direction:ltr}
.c360-map-modal{position:fixed;inset:0;z-index:2500;display:none;place-items:center;padding:18px;background:rgba(15,23,42,.48);backdrop-filter:blur(2px)}
.c360-map-modal[data-open="1"]{display:grid}
.c360-map-dialog{width:min(920px,calc(100vw - 24px));max-height:92vh;background:#fff;border-radius:16px;box-shadow:0 28px 80px rgba(15,23,42,.28);overflow:hidden;border:1px solid var(--foodex-border)}
.c360-map-head{display:flex;align-items:flex-start;justify-content:space-between;gap:14px;padding:16px 18px;border-bottom:1px solid var(--foodex-border)}
.c360-map-head h3{margin:0}.c360-map-head p{margin:4px 0 0;color:var(--foodex-muted);font-size:.86rem}
.c360-map-close{width:36px;height:36px;border:1px solid var(--foodex-border);border-radius:9px;background:#fff;font-size:22px;cursor:pointer}
.c360-map-body{padding:16px;display:grid;gap:12px}
.c360-map-search{display:flex;gap:8px}.c360-map-search input{flex:1;min-width:0}
.c360-map-search-state{display:none;align-items:center;justify-content:space-between;gap:10px;padding:10px 12px;border:1px solid var(--foodex-border);border-radius:10px;background:var(--foodex-background)}
.c360-map-search-state[data-visible="1"]{display:flex}.c360-map-search-state [data-address-map-search-message]{min-width:0}
.c360-map-results{display:none;max-height:150px;overflow:auto;border:1px solid var(--foodex-border);border-radius:10px;background:#fff}
.c360-map-results[data-visible="1"]{display:block}
.c360-map-result{display:block;width:100%;text-align:start;border:0;border-bottom:1px solid var(--foodex-border);background:#fff;padding:10px 12px;cursor:pointer;font:inherit}.c360-map-result:last-child{border-bottom:0}.c360-map-result:hover{background:var(--foodex-background)}
#c360-address-map{height:min(56vh,500px);min-height:340px;border-radius:12px;border:1px solid var(--foodex-border);overflow:hidden}
.c360-map-footer{display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap}.c360-map-coords{font-family:var(--foodex-font-en);color:var(--foodex-muted);font-size:.82rem;direction:ltr}
@media(max-width:1100px){.c360-kpis{grid-template-columns:repeat(2,1fr)}.c360-finance-details{grid-template-columns:repeat(2,1fr)}.c360-finance-details>div:nth-child(3n){border-inline-end:1px solid var(--foodex-border)}.c360-finance-details>div:nth-child(2n){border-inline-end:0}}
@media(max-width:1100px){.c360-report-grid{grid-template-columns:1fr 1fr}}
@media(max-width:720px){.c360-identity-admin{grid-template-columns:1fr}.c360-report-grid{grid-template-columns:1fr}.c360-summary{align-items:flex-start}.c360-avatar{width:54px;height:54px;font-size:27px}.c360-kpis{grid-template-columns:1fr}.c360-finance-details{grid-template-columns:1fr}.c360-finance-details>div{border-inline-end:0!important}.c360-address-form{grid-template-columns:1fr}.c360-address-form .wide{grid-column:auto}.c360-tab{padding:12px 15px}.c360-card{padding:15px}}
</style>
</head>
<body>
@php($ar=app()->getLocale()==='ar')
@php($finance=$wholesale['financial'] ?? null)
@php($financeCurrency=$finance ? strtoupper((string)$finance['currency']) : null)
@php($financeAmountScale=$financeCurrency ? \App\Support\DisplayNumber::moneyFractionDigits($financeCurrency) : 3)
@php($financeAmountStep=$financeCurrency ? \App\Support\DisplayNumber::moneyStep($financeCurrency) : '0.001')
@php($replicaFrom=now()->startOfMonth()->toDateString())
@php($replicaTo=now()->toDateString())

<div class="foodex-admin-layout">
<aside class="sidebar">@include('admin._sidebar')</aside>
<main class="foodex-admin-main foodex-admin-page">
@include('admin._global-header')
<div class="c360-shell">

<div class="c360-topline foodex-page-toolbar">
<div>
<h1 style="margin:0">{{ __('customer_360.title') }}</h1>
<div class="c360-breadcrumbs">
<a href="{{ route('admin.customer-360.index') }}">{{ __('customer_360.customers') }}</a><span>›</span>
<a href="{{ route('admin.customer-360.index') }}">{{ __('customer_360.customer_list') }}</a><span>›</span>
<span>{{ __('customer_360.title') }}</span>
</div>
</div>
<div class="c360-actions">
<a class="foodex-action-secondary button secondary" href="{{ route('admin.customer-360.index') }}">← {{ __('customer_360.back_to_list') }}</a>
</div>
</div>

<section class="foodex-card c360-summary">
<div class="c360-person">
<div class="c360-avatar" aria-hidden="true">🏪</div>
<div class="c360-person-copy">
<h2>{{ $customer->name }}</h2>
<p>{{ $summary['active'] ? __('customer_360.active') : __('customer_360.inactive') }}</p>
</div>
</div>
<span class="c360-badge {{ $summary['active']?'active':'' }}">{{ $summary['registration_source_label'] }}</span>
</section>

@if(session('status'))<div class="foodex-success">{{ session('status') }}</div>@endif
@if($errors->any())<div class="foodex-error">{{ $errors->first() }}</div>@endif

<div class="c360-tabs-wrap">
<div class="c360-tabs" role="tablist" aria-label="{{ __('customer_360.sections') }}">
<button class="c360-tab" type="button" role="tab" id="tab-finance" aria-controls="panel-finance" aria-selected="true" data-c360-tab="finance"><span class="c360-tab-icon">▣</span>{{ __('customer_360.tabs.finance') }}</button>
<button class="c360-tab" type="button" role="tab" id="tab-identity" aria-controls="panel-identity" aria-selected="false" data-c360-tab="identity"><span class="c360-tab-icon">♙</span>{{ __('customer_360.tabs.identity') }}</button>
<button class="c360-tab" type="button" role="tab" id="tab-service" aria-controls="panel-service" aria-selected="false" data-c360-tab="service"><span class="c360-tab-icon">✦</span>{{ __('customer_360.tabs.service') }}</button>
<button class="c360-tab" type="button" role="tab" id="tab-addresses" aria-controls="panel-addresses" aria-selected="false" data-c360-tab="addresses"><span class="c360-tab-icon">⌖</span>{{ __('customer_360.tabs.addresses') }}</button>
<button class="c360-tab" type="button" role="tab" id="tab-stores" aria-controls="panel-stores" aria-selected="false" data-c360-tab="stores"><span class="c360-tab-icon">▦</span>{{ __('customer_360.tabs.stores') }}</button>
<button class="c360-tab" type="button" role="tab" id="tab-orders" aria-controls="panel-orders" aria-selected="false" data-c360-tab="orders"><span class="c360-tab-icon">🛒</span>{{ __('customer_360.tabs.orders') }}</button>
<button class="c360-tab" type="button" role="tab" id="tab-invoices" aria-controls="panel-invoices" aria-selected="false" data-c360-tab="invoices"><span class="c360-tab-icon">▤</span>{{ __('customer_360.tabs.invoices') }}</button>
<button class="c360-tab" type="button" role="tab" id="tab-statement" aria-controls="panel-statement" aria-selected="false" data-c360-tab="statement"><span class="c360-tab-icon">≣</span>{{ __('customer_360.tabs.statement') }}</button>
@if($wholesale)<button class="c360-tab" type="button" role="tab" id="tab-reports" aria-controls="panel-reports" aria-selected="false" data-c360-tab="reports"><span class="c360-tab-icon">▥</span>{{ __('customer_360.tabs.reports') }}</button>@endif
</div>
</div>

<section class="c360-panel active" id="panel-finance" role="tabpanel" aria-labelledby="tab-finance" data-c360-panel="finance">
<div class="foodex-card c360-card">
<div class="c360-section-head"><h2>{{ __('customer_360.tabs.finance') }}</h2>@if($wholesale)<span class="c360-badge {{ strtolower((string)($wholesale['status'] ?? ''))==='active'?'active':'' }}">{{ $wholesale['status_label'] }}</span>@endif</div>
@if($wholesale)
<div class="c360-kpis">
@if($finance)
<article class="c360-kpi primary"><small>{{ __('customer_360.finance.current_balance') }}</small><strong>{{ \App\Support\DisplayNumber::money(abs((float)$finance['balance']), $finance['currency']) }} {{ $finance['currency'] ?: '' }}</strong><span class="muted">@if($finance['balance_direction']==='customer_owes_company'){{ __('customer_360.finance.customer_owes_company') }}@elseif($finance['balance_direction']==='company_owes_customer'){{ __('customer_360.finance.company_owes_customer') }}@else{{ __('customer_360.finance.settled') }}@endif</span></article>
<article class="c360-kpi">
<small>{{ __('customer_360.finance.credit_limit') }}</small>
<strong>{{ \App\Support\DisplayNumber::money((float)$finance['credit_limit'], $finance['currency']) }} {{ $finance['currency'] ?: '' }}</strong>
@if($canManageFinance)
<form method="post" action="{{ route('admin.customer-360.credit-limit.update',['platformCustomer'=>$customer->id]) }}" class="c360-credit-editor">
@csrf @method('PATCH')
<label>
<span class="sr-only">{{ __('customer_360.finance.edit_credit_limit') }}</span>
<input name="credit_limit" type="number" min="0" max="99999999999.999" step="0.001" value="{{ number_format((float)$finance['credit_limit'],3,'.','') }}" required inputmode="decimal" aria-label="{{ __('customer_360.finance.credit_limit') }}">
</label>
<button class="foodex-action-secondary button secondary" type="submit">{{ __('customer_360.finance.save') }}</button>
</form>
@endif
</article>
<article class="c360-kpi"><small>{{ __('customer_360.finance.available_credit') }}</small><strong>{{ \App\Support\DisplayNumber::money((float)$finance['available_credit_line'], $finance['currency']) }} {{ $finance['currency'] ?: '' }}</strong></article>
<article class="c360-kpi"><small>{{ __('customer_360.finance.purchasing_power') }}</small><strong>{{ \App\Support\DisplayNumber::money((float)$finance['purchasing_power'], $finance['currency']) }} {{ $finance['currency'] ?: '' }}</strong></article>
@endif
</div>
<div class="c360-finance-details">
<div><small>{{ __('customer_360.finance.company') }}</small><strong>{{ $wholesale['company_name'] ?: $customer->name }}</strong></div>
<div><small>{{ __('customer_360.finance.price_tier') }}</small><strong>{{ $wholesale['tier_name'] ?: '-' }}</strong></div>
<div><small>{{ __('customer_360.finance.account_status') }}</small><strong>{{ $wholesale['status_label'] }}</strong></div>
@if($finance)
<div><small>{{ __('customer_360.finance.open_amount') }}</small><strong>{{ \App\Support\DisplayNumber::money((float)$finance['open_amount'], $finance['currency']) }} {{ $finance['currency'] ?: '' }}</strong></div>
<div><small>{{ __('customer_360.finance.overdue_amount') }}</small><strong>{{ \App\Support\DisplayNumber::money((float)$finance['overdue_amount'], $finance['currency']) }} {{ $finance['currency'] ?: '' }}</strong></div>
<div><small>{{ __('customer_360.finance.last_payment') }}</small><strong>{{ $finance['last_payment']['occurred_at'] ?? '-' }}</strong></div>
<div><small>{{ __('customer_360.finance.last_transaction') }}</small><strong>{{ $finance['last_transaction']['occurred_at'] ?? '-' }}</strong></div>
@else
<div><small>{{ __('customer_360.finance.credit_limit') }}</small><strong>{{ $wholesale['credit_limit']===null?'-':\App\Support\DisplayNumber::money($wholesale['credit_limit'], 'EGP') }}</strong></div>
@endif
</div>
@if($canManageFinance && $finance)
<details class="c360-form-details" data-foodex-operational-modal>
<summary>{{ __('customer_360.finance.record_entry') }}</summary>
<form method="post" action="{{ route('admin.customer-360.finance-entries.store',['platformCustomer'=>$customer->id]) }}" class="c360-address-form" style="margin-top:14px">
@csrf
<label><small>{{ __('customer_360.finance.entry_type') }}</small><select name="entry_type" required><option value="customer_credit">{{ __('customer_360.finance.entry_types.customer_credit') }}</option><option value="payment">{{ __('customer_360.finance.entry_types.payment') }}</option><option value="credit_note">{{ __('customer_360.finance.entry_types.credit_note') }}</option><option value="debit_note">{{ __('customer_360.finance.entry_types.debit_note') }}</option><option value="opening_balance">{{ __('customer_360.finance.entry_types.opening_balance') }}</option><option value="adjustment_positive">{{ __('customer_360.finance.entry_types.adjustment_positive') }}</option><option value="adjustment_negative">{{ __('customer_360.finance.entry_types.adjustment_negative') }}</option><option value="return">{{ __('customer_360.finance.entry_types.return') }}</option><option value="refund">{{ __('customer_360.finance.entry_types.refund') }}</option></select></label>
<label><small>{{ __('customer_360.finance.direction') }}</small><select name="direction" required><option value="credit">{{ __('customer_360.finance.directions.credit') }}</option><option value="debit">{{ __('customer_360.finance.directions.debit') }}</option></select></label>
<label><small>{{ __('customer_360.finance.amount') }}</small><input name="amount" type="number" min="{{ $financeAmountStep }}" step="{{ $financeAmountStep }}" value="{{ old('amount') }}" required inputmode="decimal" aria-describedby="c360-finance-amount-contract"><span id="c360-finance-amount-contract" class="muted">{{ __('customer_360.finance.amount_contract',['currency'=>$financeCurrency,'decimals'=>$financeAmountScale]) }}</span></label>
<div class="c360-authoritative-field" data-finance-authoritative-currency="{{ $financeCurrency }}"><small>{{ __('customer_360.finance.currency') }}</small><strong>{{ $financeCurrency }}</strong><span class="muted">{{ __('customer_360.finance.authoritative_currency') }}</span></div>
<label><small>{{ __('customer_360.finance.reference') }}</small><input name="reference" maxlength="120" value="{{ old('reference') }}"></label>
<div class="c360-invoice-picker" data-finance-invoice-picker data-url="{{ route('admin.customer-360.finance-invoices',['platformCustomer'=>$customer->id]) }}">
<small>{{ __('customer_360.finance.invoice') }}</small>
<input type="hidden" name="invoice_id" value="{{ old('invoice_id') }}" data-finance-invoice-id>
<div class="c360-invoice-picker-row">
<input type="search" autocomplete="off" role="combobox" aria-autocomplete="list" aria-expanded="false" aria-controls="c360-finance-invoice-results" aria-describedby="c360-finance-invoice-help c360-finance-invoice-status" placeholder="{{ __('customer_360.finance.invoice_search_placeholder') }}" data-finance-invoice-search>
<button type="button" class="foodex-action-secondary button secondary c360-invoice-clear" data-finance-invoice-clear hidden>{{ __('customer_360.finance.invoice_clear') }}</button>
</div>
<span id="c360-finance-invoice-help" class="c360-invoice-picker-help">{{ __('customer_360.finance.invoice_search_help') }}</span>
<span id="c360-finance-invoice-status" class="c360-invoice-picker-status" role="status" aria-live="polite" data-finance-invoice-status></span>
<button type="button" class="foodex-action-secondary button secondary" data-finance-invoice-retry hidden>{{ __('customer_360.finance.invoice_retry') }}</button>
<div id="c360-finance-invoice-results" class="c360-invoice-results" role="listbox" aria-label="{{ __('customer_360.finance.invoice_results_aria') }}" data-finance-invoice-results hidden></div>
</div>
<label class="wide"><small>{{ __('customer_360.finance.description') }}</small><input name="description" maxlength="500"></label>
<label><small>{{ __('customer_360.finance.date') }}</small><input name="occurred_at" type="datetime-local"></label>
<div class="wide"><button class="foodex-action-primary" type="submit">{{ __('customer_360.finance.record') }}</button></div>
</form>
</details>
@endif
@else
<div class="c360-empty">{{ __('customer_360.finance.no_account') }}</div>
@endif
</div>
</section>

<section class="c360-panel" id="panel-identity" role="tabpanel" aria-labelledby="tab-identity" data-c360-panel="identity" hidden>
<div class="foodex-card c360-card">
<div class="c360-section-head"><h2>{{ __('customer_360.tabs.identity') }}</h2><span class="c360-badge {{ $summary['active']?'active':'' }}">{{ $summary['active'] ? __('customer_360.active') : __('customer_360.inactive') }}</span></div>
<div class="c360-info">
<div><small>{{ __('customer_360.identity.name') }}</small><strong>{{ $customer->name }}</strong></div>
<div><small>{{ __('customer_360.identity.email') }}</small><strong>{{ $customer->email ?: '-' }}</strong></div>
<div><small>{{ __('customer_360.identity.phone') }}</small><strong>{{ $customer->phone ?: '-' }}</strong></div>
<div><small>{{ __('customer_360.identity.registered_at') }}</small><strong>{{ optional($customer->registered_at)->format('Y-m-d H:i') ?: '-' }}</strong></div>
<div><small>{{ __('customer_360.identity.origin_channel') }}</small><strong>{{ $summary['origin_channel_label'] }}</strong></div>
<div><small>{{ __('customer_360.identity.registration_source') }}</small><strong>{{ $summary['registration_source_label'] }}</strong></div>
<div><small>{{ __('customer_360.identity.registration_origin') }}</small><strong>{{ $summary['origin']['label'] }}</strong></div>
</div>

@if($canManageIdentity)
<form method="post" action="{{ route('admin.customer-360.identity.update',['platformCustomer'=>$customer->id]) }}" class="c360-identity-admin">
@csrf @method('PATCH')
<div>
<h3>{{ __('customer_360.identity.edit_title') }}</h3>
<p>{{ __('customer_360.identity.edit_help') }}</p>
<div class="c360-address-form">
<label><small>{{ __('customer_360.identity.name') }}</small><input name="name" maxlength="255" value="{{ old('name',$customer->name) }}" required autocomplete="name"></label>
<label><small>{{ __('customer_360.identity.email') }}</small><input name="email" type="email" maxlength="255" value="{{ old('email',$customer->email) }}" required autocomplete="email" inputmode="email"></label>
<label class="wide"><small>{{ __('customer_360.identity.phone') }}</small><input name="phone" maxlength="40" value="{{ old('phone',$customer->phone) }}" autocomplete="tel" inputmode="tel"></label>
</div>
</div>

<div>
<h3>{{ __('customer_360.identity.mobile_access_title') }}</h3>
<p>{{ __('customer_360.identity.mobile_access_help') }}</p>
<div class="c360-login-state">
<span>{{ __('customer_360.identity.login_status') }}</span>
<span class="c360-badge {{ ($loginUser && $loginUser->is_active && $customer->is_active)?'active':'' }}">{{ ($loginUser && $loginUser->is_active && $customer->is_active) ? __('customer_360.identity.login_enabled') : __('customer_360.identity.login_disabled') }}</span>
</div>
<div class="c360-address-form">
<label class="wide"><small>{{ __('customer_360.identity.password') }}</small><input name="password" type="password" minlength="8" maxlength="255" autocomplete="new-password"></label>
<label class="wide"><small>{{ __('customer_360.identity.password_confirmation') }}</small><input name="password_confirmation" type="password" minlength="8" maxlength="255" autocomplete="new-password"></label>
<div class="wide muted">{{ __('customer_360.identity.password_help') }}</div>
<div class="wide"><button class="foodex-action-primary" type="submit">{{ __('customer_360.identity.save_credentials') }}</button></div>
</div>
</div>
</form>
@endif
</div>
</section>

<section class="c360-panel" id="panel-service" role="tabpanel" aria-labelledby="tab-service" data-c360-panel="service" hidden>
<div class="foodex-card c360-card">
<div class="c360-section-head">
    <div><h2>{{ __('customer_360.service.title') }}</h2><p>{{ __('customer_360.service.description') }}</p></div>
    @if($canManageServiceCases)<a class="foodex-action-primary" href="{{ route('admin.erp.customer-service',['customer_q'=>$customer->email ?: $customer->phone]) }}">{{ __('customer_360.service.create_case') }}</a>@endif
</div>
@if($serviceCases->isEmpty())
<div class="c360-empty">{{ __('customer_360.service.no_cases') }}</div>
@else
<div class="c360-table-wrap"><table class="c360-table" data-pagination-exempt="bounded-customer-service-cases"><thead><tr>
<th>{{ __('customer_360.service.case') }}</th><th>{{ __('customer_360.service.status') }}</th><th>{{ __('customer_360.service.priority') }}</th><th>{{ __('customer_360.service.queue') }}</th><th>{{ __('customer_360.service.owner') }}</th><th>{{ __('customer_360.service.sla') }}</th><th></th>
</tr></thead><tbody>
@foreach($serviceCases as $case)
<tr>
<td><strong>{{ \App\Support\DisplayNumber::reference($case->id) }}</strong><div>{{ $case->subject ?: __('customer_service_workspace.values.untitled') }}</div></td>
<td>{{ __('customer_service_workspace.values.'.$case->status) }}</td>
<td>{{ __('customer_service_workspace.values.'.$case->priority) }}</td>
<td>{{ __('customer_service_workspace.values.'.($case->queue ?: 'general')) }}</td>
<td>{{ $case->assigned_agent_name ?: __('customer_service_workspace.values.unassigned') }}</td>
<td>{{ $case->sla_target_at ?: '—' }}</td>
<td><a class="foodex-action-secondary button secondary" href="{{ route('admin.erp.customer-service.cases.show',['case'=>$case->id]) }}">{{ __('customer_360.service.open_case') }}</a></td>
</tr>
@endforeach
</tbody></table></div>
@endif
</div>

<div class="foodex-card c360-card" style="margin-top:16px">
<div class="c360-section-head"><div><h2>{{ __('customer_360.service.timeline') }}</h2><p>{{ __('customer_360.service.timeline_help') }}</p></div><span class="c360-badge">{{ $serviceTimeline->count() }}</span></div>
<div style="display:grid;gap:10px">
@forelse($serviceTimeline as $event)
    @php($eventUrl = null)
    @if(($event['kind'] ?? '')==='service_case' && !empty($event['service_case_id']))
        @php($eventUrl=route('admin.erp.customer-service.cases.show',['case'=>$event['service_case_id']]))
    @elseif(!empty($event['conversation_id']))
        @php($eventUrl=route('admin.whatsapp-inbox.index',['conversation'=>$event['conversation_id']]))
    @elseif(($event['kind'] ?? '')==='order')
        @php($eventUrl=route('admin.operations.orders.index',['q'=>$event['source_id']]))
    @elseif(($event['kind'] ?? '')==='invoice')
        @php($eventUrl=route('admin.invoices.show',['invoice'=>$event['source_id']]))
    @endif
    <article style="padding:12px;border:1px solid var(--foodex-border);border-radius:12px;display:grid;gap:4px">
        <div style="display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap"><strong>{{ $event['title'] }}</strong><small>{{ $event['occurred_at'] }}</small></div>
        @if(!empty($event['detail']))<div class="muted">{{ $event['detail'] }}</div>@endif
        @if($eventUrl)<div><a class="foodex-action-secondary button secondary" href="{{ $eventUrl }}">{{ __('customer_360.service.open_source') }}</a></div>@endif
    </article>
@empty
    <div class="c360-empty">{{ __('customer_360.service.no_timeline') }}</div>
@endforelse
</div>
</div>
</section>

<section class="c360-panel" id="panel-addresses" role="tabpanel" aria-labelledby="tab-addresses" data-c360-panel="addresses" hidden>
<div class="foodex-card c360-card">
<div class="c360-section-head"><div><h2>{{ __('customer_360.tabs.addresses') }}</h2><p>{{ __('customer_360.addresses.description') }}</p></div><span class="c360-badge">{{ count($addresses) }} {{ __('customer_360.addresses.count_suffix') }}</span></div>
@if($canManageAddresses)
<details class="c360-form-details" data-foodex-operational-modal style="margin-top:0;margin-bottom:16px;border-top:0;padding-top:0">
<summary class="foodex-action-primary" style="display:inline-flex">{{ __('customer_360.addresses.add_new') }}</summary>
<form method="post" action="{{ route('admin.customer-360.addresses.store',['platformCustomer'=>$customer->id]) }}" class="c360-address-form" style="margin-top:14px">@csrf
<label><small>{{ __('customer_360.addresses.label') }}</small><input name="label" maxlength="100" placeholder="{{ __('customer_360.addresses.label_placeholder') }}"></label>
<label><small>{{ __('customer_360.addresses.recipient') }}</small><input name="recipient_name" maxlength="255"></label>
<label><small>{{ __('customer_360.addresses.delivery_phone') }}</small><input name="delivery_phone" maxlength="50"></label>
<label><small>{{ __('customer_360.addresses.country') }}</small><select name="country_code" required data-geography-country><option value="">{{ __('customer_360.addresses.choose_country') }}</option>@foreach($geographyOptions['country'] ?? [] as $option)<option value="{{ $option['value'] }}" data-node-id="{{ $option['id'] }}" @selected(old('country_code')===$option['value'])>{{ $option['label'] }}</option>@endforeach</select></label>
<label><small>{{ __('customer_360.addresses.governorate') }}</small><select name="governorate" data-geography-level="governorate"><option value="">{{ __('customer_360.addresses.choose_governorate') }}</option>@foreach($geographyOptions['governorate'] ?? [] as $option)<option value="{{ $option['value'] }}" data-country="{{ $option['country_code'] }}" data-node-id="{{ $option['id'] }}" data-parent-id="{{ $option['parent_id'] }}" @selected(old('governorate')===$option['value'])>{{ $option['label'] }}</option>@endforeach</select></label>
<label><small>{{ __('customer_360.addresses.city') }}</small><select name="city" required data-geography-level="city"><option value="">{{ __('customer_360.addresses.choose_city') }}</option>@foreach($geographyOptions['city'] ?? [] as $option)<option value="{{ $option['value'] }}" data-country="{{ $option['country_code'] }}" data-node-id="{{ $option['id'] }}" data-parent-id="{{ $option['parent_id'] }}" @selected(old('city')===$option['value'])>{{ $option['label'] }}</option>@endforeach</select></label>
<label><small>{{ __('customer_360.addresses.area') }}</small><select name="area" data-geography-level="area"><option value="">{{ __('customer_360.addresses.choose_area') }}</option>@foreach($geographyOptions['area'] ?? [] as $option)<option value="{{ $option['value'] }}" data-country="{{ $option['country_code'] }}" data-node-id="{{ $option['id'] }}" data-parent-id="{{ $option['parent_id'] }}" @selected(old('area')===$option['value'])>{{ $option['label'] }}</option>@endforeach</select></label>
<label class="wide"><small>{{ __('customer_360.addresses.address') }}</small><input name="line1" maxlength="255" required></label>
<label><small>{{ __('customer_360.addresses.block') }}</small><input name="block" maxlength="120"></label>
<label><small>{{ __('customer_360.addresses.building') }}</small><input name="building" maxlength="120"></label>
<label><small>{{ __('customer_360.addresses.floor') }}</small><input name="floor" maxlength="120"></label>
<label><small>{{ __('customer_360.addresses.apartment') }}</small><input name="apartment" maxlength="120"></label>
<input name="latitude" type="hidden">
<input name="longitude" type="hidden">
<input type="hidden" name="location_source" value="manual">
<div class="wide c360-map-picker-row">
<button type="button" class="foodex-action-secondary button secondary" data-address-map-picker>{{ __('customer_360.addresses.choose_map') }}</button>
<span class="c360-map-picked" data-address-map-summary>{{ __('customer_360.addresses.no_location') }}</span>
</div>
<label class="wide"><small>{{ __('customer_360.addresses.landmark') }}</small><input name="landmark" maxlength="255"></label>
<label class="wide"><small>{{ __('customer_360.addresses.delivery_notes') }}</small><textarea name="delivery_notes" maxlength="1000" rows="2"></textarea></label>
<label class="wide"><input type="checkbox" name="is_default" value="1"> {{ __('customer_360.addresses.set_as_default') }}</label>
<div class="wide"><button class="foodex-action-primary" type="submit">{{ __('customer_360.addresses.save_address') }}</button></div>
</form>
</details>
@endif
<div class="c360-address-grid">
@forelse($addresses as $address)
<article class="c360-address-card">
<div class="c360-actions" style="justify-content:space-between"><strong>{{ $address->label ?: __('customer_360.addresses.delivery_address') }}</strong>@if($address->is_default)<span class="c360-badge active">{{ __('customer_360.addresses.default') }}</span>@endif {{-- localization-gate: allow Blade control directive --}}</div>
<p style="margin:8px 0">{{ collect([$address->building,$address->street ?: $address->line1,$address->block,$address->area,$address->city,$address->governorate])->filter()->join(' · ') }}</p>
@if($address->landmark)<small>{{ __('customer_360.addresses.landmark') }}: {{ $address->landmark }}</small>@endif {{-- localization-gate: allow Blade control directive --}}
@if($address->latitude!==null && $address->longitude!==null)<div style="margin-top:8px"><span class="c360-badge active">{{ __('customer_360.addresses.map_location_selected') }}</span></div>@endif {{-- localization-gate: allow Blade control directive --}}
@if($canManageAddresses)
<div class="c360-address-actions" style="margin-top:12px">
@if(!$address->is_default)<form method="post" action="{{ route('admin.customer-360.addresses.default',['platformCustomer'=>$customer->id,'address'=>$address->id]) }}">@csrf<button class="foodex-action-secondary button secondary" type="submit">{{ __('customer_360.addresses.set_default') }}</button></form>@endif {{-- localization-gate: allow Blade control directive --}}
<form method="post" action="{{ route('admin.customer-360.addresses.destroy',['platformCustomer'=>$customer->id,'address'=>$address->id]) }}" onsubmit="return confirm('{{ __('customer_360.addresses.delete_confirm') }}')">@csrf @method('DELETE')<button class="foodex-action-secondary button secondary" type="submit">{{ __('customer_360.addresses.delete') }}</button></form>
</div>
<details class="c360-form-details" data-foodex-operational-modal><summary>{{ __('customer_360.addresses.edit_address') }}</summary>
<form method="post" action="{{ route('admin.customer-360.addresses.update',['platformCustomer'=>$customer->id,'address'=>$address->id]) }}" class="c360-address-form" style="margin-top:12px">@csrf @method('PATCH')
<label><small>{{ __('customer_360.addresses.label') }}</small><input name="label" value="{{ $address->label }}" maxlength="100"></label><label><small>{{ __('customer_360.addresses.recipient') }}</small><input name="recipient_name" value="{{ $address->recipient_name }}" maxlength="255"></label><label><small>{{ __('customer_360.addresses.delivery_phone') }}</small><input name="delivery_phone" value="{{ $address->delivery_phone }}" maxlength="50"></label><label><small>{{ __('customer_360.addresses.country') }}</small><select name="country_code" data-geography-country><option value="">{{ __('customer_360.addresses.choose_country') }}</option>@foreach($geographyOptions['country'] ?? [] as $option)<option value="{{ $option['value'] }}" data-node-id="{{ $option['id'] }}" @selected(strtoupper((string)$address->country_code)===$option['value'])>{{ $option['label'] }}</option>@endforeach @if($address->country_code && !collect($geographyOptions['country'] ?? [])->contains(fn($option)=>$option['value']===strtoupper((string)$address->country_code)))<option value="{{ strtoupper((string)$address->country_code) }}" selected>{{ strtoupper((string)$address->country_code) }} · {{ __('customer_360.addresses.saved_legacy_value') }}</option>@endif</select></label><label><small>{{ __('customer_360.addresses.governorate') }}</small><select name="governorate" data-geography-level="governorate"><option value="">{{ __('customer_360.addresses.choose_governorate') }}</option>@foreach($geographyOptions['governorate'] ?? [] as $option)<option value="{{ $option['value'] }}" data-country="{{ $option['country_code'] }}" data-node-id="{{ $option['id'] }}" data-parent-id="{{ $option['parent_id'] }}" @selected((string)$address->governorate===$option['value'])>{{ $option['label'] }}</option>@endforeach @if($address->governorate && !collect($geographyOptions['governorate'] ?? [])->contains(fn($option)=>$option['value']===(string)$address->governorate))<option value="{{ $address->governorate }}" selected data-country="{{ strtoupper((string)$address->country_code) }}">{{ $address->governorate }} · {{ __('customer_360.addresses.saved_legacy_value') }}</option>@endif</select></label><label><small>{{ __('customer_360.addresses.city') }}</small><select name="city" data-geography-level="city"><option value="">{{ __('customer_360.addresses.choose_city') }}</option>@foreach($geographyOptions['city'] ?? [] as $option)<option value="{{ $option['value'] }}" data-country="{{ $option['country_code'] }}" data-node-id="{{ $option['id'] }}" data-parent-id="{{ $option['parent_id'] }}" @selected((string)$address->city===$option['value'])>{{ $option['label'] }}</option>@endforeach @if($address->city && !collect($geographyOptions['city'] ?? [])->contains(fn($option)=>$option['value']===(string)$address->city))<option value="{{ $address->city }}" selected data-country="{{ strtoupper((string)$address->country_code) }}">{{ $address->city }} · {{ __('customer_360.addresses.saved_legacy_value') }}</option>@endif</select></label><label><small>{{ __('customer_360.addresses.area') }}</small><select name="area" data-geography-level="area"><option value="">{{ __('customer_360.addresses.choose_area') }}</option>@foreach($geographyOptions['area'] ?? [] as $option)<option value="{{ $option['value'] }}" data-country="{{ $option['country_code'] }}" data-node-id="{{ $option['id'] }}" data-parent-id="{{ $option['parent_id'] }}" @selected((string)$address->area===$option['value'])>{{ $option['label'] }}</option>@endforeach @if($address->area && !collect($geographyOptions['area'] ?? [])->contains(fn($option)=>$option['value']===(string)$address->area))<option value="{{ $address->area }}" selected data-country="{{ strtoupper((string)$address->country_code) }}">{{ $address->area }} · {{ __('customer_360.addresses.saved_legacy_value') }}</option>@endif</select></label><label class="wide"><small>{{ __('customer_360.addresses.address') }}</small><input name="line1" value="{{ $address->line1 }}" maxlength="255"></label><label><small>{{ __('customer_360.addresses.block') }}</small><input name="block" value="{{ $address->block }}" maxlength="120"></label><label><small>{{ __('customer_360.addresses.building') }}</small><input name="building" value="{{ $address->building }}" maxlength="120"></label><label><small>{{ __('customer_360.addresses.floor') }}</small><input name="floor" value="{{ $address->floor }}" maxlength="120"></label><label><small>{{ __('customer_360.addresses.apartment') }}</small><input name="apartment" value="{{ $address->apartment }}" maxlength="120"></label>{{-- localization-gate: allow geography legacy-value callback expressions; visible labels use catalog keys --}}<input name="latitude" type="hidden" value="{{ $address->latitude }}"><input name="longitude" type="hidden" value="{{ $address->longitude }}"><input type="hidden" name="location_source" value="{{ $address->location_source ?: 'manual' }}"><div class="wide c360-map-picker-row"><button type="button" class="foodex-action-secondary button secondary" data-address-map-picker>{{ $address->latitude!==null && $address->longitude!==null ? __('customer_360.addresses.edit_map') : __('customer_360.addresses.choose_map') }}</button><span class="c360-map-picked" data-address-map-summary>@if($address->latitude!==null && $address->longitude!==null){{ __('customer_360.addresses.location_selected') }}@else{{ __('customer_360.addresses.no_location') }}@endif</span></div><label class="wide"><small>{{ __('customer_360.addresses.landmark') }}</small><input name="landmark" value="{{ $address->landmark }}" maxlength="255"></label><label class="wide"><small>{{ __('customer_360.addresses.delivery_notes') }}</small><textarea name="delivery_notes" maxlength="1000" rows="2">{{ $address->delivery_notes }}</textarea></label><div class="wide"><button class="foodex-action-primary" type="submit">{{ __('customer_360.addresses.save_changes') }}</button></div>
</form></details>
@endif
</article>
@empty<div class="c360-empty">{{ __('customer_360.addresses.empty') }}</div>@endforelse
</div>
</div>
</section>

<section class="c360-panel" id="panel-stores" role="tabpanel" aria-labelledby="tab-stores" data-c360-panel="stores" hidden>
<div class="foodex-card c360-card">
<div class="c360-section-head"><h2>{{ __('customer_360.tabs.stores') }}</h2></div>
@if($access['mode']!=='b2b')
<div class="c360-list">@forelse($retailStores as $store)<span class="c360-store">{{ $store['name'] }} · {{ $store['code'] }}</span>@empty<div class="c360-empty" style="width:100%">{{ __('customer_360.records.no_retail_stores') }}</div>@endforelse</div>
@else
<div class="c360-empty">{{ __('customer_360.records.retail_unavailable') }}</div>
@endif
</div>
</section>

<section class="c360-panel" id="panel-orders" role="tabpanel" aria-labelledby="tab-orders" data-c360-panel="orders" hidden>
<div class="foodex-card c360-card">
<div class="c360-section-head"><h2>{{ __('customer_360.tabs.orders') }}</h2><span class="c360-badge">{{ $orders->total() }}</span></div>
@if($orders->isEmpty())<div class="c360-empty">{{ __('customer_360.records.no_orders') }}</div>@else
<div class="c360-table-wrap"><table class="c360-table" data-pagination-required><thead><tr><th>{{ __('customer_360.records.order') }}</th><th>{{ __('customer_360.records.store') }}</th><th>{{ __('customer_360.records.channel') }}</th><th>{{ __('customer_360.records.status') }}</th><th>{{ __('order_operations.columns.current_executor') }}</th><th>{{ __('customer_360.records.total') }}</th><th>{{ __('customer_360.finance.date') }}</th><th></th></tr></thead><tbody>
@foreach($orders as $order)<tr id="order-{{ $order['id'] }}"><td><strong>{{ $order['number'] }}</strong></td><td>{{ $order['store'] }}</td><td><span class="c360-badge {{ $order['channel'] }}">{{ $order['channel_label'] }}</span></td><td>{{ $order['status_label'] }}</td><td data-c360-fulfillment> {{-- localization-gate: allow channel is CSS-only; visible channel/status use localized labels --}}
@if($order['channel']==='b2b')
<strong>{{ __('order_operations.dispatch.van') }} · {{ $order['van_code'] ?: __('order_operations.unassigned') }}</strong>
@if($order['van_execution_status'])<div>{{ __('order_operations.detail.van_execution_timeline') }}: {{ __('order_operations.business_labels.'.$order['van_execution_status']) }}</div>@endif
@if($order['van_failure_reason'])<div>{{ __('order_operations.detail.failure_reason') }}: {{ __('order_operations.business_labels.'.$order['van_failure_reason']) }}</div>@endif
@if(!empty($order['van_timeline']))
<details data-c360-van-timeline><summary>{{ __('order_operations.detail.van_execution_timeline') }}</summary>
@foreach($order['van_timeline'] as $event)<div><small>{{ $event['captured_at'] }} · {{ $event['action'] }} · {{ $event['from'] }} → {{ $event['to'] }}@if($event['reason']) · {{ $event['reason'] }}@endif</small></div>@endforeach
</details>
@endif
@else
{{ __('order_operations.dispatch.driver') }}
@endif
</td><td>{{ \App\Support\DisplayNumber::money($order['total'], $order['currency']) }} {{ $order['currency'] }}</td><td>{{ $order['created_at'] }}</td><td><a class="foodex-action-secondary button secondary" href="{{ $order['url'] }}">{{ __('customer_360.records.manage_order') }}</a></td></tr>@endforeach
</tbody></table></div>{{ $orders->links() }}@endif
</div>
</section>

<section class="c360-panel" id="panel-invoices" role="tabpanel" aria-labelledby="tab-invoices" data-c360-panel="invoices" hidden>
<div class="foodex-card c360-card">
<div class="c360-section-head"><h2>{{ __('customer_360.tabs.invoices') }}</h2><span class="c360-badge">{{ $invoices->total() }}</span></div>
@if($invoices->isEmpty())<div class="c360-empty">{{ __('customer_360.records.no_invoices') }}</div>@else
<div class="c360-table-wrap"><table class="c360-table" data-pagination-required><thead><tr><th>{{ __('customer_360.finance.invoice') }}</th><th>{{ __('customer_360.records.store') }}</th><th>{{ __('customer_360.records.channel') }}</th><th>{{ __('customer_360.records.status') }}</th><th>{{ __('customer_360.records.total') }}</th><th>{{ __('customer_360.finance.paid') }}</th><th>{{ __('customer_360.finance.outstanding') }}</th><th>{{ __('customer_360.records.issued') }}</th><th></th></tr></thead><tbody>
@foreach($invoices as $invoice)<tr><td><strong>{{ $invoice['number'] }}</strong></td><td>{{ $invoice['store'] }}</td><td><span class="c360-badge {{ $invoice['channel'] }}">{{ $invoice['channel_label'] }}</span></td><td>{{ $invoice['status_label'] }}</td><td>{{ \App\Support\DisplayNumber::money($invoice['total'], $invoice['currency']) }} {{ $invoice['currency'] }}</td><td>{{ \App\Support\DisplayNumber::money($invoice['paid_amount'], $invoice['currency']) }} {{ $invoice['currency'] }}</td><td>{{ \App\Support\DisplayNumber::money($invoice['outstanding_amount'], $invoice['currency']) }} {{ $invoice['currency'] }}</td><td>{{ $invoice['issued_at'] ?: '-' }}</td><td class="c360-actions"><a class="foodex-action-secondary button secondary" href="{{ $invoice['url'] }}">{{ __('customer_360.records.details') }}</a><a class="foodex-action-secondary button secondary" href="{{ $invoice['pdf_url'] }}">PDF</a>@if($canManageFinance && $invoice['channel']==='b2b' && $invoice['outstanding_amount']>0.0005)<button type="button" class="foodex-action-primary" data-settlement-open data-invoice-id="{{ $invoice['id'] }}" data-invoice-number="{{ $invoice['number'] }}" data-outstanding="{{ number_format($invoice['outstanding_amount'],3,'.','') }}" data-currency="{{ $invoice['currency'] }}">{{ __('customer_360.finance.settle') }}</button>@endif</td></tr>@endforeach
</tbody></table></div>{{ $invoices->links() }}@endif
</div>
</section>

<section class="c360-panel" id="panel-statement" role="tabpanel" aria-labelledby="tab-statement" data-c360-panel="statement" hidden>
<div class="foodex-card c360-card">
<div class="c360-section-head"><div><h2>{{ __('customer_360.tabs.statement') }}</h2><p>{{ __('customer_360.statement.description') }}</p></div></div>
@if($statement)
<form method="get" action="{{ route('admin.customer-360.show',['platformCustomer'=>$customer->id]) }}#statement" class="c360-address-form" style="margin-bottom:16px">
<label><small>{{ __('customer_360.statement.from') }}</small><input type="date" name="statement_from" value="{{ $statementFilters['statement_from'] ?? '' }}"></label>
<label><small>{{ __('customer_360.statement.to') }}</small><input type="date" name="statement_to" value="{{ $statementFilters['statement_to'] ?? '' }}"></label>
<div class="wide c360-actions"><button class="foodex-action-primary" type="submit">{{ __('customer_360.statement.apply') }}</button><a class="foodex-action-secondary button secondary" href="{{ route('admin.customer-360.statement.export',['platformCustomer'=>$customer->id,'from'=>$statementFilters['statement_from'] ?? null,'to'=>$statementFilters['statement_to'] ?? null,'format'=>'pdf','locale'=>app()->getLocale()]) }}">PDF</a><a class="foodex-action-secondary button secondary" href="{{ route('admin.customer-360.statement.export',['platformCustomer'=>$customer->id,'from'=>$statementFilters['statement_from'] ?? null,'to'=>$statementFilters['statement_to'] ?? null,'format'=>'xlsx','locale'=>app()->getLocale()]) }}">{{ __('customer_360.statement.xlsx') }}</a></div>
</form>
<div class="c360-kpis">
<article class="c360-kpi"><small>{{ __('customer_360.statement.opening') }}</small><strong>{{ \App\Support\DisplayNumber::money((float)$statement['opening_balance'], $statement['currency']) }} {{ $statement['currency'] }}</strong></article>
<article class="c360-kpi"><small>{{ __('customer_360.statement.debits') }}</small><strong>{{ \App\Support\DisplayNumber::money((float)$statement['period_debits'], $statement['currency']) }} {{ $statement['currency'] }}</strong></article>
<article class="c360-kpi"><small>{{ __('customer_360.statement.credits') }}</small><strong>{{ \App\Support\DisplayNumber::money((float)$statement['period_credits'], $statement['currency']) }} {{ $statement['currency'] }}</strong></article>
<article class="c360-kpi primary"><small>{{ __('customer_360.statement.closing') }}</small><strong>{{ \App\Support\DisplayNumber::money((float)$statement['closing_balance'], $statement['currency']) }} {{ $statement['currency'] }}</strong></article>
</div>
@if(empty($statement['transactions']))<div class="c360-empty">{{ __('customer_360.statement.empty') }}</div>@else
<div class="c360-table-wrap"><table class="c360-table"><thead><tr><th>{{ __('customer_360.finance.date') }}</th><th>{{ __('customer_360.statement.type') }}</th><th>{{ __('customer_360.finance.reference') }}</th><th>{{ __('customer_360.finance.description') }}</th><th>{{ __('customer_360.statement.debit') }}</th><th>{{ __('customer_360.statement.credit') }}</th><th>{{ __('customer_360.statement.running') }}</th><th></th></tr></thead><tbody>
@foreach($statement['transactions'] as $row)
<tr><td>{{ $row['occurred_at'] }}</td><td>{{ $row['type'] }}</td><td>{{ $row['reference'] ?: '-' }}</td><td>{{ $row['description'] ?: '-' }}</td><td>{{ \App\Support\DisplayNumber::money((float)$row['debit'], $row['currency']) }}</td><td>{{ \App\Support\DisplayNumber::money((float)$row['credit'], $row['currency']) }}</td><td>{{ \App\Support\DisplayNumber::money((float)$row['running_balance'], $row['currency']) }} {{ $row['currency'] }}</td><td>@if($canManageFinance && str_starts_with((string)$row['id'],'ledger:') && ($row['source'] ?? '')!=='ledger_reversal')<form method="post" action="{{ route('admin.customer-360.finance-entries.reverse',['platformCustomer'=>$customer->id,'ledgerEntry'=>(int)str_replace('ledger:','',$row['id'])]) }}" class="c360-actions">@csrf<input name="reason" required maxlength="500" placeholder="{{ __('customer_360.statement.reversal_reason') }}"><button type="submit" class="foodex-action-secondary button secondary">{{ __('customer_360.statement.reverse') }}</button></form>@endif</td></tr>
@endforeach
</tbody></table></div>
@endif
@else
<div class="c360-empty">{{ __('customer_360.finance.no_account') }}</div>
@endif
</div>
</section>

@if($wholesale)
<section class="c360-panel" id="panel-reports" role="tabpanel" aria-labelledby="tab-reports" data-c360-panel="reports" hidden>
<div class="foodex-card c360-card">
<div class="c360-section-head"><div><h2>{{ __('customer_360.tabs.reports') }}</h2><p>{{ __('customer_360.reports.description') }}</p></div></div>
<div class="c360-report-grid">
@foreach([
    ['key'=>'account-statement','label'=>__('customer_360.reports.account_statement')],
    ['key'=>'item-detail','label'=>__('customer_360.reports.item_detail')],
    ['key'=>'item-summary','label'=>__('customer_360.reports.item_summary')],
] as $replica)
<article class="c360-report-card" data-customer-replica-report="{{ $replica['key'] }}">
<h3>{{ $replica['label'] }}</h3>
<form method="get" action="{{ route('admin.customer-360.reports.show',['platformCustomer'=>$customer->id,'report'=>$replica['key']]) }}" class="c360-report-form">
<label><small>{{ __('customer_360.reports.from') }}</small><input type="date" name="from" value="{{ $replicaFrom }}" required></label>
<label><small>{{ __('customer_360.reports.to') }}</small><input type="date" name="to" value="{{ $replicaTo }}" required></label>
<div class="wide c360-actions"><button class="foodex-action-primary" type="submit">{{ __('customer_360.reports.view') }}</button><a class="foodex-action-secondary button secondary" href="{{ route('admin.customer-360.reports.pdf',['platformCustomer'=>$customer->id,'report'=>$replica['key'],'from'=>$replicaFrom,'to'=>$replicaTo]) }}">{{ __('customer_360.reports.download_pdf') }}</a></div>
</form>
</article>
@endforeach
</div>
</div>
</section>
@endif

</div>
</main>
</div>

<dialog id="c360-settlement-dialog" class="c360-settlement-dialog">
<form method="post" data-settlement-form>
@csrf
<h3>{{ __('customer_360.finance.settle_invoice') }}</h3>
<p><strong data-settlement-invoice></strong></p>
<p>{{ __('customer_360.finance.outstanding') }}: <strong data-settlement-outstanding></strong></p>
<label><small>{{ __('customer_360.finance.amount') }}</small><input name="amount" type="number" min="0.001" step="0.001" required data-settlement-amount></label>
<label><small>{{ __('customer_360.finance.reference') }}</small><input name="reference" maxlength="120"></label>
<label><small>{{ __('customer_360.finance.description') }}</small><input name="description" maxlength="500"></label>
<div class="c360-actions"><button type="button" class="foodex-action-secondary button secondary" data-settlement-close>{{ __('customer_360.statement.cancel') }}</button><button type="submit" class="foodex-action-primary">{{ __('customer_360.finance.record_payment') }}</button></div>
</form>
</dialog>

<div class="c360-map-modal" data-address-map-modal data-open="0" aria-hidden="true">
<section class="c360-map-dialog" role="dialog" aria-modal="true" aria-labelledby="c360-map-title">
<div class="c360-map-head">
<div><h3 id="c360-map-title">{{ __('customer_360.addresses.map_title') }}</h3><p>{{ __('customer_360.addresses.map_help') }}</p></div>
<button type="button" class="c360-map-close" data-address-map-close aria-label="{{ __('customer_360.addresses.map_close') }}">×</button>
</div>
<div class="c360-map-body">
<div class="c360-map-search">
<input type="search" data-address-map-search aria-label="{{ __('customer_360.addresses.map_search_placeholder') }}" placeholder="{{ __('customer_360.addresses.map_search_placeholder') }}">
<button type="button" class="foodex-action-secondary button secondary" data-address-map-search-button>{{ __('customer_360.addresses.map_search') }}</button>
</div>
<div class="c360-map-search-state" data-address-map-search-state data-visible="0">
<span data-address-map-search-message role="status" aria-live="polite" aria-atomic="true"></span>
<button type="button" class="foodex-action-secondary button secondary" data-address-map-search-retry hidden>{{ __('customer_360.addresses.map_retry') }}</button>
</div>
<div class="c360-map-results" data-address-map-results role="region" aria-label="{{ __('customer_360.addresses.map_results_aria') }}"></div>
<div id="c360-address-map" aria-label="{{ __('customer_360.addresses.map_aria') }}"></div>
<div class="c360-map-footer">
<span class="c360-map-coords" data-address-map-coords>{{ __('customer_360.addresses.map_choose_point') }}</span>
<div class="c360-actions">
<button type="button" class="foodex-action-secondary button secondary" data-address-map-close>{{ __('customer_360.addresses.map_cancel') }}</button>
<button type="button" class="foodex-action-primary" data-address-map-apply disabled>{{ __('customer_360.addresses.map_apply') }}</button>
</div>
</div>
</div>
</section>
</div>

<script src="{{ asset('assets/leaflet/1.9.4/leaflet.js') }}"></script>
<script>
(() => {
    const financeInvoicePicker = document.querySelector('[data-finance-invoice-picker]');
    if (financeInvoicePicker) {
        const search = financeInvoicePicker.querySelector('[data-finance-invoice-search]');
        const hidden = financeInvoicePicker.querySelector('[data-finance-invoice-id]');
        const results = financeInvoicePicker.querySelector('[data-finance-invoice-results]');
        const status = financeInvoicePicker.querySelector('[data-finance-invoice-status]');
        const clear = financeInvoicePicker.querySelector('[data-finance-invoice-clear]');
        const retry = financeInvoicePicker.querySelector('[data-finance-invoice-retry]');
        const endpoint = financeInvoicePicker.dataset.url;
        let controller = null;
        let timer = null;
        let activeIndex = -1;
        let options = [];
        let requestGeneration = 0;

        const closeInvoiceResults = () => {
            results.hidden = true;
            search.setAttribute('aria-expanded', 'false');
            search.removeAttribute('aria-activedescendant');
            activeIndex = -1;
            options = [];
        };
        const invoiceMeta = (invoice) => [
            invoice.store || '',
            invoice.status_label || '',
            invoice.issued_at || '',
            @json(__('customer_360.finance.outstanding')) + ': ' + Number(invoice.outstanding || 0).toFixed(2) + ' ' + (invoice.currency || ''),
        ].filter(Boolean).join(' · ');
        const invoiceLabel = (invoice) => invoice.number || '';
        const selectInvoice = (invoice) => {
            hidden.value = String(invoice.id);
            search.value = invoiceLabel(invoice);
            clear.hidden = false;
            status.textContent = @json(__('customer_360.finance.invoice_selected')) + ': ' + invoiceLabel(invoice) + ' · ' + invoiceMeta(invoice);
            closeInvoiceResults();
        };
        const renderInvoiceResults = (items) => {
            results.replaceChildren();
            options = [];
            activeIndex = -1;
            if (!items.length) {
                status.textContent = @json(__('customer_360.finance.invoice_no_results'));
                closeInvoiceResults();
                return;
            }
            items.forEach((invoice, index) => {
                const option = document.createElement('button');
                option.type = 'button';
                option.className = 'c360-invoice-option';
                option.id = 'c360-finance-invoice-option-' + invoice.id;
                option.setAttribute('role', 'option');
                option.setAttribute('aria-selected', String(String(hidden.value) === String(invoice.id)));
                const title = document.createElement('strong');
                title.textContent = invoiceLabel(invoice);
                const meta = document.createElement('small');
                meta.textContent = invoiceMeta(invoice);
                option.append(title, meta);
                option.addEventListener('mousedown', (event) => event.preventDefault());
                option.addEventListener('click', () => selectInvoice(invoice));
                results.appendChild(option);
                options.push(option);
                option.dataset.resultIndex = String(index);
            });
            results.hidden = false;
            search.setAttribute('aria-expanded', 'true');
            status.textContent = '';
        };
        const setActiveInvoice = (next) => {
            if (!options.length) return;
            activeIndex = Math.max(0, Math.min(next, options.length - 1));
            options.forEach((option, index) => option.dataset.active = index === activeIndex ? '1' : '0');
            const active = options[activeIndex];
            search.setAttribute('aria-activedescendant', active.id);
            active.scrollIntoView({block:'nearest'});
        };
        const loadInvoices = async ({selectedOnly = false} = {}) => {
            const generation = ++requestGeneration;
            controller?.abort();
            controller = new AbortController();
            status.textContent = @json(__('customer_360.finance.invoice_searching'));
            retry.hidden = true;
            const url = new URL(endpoint, window.location.origin);
            if (!selectedOnly) url.searchParams.set('q', search.value.trim());
            if (hidden.value) url.searchParams.set('selected_id', hidden.value);
            const entryType = financeInvoicePicker.closest('form')?.querySelector('[name="entry_type"]')?.value;
            if (entryType) url.searchParams.set('entry_type', entryType);
            try {
                const response = await fetch(url, {headers:{Accept:'application/json'}, signal:controller.signal});
                if (!response.ok) throw new Error('lookup');
                const payload = await response.json();
                if (generation !== requestGeneration) return;
                if (selectedOnly && payload.selected) {
                    selectInvoice(payload.selected);
                    return;
                }
                retry.hidden = true;
                renderInvoiceResults(Array.isArray(payload.results) ? payload.results : []);
            } catch (error) {
                if (error?.name === 'AbortError' || generation !== requestGeneration) return;
                closeInvoiceResults();
                status.textContent = @json(__('customer_360.finance.invoice_search_failed'));
                retry.hidden = false;
            }
        };

        retry.addEventListener('click', () => loadInvoices());
        search.addEventListener('input', () => {
            if (hidden.value) {
                hidden.value = '';
                clear.hidden = true;
            }
            clearTimeout(timer);
            timer = setTimeout(() => loadInvoices(), 180);
        });
        search.addEventListener('focus', () => loadInvoices());
        search.addEventListener('keydown', (event) => {
            if (event.key === 'ArrowDown') {
                event.preventDefault();
                if (results.hidden) loadInvoices();
                else setActiveInvoice(activeIndex + 1);
            } else if (event.key === 'ArrowUp') {
                event.preventDefault();
                setActiveInvoice(activeIndex <= 0 ? options.length - 1 : activeIndex - 1);
            } else if (event.key === 'Enter' && activeIndex >= 0 && options[activeIndex]) {
                event.preventDefault();
                options[activeIndex].click();
            } else if (event.key === 'Escape') {
                closeInvoiceResults();
            }
        });
        clear.addEventListener('click', () => {
            hidden.value = '';
            search.value = '';
            clear.hidden = true;
            status.textContent = '';
            retry.hidden = true;
            closeInvoiceResults();
            search.focus();
        });
        document.addEventListener('click', (event) => {
            if (!financeInvoicePicker.contains(event.target)) closeInvoiceResults();
        });
        if (hidden.value) loadInvoices({selectedOnly:true});
    }

    const settlementDialog = document.getElementById('c360-settlement-dialog');
    document.querySelectorAll('[data-settlement-open]').forEach((button) => {
        button.addEventListener('click', () => {
            if (!settlementDialog) return;
            const form = settlementDialog.querySelector('[data-settlement-form]');
            const invoiceId = button.dataset.invoiceId;
            form.action = @json(route('admin.customer-360.show',['platformCustomer'=>$customer->id])) + '/invoices/' + invoiceId + '/settle';
            settlementDialog.querySelector('[data-settlement-invoice]').textContent = button.dataset.invoiceNumber || '';
            settlementDialog.querySelector('[data-settlement-outstanding]').textContent = (button.dataset.outstanding || '') + ' ' + (button.dataset.currency || '');
            const amount = settlementDialog.querySelector('[data-settlement-amount]');
            amount.value = button.dataset.outstanding || '';
            amount.max = button.dataset.outstanding || '';
            settlementDialog.showModal();
        });
    });
    settlementDialog?.querySelector('[data-settlement-close]')?.addEventListener('click', () => settlementDialog.close());

    const mapModal = document.querySelector('[data-address-map-modal]');
    const mapContainer = document.getElementById('c360-address-map');
    const searchInput = mapModal?.querySelector('[data-address-map-search]');
    const searchButton = mapModal?.querySelector('[data-address-map-search-button]');
    const resultsBox = mapModal?.querySelector('[data-address-map-results]');
    const searchState = mapModal?.querySelector('[data-address-map-search-state]');
    const searchMessage = mapModal?.querySelector('[data-address-map-search-message]');
    const searchRetry = mapModal?.querySelector('[data-address-map-search-retry]');
    const coordsBox = mapModal?.querySelector('[data-address-map-coords]');
    const applyButton = mapModal?.querySelector('[data-address-map-apply]');
    const defaultMapCenter = [26.8206,30.8025];
    const defaultMapZoom = 6;
    let activeForm = null;
    let map = null;
    let marker = null;
    let selected = null;
    let searchRequestId = 0;
    let mapSessionId = 0;
    let lastSearchQuery = '';

    const resetSearchState = () => {
        resultsBox.replaceChildren();
        resultsBox.dataset.visible = '0';
        searchState.dataset.visible = '0';
        searchMessage.textContent = '';
        searchRetry.hidden = true;
    };

    const showSearchState = (message, retry = false) => {
        resultsBox.dataset.visible = '0';
        searchMessage.textContent = message;
        searchRetry.hidden = !retry;
        searchState.dataset.visible = '1';
    };

    const setSelected = (lat, lng, center = true) => {
        selected = {lat:Number(lat), lng:Number(lng)};
        if (!Number.isFinite(selected.lat) || !Number.isFinite(selected.lng)) return;
        if (!marker) marker = L.marker([selected.lat, selected.lng], {draggable:true}).addTo(map);
        else marker.setLatLng([selected.lat, selected.lng]);
        marker.off('dragend').on('dragend', () => {
            const point = marker.getLatLng();
            setSelected(point.lat, point.lng, false);
        });
        if (center) map.setView([selected.lat, selected.lng], Math.max(map.getZoom(), 16));
        coordsBox.textContent = selected.lat.toFixed(7) + ', ' + selected.lng.toFixed(7);
        applyButton.disabled = false;
    };

    const ensureMap = () => {
        if (map || !mapContainer || typeof L === 'undefined') return;
        map = L.map(mapContainer).setView(defaultMapCenter, defaultMapZoom);
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            maxZoom:19,
            attribution:'&copy; OpenStreetMap contributors',
        }).addTo(map);
        map.on('click', (event) => setSelected(event.latlng.lat, event.latlng.lng, false));
    };

    const closeMap = () => {
        if (!mapModal) return;
        searchRequestId += 1;
        mapSessionId += 1;
        mapModal.dataset.open = '0';
        mapModal.setAttribute('aria-hidden','true');
        mapModal.removeAttribute('aria-busy');
        document.body.style.overflow = '';
        resetSearchState();
        searchButton.disabled = false;
        activeForm = null;
    };

    document.querySelectorAll('[data-address-map-picker]').forEach((button) => {
        button.addEventListener('click', () => {
            searchRequestId += 1;
            mapSessionId += 1;
            activeForm = button.closest('form');
            lastSearchQuery = '';
            searchInput.value = '';
            resetSearchState();
            ensureMap();
            mapModal.dataset.open = '1';
            mapModal.setAttribute('aria-hidden','false');
            document.body.style.overflow = 'hidden';
            requestAnimationFrame(() => map?.invalidateSize());
            const lat = Number(activeForm?.querySelector('input[name="latitude"]')?.value);
            const lng = Number(activeForm?.querySelector('input[name="longitude"]')?.value);
            if (Number.isFinite(lat) && Number.isFinite(lng) && activeForm?.querySelector('input[name="latitude"]')?.value !== '' && activeForm?.querySelector('input[name="longitude"]')?.value !== '') {
                setSelected(lat,lng,true);
            } else {
                selected = null;
                if (marker) { marker.remove(); marker = null; }
                map?.setView(defaultMapCenter, defaultMapZoom);
                coordsBox.textContent = @json(__('customer_360.addresses.map_choose_point'));
                applyButton.disabled = true;
            }
            searchInput?.focus();
        });
    });

    mapModal?.querySelectorAll('[data-address-map-close]').forEach((button) => button.addEventListener('click', closeMap));
    mapModal?.addEventListener('click', (event) => { if (event.target === mapModal) closeMap(); });

    const runSearch = async (retryQuery = null) => {
        const query = (retryQuery ?? searchInput?.value ?? '').trim();
        if (!query || !activeForm || mapModal?.dataset.open !== '1') return;

        const requestId = ++searchRequestId;
        const sessionId = mapSessionId;
        const requestForm = activeForm;
        lastSearchQuery = query;
        searchButton.disabled = true;
        mapModal.setAttribute('aria-busy','true');
        resultsBox.replaceChildren();
        showSearchState(@json(__('customer_360.addresses.map_searching')));

        const ownsSearch = () =>
            requestId === searchRequestId
            && sessionId === mapSessionId
            && activeForm === requestForm
            && mapModal?.dataset.open === '1';

        try {
            const response = await fetch('https://nominatim.openstreetmap.org/search?format=jsonv2&limit=6&q=' + encodeURIComponent(query), {
                headers:{'Accept':'application/json'},
            });
            if (!response.ok) throw new Error('map-search-http-' + response.status);
            const payload = await response.json();
            if (!ownsSearch()) return;
            const rows = Array.isArray(payload) ? payload : [];
            resultsBox.replaceChildren();
            rows.forEach((row) => {
                const button = document.createElement('button');
                button.type = 'button';
                button.className = 'c360-map-result';
                button.textContent = row.display_name;
                button.addEventListener('click', () => {
                    if (!ownsSearch()) return;
                    setSelected(row.lat,row.lon,true);
                    resetSearchState();
                });
                resultsBox.appendChild(button);
            });

            if (rows.length) {
                searchState.dataset.visible = '0';
                searchMessage.textContent = '';
                searchRetry.hidden = true;
                resultsBox.dataset.visible = '1';
            } else {
                showSearchState(@json(__('customer_360.addresses.map_no_results')));
            }
        } catch (_) {
            if (!ownsSearch()) return;
            showSearchState(@json(__('customer_360.addresses.map_failed')), true);
        } finally {
            if (ownsSearch()) {
                searchButton.disabled = false;
                mapModal.removeAttribute('aria-busy');
            }
        }
    };
    searchButton?.addEventListener('click', () => runSearch());
    searchRetry?.addEventListener('click', () => runSearch(lastSearchQuery));
    searchInput?.addEventListener('keydown', (event) => {
        if (event.key === 'Enter') { event.preventDefault(); runSearch(); }
    });

    applyButton?.addEventListener('click', () => {
        if (!activeForm || !selected) return;
        const latInput = activeForm.querySelector('input[name="latitude"]');
        const lngInput = activeForm.querySelector('input[name="longitude"]');
        const sourceInput = activeForm.querySelector('input[name="location_source"]');
        if (latInput) latInput.value = selected.lat.toFixed(7);
        if (lngInput) lngInput.value = selected.lng.toFixed(7);
        if (sourceInput) sourceInput.value = 'map_pin';
        const summary = activeForm.querySelector('[data-address-map-summary]');
        if (summary) summary.textContent = @json(__('customer_360.addresses.map_selected_editable'));
        closeMap();
    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && mapModal?.dataset.open === '1') closeMap();
    });

    const syncGeographyForm = (form) => {
        const country = form.querySelector('[data-geography-country]');
        if (!country) return;
        const countryCode = country.value;
        const governorate = form.querySelector('[data-geography-level="governorate"]');
        const city = form.querySelector('[data-geography-level="city"]');
        const area = form.querySelector('[data-geography-level="area"]');

        const selectedNodeId = (select) => select?.selectedOptions?.[0]?.dataset?.nodeId || '';
        const filter = (select, parentSelect = null) => {
            if (!select) return;
            const parentNodeId = selectedNodeId(parentSelect);
            Array.from(select.options).forEach((option, index) => {
                if (index === 0 || !option.value) {
                    option.hidden = false;
                    return;
                }
                const sameCountry = !option.dataset.country || option.dataset.country === countryCode;
                const parentMatches = !parentNodeId || !option.dataset.parentId || option.dataset.parentId === parentNodeId;
                option.hidden = !(sameCountry && parentMatches);
            });
            if (select.selectedOptions[0]?.hidden) select.value = '';
        };

        filter(governorate);
        filter(city, governorate);
        filter(area, city?.value ? city : governorate);
    };

    document.querySelectorAll('.c360-address-form').forEach((form) => {
        const controls = form.querySelectorAll('[data-geography-country],[data-geography-level]');
        controls.forEach((control) => control.addEventListener('change', () => syncGeographyForm(form)));
        syncGeographyForm(form);
    });

    const tabs = Array.from(document.querySelectorAll('[data-c360-tab]'));
    const panels = Array.from(document.querySelectorAll('[data-c360-panel]'));

    const activate = (name, updateHash = false) => {
        const target = tabs.find((tab) => tab.dataset.c360Tab === name);
        if (!target) return;

        tabs.forEach((tab) => {
            const active = tab === target;
            tab.setAttribute('aria-selected', active ? 'true' : 'false');
            tab.tabIndex = active ? 0 : -1;
        });

        panels.forEach((panel) => {
            const active = panel.dataset.c360Panel === name;
            panel.classList.toggle('active', active);
            panel.hidden = !active;
        });

        if (updateHash) history.replaceState(null, '', '#'+name);
        target.scrollIntoView({block:'nearest', inline:'nearest'});
    };

    tabs.forEach((tab, index) => {
        tab.addEventListener('click', () => activate(tab.dataset.c360Tab, true));
        tab.addEventListener('keydown', (event) => {
            if (!['ArrowLeft','ArrowRight','Home','End'].includes(event.key)) return;
            event.preventDefault();
            let next = index;
            if (event.key === 'Home') next = 0;
            else if (event.key === 'End') next = tabs.length - 1;
            else if (event.key === 'ArrowLeft') next = Math.max(0, index - 1);
            else next = Math.min(tabs.length - 1, index + 1);
            tabs[next].focus();
            activate(tabs[next].dataset.c360Tab, true);
        });
    });

    const requested = location.hash.replace('#','');
    activate(['finance','identity','service','addresses','stores','orders','invoices','statement','reports'].includes(requested) ? requested : 'finance');
})();
</script>
</body>
</html>
