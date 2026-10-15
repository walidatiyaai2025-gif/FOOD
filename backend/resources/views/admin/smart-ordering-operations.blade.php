<!doctype html>
<html lang="{{ app()->getLocale() }}" dir="{{ $isAr ? 'rtl' : 'ltr' }}">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>{{ __('smart_ordering.title') }} · FOODEX</title>
@include('admin._brand-components')
<style>
.so-toolbar{display:grid;grid-template-columns:repeat(5,minmax(0,1fr)) auto;gap:10px;align-items:end;margin-bottom:16px}
.so-toolbar label,.so-grid label{display:grid;gap:6px;font-weight:700;font-size:.82rem}
.so-actions{display:flex;gap:8px;align-items:center;flex-wrap:wrap}
.so-table-wrap{width:100%;max-width:100%;overflow:visible}
.so-order-number{white-space:nowrap}
.so-row-actions{position:relative;display:inline-block}
.so-row-actions summary{list-style:none;width:36px;height:36px;border-radius:50%;display:grid;place-items:center;background:var(--foodex-green);color:#fff;cursor:pointer;font-size:20px}
.so-row-actions summary::-webkit-details-marker{display:none}
.so-menu{position:absolute;z-index:30;inset-inline-end:0;top:42px;width:min(300px,80vw);padding:10px;background:#fff;border:1px solid var(--foodex-border);border-radius:12px;box-shadow:var(--foodex-shadow-md);display:grid;gap:8px}
.so-menu form{display:grid;gap:8px}
.so-branch-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,340px),1fr));gap:14px}
.so-branch{padding:16px;display:grid;gap:12px}
.so-branch-head{display:flex;align-items:flex-start;justify-content:space-between;gap:10px}
.so-badges{display:flex;flex-wrap:wrap;gap:6px}
.so-dialog{width:min(760px,94vw);max-height:90vh;border:0;border-radius:16px;padding:0;box-shadow:0 20px 60px rgba(15,23,42,.25)}
.so-dialog::backdrop{background:rgba(15,23,42,.42)}
.so-dialog-body{padding:20px;overflow:auto;max-height:90vh}
.so-dialog-head{display:flex;align-items:center;justify-content:space-between;gap:12px}
.so-close{min-width:44px;min-height:44px;border:0;background:transparent;font-size:24px;cursor:pointer}
.so-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}
.so-checks{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px}
.so-checks label{display:flex;gap:8px;align-items:center;min-height:44px}
.so-hours{display:grid;gap:8px;grid-column:1/-1}
.so-hour-row{display:grid;grid-template-columns:minmax(90px,.8fr) 1fr 1fr auto;gap:8px;align-items:center}
.so-zones{display:grid;gap:12px;margin-top:18px}
.so-zone-head h3,.so-zone-card h4{margin:0}
.so-zone-head p{margin:4px 0 0;color:var(--foodex-muted)}
.so-zone-card{display:grid;gap:10px;padding:14px}
.so-zone-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px;align-items:end}
.so-zone-grid label{display:grid;gap:6px;font-weight:700;font-size:.82rem}
.so-zone-check{display:flex!important;align-items:center;gap:8px;min-height:44px}
@media(max-width:1023px){.so-toolbar{grid-template-columns:repeat(3,minmax(0,1fr))}.so-toolbar .so-actions{grid-column:1/-1}}
@media(max-width:767px){.so-toolbar{grid-template-columns:1fr 1fr}.so-grid{grid-template-columns:1fr}.so-checks{grid-template-columns:1fr 1fr}.so-hour-row{grid-template-columns:1fr 1fr}.so-hour-row strong{grid-column:1/-1}.so-zone-grid{grid-template-columns:1fr 1fr}.so-table-wrap{overflow:visible}}
@media(max-width:479px){.so-toolbar{grid-template-columns:1fr}.so-checks{grid-template-columns:1fr}.so-hour-row{grid-template-columns:1fr}.so-zone-grid{grid-template-columns:1fr}}
</style>
</head>
<body>
<div class="foodex-admin-layout" data-foodex-mobile-web-page="smart-ordering">
<aside class="sidebar">@include('admin._sidebar', ['navGroups'=>$navGroups,'navContext'=>$navContext,'user'=>$user])</aside>
<main class="foodex-admin-main foodex-admin-page">
<header class="foodex-page-header">
<div><h1>{{ __('smart_ordering.title') }}</h1><p>{{ __('smart_ordering.description') }}</p></div>
@if($tab==='branches' && ($user->hasPermission('orders.manage') || $stores->contains(fn($store) => $user->hasPermission('orders.manage',(int)$store->id))))
<button type="button" class="foodex-primary" onclick="document.getElementById('so-create-branch').showModal()">+ {{ __('smart_ordering.add_branch') }}</button>
@endif
</header>
@if(session('status'))<div class="foodex-state" role="status">{{ session('status') }}</div>@endif
@if($errors->any())<div class="foodex-state" role="alert"><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif

<nav class="foodex-tabs" aria-label="{{ __('smart_ordering.title') }}">
<a class="foodex-tab" href="{{ route('admin.smart-ordering.index') }}">{{ __('smart_ordering.settings_tab') }}</a>
<a class="foodex-tab" href="{{ route('admin.smart-ordering.operations.index',['tab'=>'orders']) }}" @if($tab==='orders') aria-current="page" @endif>{{ __('smart_ordering.orders_tab') }}</a>
<a class="foodex-tab" href="{{ route('admin.smart-ordering.operations.index',['tab'=>'branches']) }}" @if($tab==='branches') aria-current="page" @endif>{{ __('smart_ordering.branches_tab') }}</a>
</nav>

@if($tab==='orders')
<form method="get" class="foodex-card so-toolbar">
<input type="hidden" name="tab" value="orders">
<label>{{ __('smart_ordering.filters.status') }}<select name="status"><option value="">{{ __('smart_ordering.filters.all') }}</option>@foreach(array_keys(__('smart_ordering.statuses')) as $status)<option value="{{ $status }}" @selected(request('status')===$status)>{{ __('smart_ordering.statuses.'.$status) }}</option>@endforeach</select></label>
<label>{{ __('smart_ordering.filters.mode') }}<select name="fulfillment_mode"><option value="">{{ __('smart_ordering.filters.all') }}</option>@foreach(['dine_in','pickup','delivery'] as $mode)<option value="{{ $mode }}" @selected(request('fulfillment_mode')===$mode)>{{ __('smart_ordering.modes.'.$mode) }}</option>@endforeach</select></label>
<label>{{ __('smart_ordering.filters.branch') }}<select name="branch"><option value="">{{ __('smart_ordering.filters.all') }}</option>@foreach($branches as $branch)<option value="{{ $branch->public_id }}" @selected(request('branch')===$branch->public_id)>{{ $branch->name }}</option>@endforeach</select></label>
<label>{{ __('smart_ordering.filters.from') }}<input type="date" name="from" value="{{ request('from') }}"></label>
<label>{{ __('smart_ordering.filters.to') }}<input type="date" name="to" value="{{ request('to') }}"></label>
<div class="so-actions"><button class="foodex-primary" type="submit">{{ __('smart_ordering.filters.apply') }}</button><a href="{{ route('admin.smart-ordering.operations.index',['tab'=>'orders']) }}">{{ __('smart_ordering.filters.reset') }}</a></div>
</form>

<section class="foodex-card so-table-wrap">
<table class="foodex-table" data-pagination-required>
<thead><tr>
@foreach(['order','branch','mode','status','scheduled','notification','total','created','actions'] as $column)<th>{{ __('smart_ordering.columns.'.$column) }}</th>@endforeach
</tr></thead>
<tbody>
@forelse($rows as $row)
<tr>
<td><strong class="so-order-number">{{ $row['order_number'] }}</strong></td>
<td>{{ $row['branch_name'] }}</td>
<td>{{ __('smart_ordering.modes.'.$row['fulfillment_mode']) }}</td>
<td><span class="badge {{ strtolower($row['lifecycle_status']) }}">{{ __('smart_ordering.statuses.'.$row['lifecycle_status']) }}</span></td>
<td>{{ $row['scheduled_display'] }}</td>
<td><span class="badge">{{ __('smart_ordering.notifications.'.$row['notification_status']) }}</span></td>
<td>{{ number_format((float)$row['grand_total'],3) }} {{ $row['currency'] }}</td>
<td>{{ $row['created_display'] }}</td>
<td>
@if($row['can_manage'] && count($row['allowed_targets']))
<details class="so-row-actions"><summary aria-label="{{ __('smart_ordering.change_status') }}">⋮</summary><div class="so-menu">
@foreach($row['allowed_targets'] as $target)
<form method="post" action="{{ route('admin.smart-ordering.orders.transition',$row['public_reference']) }}">
@csrf
<input type="hidden" name="status" value="{{ $target }}">
<input type="hidden" name="transition_key" value="{{ $row['transition_keys'][$target] }}">
<input name="note" maxlength="1000" placeholder="{{ __('smart_ordering.note') }}">
<button class="foodex-primary" type="submit">{{ __('smart_ordering.statuses.'.$target) }}</button>
</form>
@endforeach
</div></details>
@else —
@endif
</td>
</tr>
@empty
<tr><td colspan="9"><div class="foodex-empty-state">{{ __('smart_ordering.empty') }}</div></td></tr>
@endforelse
</tbody>
</table>
</section>
{{ $orders->links() }}
@else
<div class="so-branch-grid">
@foreach($branches as $branch)
<section class="foodex-card so-branch">
<div class="so-branch-head"><div><strong>{{ $branch->name }}</strong><div>{{ $stores->firstWhere('id',$branch->store_id)?->name }}</div></div>
@if($user->hasPermission('orders.manage',(int)$branch->store_id) || $user->hasPermission('orders.manage'))
<button class="foodex-primary" type="button" onclick="document.getElementById('branch-{{ $branch->public_id }}').showModal()">{{ __('smart_ordering.edit_branch') }}</button>
@endif
</div>
<div class="so-badges"><span class="badge">{{ $branch->is_active?__('smart_ordering.branch_state.active'):__('smart_ordering.branch_state.inactive') }}</span><span class="badge">{{ $branch->accepting_orders?__('smart_ordering.branch_state.accepting'):__('smart_ordering.branch_state.paused') }}</span></div>
<div>{{ __('smart_ordering.modes.dine_in') }}: {{ $branch->supports_dine_in?'✓':'—' }} · {{ __('smart_ordering.modes.pickup') }}: {{ $branch->supports_pickup?'✓':'—' }} · {{ __('smart_ordering.modes.delivery') }}: {{ $branch->supports_delivery?'✓':'—' }}</div>
</section>

<dialog class="so-dialog" id="branch-{{ $branch->public_id }}"><div class="so-dialog-body">
<div class="so-dialog-head"><h2>{{ __('smart_ordering.edit_branch') }} · {{ $branch->name }}</h2><button type="button" class="so-close" onclick="this.closest('dialog').close()">×</button></div>
@include('admin._smart-ordering-branch-form',['branchModel'=>$branch,'formAction'=>route('admin.smart-ordering.branches.update',$branch->public_id),'method'=>'PUT'])
</div></dialog>
@endforeach
</div>
@endif
</main>
</div>

<dialog class="so-dialog" id="so-create-branch"><div class="so-dialog-body">
<div class="so-dialog-head"><h2>{{ __('smart_ordering.add_branch') }}</h2><button type="button" class="so-close" onclick="this.closest('dialog').close()">×</button></div>
@include('admin._smart-ordering-branch-form',['branchModel'=>null,'formAction'=>route('admin.smart-ordering.branches.store'),'method'=>'POST'])
</div></dialog>
</body>
</html>
