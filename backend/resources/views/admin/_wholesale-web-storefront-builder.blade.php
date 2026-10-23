@php
    $webStore = $moduleData['store'] ?? [];
    $webSettings = $moduleData['settings'] ?? [];
    $webSections = $moduleData['sections'] ?? [];
    $webBanners = $moduleData['banners'] ?? [];
    $webTargets = $moduleData['targets'] ?? [];
    $webSectionTypes = $moduleData['section_types'] ?? [];
    $webRevision = $moduleData['revision'] ?? [];
    $webHasDraft = (bool)($webRevision['has_draft'] ?? false);
    $webStoreId = (int)($webStore['id'] ?? 0);
    $canManageWeb = $user->hasPermission('settings.manage');
    $canPublishWeb = $webStoreId > 0 && ($user->hasPermission('app_preview.publish', $webStoreId) || $user->hasPermission('app_preview.publish'));
    $webEnabled = (bool)($webSettings['public_web_enabled'] ?? false);
@endphp

<style>
.wweb-grid{display:grid;grid-template-columns:minmax(0,1.15fr) minmax(320px,.85fr);gap:16px;margin-bottom:16px}.wweb-card{background:#fff;border:1px solid var(--foodex-border);border-radius:18px;padding:16px}.wweb-card h3{margin:0 0 6px}.wweb-muted{color:#667085;font-size:.9rem}.wweb-form{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}.wweb-form .wide{grid-column:1/-1}.wweb-card label{display:grid;gap:5px;font-size:.82rem;font-weight:700}.wweb-card input,.wweb-card select,.wweb-card textarea{width:100%;box-sizing:border-box;border:1px solid #cbd5e1;border-radius:10px;padding:9px 10px;background:#fff}.wweb-card input[type=checkbox]{width:auto}.wweb-actions{display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-top:10px}.wweb-item{border:1px solid #e5e7eb;border-radius:14px;padding:12px}.wweb-list{display:grid;gap:10px}.wweb-item-head{display:flex;justify-content:space-between;gap:10px;align-items:center}.wweb-code{font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:.76rem;color:#667085}.wweb-danger{border:1px solid #fecaca;background:#fff1f2;color:#b91c1c;border-radius:9px;padding:7px 10px;cursor:pointer}.wweb-thumb{width:96px;height:58px;border-radius:10px;object-fit:cover;border:1px solid #e5e7eb}.wweb-status{display:inline-flex;align-items:center;border-radius:999px;padding:5px 9px;font-size:.72rem;font-weight:800;background:var(--foodex-green-soft);color:var(--foodex-green-dark)}@media(max-width:980px){.wweb-grid{grid-template-columns:1fr}.wweb-form{grid-template-columns:1fr}.wweb-form .wide{grid-column:auto}}
</style>

<div class="wweb-grid">
    <section class="wweb-card">
        <h3>{{ app()->getLocale()==='ar'?'الموقع العام لمتجر الجملة':'Public Wholesale Web' }}</h3>
        <p class="wweb-muted">{{ app()->getLocale()==='ar'?'هذه إعدادات الويب فقط. تطبيق العميل له مسودة وأقسام وبانرات مستقلة تماماً. الهوية الأساسية فقط مشتركة.':'These settings control Public Web only. The Customer App has a completely separate Draft, sections and banners. Only base identity is shared.' }}</p>
        <div class="wweb-actions">
            <a class="foodex-primary" href="{{ route('admin.b2b.module',['module'=>'storefront']) }}">{{ app()->getLocale()==='ar'?'فتح إعدادات تطبيق العميل':'Open Customer App settings' }}</a>
            @if($webEnabled)<a class="foodex-primary" href="{{ route('public.wholesale.home') }}" target="_blank" rel="noopener">{{ app()->getLocale()==='ar'?'فتح الموقع المنشور':'Open published site' }}</a>@endif
        </div>

        @if($canManageWeb)
        <form method="post" action="{{ route('admin.b2b.web-storefront.settings') }}" style="margin-top:14px">
            @csrf @method('PUT')
            <input type="hidden" name="store_id" value="{{ $webStoreId }}">
            <div class="wweb-item" style="margin-bottom:12px">
                <div class="wweb-item-head">
                    <div><strong>{{ app()->getLocale()==='ar'?'تشغيل الموقع العام':'Public site availability' }}</strong><div class="wweb-muted">{{ app()->getLocale()==='ar'?'عند الإيقاف يفتح رابط الموقع صفحة دخول الإدارة. عند التشغيل يظهر الكتالوج بدون أسعار أو شراء.':'When disabled the public URL redirects to management login. When enabled guests can browse without pricing or checkout.' }}</div></div>
                    <span class="wweb-status">{{ $webEnabled ? (app()->getLocale()==='ar'?'مفتوح':'ON') : (app()->getLocale()==='ar'?'مغلق':'OFF') }}</span>
                </div>
                <input type="hidden" name="public_web_enabled" value="0">
                <label style="margin-top:10px"><span><input type="checkbox" name="public_web_enabled" value="1" @checked($webEnabled)> {{ app()->getLocale()==='ar'?'تشغيل الصفحة العامة':'Enable public landing page' }}</span></label>
            </div>

            <div class="wweb-form">
                <label>{{ app()->getLocale()==='ar'?'عنوان الهيرو بالعربية':'Arabic hero title' }}<input name="hero_title_ar" value="{{ $webSettings['web_hero_title_ar'] ?? '' }}" maxlength="255"></label>
                <label>{{ app()->getLocale()==='ar'?'عنوان الهيرو بالإنجليزية':'English hero title' }}<input name="hero_title_en" value="{{ $webSettings['web_hero_title_en'] ?? '' }}" maxlength="255"></label>
                <label>{{ app()->getLocale()==='ar'?'وصف الهيرو بالعربية':'Arabic hero subtitle' }}<textarea name="hero_subtitle_ar" rows="2" maxlength="500">{{ $webSettings['web_hero_subtitle_ar'] ?? '' }}</textarea></label>
                <label>{{ app()->getLocale()==='ar'?'وصف الهيرو بالإنجليزية':'English hero subtitle' }}<textarea name="hero_subtitle_en" rows="2" maxlength="500">{{ $webSettings['web_hero_subtitle_en'] ?? '' }}</textarea></label>
                <label>{{ app()->getLocale()==='ar'?'زر الهيرو بالعربية':'Arabic hero CTA' }}<input name="hero_cta_ar" value="{{ $webSettings['web_hero_cta_ar'] ?? '' }}" maxlength="120"></label>
                <label>{{ app()->getLocale()==='ar'?'زر الهيرو بالإنجليزية':'English hero CTA' }}<input name="hero_cta_en" value="{{ $webSettings['web_hero_cta_en'] ?? '' }}" maxlength="120"></label>

                <label class="wide">{{ app()->getLocale()==='ar'?'رابط صفحة فيسبوك':'Facebook page URL' }}<input type="url" name="facebook_url" value="{{ $webSettings['facebook_url'] ?? '' }}" maxlength="500" placeholder="https://www.facebook.com/..."></label>
                <label>{{ app()->getLocale()==='ar'?'عنوان فيسبوك بالعربية':'Arabic Facebook title' }}<input name="facebook_title_ar" value="{{ $webSettings['facebook_title_ar'] ?? '' }}" maxlength="255"></label>
                <label>{{ app()->getLocale()==='ar'?'عنوان فيسبوك بالإنجليزية':'English Facebook title' }}<input name="facebook_title_en" value="{{ $webSettings['facebook_title_en'] ?? '' }}" maxlength="255"></label>
                <label>{{ app()->getLocale()==='ar'?'نص فيسبوك بالعربية':'Arabic Facebook text' }}<textarea name="facebook_text_ar" rows="2" maxlength="500">{{ $webSettings['facebook_text_ar'] ?? '' }}</textarea></label>
                <label>{{ app()->getLocale()==='ar'?'نص فيسبوك بالإنجليزية':'English Facebook text' }}<textarea name="facebook_text_en" rows="2" maxlength="500">{{ $webSettings['facebook_text_en'] ?? '' }}</textarea></label>

                <label>{{ app()->getLocale()==='ar'?'عنوان SEO بالعربية':'Arabic SEO title' }}<input name="meta_title_ar" value="{{ $webSettings['web_meta_title_ar'] ?? '' }}" maxlength="255"></label>
                <label>{{ app()->getLocale()==='ar'?'عنوان SEO بالإنجليزية':'English SEO title' }}<input name="meta_title_en" value="{{ $webSettings['web_meta_title_en'] ?? '' }}" maxlength="255"></label>
                <label>{{ app()->getLocale()==='ar'?'وصف SEO بالعربية':'Arabic SEO description' }}<textarea name="meta_description_ar" rows="2" maxlength="500">{{ $webSettings['web_meta_description_ar'] ?? '' }}</textarea></label>
                <label>{{ app()->getLocale()==='ar'?'وصف SEO بالإنجليزية':'English SEO description' }}<textarea name="meta_description_en" rows="2" maxlength="500">{{ $webSettings['web_meta_description_en'] ?? '' }}</textarea></label>
            </div>
            <div class="wweb-actions"><button class="foodex-primary" type="submit">{{ app()->getLocale()==='ar'?'حفظ مسودة الويب':'Save Web Draft' }}</button></div>
        </form>
        @endif
    </section>

    <aside class="wweb-card">
        <h3>{{ app()->getLocale()==='ar'?'مسودة الويب والنشر':'Web Draft & Publish' }}</h3>
        <p class="wweb-muted">{{ app()->getLocale()==='ar'?'نشر الويب مستقل عن نشر تطبيق العميل.':'Web publishing is independent from Customer App publishing.' }}</p>
        <div class="wweb-item">
            <div class="wweb-item-head"><strong>{{ $webHasDraft ? (app()->getLocale()==='ar'?'مسودة ويب نشطة':'Active Web Draft') : (app()->getLocale()==='ar'?'نسخة الويب المنشورة':'Published Web') }}</strong><span class="wweb-code">rev {{ $webRevision['revision_id'] ?? '—' }}</span></div>
            <div class="wweb-code" style="margin-top:8px">{{ substr((string)($webRevision['checksum'] ?? ''),0,16) }}</div>
        </div>
        @if($webHasDraft && $canPublishWeb)
        <div class="wweb-actions">
            <form method="post" action="{{ route('admin.b2b.web-storefront.publish') }}">@csrf<input type="hidden" name="store_id" value="{{ $webStoreId }}"><button class="foodex-primary" type="submit">{{ app()->getLocale()==='ar'?'نشر مسودة الويب':'Publish Web Draft' }}</button></form>
            <form method="post" action="{{ route('admin.b2b.web-storefront.discard') }}" onsubmit="return confirm(@json(app()->getLocale()==='ar'?'إلغاء تغييرات مسودة الويب؟':'Discard Web Draft changes?'))">@csrf<input type="hidden" name="store_id" value="{{ $webStoreId }}"><button class="wweb-danger" type="submit">{{ app()->getLocale()==='ar'?'إلغاء تغييرات الويب':'Discard Web changes' }}</button></form>
        </div>
        @endif
    </aside>
</div>

<div class="wweb-grid">
    <section class="wweb-card">
        <h3>{{ app()->getLocale()==='ar'?'أقسام الموقع العام':'Public Web sections' }}</h3>
        @if($canManageWeb)
        <form method="post" action="{{ route('admin.b2b.web-storefront.sections.store') }}" class="wweb-item" style="margin-bottom:12px">
            @csrf
            <input type="hidden" name="store_id" value="{{ $webStoreId }}">
            <div class="wweb-form">
                <label>{{ app()->getLocale()==='ar'?'مفتاح القسم':'Section key' }}<input name="section_key" required maxlength="80" placeholder="brands"></label>
                <label>{{ app()->getLocale()==='ar'?'النوع':'Type' }}<select name="section_type">@foreach($webSectionTypes as $value=>$label)<option value="{{ $value }}">{{ $label }}</option>@endforeach</select></label>
                <label>{{ app()->getLocale()==='ar'?'العنوان العربي':'Arabic title' }}<input name="title_ar" maxlength="255"></label>
                <label>{{ app()->getLocale()==='ar'?'العنوان الإنجليزي':'English title' }}<input name="title_en" maxlength="255"></label>
                <label>{{ app()->getLocale()==='ar'?'الترتيب':'Sort order' }}<input type="number" name="sort_order" value="50" min="0" max="9999" required></label>
                <label style="align-content:end"><span><input type="checkbox" name="is_active" value="1" checked> {{ app()->getLocale()==='ar'?'نشط':'Active' }}</span></label>
            </div>
            <div class="wweb-actions"><button class="foodex-primary" type="submit">{{ app()->getLocale()==='ar'?'إضافة لمسودة الويب':'Add to Web Draft' }}</button></div>
        </form>
        @endif

        <div class="wweb-list">
        @forelse($webSections as $section)
            <div class="wweb-item">
                <div class="wweb-item-head"><strong>{{ $webSectionTypes[$section['section_type']] ?? $section['section_type'] }}</strong><span class="wweb-code">{{ $section['section_key'] }}</span></div>
                @if($canManageWeb)
                <form method="post" action="{{ route('admin.b2b.web-storefront.sections.update',['section'=>$section['id']]) }}" style="margin-top:10px">
                    @csrf @method('PATCH')
                    <input type="hidden" name="store_id" value="{{ $webStoreId }}">
                    <div class="wweb-form">
                        <label>{{ app()->getLocale()==='ar'?'المفتاح':'Key' }}<input name="section_key" value="{{ $section['section_key'] }}" required></label>
                        <label>{{ app()->getLocale()==='ar'?'النوع':'Type' }}<select name="section_type">@foreach($webSectionTypes as $value=>$label)<option value="{{ $value }}" @selected($section['section_type']===$value)>{{ $label }}</option>@endforeach</select></label>
                        <label>{{ app()->getLocale()==='ar'?'العنوان العربي':'Arabic title' }}<input name="title_ar" value="{{ $section['title_ar'] }}"></label>
                        <label>{{ app()->getLocale()==='ar'?'العنوان الإنجليزي':'English title' }}<input name="title_en" value="{{ $section['title_en'] }}"></label>
                        <label>{{ app()->getLocale()==='ar'?'الترتيب':'Sort order' }}<input type="number" name="sort_order" value="{{ $section['sort_order'] }}" min="0" max="9999" required></label>
                        <label style="align-content:end"><span><input type="checkbox" name="is_active" value="1" @checked($section['is_active'])> {{ app()->getLocale()==='ar'?'نشط':'Active' }}</span></label>
                    </div>
                    <div class="wweb-actions"><button class="foodex-primary" type="submit">{{ app()->getLocale()==='ar'?'حفظ في مسودة الويب':'Save to Web Draft' }}</button></div>
                </form>
                <form method="post" action="{{ route('admin.b2b.web-storefront.sections.destroy',['section'=>$section['id']]) }}">@csrf @method('DELETE')<input type="hidden" name="store_id" value="{{ $webStoreId }}"><button class="wweb-danger" type="submit">{{ app()->getLocale()==='ar'?'حذف':'Delete' }}</button></form>
                @endif
            </div>
        @empty
            <p class="wweb-muted">{{ app()->getLocale()==='ar'?'لا توجد أقسام ويب مهيأة.':'No Public Web sections configured.' }}</p>
        @endforelse
        </div>
    </section>

    <section class="wweb-card">
        <h3>{{ app()->getLocale()==='ar'?'بانرات الموقع العام':'Public Web banners' }}</h3>
        <p class="wweb-muted">{{ app()->getLocale()==='ar'?'بانرات الويب مستقلة تماماً عن بانرات تطبيق العميل والمنصة.':'Web banners are fully isolated from Customer App and Platform banners.' }}</p>
        @if($canManageWeb)
        <form method="post" action="{{ route('admin.b2b.web-storefront.banners.store') }}" enctype="multipart/form-data" class="wweb-item" style="margin-bottom:12px">
            @csrf
            <input type="hidden" name="store_id" value="{{ $webStoreId }}">
            <div class="wweb-form">
                <label>{{ app()->getLocale()==='ar'?'العنوان':'Title' }}<input name="title" required maxlength="255"></label>
                <label>{{ app()->getLocale()==='ar'?'الصورة':'Image' }}<input type="file" name="banner_image" accept="image/jpeg,image/png,image/webp" required></label>
                <label>{{ app()->getLocale()==='ar'?'الهدف':'Target' }}<select name="target_ref"><option value="">{{ app()->getLocale()==='ar'?'بدون هدف':'No target' }}</option>@foreach($webTargets as $target)<option value="{{ $target['ref'] }}">{{ $target['label'] }}</option>@endforeach</select></label>
                <label>{{ app()->getLocale()==='ar'?'الترتيب':'Sort order' }}<input type="number" name="sort_order" value="0" min="0" required></label>
                <label>{{ app()->getLocale()==='ar'?'يبدأ في':'Starts at' }}<input type="datetime-local" name="starts_at"></label>
                <label>{{ app()->getLocale()==='ar'?'ينتهي في':'Ends at' }}<input type="datetime-local" name="ends_at"></label>
                <label><span><input type="checkbox" name="is_active" value="1" checked> {{ app()->getLocale()==='ar'?'نشط':'Active' }}</span></label>
            </div>
            <div class="wweb-actions"><button class="foodex-primary" type="submit">{{ app()->getLocale()==='ar'?'إضافة لمسودة الويب':'Add to Web Draft' }}</button></div>
        </form>
        @endif

        <div class="wweb-list">
        @forelse($webBanners as $banner)
            <div class="wweb-item">
                <div class="wweb-item-head"><div style="display:flex;align-items:center;gap:9px">@if(!empty($banner['image']))<img class="wweb-thumb" src="{{ asset(ltrim($banner['image'],'/')) }}" alt="{{ $banner['title'] }}">@endif<strong>{{ $banner['title'] }}</strong></div><span class="wweb-code">#{{ $banner['sort_order'] }}</span></div>
                @if($canManageWeb)
                <form method="post" action="{{ route('admin.b2b.web-storefront.banners.update',['banner'=>$banner['id']]) }}" enctype="multipart/form-data" style="margin-top:10px">
                    @csrf @method('PATCH')
                    <input type="hidden" name="store_id" value="{{ $webStoreId }}">
                    <div class="wweb-form">
                        <label>{{ app()->getLocale()==='ar'?'العنوان':'Title' }}<input name="title" value="{{ $banner['title'] }}" required></label>
                        <label>{{ app()->getLocale()==='ar'?'استبدال الصورة':'Replace image' }}<input type="file" name="banner_image" accept="image/jpeg,image/png,image/webp"></label>
                        <label>{{ app()->getLocale()==='ar'?'الهدف':'Target' }}<select name="target_ref"><option value="">{{ app()->getLocale()==='ar'?'بدون هدف':'No target' }}</option>@foreach($webTargets as $target)<option value="{{ $target['ref'] }}" @selected($banner['target_ref']===$target['ref'])>{{ $target['label'] }}</option>@endforeach</select></label>
                        <label>{{ app()->getLocale()==='ar'?'الترتيب':'Sort order' }}<input type="number" name="sort_order" value="{{ $banner['sort_order'] }}" min="0" required></label>
                        <label>{{ app()->getLocale()==='ar'?'يبدأ في':'Starts at' }}<input type="datetime-local" name="starts_at" value="{{ empty($banner['starts_at']) ? '' : str_replace(' ', 'T', substr((string)$banner['starts_at'],0,16)) }}"></label>
                        <label>{{ app()->getLocale()==='ar'?'ينتهي في':'Ends at' }}<input type="datetime-local" name="ends_at" value="{{ empty($banner['ends_at']) ? '' : str_replace(' ', 'T', substr((string)$banner['ends_at'],0,16)) }}"></label>
                        <label><span><input type="checkbox" name="is_active" value="1" @checked($banner['is_active'])> {{ app()->getLocale()==='ar'?'نشط':'Active' }}</span></label>
                    </div>
                    <div class="wweb-actions"><button class="foodex-primary" type="submit">{{ app()->getLocale()==='ar'?'حفظ في مسودة الويب':'Save to Web Draft' }}</button></div>
                </form>
                <form method="post" action="{{ route('admin.b2b.web-storefront.banners.destroy',['banner'=>$banner['id']]) }}">@csrf @method('DELETE')<input type="hidden" name="store_id" value="{{ $webStoreId }}"><button class="wweb-danger" type="submit">{{ app()->getLocale()==='ar'?'حذف':'Delete' }}</button></form>
                @endif
            </div>
        @empty
            <p class="wweb-muted">{{ app()->getLocale()==='ar'?'لا توجد بانرات ويب حالياً.':'No Public Web banners yet.' }}</p>
        @endforelse
        </div>
    </section>
</div>
