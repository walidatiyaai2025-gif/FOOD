@php
    $contentRevision = $draft ?? $published;
    $contentPayload = $contentRevision ? (array) $contentRevision->payload : [];
    $contentModel = is_array($contentPayload['content'] ?? null) ? $contentPayload['content'] : [];
    $contentIdentity = is_array($contentModel['identity'] ?? null) ? $contentModel['identity'] : [];
    $contentSections = is_array($contentModel['sections'] ?? null) ? $contentModel['sections'] : [];
    $legacySiteContent = is_array($contentPayload['site'] ?? null) ? $contentPayload['site'] : [];
    $contentNameEn = old('name_en', $contentIdentity['name_en'] ?? $legacySiteContent['name_en'] ?? optional($site->store)->name ?? '');
    $contentNameAr = old('name_ar', $contentIdentity['name_ar'] ?? $legacySiteContent['name_ar'] ?? '');
    $contentCompact = (bool) ($restaurantContentCompact ?? false);
@endphp

<section class="panel foodex-card {{ $contentCompact ? 'is-compact' : '' }}" data-restaurant-content-editor>
    @if($contentCompact)
    <style>
        [data-restaurant-content-editor].is-compact{padding:12px!important}
        .so-content-tabs{display:flex;align-items:center;gap:5px;margin:4px 0 8px;overflow-x:auto;scrollbar-width:thin}
        .so-content-tab{min-height:34px;padding:0 12px;border:1px solid var(--foodex-border);border-radius:999px;background:var(--foodex-surface);color:var(--foodex-muted);font-size:.69rem;font-weight:900;cursor:pointer;white-space:nowrap}
        .so-content-tab[aria-selected="true"]{background:var(--foodex-green-soft);border-color:var(--foodex-palette-b9d8c1);color:var(--foodex-green-dark)}
        [data-so-content-panel][hidden]{display:none!important}
        [data-restaurant-content-editor].is-compact .so-grid{gap:8px}
        [data-restaurant-content-editor].is-compact textarea{min-height:78px}
    </style>
    @endif
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

            @if($contentCompact)
                <nav class="so-content-tabs" role="tablist" aria-label="{{ app()->getLocale()==='ar' ? 'أقسام المحتوى' : 'Content sections' }}" data-so-content-tabs>
                    <button class="so-content-tab" type="button" role="tab" aria-selected="true" data-so-content-tab="identity">{{ __('smart_ordering.content_editor.identity') }}</button>
                    <button class="so-content-tab" type="button" role="tab" aria-selected="false" data-so-content-tab="story">{{ __('smart_ordering.content_editor.story') }}</button>
                </nav>
            @endif

            <fieldset data-so-content-panel="identity">
                <legend>{{ __('smart_ordering.content_editor.identity') }}</legend>
                <div class="so-grid">
                    <label>{{ __('smart_ordering.content_editor.name_en') }} {{-- localization-gate: allow bilingual authoring intentionally exposes the explicit locale field --}}
                        <input class="foodex-control" dir="ltr" name="name_en" maxlength="160" required value="{{ $contentNameEn }}"> {{-- localization-gate: allow bilingual authoring intentionally exposes the explicit locale field --}}
                    </label>
                    <label>{{ __('smart_ordering.content_editor.name_ar') }} {{-- localization-gate: allow bilingual authoring intentionally exposes the explicit locale field --}}
                        <input class="foodex-control" dir="rtl" name="name_ar" maxlength="160" value="{{ $contentNameAr }}"> {{-- localization-gate: allow bilingual authoring intentionally exposes the explicit locale field --}}
                    </label>
                    <label>{{ __('smart_ordering.content_editor.tagline_en') }}
                        <input class="foodex-control" dir="ltr" name="tagline_en" maxlength="240" value="{{ old('tagline_en',$contentIdentity['tagline_en'] ?? '') }}"> {{-- localization-gate: allow — bilingual content editor intentionally exposes the explicit TAGLINE_EN field. --}}
                    </label>
                    <label>{{ __('smart_ordering.content_editor.tagline_ar') }}
                        <input class="foodex-control" dir="rtl" name="tagline_ar" maxlength="240" value="{{ old('tagline_ar',$contentIdentity['tagline_ar'] ?? '') }}"> {{-- localization-gate: allow — bilingual content editor intentionally exposes the explicit TAGLINE_AR field. --}}
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

            <fieldset data-so-content-panel="story" @if($contentCompact) hidden @endif>
                <legend>{{ __('smart_ordering.content_editor.story') }}</legend>
                <div class="so-grid">
                    @foreach(['about','chef_note','location','reservation_cta','allergen_note'] as $field)
                        <label>{{ __('smart_ordering.content_editor.'.$field.'_en') }}
                            <textarea class="foodex-control" dir="ltr" name="{{ $field }}_en" rows="3">{{ old($field.'_en',$contentSections[$field.'_en'] ?? '') }}</textarea> {{-- localization-gate: allow — bilingual content editor intentionally exposes the explicit EN field. --}}
                        </label>
                        <label>{{ __('smart_ordering.content_editor.'.$field.'_ar') }}
                            <textarea class="foodex-control" dir="rtl" name="{{ $field }}_ar" rows="3">{{ old($field.'_ar',$contentSections[$field.'_ar'] ?? '') }}</textarea> {{-- localization-gate: allow — bilingual content editor intentionally exposes the explicit AR field. --}}
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
        @if($contentCompact)
        <script>
        (() => {
            const editor = document.querySelector('[data-restaurant-content-editor]');
            const tabs = editor?.querySelector('[data-so-content-tabs]');
            if (!editor || !tabs) return;
            const buttons = [...tabs.querySelectorAll('[data-so-content-tab]')];
            const panels = [...editor.querySelectorAll('[data-so-content-panel]')];
            const activate = (name) => {
                buttons.forEach((button) => {
                    const active = button.dataset.soContentTab === name;
                    button.setAttribute('aria-selected', active ? 'true' : 'false');
                });
                panels.forEach((panel) => {
                    panel.hidden = panel.dataset.soContentPanel !== name;
                });
            };
            buttons.forEach((button) => button.addEventListener('click', () => activate(button.dataset.soContentTab)));
        })();
        </script>
        @endif
    @endif
</section>
