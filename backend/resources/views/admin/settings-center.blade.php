<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>{{ __('admin.settings_center.title') }} · FOODEX</title>
@include('admin._brand-components')
<style>
body{margin:0;background:var(--foodex-background);color:var(--foodex-ink)}
.settings-wrap{max-width:1180px;margin:0 auto}
.settings-hero{display:flex;justify-content:space-between;gap:18px;align-items:flex-start;margin-bottom:18px}
.settings-hero h1{margin:4px 0 8px;font-size:clamp(1.65rem,2.5vw,2.35rem)}
.settings-eyebrow{margin:0;color:var(--foodex-green-dark);font-weight:900}
.settings-muted{color:var(--foodex-muted);line-height:1.6}
.settings-search{background:#fff;border:1px solid var(--foodex-border);border-radius:16px;padding:14px;margin:0 0 20px;box-shadow:var(--foodex-shadow-sm)}
.settings-search label{display:block;font-weight:900;margin-bottom:8px}
.settings-search input{width:100%;min-height:44px;border:1px solid var(--foodex-border);border-radius:11px;padding:0 13px;background:#fff;color:var(--foodex-ink);font:inherit;box-sizing:border-box}
.settings-section{margin-top:24px}
.settings-section-head{margin-bottom:12px}
.settings-section-head h2{margin:0 0 5px;font-size:1.16rem}
.settings-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:14px}
.settings-card{display:flex;flex-direction:column;gap:12px;background:#fff;border:1px solid var(--foodex-border);border-radius:16px;padding:18px;box-shadow:var(--foodex-shadow-sm);min-height:196px}
.settings-card[hidden]{display:none}
.settings-card__top{display:flex;justify-content:space-between;gap:12px;align-items:flex-start}
.settings-card__icon{width:42px;height:42px;border-radius:12px;display:grid;place-items:center;background:var(--foodex-green-soft);color:var(--foodex-green-dark);font-weight:900;flex:0 0 auto}
.settings-card h3{margin:0;font-size:1.02rem}
.settings-card p{margin:0}
.settings-card__meta{display:flex;gap:7px;flex-wrap:wrap}
.settings-pill{display:inline-flex;align-items:center;min-height:28px;padding:0 9px;border-radius:999px;background:var(--foodex-background);font-size:.8rem;font-weight:800;color:var(--foodex-muted)}
.settings-pill.available{background:var(--foodex-green-soft);color:var(--foodex-green-dark)}
.settings-card__action{margin-top:auto;display:inline-flex;align-items:center;justify-content:center;min-height:42px;padding:0 13px;border-radius:10px;background:var(--foodex-action-green);color:#fff;text-decoration:none;font-weight:900}
.settings-card__restricted{margin-top:auto;display:flex;align-items:center;justify-content:center;min-height:42px;padding:0 13px;border-radius:10px;background:var(--foodex-background);color:var(--foodex-muted);font-weight:800;border:1px dashed var(--foodex-border)}
.settings-empty{display:none;margin-top:18px;padding:20px;border:1px dashed var(--foodex-border);border-radius:14px;text-align:center;color:var(--foodex-muted)}
.settings-empty.is-visible{display:block}
@media(max-width:980px){.settings-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media(max-width:680px){.settings-hero{flex-direction:column}.settings-grid{grid-template-columns:1fr}.settings-card{min-height:auto}}
</style>
</head>
<body>
@php($ar = app()->getLocale() === 'ar')
<div class="foodex-admin-layout" data-settings-center="true">
<aside class="sidebar">@include('admin._sidebar',['navGroups'=>$navGroups,'navContext'=>$navContext,'user'=>$user])</aside>
<main class="foodex-admin-main foodex-admin-page">
@include('admin._global-header')
<div class="settings-wrap">
@include('admin._admin-center-switcher',['active'=>'settings'])
<header class="settings-hero foodex-page-toolbar">
<div>
<p class="settings-eyebrow">{{ __('admin.settings_center.eyebrow') }}</p>
<h1>{{ __('admin.settings_center.title') }}</h1>
<p class="settings-muted">{{ __('admin.settings_center.description') }}</p>
</div>

</header>

<div class="settings-search">
<label for="settings-search">{{ __('admin.settings_center.search_label') }}</label>
<input id="settings-search" type="search" placeholder="{{ __('admin.settings_center.search_placeholder') }}" autocomplete="off">
</div>

@foreach($settingGroups as $groupKey => $group)
<section class="settings-section" data-settings-group="{{ $groupKey }}">
<div class="settings-section-head">
<h2>{{ $group['title'] }}</h2>
<p class="settings-muted">{{ $group['description'] }}</p>
</div>
<div class="settings-grid">
@foreach($group['items'] as $item)
<article id="setting-{{ $item['key'] }}" class="settings-card" data-setting="{{ $item['key'] }}" data-setting-search="{{ strtolower($item['title'].' '.$item['description'].' '.$group['title']) }}">
<div class="settings-card__top">
<div>
<h3>{{ $item['title'] }}</h3>
</div>
<span class="settings-card__icon" aria-hidden="true">{{ $item['icon'] }}</span>
</div>
<p class="settings-muted">{{ $item['description'] }}</p>
<div class="settings-card__meta">
<span class="settings-pill">{{ $group['title'] }}</span>
<span class="settings-pill {{ $item['allowed'] ? 'available' : '' }}">{{ $item['allowed'] ? __('admin.settings_center.available') : __('admin.settings_center.restricted') }}</span>
</div>
@if($item['allowed'])
<a class="settings-card__action" href="{{ route($item['route'],$item['params']).(!empty($item['fragment']) ? '#'.$item['fragment'] : '') }}">{{ __('admin.settings_center.open') }}</a>
@else
<div class="settings-card__restricted">{{ __('admin.settings_center.permission_required') }}</div>
@endif
</article>
@endforeach
</div>
</section>
@endforeach

<div class="settings-empty" id="settings-empty">{{ __('admin.settings_center.no_results') }}</div>
</div>
</main>
</div>
<script>
(() => {
    const input = document.getElementById('settings-search');
    const cards = Array.from(document.querySelectorAll('[data-setting]'));
    const groups = Array.from(document.querySelectorAll('[data-settings-group]'));
    const empty = document.getElementById('settings-empty');
    if (!input) return;
    const normalize = (value) => String(value || '').toLocaleLowerCase().trim();
    input.addEventListener('input', () => {
        const query = normalize(input.value);
        let visible = 0;
        cards.forEach((card) => {
            const matches = !query || normalize(card.dataset.settingSearch).includes(query);
            card.hidden = !matches;
            if (matches) visible += 1;
        });
        groups.forEach((group) => {
            group.hidden = !group.querySelector('[data-setting]:not([hidden])');
        });
        empty.classList.toggle('is-visible', visible === 0);
    });
})();
</script>
</body>
</html>
