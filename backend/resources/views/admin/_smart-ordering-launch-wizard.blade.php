@php
    $launchIsAr = app()->getLocale() === 'ar';
    $launchStore = $stores->firstWhere('id', $site->store_id);
    $launchNameAr = old('name_ar', $setupIdentity['name_ar'] ?? '');
    $launchNameEn = old('name_en', $setupIdentity['name_en'] ?? ($launchStore?->name ?? ''));
    $launchTaglineAr = old('tagline_ar', $setupIdentity['tagline_ar'] ?? '');
    $launchTaglineEn = old('tagline_en', $setupIdentity['tagline_en'] ?? '');
    $launchPhone = old('phone', $setupIdentity['phone'] ?? '');
    $launchSteps = [
        'menu' => $launchIsAr ? 'المنيو' : 'Menu',
        'design' => $launchIsAr ? 'المظهر' : 'Design',
        'identity' => $launchIsAr ? 'بيانات المطعم' : 'Restaurant info',
        'review' => $launchIsAr ? 'المراجعة' : 'Review',
        'publish' => $launchIsAr ? 'النشر' : 'Publish',
    ];
    $launchReady = [
        'menu' => $setupMenuCount > 0 && $setupCategoryCount > 0 && $setupItemCount > 0,
        'design' => trim((string)$setupCurrentTheme) !== '',
        'identity' => trim((string)$launchNameEn) !== ''
            && trim((string)$site->slug) !== ''
            && trim((string)($setupIdentity['cover_url'] ?? '')) !== '',
        'review' => empty($itemPriceErrors),
        'publish' => $publishedRevision instanceof \App\Domain\SmartOrdering\Models\SmartOrderingRevision,
    ];
    $launchBase = ['store_id'=>$site->store_id] + ($supportAccess ? ['support_access'=>1] : []);
    $menuCount = $setupMenuCount;
    $menuItemCount = $setupItemCount;
    $quickMenuCurrency = $checkoutCurrency;
    $canApply = $canManageSetup;
    $openLiveAfterPublish = (bool) session('open_live_storefront', false);
@endphp

<style>
.restaurant-launch{display:grid;gap:10px}
.rl-head{display:flex;align-items:flex-start;justify-content:space-between;gap:14px;padding:14px 16px;border:1px solid var(--foodex-border);border-radius:16px;background:linear-gradient(135deg,var(--foodex-palette-f8fcf9),#fff);box-shadow:0 6px 20px rgba(15,23,42,.04)}
.rl-head h1{margin:0;font-size:1.08rem}.rl-head p{margin:4px 0 0;color:var(--foodex-muted);font-size:.72rem;line-height:1.5}.rl-head-badge{display:inline-flex;align-items:center;min-height:30px;padding:0 10px;border-radius:999px;background:var(--foodex-green-soft);color:var(--foodex-green-dark);font-size:.66rem;font-weight:900;white-space:nowrap}
.rl-steps{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:6px;padding:5px;border:1px solid var(--foodex-border);border-radius:15px;background:#fff}
.rl-step{position:relative;display:flex;align-items:center;justify-content:center;gap:7px;min-height:42px;padding:0 10px;border:1px solid transparent;border-radius:11px;color:var(--foodex-palette-667085);text-decoration:none;font-size:.7rem;font-weight:900;white-space:nowrap}.rl-step:hover{background:var(--foodex-palette-f7faf8)}.rl-step.is-active{background:var(--foodex-action-green);color:#fff;box-shadow:0 6px 16px rgba(21,138,58,.14)}.rl-step.is-ready:not(.is-active){color:var(--foodex-green-dark);background:var(--foodex-palette-f1faf3)}.rl-step-num{width:22px;height:22px;display:grid;place-items:center;border-radius:999px;background:rgba(127,127,127,.11);font-size:.61rem}.rl-step.is-active .rl-step-num{background:#fff;color:var(--foodex-green-dark)}
.rl-panel{display:grid;gap:10px}.rl-panel-title{display:flex;align-items:flex-start;justify-content:space-between;gap:12px;flex-wrap:wrap;padding:12px 14px;border:1px solid var(--foodex-border);border-radius:14px;background:#fff}.rl-panel-title h2{margin:0;font-size:.94rem}.rl-panel-title p{margin:3px 0 0;color:var(--foodex-muted);font-size:.69rem}.rl-actions{display:flex;gap:7px;align-items:center;flex-wrap:wrap}
.rl-next,.rl-secondary,.rl-danger{display:inline-flex;align-items:center;justify-content:center;min-height:38px;padding:0 13px;border-radius:10px;text-decoration:none;font-size:.69rem;font-weight:900;cursor:pointer}.rl-next{border:1px solid var(--foodex-action-green);background:var(--foodex-action-green);color:#fff}.rl-secondary{border:1px solid var(--foodex-border);background:#fff;color:var(--foodex-ink)}.rl-danger{border:1px solid var(--foodex-palette-fecaca);background:var(--foodex-palette-fff7f7);color:var(--foodex-palette-b91c1c)}
.rl-stats{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:7px}.rl-stat{padding:10px 12px;border:1px solid var(--foodex-border);border-radius:12px;background:#fff}.rl-stat small{display:block;color:var(--foodex-muted);font-size:.61rem;font-weight:800}.rl-stat strong{display:block;margin-top:4px;font-size:.9rem}
.rl-menu-list{display:grid;gap:9px}.rl-menu-card{border:1px solid var(--foodex-border);border-radius:15px;background:#fff;overflow:hidden}.rl-menu-head{display:flex;align-items:center;justify-content:space-between;gap:10px;padding:11px 13px;background:var(--foodex-palette-fbfcfb);border-bottom:1px solid var(--foodex-palette-edf1ee)}.rl-menu-title{display:flex;align-items:center;gap:9px;min-width:0}.rl-thumb{width:48px;height:48px;flex:0 0 48px;object-fit:cover;border:1px solid var(--foodex-border);border-radius:10px;background:var(--foodex-palette-f3f6f4)}.rl-thumb.is-small{width:40px;height:40px;flex-basis:40px}.rl-menu-title strong{display:block;font-size:.79rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.rl-menu-title small{color:var(--foodex-muted);font-size:.61rem}.rl-category-list{display:grid;gap:7px;padding:10px}.rl-category{border:1px solid var(--foodex-palette-e6ece8);border-radius:12px;background:#fff}.rl-category-head{display:flex;align-items:center;justify-content:space-between;gap:8px;padding:9px 10px}.rl-item-list{display:grid;grid-template-columns:repeat(auto-fill,minmax(230px,1fr));gap:6px;padding:0 10px 10px}.rl-item{display:flex;align-items:center;justify-content:space-between;gap:7px;padding:8px;border:1px solid var(--foodex-palette-edf1ef);border-radius:10px;background:var(--foodex-palette-fbfcfb)}.rl-item-main{display:flex;align-items:center;gap:7px;min-width:0}.rl-item strong{display:block;font-size:.7rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.rl-item small{font-size:.59rem;color:var(--foodex-muted)}
.rl-inline-add{margin:0 10px 10px}.rl-inline-add summary{cursor:pointer;list-style:none;display:inline-flex;align-items:center;min-height:34px;padding:0 10px;border:1px dashed var(--foodex-palette-8abd96);border-radius:9px;color:var(--foodex-green-dark);font-size:.66rem;font-weight:900}.rl-inline-add summary::-webkit-details-marker{display:none}.rl-inline-form{display:grid;grid-template-columns:repeat(4,minmax(0,1fr)) auto;gap:7px;align-items:end;margin-top:7px;padding:9px;border:1px solid var(--foodex-border);border-radius:11px;background:var(--foodex-palette-f8faf9)}.rl-inline-form label{display:grid;gap:4px;color:var(--foodex-palette-475569);font-size:.62rem;font-weight:800}.rl-inline-form input,.rl-inline-form select{width:100%;min-width:0;min-height:38px;border:1px solid var(--foodex-border);border-radius:9px;padding-inline:9px;background:#fff;font:inherit}.rl-inline-form button{min-height:38px}
.rl-design-grid{display:grid;grid-template-columns:minmax(330px,.72fr) minmax(0,1.28fr);gap:10px}.rl-theme-list{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:7px;align-content:start}.rl-theme{display:grid;gap:7px;padding:9px;border:1px solid var(--foodex-border);border-radius:12px;background:#fff}.rl-theme.is-current{border-color:var(--foodex-palette-78ba87);box-shadow:0 0 0 2px var(--foodex-palette-e7f6eb)}.rl-theme-preview{height:54px;border-radius:9px;border:1px solid var(--foodex-border);background:linear-gradient(135deg,var(--foodex-palette-eef7f0),#fff 55%,var(--foodex-palette-f7efe6))}.rl-theme[data-dark="1"] .rl-theme-preview{background:linear-gradient(135deg,var(--foodex-palette-111827),var(--foodex-palette-14532d) 60%,var(--foodex-palette-d97706))}.rl-theme strong{font-size:.69rem}.rl-theme button{min-height:34px}.rl-preview{min-height:560px;padding:10px;border:1px solid var(--foodex-border);border-radius:14px;background:var(--foodex-palette-111827)}.rl-preview iframe{display:block;width:100%;height:540px;border:0;border-radius:10px;background:#fff}
.rl-form-card{padding:13px;border:1px solid var(--foodex-border);border-radius:14px;background:#fff}.rl-form-card h3{margin:0 0 10px;font-size:.82rem}.rl-form-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:9px}.rl-form-grid label{display:grid;gap:4px;font-size:.65rem;font-weight:900;color:var(--foodex-palette-475569)}.rl-form-grid input,.rl-form-grid select,.rl-form-grid textarea{width:100%;min-width:0;box-sizing:border-box;border:1px solid var(--foodex-border);border-radius:9px;padding:9px;background:#fff;font:inherit}.rl-form-grid textarea{min-height:76px}.rl-form-grid .span-2{grid-column:1/-1}.rl-form-actions{display:flex;justify-content:flex-end;gap:7px;margin-top:10px}
.rl-review-checks{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:7px}.rl-checks{display:grid;gap:7px}.rl-check{display:flex;align-items:center;justify-content:space-between;gap:10px;padding:10px 11px;border:1px solid var(--foodex-border);border-radius:11px;background:#fff}.rl-check span{font-size:.69rem;font-weight:800}.rl-check strong{font-size:.65rem}.rl-check.is-ok strong{color:var(--foodex-green-dark)}.rl-check.is-warn strong{color:var(--foodex-palette-b45309)}
.rl-device-review{display:grid;gap:9px;padding:12px;border:1px solid var(--foodex-border);border-radius:15px;background:#fff}.rl-device-review-head{display:flex;align-items:flex-start;justify-content:space-between;gap:10px}.rl-device-review-head h3{margin:0;font-size:.82rem}.rl-device-review-head p{margin:3px 0 0;color:var(--foodex-muted);font-size:.65rem}.rl-device-grid{display:grid;grid-template-columns:minmax(280px,390px) minmax(500px,768px);gap:14px;justify-content:center;align-items:start}.rl-device-card{min-width:0;display:grid;gap:7px}.rl-device-label{display:flex;align-items:center;justify-content:space-between;gap:8px;font-size:.68rem;font-weight:900}.rl-device-label small{color:var(--foodex-muted);font-weight:700}.rl-device-shell{width:100%;box-sizing:border-box;padding:8px;border:1px solid var(--foodex-border);border-radius:16px;background:var(--foodex-palette-111827);box-shadow:0 10px 28px rgba(15,23,42,.12)}.rl-device-shell iframe{display:block;width:100%;height:720px;border:0;border-radius:10px;background:#fff}.rl-device-shell.is-mobile iframe{height:760px}
.rl-publish-card{display:grid;gap:12px;padding:18px;border:1px solid var(--foodex-palette-b9d8c1);border-radius:16px;background:linear-gradient(135deg,var(--foodex-palette-f3fbf5),#fff);text-align:center}.rl-publish-card h2{margin:0;font-size:1.05rem}.rl-publish-card p{margin:0;color:var(--foodex-muted);font-size:.72rem}.rl-public-url{display:flex;align-items:center;justify-content:center;gap:7px;flex-wrap:wrap}.rl-site-qr{display:grid;grid-template-columns:190px minmax(0,1fr);gap:16px;align-items:center;max-width:720px;margin:4px auto 0;padding:14px;border:1px solid var(--foodex-border);border-radius:14px;background:#fff;text-align:start}.rl-site-qr img{width:180px;height:180px;object-fit:contain;border:1px solid var(--foodex-border);border-radius:12px;background:#fff;padding:7px}.rl-site-qr-copy{display:grid;gap:8px}.rl-site-qr-copy h3{margin:0;font-size:.86rem}.rl-site-qr-copy code{display:block;max-width:100%;padding:8px;border-radius:9px;background:var(--foodex-background);font-size:.64rem;direction:ltr;overflow-wrap:anywhere}
.rl-panel-title .rl-actions{margin-inline-start:auto}.rl-panel-title form{margin:0}.rl-panel-title .rl-next:disabled{opacity:.65;cursor:wait}
@media(max-width:1220px){.rl-device-grid{grid-template-columns:1fr}.rl-device-card{width:min(100%,768px);justify-self:center}.rl-device-card:first-child{width:min(100%,390px)}}
@media(max-width:1050px){.rl-design-grid{grid-template-columns:1fr}.rl-review-checks{grid-template-columns:repeat(2,minmax(0,1fr))}.rl-preview{min-height:440px}.rl-preview iframe{height:420px}.rl-inline-form{grid-template-columns:repeat(2,minmax(0,1fr))}.rl-inline-form button{grid-column:1/-1}.rl-theme-list{grid-template-columns:repeat(3,minmax(0,1fr))}}
@media(max-width:760px){.rl-panel-title .rl-actions{width:100%;margin-inline-start:0}.rl-panel-title .rl-actions>a{flex:1 1 auto}.rl-panel-title .rl-actions form{display:flex;flex:1 1 auto}.rl-panel-title .rl-actions form .rl-next{width:100%}.rl-steps{display:flex;overflow-x:auto}.rl-step{flex:0 0 auto;min-width:130px}.rl-stats{grid-template-columns:repeat(2,minmax(0,1fr))}.rl-theme-list{grid-template-columns:repeat(2,minmax(0,1fr))}.rl-form-grid,.rl-inline-form{grid-template-columns:1fr}.rl-form-grid .span-2{grid-column:auto}.rl-item-list{grid-template-columns:1fr}.rl-review-checks{grid-template-columns:1fr}.rl-site-qr{grid-template-columns:1fr;text-align:center}.rl-site-qr img{margin:auto}.rl-device-shell iframe,.rl-device-shell.is-mobile iframe{height:640px}}
</style>

<div class="restaurant-launch" data-restaurant-launch>
    <header class="rl-head">
        <div>
            <h1>{{ $launchIsAr ? 'تجهيز واجهة المطعم للنشر' : 'Build and launch restaurant storefront' }}</h1>
            <p>{{ $launchIsAr ? 'امشِ بالترتيب من المنيو حتى النشر النهائي من نفس الصفحة، بدون التنقل بين صفحات غير واضحة.' : 'Complete menu, design, restaurant info, review and publishing from one guided workspace.' }}</p>
        </div>
        <span class="rl-head-badge">{{ $launchIsAr ? 'مسودة قابلة للتعديل' : 'Editable draft' }}</span>
    </header>

    <nav class="rl-steps" aria-label="{{ $launchIsAr ? 'خطوات تجهيز المطعم' : 'Restaurant launch steps' }}">
        @foreach($launchSteps as $key=>$label)
            @php($stepNumber = array_search($key, array_keys($launchSteps), true) + 1)
            <a class="rl-step {{ $setupStep===$key ? 'is-active' : '' }} {{ $launchReady[$key] ? 'is-ready' : '' }}"
               href="{{ route('admin.smart-ordering.menu.index', $launchBase + ['setup'=>$key]) }}">
                <span class="rl-step-num">{{ $launchReady[$key] && $setupStep!==$key ? '✓' : $stepNumber }}</span>
                <span>{{ $label }}</span>
            </a>
        @endforeach
    </nav>

    @if($setupStep==='menu')
    <section class="rl-panel" data-launch-step="menu">
        <div class="rl-panel-title">
            <div><h2>{{ $launchIsAr ? '1. المنيو والتصنيفات والأصناف' : '1. Menu, categories and items' }}</h2><p>{{ $launchIsAr ? 'أضف واحذف مباشرة من هنا. التفاصيل المتقدمة ليست مطلوبة للإطلاق.' : 'Add and remove directly here. Advanced editing is optional.' }}</p></div>
            @if($launchReady['menu'])<a class="rl-next" href="{{ route('admin.smart-ordering.menu.index',$launchBase+['setup'=>'design']) }}">{{ $launchIsAr ? 'التالي: المظهر' : 'Next: Design' }}</a>@endif
        </div>
        <div class="rl-stats">
            <div class="rl-stat"><small>{{ $launchIsAr ? 'المنيو' : 'Menus' }}</small><strong>{{ $setupMenuCount }}</strong></div>
            <div class="rl-stat"><small>{{ $launchIsAr ? 'التصنيفات' : 'Categories' }}</small><strong>{{ $setupCategoryCount }}</strong></div>
            <div class="rl-stat"><small>{{ $launchIsAr ? 'الأصناف' : 'Items' }}</small><strong>{{ $setupItemCount }}</strong></div>
            <div class="rl-stat"><small>{{ $launchIsAr ? 'العملة' : 'Currency' }}</small><strong>{{ $checkoutCurrency }}</strong></div>
        </div>

        @php($quickMenuReturnTo = 'menu_setup')
        @include('admin._smart-ordering-quick-menu-wizard')

        <div class="rl-menu-list">
            @forelse($menus as $menu)
                <article class="rl-menu-card">
                    <header class="rl-menu-head">
                        <div class="rl-menu-title">
                            @if($mediaUrl($menu->image_path))<img class="rl-thumb" src="{{ $mediaUrl($menu->image_path) }}" alt="">@else<span class="rl-thumb"></span>@endif
                            <div><strong>{{ $localized($menu,'name') ?: ($launchIsAr?'منيو بدون اسم':'Unnamed menu') }}</strong><small>{{ $menu->categories->count() }} {{ $launchIsAr?'تصنيف':'categories' }}</small></div>
                        </div>
                        <div class="rl-actions">
                            <button class="rl-secondary" type="button" data-qm-edit-menu="{{ $menu->id }}">{{ $launchIsAr?'تعديل المنيو':'Edit menu' }}</button>
                            <form method="post" action="{{ route('admin.smart-ordering.menu.menus.destroy',$menu) }}" data-launch-delete>
                                @csrf @method('DELETE')<input type="hidden" name="store_id" value="{{ $site->store_id }}">
                                <button class="rl-danger" type="submit">{{ $launchIsAr?'حذف المنيو':'Delete menu' }}</button>
                            </form>
                        </div>
                    </header>

                    <details class="rl-inline-add">
                        <summary>+ {{ $launchIsAr?'إضافة تصنيف':'Add category' }}</summary>
                        <form class="rl-inline-form" method="post" enctype="multipart/form-data" action="{{ route('admin.smart-ordering.menu.categories.store',$menu) }}" data-setup-mirror-form>
                            @csrf <input type="hidden" name="store_id" value="{{ $site->store_id }}"><input type="hidden" name="sort_order" value="{{ $menu->categories->count()*10 }}"><input type="hidden" name="is_visible" value="1"><input type="hidden" name="sold_out" value="0">
                            <label>{{ $launchIsAr?'الاسم بالعربي':'Arabic name' }}<input name="name_ar" data-mirror-ar></label> {{-- localization-gate: allow — bilingual authoring intentionally exposes the Arabic name field. --}}
                            <label>{{ $launchIsAr?'الاسم بالإنجليزي':'English name' }}<input name="name_en" data-mirror-en></label> {{-- localization-gate: allow — bilingual authoring intentionally exposes the English name field. --}}
                            <label>{{ $launchIsAr?'الصورة':'Image' }}<input type="file" name="image" accept="image/jpeg,image/png,image/webp"></label>
                            <label>{{ $launchIsAr?'أيقونة اختيارية':'Optional icon' }}<input name="icon"></label>
                            <button class="rl-next" type="submit">{{ $launchIsAr?'إضافة':'Add' }}</button>
                        </form>
                    </details>

                    <div class="rl-category-list">
                        @forelse($menu->categories as $category)
                            <section class="rl-category">
                                <div class="rl-category-head">
                                    <div class="rl-menu-title">
                                        @if($mediaUrl($category->image_path))<img class="rl-thumb is-small" src="{{ $mediaUrl($category->image_path) }}" alt="">@else<span class="rl-thumb is-small"></span>@endif
                                        <div><strong>{{ $localized($category,'name') }}</strong><small>{{ $category->items->count() }} {{ $launchIsAr?'صنف':'items' }}</small></div>
                                    </div>
                                    <form method="post" action="{{ route('admin.smart-ordering.menu.categories.destroy',$category) }}" data-launch-delete>
                                        @csrf @method('DELETE')<input type="hidden" name="store_id" value="{{ $site->store_id }}">
                                        <button class="rl-danger" type="submit">{{ $launchIsAr?'حذف':'Delete' }}</button>
                                    </form>
                                </div>

                                <div class="rl-item-list">
                                    @foreach($category->items as $item)
                                        <div class="rl-item">
                                            <div class="rl-item-main">
                                                @if($mediaUrl($item->image_path))<img class="rl-thumb is-small" src="{{ $mediaUrl($item->image_path) }}" alt="">@else<span class="rl-thumb is-small"></span>@endif
                                                <div><strong>{{ $localized($item,'name') }}</strong><small>{{ $itemPrices[$item->id]['display'] ?? ($launchIsAr?'السعر غير محدد':'No price') }}</small></div>
                                            </div>
                                            <form method="post" action="{{ route('admin.smart-ordering.menu.items.destroy',$item) }}" data-launch-delete>
                                                @csrf @method('DELETE')<input type="hidden" name="store_id" value="{{ $site->store_id }}">
                                                <button class="rl-danger" type="submit">×</button>
                                            </form>
                                        </div>
                                    @endforeach
                                </div>

                                <details class="rl-inline-add">
                                    <summary>+ {{ $launchIsAr?'إضافة صنف':'Add item' }}</summary>
                                    <form class="rl-inline-form" method="post" enctype="multipart/form-data" action="{{ route('admin.smart-ordering.menu.items.store',$category) }}" data-setup-mirror-form>
                                        @csrf <input type="hidden" name="store_id" value="{{ $site->store_id }}"><input type="hidden" name="sort_order" value="{{ $category->items->count()*10 }}"><input type="hidden" name="spicy_level" value="0"><input type="hidden" name="is_visible" value="1"><input type="hidden" name="allow_customer_notes" value="1">
                                        <label>{{ $launchIsAr?'الاسم بالعربي':'Arabic name' }}<input name="name_ar" data-mirror-ar></label> {{-- localization-gate: allow — bilingual authoring intentionally exposes the Arabic name field. --}}
                                        <label>{{ $launchIsAr?'الاسم بالإنجليزي':'English name' }}<input name="name_en" data-mirror-en></label> {{-- localization-gate: allow — bilingual authoring intentionally exposes the English name field. --}}
                                        <label>{{ $launchIsAr?'السعر':'Price' }}<input type="number" min="0" step="{{ $moneyStep }}" name="price_override" required></label>
                                        <label>{{ $launchIsAr?'الصورة':'Image' }}<input type="file" name="image" accept="image/jpeg,image/png,image/webp"></label>
                                        <button class="rl-next" type="submit">{{ $launchIsAr?'إضافة':'Add' }}</button>
                                    </form>
                                </details>
                            </section>
                        @empty
                            <div class="so-empty">{{ $launchIsAr?'أضف أول تصنيف للمنيو.':'Add the first category.' }}</div>
                        @endforelse
                    </div>
                </article>
            @empty
                <div class="so-empty">{{ $launchIsAr?'ابدأ بإنشاء أول منيو من الزر أعلاه.':'Create the first menu using the button above.' }}</div>
            @endforelse
        </div>
    </section>
    @endif

    @if($setupStep==='design')
    <section class="rl-panel" data-launch-step="design">
        <div class="rl-panel-title">
            <div><h2>{{ $launchIsAr?'2. اختر شكل واجهة المطعم':'2. Choose storefront design' }}</h2><p>{{ $launchIsAr?'اختر قالباً وشاهد المعاينة الحقيقية للمسودة من نفس الصفحة.':'Choose a template and preview the real draft here.' }}</p></div>
        </div>
        <div class="rl-design-grid">
            <div class="rl-theme-list">
                @foreach($setupThemes as $themeId=>$definition)
                    <form class="rl-theme {{ $setupCurrentTheme===$themeId?'is-current':'' }}" data-dark="{{ ($definition['dark']??false)?1:0 }}" method="post" action="{{ route('admin.smart-ordering.appearance.apply') }}">
                        @csrf
                        <input type="hidden" name="site" value="{{ $site->public_id }}"><input type="hidden" name="theme" value="{{ $themeId }}"><input type="hidden" name="lang" value="{{ $launchIsAr?'ar':'en' }}"><input type="hidden" name="device" value="mobile"><input type="hidden" name="return_to" value="menu_setup">
                        <div class="rl-theme-preview"></div>
                        <strong>{{ __('smart_ordering.appearance.production_themes.'.$themeId.'.label') }}</strong>
                        <button class="{{ $setupCurrentTheme===$themeId?'rl-secondary':'rl-next' }}" type="submit">{{ $setupCurrentTheme===$themeId ? ($launchIsAr?'القالب الحالي':'Current theme') : ($launchIsAr?'اختيار هذا القالب':'Use this theme') }}</button>
                    </form>
                @endforeach
            </div>
            <div class="rl-preview"><iframe src="{{ $setupPreviewUrl }}" title="{{ $launchIsAr?'معاينة واجهة المطعم':'Restaurant preview' }}"></iframe></div>
        </div>
        <div class="rl-actions"><a class="rl-secondary" href="{{ route('admin.smart-ordering.menu.index',$launchBase+['setup'=>'menu']) }}">{{ $launchIsAr?'السابق':'Back' }}</a><a class="rl-next" href="{{ route('admin.smart-ordering.menu.index',$launchBase+['setup'=>'identity']) }}">{{ $launchIsAr?'التالي: بيانات المطعم':'Next: Restaurant info' }}</a></div>
    </section>
    @endif

    @if($setupStep==='identity')
    <section class="rl-panel" data-launch-step="identity">
        <div class="rl-panel-title">
            <div>
                <h2>{{ $launchIsAr?'3. بيانات واجهة المنيو':'3. Menu storefront details' }}</h2>
                <p>{{ $launchIsAr?'راجع الاسم والوصف والرابط والهوية البصرية. التشغيل يظل مقفلاً حتى الإطلاق النهائي.':'Review storefront copy, URL and branding. Runtime stays locked until final launch.' }}</p>
            </div>
        </div>

        <form class="rl-form-card" method="post" enctype="multipart/form-data" action="{{ route('admin.smart-ordering.menu.setup.identity') }}" data-setup-mirror-form>
            @csrf
            <input type="hidden" name="store_id" value="{{ $site->store_id }}">
            <h3>{{ $launchIsAr?'بيانات واجهة المنيو':'Menu storefront details' }}</h3>

            <div class="rl-form-grid">
                <label>{{ $launchIsAr?'اسم المطعم بالعربي':'Arabic restaurant name' }}
                    <input name="name_ar" value="{{ $launchNameAr }}" data-mirror-ar> {{-- localization-gate: allow — bilingual restaurant identity editor intentionally exposes the Arabic name value. --}}
                </label>
                <label>{{ $launchIsAr?'اسم المطعم بالإنجليزي':'English restaurant name' }}
                    <input name="name_en" value="{{ $launchNameEn }}" data-mirror-en> {{-- localization-gate: allow — bilingual restaurant identity editor intentionally exposes the English name value. --}}
                </label>
                <label>{{ $launchIsAr?'وصف قصير بالعربي':'Arabic tagline' }}
                    <input name="tagline_ar" value="{{ $launchTaglineAr }}"> {{-- localization-gate: allow — bilingual restaurant identity editor intentionally exposes the Arabic tagline value. --}}
                </label>
                <label>{{ $launchIsAr?'وصف قصير بالإنجليزي':'English tagline' }}
                    <input name="tagline_en" value="{{ $launchTaglineEn }}"> {{-- localization-gate: allow — bilingual restaurant identity editor intentionally exposes the English tagline value. --}}
                </label>
                <label>{{ $launchIsAr?'الهاتف':'Phone' }}
                    <input name="phone" value="{{ $launchPhone }}">
                </label>
                <label>{{ $launchIsAr?'واتساب URL':'WhatsApp URL' }}
                    <input name="whatsapp_url" value="{{ $setupIdentity['whatsapp_url'] ?? '' }}">
                </label>
                <label>{{ $launchIsAr?'اللوجو':'Logo' }}
                    <input type="file" name="logo" accept="image/jpeg,image/png,image/webp">
                </label>
                <label>{{ $launchIsAr?'صورة الهيرو للمنيو':'Menu hero image' }}
                    <input type="file" name="cover" accept="image/jpeg,image/png,image/webp">
                    <small>{{ $launchIsAr?'نفس الصورة المستخدمة في خطوة هوية المطعم وتظهر أعلى واجهة المنيو.':'The same image used in Restaurant identity and shown at the top of the menu.' }}</small>
                </label>
                <label class="span-2">{{ $launchIsAr?'وصف المطعم بالعربي':'Arabic description' }}
                    <textarea name="description_ar">{{ $setupIdentity['description_ar'] ?? '' }}</textarea> {{-- localization-gate: allow — bilingual restaurant identity editor intentionally exposes the Arabic description value. --}}
                </label>
                <label class="span-2">{{ $launchIsAr?'وصف المطعم بالإنجليزي':'English description' }}
                    <textarea name="description_en">{{ $setupIdentity['description_en'] ?? '' }}</textarea> {{-- localization-gate: allow — bilingual restaurant identity editor intentionally exposes the English description value. --}}
                </label>

                <label>{{ $launchIsAr?'الرابط المختصر للمطعم':'Public restaurant URL' }}
                    <input name="slug" value="{{ $site->slug }}" required>
                </label>
                <div class="span-2 foodex-alert">
                    {{ $launchIsAr
                        ? 'حالة التشغيل لا تتغير من هنا. التفعيل النهائي يتم فقط في خطوة المراجعة والإطلاق بعد اكتمال جميع المتطلبات.'
                        : 'Runtime is not changed here. Final activation happens only in Review & launch after every prerequisite is complete.' }}
                </div>
            </div>

            <div class="rl-form-actions">
                <a class="rl-secondary" href="{{ route('admin.smart-ordering.menu.index',$launchBase+['setup'=>'design']) }}">{{ $launchIsAr?'السابق':'Back' }}</a>
                <button class="rl-next" type="submit">{{ $launchIsAr?'حفظ والمتابعة للمراجعة':'Save and continue to review' }}</button>
            </div>
        </form>
    </section>
    @endif

    @if($setupStep==='review')
    <section class="rl-panel" id="so-draft-review" data-launch-step="review">
        <div class="rl-panel-title">
            <div>
                <h2>{{ $launchIsAr?'4. مراجعة كل شيء قبل النشر':'4. Review before publishing' }}</h2>
                <p>{{ $launchIsAr?'المراجعة أولاً: افحص الجاهزية ثم شاهد الموبايل والتابلت قبل الانتقال للنشر.':'Review first: verify readiness, then inspect mobile and tablet before publishing.' }}</p>
            </div>
            <div class="rl-actions">
                <a class="rl-secondary" href="{{ route('admin.smart-ordering.menu.index',$launchBase+['setup'=>'identity']) }}">{{ $launchIsAr?'السابق':'Back' }}</a>
                <a class="rl-next" href="{{ route('admin.smart-ordering.menu.index',$launchBase+['setup'=>'publish']) }}">{{ $launchIsAr?'التالي: النشر النهائي':'Next: Publish' }}</a>
            </div>
        </div>

        <div class="rl-review-checks">
            <div class="rl-check {{ $launchReady['menu']?'is-ok':'is-warn' }}"><span>{{ $launchIsAr?'المنيو والتصنيفات والأصناف':'Menu structure' }}</span><strong>{{ $launchReady['menu']?'✓':'!' }}</strong></div>
            <div class="rl-check {{ $launchReady['design']?'is-ok':'is-warn' }}"><span>{{ $launchIsAr?'المظهر':'Design' }}</span><strong>{{ $launchReady['design']?'✓':'!' }}</strong></div>
            <div class="rl-check {{ $launchReady['identity']?'is-ok':'is-warn' }}"><span>{{ $launchIsAr?'بيانات المطعم والرابط':'Restaurant identity' }}</span><strong>{{ $launchReady['identity']?'✓':'!' }}</strong></div>
            <div class="rl-check {{ empty($itemPriceErrors)?'is-ok':'is-warn' }}"><span>{{ $launchIsAr?'أسعار الأصناف':'Item prices' }}</span><strong>{{ empty($itemPriceErrors)?'✓':count($itemPriceErrors) }}</strong></div>
            <div class="rl-check is-ok"><span>{{ $launchIsAr?'التشغيل النهائي':'Final runtime' }}</span><strong>{{ $launchIsAr?'بعد النشر':'After publish' }}</strong></div>
        </div>

        @if(!empty($itemPriceErrors))
            <div class="foodex-alert error">{{ $launchIsAr?'يوجد أصناف بدون سعر صالح. ارجع للمنيو وأكمل الأسعار قبل النشر.':'Some items need a valid price. Return to the menu and complete prices before publishing.' }}</div>
        @endif

        <section class="rl-device-review" aria-label="{{ $launchIsAr?'معاينة الموبايل والتابلت':'Mobile and tablet review' }}">
            <div class="rl-device-review-head">
                <div>
                    <h3>{{ $launchIsAr?'معاينة حقيقية على جهازين':'Real preview on two devices' }}</h3>
                    <p>{{ $launchIsAr?'نفس المسودة الحالية بدون أدوات تعديل داخل شاشة المراجعة.':'The current draft only, with no editing tools inside the review step.' }}</p>
                </div>
            </div>
            <div class="rl-device-grid">
                <article class="rl-device-card">
                    <div class="rl-device-label"><span>{{ $launchIsAr?'موبايل':'Mobile' }}</span><small>390px</small></div>
                    <div class="rl-device-shell is-mobile">
                        <iframe src="{{ $setupPreviewUrl }}" title="{{ $launchIsAr?'معاينة الموبايل':'Mobile preview' }}"></iframe>
                    </div>
                </article>
                <article class="rl-device-card">
                    <div class="rl-device-label"><span>{{ $launchIsAr?'تابلت':'Tablet' }}</span><small>768px</small></div>
                    <div class="rl-device-shell is-tablet">
                        <iframe src="{{ $setupPreviewUrl }}" title="{{ $launchIsAr?'معاينة التابلت':'Tablet preview' }}"></iframe>
                    </div>
                </article>
            </div>
        </section>
    </section>
    @endif

    @if($setupStep==='publish')
    <section class="rl-panel" data-launch-step="publish">
        <div class="rl-panel-title">
            <div>
                <h2>{{ $publishedRevision ? ($launchIsAr?'الموقع منشور وجاهز للمشاركة':'The site is published and ready to share') : ($launchIsAr?'5. النشر النهائي':'5. Final publish') }}</h2>
                <p>{{ $launchIsAr?'النشر يثبت المسودة، ينشئ QR للموقع، ثم يفتح الموقع الحقيقي في تبويب جديد.':'Publishing commits the draft, creates the site QR, then opens the live site in a new tab.' }}</p>
            </div>
            <div class="rl-actions">
                <a class="rl-secondary" href="{{ route('admin.smart-ordering.menu.index',$launchBase+['setup'=>'review']) }}">{{ $launchIsAr?'السابق':'Back' }}</a>
                @if(empty($itemPriceErrors) && $canPublishSetup && $canManageSetup && $scheduledRevision===null)
                    <form method="post" action="{{ route('admin.smart-ordering.menu.publish',$draft) }}" data-guided-publish-form>
                        @csrf
                        <input type="hidden" name="store_id" value="{{ $site->store_id }}">
                        <input type="hidden" name="guided" value="1">
                        <button class="rl-next" type="submit" data-guided-publish-submit data-busy-label="{{ $launchIsAr?'جاري النشر...':'Publishing...' }}">{{ $publishedRevision ? ($launchIsAr?'نشر التحديثات الآن':'Publish updates now') : ($launchIsAr?'نشر وفتح الموقع':'Publish & open site') }}</button>
                    </form>
                @endif
            </div>
        </div>

        <div class="rl-publish-card">
            @if(!empty($itemPriceErrors) || !$canPublishSetup || !$canManageSetup || $scheduledRevision!==null)
                <div class="foodex-alert error">{{ $launchIsAr?'أكمل المشاكل الظاهرة في المراجعة قبل النشر.':'Complete the review issues before publishing.' }}</div>
            @elseif(!$publishedRevision)
                <h2>{{ $launchIsAr?'جاهز للنشر':'Ready to publish' }}</h2>
                <p>{{ $launchIsAr?'اضغط زر النشر بالأعلى. بعد النجاح سيتم فتح الموقع الحقيقي تلقائياً في تبويب جديد ويظهر QR هنا مباشرة.':'Use the publish button above. After success the live site opens automatically in a new tab and its QR appears here.' }}</p>
            @endif

            @if($publishedRevision && $publicMenuUrl)
                <h2>{{ $launchIsAr?'تم النشر بنجاح':'Published successfully' }}</h2>
                <p>{{ $launchIsAr?'استخدم الرابط أو امسح QR. الرمز أدناه يفتح رابط المطعم الحقيقي مباشرة.':'Use the URL or scan the QR. The code below points directly to the live restaurant URL.' }}</p>

                <div class="rl-site-qr">
                    <img src="{{ route('admin.smart-ordering.menu.site-qr.svg', ['site'=>$site,'store_id'=>$site->store_id]) }}" alt="{{ $launchIsAr?'QR رابط المطعم':'Restaurant URL QR' }}">
                    <div class="rl-site-qr-copy">
                        <h3>{{ $launchIsAr?'QR الموقع الحقيقي':'Live site QR' }}</h3>
                        <code>{{ $publicMenuUrl }}</code>
                        <div class="rl-public-url">
                            <a class="rl-next" href="{{ $publicMenuUrl }}" target="_blank" rel="noopener">{{ $launchIsAr?'فتح الموقع الحقيقي':'Open live site' }}</a>
                            <button class="rl-secondary" type="button" data-copy-public-url="{{ $publicMenuUrl }}">{{ $launchIsAr?'نسخ الرابط':'Copy URL' }}</button>
                            <a class="rl-secondary" href="{{ route('admin.smart-ordering.menu.site-qr.svg', ['site'=>$site,'store_id'=>$site->store_id,'download'=>1]) }}">{{ $launchIsAr?'تنزيل QR SVG':'Download QR SVG' }}</a>
                            <a class="rl-secondary" href="{{ route('admin.smart-ordering.menu.site-qr.pdf', ['site'=>$site,'store_id'=>$site->store_id]) }}">{{ $launchIsAr?'تنزيل QR PDF':'Download QR PDF' }}</a>
                        </div>
                    </div>
                </div>
            @endif
        </div>
    </section>
    @endif
</div>

<script>
(() => {
    document.querySelectorAll('[data-launch-delete]').forEach(form => {
        form.addEventListener('submit', event => {
            if (!window.confirm(@json($launchIsAr ? 'متأكد من الحذف؟' : 'Are you sure you want to delete this?'))) event.preventDefault();
        });
    });

    document.querySelectorAll('[data-setup-mirror-form]').forEach(form => {
        form.addEventListener('submit', () => {
            const ar = form.querySelector('[data-mirror-ar]');
            const en = form.querySelector('[data-mirror-en]');
            if (ar && en) {
                if (!en.value.trim() && ar.value.trim()) en.value = ar.value.trim();
                if (!ar.value.trim() && en.value.trim()) ar.value = en.value.trim();
            }
        });
    });

    const guidedPublishForm = document.querySelector('[data-guided-publish-form]');
    guidedPublishForm?.addEventListener('submit', () => {
        const liveTab = window.open('about:blank', 'foodex-live-storefront');
        if (liveTab) {
            liveTab.document.title = @json($launchIsAr ? 'جاري نشر المطعم...' : 'Publishing restaurant...');
            liveTab.document.body.innerHTML = '<p style="font-family:system-ui;padding:24px;text-align:center">'+@json($launchIsAr ? 'جاري نشر الموقع، سيتم فتحه تلقائياً بعد اكتمال النشر.' : 'Publishing the site. It will open automatically when publishing completes.')+'</p>';
        }
        const button = guidedPublishForm.querySelector('[data-guided-publish-submit]');
        if (button) {
            button.disabled = true;
            button.setAttribute('aria-busy', 'true');
            button.textContent = button.dataset.busyLabel || button.textContent;
        }
    }, {once:true});

    @if($openLiveAfterPublish && $publicMenuUrl)
        window.open(@json($publicMenuUrl), 'foodex-live-storefront');
    @endif

    document.querySelectorAll('[data-copy-public-url]').forEach(button => {
        button.addEventListener('click', async () => {
            const value = button.dataset.copyPublicUrl || '';
            if (!value) return;
            try { await navigator.clipboard.writeText(value); } catch (_) {}
            button.textContent = @json($launchIsAr ? 'تم النسخ' : 'Copied');
        });
    });
})();
</script>
