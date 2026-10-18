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
.so-builder-group{display:grid;gap:var(--foodex-space-2);min-width:0}
.so-builder-group>label,.so-builder-label{font-size:var(--foodex-text-xs);font-weight:var(--foodex-font-weight-bold);color:var(--foodex-muted)}
.so-builder-group input,.so-builder-group select{width:100%;min-height:var(--foodex-touch-target);border:1px solid var(--foodex-border);background:var(--foodex-surface);color:var(--foodex-ink);border-radius:var(--foodex-radius-control);padding:0 var(--foodex-space-3);font:inherit}
.so-template-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:var(--foodex-space-3);min-width:0;max-width:100%}
.so-template-option{box-sizing:border-box;position:relative;display:grid;gap:var(--foodex-space-2);width:100%;min-width:0;max-width:100%;min-height:156px;border:1px solid var(--foodex-border);background:var(--foodex-surface);color:var(--foodex-ink);border-radius:var(--foodex-radius-md);padding:var(--foodex-space-3);text-align:start;cursor:pointer;overflow-wrap:anywhere;overflow:hidden}
.so-template-option::before{content:"";display:block;height:58px;border-radius:calc(var(--foodex-radius-md) - 3px);background:linear-gradient(135deg,var(--foodex-green-soft),var(--foodex-surface) 52%,var(--foodex-background));border:1px solid var(--foodex-border)}
.so-template-option[data-dark="true"]::before{background:linear-gradient(135deg,var(--foodex-ink),var(--foodex-green-dark) 55%,var(--foodex-orange));border-color:var(--foodex-muted)}
.so-template-option[aria-pressed=true]{border-color:var(--foodex-green);box-shadow:0 0 0 2px var(--foodex-green-soft)}
.so-template-option strong{display:block;font-size:var(--foodex-text-sm);line-height:var(--foodex-leading-tight);overflow-wrap:anywhere}
.so-template-option span{display:block;font-size:var(--foodex-text-xs);color:var(--foodex-muted);line-height:var(--foodex-leading-normal);overflow-wrap:anywhere}
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
.so-quick-menu-card{display:grid;gap:10px;padding:12px;border:1px solid #bfe0c7;border-radius:14px;background:linear-gradient(135deg,#f4fbf6,#fff);box-shadow:0 8px 24px rgba(21,138,58,.06)}
.so-quick-menu-head{display:flex;align-items:flex-start;justify-content:space-between;gap:10px}
.so-quick-menu-title{display:grid;gap:2px}.so-quick-menu-title strong{font-size:.86rem;color:var(--foodex-ink)}.so-quick-menu-title span{font-size:.68rem;color:var(--foodex-muted);line-height:1.5}
.so-quick-menu-stats{display:flex;gap:5px;flex-wrap:wrap}.so-quick-menu-stat{display:inline-flex;align-items:center;gap:5px;min-height:24px;padding:0 8px;border:1px solid var(--foodex-border);border-radius:999px;background:#fff;font-size:.62rem;font-weight:900;color:#475569}
.so-quick-menu-actions{display:flex;align-items:center;gap:6px;flex-wrap:wrap}.so-quick-menu-actions .foodex-action-primary,.so-quick-menu-actions .foodex-action-secondary{min-height:36px;padding-inline:12px;border-radius:10px;font-size:.72rem}
.so-menu-wizard{position:fixed;z-index:14050;inset:0;display:grid;place-items:center;padding:18px;background:rgba(15,23,42,.52);backdrop-filter:blur(6px)}
.so-menu-wizard[hidden]{display:none!important}
.so-menu-wizard-dialog{width:min(760px,96vw);max-height:92dvh;display:grid;grid-template-rows:auto auto minmax(0,1fr) auto;overflow:hidden;border:1px solid var(--foodex-border);border-radius:20px;background:var(--foodex-surface);box-shadow:0 32px 90px rgba(15,23,42,.3)}
.so-menu-wizard-head{display:flex;align-items:flex-start;justify-content:space-between;gap:12px;padding:16px 18px;border-bottom:1px solid var(--foodex-border);background:linear-gradient(135deg,#f8fbf9,#eef8f1)}
.so-menu-wizard-head h2{margin:0;font-size:1rem}.so-menu-wizard-head p{margin:4px 0 0;color:var(--foodex-muted);font-size:.7rem}
.so-menu-wizard-close{width:34px;height:34px;display:grid;place-items:center;border:1px solid var(--foodex-border);border-radius:999px;background:#fff;color:var(--foodex-ink);font-size:1.1rem;font-weight:900;cursor:pointer}
.so-menu-wizard-progress{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:6px;padding:10px 18px;border-bottom:1px solid var(--foodex-border);background:#fff}
.so-menu-wizard-progress span{display:flex;align-items:center;justify-content:center;gap:6px;min-height:34px;padding:0 8px;border-radius:10px;background:#f8fafc;color:#64748b;font-size:.66rem;font-weight:900}
.so-menu-wizard-progress span[aria-current="step"]{background:var(--foodex-green-soft);color:var(--foodex-green-dark);box-shadow:inset 0 0 0 1px #b9d8c1}
.so-menu-wizard-body{min-height:0;overflow:auto;overscroll-behavior:contain;padding:16px 18px}
.so-menu-step{display:grid;gap:12px}.so-menu-step[hidden]{display:none!important}
.so-menu-step-copy{padding:10px 12px;border-radius:12px;background:#f8fafc;color:#475569;font-size:.7rem;line-height:1.55}
.so-menu-form-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}.so-menu-form-grid label,.so-menu-item-row label{display:grid;gap:5px;font-size:.68rem;font-weight:900;color:#334155}
.so-menu-form-grid input,.so-menu-item-row input{width:100%;min-height:42px;border:1px solid var(--foodex-border);border-radius:10px;background:#fff;padding:0 11px;font:inherit}
.so-menu-suggestions{display:flex;gap:6px;flex-wrap:wrap}.so-menu-suggestion{min-height:32px;padding:0 10px;border:1px solid #cbd5e1;border-radius:999px;background:#fff;color:#475569;font-size:.65rem;font-weight:800;cursor:pointer}
.so-menu-suggestion:hover{border-color:#86c395;color:var(--foodex-green-dark);background:var(--foodex-green-soft)}
.so-menu-items{display:grid;gap:8px}
.so-menu-item-row{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1fr) 130px auto;gap:7px;align-items:end;padding:9px;border:1px solid var(--foodex-border);border-radius:12px;background:#fbfcfd}
.so-menu-item-featured{display:flex!important;align-items:center!important;gap:5px!important;min-height:42px;padding-inline:4px;white-space:nowrap}.so-menu-item-featured input{width:auto!important;min-height:0!important}
.so-menu-item-remove{width:34px;height:34px;border:1px solid #fecaca;border-radius:9px;background:#fff7f7;color:#b91c1c;font-weight:900;cursor:pointer}
.so-menu-add-item{justify-self:start;min-height:36px;padding:0 11px;border:1px dashed #8fb99a;border-radius:10px;background:#f5fbf6;color:var(--foodex-green-dark);font-size:.68rem;font-weight:900;cursor:pointer}
.so-menu-review{display:grid;gap:8px}.so-menu-review-card{padding:10px 12px;border:1px solid var(--foodex-border);border-radius:12px;background:#fff}.so-menu-review-card strong{display:block;margin-bottom:4px;font-size:.72rem}.so-menu-review-card span{color:var(--foodex-muted);font-size:.67rem}
.so-menu-wizard-actions{display:flex;align-items:center;justify-content:space-between;gap:8px;padding:12px 18px;border-top:1px solid var(--foodex-border);background:#fff}
.so-menu-wizard-actions>div{display:flex;align-items:center;gap:6px}.so-menu-wizard-actions button{min-height:38px;padding:0 13px;border-radius:10px;font-size:.7rem;font-weight:900;cursor:pointer}
.so-menu-next,.so-menu-submit{border:1px solid var(--foodex-action-green);background:var(--foodex-action-green);color:#fff}.so-menu-back{border:1px solid var(--foodex-border);background:#fff;color:var(--foodex-ink)}
body.so-menu-wizard-open{overflow:hidden}
@media(max-width:700px){.so-menu-wizard{padding:6px}.so-menu-wizard-dialog{width:100%;max-height:calc(100dvh - 12px);border-radius:14px}.so-menu-form-grid{grid-template-columns:1fr}.so-menu-item-row{grid-template-columns:1fr 1fr}.so-menu-item-row label:nth-child(3){grid-column:1/2}.so-menu-item-featured{grid-column:2/3}.so-menu-item-remove{grid-column:2/3;justify-self:end}.so-menu-wizard-progress{padding-inline:10px}.so-menu-wizard-body{padding:12px}.so-menu-wizard-actions{padding:10px 12px}}

@media(max-width:1023px){.so-builder-grid{grid-template-columns:minmax(0,1fr)}.so-builder-panel{position:static}.so-preview-stage{padding:var(--foodex-space-3)}}
@media(max-width:767px){.so-builder-panel,.so-preview-stage{padding:var(--foodex-space-4)}.so-preview-device iframe{height:70vh}.so-segmented{grid-template-columns:minmax(0,1fr)}.so-segmented.so-segmented-two{grid-template-columns:repeat(2,minmax(0,1fr))}.so-template-grid{grid-template-columns:minmax(0,1fr)}}
</style>
</head>
<body>
<?php
$isAr = app()->getLocale() === 'ar';
$quickMenuOldItems = old('items', [['name_ar'=>'','name_en'=>'','price'=>'','featured'=>false]]);
if (!is_array($quickMenuOldItems) || $quickMenuOldItems === []) {
    $quickMenuOldItems = [['name_ar'=>'','name_en'=>'','price'=>'','featured'=>false]];
}
$quickMenuShouldOpen = old('_quick_menu') === '1';
?>
<div class="foodex-admin-layout" data-foodex-mobile-web-page="smart-ordering-appearance" data-builder>
<aside class="sidebar">@include('admin._sidebar', ['navGroups' => app(\App\Support\AdminNavigation::class)->groupsFor(auth()->user()), 'navContext' => 'smart_ordering', 'user' => auth()->user()])</aside> {{-- localization-gate: allow Blade include expression --}}
<main class="foodex-admin-main foodex-admin-page">
@include('admin._global-header')
<div class="so-builder-shell">
<div class="foodex-page-toolbar">
<div>
<span class="foodex-subtitle">{{ __('smart_ordering.appearance.eyebrow') }}</span>
<h1>{{ __('smart_ordering.appearance.heading') }}</h1>
<p>{{ __('smart_ordering.appearance.description') }}</p>
</div>
</div>

@include('admin._smart-ordering-workspace-nav')

<div class="so-builder-grid">
<section class="foodex-card so-builder-panel" aria-label="{{ __('smart_ordering.appearance.controls_label') }}">
<div class="so-builder-group">
<label for="site">{{ __('smart_ordering.appearance.site_key_label') }}</label>
<input id="site" value="{{ $siteKey }}" placeholder="{{ __('smart_ordering.appearance.site_key_placeholder') }}" autocomplete="off">
</div>

@if($site instanceof \App\Domain\SmartOrdering\Models\SmartOrderingSite && $canApply)
<div class="so-quick-menu-card" id="menu-builder" data-quick-menu-card>
    <div class="so-quick-menu-head">
        <div class="so-quick-menu-title">
            <strong>{{ $isAr ? 'ابني المنيو من هنا' : 'Build your menu here' }}</strong>
            <span>{{ $isAr ? '3 خطوات فقط: اسم المنيو، القسم الأول، ثم الأصناف والأسعار.' : 'Only 3 steps: menu name, first category, then items and prices.' }}</span>
        </div>
        <span aria-hidden="true">🍽️</span>
    </div>
    <div class="so-quick-menu-stats">
        <span class="so-quick-menu-stat">{{ $menuCount }} {{ $isAr ? 'منيو' : 'menus' }}</span>
        <span class="so-quick-menu-stat">{{ $menuItemCount }} {{ $isAr ? 'صنف' : 'items' }}</span>
        @if($quickMenuCurrency)<span class="so-quick-menu-stat">{{ $quickMenuCurrency }}</span>@endif
    </div>
    <div class="so-quick-menu-actions">
        <button class="foodex-action-primary" type="button" data-quick-menu-open>{{ $menuCount > 0 ? ($isAr ? 'إضافة منيو سريعة' : 'Add quick menu') : ($isAr ? 'إنشاء أول منيو' : 'Create first menu') }}</button>
        <a class="foodex-action-secondary" href="{{ route('admin.smart-ordering.menu.index',['store_id'=>$site->store_id]) }}">{{ $isAr ? 'المحرر المتقدم' : 'Advanced editor' }}</a>
    </div>
</div>
@endif

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

@if($siteKey !== '' && $canApply)
<form method="post" action="{{ route('admin.smart-ordering.appearance.apply') }}" class="so-builder-group" data-apply-form data-current-theme="{{ $currentTheme }}">
@csrf
<input type="hidden" name="site" value="{{ $siteKey }}">
<input type="hidden" name="theme" value="{{ $selectedTheme }}" data-apply-theme>
<input type="hidden" name="lang" value="{{ $selectedLocale }}" data-apply-lang>
<input type="hidden" name="device" value="{{ $selectedDevice }}" data-apply-device>
<button class="foodex-action-primary button primary" type="submit" data-apply-button @disabled($currentTheme === $selectedTheme)>
{{ __('smart_ordering.appearance.apply_to_draft') }}
</button>
<p class="so-preview-hint" data-apply-status>{{ $currentTheme === $selectedTheme ? __('smart_ordering.appearance.current_template_help') : __('smart_ordering.appearance.apply_help') }}</p>
</form>
@elseif($siteKey !== '')
<p class="so-preview-hint">{{ __('smart_ordering.appearance.read_only_help') }}</p>
@else
<p class="so-preview-hint">{{ __('smart_ordering.appearance.preview_only_help') }}</p>
@endif

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
@if($site instanceof \App\Domain\SmartOrdering\Models\SmartOrderingSite && $canApply)
<div class="so-menu-wizard" data-quick-menu-modal role="dialog" aria-modal="true" aria-labelledby="quick-menu-title" @if(!$quickMenuShouldOpen) hidden @endif>
    <form class="so-menu-wizard-dialog" method="post" action="{{ route('admin.smart-ordering.menu.quick-create') }}" data-quick-menu-form>
        @csrf
        <input type="hidden" name="store_id" value="{{ $site->store_id }}">
        <input type="hidden" name="_quick_menu" value="1">

        <header class="so-menu-wizard-head">
            <div>
                <h2 id="quick-menu-title">{{ $isAr ? 'إنشاء المنيو بسهولة' : 'Create menu easily' }}</h2>
                <p>{{ $isAr ? 'ابدأ بالأساسيات الآن، والتفاصيل المتقدمة تقدر تضيفها بعدين.' : 'Start with the essentials now; advanced details can be added later.' }}</p>
            </div>
            <button class="so-menu-wizard-close" type="button" data-quick-menu-close aria-label="{{ $isAr ? 'إغلاق' : 'Close' }}">×</button>
        </header>

        <div class="so-menu-wizard-progress" aria-label="{{ $isAr ? 'خطوات إنشاء المنيو' : 'Menu creation steps' }}">
            <span aria-current="step" data-quick-menu-progress="1">1 · {{ $isAr ? 'المنيو' : 'Menu' }}</span>
            <span data-quick-menu-progress="2">2 · {{ $isAr ? 'القسم' : 'Category' }}</span>
            <span data-quick-menu-progress="3">3 · {{ $isAr ? 'الأصناف' : 'Items' }}</span>
        </div>

        <div class="so-menu-wizard-body">
            @if($quickMenuShouldOpen && $errors->any())
                <div class="foodex-alert error" role="alert">{{ $errors->first() }}</div>
            @endif

            <section class="so-menu-step" data-quick-menu-step="1">
                <div class="so-menu-step-copy">{{ $isAr ? 'اكتب اسم المنيو فقط. ممكن تستخدم نفس الاسم بالعربي والإنجليزي لو مش محتاج ترجمة مختلفة.' : 'Enter the menu name. You can use the same name for both languages if needed.' }}</div>
                <div class="so-menu-form-grid">
                    <label>{{ $isAr ? 'اسم المنيو بالعربي' : 'Menu name in Arabic' }}
                        <input type="text" name="menu_name_ar" value="{{ old('menu_name_ar') }}" placeholder="{{ $isAr ? 'مثال: المنيو الرئيسية' : 'Example: Main menu' }}" data-quick-required-group="menu">
                    </label>
                    <label>{{ $isAr ? 'اسم المنيو بالإنجليزي' : 'Menu name in English' }}
                        <input type="text" name="menu_name_en" value="{{ old('menu_name_en') }}" placeholder="Main menu" data-quick-required-group="menu">
                    </label>
                </div>
            </section>

            <section class="so-menu-step" data-quick-menu-step="2" hidden>
                <div class="so-menu-step-copy">{{ $isAr ? 'ابدأ بقسم واحد فقط. بعد الحفظ تقدر تضيف أقسام أخرى بنفس سهولة.' : 'Start with one category. You can add more later.' }}</div>
                <div class="so-menu-suggestions">
                    @foreach(($isAr ? ['الأطباق الرئيسية','المقبلات','المشروبات','الحلويات'] : ['Main dishes','Starters','Drinks','Desserts']) as $suggestion)
                        <button class="so-menu-suggestion" type="button" data-category-suggestion="{{ $suggestion }}">{{ $suggestion }}</button>
                    @endforeach
                </div>
                <div class="so-menu-form-grid">
                    <label>{{ $isAr ? 'اسم القسم بالعربي' : 'Category name in Arabic' }}
                        <input type="text" name="category_name_ar" value="{{ old('category_name_ar') }}" placeholder="{{ $isAr ? 'مثال: الأطباق الرئيسية' : 'Example: Main dishes' }}" data-quick-category-ar>
                    </label>
                    <label>{{ $isAr ? 'اسم القسم بالإنجليزي' : 'Category name in English' }}
                        <input type="text" name="category_name_en" value="{{ old('category_name_en') }}" placeholder="Main dishes" data-quick-category-en>
                    </label>
                </div>
            </section>

            <section class="so-menu-step" data-quick-menu-step="3" hidden>
                <div class="so-menu-step-copy">{{ $isAr ? 'أضف الأصناف الأساسية الآن: الاسم والسعر فقط. الصور والإضافات والتفاصيل تقدر تضيفها لاحقاً.' : 'Add the essentials now: item name and price. Images and extras can be added later.' }}</div>
                <div class="so-menu-items" data-quick-menu-items>
                    @foreach($quickMenuOldItems as $quickIndex => $quickItem)
                    <div class="so-menu-item-row" data-quick-menu-item>
                        <label>{{ $isAr ? 'اسم الصنف بالعربي' : 'Arabic item name' }}
                            <input type="text" name="items[{{ $quickIndex }}][name_ar]" value="{{ $quickItem['name_ar'] ?? '' }}" placeholder="{{ $isAr ? 'مثال: برجر لحم' : 'Example: Beef burger' }}">
                        </label>
                        <label>{{ $isAr ? 'اسم الصنف بالإنجليزي' : 'English item name' }}
                            <input type="text" name="items[{{ $quickIndex }}][name_en]" value="{{ $quickItem['name_en'] ?? '' }}" placeholder="Beef burger">
                        </label>
                        <label>{{ $isAr ? 'السعر' : 'Price' }} @if($quickMenuCurrency)<span>({{ $quickMenuCurrency }})</span>@endif
                            <input type="number" step="0.001" min="0" name="items[{{ $quickIndex }}][price]" value="{{ $quickItem['price'] ?? '' }}" placeholder="0.000" required>
                        </label>
                        <label class="so-menu-item-featured"><input type="checkbox" name="items[{{ $quickIndex }}][featured]" value="1" @checked((bool)($quickItem['featured'] ?? false))> {{ $isAr ? 'مميز' : 'Featured' }}</label>
                        <button class="so-menu-item-remove" type="button" data-quick-menu-remove aria-label="{{ $isAr ? 'حذف الصنف' : 'Remove item' }}">×</button>
                    </div>
                    @endforeach
                </div>
                <button class="so-menu-add-item" type="button" data-quick-menu-add>+ {{ $isAr ? 'إضافة صنف آخر' : 'Add another item' }}</button>
                <div class="so-menu-review" data-quick-menu-review></div>
            </section>
        </div>

        <footer class="so-menu-wizard-actions">
            <button class="so-menu-back" type="button" data-quick-menu-back hidden>{{ $isAr ? 'السابق' : 'Back' }}</button>
            <div>
                <button class="so-menu-next" type="button" data-quick-menu-next>{{ $isAr ? 'التالي' : 'Next' }}</button>
                <button class="so-menu-submit" type="submit" data-quick-menu-submit hidden>{{ $isAr ? 'حفظ المنيو كمسودة' : 'Save menu as draft' }}</button>
            </div>
        </footer>
    </form>
</div>
@endif

</main>
</div>

<script>
(() => {
    const modal = document.querySelector('[data-quick-menu-modal]');
    const openButton = document.querySelector('[data-quick-menu-open]');
    const closeButton = modal?.querySelector('[data-quick-menu-close]');
    const form = modal?.querySelector('[data-quick-menu-form]');
    if (!modal || !openButton || !closeButton || !form) return;

    const steps = [...modal.querySelectorAll('[data-quick-menu-step]')];
    const progress = [...modal.querySelectorAll('[data-quick-menu-progress]')];
    const back = modal.querySelector('[data-quick-menu-back]');
    const next = modal.querySelector('[data-quick-menu-next]');
    const submit = modal.querySelector('[data-quick-menu-submit]');
    const items = modal.querySelector('[data-quick-menu-items]');
    const addItem = modal.querySelector('[data-quick-menu-add]');
    const review = modal.querySelector('[data-quick-menu-review]');
    let current = 1;
    let itemIndex = items.querySelectorAll('[data-quick-menu-item]').length;

    const open = () => {
        modal.hidden = false;
        document.body.classList.add('so-menu-wizard-open');
        requestAnimationFrame(() => modal.querySelector('input:not([type="hidden"])')?.focus({preventScroll:true}));
    };

    const close = () => {
        modal.hidden = true;
        document.body.classList.remove('so-menu-wizard-open');
        openButton.focus({preventScroll:true});
    };

    const inputValue = (name) => (form.querySelector('[name="' + name + '"]')?.value || '').trim();
    const hasAnyValue = (selector) => [...form.querySelectorAll(selector)].some((input) => input.value.trim() !== '');

    const validateStep = () => {
        if (current === 1 && !hasAnyValue('[data-quick-required-group="menu"]')) {
            form.querySelector('[name="menu_name_ar"]')?.focus();
            return false;
        }

        if (current === 2 && inputValue('category_name_ar') === '' && inputValue('category_name_en') === '') {
            form.querySelector('[name="category_name_ar"]')?.focus();
            return false;
        }

        if (current === 3) {
            const rows = [...items.querySelectorAll('[data-quick-menu-item]')];
            if (rows.length === 0) return false;
            for (const row of rows) {
                const ar = row.querySelector('input[name$="[name_ar]"]')?.value.trim() || '';
                const en = row.querySelector('input[name$="[name_en]"]')?.value.trim() || '';
                const price = row.querySelector('input[name$="[price]"]');
                if (ar === '' && en === '') {
                    row.querySelector('input[name$="[name_ar]"]')?.focus();
                    return false;
                }
                if (!price || price.value === '') {
                    price?.focus();
                    return false;
                }
            }
        }

        return true;
    };

    const refreshReview = () => {
        if (!review) return;
        const itemRows = [...items.querySelectorAll('[data-quick-menu-item]')];
        const menuName = inputValue('menu_name_ar') || inputValue('menu_name_en');
        const categoryName = inputValue('category_name_ar') || inputValue('category_name_en');

        review.innerHTML = '';
        const card = document.createElement('div');
        card.className = 'so-menu-review-card';

        const title = document.createElement('strong');
        title.textContent = menuName + ' · ' + categoryName;

        const meta = document.createElement('span');
        meta.textContent = itemRows.length + ' {{ $isAr ? 'صنف جاهز للحفظ كمسودة' : ' items ready to save as draft' }}';

        card.append(title, meta);
        review.append(card);
    };

    const render = () => {
        steps.forEach((step) => {
            step.hidden = Number(step.dataset.quickMenuStep) !== current;
        });
        progress.forEach((node) => {
            if (Number(node.dataset.quickMenuProgress) === current) {
                node.setAttribute('aria-current', 'step');
            } else {
                node.removeAttribute('aria-current');
            }
        });
        back.hidden = current === 1;
        next.hidden = current === 3;
        submit.hidden = current !== 3;
        if (current === 3) refreshReview();
    };

    const addItemRow = () => {
        const source = items.querySelector('[data-quick-menu-item]');
        if (!source) return;

        const row = source.cloneNode(true);
        const index = itemIndex++;
        row.querySelectorAll('input').forEach((input) => {
            if (input.name) {
                input.name = input.name.replace(/items\[\d+\]/, 'items[' + index + ']');
            }
            if (input.type === 'checkbox') {
                input.checked = false;
            } else {
                input.value = '';
            }
        });

        items.append(row);
        row.querySelector('input')?.focus();
        refreshReview();
    };

    openButton.addEventListener('click', open);
    closeButton.addEventListener('click', close);
    modal.addEventListener('pointerdown', (event) => {
        if (event.target === modal) close();
    });
    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && !modal.hidden) close();
    });

    next.addEventListener('click', () => {
        if (!validateStep()) return;
        current = Math.min(3, current + 1);
        render();
    });

    back.addEventListener('click', () => {
        current = Math.max(1, current - 1);
        render();
    });

    addItem.addEventListener('click', addItemRow);

    items.addEventListener('click', (event) => {
        const remove = event.target.closest('[data-quick-menu-remove]');
        if (!remove) return;
        const rows = items.querySelectorAll('[data-quick-menu-item]');
        if (rows.length <= 1) return;
        remove.closest('[data-quick-menu-item]')?.remove();
        refreshReview();
    });

    modal.querySelectorAll('[data-category-suggestion]').forEach((button) => {
        button.addEventListener('click', () => {
            const target = document.documentElement.dir === 'rtl'
                ? form.querySelector('[data-quick-category-ar]')
                : form.querySelector('[data-quick-category-en]');
            if (target) {
                target.value = button.dataset.categorySuggestion || '';
                target.focus();
            }
        });
    });

    form.addEventListener('submit', (event) => {
        if (!validateStep()) event.preventDefault();
    });

    if (!modal.hidden) {
        document.body.classList.add('so-menu-wizard-open');
    }

    render();
})();

(() => {
    const root = document.querySelector('[data-builder]');
    const site = document.getElementById('site');
    const frame = root.querySelector('[data-preview]');
    const deviceFrame = root.querySelector('[data-device-frame]');
    const applyForm = root.querySelector('[data-apply-form]');
    const applyButton = root.querySelector('[data-apply-button]');
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
            if (applyForm) {
                applyForm.querySelector('[data-apply-theme]').value = state.theme;
                if (applyButton) {
                    applyButton.disabled = state.theme === applyForm.dataset.currentTheme;
                }
                const status = applyForm.querySelector('[data-apply-status]');
                if (status) {
                    status.textContent = state.theme === applyForm.dataset.currentTheme
                        ? @json(__('smart_ordering.appearance.current_template_help'))
                        : @json(__('smart_ordering.appearance.apply_help'));
                }
            }
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
            if (applyForm) applyForm.querySelector('[data-apply-lang]').value = state.lang;
            refresh();
        }
        if (button.dataset.device) {
            state.device = button.dataset.device;
            setPressed('[data-device]', 'device', state.device);
            if (applyForm) applyForm.querySelector('[data-apply-device]').value = state.device;
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
