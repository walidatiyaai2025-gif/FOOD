<!doctype html>
<html lang="{{ app()->getLocale() }}" dir="{{ $isAr?'rtl':'ltr' }}">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>{{ __('order_operations.title') }} · FOODEX</title>
@include('admin._brand-components')
<style>
body{margin:0}.shell{display:grid;grid-template-columns:minmax(0,1fr) 240px;min-height:100vh}.main{padding:28px}.sidebar{padding:18px;border-inline-start:1px solid var(--foodex-border)}
.filters{display:grid;grid-template-columns:repeat(7,minmax(130px,1fr));gap:10px;padding:16px;margin-bottom:16px}.filters label{display:grid;gap:5px;font-weight:700;font-size:.8rem}
.status-tabs{display:flex;gap:8px;overflow:auto;padding:4px 0 14px;margin-bottom:2px}.status-tab{display:inline-flex;align-items:center;gap:7px;white-space:nowrap;padding:9px 12px;border:1px solid var(--foodex-border);border-radius:999px;text-decoration:none;color:inherit;background:var(--foodex-surface,#fff);font-weight:800}.status-tab[aria-current="page"]{outline:2px solid currentColor}.status-tab-count{display:inline-flex;min-width:24px;height:24px;align-items:center;justify-content:center;border-radius:999px;background:rgba(0,0,0,.06);font-size:.78rem}
.table-wrap{overflow:auto}.ops-table{min-width:1200px}.row-actions{position:relative;display:inline-block}.row-actions summary{list-style:none;width:34px;height:34px;border:1px solid var(--foodex-green,#179c52);border-radius:50%;display:grid;place-items:center;background:var(--foodex-green,#179c52);color:#fff;cursor:pointer;font-size:20px;line-height:1}.row-actions summary::-webkit-details-marker{display:none}.row-actions[open] summary{box-shadow:0 0 0 3px rgba(23,156,82,.14)}.row-action-menu{position:absolute;z-index:40;inset-inline-end:0;top:40px;width:220px;background:#fff;border:1px solid var(--foodex-border);border-radius:12px;box-shadow:0 12px 32px rgba(15,23,42,.14);padding:8px}.row-action-menu>a{display:block;text-decoration:none;color:var(--foodex-ink);font-weight:800;padding:9px 10px;border-radius:8px}.row-action-menu>a:hover{background:#f6f8fa}.row-action-menu form{display:grid;gap:8px;margin:6px 0 0}.row-action-menu select{min-width:0;width:100%}.row-action-menu button{width:100%}.detail-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px}.timeline{display:grid;gap:8px}.timeline-item{padding:10px;border:1px solid var(--foodex-border);border-radius:10px}
@media(max-width:1000px){.shell{grid-template-columns:1fr}.sidebar{grid-row:1}.main{grid-row:2;padding:16px}.filters{grid-template-columns:1fr 1fr}.detail-grid{grid-template-columns:1fr}}@media(max-width:600px){.filters{grid-template-columns:1fr}}
</style>
</head>
<body>
@php
$businessLabel = static function ($value): string {
    $key = strtolower(trim((string) ($value ?? '')));
    if ($key === '') {
        return '—';
    }

    $translated = __('order_operations.business_labels.'.$key);

    return $translated !== 'order_operations.business_labels.'.$key
        ? $translated
        : ucwords(str_replace(['_', '-'], ' ', $key));
};
@endphp
<div class="shell foodex-admin-layout">
<main class="main foodex-admin-main foodex-admin-page">
<header class="foodex-page-header"><div><h1>{{ __('order_operations.title') }}</h1><p>{{ __('order_operations.description') }}</p></div>@if(count($newOrderWizard['channels'] ?? []))<button type="button" class="foodex-primary" data-new-order-open style="font-size:1rem;padding:12px 18px;white-space:nowrap">+ {{ __('order_operations.new_order') }}</button>@endif</header>
@if(session('status'))<div class="foodex-state" role="status">{{ session('status') }}</div>@endif
@if($errors->any())<div class="foodex-state" role="alert"><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif

@include('admin._order-new-wizard')

@php($tabQuery=request()->except(['status','page','order']))
<nav class="status-tabs" aria-label="{{ __('order_operations.statuses') }}" data-order-status-tabs data-order-status-selected="{{ $selectedStatus ?? 'all' }}">
<a class="status-tab" data-order-status-tab="all" href="{{ route('admin.operations.orders.index',$tabQuery) }}" @if($selectedStatus===null) aria-current="page" @endif>
<span>{{ __('order_operations.all') }}</span><span class="status-tab-count">{{ $statusTotal }}</span>
</a>
@foreach($statusTabs as $tab)
<a class="status-tab" data-order-status-tab="{{ $tab['code'] }}" href="{{ route('admin.operations.orders.index',array_merge($tabQuery,['status'=>$tab['code']])) }}" @if($selectedStatus===$tab['code']) aria-current="page" @endif>
<span>{{ $tab['label'] }}</span><span class="status-tab-count">{{ $tab['count'] }}</span>
</a>
@endforeach
</nav>

<form method="get" class="filters foodex-card">
<label>{{ __('order_operations.filters.from') }}<input type="date" name="from" value="{{ request('from') }}"></label>
<label>{{ __('order_operations.filters.to') }}<input type="date" name="to" value="{{ request('to') }}"></label>
<label>{{ __('order_operations.filters.order_number') }}<input name="order_number" value="{{ request('order_number') }}"></label>
<label>{{ __('order_operations.filters.status') }}<select name="status"><option value="">{{ __('order_operations.all') }}</option>@foreach($statusOptions as $status)<option value="{{ $status['code'] }}" @selected(request('status')===$status['code'])>{{ $status['label'] }}</option>@endforeach</select></label>
<label>{{ __('order_operations.filters.channel') }}<select name="channel"><option value="">{{ __('order_operations.filters.default') }}</option><option value="all" @selected(request('channel')==='all')>{{ __('order_operations.filters.all_authorized') }}</option><option value="b2b" @selected(request('channel')==='b2b')>{{ __('order_operations.filters.wholesale') }}</option><option value="b2c" @selected(request('channel')==='b2c')>{{ __('order_operations.filters.retail') }}</option></select></label>
<label>{{ __('order_operations.filters.store') }}<select name="store_id"><option value="">{{ __('order_operations.all') }}</option>@foreach($stores as $store)<option value="{{ $store->id }}" @selected((string)request('store_id')===(string)$store->id)>{{ $store->name }}</option>@endforeach</select></label>
<label>{{ __('order_operations.filters.driver') }}<select name="driver_id"><option value="">{{ __('order_operations.all') }}</option>@foreach($drivers as $driver)<option value="{{ $driver->id }}" @selected((string)request('driver_id')===(string)$driver->id)>{{ $driver->name ?: __('order_operations.filters.unnamed_driver') }}</option>@endforeach</select></label>
<div><button class="foodex-filter-action">{{ __('order_operations.filters.apply') }}</button> <a class="btn secondary" href="{{ route('admin.operations.orders.index') }}">{{ __('order_operations.filters.reset') }}</a></div>
</form>

<section class="foodex-card panel table-wrap">
<table class="foodex-table ops-table"><thead><tr>
<th>{{ __('order_operations.columns.order') }}</th><th>{{ __('order_operations.columns.store') }}</th><th>{{ __('order_operations.columns.channel') }}</th><th>{{ __('order_operations.columns.source') }}</th><th>{{ __('order_operations.columns.customer') }}</th><th>{{ __('order_operations.columns.status') }}</th><th>{{ __('order_operations.columns.current_driver') }}</th><th>{{ __('order_operations.columns.payment') }}</th><th>{{ __('order_operations.columns.total') }}</th><th>{{ __('order_operations.columns.created') }}</th><th>{{ __('order_operations.columns.actions') }}</th>
</tr></thead><tbody>
@forelse($rows as $row)
<tr>
<td><a href="{{ route('admin.operations.orders.index',array_merge(request()->query(),['order'=>$row['id']])) }}"><strong>{{ $row['number'] }}</strong></a></td>
<td>{{ $row['store'] }}</td><td>{{ $businessLabel($row['channel']) }}</td><td>{{ $businessLabel($row['source']) }}</td><td>{{ $row['customer'] }}</td>
<td><span class="badge {{ $row['status'] }}" data-status-code="{{ $row['status'] }}">{{ $row['status_label'] }}</span></td>
<td>{{ $row['driver'] ?? __('order_operations.unassigned') }} @if($row['assignment_status'])<small>· {{ $businessLabel($row['assignment_status']) }}</small>@endif</td>
<td>{{ $businessLabel($row['payment_status']) }} @if($row['payment_provider'])<small>· {{ strtoupper(str_replace(['_','-'],' ',(string)$row['payment_provider'])) }}</small>@endif</td>
<td>{{ number_format($row['total'],2) }} {{ $row['currency'] }}</td>
<td>{{ optional($row['created_at'])->timezone('Asia/Kuwait')?->format('Y-m-d H:i') ?? '—' }}</td>
<td>
<details class="row-actions" data-order-row-actions>
<summary aria-label="{{ __('order_operations.order_actions') }}">⋮</summary>
<div class="row-action-menu">
<a href="{{ route('admin.operations.orders.index',array_merge(request()->query(),['order'=>$row['id']])) }}">{{ __('order_operations.view_order') }}</a>
@if(count($row['available_statuses']))
<form method="post" action="{{ route('admin.operations.orders.transition',$row['id']) }}">@csrf
<select name="status" required aria-label="{{ __('order_operations.next_status') }}"><option value="">{{ __('order_operations.choose_next_status') }}</option>@foreach($row['available_statuses'] as $status)<option value="{{ $status['code'] }}">{{ $status['label'] }}</option>@endforeach</select>
<button class="foodex-primary">{{ __('order_operations.update_status') }}</button>
</form>
@else
<span class="badge {{ $row['status'] }}">{{ __('order_operations.terminal') }}</span>
@endif
</div>
</details>
</td>
</tr>
@empty<tr><td colspan="11"><div class="foodex-empty-state">{{ __('order_operations.empty') }}</div></td></tr>@endforelse
</tbody></table>
</section>
{{ $orders->links() }}

@if($detail)
<section class="detail-grid" style="margin-top:18px">
<div class="foodex-card panel">
<h2>{{ $isAr?'سياق الطلب':'Order context' }} · {{ $detail['number'] }}</h2>
<div class="timeline-item" data-order-authoritative-context>
<strong>{{ $businessLabel($detail['channel']) }} · {{ $detail['store'] }}</strong>
<div>{{ $isAr?'المصدر':'Source' }}: {{ $businessLabel($detail['source']) }}</div>
<small>{{ $isAr?'القناة المعتمدة':'Authorized channel' }}: {{ $businessLabel($detail['channel']) }}</small>
</div>
<h2 style="margin-top:16px">{{ $isAr?'عنوان التوصيل':'Delivery address' }}</h2>
@if($detail['delivery_address'])
@php($delivery=$detail['delivery_address'])
<div class="timeline">
<div class="timeline-item">
<strong>{{ $delivery['formatted'] ?: ($isAr?'عنوان محفوظ للطلب':'Saved order address') }}</strong>
@if(!empty($delivery['recipient_name']))<div>{{ $isAr?'المستلم':'Recipient' }}: {{ $delivery['recipient_name'] }}</div>@endif
@if(!empty($delivery['delivery_phone']))<div>{{ $isAr?'الهاتف':'Phone' }}: {{ $delivery['delivery_phone'] }}</div>@endif
@if(!empty($delivery['landmark']))<div>{{ $isAr?'علامة مميزة':'Landmark' }}: {{ $delivery['landmark'] }}</div>@endif
@if(!empty($delivery['delivery_notes']))<div>{{ $isAr?'ملاحظات التوصيل':'Delivery notes' }}: {{ $delivery['delivery_notes'] }}</div>@endif
@if(!empty($delivery['has_coordinates']))
<div style="margin-top:10px">
<small>{{ number_format((float)$delivery['latitude'],7,'.','') }}, {{ number_format((float)$delivery['longitude'],7,'.','') }}</small>
<br>
<a class="foodex-primary" target="_blank" rel="noopener" href="https://www.google.com/maps/search/?api=1&query={{ urlencode((string)$delivery['latitude'].','.(string)$delivery['longitude']) }}">{{ $isAr?'فتح الموقع على الخريطة':'Open in map' }}</a>
</div>
@else
<small>{{ $isAr?'لا توجد إحداثيات محفوظة لهذا الطلب.':'No coordinates were saved for this order.' }}</small>
@endif
</div>
</div>
@else
<div class="foodex-empty-state">{{ $isAr?'لا توجد لقطة عنوان محفوظة لهذا الطلب القديم.':'No delivery snapshot is available for this legacy order.' }}</div>
@endif
</div>
<div class="foodex-card panel"><h2>{{ $isAr?'سجل حالات الطلب':'Order status timeline' }} · {{ $detail['number'] }}</h2><div class="timeline">@forelse($detail['history'] as $entry)<div class="timeline-item"><strong>{{ $businessLabel($entry['from']) }} → {{ $businessLabel($entry['to']) }}</strong><div>{{ $businessLabel($entry['note']) }}</div><small>{{ optional($entry['created_at'])->timezone('Asia/Kuwait')?->format('Y-m-d H:i') ?? '—' }}</small></div>@empty<div class="foodex-empty-state">{{ $isAr?'لا يوجد سجل.':'No history.' }}</div>@endforelse</div></div>
<div class="foodex-card panel"><h2>{{ $isAr?'سجل السائقين':'Driver assignment history' }}</h2><div class="timeline">@forelse($detail['assignments'] as $entry)<div class="timeline-item"><strong>{{ str_starts_with((string)$entry['driver'],'#') ? ($isAr?'سائق بدون اسم':'Unnamed driver') : $entry['driver'] }}</strong><div>{{ $businessLabel($entry['status']) }}</div><small>{{ optional($entry['assigned_at'])->timezone('Asia/Kuwait')?->format('Y-m-d H:i') ?? '—' }} @if($entry['completed_at'])→ {{ optional($entry['completed_at'])->timezone('Asia/Kuwait')?->format('Y-m-d H:i') ?? '—' }}@endif</small></div>@empty<div class="foodex-empty-state">{{ $isAr?'لا توجد تعيينات.':'No assignments.' }}</div>@endforelse</div></div>
<div class="foodex-card panel" data-delivery-evidence>
<h2>{{ $isAr?'سجل التوصيل وإثبات التسليم':'Delivery timeline & proof' }}</h2>
<div class="timeline">
@forelse($detail['delivery_evidence'] as $assignment)
<div class="timeline-item" data-delivery-evidence-assignment="{{ $assignment['id'] }}">
<strong>{{ str_starts_with((string)$assignment['driver_name'],'#') ? ($isAr?'سائق بدون اسم':'Unnamed driver') : $assignment['driver_name'] }} · {{ $businessLabel($assignment['status']) }}</strong>
@if(empty($assignment['timeline']))
<small>{{ $isAr?'لا توجد أحداث توصيل محفوظة لهذا التعيين.':'No delivery evidence events are recorded for this assignment.' }}</small>
@else
<div class="timeline" style="margin-top:10px">
@foreach($assignment['timeline'] as $event)
<div class="timeline-item" data-delivery-evidence-event="{{ $event['id'] }}">
<strong>{{ $businessLabel($event['from_status']) }} → {{ $businessLabel($event['to_status']) }}</strong>
@if($event['reason_code'])<div>{{ $isAr?'سبب التعذر':'Failure reason' }}: {{ $businessLabel($event['reason_code']) }}</div>@endif
@if($event['note'])<div>{{ $isAr?'ملاحظة السائق':'Driver note' }}: {{ $event['note'] }}</div>@endif
@if($event['proof'])
<div><a class="foodex-primary" href="{{ $event['proof']['url'] }}" target="_blank" rel="noopener" data-delivery-proof-link>{{ $isAr?'عرض إثبات التسليم':'View delivery proof' }}</a></div>
@endif
<small>{{ $event['captured_at'] ? \Carbon\Carbon::parse($event['captured_at'])->timezone('Asia/Kuwait')->format('Y-m-d H:i') : '—' }}</small>
</div>
@endforeach
</div>
@endif
</div>
@empty
<div class="foodex-empty-state">{{ $isAr?'لا يوجد سجل توصيل أو إثبات محفوظ لهذا الطلب.':'No delivery timeline or proof is recorded for this order.' }}</div>
@endforelse
</div>
</div>
</section>
@endif
</main>
<aside class="sidebar">@include('admin._sidebar')</aside>
</div>
</body></html>