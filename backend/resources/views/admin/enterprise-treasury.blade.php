<!doctype html>
<html lang="{{ app()->getLocale() }}" dir="{{ app()->getLocale()==='ar'?'rtl':'ltr' }}">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>{{ __('enterprise_treasury.title') }} · FOODEX</title>
@include('admin._brand-components')
<style>
.erp-treasury{display:grid;gap:16px}.erp-context{display:flex;gap:12px;align-items:end;justify-content:space-between;flex-wrap:wrap;padding:14px}.erp-context form,.erp-filter,.erp-form{display:flex;gap:10px;align-items:end;flex-wrap:wrap}.erp-form{display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));padding:14px}.erp-context label,.erp-filter label,.erp-form label{display:grid;gap:5px;font-weight:700;font-size:.82rem}.erp-actions{display:flex;gap:6px;flex-wrap:wrap;align-items:center}.erp-actions form{display:inline-flex;gap:6px}.erp-link{display:inline-flex;min-height:38px;align-items:center;padding:0 10px;border-radius:9px;border:1px solid var(--foodex-border);text-decoration:none;font-weight:800;background:transparent}.erp-danger{color:var(--foodex-danger-text)}.erp-table-wrap{overflow:auto}.erp-table td,.erp-table th{vertical-align:top}.erp-ref{white-space:nowrap;font-weight:900}.erp-create summary{cursor:pointer;font-weight:900;color:var(--foodex-green-dark);padding:12px 14px}.erp-create[open] summary{border-bottom:1px solid var(--foodex-border)}.erp-note{padding:12px 14px;color:var(--foodex-muted)}.erp-badge{white-space:nowrap}.erp-match{display:grid;gap:8px;min-width:260px}.erp-match select{max-width:280px}.erp-numeric{font-variant-numeric:tabular-nums;white-space:nowrap}
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
