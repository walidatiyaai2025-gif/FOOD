<!doctype html>
<html lang="{{ app()->getLocale() }}" dir="{{ app()->getLocale()==='ar'?'rtl':'ltr' }}">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>{{ __('coupons.title') }} · FOODEX</title>
@include('admin._brand-components')
<style>
.coupon-shell{display:grid;gap:16px}.coupon-panel,.coupon-card{padding:18px}.coupon-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px}.coupon-grid .wide{grid-column:span 2}.coupon-grid .full{grid-column:1/-1}
.coupon-grid label{display:grid;gap:6px;font-weight:700;font-size:.85rem}.coupon-grid input,.coupon-grid select,.coupon-grid textarea{width:100%;min-height:42px;border:1px solid var(--foodex-border);border-radius:10px;padding:9px 11px;background:#fff}.coupon-grid textarea{min-height:82px}
.coupon-actions{display:flex;gap:8px;flex-wrap:wrap;align-items:center}.coupon-card-head{display:flex;align-items:flex-start;justify-content:space-between;gap:12px;flex-wrap:wrap}.coupon-meta{display:flex;gap:8px;flex-wrap:wrap}.coupon-badge{padding:5px 9px;border-radius:999px;background:#eef2f7;font-weight:800;font-size:.78rem}.coupon-badge.active{background:var(--foodex-green-soft);color:var(--foodex-green-dark)}
.coupon-filter{display:flex;gap:8px;flex-wrap:wrap}.coupon-filter input,.coupon-filter select{min-height:40px;border:1px solid var(--foodex-border);border-radius:10px;padding:8px 10px}
@media(max-width:1000px){.coupon-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}@media(max-width:680px){.coupon-grid{grid-template-columns:1fr}.coupon-grid .wide{grid-column:auto}}
</style>
</head>
<body>
@php($ar=app()->getLocale()==='ar')
<div class="foodex-admin-layout">
<aside class="sidebar">@include('admin._sidebar')</aside>
<main class="foodex-admin-main foodex-admin-page">
<header class="foodex-page-header"><div><span class="foodex-subtitle">FOODEX · {{ $ar?'الدعايا':'Advertising' }}</span><h1>{{ __('coupons.title') }}</h1><p>{{ __('coupons.description') }}</p></div></header>
<div class="coupon-shell">
@if(session('status'))<div class="foodex-card coupon-panel" role="status">{{ session('status') }}</div>@endif
@if($errors->any())<div class="foodex-card coupon-panel" style="border-color:#fecaca;color:#991b1b"><strong>{{ $errors->first() }}</strong></div>@endif

<section class="foodex-card coupon-panel">
<h2>{{ __('coupons.create') }}</h2>
<form method="post" action="{{ route('admin.coupons.store') }}" class="coupon-grid">
@csrf
<label>{{ __('coupons.channel') }}<select name="channel" required>
@if($canB2b)<option value="b2b" @selected(old('channel')==='b2b')>{{ __('coupons.b2b') }}</option>@endif
@if($retailStores!==[])<option value="b2c" @selected(old('channel','b2c')==='b2c')>{{ __('coupons.b2c') }}</option>@endif
</select></label>
<label>{{ __('coupons.store') }}<select name="store_id"><option value="">{{ $ar?'— للجملة لا تختار متجر —':'— Wholesale: no store —' }}</option>@foreach($retailStores as $store)<option value="{{ $store['id'] }}" @selected((string)old('store_id')===(string)$store['id'])>{{ $store['name'] }}</option>@endforeach</select></label>
<label>{{ __('coupons.code') }}<input name="code" value="{{ old('code') }}" required maxlength="80" placeholder="WELCOME10"></label>
<label>{{ __('coupons.discount_type') }}<select name="discount_type" required><option value="percentage">{{ __('coupons.percentage') }}</option><option value="fixed">{{ __('coupons.fixed') }}</option><option value="free_shipping">{{ __('coupons.free_shipping') }}</option></select></label>
<label>{{ __('coupons.name_ar') }}<input name="name_ar" value="{{ old('name_ar') }}" required></label>
<label>{{ __('coupons.name_en') }}<input name="name_en" value="{{ old('name_en') }}" required></label>
<label>{{ __('coupons.discount_value') }}<input name="discount_value" type="number" min="0" step="0.001" value="{{ old('discount_value','0') }}"></label>
<label>{{ __('coupons.minimum_order_amount') }}<input name="minimum_order_amount" type="number" min="0" step="0.001" value="{{ old('minimum_order_amount') }}"></label>
<label>{{ __('coupons.maximum_discount_amount') }}<input name="maximum_discount_amount" type="number" min="0" step="0.001" value="{{ old('maximum_discount_amount') }}"></label>
<label>{{ __('coupons.usage_limit_total') }}<input name="usage_limit_total" type="number" min="1" value="{{ old('usage_limit_total') }}"></label>
<label>{{ __('coupons.usage_limit_per_user') }}<input name="usage_limit_per_user" type="number" min="1" value="{{ old('usage_limit_per_user') }}"></label>
<label>{{ __('coupons.starts_at') }}<input name="starts_at" type="datetime-local" value="{{ old('starts_at') }}"></label>
<label>{{ __('coupons.ends_at') }}<input name="ends_at" type="datetime-local" value="{{ old('ends_at') }}"></label>
<label class="wide">{{ __('coupons.description_ar') }}<textarea name="description_ar">{{ old('description_ar') }}</textarea></label>
<label class="wide">{{ __('coupons.description_en') }}<textarea name="description_en">{{ old('description_en') }}</textarea></label>
<label><span>{{ __('coupons.first_order_only') }}</span><span><input type="hidden" name="first_order_only" value="0"><input type="checkbox" name="first_order_only" value="1" @checked(old('first_order_only')==='1')> {{ __('coupons.first_order_only') }}</span></label>
<label><span>{{ __('coupons.active') }}</span><span><input type="hidden" name="is_active" value="0"><input type="checkbox" name="is_active" value="1" @checked(old('is_active','1')==='1')> {{ __('coupons.active') }}</span></label>
<div class="full"><button class="foodex-action-primary" type="submit">＋ {{ __('coupons.save') }}</button></div>
</form>
</section>

<section class="foodex-card coupon-panel">
<form method="get" class="coupon-filter">
<input name="q" value="{{ $search }}" placeholder="{{ __('coupons.search') }}">
<select name="channel"><option value="">{{ __('coupons.all_channels') }}</option><option value="b2b" @selected($channelFilter==='b2b')>{{ __('coupons.b2b') }}</option><option value="b2c" @selected($channelFilter==='b2c')>{{ __('coupons.b2c') }}</option></select>
<select name="status"><option value="">{{ __('coupons.all_statuses') }}</option><option value="active" @selected($statusFilter==='active')>{{ __('coupons.active') }}</option><option value="inactive" @selected($statusFilter==='inactive')>{{ __('coupons.inactive') }}</option></select>
<button class="foodex-action-secondary" type="submit">{{ __('coupons.filter') }}</button>
</form>
</section>

@forelse($coupons as $coupon)
<section class="foodex-card coupon-card">
<div class="coupon-card-head">
<div><h3 style="margin:0 0 8px">{{ $coupon->code }} · {{ $ar?$coupon->name_ar:$coupon->name_en }}</h3><div class="coupon-meta"><span class="coupon-badge">{{ $coupon->channel==='b2b'?__('coupons.b2b'):__('coupons.b2c') }}</span>@if($coupon->store)<span class="coupon-badge">{{ $coupon->store->name }}</span>@endif<span class="coupon-badge {{ $coupon->is_active?'active':'' }}">{{ $coupon->is_active?__('coupons.active'):__('coupons.inactive') }}</span><span class="coupon-badge">{{ __('coupons.usage') }}: {{ $coupon->used_count }} / {{ $coupon->usage_limit_total ?? '∞' }}</span></div></div>
<div class="coupon-actions">
<form method="post" action="{{ route('admin.coupons.toggle',$coupon) }}">@csrf @method('patch')<button class="foodex-action-secondary" type="submit">{{ $coupon->is_active?__('coupons.deactivate'):__('coupons.activate') }}</button></form>
@if((int)$coupon->used_count===0)<form method="post" action="{{ route('admin.coupons.destroy',$coupon) }}" onsubmit="return confirm('{{ $ar?'حذف الكوبون؟':'Delete coupon?' }}')">@csrf @method('delete')<button class="danger btn" type="submit">{{ __('coupons.delete') }}</button></form>@endif
</div></div>
<details data-foodex-operational-modal style="margin-top:14px"><summary style="cursor:pointer;font-weight:800">{{ __('coupons.update') }}</summary>
<form method="post" action="{{ route('admin.coupons.update',$coupon) }}" class="coupon-grid" style="margin-top:14px">@csrf @method('patch')
<label>{{ __('coupons.channel') }}<select name="channel"><option value="b2b" @selected($coupon->channel==='b2b')>{{ __('coupons.b2b') }}</option>@if($retailStores!==[])<option value="b2c" @selected($coupon->channel==='b2c')>{{ __('coupons.b2c') }}</option>@endif</select></label>
<label>{{ __('coupons.store') }}<select name="store_id"><option value="">—</option>@foreach($retailStores as $store)<option value="{{ $store['id'] }}" @selected((int)$coupon->store_id===(int)$store['id'])>{{ $store['name'] }}</option>@endforeach</select></label>
<label>{{ __('coupons.code') }}<input name="code" value="{{ $coupon->code }}" required></label>
<label>{{ __('coupons.discount_type') }}<select name="discount_type"><option value="percentage" @selected($coupon->discount_type==='percentage')>{{ __('coupons.percentage') }}</option><option value="fixed" @selected($coupon->discount_type==='fixed')>{{ __('coupons.fixed') }}</option><option value="free_shipping" @selected($coupon->discount_type==='free_shipping')>{{ __('coupons.free_shipping') }}</option></select></label>
<label>{{ __('coupons.name_ar') }}<input name="name_ar" value="{{ $coupon->name_ar }}" required></label><label>{{ __('coupons.name_en') }}<input name="name_en" value="{{ $coupon->name_en }}" required></label>
<label>{{ __('coupons.discount_value') }}<input name="discount_value" type="number" min="0" step=".001" value="{{ $coupon->discount_value }}"></label>
<label>{{ __('coupons.minimum_order_amount') }}<input name="minimum_order_amount" type="number" min="0" step=".001" value="{{ $coupon->minimum_order_amount }}"></label>
<label>{{ __('coupons.maximum_discount_amount') }}<input name="maximum_discount_amount" type="number" min="0" step=".001" value="{{ $coupon->maximum_discount_amount }}"></label>
<label>{{ __('coupons.usage_limit_total') }}<input name="usage_limit_total" type="number" min="1" value="{{ $coupon->usage_limit_total }}"></label>
<label>{{ __('coupons.usage_limit_per_user') }}<input name="usage_limit_per_user" type="number" min="1" value="{{ $coupon->usage_limit_per_user }}"></label>
<label>{{ __('coupons.starts_at') }}<input name="starts_at" type="datetime-local" value="{{ $coupon->starts_at?->timezone('Asia/Kuwait')?->format('Y-m-d\TH:i') ?? '' }}"></label>
<label>{{ __('coupons.ends_at') }}<input name="ends_at" type="datetime-local" value="{{ $coupon->ends_at?->timezone('Asia/Kuwait')?->format('Y-m-d\TH:i') ?? '' }}"></label>
<label class="wide">{{ __('coupons.description_ar') }}<textarea name="description_ar">{{ $coupon->description_ar }}</textarea></label><label class="wide">{{ __('coupons.description_en') }}<textarea name="description_en">{{ $coupon->description_en }}</textarea></label>
<label><span>{{ __('coupons.first_order_only') }}</span><span><input type="hidden" name="first_order_only" value="0"><input type="checkbox" name="first_order_only" value="1" @checked($coupon->first_order_only)> {{ __('coupons.first_order_only') }}</span></label>
<label><span>{{ __('coupons.active') }}</span><span><input type="hidden" name="is_active" value="0"><input type="checkbox" name="is_active" value="1" @checked($coupon->is_active)> {{ __('coupons.active') }}</span></label>
<div class="full"><button class="foodex-action-primary" type="submit">{{ __('coupons.update') }}</button></div>
</form></details>
</section>
@empty
<section class="foodex-card coupon-panel">{{ __('coupons.empty') }}</section>
@endforelse
{{ $coupons->links() }}
</div>
</main></div>
</body></html>
