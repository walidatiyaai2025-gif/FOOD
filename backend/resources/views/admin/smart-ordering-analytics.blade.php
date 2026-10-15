<!doctype html>
<html lang="{{ str_replace('_','-',app()->getLocale()) }}" dir="{{ app()->getLocale()==='ar'?'rtl':'ltr' }}">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>{{ app()->getLocale()==='ar'?'تحليلات الطلب الذكي':'Smart Ordering Analytics' }}</title>
@include('admin._brand-components')
<style>
.so-a-shell{display:grid;gap:var(--foodex-space-4);min-width:0}
.so-a-filters{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:12px;align-items:end}
.so-a-filters label{display:grid;gap:6px;font-size:var(--foodex-text-xs);font-weight:700}
.so-a-filters input,.so-a-filters select{min-height:44px;border:1px solid var(--foodex-border);border-radius:var(--foodex-radius-input);padding:9px 11px;background:var(--foodex-surface);color:inherit}
.so-a-kpis{display:grid;grid-template-columns:repeat(auto-fit,minmax(155px,1fr));gap:12px}
.so-a-kpi{padding:18px}.so-a-kpi span{display:block;color:var(--foodex-muted);font-size:var(--foodex-text-xs);margin-bottom:6px}.so-a-kpi strong{font-size:clamp(1.35rem,2vw,2rem)}
.so-a-grid{display:grid;grid-template-columns:minmax(0,1fr) minmax(280px,.42fr);gap:16px}
.so-a-card{padding:18px;min-width:0}.so-a-card h2{margin:0 0 14px}
.so-a-funnel{display:grid;gap:10px}.so-a-stage{display:grid;grid-template-columns:150px 1fr auto;gap:10px;align-items:center}.so-a-bar{height:12px;border-radius:999px;background:var(--foodex-background);overflow:hidden}.so-a-fill{height:100%;background:var(--foodex-green);border-radius:inherit;min-width:2px}
.so-a-insights{display:grid;gap:10px}.so-a-insight{padding:12px;border:1px solid var(--foodex-border);border-radius:var(--foodex-radius-input);background:var(--foodex-background)}
.so-a-table-wrap{overflow:auto}.so-a-table{width:100%;border-collapse:collapse}.so-a-table th,.so-a-table td{padding:11px 10px;border-bottom:1px solid var(--foodex-border);text-align:start;white-space:nowrap}.so-a-table th{font-size:var(--foodex-text-xs);color:var(--foodex-muted)}
.so-a-formulas{font-size:var(--foodex-text-xs);color:var(--foodex-muted);line-height:1.6}.so-a-empty{color:var(--foodex-muted);padding:12px 0}
@media(max-width:900px){.so-a-grid{grid-template-columns:1fr}.so-a-stage{grid-template-columns:120px 1fr auto}}
</style>
</head>
<body>
<div class="foodex-admin-layout" data-smart-ordering-analytics="true">
<aside class="sidebar">@include('admin._sidebar')</aside>
<main class="foodex-admin-main foodex-admin-page">
<header class="foodex-page-header">
<div>
<span class="foodex-subtitle">{{ app()->getLocale()==='ar'?'النمو التجاري للمطعم':'Restaurant commercial growth' }}</span>
<h1>{{ app()->getLocale()==='ar'?'تحليلات الطلب الذكي':'Smart Ordering Analytics' }}</h1>
<p>{{ app()->getLocale()==='ar'?'مسار التحويل، الإيراد، الفروع، QR والحملات والعروض والعملاء المتكررين.':'Conversion funnel, revenue, branches, QR/campaign attribution, offers and repeat customers.' }}</p>
</div>
<div class="foodex-header-actions">
@include('admin._live-notifications',['user'=>$user])
<a class="foodex-action-primary" href="{{ route('admin.smart-ordering.analytics.export',request()->query()) }}">{{ app()->getLocale()==='ar'?'تصدير CSV':'Export CSV' }}</a>
</div>
</header>

<div class="so-a-shell">
<section class="foodex-card so-a-card">
<form method="get" class="so-a-filters">
<label>{{ app()->getLocale()==='ar'?'من':'From' }}<input type="date" name="from" value="{{ $filters['from']??'' }}"></label>
<label>{{ app()->getLocale()==='ar'?'إلى':'To' }}<input type="date" name="to" value="{{ $filters['to']??'' }}"></label>
<label>{{ app()->getLocale()==='ar'?'المتجر':'Store' }}
<select name="store_id"><option value="">{{ app()->getLocale()==='ar'?'الكل':'All' }}</option>@foreach($stores as $store)<option value="{{ $store->id }}" @selected((string)($filters['store_id']??'')===(string)$store->id)>{{ $store->name }}</option>@endforeach</select>
</label>
<label>{{ app()->getLocale()==='ar'?'الفرع':'Branch' }}
<select name="branch_id"><option value="">{{ app()->getLocale()==='ar'?'الكل':'All' }}</option>@foreach($branches as $branch)<option value="{{ $branch->id }}" @selected((string)($filters['branch_id']??'')===(string)$branch->id)>{{ app()->getLocale()==='ar'?($branch->name_ar?:$branch->name_en):($branch->name_en?:$branch->name_ar) }}</option>@endforeach</select>
</label>
<label>{{ app()->getLocale()==='ar'?'التنفيذ':'Fulfillment' }}
<select name="fulfillment"><option value="">{{ app()->getLocale()==='ar'?'الكل':'All' }}</option>@foreach(['dine_in','pickup','delivery'] as $mode)<option value="{{ $mode }}" @selected(($filters['fulfillment']??'')===$mode)>{{ $mode }}</option>@endforeach</select>
</label>
<label>Source<input name="source" maxlength="64" value="{{ $filters['source']??'' }}"></label>
<label>Campaign<input name="campaign" maxlength="120" value="{{ $filters['campaign']??'' }}"></label>
<label>Offer<input name="offer" maxlength="120" value="{{ $filters['offer']??'' }}"></label>
<label>{{ app()->getLocale()==='ar'?'العميل':'Customer' }}
<select name="customer_type"><option value="">{{ app()->getLocale()==='ar'?'الكل':'All' }}</option><option value="new" @selected(($filters['customer_type']??'')==='new')>New</option><option value="repeat" @selected(($filters['customer_type']??'')==='repeat')>Repeat</option></select>
</label>
<label>Product ID<input type="number" min="1" name="product_id" value="{{ $filters['product_id']??'' }}"></label>
<label>Category ID<input type="number" min="1" name="category_id" value="{{ $filters['category_id']??'' }}"></label>
<div><button class="foodex-action-primary" type="submit">{{ app()->getLocale()==='ar'?'تطبيق':'Apply' }}</button></div>
</form>
</section>

@php($k=$dashboard['kpis'])
<section class="so-a-kpis">
@foreach([
'menu_views'=>app()->getLocale()==='ar'?'مشاهدات المنيو':'Menu views',
'unique_visitors'=>app()->getLocale()==='ar'?'زوار فريدون':'Unique visitors',
'carts'=>app()->getLocale()==='ar'?'سلات':'Carts',
'orders'=>app()->getLocale()==='ar'?'طلبات':'Orders',
'conversion_rate'=>app()->getLocale()==='ar'?'التحويل %':'Conversion %',
'revenue'=>app()->getLocale()==='ar'?'الإيراد':'Revenue',
'aov'=>'AOV',
'abandoned_carts'=>app()->getLocale()==='ar'?'سلات متروكة':'Abandoned carts',
'cancellation_rate'=>app()->getLocale()==='ar'?'الإلغاء %':'Cancellation %',
'repeat_conversion'=>app()->getLocale()==='ar'?'تحويل إعادة الطلب %':'Repeat conversion %',
'offer_conversion'=>app()->getLocale()==='ar'?'تحويل العروض %':'Offer conversion %'
] as $key=>$label)
<article class="foodex-card so-a-kpi"><span>{{ $label }}</span><strong class="foodex-number">{{ is_float($k[$key])?number_format($k[$key],3):$k[$key] }}</strong></article>
@endforeach
</section>

<section class="so-a-grid">
<div class="foodex-card so-a-card">
<h2>{{ app()->getLocale()==='ar'?'مسار التحويل':'Conversion funnel' }}</h2>
@php($max=max(1,(int)($dashboard['funnel'][0]['count']??0)))
<div class="so-a-funnel">@foreach($dashboard['funnel'] as $stage)
<div class="so-a-stage"><span>{{ str_replace('_',' ',$stage['key']) }}</span><div class="so-a-bar"><div class="so-a-fill" style="width:{{ min(100,round(($stage['count']/$max)*100,2)) }}%"></div></div><strong>{{ $stage['count'] }}</strong></div>
@endforeach</div>
</div>
<div class="foodex-card so-a-card">
<h2>{{ app()->getLocale()==='ar'?'استنتاجات قابلة للتنفيذ':'Actionable insights' }}</h2>
<div class="so-a-insights">@forelse($dashboard['insights'] as $insight)<div class="so-a-insight" data-insight="{{ $insight['code'] }}">{{ $insight['message'] }}</div>@empty<div class="so-a-empty">{{ app()->getLocale()==='ar'?'لا توجد إشارة قوية في الفترة المحددة.':'No strong signal in the selected period.' }}</div>@endforelse</div>
</div>
</section>

<section class="foodex-card so-a-card">
<h2>{{ app()->getLocale()==='ar'?'أداء المصدر والحملة':'Source & campaign performance' }}</h2>
<div class="so-a-table-wrap"><table class="so-a-table"><thead><tr><th>Source</th><th>Campaign</th><th>Views</th><th>Orders</th><th>Conv %</th><th>Revenue</th><th>AOV</th></tr></thead><tbody>
@forelse($dashboard['sources'] as $row)<tr><td>{{ $row['source']?:'direct' }}</td><td>{{ $row['campaign']?:'—' }}</td><td>{{ $row['views'] }}</td><td>{{ $row['orders'] }}</td><td>{{ number_format($row['conversion'],2) }}</td><td>{{ number_format($row['revenue'],3) }}</td><td>{{ number_format($row['aov'],3) }}</td></tr>@empty<tr><td colspan="7" class="so-a-empty">—</td></tr>@endforelse
</tbody></table></div>
</section>

<section class="so-a-grid">
<div class="foodex-card so-a-card"><h2>{{ app()->getLocale()==='ar'?'أداء الفروع':'Branch performance' }}</h2><div class="so-a-table-wrap"><table class="so-a-table"><thead><tr><th>Branch</th><th>Views</th><th>Orders</th><th>Conv %</th><th>Revenue</th></tr></thead><tbody>@foreach($dashboard['branches'] as $row)<tr><td>{{ app()->getLocale()==='ar'?($row['name_ar']?:$row['name_en']):($row['name_en']?:$row['name_ar']) }}</td><td>{{ $row['views'] }}</td><td>{{ $row['orders'] }}</td><td>{{ number_format($row['conversion'],2) }}</td><td>{{ number_format($row['revenue'],3) }}</td></tr>@endforeach</tbody></table></div></div>
<div class="foodex-card so-a-card"><h2>{{ app()->getLocale()==='ar'?'معادلات قابلة لإعادة الإنتاج':'Reproducible formulas' }}</h2><div class="so-a-formulas"><strong>{{ $dashboard['formula_version'] }}</strong>@foreach($dashboard['formulas'] as $name=>$formula)<div><code>{{ $name }}</code> = {{ $formula }}</div>@endforeach</div></div>
</section>

<section class="foodex-card so-a-card">
<h2>{{ app()->getLocale()==='ar'?'أداء المنتجات':'Product performance' }}</h2>
<div class="so-a-table-wrap"><table class="so-a-table"><thead><tr><th>Product</th><th>Views</th><th>Adds</th><th>Completed</th><th>Conv %</th><th>Revenue</th></tr></thead><tbody>
@forelse($dashboard['products'] as $row)<tr><td>{{ $row['name']?:('#'.$row['product_id']) }}</td><td>{{ $row['views'] }}</td><td>{{ $row['adds'] }}</td><td>{{ $row['completed'] }}</td><td>{{ number_format($row['conversion'],2) }}</td><td>{{ number_format($row['revenue'],3) }}</td></tr>@empty<tr><td colspan="6" class="so-a-empty">—</td></tr>@endforelse
</tbody></table></div>
</section>
</div>
</main>
</div>
</body>
</html>
