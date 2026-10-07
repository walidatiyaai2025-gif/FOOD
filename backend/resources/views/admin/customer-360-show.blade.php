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
.c360-kpis{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;margin-bottom:14px}
.c360-kpi{border:1px solid var(--foodex-border);border-radius:14px;padding:16px;background:#fff;display:grid;gap:6px;min-height:100px}
.c360-kpi small{color:var(--foodex-muted);font-weight:700}.c360-kpi strong{font-size:1.22rem;word-break:break-word}.c360-kpi.primary{background:linear-gradient(135deg,#f5fff8,#fff)}
.c360-credit-editor{display:flex;align-items:end;gap:8px;margin-top:4px}.c360-credit-editor label{display:grid;gap:5px;min-width:0;flex:1}.c360-credit-editor input{width:100%;box-sizing:border-box;border:1px solid var(--foodex-border);border-radius:9px;padding:9px 10px;background:#fff;font:inherit}.c360-credit-editor button{white-space:nowrap}
.c360-info{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px}.c360-info>div{padding:14px;border:1px solid var(--foodex-border);border-radius:12px;background:#fbfcfd}.c360-info small{display:block;color:var(--foodex-muted);margin-bottom:5px}.c360-info strong{word-break:break-word}
.c360-badge{display:inline-flex;align-items:center;gap:5px;border:1px solid var(--foodex-border);border-radius:999px;padding:5px 9px;font-size:12px;font-weight:800;background:#fff}.c360-badge.active{background:var(--foodex-green-soft);color:var(--foodex-green-dark);border-color:transparent}.c360-badge.b2b{background:#fff7ed;color:#9a3412}.c360-badge.b2c{background:#eefbf4;color:#166534}
.c360-list{display:flex;gap:8px;flex-wrap:wrap}.c360-store{padding:10px 13px;border:1px solid var(--foodex-border);border-radius:10px;background:#fff;font-weight:800}
.c360-address-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(300px,1fr));gap:12px}.c360-address-card{padding:15px;border:1px solid var(--foodex-border);border-radius:12px;background:#fff}.c360-address-actions{display:flex;gap:8px;flex-wrap:wrap;align-items:center}.c360-address-form{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}.c360-address-form .wide{grid-column:1/-1}.c360-address-form input,.c360-address-form textarea,.c360-address-form select{width:100%;box-sizing:border-box}
.c360-table-wrap{overflow:auto}.c360-table{width:100%;border-collapse:collapse;min-width:760px}.c360-table th,.c360-table td{padding:12px;border-bottom:1px solid var(--foodex-border);text-align:start;vertical-align:middle}.c360-table th{font-size:12px;color:var(--foodex-muted);background:#f8fafc}.c360-table tr:hover td{background:#fbfefc}
.c360-empty{padding:42px 20px;text-align:center;color:var(--foodex-muted);border:1px dashed var(--foodex-border);border-radius:12px;background:#fbfcfd}
.c360-finance-details{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:0;border:1px solid var(--foodex-border);border-radius:14px;overflow:hidden}.c360-finance-details>div{padding:15px;border-inline-end:1px solid var(--foodex-border);border-bottom:1px solid var(--foodex-border)}.c360-finance-details>div:nth-child(3n){border-inline-end:0}.c360-finance-details small{display:block;color:var(--foodex-muted);margin-bottom:5px}
.c360-form-details{margin-top:16px;border-top:1px solid var(--foodex-border);padding-top:14px}.c360-form-details summary{cursor:pointer;font-weight:800}
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
.c360-map-results{display:none;max-height:150px;overflow:auto;border:1px solid var(--foodex-border);border-radius:10px;background:#fff}
.c360-map-results[data-visible="1"]{display:block}
.c360-map-result{display:block;width:100%;text-align:start;border:0;border-bottom:1px solid var(--foodex-border);background:#fff;padding:10px 12px;cursor:pointer;font:inherit}.c360-map-result:last-child{border-bottom:0}.c360-map-result:hover{background:#f7faf8}
#c360-address-map{height:min(56vh,500px);min-height:340px;border-radius:12px;border:1px solid var(--foodex-border);overflow:hidden}
.c360-map-footer{display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap}.c360-map-coords{font-family:var(--foodex-font-en);color:var(--foodex-muted);font-size:.82rem;direction:ltr}
@media(max-width:1100px){.c360-kpis{grid-template-columns:repeat(2,1fr)}.c360-finance-details{grid-template-columns:repeat(2,1fr)}.c360-finance-details>div:nth-child(3n){border-inline-end:1px solid var(--foodex-border)}.c360-finance-details>div:nth-child(2n){border-inline-end:0}}
@media(max-width:720px){.c360-summary{align-items:flex-start}.c360-avatar{width:54px;height:54px;font-size:27px}.c360-kpis{grid-template-columns:1fr}.c360-finance-details{grid-template-columns:1fr}.c360-finance-details>div{border-inline-end:0!important}.c360-address-form{grid-template-columns:1fr}.c360-address-form .wide{grid-column:auto}.c360-tab{padding:12px 15px}.c360-card{padding:15px}}
</style>
</head>
<body>
@php($ar=app()->getLocale()==='ar')
@php($finance=$wholesale['financial'] ?? null)
@php
$businessLabel = static function ($value): string {
    $key = strtolower(trim((string) ($value ?? '')));
    if ($key === '') {
        return '—';
    }

    $translationKey = 'customer_360.business_labels.'.$key;
    $translated = __($translationKey);

    return $translated === $translationKey
        ? ucwords(str_replace(['_', '-'], ' ', $key))
        : $translated;
};
@endphp
<div class="foodex-admin-layout">
<aside class="sidebar">@include('admin._sidebar')</aside>
<main class="foodex-admin-main foodex-admin-page">
<div class="c360-shell">

<div class="c360-topline">
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
@include('admin._live-notifications',['user'=>auth()->user()])
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
<span class="c360-badge {{ $summary['active']?'active':'' }}">{{ $businessLabel($summary['registration_source']) }}</span>
</section>

@if(session('status'))<div class="foodex-success">{{ session('status') }}</div>@endif
@if($errors->any())<div class="foodex-error">{{ $errors->first() }}</div>@endif

<div class="c360-tabs-wrap">
<div class="c360-tabs" role="tablist" aria-label="{{ __('customer_360.sections') }}">
<button class="c360-tab" type="button" role="tab" id="tab-finance" aria-controls="panel-finance" aria-selected="true" data-c360-tab="finance"><span class="c360-tab-icon">▣</span>{{ __('customer_360.tabs.finance') }}</button>
<button class="c360-tab" type="button" role="tab" id="tab-identity" aria-controls="panel-identity" aria-selected="false" data-c360-tab="identity"><span class="c360-tab-icon">♙</span>{{ __('customer_360.tabs.identity') }}</button>
<button class="c360-tab" type="button" role="tab" id="tab-addresses" aria-controls="panel-addresses" aria-selected="false" data-c360-tab="addresses"><span class="c360-tab-icon">⌖</span>{{ __('customer_360.tabs.addresses') }}</button>
<button class="c360-tab" type="button" role="tab" id="tab-stores" aria-controls="panel-stores" aria-selected="false" data-c360-tab="stores"><span class="c360-tab-icon">▦</span>{{ __('customer_360.tabs.stores') }}</button>
<button class="c360-tab" type="button" role="tab" id="tab-orders" aria-controls="panel-orders" aria-selected="false" data-c360-tab="orders"><span class="c360-tab-icon">🛒</span>{{ __('customer_360.tabs.orders') }}</button>
<button class="c360-tab" type="button" role="tab" id="tab-invoices" aria-controls="panel-invoices" aria-selected="false" data-c360-tab="invoices"><span class="c360-tab-icon">▤</span>{{ __('customer_360.tabs.invoices') }}</button>
</div>
</div>

<section class="c360-panel active" id="panel-finance" role="tabpanel" aria-labelledby="tab-finance" data-c360-panel="finance">
<div class="foodex-card c360-card">
<div class="c360-section-head"><h2>{{ __('customer_360.tabs.finance') }}</h2>@if($wholesale)<span class="c360-badge {{ strtolower((string)($wholesale['status'] ?? ''))==='active'?'active':'' }}">{{ $businessLabel($wholesale['status']) }}</span>@endif</div>
@if($wholesale)
<div class="c360-kpis">
@if($finance)
<article class="c360-kpi primary"><small>{{ __('customer_360.finance.current_balance') }}</small><strong>{{ number_format(abs((float)$finance['balance']),3) }} {{ $finance['currency'] ?: '' }}</strong><span class="muted">@if($finance['balance_direction']==='customer_owes_company'){{ __('customer_360.finance.customer_owes_company') }}@elseif($finance['balance_direction']==='company_owes_customer'){{ __('customer_360.finance.company_owes_customer') }}@else{{ __('customer_360.finance.settled') }}@endif</span></article>
<article class="c360-kpi">
<small>{{ __('customer_360.finance.credit_limit') }}</small>
<strong>{{ number_format((float)$finance['credit_limit'],3) }} {{ $finance['currency'] ?: '' }}</strong>
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
<article class="c360-kpi"><small>{{ __('customer_360.finance.available_credit') }}</small><strong>{{ number_format((float)$finance['available_credit_line'],3) }} {{ $finance['currency'] ?: '' }}</strong></article>
<article class="c360-kpi"><small>{{ __('customer_360.finance.purchasing_power') }}</small><strong>{{ number_format((float)$finance['purchasing_power'],3) }} {{ $finance['currency'] ?: '' }}</strong></article>
@endif
</div>
<div class="c360-finance-details">
<div><small>{{ __('customer_360.finance.company') }}</small><strong>{{ $wholesale['company_name'] ?: $customer->name }}</strong></div>
<div><small>{{ __('customer_360.finance.price_tier') }}</small><strong>{{ $wholesale['tier_name'] ?: '-' }}</strong></div>
<div><small>{{ __('customer_360.finance.account_status') }}</small><strong>{{ $businessLabel($wholesale['status']) }}</strong></div>
@if($finance)
<div><small>{{ __('customer_360.finance.open_amount') }}</small><strong>{{ number_format((float)$finance['open_amount'],3) }} {{ $finance['currency'] ?: '' }}</strong></div>
<div><small>{{ __('customer_360.finance.overdue_amount') }}</small><strong>{{ number_format((float)$finance['overdue_amount'],3) }} {{ $finance['currency'] ?: '' }}</strong></div>
<div><small>{{ __('customer_360.finance.last_payment') }}</small><strong>{{ $finance['last_payment']['occurred_at'] ?? '-' }}</strong></div>
<div><small>{{ __('customer_360.finance.last_transaction') }}</small><strong>{{ $finance['last_transaction']['occurred_at'] ?? '-' }}</strong></div>
@else
<div><small>{{ __('customer_360.finance.credit_limit') }}</small><strong>{{ $wholesale['credit_limit']===null?'-':number_format($wholesale['credit_limit'],3) }}</strong></div>
@endif
</div>
@if($canManageFinance && $finance)
<details class="c360-form-details">
<summary>{{ __('customer_360.finance.record_entry') }}</summary>
<form method="post" action="{{ route('admin.customer-360.finance-entries.store',['platformCustomer'=>$customer->id]) }}" class="c360-address-form" style="margin-top:14px">
@csrf
<label><small>{{ __('customer_360.finance.entry_type') }}</small><select name="entry_type" required><option value="customer_credit">{{ __('customer_360.finance.entry_types.customer_credit') }}</option><option value="payment">{{ __('customer_360.finance.entry_types.payment') }}</option><option value="credit_note">{{ __('customer_360.finance.entry_types.credit_note') }}</option><option value="debit_note">{{ __('customer_360.finance.entry_types.debit_note') }}</option><option value="opening_balance">{{ __('customer_360.finance.entry_types.opening_balance') }}</option><option value="adjustment_positive">{{ __('customer_360.finance.entry_types.adjustment_positive') }}</option><option value="adjustment_negative">{{ __('customer_360.finance.entry_types.adjustment_negative') }}</option><option value="return">{{ __('customer_360.finance.entry_types.return') }}</option><option value="refund">{{ __('customer_360.finance.entry_types.refund') }}</option></select></label>
<label><small>{{ __('customer_360.finance.direction') }}</small><select name="direction" required><option value="credit">{{ __('customer_360.finance.directions.credit') }}</option><option value="debit">{{ __('customer_360.finance.directions.debit') }}</option></select></label>
<label><small>{{ __('customer_360.finance.amount') }}</small><input name="amount" type="number" min="0.001" step="0.001" required></label>
<label><small>{{ __('customer_360.finance.currency') }}</small><input name="currency" value="{{ $finance['currency'] }}" maxlength="3" minlength="3" required></label>
<label><small>{{ __('customer_360.finance.reference') }}</small><input name="reference" maxlength="120"></label>
<label><small>{{ __('customer_360.finance.invoice') }}</small><select name="invoice_id"><option value="">—</option>@foreach($invoices as $invoice)<option value="{{ $invoice['id'] }}">{{ $invoice['number'] }}</option>@endforeach</select></label>
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
<div><small>{{ __('customer_360.identity.email') }}</small><strong>{{ $customer->email }}</strong></div>
<div><small>{{ __('customer_360.identity.phone') }}</small><strong>{{ $customer->phone ?: '-' }}</strong></div>
<div><small>{{ __('customer_360.identity.registered_at') }}</small><strong>{{ optional($customer->registered_at)->format('Y-m-d H:i') ?: '-' }}</strong></div>
<div><small>{{ __('customer_360.identity.origin_channel') }}</small><strong>{{ $businessLabel($customer->origin_channel ?: 'unknown') }}</strong></div>
<div><small>{{ __('customer_360.identity.registration_source') }}</small><strong>{{ $businessLabel($customer->registration_source) }}</strong></div>
<div><small>{{ __('customer_360.identity.registration_origin') }}</small><strong>{{ $summary['origin']['label'] }}</strong></div>
</div>
</div>
</section>

<section class="c360-panel" id="panel-addresses" role="tabpanel" aria-labelledby="tab-addresses" data-c360-panel="addresses" hidden>
<div class="foodex-card c360-card">
<div class="c360-section-head"><div><h2>{{ __('customer_360.tabs.addresses') }}</h2><p>{{ $ar?'إدارة العناوين المحفوظة للعميل.':'Manage the customer saved addresses.' }}</p></div><span class="c360-badge">{{ count($addresses) }} {{ $ar?'عنوان':'addresses' }}</span></div>
@if($canManageAddresses)
<details class="c360-form-details" style="margin-top:0;margin-bottom:16px;border-top:0;padding-top:0">
<summary class="foodex-action-primary" style="display:inline-flex">{{ $ar?'إضافة عنوان جديد':'Add new address' }}</summary>
<form method="post" action="{{ route('admin.customer-360.addresses.store',['platformCustomer'=>$customer->id]) }}" class="c360-address-form" style="margin-top:14px">@csrf
<label><small>{{ $ar?'اسم العنوان':'Label' }}</small><input name="label" maxlength="100" placeholder="{{ $ar?'المنزل / العمل':'Home / Work' }}"></label>
<label><small>{{ $ar?'اسم المستلم':'Recipient' }}</small><input name="recipient_name" maxlength="255"></label>
<label><small>{{ $ar?'هاتف التوصيل':'Delivery phone' }}</small><input name="delivery_phone" maxlength="50"></label>
<label><small>{{ $ar?'المدينة':'City' }}</small><input name="city" maxlength="120" required></label>
<label class="wide"><small>{{ $ar?'العنوان':'Address' }}</small><input name="line1" maxlength="255" required></label>
<label><small>{{ $ar?'المنطقة':'Area' }}</small><input name="area" maxlength="120"></label>
<label><small>{{ $ar?'المحافظة':'Governorate' }}</small><input name="governorate" maxlength="120"></label>
<label><small>{{ $ar?'البلوك':'Block' }}</small><input name="block" maxlength="120"></label>
<label><small>{{ $ar?'المبنى':'Building' }}</small><input name="building" maxlength="120"></label>
<label><small>{{ $ar?'الدور':'Floor' }}</small><input name="floor" maxlength="120"></label>
<label><small>{{ $ar?'الشقة':'Apartment' }}</small><input name="apartment" maxlength="120"></label>
<label><small>{{ $ar?'رمز الدولة':'Country code' }}</small><input name="country_code" maxlength="2" required></label>
<input name="latitude" type="hidden">
<input name="longitude" type="hidden">
<input type="hidden" name="location_source" value="manual">
<div class="wide c360-map-picker-row">
<button type="button" class="foodex-action-secondary button secondary" data-address-map-picker>{{ $ar?'تحديد الموقع على الخريطة':'Choose location on map' }}</button>
<span class="c360-map-picked" data-address-map-summary>{{ $ar?'لم يتم تحديد موقع بعد':'No location selected yet' }}</span>
</div>
<label class="wide"><small>{{ $ar?'علامة مميزة':'Landmark' }}</small><input name="landmark" maxlength="255"></label>
<label class="wide"><small>{{ $ar?'ملاحظات التوصيل':'Delivery notes' }}</small><textarea name="delivery_notes" maxlength="1000" rows="2"></textarea></label>
<label class="wide"><input type="checkbox" name="is_default" value="1"> {{ $ar?'تعيين كعنوان افتراضي':'Set as default' }}</label>
<div class="wide"><button class="foodex-action-primary" type="submit">{{ $ar?'حفظ العنوان':'Save address' }}</button></div>
</form>
</details>
@endif
<div class="c360-address-grid">
@forelse($addresses as $address)
<article class="c360-address-card">
<div class="c360-actions" style="justify-content:space-between"><strong>{{ $address->label ?: ($ar?'عنوان التوصيل':'Delivery address') }}</strong>@if($address->is_default)<span class="c360-badge active">{{ $ar?'افتراضي':'Default' }}</span>@endif</div>
<p style="margin:8px 0">{{ collect([$address->building,$address->street ?: $address->line1,$address->block,$address->area,$address->city,$address->governorate])->filter()->join(' · ') }}</p>
@if($address->landmark)<small>{{ $ar?'علامة مميزة':'Landmark' }}: {{ $address->landmark }}</small>@endif
@if($address->latitude!==null && $address->longitude!==null)<div style="margin-top:8px"><span class="c360-badge active">{{ $ar?'تم تحديد الموقع على الخريطة':'Map location selected' }}</span></div>@endif
@if($canManageAddresses)
<div class="c360-address-actions" style="margin-top:12px">
@if(!$address->is_default)<form method="post" action="{{ route('admin.customer-360.addresses.default',['platformCustomer'=>$customer->id,'address'=>$address->id]) }}">@csrf<button class="foodex-action-secondary button secondary" type="submit">{{ $ar?'تعيين افتراضي':'Set default' }}</button></form>@endif
<form method="post" action="{{ route('admin.customer-360.addresses.destroy',['platformCustomer'=>$customer->id,'address'=>$address->id]) }}" onsubmit="return confirm('{{ $ar?'حذف هذا العنوان؟':'Delete this address?' }}')">@csrf @method('DELETE')<button class="foodex-action-secondary button secondary" type="submit">{{ $ar?'حذف':'Delete' }}</button></form>
</div>
<details class="c360-form-details"><summary>{{ $ar?'تعديل العنوان':'Edit address' }}</summary>
<form method="post" action="{{ route('admin.customer-360.addresses.update',['platformCustomer'=>$customer->id,'address'=>$address->id]) }}" class="c360-address-form" style="margin-top:12px">@csrf @method('PATCH')
<label><small>{{ $ar?'اسم العنوان':'Label' }}</small><input name="label" value="{{ $address->label }}" maxlength="100"></label><label><small>{{ $ar?'اسم المستلم':'Recipient' }}</small><input name="recipient_name" value="{{ $address->recipient_name }}" maxlength="255"></label><label><small>{{ $ar?'هاتف التوصيل':'Delivery phone' }}</small><input name="delivery_phone" value="{{ $address->delivery_phone }}" maxlength="50"></label><label><small>{{ $ar?'المدينة':'City' }}</small><input name="city" value="{{ $address->city }}" maxlength="120"></label><label class="wide"><small>{{ $ar?'العنوان':'Address' }}</small><input name="line1" value="{{ $address->line1 }}" maxlength="255"></label><label><small>{{ $ar?'المنطقة':'Area' }}</small><input name="area" value="{{ $address->area }}" maxlength="120"></label><label><small>{{ $ar?'المحافظة':'Governorate' }}</small><input name="governorate" value="{{ $address->governorate }}" maxlength="120"></label><label><small>{{ $ar?'البلوك':'Block' }}</small><input name="block" value="{{ $address->block }}" maxlength="120"></label><label><small>{{ $ar?'المبنى':'Building' }}</small><input name="building" value="{{ $address->building }}" maxlength="120"></label><label><small>{{ $ar?'الدور':'Floor' }}</small><input name="floor" value="{{ $address->floor }}" maxlength="120"></label><label><small>{{ $ar?'الشقة':'Apartment' }}</small><input name="apartment" value="{{ $address->apartment }}" maxlength="120"></label><label><small>{{ $ar?'رمز الدولة':'Country code' }}</small><input name="country_code" value="{{ $address->country_code }}" maxlength="2"></label><input name="latitude" type="hidden" value="{{ $address->latitude }}"><input name="longitude" type="hidden" value="{{ $address->longitude }}"><input type="hidden" name="location_source" value="{{ $address->location_source ?: 'manual' }}"><div class="wide c360-map-picker-row"><button type="button" class="foodex-action-secondary button secondary" data-address-map-picker>{{ $address->latitude!==null && $address->longitude!==null ? ($ar?'تعديل الموقع على الخريطة':'Edit map location') : ($ar?'تحديد الموقع على الخريطة':'Choose location on map') }}</button><span class="c360-map-picked" data-address-map-summary>@if($address->latitude!==null && $address->longitude!==null){{ $ar?'تم تحديد الموقع':'Location selected' }}@else{{ $ar?'لم يتم تحديد موقع بعد':'No location selected yet' }}@endif</span></div><label class="wide"><small>{{ $ar?'علامة مميزة':'Landmark' }}</small><input name="landmark" value="{{ $address->landmark }}" maxlength="255"></label><label class="wide"><small>{{ $ar?'ملاحظات التوصيل':'Delivery notes' }}</small><textarea name="delivery_notes" maxlength="1000" rows="2">{{ $address->delivery_notes }}</textarea></label><div class="wide"><button class="foodex-action-primary" type="submit">{{ $ar?'حفظ التعديل':'Save changes' }}</button></div>
</form></details>
@endif
</article>
@empty<div class="c360-empty">{{ $ar?'لا توجد عناوين محفوظة لهذا العميل.':'No saved addresses for this customer.' }}</div>@endforelse
</div>
</div>
</section>

<section class="c360-panel" id="panel-stores" role="tabpanel" aria-labelledby="tab-stores" data-c360-panel="stores" hidden>
<div class="foodex-card c360-card">
<div class="c360-section-head"><h2>{{ __('customer_360.tabs.stores') }}</h2></div>
@if($access['mode']!=='b2b')
<div class="c360-list">@forelse($retailStores as $store)<span class="c360-store">{{ $store['name'] }} · {{ $store['code'] }}</span>@empty<div class="c360-empty" style="width:100%">{{ $ar?'لا توجد متاجر ضمن النطاق الحالي.':'No Retail stores in the current scope.' }}</div>@endforelse</div>
@else
<div class="c360-empty">{{ $ar?'لا تتوفر متاجر تجزئة ضمن هذا النطاق.':'Retail stores are not available in this scope.' }}</div>
@endif
</div>
</section>

<section class="c360-panel" id="panel-orders" role="tabpanel" aria-labelledby="tab-orders" data-c360-panel="orders" hidden>
<div class="foodex-card c360-card">
<div class="c360-section-head"><h2>{{ __('customer_360.tabs.orders') }}</h2><span class="c360-badge">{{ count($orders) }}</span></div>
@if(empty($orders))<div class="c360-empty">{{ $ar?'لا توجد طلبات داخل النطاق الحالي.':'No orders in the current scope.' }}</div>@else
<div class="c360-table-wrap"><table class="c360-table"><thead><tr><th>{{ $ar?'الطلب':'Order' }}</th><th>{{ $ar?'المتجر':'Store' }}</th><th>{{ $ar?'القناة':'Channel' }}</th><th>{{ $ar?'الحالة':'Status' }}</th><th>{{ $ar?'الإجمالي':'Total' }}</th><th>{{ __('customer_360.finance.date') }}</th><th></th></tr></thead><tbody>
@foreach($orders as $order)<tr id="order-{{ $order['id'] }}"><td><strong>{{ $order['number'] }}</strong></td><td>{{ $order['store'] }}</td><td><span class="c360-badge {{ $order['channel'] }}">{{ $businessLabel($order['channel']) }}</span></td><td>{{ $businessLabel($order['status']) }}</td><td>{{ number_format($order['total'],3) }} {{ $order['currency'] }}</td><td>{{ $order['created_at'] }}</td><td><a class="foodex-action-secondary button secondary" href="{{ $order['url'] }}">{{ $ar?'إدارة الطلب':'Manage order' }}</a></td></tr>@endforeach
</tbody></table></div>@endif
</div>
</section>

<section class="c360-panel" id="panel-invoices" role="tabpanel" aria-labelledby="tab-invoices" data-c360-panel="invoices" hidden>
<div class="foodex-card c360-card">
<div class="c360-section-head"><h2>{{ __('customer_360.tabs.invoices') }}</h2><span class="c360-badge">{{ count($invoices) }}</span></div>
@if(empty($invoices))<div class="c360-empty">{{ $ar?'لا توجد فواتير داخل النطاق الحالي.':'No invoices in the current scope.' }}</div>@else
<div class="c360-table-wrap"><table class="c360-table"><thead><tr><th>{{ __('customer_360.finance.invoice') }}</th><th>{{ $ar?'المتجر':'Store' }}</th><th>{{ $ar?'القناة':'Channel' }}</th><th>{{ $ar?'الحالة':'Status' }}</th><th>{{ $ar?'الإجمالي':'Total' }}</th><th>{{ $ar?'الإصدار':'Issued' }}</th><th></th></tr></thead><tbody>
@foreach($invoices as $invoice)<tr><td><strong>{{ $invoice['number'] }}</strong></td><td>{{ $invoice['store'] }}</td><td><span class="c360-badge {{ $invoice['channel'] }}">{{ $businessLabel($invoice['channel']) }}</span></td><td>{{ $businessLabel($invoice['status']) }}</td><td>{{ number_format($invoice['total'],3) }} {{ $invoice['currency'] }}</td><td>{{ $invoice['issued_at'] ?: '-' }}</td><td class="c360-actions"><a class="foodex-action-secondary button secondary" href="{{ $invoice['url'] }}">{{ $ar?'التفاصيل':'Details' }}</a><a class="foodex-action-primary" href="{{ $invoice['pdf_url'] }}">PDF</a></td></tr>@endforeach
</tbody></table></div>@endif
</div>
</section>

</div>
</main>
</div>

<div class="c360-map-modal" data-address-map-modal data-open="0" aria-hidden="true">
<section class="c360-map-dialog" role="dialog" aria-modal="true" aria-labelledby="c360-map-title">
<div class="c360-map-head">
<div><h3 id="c360-map-title">{{ $ar?'تحديد موقع العميل':'Choose customer location' }}</h3><p>{{ $ar?'ابحث عن مكان أو اضغط مباشرة على الخريطة لتحديد النقطة.':'Search for a place or click directly on the map to choose the point.' }}</p></div>
<button type="button" class="c360-map-close" data-address-map-close aria-label="{{ $ar?'إغلاق':'Close' }}">×</button>
</div>
<div class="c360-map-body">
<div class="c360-map-search">
<input type="search" data-address-map-search placeholder="{{ $ar?'ابحث عن منطقة أو شارع أو مكان...':'Search area, street, or place...' }}">
<button type="button" class="foodex-action-secondary button secondary" data-address-map-search-button>{{ $ar?'بحث':'Search' }}</button>
</div>
<div class="c360-map-results" data-address-map-results></div>
<div id="c360-address-map" aria-label="{{ $ar?'خريطة تحديد موقع العميل':'Customer location map' }}"></div>
<div class="c360-map-footer">
<span class="c360-map-coords" data-address-map-coords>{{ $ar?'اختر نقطة على الخريطة':'Choose a point on the map' }}</span>
<div class="c360-actions">
<button type="button" class="foodex-action-secondary button secondary" data-address-map-close>{{ $ar?'إلغاء':'Cancel' }}</button>
<button type="button" class="foodex-action-primary" data-address-map-apply disabled>{{ $ar?'اعتماد الموقع':'Use this location' }}</button>
</div>
</div>
</div>
</section>
</div>

<script src="{{ asset('assets/leaflet/1.9.4/leaflet.js') }}"></script>
<script>
(() => {
    const mapModal = document.querySelector('[data-address-map-modal]');
    const mapContainer = document.getElementById('c360-address-map');
    const searchInput = mapModal?.querySelector('[data-address-map-search]');
    const searchButton = mapModal?.querySelector('[data-address-map-search-button]');
    const resultsBox = mapModal?.querySelector('[data-address-map-results]');
    const coordsBox = mapModal?.querySelector('[data-address-map-coords]');
    const applyButton = mapModal?.querySelector('[data-address-map-apply]');
    let activeForm = null;
    let map = null;
    let marker = null;
    let selected = null;

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
        map = L.map(mapContainer).setView([29.3759,47.9774], 11);
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            maxZoom:19,
            attribution:'&copy; OpenStreetMap contributors',
        }).addTo(map);
        map.on('click', (event) => setSelected(event.latlng.lat, event.latlng.lng, false));
    };

    const closeMap = () => {
        if (!mapModal) return;
        mapModal.dataset.open = '0';
        mapModal.setAttribute('aria-hidden','true');
        document.body.style.overflow = '';
        resultsBox.dataset.visible = '0';
        resultsBox.replaceChildren();
        activeForm = null;
    };

    document.querySelectorAll('[data-address-map-picker]').forEach((button) => {
        button.addEventListener('click', () => {
            activeForm = button.closest('form');
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
                coordsBox.textContent = @json($ar?'اختر نقطة على الخريطة':'Choose a point on the map');
                applyButton.disabled = true;
            }
            searchInput?.focus();
        });
    });

    mapModal?.querySelectorAll('[data-address-map-close]').forEach((button) => button.addEventListener('click', closeMap));
    mapModal?.addEventListener('click', (event) => { if (event.target === mapModal) closeMap(); });

    const runSearch = async () => {
        const query = searchInput?.value.trim();
        if (!query) return;
        searchButton.disabled = true;
        resultsBox.replaceChildren();
        resultsBox.dataset.visible = '0';
        try {
            const response = await fetch('https://nominatim.openstreetmap.org/search?format=jsonv2&limit=6&q=' + encodeURIComponent(query), {
                headers:{'Accept':'application/json'},
            });
            const rows = response.ok ? await response.json() : [];
            rows.forEach((row) => {
                const button = document.createElement('button');
                button.type = 'button';
                button.className = 'c360-map-result';
                button.textContent = row.display_name;
                button.addEventListener('click', () => {
                    setSelected(row.lat,row.lon,true);
                    resultsBox.dataset.visible = '0';
                });
                resultsBox.appendChild(button);
            });
            resultsBox.dataset.visible = rows.length ? '1' : '0';
        } catch (_) {
            resultsBox.dataset.visible = '0';
        } finally {
            searchButton.disabled = false;
        }
    };
    searchButton?.addEventListener('click', runSearch);
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
        if (summary) summary.textContent = @json($ar?'تم تحديد الموقع ويمكن تعديله من الخريطة':'Location selected; you can edit it on the map');
        closeMap();
    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && mapModal?.dataset.open === '1') closeMap();
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
    activate(['finance','identity','addresses','stores','orders','invoices'].includes(requested) ? requested : 'finance');
})();
</script>
</body>
</html>
