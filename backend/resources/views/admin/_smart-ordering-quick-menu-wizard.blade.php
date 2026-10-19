@php
    $quickMenuIsAr = app()->getLocale() === 'ar';
    $quickMenuCategories = old('categories', [
        '0' => ['name_ar' => '', 'name_en' => ''],
    ]);
    $quickMenuItems = old('items', [
        '0' => ['category_key' => '0', 'name_ar' => '', 'name_en' => '', 'price' => '', 'featured' => false],
    ]);
    if (!is_array($quickMenuCategories) || $quickMenuCategories === []) {
        $quickMenuCategories = ['0' => ['name_ar' => '', 'name_en' => '']];
    }
    if (!is_array($quickMenuItems) || $quickMenuItems === []) {
        $firstCategoryKey = (string) array_key_first($quickMenuCategories);
        $quickMenuItems = ['0' => ['category_key' => $firstCategoryKey, 'name_ar' => '', 'name_en' => '', 'price' => '', 'featured' => false]];
    }
    $quickMenuShouldOpen = old('_quick_menu') === '1';
    $quickMenuMode = old('_quick_menu_mode', 'create') === 'edit' ? 'edit' : 'create';
    $quickMenuEditingId = (int) old('quick_menu_id', 0);
    $quickMediaUrl = static function ($path): ?string {
        $path = trim((string) $path);
        if ($path === '') return null;
        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://') || str_starts_with($path, '/')) return $path;
        return \Illuminate\Support\Facades\Storage::disk('public')->url($path);
    };
    $quickMenuEditPayloads = [];
    foreach (($menus ?? collect()) as $editMenu) {
        $categoryKeyById = [];
        $editCategories = [];
        foreach ($editMenu->categories as $categoryIndex => $editCategory) {
            $categoryKey = (string) $categoryIndex;
            $categoryKeyById[(int) $editCategory->id] = $categoryKey;
            $editCategories[] = [
                'key' => $categoryKey,
                'id' => (int) $editCategory->id,
                'name_ar' => (string) ($editCategory->name_ar ?? ''),
                'name_en' => (string) ($editCategory->name_en ?? ''),
                'image_url' => $quickMediaUrl($editCategory->image_path),
            ];
        }

        $editItems = [];
        foreach ($editMenu->categories as $editCategory) {
            foreach ($editCategory->items as $editItem) {
                $resolvedAmount = data_get($itemPrices ?? [], ((int) $editItem->id).'.amount');
                $editItems[] = [
                    'id' => (int) $editItem->id,
                    'category_key' => $categoryKeyById[(int) $editCategory->id] ?? '0',
                    'name_ar' => (string) ($editItem->name_ar ?? ''),
                    'name_en' => (string) ($editItem->name_en ?? ''),
                    'price' => $editItem->price_override !== null
                        ? (string) $editItem->price_override
                        : ($resolvedAmount !== null ? (string) $resolvedAmount : ''),
                    'featured' => (bool) $editItem->featured,
                    'image_url' => $quickMediaUrl($editItem->image_path),
                ];
            }
        }

        $quickMenuEditPayloads[(int) $editMenu->id] = [
            'id' => (int) $editMenu->id,
            'action' => route('admin.smart-ordering.menu.quick-update', $editMenu),
            'name_ar' => (string) ($editMenu->name_ar ?? ''),
            'name_en' => (string) ($editMenu->name_en ?? ''),
            'image_url' => $quickMediaUrl($editMenu->image_path),
            'categories' => $editCategories,
            'items' => $editItems,
        ];
    }
    $quickMenuInitialAction = $quickMenuMode === 'edit' && isset($quickMenuEditPayloads[$quickMenuEditingId])
        ? $quickMenuEditPayloads[$quickMenuEditingId]['action']
        : route('admin.smart-ordering.menu.quick-create');
@endphp

<style>
.qm-card{display:grid;gap:10px;padding:13px;border:1px solid var(--foodex-palette-bfe0c7);border-radius:14px;background:linear-gradient(135deg,var(--foodex-palette-f5fbf6),#fff);box-shadow:0 8px 24px rgba(21,138,58,.06)}
.qm-card-head{display:flex;align-items:flex-start;justify-content:space-between;gap:10px}.qm-card-title{display:grid;gap:3px}.qm-card-title strong{font-size:.88rem;color:var(--foodex-ink)}.qm-card-title span{font-size:.69rem;color:var(--foodex-muted);line-height:1.55}
.qm-card-stats,.qm-card-actions{display:flex;align-items:center;gap:6px;flex-wrap:wrap}.qm-stat{display:inline-flex;align-items:center;justify-content:center;min-height:25px;padding:0 9px;border:1px solid var(--foodex-border);border-radius:999px;background:#fff;color:var(--foodex-palette-475569);font-size:.62rem;font-weight:900}
.qm-card-actions .foodex-action-primary,.qm-card-actions .foodex-action-secondary{min-height:37px;padding-inline:13px;border-radius:10px;font-size:.72rem}

.qm-modal{position:fixed!important;z-index:14050!important;inset:0!important;display:flex!important;align-items:center!important;justify-content:center!important;padding:18px!important;background:rgba(15,23,42,.58)!important;backdrop-filter:blur(8px)}
.qm-modal[hidden]{display:none!important}
.qm-dialog{box-sizing:border-box!important;width:min(1080px,calc(100vw - 36px))!important;height:auto!important;max-width:none!important;max-height:calc(100dvh - 36px)!important;margin:0!important;padding:0!important;display:flex!important;flex-direction:column!important;align-items:stretch!important;justify-content:flex-start!important;gap:0!important;overflow:hidden!important;border:1px solid var(--foodex-palette-dce5df)!important;border-radius:20px!important;background:#fff!important;box-shadow:0 34px 100px rgba(15,23,42,.34)!important}
.qm-dialog>*{box-sizing:border-box;min-width:0;width:100%!important;max-width:none!important;justify-self:stretch!important;align-self:stretch!important}

.qm-head{flex:0 0 auto!important;display:flex!important;align-items:flex-start!important;justify-content:space-between!important;gap:14px!important;padding:16px 20px!important;border-bottom:1px solid var(--foodex-palette-e7ece9)!important;background:linear-gradient(135deg,var(--foodex-palette-f8fcf9),var(--foodex-palette-eff8f2))!important}
.qm-head>div{min-width:0}.qm-head h2{margin:0;font-size:1.08rem;color:var(--foodex-palette-13221a)}.qm-head p{margin:4px 0 0;color:var(--foodex-palette-667085);font-size:.71rem;line-height:1.55}
.qm-close{flex:0 0 36px;width:36px!important;height:36px;display:grid;place-items:center;border:1px solid var(--foodex-palette-d7e1db);border-radius:999px;background:#fff;color:var(--foodex-palette-13221a);font-size:1.1rem;font-weight:900;cursor:pointer}

.qm-progress{flex:0 0 auto!important;display:grid!important;grid-template-columns:repeat(3,minmax(0,1fr))!important;gap:8px!important;padding:10px 20px!important;border-bottom:1px solid var(--foodex-palette-edf1ef)!important;background:#fff!important}
.qm-progress span{display:flex;align-items:center;justify-content:center;min-height:38px;padding:0 12px;border-radius:11px;background:var(--foodex-palette-f5f7f6);color:var(--foodex-palette-667085);font-size:.68rem;font-weight:900;transition:.18s ease}
.qm-progress span[aria-current="step"]{background:var(--foodex-palette-e9f7ed);color:var(--foodex-palette-146c36);box-shadow:inset 0 0 0 1px var(--foodex-palette-afd4b8)}

.qm-body{flex:0 1 auto!important;min-height:240px!important;max-height:calc(100dvh - 238px)!important;width:100%!important;overflow:auto!important;overscroll-behavior:contain;padding:16px 20px 18px!important;background:var(--foodex-palette-f8faf9)!important}
.qm-step{width:100%!important;max-width:none!important;margin:0!important;display:grid!important;gap:12px!important}
.qm-step[hidden]{display:none!important}
.qm-step[data-qm-step="1"]{max-width:820px!important;margin-inline:auto!important}
.qm-copy{padding:10px 12px;border:1px solid var(--foodex-palette-e2e8e4);border-radius:11px;background:#fff;color:var(--foodex-palette-52605a);font-size:.69rem;line-height:1.55}
.qm-error{display:none;padding:9px 11px;margin-bottom:10px;border:1px solid var(--foodex-palette-fecaca);border-radius:10px;background:var(--foodex-palette-fff1f2);color:var(--foodex-palette-b91c1c);font-size:.68rem;font-weight:800}.qm-error.is-visible{display:block}

.qm-form-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}
.qm-field{display:grid;gap:5px;min-width:0;color:var(--foodex-palette-334155);font-size:.68rem;font-weight:900}
.qm-field input,.qm-field select{box-sizing:border-box;width:100%!important;min-width:0!important;min-height:42px!important;border:1px solid var(--foodex-palette-d6dfda)!important;border-radius:10px!important;background:#fff!important;padding:0 11px!important;color:var(--foodex-palette-18251f)!important;font:inherit!important}
.qm-field input:focus,.qm-field select:focus{outline:2px solid rgba(21,138,58,.13);border-color:var(--foodex-palette-7db98c)!important}

.qm-upload{display:grid;grid-template-columns:150px minmax(0,1fr);gap:14px;align-items:center;padding:12px;border:1px dashed var(--foodex-palette-a9ceb2);border-radius:13px;background:var(--foodex-palette-f7fcf8);cursor:pointer}
.qm-upload-preview{width:150px;height:108px;display:grid;place-items:center;overflow:hidden;border-radius:11px;background:var(--foodex-palette-eaf0ec);color:var(--foodex-palette-667085);font-size:.64rem;font-weight:800;text-align:center}.qm-upload-preview img{width:100%;height:100%;object-fit:cover}.qm-upload input[type=file]{min-height:auto!important;padding:8px!important}

.qm-toolbar{display:flex;align-items:center;justify-content:space-between;gap:8px;flex-wrap:wrap}
.qm-suggestions{display:flex;align-items:center;gap:6px;flex-wrap:wrap}.qm-chip{min-height:32px;padding:0 11px;border:1px solid var(--foodex-palette-cfd9d3);border-radius:999px;background:#fff;color:var(--foodex-palette-475569);font-size:.64rem;font-weight:800;cursor:pointer}.qm-chip:hover{border-color:var(--foodex-palette-83bd91);background:var(--foodex-palette-edf8f0);color:var(--foodex-palette-146c36)}
.qm-add{min-height:36px;padding:0 12px;border:1px dashed var(--foodex-palette-72ad82);border-radius:10px;background:var(--foodex-palette-eef9f1);color:var(--foodex-palette-146c36);font-size:.68rem;font-weight:900;cursor:pointer}

.qm-categories{display:grid!important;grid-template-columns:repeat(2,minmax(0,1fr))!important;gap:10px!important;width:100%!important}
.qm-category-row{position:relative;display:grid!important;grid-template-columns:108px minmax(0,1fr)!important;grid-template-areas:"image ar" "image en"!important;gap:8px 10px!important;align-items:start!important;width:100%!important;padding:11px!important;border:1px solid var(--foodex-palette-dce4df);border-radius:14px;background:#fff;box-shadow:0 4px 14px rgba(15,23,42,.035)}
.qm-category-row>.qm-image-tile{grid-area:image}.qm-category-row>.qm-name-ar{grid-area:ar}.qm-category-row>.qm-name-en{grid-area:en}
.qm-category-row>.qm-remove{position:absolute;top:7px;inset-inline-end:7px;z-index:2}
.qm-category-row .qm-field{padding-inline-end:34px}

.qm-image-tile{display:grid;gap:5px;min-width:0}.qm-image-box{width:108px;height:96px;display:grid;place-items:center;overflow:hidden;border:1px dashed var(--foodex-palette-c7d1cb);border-radius:11px;background:var(--foodex-palette-f4f7f5);color:var(--foodex-palette-667085);font-size:.59rem;font-weight:800;text-align:center;cursor:pointer}.qm-image-box img{width:100%;height:100%;object-fit:cover}.qm-image-tile input{position:absolute!important;width:1px!important;height:1px!important;opacity:0!important;pointer-events:none!important}

.qm-items{display:grid!important;gap:10px!important;width:100%!important}
.qm-item-row{position:relative;display:grid!important;grid-template-columns:112px 150px minmax(140px,1fr) minmax(140px,1fr) 118px 82px 38px!important;gap:8px!important;align-items:end!important;width:100%!important;padding:11px!important;border:1px solid var(--foodex-palette-dce4df);border-radius:14px;background:#fff;box-shadow:0 4px 14px rgba(15,23,42,.035)}
.qm-item-row .qm-image-box{width:112px;height:88px}.qm-category-select,.qm-name-ar,.qm-name-en,.qm-price{min-width:0}
.qm-featured{display:flex!important;align-items:center!important;justify-content:center!important;gap:5px!important;min-height:42px;padding:0 8px;border:1px solid var(--foodex-palette-d6dfda);border-radius:10px;background:#fff;font-size:.64rem;font-weight:900;white-space:nowrap}.qm-featured input{width:auto!important;min-height:0!important;margin:0}
.qm-remove{width:36px!important;height:36px;display:grid;place-items:center;border:1px solid var(--foodex-palette-fecaca);border-radius:9px;background:var(--foodex-palette-fff7f7);color:var(--foodex-palette-b91c1c);font-weight:900;cursor:pointer}.qm-remove:hover{background:var(--foodex-palette-fee2e2)}

.qm-footer{flex:0 0 auto!important;display:flex!important;align-items:center!important;justify-content:space-between!important;gap:10px!important;padding:12px 20px!important;border-top:1px solid var(--foodex-palette-e7ece9)!important;background:#fff!important}
.qm-footer-actions{display:flex;align-items:center;gap:7px}.qm-footer button{min-height:40px;padding:0 15px;border-radius:10px;font-size:.71rem;font-weight:900;cursor:pointer}.qm-back{border:1px solid var(--foodex-palette-d6dfda);background:#fff;color:var(--foodex-palette-243029)}.qm-next,.qm-save{border:1px solid var(--foodex-palette-158a3a);background:var(--foodex-palette-158a3a);color:#fff;box-shadow:0 5px 14px rgba(21,138,58,.14)}.qm-save[disabled]{opacity:.65;cursor:wait}
body.qm-open{overflow:hidden}

@media(max-width:1050px){
  .qm-dialog{width:calc(100vw - 20px)!important;max-height:calc(100dvh - 20px)!important}
  .qm-categories{grid-template-columns:1fr!important}
  .qm-item-row{grid-template-columns:100px 140px minmax(130px,1fr) minmax(130px,1fr) 110px!important}
  .qm-item-row>.qm-featured{grid-column:2/3}.qm-item-row>.qm-remove{grid-column:5/6;justify-self:end}
}
@media(max-width:760px){
  .qm-modal{padding:5px!important}.qm-dialog{width:calc(100vw - 10px)!important;max-height:calc(100dvh - 10px)!important;border-radius:14px!important}
  .qm-head,.qm-body,.qm-footer{padding-inline:12px!important}.qm-progress{padding-inline:10px!important;gap:5px!important}.qm-progress span{min-height:34px;font-size:.6rem;padding-inline:5px}
  .qm-form-grid{grid-template-columns:1fr}.qm-upload{grid-template-columns:1fr}.qm-upload-preview{width:100%;height:150px}
  .qm-category-row{grid-template-columns:90px minmax(0,1fr)!important}.qm-image-box{width:90px}
  .qm-item-row{grid-template-columns:90px minmax(0,1fr)!important;align-items:start!important}
  .qm-item-row>.qm-image-tile{grid-row:1/4}.qm-item-row>.qm-category-select,.qm-item-row>.qm-name-ar,.qm-item-row>.qm-name-en,.qm-item-row>.qm-price{grid-column:2/3}
  .qm-item-row>.qm-featured{grid-column:2/3;justify-self:start}.qm-item-row>.qm-remove{position:absolute;top:8px;inset-inline-end:8px}
}
</style>

@if($site instanceof \App\Domain\SmartOrdering\Models\SmartOrderingSite && $canApply)
<div class="qm-card" id="menu-builder" data-qm-card>
    <div class="qm-card-head">
        <div class="qm-card-title">
            <strong>{{ $quickMenuIsAr ? 'إدارة المنيو بسهولة' : 'Easy menu management' }}</strong>
            <span>{{ $quickMenuIsAr ? 'من نفس الصفحة: المنيو، التصنيفات، الصور، الأصناف والأسعار.' : 'Menu, categories, images, items and prices from this page.' }}</span>
        </div>
        <span aria-hidden="true">🍽️</span>
    </div>
    <div class="qm-card-stats">
        <span class="qm-stat">{{ $menuCount }} {{ $quickMenuIsAr ? 'منيو' : 'menus' }}</span>
        <span class="qm-stat">{{ $menuItemCount }} {{ $quickMenuIsAr ? 'صنف' : 'items' }}</span>
        @if($quickMenuCurrency)<span class="qm-stat">{{ $quickMenuCurrency }}</span>@endif
    </div>
    <div class="qm-card-actions">
        <button class="foodex-action-primary" type="button" data-qm-open>{{ $menuCount > 0 ? ($quickMenuIsAr ? 'إضافة منيو' : 'Add menu') : ($quickMenuIsAr ? 'إنشاء أول منيو' : 'Create first menu') }}</button>
        <a class="foodex-action-secondary" href="{{ route('admin.smart-ordering.menu.index',['store_id'=>$site->store_id]) }}">{{ $quickMenuIsAr ? 'المحرر المتقدم' : 'Advanced editor' }}</a>
    </div>
</div>

<div class="qm-modal" data-qm-modal role="dialog" aria-modal="true" aria-labelledby="qm-title" @if(!$quickMenuShouldOpen) hidden @endif>
<form class="qm-dialog" method="post" enctype="multipart/form-data" action="{{ $quickMenuInitialAction }}" data-qm-form novalidate>
    @csrf
    <input type="hidden" name="_method" value="PUT" data-qm-method @disabled($quickMenuMode !== 'edit')>
    <input type="hidden" name="store_id" value="{{ $site->store_id }}">
    <input type="hidden" name="_quick_menu" value="1">
    <input type="hidden" name="_quick_menu_mode" value="{{ $quickMenuMode }}" data-qm-mode>
    <input type="hidden" name="quick_menu_id" value="{{ $quickMenuEditingId ?: '' }}" data-qm-edit-id>
    @if(($quickMenuReturnTo ?? null) === 'menu_setup')
        <input type="hidden" name="return_to" value="menu_setup">
    @endif

    <header class="qm-head">
        <div>
            <h2 id="qm-title" data-qm-title>{{ $quickMenuMode === 'edit' ? ($quickMenuIsAr ? 'تعديل المنيو بسهولة' : 'Edit menu easily') : ($quickMenuIsAr ? 'إنشاء المنيو بسهولة' : 'Create menu easily') }}</h2>
            <p data-qm-subtitle>{{ $quickMenuMode === 'edit' ? ($quickMenuIsAr ? 'عدّل المنيو الحالية والتصنيفات والأصناف من نفس الخطوات الثلاث.' : 'Edit the current menu, categories and items in the same three steps.') : ($quickMenuIsAr ? 'كل شيء أساسي في 3 خطوات واضحة، بدون المحرر المعقد.' : 'Everything essential in 3 clear steps.') }}</p>
        </div>
        <button class="qm-close" type="button" data-qm-close aria-label="{{ $quickMenuIsAr ? 'إغلاق' : 'Close' }}">×</button>
    </header>

    <div class="qm-progress">
        <span aria-current="step" data-qm-progress="1">1 · {{ $quickMenuIsAr ? 'المنيو' : 'Menu' }}</span>
        <span data-qm-progress="2">2 · {{ $quickMenuIsAr ? 'التصنيفات' : 'Categories' }}</span>
        <span data-qm-progress="3">3 · {{ $quickMenuIsAr ? 'الأصناف' : 'Items' }}</span>
    </div>

    <div class="qm-body">
        @if($quickMenuShouldOpen && $errors->any())
            <div class="foodex-alert error" role="alert">{{ $errors->first() }}</div>
        @endif
        <div class="qm-error" data-qm-error role="alert"></div>

        <section class="qm-step" data-qm-step="1">
            <div class="qm-copy">{{ $quickMenuIsAr ? 'اسم المنيو وصورتها الرئيسية. الصورة اختيارية ويمكن تغييرها لاحقاً.' : 'Menu name and cover image. The image is optional.' }}</div>
            <div class="qm-form-grid">
                <label class="qm-field">{{ $quickMenuIsAr ? 'اسم المنيو بالعربي' : 'Arabic menu name' }}
                    <input type="text" name="menu_name_ar" value="{{ old('menu_name_ar') }}" placeholder="{{ $quickMenuIsAr ? 'المنيو الرئيسية' : 'Main menu' }}">
                </label>
                <label class="qm-field">{{ $quickMenuIsAr ? 'اسم المنيو بالإنجليزي' : 'English menu name' }}
                    <input type="text" name="menu_name_en" value="{{ old('menu_name_en') }}" placeholder="{{ $quickMenuIsAr ? 'المنيو الرئيسية' : 'Main menu' }}">
                </label>
            </div>
            <label class="qm-upload">
                <span class="qm-upload-preview" data-image-preview>{{ $quickMenuIsAr ? 'صورة المنيو' : 'Menu cover' }}</span>
                <span class="qm-field">{{ $quickMenuIsAr ? 'صورة المنيو الرئيسية' : 'Menu cover image' }}
                    <input type="file" name="menu_image" accept="image/jpeg,image/png,image/webp" data-image-input>
                    <small>{{ $quickMenuIsAr ? 'JPG / PNG / WEBP حتى 3MB' : 'JPG / PNG / WEBP up to 3MB' }}</small>
                </span>
            </label>
        </section>

        <section class="qm-step" data-qm-step="2" hidden>
            <div class="qm-copy">{{ $quickMenuIsAr ? 'أنشئ كل التصنيفات هنا، واحذف أو أضف أي تصنيف قبل الحفظ. صورة التصنيف اختيارية.' : 'Create and manage all categories here. Category images are optional.' }}</div>
            <div class="qm-toolbar">
                <div class="qm-suggestions">
                    @foreach(($quickMenuIsAr ? ['الأطباق الرئيسية','المقبلات','المشروبات','الحلويات'] : ['Main dishes','Starters','Drinks','Desserts']) as $suggestion)
                        <button class="qm-chip" type="button" data-qm-category-suggestion="{{ $suggestion }}">{{ $suggestion }}</button>
                    @endforeach
                </div>
                <button class="qm-add" type="button" data-qm-add-category>+ {{ $quickMenuIsAr ? 'إضافة تصنيف' : 'Add category' }}</button>
            </div>
            <div class="qm-categories" data-qm-categories>
                @foreach($quickMenuCategories as $categoryKey => $category)
                <div class="qm-category-row" data-qm-category data-category-key="{{ $categoryKey }}">
                    @if(!empty($category['id']))<input type="hidden" name="categories[{{ $categoryKey }}][id]" value="{{ $category['id'] }}">@endif
                    <label class="qm-image-tile">
                        <span class="qm-image-box" data-image-preview>{{ $quickMenuIsAr ? 'صورة التصنيف' : 'Category image' }}</span>
                        <input type="file" name="categories[{{ $categoryKey }}][image]" accept="image/jpeg,image/png,image/webp" data-image-input>
                    </label>
                    <label class="qm-field qm-name-ar">{{ $quickMenuIsAr ? 'الاسم بالعربي' : 'Arabic name' }}
                        <input type="text" name="categories[{{ $categoryKey }}][name_ar]" value="{{ $category['name_ar'] ?? '' }}" data-qm-category-ar> {{-- localization-gate: allow — quick-menu bilingual authoring intentionally exposes the Arabic category name. --}}
                    </label>
                    <label class="qm-field qm-name-en">{{ $quickMenuIsAr ? 'الاسم بالإنجليزي' : 'English name' }}
                        <input type="text" name="categories[{{ $categoryKey }}][name_en]" value="{{ $category['name_en'] ?? '' }}" data-qm-category-en> {{-- localization-gate: allow — quick-menu bilingual authoring intentionally exposes the English category name. --}}
                    </label>
                    <button class="qm-remove" type="button" data-qm-remove-category aria-label="{{ $quickMenuIsAr ? 'حذف التصنيف' : 'Remove category' }}">×</button>
                </div>
                @endforeach
            </div>
        </section>

        <section class="qm-step" data-qm-step="3" hidden>
            <div class="qm-copy">{{ $quickMenuIsAr ? 'لكل صنف: اختر التصنيف، أضف الصورة، الاسم والسعر. يمكنك إضافة أو حذف الأصناف بحرية.' : 'For each item choose a category, image, name and price.' }}</div>
            <div class="qm-toolbar">
                <span class="qm-stat" data-qm-item-count></span>
                <button class="qm-add" type="button" data-qm-add-item>+ {{ $quickMenuIsAr ? 'إضافة صنف' : 'Add item' }}</button>
            </div>
            <div class="qm-items" data-qm-items>
                @foreach($quickMenuItems as $itemKey => $item)
                <div class="qm-item-row" data-qm-item>
                    @if(!empty($item['id']))<input type="hidden" name="items[{{ $itemKey }}][id]" value="{{ $item['id'] }}">@endif
                    <label class="qm-image-tile">
                        <span class="qm-image-box" data-image-preview>{{ $quickMenuIsAr ? 'صورة الصنف' : 'Item image' }}</span>
                        <input type="file" name="items[{{ $itemKey }}][image]" accept="image/jpeg,image/png,image/webp" data-image-input>
                    </label>
                    <label class="qm-field qm-category-select">{{ $quickMenuIsAr ? 'التصنيف' : 'Category' }}
                        <select name="items[{{ $itemKey }}][category_key]" data-qm-item-category>
                            @foreach($quickMenuCategories as $categoryKey => $category)
                                @php($categoryLabel = trim((string)($category[$quickMenuIsAr ? 'name_ar' : 'name_en'] ?? '')) ?: trim((string)($category['name_ar'] ?? $category['name_en'] ?? '')))
                                <option value="{{ $categoryKey }}" @selected((string)($item['category_key'] ?? '') === (string)$categoryKey)>{{ $categoryLabel !== '' ? $categoryLabel : (($quickMenuIsAr ? 'تصنيف ' : 'Category ').((int)$loop->index+1)) }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label class="qm-field qm-name-ar">{{ $quickMenuIsAr ? 'الاسم بالعربي' : 'Arabic name' }}
                        <input type="text" name="items[{{ $itemKey }}][name_ar]" value="{{ $item['name_ar'] ?? '' }}"> {{-- localization-gate: allow — quick-menu bilingual authoring intentionally exposes the Arabic item name. --}}
                    </label>
                    <label class="qm-field qm-name-en">{{ $quickMenuIsAr ? 'الاسم بالإنجليزي' : 'English name' }}
                        <input type="text" name="items[{{ $itemKey }}][name_en]" value="{{ $item['name_en'] ?? '' }}"> {{-- localization-gate: allow — quick-menu bilingual authoring intentionally exposes the English item name. --}}
                    </label>
                    <label class="qm-field qm-price">{{ $quickMenuIsAr ? 'السعر' : 'Price' }} @if($quickMenuCurrency)<small>({{ $quickMenuCurrency }})</small>@endif
                        <input type="number" step="0.001" min="0" name="items[{{ $itemKey }}][price]" value="{{ $item['price'] ?? '' }}" placeholder="0.000">
                    </label>
                    <label class="qm-featured"><input type="checkbox" name="items[{{ $itemKey }}][featured]" value="1" @checked((bool)($item['featured'] ?? false))> {{ $quickMenuIsAr ? 'مميز' : 'Featured' }}</label>
                    <button class="qm-remove" type="button" data-qm-remove-item aria-label="{{ $quickMenuIsAr ? 'حذف الصنف' : 'Remove item' }}">×</button>
                </div>
                @endforeach
            </div>
        </section>
    </div>

    <footer class="qm-footer">
        <button class="qm-back" type="button" data-qm-back hidden>{{ $quickMenuIsAr ? 'السابق' : 'Back' }}</button>
        <div class="qm-footer-actions">
            <button class="qm-next" type="button" data-qm-next>{{ $quickMenuIsAr ? 'التالي' : 'Next' }}</button>
            <button class="qm-save" type="submit" data-qm-save hidden>{{ $quickMenuMode === 'edit' ? ($quickMenuIsAr ? 'حفظ التعديلات' : 'Save changes') : ($quickMenuIsAr ? 'حفظ المنيو كمسودة' : 'Save menu as draft') }}</button>
        </div>
    </footer>
</form>
</div>

<script>
(() => {
    const modal = document.querySelector('[data-qm-modal]');
    const openButton = document.querySelector('[data-qm-open]');
    const closeButton = modal?.querySelector('[data-qm-close]');
    const form = modal?.querySelector('[data-qm-form]');
    if (!modal || !openButton || !closeButton || !form) return;

    const steps = [...modal.querySelectorAll('[data-qm-step]')];
    const progress = [...modal.querySelectorAll('[data-qm-progress]')];
    const back = modal.querySelector('[data-qm-back]');
    const next = modal.querySelector('[data-qm-next]');
    const save = modal.querySelector('[data-qm-save]');
    const error = modal.querySelector('[data-qm-error]');
    const categories = modal.querySelector('[data-qm-categories]');
    const items = modal.querySelector('[data-qm-items]');
    const itemCount = modal.querySelector('[data-qm-item-count]');
    let step = 1;
    let categoryIndex = Math.max(0, ...[...categories.querySelectorAll('[data-qm-category]')].map(row => Number(row.dataset.categoryKey) || 0)) + 1;
    let itemIndex = items.querySelectorAll('[data-qm-item]').length;

    const t = {
        menu: @json($quickMenuIsAr ? 'اكتب اسم المنيو بالعربي أو الإنجليزي.' : 'Enter the menu name.'),
        category: @json($quickMenuIsAr ? 'أضف تصنيفاً واحداً على الأقل واكتب اسمه.' : 'Add at least one named category.'),
        items: @json($quickMenuIsAr ? 'أضف صنفاً واحداً على الأقل، وحدد التصنيف والاسم والسعر.' : 'Add at least one item with category, name and price.'),
        saving: @json($quickMenuIsAr ? 'جاري الحفظ...' : 'Saving...'),
        itemWord: @json($quickMenuIsAr ? 'صنف' : 'items'),
        categoryWord: @json($quickMenuIsAr ? 'تصنيف' : 'Category'),
        categoryImage: @json($quickMenuIsAr ? 'صورة التصنيف' : 'Category image'),
        itemImage: @json($quickMenuIsAr ? 'صورة الصنف' : 'Item image'),
        arabicName: @json($quickMenuIsAr ? 'الاسم بالعربي' : 'Arabic name'),
        englishName: @json($quickMenuIsAr ? 'الاسم بالإنجليزي' : 'English name'),
        categoryLabel: @json($quickMenuIsAr ? 'التصنيف' : 'Category'),
        price: @json($quickMenuIsAr ? 'السعر' : 'Price'),
        featured: @json($quickMenuIsAr ? 'مميز' : 'Featured'),
    };

    const showError = (message) => {
        error.textContent = message || '';
        error.classList.toggle('is-visible', Boolean(message));
        if (message) error.scrollIntoView({block:'nearest'});
    };

    const open = () => {
        modal.hidden = false;
        document.body.classList.add('qm-open');
        requestAnimationFrame(() => modal.querySelector('input:not([type="hidden"])')?.focus({preventScroll:true}));
    };
    const close = () => {
        modal.hidden = true;
        document.body.classList.remove('qm-open');
        showError('');
        openButton.focus({preventScroll:true});
    };

    const categoryRows = () => [...categories.querySelectorAll('[data-qm-category]')];
    const itemRows = () => [...items.querySelectorAll('[data-qm-item]')];
    const rowName = (row) => {
        const ar = row.querySelector('[data-qm-category-ar]')?.value.trim() || '';
        const en = row.querySelector('[data-qm-category-en]')?.value.trim() || '';
        return (@json($quickMenuIsAr) ? ar || en : en || ar) || (t.categoryWord + ' ' + (categoryRows().indexOf(row) + 1));
    };

    const refreshCategoryOptions = () => {
        const source = categoryRows().map(row => ({key:String(row.dataset.categoryKey), label:rowName(row)}));
        itemRows().forEach(row => {
            const select = row.querySelector('[data-qm-item-category]');
            const current = select.value;
            select.innerHTML = '';
            source.forEach(option => {
                const node = document.createElement('option');
                node.value = option.key;
                node.textContent = option.label;
                select.append(node);
            });
            if (source.some(option => option.key === current)) select.value = current;
        });
    };

    const updateCount = () => {
        itemCount.textContent = itemRows().length + ' ' + t.itemWord;
    };

    const bindImagePreview = (root) => {
        root.querySelectorAll('[data-image-input]').forEach(input => {
            if (input.dataset.previewBound === '1') return;
            input.dataset.previewBound = '1';
            input.addEventListener('change', () => {
                const preview = input.closest('label')?.querySelector('[data-image-preview]') || input.parentElement?.parentElement?.querySelector('[data-image-preview]');
                const file = input.files?.[0];
                if (!preview || !file) return;
                const url = URL.createObjectURL(file);
                preview.innerHTML = '';
                const img = document.createElement('img');
                img.src = url;
                img.alt = '';
                img.onload = () => URL.revokeObjectURL(url);
                preview.append(img);
            });
        });
    };

    const render = () => {
        steps.forEach(node => { node.hidden = Number(node.dataset.qmStep) !== step; });
        progress.forEach(node => {
            if (Number(node.dataset.qmProgress) === step) node.setAttribute('aria-current','step');
            else node.removeAttribute('aria-current');
        });
        back.hidden = step === 1;
        next.hidden = step === 3;
        save.hidden = step !== 3;
        showError('');
        if (step === 3) {
            refreshCategoryOptions();
            updateCount();
        }
    };

    const validate = () => {
        if (step === 1) {
            const ar = form.querySelector('[name="menu_name_ar"]')?.value.trim() || '';
            const en = form.querySelector('[name="menu_name_en"]')?.value.trim() || '';
            if (!ar && !en) { showError(t.menu); return false; }
        }
        if (step === 2) {
            const rows = categoryRows();
            if (!rows.length || rows.some(row => {
                const ar = row.querySelector('[data-qm-category-ar]')?.value.trim() || '';
                const en = row.querySelector('[data-qm-category-en]')?.value.trim() || '';
                return !ar && !en;
            })) { showError(t.category); return false; }
        }
        if (step === 3) {
            const rows = itemRows();
            const invalid = !rows.length || rows.some(row => {
                const ar = row.querySelector('input[name$="[name_ar]"]')?.value.trim() || '';
                const en = row.querySelector('input[name$="[name_en]"]')?.value.trim() || '';
                const price = row.querySelector('input[name$="[price]"]')?.value ?? '';
                const category = row.querySelector('[data-qm-item-category]')?.value || '';
                return (!ar && !en) || price === '' || category === '';
            });
            if (invalid) { showError(t.items); return false; }
        }
        return true;
    };

    const makeImageTile = (name, placeholder) => {
        const label = document.createElement('label');
        label.className = 'qm-image-tile';
        const box = document.createElement('span');
        box.className = 'qm-image-box';
        box.dataset.imagePreview = '';
        box.textContent = placeholder;
        const input = document.createElement('input');
        input.type = 'file';
        input.name = name;
        input.accept = 'image/jpeg,image/png,image/webp';
        input.dataset.imageInput = '';
        label.append(box,input);
        return label;
    };

    const makeField = (labelText, input) => {
        const label = document.createElement('label');
        label.className = 'qm-field';
        label.append(document.createTextNode(labelText), input);
        return label;
    };

    const addCategory = (prefill = '') => {
        const key = String(categoryIndex++);
        const row = document.createElement('div');
        row.className = 'qm-category-row';
        row.dataset.qmCategory = '';
        row.dataset.categoryKey = key;
        row.append(makeImageTile('categories['+key+'][image]', t.categoryImage));

        const ar = document.createElement('input');
        ar.type='text'; ar.name='categories['+key+'][name_ar]'; ar.dataset.qmCategoryAr=''; if (@json($quickMenuIsAr)) ar.value=prefill;
        const en = document.createElement('input');
        en.type='text'; en.name='categories['+key+'][name_en]'; en.dataset.qmCategoryEn=''; if (!@json($quickMenuIsAr)) en.value=prefill;
        const arField = makeField(t.arabicName, ar); arField.classList.add('qm-name-ar');
        const enField = makeField(t.englishName, en); enField.classList.add('qm-name-en');
        row.append(arField, enField);

        const remove = document.createElement('button');
        remove.type='button'; remove.className='qm-remove'; remove.dataset.qmRemoveCategory=''; remove.textContent='×';
        row.append(remove);
        categories.append(row);
        bindImagePreview(row);
        refreshCategoryOptions();
        (prefill ? ( @json($quickMenuIsAr) ? ar : en ) : ar).focus();
    };

    const addItem = () => {
        const key = String(itemIndex++);
        const row = document.createElement('div');
        row.className='qm-item-row'; row.dataset.qmItem='';
        row.append(makeImageTile('items['+key+'][image]', t.itemImage));

        const select = document.createElement('select');
        select.name='items['+key+'][category_key]'; select.dataset.qmItemCategory='';
        const categoryField = makeField(t.categoryLabel, select); categoryField.classList.add('qm-category-select');
        row.append(categoryField);

        const ar=document.createElement('input'); ar.type='text'; ar.name='items['+key+'][name_ar]';
        const en=document.createElement('input'); en.type='text'; en.name='items['+key+'][name_en]';
        const price=document.createElement('input'); price.type='number'; price.step='0.001'; price.min='0'; price.name='items['+key+'][price]'; price.placeholder='0.000';
        const arField = makeField(t.arabicName, ar); arField.classList.add('qm-name-ar');
        const enField = makeField(t.englishName, en); enField.classList.add('qm-name-en');
        const priceField = makeField(t.price, price); priceField.classList.add('qm-price');
        row.append(arField, enField, priceField);

        const featured=document.createElement('label'); featured.className='qm-featured';
        const check=document.createElement('input'); check.type='checkbox'; check.name='items['+key+'][featured]'; check.value='1';
        featured.append(check,document.createTextNode(' '+t.featured));
        row.append(featured);

        const remove=document.createElement('button'); remove.type='button'; remove.className='qm-remove'; remove.dataset.qmRemoveItem=''; remove.textContent='×';
        row.append(remove);
        items.append(row);
        bindImagePreview(row);
        refreshCategoryOptions();
        updateCount();
        ar.focus();
    };

    openButton.addEventListener('click',open);
    closeButton.addEventListener('click',close);
    modal.addEventListener('pointerdown',event=>{if(event.target===modal)close();});
    document.addEventListener('keydown',event=>{if(event.key==='Escape'&&!modal.hidden)close();});

    next.addEventListener('click',()=>{if(!validate())return;step=Math.min(3,step+1);render();});
    back.addEventListener('click',()=>{step=Math.max(1,step-1);render();});
    modal.querySelector('[data-qm-add-category]')?.addEventListener('click',()=>addCategory());
    modal.querySelector('[data-qm-add-item]')?.addEventListener('click',addItem);

    modal.querySelectorAll('[data-qm-category-suggestion]').forEach(button=>{
        button.addEventListener('click',()=>{
            const blank=categoryRows().find(row=>{
                const ar=row.querySelector('[data-qm-category-ar]')?.value.trim()||'';
                const en=row.querySelector('[data-qm-category-en]')?.value.trim()||'';
                return !ar&&!en;
            });
            if(blank){
                const target=@json($quickMenuIsAr)?blank.querySelector('[data-qm-category-ar]'):blank.querySelector('[data-qm-category-en]');
                target.value=button.dataset.qmCategorySuggestion||'';
                target.focus();
                refreshCategoryOptions();
            }else addCategory(button.dataset.qmCategorySuggestion||'');
        });
    });

    categories.addEventListener('input',event=>{
        if(event.target.matches('[data-qm-category-ar],[data-qm-category-en]'))refreshCategoryOptions();
    });
    categories.addEventListener('click',event=>{
        const button=event.target.closest('[data-qm-remove-category]');
        if(!button)return;
        if(categoryRows().length<=1){showError(t.category);return;}
        button.closest('[data-qm-category]')?.remove();
        refreshCategoryOptions();
    });
    items.addEventListener('click',event=>{
        const button=event.target.closest('[data-qm-remove-item]');
        if(!button)return;
        if(itemRows().length<=1){showError(t.items);return;}
        button.closest('[data-qm-item]')?.remove();
        updateCount();
    });

    form.addEventListener('submit',event=>{
        step=3;
        render();
        if(!validate()){event.preventDefault();return;}
        save.disabled=true;
        save.textContent=t.saving;
    });

    bindImagePreview(modal);
    refreshCategoryOptions();
    updateCount();
    if(!modal.hidden)document.body.classList.add('qm-open');
    render();
})();
</script>
@endif
