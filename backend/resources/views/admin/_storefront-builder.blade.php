@php
    $sfStore = $moduleData['store'] ?? [];
    $sfSettings = $moduleData['settings'] ?? [];
    $sfSections = $moduleData['sections'] ?? [];
    $sfZones = $moduleData['zones'] ?? [];
    $sfBanners = $moduleData['banners'] ?? [];
    $sfTargets = $moduleData['targets'] ?? [];
    $sfThemes = $moduleData['themes'] ?? [];
    $sfSectionTypes = $moduleData['section_types'] ?? [];
    $sfRevision = $moduleData['revision'] ?? [];
    $sfHasDraft = (bool)($sfRevision['has_draft'] ?? false);
    $sfCanPublish = $storeId > 0 && ($user->hasPermission('app_preview.publish', $storeId) || $user->hasPermission('app_preview.publish'));
    $sfPreviewUrl = route('admin.app-preview.index', ['app'=>'customer','channel'=>'b2c','store_id'=>$storeId,'persona'=>'guest','mode'=>'draft'] + ($supportAccess ? ['support_access'=>1] : []));
    $canManageStorefront = $storeId > 0 && ($user->hasPermission('settings.manage', $storeId) || $user->hasPermission('settings.manage'));
    $isSuper = $user->hasRole('SUPER_ADMIN');
    $canManageBanners = $storeId > 0 && ($user->hasPermission('promotions.manage', $storeId) || $user->hasPermission('promotions.manage'));
    $primary = $sfSettings['primary_color'] ?? '#078A43';
    $primaryDark = $sfSettings['primary_dark_color'] ?? '#006736';
    $accent = $sfSettings['accent_color'] ?? '#B5F23E';
    $background = $sfSettings['background_color'] ?? '#F8FBF9';
@endphp

<style>
.sf-builder{display:grid;gap:18px;margin:0 0 18px}.sf-grid{display:grid;grid-template-columns:minmax(0,1.2fr) minmax(300px,.8fr);gap:16px}.sf-card{background:#fff;border:1px solid var(--foodex-border);border-radius:18px;padding:16px}.sf-card h3{margin:0 0 6px}.sf-muted{color:#64748b;font-size:.9rem}.sf-form-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}.sf-form-grid .wide{grid-column:1/-1}.sf-card input,.sf-card select,.sf-card textarea{width:100%;box-sizing:border-box;border:1px solid #cbd5e1;border-radius:10px;padding:9px 10px;background:#fff}.sf-card input[type=color]{padding:3px;height:42px}.sf-card input[type=checkbox]{width:auto}.sf-label{display:grid;gap:5px;font-size:.82rem;font-weight:700}.sf-actions{display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-top:10px}.sf-secondary{border:1px solid #cbd5e1;background:#fff;border-radius:10px;padding:9px 12px;cursor:pointer}.sf-preview{border-radius:24px;padding:18px;min-height:410px;background:var(--sf-bg);border:1px solid #e2e8f0;overflow:hidden}.sf-preview-head{display:flex;justify-content:space-between;align-items:center;gap:10px;background:linear-gradient(135deg,var(--sf-primary-dark),var(--sf-primary));color:#fff;border-radius:18px;padding:14px}.sf-logo{width:48px;height:48px;border-radius:14px;object-fit:cover;background:#fff;border:1px solid rgba(255,255,255,.45)}.sf-hero{margin-top:12px;border-radius:18px;padding:18px;color:#fff;background:linear-gradient(135deg,var(--sf-primary),var(--sf-primary-dark));position:relative;overflow:hidden}.sf-hero:after{content:'';position:absolute;width:100px;height:100px;border-radius:50%;background:var(--sf-accent);opacity:.35;inset-inline-start:-28px;bottom:-38px}.sf-chips{display:flex;gap:7px;flex-wrap:wrap;margin-top:12px}.sf-chip{padding:6px 10px;border-radius:999px;background:#fff;border:1px solid #e2e8f0;font-size:.78rem}.sf-list{display:grid;gap:10px}.sf-item{border:1px solid #e2e8f0;border-radius:14px;padding:12px;background:#fff}.sf-item-head{display:flex;justify-content:space-between;gap:10px;align-items:center}.sf-banner-thumb{width:92px;height:58px;border-radius:10px;object-fit:cover;border:1px solid #e2e8f0;background:#f8fafc}.sf-zone{display:flex;justify-content:space-between;align-items:center;gap:10px;border:1px solid #e2e8f0;border-radius:12px;padding:10px}.sf-tabs{display:flex;gap:7px;flex-wrap:wrap}.sf-tab{padding:7px 10px;border-radius:999px;background:#f8fafc;border:1px solid #e2e8f0;font-size:.8rem;font-weight:700}.sf-code{font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:.78rem;color:#475569}.sf-danger{border:1px solid #fecaca;background:#fff1f2;color:#b91c1c;border-radius:9px;padding:7px 10px;cursor:pointer}@media(max-width:980px){.sf-grid{grid-template-columns:1fr}.sf-form-grid{grid-template-columns:1fr}.sf-form-grid .wide{grid-column:auto}}
</style>

<div class="sf-builder">
    <div class="sf-grid">
        <section class="sf-card">
            <h3>{{ app()->getLocale()==='ar' ? 'هوية وتصميم المتجر' : 'Store branding & theme' }}</h3>
            <p class="sf-muted">{{ app()->getLocale()==='ar' ? 'كل تعديل هنا يُحفظ أولاً كمسودة ولا يصل للتطبيق الحقيقي إلا بعد النشر.' : 'Every change here is saved as Draft first and reaches the real app only after Publish.' }}</p>
            @if($canManageStorefront)
            <form method="post" action="{{ route('admin.b2c.storefront.settings') }}" enctype="multipart/form-data">
                @csrf @method('PUT')
                <input type="hidden" name="store_id" value="{{ $storeId }}">
                @if($supportAccess)<input type="hidden" name="support_access" value="1">@endif
                <div class="sf-form-grid">
                    <label class="sf-label">{{ app()->getLocale()==='ar'?'القالب':'Theme' }}
                        <select name="theme_code" required>
                            @foreach($sfThemes as $value=>$label)<option value="{{ $value }}" @selected(($sfSettings['theme_code'] ?? '')===$value)>{{ $label }}</option>@endforeach
                        </select>
                    </label>
                    <label class="sf-label">{{ app()->getLocale()==='ar'?'شعار المتجر':'Store logo' }}
                        <input type="file" name="logo" accept="image/jpeg,image/png,image/webp">
                    </label>
                    <label class="sf-label">{{ app()->getLocale()==='ar'?'اللون الرئيسي':'Primary color' }}<input type="color" name="primary_color" value="{{ $primary }}"></label>
                    <label class="sf-label">{{ app()->getLocale()==='ar'?'اللون الرئيسي الداكن':'Primary dark' }}<input type="color" name="primary_dark_color" value="{{ $primaryDark }}"></label>
                    <label class="sf-label">{{ app()->getLocale()==='ar'?'لون التمييز':'Accent color' }}<input type="color" name="accent_color" value="{{ $accent }}"></label>
                    <label class="sf-label">{{ app()->getLocale()==='ar'?'لون الخلفية':'Background color' }}<input type="color" name="background_color" value="{{ $background }}"></label>
                    <label class="sf-label wide">{{ app()->getLocale()==='ar'?'عنوان التوصيل الظاهر':'Header delivery address' }}<input name="header_address" maxlength="255" value="{{ $sfSettings['header_address'] ?? '' }}" placeholder="{{ app()->getLocale()==='ar'?'مثال: التوصيل إلى سموحة':'e.g. Deliver to Smouha' }}"></label>
                    <label class="sf-label">{{ app()->getLocale()==='ar'?'اسم الهوية بالعربية':'Arabic brand title' }}<input name="brand_title_ar" maxlength="255" value="{{ $sfSettings['brand_title_ar'] ?? '' }}" placeholder="فودكس"></label>
                    <label class="sf-label">{{ app()->getLocale()==='ar'?'اسم الهوية بالإنجليزية':'English brand title' }}<input name="brand_title_en" maxlength="255" value="{{ $sfSettings['brand_title_en'] ?? '' }}" placeholder="FOODEX"></label>
                    <label class="sf-label">{{ app()->getLocale()==='ar'?'وصف قصير بالعربية':'Arabic subtitle' }}<input name="brand_subtitle_ar" maxlength="500" value="{{ $sfSettings['brand_subtitle_ar'] ?? '' }}" placeholder="{{ app()->getLocale()==='ar'?'طازج وسريع إلى بابك':'Arabic subtitle' }}"></label>
                    <label class="sf-label">{{ app()->getLocale()==='ar'?'وصف قصير بالإنجليزية':'English subtitle' }}<input name="brand_subtitle_en" maxlength="500" value="{{ $sfSettings['brand_subtitle_en'] ?? '' }}" placeholder="Fresh and fast"></label>
                </div>
                <div class="sf-actions"><button class="foodex-primary" type="submit">{{ app()->getLocale()==='ar'?'حفظ المسودة':'Save Draft' }}</button></div>
            </form>
            @endif
        </section>

        <aside class="sf-card">
            <h3>{{ app()->getLocale()==='ar'?'المسودة والمعاينة الحقيقية':'Draft & real app preview' }}</h3>
            <p class="sf-muted">
                {{ app()->getLocale()==='ar'
                    ? 'المعاينة المعتمدة تستخدم نفس Flutter runtime ونفس العقود الخاصة بالموبايل.'
                    : 'The authoritative preview uses the same Flutter runtime and mobile contracts.' }}
            </p>
            <div class="sf-item" style="margin-top:12px">
                <div class="sf-item-head">
                    <strong>{{ $sfHasDraft ? (app()->getLocale()==='ar'?'مسودة نشطة':'Active Draft') : (app()->getLocale()==='ar'?'النسخة المنشورة':'Published') }}</strong>
                    <span class="sf-tab">{{ $sfHasDraft ? (app()->getLocale()==='ar'?'مسودة':'Draft') : (app()->getLocale()==='ar'?'منشور':'Published') }}</span>
                </div>
                <div class="sf-code" style="margin-top:8px">rev {{ $sfRevision['revision_id'] ?? '—' }}</div>
                <div class="sf-code">{{ substr((string)($sfRevision['checksum'] ?? ''),0,16) }}</div>
            </div>
            <div class="sf-actions">
                <a class="foodex-primary" href="{{ $sfPreviewUrl }}">{{ app()->getLocale()==='ar'?'فتح المعاينة الحقيقية':'Open real app preview' }}</a>
                @if($sfHasDraft && $sfCanPublish)
                    <form method="post" action="{{ route('admin.b2c.storefront.publish') }}">@csrf<input type="hidden" name="store_id" value="{{ $storeId }}">@if($supportAccess)<input type="hidden" name="support_access" value="1">@endif<button class="foodex-primary" type="submit">{{ app()->getLocale()==='ar'?'نشر المسودة':'Publish Draft' }}</button></form>
                    <form method="post" action="{{ route('admin.b2c.storefront.discard') }}" onsubmit="return confirm(@json(app()->getLocale()==='ar'?'إرجاع المسودة إلى النسخة المنشورة؟':'Reset Draft to Published?'))">@csrf<input type="hidden" name="store_id" value="{{ $storeId }}">@if($supportAccess)<input type="hidden" name="support_access" value="1">@endif<button class="sf-secondary" type="submit">{{ app()->getLocale()==='ar'?'إلغاء تغييرات المسودة':'Discard Draft changes' }}</button></form>
                @endif
            </div>
        </aside>
    </div>

    <div class="sf-grid">
        <section class="sf-card">
            <h3>{{ app()->getLocale()==='ar'?'ترتيب أقسام الصفحة الرئيسية':'Home layout sections' }}</h3>
            <p class="sf-muted">{{ app()->getLocale()==='ar'?'غيّر النوع والعنوان والترتيب والحالة بدون تعديل Flutter.' : 'Manage section type, titles, order and visibility without changing Flutter.' }}</p>
            @if($canManageStorefront)
            <form method="post" action="{{ route('admin.b2c.storefront.sections.store') }}" class="sf-item" style="margin-bottom:12px">
                @csrf
                <input type="hidden" name="store_id" value="{{ $storeId }}">
                @if($supportAccess)<input type="hidden" name="support_access" value="1">@endif
                <div class="sf-form-grid">
                    <label class="sf-label">{{ app()->getLocale()==='ar'?'مفتاح القسم':'Section key' }}<input name="section_key" required maxlength="80" placeholder="featured_products"></label>
                    <label class="sf-label">{{ app()->getLocale()==='ar'?'النوع':'Type' }}<select name="section_type" required>@foreach($sfSectionTypes as $value=>$label)<option value="{{ $value }}">{{ $label }}</option>@endforeach</select></label>
                    <label class="sf-label">{{ app()->getLocale()==='ar'?'العنوان العربي':'Arabic title' }}<input name="title_ar" maxlength="255" placeholder="منتجات مميزة"></label>
                    <label class="sf-label">{{ app()->getLocale()==='ar'?'العنوان الإنجليزي':'English title' }}<input name="title_en" maxlength="255" placeholder="Featured products"></label>
                    <label class="sf-label">{{ app()->getLocale()==='ar'?'الترتيب':'Sort order' }}<input name="sort_order" type="number" min="0" max="9999" value="50" required></label>
                    <label class="sf-label" style="align-content:end"><span><input type="checkbox" name="is_active" value="1" checked> {{ app()->getLocale()==='ar'?'نشط':'Active' }}</span></label>
                    @if($isSuper)
                    <details class="sf-label wide" data-advanced>
                        <summary>{{ app()->getLocale()==='ar'?'إعدادات تقنية متقدمة':'Advanced technical settings' }}</summary>
                        <label>{{ app()->getLocale()==='ar'?'إعدادات JSON':'Config JSON' }} <textarea name="config_json" rows="2" placeholder='{"limit":12}'></textarea></label>
                    </details>
                    @endif
                </div>
                <div class="sf-actions"><button class="foodex-primary" type="submit">{{ app()->getLocale()==='ar'?'إضافة للقسم في المسودة':'Add section to Draft' }}</button></div>
            </form>
            @endif

            <div class="sf-list">
                @forelse($sfSections as $section)
                <div class="sf-item">
                    <div class="sf-item-head"><strong>{{ $sfSectionTypes[$section['section_type']] ?? $section['section_type'] }}</strong><span class="sf-code">{{ $section['section_key'] }}</span></div>
                    @if($canManageStorefront)
                    <form method="post" action="{{ route('admin.b2c.storefront.sections.update',['section'=>$section['id']]) }}" style="margin-top:10px">
                        @csrf @method('PATCH')
                        <input type="hidden" name="store_id" value="{{ $storeId }}">
                        @if($supportAccess)<input type="hidden" name="support_access" value="1">@endif
                        <div class="sf-form-grid">
                            <label class="sf-label">{{ app()->getLocale()==='ar'?'المفتاح':'Key' }}<input name="section_key" value="{{ $section['section_key'] }}" required></label>
                            <label class="sf-label">{{ app()->getLocale()==='ar'?'النوع':'Type' }}<select name="section_type">@foreach($sfSectionTypes as $value=>$label)<option value="{{ $value }}" @selected($section['section_type']===$value)>{{ $label }}</option>@endforeach</select></label>
                            <label class="sf-label">{{ app()->getLocale()==='ar'?'العنوان العربي':'Arabic title' }}<input name="title_ar" value="{{ $section['title_ar'] }}"></label>
                            <label class="sf-label">{{ app()->getLocale()==='ar'?'العنوان الإنجليزي':'English title' }}<input name="title_en" value="{{ $section['title_en'] }}"></label>
                            <label class="sf-label">{{ app()->getLocale()==='ar'?'الترتيب':'Sort order' }}<input name="sort_order" type="number" min="0" max="9999" value="{{ $section['sort_order'] }}" required></label>
                            <label class="sf-label" style="align-content:end"><span><input type="checkbox" name="is_active" value="1" @checked($section['is_active'])> {{ app()->getLocale()==='ar'?'نشط':'Active' }}</span></label>
                            @if($isSuper)
                            <details class="sf-label wide" data-advanced>
                                <summary>{{ app()->getLocale()==='ar'?'إعدادات تقنية متقدمة':'Advanced technical settings' }}</summary>
                                <label>{{ app()->getLocale()==='ar'?'إعدادات JSON':'Config JSON' }}<textarea name="config_json" rows="2">{{ $section['config_json'] }}</textarea></label>
                            </details>
                            @endif
                        </div>
                        <div class="sf-actions"><button class="foodex-primary" type="submit">{{ app()->getLocale()==='ar'?'حفظ في المسودة':'Save to Draft' }}</button></div>
                    </form>
                    <form method="post" action="{{ route('admin.b2c.storefront.sections.destroy',['section'=>$section['id']]) }}" onsubmit="return confirm(@json(app()->getLocale()==='ar'?'حذف هذا القسم؟':'Delete this section?'))">
                        @csrf @method('DELETE')
                        <input type="hidden" name="store_id" value="{{ $storeId }}">
                        @if($supportAccess)<input type="hidden" name="support_access" value="1">@endif
                        <button class="sf-danger" type="submit">{{ app()->getLocale()==='ar'?'حذف':'Delete' }}</button>
                    </form>
                    @endif
                </div>
                @empty<p class="sf-muted">{{ app()->getLocale()==='ar'?'لا توجد أقسام مهيأة لهذا المتجر.':'No configured storefront sections.' }}</p>@endforelse
            </div>
        </section>

        <section class="sf-card">
            <h3>{{ app()->getLocale()==='ar'?'مناطق الخدمة':'Service zones' }}</h3>
            <p class="sf-muted">{{ app()->getLocale()==='ar'?'تحدد ظهور المتجر للعميل حسب الدولة والمدينة والمنطقة.' : 'Controls store discovery by country, city and area.' }}</p>
            @if($canManageStorefront)
            <form method="post" action="{{ route('admin.b2c.storefront.zones.store') }}" class="sf-item">
                @csrf
                <input type="hidden" name="store_id" value="{{ $storeId }}">
                @if($supportAccess)<input type="hidden" name="support_access" value="1">@endif
                <div class="sf-form-grid">
                    <label class="sf-label">{{ app()->getLocale()==='ar'?'الدولة':'Country' }}<input name="country_code" maxlength="2" value="EG" required placeholder="EG"></label>
                    <label class="sf-label">{{ app()->getLocale()==='ar'?'المدينة':'City' }}<input name="city" maxlength="120" placeholder="{{ app()->getLocale()==='ar'?'الإسكندرية':'Alexandria' }}"></label>
                    <label class="sf-label">{{ app()->getLocale()==='ar'?'المنطقة':'Area' }}<input name="area" maxlength="120" placeholder="{{ app()->getLocale()==='ar'?'سموحة':'Smouha' }}"></label>
                    <label class="sf-label" style="align-content:end"><span><input type="checkbox" name="is_active" value="1" checked> {{ app()->getLocale()==='ar'?'نشطة':'Active' }}</span></label>
                </div>
                <div class="sf-actions"><button class="foodex-primary" type="submit">{{ app()->getLocale()==='ar'?'إضافة للـمسودة':'Add to Draft' }}</button></div>
            </form>
            @endif
            <div class="sf-list" style="margin-top:12px">
                @forelse($sfZones as $zone)
                <div class="sf-zone">
                    <span><strong>{{ $zone['country_code'] }}</strong> · {{ $zone['city'] ?: (app()->getLocale()==='ar'?'كل المدن':'All cities') }} · {{ $zone['area'] ?: (app()->getLocale()==='ar'?'كل المناطق':'All areas') }}</span>
                    @if($canManageStorefront)<form method="post" action="{{ route('admin.b2c.storefront.zones.destroy',['zone'=>$zone['id']]) }}">@csrf @method('DELETE')<input type="hidden" name="store_id" value="{{ $storeId }}">@if($supportAccess)<input type="hidden" name="support_access" value="1">@endif<button class="sf-danger" type="submit">{{ app()->getLocale()==='ar'?'حذف':'Delete' }}</button></form>@endif
                </div>
                @empty<p class="sf-muted">{{ app()->getLocale()==='ar'?'لا توجد مناطق خدمة؛ المتجر غير مقيد بمنطقة محددة.' : 'No service zones; store discovery is not geographically restricted.' }}</p>@endforelse
            </div>
        </section>
    </div>

    <section class="sf-card">
        <div class="sf-item-head"><div><h3>{{ app()->getLocale()==='ar'?'بانرات واجهة المتجر':'Storefront banners' }}</h3><p class="sf-muted">{{ app()->getLocale()==='ar'?'رفع حقيقي للصورة مع معاينة وربط اختياري بمنتج أو تصنيف.' : 'Real image upload with preview and optional product/category target.' }}</p></div><span class="sf-tab">{{ count($sfBanners) }} {{ app()->getLocale()==='ar'?'بانر':'banners' }}</span></div>
        @if($canManageBanners)
        <form method="post" action="{{ route('admin.business.banners.store') }}" enctype="multipart/form-data" class="sf-item" style="margin:12px 0">
            @csrf
            <input type="hidden" name="store_id" value="{{ $storeId }}">
            @if($supportAccess)<input type="hidden" name="support_access" value="1">@endif
            <div class="sf-form-grid">
                <label class="sf-label">{{ app()->getLocale()==='ar'?'العنوان':'Title' }}<input name="title" required maxlength="255" placeholder="{{ app()->getLocale()==='ar'?'عرض اليوم':'Today\'s offer' }}"></label>
                <label class="sf-label">{{ app()->getLocale()==='ar'?'الصورة':'Image' }}<input type="file" name="banner_image" accept="image/jpeg,image/png,image/webp" required></label>
                <label class="sf-label">{{ app()->getLocale()==='ar'?'الهدف':'Target' }}<select name="target_ref"><option value="">{{ app()->getLocale()==='ar'?'بدون هدف':'No target' }}</option>@foreach($sfTargets as $target)<option value="{{ $target['ref'] }}">{{ $target['label'] }}</option>@endforeach</select></label>
                <label class="sf-label">{{ app()->getLocale()==='ar'?'الترتيب':'Sort order' }}<input type="number" name="sort_order" min="0" value="0" required></label>
                <label class="sf-label"><span><input type="checkbox" name="is_active" value="1" checked> {{ app()->getLocale()==='ar'?'نشط':'Active' }}</span></label>
            </div>
            <div class="sf-actions"><button class="foodex-primary" type="submit">{{ app()->getLocale()==='ar'?'إضافة البانر للمسودة':'Add banner to Draft' }}</button></div>
        </form>
        @endif

        <div class="sf-list">
            @forelse($sfBanners as $banner)
            <div class="sf-item">
                <div class="sf-item-head">
                    <div style="display:flex;align-items:center;gap:10px">@if(!empty($banner['image']))<img class="sf-banner-thumb" src="{{ asset(ltrim($banner['image'],'/')) }}" alt="{{ $banner['title'] }}">@endif<div><strong>{{ $banner['title'] }}</strong><div class="sf-muted">{{ $banner['target'] ?? '—' }}</div></div></div>
                    <span class="sf-tab">#{{ $banner['sort_order'] }}</span>
                </div>
                @if($canManageBanners)
                <form method="post" action="{{ route('admin.business.banners.update',['banner'=>$banner['_id']]) }}" enctype="multipart/form-data" style="margin-top:10px">
                    @csrf @method('PATCH')
                    <input type="hidden" name="store_id" value="{{ $storeId }}">
                    @if($supportAccess)<input type="hidden" name="support_access" value="1">@endif
                    <div class="sf-form-grid">
                        <label class="sf-label">{{ app()->getLocale()==='ar'?'العنوان':'Title' }}<input name="title" value="{{ $banner['title'] }}" required></label>
                        <label class="sf-label">{{ app()->getLocale()==='ar'?'استبدال الصورة':'Replace image' }}<input type="file" name="banner_image" accept="image/jpeg,image/png,image/webp"></label>
                        <label class="sf-label">{{ app()->getLocale()==='ar'?'الهدف':'Target' }}<select name="target_ref"><option value="">{{ app()->getLocale()==='ar'?'بدون هدف':'No target' }}</option>@foreach($sfTargets as $target)<option value="{{ $target['ref'] }}" @selected(($banner['_target_ref'] ?? '')===$target['ref'])>{{ $target['label'] }}</option>@endforeach</select></label>
                        <label class="sf-label">{{ app()->getLocale()==='ar'?'الترتيب':'Sort order' }}<input type="number" name="sort_order" min="0" value="{{ $banner['sort_order'] }}" required></label>
                        <label class="sf-label"><span><input type="checkbox" name="is_active" value="1" @checked($banner['status'])> {{ app()->getLocale()==='ar'?'نشط':'Active' }}</span></label>
                    </div>
                    <div class="sf-actions"><button class="foodex-primary" type="submit">{{ app()->getLocale()==='ar'?'حفظ في المسودة':'Save to Draft' }}</button></div>
                </form>
                <form method="post" action="{{ route('admin.business.banners.destroy',['banner'=>$banner['_id']]) }}" onsubmit="return confirm(@json(app()->getLocale()==='ar'?'حذف هذا البانر وصورته؟':'Delete this banner and its image?'))">@csrf @method('DELETE')<input type="hidden" name="store_id" value="{{ $storeId }}">@if($supportAccess)<input type="hidden" name="support_access" value="1">@endif<button class="sf-danger" type="submit">{{ app()->getLocale()==='ar'?'حذف البانر':'Delete banner' }}</button></form>
                @endif
            </div>
            @empty<p class="sf-muted">{{ app()->getLocale()==='ar'?'لا توجد بانرات حتى الآن.':'No storefront banners yet.' }}</p>@endforelse
        </div>
    </section>
</div>
