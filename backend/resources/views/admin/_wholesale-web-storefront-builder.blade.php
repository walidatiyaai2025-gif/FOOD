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
    $webPublishedEnabled = (bool)($moduleData['published_web_enabled'] ?? false);
@endphp

<style>
.wweb-grid{display:grid;grid-template-columns:minmax(0,1.15fr) minmax(320px,.85fr);gap:16px;margin-bottom:16px}.wweb-card{background:var(--foodex-surface);border:1px solid var(--foodex-border);border-radius:18px;padding:16px}.wweb-card h3{margin:0 0 6px}.wweb-muted{color:var(--foodex-muted);font-size:.9rem}.wweb-form{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}.wweb-form .wide{grid-column:1/-1}.wweb-card label{display:grid;gap:5px;font-size:.82rem;font-weight:700}.wweb-card input,.wweb-card select,.wweb-card textarea{width:100%;box-sizing:border-box;border:1px solid var(--foodex-border);border-radius:10px;padding:9px 10px;background:var(--foodex-surface)}.wweb-card input[type=checkbox]{width:auto}.wweb-actions{display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-top:10px}.wweb-item{border:1px solid var(--foodex-border);border-radius:14px;padding:12px}.wweb-list{display:grid;gap:10px}.wweb-item-head{display:flex;justify-content:space-between;gap:10px;align-items:center}.wweb-code{font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:.76rem;color:var(--foodex-muted)}.wweb-danger{border:1px solid var(--foodex-red);background:var(--foodex-surface);color:var(--foodex-red);border-radius:9px;padding:7px 10px;cursor:pointer}.wweb-thumb{width:96px;height:58px;border-radius:10px;object-fit:cover;border:1px solid var(--foodex-border)}.wweb-status{display:inline-flex;align-items:center;border-radius:999px;padding:5px 9px;font-size:.72rem;font-weight:800;background:var(--foodex-green-soft);color:var(--foodex-green-dark)}@media(max-width:980px){.wweb-grid{grid-template-columns:1fr}.wweb-form{grid-template-columns:1fr}.wweb-form .wide{grid-column:auto}}
</style>

<div class="wweb-grid">
    <section class="wweb-card">
        <h3>{{ __('wholesale_public.admin.web_editor.title') }}</h3>
        <p class="wweb-muted">{{ __('wholesale_public.admin.web_editor.help') }}</p>
        <div class="wweb-actions">
            <a class="foodex-primary" href="{{ route('admin.b2b.module',['module'=>'storefront']) }}">{{ __('wholesale_public.admin.web_editor.open_customer_app') }}</a>
            <a class="foodex-primary" href="{{ route('admin.b2b.web-storefront.preview') }}" target="_blank" rel="noopener">{{ __('wholesale_public.admin.web_editor.preview_draft') }}</a>
            @if($webPublishedEnabled)<a class="foodex-primary" href="{{ route('public.wholesale.home') }}" target="_blank" rel="noopener">{{ __('wholesale_public.admin.web_editor.open_published') }}</a>@endif
        </div>

        @if($canManageWeb)
        <form method="post" action="{{ route('admin.b2b.web-storefront.settings') }}" style="margin-top:14px">
            @csrf @method('PUT')
            <input type="hidden" name="store_id" value="{{ $webStoreId }}">
            <div class="wweb-item" style="margin-bottom:12px">
                <div class="wweb-item-head">
                    <div><strong>{{ __('wholesale_public.admin.web_editor.availability_title') }}</strong><div class="wweb-muted">{{ __('wholesale_public.admin.web_editor.availability_help') }}</div></div>
                    <div style="display:flex;gap:6px;flex-wrap:wrap;justify-content:flex-end">
                        <span class="wweb-status">{{ __('wholesale_public.admin.web_editor.draft_label') }} {{ $webEnabled ? __('wholesale_public.admin.web_editor.on') : __('wholesale_public.admin.web_editor.off') }}</span>
                        <span class="wweb-status" style="background:var(--foodex-background);color:var(--foodex-ink)">{{ __('wholesale_public.admin.web_editor.published_label') }} {{ $webPublishedEnabled ? __('wholesale_public.admin.web_editor.on') : __('wholesale_public.admin.web_editor.off') }}</span>
                    </div>
                </div>
                <input type="hidden" name="public_web_enabled" value="0">
                <label style="margin-top:10px"><span><input type="checkbox" name="public_web_enabled" value="1" @checked($webEnabled)> {{ __('wholesale_public.admin.web_editor.enable') }}</span></label>
            </div>

            <div class="wweb-form">
                <label>{{ __('wholesale_public.admin.web_editor.hero_title_ar') }}<input name="hero_title_ar" value="{{ $webSettings['web_hero_title_ar'] ?? '' }}" maxlength="255"></label>
                <label>{{ __('wholesale_public.admin.web_editor.hero_title_en') }}<input name="hero_title_en" value="{{ $webSettings['web_hero_title_en'] ?? '' }}" maxlength="255"></label>
                <label>{{ __('wholesale_public.admin.web_editor.hero_subtitle_ar') }}<textarea name="hero_subtitle_ar" rows="2" maxlength="500">{{ $webSettings['web_hero_subtitle_ar'] ?? '' }}</textarea></label>
                <label>{{ __('wholesale_public.admin.web_editor.hero_subtitle_en') }}<textarea name="hero_subtitle_en" rows="2" maxlength="500">{{ $webSettings['web_hero_subtitle_en'] ?? '' }}</textarea></label>
                <label>{{ __('wholesale_public.admin.web_editor.hero_cta_ar') }}<input name="hero_cta_ar" value="{{ $webSettings['web_hero_cta_ar'] ?? '' }}" maxlength="120"></label>
                <label>{{ __('wholesale_public.admin.web_editor.hero_cta_en') }}<input name="hero_cta_en" value="{{ $webSettings['web_hero_cta_en'] ?? '' }}" maxlength="120"></label>

                <label class="wide">{{ __('wholesale_public.admin.web_editor.facebook_url') }}<input type="url" name="facebook_url" value="{{ $webSettings['facebook_url'] ?? '' }}" maxlength="500" placeholder="https://www.facebook.com/..."></label>
                <label>{{ __('wholesale_public.admin.web_editor.facebook_title_ar') }}<input name="facebook_title_ar" value="{{ $webSettings['facebook_title_ar'] ?? '' }}" maxlength="255"></label>
                <label>{{ __('wholesale_public.admin.web_editor.facebook_title_en') }}<input name="facebook_title_en" value="{{ $webSettings['facebook_title_en'] ?? '' }}" maxlength="255"></label>
                <label>{{ __('wholesale_public.admin.web_editor.facebook_text_ar') }}<textarea name="facebook_text_ar" rows="2" maxlength="500">{{ $webSettings['facebook_text_ar'] ?? '' }}</textarea></label>
                <label>{{ __('wholesale_public.admin.web_editor.facebook_text_en') }}<textarea name="facebook_text_en" rows="2" maxlength="500">{{ $webSettings['facebook_text_en'] ?? '' }}</textarea></label>

                <label>{{ __('wholesale_public.admin.web_editor.meta_title_ar') }}<input name="meta_title_ar" value="{{ $webSettings['web_meta_title_ar'] ?? '' }}" maxlength="255"></label>
                <label>{{ __('wholesale_public.admin.web_editor.meta_title_en') }}<input name="meta_title_en" value="{{ $webSettings['web_meta_title_en'] ?? '' }}" maxlength="255"></label>
                <label>{{ __('wholesale_public.admin.web_editor.meta_description_ar') }}<textarea name="meta_description_ar" rows="2" maxlength="500">{{ $webSettings['web_meta_description_ar'] ?? '' }}</textarea></label>
                <label>{{ __('wholesale_public.admin.web_editor.meta_description_en') }}<textarea name="meta_description_en" rows="2" maxlength="500">{{ $webSettings['web_meta_description_en'] ?? '' }}</textarea></label>
            </div>
            <div class="wweb-actions"><button class="foodex-primary" type="submit">{{ __('wholesale_public.admin.web_editor.save_draft') }}</button></div>
        </form>
        @endif
    </section>

    <aside class="wweb-card">
        <h3>{{ __('wholesale_public.admin.web_editor.draft_publish_title') }}</h3>
        <p class="wweb-muted">{{ __('wholesale_public.admin.web_editor.draft_publish_help') }}</p>
        <div class="wweb-item">
            <div class="wweb-item-head"><strong>{{ $webHasDraft ? __('wholesale_public.admin.web_editor.active_draft') : __('wholesale_public.admin.web_editor.published_web') }}</strong><span class="wweb-code">rev {{ $webRevision['revision_id'] ?? '—' }}</span></div>
            <div class="wweb-code" style="margin-top:8px">{{ substr((string)($webRevision['checksum'] ?? ''),0,16) }}</div>
        </div>
        @if($webHasDraft && $canPublishWeb)
        <div class="wweb-actions">
            <form method="post" action="{{ route('admin.b2b.web-storefront.publish') }}">@csrf<input type="hidden" name="store_id" value="{{ $webStoreId }}"><button class="foodex-primary" type="submit">{{ __('wholesale_public.admin.web_editor.publish') }}</button></form>
            <form method="post" action="{{ route('admin.b2b.web-storefront.discard') }}" onsubmit="return confirm(@json(__('wholesale_public.admin.web_editor.discard_confirm')))">@csrf<input type="hidden" name="store_id" value="{{ $webStoreId }}"><button class="wweb-danger" type="submit">{{ __('wholesale_public.admin.web_editor.discard') }}</button></form>
        </div>
        @endif
    </aside>
</div>

<div class="wweb-grid">
    <section class="wweb-card">
        <h3>{{ __('wholesale_public.admin.web_editor.sections_title') }}</h3>
        @if($canManageWeb)
        <form method="post" action="{{ route('admin.b2b.web-storefront.sections.store') }}" class="wweb-item" style="margin-bottom:12px">
            @csrf
            <input type="hidden" name="store_id" value="{{ $webStoreId }}">
            <div class="wweb-form">
                <label>{{ __('wholesale_public.admin.web_editor.section_key') }}<input name="section_key" required maxlength="80" placeholder="{{ __('wholesale_public.admin.web_editor.section_key_placeholder') }}"></label>
                <label>{{ __('wholesale_public.admin.web_editor.type') }}<select name="section_type">@foreach($webSectionTypes as $value=>$label)<option value="{{ $value }}">{{ $label }}</option>@endforeach</select></label>
                <label>{{ __('wholesale_public.admin.web_editor.title_ar') }}<input name="title_ar" maxlength="255" dir="rtl"></label> {{-- localization-gate: allow explicit bilingual authoring field --}}
                <label>{{ __('wholesale_public.admin.web_editor.title_en') }}<input name="title_en" maxlength="255" dir="ltr"></label> {{-- localization-gate: allow explicit bilingual authoring field --}}
                <label>{{ __('wholesale_public.admin.web_editor.sort_order') }}<input type="number" name="sort_order" value="50" min="0" max="9999" required></label>
                <label style="align-content:end"><span><input type="checkbox" name="is_active" value="1" checked> {{ __('wholesale_public.admin.web_editor.active') }}</span></label>
                <label class="wide">{{ __('wholesale_public.admin.web_editor.config_json') }}<textarea name="config_json" rows="2" placeholder="{{ __('wholesale_public.admin.web_editor.config_json_placeholder') }}"></textarea></label>
            </div>
            <div class="wweb-actions"><button class="foodex-primary" type="submit">{{ __('wholesale_public.admin.web_editor.add_section') }}</button></div>
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
                        <label>{{ __('wholesale_public.admin.web_editor.key') }}<input name="section_key" value="{{ $section['section_key'] }}" required></label>
                        <label>{{ __('wholesale_public.admin.web_editor.type') }}<select name="section_type">@foreach($webSectionTypes as $value=>$label)<option value="{{ $value }}" @selected($section['section_type']===$value)>{{ $label }}</option>@endforeach</select></label>
                        <label>{{ __('wholesale_public.admin.web_editor.title_ar') }}<input name="title_ar" value="{{ $section['title_ar'] }}" dir="rtl"></label> {{-- localization-gate: allow explicit bilingual authoring field --}}
                        <label>{{ __('wholesale_public.admin.web_editor.title_en') }}<input name="title_en" value="{{ $section['title_en'] }}" dir="ltr"></label> {{-- localization-gate: allow explicit bilingual authoring field --}}
                        <label>{{ __('wholesale_public.admin.web_editor.sort_order') }}<input type="number" name="sort_order" value="{{ $section['sort_order'] }}" min="0" max="9999" required></label>
                        <label style="align-content:end"><span><input type="checkbox" name="is_active" value="1" @checked($section['is_active'])> {{ __('wholesale_public.admin.web_editor.active') }}</span></label>
                        <label class="wide">{{ __('wholesale_public.admin.web_editor.config_json') }}<textarea name="config_json" rows="2">{{ $section['config_json'] }}</textarea></label>
                    </div>
                    <div class="wweb-actions"><button class="foodex-primary" type="submit">{{ __('wholesale_public.admin.web_editor.save_section') }}</button></div>
                </form>
                <form method="post" action="{{ route('admin.b2b.web-storefront.sections.destroy',['section'=>$section['id']]) }}">@csrf @method('DELETE')<input type="hidden" name="store_id" value="{{ $webStoreId }}"><button class="wweb-danger" type="submit">{{ __('wholesale_public.admin.web_editor.delete') }}</button></form>
                @endif
            </div>
        @empty
            <p class="wweb-muted">{{ __('wholesale_public.admin.web_editor.empty_sections') }}</p>
        @endforelse
        </div>
    </section>

    <section class="wweb-card">
        <h3>{{ __('wholesale_public.admin.web_editor.banners_title') }}</h3>
        <p class="wweb-muted">{{ __('wholesale_public.admin.web_editor.banners_help') }}</p>
        @if($canManageWeb)
        <form method="post" action="{{ route('admin.b2b.web-storefront.banners.store') }}" enctype="multipart/form-data" class="wweb-item" style="margin-bottom:12px">
            @csrf
            <input type="hidden" name="store_id" value="{{ $webStoreId }}">
            <div class="wweb-form">
                <label>{{ __('wholesale_public.admin.web_editor.title_field') }}<input name="title" required maxlength="255"></label>
                <label>{{ __('wholesale_public.admin.web_editor.image') }}<input type="file" name="banner_image" accept="image/jpeg,image/png,image/webp" required></label>
                <label>{{ __('wholesale_public.admin.web_editor.target') }}<select name="target_ref"><option value="">{{ __('wholesale_public.admin.web_editor.no_target') }}</option>@foreach($webTargets as $target)<option value="{{ $target['ref'] }}">{{ $target['label'] }}</option>@endforeach</select></label>
                <label>{{ __('wholesale_public.admin.web_editor.sort_order') }}<input type="number" name="sort_order" value="0" min="0" required></label>
                <label>{{ __('wholesale_public.admin.web_editor.starts_at') }}<input type="datetime-local" name="starts_at"></label>
                <label>{{ __('wholesale_public.admin.web_editor.ends_at') }}<input type="datetime-local" name="ends_at"></label>
                <label><span><input type="checkbox" name="is_active" value="1" checked> {{ __('wholesale_public.admin.web_editor.active') }}</span></label>
            </div>
            <div class="wweb-actions"><button class="foodex-primary" type="submit">{{ __('wholesale_public.admin.web_editor.add_banner') }}</button></div>
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
                        <label>{{ __('wholesale_public.admin.web_editor.title_field') }}<input name="title" value="{{ $banner['title'] }}" required></label>
                        <label>{{ __('wholesale_public.admin.web_editor.replace_image') }}<input type="file" name="banner_image" accept="image/jpeg,image/png,image/webp"></label>
                        <label>{{ __('wholesale_public.admin.web_editor.target') }}<select name="target_ref"><option value="">{{ __('wholesale_public.admin.web_editor.no_target') }}</option>@foreach($webTargets as $target)<option value="{{ $target['ref'] }}" @selected($banner['target_ref']===$target['ref'])>{{ $target['label'] }}</option>@endforeach</select></label>
                        <label>{{ __('wholesale_public.admin.web_editor.sort_order') }}<input type="number" name="sort_order" value="{{ $banner['sort_order'] }}" min="0" required></label>
                        <label>{{ __('wholesale_public.admin.web_editor.starts_at') }}<input type="datetime-local" name="starts_at" value="{{ empty($banner['starts_at']) ? '' : str_replace(' ', 'T', substr((string)$banner['starts_at'],0,16)) }}"></label>
                        <label>{{ __('wholesale_public.admin.web_editor.ends_at') }}<input type="datetime-local" name="ends_at" value="{{ empty($banner['ends_at']) ? '' : str_replace(' ', 'T', substr((string)$banner['ends_at'],0,16)) }}"></label>
                        <label><span><input type="checkbox" name="is_active" value="1" @checked($banner['is_active'])> {{ __('wholesale_public.admin.web_editor.active') }}</span></label>
                    </div>
                    <div class="wweb-actions"><button class="foodex-primary" type="submit">{{ __('wholesale_public.admin.web_editor.save_banner') }}</button></div>
                </form>
                <form method="post" action="{{ route('admin.b2b.web-storefront.banners.destroy',['banner'=>$banner['id']]) }}">@csrf @method('DELETE')<input type="hidden" name="store_id" value="{{ $webStoreId }}"><button class="wweb-danger" type="submit">{{ __('wholesale_public.admin.web_editor.delete') }}</button></form>
                @endif
            </div>
        @empty
            <p class="wweb-muted">{{ __('wholesale_public.admin.web_editor.empty_banners') }}</p>
        @endforelse
        </div>
    </section>
</div>
