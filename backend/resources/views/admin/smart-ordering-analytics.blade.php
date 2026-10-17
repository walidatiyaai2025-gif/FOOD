<!doctype html>
<html lang="{{ str_replace('_','-',app()->getLocale()) }}" dir="{{ app()->getLocale()==='ar'?'rtl':'ltr' }}">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>{{ __('smart_ordering.analytics.nav_title') }}</title>
@include('admin._brand-components')
<style>
.so-a-shell{display:grid;gap:var(--foodex-space-4);min-width:0}
.so-a-filters{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:12px;align-items:end}
.so-a-filters label{display:grid;gap:6px;font-size:var(--foodex-text-xs);font-weight:700}
.so-a-filters input,.so-a-filters select{min-height:44px;border:1px solid var(--foodex-border);border-radius:var(--foodex-radius-control);padding:9px 11px;background:var(--foodex-surface);color:inherit}
.so-a-kpis{display:grid;grid-template-columns:repeat(auto-fit,minmax(155px,1fr));gap:12px}
.so-a-kpi{padding:18px}.so-a-kpi span{display:block;color:var(--foodex-muted);font-size:var(--foodex-text-xs);margin-bottom:6px}.so-a-kpi strong{font-size:clamp(1.35rem,2vw,2rem)}
.so-a-grid{display:grid;grid-template-columns:minmax(0,1fr) minmax(280px,.42fr);gap:16px}
.so-a-card{padding:18px;min-width:0}.so-a-card h2{margin:0 0 14px}
.so-a-funnel{display:grid;gap:10px}.so-a-stage{display:grid;grid-template-columns:150px 1fr auto;gap:10px;align-items:center}.so-a-bar{height:12px;border-radius:999px;background:var(--foodex-background);overflow:hidden}.so-a-fill{height:100%;background:var(--foodex-green);border-radius:inherit;min-width:2px}
.so-a-insights{display:grid;gap:10px}.so-a-insight{padding:12px;border:1px solid var(--foodex-border);border-radius:var(--foodex-radius-control);background:var(--foodex-background)}
.so-a-table-wrap{overflow:auto}.so-a-table{width:100%;border-collapse:collapse}.so-a-table th,.so-a-table td{padding:11px 10px;border-bottom:1px solid var(--foodex-border);text-align:start;white-space:nowrap}.so-a-table th{font-size:var(--foodex-text-xs);color:var(--foodex-muted)}
.so-a-formulas{font-size:var(--foodex-text-xs);color:var(--foodex-muted);line-height:1.6}.so-a-empty{color:var(--foodex-muted);padding:12px 0}
@media(max-width:900px){.so-a-grid{grid-template-columns:1fr}.so-a-stage{grid-template-columns:120px 1fr auto}}
</style>
</head>
<body>
<div class="foodex-admin-layout" data-smart-ordering-analytics="true">
<aside class="sidebar">@include('admin._sidebar')</aside>
<main class="foodex-admin-main foodex-admin-page">
@include('admin._global-header')
<header class="foodex-page-toolbar">
<div>
<span class="foodex-subtitle">{{ __('smart_ordering.analytics.eyebrow') }}</span>
<h1>{{ __('smart_ordering.analytics.title') }}</h1>
<p>{{ __('smart_ordering.analytics.description') }}</p>
</div>
<div class="foodex-header-actions">

<a class="foodex-action-primary" href="{{ route('admin.smart-ordering.analytics.export',request()->query()) }}">{{ __('smart_ordering.analytics.export_csv') }}</a>
</div>
</header>

@include('admin._smart-ordering-workspace-nav')

<div class="so-a-shell">
<section class="foodex-card so-a-card">
<form method="get" class="so-a-filters">
<label>{{ __('smart_ordering.analytics.from') }}<input type="date" name="from" value="{{ $filters['from']??'' }}"></label>
<label>{{ __('smart_ordering.analytics.to') }}<input type="date" name="to" value="{{ $filters['to']??'' }}"></label>
<label>{{ __('smart_ordering.analytics.store') }}
<select name="store_id"><option value="">{{ __('smart_ordering.analytics.all') }}</option>@foreach($stores as $store)<option value="{{ $store->id }}" @selected((string)($filters['store_id']??'')===(string)$store->id)>{{ $store->name }}</option>@endforeach</select>
</label>
<label>{{ __('smart_ordering.analytics.branch') }}
<select name="branch_id"><option value="">{{ __('smart_ordering.analytics.all') }}</option>@foreach($branches as $branch)<option value="{{ $branch->id }}" @selected((string)($filters['branch_id']??'')===(string)$branch->id)>{{ app()->getLocale()==='ar'?($branch->name_ar?:$branch->name_en):($branch->name_en?:$branch->name_ar) }}</option>@endforeach</select>
</label>
<label>{{ __('smart_ordering.analytics.fulfillment') }}
<select name="fulfillment"><option value="">{{ __('smart_ordering.analytics.all') }}</option>@foreach(['dine_in','pickup','delivery'] as $mode)<option value="{{ $mode }}" @selected(($filters['fulfillment']??'')===$mode)>{{ __('smart_ordering.analytics.fulfillment_modes.'.$mode) }}</option>@endforeach</select>
</label>
<label>{{ __('smart_ordering.analytics.qr') }}
<select name="qr_public_id"><option value="">{{ __('smart_ordering.analytics.all') }}</option>@foreach($qrCodes as $qr)<option value="{{ $qr->public_id }}" @selected((string)($filters['qr_public_id']??'')===(string)$qr->public_id)>{{ $qr->label }}@if($qr->source) — {{ $qr->source }}@endif @if($qr->campaign) / {{ $qr->campaign }}@endif</option>@endforeach</select>
</label>
<label>{{ __('smart_ordering.analytics.source') }}<input name="source" maxlength="64" value="{{ $filters['source']??'' }}"></label>
<label>{{ __('smart_ordering.analytics.campaign') }}<input name="campaign" maxlength="120" value="{{ $filters['campaign']??'' }}"></label>
<label>{{ __('smart_ordering.analytics.offer') }}<input name="offer" maxlength="120" value="{{ $filters['offer']??'' }}"></label>
<label>{{ __('smart_ordering.analytics.customer') }}
<select name="customer_type"><option value="">{{ __('smart_ordering.analytics.all') }}</option><option value="new" @selected(($filters['customer_type']??'')==='new')>{{ __('smart_ordering.analytics.new_customer') }}</option><option value="repeat" @selected(($filters['customer_type']??'')==='repeat')>{{ __('smart_ordering.analytics.repeat_customer') }}</option></select>
</label>
<label>{{ __('smart_ordering.analytics.product') }}
<select name="product_id"><option value="">{{ __('smart_ordering.analytics.all') }}</option>@foreach($products as $product)<option value="{{ $product->id }}" @selected((string)($filters['product_id']??'')===(string)$product->id)>{{ $product->sku }} — {{ $product->name }}</option>@endforeach</select>
</label>
<label>{{ __('smart_ordering.analytics.category') }}
<select name="category_id"><option value="">{{ __('smart_ordering.analytics.all') }}</option>@foreach($categories as $category)<option value="{{ $category->id }}" @selected((string)($filters['category_id']??'')===(string)$category->id)>{{ $category->name }}</option>@endforeach</select>
</label>
<div><button class="foodex-action-primary" type="submit">{{ __('smart_ordering.analytics.apply') }}</button></div>
</form>
</section>

@php($k=$dashboard['kpis'])
<section class="so-a-kpis">
@foreach([
'menu_views'=>__('smart_ordering.analytics.menu_views'),
'unique_visitors'=>__('smart_ordering.analytics.unique_visitors'),
'carts'=>__('smart_ordering.analytics.carts'),
'orders'=>__('smart_ordering.analytics.orders'),
'conversion_rate'=>__('smart_ordering.analytics.conversion_rate'),
'revenue'=>__('smart_ordering.analytics.revenue'),
'aov'=>__('smart_ordering.analytics.aov'),
'abandoned_carts'=>__('smart_ordering.analytics.abandoned_carts'),
'cancellation_rate'=>__('smart_ordering.analytics.cancellation_rate'),
'repeat_conversion'=>__('smart_ordering.analytics.repeat_conversion'),
'offer_conversion'=>__('smart_ordering.analytics.offer_conversion'),
'feedback_count'=>__('smart_ordering.analytics.feedback_count'),
'average_rating'=>__('smart_ordering.analytics.average_rating')
] as $key=>$label)
<article class="foodex-card so-a-kpi"><span>{{ $label }}</span><strong class="foodex-number">{{ is_float($k[$key])?number_format($k[$key],3):$k[$key] }}</strong></article>
@endforeach
</section>

<section class="so-a-grid">
<div class="foodex-card so-a-card">
<h2>{{ __('smart_ordering.analytics.conversion_funnel') }}</h2>
@php($max=max(1,(int)($dashboard['funnel'][0]['count']??0)))
<div class="so-a-funnel">@foreach($dashboard['funnel'] as $stage)
<div class="so-a-stage"><span>{{ __('smart_ordering.analytics.events.'.$stage['key']) }}</span><div class="so-a-bar"><div class="so-a-fill" style="width:{{ min(100,round(($stage['count']/$max)*100,2)) }}%"></div></div><strong>{{ $stage['count'] }}</strong></div>
@endforeach</div>
</div>
<div class="foodex-card so-a-card">
<h2>{{ __('smart_ordering.analytics.actionable_insights') }}</h2>
<div class="so-a-insights">@forelse($dashboard['insights'] as $insight)<div class="so-a-insight" data-insight="{{ $insight['code'] }}">{{ $insight['message'] }}</div>@empty<div class="so-a-empty">{{ __('smart_ordering.analytics.no_strong_signal') }}</div>@endforelse</div>
</div>
</section>

<section class="foodex-card so-a-card">
<h2>{{ __('smart_ordering.analytics.source_campaign_performance') }}</h2>
<div class="so-a-table-wrap"><table class="so-a-table" data-pagination-exempt="bounded-read-model"><thead><tr><th>{{ __('smart_ordering.analytics.qr') }}</th><th>{{ __('smart_ordering.analytics.source') }}</th><th>{{ __('smart_ordering.analytics.campaign') }}</th><th>{{ __('smart_ordering.analytics.views') }}</th><th>{{ __('smart_ordering.analytics.orders') }}</th><th>{{ __('smart_ordering.analytics.conversion_short') }}</th><th>{{ __('smart_ordering.analytics.revenue') }}</th><th>{{ __('smart_ordering.analytics.aov') }}</th></tr></thead><tbody>
@forelse($dashboard['sources'] as $row)<tr><td>{{ $row['qr_public_id']?:'—' }}</td><td>{{ $row['source']?:__('smart_ordering.analytics.direct') }}</td><td>{{ $row['campaign']?:'—' }}</td><td>{{ $row['views'] }}</td><td>{{ $row['orders'] }}</td><td>{{ number_format($row['conversion'],2) }}</td><td>{{ number_format($row['revenue'],3) }}</td><td>{{ number_format($row['aov'],3) }}</td></tr>@empty<tr><td colspan="8" class="so-a-empty">{{ __('smart_ordering.analytics.empty') }}</td></tr>@endforelse
</tbody></table></div>
</section>

<section class="so-a-grid">
<div class="foodex-card so-a-card"><h2>{{ __('smart_ordering.analytics.branch_performance') }}</h2><div class="so-a-table-wrap"><table class="so-a-table" data-pagination-exempt="bounded-read-model"><thead><tr><th>{{ __('smart_ordering.analytics.branch') }}</th><th>{{ __('smart_ordering.analytics.views') }}</th><th>{{ __('smart_ordering.analytics.orders') }}</th><th>{{ __('smart_ordering.analytics.conversion_short') }}</th><th>{{ __('smart_ordering.analytics.revenue') }}</th></tr></thead><tbody>@forelse($dashboard['branches'] as $row)<tr><td>{{ app()->getLocale()==='ar'?($row['name_ar']?:$row['name_en']):($row['name_en']?:$row['name_ar']) }}</td><td>{{ $row['views'] }}</td><td>{{ $row['orders'] }}</td><td>{{ number_format($row['conversion'],2) }}</td><td>{{ number_format($row['revenue'],3) }}</td></tr>@empty<tr><td colspan="5" class="so-a-empty">{{ __('smart_ordering.analytics.empty') }}</td></tr>@endforelse</tbody></table></div></div>
<div class="foodex-card so-a-card"><h2>{{ __('smart_ordering.analytics.reproducible_formulas') }}</h2><div class="so-a-formulas"><strong>{{ $dashboard['formula_version'] }}</strong>@foreach($dashboard['formulas'] as $name=>$formula)<div><code>{{ $name }}</code> = {{ $formula }}</div>@endforeach</div></div>
</section>

<section class="foodex-card so-a-card">
<h2>{{ __('smart_ordering.analytics.product_performance') }}</h2>
<div class="so-a-table-wrap"><table class="so-a-table" data-pagination-exempt="bounded-read-model"><thead><tr><th>{{ __('smart_ordering.analytics.product') }}</th><th>{{ __('smart_ordering.analytics.views') }}</th><th>{{ __('smart_ordering.analytics.adds') }}</th><th>{{ __('smart_ordering.analytics.completed') }}</th><th>{{ __('smart_ordering.analytics.conversion_short') }}</th><th>{{ __('smart_ordering.analytics.revenue') }}</th></tr></thead><tbody>
@forelse($dashboard['products'] as $row)<tr><td>{{ $row['name']?:('#'.$row['product_id']) }}</td><td>{{ $row['views'] }}</td><td>{{ $row['adds'] }}</td><td>{{ $row['completed'] }}</td><td>{{ number_format($row['conversion'],2) }}</td><td>{{ number_format($row['revenue'],3) }}</td></tr>@empty<tr><td colspan="6" class="so-a-empty">{{ __('smart_ordering.analytics.empty') }}</td></tr>@endforelse
</tbody></table></div>
</section>

<section class="so-a-grid">
<div class="foodex-card so-a-card">
<h2>{{ __('smart_ordering.analytics.category_performance') }}</h2>
<div class="so-a-table-wrap"><table class="so-a-table" data-pagination-exempt="bounded-read-model"><thead><tr><th>{{ __('smart_ordering.analytics.category') }}</th><th>{{ __('smart_ordering.analytics.views') }}</th><th>{{ __('smart_ordering.analytics.completed') }}</th></tr></thead><tbody>
@forelse($dashboard['categories'] as $row)<tr><td>{{ $row['name']?:('#'.$row['category_id']) }}</td><td>{{ $row['views'] }}</td><td>{{ $row['completed'] }}</td></tr>@empty<tr><td colspan="3" class="so-a-empty">{{ __('smart_ordering.analytics.empty') }}</td></tr>@endforelse
</tbody></table></div>
</div>
<div class="foodex-card so-a-card">
<h2>{{ __('smart_ordering.analytics.peak_order_hours') }}</h2>
<div class="so-a-table-wrap"><table class="so-a-table" data-pagination-exempt="bounded-read-model"><thead><tr><th>{{ __('smart_ordering.analytics.hour') }}</th><th>{{ __('smart_ordering.analytics.orders') }}</th></tr></thead><tbody>
@forelse($dashboard['peak_hours'] as $row)<tr><td>{{ str_pad((string)$row['hour'],2,'0',STR_PAD_LEFT) }}:00</td><td>{{ $row['orders'] }}</td></tr>@empty<tr><td colspan="2" class="so-a-empty">{{ __('smart_ordering.analytics.empty') }}</td></tr>@endforelse
</tbody></table></div>
</div>
</section>

<section class="so-a-grid">
<div class="foodex-card so-a-card">
<h2>{{ __('smart_ordering.analytics.fulfillment_customer_mix') }}</h2>
<div class="so-a-table-wrap"><table class="so-a-table" data-pagination-exempt="bounded-read-model"><thead><tr><th>{{ __('smart_ordering.analytics.dimension') }}</th><th>{{ __('smart_ordering.analytics.count') }}</th></tr></thead><tbody>
@foreach($dashboard['fulfillment'] as $row)<tr><td>{{ __('smart_ordering.analytics.fulfillment_modes.'.$row['key']) }}</td><td>{{ $row['count'] }}</td></tr>@endforeach
@foreach($dashboard['customer_mix'] as $row)<tr><td>{{ __('smart_ordering.analytics.customer_types.'.$row['key']) }}</td><td>{{ $row['count'] }}</td></tr>@endforeach
@if($dashboard['fulfillment']===[] && $dashboard['customer_mix']===[])<tr><td colspan="2" class="so-a-empty">{{ __('smart_ordering.analytics.empty') }}</td></tr>@endif
</tbody></table></div>
</div>
<div class="foodex-card so-a-card">
<h2>{{ __('smart_ordering.analytics.offer_performance') }}</h2>
<div class="so-a-table-wrap"><table class="so-a-table" data-pagination-exempt="bounded-read-model"><thead><tr><th>{{ __('smart_ordering.analytics.offer') }}</th><th>{{ __('smart_ordering.analytics.impressions') }}</th><th>{{ __('smart_ordering.analytics.clicks') }}</th><th>{{ __('smart_ordering.analytics.redeemed') }}</th><th>{{ __('smart_ordering.analytics.conversion_short') }}</th></tr></thead><tbody>
@forelse($dashboard['offers'] as $row)<tr><td>{{ $row['offer'] }}</td><td>{{ $row['impressions'] }}</td><td>{{ $row['clicks'] }}</td><td>{{ $row['redeemed'] }}</td><td>{{ number_format($row['conversion'],2) }}</td></tr>@empty<tr><td colspan="5" class="so-a-empty">{{ __('smart_ordering.analytics.empty') }}</td></tr>@endforelse
</tbody></table></div>
</div>
</section>
</div>
</main>
</div>
</body>
</html>
