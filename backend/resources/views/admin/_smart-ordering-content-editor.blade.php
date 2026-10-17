@php
    $contentRevision = $draft ?? $published;
    $contentPayload = $contentRevision ? (array) $contentRevision->payload : [];
    $contentModel = is_array($contentPayload['content'] ?? null) ? $contentPayload['content'] : [];
    $contentIdentity = is_array($contentModel['identity'] ?? null) ? $contentModel['identity'] : [];
    $contentSections = is_array($contentModel['sections'] ?? null) ? $contentModel['sections'] : [];
    $legacySiteContent = is_array($contentPayload['site'] ?? null) ? $contentPayload['site'] : [];
    $contentNameEn = old('name_en', $contentIdentity['name_en'] ?? $legacySiteContent['name_en'] ?? optional($site->store)->name ?? '');
    $contentNameAr = old('name_ar', $contentIdentity['name_ar'] ?? $legacySiteContent['name_ar'] ?? '');
@endphp

<section class="panel foodex-card" data-restaurant-content-editor>
    <div class="foodex-page-toolbar">
        <div>
            <h2>{{ __('smart_ordering.content_editor.title') }}</h2>
            <p>{{ __('smart_ordering.content_editor.description') }}</p>
        </div>
        <div class="foodex-header-actions">
            <a class="foodex-action-secondary" href="{{ route('admin.smart-ordering.menu.index', array_filter(['store_id'=>$site->store_id,'support_access'=>$supportAccess?1:null])) }}">
                {{ __('smart_ordering.content_editor.menu_link') }}
            </a>
        </div>
    </div>
    <p>{{ __('smart_ordering.content_editor.menu_hint') }}</p>

    @if(!$draft)
        <div class="foodex-empty-state" role="status">
            <p>{{ __('smart_ordering.content_editor.draft_required') }}</p>
            <form method="post" action="{{ route('admin.smart-ordering.draft', $site) }}">
                @csrf
                @if($supportAccess)<input type="hidden" name="support_access" value="1">@endif
                <button class="foodex-action-primary" type="submit">{{ __('smart_ordering.create_draft') }}</button>
            </form>
        </div>
    @else
        <form class="foodex-form" method="post" enctype="multipart/form-data" action="{{ route('admin.smart-ordering.revisions.content', $draft) }}">
            @csrf @method('PUT')
            @if($supportAccess)<input type="hidden" name="support_access" value="1">@endif

            <fieldset>
                <legend>{{ __('smart_ordering.content_editor.identity') }}</legend>
                <div class="so-grid">
                    <label>{{ __('smart_ordering.content_editor.name_en') }} {{-- localization-gate: allow bilingual authoring intentionally exposes the explicit locale field --}}
                        <input class="foodex-control" dir="ltr" name="name_en" maxlength="160" required value="{{ $contentNameEn }}"> {{-- localization-gate: allow bilingual authoring intentionally exposes the explicit locale field --}}
                    </label>
                    <label>{{ __('smart_ordering.content_editor.name_ar') }} {{-- localization-gate: allow bilingual authoring intentionally exposes the explicit locale field --}}
                        <input class="foodex-control" dir="rtl" name="name_ar" maxlength="160" value="{{ $contentNameAr }}"> {{-- localization-gate: allow bilingual authoring intentionally exposes the explicit locale field --}}
                    </label>
                    <label>{{ __('smart_ordering.content_editor.tagline_en') }}
                        <input class="foodex-control" dir="ltr" name="tagline_en" maxlength="240" value="{{ old('tagline_en',$contentIdentity['tagline_en'] ?? '') }}">
                    </label>
                    <label>{{ __('smart_ordering.content_editor.tagline_ar') }}
                        <input class="foodex-control" dir="rtl" name="tagline_ar" maxlength="240" value="{{ old('tagline_ar',$contentIdentity['tagline_ar'] ?? '') }}">
                    </label>
                    <label>{{ __('smart_ordering.content_editor.description_en') }} {{-- localization-gate: allow bilingual authoring intentionally exposes the explicit locale field --}}
                        <textarea class="foodex-control" dir="ltr" name="description_en" maxlength="1000" rows="3">{{ old('description_en',$contentIdentity['description_en'] ?? '') }}</textarea> {{-- localization-gate: allow bilingual authoring intentionally exposes the explicit locale field --}}
                    </label>
                    <label>{{ __('smart_ordering.content_editor.description_ar') }} {{-- localization-gate: allow bilingual authoring intentionally exposes the explicit locale field --}}
                        <textarea class="foodex-control" dir="rtl" name="description_ar" maxlength="1000" rows="3">{{ old('description_ar',$contentIdentity['description_ar'] ?? '') }}</textarea> {{-- localization-gate: allow bilingual authoring intentionally exposes the explicit locale field --}}
                    </label>
                    <label>{{ __('smart_ordering.content_editor.phone') }}
                        <input class="foodex-control" dir="ltr" name="phone" maxlength="40" value="{{ old('phone',$contentIdentity['phone'] ?? '') }}">
                    </label>
                    <label>{{ __('smart_ordering.content_editor.whatsapp_url') }}
                        <input class="foodex-control" dir="ltr" type="url" name="whatsapp_url" maxlength="500" value="{{ old('whatsapp_url',$contentIdentity['whatsapp_url'] ?? '') }}">
                    </label>
                    <label>{{ __('smart_ordering.content_editor.logo') }}
                        <input class="foodex-control" type="file" name="logo" accept="image/jpeg,image/png,image/webp">
                    </label>
                    <label>{{ __('smart_ordering.content_editor.cover') }}
                        <input class="foodex-control" type="file" name="cover" accept="image/jpeg,image/png,image/webp">
                    </label>
                </div>
            </fieldset>

            <fieldset>
                <legend>{{ __('smart_ordering.content_editor.story') }}</legend>
                <div class="so-grid">
                    @foreach(['about','chef_note','location','reservation_cta','allergen_note'] as $field)
                        <label>{{ __('smart_ordering.content_editor.'.$field.'_en') }}
                            <textarea class="foodex-control" dir="ltr" name="{{ $field }}_en" rows="3">{{ old($field.'_en',$contentSections[$field.'_en'] ?? '') }}</textarea>
                        </label>
                        <label>{{ __('smart_ordering.content_editor.'.$field.'_ar') }}
                            <textarea class="foodex-control" dir="rtl" name="{{ $field }}_ar" rows="3">{{ old($field.'_ar',$contentSections[$field.'_ar'] ?? '') }}</textarea>
                        </label>
                    @endforeach
                    <label>{{ __('smart_ordering.content_editor.map_url') }}
                        <input class="foodex-control" dir="ltr" type="url" name="map_url" maxlength="500" value="{{ old('map_url',$contentSections['map_url'] ?? '') }}">
                    </label>
                    <label>{{ __('smart_ordering.content_editor.reservation_url') }}
                        <input class="foodex-control" dir="ltr" type="url" name="reservation_url" maxlength="500" value="{{ old('reservation_url',$contentSections['reservation_url'] ?? '') }}">
                    </label>
                </div>
            </fieldset>

            <button class="foodex-action-primary" type="submit">{{ __('smart_ordering.content_editor.save') }}</button>
        </form>
    @endif
</section>
