<!doctype html>
<html lang="{{ $locale }}" dir="{{ $direction }}" data-theme="{{ $appearance['mode'] }}" data-template="{{ $theme['id'] }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>{{ $site['seo']['title'] }}</title>
    @if($site['seo']['description'] !== '')<meta name="description" content="{{ $site['seo']['description'] }}">@endif
    @if($site['seo']['canonical'])<link rel="canonical" href="{{ $site['seo']['canonical'] }}">@endif
    <meta property="og:type" content="restaurant">
    <meta property="og:title" content="{{ $site['seo']['title'] }}">
    @if($site['seo']['description'] !== '')<meta property="og:description" content="{{ $site['seo']['description'] }}">@endif
    @if($site['seo']['image_url'])<meta property="og:image" content="{{ $site['seo']['image_url'] }}">@endif
    @if($site['seo']['canonical'])<meta property="og:url" content="{{ $site['seo']['canonical'] }}">@endif
    <meta name="color-scheme" content="light dark">
    <link rel="stylesheet" href="/css/smart-ordering-storefront.css">
    <style>:root{ @foreach($appearance['css_variables'] as $name => $value) {{ $name }}: {{ $value }}; @endforeach }</style>
</head>
<body class="so-page so-font-{{ $appearance['customization']['font'] }} so-card-{{ $appearance['customization']['card_style'] }} so-button-{{ $appearance['customization']['button_style'] }}"
    data-analytics-site="{{ $analytics['site_public_id'] ?? '' }}"
    data-analytics-branch="{{ $analytics['branch_public_id'] ?? '' }}"
    data-analytics-context="{{ $analytics['attribution_context'] ?? '' }}">
<a class="so-skip" href="#menu">{{ $locale === 'ar' ? 'انتقل إلى القائمة' : 'Skip to menu' }}</a>
<header class="so-header">
    <div class="so-brand">
        @if($site['logo_url'])<img src="{{ $site['logo_url'] }}" alt="" width="52" height="52" decoding="async">@endif
        <div><strong>{{ $site['name'] }}</strong>@if($site['tagline'] !== '')<span>{{ $site['tagline'] }}</span>@endif</div>
    </div>
    <div class="so-header-actions">
        <a class="so-icon-button" href="?lang={{ $locale === 'ar' ? 'en' : 'ar' }}" hreflang="{{ $locale === 'ar' ? 'en' : 'ar' }}">{{ $locale === 'ar' ? 'EN' : 'ع' }}</a>
        <a class="so-cart-link" href="#cart" aria-label="{{ $locale === 'ar' ? 'فتح السلة' : 'Open cart' }}">{{ $locale === 'ar' ? 'السلة' : 'Cart' }} <span aria-hidden="true">🛒</span></a>
    </div>
</header>
@if(! $is_open)
<section class="so-state-banner" role="status"><strong>{{ $closed_message }}</strong><span>{{ $locale === 'ar' ? 'يمكنك تصفح القائمة الآن والطلب عند إعادة الفتح.' : 'You can still browse the menu and order when we reopen.' }}</span></section>
@endif
<main id="menu" tabindex="-1">@include($theme['view'])</main>
<nav class="so-mobile-cart" id="cart" aria-label="{{ $locale === 'ar' ? 'السلة' : 'Cart' }}">
    <button type="button" data-cart-entry @disabled(! $is_open)><span>{{ $locale === 'ar' ? 'عرض السلة' : 'View cart' }}</span><span aria-hidden="true">→</span></button>
</nav>
<dialog class="so-item-dialog" data-item-dialog>
    <form method="dialog" class="so-dialog-shell">
        <button class="so-dialog-close" value="close" aria-label="{{ $locale === 'ar' ? 'إغلاق' : 'Close' }}">×</button>
        <img data-dialog-image alt="" loading="lazy">
        <h2 data-dialog-name></h2><p data-dialog-description></p><div data-dialog-modifiers></div>
        <button class="so-primary-action" type="button" data-cart-entry @disabled(! $is_open)>{{ $locale === 'ar' ? 'إضافة إلى السلة' : 'Add to cart' }}</button>
    </form>
</dialog>
<script src="/js/smart-ordering-storefront.js" defer></script>
</body>
</html>
