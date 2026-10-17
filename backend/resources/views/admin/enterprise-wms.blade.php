<!doctype html>
<html lang="{{ str_replace('_','-',app()->getLocale()) }}" dir="{{ app()->getLocale()==='ar' ? 'rtl' : 'ltr' }}">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>{{ __('enterprise_wms.title') }} · FOODEX</title>
@include('admin._brand-components')
<style>
body{margin:0;background:var(--foodex-background);color:var(--foodex-ink)}
.wms-page{padding:clamp(16px,2.4vw,34px);min-width:0}.wms-head{margin-block:14px 20px}.wms-head h1{margin:.25rem 0 .45rem;font-size:clamp(1.55rem,2.5vw,2.15rem)}.wms-head p{margin:0;color:var(--foodex-muted);max-width:82ch;line-height:1.55}
.wms-context,.wms-panel{background:var(--foodex-surface);border:1px solid var(--foodex-border);border-radius:var(--foodex-radius-card);box-shadow:var(--foodex-shadow-sm)}
.wms-context{padding:14px;margin-block-end:14px}.wms-context form,.wms-form{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px;align-items:end}
.wms-form{padding:14px;margin-block:12px;background:var(--foodex-background);border:1px solid var(--foodex-border);border-radius:12px}.wms-form .wide{grid-column:span 2}.wms-field{display:grid;gap:5px;font-weight:700;min-width:0}.wms-field input,.wms-field select,.wms-field textarea{width:100%;min-width:0;min-height:44px;box-sizing:border-box;border:1px solid var(--foodex-border);border-radius:9px;padding:9px 10px;background:var(--foodex-surface);color:var(--foodex-ink);font:inherit}.wms-field textarea{min-height:84px;resize:vertical}
.wms-tabs{display:flex;gap:8px;overflow:auto;padding-block:4px 12px;scrollbar-width:thin}.wms-tab{flex:0 0 auto;display:inline-flex;align-items:center;justify-content:center;min-height:42px;padding-inline:13px;border:1px solid var(--foodex-border);border-radius:10px;background:var(--foodex-surface);color:var(--foodex-ink);text-decoration:none;font-weight:800}.wms-tab.is-active{background:var(--foodex-action-green);border-color:var(--foodex-action-green);color:#fff}
.wms-panel{padding:16px}.wms-panel h2,.wms-panel h3{margin:.1rem 0 .35rem}.wms-sub{margin:0 0 12px;color:var(--foodex-muted);line-height:1.5}.wms-state{margin-block-end:12px;padding:11px 13px;border:1px solid var(--foodex-border);border-radius:9px;background:var(--foodex-surface)}.wms-state ul{margin:0}
.wms-table-wrap{overflow:auto}.wms-table{width:100%;border-collapse:collapse;min-width:720px}.wms-table th,.wms-table td{padding:11px;border-bottom:1px solid var(--foodex-border);text-align:start;vertical-align:top}.wms-table th{font-size:.82rem;color:var(--foodex-muted)}.wms-table small{color:var(--foodex-muted)}.wms-badge{display:inline-flex;align-items:center;min-height:30px;padding-inline:9px;border:1px solid var(--foodex-border);border-radius:999px;font-weight:800}
.wms-actions{display:flex;gap:6px;flex-wrap:wrap}.wms-actions form{margin:0}.wms-actions button,.wms-form button,.wms-context button{min-height:42px}.wms-checks{grid-column:1/-1;display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:8px}.wms-check{display:flex;gap:8px;align-items:flex-start;padding:9px;border:1px solid var(--foodex-border);border-radius:9px;background:var(--foodex-surface)}.wms-check input{margin-top:4px}.wms-help{grid-column:1/-1;margin:0;color:var(--foodex-muted);font-size:.9rem}
.wms-split{display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-block-start:14px}.wms-empty{padding:24px;text-align:center;color:var(--foodex-muted)}
.wms-dashboard-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}.wms-donut-row{display:grid;grid-template-columns:minmax(124px,.8fr) minmax(0,1.2fr);gap:16px;align-items:center}.wms-donut-row .foodex-viz-legend{display:grid;margin-top:0}.wms-chart-list{display:grid;gap:8px}.wms-chart-list>span{display:flex;align-items:center;justify-content:space-between;gap:10px}.wms-exception-list{display:grid;gap:8px}.wms-exception-list>a{display:grid;grid-template-columns:minmax(120px,.7fr) minmax(0,1fr) auto;align-items:center;gap:10px;min-height:44px;padding:8px 10px;border-bottom:1px solid var(--foodex-border);text-decoration:none;color:var(--foodex-ink)}.wms-exception-list>a:last-child{border-bottom:0}.foodex-viz-swatch[data-series="warning"]{background:var(--foodex-viz-warning)}.foodex-viz-swatch[data-series="danger"]{background:var(--foodex-viz-danger)}
.wms-location-forms{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}.wms-location-forms>.wms-form:last-child{grid-column:1/-1}.wms-report-cards{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px}.wms-report-card{display:grid;gap:5px;text-decoration:none;color:var(--foodex-ink)}.wms-report-card small{color:var(--foodex-muted);overflow-wrap:anywhere}.wms-plan-explain td{background:var(--foodex-background);color:var(--foodex-muted)}.wms-360-facts{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px}.wms-360-facts>span{display:grid;gap:2px;padding:8px;border-bottom:1px solid var(--foodex-border);color:var(--foodex-muted)}.wms-360-facts strong{color:var(--foodex-ink)}

@media(max-width:900px){.wms-context form,.wms-form{grid-template-columns:repeat(2,minmax(0,1fr))}.wms-split,.wms-dashboard-grid{grid-template-columns:1fr}.wms-checks{grid-template-columns:repeat(2,minmax(0,1fr))}.wms-report-cards{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media(max-width:767px){.wms-page{padding:13px}.wms-context form,.wms-form{grid-template-columns:1fr}.wms-form .wide{grid-column:auto}.wms-checks,.wms-location-forms,.wms-report-cards{grid-template-columns:1fr}.wms-location-forms>.wms-form:last-child{grid-column:auto}.wms-panel{padding:13px}.wms-table{min-width:0}.wms-table-wrap{overflow:visible}.wms-donut-row{grid-template-columns:1fr}.wms-exception-list>a{grid-template-columns:1fr}.wms-360-facts{grid-template-columns:1fr}}
</style>
</head><body>
@php
  $warehouseLabels=$warehouses->keyBy('id');
  $productLabels=$inventoryLookup->keyBy('product_id');
  $movementLabel=function($type){$key='enterprise_wms.movements.movement_types.'.$type;return __($key)===$key ? __('enterprise_wms.movements.other') : __($key);};
@endphp
<div class="foodex-admin-layout" data-foodex-mobile-web-page="enterprise-wms" data-enterprise-wms="true">
<aside class="sidebar">@include('admin._sidebar',['navGroups'=>$navGroups,'navContext'=>$navContext,'user'=>$user])</aside> {{-- localization-gate: allow Blade include expression --}}
<main class="foodex-admin-main foodex-admin-page wms-page">
<header class="wms-head foodex-page-header"><div><div class="foodex-subtitle">FOODEX · {{ __('enterprise_wms.eyebrow') }}</div><h1>{{ __('enterprise_wms.title') }}</h1><p>{{ __('enterprise_wms.description') }}</p></div></header>
@if(session('status'))<div class="wms-state foodex-state" role="status">{{ session('status') }}</div>@endif
@if($errors->any())<div class="wms-state foodex-state" role="alert"><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif

<section class="wms-context">
<form method="get" action="{{ route('admin.erp.wms.index') }}">
<input type="hidden" name="tab" value="{{ $tab }}">
<label class="wms-field">{{ __('enterprise_wms.context.store') }}<select name="store_id">@foreach($stores as $store)<option value="{{ $store->id }}" @selected($storeId===(int)$store->id)>{{ $store->name }}</option>@endforeach</select></label>
<label class="wms-field">{{ __('enterprise_wms.context.warehouse') }}<select name="warehouse_id">@foreach($warehouses as $warehouse)<option value="{{ $warehouse->id }}" @selected($warehouseId===(int)$warehouse->id)>{{ $warehouse->name }} · {{ $warehouse->code }}</option>@endforeach</select></label>
<div></div><button class="foodex-action-primary" type="submit">{{ __('enterprise_wms.context.apply') }}</button>
</form>
</section>

<nav class="wms-tabs foodex-tabs" aria-label="{{ __('enterprise_wms.title') }}">
@foreach($tabs as $tabKey)<a class="wms-tab foodex-tab {{ $tab===$tabKey ? 'is-active' : '' }}" href="{{ route('admin.erp.wms.index',array_filter(['tab'=>$tabKey,'store_id'=>$storeId,'warehouse_id'=>$warehouseId])) }}" @if($tab===$tabKey) aria-current="page" @endif>{{ __('enterprise_wms.tabs.'.$tabKey) }}</a>@endforeach
</nav>

<section class="wms-panel foodex-card">
@if($tab==='overview')
@include('admin._enterprise-wms-overview')

@elseif($tab==='movements')
<h2>{{ __('enterprise_wms.movements.title') }}</h2><p class="wms-sub">{{ __('enterprise_wms.movements.subtitle') }}</p>
<div class="wms-table-wrap"><table class="wms-table foodex-table" data-pagination-required="server-bounded"><thead><tr><th>{{ __('enterprise_wms.common.product') }}</th><th>{{ __('enterprise_wms.movements.type') }}</th><th>{{ __('enterprise_wms.common.quantity') }}</th><th>{{ __('enterprise_wms.common.reason') }}</th><th>{{ __('enterprise_wms.common.created') }}</th></tr></thead><tbody>
@forelse($movementRows as $row)<tr><td>{{ $row->product_name }}<br><small>{{ $row->sku }}</small></td><td><span class="wms-badge">{{ $movementLabel((string)$row->type) }}</span></td><td>{{ \App\Support\DisplayNumber::quantity((float)$row->quantity) }}</td><td>{{ $row->reason ?: '—' }}</td><td>{{ \Illuminate\Support\Carbon::parse($row->created_at)->format('Y-m-d H:i') }}</td></tr>@empty<tr><td colspan="5" class="wms-empty">{{ __('enterprise_wms.common.none') }}</td></tr>@endforelse
</tbody></table></div>

@elseif($tab==='transfers')
<h2>{{ __('enterprise_wms.transfers.title') }}</h2><p class="wms-sub">{{ __('enterprise_wms.transfers.subtitle') }}</p>
@if($warehouseId && $warehouses->count()>1)
<form class="wms-form" method="post" action="{{ route('admin.erp.wms.transfers.store') }}" data-wms-mutation>@csrf
<input type="hidden" name="store_id" value="{{ $storeId }}"><input type="hidden" name="source_warehouse_id" value="{{ $warehouseId }}"><input type="hidden" name="request_key" value="{{ (string)\Illuminate\Support\Str::uuid() }}">
<label class="wms-field">{{ __('enterprise_wms.transfers.source') }}<input value="{{ $warehouseLabels->get($warehouseId)?->name }}" disabled></label>
<label class="wms-field">{{ __('enterprise_wms.transfers.target') }}<select name="target_warehouse_id" required>@foreach($warehouses as $warehouse)@if((int)$warehouse->id!==$warehouseId)<option value="{{ $warehouse->id }}">{{ $warehouse->name }}</option>@endif @endforeach</select></label>
<label class="wms-field">{{ __('enterprise_wms.common.product') }}<select name="product_id" required>@foreach($inventoryLookup as $item)<option value="{{ $item->product_id }}">{{ $item->product_name }} · {{ $item->sku }}</option>@endforeach</select></label>
<label class="wms-field">{{ __('enterprise_wms.common.quantity') }}<input type="number" name="quantity" min="0.001" step="0.001" required></label>
<button class="foodex-action-primary" type="submit">{{ __('enterprise_wms.transfers.new') }}</button>
</form>
@endif
<div class="wms-table-wrap"><table class="wms-table foodex-table" data-pagination-required="server-bounded"><thead><tr><th>{{ __('enterprise_wms.transfers.source') }}</th><th>{{ __('enterprise_wms.transfers.target') }}</th><th>{{ __('enterprise_wms.common.status') }}</th><th>{{ __('enterprise_wms.common.created') }}</th><th>{{ __('enterprise_wms.common.actions') }}</th></tr></thead><tbody>
@forelse($transferRows as $row)<tr><td>{{ $warehouseLabels->get((int)$row->source_warehouse_id)?->name ?? '—' }}</td><td>{{ $warehouseLabels->get((int)$row->target_warehouse_id)?->name ?? '—' }}</td><td><span class="wms-badge">{{ __('enterprise_wms.transfers.statuses.'.$row->status) }}</span></td><td>{{ $row->created_at?->format('Y-m-d H:i') ?? $row->created_at }}</td><td><div class="wms-actions">
@if($row->status==='draft') {{-- localization-gate: allow workflow enum control-flow literal; rendered status is localized. --}}<form method="post" action="{{ route('admin.erp.wms.transfers.dispatch',$row->id) }}" data-wms-mutation>@csrf<input type="hidden" name="store_id" value="{{ $storeId }}"><button class="foodex-action-primary" type="submit">{{ __('enterprise_wms.transfers.dispatch') }}</button></form>@endif
@if($row->status==='dispatched') {{-- localization-gate: allow workflow enum control-flow literal; rendered status is localized. --}}<form method="post" action="{{ route('admin.erp.wms.transfers.receive',$row->id) }}" data-wms-mutation>@csrf<input type="hidden" name="store_id" value="{{ $storeId }}"><button class="foodex-action-primary" type="submit">{{ __('enterprise_wms.transfers.receive') }}</button></form>@endif
</div></td></tr>@empty<tr><td colspan="5" class="wms-empty">{{ __('enterprise_wms.common.none') }}</td></tr>@endforelse
</tbody></table></div>

@elseif($tab==='counts')
<h2>{{ __('enterprise_wms.counts.title') }}</h2><p class="wms-sub">{{ __('enterprise_wms.counts.subtitle') }}</p>
@if($warehouseId && $inventoryLookup->isNotEmpty())
<form class="wms-form" method="post" action="{{ route('admin.erp.wms.counts.store') }}" data-wms-mutation>@csrf
<input type="hidden" name="store_id" value="{{ $storeId }}"><input type="hidden" name="warehouse_id" value="{{ $warehouseId }}"><input type="hidden" name="request_key" value="{{ (string)\Illuminate\Support\Str::uuid() }}">
<div class="wms-checks">@foreach($inventoryLookup as $item)<label class="wms-check"><input type="checkbox" name="inventory_ids[]" value="{{ $item->inventory_id }}"><span><strong>{{ $item->product_name }}</strong><br><small>{{ $item->sku }} · {{ \App\Support\DisplayNumber::quantity((float)$item->quantity) }}</small></span></label>@endforeach</div>
<button class="foodex-action-primary" type="submit">{{ __('enterprise_wms.counts.new') }}</button>
</form>
@endif
@if($selectedCountId && $countLines->isNotEmpty())
<form class="wms-form" method="post" action="{{ route('admin.erp.wms.counts.complete',$selectedCountId) }}" data-wms-mutation>@csrf
<input type="hidden" name="store_id" value="{{ $storeId }}"><input type="hidden" name="warehouse_id" value="{{ $warehouseId }}">
@foreach($countLines as $line)<label class="wms-field"><span>{{ $line->product_name }} · {{ __('enterprise_wms.counts.expected') }} {{ \App\Support\DisplayNumber::quantity((float)$line->expected_quantity) }}</span><input type="number" name="counts[{{ $line->inventory_id }}]" min="0" step="0.001" value="{{ $line->counted_quantity ?? $line->expected_quantity }}" required></label>@endforeach
<button class="foodex-action-primary" type="submit">{{ __('enterprise_wms.counts.complete') }}</button>
</form>
@endif
<div class="wms-table-wrap"><table class="wms-table foodex-table" data-pagination-required="server-bounded"><thead><tr><th>{{ __('enterprise_wms.common.warehouse') }}</th><th>{{ __('enterprise_wms.common.status') }}</th><th>{{ __('enterprise_wms.common.created') }}</th><th>{{ __('enterprise_wms.common.actions') }}</th></tr></thead><tbody>
@forelse($countRows as $row)<tr><td>{{ $warehouseLabels->get((int)$row->warehouse_id)?->name ?? '—' }}</td><td><span class="wms-badge">{{ __('enterprise_wms.counts.statuses.'.$row->status) }}</span></td><td>{{ $row->created_at?->format('Y-m-d H:i') ?? $row->created_at }}</td><td>@if($row->status==='open') {{-- localization-gate: allow workflow enum control-flow literal; rendered status is localized. --}}<a class="foodex-action-primary" href="{{ route('admin.erp.wms.index',['tab'=>'counts','store_id'=>$storeId,'warehouse_id'=>$row->warehouse_id,'count_id'=>$row->id]) }}">{{ __('enterprise_wms.counts.manage') }}</a>@endif</td></tr>@empty<tr><td colspan="4" class="wms-empty">{{ __('enterprise_wms.common.none') }}</td></tr>@endforelse
</tbody></table></div>

@elseif($tab==='adjustments')
<h2>{{ __('enterprise_wms.adjustments.title') }}</h2><p class="wms-sub">{{ __('enterprise_wms.adjustments.subtitle') }}</p>
@if($warehouseId && $inventoryLookup->isNotEmpty())
<form class="wms-form" method="post" action="{{ route('admin.erp.wms.adjustments.store') }}" data-wms-mutation>@csrf
<input type="hidden" name="store_id" value="{{ $storeId }}"><input type="hidden" name="warehouse_id" value="{{ $warehouseId }}"><input type="hidden" name="request_key" value="{{ (string)\Illuminate\Support\Str::uuid() }}">
<label class="wms-field">{{ __('enterprise_wms.common.product') }}<select name="product_id">@foreach($inventoryLookup as $item)<option value="{{ $item->product_id }}">{{ $item->product_name }} · {{ $item->sku }}</option>@endforeach</select></label>
<label class="wms-field">{{ __('enterprise_wms.adjustments.delta') }}<input type="number" name="quantity_delta" step="0.001" required></label>
<label class="wms-field">{{ __('enterprise_wms.adjustments.classification') }}<select name="classification">@foreach(['correction','damage','loss'] as $kind)<option value="{{ $kind }}">{{ __('enterprise_wms.adjustments.classifications.'.$kind) }}</option>@endforeach</select></label>
<label class="wms-field">{{ __('enterprise_wms.common.reason') }}<input name="reason" maxlength="255" required></label>
<label class="wms-field wide">{{ __('enterprise_wms.common.notes') }}<textarea name="notes" maxlength="1000"></textarea></label>
<button class="foodex-action-primary" type="submit">{{ __('enterprise_wms.adjustments.new') }}</button>
</form>
@endif
<div class="wms-table-wrap"><table class="wms-table foodex-table" data-pagination-required="server-bounded"><thead><tr><th>{{ __('enterprise_wms.movements.type') }}</th><th>{{ __('enterprise_wms.common.quantity') }}</th><th>{{ __('enterprise_wms.common.reason') }}</th><th>{{ __('enterprise_wms.common.created') }}</th></tr></thead><tbody>
@forelse($adjustmentRows as $row)<tr><td>{{ $movementLabel((string)$row->type) }}</td><td>{{ \App\Support\DisplayNumber::quantity((float)$row->quantity) }}</td><td>{{ $row->reason }}</td><td>{{ $row->created_at?->format('Y-m-d H:i') ?? $row->created_at }}</td></tr>@empty<tr><td colspan="4" class="wms-empty">{{ __('enterprise_wms.common.none') }}</td></tr>@endforelse
</tbody></table></div>

@elseif($tab==='lots')
<h2>{{ __('enterprise_wms.lots.title') }}</h2><p class="wms-sub">{{ __('enterprise_wms.lots.subtitle') }}</p>
@if($warehouseId && $inventoryLookup->isNotEmpty())
<form class="wms-form" method="post" action="{{ route('admin.erp.wms.lots.store') }}" data-wms-mutation>@csrf
<input type="hidden" name="store_id" value="{{ $storeId }}"><input type="hidden" name="warehouse_id" value="{{ $warehouseId }}">
<label class="wms-field">{{ __('enterprise_wms.common.product') }}<select name="product_id">@foreach($inventoryLookup as $item)<option value="{{ $item->product_id }}">{{ $item->product_name }} · {{ $item->sku }}</option>@endforeach</select></label>
<label class="wms-field">{{ __('enterprise_wms.lots.identifier') }}<input name="identifier" maxlength="120" required></label>
<label class="wms-field">{{ __('enterprise_wms.lots.manufactured_on') }}<input type="date" name="manufactured_on"></label>
<label class="wms-field">{{ __('enterprise_wms.lots.received_on') }}<input type="date" name="received_on"></label>
<label class="wms-field">{{ __('enterprise_wms.lots.expires_on') }}<input type="date" name="expires_on"></label>
<button class="foodex-action-primary" type="submit">{{ __('enterprise_wms.lots.new') }}</button>
</form>
@endif
<div class="wms-table-wrap"><table class="wms-table foodex-table" data-pagination-required="server-bounded"><thead><tr><th>{{ __('enterprise_wms.lots.identifier') }}</th><th>{{ __('enterprise_wms.common.product') }}</th><th>{{ __('enterprise_wms.lots.expires_on') }}</th><th>{{ __('enterprise_wms.common.status') }}</th><th>{{ __('enterprise_wms.common.actions') }}</th></tr></thead><tbody>
@forelse($lotRows as $row) @php($product=$productLabels->get((int)$row->product_id)) <tr><td>{{ $row->identifier }}</td><td>{{ $product?->product_name ?? __('enterprise_wms.common.product') }}</td><td>{{ $row->expires_on?->format('Y-m-d') ?? '—' }}</td><td>{{ __('enterprise_wms.lots.statuses.'.$row->status) }}</td><td><form class="wms-actions" method="post" action="{{ route('admin.erp.wms.lots.status',$row->id) }}" data-wms-mutation>@csrf<input type="hidden" name="store_id" value="{{ $storeId }}"><input type="hidden" name="warehouse_id" value="{{ $warehouseId }}"><select name="status">@foreach(['active','quarantined','closed'] as $status)<option value="{{ $status }}" @selected($row->status===$status)>{{ __('enterprise_wms.lots.statuses.'.$status) }}</option>@endforeach</select><button class="foodex-action-primary" type="submit">{{ __('enterprise_wms.lots.change_status') }}</button></form></td></tr>@empty<tr><td colspan="5" class="wms-empty">{{ __('enterprise_wms.common.none') }}</td></tr>@endforelse
</tbody></table></div>
<h3>{{ __('enterprise_wms.lots.expiry_30') }}</h3>
<div class="wms-table-wrap"><table class="wms-table foodex-table" data-pagination-required="server-bounded"><thead><tr><th>{{ __('enterprise_wms.lots.identifier') }}</th><th>{{ __('enterprise_wms.common.product') }}</th><th>{{ __('enterprise_wms.lots.expires_on') }}</th></tr></thead><tbody>
@forelse($expiryRows as $row) @php($expiryProduct=$productLabels->get((int)$row->product_id)) <tr><td>{{ $row->identifier }}</td><td>{{ $expiryProduct?->product_name ?? __('enterprise_wms.common.product') }}</td><td>{{ $row->expires_on?->format('Y-m-d') ?? '—' }}</td></tr>@empty<tr><td colspan="3" class="wms-empty">{{ __('enterprise_wms.common.none') }}</td></tr>@endforelse
</tbody></table></div>

@elseif($tab==='reservations')
<h2>{{ __('enterprise_wms.reservations.title') }}</h2><p class="wms-sub">{{ __('enterprise_wms.reservations.subtitle') }}</p>
@if($warehouseId && $inventoryLookup->isNotEmpty())
<form class="wms-form" method="post" action="{{ route('admin.erp.wms.reservations.store') }}" data-wms-mutation>@csrf
<input type="hidden" name="store_id" value="{{ $storeId }}"><input type="hidden" name="warehouse_id" value="{{ $warehouseId }}">
<label class="wms-field">{{ __('enterprise_wms.common.product') }}<select name="product_id">@foreach($inventoryLookup as $item)<option value="{{ $item->product_id }}">{{ $item->product_name }} · {{ $item->sku }}</option>@endforeach</select></label>
<label class="wms-field">{{ __('enterprise_wms.common.quantity') }}<input type="number" name="quantity" min="0" step="0.001" required></label>
<label class="wms-field wide">{{ __('enterprise_wms.common.reference') }}<input name="business_reference" maxlength="120" required></label>
<p class="wms-help">{{ __('enterprise_wms.reservations.business_reference_help') }} {{ __('enterprise_wms.reservations.zero_help') }}</p>
<button class="foodex-action-primary" type="submit">{{ __('enterprise_wms.reservations.new') }}</button>
</form>
@endif
<div class="wms-table-wrap"><table class="wms-table foodex-table" data-pagination-required="server-bounded"><thead><tr><th>{{ __('enterprise_wms.common.product') }}</th><th>{{ __('enterprise_wms.common.quantity') }}</th><th>{{ __('enterprise_wms.common.reserved') }}</th><th>{{ __('enterprise_wms.common.available') }}</th></tr></thead><tbody>
@forelse($inventoryRows as $row)<tr><td>{{ $row->product_name }}<br><small>{{ $row->product_sku }}</small></td><td>{{ \App\Support\DisplayNumber::quantity((float)$row->quantity) }}</td><td>{{ \App\Support\DisplayNumber::quantity((float)$row->reserved_quantity) }}</td><td>{{ \App\Support\DisplayNumber::quantity((float)$row->quantity-(float)$row->reserved_quantity) }}</td></tr>@empty<tr><td colspan="4" class="wms-empty">{{ __('enterprise_wms.common.none') }}</td></tr>@endforelse
</tbody></table></div>

@elseif($tab==='reorder')
<h2>{{ __('enterprise_wms.reorder.title') }}</h2><p class="wms-sub">{{ __('enterprise_wms.reorder.subtitle') }}</p>
@if($warehouseId && $inventoryLookup->isNotEmpty())
<form class="wms-form" method="post" action="{{ route('admin.erp.wms.reorder.store') }}" data-wms-mutation>@csrf
<input type="hidden" name="store_id" value="{{ $storeId }}"><input type="hidden" name="warehouse_id" value="{{ $warehouseId }}">
<label class="wms-field">{{ __('enterprise_wms.common.product') }}<select name="product_id">@foreach($inventoryLookup as $item)<option value="{{ $item->product_id }}">{{ $item->product_name }} · {{ $item->sku }}</option>@endforeach</select></label>
<label class="wms-field">{{ __('enterprise_wms.reorder.minimum') }}<input type="number" name="minimum_quantity" min="0" step="0.001" required></label>
<label class="wms-field">{{ __('enterprise_wms.reorder.reorder_quantity') }}<input type="number" name="reorder_quantity" min="0.001" step="0.001" required></label>
<label class="wms-field">{{ __('enterprise_wms.reorder.maximum') }}<input type="number" name="maximum_quantity" min="0" step="0.001"></label>
<button class="foodex-action-primary" type="submit">{{ __('enterprise_wms.reorder.new') }}</button>
</form>
@endif
<div class="wms-split"><div><h3>{{ __('enterprise_wms.reorder.title') }}</h3><div class="wms-table-wrap"><table class="wms-table foodex-table" data-pagination-required="server-bounded"><thead><tr><th>{{ __('enterprise_wms.common.product') }}</th><th>{{ __('enterprise_wms.reorder.minimum') }}</th><th>{{ __('enterprise_wms.reorder.reorder_quantity') }}</th><th>{{ __('enterprise_wms.common.actions') }}</th></tr></thead><tbody>
@forelse($reorderRows as $row) @php($product=$productLabels->get((int)$row->product_id)) <tr><td>{{ $product?->product_name ?? __('enterprise_wms.common.product') }}</td><td>{{ \App\Support\DisplayNumber::quantity((float)$row->minimum_quantity) }}</td><td>{{ \App\Support\DisplayNumber::quantity((float)$row->reorder_quantity) }}</td><td><form method="post" action="{{ route('admin.erp.wms.reorder.disable',$row->id) }}" data-wms-mutation>@csrf<input type="hidden" name="store_id" value="{{ $storeId }}"><input type="hidden" name="warehouse_id" value="{{ $warehouseId }}"><button class="foodex-action-secondary" type="submit">{{ __('enterprise_wms.reorder.disable') }}</button></form></td></tr>@empty<tr><td colspan="4" class="wms-empty">{{ __('enterprise_wms.common.none') }}</td></tr>@endforelse
</tbody></table></div></div><div><h3>{{ __('enterprise_wms.reorder.low_stock') }}</h3><div class="wms-table-wrap"><table class="wms-table foodex-table" data-pagination-required="server-bounded"><thead><tr><th>{{ __('enterprise_wms.common.product') }}</th><th>{{ __('enterprise_wms.common.available') }}</th><th>{{ __('enterprise_wms.reorder.minimum') }}</th></tr></thead><tbody>
@forelse($lowStockRows as $row) @php($product=$productLabels->get((int)$row->product_id)) <tr><td>{{ $product?->product_name ?? __('enterprise_wms.common.product') }}</td><td>{{ \App\Support\DisplayNumber::quantity((float)$row->available_quantity) }}</td><td>{{ \App\Support\DisplayNumber::quantity((float)$row->minimum_quantity) }}</td></tr>@empty<tr><td colspan="3" class="wms-empty">{{ __('enterprise_wms.common.none') }}</td></tr>@endforelse
</tbody></table></div></div></div>

@elseif($tab==='valuation')
<h2>{{ __('enterprise_wms.valuation.title') }}</h2><p class="wms-sub">{{ __('enterprise_wms.valuation.subtitle') }}</p>
@if($warehouseId && $inventoryLookup->isNotEmpty())
<form class="wms-form" method="post" action="{{ route('admin.erp.wms.valuation.store') }}" data-wms-mutation>@csrf
<input type="hidden" name="store_id" value="{{ $storeId }}"><input type="hidden" name="warehouse_id" value="{{ $warehouseId }}">
<label class="wms-field">{{ __('enterprise_wms.common.product') }}<select name="product_id">@foreach($inventoryLookup as $item)<option value="{{ $item->product_id }}">{{ $item->product_name }} · {{ $item->sku }}</option>@endforeach</select></label>
<label class="wms-field">{{ __('enterprise_wms.valuation.method') }}<input value="{{ __('enterprise_wms.valuation.standard') }}" disabled></label>
<label class="wms-field">{{ __('enterprise_wms.common.unit_cost') }}<input type="number" name="unit_cost" min="0" step="0.001" required></label>
<button class="foodex-action-primary" type="submit">{{ __('enterprise_wms.valuation.new') }}</button>
</form>
@endif
<div class="wms-table-wrap"><table class="wms-table foodex-table" data-pagination-required="server-bounded"><thead><tr><th>{{ __('enterprise_wms.common.product') }}</th><th>{{ __('enterprise_wms.common.quantity') }}</th><th>{{ __('enterprise_wms.common.available') }}</th><th>{{ __('enterprise_wms.common.unit_cost') }}</th><th>{{ __('enterprise_wms.common.value') }}</th></tr></thead><tbody>
@forelse($valuationRows as $row) @php($product=$productLabels->get((int)$row->product_id)) <tr><td>{{ $product?->product_name ?? __('enterprise_wms.common.product') }}</td><td>{{ \App\Support\DisplayNumber::quantity((float)$row->quantity) }}</td><td>{{ \App\Support\DisplayNumber::quantity((float)$row->available_quantity) }}</td><td>{{ \App\Support\DisplayNumber::money((float)$row->unit_cost, null) }}</td><td>{{ \App\Support\DisplayNumber::money((float)$row->inventory_value, null) }}</td></tr>@empty<tr><td colspan="5" class="wms-empty">{{ __('enterprise_wms.common.none') }}</td></tr>@endforelse
</tbody></table></div>

@elseif($tab==='aging')
<h2>{{ __('enterprise_wms.aging.title') }}</h2><p class="wms-sub">{{ __('enterprise_wms.aging.subtitle') }}</p>
<div class="wms-split"><div><div class="wms-table-wrap"><table class="wms-table foodex-table" data-pagination-required="server-bounded"><thead><tr><th>{{ __('enterprise_wms.common.product') }}</th><th>{{ __('enterprise_wms.common.quantity') }}</th><th>{{ __('enterprise_wms.common.last_inbound') }}</th><th>{{ __('enterprise_wms.common.value') }}</th></tr></thead><tbody>
@forelse($agingRows as $row) @php($product=$productLabels->get((int)$row->product_id)) <tr><td>{{ $product?->product_name ?? __('enterprise_wms.common.product') }}</td><td>{{ \App\Support\DisplayNumber::quantity((float)$row->quantity) }}</td><td>{{ \Illuminate\Support\Carbon::parse($row->last_inbound_at)->format('Y-m-d') }}</td><td>{{ \App\Support\DisplayNumber::money((float)$row->inventory_value, null) }}</td></tr>@empty<tr><td colspan="4" class="wms-empty">{{ __('enterprise_wms.common.none') }}</td></tr>@endforelse
</tbody></table></div></div><div><h3>{{ __('enterprise_wms.aging.slow_dead') }}</h3><div class="wms-table-wrap"><table class="wms-table foodex-table" data-pagination-required="server-bounded"><thead><tr><th>{{ __('enterprise_wms.common.product') }}</th><th>{{ __('enterprise_wms.common.quantity') }}</th><th>{{ __('enterprise_wms.common.last_inbound') }}</th></tr></thead><tbody>
@forelse($slowStockRows as $row) @php($product=$productLabels->get((int)$row->product_id)) <tr><td>{{ $product?->product_name ?? __('enterprise_wms.common.product') }}</td><td>{{ \App\Support\DisplayNumber::quantity((float)$row->quantity) }}</td><td>{{ \Illuminate\Support\Carbon::parse($row->last_inbound_at)->format('Y-m-d') }}</td></tr>@empty<tr><td colspan="3" class="wms-empty">{{ __('enterprise_wms.common.none') }}</td></tr>@endforelse
</tbody></table></div></div></div>
@elseif($tab==='locations')
@include('admin._enterprise-wms-locations')

@elseif($tab==='planning')
@include('admin._enterprise-wms-planning')

@elseif($tab==='reports')
@include('admin._enterprise-wms-reports')

@endif
</section>
@include('admin._enterprise-wms-record360')
</main></div>
<script>
document.querySelectorAll('[data-wms-mutation]').forEach((form)=>{
  form.addEventListener('submit',()=>{
    if(form.dataset.submitting==='1'){return;}
    form.dataset.submitting='1'; form.setAttribute('aria-busy','true');
    form.querySelectorAll('button').forEach((button)=>{button.disabled=true;});
  });
});
</script>
</body></html>
