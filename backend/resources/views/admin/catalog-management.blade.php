<!doctype html>
<html lang="{{ app()->getLocale() }}" dir="{{ app()->getLocale()==='ar'?'rtl':'ltr' }}">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>{{ app()->getLocale()==='ar'?'إدارة الكتالوج':'Catalog Management' }} · FOODEX</title>
@include('admin._brand-components')
<style>
body{margin:0;background:var(--foodex-background);color:var(--foodex-ink)}
.catalog-layout{direction:ltr;display:grid;grid-template-columns:minmax(0,1fr) var(--foodex-sidebar-width);min-height:100vh}
.catalog-layout aside{grid-column:2;grid-row:1;direction:rtl;background:var(--foodex-surface);border-left:1px solid var(--foodex-border);padding:var(--foodex-space-5)}
.catalog-layout main{grid-column:1;grid-row:1;direction:rtl;padding:var(--foodex-space-6);min-width:0}
html[dir=ltr] .catalog-layout{grid-template-columns:var(--foodex-sidebar-width) minmax(0,1fr)}
html[dir=ltr] .catalog-layout aside{grid-column:1;direction:ltr;border-left:0;border-right:1px solid var(--foodex-border)}
html[dir=ltr] .catalog-layout main{grid-column:2;direction:ltr}
.header{display:flex;justify-content:space-between;gap:16px;align-items:center;margin-bottom:16px}
.header h1{margin:0}.muted{color:var(--foodex-muted)}
.tabs,.actions{display:flex;flex-wrap:wrap;gap:8px}.tabs{margin-bottom:16px}
.tabs a,.btn{border:1px solid var(--foodex-border);background:var(--foodex-surface);color:var(--foodex-ink);border-radius:10px;padding:9px 13px;text-decoration:none;font:inherit;cursor:pointer}
.tabs a.active,.btn.primary{background:var(--foodex-green);color:#fff;border-color:var(--foodex-green)}
.btn.danger{color:var(--foodex-red)}
.grid{display:grid;grid-template-columns:minmax(280px,360px) minmax(0,1fr);gap:16px}
.card{background:var(--foodex-surface);border:1px solid var(--foodex-border);border-radius:var(--foodex-radius-card);padding:16px;box-shadow:var(--foodex-shadow-sm)}
.form{display:grid;gap:10px}.form label{font-size:.85rem;font-weight:700}.form input,.form select,.form textarea{width:100%;box-sizing:border-box;border:1px solid var(--foodex-border);border-radius:10px;padding:10px;background:#fff;font:inherit}.form textarea{min-height:90px}
.row{display:grid;grid-template-columns:1fr 1fr;gap:10px}.check{display:flex;align-items:center;gap:8px}.check input{width:auto}
.table-wrap{overflow:auto}.table{width:100%;border-collapse:collapse;min-width:780px}.table th,.table td{padding:10px;border-bottom:1px solid var(--foodex-border);text-align:start;vertical-align:top}.table th{background:var(--foodex-background)}
.inline-form{display:flex;flex-wrap:wrap;gap:6px;align-items:center}.inline-form input,.inline-form select{min-width:90px;max-width:170px;padding:7px;border:1px solid var(--foodex-border);border-radius:8px}
.notice{padding:10px 12px;border-radius:10px;margin-bottom:12px}.ok{background:var(--foodex-green-soft);color:var(--foodex-green-dark)}.err{background:#fff1f0;color:var(--foodex-red)}

.image-thumb{width:64px;height:64px;border-radius:12px;object-fit:cover;border:1px solid var(--foodex-border);background:var(--foodex-background)}.image-grid{display:flex;flex-wrap:wrap;gap:8px;min-width:180px}.image-item{display:grid;gap:4px;justify-items:start}.image-item.primary .image-thumb{outline:2px solid var(--foodex-green)}.image-upload{min-width:220px}.foodex-upload-preview{display:flex;flex-wrap:wrap;gap:7px;margin-top:6px;min-height:0}.foodex-upload-preview img{width:64px;height:64px;object-fit:cover;border:1px solid var(--foodex-border);border-radius:10px;background:#fff}.foodex-upload-preview:empty{display:none}
.catalog-products-shell{display:grid;gap:12px}
.catalog-toolbar{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap}
.catalog-toolbar-group{display:flex;gap:8px;align-items:center;flex-wrap:wrap}
.catalog-count{display:inline-flex;align-items:center;gap:7px;padding:9px 13px;border:1px solid var(--foodex-border);background:var(--foodex-surface);border-radius:10px;font-weight:700}
.catalog-table-card{padding:0;overflow:visible}
.catalog-products-table{width:100%;border-collapse:separate;border-spacing:0;table-layout:fixed;min-width:900px}
.catalog-products-table th{padding:11px 12px;background:#f8fafc;color:var(--foodex-muted);font-size:.78rem;font-weight:800;border-bottom:1px solid var(--foodex-border);white-space:nowrap}
.catalog-products-table td{padding:9px 12px;border-bottom:1px solid var(--foodex-border);vertical-align:middle;font-size:.88rem}
.catalog-products-table tbody tr:last-child td{border-bottom:0}
.catalog-products-table tbody tr:hover{background:#fbfdfc}
.catalog-product-cell{display:flex;align-items:center;gap:10px;min-width:0}
.catalog-product-thumb{width:44px;height:44px;min-width:44px;border-radius:9px;object-fit:cover;border:1px solid var(--foodex-border);background:#fff}
.catalog-product-thumb-placeholder{width:44px;height:44px;min-width:44px;border-radius:9px;border:1px dashed var(--foodex-border);display:grid;place-items:center;color:var(--foodex-muted);font-size:.72rem;background:#fff}
.catalog-product-copy{min-width:0}
.catalog-product-name{font-weight:800;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.catalog-product-sub{color:var(--foodex-muted);font-size:.75rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;margin-top:2px}
.catalog-stock{display:inline-flex;align-items:center;gap:6px;white-space:nowrap}
.catalog-stock-dot{width:7px;height:7px;border-radius:50%;background:var(--foodex-green)}
.catalog-stock.out .catalog-stock-dot{background:var(--foodex-red)}
.catalog-status{display:inline-flex;align-items:center;gap:6px;padding:5px 9px;border-radius:999px;background:var(--foodex-green-soft);color:var(--foodex-green-dark);font-size:.76rem;font-weight:800;white-space:nowrap}
.catalog-status.off{background:#f3f4f6;color:#6b7280}
.catalog-status-dot{width:7px;height:7px;border-radius:50%;background:currentColor}
.catalog-money{font-weight:800;white-space:nowrap}
.catalog-currency{font-size:.72rem;color:var(--foodex-muted);margin-inline-start:3px}
.catalog-actions{position:relative;display:inline-block}
.catalog-actions summary{list-style:none;width:34px;height:34px;border:1px solid var(--foodex-border);border-radius:50%;display:grid;place-items:center;background:#f8fafc;cursor:pointer;font-size:20px;line-height:1}
.catalog-actions summary::-webkit-details-marker{display:none}
.catalog-actions[open] summary{background:#eef7f1;border-color:#cce8d6}
.catalog-action-menu{position:absolute;z-index:40;inset-inline-end:0;top:40px;width:200px;background:#fff;border:1px solid var(--foodex-border);border-radius:12px;box-shadow:0 12px 32px rgba(15,23,42,.14);padding:6px}
.catalog-action-menu button,.catalog-action-menu form button{width:100%;border:0;background:transparent;text-align:start;padding:9px 10px;border-radius:8px;font:inherit;cursor:pointer;color:var(--foodex-ink)}
.catalog-action-menu button:hover,.catalog-action-menu form button:hover{background:#f6f8fa}
.catalog-action-menu .danger-action{color:var(--foodex-red)}
.catalog-action-menu form{margin:0}
.catalog-dialog{width:min(720px,calc(100vw - 28px));max-height:88vh;border:0;border-radius:16px;padding:0;box-shadow:0 24px 70px rgba(15,23,42,.25)}
.catalog-dialog::backdrop{background:rgba(15,23,42,.42)}
.catalog-dialog-head{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:16px 18px;border-bottom:1px solid var(--foodex-border)}
.catalog-dialog-head h2{margin:0;font-size:1.05rem}
.catalog-dialog-body{padding:18px;overflow:auto;max-height:calc(88vh - 66px)}
.catalog-dialog-close{width:34px;height:34px;border:1px solid var(--foodex-border);background:#fff;border-radius:9px;cursor:pointer;font-size:20px}
.catalog-dialog .form{gap:12px}
.catalog-dialog .image-grid{min-width:0}
.catalog-dialog-actions{display:flex;justify-content:flex-end;gap:8px;margin-top:6px}
.catalog-empty{padding:38px 16px;text-align:center;color:var(--foodex-muted)}
@media(max-width:1200px){.catalog-col-brand,.catalog-col-category{display:none}.catalog-products-table{min-width:720px}}
@media(max-width:820px){.catalog-col-sku{display:none}.catalog-products-table{min-width:600px}.catalog-products-table th,.catalog-products-table td{padding:8px}.catalog-product-sub{display:none}}
@media(max-width:1023px){.grid{grid-template-columns:1fr}.catalog-layout,.catalog-layout[dir]{grid-template-columns:1fr}.catalog-layout aside,.catalog-layout main,html[dir=ltr] .catalog-layout aside,html[dir=ltr] .catalog-layout main{grid-column:1}.catalog-layout aside{grid-row:1;min-height:auto}.catalog-layout main{grid-row:2}}
</style>
</head>
<body>
<div class="catalog-layout">
<aside class="sidebar">@include('admin._sidebar',['navContext'=>$navContext])</aside>
<main>
<div class="header foodex-page-header"><div><h1>{{ app()->getLocale()==='ar'?($canManageStores?'إدارة الكتالوج والمتاجر':'إدارة الكتالوج'):($canManageStores?'Catalog & Store Management':'Catalog Management') }}</h1><div class="muted">{{ app()->getLocale()==='ar'?($canManageStores?'إدارة المنتجات والتصنيفات والمتاجر من نطاق المنصة.':'إدارة منتجات وتصنيفات المتجر المصرح فقط.') : ($canManageStores?'Manage catalog and store configuration from the platform control plane.':'Manage products and categories only for the authorized store.') }}</div></div><div class="foodex-header-actions">@include('admin._live-notifications',['user'=>auth()->user()])</div></div>
@if(session('status'))<div class="notice ok">{{ session('status') }}</div>@endif
@if($errors->any())<div class="notice err">@foreach($errors->all() as $error)<div>{{ $error }}</div>@endforeach</div>@endif
<nav class="tabs">
@foreach(['products'=>'المنتجات','categories'=>'التصنيفات'] as $key=>$ar)
<a class="{{ $tab===$key?'active':'' }}" href="{{ route('admin.catalog.index', array_merge(['tab'=>$key], $scopeParams)) }}">{{ app()->getLocale()==='ar'?$ar:ucfirst($key) }}</a>
@endforeach
<a class="{{ $tab==='import'?'active':'' }}" href="{{ route('admin.catalog.index', array_merge(['tab'=>'import'], $scopeParams)) }}">{{ app()->getLocale()==='ar'?'استيراد ZIP':'ZIP Import' }}</a>
@if($canManageStores)
<a class="{{ $tab==='stores'?'active':'' }}" href="{{ route('admin.catalog.index',['tab'=>'stores']) }}">{{ app()->getLocale()==='ar'?'المتاجر':'Stores' }}</a>
@endif
<a href="{{ route('admin.lookups.index', $scopeParams) }}">{{ app()->getLocale()==='ar'?'العلامات والوحدات':'Brands & Units' }}</a>
@if($inventoryUrl)<a href="{{ $inventoryUrl }}">{{ app()->getLocale()==='ar'?'إدارة المخزون':'Inventory Management' }}</a>@endif
</nav>

@if($tab==='products')
<div class="catalog-products-shell">
<div class="catalog-toolbar">
<div class="catalog-toolbar-group">
<button type="button" class="btn primary" data-dialog-open="catalog-add-product">＋ {{ app()->getLocale()==='ar'?'إضافة منتج':'Add Product' }}</button>
@if($inventoryUrl)<a class="btn" href="{{ $inventoryUrl }}">{{ app()->getLocale()==='ar'?'إدارة المخزون':'Inventory Management' }}</a>@endif
</div>
<div class="catalog-toolbar-group">
<span class="catalog-count">{{ $products->count() }} {{ app()->getLocale()==='ar'?'منتج':'products' }}</span>
</div>
</div>

<section class="card catalog-table-card table-wrap">
<table class="catalog-products-table">
<thead><tr>
<th style="width:28%">{{ app()->getLocale()==='ar'?'المنتج':'Product' }}</th>
<th class="catalog-col-category" style="width:13%">{{ app()->getLocale()==='ar'?'التصنيف':'Category' }}</th>
<th style="width:12%">{{ app()->getLocale()==='ar'?'السعر':'Price' }}</th>
<th style="width:12%">{{ app()->getLocale()==='ar'?'المخزون':'Stock' }}</th>
<th class="catalog-col-sku" style="width:12%">{{ app()->getLocale()==='ar'?'رمز المنتج':'SKU' }}</th>
<th class="catalog-col-brand" style="width:11%">{{ app()->getLocale()==='ar'?'العلامة التجارية':'Brand' }}</th>
<th style="width:8%">{{ app()->getLocale()==='ar'?'الحالة':'Status' }}</th>
<th style="width:4%">{{ app()->getLocale()==='ar'?'الإجراءات':'Actions' }}</th>
</tr></thead>
<tbody>
@forelse($products as $p)
@php($assignment=$assignments->get($p->catalog_store_id.':'.$p->id))
@php($currency=$currencies->get((int)$p->catalog_store_id))
@php($images=$productImages->get($p->id,collect()))
<tr>
<td>
<div class="catalog-product-cell">
@if($p->primary_image_path)
<img class="catalog-product-thumb" src="{{ url('/'.ltrim($p->primary_image_path,'/')) }}" alt="{{ $p->name }}">
@else
<div class="catalog-product-thumb-placeholder">{{ app()->getLocale()==='ar'?'بدون صورة':'No image' }}</div>
@endif
<div class="catalog-product-copy">
<div class="catalog-product-name" title="{{ $p->name }}">{{ $p->name }}</div>
@if($p->description)<div class="catalog-product-sub" title="{{ $p->description }}">{{ $p->description }}</div>@endif
</div>
</div>
</td>
<td class="catalog-col-category">{{ $p->category ?: '—' }}</td>
<td>
@if($assignment && $assignment->price !== null)
<span class="catalog-money">{{ number_format((float)$assignment->price,3) }}@if($currency)<span class="catalog-currency">{{ $currency }}</span>@endif</span>
@else<span class="muted">—</span>@endif
</td>
<td>
@if($p->is_available)
<span class="catalog-stock" data-availability-state="AVAILABLE"><span class="catalog-stock-dot"></span>{{ number_format((float)$p->available_quantity,3) }}</span>
@else
<span class="catalog-stock out" data-availability-state="OUT_OF_STOCK"><span class="catalog-stock-dot"></span>{{ app()->getLocale()==='ar'?'نفد':'Out' }}</span>
@endif
</td>
<td class="catalog-col-sku">{{ $p->sku }}</td>
<td class="catalog-col-brand">{{ $p->brand ?: '—' }}</td>
<td><span class="catalog-status {{ $p->is_active?'':'off' }}"><span class="catalog-status-dot"></span>{{ $p->is_active?(app()->getLocale()==='ar'?'نشط':'Active'):(app()->getLocale()==='ar'?'غير نشط':'Inactive') }}</span></td>
<td>
<details class="catalog-actions">
<summary aria-label="{{ app()->getLocale()==='ar'?'إجراءات المنتج':'Product actions' }}">⋮</summary>
<div class="catalog-action-menu">
<button type="button" data-dialog-open="catalog-edit-{{ $p->id }}">{{ app()->getLocale()==='ar'?'تعديل المنتج':'Edit product' }}</button>
<button type="button" data-dialog-open="catalog-price-{{ $p->id }}">{{ app()->getLocale()==='ar'?'إدارة السعر':'Manage price' }}</button>
<button type="button" data-dialog-open="catalog-images-{{ $p->id }}">{{ app()->getLocale()==='ar'?'إدارة الصور':'Manage images' }}</button>
<form method="post" action="{{ route('admin.catalog.products.destroy',$p->id) }}" onsubmit="return confirm('{{ app()->getLocale()==='ar'?'تأكيد الحذف؟':'Delete product?' }}')">@csrf @method('DELETE')<button class="danger-action">{{ app()->getLocale()==='ar'?'حذف المنتج':'Delete product' }}</button></form>
</div>
</details>
</td>
</tr>
@empty
<tr><td colspan="8"><div class="catalog-empty">{{ app()->getLocale()==='ar'?'لا توجد منتجات.':'No products yet.' }}</div></td></tr>
@endforelse
</tbody>
</table>
</section>
</div>

<dialog class="catalog-dialog" id="catalog-add-product">
<div class="catalog-dialog-head"><h2>{{ app()->getLocale()==='ar'?'إضافة منتج':'Add product' }}</h2><button type="button" class="catalog-dialog-close" data-dialog-close>×</button></div>
<div class="catalog-dialog-body">
<form class="form" method="post" enctype="multipart/form-data" action="{{ route('admin.catalog.products.store') }}">@csrf
<div class="row"><label>{{ app()->getLocale()==='ar'?'رمز المنتج':'SKU' }}<input name="sku" placeholder="{{ app()->getLocale()==='ar'?'مثال: PROD-001':'e.g. PROD-001' }}" required></label><label>{{ app()->getLocale()==='ar'?'الاسم':'Name' }}<input name="name" placeholder="{{ app()->getLocale()==='ar'?'اسم المنتج':'Product name' }}" required></label></div>
<label>{{ app()->getLocale()==='ar'?'الوصف':'Description' }}<textarea name="description" placeholder="{{ app()->getLocale()==='ar'?'وصف مختصر وواضح للمنتج':'Short product description' }}"></textarea></label>
<label>{{ app()->getLocale()==='ar'?'المتجر المالك':'Owning store' }}<select name="store_id" required>@foreach($stores as $s)<option value="{{ $s->id }}">{{ $s->name }}</option>@endforeach</select></label>
<div class="row"><label>{{ app()->getLocale()==='ar'?'التصنيف':'Category' }}<select name="category_id"><option value="">—</option>@foreach($categories as $c)<option value="{{ $c->id }}">{{ $c->name }} — {{ $c->catalog_store_name }}</option>@endforeach</select></label>
<label>{{ app()->getLocale()==='ar'?'العلامة':'Brand' }}<select name="brand_id"><option value="">—</option>@foreach($brands as $b)<option value="{{ $b->id }}">{{ $b->name }}</option>@endforeach</select></label></div>
<div class="row"><label>{{ app()->getLocale()==='ar'?'الوحدة':'Unit' }}<select name="unit_id" required>@foreach($units as $u)<option value="{{ $u->id }}">{{ $u->name }} ({{ $u->code }})</option>@endforeach</select></label>
<label>{{ app()->getLocale()==='ar'?'السعر':'Price' }}<input type="number" step=".001" min="0" name="price" placeholder="{{ app()->getLocale()==='ar'?'السعر':'Price' }}"></label></div>
<label>{{ app()->getLocale()==='ar'?'صور المنتج (حتى 8 صور)':'Product images (up to 8)' }}<input type="file" name="images[]" accept="image/jpeg,image/png,image/webp" multiple class="js-catalog-image-preview"></label>
<label class="check"><input type="checkbox" name="is_active" value="1" checked>{{ app()->getLocale()==='ar'?'نشط':'Active' }}</label>
<div class="catalog-dialog-actions"><button type="button" class="btn" data-dialog-close>{{ app()->getLocale()==='ar'?'إلغاء':'Cancel' }}</button><button class="btn primary">{{ app()->getLocale()==='ar'?'إضافة المنتج':'Add product' }}</button></div>
</form>
</div>
</dialog>

@foreach($products as $p)
@php($images=$productImages->get($p->id,collect()))
<dialog class="catalog-dialog" id="catalog-edit-{{ $p->id }}">
<div class="catalog-dialog-head"><h2>{{ app()->getLocale()==='ar'?'تعديل المنتج':'Edit product' }} — {{ $p->name }}</h2><button type="button" class="catalog-dialog-close" data-dialog-close>×</button></div>
<div class="catalog-dialog-body">
<form class="form" method="post" enctype="multipart/form-data" action="{{ route('admin.catalog.products.update',$p->id) }}">@csrf @method('PATCH')
<div class="row"><label>{{ app()->getLocale()==='ar'?'رمز المنتج':'SKU' }}<input name="sku" value="{{ $p->sku }}" required></label><label>{{ app()->getLocale()==='ar'?'الاسم':'Name' }}<input name="name" value="{{ $p->name }}" required></label></div>
<label>{{ app()->getLocale()==='ar'?'الوصف':'Description' }}<textarea name="description">{{ $p->description }}</textarea></label>
<div class="row">
<label>{{ app()->getLocale()==='ar'?'التصنيف':'Category' }}<select name="category_id"><option value="">—</option>@foreach($categories as $c)@if((int)$c->catalog_id === (int)$p->catalog_id || (int)$c->id === (int)$p->category_id)<option value="{{ $c->id }}" @selected($p->category_id==$c->id)>{{ $c->name }}</option>@endif @endforeach</select></label>
<label>{{ app()->getLocale()==='ar'?'العلامة':'Brand' }}<select name="brand_id"><option value="">—</option>@foreach($brands as $b)@php($scope=(string)($b->scope ?? 'global'))@if((int)$b->id === (int)$p->brand_id || $scope === 'global' || ($scope === 'b2b' && strtolower((string)$p->catalog_channel) === 'b2b') || ($scope === 'store' && strtolower((string)$p->catalog_channel) === 'b2c' && (int)$b->store_id === (int)$p->catalog_store_id))<option value="{{ $b->id }}" @selected($p->brand_id==$b->id)>{{ $b->name }}</option>@endif @endforeach</select></label>
</div>
<label>{{ app()->getLocale()==='ar'?'الوحدة':'Unit' }}<select name="unit_id">@foreach($units as $u)@php($scope=(string)($u->scope ?? 'global'))@if((int)$u->id === (int)$p->unit_id || $scope === 'global' || ($scope === 'b2b' && strtolower((string)$p->catalog_channel) === 'b2b') || ($scope === 'store' && strtolower((string)$p->catalog_channel) === 'b2c' && (int)$u->store_id === (int)$p->catalog_store_id))<option value="{{ $u->id }}" @selected($p->unit_id==$u->id)>{{ $u->name }}</option>@endif @endforeach</select></label>
<label>{{ app()->getLocale()==='ar'?'إضافة صور':'Add images' }}<input class="image-upload js-catalog-image-preview" type="file" name="images[]" accept="image/jpeg,image/png,image/webp" multiple></label>
<label class="check"><input type="checkbox" name="is_active" value="1" @checked($p->is_active)>{{ app()->getLocale()==='ar'?'نشط':'Active' }}</label>
<div class="catalog-dialog-actions"><button type="button" class="btn" data-dialog-close>{{ app()->getLocale()==='ar'?'إلغاء':'Cancel' }}</button><button class="btn primary">{{ app()->getLocale()==='ar'?'حفظ':'Save' }}</button></div>
</form>
</div>
</dialog>

<dialog class="catalog-dialog" id="catalog-price-{{ $p->id }}">
<div class="catalog-dialog-head"><h2>{{ app()->getLocale()==='ar'?'إدارة السعر':'Manage price' }} — {{ $p->name }}</h2><button type="button" class="catalog-dialog-close" data-dialog-close>×</button></div>
<div class="catalog-dialog-body">
<form class="form" method="post" action="{{ route('admin.catalog.products.assign',$p->id) }}">@csrf
<label>{{ app()->getLocale()==='ar'?'المتجر':'Store' }}<select name="store_id" required>@foreach($stores->where('id',$p->catalog_store_id) as $s)<option value="{{ $s->id }}">{{ $s->name }}</option>@endforeach</select></label>
<label>{{ app()->getLocale()==='ar'?'السعر':'Price' }}<input type="number" step=".001" min="0" name="price" value="{{ optional($assignments->get($p->catalog_store_id.':'.$p->id))->price }}" placeholder="{{ app()->getLocale()==='ar'?'السعر':'Price' }}"></label>
<input type="hidden" name="is_active" value="1">
<div class="catalog-dialog-actions"><button type="button" class="btn" data-dialog-close>{{ app()->getLocale()==='ar'?'إلغاء':'Cancel' }}</button><button class="btn primary">{{ app()->getLocale()==='ar'?'حفظ السعر':'Save price' }}</button></div>
</form>
</div>
</dialog>

<dialog class="catalog-dialog" id="catalog-images-{{ $p->id }}">
<div class="catalog-dialog-head"><h2>{{ app()->getLocale()==='ar'?'إدارة الصور':'Manage images' }} — {{ $p->name }}</h2><button type="button" class="catalog-dialog-close" data-dialog-close>×</button></div>
<div class="catalog-dialog-body">
<div class="image-grid">
@forelse($images as $image)
<div class="image-item {{ $image->is_primary?'primary':'' }}">
<img class="image-thumb" src="{{ url('/'.ltrim($image->path,'/')) }}" alt="{{ $p->name }}">
<form class="inline-form" method="post" action="{{ route('admin.catalog.products.images.update',[$p->id,$image->id]) }}">@csrf @method('PATCH')
<input type="number" min="0" max="9999" name="sort_order" value="{{ $image->sort_order }}" title="{{ app()->getLocale()==='ar'?'الترتيب':'Order' }}">
<label class="check"><input type="checkbox" name="is_primary" value="1" @checked($image->is_primary)>{{ app()->getLocale()==='ar'?'أساسية':'Primary' }}</label>
<button class="btn">{{ app()->getLocale()==='ar'?'حفظ':'Save' }}</button>
</form>
<form method="post" action="{{ route('admin.catalog.products.images.destroy',[$p->id,$image->id]) }}">@csrf @method('DELETE')<button class="btn danger">{{ app()->getLocale()==='ar'?'حذف الصورة':'Remove' }}</button></form>
</div>
@empty<span class="muted">{{ app()->getLocale()==='ar'?'لا توجد صور':'No images' }}</span>@endforelse
</div>
</div>
</dialog>
@endforeach

@elseif($tab==='import')
@php($importPreview=session('catalog_import_preview'))
@php($importResult=session('catalog_import_result'))
<div class="grid">
<section class="card">
<h2>{{ app()->getLocale()==='ar'?'استيراد الكتالوج من ZIP':'Catalog ZIP Import' }}</h2>
<p class="muted">{{ app()->getLocale()==='ar'?'الاستيراد يمر أولاً بمعاينة كاملة بدون حفظ أي بيانات. بعد نجاح التحقق يظهر زر التأكيد النهائي.':'Import always starts with a full validation preview. Nothing is persisted until validation passes and you confirm the final import.' }}</p>
<ol>
<li>{{ app()->getLocale()==='ar'?'حمّل ملف العينة الجاهز.':'Download the ready-to-use sample package.' }}</li>
<li>{{ app()->getLocale()==='ar'?'عدّل catalog.xlsx مع الحفاظ على أسماء أوراق Products وCategories وBrands.':'Edit catalog.xlsx without renaming the Products, Categories, or Brands sheets.' }}</li>
<li>{{ app()->getLocale()==='ar'?'ضع الصور في مجلد products أو categories أو brands المخصص داخل images.':'Place images in the matching images/products, images/categories, or images/brands folder.' }}</li>
<li>{{ app()->getLocale()==='ar'?'ارفع ZIP للمعاينة، راجع الأخطاء، ثم أكد الاستيراد فقط بعد نجاح التحقق.':'Upload the ZIP for preview, review any errors, then confirm only after validation succeeds.' }}</li>
</ol>
<p><a class="btn" href="{{ route('admin.catalog.import.sample', $scopeParams) }}">{{ app()->getLocale()==='ar'?'تحميل ZIP العينة':'Download sample ZIP' }}</a></p>
<form class="form" method="post" enctype="multipart/form-data" action="{{ route('admin.catalog.import.preview') }}">@csrf
@if(!empty($scopeParams['support_access']))<input type="hidden" name="support_access" value="1">@endif
<label>{{ app()->getLocale()==='ar'?'المتجر الهدف':'Target store' }}<select name="store_id" required>@foreach($stores as $s)<option value="{{ $s->id }}" @selected((int)request('store_id')===(int)$s->id)>{{ $s->name }} — {{ $s->type_code }}</option>@endforeach</select></label>
<label>{{ app()->getLocale()==='ar'?'ملف الكتالوج ZIP':'Catalog ZIP package' }}<input type="file" name="catalog_zip" accept=".zip,application/zip" required></label>
<button class="btn primary">{{ app()->getLocale()==='ar'?'فحص ومعاينة':'Validate & Preview' }}</button>
</form>
</section>

<section class="card">
<h2>{{ app()->getLocale()==='ar'?'نتيجة المعاينة':'Preview Result' }}</h2>
@if($importPreview)
<div class="row">
<div><strong>{{ (int)($importPreview['counts']['products'] ?? 0) }}</strong><div class="muted">{{ app()->getLocale()==='ar'?'منتجات':'Products' }}</div></div>
<div><strong>{{ (int)($importPreview['counts']['categories'] ?? 0) }}</strong><div class="muted">{{ app()->getLocale()==='ar'?'تصنيفات':'Categories' }}</div></div>
<div><strong>{{ (int)($importPreview['counts']['brands'] ?? 0) }}</strong><div class="muted">{{ app()->getLocale()==='ar'?'علامات':'Brands' }}</div></div>
<div><strong>{{ (int)($importPreview['counts']['images'] ?? 0) }}</strong><div class="muted">{{ app()->getLocale()==='ar'?'صور':'Images' }}</div></div>
</div>
@if(!empty($importPreview['errors']))
<div class="notice err"><strong>{{ app()->getLocale()==='ar'?'يجب إصلاح الأخطاء التالية وإعادة رفع الملف:':'Fix these errors and upload the package again:' }}</strong>
<ul>@foreach($importPreview['errors'] as $error)<li>{{ $error }}</li>@endforeach</ul>
</div>
@else
<div class="notice ok">{{ app()->getLocale()==='ar'?'التحقق ناجح. لم يتم حفظ أي بيانات حتى الآن. يمكنك تأكيد الاستيراد.':'Validation passed. No catalog data has been persisted yet. You can now confirm the import.' }}</div>
@if(!empty($importPreview['warnings']))
<div class="notice"><strong>{{ app()->getLocale()==='ar'?'تنبيهات:':'Warnings:' }}</strong><ul>@foreach($importPreview['warnings'] as $warning)<li>{{ $warning }}</li>@endforeach</ul></div>
@endif
@if(!empty($importPreview['token']))
<form method="post" action="{{ route('admin.catalog.import.commit') }}" onsubmit="return confirm('{{ app()->getLocale()==='ar'?'تأكيد الاستيراد النهائي؟':'Confirm final catalog import?' }}')">@csrf
@if(!empty($scopeParams['support_access']))<input type="hidden" name="support_access" value="1">@endif
<input type="hidden" name="preview_token" value="{{ $importPreview['token'] }}">
<button class="btn primary">{{ app()->getLocale()==='ar'?'تأكيد الاستيراد':'Confirm Import' }}</button>
</form>
@endif
@endif
@else
<p class="muted">{{ app()->getLocale()==='ar'?'ارفع الحزمة أولاً لعرض عدد الصفوف والصور وأي أخطاء قبل الاستيراد.':'Upload a package to see row/image counts and validation errors before import.' }}</p>
@endif

@if($importResult)
<hr>
<h3>{{ app()->getLocale()==='ar'?'آخر نتيجة استيراد':'Latest Import Result' }}</h3>
<div class="row">
<div>{{ app()->getLocale()==='ar'?'منتجات جديدة':'Products created' }}: <strong>{{ (int)($importResult['counts']['products_created'] ?? 0) }}</strong></div>
<div>{{ app()->getLocale()==='ar'?'منتجات محدثة':'Products updated' }}: <strong>{{ (int)($importResult['counts']['products_updated'] ?? 0) }}</strong></div>
<div>{{ app()->getLocale()==='ar'?'تصنيفات جديدة':'Categories created' }}: <strong>{{ (int)($importResult['counts']['categories_created'] ?? 0) }}</strong></div>
<div>{{ app()->getLocale()==='ar'?'تصنيفات محدثة':'Categories updated' }}: <strong>{{ (int)($importResult['counts']['categories_updated'] ?? 0) }}</strong></div>
<div>{{ app()->getLocale()==='ar'?'علامات جديدة':'Brands created' }}: <strong>{{ (int)($importResult['counts']['brands_created'] ?? 0) }}</strong></div>
<div>{{ app()->getLocale()==='ar'?'علامات محدثة':'Brands updated' }}: <strong>{{ (int)($importResult['counts']['brands_updated'] ?? 0) }}</strong></div>
</div>
@if(!empty($importResult['media_errors']))
<div class="notice err"><strong>{{ app()->getLocale()==='ar'?'أخطاء حفظ الصور:':'Media persistence errors:' }}</strong><ul>@foreach($importResult['media_errors'] as $error)<li>{{ $error }}</li>@endforeach</ul></div>
@endif
@endif
</section>
</div>

@elseif($tab==='categories')
<div class="grid"><section class="card"><h2>{{ app()->getLocale()==='ar'?'إضافة تصنيف':'Add category' }}</h2><form class="form" method="post" enctype="multipart/form-data" action="{{ route('admin.catalog.categories.store') }}">@csrf
<label>{{ app()->getLocale()==='ar'?'المتجر المالك':'Owning store' }}<select name="store_id" required>@foreach($stores as $s)<option value="{{ $s->id }}">{{ $s->name }}</option>@endforeach</select></label><label>{{ app()->getLocale()==='ar'?'الاسم':'Name' }}<input name="name" placeholder="{{ app()->getLocale()==='ar'?'أدخل الاسم':'Enter name' }}" required></label><label>{{ app()->getLocale()==='ar'?'المعرّف النصي':'Slug' }}<input name="slug" placeholder="{{ app()->getLocale()==='ar'?'مثال: beverages':'e.g. beverages' }}"></label><label>{{ app()->getLocale()==='ar'?'صورة التصنيف':'Category image' }}<input type="file" name="category_image" accept="image/jpeg,image/png,image/webp" class="js-catalog-image-preview"></label><label>{{ app()->getLocale()==='ar'?'التصنيف الأب':'Parent' }}<select name="parent_id"><option value="">—</option>@foreach($categories as $c)<option value="{{ $c->id }}">{{ $c->name }}</option>@endforeach</select></label><label class="check"><input type="checkbox" name="is_active" value="1" checked>{{ app()->getLocale()==='ar'?'نشط':'Active' }}</label><button class="btn primary">{{ app()->getLocale()==='ar'?'إضافة التصنيف':'Add category' }}</button></form></section>
<section class="card table-wrap"><table class="table"><thead><tr><th>{{ app()->getLocale()==='ar'?'الصورة':'Image' }}</th><th>{{ app()->getLocale()==='ar'?'الاسم':'Name' }}</th><th>{{ app()->getLocale()==='ar'?'المعرّف النصي':'Slug' }}</th><th>{{ app()->getLocale()==='ar'?'الأب':'Parent' }}</th><th>{{ app()->getLocale()==='ar'?'إجراءات':'Actions' }}</th></tr></thead><tbody>@forelse($categories as $c)<tr><td>@if($c->image_path)<img class="image-thumb" src="{{ url('/'.ltrim($c->image_path,'/')) }}" alt="{{ $c->name }}">@else<span class="muted">—</span>@endif</td><td>{{ $c->name }}</td><td>{{ $c->slug }}</td><td>{{ $c->parent_name ?: '—' }}</td><td><form class="inline-form" method="post" enctype="multipart/form-data" action="{{ route('admin.catalog.categories.update',$c->id) }}">@csrf @method('PATCH')<input name="name" value="{{ $c->name }}" placeholder="{{ app()->getLocale()==='ar'?'اسم التصنيف':'Category name' }}" placeholder="{{ app()->getLocale()==='ar'?'اسم التصنيف':'Category name' }}"><input name="slug" value="{{ $c->slug }}" placeholder="{{ app()->getLocale()==='ar'?'مثال: beverages':'e.g. beverages' }}"><input class="image-upload js-catalog-image-preview" type="file" name="category_image" accept="image/jpeg,image/png,image/webp"><select name="parent_id"><option value="">—</option>@foreach($categories->where('id','!=',$c->id) as $parent)<option value="{{ $parent->id }}" @selected($c->parent_id==$parent->id)>{{ $parent->name }}</option>@endforeach</select><label class="check"><input type="checkbox" name="remove_image" value="1">{{ app()->getLocale()==='ar'?'حذف الصورة':'Remove image' }}</label><label class="check"><input type="checkbox" name="is_active" value="1" @checked($c->is_active)>{{ app()->getLocale()==='ar'?'نشط':'Active' }}</label><button class="btn">{{ app()->getLocale()==='ar'?'حفظ':'Save' }}</button></form><form method="post" action="{{ route('admin.catalog.categories.destroy',$c->id) }}" style="margin-top:6px">@csrf @method('DELETE')<button class="btn danger">{{ app()->getLocale()==='ar'?'حذف':'Delete' }}</button></form></td></tr>@empty<tr><td colspan="5">{{ app()->getLocale()==='ar'?'لا توجد تصنيفات.':'No categories.' }}</td></tr>@endforelse</tbody></table></section></div>

@elseif($tab==='brands')
<div class="grid"><section class="card"><h2>{{ app()->getLocale()==='ar'?'إضافة علامة تجارية':'Add brand' }}</h2><form class="form" method="post" action="{{ route('admin.catalog.brands.store') }}">@csrf<label>{{ app()->getLocale()==='ar'?'الاسم':'Name' }}<input name="name" placeholder="{{ app()->getLocale()==='ar'?'أدخل الاسم':'Enter name' }}" required></label><label>{{ app()->getLocale()==='ar'?'المعرّف النصي':'Slug' }}<input name="slug" placeholder="{{ app()->getLocale()==='ar'?'مثال: beverages':'e.g. beverages' }}"></label><button class="btn primary">{{ app()->getLocale()==='ar'?'إضافة':'Add' }}</button></form></section><section class="card"><table class="table"><tbody>@foreach($brands as $b)<tr><td>{{ $b->name }}</td><td>{{ $b->slug }}</td><td><form method="post" action="{{ route('admin.catalog.brands.destroy',$b->id) }}">@csrf @method('DELETE')<button class="btn danger">{{ app()->getLocale()==='ar'?'حذف':'Delete' }}</button></form></td></tr>@endforeach</tbody></table></section></div>

@elseif($tab==='units')
<div class="grid"><section class="card"><h2>{{ app()->getLocale()==='ar'?'إضافة وحدة قياس':'Add unit' }}</h2><form class="form" method="post" action="{{ route('admin.catalog.units.store') }}">@csrf<label>{{ app()->getLocale()==='ar'?'الكود':'Code' }}<input name="code" placeholder="{{ app()->getLocale()==='ar'?'مثال: PCS':'e.g. PCS' }}" required></label><label>{{ app()->getLocale()==='ar'?'الاسم':'Name' }}<input name="name" placeholder="{{ app()->getLocale()==='ar'?'أدخل الاسم':'Enter name' }}" required></label><label>{{ app()->getLocale()==='ar'?'المنازل العشرية':'Decimal places' }}<input type="number" min="0" max="6" name="decimal_places" value="0" placeholder="0" required></label><button class="btn primary">{{ app()->getLocale()==='ar'?'إضافة':'Add' }}</button></form></section><section class="card"><table class="table"><thead><tr><th>{{ app()->getLocale()==='ar'?'الكود':'Code' }}</th><th>{{ app()->getLocale()==='ar'?'الاسم':'Name' }}</th><th>{{ app()->getLocale()==='ar'?'الدقة':'Precision' }}</th></tr></thead><tbody>@foreach($units as $u)<tr><td>{{ $u->code }}</td><td>{{ $u->name }}</td><td>{{ $u->decimal_places }}</td></tr>@endforeach</tbody></table></section></div>

@elseif($tab==='stores' && $canManageStores)
<div class="grid"><section class="card"><h2>{{ app()->getLocale()==='ar'?'إضافة متجر':'Add store' }}</h2><form class="form" method="post" action="{{ route('admin.catalog.stores.store') }}">@csrf<label>{{ app()->getLocale()==='ar'?'النوع':'Type' }}<select name="store_type_id">@foreach($storeTypes as $t)<option value="{{ $t->id }}">{{ $t->code }} — {{ $t->name }}</option>@endforeach</select></label><label>{{ app()->getLocale()==='ar'?'الكود':'Code' }}<input name="code" placeholder="{{ app()->getLocale()==='ar'?'مثال: PCS':'e.g. PCS' }}" required></label><label>{{ app()->getLocale()==='ar'?'الاسم':'Name' }}<input name="name" placeholder="{{ app()->getLocale()==='ar'?'أدخل الاسم':'Enter name' }}" required></label><label class="check"><input type="checkbox" name="is_active" value="1" checked>{{ app()->getLocale()==='ar'?'نشط':'Active' }}</label><button class="btn primary">{{ app()->getLocale()==='ar'?'إضافة المتجر':'Add store' }}</button></form></section><section class="card table-wrap"><table class="table"><thead><tr><th>{{ app()->getLocale()==='ar'?'النوع':'Type' }}</th><th>{{ app()->getLocale()==='ar'?'الكود':'Code' }}</th><th>{{ app()->getLocale()==='ar'?'الاسم':'Name' }}</th><th>{{ app()->getLocale()==='ar'?'تعديل':'Edit' }}</th></tr></thead><tbody>@foreach($stores as $s)<tr><td>{{ $s->type_code }}</td><td>{{ $s->code }}</td><td>{{ $s->name }}</td><td><form class="inline-form" method="post" action="{{ route('admin.catalog.stores.update',$s->id) }}">@csrf @method('PATCH')<select name="store_type_id">@foreach($storeTypes as $t)<option value="{{ $t->id }}" @selected($s->store_type_id==$t->id)>{{ $t->code }}</option>@endforeach</select><input name="code" value="{{ $s->code }}" placeholder="{{ app()->getLocale()==='ar'?'مثال: STORE-01':'e.g. STORE-01' }}" placeholder="{{ app()->getLocale()==='ar'?'مثال: STORE-01':'e.g. STORE-01' }}"><input name="name" value="{{ $s->name }}" placeholder="{{ app()->getLocale()==='ar'?'اسم المتجر':'Store name' }}" placeholder="{{ app()->getLocale()==='ar'?'اسم المتجر':'Store name' }}"><label class="check"><input type="checkbox" name="is_active" value="1" @checked($s->is_active)>{{ app()->getLocale()==='ar'?'نشط':'Active' }}</label><button class="btn">{{ app()->getLocale()==='ar'?'حفظ':'Save' }}</button></form></td></tr>@endforeach</tbody></table></section></div>
@endif
</main></div><script>
(() => {
    document.querySelectorAll('.js-catalog-image-preview').forEach((input) => {
        const preview = document.createElement('div');
        preview.className = 'foodex-upload-preview';
        input.insertAdjacentElement('afterend', preview);
        input.addEventListener('change', () => {
            preview.replaceChildren();
            Array.from(input.files || []).forEach((file) => {
                if (!file.type.startsWith('image/')) return;
                const image = document.createElement('img');
                image.alt = file.name;
                image.src = URL.createObjectURL(file);
                image.addEventListener('load', () => URL.revokeObjectURL(image.src), {once:true});
                preview.appendChild(image);
            });
        });
    });

    document.querySelectorAll('[data-dialog-open]').forEach((button) => {
        button.addEventListener('click', () => {
            const dialog = document.getElementById(button.dataset.dialogOpen);
            if (dialog && typeof dialog.showModal === 'function') dialog.showModal();
            button.closest('details')?.removeAttribute('open');
        });
    });

    document.querySelectorAll('[data-dialog-close]').forEach((button) => {
        button.addEventListener('click', () => button.closest('dialog')?.close());
    });

    document.querySelectorAll('.catalog-dialog').forEach((dialog) => {
        dialog.addEventListener('click', (event) => {
            if (event.target === dialog) dialog.close();
        });
    });

    document.addEventListener('click', (event) => {
        document.querySelectorAll('.catalog-actions[open]').forEach((menu) => {
            if (!menu.contains(event.target)) menu.removeAttribute('open');
        });
    });
})();
</script>
</body></html>
