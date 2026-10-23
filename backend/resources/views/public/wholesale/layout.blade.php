<!doctype html>
<html lang="{{ app()->getLocale() }}" dir="{{ app()->getLocale()==='ar' ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <meta name="theme-color" content="#00452F">
    <title>@yield('title', __('wholesale_public.page_title'))</title>
    <meta name="description" content="@yield('meta_description', __('wholesale_public.meta_description'))">
    <link rel="stylesheet" href="{{ asset('assets/wholesale/foodex-wholesale.css') }}">
</head>
@php
    $identityBrand = app()->getLocale()==='ar'
        ? ((string)($identity['brand_title_ar'] ?? '') ?: __('wholesale_public.brand_name'))
        : ((string)($identity['brand_title_en'] ?? '') ?: __('wholesale_public.brand_name'));
    $isWebDraftPreview = (bool)($webPreview['active'] ?? false);
    $webHomeUrl = $isWebDraftPreview ? route('admin.b2b.web-storefront.preview') : route('public.wholesale.home');
@endphp
<style>
:root{
    --fx-green:{{ $identity['primary_color'] ?? '#00452F' }};
    --fx-green-strong:{{ $identity['primary_dark_color'] ?? '#003C2A' }};
    --fx-lime:{{ $identity['accent_color'] ?? '#9BE252' }};
    --fx-mint:{{ $identity['background_color'] ?? '#EDF7F1' }};
}
</style>
<body>
@if($isWebDraftPreview)
<div style="position:sticky;top:0;z-index:90;background:#17231D;color:#fff;padding:8px 16px;text-align:center;font-weight:800;font-size:13px">
    {{ app()->getLocale()==='ar' ? 'معاينة مسودة الويب — هذه النسخة غير منشورة' : 'Web Draft Preview — this revision is not published' }}
    <span style="opacity:.7;font-weight:600"> · {{ substr((string)($webPreview['revision_id'] ?? ''),0,8) }}</span>
</div>
@endif
<header class="fx-header">
    <div class="fx-shell fx-header__row">
        <a class="fx-brand" href="{{ $webHomeUrl }}" aria-label="{{ $identityBrand }}">
            @if(!empty($store['logo_url']))
                <img src="{{ $store['logo_url'] }}" alt="{{ $identityBrand }}">
            @else
                <span class="fx-brand__mark" aria-hidden="true">✦</span>
            @endif
            <span>{{ $identityBrand }}</span>
        </a>
        <nav class="fx-nav" aria-label="{{ __('wholesale_public.nav.primary') }}">
            <a href="{{ $webHomeUrl }}">{{ __('wholesale_public.nav.home') }}</a>
            <a href="{{ $webHomeUrl }}#categories">{{ __('wholesale_public.nav.categories') }}</a>
            <a href="{{ $isWebDraftPreview ? $webHomeUrl.'#brands' : route('public.wholesale.brands') }}">{{ __('wholesale_public.nav.brands') }}</a>
            <a href="{{ $webHomeUrl }}#products">{{ __('wholesale_public.nav.products') }}</a>
        </nav>
        <div class="fx-header__actions">
            <a class="fx-link-button" href="{{ $adminUrl }}">{{ __('wholesale_public.nav.management_login') }}</a>
            <a class="fx-primary-button" href="{{ $appUrl }}">{{ __('wholesale_public.nav.get_app') }}</a>
        </div>
        <button class="fx-menu-button" type="button" data-menu-toggle aria-label="{{ __('wholesale_public.nav.open_menu') }}">☰</button>
    </div>
    <div class="fx-mobile-nav" data-mobile-nav hidden>
        <a href="{{ $webHomeUrl }}">{{ __('wholesale_public.nav.home') }}</a>
        <a href="{{ $webHomeUrl }}#categories">{{ __('wholesale_public.nav.categories') }}</a>
        <a href="{{ $isWebDraftPreview ? $webHomeUrl.'#brands' : route('public.wholesale.brands') }}">{{ __('wholesale_public.nav.brands') }}</a>
        <a href="{{ $webHomeUrl }}#products">{{ __('wholesale_public.nav.products') }}</a>
        <a href="{{ $adminUrl }}">{{ __('wholesale_public.nav.management_login') }}</a>
    </div>
</header>

<main>
    @yield('content')
</main>

<footer class="fx-footer">
    <div class="fx-shell fx-footer__grid">
        <div>
            <div class="fx-brand fx-brand--footer"><span class="fx-brand__mark" aria-hidden="true">✦</span><span>{{ $identityBrand }}</span></div>
            <p>{{ __('wholesale_public.footer.tagline') }}</p>
        </div>
        <div>
            <strong>{{ __('wholesale_public.footer.quick_links') }}</strong>
            <a href="{{ $isWebDraftPreview ? $webHomeUrl.'#brands' : route('public.wholesale.brands') }}">{{ __('wholesale_public.nav.brands') }}</a>
            <a href="{{ route('public.privacy') }}">{{ __('wholesale_public.footer.privacy') }}</a>
            <a href="{{ route('public.terms') }}">{{ __('wholesale_public.footer.terms') }}</a>
        </div>
        <div>
            <strong>{{ __('wholesale_public.footer.connect') }}</strong>
            @if(!empty($branding['facebook_url']))
                <a href="{{ $branding['facebook_url'] }}" target="_blank" rel="noopener">{{ __('wholesale_public.facebook.label') }}</a>
            @endif
            <a href="{{ route('public.support') }}">{{ __('wholesale_public.footer.support') }}</a>
        </div>
        <div>
            <strong>{{ __('wholesale_public.footer.pricing_title') }}</strong>
            <p>{{ __('wholesale_public.footer.pricing_body') }}</p>
            <a class="fx-primary-button" href="{{ $appUrl }}">{{ __('wholesale_public.footer.app_cta') }}</a>
        </div>
    </div>
</footer>
<script src="{{ asset('assets/wholesale/foodex-wholesale.js') }}" defer></script>
</body>
</html>
