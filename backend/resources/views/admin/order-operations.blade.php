<!doctype html>
<html lang="{{ app()->getLocale() }}" dir="{{ $isAr?'rtl':'ltr' }}">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>{{ $isAr?'إدارة الطلبات':'Order Management' }} · FOODEX</title>
@include('admin._brand-components')
<style>
body{margin:0}.shell{display:grid;grid-template-columns:minmax(0,1fr) 240px;min-height:100vh}.main{padding:28px}.sidebar{padding:18px;border-inline-start:1px solid var(--foodex-border)}
.filters{display:grid;grid-template-columns:repeat(7,minmax(130px,1fr));gap:10px;padding:16px;margin-bottom:16px}.filters label{display:grid;gap:5px;font-weight:700;font-size:.8rem}
.table-wrap{overflow:auto}.ops-table{min-width:1200px}.actions{display:flex;gap:6px;flex-wrap:wrap}.actions form{margin:0}.actions select{min-width:120px}.detail-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px}.timeline{display:grid;gap:8px}.timeline-item{padding:10px;border:1px solid var(--foodex-border);border-radius:10px}
@media(max-width:1000px){.shell{grid-template-columns:1fr}.sidebar{grid-row:1}.main{grid-row:2;padding:16px}.filters{grid-template-columns:1fr 1fr}.detail-grid{grid-template-columns:1fr}}@media(max-width:600px){.filters{grid-template-columns:1fr}}
</style>
</head>
<body>
<div class="shell foodex-admin-layout">
<main class="main foodex-admin-main foodex-admin-page">
<header class="foodex-page-header"><div><h1>{{ $isAr?'إدارة الطلبات':'Order Management' }}</h1><p>{{ $isAr?'متابعة وتشغيل كل الطلبات المسموح بها حسب المنصة والمتجر.':'Monitor and operate every order allowed by the current platform/store scope.' }}</p></div></header>
@if(session('status'))<div class="foodex-state" role="status">{{ session('status') }}</div>@endif
@if($errors->any())<div class="foodex-state" role="alert"><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif

<form method="get" class="filters foodex-card">
<label>{{ $isAr?'من':'From' }}<input type="date" name="from" value="{{ request('from') }}"></label>
<label>{{ $isAr?'إلى':'To' }}<input type="date" name="to" value="{{ request('to') }}"></label>
<label>{{ $isAr?'رقم الطلب':'Order no.' }}<input name="order_number" value="{{ request('order_number') }}"></label>
<label>{{ $isAr?'الحالة':'Status' }}<select name="status"><option value="">{{ $isAr?'الكل':'All' }}</option>@foreach($statuses as $status)<option value="{{ $status }}" @selected(request('status')===$status)>{{ $status }}</option>@endforeach</select></label>
<label>{{ $isAr?'القناة':'Channel' }}<select name="channel"><option value="">{{ $isAr?'الكل':'All' }}</option><option value="b2b" @selected(request('channel')==='b2b')>{{ $isAr?'الجملة':'Wholesale' }}</option><option value="b2c" @selected(request('channel')==='b2c')>{{ $isAr?'التجزئة':'Retail' }}</option></select></label>
<label>{{ $isAr?'المتجر':'Store' }}<select name="store_id"><option value="">{{ $isAr?'الكل':'All' }}</option>@foreach($stores as $store)<option value="{{ $store->id }}" @selected((string)request('store_id')===(string)$store->id)>{{ $store->name }}</option>@endforeach</select></label>
<label>{{ $isAr?'السائق':'Driver' }}<select name="driver_id"><option value="">{{ $isAr?'الكل':'All' }}</option>@foreach($drivers as $driver)<option value="{{ $driver->id }}" @selected((string)request('driver_id')===(string)$driver->id)>{{ $driver->name ?? '#'.$driver->id }}</option>@endforeach</select></label>
<div><button class="foodex-filter-action">{{ $isAr?'تطبيق':'Apply' }}</button> <a class="btn secondary" href="{{ route('admin.operations.orders.index') }}">{{ $isAr?'مسح':'Reset' }}</a></div>
</form>

<section class="foodex-card panel table-wrap">
<table class="foodex-table ops-table"><thead><tr>
<th>{{ $isAr?'الطلب':'Order' }}</th><th>{{ $isAr?'المتجر':'Store' }}</th><th>{{ $isAr?'القناة':'Channel' }}</th><th>{{ $isAr?'العميل':'Customer' }}</th><th>{{ $isAr?'الحالة':'Status' }}</th><th>{{ $isAr?'السائق الحالي':'Current driver' }}</th><th>{{ $isAr?'الدفع':'Payment' }}</th><th>{{ $isAr?'الإجمالي':'Total' }}</th><th>{{ $isAr?'التاريخ':'Created' }}</th><th>{{ $isAr?'الإجراءات':'Actions' }}</th>
</tr></thead><tbody>
@forelse($rows as $row)
<tr>
<td><a href="{{ route('admin.operations.orders.index',array_merge(request()->query(),['order'=>$row['id']])) }}"><strong>{{ $row['number'] }}</strong></a></td>
<td>{{ $row['store'] }}</td><td>{{ strtoupper($row['channel']) }}</td><td>{{ $row['customer'] }}</td>
<td><span class="badge {{ $row['status'] }}">{{ $row['status'] }}</span></td>
<td>{{ $row['driver'] ?? ($isAr?'غير معين':'Unassigned') }} @if($row['assignment_status'])<small>· {{ $row['assignment_status'] }}</small>@endif</td>
<td>{{ $row['payment_status'] ?? '-' }} @if($row['payment_provider'])<small>· {{ $row['payment_provider'] }}</small>@endif</td>
<td>{{ number_format($row['total'],2) }} {{ $row['currency'] }}</td>
<td>{{ optional($row['created_at'])->timezone('Asia/Kuwait')->format('Y-m-d H:i') }}</td>
<td><div class="actions">
<form method="post" action="{{ route('admin.operations.orders.transition',$row['id']) }}">@csrf
<select name="status" required>@foreach($statuses as $status)<option value="{{ $status }}" @selected($status===$row['status'])>{{ $status }}</option>@endforeach</select>
<button class="btn secondary">{{ $isAr?'تحديث':'Update' }}</button></form>
<form method="post" action="{{ route('admin.operations.orders.reassign',$row['id']) }}">@csrf @method('PATCH')
<select name="driver_id" required><option value="">{{ $isAr?'اختر سائق':'Choose driver' }}</option>@foreach($drivers as $driver)@if((int)$driver->store_id===$row['store_id'] && strtolower((string)$driver->driver_type)===$row['channel'])<option value="{{ $driver->id }}">{{ $driver->name ?? '#'.$driver->id }}</option>@endif @endforeach</select>
<button class="btn secondary">{{ $isAr?'تعيين':'Assign' }}</button></form>
@if($row['assignment_id'])
<form method="post" action="{{ route('admin.operations.orders.remind',$row['id']) }}">@csrf<button class="foodex-primary">{{ $isAr?'تذكير السائق':'Remind driver' }}</button></form>
<form method="post" action="{{ route('admin.operations.orders.unassign',$row['id']) }}">@csrf @method('DELETE')<button class="danger btn">{{ $isAr?'سحب':'Unassign' }}</button></form>
@endif
</div></td>
</tr>
@empty<tr><td colspan="10"><div class="foodex-empty-state">{{ $isAr?'لا توجد طلبات مطابقة.':'No matching orders.' }}</div></td></tr>@endforelse
</tbody></table>
</section>
{{ $orders->links() }}

@if($detail)
<section class="detail-grid" style="margin-top:18px">
<div class="foodex-card panel">
<h2>{{ $isAr?'عنوان التوصيل':'Delivery address' }} · {{ $detail['number'] }}</h2>
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
<div class="foodex-card panel"><h2>{{ $isAr?'سجل حالات الطلب':'Order status timeline' }} · {{ $detail['number'] }}</h2><div class="timeline">@forelse($detail['history'] as $entry)<div class="timeline-item"><strong>{{ $entry['from'] ?? '—' }} → {{ $entry['to'] }}</strong><div>{{ $entry['note'] }}</div><small>{{ optional($entry['created_at'])->timezone('Asia/Kuwait')->format('Y-m-d H:i') }}</small></div>@empty<div class="foodex-empty-state">{{ $isAr?'لا يوجد سجل.':'No history.' }}</div>@endforelse</div></div>
<div class="foodex-card panel"><h2>{{ $isAr?'سجل السائقين':'Driver assignment history' }}</h2><div class="timeline">@forelse($detail['assignments'] as $entry)<div class="timeline-item"><strong>{{ $entry['driver'] }}</strong><div>{{ $entry['status'] }}</div><small>{{ optional($entry['assigned_at'])->timezone('Asia/Kuwait')->format('Y-m-d H:i') }} @if($entry['completed_at'])→ {{ optional($entry['completed_at'])->timezone('Asia/Kuwait')->format('Y-m-d H:i') }}@endif</small></div>@empty<div class="foodex-empty-state">{{ $isAr?'لا توجد تعيينات.':'No assignments.' }}</div>@endforelse</div></div>
</section>
@endif
</main>
<aside class="sidebar">@include('admin._sidebar')</aside>
</div>
</body></html>