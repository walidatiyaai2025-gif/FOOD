<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>{{ __('smart_ordering.appearance.page_title') }} · FOODEX</title>
@include('admin._brand-components')
<style>
.so-builder-shell{display:grid;gap:var(--foodex-space-5);min-width:0}
.so-builder-grid{display:grid;grid-template-columns:minmax(280px,360px) minmax(0,1fr);gap:var(--foodex-space-5);align-items:start}
.so-builder-panel{padding:var(--foodex-space-5);display:grid;gap:var(--foodex-space-5);position:sticky;top:var(--foodex-space-4)}
.so-builder-group{display:grid;gap:var(--foodex-space-2)}
.so-builder-group>label,.so-builder-label{font-size:var(--foodex-text-xs);font-weight:var(--foodex-font-weight-bold);color:var(--foodex-muted)}
.so-builder-group input,.so-builder-group select{width:100%;min-height:var(--foodex-touch-target);border:1px solid var(--foodex-border);background:var(--foodex-surface);color:var(--foodex-ink);border-radius:var(--foodex-radius-control);padding:0 var(--foodex-space-3);font:inherit}
.so-template-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:var(--foodex-space-3)}
.so-template-option{position:relative;display:grid;gap:var(--foodex-space-2);width:100%;min-height:156px;border:1px solid var(--foodex-border);background:var(--foodex-surface);color:var(--foodex-ink);border-radius:var(--foodex-radius-md);padding:var(--foodex-space-3);text-align:start;cursor:pointer;overflow:hidden}
.so-template-option::before{content:"";display:block;height:58px;border-radius:calc(var(--foodex-radius-md) - 3px);background:linear-gradient(135deg,var(--foodex-green-soft),var(--foodex-surface) 52%,var(--foodex-background));border:1px solid var(--foodex-border)}
.so-template-option[data-dark="true"]::before{background:linear-gradient(135deg,#111827,#292524 55%,#7c5b2e);border-color:#44403c}
.so-template-option[aria-pressed=true]{border-color:var(--foodex-green);box-shadow:0 0 0 2px var(--foodex-green-soft)}
.so-template-option strong{display:block;font-size:var(--foodex-text-sm);line-height:var(--foodex-leading-tight)}
.so-template-option span{display:block;font-size:var(--foodex-text-xs);color:var(--foodex-muted);line-height:var(--foodex-leading-normal)}
.so-template-meta{display:flex;flex-wrap:wrap;gap:5px;align-items:center}
.so-template-chip{display:inline-flex!important;width:max-content;padding:3px 7px;border-radius:999px;background:var(--foodex-background);border:1px solid var(--foodex-border);font-size:10px!important;font-weight:700}
.so-template-current{background:var(--foodex-green-soft);color:var(--foodex-green-dark);border-color:var(--foodex-green)}
.so-demo-note{margin:0;padding:var(--foodex-space-3);border-radius:var(--foodex-radius-md);background:var(--foodex-green-soft);color:var(--foodex-green-dark);font-size:var(--foodex-text-xs);line-height:var(--foodex-leading-normal)}
.so-segmented{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:var(--foodex-space-2)}
.so-segmented.so-segmented-two{grid-template-columns:repeat(2,minmax(0,1fr))}
.so-segmented button{min-height:var(--foodex-touch-target);padding:0 var(--foodex-space-2);border:1px solid var(--foodex-border);background:var(--foodex-surface);color:var(--foodex-ink);border-radius:var(--foodex-radius-control);font:inherit;font-weight:var(--foodex-font-weight-medium);cursor:pointer}
.so-segmented button[aria-pressed=true]{background:var(--foodex-green);border-color:var(--foodex-green);color:var(--foodex-surface)}
.so-preview-stage{min-width:0;padding:var(--foodex-space-5);display:grid;place-items:center;overflow:auto}
.so-preview-device{width:100%;max-width:100%;padding:var(--foodex-space-3);background:var(--foodex-ink);border:1px solid var(--foodex-border);border-radius:var(--foodex-radius-lg);box-shadow:var(--foodex-shadow-raised);transition:width .2s ease}
.so-preview-device[data-device=mobile]{width:390px}
.so-preview-device[data-device=tablet]{width:820px}
.so-preview-device[data-device=desktop]{width:1280px}
.so-preview-device iframe{display:block;width:100%;height:78vh;border:0;border-radius:var(--foodex-radius-md);background:var(--foodex-surface)}
.so-preview-empty{min-height:420px;display:grid;place-items:center;padding:var(--foodex-space-6);border:1px dashed var(--foodex-border);border-radius:var(--foodex-radius-md);background:var(--foodex-surface);color:var(--foodex-muted);text-align:center}
.so-preview-hint{margin:0;color:var(--foodex-muted);font-size:var(--foodex-text-xs);line-height:var(--foodex-leading-normal)}
@media(max-width:1023px){.so-builder-grid{grid-template-columns:minmax(0,1fr)}.so-builder-panel{position:static}.so-preview-stage{padding:var(--foodex-space-3)}}
@media(max-width:767px){.so-builder-panel,.so-preview-stage{padding:var(--foodex-space-4)}.so-preview-device iframe{height:70vh}.so-segmented{grid-template-columns:minmax(0,1fr)}.so-segmented.so-segmented-two{grid-template-columns:repeat(2,minmax(0,1fr))}.so-template-grid{grid-template-columns:minmax(0,1fr)}}
</style>
</head>
<body>
<div class="foodex-admin-layout" data-builder>
<aside class="sidebar">@include('admin._sidebar', ['navGroups' => app(\App\Support\AdminNavigation::class)->groupsFor(auth()->user()), 'navContext' => 'smart_ordering', 'user' => auth()->user()])</aside> {{-- localization-gate: allow Blade include expression --}}
<main class="foodex-admin-main foodex-admin-page">
<div class="so-builder-shell">
<header class="foodex-page-header">
<div>
<span class="foodex-subtitle">{{ __('smart_ordering.appearance.eyebrow') }}</span>
<h1>{{ __('smart_ordering.appearance.heading') }}</h1>
<p>{{ __('smart_ordering.appearance.description') }}</p>
</div>
@include('admin._live-notifications')
</header>

@include('admin._smart-ordering-workspace-nav')

<div class="so-builder-grid">
<section class="foodex-card so-builder-panel" aria-label="{{ __('smart_ordering.appearance.controls_label') }}">
<div class="so-builder-group">
<label for="site">{{ __('smart_ordering.appearance.site_key_label') }}</label>
<input id="site" value="{{ $siteKey }}" placeholder="{{ __('smart_ordering.appearance.site_key_placeholder') }}" autocomplete="off">
</div>

<div class="so-builder-group">
<span class="so-builder-label">{{ __('smart_ordering.appearance.template_gallery_label') }}</span>
<p class="so-demo-note">{{ $siteKey === '' ? __('smart_ordering.appearance.demo_preview_help') : __('smart_ordering.appearance.real_preview_help') }}</p>
<div class="so-template-grid" role="list" aria-label="{{ __('smart_ordering.appearance.template_gallery_label') }}">
@foreach($themes as $id => $definition)
<button class="so-template-option" type="button" role="listitem" data-theme="{{ $id }}" data-dark="{{ ($definition['dark'] ?? false) ? 'true' : 'false' }}" aria-pressed="{{ $selectedTheme === $id ? 'true' : 'false' }}" @if($currentTheme === $id) aria-current="true" @endif>
<div>
<strong>{{ __('smart_ordering.appearance.production_themes.'.$id.'.label') }}</strong>
<span>{{ __('smart_ordering.appearance.production_themes.'.$id.'.positioning') }}</span>
</div>
<div class="so-template-meta">
<span class="so-template-chip">{{ __('smart_ordering.appearance.production_themes.'.$id.'.style') }}</span>
<span class="so-template-chip">{{ ($definition['dark'] ?? false) ? __('smart_ordering.appearance.dark') : __('smart_ordering.appearance.light') }}</span>
<span class="so-template-chip">v{{ $definition['version'] ?? 1 }}</span>
@if($currentTheme === $id)<span class="so-template-chip so-template-current">{{ __('smart_ordering.appearance.current_template') }}</span>@endif
</div>
</button>
@endforeach
</div>
</div>

<div class="so-builder-group">
<span class="so-builder-label">{{ __('smart_ordering.appearance.appearance_label') }}</span>
<div class="so-segmented so-segmented-two">
<button type="button" data-appearance="light" aria-pressed="{{ $selectedAppearance === 'light' ? 'true' : 'false' }}">{{ __('smart_ordering.appearance.light') }}</button>
<button type="button" data-appearance="dark" aria-pressed="{{ $selectedAppearance === 'dark' ? 'true' : 'false' }}">{{ __('smart_ordering.appearance.dark') }}</button>
</div>
</div>

<div class="so-builder-group">
<span class="so-builder-label">{{ __('smart_ordering.appearance.language_label') }}</span>
<div class="so-segmented so-segmented-two">
<button type="button" data-lang="en" aria-pressed="{{ $selectedLocale === 'en' ? 'true' : 'false' }}">{{ __('smart_ordering.appearance.english') }}</button>
<button type="button" data-lang="ar" aria-pressed="{{ $selectedLocale === 'ar' ? 'true' : 'false' }}">{{ __('smart_ordering.appearance.arabic') }}</button>
</div>
</div>

<div class="so-builder-group">
<label for="revision">{{ __('smart_ordering.appearance.revision_label') }}</label>
<select id="revision" data-revision>
<option value="draft" @selected($selectedRevision === 'draft')>{{ __('smart_ordering.appearance.draft') }}</option>
<option value="published" @selected($selectedRevision === 'published')>{{ __('smart_ordering.appearance.published') }}</option>
</select>
</div>

<div class="so-builder-group">
<span class="so-builder-label">{{ __('smart_ordering.appearance.preview_size_label') }}</span>
<div class="so-segmented">
<button type="button" data-device="mobile" aria-pressed="{{ $selectedDevice === 'mobile' ? 'true' : 'false' }}">{{ __('smart_ordering.appearance.mobile') }}</button>
<button type="button" data-device="tablet" aria-pressed="{{ $selectedDevice === 'tablet' ? 'true' : 'false' }}">{{ __('smart_ordering.appearance.tablet') }}</button>
<button type="button" data-device="desktop" aria-pressed="{{ $selectedDevice === 'desktop' ? 'true' : 'false' }}">{{ __('smart_ordering.appearance.desktop') }}</button>
</div>
</div>

<p class="so-preview-hint">{{ __('smart_ordering.appearance.privacy_hint') }}</p>
</section>

<section class="foodex-card so-preview-stage" aria-label="{{ __('smart_ordering.appearance.preview_region_label') }}">
<div class="so-preview-device" data-device-frame data-device="{{ $selectedDevice }}">
<iframe title="{{ __('smart_ordering.appearance.preview_title') }}" data-preview src="{{ $siteKey !== '' ? route('admin.smart-ordering.appearance.preview', ['site' => $siteKey, 'theme' => $selectedTheme, 'appearance' => $selectedAppearance, 'lang' => $selectedLocale, 'revision' => $selectedRevision]) : route('admin.smart-ordering.appearance.demo-preview', ['theme' => $selectedTheme, 'appearance' => $selectedAppearance, 'lang' => $selectedLocale]) }}"></iframe>
</div>
</section>
</div>
</div>
</main>
</div>

<script>
(() => {
    const root = document.querySelector('[data-builder]');
    const site = document.getElementById('site');
    const frame = root.querySelector('[data-preview]');
    const deviceFrame = root.querySelector('[data-device-frame]');
    const state = {
        theme: @json($selectedTheme),
        appearance: @json($selectedAppearance),
        lang: @json($selectedLocale),
        revision: @json($selectedRevision),
        device: @json($selectedDevice),
    };

    const setPressed = (selector, key, value) => {
        root.querySelectorAll(selector).forEach((button) => {
            button.setAttribute('aria-pressed', button.dataset[key] === value ? 'true' : 'false');
        });
    };

    const refresh = () => {
        const key = site.value.trim();
        deviceFrame.dataset.device = state.device;

        const params = new URLSearchParams({
            theme: state.theme,
            appearance: state.appearance,
            lang: state.lang,
        });
        if (key) {
            params.set('site', key);
            params.set('revision', state.revision);
        }

        const endpoint = key
            ? @json(route('admin.smart-ordering.appearance.preview'))
            : @json(route('admin.smart-ordering.appearance.demo-preview'));
        frame.src = endpoint + '?' + params.toString();
        frame.hidden = false;
    };

    root.addEventListener('click', (event) => {
        const button = event.target.closest('button');
        if (!button) return;

        if (button.dataset.theme) {
            state.theme = button.dataset.theme;
            setPressed('[data-theme]', 'theme', state.theme);
            refresh();
        }
        if (button.dataset.appearance) {
            state.appearance = button.dataset.appearance;
            setPressed('[data-appearance]', 'appearance', state.appearance);
            refresh();
        }
        if (button.dataset.lang) {
            state.lang = button.dataset.lang;
            setPressed('[data-lang]', 'lang', state.lang);
            refresh();
        }
        if (button.dataset.device) {
            state.device = button.dataset.device;
            setPressed('[data-device]', 'device', state.device);
            refresh();
        }
    });

    root.querySelector('[data-revision]').addEventListener('change', (event) => {
        state.revision = event.target.value;
        refresh();
    });
    site.addEventListener('change', refresh);
})();
</script>
</body>
</html>
