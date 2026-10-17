<!doctype html>
<html lang="{{ app()->getLocale() }}" dir="{{ app()->getLocale()==='ar'?'rtl':'ltr' }}">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>{{ __('enterprise_procurement.title') }} · FOODEX</title>
@include('admin._brand-components')
<style>
.erp-procurement{display:grid;gap:16px}.erp-context{display:flex;gap:12px;align-items:end;justify-content:space-between;flex-wrap:wrap}.erp-context form{display:flex;gap:8px;align-items:end;flex-wrap:wrap}.erp-context label,.erp-filters label,.erp-form label{display:grid;gap:5px;font-weight:700;font-size:.82rem}.erp-filters,.erp-form{display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:10px;padding:14px}.erp-form .span-2{grid-column:span 2}.erp-form .span-all{grid-column:1/-1}.erp-table-wrap{overflow:auto}.erp-actions{display:flex;gap:6px;flex-wrap:wrap}.erp-actions form{display:inline}.erp-link{display:inline-flex;min-height:38px;align-items:center;padding:0 10px;border-radius:9px;border:1px solid var(--foodex-border);text-decoration:none;font-weight:800}.erp-danger{color:var(--foodex-danger-text)}.erp-detail{display:grid;gap:12px;padding:16px}.erp-detail-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:10px}.erp-detail-grid>div{padding:10px;border:1px solid var(--foodex-border);border-radius:10px}.erp-lines{display:grid;gap:8px}.erp-line{display:grid;grid-template-columns:minmax(160px,2fr) repeat(3,minmax(90px,1fr));gap:8px;padding:10px;border:1px solid var(--foodex-border);border-radius:10px}.erp-line--five{grid-template-columns:minmax(160px,2fr) repeat(4,minmax(90px,1fr))}.erp-aging{display:grid;grid-template-columns:repeat(auto-fit,minmax(145px,1fr));gap:10px}.erp-aging>div{padding:14px}.erp-aging strong{display:block;font-size:1.2rem;margin-top:5px}.erp-inline-note{color:var(--foodex-muted);font-size:.82rem}.erp-create{margin:0}.erp-create summary{cursor:pointer;font-weight:900;color:var(--foodex-green-dark);padding:10px 0}.erp-create[open] summary{margin-bottom:8px}.erp-status{white-space:nowrap}.erp-table td,.erp-table th{vertical-align:top}.erp-ref{white-space:nowrap;font-weight:900}.erp-lookup-hint{margin:0;color:var(--foodex-muted)}
@media(max-width:767px){.erp-form .span-2{grid-column:1/-1}.erp-line{grid-template-columns:1fr}.erp-actions{min-width:180px}}
</style>
</head>
<body>
@php
$statusLabel=static fn($status)=>__('enterprise_procurement.statuses.'.($status?:'draft'));
$statusOptions=match($activeTab){
    'suppliers'=>['active','inactive','on_hold'],
    'requests'=>['draft','submitted','approved','rejected','cancelled'],
    'orders'=>['draft','issued','cancelled'],
    'rfqs'=>['draft','published','awarded','cancelled'],
    'contracts'=>['draft','active','terminated','expired'],
    'receipts','returns'=>['draft','posted','cancelled'],
    'invoices'=>['draft','handed_off','cancelled'],
    'matches'=>['auto_approved','pending','approved','rejected'],
    default=>[],
};
$base=['store_id'=>$selectedStoreId,'tab'=>$activeTab];
@endphp
<div class="foodex-admin-layout">
<aside class="sidebar">@include('admin._sidebar',['navGroups'=>$navGroups,'navContext'=>$navContext,'user'=>$user])</aside> {{-- localization-gate: allow Blade include expression --}}
<main class="foodex-admin-main foodex-admin-page erp-procurement" data-erp-procurement-workbench="true">
<header class="foodex-page-header">
<div><div class="foodex-subtitle">{{ __('enterprise_procurement.section') }}</div><h1>{{ __('enterprise_procurement.title') }}</h1><p>{{ __('enterprise_procurement.description') }}</p></div>
</header>

<section class="foodex-card erp-context">
<form method="get" action="{{ route('admin.erp.procurement.index') }}">
<input type="hidden" name="tab" value="{{ $activeTab }}">
<label>{{ __('enterprise_procurement.store') }}
<select name="store_id" onchange="this.form.submit()">
@foreach($storeOptions as $store)<option value="{{ $store['id'] }}" @selected($selectedStoreId===$store['id'])>{{ $store['name'] }} · {{ $store['code'] }} · {{ __('enterprise_procurement.channels.'.$store['channel']) }}</option>@endforeach
</select>
</label>
<noscript><button class="foodex-primary">{{ __('enterprise_procurement.switch_store') }}</button></noscript>
</form>
</section>

@if(session('status'))<div class="foodex-state" role="status">{{ session('status') }}</div>@endif
@if($errors->any())<div class="foodex-state" role="alert"><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif

<nav class="foodex-tabs" aria-label="{{ __('enterprise_procurement.title') }}">
@foreach($tabs as $tab)
<a href="{{ route('admin.erp.procurement.index',['tab'=>$tab['key'],'store_id'=>$selectedStoreId]) }}" class="{{ $activeTab===$tab['key']?'active':'' }}" @if($activeTab===$tab['key']) aria-current="page" @endif>{{ $tab['label'] }}</a>
@endforeach
</nav>

@if(!in_array($activeTab,['overview','statements','reports'],true))
<form method="get" class="foodex-card erp-filters">
<input type="hidden" name="store_id" value="{{ $selectedStoreId }}"><input type="hidden" name="tab" value="{{ $activeTab }}">
<label>{{ __('enterprise_procurement.search') }}<input type="search" name="q" value="{{ request('q') }}" maxlength="120"></label>
@if($statusOptions!==[])
<label>{{ __('enterprise_procurement.status') }}<select name="status"><option value="">{{ __('enterprise_procurement.all') }}</option>@foreach($statusOptions as $status)<option value="{{ $status }}" @selected(request('status')===$status)>{{ $statusLabel($status) }}</option>@endforeach</select></label>
@endif
@if($activeTab==='suppliers')
<label>{{ __('enterprise_procurement.scope') }}<select name="scope_type"><option value="">{{ __('enterprise_procurement.all') }}</option><option value="company" @selected(request('scope_type')==='company')>{{ __('enterprise_procurement.company_scope') }}</option><option value="store" @selected(request('scope_type')==='store')>{{ __('enterprise_procurement.store_scope') }}</option></select></label>
@endif
<div class="erp-actions"><button class="foodex-filter-action">{{ __('enterprise_procurement.apply') }}</button><a class="erp-link" href="{{ route('admin.erp.procurement.index',$base) }}">{{ __('enterprise_procurement.clear') }}</a></div>
</form>
@endif

@include('admin._enterprise-procurement-completion')

@if($activeTab==='suppliers')
@if($can['supplier_manage'])
<details class="foodex-card erp-create"><summary>+ {{ __('enterprise_procurement.new_supplier') }}</summary>
<form method="post" action="{{ route('admin.erp.procurement.suppliers.store') }}" class="erp-form">@csrf
<input type="hidden" name="store_id" value="{{ $selectedStoreId }}">
<label>{{ __('enterprise_procurement.scope') }}<select name="scope_type" required><option value="store">{{ __('enterprise_procurement.store_scope') }}</option><option value="company">{{ __('enterprise_procurement.company_scope') }}</option></select></label>
<label>{{ __('enterprise_procurement.code') }}<input name="code" required maxlength="64"></label>
<label>{{ __('enterprise_procurement.name_en') }}<input name="name_en" required maxlength="190"></label> {{-- localization-gate: allow form field name identifies persisted bilingual attribute; visible label is translated --}}
<label>{{ __('enterprise_procurement.name_ar') }}<input name="name_ar" maxlength="190" dir="rtl"></label> {{-- localization-gate: allow form field name identifies persisted bilingual attribute; visible label is translated --}}
<label>{{ __('enterprise_procurement.contact') }}<input name="contact_name" maxlength="190"></label>
<label>{{ __('enterprise_procurement.email') }}<input type="email" name="email" maxlength="190"></label>
<label>{{ __('enterprise_procurement.phone') }}<input name="phone" maxlength="64"></label>
<label>{{ __('enterprise_procurement.tax_number') }}<input name="tax_registration_number" maxlength="96"></label>
<label>{{ __('enterprise_procurement.commercial_number') }}<input name="commercial_registration_number" maxlength="96"></label>
<label>{{ __('enterprise_procurement.business_type') }}<input name="business_type" maxlength="96"></label>
<label>{{ __('enterprise_procurement.payment_terms') }}<input type="number" name="payment_terms_days" min="0" max="3650"></label>
<label class="span-all">{{ __('enterprise_procurement.notes') }}<textarea name="notes" maxlength="4000"></textarea></label>
<div><button class="foodex-primary">{{ __('enterprise_procurement.create') }}</button></div>
</form></details>
@endif
<section class="foodex-card erp-table-wrap"><table class="foodex-table erp-table"><thead><tr><th>{{ __('enterprise_procurement.code') }}</th><th>{{ __('enterprise_procurement.supplier') }}</th><th>{{ __('enterprise_procurement.scope') }}</th><th>{{ __('enterprise_procurement.status') }}</th><th>{{ __('enterprise_procurement.contact') }}</th><th>{{ __('enterprise_procurement.actions') }}</th></tr></thead><tbody>
@forelse($rows as $row)<tr><td class="erp-ref">{{ $row->code }}</td><td><strong>{{ app()->getLocale()==='ar' && $row->name_ar ? $row->name_ar : $row->name_en }}</strong></td><td>{{ $row->scope_type==='company'?__('enterprise_procurement.company_scope'):__('enterprise_procurement.store_scope') }}</td><td><span class="badge erp-status">{{ __('enterprise_procurement.statuses.'.($row->status ?: 'draft')) }}</span></td><td>{{ $row->contact_name ?: '—' }}<div class="foodex-subtitle">{{ $row->email ?: $row->phone }}</div></td><td><div class="erp-actions"><a class="erp-link" href="{{ route('admin.erp.procurement.index',array_merge($base,['supplier'=>$row->id])) }}">{{ __('enterprise_procurement.view') }}</a>@if($can['supplier_manage'] && $row->status!=='inactive')<form method="post" action="{{ route('admin.erp.procurement.suppliers.archive',$row) }}">@csrf<input type="hidden" name="store_id" value="{{ $selectedStoreId }}"><button class="erp-link erp-danger">{{ __('enterprise_procurement.archive') }}</button></form>@endif</div></td></tr> {{-- localization-gate: allow localized supplier status/name rendering plus non-visible control-flow comparisons --}}
@empty<tr><td colspan="6"><div class="foodex-empty-state">{{ __('enterprise_procurement.empty') }}</div></td></tr>@endforelse
</tbody></table></section>
@if($detail)
<section class="foodex-card erp-detail"><h2>{{ app()->getLocale()==='ar' && $detail['supplier']->name_ar ? $detail['supplier']->name_ar : $detail['supplier']->name_en }}</h2>
<div class="erp-detail-grid"><div><small>{{ __('enterprise_procurement.code') }}</small><strong>{{ $detail['supplier']->code }}</strong></div><div><small>{{ __('enterprise_procurement.status') }}</small><strong>{{ $statusLabel($detail['supplier']->status) }}</strong></div><div><small>{{ __('enterprise_procurement.payment_terms') }}</small><strong>{{ $detail['supplier']->payment_terms_days ?? '—' }}</strong></div><div><small>{{ __('enterprise_procurement.tax_number') }}</small><strong>{{ $detail['supplier']->tax_registration_number ?: '—' }}</strong></div></div>
<h3>{{ __('enterprise_procurement.documents') }}</h3><div class="erp-lines">@forelse($detail['documents'] as $document)<div class="erp-line"><strong>{{ $document->title }}</strong><span>{{ $document->category }}</span><span>{{ $document->reference ?: '—' }}</span><span>{{ $document->expires_at ?: '—' }}</span></div>@empty<p class="erp-lookup-hint">{{ __('enterprise_procurement.empty') }}</p>@endforelse</div>
<h3>{{ __('enterprise_procurement.activity') }}</h3><div class="erp-lines">@forelse($detail['activity'] as $event)<div class="erp-line"><strong>{{ $event->event }}</strong><span>{{ $event->created_at }}</span><span>—</span><span>—</span></div>@empty<p class="erp-lookup-hint">{{ __('enterprise_procurement.empty') }}</p>@endforelse</div>
</section>@endif
@endif

@if($activeTab==='requests')
@if($can['request_manage'])
<details class="foodex-card erp-create"><summary>+ {{ __('enterprise_procurement.new_request') }}</summary>
<form method="post" action="{{ route('admin.erp.procurement.requests.store') }}" class="erp-form">@csrf<input type="hidden" name="store_id" value="{{ $selectedStoreId }}">
<label>{{ __('enterprise_procurement.supplier') }}<select name="supplier_id"><option value="">{{ __('enterprise_procurement.all') }}</option>@foreach($suppliersForLookup as $supplier)<option value="{{ $supplier->id }}">{{ $supplier->code }} · {{ app()->getLocale()==='ar' && $supplier->name_ar ? $supplier->name_ar : $supplier->name_en }}</option>@endforeach</select></label>
<label>{{ __('enterprise_procurement.currency') }}<input name="currency_code" value="KWD" required maxlength="3"></label>
<label>{{ __('enterprise_procurement.needed_by') }}<input type="date" name="needed_by"></label>
<label class="span-2">{{ __('enterprise_procurement.reason') }}<input name="reason" maxlength="2000"></label>
<label>{{ __('enterprise_procurement.item_code') }}<input name="item_code" maxlength="96"></label>
<label class="span-2">{{ __('enterprise_procurement.description_field') }}<input name="description" required maxlength="255"></label>
<label>{{ __('enterprise_procurement.quantity') }}<input type="number" name="quantity" step="0.001" min="0.001" required></label>
<label>{{ __('enterprise_procurement.unit') }}<input name="unit" required maxlength="32"></label>
<label>{{ __('enterprise_procurement.unit_cost') }}<input type="number" name="estimated_unit_cost" step="0.001" min="0"></label>
<label class="span-all">{{ __('enterprise_procurement.notes') }}<textarea name="notes" maxlength="4000"></textarea></label>
<div><button class="foodex-primary">{{ __('enterprise_procurement.create') }}</button></div>
</form></details>
@endif
<section class="foodex-card erp-table-wrap"><table class="foodex-table erp-table"><thead><tr><th>{{ __('enterprise_procurement.reference') }}</th><th>{{ __('enterprise_procurement.supplier') }}</th><th>{{ __('enterprise_procurement.status') }}</th><th>{{ __('enterprise_procurement.currency') }}</th><th>{{ __('enterprise_procurement.needed_by') }}</th><th>{{ __('enterprise_procurement.actions') }}</th></tr></thead><tbody>
@forelse($rows as $row)<tr><td class="erp-ref">{{ $row->request_number }}</td><td>{{ $row->supplier?->code ?? '—' }}</td><td><span class="badge">{{ __('enterprise_procurement.statuses.'.($row->status ?: 'draft')) }}</span></td><td>{{ $row->currency_code }}</td><td>{{ $row->needed_by?->toDateString() ?? '—' }}</td><td><div class="erp-actions"> {{-- localization-gate: allow request status rendered through localized $statusLabel; currency is an ISO code --}}
<a class="erp-link" href="{{ route('admin.erp.procurement.index',array_merge($base,['request'=>$row->id])) }}">{{ __('enterprise_procurement.view') }}</a>
@if($can['request_manage'] && $row->status==='draft')<form method="post" action="{{ route('admin.erp.procurement.requests.submit',$row) }}">@csrf<input type="hidden" name="store_id" value="{{ $selectedStoreId }}"><button class="erp-link">{{ __('enterprise_procurement.submit') }}</button></form>@endif {{-- localization-gate: allow non-visible request status control flow; visible labels are translated --}}
@if($can['request_approve'] && $row->status==='submitted')<form method="post" action="{{ route('admin.erp.procurement.requests.decision',$row) }}">@csrf<input type="hidden" name="store_id" value="{{ $selectedStoreId }}"><input type="hidden" name="decision" value="approve"><button class="erp-link">{{ __('enterprise_procurement.approve') }}</button></form><form method="post" action="{{ route('admin.erp.procurement.requests.decision',$row) }}">@csrf<input type="hidden" name="store_id" value="{{ $selectedStoreId }}"><input type="hidden" name="decision" value="reject"><button class="erp-link erp-danger">{{ __('enterprise_procurement.reject') }}</button></form>@endif {{-- localization-gate: allow non-visible request status control flow; visible labels are translated --}}
@if($can['order_manage'] && $row->status==='approved')<form method="post" action="{{ route('admin.erp.procurement.orders.create',$row) }}">@csrf<input type="hidden" name="store_id" value="{{ $selectedStoreId }}"><button class="erp-link">{{ __('enterprise_procurement.create_po') }}</button></form>@endif {{-- localization-gate: allow non-visible request status control flow; visible labels are translated --}}
@if($can['request_manage'] && in_array($row->status,['draft','submitted'],true))<form method="post" action="{{ route('admin.erp.procurement.requests.cancel',$row) }}">@csrf<input type="hidden" name="store_id" value="{{ $selectedStoreId }}"><button class="erp-link erp-danger">{{ __('enterprise_procurement.cancel') }}</button></form>@endif {{-- localization-gate: allow non-visible request status control flow; visible labels are translated --}}
</div></td></tr>@empty<tr><td colspan="6"><div class="foodex-empty-state">{{ __('enterprise_procurement.empty') }}</div></td></tr>@endforelse
</tbody></table></section>
@if($detail)<section class="foodex-card erp-detail"><h2>{{ $detail->request_number }}</h2><div class="erp-detail-grid"><div><small>{{ __('enterprise_procurement.status') }}</small><strong>{{ $statusLabel($detail->status) }}</strong></div><div><small>{{ __('enterprise_procurement.supplier') }}</small><strong>{{ $detail->supplier?->code ?? '—' }}</strong></div><div><small>{{ __('enterprise_procurement.reason') }}</small><strong>{{ $detail->reason ?: '—' }}</strong></div></div><div class="erp-lines">@foreach($detail->lines as $line)<div class="erp-line"><strong>{{ $line->description }}</strong><span>{{ $line->quantity }} {{ $line->unit }}</span><span>{{ $line->item_code ?: '—' }}</span><span>{{ $line->estimated_unit_cost ?? '—' }} {{ $detail->currency_code }}</span></div>@endforeach</div></section>@endif
@endif

@if($activeTab==='orders')
<section class="foodex-card erp-table-wrap"><table class="foodex-table erp-table"><thead><tr><th>{{ __('enterprise_procurement.reference') }}</th><th>{{ __('enterprise_procurement.supplier') }}</th><th>{{ __('enterprise_procurement.status') }}</th><th>{{ __('enterprise_procurement.currency') }}</th><th>{{ __('enterprise_procurement.needed_by') }}</th><th>{{ __('enterprise_procurement.actions') }}</th></tr></thead><tbody>
@forelse($rows as $row)<tr><td class="erp-ref">{{ $row->order_number }}</td><td>{{ $row->supplier_code }}</td><td><span class="badge">{{ $statusLabel($row->status) }}</span></td><td>{{ $row->currency_code }}</td><td>{{ $row->needed_by?->toDateString() ?? '—' }}</td><td><div class="erp-actions"><a class="erp-link" href="{{ route('admin.erp.procurement.index',array_merge($base,['order'=>$row->id])) }}">{{ __('enterprise_procurement.view') }}</a>@if($can['order_issue'] && $row->status==='draft')<form method="post" action="{{ route('admin.erp.procurement.orders.issue',$row) }}">@csrf<input type="hidden" name="store_id" value="{{ $selectedStoreId }}"><button class="erp-link">{{ __('enterprise_procurement.issue') }}</button></form>@endif @if($row->status==='issued' && $can['receipt_manage'])<a class="erp-link" href="{{ route('admin.erp.procurement.index',['tab'=>'receipts','store_id'=>$selectedStoreId,'purchase_order_id'=>$row->id]) }}">{{ __('enterprise_procurement.receive') }}</a>@endif @if($can['order_manage'] && in_array($row->status,['draft','issued'],true))<form method="post" action="{{ route('admin.erp.procurement.orders.cancel',$row) }}">@csrf<input type="hidden" name="store_id" value="{{ $selectedStoreId }}"><button class="erp-link erp-danger">{{ __('enterprise_procurement.cancel') }}</button></form>@endif</div></td></tr> {{-- localization-gate: allow localized order status rendering plus non-visible status control flow --}}
@empty<tr><td colspan="6"><div class="foodex-empty-state">{{ __('enterprise_procurement.empty') }}</div></td></tr>@endforelse
</tbody></table></section>
@if($detail)<section class="foodex-card erp-detail"><h2>{{ $detail->order_number }}</h2><p>{{ __('enterprise_procurement.supplier') }}: <strong>{{ $detail->supplier_code }} · {{ app()->getLocale()==='ar' && $detail->supplier_name_ar ? $detail->supplier_name_ar : $detail->supplier_name_en }}</strong></p><div class="erp-lines">@foreach($detail->lines as $line)<div class="erp-line"><strong>{{ $line->description }}</strong><span>{{ $line->quantity }} {{ $line->unit }}</span><span>{{ $line->item_code ?: '—' }}</span><span>{{ $line->unit_price ?? '—' }} {{ $detail->currency_code }}</span></div>@endforeach</div></section>@endif
@endif

@if($activeTab==='receipts')
@if($sourceOrder && $can['receipt_manage'] && $sourceOrder->status==='issued')
<section class="foodex-card erp-detail"><h2>{{ __('enterprise_procurement.receipt_from_order') }} · {{ $sourceOrder->order_number }}</h2>
<form method="post" action="{{ route('admin.erp.procurement.receipts.store',$sourceOrder) }}" class="erp-form">@csrf<input type="hidden" name="store_id" value="{{ $selectedStoreId }}">
<label>{{ __('enterprise_procurement.warehouse') }}<select name="warehouse_id" required><option value="">—</option>@foreach($warehousesForLookup as $warehouse)<option value="{{ $warehouse->id }}">{{ $warehouse->name }} · {{ $warehouse->code }}</option>@endforeach</select></label>
<div class="span-all erp-lines">@foreach($sourceOrder->lines as $index=>$line)<div class="erp-line"><strong>{{ $line->description }}</strong><span>{{ $line->quantity }} {{ $line->unit }}</span><input type="hidden" name="lines[{{ $index }}][purchase_order_line_id]" value="{{ $line->id }}"><label>{{ __('enterprise_procurement.quantity') }}<input type="number" name="lines[{{ $index }}][quantity]" step="0.001" min="0.001" max="{{ $line->quantity }}" value="{{ $line->quantity }}" required></label><span>{{ $line->item_code ?: '—' }}</span></div>@endforeach</div>
<div><button class="foodex-primary">{{ __('enterprise_procurement.create') }}</button></div>
</form></section>
@endif
<section class="foodex-card erp-table-wrap"><table class="foodex-table erp-table"><thead><tr><th>{{ __('enterprise_procurement.reference') }}</th><th>{{ __('enterprise_procurement.supplier') }}</th><th>{{ __('enterprise_procurement.status') }}</th><th>{{ __('enterprise_procurement.purchase_order_short') }}</th><th>{{ __('enterprise_procurement.created') }}</th><th>{{ __('enterprise_procurement.actions') }}</th></tr></thead><tbody>
@forelse($rows as $row)<tr><td class="erp-ref">{{ $row->receipt_number }}</td><td>{{ $row->supplier_code }}</td><td><span class="badge">{{ $statusLabel($row->status) }}</span></td><td>{{ $row->source_order_number }}</td><td>{{ $row->created_at }}</td><td><div class="erp-actions"><a class="erp-link" href="{{ route('admin.erp.procurement.index',array_merge($base,['receipt'=>$row->id])) }}">{{ __('enterprise_procurement.view') }}</a>@if($can['receipt_post'] && $row->status==='draft')<form method="post" action="{{ route('admin.erp.procurement.receipts.post',$row) }}">@csrf<input type="hidden" name="store_id" value="{{ $selectedStoreId }}"><button class="erp-link">{{ __('enterprise_procurement.post') }}</button></form>@endif @if($row->status==='posted')@if($can['invoice_manage'])<a class="erp-link" href="{{ route('admin.erp.procurement.index',['tab'=>'invoices','store_id'=>$selectedStoreId,'goods_receipt_id'=>$row->id]) }}">{{ __('enterprise_procurement.create_invoice') }}</a>@endif @if($can['return_manage'])<a class="erp-link" href="{{ route('admin.erp.procurement.index',['tab'=>'returns','store_id'=>$selectedStoreId,'goods_receipt_id'=>$row->id]) }}">{{ __('enterprise_procurement.create_return') }}</a>@endif @endif @if($can['receipt_manage'] && $row->status==='draft')<form method="post" action="{{ route('admin.erp.procurement.receipts.cancel',$row) }}">@csrf<input type="hidden" name="store_id" value="{{ $selectedStoreId }}"><button class="erp-link erp-danger">{{ __('enterprise_procurement.cancel') }}</button></form>@endif</div></td></tr> {{-- localization-gate: allow localized receipt status rendering plus non-visible status control flow --}}
@empty<tr><td colspan="6"><div class="foodex-empty-state">{{ __('enterprise_procurement.empty') }}</div></td></tr>@endforelse
</tbody></table></section>
@if($detail)<section class="foodex-card erp-detail"><h2>{{ $detail->receipt_number }}</h2><div class="erp-lines">@foreach($detail->lines as $line)<div class="erp-line"><strong>{{ $line->description }}</strong><span>{{ $line->received_quantity }} {{ $line->unit }}</span><span>{{ $line->warehouse?->name ?? '—' }}</span><span>{{ $line->item_code ?: '—' }}</span></div>@endforeach</div></section>@endif
@endif

@if($activeTab==='invoices')
@if($sourceReceipt && $can['invoice_manage'] && $sourceReceipt->status==='posted')
<section class="foodex-card erp-detail"><h2>{{ __('enterprise_procurement.invoice_from_receipt') }} · {{ $sourceReceipt->receipt_number }}</h2>
<form method="post" action="{{ route('admin.erp.procurement.invoices.store',$sourceReceipt) }}" class="erp-form">@csrf<input type="hidden" name="store_id" value="{{ $selectedStoreId }}">
<label>{{ __('enterprise_procurement.invoice_number') }}<input name="supplier_invoice_number" required maxlength="96"></label><label>{{ __('enterprise_procurement.invoice_date') }}<input type="date" name="invoice_date" value="{{ now()->toDateString() }}" required></label><label>{{ __('enterprise_procurement.due_date') }}<input type="date" name="due_date"></label>
<div class="span-all erp-lines">@foreach($sourceReceipt->lines as $index=>$line)<div class="erp-line erp-line--five"><strong>{{ $line->description }}</strong><span>{{ $line->received_quantity }} {{ $line->unit }}</span><input type="hidden" name="lines[{{ $index }}][goods_receipt_line_id]" value="{{ $line->id }}"><label>{{ __('enterprise_procurement.quantity') }}<input type="number" name="lines[{{ $index }}][quantity]" step="0.001" min="0.001" max="{{ $line->received_quantity }}" value="{{ $line->received_quantity }}" required></label><label>{{ __('enterprise_procurement.unit_cost') }}<input type="number" name="lines[{{ $index }}][unit_price]" step="0.001" min="0" value="{{ $line->unit_price }}" required></label><span>{{ $line->item_code ?: '—' }}</span></div>@endforeach</div>
<div><button class="foodex-primary">{{ __('enterprise_procurement.create') }}</button></div></form></section>
@endif
<section class="foodex-card erp-table-wrap"><table class="foodex-table erp-table"><thead><tr><th>{{ __('enterprise_procurement.reference') }}</th><th>{{ __('enterprise_procurement.invoice_number') }}</th><th>{{ __('enterprise_procurement.supplier') }}</th><th>{{ __('enterprise_procurement.status') }}</th><th>{{ __('enterprise_procurement.total') }}</th><th>{{ __('enterprise_procurement.actions') }}</th></tr></thead><tbody>
@forelse($rows as $row)<tr><td class="erp-ref">{{ $row->invoice_number }}</td><td>{{ $row->supplier_invoice_number }}</td><td>{{ $row->supplier_code }}</td><td><span class="badge">{{ $statusLabel($row->status) }}</span></td><td>{{ $row->total_amount }} {{ $row->currency_code }}</td><td><div class="erp-actions"><a class="erp-link" href="{{ route('admin.erp.procurement.index',array_merge($base,['invoice'=>$row->id])) }}">{{ __('enterprise_procurement.view') }}</a>@if($can['invoice_handoff'] && $row->status==='draft')<form method="post" action="{{ route('admin.erp.procurement.invoices.handoff',$row) }}">@csrf<input type="hidden" name="store_id" value="{{ $selectedStoreId }}"><button class="erp-link">{{ __('enterprise_procurement.handoff') }}</button></form>@endif @if($can['invoice_manage'] && $row->status==='draft')<form method="post" action="{{ route('admin.erp.procurement.invoices.cancel',$row) }}">@csrf<input type="hidden" name="store_id" value="{{ $selectedStoreId }}"><button class="erp-link erp-danger">{{ __('enterprise_procurement.cancel') }}</button></form>@endif</div></td></tr> {{-- localization-gate: allow localized invoice status rendering plus non-visible status control flow --}}
@empty<tr><td colspan="6"><div class="foodex-empty-state">{{ __('enterprise_procurement.empty') }}</div></td></tr>@endforelse
</tbody></table></section>
@endif

@if($activeTab==='returns')
@if($sourceReceipt && $can['return_manage'] && $sourceReceipt->status==='posted')
<section class="foodex-card erp-detail"><h2>{{ __('enterprise_procurement.return_from_receipt') }} · {{ $sourceReceipt->receipt_number }}</h2>
<form method="post" action="{{ route('admin.erp.procurement.returns.store',$sourceReceipt) }}" class="erp-form">@csrf<input type="hidden" name="store_id" value="{{ $selectedStoreId }}">
<div class="span-all erp-lines">@foreach($sourceReceipt->lines as $index=>$line)<div class="erp-line"><strong>{{ $line->description }}</strong><span>{{ $line->received_quantity }} {{ $line->unit }}</span><input type="hidden" name="lines[{{ $index }}][goods_receipt_line_id]" value="{{ $line->id }}"><label>{{ __('enterprise_procurement.quantity') }}<input type="number" name="lines[{{ $index }}][quantity]" step="0.001" min="0.001" max="{{ $line->received_quantity }}" value="{{ $line->received_quantity }}" required></label><span>{{ $line->item_code ?: '—' }}</span></div>@endforeach</div>
<div><button class="foodex-primary">{{ __('enterprise_procurement.create') }}</button></div></form></section>
@endif
<section class="foodex-card erp-table-wrap"><table class="foodex-table erp-table"><thead><tr><th>{{ __('enterprise_procurement.reference') }}</th><th>{{ __('enterprise_procurement.supplier') }}</th><th>{{ __('enterprise_procurement.status') }}</th><th>{{ __('enterprise_procurement.goods_receipt_short') }}</th><th>{{ __('enterprise_procurement.created') }}</th><th>{{ __('enterprise_procurement.actions') }}</th></tr></thead><tbody>
@forelse($rows as $row)<tr><td class="erp-ref">{{ $row->return_number }}</td><td>{{ $row->supplier_code }}</td><td><span class="badge">{{ $statusLabel($row->status) }}</span></td><td>{{ $row->source_receipt_number }}</td><td>{{ $row->created_at }}</td><td><div class="erp-actions">@if($can['return_post'] && $row->status==='draft')<form method="post" action="{{ route('admin.erp.procurement.returns.post',$row) }}">@csrf<input type="hidden" name="store_id" value="{{ $selectedStoreId }}"><button class="erp-link">{{ __('enterprise_procurement.post') }}</button></form>@endif @if($can['return_manage'] && $row->status==='draft')<form method="post" action="{{ route('admin.erp.procurement.returns.cancel',$row) }}">@csrf<input type="hidden" name="store_id" value="{{ $selectedStoreId }}"><button class="erp-link erp-danger">{{ __('enterprise_procurement.cancel') }}</button></form>@endif</div></td></tr> {{-- localization-gate: allow localized return status rendering plus non-visible status control flow --}}
@empty<tr><td colspan="6"><div class="foodex-empty-state">{{ __('enterprise_procurement.empty') }}</div></td></tr>@endforelse
</tbody></table></section>
@endif

@if($activeTab==='statements')
<section class="foodex-card"><form method="get" class="erp-filters"><input type="hidden" name="store_id" value="{{ $selectedStoreId }}"><input type="hidden" name="tab" value="statements">
<label>{{ __('enterprise_procurement.supplier') }}<select name="supplier_id" required><option value="">—</option>@foreach($suppliersForLookup as $supplier)<option value="{{ $supplier->id }}" @selected(request('supplier_id')==$supplier->id)>{{ $supplier->code }} · {{ app()->getLocale()==='ar' && $supplier->name_ar ? $supplier->name_ar : $supplier->name_en }}</option>@endforeach</select></label>
<label>{{ __('enterprise_procurement.from') }}<input type="date" name="from" value="{{ request('from') }}"></label><label>{{ __('enterprise_procurement.to') }}<input type="date" name="to" value="{{ request('to') }}"></label><label>{{ __('enterprise_procurement.as_of') }}<input type="date" name="as_of" value="{{ request('as_of',now()->toDateString()) }}"></label><label>{{ __('enterprise_procurement.currency') }}<input name="currency_code" value="{{ request('currency_code','KWD') }}" maxlength="3"></label><div><button class="foodex-filter-action">{{ __('enterprise_procurement.apply') }}</button></div>
</form></section>
@if($aging)<section class="erp-aging">@foreach(['gross_exposure','invoice_count','not_due','due_0_30','due_31_60','due_61_90','due_over_90','no_due_date'] as $metric)<div class="foodex-card"><span>{{ __('enterprise_procurement.'.$metric) }}</span><strong>{{ in_array($metric,['gross_exposure','invoice_count'],true) ? $aging[$metric] : $aging['buckets'][$metric] }}</strong>@if($metric!=='invoice_count')<small>{{ $aging['currency_code'] }}</small>@endif</div>@endforeach</section>@endif
@if($statement)<section class="foodex-card erp-table-wrap"><table class="foodex-table erp-table"><thead><tr><th>{{ __('enterprise_procurement.reference') }}</th><th>{{ __('enterprise_procurement.invoice_number') }}</th><th>{{ __('enterprise_procurement.invoice_date') }}</th><th>{{ __('enterprise_procurement.due_date') }}</th><th>{{ __('enterprise_procurement.total') }}</th><th>{{ __('enterprise_procurement.status') }}</th></tr></thead><tbody>@forelse($statement as $row)<tr><td class="erp-ref">{{ $row->invoice_number }}</td><td>{{ $row->supplier_invoice_number }}</td><td>{{ $row->invoice_date?->toDateString() }}</td><td>{{ $row->due_date?->toDateString() ?? '—' }}</td><td>{{ $row->total_amount }} {{ $row->currency_code }}</td><td>{{ $statusLabel($row->status) }}</td></tr>@empty<tr><td colspan="6"><div class="foodex-empty-state">{{ __('enterprise_procurement.empty') }}</div></td></tr>@endforelse</tbody></table></section>@endif
@endif

@if($rows && method_exists($rows,'links'))<div>{{ $rows->links() }}</div>@endif
</main></div>
</body></html>
