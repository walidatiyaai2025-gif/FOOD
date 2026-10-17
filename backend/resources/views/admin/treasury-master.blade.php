<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>{{ __('treasury.title') }} · FOODEX</title>
@include('admin._brand-components')
<style>
.treasury-section{display:grid;gap:var(--foodex-space-4)}
.treasury-form-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:var(--foodex-space-3)}
.treasury-form-grid .wide{grid-column:1/-1}
.treasury-check{display:flex!important;grid-template-columns:none!important;align-items:center;gap:var(--foodex-space-2)!important}
.treasury-check input{inline-size:18px;block-size:18px}
.treasury-actions{display:flex;gap:var(--foodex-space-2);align-items:center;flex-wrap:wrap}
.treasury-code{font-family:var(--foodex-font-en);font-variant-numeric:tabular-nums;white-space:nowrap}
@media(max-width:767px){.treasury-form-grid{grid-template-columns:minmax(0,1fr)}}
</style>
</head>
<body>
<div class="foodex-admin-layout" data-foodex-mobile-web-page="treasury-master">
<aside class="sidebar">@include('admin._sidebar',['navGroups'=>$navGroups,'navContext'=>$navContext,'user'=>$user])</aside>
<main class="foodex-admin-main foodex-admin-page">
@include('admin._global-header')
<div class="foodex-page-toolbar">
<div>
<div class="muted">{{ __('treasury.eyebrow') }}</div>
<h1>{{ __('treasury.title') }}</h1>
<p>{{ __('treasury.description') }}</p>
</div>
@if($manageableStores->isNotEmpty())
<div class="foodex-header-actions">
@if(($filters['tab'] ?? 'cashboxes') === 'bank_accounts')
<a class="foodex-action-primary" href="{{ route('admin.treasury.master.index',['tab'=>'bank_accounts','create'=>'bank_account']) }}">{{ __('treasury.new_bank_account') }}</a>
@else
<a class="foodex-action-primary" href="{{ route('admin.treasury.master.index',['tab'=>'cashboxes','create'=>'cashbox']) }}">{{ __('treasury.new_cashbox') }}</a>
@endif
</div>
@endif
</div>

@if(session('status'))
<div class="foodex-alert foodex-alert-success" role="status">{{ session('status') }}</div>
@endif
@if($errors->any())
<div class="foodex-alert foodex-alert-error" role="alert">
<ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
</div>
@endif

<nav class="foodex-tabs" aria-label="{{ __('treasury.title') }}">
<a class="{{ ($filters['tab'] ?? 'cashboxes') === 'cashboxes' ? 'active' : '' }}" href="{{ route('admin.treasury.master.index',['tab'=>'cashboxes']) }}">{{ __('treasury.cashboxes') }}</a>
<a class="{{ ($filters['tab'] ?? 'cashboxes') === 'bank_accounts' ? 'active' : '' }}" href="{{ route('admin.treasury.master.index',['tab'=>'bank_accounts']) }}">{{ __('treasury.bank_accounts') }}</a>
</nav>

@if((($filters['create'] ?? null) === 'cashbox' || $editingCashbox) && $manageableStores->isNotEmpty())
<section class="treasury-section" aria-labelledby="cashbox-form-title">
<form class="foodex-form" method="post" action="{{ $editingCashbox ? route('admin.treasury.cashboxes.update',['cashbox'=>$editingCashbox->id]) : route('admin.treasury.cashboxes.store') }}">
@csrf
@if($editingCashbox) @method('patch') @endif
<h2 id="cashbox-form-title">{{ $editingCashbox ? __('treasury.edit_cashbox') : __('treasury.new_cashbox') }}</h2>
<div class="treasury-form-grid">
<label>{{ __('treasury.fields.store') }}
<select name="store_id" required>
@foreach($manageableStores as $store)
<option value="{{ $store->id }}" @selected((int)old('store_id',$editingCashbox?->store_id) === (int)$store->id)>{{ $store->name }}</option>
@endforeach
</select>
</label>
<label>{{ __('treasury.fields.code') }}<input name="code" maxlength="100" required value="{{ old('code',$editingCashbox?->code) }}"></label>
<label class="wide">{{ __('treasury.fields.name') }}<input name="name" maxlength="180" required value="{{ old('name',$editingCashbox?->name) }}"></label>
<label>{{ __('treasury.fields.operator_visibility') }}
<select name="operator_visibility" required>
@foreach(['store','company'] as $visibility)<option value="{{ $visibility }}" @selected(old('operator_visibility',$editingCashbox?->operator_visibility ?? 'store')===$visibility)>{{ __('treasury.visibility.'.$visibility) }}</option>@endforeach
</select>
</label>
<label>{{ __('treasury.fields.approval_visibility') }}
<select name="approval_visibility" required>
@foreach(['store','company'] as $visibility)<option value="{{ $visibility }}" @selected(old('approval_visibility',$editingCashbox?->approval_visibility ?? 'store')===$visibility)>{{ __('treasury.visibility.'.$visibility) }}</option>@endforeach
</select>
</label>
<label class="treasury-check wide"><input type="hidden" name="is_active" value="0"><input type="checkbox" name="is_active" value="1" @checked((bool)old('is_active',$editingCashbox?->is_active ?? true))> <span>{{ __('treasury.fields.is_active') }}</span></label>
</div>
<div class="treasury-actions">
<button type="submit" class="foodex-primary">{{ __('treasury.actions.save') }}</button>
<a class="foodex-action-secondary" href="{{ route('admin.treasury.master.index',['tab'=>'cashboxes']) }}">{{ __('treasury.actions.cancel') }}</a>
</div>
</form>
</section>
@endif

@if((($filters['create'] ?? null) === 'bank_account' || $editingBankAccount) && $manageableStores->isNotEmpty())
<section class="treasury-section" aria-labelledby="bank-form-title">
<form class="foodex-form" method="post" action="{{ $editingBankAccount ? route('admin.treasury.bank-accounts.update',['bankAccount'=>$editingBankAccount->id]) : route('admin.treasury.bank-accounts.store') }}">
@csrf
@if($editingBankAccount) @method('patch') @endif
<h2 id="bank-form-title">{{ $editingBankAccount ? __('treasury.edit_bank_account') : __('treasury.new_bank_account') }}</h2>
<div class="treasury-form-grid">
<label>{{ __('treasury.fields.store') }}
<select name="store_id" required>
@foreach($manageableStores as $store)
<option value="{{ $store->id }}" @selected((int)old('store_id',$editingBankAccount?->store_id) === (int)$store->id)>{{ $store->name }}</option>
@endforeach
</select>
</label>
<label>{{ __('treasury.fields.code') }}<input name="code" maxlength="100" required value="{{ old('code',$editingBankAccount?->code) }}"></label>
<label>{{ __('treasury.fields.name') }}<input name="name" maxlength="180" required value="{{ old('name',$editingBankAccount?->name) }}"></label>
<label>{{ __('treasury.fields.bank_name') }}<input name="bank_name" maxlength="180" required value="{{ old('bank_name',$editingBankAccount?->bank_name) }}"></label>
<label class="wide">{{ __('treasury.fields.account_name') }}<input name="account_name" maxlength="180" required value="{{ old('account_name',$editingBankAccount?->account_name) }}"></label>
<label class="wide">{{ __('treasury.fields.account_reference') }}
<input name="account_reference" maxlength="180" {{ $editingBankAccount ? '' : 'required' }} autocomplete="off">
<small>{{ __('treasury.fields.account_reference_help') }}</small>
</label>
<label>{{ __('treasury.fields.operator_visibility') }}
<select name="operator_visibility" required>
@foreach(['store','company'] as $visibility)<option value="{{ $visibility }}" @selected(old('operator_visibility',$editingBankAccount?->operator_visibility ?? 'store')===$visibility)>{{ __('treasury.visibility.'.$visibility) }}</option>@endforeach
</select>
</label>
<label>{{ __('treasury.fields.approval_visibility') }}
<select name="approval_visibility" required>
@foreach(['store','company'] as $visibility)<option value="{{ $visibility }}" @selected(old('approval_visibility',$editingBankAccount?->approval_visibility ?? 'store')===$visibility)>{{ __('treasury.visibility.'.$visibility) }}</option>@endforeach
</select>
</label>
<label class="treasury-check wide"><input type="hidden" name="is_active" value="0"><input type="checkbox" name="is_active" value="1" @checked((bool)old('is_active',$editingBankAccount?->is_active ?? true))> <span>{{ __('treasury.fields.is_active') }}</span></label>
</div>
<div class="treasury-actions">
<button type="submit" class="foodex-primary">{{ __('treasury.actions.save') }}</button>
<a class="foodex-action-secondary" href="{{ route('admin.treasury.master.index',['tab'=>'bank_accounts']) }}">{{ __('treasury.actions.cancel') }}</a>
</div>
</form>
</section>
@endif

<section class="foodex-ops-shell treasury-section">
<form method="get" class="foodex-ops-toolbar" aria-label="{{ __('treasury.filters.apply') }}">
<input type="hidden" name="tab" value="{{ $filters['tab'] ?? 'cashboxes' }}">
<label>{{ __('treasury.filters.search') }}<input type="search" name="q" maxlength="100" value="{{ $filters['q'] ?? '' }}" placeholder="{{ __('treasury.filters.search_placeholder') }}"></label>
<label>{{ __('treasury.filters.store') }}
<select name="store_id">
<option value="">{{ __('treasury.filters.all_stores') }}</option>
@foreach($stores as $store)<option value="{{ $store->id }}" @selected((string)($filters['store_id'] ?? '') === (string)$store->id)>{{ $store->name }}</option>@endforeach
</select>
</label>
<label>{{ __('treasury.filters.status') }}
<select name="status">
<option value="all" @selected(($filters['status'] ?? 'all')==='all')>{{ __('treasury.filters.all_statuses') }}</option>
<option value="active" @selected(($filters['status'] ?? 'all')==='active')>{{ __('treasury.status.active') }}</option>
<option value="inactive" @selected(($filters['status'] ?? 'all')==='inactive')>{{ __('treasury.status.inactive') }}</option>
</select>
</label>
<label>{{ __('treasury.filters.per_page') }}
<select name="per_page">@foreach([25,50,100] as $size)<option value="{{ $size }}" @selected((int)($filters['per_page'] ?? 25)===$size)>{{ $size }}</option>@endforeach</select>
</label>
<button class="foodex-filter-action" type="submit">{{ __('treasury.filters.apply') }}</button>
</form>

@if(($filters['tab'] ?? 'cashboxes') === 'cashboxes')
@if($cashboxes->isEmpty())
<div class="foodex-empty-state">{{ __('treasury.empty.cashboxes') }}</div>
@else
<div class="foodex-table-wrap">
<table class="foodex-table foodex-ops-grid">
<thead><tr>
<th>{{ __('treasury.fields.code') }}</th><th>{{ __('treasury.fields.name') }}</th><th>{{ __('treasury.fields.company') }}</th><th>{{ __('treasury.fields.store') }}</th><th>{{ __('treasury.fields.currency') }}</th><th>{{ __('treasury.fields.operator_visibility') }}</th><th>{{ __('treasury.fields.approval_visibility') }}</th><th>{{ __('treasury.fields.status') }}</th><th>{{ __('treasury.fields.actions') }}</th>
</tr></thead>
<tbody>
@foreach($cashboxes as $cashbox)
<tr>
<td class="treasury-code">{{ $cashbox->code }}</td>
<td>{{ $cashbox->name }}</td>
<td>{{ $cashbox->company?->name }}</td>
<td>{{ $cashbox->store?->name }}</td>
<td class="treasury-code">{{ $cashbox->currency_code }}</td>
<td>{{ __('treasury.visibility.'.$cashbox->operator_visibility) }}</td>
<td>{{ __('treasury.visibility.'.$cashbox->approval_visibility) }}</td>
<td><span class="badge {{ $cashbox->is_active ? 'active' : '' }}">{{ $cashbox->is_active ? __('treasury.status.active') : __('treasury.status.inactive') }}</span></td>
<td>
@if(in_array((int)$cashbox->store_id,$manageStoreIds,true))
<a class="foodex-action-secondary" href="{{ route('admin.treasury.master.index',['tab'=>'cashboxes','edit_cashbox'=>$cashbox->id]) }}">{{ __('treasury.actions.edit') }}</a>
@else <span class="muted">—</span> @endif
</td>
</tr>
@endforeach
</tbody>
</table>
</div>
<div class="foodex-pagination">{{ $cashboxes->onEachSide(1)->links() }}</div>
@endif
@else
@if($bankAccounts->isEmpty())
<div class="foodex-empty-state">{{ __('treasury.empty.bank_accounts') }}</div>
@else
<div class="foodex-table-wrap">
<table class="foodex-table foodex-ops-grid">
<thead><tr>
<th>{{ __('treasury.fields.code') }}</th><th>{{ __('treasury.fields.name') }}</th><th>{{ __('treasury.fields.bank_name') }}</th><th>{{ __('treasury.fields.account_name') }}</th><th>{{ __('treasury.fields.account_reference') }}</th><th>{{ __('treasury.fields.company') }}</th><th>{{ __('treasury.fields.store') }}</th><th>{{ __('treasury.fields.currency') }}</th><th>{{ __('treasury.fields.status') }}</th><th>{{ __('treasury.fields.actions') }}</th>
</tr></thead>
<tbody>
@foreach($bankAccounts as $bank)
<tr>
<td class="treasury-code">{{ $bank->code }}</td>
<td>{{ $bank->name }}</td>
<td>{{ $bank->bank_name }}</td>
<td>{{ $bank->account_name }}</td>
<td class="treasury-code">{{ $bank->account_reference_last4 ? '•••• '.$bank->account_reference_last4 : '—' }}</td>
<td>{{ $bank->company?->name }}</td>
<td>{{ $bank->store?->name }}</td>
<td class="treasury-code">{{ $bank->currency_code }}</td>
<td><span class="badge {{ $bank->is_active ? 'active' : '' }}">{{ $bank->is_active ? __('treasury.status.active') : __('treasury.status.inactive') }}</span></td>
<td>
@if(in_array((int)$bank->store_id,$manageStoreIds,true))
<a class="foodex-action-secondary" href="{{ route('admin.treasury.master.index',['tab'=>'bank_accounts','edit_bank_account'=>$bank->id]) }}">{{ __('treasury.actions.edit') }}</a>
@else <span class="muted">—</span> @endif
</td>
</tr>
@endforeach
</tbody>
</table>
</div>
<div class="foodex-pagination">{{ $bankAccounts->onEachSide(1)->links() }}</div>
@endif
@endif
</section>
</main>
</div>
</body>
</html>
