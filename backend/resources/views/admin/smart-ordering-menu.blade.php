<!doctype html>
<html lang="{{ app()->getLocale() }}" dir="{{ app()->getLocale()==='ar' ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>{{ __('smart_ordering_menu.title') }}</title>
    @include('admin._brand-components')
    @include('admin._settings-page-polish')
    <style>
        .so-toolbar,.so-inline,.so-flags,.so-actions{display:flex;gap:10px;align-items:end;flex-wrap:wrap}
        .so-toolbar{justify-content:space-between;margin-bottom:16px}
        .so-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,300px),1fr));gap:14px}
        .so-menu,.so-category,.so-item,.so-group,.so-option{border:1px solid var(--foodex-border);border-radius:16px;padding:14px;margin-top:12px;background:var(--foodex-surface)}
        .so-category{margin-inline-start:14px}.so-item{margin-inline-start:28px}.so-group{margin-inline-start:42px}.so-option{margin-inline-start:56px}
        .so-form{display:grid;gap:10px}.so-form textarea{min-height:74px}
        .so-inline>*{flex:1 1 150px}.so-flags label,.so-check{display:flex;gap:6px;align-items:center}
        .so-days{display:flex;gap:8px;flex-wrap:wrap;margin:6px 0}.so-days label{font-size:.88rem}
        .so-product-picker{position:relative}.so-product-results{position:absolute;z-index:30;inset-inline:0;top:100%;max-height:240px;overflow:auto;background:var(--foodex-surface);border:1px solid var(--foodex-border);border-radius:var(--foodex-radius-md);box-shadow:var(--foodex-shadow-raised)}
        .so-product-result{display:flex;width:100%;justify-content:space-between;gap:12px;padding:10px 12px;border:0;border-bottom:1px solid var(--foodex-border);background:var(--foodex-surface);text-align:start;cursor:pointer}
        .so-product-result:hover{background:var(--foodex-green-soft)}.so-product-result small{opacity:.7}
        .so-danger{border-color:var(--foodex-red)!important;color:var(--foodex-red)!important}.so-muted{opacity:.72}
        .so-badge{display:inline-flex;padding:4px 9px;border-radius:999px;background:var(--foodex-background);color:var(--foodex-muted);font-size:.8rem}
        .so-section-title{display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap}
        details>summary{cursor:pointer;font-weight:700;margin:6px 0}
        .so-bulk-select{min-height:150px}.so-empty{padding:22px;text-align:center;border:1px dashed var(--foodex-border);border-radius:16px}
        .so-sticky{position:sticky;bottom:12px;z-index:20;background:var(--foodex-surface);backdrop-filter:blur(8px);padding:10px;border:1px solid var(--foodex-border);border-radius:14px}
        .so-media-current{display:flex;align-items:center;gap:10px;flex-wrap:wrap}.so-media-thumb{width:112px;height:84px;object-fit:cover;border:1px solid var(--foodex-border);border-radius:12px;background:var(--foodex-background)}
        .so-review{display:grid;gap:18px}.so-review-menu{border:1px solid var(--foodex-border);border-radius:18px;overflow:hidden;background:var(--foodex-surface)}.so-review-menu-head{display:grid;grid-template-columns:160px 1fr;gap:16px;align-items:center;padding:16px;background:var(--foodex-background)}.so-review-menu-head img,.so-review-placeholder{width:160px;height:110px;object-fit:cover;border-radius:14px;background:var(--foodex-green-soft);display:grid;place-items:center;color:var(--foodex-muted);font-weight:700}.so-review-categories{display:grid;gap:14px;padding:16px}.so-review-category{border-top:1px solid var(--foodex-border);padding-top:14px}.so-review-category-head{display:flex;gap:12px;align-items:center;flex-wrap:wrap}.so-review-category-head img,.so-review-category-head .so-review-placeholder{width:88px;height:66px}.so-review-items{display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:12px;margin-top:12px}.so-review-item{border:1px solid var(--foodex-border);border-radius:14px;padding:10px;display:grid;gap:8px}.so-review-item img,.so-review-item .so-review-placeholder{width:100%;height:130px}.so-review-status{display:flex;gap:6px;flex-wrap:wrap}
        .so-review-link{white-space:nowrap}
        .so-item-invalid,.so-review-item-invalid{border-color:var(--foodex-red)}
        .so-invalid-summary{display:grid;gap:8px;margin-bottom:16px}
        .so-invalid-actions{display:flex;gap:8px;flex-wrap:wrap;align-items:center}
        @media(max-width:760px){.so-category,.so-item,.so-group,.so-option{margin-inline-start:0}.so-sticky{position:static}.so-review-menu-head{grid-template-columns:1fr}.so-review-menu-head img,.so-review-menu-head .so-review-placeholder{width:100%;height:180px}}
    </style>
</head>
<body>
@php
    $isAr = app()->getLocale()==='ar';
    $modifierKindValues = ['modifier', 'size', 'variant', 'extra', 'ingredient'];
    $focusTypeLabels = $isAr
        ? ['menu'=>'منيو','category'=>'تصنيف','item'=>'صنف','group'=>'مجموعة','option'=>'خيار']
        : ['menu'=>'Menu','category'=>'Category','item'=>'Item','group'=>'Group','option'=>'Option'];
    $localized = static function ($model, string $field = 'name') use ($isAr): string {
        $primary = data_get($model, $field.'_'.($isAr ? 'ar' : 'en'));
        $fallback = data_get($model, $field.'_'.($isAr ? 'en' : 'ar'));
        return trim((string) ($primary ?: $fallback ?: ''));
    };
    $allCategories = $menus->flatMap(fn($menu) => $menu->categories);
    $allItems = $allCategories->flatMap(fn($category) => $category->items);
    $mediaUrl = static function ($path): ?string {
        $path = trim((string) $path);
        if ($path === '') return null;
        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://') || str_starts_with($path, '/')) return $path;
        return \Illuminate\Support\Facades\Storage::disk('public')->url($path);
    };
@endphp
<div class="foodex-admin-layout" data-foodex-mobile-web-page="smart-ordering-menu">
    <aside class="sidebar">@include('admin._sidebar', ['navGroups'=>app(\App\Support\AdminNavigation::class)->groupsFor(auth()->user()), 'navContext'=>'smart_ordering', 'user'=>auth()->user()])</aside> {{-- localization-gate: allow Blade include expression --}}
    <main class="foodex-admin-main foodex-admin-page foodex-settings-surface">
@include('admin._global-header')
        <div class="foodex-page-toolbar">
            <div>
                <h1>{{ __('smart_ordering_menu.title') }}</h1>
                <p>{{ __('smart_ordering_menu.description') }}</p>
            </div>
            <div class="so-actions">
                @if($reviewMode)
                <a class="foodex-action-primary so-review-link" href="{{ route('admin.smart-ordering.menu.index', ['store_id'=>$site->store_id, 'menu_id'=>$focusedMenuId] + ($supportAccess ? ['support_access'=>1] : [])) }}">{{ $isAr ? 'العودة للمحرر' : 'Back to editor' }}</a>
                @else
                <a class="foodex-action-primary so-review-link" href="{{ route('admin.smart-ordering.menu.index', ['store_id'=>$site->store_id, 'review'=>1] + ($supportAccess ? ['support_access'=>1] : [])) }}#so-draft-review">{{ $isAr ? 'مراجعة المنيو قبل النشر' : 'Review menu before publishing' }}</a>
                @endif
                <a class="foodex-secondary" href="{{ route('admin.smart-ordering.index',['store_id'=>$site->store_id] + ($supportAccess ? ['support_access'=>1] : [])) }}">{{ $isAr ? 'إعدادات الطلب الذكي' : 'Smart Ordering settings' }}</a>
            </div>
        </div>

        @include('admin._smart-ordering-workspace-nav')

        @if(session('status'))<div class="foodex-alert success" role="status">{{ session('status') }}</div>@endif
        @if($errors->any())<div class="foodex-alert error" role="alert">{{ $errors->first() }}</div>@endif

        <section class="panel">
            <div class="so-toolbar">
                <form method="get" action="{{ route('admin.smart-ordering.menu.index') }}" class="so-inline">
                    <label>{{ $isAr ? 'المطعم' : 'Restaurant' }}
                        <select class="foodex-control" name="store_id" onchange="this.form.submit()">
                            @foreach($stores as $store)<option value="{{ $store->id }}" @selected((int)$store->id===(int)$site->store_id)>{{ $store->name }}</option>@endforeach
                        </select>
                    </label>
                    @if($supportAccess)<input type="hidden" name="support_access" value="1">@endif
                </form>
                @unless($reviewMode)
                <form method="get" action="{{ route('admin.smart-ordering.menu.index') }}" class="so-inline" data-menu-focus-form>
                    <input type="hidden" name="store_id" value="{{ $site->store_id }}">
                    @if($supportAccess)<input type="hidden" name="support_access" value="1">@endif
                    <label>{{ $isAr ? 'المنيو النشطة' : 'Focused menu' }}
                        <select class="foodex-control" name="menu_id" onchange="this.form.submit()">
                            @foreach($menuIndex as $menuOption)
                            <option value="{{ $menuOption->id }}" @selected((int)$menuOption->id===(int)$focusedMenuId)>{{ $localized($menuOption, 'name') }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label>{{ $isAr ? 'ابحث داخل المسودة' : 'Find draft record' }}
                        <input class="foodex-control" name="q" value="{{ $focusQuery }}" placeholder="{{ $isAr ? 'منيو، تصنيف، صنف أو خيار' : 'Menu, category, item or option' }}">
                    </label>
                    <button class="foodex-secondary" type="submit">{{ $isAr ? 'بحث' : 'Find' }}</button>
                </form>
                @endunless
                <div>
                    <span class="so-badge">{{ $isAr ? 'مسودة' : 'Draft' }} #{{ $draft->public_id }}</span>
                    <span class="so-badge">{{ $menuIndex->count() }} {{ $isAr ? 'منيو' : 'menus' }}</span>
                    @unless($reviewMode)<span class="so-badge" data-menu-editor-bounded>{{ $isAr ? 'تحرير مركز' : 'Focused editor' }}</span>@endunless
                    @if($reviewMode)<span class="so-badge">{{ $isAr ? 'مراجعة كاملة' : 'Full review' }}</span>@endif
                </div>
            </div>
        </section>

        @if(!$reviewMode && $focusQuery !== '')
        <section class="panel" data-menu-focus-results>
            <div class="so-section-title">
                <div>
                    <h2>{{ $isAr ? 'نتائج البحث داخل المسودة' : 'Draft record results' }}</h2>
                    <p class="so-muted">{{ $isAr ? 'افتح السجل المطلوب مباشرة داخل المنيو المرتبطة به.' : 'Open the exact record in its owning menu.' }}</p>
                </div>
                <a class="foodex-secondary" href="{{ route('admin.smart-ordering.menu.index', ['store_id'=>$site->store_id, 'menu_id'=>$focusedMenuId] + ($supportAccess ? ['support_access'=>1] : [])) }}">{{ $isAr ? 'مسح البحث' : 'Clear search' }}</a>
            </div>
            <div class="so-actions">
                @forelse($focusResults as $result)
                <a class="foodex-secondary" href="{{ route('admin.smart-ordering.menu.index', ['store_id'=>$site->store_id, 'menu_id'=>$result['menu_id'], 'q'=>$focusQuery] + ($supportAccess ? ['support_access'=>1] : [])) }}#{{ $result['anchor'] }}">
                    <span class="so-badge">{{ $result['type_label'] }}</span>
                    {{ $result['label'] }}
                </a>
                @empty
                <div class="so-empty">{{ $isAr ? 'لا توجد سجلات مطابقة.' : 'No matching draft records.' }}</div>
                @endforelse
            </div>
        </section>
        @endif

        @unless($reviewMode)
        <section class="panel">
            <div class="so-section-title"><div><h2>{{ $isAr ? 'إضافة منيو' : 'Add menu' }}</h2><p class="so-muted">{{ $isAr ? 'يمكن ترك الفروع بدون تحديد لتكون المنيو متاحة لكل الفروع.' : 'Leave branches empty to make the menu global.' }}</p></div></div>
            <form class="so-form" method="post" enctype="multipart/form-data" action="{{ route('admin.smart-ordering.menu.menus.store') }}">
                @csrf
                <input type="hidden" name="store_id" value="{{ $site->store_id }}">@if($supportAccess)<input type="hidden" name="support_access" value="1">@endif
                <div class="so-grid">
                    <label>{{ $isAr ? 'الاسم بالعربية' : 'Arabic name' }}<input class="foodex-control" data-localized-field="ar" name="name_ar"></label>
                    <label>{{ $isAr ? 'الاسم بالإنجليزية' : 'English name' }}<input class="foodex-control" data-localized-field="en" name="name_en" required></label>
                    <label>{{ $isAr ? 'الترتيب' : 'Sort order' }}<input class="foodex-control" type="number" min="0" name="sort_order" value="0" required></label>
                    <label>{{ $isAr ? 'صورة المنيو' : 'Menu image' }}<input class="foodex-control" type="file" name="image" accept="image/jpeg,image/png,image/webp"></label>
                </div>
                <div class="so-grid">
                    <label>{{ $isAr ? 'الوصف بالعربية' : 'Arabic description' }}<textarea class="foodex-control" data-localized-field="ar" name="description_ar"></textarea></label>
                    <label>{{ $isAr ? 'الوصف بالإنجليزية' : 'English description' }}<textarea class="foodex-control" data-localized-field="en" name="description_en"></textarea></label>
                </div>
                <div class="so-flags">
                    <input type="hidden" name="is_visible" value="0"><label><input type="checkbox" name="is_visible" value="1" checked> {{ $isAr ? 'ظاهرة' : 'Visible' }}</label>
                    <input type="hidden" name="is_default" value="0"><label><input type="checkbox" name="is_default" value="1"> {{ $isAr ? 'افتراضية' : 'Default' }}</label>
                    @foreach($branches as $branch)<label><input type="checkbox" name="branch_ids[]" value="{{ $branch->id }}"> {{ $localized($branch, 'name') }}</label>@endforeach
                </div>
                <button class="primary" type="submit">{{ $isAr ? 'إضافة المنيو' : 'Add menu' }}</button>
            </form>
        </section>

        <section class="panel">
            <div class="so-section-title"><div><h2>{{ $isAr ? 'تعديل التوفر بالجملة' : 'Bulk availability' }}</h2><p class="so-muted">{{ $isAr ? 'حدد تصنيفات أو أصناف ثم غيّر الظهور أو حالة نفاد الكمية دفعة واحدة.' : 'Select categories or items, then change visibility or sold-out state in one action.' }}</p></div></div>
            <form class="so-form" method="post" action="{{ route('admin.smart-ordering.menu.bulk-availability') }}" data-bulk-form>
                @csrf @method('PUT')
                <input type="hidden" name="store_id" value="{{ $site->store_id }}">@if($supportAccess)<input type="hidden" name="support_access" value="1">@endif
                <div class="so-grid">
                    <label>{{ $isAr ? 'النوع' : 'Type' }}
                        <select class="foodex-control" name="entity" data-bulk-entity>
                            <option value="items">{{ $isAr ? 'الأصناف' : 'Items' }}</option>
                            <option value="categories">{{ $isAr ? 'التصنيفات' : 'Categories' }}</option>
                        </select>
                    </label>
                    <label>{{ $isAr ? 'الظهور' : 'Visibility' }}
                        <select class="foodex-control" name="is_visible"><option value="">{{ $isAr ? 'بدون تغيير' : 'No change' }}</option><option value="1">{{ $isAr ? 'ظاهر' : 'Visible' }}</option><option value="0">{{ $isAr ? 'مخفي' : 'Hidden' }}</option></select>
                    </label>
                    <label>{{ $isAr ? 'التوفر' : 'Availability' }}
                        <select class="foodex-control" name="sold_out"><option value="">{{ $isAr ? 'بدون تغيير' : 'No change' }}</option><option value="0">{{ $isAr ? 'متاح' : 'Available' }}</option><option value="1">{{ $isAr ? 'نفد' : 'Sold out' }}</option></select>
                    </label>
                </div>
                <label>{{ $isAr ? 'السجلات' : 'Records' }}
                    <select class="foodex-control so-bulk-select" name="public_ids[]" multiple required data-bulk-records>
                        @foreach($allItems as $item)<option value="{{ $item->public_id }}" data-entity="items">{{ $localized($item, 'name') }}</option>@endforeach
                        @foreach($allCategories as $category)<option value="{{ $category->public_id }}" data-entity="categories">{{ $localized($category, 'name') }}</option>@endforeach
                    </select>
                </label>
                <button class="primary" type="submit">{{ $isAr ? 'تطبيق' : 'Apply' }}</button>
            </form>
        </section>

        <section class="panel">
            <div class="so-section-title"><div><h2>{{ $isAr ? 'هيكل المنيو' : 'Menu structure' }}</h2><p class="so-muted">{{ $isAr ? 'كل التغييرات أدناه تبقى في المسودة حتى الضغط على نشر.' : 'Everything below stays in the draft until you publish.' }}</p></div></div>

            @forelse($menus as $menu)
            <article class="so-menu" id="menu-{{ $menu->id }}">
                <div class="so-section-title"><h3>{{ $localized($menu, 'name') }}</h3><span class="so-badge">{{ $menu->is_default ? ($isAr?'افتراضية':'Default') : ($isAr?'منيو':'Menu') }}</span></div>
                <form class="so-form" method="post" enctype="multipart/form-data" action="{{ route('admin.smart-ordering.menu.menus.update',$menu) }}">
                    @csrf @method('PUT')
                    <input type="hidden" name="store_id" value="{{ $site->store_id }}">@if($supportAccess)<input type="hidden" name="support_access" value="1">@endif
                    <div class="so-grid">
                        <label>{{ $isAr?'الاسم بالعربية':'Arabic name' }}<input class="foodex-control" data-localized-field="ar" name="name_ar" value="{{ $menu->name_ar }}"></label>
                        <label>{{ $isAr?'الاسم بالإنجليزية':'English name' }}<input class="foodex-control" data-localized-field="en" name="name_en" value="{{ $menu->name_en }}" required></label>
                        <label>{{ $isAr?'الترتيب':'Sort order' }}<input class="foodex-control" type="number" min="0" name="sort_order" value="{{ $menu->sort_order }}" required></label>
                        <label>{{ $isAr?'استبدال صورة المنيو':'Replace menu image' }}<input class="foodex-control" type="file" name="image" accept="image/jpeg,image/png,image/webp"></label>
                    </div>
                    @if($mediaUrl($menu->image_path))
                    <div class="so-media-current"><img class="so-media-thumb" src="{{ $mediaUrl($menu->image_path) }}" alt="{{ $localized($menu,'name') }}"><label class="so-check"><input type="checkbox" name="remove_image" value="1"> {{ $isAr?'حذف صورة المنيو الحالية':'Remove current menu image' }}</label></div>
                    @endif
                    <div class="so-grid">
                        <label>{{ $isAr?'الوصف بالعربية':'Arabic description' }}<textarea class="foodex-control" data-localized-field="ar" name="description_ar">{{ $menu->description_ar }}</textarea></label>
                        <label>{{ $isAr?'الوصف بالإنجليزية':'English description' }}<textarea class="foodex-control" data-localized-field="en" name="description_en">{{ $menu->description_en }}</textarea></label>
                    </div>
                    <div class="so-flags">
                        <input type="hidden" name="is_visible" value="0"><label><input type="checkbox" name="is_visible" value="1" @checked($menu->is_visible)> {{ $isAr?'ظاهرة':'Visible' }}</label>
                        <input type="hidden" name="is_default" value="0"><label><input type="checkbox" name="is_default" value="1" @checked($menu->is_default)> {{ $isAr?'افتراضية':'Default' }}</label>
                        @foreach($branches as $branch)<label><input type="checkbox" name="branch_ids[]" value="{{ $branch->id }}" @checked(in_array((int)$branch->id,$menuBranchIds[$menu->id] ?? [],true))> {{ $localized($branch, 'name') }}</label>@endforeach
                    </div>
                    <button class="primary" type="submit">{{ $isAr?'حفظ المنيو':'Save menu' }}</button>
                </form>
                <form method="post" action="{{ route('admin.smart-ordering.menu.menus.destroy',$menu) }}" data-confirm-delete>
                    @csrf @method('DELETE')<input type="hidden" name="store_id" value="{{ $site->store_id }}">
                    @if($supportAccess)<input type="hidden" name="support_access" value="1">@endif
                    <button class="so-danger" type="submit">{{ $isAr?'حذف المنيو':'Delete menu' }}</button>
                </form>

                <details>
                    <summary>{{ $isAr?'إضافة تصنيف':'Add category' }}</summary>
                    <form class="so-form" method="post" enctype="multipart/form-data" action="{{ route('admin.smart-ordering.menu.categories.store',$menu) }}">
                        @csrf <input type="hidden" name="store_id" value="{{ $site->store_id }}">@if($supportAccess)<input type="hidden" name="support_access" value="1">@endif
                        <div class="so-grid">
                            <label>{{ $isAr?'الاسم بالعربية':'Arabic name' }}<input class="foodex-control" data-localized-field="ar" name="name_ar"></label>
                            <label>{{ $isAr?'الاسم بالإنجليزية':'English name' }}<input class="foodex-control" data-localized-field="en" name="name_en" required></label>
                            <label>{{ $isAr?'الأيقونة':'Icon' }}<input class="foodex-control" name="icon" maxlength="64"></label>
                            <label>{{ $isAr?'الترتيب':'Sort order' }}<input class="foodex-control" type="number" name="sort_order" min="0" value="0" required></label>
                            <label>{{ $isAr?'الصورة':'Image' }}<input class="foodex-control" type="file" name="image" accept="image/jpeg,image/png,image/webp"></label>
                        </div>
                        <div class="so-grid"><label>{{ $isAr?'الوصف بالعربية':'Arabic description' }}<textarea class="foodex-control" data-localized-field="ar" name="description_ar"></textarea></label><label>{{ $isAr?'الوصف بالإنجليزية':'English description' }}<textarea class="foodex-control" data-localized-field="en" name="description_en"></textarea></label></div>
                        <div class="so-flags"><input type="hidden" name="is_visible" value="0"><label><input type="checkbox" name="is_visible" value="1" checked> {{ $isAr?'ظاهر':'Visible' }}</label><input type="hidden" name="sold_out" value="0"><label><input type="checkbox" name="sold_out" value="1"> {{ $isAr?'نفد':'Sold out' }}</label></div>
                        @include('admin._smart-ordering-schedule-fields',['schedule'=>null,'prefix'=>''])
                        <button class="primary" type="submit">{{ $isAr?'إضافة التصنيف':'Add category' }}</button>
                    </form>
                </details>

                @foreach($menu->categories as $category)
                <article class="so-category" id="menu-category-{{ $category->id }}">
                    <div class="so-section-title"><h4>{{ $localized($category, 'name') }}</h4><span class="so-badge">{{ $category->items->count() }} {{ $isAr?'صنف':'items' }}</span></div>
                    <form class="so-form" method="post" enctype="multipart/form-data" action="{{ route('admin.smart-ordering.menu.categories.update',$category) }}">
                        @csrf @method('PUT')<input type="hidden" name="store_id" value="{{ $site->store_id }}">@if($supportAccess)<input type="hidden" name="support_access" value="1">@endif
                        <div class="so-grid">
                            <label>{{ $isAr?'الاسم بالعربية':'Arabic name' }}<input class="foodex-control" data-localized-field="ar" name="name_ar" value="{{ $category->name_ar }}"></label>
                            <label>{{ $isAr?'الاسم بالإنجليزية':'English name' }}<input class="foodex-control" data-localized-field="en" name="name_en" value="{{ $category->name_en }}" required></label>
                            <label>{{ $isAr?'الأيقونة':'Icon' }}<input class="foodex-control" name="icon" value="{{ $category->icon }}"></label>
                            <label>{{ $isAr?'الترتيب':'Sort order' }}<input class="foodex-control" type="number" name="sort_order" min="0" value="{{ $category->sort_order }}" required></label>
                            <label>{{ $isAr?'استبدال الصورة':'Replace image' }}<input class="foodex-control" type="file" name="image" accept="image/jpeg,image/png,image/webp"></label>
                            <label class="so-check"><input type="checkbox" name="remove_image" value="1"> {{ $isAr?'حذف الصورة الحالية':'Remove current image' }}</label>
                        </div>
                        @if($mediaUrl($category->image_path))<div class="so-media-current"><img class="so-media-thumb" src="{{ $mediaUrl($category->image_path) }}" alt="{{ $localized($category,'name') }}"><span class="so-muted">{{ $isAr?'الصورة الحالية للتصنيف':'Current category image' }}</span></div>@endif
                        <div class="so-grid"><label>{{ $isAr?'الوصف بالعربية':'Arabic description' }}<textarea class="foodex-control" data-localized-field="ar" name="description_ar">{{ $category->description_ar }}</textarea></label><label>{{ $isAr?'الوصف بالإنجليزية':'English description' }}<textarea class="foodex-control" data-localized-field="en" name="description_en">{{ $category->description_en }}</textarea></label></div>
                        <div class="so-flags"><input type="hidden" name="is_visible" value="0"><label><input type="checkbox" name="is_visible" value="1" @checked($category->is_visible)> {{ $isAr?'ظاهر':'Visible' }}</label><input type="hidden" name="sold_out" value="0"><label><input type="checkbox" name="sold_out" value="1" @checked($category->sold_out)> {{ $isAr?'نفد':'Sold out' }}</label></div>
                        @include('admin._smart-ordering-schedule-fields',['schedule'=>$category->availability_schedule,'prefix'=>''])
                        <button class="primary" type="submit">{{ $isAr?'حفظ التصنيف':'Save category' }}</button>
                    </form>
                    <div class="so-actions">
                        <form method="post" action="{{ route('admin.smart-ordering.menu.categories.destroy',$category) }}" data-confirm-delete>@csrf @method('DELETE')<input type="hidden" name="store_id" value="{{ $site->store_id }}"><button class="so-danger" type="submit">{{ $isAr?'حذف التصنيف':'Delete category' }}</button></form>
                    </div>

                    @if($branches->isNotEmpty())
                    <details>
                        <summary>{{ $isAr?'تجاوز إعدادات فرع للتصنيف':'Category branch override' }}</summary>
                        <form class="so-form" method="post" action="{{ route('admin.smart-ordering.menu.categories.branch',$category) }}">
                            @csrf @method('PUT')<input type="hidden" name="store_id" value="{{ $site->store_id }}">
                            <div class="so-grid">
                                <label>{{ $isAr?'الفرع':'Branch' }}<select class="foodex-control" name="branch_id" required>@foreach($branches as $branch)<option value="{{ $branch->id }}">{{ $localized($branch, 'name') }}</option>@endforeach</select></label>
                                <label>{{ $isAr?'الظهور':'Visibility' }}<select class="foodex-control" name="override_visible"><option value="inherit">{{ $isAr?'وراثة':'Inherit' }}</option><option value="1">{{ $isAr?'ظاهر':'Visible' }}</option><option value="0">{{ $isAr?'مخفي':'Hidden' }}</option></select></label>
                                <label>{{ $isAr?'نفاد الكمية':'Sold out' }}<select class="foodex-control" name="override_sold_out"><option value="inherit">{{ $isAr?'وراثة':'Inherit' }}</option><option value="1">{{ $isAr?'نفد':'Sold out' }}</option><option value="0">{{ $isAr?'متاح':'Available' }}</option></select></label>
                                <label>{{ $isAr?'ترتيب الفرع':'Branch sort order' }}<input class="foodex-control" type="number" min="0" name="override_sort_order"></label>
                            </div>
                            @include('admin._smart-ordering-schedule-fields',['schedule'=>null,'prefix'=>'override_'])
                            <button class="primary" type="submit">{{ $isAr?'حفظ تجاوز الفرع':'Save branch override' }}</button>
                        </form>
                    </details>
                    @endif

                    <details>
                        <summary>{{ $isAr?'إضافة صنف':'Add item' }}</summary>
                        <form class="so-form" method="post" enctype="multipart/form-data" action="{{ route('admin.smart-ordering.menu.items.store',$category) }}">
                            @csrf <input type="hidden" name="store_id" value="{{ $site->store_id }}">@if($supportAccess)<input type="hidden" name="support_access" value="1">@endif
                            @include('admin._smart-ordering-product-picker',['selectedId'=>null,'selectedLabel'=>'','id'=>'new-item-product-'.$category->id])
                            <div class="so-grid">
                                <label>{{ $isAr?'الاسم بالعربية':'Arabic name' }}<input class="foodex-control" data-localized-field="ar" name="name_ar"></label>
                                <label>{{ $isAr?'الاسم بالإنجليزية':'English name' }}<input class="foodex-control" data-localized-field="en" name="name_en" required></label>
                                <label>{{ ($isAr?'سعر مستقل/تجاوز':'Standalone/override price').' ('.$checkoutCurrency.')' }}<input class="foodex-control" type="number" min="0" step="{{ $moneyStep }}" name="price_override"></label>
                                <label>{{ $isAr?'الترتيب':'Sort order' }}<input class="foodex-control" type="number" min="0" name="sort_order" value="0" required></label>
                                <label>{{ $isAr?'وقت التحضير بالدقائق':'Prep minutes' }}<input class="foodex-control" type="number" min="0" max="1440" name="prep_time_minutes"></label>
                                <label>{{ $isAr?'السعرات':'Calories' }}<input class="foodex-control" type="number" min="0" name="calories"></label>
                                <label>{{ $isAr?'درجة الحار':'Spicy level' }}<input class="foodex-control" type="number" min="0" max="5" name="spicy_level" value="0" required></label>
                                <label>{{ $isAr?'الصورة':'Image' }}<input class="foodex-control" type="file" name="image" accept="image/jpeg,image/png,image/webp"></label>
                                <label>{{ $isAr?'صور إضافية':'Gallery' }}<input class="foodex-control" type="file" name="gallery_images[]" multiple accept="image/jpeg,image/png,image/webp"></label>
                            </div>
                            <div class="so-grid"><label>{{ $isAr?'الوصف بالعربية':'Arabic description' }}<textarea class="foodex-control" data-localized-field="ar" name="description_ar"></textarea></label><label>{{ $isAr?'الوصف بالإنجليزية':'English description' }}<textarea class="foodex-control" data-localized-field="en" name="description_en"></textarea></label><label>{{ $isAr?'الحساسيات مفصولة بفاصلة':'Allergens, comma separated' }}<textarea class="foodex-control" name="allergens_text"></textarea></label><label>{{ $isAr?'مكونات قابلة للإزالة':'Removable ingredients' }}<textarea class="foodex-control" name="removable_ingredients_text"></textarea></label></div>
                            <div class="so-flags">
                                @foreach(['featured'=>($isAr?'مميز':'Featured'),'best_seller'=>($isAr?'الأكثر مبيعًا':'Best seller'),'is_new'=>($isAr?'جديد':'New'),'vegetarian'=>($isAr?'نباتي':'Vegetarian'),'sold_out'=>($isAr?'نفد':'Sold out')] as $flag=>$label)<input type="hidden" name="{{ $flag }}" value="0"><label><input type="checkbox" name="{{ $flag }}" value="1"> {{ $label }}</label>@endforeach
                                <input type="hidden" name="is_visible" value="0"><label><input type="checkbox" name="is_visible" value="1" checked> {{ $isAr?'ظاهر':'Visible' }}</label>
                                <input type="hidden" name="allow_customer_notes" value="0"><label><input type="checkbox" name="allow_customer_notes" value="1" checked> {{ $isAr?'يسمح بالملاحظات':'Allow notes' }}</label>
                            </div>
                            @include('admin._smart-ordering-schedule-fields',['schedule'=>null,'prefix'=>''])
                            <button class="primary" type="submit">{{ $isAr?'إضافة الصنف':'Add item' }}</button>
                        </form>
                    </details>

                    @foreach($category->items as $item)
                    @php($itemInvalid = $itemPriceErrors[(int)$item->id] ?? null)
                    <article class="so-item @if($itemInvalid) so-item-invalid @endif" id="menu-item-{{ $item->public_id }}">
                        <div class="so-section-title"><h5>{{ $localized($item, 'name') }}</h5><span class="so-badge foodex-number" dir="ltr">@if($itemInvalid){{ __('smart_ordering_menu.invalid_item.badge') }}@else{{ $itemPrices[(int)$item->id]['display'] }} {{ $itemPrices[(int)$item->id]['currency'] ?? $checkoutCurrency }}@endif</span></div>
                        @if($itemInvalid)
                        <div class="foodex-alert error" role="alert">
                            <strong>{{ __('smart_ordering_menu.invalid_item.title') }}</strong>
                            <div>{{ $itemInvalid }}</div>
                            <div class="so-invalid-actions">
                                <a class="foodex-secondary" href="#item-product-{{ $item->id }}">{{ __('smart_ordering_menu.invalid_item.replace') }}</a>
                                <a class="foodex-secondary" href="#item-price-{{ $item->id }}">{{ __('smart_ordering_menu.invalid_item.reprice') }}</a>
                                <a class="foodex-secondary" href="#item-visible-{{ $item->id }}">{{ __('smart_ordering_menu.invalid_item.hide') }}</a>
                            </div>
                        </div>
                        @endif
                        <form class="so-form" method="post" enctype="multipart/form-data" action="{{ route('admin.smart-ordering.menu.items.update',$item) }}">
                            @csrf @method('PUT')<input type="hidden" name="store_id" value="{{ $site->store_id }}">@if($supportAccess)<input type="hidden" name="support_access" value="1">@endif
                            <label>{{ $isAr?'التصنيف':'Category' }}<select class="foodex-control" name="category_id">@foreach($allCategories as $targetCategory)<option value="{{ $targetCategory->id }}" @selected((int)$targetCategory->id===(int)$item->category_id)>{{ $localized($targetCategory, 'name') }}</option>@endforeach</select></label>
                            @include('admin._smart-ordering-product-picker',['selectedId'=>$item->source_product_id,'selectedLabel'=>$productLabels[(int)$item->source_product_id] ?? '','id'=>'item-product-'.$item->id])
                            @if($itemInvalid)
                            <button type="button" class="foodex-secondary" data-unlink-product data-picker-id="item-product-{{ $item->id }}">{{ __('smart_ordering_menu.invalid_item.unlink') }}</button>
                            @endif
                            <div class="so-grid">
                                <label>{{ $isAr?'الاسم بالعربية':'Arabic name' }}<input class="foodex-control" data-localized-field="ar" name="name_ar" value="{{ $item->name_ar }}"></label>
                                <label>{{ $isAr?'الاسم بالإنجليزية':'English name' }}<input class="foodex-control" data-localized-field="en" name="name_en" value="{{ $item->name_en }}" required></label>
                                <label>{{ ($isAr?'سعر مستقل/تجاوز':'Standalone/override price').' ('.$checkoutCurrency.')' }}<input id="item-price-{{ $item->id }}" class="foodex-control" type="number" min="0" step="{{ $moneyStep }}" name="price_override" value="{{ $item->price_override }}"></label>
                                <label>{{ $isAr?'الترتيب':'Sort order' }}<input class="foodex-control" type="number" min="0" name="sort_order" value="{{ $item->sort_order }}" required></label>
                                <label>{{ $isAr?'وقت التحضير':'Prep minutes' }}<input class="foodex-control" type="number" min="0" max="1440" name="prep_time_minutes" value="{{ $item->prep_time_minutes }}"></label>
                                <label>{{ $isAr?'السعرات':'Calories' }}<input class="foodex-control" type="number" min="0" name="calories" value="{{ $item->calories }}"></label>
                                <label>{{ $isAr?'درجة الحار':'Spicy level' }}<input class="foodex-control" type="number" min="0" max="5" name="spicy_level" value="{{ $item->spicy_level }}" required></label>
                                <label>{{ $isAr?'استبدال الصورة':'Replace image' }}<input class="foodex-control" type="file" name="image" accept="image/jpeg,image/png,image/webp"></label>
                                <label>{{ $isAr?'إضافة للمعرض':'Add gallery images' }}<input class="foodex-control" type="file" name="gallery_images[]" multiple accept="image/jpeg,image/png,image/webp"></label>
                            </div>
                            @if($mediaUrl($item->image_path))<div class="so-media-current"><img class="so-media-thumb" src="{{ $mediaUrl($item->image_path) }}" alt="{{ $localized($item,'name') }}"><span class="so-muted">{{ $isAr?'الصورة الحالية للصنف':'Current item image' }}</span></div>@endif
                            <div class="so-grid"><label>{{ $isAr?'الوصف بالعربية':'Arabic description' }}<textarea class="foodex-control" data-localized-field="ar" name="description_ar">{{ $item->description_ar }}</textarea></label><label>{{ $isAr?'الوصف بالإنجليزية':'English description' }}<textarea class="foodex-control" data-localized-field="en" name="description_en">{{ $item->description_en }}</textarea></label><label>{{ $isAr?'الحساسيات':'Allergens' }}<textarea class="foodex-control" name="allergens_text">{{ implode(', ',(array)$item->allergens) }}</textarea></label><label>{{ $isAr?'المكونات القابلة للإزالة':'Removable ingredients' }}<textarea class="foodex-control" name="removable_ingredients_text">{{ implode(', ',(array)$item->removable_ingredients) }}</textarea></label></div>
                            <div class="so-flags">
                                @foreach(['featured'=>[$item->featured,$isAr?'مميز':'Featured'],'best_seller'=>[$item->best_seller,$isAr?'الأكثر مبيعًا':'Best seller'],'is_new'=>[$item->is_new,$isAr?'جديد':'New'],'vegetarian'=>[$item->vegetarian,$isAr?'نباتي':'Vegetarian'],'sold_out'=>[$item->sold_out,$isAr?'نفد':'Sold out'],'is_visible'=>[$item->is_visible,$isAr?'ظاهر':'Visible'],'allow_customer_notes'=>[$item->allow_customer_notes,$isAr?'يسمح بالملاحظات':'Allow notes']] as $flag=>$meta)
                                <input type="hidden" name="{{ $flag }}" value="0"><label><input @if($flag==='is_visible') id="item-visible-{{ $item->id }}" @endif type="checkbox" name="{{ $flag }}" value="1" @checked($meta[0])> {{ $meta[1] }}</label>
                                @endforeach
                                <label><input type="checkbox" name="remove_image" value="1"> {{ $isAr?'حذف الصورة':'Remove image' }}</label>
                                <label><input type="checkbox" name="clear_gallery" value="1"> {{ $isAr?'مسح المعرض':'Clear gallery' }}</label>
                            </div>
                            @include('admin._smart-ordering-schedule-fields',['schedule'=>$item->availability_schedule,'prefix'=>''])
                            <button class="primary" type="submit">{{ $isAr?'حفظ الصنف':'Save item' }}</button>
                        </form>
                        <form method="post" action="{{ route('admin.smart-ordering.menu.items.destroy',$item) }}" data-confirm-delete>@csrf @method('DELETE')<input type="hidden" name="store_id" value="{{ $site->store_id }}"><button class="so-danger" type="submit">{{ $isAr?'حذف الصنف':'Delete item' }}</button></form>

                        @if($branches->isNotEmpty())
                        <details>
                            <summary>{{ $isAr?'تجاوز إعدادات فرع للصنف':'Item branch override' }}</summary>
                            <form class="so-form" method="post" action="{{ route('admin.smart-ordering.menu.items.branch',$item) }}">
                                @csrf @method('PUT')<input type="hidden" name="store_id" value="{{ $site->store_id }}">
                                <div class="so-grid">
                                    <label>{{ $isAr?'الفرع':'Branch' }}<select class="foodex-control" name="branch_id">@foreach($branches as $branch)<option value="{{ $branch->id }}">{{ $localized($branch, 'name') }}</option>@endforeach</select></label>
                                    <label>{{ $isAr?'الظهور':'Visibility' }}<select class="foodex-control" name="override_visible"><option value="inherit">{{ $isAr?'وراثة':'Inherit' }}</option><option value="1">{{ $isAr?'ظاهر':'Visible' }}</option><option value="0">{{ $isAr?'مخفي':'Hidden' }}</option></select></label>
                                    <label>{{ $isAr?'نفاد الكمية':'Sold out' }}<select class="foodex-control" name="override_sold_out"><option value="inherit">{{ $isAr?'وراثة':'Inherit' }}</option><option value="1">{{ $isAr?'نفد':'Sold out' }}</option><option value="0">{{ $isAr?'متاح':'Available' }}</option></select></label>
                                    <label>{{ ($isAr?'سعر الفرع':'Branch price').' ('.$checkoutCurrency.')' }}<input class="foodex-control" type="number" min="0" step="{{ $moneyStep }}" name="override_price"></label>
                                    <label>{{ $isAr?'وقت التحضير':'Prep minutes' }}<input class="foodex-control" type="number" min="0" name="override_prep_time"></label>
                                </div>
                                @include('admin._smart-ordering-schedule-fields',['schedule'=>null,'prefix'=>'override_'])
                                <button class="primary" type="submit">{{ $isAr?'حفظ تجاوز الفرع':'Save branch override' }}</button>
                            </form>
                        </details>
                        @endif

                        <details>
                            <summary>{{ $isAr?'إضافة مجموعة تخصيص':'Add modifier group' }}</summary>
                            <form class="so-form" method="post" action="{{ route('admin.smart-ordering.menu.groups.store',$item) }}">
                                @csrf <input type="hidden" name="store_id" value="{{ $site->store_id }}">
                                <div class="so-grid">
                                    <label>{{ $isAr?'الاسم بالعربية':'Arabic name' }}<input class="foodex-control" data-localized-field="ar" name="name_ar"></label>
                                    <label>{{ $isAr?'الاسم بالإنجليزية':'English name' }}<input class="foodex-control" data-localized-field="en" name="name_en" required></label>
                                    <label>{{ $isAr?'النوع':'Kind' }}<select class="foodex-control" name="kind">@foreach($modifierKindValues as $kind)<option value="{{ $kind }}">{{ __('smart_ordering_menu.modifier_kinds.'.$kind) }}</option>@endforeach</select></label>
                                    <label>{{ $isAr?'الاختيار':'Selection' }}<select class="foodex-control" name="selection_type"><option value="single">{{ $isAr?'واحد':'Single' }}</option><option value="multiple">{{ $isAr?'متعدد':'Multiple' }}</option></select></label>
                                    <label>{{ $isAr?'الحد الأدنى':'Min' }}<input class="foodex-control" type="number" min="0" name="min_selections" value="0" required></label>
                                    <label>{{ $isAr?'الحد الأقصى':'Max' }}<input class="foodex-control" type="number" min="1" name="max_selections" value="1" required></label>
                                    <label>{{ $isAr?'الترتيب':'Sort order' }}<input class="foodex-control" type="number" min="0" name="sort_order" value="0" required></label>
                                </div>
                                <div class="so-flags"><input type="hidden" name="is_required" value="0"><label><input type="checkbox" name="is_required" value="1"> {{ $isAr?'إجباري':'Required' }}</label><input type="hidden" name="is_visible" value="0"><label><input type="checkbox" name="is_visible" value="1" checked> {{ $isAr?'ظاهر':'Visible' }}</label></div>
                                <button class="primary" type="submit">{{ $isAr?'إضافة المجموعة':'Add group' }}</button>
                            </form>
                        </details>

                        @foreach($item->modifierGroups as $group)
                        <article class="so-group" id="menu-group-{{ $group->id }}">
                            <form class="so-form" method="post" action="{{ route('admin.smart-ordering.menu.groups.update',$group) }}">
                                @csrf @method('PUT')<input type="hidden" name="store_id" value="{{ $site->store_id }}">
                                <div class="so-grid">
                                    <label>{{ $isAr?'الاسم بالعربية':'Arabic name' }}<input class="foodex-control" data-localized-field="ar" name="name_ar" value="{{ $group->name_ar }}"></label>
                                    <label>{{ $isAr?'الاسم بالإنجليزية':'English name' }}<input class="foodex-control" data-localized-field="en" name="name_en" value="{{ $group->name_en }}" required></label>
                                    <label>{{ $isAr?'النوع':'Kind' }}<select class="foodex-control" name="kind">@if(!in_array($group->kind, $modifierKindValues, true))<option value="{{ $group->kind }}" selected disabled>{{ __('smart_ordering_menu.modifier_kinds.unknown') }}</option>@endif @foreach($modifierKindValues as $kind)<option value="{{ $kind }}" @selected($group->kind===$kind)>{{ __('smart_ordering_menu.modifier_kinds.'.$kind) }}</option>@endforeach</select></label>
                                    <label>{{ $isAr?'الاختيار':'Selection' }}<select class="foodex-control" name="selection_type"><option value="single" @selected($group->selection_type==='single')>{{ $isAr?'واحد':'Single' }}</option><option value="multiple" @selected($group->selection_type==='multiple')>{{ $isAr?'متعدد':'Multiple' }}</option></select></label>
                                    <label>{{ __('smart_ordering_menu.minimum') }}<input class="foodex-control" type="number" min="0" name="min_selections" value="{{ $group->min_selections }}" required></label>
                                    <label>{{ __('smart_ordering_menu.maximum') }}<input class="foodex-control" type="number" min="1" name="max_selections" value="{{ $group->max_selections }}" required></label>
                                    <label>{{ $isAr?'الترتيب':'Sort' }}<input class="foodex-control" type="number" min="0" name="sort_order" value="{{ $group->sort_order }}" required></label>
                                </div>
                                <div class="so-flags"><input type="hidden" name="is_required" value="0"><label><input type="checkbox" name="is_required" value="1" @checked($group->is_required)> {{ $isAr?'إجباري':'Required' }}</label><input type="hidden" name="is_visible" value="0"><label><input type="checkbox" name="is_visible" value="1" @checked($group->is_visible)> {{ $isAr?'ظاهر':'Visible' }}</label></div>
                                <button class="primary" type="submit">{{ $isAr?'حفظ المجموعة':'Save group' }}</button>
                            </form>
                            <form method="post" action="{{ route('admin.smart-ordering.menu.groups.destroy',$group) }}" data-confirm-delete>@csrf @method('DELETE')<input type="hidden" name="store_id" value="{{ $site->store_id }}"><button class="so-danger" type="submit">{{ $isAr?'حذف المجموعة':'Delete group' }}</button></form>

                            @foreach($group->options as $option)
                            <div class="so-option" id="menu-option-{{ $option->id }}">
                                <form class="so-form" method="post" action="{{ route('admin.smart-ordering.menu.options.update',$option) }}">
                                    @csrf @method('PUT')<input type="hidden" name="store_id" value="{{ $site->store_id }}">
                                    @include('admin._smart-ordering-product-picker',['selectedId'=>$option->source_product_id,'selectedLabel'=>$productLabels[(int)$option->source_product_id] ?? '','id'=>'option-product-'.$option->id])
                                    <div class="so-grid">
                                        <label>{{ $isAr?'الاسم بالعربية':'Arabic name' }}<input class="foodex-control" data-localized-field="ar" name="name_ar" value="{{ $option->name_ar }}"></label>
                                        <label>{{ $isAr?'الاسم بالإنجليزية':'English name' }}<input class="foodex-control" data-localized-field="en" name="name_en" value="{{ $option->name_en }}" required></label>
                                        <label>{{ ($isAr?'فرق السعر':'Price delta').' ('.$checkoutCurrency.')' }}<input class="foodex-control" type="number" min="0" step="{{ $moneyStep }}" name="price_delta" value="{{ $option->price_delta }}" required></label>
                                        <label>{{ $isAr?'الترتيب':'Sort' }}<input class="foodex-control" type="number" min="0" name="sort_order" value="{{ $option->sort_order }}" required></label>
                                    </div>
                                    <div class="so-flags">@foreach(['is_default'=>[$option->is_default,$isAr?'افتراضي':'Default'],'is_visible'=>[$option->is_visible,$isAr?'ظاهر':'Visible'],'sold_out'=>[$option->sold_out,$isAr?'نفد':'Sold out']] as $flag=>$meta)<input type="hidden" name="{{ $flag }}" value="0"><label><input type="checkbox" name="{{ $flag }}" value="1" @checked($meta[0])> {{ $meta[1] }}</label>@endforeach</div>
                                    <button class="primary" type="submit">{{ $isAr?'حفظ الخيار':'Save option' }}</button>
                                </form>
                                <form method="post" action="{{ route('admin.smart-ordering.menu.options.destroy',$option) }}" data-confirm-delete>@csrf @method('DELETE')<input type="hidden" name="store_id" value="{{ $site->store_id }}"><button class="so-danger" type="submit">{{ $isAr?'حذف الخيار':'Delete option' }}</button></form>
                                @if($branches->isNotEmpty())
                                <details><summary>{{ $isAr?'تجاوز فرع للخيار':'Option branch override' }}</summary>
                                    <form class="so-form" method="post" action="{{ route('admin.smart-ordering.menu.options.branch',$option) }}">
                                        @csrf @method('PUT')<input type="hidden" name="store_id" value="{{ $site->store_id }}">
                                        <div class="so-grid">
                                            <label>{{ $isAr?'الفرع':'Branch' }}<select class="foodex-control" name="branch_id">@foreach($branches as $branch)<option value="{{ $branch->id }}">{{ $localized($branch, 'name') }}</option>@endforeach</select></label>
                                            <label>{{ $isAr?'الظهور':'Visibility' }}<select class="foodex-control" name="override_visible"><option value="inherit">{{ $isAr?'وراثة':'Inherit' }}</option><option value="1">{{ $isAr?'ظاهر':'Visible' }}</option><option value="0">{{ $isAr?'مخفي':'Hidden' }}</option></select></label>
                                            <label>{{ $isAr?'نفاد':'Sold out' }}<select class="foodex-control" name="override_sold_out"><option value="inherit">{{ $isAr?'وراثة':'Inherit' }}</option><option value="1">{{ $isAr?'نفد':'Sold out' }}</option><option value="0">{{ $isAr?'متاح':'Available' }}</option></select></label>
                                            <label>{{ ($isAr?'فرق سعر الفرع':'Branch price delta').' ('.$checkoutCurrency.')' }}<input class="foodex-control" type="number" min="0" step="{{ $moneyStep }}" name="override_price_delta"></label>
                                        </div><button class="primary" type="submit">{{ $isAr?'حفظ':'Save' }}</button>
                                    </form>
                                </details>
                                @endif
                            </div>
                            @endforeach

                            <details>
                                <summary>{{ $isAr?'إضافة خيار':'Add option' }}</summary>
                                <form class="so-form" method="post" action="{{ route('admin.smart-ordering.menu.options.store',$group) }}">
                                    @csrf <input type="hidden" name="store_id" value="{{ $site->store_id }}">
                                    @include('admin._smart-ordering-product-picker',['selectedId'=>null,'selectedLabel'=>'','id'=>'new-option-product-'.$group->id])
                                    <div class="so-grid">
                                        <label>{{ $isAr?'الاسم بالعربية':'Arabic name' }}<input class="foodex-control" data-localized-field="ar" name="name_ar"></label>
                                        <label>{{ $isAr?'الاسم بالإنجليزية':'English name' }}<input class="foodex-control" data-localized-field="en" name="name_en" required></label>
                                        <label>{{ ($isAr?'فرق السعر':'Price delta').' ('.$checkoutCurrency.')' }}<input class="foodex-control" type="number" min="0" step="{{ $moneyStep }}" name="price_delta" value="0" required></label>
                                        <label>{{ $isAr?'الترتيب':'Sort' }}<input class="foodex-control" type="number" min="0" name="sort_order" value="0" required></label>
                                    </div>
                                    <div class="so-flags"><input type="hidden" name="is_default" value="0"><label><input type="checkbox" name="is_default" value="1"> {{ $isAr?'افتراضي':'Default' }}</label><input type="hidden" name="is_visible" value="0"><label><input type="checkbox" name="is_visible" value="1" checked> {{ $isAr?'ظاهر':'Visible' }}</label><input type="hidden" name="sold_out" value="0"><label><input type="checkbox" name="sold_out" value="1"> {{ $isAr?'نفد':'Sold out' }}</label></div>
                                    <button class="primary" type="submit">{{ $isAr?'إضافة الخيار':'Add option' }}</button>
                                </form>
                            </details>
                        </article>
                        @endforeach
                    </article>
                    @endforeach
                </article>
                @endforeach
            </article>
            @empty
            <div class="so-empty">{{ $isAr ? 'لا توجد منيو بعد. أضف أول منيو من الأعلى.' : 'No menu yet. Add the first menu above.' }}</div>
            @endforelse
        </section>
        @endunless

        @if(!empty($itemPriceErrors))
        <section class="panel so-invalid-summary" id="so-invalid-items" role="alert" aria-labelledby="so-invalid-items-title">
            <h2 id="so-invalid-items-title">{{ __('smart_ordering_menu.invalid_item.summary_title') }}</h2>
            <p class="so-muted">{{ __('smart_ordering_menu.invalid_item.summary_body') }}</p>
            <div class="so-invalid-actions">
                @foreach($allItems as $item)
                    @if(isset($itemPriceErrors[(int)$item->id]))
                    <a class="foodex-secondary" href="{{ $reviewMode ? route('admin.smart-ordering.menu.index', ['store_id'=>$site->store_id, 'menu_id'=>$itemMenuIds[(int)$item->id] ?? $focusedMenuId] + ($supportAccess ? ['support_access'=>1] : [])) : '' }}#menu-item-{{ $item->public_id }}">{{ $localized($item,'name') }}</a>
                    @endif
                @endforeach
            </div>
        </section>
        @endif

        @if($reviewMode)
        <section class="panel" id="so-draft-review">
            <div class="so-section-title">
                <div>
                    <h2>{{ $isAr ? 'مراجعة المنيو قبل النشر' : 'Review menu before publishing' }}</h2>
                    <p class="so-muted">{{ $isAr ? 'هذه معاينة للمسودة الحالية كما رتبتها. راجع الصور والتصنيفات والأسعار والحالات قبل الضغط على نشر.' : 'This is a review of the current draft. Check media, categories, prices and status before publishing.' }}</p>
                </div>
                <span class="so-badge">{{ $isAr ? 'المراجعة لا تنشر أي شيء' : 'Review does not publish' }}</span>
            </div>
            <div class="so-review" data-smart-ordering-draft-review>
                @forelse($menus as $menu)
                <article class="so-review-menu">
                    <div class="so-review-menu-head">
                        @if($mediaUrl($menu->image_path))<img src="{{ $mediaUrl($menu->image_path) }}" alt="{{ $localized($menu,'name') }}">@else<div class="so-review-placeholder">{{ $isAr?'صورة المنيو':'Menu image' }}</div>@endif
                        <div>
                            <h3>{{ $localized($menu,'name') }}</h3>
                            @if($localized($menu,'description'))<p class="so-muted">{{ $localized($menu,'description') }}</p>@endif
                            <div class="so-review-status">
                                @if($menu->is_default)<span class="so-badge">{{ $isAr?'افتراضية':'Default' }}</span>@endif
                                @unless($menu->is_visible)<span class="so-badge">{{ $isAr?'مخفية':'Hidden' }}</span>@endunless
                                <span class="so-badge">{{ $menu->categories->count() }} {{ $isAr?'تصنيف':'categories' }}</span>
                            </div>
                        </div>
                    </div>
                    <div class="so-review-categories">
                        @forelse($menu->categories as $category)
                        <section class="so-review-category">
                            <div class="so-review-category-head">
                                @if($mediaUrl($category->image_path))<div><img src="{{ $mediaUrl($category->image_path) }}" alt="{{ $localized($category,'name') }}"><span class="so-muted">{{ $isAr?'الصورة الحالية للتصنيف':'Current category image' }}</span></div>@else<div class="so-review-placeholder">{{ $isAr?'تصنيف':'Category' }}</div>@endif
                                <div>
                                    <strong>{{ $localized($category,'name') }}</strong>
                                    @if($localized($category,'description'))<div class="so-muted">{{ $localized($category,'description') }}</div>@endif
                                    <div class="so-review-status">
                                        @unless($category->is_visible)<span class="so-badge">{{ $isAr?'مخفي':'Hidden' }}</span>@endunless
                                        @if($category->sold_out)<span class="so-badge">{{ $isAr?'نفد':'Sold out' }}</span>@endif
                                        <span class="so-badge">{{ $category->items->count() }} {{ $isAr?'صنف':'items' }}</span>
                                    </div>
                                </div>
                            </div>
                            <div class="so-review-items">
                                @forelse($category->items as $item)
                                <article class="so-review-item @if(isset($itemPriceErrors[(int)$item->id])) so-review-item-invalid @endif">
                                    @if($mediaUrl($item->image_path))<div><img src="{{ $mediaUrl($item->image_path) }}" alt="{{ $localized($item,'name') }}"><span class="so-muted">{{ $isAr?'الصورة الحالية للصنف':'Current item image' }}</span></div>@else<div class="so-review-placeholder">{{ $isAr?'صورة الصنف':'Item image' }}</div>@endif
                                    <strong>{{ $localized($item,'name') }}</strong>
                                    @if(isset($itemPriceErrors[(int)$item->id]))
                                    <div class="foodex-alert error" role="status">
                                        <strong>{{ __('smart_ordering_menu.invalid_item.badge') }}</strong>
                                        <div>{{ $itemPriceErrors[(int)$item->id] }}</div>
                                        <a class="foodex-secondary" href="{{ route('admin.smart-ordering.menu.index', ['store_id'=>$site->store_id, 'menu_id'=>$menu->id] + ($supportAccess ? ['support_access'=>1] : [])) }}#menu-item-{{ $item->public_id }}">{{ __('smart_ordering_menu.invalid_item.edit') }}</a>
                                    </div>
                                    @else
                                    <span class="foodex-number" dir="ltr">{{ $itemPrices[(int)$item->id]['display'] }} {{ $itemPrices[(int)$item->id]['currency'] ?? $checkoutCurrency }}</span>
                                    @endif
                                    <div class="so-review-status">
                                        @unless($item->is_visible)<span class="so-badge">{{ $isAr?'مخفي':'Hidden' }}</span>@endunless
                                        @if($item->sold_out)<span class="so-badge">{{ $isAr?'نفد':'Sold out' }}</span>@endif
                                        @if($item->featured)<span class="so-badge">{{ $isAr?'مميز':'Featured' }}</span>@endif {{-- localization-gate: allow Blade control directive; visible badge copy is localized --}}
                                    </div>
                                </article>
                                @empty
                                <div class="so-empty">{{ $isAr?'لا توجد أصناف داخل هذا التصنيف.':'No items in this category.' }}</div>
                                @endforelse
                            </div>
                        </section>
                        @empty
                        <div class="so-empty">{{ $isAr?'لا توجد تصنيفات داخل هذه المنيو.':'No categories in this menu.' }}</div>
                        @endforelse
                    </div>
                </article>
                @empty
                <div class="so-empty">{{ $isAr?'لا توجد منيو لمراجعتها بعد.':'There is no menu to review yet.' }}</div>
                @endforelse
            </div>
        </section>

        @endif

        <div class="so-sticky">
            <div class="so-actions">
                <strong>{{ $isAr ? 'النشر فقط يجعل التغييرات مرئية للعملاء.' : 'Only publishing makes draft changes public.' }}</strong>
                @if($reviewMode)
                <a class="foodex-secondary" href="{{ route('admin.smart-ordering.menu.index', ['store_id'=>$site->store_id, 'menu_id'=>$focusedMenuId] + ($supportAccess ? ['support_access'=>1] : [])) }}">{{ $isAr ? 'العودة للمحرر' : 'Back to editor' }}</a>
                <form class="so-inline" method="post" action="{{ route('admin.smart-ordering.menu.publish',$draft) }}">
                    @csrf
                    <input type="hidden" name="store_id" value="{{ $site->store_id }}">
                    @if($supportAccess)<input type="hidden" name="support_access" value="1">@endif
                    <button class="primary" type="submit" @disabled(!empty($itemPriceErrors) || $scheduledRevision !== null) aria-describedby="@if(!empty($itemPriceErrors)) so-invalid-items-title @endif @if($scheduledRevision !== null) so-scheduled-publish-state @endif">{{ $isAr ? 'نشر المنيو الآن' : 'Publish menu now' }}</button>
                </form>
                @if($scheduledRevision !== null)
                <div id="so-scheduled-publish-state" class="foodex-alert" role="status">
                    <strong>{{ __('smart_ordering_menu.schedule.pending', [
                        'time' => $scheduledRevision->scheduled_at?->copy()->setTimezone($publishTimezone)->format('Y-m-d H:i'),
                        'timezone' => $publishTimezone,
                    ]) }}</strong>
                    <div class="so-muted">{{ __('smart_ordering_menu.schedule.pending_hint') }}</div>
                </div>
                @else
                <form class="so-inline" method="post" action="{{ route('admin.smart-ordering.menu.schedule',$draft) }}">
                    @csrf
                    <input type="hidden" name="store_id" value="{{ $site->store_id }}">
                    @if($supportAccess)<input type="hidden" name="support_access" value="1">@endif
                    <label>
                        {{ __('smart_ordering_menu.schedule.at') }}
                        <input class="foodex-control" type="datetime-local" name="scheduled_at" value="{{ old('scheduled_at', $scheduleMinimum) }}" min="{{ $scheduleMinimum }}" required>
                        <span class="so-muted">{{ __('smart_ordering_menu.schedule.timezone', ['timezone' => $publishTimezone]) }}</span>
                    </label>
                    <button class="foodex-secondary" type="submit" @disabled(!empty($itemPriceErrors))>{{ __('smart_ordering_menu.schedule.action') }}</button>
                </form>
                @endif
                @if(!empty($itemPriceErrors))<a class="foodex-secondary" href="#so-invalid-items">{{ __('smart_ordering_menu.invalid_item.review_before_publish') }}</a>@endif
                @else
                <a class="foodex-secondary" href="{{ route('admin.smart-ordering.menu.index', ['store_id'=>$site->store_id, 'review'=>1] + ($supportAccess ? ['support_access'=>1] : [])) }}#so-draft-review">{{ $isAr ? 'مراجعة المسودة' : 'Review draft' }}</a>
                <button class="primary" type="button" disabled aria-disabled="true">{{ $isAr ? 'راجع المسودة قبل النشر' : 'Review draft before publishing' }}</button>
                @endif
            </div>
        </div>
    </main>
</div>
<script src="{{ asset('assets/admin/entity-lookup.js') }}"></script>
<script>
(() => {
    document.querySelectorAll('[data-unlink-product]').forEach((button) => {
        button.addEventListener('click', () => {
            const input = document.getElementById(button.dataset.pickerId);
            const picker = input?.closest('[data-foodex-entity-picker]');
            if (!picker) return;
            const clear = picker.querySelector('[data-foodex-entity-clear]');
            if (clear instanceof HTMLButtonElement) {
                clear.click();
                return;
            }
            const hidden = picker.querySelector('[data-foodex-entity-id]');
            if (hidden instanceof HTMLInputElement) hidden.value = '';
            if (input instanceof HTMLInputElement) { input.value = ''; input.focus(); }
        });
    });

    const bulk = document.querySelector('[data-bulk-form]');
    if (bulk) {
        const entity = bulk.querySelector('[data-bulk-entity]');
        const records = bulk.querySelector('[data-bulk-records]');
        const sync = () => {
            [...records.options].forEach((option) => {
                const match = option.dataset.entity === entity.value;
                option.hidden = !match;
                if (!match) option.selected = false;
            });
        };
        entity.addEventListener('change',sync); sync();
    }

    document.querySelectorAll('[data-confirm-delete]').forEach((form) => {
        form.addEventListener('submit',(event) => {
            const message = @json($isAr ? 'هل أنت متأكد من الحذف؟' : 'Are you sure you want to delete this record?');
            if (!window.confirm(message)) event.preventDefault();
        });
    });
})();
</script>
</body>
</html>
