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
.catalog-page-header{direction:ltr;display:grid;grid-template-columns:minmax(0,1fr) auto;gap:18px;align-items:start}
.catalog-page-copy{direction:rtl;min-width:270px;text-align:right}
html[dir=ltr] .catalog-page-header{grid-template-columns:auto minmax(0,1fr)}
html[dir=ltr] .catalog-page-copy{direction:ltr;text-align:left;grid-column:1;grid-row:1}
html[dir=ltr] .catalog-header-filters{grid-column:2;grid-row:1}
.tabs,.actions{display:flex;flex-wrap:wrap;gap:8px}.tabs{margin-bottom:16px}
.tabs a,.btn{border:1px solid var(--foodex-border);background:var(--foodex-surface);color:var(--foodex-ink);border-radius:10px;padding:9px 13px;text-decoration:none;font:inherit;cursor:pointer}
.tabs a.active,.btn.primary{background:var(--foodex-action-green);color:#fff;border-color:var(--foodex-action-green)}
.catalog-tabs-row{direction:ltr;display:grid;grid-template-columns:auto minmax(0,1fr) auto;gap:12px;align-items:center;margin-bottom:16px}
.catalog-tabs-row .tabs{direction:rtl;margin-bottom:0;min-width:0;justify-content:flex-end}
html[dir=ltr] .catalog-tabs-row .tabs{direction:ltr;justify-content:flex-start}
.catalog-tabs-meta{display:flex;justify-content:flex-start;min-width:0}
.catalog-tabs-action{display:flex;justify-content:flex-end;min-width:0}
.catalog-tabs-add{white-space:nowrap}
.btn.danger{color:var(--foodex-danger-text)}
.grid{display:grid;grid-template-columns:minmax(280px,360px) minmax(0,1fr);gap:16px}
.card{background:var(--foodex-surface);border:1px solid var(--foodex-border);border-radius:var(--foodex-radius-card);padding:16px;box-shadow:var(--foodex-shadow-sm)}
.form{display:grid;gap:10px}.form label{font-size:.85rem;font-weight:700}.form input,.form select,.form textarea{width:100%;box-sizing:border-box;border:1px solid var(--foodex-border);border-radius:10px;padding:10px;background:#fff;font:inherit}.form textarea{min-height:90px}
.row{display:grid;grid-template-columns:1fr 1fr;gap:10px}.check{display:flex;align-items:center;gap:8px}.check input{width:auto}
.table-wrap{overflow:auto}.table{width:100%;border-collapse:collapse;min-width:780px}.table th,.table td{padding:10px;border-bottom:1px solid var(--foodex-border);text-align:start;vertical-align:top}.table th{background:var(--foodex-background)}
.inline-form{display:flex;flex-wrap:wrap;gap:6px;align-items:center}.inline-form input,.inline-form select{min-width:90px;max-width:170px;padding:7px;border:1px solid var(--foodex-border);border-radius:8px}
.notice{padding:10px 12px;border-radius:10px;margin-bottom:12px}.ok{background:var(--foodex-green-soft);color:var(--foodex-green-dark)}.err{background:#fff1f0;color:var(--foodex-danger-text)}

.image-thumb{width:64px;height:64px;border-radius:12px;object-fit:cover;border:1px solid var(--foodex-border);background:var(--foodex-background)}.image-grid{display:flex;flex-wrap:wrap;gap:8px;min-width:180px}.image-item{display:grid;gap:4px;justify-items:start}.image-item.primary .image-thumb{outline:2px solid var(--foodex-green)}.image-upload{min-width:220px}.foodex-upload-preview{display:flex;flex-wrap:wrap;gap:7px;margin-top:6px;min-height:0}.foodex-upload-preview img{width:64px;height:64px;object-fit:cover;border:1px solid var(--foodex-border);border-radius:10px;background:#fff}.foodex-upload-preview:empty{display:none}
.catalog-grid-image{display:flex;align-items:center;gap:5px;min-width:70px}.catalog-grid-image-form{margin:0;display:flex;align-items:center}.catalog-grid-image-input{position:absolute!important;width:1px!important;height:1px!important;opacity:0!important;overflow:hidden!important;pointer-events:none}.catalog-grid-image-trigger{display:inline-flex;align-items:center;justify-content:center;width:22px;height:22px;min-width:22px;padding:0;border:1px solid var(--foodex-border);border-radius:50%;background:#fff;color:var(--foodex-ink);font-size:.72rem;font-weight:900;cursor:pointer;line-height:1}.catalog-grid-image-trigger:hover{background:var(--foodex-background);border-color:var(--foodex-green)}.catalog-grid-image-form.is-uploading .catalog-grid-image-trigger{opacity:.6;pointer-events:none}.catalog-grid-image-note{font-size:.68rem;color:var(--foodex-muted)}
.catalog-sort-link{display:inline-flex;align-items:center;gap:5px;color:inherit;text-decoration:none}.catalog-sort-link:hover{color:var(--foodex-green-dark)}.catalog-sort-arrow{font-size:.68rem;opacity:.7}
.catalog-inline-price-form,.catalog-inline-form{display:flex;align-items:center;gap:5px;min-width:0}.catalog-inline-price-input,.catalog-inline-input,.catalog-inline-select{width:100%;min-width:0;padding:6px 7px;border:1px solid var(--foodex-border);border-radius:8px;background:#fff;font:inherit;font-weight:700;box-sizing:border-box}.catalog-inline-price-input{width:92px;font-weight:800}.catalog-inline-input:focus,.catalog-inline-select:focus,.catalog-inline-price-input:focus{outline:2px solid rgba(22,101,52,.13);border-color:var(--foodex-green)}.catalog-inline-price-save,.catalog-inline-save{width:25px;height:25px;min-width:25px;border:1px solid var(--foodex-border);border-radius:7px;background:#fff;color:var(--foodex-green-dark);cursor:pointer;font-weight:900;line-height:1}.catalog-inline-price-save:hover,.catalog-inline-save:hover{background:var(--foodex-green-soft)}.catalog-inline-toggle{border:1px solid var(--foodex-border);border-radius:999px;background:#fff;padding:6px 9px;cursor:pointer;font-weight:800;white-space:nowrap}.catalog-inline-toggle.on{background:var(--foodex-green-soft);color:var(--foodex-green-dark)}.catalog-inline-toggle.off{color:var(--foodex-muted)}.catalog-inline-stock{display:grid;grid-template-columns:minmax(80px,1fr) auto;gap:5px;align-items:center}.catalog-inline-stock select{grid-column:1/-1}
.catalog-products-shell{display:grid;gap:12px}
.catalog-toolbar{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap}
.catalog-toolbar-group{display:flex;gap:8px;align-items:center;flex-wrap:wrap}
.catalog-count{display:inline-flex;align-items:center;gap:7px;padding:9px 13px;border:1px solid var(--foodex-border);background:var(--foodex-surface);border-radius:10px;font-weight:700}
.catalog-filterbar{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:10px;align-items:end;padding:12px;border:1px solid var(--foodex-border);border-radius:var(--foodex-radius-card);background:var(--foodex-surface)}
.catalog-header-filters{direction:rtl;width:100%;padding:10px;grid-template-columns:minmax(170px,1.35fr) repeat(5,minmax(105px,1fr)) auto;gap:8px;box-shadow:var(--foodex-shadow-sm)}
html[dir=ltr] .catalog-header-filters{direction:ltr}
.catalog-filterbar label{display:grid;gap:5px;font-size:.78rem;font-weight:800;color:var(--foodex-muted)}
.catalog-header-filters label{gap:3px;font-size:.7rem}
.catalog-filterbar input,.catalog-filterbar select{width:100%;box-sizing:border-box;border:1px solid var(--foodex-border);border-radius:10px;padding:9px 10px;background:#fff;color:var(--foodex-ink);font:inherit}
.catalog-header-filters input,.catalog-header-filters select{padding:7px 8px;min-height:34px;font-size:.8rem}
.catalog-filter-actions{display:flex;align-items:center;gap:8px;flex-wrap:wrap}
.catalog-header-filters .catalog-filter-actions{align-self:end;flex-wrap:nowrap}
.catalog-header-filters .catalog-filter-actions .btn{padding:7px 10px;white-space:nowrap;font-size:.78rem}
.catalog-filter-state{display:flex;align-items:center;gap:8px;flex-wrap:wrap;font-size:.8rem;color:var(--foodex-muted)}
.catalog-filter-chip{display:inline-flex;align-items:center;padding:5px 9px;border-radius:999px;background:var(--foodex-green-soft);color:var(--foodex-green-dark);font-weight:800}
.catalog-category-results{display:grid;gap:10px;min-width:0}
.catalog-table-card{padding:0;overflow:visible}
.catalog-products-table{width:100%;border-collapse:separate;border-spacing:0;table-layout:fixed;min-width:1380px}
.catalog-products-table th{padding:11px 12px;background:#f8fafc;color:var(--foodex-muted);font-size:.78rem;font-weight:800;border-bottom:1px solid var(--foodex-border);white-space:nowrap}
.catalog-products-table td{padding:9px 12px;border-bottom:1px solid var(--foodex-border);vertical-align:middle;font-size:.88rem}
.catalog-products-table tbody tr:last-child td{border-bottom:0}
.catalog-products-table tbody tr:hover{background:#fbfdfc}
.catalog-product-cell{display:flex;align-items:center;gap:10px;min-width:0}
.catalog-product-thumb{width:44px;height:44px;min-width:44px;border-radius:9px;object-fit:cover;border:1px solid var(--foodex-border);background:#fff}
.catalog-product-thumb-placeholder{width:44px;height:44px;min-width:44px;border-radius:9px;border:1px dashed var(--foodex-border);display:grid;place-items:center;color:var(--foodex-muted);font-size:.72rem;background:#fff}
.catalog-product-copy{min-width:0}
.catalog-product-name{font-weight:800;white-space:normal;overflow:visible;text-overflow:clip;line-height:1.35;overflow-wrap:anywhere}
.catalog-product-sub{color:var(--foodex-muted);font-size:.75rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;margin-top:2px}
.catalog-stock{display:inline-flex;align-items:center;gap:6px;white-space:nowrap}
.catalog-stock-dot{width:7px;height:7px;border-radius:50%;background:var(--foodex-green)}
.catalog-stock.out .catalog-stock-dot{background:var(--foodex-red)}
.catalog-status{display:inline-flex;align-items:center;gap:6px;padding:5px 9px;border-radius:999px;background:var(--foodex-green-soft);color:var(--foodex-green-dark);font-size:.76rem;font-weight:800;white-space:nowrap}
.catalog-status-form{margin:0;display:inline-flex}.catalog-status-toggle{border:0;font:inherit;cursor:pointer}.catalog-status-toggle:hover{box-shadow:0 0 0 2px rgba(22,101,52,.12)}
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
.catalog-action-menu .danger-action{color:var(--foodex-danger-text)}
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
@media(max-width:1200px){.catalog-page-header{grid-template-columns:1fr}.catalog-page-copy{grid-column:1;grid-row:1;min-width:0}.catalog-header-filters,html[dir=ltr] .catalog-header-filters{grid-column:1;grid-row:2;grid-template-columns:repeat(3,minmax(0,1fr))}.catalog-header-filters .catalog-filter-actions{grid-column:1/-1;justify-content:flex-start}}@media(max-width:1023px){.grid{grid-template-columns:1fr}.catalog-layout,.catalog-layout[dir]{grid-template-columns:1fr}.catalog-layout aside,.catalog-layout main,html[dir=ltr] .catalog-layout aside,html[dir=ltr] .catalog-layout main{grid-column:1}.catalog-layout aside{grid-row:1;min-height:auto}.catalog-layout main{grid-row:2}.catalog-layout main{padding:var(--foodex-space-4)}.header{align-items:flex-start}.tabs{overflow-x:auto;flex-wrap:nowrap;padding-bottom:4px}.tabs a{white-space:nowrap}.catalog-tabs-row{grid-template-columns:auto minmax(0,1fr) auto}.catalog-filterbar{grid-template-columns:repeat(2,minmax(0,1fr))}.catalog-header-filters{grid-template-columns:repeat(2,minmax(0,1fr))}.catalog-header-filters .catalog-filter-actions{grid-column:1/-1}}
@media(max-width:720px){.catalog-col-sku,.catalog-col-brand,.catalog-col-category{display:none!important}.catalog-tabs-row{grid-template-columns:1fr;align-items:stretch}.catalog-tabs-meta{order:3}.catalog-tabs-row .tabs{order:2;justify-content:flex-start}.catalog-tabs-action{order:1}.catalog-tabs-add{width:100%}.catalog-count{width:max-content}.catalog-filterbar{grid-template-columns:1fr}.catalog-header-filters{grid-template-columns:1fr}.catalog-header-filters .catalog-filter-actions{grid-column:1}.catalog-filter-actions{display:grid;grid-template-columns:1fr 1fr}.catalog-filter-actions .btn{text-align:center}.catalog-table-card.table-wrap,.catalog-categories-wrap.table-wrap{overflow:visible;background:transparent;border:0;box-shadow:none}.catalog-products-table,.catalog-categories-table{display:block;min-width:0!important;width:100%}.catalog-products-table thead,.catalog-categories-table thead{display:none}.catalog-products-table tbody,.catalog-categories-table tbody{display:grid;gap:12px}.catalog-products-table tr.catalog-product-row,.catalog-categories-table tr.catalog-category-row{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px;padding:12px;border:1px solid var(--foodex-border);border-radius:14px;background:var(--foodex-surface);box-shadow:var(--foodex-shadow-sm)}.catalog-products-table td,.catalog-categories-table td{display:grid;gap:5px;padding:7px 8px!important;border:0!important;min-width:0}.catalog-products-table td[data-label]::before,.catalog-categories-table td[data-label]::before{content:attr(data-label);font-size:.68rem;font-weight:800;color:var(--foodex-muted);text-transform:none}.catalog-products-table td.catalog-mobile-full,.catalog-categories-table td.catalog-mobile-full{grid-column:1/-1}.catalog-product-cell{align-items:flex-start}.catalog-product-thumb,.catalog-product-thumb-placeholder{width:58px;height:58px;min-width:58px}.catalog-product-sub{display:none}.catalog-mobile-actions .catalog-actions{display:block}.catalog-mobile-actions .catalog-actions summary{width:100%;height:38px;border-radius:10px;font-size:0}.catalog-mobile-actions .catalog-actions summary::after{content:'•••';font-size:18px}.catalog-action-menu{position:static;width:auto;margin-top:6px;box-shadow:none}.catalog-categories-table .inline-form{display:grid;grid-template-columns:1fr;align-items:stretch}.catalog-categories-table .inline-form input,.catalog-categories-table .inline-form select{min-width:0;max-width:none;width:100%;box-sizing:border-box}.catalog-categories-table .btn{width:100%;text-align:center}.catalog-grid-image{align-items:center}.catalog-grid-image-form{min-width:0}.catalog-inline-price-input{width:100%;max-width:120px}}
@media(max-width:420px){.catalog-products-table tr.catalog-product-row,.catalog-categories-table tr.catalog-category-row{grid-template-columns:1fr}.catalog-products-table td.catalog-mobile-full,.catalog-categories-table td.catalog-mobile-full{grid-column:1}.catalog-filter-actions{grid-template-columns:1fr}.row{grid-template-columns:1fr}.catalog-dialog-body{padding:14px}.catalog-dialog-actions{display:grid;grid-template-columns:1fr}.catalog-dialog-actions .btn{width:100%}}
</style>
</head>
<body>
<div class="catalog-layout">
<aside class="sidebar">@include('admin._sidebar',['navContext'=>$navContext])</aside>
<main>
@include('admin._global-header')
@php($hasProductFilters=$productFilters['q']!=='' || $productFilters['category_id']>0 || $productFilters['brand_id']>0 || $productFilters['status']!=='' || $productFilters['availability']!=='' || $productFilters['health']!=='')
<div class="header foodex-page-toolbar catalog-page-header">
@if($tab==='products')
<form method="get" class="catalog-filterbar catalog-header-filters" data-catalog-product-filters>
<input type="hidden" name="tab" value="products">
<input type="hidden" name="product_sort" value="{{ $productFilters['sort'] }}">
<input type="hidden" name="product_dir" value="{{ $productFilters['dir'] }}">
@foreach($scopeParams as $scopeKey=>$scopeValue)<input type="hidden" name="{{ $scopeKey }}" value="{{ $scopeValue }}">@endforeach
<label>{{ app()->getLocale()==='ar'?'بحث بالاسم أو رمز المنتج':'Search name or SKU' }}<input type="search" name="product_q" value="{{ $productFilters['q'] }}" maxlength="120" autocomplete="off" placeholder="{{ app()->getLocale()==='ar'?'اسم أو SKU':'Name or SKU' }}"></label>
<label>{{ app()->getLocale()==='ar'?'التصنيف':'Category' }}<select name="product_category_id"><option value="">{{ app()->getLocale()==='ar'?'كل التصنيفات':'All categories' }}</option>@foreach($categories as $filterCategory)<option value="{{ $filterCategory->id }}" @selected($productFilters['category_id']===(int)$filterCategory->id)>{{ $filterCategory->name }} · {{ $filterCategory->catalog_store_name }}</option>@endforeach</select></label>
<label>{{ app()->getLocale()==='ar'?'العلامة التجارية':'Brand' }}<select name="product_brand_id"><option value="">{{ app()->getLocale()==='ar'?'كل العلامات':'All brands' }}</option>@foreach($filterBrands as $filterBrand)<option value="{{ $filterBrand->id }}" @selected($productFilters['brand_id']===(int)$filterBrand->id)>{{ $filterBrand->name }}</option>@endforeach</select></label>
<label>{{ app()->getLocale()==='ar'?'الحالة':'Status' }}<select name="product_status"><option value="">{{ app()->getLocale()==='ar'?'كل الحالات':'All statuses' }}</option><option value="active" @selected($productFilters['status']==='active')>{{ app()->getLocale()==='ar'?'نشط':'Active' }}</option><option value="inactive" @selected($productFilters['status']==='inactive')>{{ app()->getLocale()==='ar'?'غير نشط':'Inactive' }}</option></select></label>
<label>{{ app()->getLocale()==='ar'?'التوفر':'Availability' }}<select name="product_availability"><option value="">{{ app()->getLocale()==='ar'?'كل حالات التوفر':'All availability' }}</option><option value="available" @selected($productFilters['availability']==='available')>{{ app()->getLocale()==='ar'?'متاح':'Available' }}</option><option value="out_of_stock" @selected($productFilters['availability']==='out_of_stock')>{{ app()->getLocale()==='ar'?'نفد المخزون':'Out of stock' }}</option></select></label>
<label>{{ app()->getLocale()==='ar'?'صحة المنتج':'Product health' }}<select name="product_health"><option value="">{{ app()->getLocale()==='ar'?'كل الحالات':'All health states' }}</option><option value="low_stock" @selected($productFilters['health']==='low_stock')>{{ __('catalog_completion.kpis.low_stock') }}</option><option value="unpriced" @selected($productFilters['health']==='unpriced')>{{ __('catalog_completion.kpis.unpriced') }}</option><option value="unavailable" @selected($productFilters['health']==='unavailable')>{{ __('catalog_completion.kpis.unavailable') }}</option><option value="missing_category" @selected($productFilters['health']==='missing_category')>{{ app()->getLocale()==='ar'?'بدون تصنيف':'Missing category' }}</option><option value="missing_brand" @selected($productFilters['health']==='missing_brand')>{{ app()->getLocale()==='ar'?'بدون علامة':'Missing brand' }}</option><option value="margin_exception" @selected($productFilters['health']==='margin_exception')>{{ __('catalog_completion.kpis.margin_exceptions') }}</option><option value="data_quality" @selected($productFilters['health']==='data_quality')>{{ __('catalog_completion.exceptions.data_quality') }}</option></select></label>
<div class="catalog-filter-actions"><button class="btn primary">{{ app()->getLocale()==='ar'?'تطبيق':'Apply' }}</button><a class="btn" data-catalog-filter-reset href="{{ route('admin.catalog.index',array_merge(['tab'=>'products'],$scopeParams)) }}">{{ app()->getLocale()==='ar'?'مسح':'Clear' }}</a>@if($hasProductFilters)<span class="catalog-filter-chip">{{ app()->getLocale()==='ar'?'مفلتر':'Filtered' }}</span>@endif</div>
</form>
@endif
<div class="catalog-page-copy"><h1>{{ app()->getLocale()==='ar'?($canManageStores?'إدارة الكتالوج والمتاجر':'إدارة الكتالوج'):($canManageStores?'Catalog & Store Management':'Catalog Management') }}</h1><div class="muted">{{ app()->getLocale()==='ar'?($canManageStores?'إدارة المنتجات والتصنيفات والمتاجر من نطاق المنصة.':'إدارة منتجات وتصنيفات المتجر المصرح فقط.') : ($canManageStores?'Manage catalog and store configuration from the platform control plane.':'Manage products and categories only for the authorized store.') }}</div></div>
</div>
@if(session('status'))<div class="notice ok">{{ session('status') }}</div>@endif
@if($errors->any())<div class="notice err">@foreach($errors->all() as $error)<div>{{ $error }}</div>@endforeach</div>@endif
<div class="catalog-tabs-row">
<div class="catalog-tabs-meta">@if($tab==='products')<span class="catalog-count">{{ $products->total() }} {{ app()->getLocale()==='ar'?'منتج':'products' }}</span>@endif</div>
<nav class="tabs" aria-label="{{ __('catalog_completion.title') }}">
@foreach(['overview','products','categories','attributes','import'] as $catalogTab)
<a class="{{ $tab===$catalogTab?'active':'' }}" href="{{ route('admin.catalog.index', array_merge(['tab'=>$catalogTab], $scopeParams)) }}">{{ __('catalog_completion.tabs.'.$catalogTab) }}</a>
@endforeach
@if($canManageStores)
<a class="{{ $tab==='stores'?'active':'' }}" href="{{ route('admin.catalog.index',['tab'=>'stores']) }}">{{ app()->getLocale()==='ar'?'المتاجر':'Stores' }}</a>
@endif
<a href="{{ route('admin.lookups.index', $scopeParams) }}">{{ app()->getLocale()==='ar'?'العلامات والوحدات':'Brands & Units' }}</a>
@if($inventoryUrl)<a href="{{ $inventoryUrl }}">{{ app()->getLocale()==='ar'?'إدارة المخزون':'Inventory Management' }}</a>@endif
</nav>
<div class="catalog-tabs-action">
@if($tab==='products')
<button type="button" class="btn primary catalog-tabs-add" data-dialog-open="catalog-add-product">＋ {{ app()->getLocale()==='ar'?'إضافة منتج':'Add Product' }}</button>
@endif
</div>
</div>

@if($tab==='products')
<div class="catalog-products-shell">
<section class="card catalog-table-card table-wrap">
<table class="catalog-products-table" data-pagination-required>
<thead><tr>
<th><a class="catalog-sort-link" href="{{ route('admin.catalog.index', array_merge(request()->except('product_page'), ['tab'=>'products','product_sort'=>'name','product_dir'=>($productFilters['sort']==='name' && $productFilters['dir']==='asc')?'desc':'asc'])) }}">{{ app()->getLocale()==='ar'?'المنتج':'Product' }} <span class="catalog-sort-arrow">{{ $productFilters['sort']==='name' ? ($productFilters['dir']==='asc'?'▲':'▼') : '↕' }}</span></a></th>
<th class="catalog-col-category"><a class="catalog-sort-link" href="{{ route('admin.catalog.index', array_merge(request()->except('product_page'), ['tab'=>'products','product_sort'=>'category','product_dir'=>($productFilters['sort']==='category' && $productFilters['dir']==='asc')?'desc':'asc'])) }}">{{ app()->getLocale()==='ar'?'التصنيف':'Category' }} <span class="catalog-sort-arrow">{{ $productFilters['sort']==='category' ? ($productFilters['dir']==='asc'?'▲':'▼') : '↕' }}</span></a></th>
<th><a class="catalog-sort-link" href="{{ route('admin.catalog.index', array_merge(request()->except('product_page'), ['tab'=>'products','product_sort'=>'price','product_dir'=>($productFilters['sort']==='price' && $productFilters['dir']==='asc')?'desc':'asc'])) }}">{{ app()->getLocale()==='ar'?'السعر':'Price' }} <span class="catalog-sort-arrow">{{ $productFilters['sort']==='price' ? ($productFilters['dir']==='asc'?'▲':'▼') : '↕' }}</span></a></th>
<th><a class="catalog-sort-link" href="{{ route('admin.catalog.index', array_merge(request()->except('product_page'), ['tab'=>'products','product_sort'=>'stock','product_dir'=>($productFilters['sort']==='stock' && $productFilters['dir']==='asc')?'desc':'asc'])) }}">{{ app()->getLocale()==='ar'?'المخزون':'Stock' }} <span class="catalog-sort-arrow">{{ $productFilters['sort']==='stock' ? ($productFilters['dir']==='asc'?'▲':'▼') : '↕' }}</span></a></th>
<th>{{ app()->getLocale()==='ar'?'حد المخزون المنخفض':'Low stock' }}</th>
<th>{{ app()->getLocale()==='ar'?'عرض في التطبيق':'Show in app' }}</th>
<th class="catalog-col-sku"><a class="catalog-sort-link" href="{{ route('admin.catalog.index', array_merge(request()->except('product_page'), ['tab'=>'products','product_sort'=>'sku','product_dir'=>($productFilters['sort']==='sku' && $productFilters['dir']==='asc')?'desc':'asc'])) }}">{{ app()->getLocale()==='ar'?'رمز المنتج':'SKU' }} <span class="catalog-sort-arrow">{{ $productFilters['sort']==='sku' ? ($productFilters['dir']==='asc'?'▲':'▼') : '↕' }}</span></a></th>
<th class="catalog-col-brand"><a class="catalog-sort-link" href="{{ route('admin.catalog.index', array_merge(request()->except('product_page'), ['tab'=>'products','product_sort'=>'brand','product_dir'=>($productFilters['sort']==='brand' && $productFilters['dir']==='asc')?'desc':'asc'])) }}">{{ app()->getLocale()==='ar'?'العلامة التجارية':'Brand' }} <span class="catalog-sort-arrow">{{ $productFilters['sort']==='brand' ? ($productFilters['dir']==='asc'?'▲':'▼') : '↕' }}</span></a></th>
<th>{{ app()->getLocale()==='ar'?'الوحدة':'Unit' }}</th>
<th><a class="catalog-sort-link" href="{{ route('admin.catalog.index', array_merge(request()->except('product_page'), ['tab'=>'products','product_sort'=>'status','product_dir'=>($productFilters['sort']==='status' && $productFilters['dir']==='asc')?'desc':'asc'])) }}">{{ app()->getLocale()==='ar'?'الحالة':'Status' }} <span class="catalog-sort-arrow">{{ $productFilters['sort']==='status' ? ($productFilters['dir']==='asc'?'▲':'▼') : '↕' }}</span></a></th>
<th>{{ app()->getLocale()==='ar'?'الإجراءات':'Actions' }}</th>
</tr></thead>
<tbody>
@forelse($products as $p)
@php($assignment=$assignments->get($p->catalog_store_id.':'.$p->id))
@php($currency=$currencies->get((int)$p->catalog_store_id))
@php($images=$productImages->get($p->id,collect()))
@php($rowWarehouses=$catalogWarehouses->get((int)$p->catalog_store_id,collect()))
@php($isB2b=strtolower((string)$p->catalog_channel)==='b2b')
@php($stockWarehouseId=$isB2b && $principalWarehouseId ? (int)$principalWarehouseId : (($rowWarehouses->first()->id ?? null) ? (int)$rowWarehouses->first()->id : null))
@php($stockPosition=$stockWarehouseId ? $inventoryPositions->get($stockWarehouseId.':'.$p->id) : null)
@php($appVisible=(bool)($p->grid_app_visible ?? false))
@php($currentBrand=$p->brand_id ? $currentBrands->get((int)$p->brand_id) : null)
@php($currentUnit=$p->unit_id ? $currentUnits->get((int)$p->unit_id) : null)
<tr class="catalog-product-row">
<td class="catalog-mobile-full">
<div class="catalog-product-cell">
<div class="catalog-grid-image">
@if($p->primary_image_path)
<img class="catalog-product-thumb" src="{{ url('/'.ltrim($p->primary_image_path,'/')) }}" alt="{{ $p->name }}">
@else
<div class="catalog-product-thumb-placeholder">{{ app()->getLocale()==='ar'?'بدون صورة':'No image' }}</div>
@endif
<form method="post" enctype="multipart/form-data" action="{{ route('admin.catalog.products.grid-image',$p->id) }}" class="catalog-grid-image-form" data-grid-image-form>
@csrf @method('PATCH')
@if(!empty($scopeParams['support_access']))<input type="hidden" name="support_access" value="1">@endif
<label class="catalog-grid-image-trigger" title="{{ app()->getLocale()==='ar'?'تغيير الصورة':'Change image' }}" aria-label="{{ app()->getLocale()==='ar'?'تغيير الصورة':'Change image' }}">✎<input class="catalog-grid-image-input" data-grid-image-input type="file" name="image" accept="image/jpeg,image/png,image/webp" required></label>
</form>
</div>
<div class="catalog-product-copy">
<form method="post" action="{{ route('admin.catalog.products.inline',$p->id) }}" class="catalog-inline-form">@csrf @method('PATCH')
<input type="hidden" name="field" value="name">
@if(!empty($scopeParams['support_access']))<input type="hidden" name="support_access" value="1">@endif
<input class="catalog-inline-input" name="value" value="{{ $p->name }}" required maxlength="255" aria-label="{{ app()->getLocale()==='ar'?'اسم المنتج':'Product name' }}">
<button class="catalog-inline-save" type="submit" aria-label="{{ app()->getLocale()==='ar'?'حفظ الاسم':'Save name' }}">✓</button>
</form>
@if($p->description)<div class="catalog-product-sub" title="{{ $p->description }}">{{ $p->description }}</div>@endif
</div>
</div>
</td>
<td class="catalog-col-category" data-label="{{ app()->getLocale()==='ar'?'التصنيف':'Category' }}">
<form method="post" action="{{ route('admin.catalog.products.inline',$p->id) }}" class="catalog-inline-form">@csrf @method('PATCH')
<input type="hidden" name="field" value="category_id">@if(!empty($scopeParams['support_access']))<input type="hidden" name="support_access" value="1">@endif
<select class="catalog-inline-select" name="value" onchange="this.form.submit()"><option value="">—</option>@foreach($categories->where('catalog_id',$p->catalog_id) as $category)<option value="{{ $category->id }}" @selected((int)$p->category_id===(int)$category->id)>{{ $category->name }}</option>@endforeach</select>
</form></td>
<td data-label="{{ app()->getLocale()==='ar'?'السعر':'Price' }}">
<form method="post" action="{{ route('admin.catalog.products.inline',$p->id) }}" class="catalog-inline-price-form">
@csrf @method('PATCH')
<input type="hidden" name="field" value="price">
@if(!empty($scopeParams['support_access']))<input type="hidden" name="support_access" value="1">@endif
<input class="catalog-inline-price-input" type="number" step=".001" min="0" name="value" value="{{ $p->grid_price }}" inputmode="decimal" aria-label="{{ app()->getLocale()==='ar'?'سعر المنتج':'Product price' }}" placeholder="—">
@if($currency)<span class="catalog-currency">{{ $currency }}</span>@endif
<button type="submit" class="catalog-inline-price-save" title="{{ app()->getLocale()==='ar'?'حفظ السعر':'Save price' }}" aria-label="{{ app()->getLocale()==='ar'?'حفظ السعر':'Save price' }}">✓</button>
</form>
</td>
<td data-label="{{ app()->getLocale()==='ar'?'المخزون':'Stock' }}" data-availability-state="{{ $p->is_available?'AVAILABLE':'OUT_OF_STOCK' }}">
@if($stockWarehouseId)
<form method="post" action="{{ route('admin.catalog.products.inline',$p->id) }}" class="catalog-inline-stock" data-inline-stock-form>@csrf @method('PATCH')
<input type="hidden" name="field" value="stock_quantity">@if(!empty($scopeParams['support_access']))<input type="hidden" name="support_access" value="1">@endif
@if($isB2b)
<input type="hidden" name="warehouse_id" value="{{ $stockWarehouseId }}">
@elseif($rowWarehouses->count()>1)
<select class="catalog-inline-select" name="warehouse_id" data-inline-stock-warehouse>@foreach($rowWarehouses as $warehouse)@php($position=$inventoryPositions->get($warehouse->id.':'.$p->id))<option value="{{ $warehouse->id }}" data-quantity="{{ (float)($position->quantity ?? 0) }}" data-reserved="{{ (float)($position->reserved_quantity ?? 0) }}">{{ $warehouse->name }}</option>@endforeach</select>
@else
<input type="hidden" name="warehouse_id" value="{{ $stockWarehouseId }}">
@endif
<input class="catalog-inline-input" data-inline-stock-quantity type="number" step=".001" min="{{ (float)($stockPosition->reserved_quantity ?? 0) }}" name="value" value="{{ (float)($stockPosition->quantity ?? 0) }}" inputmode="decimal">
<button class="catalog-inline-save" type="submit" aria-label="{{ app()->getLocale()==='ar'?'حفظ المخزون':'Save stock' }}">✓</button>
</form>
@if($p->is_available)
<span class="catalog-stock" style="margin-top:4px" data-availability-state="AVAILABLE"><span class="catalog-stock-dot"></span>{{ \App\Support\DisplayNumber::quantity((float)$p->available_quantity) }} {{ app()->getLocale()==='ar'?'متاح':'available' }}</span>
@else
<span class="catalog-stock out" style="margin-top:4px" data-availability-state="OUT_OF_STOCK"><span class="catalog-stock-dot"></span>{{ app()->getLocale()==='ar'?'نفد':'Out' }}</span>
@endif
@else
<span class="muted">{{ app()->getLocale()==='ar'?'لا يوجد مخزن نشط':'No active warehouse' }}</span>
@endif
</td>
<td data-label="{{ app()->getLocale()==='ar'?'حد المخزون المنخفض':'Low stock' }}"><form method="post" action="{{ route('admin.catalog.products.inline',$p->id) }}" class="catalog-inline-form">@csrf @method('PATCH')<input type="hidden" name="field" value="low_stock_threshold">@if(!empty($scopeParams['support_access']))<input type="hidden" name="support_access" value="1">@endif<input class="catalog-inline-input" type="number" min="0" step=".001" name="value" value="{{ (float)$p->low_stock_threshold }}"><button class="catalog-inline-save" type="submit">✓</button></form></td>
<td data-label="{{ app()->getLocale()==='ar'?'عرض في التطبيق':'Show in app' }}"><form method="post" action="{{ route('admin.catalog.products.inline',$p->id) }}" class="catalog-inline-form">@csrf @method('PATCH')<input type="hidden" name="field" value="app_visible"><input type="hidden" name="value" value="{{ $appVisible?0:1 }}">@if(!empty($scopeParams['support_access']))<input type="hidden" name="support_access" value="1">@endif<button type="submit" class="catalog-inline-toggle {{ $appVisible?'on':'off' }}">{{ $appVisible?(app()->getLocale()==='ar'?'ظاهر':'Visible'):(app()->getLocale()==='ar'?'مخفي':'Hidden') }}</button></form></td>
<td class="catalog-col-sku" data-label="{{ app()->getLocale()==='ar'?'رمز المنتج':'SKU' }}">{{ $p->sku }}</td>
<td class="catalog-col-brand" data-label="{{ app()->getLocale()==='ar'?'العلامة التجارية':'Brand' }}"><form method="post" action="{{ route('admin.catalog.products.inline',$p->id) }}" class="catalog-inline-form">@csrf @method('PATCH')<input type="hidden" name="field" value="brand_id">@if(!empty($scopeParams['support_access']))<input type="hidden" name="support_access" value="1">@endif<select class="catalog-inline-select" name="value" onchange="this.form.submit()"><option value="">—</option>@if($currentBrand && !$brands->contains('id',(int)$currentBrand->id))<option value="{{ $currentBrand->id }}" selected>{{ $currentBrand->name }}{{ $currentBrand->is_active?'':' · '.(app()->getLocale()==='ar'?'غير نشط':'Inactive') }}</option>@endif @foreach($brands as $brand)@if(($brand->scope ?? 'global')==='global' || (int)($brand->store_id ?? 0)===(int)$p->catalog_store_id)<option value="{{ $brand->id }}" @selected((int)$p->brand_id===(int)$brand->id)>{{ $brand->name }}</option>@endif @endforeach</select></form></td>
<td data-label="{{ app()->getLocale()==='ar'?'الوحدة':'Unit' }}"><form method="post" action="{{ route('admin.catalog.products.inline',$p->id) }}" class="catalog-inline-form">@csrf @method('PATCH')<input type="hidden" name="field" value="unit_id">@if(!empty($scopeParams['support_access']))<input type="hidden" name="support_access" value="1">@endif<select class="catalog-inline-select" name="value" onchange="this.form.submit()" required>@if($currentUnit && !$units->contains('id',(int)$currentUnit->id))<option value="{{ $currentUnit->id }}" selected>{{ $currentUnit->name }} ({{ $currentUnit->code }}) · {{ app()->getLocale()==='ar'?'غير نشط':'Inactive' }}</option>@endif @foreach($units as $unit)@if(($unit->scope ?? 'global')==='global' || (int)($unit->store_id ?? 0)===(int)$p->catalog_store_id)<option value="{{ $unit->id }}" @selected((int)$p->unit_id===(int)$unit->id)>{{ $unit->name }} ({{ $unit->code }})</option>@endif @endforeach</select></form></td>
<td data-label="{{ app()->getLocale()==='ar'?'الحالة':'Status' }}">
<form method="post" action="{{ route('admin.catalog.products.inline',$p->id) }}" class="catalog-status-form">
@csrf @method('PATCH')
<input type="hidden" name="field" value="is_active">
<input type="hidden" name="value" value="{{ $p->is_active?0:1 }}">
@if(!empty($scopeParams['support_access']))<input type="hidden" name="support_access" value="1">@endif
<button type="submit" class="catalog-status catalog-status-toggle {{ $p->is_active?'':'off' }}" title="{{ $p->is_active?(app()->getLocale()==='ar'?'اضغط لتعطيل المنتج':'Click to deactivate product'):(app()->getLocale()==='ar'?'اضغط لتفعيل المنتج':'Click to activate product') }}">
<span class="catalog-status-dot"></span>{{ $p->is_active?(app()->getLocale()==='ar'?'نشط':'Active'):(app()->getLocale()==='ar'?'غير نشط':'Inactive') }}
</button>
</form>
</td>
<td class="catalog-mobile-full catalog-mobile-actions" data-label="{{ app()->getLocale()==='ar'?'الإجراءات':'Actions' }}">
<details class="catalog-actions">
<summary aria-label="{{ app()->getLocale()==='ar'?'إجراءات المنتج':'Product actions' }}">⋮</summary>
<div class="catalog-action-menu">
<a href="{{ route('admin.catalog.index',array_merge(['tab'=>'products','product_detail'=>$p->id],$scopeParams)) }}" style="display:block;text-decoration:none;padding:9px 10px;color:var(--foodex-ink)">{{ __('catalog_completion.product_360.title') }}</a>
<button type="button" data-dialog-open="catalog-edit-{{ $p->id }}">{{ app()->getLocale()==='ar'?'تعديل المنتج':'Edit product' }}</button>
<button type="button" data-dialog-open="catalog-price-{{ $p->id }}">{{ app()->getLocale()==='ar'?'إدارة السعر':'Manage price' }}</button>
<button type="button" data-dialog-open="catalog-images-{{ $p->id }}">{{ app()->getLocale()==='ar'?'إدارة الصور':'Manage images' }}</button>
<form method="post" action="{{ route('admin.catalog.products.destroy',$p->id) }}" onsubmit="return confirm('{{ app()->getLocale()==='ar'?'تأكيد الحذف؟':'Delete product?' }}')">@csrf @method('DELETE')<button class="danger-action">{{ app()->getLocale()==='ar'?'حذف المنتج':'Delete product' }}</button></form>
</div>
</details>
</td>
</tr>
@empty
<tr><td colspan="11"><div class="catalog-empty">{{ $hasProductFilters ? (app()->getLocale()==='ar'?'لا توجد منتجات تطابق الفلاتر الحالية.':'No products match the current filters.') : (app()->getLocale()==='ar'?'لا توجد منتجات.':'No products yet.') }}</div></td></tr>
@endforelse
</tbody>
</table>
{{ $products->links() }}
</section>
</div>

<dialog class="catalog-dialog" id="catalog-add-product">
<div class="catalog-dialog-head"><h2>{{ app()->getLocale()==='ar'?'إضافة منتج':'Add product' }}</h2><button type="button" class="catalog-dialog-close" data-dialog-close>×</button></div>
<div class="catalog-dialog-body">
<form class="form" method="post" enctype="multipart/form-data" action="{{ route('admin.catalog.products.store') }}" data-catalog-scope-form>@csrf
<div class="row"><label>{{ app()->getLocale()==='ar'?'رمز المنتج':'SKU' }}<input value="{{ app()->getLocale()==='ar'?'يتم إنشاؤه تلقائياً عند الحفظ':'Generated automatically on save' }}" readonly aria-readonly="true" data-auto-sku></label><label>{{ app()->getLocale()==='ar'?'الاسم':'Name' }}<input name="name" placeholder="{{ app()->getLocale()==='ar'?'اسم المنتج':'Product name' }}" required></label></div>
<label>{{ app()->getLocale()==='ar'?'الوصف':'Description' }}<textarea name="description" placeholder="{{ app()->getLocale()==='ar'?'وصف مختصر وواضح للمنتج':'Short product description' }}"></textarea></label>
<label>{{ app()->getLocale()==='ar'?'المتجر المالك':'Owning store' }}<select name="store_id" required data-catalog-store-context>@if($stores->count()>1)<option value="">—</option>@endif @foreach($stores as $s)<option value="{{ $s->id }}" data-catalog-id="{{ $s->default_catalog_id ?? '' }}" data-channel="{{ strtolower((string)$s->type_code) }}" @selected($stores->count()===1)>{{ $s->name }}</option>@endforeach</select></label> {{-- localization-gate: allow machine-only channel scope token used for lookup eligibility; never rendered to users --}}
<div class="row"><label>{{ app()->getLocale()==='ar'?'التصنيف':'Category' }}<select name="category_id" data-catalog-dependent><option value="">—</option>@foreach($categories as $c)<option value="{{ $c->id }}" data-catalog-id="{{ $c->catalog_id }}">{{ $c->parent_name ? $c->parent_name.' / ' : '' }}{{ $c->name }} — {{ $c->catalog_store_name }}</option>@endforeach</select></label>
<label>{{ app()->getLocale()==='ar'?'العلامة':'Brand' }}<select name="brand_id" data-catalog-dependent><option value="">—</option>@foreach($brands as $b)<option value="{{ $b->id }}" data-lookup-scope="{{ $b->scope ?? 'global' }}" data-store-id="{{ $b->store_id ?? '' }}" data-active-lookup-option>{{ $b->name }}</option>@endforeach</select></label></div>
<div class="row"><label>{{ app()->getLocale()==='ar'?'الوحدة':'Unit' }}<select name="unit_id" required data-catalog-dependent data-catalog-create-unit><option value="">—</option>@foreach($units as $u)<option value="{{ $u->id }}" data-lookup-scope="{{ $u->scope ?? 'global' }}" data-store-id="{{ $u->store_id ?? '' }}" data-active-lookup-option>{{ $u->name }} ({{ $u->code }})</option>@endforeach</select></label>
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
<div class="row"><label>{{ app()->getLocale()==='ar'?'رمز المنتج':'SKU' }}<input value="{{ $p->sku }}" readonly aria-readonly="true" data-auto-sku></label><label>{{ app()->getLocale()==='ar'?'الاسم':'Name' }}<input name="name" value="{{ $p->name }}" required></label></div>
<label>{{ app()->getLocale()==='ar'?'الوصف':'Description' }}<textarea name="description">{{ $p->description }}</textarea></label>
<div class="row">
<label>{{ app()->getLocale()==='ar'?'التصنيف':'Category' }}<select name="category_id"><option value="">—</option>@foreach($categories as $c)@if((int)$c->catalog_id === (int)$p->catalog_id || (int)$c->id === (int)$p->category_id)<option value="{{ $c->id }}" @selected($p->category_id==$c->id)>{{ $c->name }}</option>@endif @endforeach</select></label>
<label>{{ app()->getLocale()==='ar'?'العلامة':'Brand' }}<select name="brand_id"><option value="">—</option>
@php($currentBrand=$p->brand_id===null?null:$currentBrands->get((int)$p->brand_id))
@if($p->brand_id!==null && !$brands->contains('id',(int)$p->brand_id))
<option value="{{ $p->brand_id }}" selected data-current-lookup-option data-lookup-state="{{ $currentBrand===null?'missing':($currentBrand->is_active?'current':'inactive') }}">{{ $currentBrand->name ?? __('catalog.unavailable_current_reference',['id'=>$p->brand_id]) }} · {{ $currentBrand===null?__('catalog.unavailable'):($currentBrand->is_active?__('catalog.current_reference'):__('catalog.inactive')) }}</option>
@endif
@foreach($brands as $b)@php($scope=(string)($b->scope ?? 'global'))@if($scope === 'global' || ($scope === 'b2b' && strtolower((string)$p->catalog_channel) === 'b2b') || ($scope === 'store' && strtolower((string)$p->catalog_channel) === 'b2c' && (int)$b->store_id === (int)$p->catalog_store_id))<option value="{{ $b->id }}" data-active-lookup-option @selected($p->brand_id==$b->id)>{{ $b->name }}</option>@endif @endforeach</select></label>
</div>
<label>{{ app()->getLocale()==='ar'?'الوحدة':'Unit' }}<select name="unit_id" required data-catalog-edit-unit>
@php($currentUnit=$p->unit_id===null?null:$currentUnits->get((int)$p->unit_id))
@if($p->unit_id!==null && !$units->contains('id',(int)$p->unit_id))
<option value="{{ $p->unit_id }}" selected data-current-lookup-option data-lookup-state="{{ $currentUnit===null?'missing':($currentUnit->is_active?'current':'inactive') }}">{{ $currentUnit->name ?? __('catalog.unavailable_current_reference',['id'=>$p->unit_id]) }}{{ $currentUnit?->code ? ' ('.$currentUnit->code.')' : '' }} · {{ $currentUnit===null?__('catalog.unavailable'):($currentUnit->is_active?__('catalog.current_reference'):__('catalog.inactive')) }}</option>
@endif
@foreach($units as $u)@php($scope=(string)($u->scope ?? 'global'))@if($scope === 'global' || ($scope === 'b2b' && strtolower((string)$p->catalog_channel) === 'b2b') || ($scope === 'store' && strtolower((string)$p->catalog_channel) === 'b2c' && (int)$u->store_id === (int)$p->catalog_store_id))<option value="{{ $u->id }}" data-active-lookup-option @selected($p->unit_id==$u->id)>{{ $u->name }} ({{ $u->code }})</option>@endif @endforeach
@if($p->unit_id===null)<option value="" selected>{{ __('catalog.unavailable') }}</option>@endif {{-- localization-gate: allow — technical Blade null-state condition; rendered option text is localized. --}}
</select></label>
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
<div class="grid"><section class="card"><h2>{{ app()->getLocale()==='ar'?'إضافة تصنيف':'Add category' }}</h2><form class="form" method="post" enctype="multipart/form-data" action="{{ route('admin.catalog.categories.store') }}" data-catalog-scope-form>@csrf
<label>{{ app()->getLocale()==='ar'?'المتجر المالك':'Owning store' }}<select name="store_id" required data-catalog-store-context>@if($stores->count()>1)<option value="">—</option>@endif @foreach($stores as $s)<option value="{{ $s->id }}" data-catalog-id="{{ $s->default_catalog_id ?? '' }}" data-channel="{{ strtolower((string)$s->type_code) }}" @selected($stores->count()===1)>{{ $s->name }}</option>@endforeach</select></label> {{-- localization-gate: allow machine-only channel scope token used for lookup eligibility; never rendered to users --}}<label>{{ app()->getLocale()==='ar'?'الاسم':'Name' }}<input name="name" placeholder="{{ app()->getLocale()==='ar'?'أدخل الاسم':'Enter name' }}" required></label><label>{{ app()->getLocale()==='ar'?'المعرّف النصي':'Slug' }}<input name="slug" placeholder="{{ app()->getLocale()==='ar'?'مثال: beverages':'e.g. beverages' }}"></label><label>{{ app()->getLocale()==='ar'?'صورة التصنيف':'Category image' }}<input type="file" name="category_image" accept="image/jpeg,image/png,image/webp" class="js-catalog-image-preview"></label><label>{{ app()->getLocale()==='ar'?'التصنيف الأب':'Parent' }}<select name="parent_id" data-catalog-dependent><option value="">—</option>@foreach($categories as $c)<option value="{{ $c->id }}" data-catalog-id="{{ $c->catalog_id }}">{{ $c->parent_name ? $c->parent_name.' / ' : '' }}{{ $c->name }} — {{ $c->catalog_store_name }}</option>@endforeach</select></label><label class="check"><input type="checkbox" name="is_active" value="1" checked>{{ app()->getLocale()==='ar'?'نشط':'Active' }}</label><button class="btn primary">{{ app()->getLocale()==='ar'?'إضافة التصنيف':'Add category' }}</button></form></section>
<section class="catalog-category-results">
@php($hasCategoryFilters=$categoryFilters['q']!=='' || $categoryFilters['parent']!=='' || $categoryFilters['status']!=='')
<form method="get" class="catalog-filterbar" data-catalog-category-filters>
<input type="hidden" name="tab" value="categories">
<input type="hidden" name="category_sort" value="{{ $categoryFilters['sort'] }}">
<input type="hidden" name="category_dir" value="{{ $categoryFilters['dir'] }}">
@foreach($scopeParams as $scopeKey=>$scopeValue)<input type="hidden" name="{{ $scopeKey }}" value="{{ $scopeValue }}">@endforeach
<label>{{ app()->getLocale()==='ar'?'بحث بالاسم':'Search name' }}<input type="search" name="category_q" value="{{ $categoryFilters['q'] }}" maxlength="120" autocomplete="off" placeholder="{{ app()->getLocale()==='ar'?'اسم التصنيف':'Category name' }}"></label>
<label>{{ app()->getLocale()==='ar'?'التصنيف الأب':'Parent' }}<select name="category_parent"><option value="">{{ app()->getLocale()==='ar'?'كل التصنيفات':'All categories' }}</option><option value="root" @selected($categoryFilters['parent']==='root')>{{ app()->getLocale()==='ar'?'تصنيفات رئيسية فقط':'Root only' }}</option>@foreach($categories as $filterParent)<option value="{{ $filterParent->id }}" @selected($categoryFilters['parent']===(string)$filterParent->id)>{{ $filterParent->name }} · {{ $filterParent->catalog_store_name }}</option>@endforeach</select></label>
<label>{{ app()->getLocale()==='ar'?'الحالة':'Status' }}<select name="category_status"><option value="">{{ app()->getLocale()==='ar'?'كل الحالات':'All statuses' }}</option><option value="active" @selected($categoryFilters['status']==='active')>{{ app()->getLocale()==='ar'?'نشط':'Active' }}</option><option value="inactive" @selected($categoryFilters['status']==='inactive')>{{ app()->getLocale()==='ar'?'غير نشط':'Inactive' }}</option></select></label>
<div class="catalog-filter-actions"><button class="btn primary">{{ app()->getLocale()==='ar'?'تطبيق':'Apply' }}</button><a class="btn" data-catalog-filter-reset href="{{ route('admin.catalog.index',array_merge(['tab'=>'categories'],$scopeParams)) }}">{{ app()->getLocale()==='ar'?'مسح الفلاتر':'Clear filters' }}</a></div>
<div class="catalog-filter-state" role="status" aria-live="polite"><strong>{{ $categoryRows->total() }}</strong> {{ app()->getLocale()==='ar'?'نتيجة':'results' }} @if($hasCategoryFilters)<span class="catalog-filter-chip">{{ app()->getLocale()==='ar'?'تم تطبيق فلاتر':'Filtered' }}</span>@endif</div>
</form>
<div class="card table-wrap catalog-categories-wrap"><table class="table catalog-categories-table" data-pagination-required><thead><tr><th><a class="catalog-sort-link" href="{{ route('admin.catalog.index', array_merge(request()->except('category_page'), ['tab'=>'categories','category_sort'=>'image','category_dir'=>($categoryFilters['sort']==='image' && $categoryFilters['dir']==='asc')?'desc':'asc'])) }}">{{ app()->getLocale()==='ar'?'الصورة':'Image' }} <span class="catalog-sort-arrow">{{ $categoryFilters['sort']==='image' ? ($categoryFilters['dir']==='asc'?'▲':'▼') : '↕' }}</span></a></th><th><a class="catalog-sort-link" href="{{ route('admin.catalog.index', array_merge(request()->except('category_page'), ['tab'=>'categories','category_sort'=>'name','category_dir'=>($categoryFilters['sort']==='name' && $categoryFilters['dir']==='asc')?'desc':'asc'])) }}">{{ app()->getLocale()==='ar'?'الاسم':'Name' }} <span class="catalog-sort-arrow">{{ $categoryFilters['sort']==='name' ? ($categoryFilters['dir']==='asc'?'▲':'▼') : '↕' }}</span></a></th><th><a class="catalog-sort-link" href="{{ route('admin.catalog.index', array_merge(request()->except('category_page'), ['tab'=>'categories','category_sort'=>'slug','category_dir'=>($categoryFilters['sort']==='slug' && $categoryFilters['dir']==='asc')?'desc':'asc'])) }}">{{ app()->getLocale()==='ar'?'المعرّف النصي':'Slug' }} <span class="catalog-sort-arrow">{{ $categoryFilters['sort']==='slug' ? ($categoryFilters['dir']==='asc'?'▲':'▼') : '↕' }}</span></a></th><th><a class="catalog-sort-link" href="{{ route('admin.catalog.index', array_merge(request()->except('category_page'), ['tab'=>'categories','category_sort'=>'parent','category_dir'=>($categoryFilters['sort']==='parent' && $categoryFilters['dir']==='asc')?'desc':'asc'])) }}">{{ app()->getLocale()==='ar'?'الأب':'Parent' }} <span class="catalog-sort-arrow">{{ $categoryFilters['sort']==='parent' ? ($categoryFilters['dir']==='asc'?'▲':'▼') : '↕' }}</span></a></th><th>{{ app()->getLocale()==='ar'?'إجراءات':'Actions' }}</th></tr></thead><tbody>@forelse($categoryRows as $c)<tr class="catalog-category-row"><td class="catalog-mobile-full" data-label="{{ app()->getLocale()==='ar'?'الصورة':'Image' }}"><div class="catalog-grid-image">@if($c->image_path)<img class="image-thumb" src="{{ url('/'.ltrim($c->image_path,'/')) }}" alt="{{ $c->name }}">@else<span class="muted">—</span>@endif<form method="post" enctype="multipart/form-data" action="{{ route('admin.catalog.categories.grid-image',$c->id) }}" class="catalog-grid-image-form" data-grid-image-form>@csrf @method('PATCH')@if(!empty($scopeParams['support_access']))<input type="hidden" name="support_access" value="1">@endif<label class="catalog-grid-image-trigger" title="{{ app()->getLocale()==='ar'?'تغيير الصورة':'Change image' }}" aria-label="{{ app()->getLocale()==='ar'?'تغيير الصورة':'Change image' }}">✎<input class="catalog-grid-image-input" data-grid-image-input type="file" name="image" accept="image/jpeg,image/png,image/webp" required></label></form></div></td><td data-label="{{ app()->getLocale()==='ar'?'الاسم':'Name' }}">{{ $c->name }}</td><td data-label="{{ app()->getLocale()==='ar'?'المعرّف النصي':'Slug' }}">{{ $c->slug }}</td><td data-label="{{ app()->getLocale()==='ar'?'الأب':'Parent' }}">{{ $c->parent_name ?: '—' }}</td><td class="catalog-mobile-full catalog-mobile-actions" data-label="{{ app()->getLocale()==='ar'?'الإجراءات':'Actions' }}"><a class="btn" href="{{ route('admin.catalog.index',array_merge(['tab'=>'categories','category_detail'=>$c->id],$scopeParams)) }}">{{ __('catalog_completion.category_360.title') }}</a><form class="inline-form" method="post" enctype="multipart/form-data" action="{{ route('admin.catalog.categories.update',$c->id) }}">@csrf @method('PATCH')<input name="name" value="{{ $c->name }}" placeholder="{{ app()->getLocale()==='ar'?'اسم التصنيف':'Category name' }}" placeholder="{{ app()->getLocale()==='ar'?'اسم التصنيف':'Category name' }}"><input name="slug" value="{{ $c->slug }}" placeholder="{{ app()->getLocale()==='ar'?'مثال: beverages':'e.g. beverages' }}"><input class="image-upload js-catalog-image-preview" type="file" name="category_image" accept="image/jpeg,image/png,image/webp"><select name="parent_id"><option value="">—</option>@foreach($categories->where('id','!=',$c->id) as $parent)<option value="{{ $parent->id }}" @selected($c->parent_id==$parent->id)>{{ $parent->name }}</option>@endforeach</select><label class="check"><input type="checkbox" name="remove_image" value="1">{{ app()->getLocale()==='ar'?'حذف الصورة':'Remove image' }}</label><label class="check"><input type="checkbox" name="is_active" value="1" @checked($c->is_active)>{{ app()->getLocale()==='ar'?'نشط':'Active' }}</label><button class="btn">{{ app()->getLocale()==='ar'?'حفظ':'Save' }}</button></form><form method="post" action="{{ route('admin.catalog.categories.destroy',$c->id) }}" style="margin-top:6px">@csrf @method('DELETE')<button class="btn danger">{{ app()->getLocale()==='ar'?'حذف':'Delete' }}</button></form></td></tr>@empty<tr><td colspan="5"><div class="catalog-empty">{{ $hasCategoryFilters ? (app()->getLocale()==='ar'?'لا توجد تصنيفات تطابق الفلاتر الحالية.':'No categories match the current filters.') : (app()->getLocale()==='ar'?'لا توجد تصنيفات.':'No categories.') }}</div></td></tr>@endforelse</tbody></table>{{ $categoryRows->links() }}</div></section></div>

@elseif($tab==='brands')
<div class="grid"><section class="card"><h2>{{ app()->getLocale()==='ar'?'إضافة علامة تجارية':'Add brand' }}</h2><form class="form" method="post" action="{{ route('admin.catalog.brands.store') }}">@csrf<label>{{ app()->getLocale()==='ar'?'الاسم':'Name' }}<input name="name" placeholder="{{ app()->getLocale()==='ar'?'أدخل الاسم':'Enter name' }}" required></label><label>{{ app()->getLocale()==='ar'?'المعرّف النصي':'Slug' }}<input name="slug" placeholder="{{ app()->getLocale()==='ar'?'مثال: beverages':'e.g. beverages' }}"></label><button class="btn primary">{{ app()->getLocale()==='ar'?'إضافة':'Add' }}</button></form></section><section class="card"><table class="table"><tbody>@foreach($brands as $b)<tr><td>{{ $b->name }}</td><td>{{ $b->slug }}</td><td><form method="post" action="{{ route('admin.catalog.brands.destroy',$b->id) }}">@csrf @method('DELETE')<button class="btn danger">{{ app()->getLocale()==='ar'?'حذف':'Delete' }}</button></form></td></tr>@endforeach</tbody></table></section></div>

@elseif($tab==='units')
<div class="grid"><section class="card"><h2>{{ app()->getLocale()==='ar'?'إضافة وحدة قياس':'Add unit' }}</h2><form class="form" method="post" action="{{ route('admin.catalog.units.store') }}">@csrf<label>{{ app()->getLocale()==='ar'?'الكود':'Code' }}<input name="code" placeholder="{{ app()->getLocale()==='ar'?'مثال: PCS':'e.g. PCS' }}" required></label><label>{{ app()->getLocale()==='ar'?'الاسم':'Name' }}<input name="name" placeholder="{{ app()->getLocale()==='ar'?'أدخل الاسم':'Enter name' }}" required></label><label>{{ app()->getLocale()==='ar'?'المنازل العشرية':'Decimal places' }}<input type="number" min="0" max="6" name="decimal_places" value="0" placeholder="0" required></label><button class="btn primary">{{ app()->getLocale()==='ar'?'إضافة':'Add' }}</button></form></section><section class="card"><table class="table"><thead><tr><th>{{ app()->getLocale()==='ar'?'الكود':'Code' }}</th><th>{{ app()->getLocale()==='ar'?'الاسم':'Name' }}</th><th>{{ app()->getLocale()==='ar'?'الدقة':'Precision' }}</th></tr></thead><tbody>@foreach($units as $u)<tr><td>{{ $u->code }}</td><td>{{ $u->name }}</td><td>{{ $u->decimal_places }}</td></tr>@endforeach</tbody></table></section></div>

@elseif($tab==='stores' && $canManageStores)
<div class="grid"><section class="card"><h2>{{ app()->getLocale()==='ar'?'إضافة متجر':'Add store' }}</h2><form class="form" method="post" action="{{ route('admin.catalog.stores.store') }}">@csrf<label>{{ app()->getLocale()==='ar'?'النوع':'Type' }}<select name="store_type_id">@foreach($storeTypes as $t)<option value="{{ $t->id }}">{{ $t->code }} — {{ $t->name }}</option>@endforeach</select></label><label>{{ app()->getLocale()==='ar'?'الكود':'Code' }}<input name="code" placeholder="{{ app()->getLocale()==='ar'?'مثال: PCS':'e.g. PCS' }}" required></label><label>{{ app()->getLocale()==='ar'?'الاسم':'Name' }}<input name="name" placeholder="{{ app()->getLocale()==='ar'?'أدخل الاسم':'Enter name' }}" required></label><label class="check"><input type="checkbox" name="is_active" value="1" checked>{{ app()->getLocale()==='ar'?'نشط':'Active' }}</label><button class="btn primary">{{ app()->getLocale()==='ar'?'إضافة المتجر':'Add store' }}</button></form></section><section class="card table-wrap"><table class="table"><thead><tr><th>{{ app()->getLocale()==='ar'?'النوع':'Type' }}</th><th>{{ app()->getLocale()==='ar'?'الكود':'Code' }}</th><th>{{ app()->getLocale()==='ar'?'الاسم':'Name' }}</th><th>{{ app()->getLocale()==='ar'?'تعديل':'Edit' }}</th></tr></thead><tbody>@foreach($stores as $s)<tr><td>{{ $s->type_code }}</td><td>{{ $s->code }}</td><td>{{ $s->name }}</td><td><form class="inline-form" method="post" action="{{ route('admin.catalog.stores.update',$s->id) }}">@csrf @method('PATCH')<select name="store_type_id">@foreach($storeTypes as $t)<option value="{{ $t->id }}" @selected($s->store_type_id==$t->id)>{{ $t->code }}</option>@endforeach</select><input name="code" value="{{ $s->code }}" placeholder="{{ app()->getLocale()==='ar'?'مثال: STORE-01':'e.g. STORE-01' }}" placeholder="{{ app()->getLocale()==='ar'?'مثال: STORE-01':'e.g. STORE-01' }}"><input name="name" value="{{ $s->name }}" placeholder="{{ app()->getLocale()==='ar'?'اسم المتجر':'Store name' }}" placeholder="{{ app()->getLocale()==='ar'?'اسم المتجر':'Store name' }}"><label class="check"><input type="checkbox" name="is_active" value="1" @checked($s->is_active)>{{ app()->getLocale()==='ar'?'نشط':'Active' }}</label><button class="btn">{{ app()->getLocale()==='ar'?'حفظ':'Save' }}</button></form></td></tr>@endforeach</tbody></table></section></div>
@endif
@include('admin._catalog-product-completion')
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

    const syncCatalogScope = (form) => {
        const store = form.querySelector('[data-catalog-store-context]');
        if (!store) return;
        const selectedStore = store.selectedOptions[0] || null;
        const storeId = selectedStore?.value || '';
        const catalogId = selectedStore?.dataset.catalogId || '';
        const channel = (selectedStore?.dataset.channel || '').toLowerCase();

        form.querySelectorAll('[data-catalog-dependent]').forEach((select) => {
            const previous = select.value;
            Array.from(select.options).forEach((option) => {
                if (!option.value) {
                    option.hidden = false;
                    option.disabled = false;
                    return;
                }

                let allowed = false;
                if (Object.hasOwn(option.dataset, 'catalogId')) {
                    allowed = catalogId !== '' && option.dataset.catalogId === catalogId;
                } else {
                    const scope = (option.dataset.lookupScope || 'global').toLowerCase();
                    allowed = scope === 'global'
                        || (scope === 'b2b' && channel === 'b2b')
                        || (scope === 'store' && channel === 'b2c' && option.dataset.storeId === storeId);
                }
                option.hidden = !allowed;
                option.disabled = !allowed;
            });

            if (!storeId || !Array.from(select.options).some((option) => option.value === previous && !option.disabled)) {
                select.value = '';
            }
            select.disabled = !storeId;
        });
    };

    document.querySelectorAll('[data-catalog-scope-form]').forEach((form) => {
        const store = form.querySelector('[data-catalog-store-context]');
        syncCatalogScope(form);
        store?.addEventListener('change', () => syncCatalogScope(form));
    });

    document.querySelectorAll('[data-grid-image-input]').forEach((input) => {
        input.addEventListener('change', () => {
            const form = input.closest('[data-grid-image-form]');
            if (!form || !input.files || input.files.length === 0) return;
            form.classList.add('is-uploading');
            if (typeof form.requestSubmit === 'function') form.requestSubmit();
            else form.submit();
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
document.querySelectorAll('[data-inline-stock-warehouse]').forEach(function(select){
    select.addEventListener('change',function(){
        var option=this.options[this.selectedIndex];
        var form=this.closest('[data-inline-stock-form]');
        if(!form||!option)return;
        var input=form.querySelector('[data-inline-stock-quantity]');
        if(!input)return;
        input.value=option.dataset.quantity||'0';
        input.min=option.dataset.reserved||'0';
    });
});
</script>
</body></html>
