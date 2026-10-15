<!doctype html>
<html lang="{{ app()->getLocale() }}" dir="{{ $isAr?'rtl':'ltr' }}">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>{{ __('order_operations.title') }} · FOODEX</title>
@include('admin._brand-components')
<style>
body{margin:0}.shell{display:grid;grid-template-columns:minmax(0,1fr) 240px;min-height:100vh}.main{padding:28px}.sidebar{padding:18px;border-inline-start:1px solid var(--foodex-border)}
.workspace-context{display:flex;align-items:center;flex-wrap:wrap;gap:8px 16px;padding:10px 14px;margin-bottom:10px;border:1px solid var(--foodex-border);border-radius:var(--foodex-radius-md);background:var(--foodex-surface,#fff);font-size:.82rem}.workspace-context span{display:inline-flex;align-items:center;gap:6px}.workspace-context strong{color:var(--foodex-muted)}.filters{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:10px;padding:14px;margin-bottom:14px}.filters label{display:grid;gap:5px;font-weight:700;font-size:.8rem}
.status-tabs{display:flex;gap:8px;overflow:auto;padding:4px 0 14px;margin-bottom:2px}.status-tab{display:inline-flex;align-items:center;gap:7px;white-space:nowrap;padding:9px 12px;border:1px solid var(--foodex-border);border-radius:999px;text-decoration:none;color:inherit;background:var(--foodex-surface,#fff);font-weight:800}.status-tab[aria-current="page"]{outline:2px solid currentColor}.status-tab-count{display:inline-flex;min-width:24px;height:24px;align-items:center;justify-content:center;border-radius:999px;background:rgba(0,0,0,.06);font-size:.78rem}
.table-wrap{overflow:visible}.ops-table{width:100%;min-width:0;table-layout:fixed;font-size:.78rem}.ops-table th,.ops-table td{padding:8px 6px;vertical-align:top;white-space:normal;overflow-wrap:anywhere;line-height:1.35}.ops-table th:nth-child(1){width:9%}.ops-table th:nth-child(2){width:8%}.ops-table th:nth-child(3){width:11%}.ops-table th:nth-child(4){width:10%}.ops-table th:nth-child(5){width:12%}.ops-table th:nth-child(6){width:15%}.ops-table th:nth-child(7){width:10%}.ops-table th:nth-child(8){width:9%}.ops-table th:nth-child(9){width:11%}.ops-table th:nth-child(10){width:5%}.row-actions{position:relative;display:inline-block}.row-actions summary{list-style:none;width:34px;height:34px;border:1px solid var(--foodex-green,#179c52);border-radius:50%;display:grid;place-items:center;background:var(--foodex-green,#179c52);color:#fff;cursor:pointer;font-size:20px;line-height:1}.row-actions summary::-webkit-details-marker{display:none}.row-actions[open] summary{box-shadow:0 0 0 3px rgba(23,156,82,.14)}.row-action-menu{position:absolute;z-index:40;inset-inline-end:0;top:40px;width:310px;background:#fff;border:1px solid var(--foodex-border);border-radius:12px;box-shadow:0 12px 32px rgba(15,23,42,.14);padding:8px}.row-action-menu>a{display:block;text-decoration:none;color:var(--foodex-ink);font-weight:800;padding:9px 10px;border-radius:8px}.row-action-menu>a:hover{background:#f6f8fa}.row-action-menu form{display:grid;gap:8px;margin:8px 0 0;padding-top:8px;border-top:1px solid var(--foodex-border)}.row-action-menu select,.row-action-menu input{min-width:0;width:100%;box-sizing:border-box}.row-action-menu button{width:100%}.dispatch-cell{display:grid;gap:4px;min-width:0}.dispatch-cell strong,.dispatch-cell span{overflow-wrap:anywhere}.dispatch-warning{padding:7px 9px;border-radius:9px;background:#fff7e6;border:1px solid #f3c36b;font-weight:800}.dispatch-meta{color:var(--foodex-muted);font-size:.78rem}.detail-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px}.order-detail-drawer{position:fixed;inset:0 0 0 auto;width:min(860px,94vw);height:100vh;max-height:none;margin:0;border:0;border-inline-start:1px solid var(--foodex-border);padding:0;background:var(--foodex-surface,#fff);box-shadow:-18px 0 50px rgba(15,23,42,.18);z-index:100}.order-detail-drawer::backdrop{background:rgba(15,23,42,.35)}.order-detail-drawer-shell{padding:20px;overflow:auto;height:100%}.order-detail-drawer-head{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:14px}.order-detail-close{text-decoration:none;font-weight:900;font-size:1.35rem;color:inherit}.timeline{display:grid;gap:8px}.timeline-item{padding:10px;border:1px solid var(--foodex-border);border-radius:10px}
@media(max-width:1500px) and (min-width:1001px){.shell{grid-template-columns:minmax(0,1fr) 210px}.main{padding:16px 14px}.sidebar{padding:14px}.filters{grid-template-columns:repeat(3,minmax(0,1fr));padding:12px;gap:8px}.ops-table{font-size:.72rem}.ops-table th,.ops-table td{padding:7px 4px}.dispatch-warning{padding:5px 6px}.dispatch-meta{font-size:.7rem}.row-actions summary{width:30px;height:30px;font-size:18px}.row-action-menu{width:280px}}@media(max-width:1000px){.shell{grid-template-columns:1fr}.sidebar{grid-row:1}.main{grid-row:2;padding:16px}.filters{grid-template-columns:1fr 1fr}.detail-grid{grid-template-columns:1fr}.table-wrap{overflow-x:auto}.ops-table{min-width:980px;table-layout:auto}}@media(max-width:600px){.filters{grid-template-columns:1fr}}
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

@php
    $selectedStore = $selectedStoreId === null
        ? null
        : collect($availableStores ?? [])->firstWhere('id', $selectedStoreId);
    $visibleStore = $selectedStore
        ?? (count($availableStores ?? []) === 1 ? ($availableStores[0] ?? null) : null);
    $selectedChannelOption = collect($availableChannels ?? [])->firstWhere('code', $selectedChannel);
    $selectedChannelLabel = $selectedChannel === 'all'
        ? __('order_operations.filters.all_authorized')
        : ($selectedChannelOption['label'] ?? $businessLabel($selectedChannel));
    $workspaceContextQuery = ['channel' => $selectedChannel];
    if ($selectedStoreId !== null) {
        $workspaceContextQuery['store_id'] = $selectedStoreId;
    }
@endphp
<div class="workspace-context" data-order-workspace-context data-channel="{{ $selectedChannel }}" data-store-id="{{ $selectedStoreId ?? '' }}">
    <span><strong>{{ __('order_operations.filters.channel') }}</strong>{{ $selectedChannelLabel }}</span>
    <span><strong>{{ __('order_operations.filters.store') }}</strong>{{ $visibleStore['name'] ?? __('order_operations.filters.all_authorized') }}</span>
</div>

@php($channelQuery=request()->except(['channel','store_id','driver_id','dispatch_status','page','order']))
@php($channelCodes=collect($availableChannels ?? [])->pluck('code'))
@if(count($availableChannels ?? []) > 1)
<nav class="status-tabs" aria-label="{{ $isAr ? 'قنوات الطلبات' : 'Order channels' }}" data-order-channel-tabs data-order-channel-selected="{{ $selectedChannel }}">
@foreach($availableChannels as $channelOption)
<a class="status-tab" data-order-channel-tab="{{ $channelOption['code'] }}" href="{{ route('admin.operations.orders.index',array_merge($channelQuery,['channel'=>$channelOption['code']])) }}" @if($selectedChannel===$channelOption['code']) aria-current="page" @endif><span>{{ $channelOption['label'] }}</span></a>
@endforeach
<a class="status-tab" data-order-channel-tab="all" href="{{ route('admin.operations.orders.index',array_merge($channelQuery,['channel'=>'all'])) }}" @if($selectedChannel==='all') aria-current="page" @endif><span>{{ $isAr ? 'كل القنوات' : 'All channels' }}</span></a>
</nav>
@endif

@php($tabQuery=request()->except(['status','page','order','edit']))
@php($dispatchQueueQuery=request()->except(['status','dispatch_status','channel','page','order','edit']))
<nav class="status-tabs" aria-label="{{ __('order_operations.statuses') }}" data-order-status-tabs data-order-status-selected="{{ $selectedStatus ?? 'all' }}">
<a class="status-tab" data-order-status-tab="all" href="{{ route('admin.operations.orders.index',$tabQuery) }}" @if($selectedStatus===null) aria-current="page" @endif>
<span>{{ __('order_operations.all') }}</span><span class="status-tab-count">{{ $statusTotal }}</span>
</a>
@foreach($statusTabs as $tab)
<a class="status-tab" data-order-status-tab="{{ $tab['code'] }}" href="{{ route('admin.operations.orders.index',array_merge($tabQuery,['status'=>$tab['code']])) }}" @if($selectedStatus===$tab['code']) aria-current="page" @endif>
<span>{{ $tab['label'] }}</span><span class="status-tab-count">{{ $tab['count'] }}</span>
</a>
@endforeach
@if($channelCodes->contains('b2b') && $selectedChannel !== 'b2c')
<a class="status-tab" data-order-dispatch-workspace href="{{ route('admin.operations.orders.index',array_merge($dispatchQueueQuery,['channel'=>'b2b','dispatch_status'=>'awaiting_dispatch'])) }}" @if($selectedChannel==='b2b' && request('dispatch_status')==='awaiting_dispatch') aria-current="page" @endif><span>{{ __('order_operations.dispatch.awaiting_queue') }}</span></a>
@endif
</nav>

<form method="get" class="filters foodex-card" data-order-filter-context data-selected-channel="{{ $selectedChannel }}" data-selected-store="{{ $selectedStoreId ?? '' }}">
<input type="hidden" name="channel" value="{{ $selectedChannel }}">
@if(count($availableStores ?? []) > 1)
<label>{{ __('order_operations.filters.store') }}<select name="store_id"><option value="">{{ __('order_operations.filters.all_authorized') }}</option>@foreach($availableStores as $storeOption)<option value="{{ $storeOption['id'] }}" @selected($selectedStoreId===$storeOption['id'])>{{ $storeOption['name'] }}{{ $storeOption['code'] !== '' ? ' · '.$storeOption['code'] : '' }}</option>@endforeach</select></label>
@elseif($selectedStoreId !== null)
<input type="hidden" name="store_id" value="{{ $selectedStoreId }}">
@endif
<label>{{ __('order_operations.filters.from') }}<input type="date" name="from" value="{{ request('from') }}"></label>
<label>{{ __('order_operations.filters.to') }}<input type="date" name="to" value="{{ request('to') }}"></label>
<label>{{ __('order_operations.filters.order_number') }}<input name="order_number" value="{{ request('order_number') }}"></label>
<label>{{ __('order_operations.filters.status') }}<select name="status"><option value="">{{ __('order_operations.all') }}</option>@foreach($statusOptions as $status)<option value="{{ $status['code'] }}" @selected(request('status')===$status['code'])>{{ $status['label'] }}</option>@endforeach</select></label>
<label>{{ __('order_operations.filters.driver') }}<select name="driver_id"><option value="">{{ __('order_operations.all') }}</option>@foreach($drivers as $driver)<option value="{{ $driver->id }}" @selected((string)request('driver_id')===(string)$driver->id)>{{ $driver->name ?: __('order_operations.filters.unnamed_driver') }}</option>@endforeach</select></label>
<label>{{ __('order_operations.filters.dispatch_status') }}<select name="dispatch_status"><option value="">{{ __('order_operations.all') }}</option><option value="awaiting_dispatch" @selected(request('dispatch_status')==='awaiting_dispatch')>{{ __('order_operations.dispatch.awaiting_queue') }}</option><option value="assigned" @selected(request('dispatch_status')==='assigned')>{{ $businessLabel('assigned') }}</option></select></label>
<div><button class="foodex-filter-action">{{ __('order_operations.filters.apply') }}</button> <a class="btn secondary" data-order-filter-reset-context href="{{ route('admin.operations.orders.index',$workspaceContextQuery) }}">{{ __('order_operations.filters.reset') }}</a></div>
</form>

<section class="foodex-card panel table-wrap">
<table class="foodex-table ops-table"><thead><tr>
<th>{{ __('order_operations.columns.order') }}</th><th>{{ __('order_operations.columns.source') }}</th><th>{{ __('order_operations.columns.customer') }}</th><th>{{ __('order_operations.columns.status') }}</th><th>{{ __('order_operations.columns.current_executor') }}</th><th>{{ __('order_operations.columns.dispatch') }}</th><th>{{ __('order_operations.columns.payment') }}</th><th>{{ __('order_operations.columns.total') }}</th><th>{{ __('order_operations.columns.created') }}</th><th>{{ __('order_operations.columns.actions') }}</th>
</tr></thead><tbody>
@forelse($rows as $row)
<tr>
<td><a href="{{ route('admin.operations.orders.index',array_merge(request()->query(),['order'=>$row['id']])) }}"><strong>{{ $row['number'] }}</strong></a></td>
<td>{{ $businessLabel($row['source']) }}</td><td>{{ $row['customer'] }}</td> {{-- localization-gate: source resolved through localized businessLabel --}}
<td><span class="badge {{ $row['status'] }}" data-status-code="{{ $row['status'] }}">{{ $row['status_label'] }}</span></td>
<td>
@if($row['channel']==='b2b')
{{ $row['dispatch_assignee_type']==='van' && $row['dispatch_assignee'] ? $row['dispatch_assignee'] : __('order_operations.unassigned') }}
@else
{{ $row['driver'] ?? __('order_operations.unassigned') }}
@if($row['assignment_status'])<small>· {{ $businessLabel($row['assignment_status']) }}</small>@endif
@endif
</td>
<td><div class="dispatch-cell" data-order-dispatch-status="{{ $row['dispatch_status'] }}">
@if($row['dispatch_status']==='awaiting_dispatch')<div class="dispatch-warning">{{ __('order_operations.dispatch.warning') }}</div>@else<span class="badge {{ $row['dispatch_status'] }}">{{ $businessLabel($row['dispatch_status']) }}</span>@endif
@if($row['dispatch_assignee'])<strong>{{ $businessLabel($row['dispatch_assignee_type']) }} · {{ $row['dispatch_assignee'] }}</strong>@endif
@if($row['dispatch_territory'])<span class="dispatch-meta">{{ __('order_operations.dispatch.territory') }}: {{ $row['dispatch_territory'] }}</span>@endif
@if($row['dispatch_source'])<span class="dispatch-meta">{{ __('order_operations.dispatch.source') }}: {{ $businessLabel($row['dispatch_source']) }}</span>@endif
@if($row['dispatch_reason'])<span class="dispatch-meta">{{ __('order_operations.dispatch.routing_reason') }}: {{ $businessLabel($row['dispatch_reason']) }}</span>@endif
</div></td>
<td>{{ $businessLabel($row['payment_status']) }} @if($row['payment_provider'])<small>· {{ strtoupper(str_replace(['_','-'],' ',(string)$row['payment_provider'])) }}</small>@endif</td>
<td>{{ number_format($row['total'],2) }} {{ $row['currency'] }}</td>
<td>{{ optional($row['created_at'])->timezone('Asia/Kuwait')?->format('Y-m-d H:i') ?? '—' }}</td>
<td>
<details class="row-actions" data-order-row-actions>
<summary aria-label="{{ __('order_operations.order_actions') }}">⋮</summary>
<div class="row-action-menu">
<a href="{{ route('admin.operations.orders.index',array_merge(request()->except('edit'),['order'=>$row['id']])) }}">{{ __('order_operations.view_order') }}</a>
@if($row['can_edit'])
<a data-order-edit href="{{ route('admin.operations.orders.index',array_merge(request()->query(),['order'=>$row['id'],'edit'=>1])) }}">{{ $isAr ? 'تعديل الطلب' : 'Edit order' }}</a>
@endif
@if($row['invoice_id'] && $row['can_view_invoice'])
<a data-order-invoice href="{{ route('admin.invoices.show',$row['invoice_id']) }}">{{ $isAr ? 'عرض / طباعة الفاتورة' : 'View / Print invoice' }}</a>
@endif
@if($row['channel']==='b2c' && $row['driver_id'] && $row['can_manage'])
<form method="post" action="{{ route('admin.operations.orders.remind',$row['id']) }}" data-order-remind-driver>@csrf
<button type="submit">{{ $isAr ? 'تذكير السائق' : 'Remind driver' }}</button>
</form>
@endif

@if($row['channel']==='b2b' && $row['status']==='pending' && $row['can_manage'])
<div data-b2b-customer-service-approval style="display:grid;gap:8px;margin-top:8px;padding-top:8px;border-top:1px solid var(--foodex-border)">
<strong>{{ $isAr ? 'قرار خدمة العملاء' : 'Customer Service approval' }}</strong>
<form method="post" action="{{ route('admin.operations.orders.transition',$row['id']) }}">@csrf
<input type="hidden" name="status" value="confirmed">
<input type="hidden" name="note" value="customer_service_approved">
<button class="foodex-primary" type="submit">{{ $isAr ? 'اعتماد الطلب' : 'Approve order' }}</button>
</form>
<form method="post" action="{{ route('admin.operations.orders.transition',$row['id']) }}">@csrf
<input type="hidden" name="status" value="cancelled">
<input name="note" maxlength="1000" required placeholder="{{ $isAr ? 'سبب الرفض (إلزامي)' : 'Rejection reason (required)' }}">
<button class="danger btn" type="submit">{{ $isAr ? 'رفض الطلب' : 'Reject order' }}</button>
</form>
</div>
@endif

@if($row['channel']==='b2b' && $row['settlement'])
@php($settlement=$row['settlement'])
<div data-b2b-settlement-review style="display:grid;gap:5px;margin-top:8px;padding:9px;border-top:1px solid var(--foodex-border)">
<strong>{{ $isAr ? 'مراجعة التسوية المالية' : 'Financial settlement review' }}</strong>
<div>{{ $isAr ? 'رصيد العميل المتاح' : 'Customer credit balance' }}: <strong>{{ number_format((float)$settlement['customer_credit_balance'],3) }} {{ $settlement['currency'] }}</strong></div>
<div>{{ $isAr ? 'إجمالي المديونية الحالية' : 'Aggregate outstanding' }}: <strong>{{ number_format((float)$settlement['aggregate_outstanding'],3) }} {{ $settlement['currency'] }}</strong></div>
<div>{{ $isAr ? 'حد الائتمان' : 'Credit limit' }}: <strong>{{ number_format((float)$settlement['credit_limit'],3) }} {{ $settlement['currency'] }}</strong></div>
<div>{{ $isAr ? 'الحد المتاح' : 'Available credit line' }}: <strong>{{ number_format((float)$settlement['available_credit_line'],3) }} {{ $settlement['currency'] }}</strong></div>
@if($settlement['invoice_outstanding']!==null)
<div>{{ $isAr ? 'المتبقي على الفاتورة' : 'Invoice outstanding' }}: <strong>{{ number_format((float)$settlement['invoice_outstanding'],3) }} {{ $settlement['currency'] }}</strong></div>
@endif
</div>
@endif

@if($row['can_dispatch'])
@if($row['channel']==='b2c')
<form method="post" action="{{ route('admin.operations.orders.dispatch',$row['id']) }}" data-order-dispatch-driver>@csrf @method('PATCH')
<input type="hidden" name="assignee_type" value="driver">
<select name="assignee_id" required aria-label="{{ __('order_operations.dispatch.choose_driver') }}">
<option value="">{{ __('order_operations.dispatch.choose_driver') }}</option>
@foreach($drivers as $driver)
@if((int)$driver->store_id===(int)$row['store_id'] && strtolower((string)$driver->driver_type)===$row['channel'])
<option value="{{ $driver->id }}">{{ $driver->name ?: __('order_operations.filters.unnamed_driver') }}</option>
@endif
@endforeach
</select>
<input name="reason" required maxlength="500" placeholder="{{ __('order_operations.dispatch.reason_required') }}">
<button class="foodex-primary">{{ $row['dispatch_status']==='assigned' ? __('order_operations.dispatch.reassign') : __('order_operations.dispatch.assign') }} · {{ __('order_operations.dispatch.driver') }}</button>
</form>
@endif
@if($row['channel']==='b2b')
<form method="post" action="{{ route('admin.operations.orders.dispatch',$row['id']) }}" data-order-dispatch-van>@csrf @method('PATCH')
<input type="hidden" name="assignee_type" value="van">
<select name="assignee_id" required aria-label="{{ __('order_operations.dispatch.choose_van') }}">
<option value="">{{ __('order_operations.dispatch.choose_van') }}</option>
@foreach($vans as $van)<option value="{{ $van->id }}">{{ $van->code }}{{ $van->plate_number ? ' · '.$van->plate_number : '' }}</option>@endforeach
</select>
<input name="reason" required maxlength="500" placeholder="{{ __('order_operations.dispatch.reason_required') }}">
<button class="foodex-primary">{{ $row['dispatch_status']==='assigned' ? __('order_operations.dispatch.reassign') : __('order_operations.dispatch.assign') }} · {{ __('order_operations.dispatch.van') }}</button>
</form>
@endif
@if($row['dispatch_status']==='assigned')
<form method="post" action="{{ route('admin.operations.orders.dispatch.clear',$row['id']) }}" data-order-dispatch-clear>@csrf @method('DELETE')
<input name="reason" required maxlength="500" placeholder="{{ __('order_operations.dispatch.reason_required') }}">
<button type="submit">{{ __('order_operations.dispatch.unassign') }}</button>
</form>
@endif
@endif
@if($row['can_manage'] && !($row['channel']==='b2b' && $row['status']==='pending'))
@if(count($row['available_statuses']))
<form method="post" action="{{ route('admin.operations.orders.transition',$row['id']) }}">@csrf
<select name="status" required aria-label="{{ __('order_operations.next_status') }}"><option value="">{{ __('order_operations.choose_next_status') }}</option>@foreach($row['available_statuses'] as $status)<option value="{{ $status['code'] }}">{{ $status['label'] }}</option>@endforeach</select>
<button class="foodex-primary">{{ __('order_operations.update_status') }}</button>
</form>
@else
<span class="badge {{ $row['status'] }}">{{ __('order_operations.terminal') }}</span>
@endif
@endif
</div>
</details>
</td>
</tr>
@empty<tr><td colspan="10"><div class="foodex-empty-state">{{ __('order_operations.empty') }}</div></td></tr>@endforelse
</tbody></table>
</section>
{{ $orders->links() }}

@if($detail)
@php($detailCloseQuery=request()->except(['order','edit']))
<dialog class="order-detail-drawer" open aria-modal="true" aria-label="{{ __('order_operations.view_order') }}">
<div class="order-detail-drawer-shell">
<div class="order-detail-drawer-head"><div><strong>{{ __('order_operations.view_order') }}</strong><div>{{ $detail['number'] }}</div></div><a class="order-detail-close" href="{{ route('admin.operations.orders.index',$detailCloseQuery) }}" aria-label="{{ __('order_operations.detail.close') }}">×</a></div>
<section class="detail-grid">
<div class="foodex-card panel">
<h2>{{ __('order_operations.detail.context') }} · {{ $detail['number'] }}</h2>
<div class="timeline-item" data-order-authoritative-context>
<strong>{{ $businessLabel($detail['channel']) }} · {{ $detail['store'] }}</strong> {{-- localization-gate: allow channel resolved through localized businessLabel --}}
<div>{{ __('order_operations.detail.source') }}: {{ $businessLabel($detail['source']) }}</div>
<small>{{ __('order_operations.detail.authorized_channel') }}: {{ $businessLabel($detail['channel']) }}</small>
</div>
<h2 style="margin-top:16px">{{ __('order_operations.detail.delivery_address') }}</h2>
@if($detail['delivery_address'])
@php($delivery=$detail['delivery_address'])
<div class="timeline">
<div class="timeline-item">
<strong>{{ $delivery['formatted'] ?: __('order_operations.detail.saved_address') }}</strong>
@if(!empty($delivery['recipient_name']))<div>{{ __('order_operations.detail.recipient') }}: {{ $delivery['recipient_name'] }}</div>@endif
@if(!empty($delivery['delivery_phone']))<div>{{ __('order_operations.detail.phone') }}: {{ $delivery['delivery_phone'] }}</div>@endif
@if(!empty($delivery['landmark']))<div>{{ __('order_operations.detail.landmark') }}: {{ $delivery['landmark'] }}</div>@endif
@if(!empty($delivery['delivery_notes']))<div>{{ __('order_operations.detail.delivery_notes') }}: {{ $delivery['delivery_notes'] }}</div>@endif
@if(!empty($delivery['has_coordinates']))
<div style="margin-top:10px">
<small>{{ number_format((float)$delivery['latitude'],7,'.','') }}, {{ number_format((float)$delivery['longitude'],7,'.','') }}</small>
<br>
<a class="foodex-primary" target="_blank" rel="noopener" href="https://www.google.com/maps/search/?api=1&query={{ urlencode((string)$delivery['latitude'].','.(string)$delivery['longitude']) }}">{{ __('order_operations.detail.open_map') }}</a>
</div>
@else
<small>{{ __('order_operations.detail.no_coordinates') }}</small>
@endif
</div>
</div>
@else
<div class="foodex-empty-state">{{ __('order_operations.detail.no_snapshot') }}</div>
@endif
</div>
<div class="foodex-card panel" data-order-financials>
<h2>{{ $isAr ? 'الدفع والفاتورة' : 'Payment & invoice' }}</h2>
<div class="timeline">
<div class="timeline-item">
<strong>{{ $isAr ? 'الدفع' : 'Payment' }} · {{ $businessLabel($detail['payment_status']) }}</strong>
@if($detail['payment_provider'])<div>{{ strtoupper(str_replace(['_','-'],' ',(string)$detail['payment_provider'])) }}</div>@endif
@if($detail['payment_amount'] !== null)<small>{{ number_format((float)$detail['payment_amount'],3) }} {{ $detail['payment_currency'] ?: $detail['currency'] }}</small>@endif
</div>
@if($detail['invoice_id'] && $detail['can_view_invoice'])
<div class="timeline-item">
<strong>{{ $isAr ? 'الفاتورة' : 'Invoice' }} · {{ $detail['invoice_number'] ?: '#'.$detail['invoice_id'] }}</strong>
<div>{{ $businessLabel($detail['invoice_status']) }}</div>
@if($detail['invoice_total'] !== null)<small>{{ number_format((float)$detail['invoice_total'],3) }} {{ $detail['invoice_currency'] ?: $detail['currency'] }}</small>@endif
<div style="margin-top:8px"><a class="foodex-primary" href="{{ route('admin.invoices.show',$detail['invoice_id']) }}">{{ $isAr ? 'فتح الفاتورة والتحصيل' : 'Open invoice & settlement' }}</a></div>
</div>
@endif
</div>
</div>
<div class="foodex-card panel" data-order-dispatch-detail>
<h2>{{ __('order_operations.dispatch.current_assignment') }}</h2>
@if($detail['dispatch_status']==='awaiting_dispatch')<div class="dispatch-warning">{{ __('order_operations.dispatch.warning') }}</div>@endif
<div class="timeline">
<div class="timeline-item">
<strong>{{ $businessLabel($detail['dispatch_status']) }}</strong>
@if($detail['dispatch_assignee'])<div>{{ __('order_operations.columns.assignee') }}: {{ $businessLabel($detail['dispatch_assignee_type']) }} · {{ $detail['dispatch_assignee'] }}</div>@endif
@if($detail['dispatch_territory'])<div>{{ __('order_operations.dispatch.territory') }}: {{ $detail['dispatch_territory'] }}</div>@endif
@if($detail['dispatch_source'])<div>{{ __('order_operations.dispatch.source') }}: {{ $businessLabel($detail['dispatch_source']) }}</div>@endif
@if($detail['dispatch_reason'])<div>{{ __('order_operations.dispatch.routing_reason') }}: {{ $businessLabel($detail['dispatch_reason']) }}</div>@endif
</div>
</div>
<h3>{{ __('order_operations.dispatch.history') }}</h3>
<div class="timeline">
@forelse($detail['van_assignments'] as $entry)
<div class="timeline-item"><strong>{{ __('order_operations.dispatch.van') }} · {{ $entry['van'] }}</strong><div>{{ $businessLabel($entry['status']) }} · {{ $businessLabel($entry['source']) }}</div>@if($entry['reason'])<div>{{ $businessLabel($entry['reason']) }}</div>@endif<small>{{ optional($entry['assigned_at'])->timezone('Asia/Kuwait')?->format('Y-m-d H:i') ?? '—' }} @if($entry['ended_at'])→ {{ optional($entry['ended_at'])->timezone('Asia/Kuwait')?->format('Y-m-d H:i') ?? '—' }}@endif</small></div>
@empty<div class="foodex-empty-state">{{ __('order_operations.dispatch.no_history') }}</div>@endforelse
</div>
<h3>{{ __('order_operations.dispatch.audit_title') }}</h3>
<div class="timeline" data-order-dispatch-audit>
@forelse($detail['dispatch_audit'] as $entry)
<div class="timeline-item">
<strong>{{ __('order_operations.dispatch.audit_events.'.$entry['event_key']) }}</strong>
<div>{{ $entry['actor'] !== '' ? $entry['actor'] : __('order_operations.dispatch.system_actor') }}</div>
@if($entry['status'])<div>{{ $businessLabel($entry['status']) }}</div>@endif {{-- localization-gate: allow status resolved through localized businessLabel --}}
@if($entry['assignee_type'])<div>{{ __('order_operations.columns.assignee') }}: {{ $businessLabel($entry['assignee_type']) }}</div>@endif
@if($entry['source'])<div>{{ __('order_operations.dispatch.source') }}: {{ $businessLabel($entry['source']) }}</div>@endif
@if($entry['reason'])<div>{{ __('order_operations.dispatch.routing_reason') }}: {{ $businessLabel($entry['reason']) }}</div>@endif
<small>{{ optional($entry['created_at'])->timezone('Asia/Kuwait')?->format('Y-m-d H:i') ?? '—' }}</small>
</div>
@empty<div class="foodex-empty-state">{{ __('order_operations.dispatch.audit_empty') }}</div>@endforelse
</div>
</div>
@if($detail['channel']==='b2b')
<div class="foodex-card panel" data-b2b-van-operations>
<h2>{{ __('order_operations.detail.van_execution_timeline') }}</h2>
@if($detail['van_execution'])
<div class="timeline-item" data-van-execution-state>
<strong>{{ $businessLabel($detail['van_execution']->status) }}</strong> {{-- localization-gate: allow execution status is resolved through localized businessLabel --}}
@if($detail['van_execution']->failure_reason_code)<div>{{ __('order_operations.detail.failure_reason') }}: {{ $businessLabel($detail['van_execution']->failure_reason_code) }}</div>@endif
@if($detail['van_execution']->failure_note)<div>{{ __('order_operations.detail.van_note') }}: {{ $detail['van_execution']->failure_note }}</div>@endif
<small>{{ $detail['van_execution']->last_transition_at ? \Carbon\Carbon::parse($detail['van_execution']->last_transition_at)->timezone('Asia/Kuwait')->format('Y-m-d H:i') : '—' }}</small>
</div>
@endif
<div class="timeline" style="margin-top:10px">
@forelse($detail['van_events'] as $event)
<div class="timeline-item" data-van-execution-event="{{ $event['id'] }}">
<strong>{{ $businessLabel($event['action']) }} · {{ $businessLabel($event['from_status']) }} → {{ $businessLabel($event['to_status']) }}</strong>
@if($event['actor']!=='')<div>{{ $event['actor'] }}</div>@endif
@if($event['reason_code'])<div>{{ __('order_operations.detail.failure_reason') }}: {{ $businessLabel($event['reason_code']) }}</div>@endif
@if($event['note'])<div>{{ __('order_operations.detail.van_note') }}: {{ $event['note'] }}</div>@endif
@if($event['proof_path'])<div><a class="foodex-primary" href="{{ asset('storage/'.ltrim((string)$event['proof_path'],'/')) }}" target="_blank" rel="noopener" data-van-proof-link>{{ __('order_operations.detail.van_proof') }}</a></div>@endif
<small>{{ $event['captured_at'] ? \Carbon\Carbon::parse($event['captured_at'])->timezone('Asia/Kuwait')->format('Y-m-d H:i') : '—' }}</small>
</div>
@empty<div class="foodex-empty-state">{{ __('order_operations.detail.no_van_events') }}</div>@endforelse
</div>
<h3>{{ __('order_operations.detail.collection_timeline') }}</h3>
<div class="timeline" data-van-collection-timeline>
@forelse($detail['collections'] as $collection)
<div class="timeline-item"><strong>{{ \App\Support\DisplayNumber::money($collection['amount'], $collection['currency']) }} {{ $collection['currency'] }} · {{ $businessLabel($collection['status']) }}</strong><div>{{ $businessLabel($collection['source']) }}</div><small>{{ $collection['created_at'] ? \Carbon\Carbon::parse($collection['created_at'])->timezone('Asia/Kuwait')->format('Y-m-d H:i') : '—' }}</small></div> {{-- localization-gate: allow collection status/source are resolved through localized businessLabel --}}
@empty<div class="foodex-empty-state">{{ __('order_operations.detail.no_collections') }}</div>@endforelse
</div>
</div>
@endif
@if(request('edit') && $detail['can_edit'] && $detail['edit_options'])
@php($editOptions=$detail['edit_options'])
<div class="foodex-card panel" data-order-edit-panel>
<h2>{{ $isAr ? 'تعديل الطلب' : 'Edit order' }} · {{ $detail['number'] }}</h2>
<p>{{ $isAr ? 'يتم إعادة التسعير والتحقق من المخزون عند الحفظ. التعديل متاح للطلبات المعلقة غير المقفلة فقط.' : 'Saving re-prices and re-validates stock. Editing is limited to unlocked pending orders.' }}</p>
<form method="post" action="{{ route('admin.operations.orders.update',$detail['id']) }}" data-order-edit-form>
@csrf @method('PATCH')
<div class="new-order-grid">
<label class="new-order-field"><span>{{ $isAr ? 'العميل' : 'Customer' }}</span><select name="customer_id" required>
@foreach($editOptions['customers'] as $customer)<option value="{{ $customer['id'] }}" @selected((int)$detail['customer_id']===(int)$customer['id'])>{{ $customer['name'] }}</option>@endforeach
</select></label>
@if($detail['channel']==='b2b')
<label class="new-order-field"><span>{{ $isAr ? 'مخزن الصرف' : 'Source warehouse' }}</span><select name="warehouse_id" required>
@foreach($editOptions['warehouses'] as $warehouse)<option value="{{ $warehouse['id'] }}" @selected((int)$detail['warehouse_id']===(int)$warehouse['id'])>{{ $warehouse['code'] }} · {{ $warehouse['name'] }}</option>@endforeach
</select></label>
@endif
<label class="new-order-field"><span>{{ $isAr ? 'العنوان' : 'Address' }}</span><select name="address_id">
<option value="">{{ $isAr ? 'بدون عنوان / استلام' : 'No address / pickup' }}</option>
@foreach($editOptions['addresses'] as $address)<option value="{{ $address['id'] }}" @selected((int)$detail['address_id']===(int)$address['id'])>{{ $address['label'] }}</option>@endforeach
</select></label>
<label class="new-order-field"><span>{{ $isAr ? 'طريقة الدفع' : 'Payment method' }}</span><select name="payment_method" required>
@foreach($newOrderWizard['payment_methods'] as $method)<option value="{{ $method['code'] }}" @selected((string)$detail['payment_method']===(string)$method['code'])>{{ $method['label'] }}</option>@endforeach
</select></label>
<label class="new-order-field"><span>{{ $isAr ? 'كود الكوبون' : 'Coupon code' }}</span><input name="coupon_code" maxlength="80" value="{{ $detail['coupon_code'] }}"></label>
</div>
<label class="new-order-field" style="margin-top:14px"><span>{{ $isAr ? 'ملاحظة الطلب' : 'Order note' }}</span><textarea name="customer_note" maxlength="1000" rows="3">{{ $detail['customer_note'] }}</textarea></label>
<h3>{{ $isAr ? 'الأصناف والكميات' : 'Items & quantities' }}</h3>
<div class="new-order-lines" data-edit-order-lines>
@foreach($detail['items'] as $index=>$item)
<div class="new-order-line" data-edit-order-line>
<label class="new-order-field"><span>{{ $isAr ? 'المنتج' : 'Product' }}</span><select name="items[{{ $index }}][product_id]" data-edit-product required>
@foreach($editOptions['products'] as $product)<option value="{{ $product['id'] }}" @selected((int)$item['product_id']===(int)$product['id'])>{{ $product['sku'] }} · {{ $product['name'] }}</option>@endforeach
</select></label>
<label class="new-order-field"><span>{{ $isAr ? 'الكمية' : 'Quantity' }}</span><input type="number" min="0.001" step="0.001" name="items[{{ $index }}][quantity]" value="{{ $item['quantity'] }}" required></label>
<button type="button" class="btn secondary" data-edit-remove-line>{{ $isAr ? 'حذف' : 'Remove' }}</button>
</div>
@endforeach
</div>
<template data-edit-line-template>
<div class="new-order-line" data-edit-order-line>
<label class="new-order-field"><span>{{ $isAr ? 'المنتج' : 'Product' }}</span><select data-edit-product required>
<option value="">{{ $isAr ? 'اختر المنتج' : 'Select product' }}</option>
@foreach($editOptions['products'] as $product)<option value="{{ $product['id'] }}">{{ $product['sku'] }} · {{ $product['name'] }}</option>@endforeach
</select></label>
<label class="new-order-field"><span>{{ $isAr ? 'الكمية' : 'Quantity' }}</span><input type="number" min="0.001" step="0.001" value="1" required></label>
<button type="button" class="btn secondary" data-edit-remove-line>{{ $isAr ? 'حذف' : 'Remove' }}</button>
</div>
</template>
<div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:12px">
<button type="button" class="btn secondary" data-edit-add-line>+ {{ $isAr ? 'إضافة صنف' : 'Add item' }}</button>
<button type="submit" class="foodex-primary">{{ $isAr ? 'حفظ وإعادة التسعير' : 'Save & re-price' }}</button>
<a class="btn secondary" href="{{ route('admin.operations.orders.index',array_merge(request()->except('edit'),['order'=>$detail['id']])) }}">{{ $isAr ? 'إلغاء التعديل' : 'Cancel edit' }}</a>
</div>
</form>
</div>
@endif
<div class="foodex-card panel"><h2>{{ __('order_operations.detail.status_timeline') }} · {{ $detail['number'] }}</h2><div class="timeline">@forelse($detail['history'] as $entry)<div class="timeline-item"><strong>{{ $businessLabel($entry['from']) }} → {{ $businessLabel($entry['to']) }}</strong><div>{{ $businessLabel($entry['note']) }}</div><small>{{ optional($entry['created_at'])->timezone('Asia/Kuwait')?->format('Y-m-d H:i') ?? '—' }}</small></div>@empty<div class="foodex-empty-state">{{ __('order_operations.detail.no_history') }}</div>@endforelse</div></div>
<div class="foodex-card panel"><h2>{{ __('order_operations.detail.driver_history') }}</h2><div class="timeline">@forelse($detail['assignments'] as $entry)<div class="timeline-item"><strong>{{ str_starts_with((string)$entry['driver'],'#') ? __('order_operations.detail.unnamed_driver') : $entry['driver'] }}</strong><div>{{ $businessLabel($entry['status']) }}</div><small>{{ optional($entry['assigned_at'])->timezone('Asia/Kuwait')?->format('Y-m-d H:i') ?? '—' }} @if($entry['completed_at'])→ {{ optional($entry['completed_at'])->timezone('Asia/Kuwait')?->format('Y-m-d H:i') ?? '—' }}@endif</small></div>@empty<div class="foodex-empty-state">{{ __('order_operations.detail.no_assignments') }}</div>@endforelse</div></div>
<div class="foodex-card panel" data-delivery-evidence>
<h2>{{ __('order_operations.detail.delivery_timeline') }}</h2>
<div class="timeline">
@forelse($detail['delivery_evidence'] as $assignment)
<div class="timeline-item" data-delivery-evidence-assignment="{{ $assignment['id'] }}">
<strong>{{ str_starts_with((string)$assignment['driver_name'],'#') ? __('order_operations.detail.unnamed_driver') : $assignment['driver_name'] }} · {{ $businessLabel($assignment['status']) }}</strong>
@if(empty($assignment['timeline']))
<small>{{ __('order_operations.detail.no_assignment_events') }}</small>
@else
<div class="timeline" style="margin-top:10px">
@foreach($assignment['timeline'] as $event)
<div class="timeline-item" data-delivery-evidence-event="{{ $event['id'] }}">
<strong>{{ $businessLabel($event['from_status']) }} → {{ $businessLabel($event['to_status']) }}</strong>
@if($event['reason_code'])<div>{{ __('order_operations.detail.failure_reason') }}: {{ $businessLabel($event['reason_code']) }}</div>@endif
@if($event['note'])<div>{{ __('order_operations.detail.driver_note') }}: {{ $event['note'] }}</div>@endif
@if($event['proof'])
<div><a class="foodex-primary" href="{{ $event['proof']['url'] }}" target="_blank" rel="noopener" data-delivery-proof-link>{{ __('order_operations.detail.view_proof') }}</a></div>
@endif
<small>{{ $event['captured_at'] ? \Carbon\Carbon::parse($event['captured_at'])->timezone('Asia/Kuwait')->format('Y-m-d H:i') : '—' }}</small>
</div>
@endforeach
</div>
@endif
</div>
@empty
<div class="foodex-empty-state">{{ __('order_operations.detail.no_delivery_evidence') }}</div>
@endforelse
</div>
</div>
<div class="foodex-card panel" data-van-delivery-evidence>
<h2>{{ __('order_operations.detail.van_delivery_timeline') }}</h2>
<div class="timeline">
@forelse($detail['van_delivery_evidence'] as $assignment)
<div class="timeline-item" data-van-delivery-evidence-assignment="{{ $assignment['id'] }}">
<strong>{{ $assignment['van_name'] }} · {{ $businessLabel($assignment['execution_status'] ?? $assignment['assignment_status']) }}</strong>
@if(empty($assignment['timeline']))
<small>{{ __('order_operations.detail.no_assignment_events') }}</small>
@else
<div class="timeline" style="margin-top:10px">
@foreach($assignment['timeline'] as $event)
<div class="timeline-item" data-van-delivery-evidence-event="{{ $event['id'] }}">
<strong>{{ $businessLabel($event['from_status']) }} → {{ $businessLabel($event['to_status']) }}</strong>
@if($event['reason_code'])<div>{{ __('order_operations.detail.failure_reason') }}: {{ $businessLabel($event['reason_code']) }}</div>@endif
@if($event['note'])<div>{{ __('order_operations.detail.van_note') }}: {{ $event['note'] }}</div>@endif
@if($event['proof'])
<div><a class="foodex-primary" href="{{ $event['proof']['url'] }}" target="_blank" rel="noopener" data-van-delivery-proof-link>{{ __('order_operations.detail.view_proof') }}</a></div>
@endif
<small>{{ $event['captured_at'] ? \Carbon\Carbon::parse($event['captured_at'])->timezone('Asia/Kuwait')->format('Y-m-d H:i') : '—' }}</small>
</div>
@endforeach
</div>
@endif
</div>
@empty
<div class="foodex-empty-state">{{ __('order_operations.detail.no_van_delivery_evidence') }}</div>
@endforelse
</div>
</div>
</section>
</div>
</dialog>
@endif
</main>
<aside class="sidebar">@include('admin._sidebar')</aside>
</div>
</body></html>
@if($detail)
<script>
document.addEventListener('DOMContentLoaded', () => {
    const drawer = document.querySelector('.order-detail-drawer');
    const closeLink = drawer?.querySelector('.order-detail-close');
    if (!(drawer instanceof HTMLElement) || !window.FoodexModalA11y) return;

    let modalSession = null;
    const leaveDrawer = () => {
        modalSession?.deactivate({ restoreFocus: false });
        modalSession = null;
    };
    const dismissDrawer = () => {
        leaveDrawer();
        if (closeLink instanceof HTMLAnchorElement) window.location.assign(closeLink.href);
    };

    modalSession = window.FoodexModalA11y.activate({
        root: drawer,
        dialog: drawer,
        initialFocus: closeLink instanceof HTMLElement ? closeLink : drawer,
        onEscape: dismissDrawer,
    });
    closeLink?.addEventListener('click', leaveDrawer, { once: true });
    window.addEventListener('pagehide', leaveDrawer, { once: true });
});
</script>
@endif
@if($detail && request('edit') && $detail['can_edit'])
<script>
document.addEventListener('DOMContentLoaded',()=>{
 const box=document.querySelector('[data-edit-order-lines]');
 const tpl=document.querySelector('[data-edit-line-template]');
 const add=document.querySelector('[data-edit-add-line]');
 if(!box||!tpl||!add)return;
 const reindex=()=>box.querySelectorAll('[data-edit-order-line]').forEach((row,index)=>{
   const select=row.querySelector('select[data-edit-product]');
   const qty=row.querySelector('input[type="number"]');
   if(select)select.name='items['+index+'][product_id]';
   if(qty)qty.name='items['+index+'][quantity]';
 });
 const bind=(row)=>row.querySelector('[data-edit-remove-line]')?.addEventListener('click',()=>{
   if(box.querySelectorAll('[data-edit-order-line]').length<=1)return;
   row.remove();reindex();
 });
 box.querySelectorAll('[data-edit-order-line]').forEach(bind);
 add.addEventListener('click',()=>{const fragment=tpl.content.cloneNode(true);const row=fragment.querySelector('[data-edit-order-line]');box.appendChild(fragment);bind(row);reindex();});
 reindex();
});
</script>
@endif
