@php
    $sfStore = $moduleData['store'] ?? [];
    $sfSettings = $moduleData['settings'] ?? [];
    $sfSections = $moduleData['sections'] ?? [];
    $sfBanners = $moduleData['banners'] ?? [];
    $sfTargets = $moduleData['targets'] ?? [];
    $sfSectionTypes = $moduleData['section_types'] ?? [];
    $canManageStorefront = $user->hasPermission('settings.manage');
    $primary = $sfSettings['primary_color'] ?? '#5D2A91';
    $primaryDark = $sfSettings['primary_dark_color'] ?? '#35195E';
    $accent = $sfSettings['accent_color'] ?? '#B983F0';
    $background = $sfSettings['background_color'] ?? '#FBFAFD';
@endphp

<style>
.wsf-grid{display:grid;grid-template-columns:minmax(0,1.15fr) minmax(320px,.85fr);gap:16px;margin-bottom:16px}.wsf-card{background:#fff;border:1px solid var(--foodex-border);border-radius:18px;padding:16px}.wsf-card h3{margin:0 0 6px}.wsf-muted{color:#667085;font-size:.9rem}.wsf-form{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}.wsf-form .wide{grid-column:1/-1}.wsf-card label{display:grid;gap:5px;font-size:.82rem;font-weight:700}.wsf-card input,.wsf-card select,.wsf-card textarea{width:100%;box-sizing:border-box;border:1px solid #cbd5e1;border-radius:10px;padding:9px 10px;background:#fff}.wsf-card input[type=color]{padding:3px;height:42px}.wsf-card input[type=checkbox]{width:auto}.wsf-preview{background:var(--wsf-bg);border:1px solid #e5e7eb;border-radius:24px;padding:16px;min-height:430px;overflow:hidden}.wsf-preview-head{display:flex;align-items:center;gap:10px;color:#fff;background:linear-gradient(135deg,var(--wsf-dark),var(--wsf-primary));border-radius:18px;padding:14px}.wsf-logo{width:50px;height:50px;border-radius:14px;background:#fff;object-fit:cover}.wsf-badge{background:var(--wsf-accent);color:#221430;border-radius:999px;padding:5px 9px;font-size:.72rem;font-weight:800}.wsf-hero{margin-top:12px;border-radius:18px;padding:20px;color:#fff;background:linear-gradient(135deg,var(--wsf-primary),var(--wsf-dark));min-height:125px}.wsf-chip-row{display:flex;gap:7px;flex-wrap:wrap;margin-top:12px}.wsf-chip{background:#fff;border:1px solid #e5e7eb;border-radius:999px;padding:6px 9px;font-size:.75rem}.wsf-list{display:grid;gap:10px}.wsf-item{border:1px solid #e5e7eb;border-radius:14px;padding:12px}.wsf-actions{display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-top:10px}.wsf-danger{border:1px solid #fecaca;background:#fff1f2;color:#b91c1c;border-radius:9px;padding:7px 10px;cursor:pointer}.wsf-thumb{width:96px;height:58px;border-radius:10px;object-fit:cover;border:1px solid #e5e7eb}.wsf-item-head{display:flex;justify-content:space-between;gap:10px;align-items:center}.wsf-code{font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:.76rem;color:#667085}@media(max-width:980px){.wsf-grid{grid-template-columns:1fr}.wsf-form{grid-template-columns:1fr}.wsf-form .wide{grid-column:auto}}
</style>

<div class="wsf-grid">
    <section class="wsf-card">
        <h3>{{ app()->getLocale()==='ar'?'هوية وتصميم متجر الجملة':'Wholesale branding & theme' }}</h3>
        <p class="wsf-muted">{{ app()->getLocale()==='ar'?'تتحكم هذه الإعدادات مباشرة في واجهة متجر الجملة داخل تطبيق العميل.':'These settings feed the Wholesale storefront in the customer app.' }}</p>
        @if($canManageStorefront)
        <form method="post" action="{{ route('admin.b2b.storefront.settings') }}" enctype="multipart/form-data">
            @csrf @method('PUT')
            <input type="hidden" name="store_id" value="{{ $sfStore['id'] ?? 0 }}">
            <input type="hidden" name="theme_code" value="wholesale_b2b">
            <div class="wsf-form">
                <label>{{ app()->getLocale()==='ar'?'الشعار':'Logo' }}<input type="file" name="logo" accept="image/jpeg,image/png,image/webp"></label>
                <label>{{ app()->getLocale()==='ar'?'عنوان التوصيل/التغطية':'Header location text' }}<input name="header_address" maxlength="255" value="{{ $sfSettings['header_address'] ?? '' }}" placeholder="{{ app()->getLocale()==='ar'?'مثال: توريد القاهرة والإسكندرية':'e.g. Cairo & Alexandria supply' }}"></label>
                <label>{{ app()->getLocale()==='ar'?'اللون الرئيسي':'Primary' }}<input type="color" name="primary_color" value="{{ $primary }}"></label>
                <label>{{ app()->getLocale()==='ar'?'اللون الداكن':'Primary dark' }}<input type="color" name="primary_dark_color" value="{{ $primaryDark }}"></label>
                <label>{{ app()->getLocale()==='ar'?'لون التمييز':'Accent' }}<input type="color" name="accent_color" value="{{ $accent }}"></label>
                <label>{{ app()->getLocale()==='ar'?'الخلفية':'Background' }}<input type="color" name="background_color" value="{{ $background }}"></label>
                <label>{{ app()->getLocale()==='ar'?'اسم الهوية بالعربية':'Arabic brand title' }}<input name="brand_title_ar" value="{{ $sfSettings['brand_title_ar'] ?? '' }}" maxlength="255" placeholder="FOODEX جملة"></label>
                <label>{{ app()->getLocale()==='ar'?'اسم الهوية بالإنجليزية':'English brand title' }}<input name="brand_title_en" value="{{ $sfSettings['brand_title_en'] ?? '' }}" maxlength="255" placeholder="FOODEX Wholesale"></label>
                <label>{{ app()->getLocale()==='ar'?'عنوان الهيرو بالعربية':'Arabic hero title' }}<input name="brand_subtitle_ar" value="{{ $sfSettings['brand_subtitle_ar'] ?? '' }}" maxlength="500" placeholder="أفضل الأسعار لمتاجر التجزئة"></label>
                <label>{{ app()->getLocale()==='ar'?'عنوان الهيرو بالإنجليزية':'English hero title' }}<input name="brand_subtitle_en" value="{{ $sfSettings['brand_subtitle_en'] ?? '' }}" maxlength="500" placeholder="Best prices for retailers"></label>
                <label>{{ app()->getLocale()==='ar'?'زر الهيرو بالعربية':'Arabic hero CTA' }}<input name="hero_cta_ar" value="{{ $sfSettings['hero_cta_ar'] ?? '' }}" maxlength="120" placeholder="تصفح الكتالوج"></label>
                <label>{{ app()->getLocale()==='ar'?'زر الهيرو بالإنجليزية':'English hero CTA' }}<input name="hero_cta_en" value="{{ $sfSettings['hero_cta_en'] ?? '' }}" maxlength="120" placeholder="Browse catalog"></label>
            </div>
            <div class="wsf-actions"><button class="foodex-primary" type="submit">{{ app()->getLocale()==='ar'?'حفظ واجهة الجملة':'Save Wholesale storefront' }}</button></div>
        </form>
        @endif
    </section>

    <aside class="wsf-card">
        <h3>{{ app()->getLocale()==='ar'?'معاينة مباشرة':'Live preview' }}</h3>
        <div class="wsf-preview" style="--wsf-primary:{{ $primary }};--wsf-dark:{{ $primaryDark }};--wsf-accent:{{ $accent }};--wsf-bg:{{ $background }}">
            <div class="wsf-preview-head">
                @if(!empty($sfStore['logo_path']))<img class="wsf-logo" src="{{ asset(ltrim($sfStore['logo_path'],'/')) }}" alt="logo">@else<div class="wsf-logo"></div>@endif
                <div style="flex:1"><strong>{{ app()->getLocale()==='ar' ? (($sfSettings['brand_title_ar'] ?? '') ?: ($sfStore['name'] ?? 'متجر الجملة')) : (($sfSettings['brand_title_en'] ?? '') ?: ($sfStore['name'] ?? 'Wholesale')) }}</strong><div style="font-size:.76rem;opacity:.85">{{ $sfSettings['header_address'] ?? '' }}</div></div>
                <span class="wsf-badge">B2B</span>
            </div>
            <div class="wsf-hero"><strong style="font-size:1.15rem">{{ app()->getLocale()==='ar' ? (($sfSettings['brand_subtitle_ar'] ?? '') ?: 'أفضل الأسعار لمتاجر التجزئة') : (($sfSettings['brand_subtitle_en'] ?? '') ?: 'Best prices for retailers') }}</strong><div style="margin-top:12px"><span class="wsf-badge">{{ app()->getLocale()==='ar' ? (($sfSettings['hero_cta_ar'] ?? '') ?: 'تصفح الكتالوج') : (($sfSettings['hero_cta_en'] ?? '') ?: 'Browse catalog') }}</span></div></div>
            <div class="wsf-chip-row">@foreach(array_slice($sfSections,0,8) as $section)<span class="wsf-chip">{{ $sfSectionTypes[$section['section_type']] ?? $section['section_type'] }} · {{ $section['sort_order'] }}</span>@endforeach</div>
        </div>
    </aside>
</div>

<div class="wsf-grid">
    <section class="wsf-card">
        <h3>{{ app()->getLocale()==='ar'?'أقسام الصفحة الرئيسية':'Home sections' }}</h3>
        @if($canManageStorefront)
        <form method="post" action="{{ route('admin.b2b.storefront.sections.store') }}" class="wsf-item" style="margin-bottom:12px">
            @csrf
            <input type="hidden" name="store_id" value="{{ $sfStore['id'] ?? 0 }}">
            <div class="wsf-form">
                <label>{{ app()->getLocale()==='ar'?'مفتاح القسم':'Section key' }}<input name="section_key" required maxlength="80" placeholder="offers"></label>
                <label>{{ app()->getLocale()==='ar'?'النوع':'Type' }}<select name="section_type">@foreach($sfSectionTypes as $value=>$label)<option value="{{ $value }}">{{ $label }}</option>@endforeach</select></label>
                <label>{{ app()->getLocale()==='ar'?'العنوان العربي':'Arabic title' }}<input name="title_ar" maxlength="255"></label>
                <label>{{ app()->getLocale()==='ar'?'العنوان الإنجليزي':'English title' }}<input name="title_en" maxlength="255"></label>
                <label>{{ app()->getLocale()==='ar'?'الترتيب':'Sort order' }}<input type="number" name="sort_order" value="50" min="0" max="9999" required></label>
                <label style="align-content:end"><span><input type="checkbox" name="is_active" value="1" checked> {{ app()->getLocale()==='ar'?'نشط':'Active' }}</span></label>
                <label class="wide">Config JSON<textarea name="config_json" rows="2" placeholder='{"limit":12}'></textarea></label>
            </div>
            <div class="wsf-actions"><button class="foodex-primary" type="submit">{{ app()->getLocale()==='ar'?'إضافة القسم':'Add section' }}</button></div>
        </form>
        @endif
        <div class="wsf-list">
        @forelse($sfSections as $section)
            <div class="wsf-item">
                <div class="wsf-item-head"><strong>{{ $sfSectionTypes[$section['section_type']] ?? $section['section_type'] }}</strong><span class="wsf-code">{{ $section['section_key'] }}</span></div>
                @if($canManageStorefront)
                <form method="post" action="{{ route('admin.b2b.storefront.sections.update',['section'=>$section['id']]) }}" style="margin-top:10px">
                    @csrf @method('PATCH')
                    <input type="hidden" name="store_id" value="{{ $sfStore['id'] ?? 0 }}">
                    <div class="wsf-form">
                        <label>{{ app()->getLocale()==='ar'?'المفتاح':'Key' }}<input name="section_key" value="{{ $section['section_key'] }}" required></label>
                        <label>{{ app()->getLocale()==='ar'?'النوع':'Type' }}<select name="section_type">@foreach($sfSectionTypes as $value=>$label)<option value="{{ $value }}" @selected($section['section_type']===$value)>{{ $label }}</option>@endforeach</select></label>
                        <label>{{ app()->getLocale()==='ar'?'العنوان العربي':'Arabic title' }}<input name="title_ar" value="{{ $section['title_ar'] }}"></label>
                        <label>{{ app()->getLocale()==='ar'?'العنوان الإنجليزي':'English title' }}<input name="title_en" value="{{ $section['title_en'] }}"></label>
                        <label>{{ app()->getLocale()==='ar'?'الترتيب':'Sort order' }}<input type="number" name="sort_order" value="{{ $section['sort_order'] }}" min="0" max="9999" required></label>
                        <label style="align-content:end"><span><input type="checkbox" name="is_active" value="1" @checked($section['is_active'])> {{ app()->getLocale()==='ar'?'نشط':'Active' }}</span></label>
                        <label class="wide">Config JSON<textarea name="config_json" rows="2">{{ $section['config_json'] }}</textarea></label>
                    </div>
                    <div class="wsf-actions"><button class="foodex-primary" type="submit">{{ app()->getLocale()==='ar'?'حفظ':'Save' }}</button></div>
                </form>
                <form method="post" action="{{ route('admin.b2b.storefront.sections.destroy',['section'=>$section['id']]) }}" onsubmit="return confirm(@json(app()->getLocale()==='ar'?'حذف القسم؟':'Delete section?'))">@csrf @method('DELETE')<button class="wsf-danger" type="submit">{{ app()->getLocale()==='ar'?'حذف':'Delete' }}</button></form>
                @endif
            </div>
        @empty
            <p class="wsf-muted">{{ app()->getLocale()==='ar'?'لا توجد أقسام مهيأة.':'No configured sections.' }}</p>
        @endforelse
        </div>
    </section>

    <section class="wsf-card">
        <h3>{{ app()->getLocale()==='ar'?'بانرات متجر الجملة':'Wholesale banners' }}</h3>
        @if($canManageStorefront)
        <form method="post" action="{{ route('admin.b2b.storefront.banners.store') }}" enctype="multipart/form-data" class="wsf-item" style="margin-bottom:12px">
            @csrf
            <input type="hidden" name="store_id" value="{{ $sfStore['id'] ?? 0 }}">
            <div class="wsf-form">
                <label>{{ app()->getLocale()==='ar'?'العنوان':'Title' }}<input name="title" required maxlength="255"></label>
                <label>{{ app()->getLocale()==='ar'?'الصورة':'Image' }}<input type="file" name="banner_image" accept="image/jpeg,image/png,image/webp" required></label>
                <label>{{ app()->getLocale()==='ar'?'الهدف':'Target' }}<select name="target_ref"><option value="">{{ app()->getLocale()==='ar'?'بدون هدف':'No target' }}</option>@foreach($sfTargets as $target)<option value="{{ $target['ref'] }}">{{ $target['label'] }}</option>@endforeach</select></label>
                <label>{{ app()->getLocale()==='ar'?'الترتيب':'Sort order' }}<input type="number" name="sort_order" value="0" min="0" required></label>
                <label><span><input type="checkbox" name="is_active" value="1" checked> {{ app()->getLocale()==='ar'?'نشط':'Active' }}</span></label>
            </div>
            <div class="wsf-actions"><button class="foodex-primary" type="submit">{{ app()->getLocale()==='ar'?'رفع البانر':'Upload banner' }}</button></div>
        </form>
        @endif
        <div class="wsf-list">
        @forelse($sfBanners as $banner)
            <div class="wsf-item">
                <div class="wsf-item-head"><div style="display:flex;align-items:center;gap:9px">@if(!empty($banner['image']))<img class="wsf-thumb" src="{{ asset(ltrim($banner['image'],'/')) }}" alt="{{ $banner['title'] }}">@endif<strong>{{ $banner['title'] }}</strong></div><span class="wsf-code">#{{ $banner['sort_order'] }}</span></div>
                @if($canManageStorefront)
                <form method="post" action="{{ route('admin.b2b.storefront.banners.update',['banner'=>$banner['id']]) }}" enctype="multipart/form-data" style="margin-top:10px">
                    @csrf @method('PATCH')
                    <input type="hidden" name="store_id" value="{{ $sfStore['id'] ?? 0 }}">
                    <div class="wsf-form">
                        <label>{{ app()->getLocale()==='ar'?'العنوان':'Title' }}<input name="title" value="{{ $banner['title'] }}" required></label>
                        <label>{{ app()->getLocale()==='ar'?'استبدال الصورة':'Replace image' }}<input type="file" name="banner_image" accept="image/jpeg,image/png,image/webp"></label>
                        <label>{{ app()->getLocale()==='ar'?'الهدف':'Target' }}<select name="target_ref"><option value="">{{ app()->getLocale()==='ar'?'بدون هدف':'No target' }}</option>@foreach($sfTargets as $target)<option value="{{ $target['ref'] }}" @selected($banner['target_ref']===$target['ref'])>{{ $target['label'] }}</option>@endforeach</select></label>
                        <label>{{ app()->getLocale()==='ar'?'الترتيب':'Sort order' }}<input type="number" name="sort_order" value="{{ $banner['sort_order'] }}" min="0" required></label>
                        <label><span><input type="checkbox" name="is_active" value="1" @checked($banner['is_active'])> {{ app()->getLocale()==='ar'?'نشط':'Active' }}</span></label>
                    </div>
                    <div class="wsf-actions"><button class="foodex-primary" type="submit">{{ app()->getLocale()==='ar'?'حفظ البانر':'Save banner' }}</button></div>
                </form>
                <form method="post" action="{{ route('admin.b2b.storefront.banners.destroy',['banner'=>$banner['id']]) }}" onsubmit="return confirm(@json(app()->getLocale()==='ar'?'حذف البانر؟':'Delete banner?'))">@csrf @method('DELETE')<button class="wsf-danger" type="submit">{{ app()->getLocale()==='ar'?'حذف':'Delete' }}</button></form>
                @endif
            </div>
        @empty
            <p class="wsf-muted">{{ app()->getLocale()==='ar'?'لا توجد بانرات حالياً.':'No banners yet.' }}</p>
        @endforelse
        </div>
    </section>
</div>
