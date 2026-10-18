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
@endphp

<style>
.qm-card{display:grid;gap:10px;padding:12px;border:1px solid #bfe0c7;border-radius:14px;background:linear-gradient(135deg,#f4fbf6,#fff);box-shadow:0 8px 24px rgba(21,138,58,.06)}
.qm-card-head{display:flex;align-items:flex-start;justify-content:space-between;gap:10px}.qm-card-title{display:grid;gap:2px}.qm-card-title strong{font-size:.86rem}.qm-card-title span{font-size:.68rem;color:var(--foodex-muted);line-height:1.5}
.qm-card-stats,.qm-card-actions{display:flex;gap:6px;flex-wrap:wrap;align-items:center}.qm-stat{display:inline-flex;align-items:center;min-height:24px;padding:0 8px;border:1px solid var(--foodex-border);border-radius:999px;background:#fff;font-size:.62rem;font-weight:900;color:#475569}
.qm-card-actions .foodex-action-primary,.qm-card-actions .foodex-action-secondary{min-height:36px;padding-inline:12px;border-radius:10px;font-size:.72rem}
.qm-modal{position:fixed;z-index:14050;inset:0;display:grid;place-items:center;padding:14px;background:rgba(15,23,42,.56);backdrop-filter:blur(7px)}
.qm-modal[hidden]{display:none!important}.qm-dialog{width:min(1040px,97vw);height:min(820px,94dvh);display:grid;grid-template-rows:auto auto minmax(0,1fr) auto;overflow:hidden;border:1px solid var(--foodex-border);border-radius:20px;background:#fff;box-shadow:0 32px 90px rgba(15,23,42,.32)}
.qm-head{display:flex;align-items:flex-start;justify-content:space-between;gap:14px;padding:16px 18px;border-bottom:1px solid var(--foodex-border);background:linear-gradient(135deg,#f7fbf8,#eef8f1)}
.qm-head h2{margin:0;font-size:1.05rem}.qm-head p{margin:4px 0 0;color:var(--foodex-muted);font-size:.71rem}.qm-close{width:36px;height:36px;display:grid;place-items:center;border:1px solid var(--foodex-border);border-radius:999px;background:#fff;color:var(--foodex-ink);font-size:1.1rem;font-weight:900;cursor:pointer}
.qm-progress{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:7px;padding:10px 18px;border-bottom:1px solid var(--foodex-border);background:#fff}.qm-progress span{display:flex;align-items:center;justify-content:center;gap:6px;min-height:36px;border-radius:10px;background:#f8fafc;color:#64748b;font-size:.67rem;font-weight:900}.qm-progress span[aria-current="step"]{background:var(--foodex-green-soft);color:var(--foodex-green-dark);box-shadow:inset 0 0 0 1px #b9d8c1}
.qm-body{min-height:0;overflow:auto;padding:16px 18px;background:#fbfcfd}.qm-step{display:grid;gap:12px;max-width:100%}.qm-step[hidden]{display:none!important}.qm-copy{padding:10px 12px;border:1px solid #e2e8f0;border-radius:12px;background:#fff;color:#475569;font-size:.7rem;line-height:1.55}
.qm-form-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}.qm-field{display:grid;gap:5px;font-size:.68rem;font-weight:900;color:#334155}.qm-field input,.qm-field select{width:100%;min-height:42px;border:1px solid var(--foodex-border);border-radius:10px;background:#fff;padding:0 11px;font:inherit}.qm-field input:focus,.qm-field select:focus{outline:2px solid rgba(21,138,58,.16);border-color:#8bc79a}
.qm-upload{display:grid;grid-template-columns:92px minmax(0,1fr);gap:10px;align-items:center;padding:10px;border:1px dashed #b9d8c1;border-radius:12px;background:#f8fcf9}.qm-upload-preview{width:92px;height:72px;display:grid;place-items:center;overflow:hidden;border-radius:10px;background:#eef3ef;color:#64748b;font-size:.62rem;font-weight:800;text-align:center}.qm-upload-preview img{width:100%;height:100%;object-fit:cover}.qm-upload input[type="file"]{min-height:auto;padding:7px;background:#fff}
.qm-toolbar{display:flex;align-items:center;justify-content:space-between;gap:8px;flex-wrap:wrap}.qm-suggestions{display:flex;gap:6px;flex-wrap:wrap}.qm-chip{min-height:31px;padding:0 10px;border:1px solid #cbd5e1;border-radius:999px;background:#fff;color:#475569;font-size:.64rem;font-weight:800;cursor:pointer}.qm-chip:hover{border-color:#8bc79a;background:var(--foodex-green-soft);color:var(--foodex-green-dark)}
.qm-add{min-height:35px;padding:0 11px;border:1px dashed #86b893;border-radius:10px;background:#f5fbf6;color:var(--foodex-green-dark);font-size:.67rem;font-weight:900;cursor:pointer}
.qm-categories,.qm-items{display:grid;gap:9px}.qm-category-row{display:grid;grid-template-columns:90px minmax(0,1fr) minmax(0,1fr) auto;gap:8px;align-items:end;padding:10px;border:1px solid var(--foodex-border);border-radius:13px;background:#fff;box-shadow:0 3px 12px rgba(15,23,42,.025)}
.qm-image-tile{display:grid;gap:5px}.qm-image-box{width:90px;height:72px;display:grid;place-items:center;overflow:hidden;border:1px dashed #cbd5e1;border-radius:10px;background:#f8fafc;color:#64748b;font-size:.58rem;font-weight:800;text-align:center;cursor:pointer}.qm-image-box img{width:100%;height:100%;object-fit:cover}.qm-image-tile input{position:absolute;opacity:0;pointer-events:none}
.qm-item-row{display:grid;grid-template-columns:88px 150px minmax(0,1fr) minmax(0,1fr) 120px 78px auto;gap:8px;align-items:end;padding:10px;border:1px solid var(--foodex-border);border-radius:13px;background:#fff;box-shadow:0 3px 12px rgba(15,23,42,.025)}
.qm-featured{display:flex;align-items:center;justify-content:center;gap:5px;min-height:42px;padding:0 8px;border:1px solid var(--foodex-border);border-radius:10px;background:#fff;font-size:.64rem;font-weight:900;white-space:nowrap}.qm-featured input{width:auto;min-height:0}
.qm-remove{width:36px;height:36px;border:1px solid #fecaca;border-radius:9px;background:#fff7f7;color:#b91c1c;font-weight:900;cursor:pointer}.qm-remove:hover{background:#fee2e2}.qm-empty{padding:14px;border:1px dashed #cbd5e1;border-radius:12px;background:#fff;color:#64748b;text-align:center;font-size:.7rem}
.qm-error{display:none;padding:9px 11px;border:1px solid #fecaca;border-radius:10px;background:#fff1f2;color:#b91c1c;font-size:.68rem;font-weight:800}.qm-error.is-visible{display:block}
.qm-footer{display:flex;align-items:center;justify-content:space-between;gap:10px;padding:12px 18px;border-top:1px solid var(--foodex-border);background:#fff}.qm-footer-actions{display:flex;align-items:center;gap:7px}.qm-footer button{min-height:40px;padding:0 14px;border-radius:10px;font-size:.71rem;font-weight:900;cursor:pointer}.qm-back{border:1px solid var(--foodex-border);background:#fff;color:var(--foodex-ink)}.qm-next,.qm-save{border:1px solid var(--foodex-action-green);background:var(--foodex-action-green);color:#fff}.qm-save[disabled]{opacity:.65;cursor:wait}
body.qm-open{overflow:hidden}
@media(max-width:900px){.qm-dialog{width:100%;height:96dvh}.qm-item-row{grid-template-columns:80px 130px 1fr 1fr}.qm-item-row>.qm-field:nth-of-type(4),.qm-featured,.qm-remove{grid-row:auto}.qm-category-row{grid-template-columns:80px 1fr 1fr auto}.qm-image-box{width:80px}}
@media(max-width:650px){.qm-modal{padding:5px}.qm-dialog{height:calc(100dvh - 10px);border-radius:14px}.qm-head,.qm-body,.qm-footer{padding-inline:11px}.qm-progress{padding-inline:10px}.qm-progress span{font-size:.6rem}.qm-form-grid{grid-template-columns:1fr}.qm-category-row,.qm-item-row{grid-template-columns:1fr 1fr}.qm-image-tile{grid-column:1/-1}.qm-image-box{width:100%;height:120px}.qm-item-row .qm-field:nth-child(2){grid-column:1/-1}.qm-remove{justify-self:end}.qm-footer{position:sticky;bottom:0}}
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
<form class="qm-dialog" method="post" enctype="multipart/form-data" action="{{ route('admin.smart-ordering.menu.quick-create') }}" data-qm-form novalidate>
    @csrf
    <input type="hidden" name="store_id" value="{{ $site->store_id }}">
    <input type="hidden" name="_quick_menu" value="1">

    <header class="qm-head">
        <div>
            <h2 id="qm-title">{{ $quickMenuIsAr ? 'إنشاء المنيو بسهولة' : 'Create menu easily' }}</h2>
            <p>{{ $quickMenuIsAr ? 'كل شيء أساسي في 3 خطوات واضحة، بدون المحرر المعقد.' : 'Everything essential in 3 clear steps.' }}</p>
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
                    <input type="text" name="menu_name_en" value="{{ old('menu_name_en') }}" placeholder="Main menu">
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
                    <label class="qm-image-tile">
                        <span class="qm-image-box" data-image-preview>{{ $quickMenuIsAr ? 'صورة التصنيف' : 'Category image' }}</span>
                        <input type="file" name="categories[{{ $categoryKey }}][image]" accept="image/jpeg,image/png,image/webp" data-image-input>
                    </label>
                    <label class="qm-field">{{ $quickMenuIsAr ? 'الاسم بالعربي' : 'Arabic name' }}
                        <input type="text" name="categories[{{ $categoryKey }}][name_ar]" value="{{ $category['name_ar'] ?? '' }}" data-qm-category-ar>
                    </label>
                    <label class="qm-field">{{ $quickMenuIsAr ? 'الاسم بالإنجليزي' : 'English name' }}
                        <input type="text" name="categories[{{ $categoryKey }}][name_en]" value="{{ $category['name_en'] ?? '' }}" data-qm-category-en>
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
                    <label class="qm-image-tile">
                        <span class="qm-image-box" data-image-preview>{{ $quickMenuIsAr ? 'صورة الصنف' : 'Item image' }}</span>
                        <input type="file" name="items[{{ $itemKey }}][image]" accept="image/jpeg,image/png,image/webp" data-image-input>
                    </label>
                    <label class="qm-field">{{ $quickMenuIsAr ? 'التصنيف' : 'Category' }}
                        <select name="items[{{ $itemKey }}][category_key]" data-qm-item-category>
                            @foreach($quickMenuCategories as $categoryKey => $category)
                                @php($categoryLabel = trim((string)($category[$quickMenuIsAr ? 'name_ar' : 'name_en'] ?? '')) ?: trim((string)($category['name_ar'] ?? $category['name_en'] ?? '')))
                                <option value="{{ $categoryKey }}" @selected((string)($item['category_key'] ?? '') === (string)$categoryKey)>{{ $categoryLabel !== '' ? $categoryLabel : (($quickMenuIsAr ? 'تصنيف ' : 'Category ').((int)$loop->index+1)) }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label class="qm-field">{{ $quickMenuIsAr ? 'الاسم بالعربي' : 'Arabic name' }}
                        <input type="text" name="items[{{ $itemKey }}][name_ar]" value="{{ $item['name_ar'] ?? '' }}">
                    </label>
                    <label class="qm-field">{{ $quickMenuIsAr ? 'الاسم بالإنجليزي' : 'English name' }}
                        <input type="text" name="items[{{ $itemKey }}][name_en]" value="{{ $item['name_en'] ?? '' }}">
                    </label>
                    <label class="qm-field">{{ $quickMenuIsAr ? 'السعر' : 'Price' }} @if($quickMenuCurrency)<small>({{ $quickMenuCurrency }})</small>@endif
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
            <button class="qm-save" type="submit" data-qm-save hidden>{{ $quickMenuIsAr ? 'حفظ المنيو كمسودة' : 'Save menu as draft' }}</button>
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
        row.append(makeField(t.arabicName,ar),makeField(t.englishName,en));

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
        row.append(makeField(t.categoryLabel,select));

        const ar=document.createElement('input'); ar.type='text'; ar.name='items['+key+'][name_ar]';
        const en=document.createElement('input'); en.type='text'; en.name='items['+key+'][name_en]';
        const price=document.createElement('input'); price.type='number'; price.step='0.001'; price.min='0'; price.name='items['+key+'][price]'; price.placeholder='0.000';
        row.append(makeField(t.arabicName,ar),makeField(t.englishName,en),makeField(t.price,price));

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
