<!doctype html>
<html lang="{{ app()->getLocale() }}" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>{{ app()->getLocale() === 'ar' ? 'إعدادات النظام والقيم المرجعية' : 'System Settings / Lookups' }} · FOODEX</title>
@include('admin._brand-components')
<style>
body{margin:0;background:var(--foodex-background);color:var(--foodex-ink)}
.page{direction:ltr;display:grid;grid-template-columns:minmax(0,1fr) var(--foodex-sidebar-width);min-height:100vh}
.page aside{grid-column:2;grid-row:1;direction:rtl;background:var(--foodex-surface);border-left:1px solid var(--foodex-border);padding:var(--foodex-space-5)}
.page main{grid-column:1;grid-row:1;direction:rtl;padding:var(--foodex-space-6);min-width:0}
html[dir=ltr] .page{grid-template-columns:var(--foodex-sidebar-width) minmax(0,1fr)}
html[dir=ltr] .page aside{grid-column:1;direction:ltr;border-left:0;border-right:1px solid var(--foodex-border)}
html[dir=ltr] .page main{grid-column:2;direction:ltr}
.header{display:flex;justify-content:space-between;gap:16px;align-items:flex-start;margin-bottom:18px}.header h1{margin:0 0 6px}.muted{color:var(--foodex-muted)}
.card{background:var(--foodex-surface);border:1px solid var(--foodex-border);border-radius:var(--foodex-radius-card);padding:16px;box-shadow:var(--foodex-shadow-sm);margin-bottom:16px}
.tabs{display:flex;gap:8px;overflow:auto;padding-bottom:4px}.tab{white-space:nowrap;padding:10px 14px;border:1px solid var(--foodex-border);border-radius:10px;text-decoration:none;color:inherit;background:#fff}.tab.active{background:var(--foodex-green);color:#fff;border-color:var(--foodex-green)}
.toolbar,.form-grid,.actions{display:flex;gap:10px;flex-wrap:wrap;align-items:end}.form-grid label,.toolbar label{display:grid;gap:5px;font-size:12px;font-weight:700}
input,select{border:1px solid var(--foodex-border);border-radius:9px;padding:9px;background:#fff;font:inherit;min-width:140px}.wide{min-width:220px}
.btn{border:1px solid var(--foodex-border);background:#fff;border-radius:9px;padding:9px 12px;text-decoration:none;color:inherit;cursor:pointer;font:inherit}.btn.primary{background:var(--foodex-green);color:#fff;border-color:var(--foodex-green)}
.notice{padding:10px 12px;border-radius:10px;margin-bottom:12px}.ok{background:var(--foodex-green-soft);color:var(--foodex-green-dark)}.err{background:#fff1f0;color:var(--foodex-red)}
.table-wrap{overflow:auto}.table{width:100%;border-collapse:collapse;min-width:900px}.table th,.table td{padding:10px;border-bottom:1px solid var(--foodex-border);text-align:start;vertical-align:top}.table th{background:var(--foodex-background)}
.code{font-family:ui-monospace,SFMono-Regular,Consolas,monospace;font-size:12px}.badge{display:inline-flex;border-radius:999px;padding:4px 8px;font-size:11px}.badge.on{background:var(--foodex-green-soft);color:var(--foodex-green-dark)}.badge.off{background:#f2f4f7;color:#667085}
.immutable{font-size:11px;color:var(--foodex-muted);display:block;margin-top:4px}
@media(max-width:1023px){.page{grid-template-columns:1fr}.page aside,.page main,html[dir=ltr] .page aside,html[dir=ltr] .page main{grid-column:1}.page aside{grid-row:1}.page main{grid-row:2}}
@media(max-width:720px){.page main{padding:var(--foodex-space-4)}.form-grid{display:grid;grid-template-columns:1fr}.form-grid input{width:100%;box-sizing:border-box}}
</style>
</head>
<body>
@php($ar = app()->getLocale() === 'ar')
<div class="page">
<aside>
@include('admin._sidebar', ['navContext' => 'system_lookups'])
</aside>
<main>
<div class="header foodex-page-header">
<div>
<h1>{{ $ar ? 'إعدادات النظام / القيم المرجعية' : 'System Settings / Lookups' }}</h1>
<div class="muted">{{ $ar ? 'إدارة القيم التشغيلية بأكواد ثابتة لا تعتمد على النص المترجم. تعطيل القيمة يمنع اختيارها مستقبلاً بدون حذف التاريخ.' : 'Manage operational values by stable codes that never depend on translated labels. Deactivation prevents new selection without deleting history.' }}</div>
</div>
<div class="foodex-header-actions">
@include('admin._live-notifications', ['user' => auth()->user()])
</div>
</div>

@if (session('status'))
<div class="notice ok">{{ session('status') }}</div>
@endif

@if ($errors->any())
<div class="notice err">
@foreach ($errors->all() as $error)
<div>{{ $error }}</div>
@endforeach
</div>
@endif

<section class="card">
<nav class="tabs">
<a class="tab {{ $typeKey === 'payment-operation-types' ? 'active' : '' }}" href="{{ route('admin.operations.lookups.index', ['type' => 'payment-operation-types']) }}">{{ $ar ? 'أنواع عمليات الدفع' : 'Payment operation types' }}</a>
<a class="tab {{ $typeKey === 'payment-methods' ? 'active' : '' }}" href="{{ route('admin.operations.lookups.index', ['type' => 'payment-methods']) }}">{{ $ar ? 'طرق الدفع' : 'Payment methods' }}</a>
<a class="tab {{ $typeKey === 'pricing-tiers' ? 'active' : '' }}" href="{{ route('admin.operations.lookups.index', ['type' => 'pricing-tiers']) }}">{{ $ar ? 'شرائح التسعير' : 'Pricing tiers' }}</a>
<a class="tab {{ $typeKey === 'order-statuses' ? 'active' : '' }}" href="{{ route('admin.operations.lookups.index', ['type' => 'order-statuses']) }}">{{ $ar ? 'حالات الطلب' : 'Order statuses' }}</a>
<a class="tab {{ $typeKey === 'failed-delivery-reasons' ? 'active' : '' }}" href="{{ route('admin.operations.lookups.index', ['type' => 'failed-delivery-reasons']) }}">{{ $ar ? 'أسباب تعذر التوصيل' : 'Failed-delivery reasons' }}</a>
</nav>
</section>

<section class="card">
<form class="toolbar" method="get" action="{{ route('admin.operations.lookups.index') }}">
<input type="hidden" name="type" value="{{ $typeKey }}">
<label>
{{ $ar ? 'بحث' : 'Search' }}
<input class="wide" name="q" value="{{ $filters['q'] }}" placeholder="{{ $ar ? 'الكود أو الاسم' : 'Code or label' }}">
</label>
<label>
{{ $ar ? 'الحالة' : 'Status' }}
<select name="status">
<option value="all">{{ $ar ? 'الكل' : 'All' }}</option>
<option value="active" @selected($filters['status'] === 'active')>{{ $ar ? 'نشط' : 'Active' }}</option>
<option value="inactive" @selected($filters['status'] === 'inactive')>{{ $ar ? 'غير نشط' : 'Inactive' }}</option>
</select>
</label>
<button class="btn">{{ $ar ? 'تصفية' : 'Filter' }}</button>
</form>
</section>

@if ($canManage)
<section class="card">
<h2>{{ $ar ? 'إضافة قيمة' : 'Add value' }} · {{ $ar ? $definition['title_ar'] : $definition['title_en'] }}</h2>
<form method="post" action="{{ route('admin.operations.lookups.store', $typeKey) }}">
@csrf
<div class="form-grid">
<label>
{{ $ar ? 'الكود الثابت' : 'Immutable code' }}
<input name="code" value="{{ old('code') }}" required pattern="[A-Za-z0-9_-]+">
<span class="immutable">{{ $ar ? 'لا يمكن تغييره بعد الإنشاء.' : 'Cannot be changed after creation.' }}</span>
</label>
<label>{{ $ar ? 'الاسم بالعربية' : 'Arabic label' }}<input name="label_ar" value="{{ old('label_ar') }}" required></label>
<label>{{ $ar ? 'الاسم بالإنجليزية' : 'English label' }}<input name="label_en" value="{{ old('label_en') }}" required></label>
<label>{{ $ar ? 'الترتيب' : 'Sort order' }}<input type="number" name="sort_order" min="0" value="{{ old('sort_order', 10) }}" required></label>
<label><input type="checkbox" name="is_active" value="1" checked> {{ $ar ? 'نشط' : 'Active' }}</label>
<button class="btn primary">{{ $ar ? 'إضافة' : 'Add' }}</button>
</div>
</form>
</section>
@endif

<section class="card">
<div class="table-wrap">
<table class="table">
<thead>
<tr>
<th>ID</th>
<th>{{ $ar ? 'الكود' : 'Code' }}</th>
<th>{{ $ar ? 'العربية' : 'Arabic' }}</th>
<th>{{ $ar ? 'الإنجليزية' : 'English' }}</th>
<th>{{ $ar ? 'الترتيب' : 'Sort' }}</th>
<th>{{ $ar ? 'الحالة' : 'Status' }}</th>
@if ($canManage)
<th>{{ $ar ? 'تعديل' : 'Edit' }}</th>
@endif
</tr>
</thead>
<tbody>
@if ($records->isEmpty())
<tr>
<td colspan="{{ $canManage ? 7 : 6 }}">{{ $ar ? 'لا توجد قيم.' : 'No lookup values.' }}</td>
</tr>
@else
@foreach ($records as $record)
@php
    $isTier = $definition['type'] === \App\Services\OperationalLookupService::PRICE_TIER;
    $labelAr = $isTier ? ($record->name_ar ?? $record->name) : $record->label_ar;
    $labelEn = $isTier ? ($record->name_en ?? $record->name) : $record->label_en;
    $sort = $isTier ? $record->priority : $record->sort_order;
@endphp
<tr>
<td>{{ $record->id }}</td>
<td><span class="code">{{ $record->code }}</span><span class="immutable">{{ $ar ? 'ثابت' : 'immutable' }}</span></td>
<td>{{ $labelAr }}</td>
<td>{{ $labelEn }}</td>
<td>{{ $sort }}</td>
<td>
@if ($record->is_active)
<span class="badge on">{{ $ar ? 'نشط' : 'Active' }}</span>
@else
<span class="badge off">{{ $ar ? 'غير نشط' : 'Inactive' }}</span>
@endif
</td>
@if ($canManage)
<td>
<form method="post" action="{{ route('admin.operations.lookups.update', ['type' => $typeKey, 'lookup' => $record->id]) }}">
@csrf
@method('PATCH')
<div class="actions">
<input name="label_ar" value="{{ $labelAr }}" required aria-label="{{ $ar ? 'الاسم بالعربية' : 'Arabic label' }}">
<input name="label_en" value="{{ $labelEn }}" required aria-label="{{ $ar ? 'الاسم بالإنجليزية' : 'English label' }}">
<input type="number" name="sort_order" min="0" value="{{ $sort }}" required aria-label="{{ $ar ? 'الترتيب' : 'Sort order' }}">
<label>
<input type="checkbox" name="is_active" value="1" @checked($record->is_active)>
{{ $ar ? 'نشط' : 'Active' }}
</label>
<button class="btn">{{ $ar ? 'حفظ' : 'Save' }}</button>
</div>
</form>
</td>
@endif
</tr>
@endforeach
@endif
</tbody>
</table>
</div>
<div>{{ $records->links() }}</div>
</section>
</main>
</div>
</body>
</html>
