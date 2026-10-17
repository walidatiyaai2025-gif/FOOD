<!doctype html>
<html lang="{{ $locale }}" dir="{{ $direction }}" data-theme="{{ $appearance['mode'] }}" data-template="{{ $theme['id'] }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>{{ $site['seo']['title'] }}</title>
    @if($site['seo']['description'] !== '')<meta name="description" content="{{ $site['seo']['description'] }}">@endif
    @if($site['seo']['canonical'])<link rel="canonical" href="{{ $site['seo']['canonical'] }}">@endif
    @foreach($site['seo']['alternates'] ?? [] as $hreflang => $alternate)<link rel="alternate" hreflang="{{ $hreflang }}" href="{{ $alternate }}">@endforeach
    @if($site['seo']['canonical'])<link rel="alternate" hreflang="x-default" href="{{ $site['seo']['canonical'] }}">@endif
    <meta property="og:type" content="restaurant">
    <meta property="og:title" content="{{ $site['seo']['title'] }}">
    @if($site['seo']['description'] !== '')<meta property="og:description" content="{{ $site['seo']['description'] }}">@endif
    @if($site['seo']['image_url'])<meta property="og:image" content="{{ $site['seo']['image_url'] }}">@endif
    @if($site['seo']['canonical'])<meta property="og:url" content="{{ $site['seo']['canonical'] }}">@endif
    @if(($site['seo']['structured_json'] ?? '') !== '')<script type="application/ld+json">{!! $site['seo']['structured_json'] !!}</script>@endif
    <meta name="color-scheme" content="light dark">
    <link rel="stylesheet" href="/css/smart-ordering-storefront.css">
    <link rel="stylesheet" href="/css/restaurant-menu-templates-v2.css"> {{-- localization-gate: allow technical stylesheet asset --}}
    <style>:root{ @foreach($appearance['css_variables'] as $name => $value) {{ $name }}: {{ $value }}; @endforeach }</style>
</head>
<body class="so-page so-font-{{ $appearance['customization']['font'] }} so-card-{{ $appearance['customization']['card_style'] }} so-button-{{ $appearance['customization']['button_style'] }}"
    data-analytics-site="{{ $analytics['site_public_id'] ?? '' }}"
    data-analytics-branch="{{ $analytics['branch_public_id'] ?? '' }}"
    data-analytics-context="{{ $analytics['attribution_context'] ?? '' }}"
    data-menu-search-no-results="{{ trans('smart_ordering_storefront.search.no_results', [], $locale) }}"
    data-menu-search-result-one="{{ trans('smart_ordering_storefront.search.result_one', [], $locale) }}"
    data-menu-search-results="{{ trans('smart_ordering_storefront.search.results', [], $locale) }}"
    data-menu-search-clear="{{ trans('smart_ordering_storefront.search.clear', [], $locale) }}"
    data-ordering-enabled="{{ ($ordering['enabled'] ?? false) ? '1' : '0' }}"
    data-ordering-site="{{ $ordering['site_slug'] ?? '' }}"
    data-ordering-branch="{{ $ordering['branch_public_id'] ?? '' }}"
    data-ordering-revision="{{ $ordering['revision_public_id'] ?? '' }}"
    data-ordering-context="{{ $ordering['attribution_context'] ?? '' }}">
<a class="so-skip" href="#menu">{{ $locale === 'ar' ? 'انتقل إلى القائمة' : 'Skip to menu' }}</a>
<header class="so-header">
    <div class="so-brand">
        @if($site['logo_url'])<img src="{{ $site['logo_url'] }}" alt="" width="52" height="52" decoding="async">@endif
        <div><strong>{{ $site['name'] }}</strong>@if($site['tagline'] !== '')<span>{{ $site['tagline'] }}</span>@endif</div>
    </div>
    <div class="so-header-actions">
        <a class="so-icon-button" href="?lang={{ $locale === 'ar' ? 'en' : 'ar' }}" hreflang="{{ $locale === 'ar' ? 'en' : 'ar' }}">{{ $locale === 'ar' ? 'EN' : 'ع' }}</a>
        <button class="so-cart-link" type="button" data-cart-open aria-label="{{ $locale === 'ar' ? 'فتح السلة' : 'Open cart' }}" @disabled(! ($ordering['enabled'] ?? false))>
            {{ $locale === 'ar' ? 'السلة' : 'Cart' }} <span aria-hidden="true">🛒</span>
            <span class="so-cart-count" data-cart-count aria-live="polite">0</span>
        </button>
    </div>
</header>
@if(! $is_open)
<section class="so-state-banner" role="status"><strong>{{ $closed_message }}</strong><span>{{ $locale === 'ar' ? 'يمكنك تصفح القائمة الآن والطلب عند إعادة الفتح.' : 'You can still browse the menu and order when we reopen.' }}</span></section>
@endif
<main id="menu" tabindex="-1">@include($theme['view'])</main>
<nav class="so-mobile-cart" id="cart" aria-label="{{ $locale === 'ar' ? 'السلة' : 'Cart' }}">
    <button type="button" data-cart-open @disabled(! ($ordering['enabled'] ?? false))>
        <span>{{ $locale === 'ar' ? 'عرض السلة' : 'View cart' }} <b data-cart-count>0</b></span><span aria-hidden="true">→</span>
    </button>
</nav>

<dialog class="so-item-dialog" data-item-dialog aria-labelledby="so-item-dialog-title" aria-describedby="so-item-dialog-description">
    <form method="dialog" class="so-dialog-shell" data-item-form>
        <button class="so-dialog-close" value="close" aria-label="{{ $locale === 'ar' ? 'إغلاق' : 'Close' }}">×</button>
        <img data-dialog-image alt="" loading="lazy">
        <h2 id="so-item-dialog-title" data-dialog-name></h2>
        <p id="so-item-dialog-description" data-dialog-description></p>
        <div data-dialog-modifiers></div>
        <label class="so-quantity-field">
            <span>{{ $locale === 'ar' ? 'الكمية' : 'Quantity' }}</span>
            <input type="number" inputmode="numeric" min="1" max="99" step="1" value="1" data-item-quantity>
        </label>
        <p class="so-inline-error" data-item-error role="alert" hidden></p>
        <p class="so-inline-status" data-item-status role="status" aria-live="polite"></p>
        <button class="so-primary-action" type="button" data-add-to-cart @disabled(! ($ordering['enabled'] ?? false))>{{ $locale === 'ar' ? 'إضافة إلى السلة' : 'Add to cart' }}</button>
    </form>
</dialog>

<dialog class="so-cart-dialog" data-cart-dialog aria-labelledby="so-cart-dialog-title">
    <div class="so-cart-shell">
        <form method="dialog"><button class="so-dialog-close" value="close" aria-label="{{ $locale === 'ar' ? 'إغلاق' : 'Close' }}">×</button></form>
        <header class="so-cart-head">
            <p>{{ $locale === 'ar' ? 'طلبك' : 'Your order' }}</p>
            <h2 id="so-cart-dialog-title">{{ $locale === 'ar' ? 'السلة والدفع' : 'Cart & checkout' }}</h2>
        </header>
        <p class="so-inline-status" data-cart-loading role="status" hidden>{{ $locale === 'ar' ? 'جاري تحميل السلة…' : 'Loading cart…' }}</p>
        <div class="so-cart-error" data-cart-error role="alert" hidden></div>
        <div class="so-cart-empty" data-cart-empty hidden>
            <strong>{{ $locale === 'ar' ? 'السلة فارغة' : 'Your cart is empty' }}</strong>
            <span>{{ $locale === 'ar' ? 'اختر صنفاً من القائمة للبدء.' : 'Choose an item from the menu to get started.' }}</span>
        </div>
        <div data-cart-content hidden>
            <div class="so-cart-items" data-cart-items></div>
            <dl class="so-cart-totals">
                <div><dt>{{ $locale === 'ar' ? 'الإجمالي الفرعي' : 'Subtotal' }}</dt><dd data-cart-subtotal>—</dd></div>
                <div data-cart-discount-row hidden><dt>{{ $locale === 'ar' ? 'الخصم' : 'Discount' }}</dt><dd data-cart-discount>—</dd></div>
                <div><dt>{{ $locale === 'ar' ? 'الإجمالي' : 'Total' }}</dt><dd data-cart-total>—</dd></div>
            </dl>
            <form class="so-checkout-form" data-checkout-form novalidate>
                <h3>{{ $locale === 'ar' ? 'إتمام الطلب' : 'Checkout' }}</h3>
                <label>
                    <span>{{ $locale === 'ar' ? 'طريقة الاستلام' : 'Fulfillment' }}</span>
                    <select data-checkout-mode name="fulfillment_mode" required>
                        @if($ordering['supports_pickup'] ?? false)<option value="pickup">{{ $locale === 'ar' ? 'استلام من الفرع' : 'Pickup' }}</option>@endif
                        @if($ordering['supports_delivery'] ?? false)<option value="delivery">{{ $locale === 'ar' ? 'توصيل' : 'Delivery' }}</option>@endif
                        @if($ordering['supports_dine_in'] ?? false)<option value="dine_in">{{ $locale === 'ar' ? 'داخل المطعم' : 'Dine in' }}</option>@endif
                    </select>
                </label>
                <div class="so-checkout-grid">
                    <label><span>{{ $locale === 'ar' ? 'الاسم' : 'Name' }}</span><input name="name" autocomplete="name" maxlength="160" required></label>
                    <label><span>{{ $locale === 'ar' ? 'الهاتف' : 'Phone' }}</span><input name="phone" type="tel" autocomplete="tel" maxlength="60" required></label>
                </div>
                <label><span>{{ $locale === 'ar' ? 'البريد الإلكتروني (اختياري)' : 'Email (optional)' }}</span><input name="email" type="email" autocomplete="email" maxlength="190"></label>
                <label data-delivery-fields hidden>
                    <span>{{ $locale === 'ar' ? 'عنوان التوصيل' : 'Delivery address' }}</span>
                    <textarea name="delivery_address" rows="2" maxlength="1000"></textarea>
                    <small>{{ $locale === 'ar' ? 'سنطلب موقع الجهاز للتحقق من نطاق التوصيل.' : 'We will request your device location to verify the delivery zone.' }}</small>
                </label>
                <label><span>{{ $locale === 'ar' ? 'ملاحظة للطلب (اختياري)' : 'Order note (optional)' }}</span><textarea name="order_note" rows="2" maxlength="1000"></textarea></label>
                <p class="so-inline-error" data-checkout-error role="alert" hidden></p>
                <button class="so-primary-action" type="submit" data-checkout-submit>{{ $locale === 'ar' ? 'تأكيد الطلب' : 'Place order' }}</button>
            </form>
        </div>
        <section class="so-order-confirmation" data-order-confirmation hidden role="status" aria-live="polite">
            <span aria-hidden="true">✓</span>
            <h3>{{ $locale === 'ar' ? 'تم استلام طلبك' : 'Order received' }}</h3>
            <p data-order-reference></p>
        </section>
    </div>
</dialog>
<script src="/js/smart-ordering-storefront.js" defer></script>
</body>
</html>
