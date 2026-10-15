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
    data-analytics-context="{{ $analytics['attribution_context'] ?? '' }}"
    data-commerce-branch="{{ $commerce['branch_public_id'] ?? '' }}"
    data-commerce-revision="{{ $commerce['revision_public_id'] ?? '' }}"
    data-commerce-modes='@json($commerce["fulfillment_modes"] ?? [], JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_TAG | JSON_HEX_QUOT)'>
<a class="so-skip" href="#menu">{{ $locale === 'ar' ? 'انتقل إلى القائمة' : 'Skip to menu' }}</a>
<header class="so-header">
    <div class="so-brand">
        @if($site['logo_url'])<img src="{{ $site['logo_url'] }}" alt="" width="52" height="52" decoding="async">@endif
        <div><strong>{{ $site['name'] }}</strong>@if($site['tagline'] !== '')<span>{{ $site['tagline'] }}</span>@endif</div>
    </div>
    <div class="so-header-actions">
        <a class="so-icon-button" href="?lang={{ $locale === 'ar' ? 'en' : 'ar' }}" hreflang="{{ $locale === 'ar' ? 'en' : 'ar' }}">{{ $locale === 'ar' ? 'EN' : 'ع' }}</a>
        <a class="so-cart-link" href="#cart" data-view-cart aria-label="{{ $locale === 'ar' ? 'فتح السلة' : 'Open cart' }}">{{ $locale === 'ar' ? 'السلة' : 'Cart' }} <span data-cart-count hidden>0</span> <span aria-hidden="true">🛒</span></a>
    </div>
</header>
@if(! $is_open)
<section class="so-state-banner" role="status"><strong>{{ $closed_message }}</strong><span>{{ $locale === 'ar' ? 'يمكنك تصفح القائمة الآن والطلب عند إعادة الفتح.' : 'You can still browse the menu and order when we reopen.' }}</span></section>
@endif
<main id="menu" tabindex="-1">@include($theme['view'])</main>
<nav class="so-mobile-cart" id="cart" aria-label="{{ $locale === 'ar' ? 'السلة' : 'Cart' }}">
    <button type="button" data-view-cart @disabled(! $is_open)><span>{{ $locale === 'ar' ? 'عرض السلة' : 'View cart' }}</span><strong data-cart-mobile-total></strong><span aria-hidden="true">→</span></button>
</nav>
<dialog class="so-item-dialog" data-item-dialog aria-labelledby="so-item-dialog-title" aria-describedby="so-item-dialog-description">
    <form method="dialog" class="so-dialog-shell">
        <button class="so-dialog-close" value="close" aria-label="{{ $locale === 'ar' ? 'إغلاق' : 'Close' }}">×</button>
        <img data-dialog-image alt="" loading="lazy">
        <h2 id="so-item-dialog-title" data-dialog-name></h2>
        <p id="so-item-dialog-description" data-dialog-description></p>
        <div data-dialog-modifiers></div>
        <label class="so-cart-field"><span>{{ $locale === 'ar' ? 'الكمية' : 'Quantity' }}</span><input type="number" min="1" max="999" step="1" value="1" inputmode="numeric" data-dialog-quantity></label>
        <p class="so-cart-error" data-item-errors role="alert" hidden></p>
        <p class="so-cart-status" data-item-status aria-live="polite"></p>
        <button class="so-primary-action" type="button" data-add-cart @disabled(! $is_open)>{{ $locale === 'ar' ? 'إضافة إلى السلة' : 'Add to cart' }}</button>
    </form>
</dialog>
<dialog class="so-cart-dialog" data-cart-dialog aria-labelledby="so-cart-title">
    <div class="so-dialog-shell so-cart-shell">
        <button class="so-dialog-close" type="button" data-cart-close aria-label="{{ $locale === 'ar' ? 'إغلاق' : 'Close' }}">×</button>
        <h2 id="so-cart-title">{{ $locale === 'ar' ? 'سلتك' : 'Your cart' }}</h2>
        <p class="so-cart-status" data-cart-status role="status" aria-live="polite"></p>
        <div class="so-cart-lines" data-cart-lines></div>
        <div class="so-empty" data-cart-empty hidden>{{ $locale === 'ar' ? 'سلتك فارغة. أضف صنفاً للمتابعة.' : 'Your cart is empty. Add an item to continue.' }}</div>
        <div class="so-cart-summary" data-cart-summary hidden>
            <span>{{ $locale === 'ar' ? 'الإجمالي' : 'Total' }}</span>
            <strong data-cart-total></strong>
        </div>
        <button class="so-primary-action" type="button" data-checkout-toggle hidden>{{ $locale === 'ar' ? 'متابعة إتمام الطلب' : 'Continue to checkout' }}</button>
        <section class="so-checkout-panel" data-checkout-panel hidden aria-labelledby="so-checkout-title">
            <h3 id="so-checkout-title">{{ $locale === 'ar' ? 'إتمام الطلب' : 'Checkout' }}</h3>
            <form class="so-checkout-form" data-checkout-form>
                <label class="so-cart-field"><span>{{ $locale === 'ar' ? 'طريقة الاستلام' : 'Fulfillment' }}</span><select name="fulfillment_mode" required data-fulfillment-mode>
                    @foreach(($commerce['fulfillment_modes'] ?? []) as $mode)
                        <option value="{{ $mode }}">{{ $locale === 'ar' ? match($mode) {'pickup' => 'استلام من الفرع', 'delivery' => 'توصيل', 'dine_in' => 'داخل المطعم', default => $mode} : match($mode) {'pickup' => 'Pickup', 'delivery' => 'Delivery', 'dine_in' => 'Dine in', default => $mode} }}</option>
                    @endforeach
                </select></label>
                <label class="so-cart-field"><span>{{ $locale === 'ar' ? 'الاسم' : 'Name' }}</span><input name="name" autocomplete="name" required maxlength="160"></label>
                <label class="so-cart-field"><span>{{ $locale === 'ar' ? 'الهاتف' : 'Phone' }}</span><input name="phone" type="tel" autocomplete="tel" required maxlength="60"></label>
                <label class="so-cart-field"><span>{{ $locale === 'ar' ? 'البريد الإلكتروني (اختياري)' : 'Email (optional)' }}</span><input name="email" type="email" autocomplete="email" maxlength="190"></label>
                <div data-delivery-fields hidden>
                    <label class="so-cart-field"><span>{{ $locale === 'ar' ? 'عنوان التوصيل' : 'Delivery address' }}</span><textarea name="delivery_address" maxlength="1000"></textarea></label>
                    <button type="button" class="so-secondary-action" data-use-location>{{ $locale === 'ar' ? 'استخدام موقعي الحالي' : 'Use my current location' }}</button>
                    <p class="so-cart-status" data-location-status aria-live="polite"></p>
                </div>
                <p class="so-cart-hint" data-dinein-hint hidden>{{ $locale === 'ar' ? 'للطلب داخل المطعم افتح القائمة من رمز QR الخاص بالطاولة.' : 'For dine-in, open this menu from the table QR code.' }}</p>
                <label class="so-cart-field"><span>{{ $locale === 'ar' ? 'ملاحظة للطلب (اختياري)' : 'Order note (optional)' }}</span><textarea name="order_note" maxlength="1000"></textarea></label>
                <p class="so-cart-error" data-checkout-error role="alert" hidden></p>
                <button class="so-primary-action" type="submit" data-place-order>{{ $locale === 'ar' ? 'تأكيد الطلب' : 'Place order' }}</button>
            </form>
        </section>
        <section class="so-order-confirmation" data-order-confirmation hidden tabindex="-1">
            <h3>{{ $locale === 'ar' ? 'تم استلام طلبك' : 'Order received' }}</h3>
            <p data-order-reference></p>
        </section>
    </div>
</dialog>
<script type="application/json" data-cart-copy>@json([
    'loading' => $locale === 'ar' ? 'جارٍ تحميل السلة…' : 'Loading cart…',
    'adding' => $locale === 'ar' ? 'جارٍ الإضافة…' : 'Adding…',
    'added' => $locale === 'ar' ? 'تمت الإضافة إلى السلة.' : 'Added to cart.',
    'updating' => $locale === 'ar' ? 'جارٍ تحديث السلة…' : 'Updating cart…',
    'removed' => $locale === 'ar' ? 'تم حذف الصنف.' : 'Item removed.',
    'error' => $locale === 'ar' ? 'تعذر إكمال العملية. حاول مرة أخرى.' : 'We could not complete that action. Try again.',
    'requiredModifier' => $locale === 'ar' ? 'اختر الخيار المطلوب.' : 'Choose the required option.',
    'modifierRange' => $locale === 'ar' ? 'راجع عدد الخيارات المحددة.' : 'Review the number of selected options.',
    'quantity' => $locale === 'ar' ? 'الكمية' : 'Quantity',
    'remove' => $locale === 'ar' ? 'حذف' : 'Remove',
    'line' => $locale === 'ar' ? 'صنف' : 'Item',
    'locationRequest' => $locale === 'ar' ? 'جارٍ تحديد موقعك…' : 'Getting your location…',
    'locationReady' => $locale === 'ar' ? 'تم تحديد موقع التوصيل.' : 'Delivery location ready.',
    'locationError' => $locale === 'ar' ? 'تعذر تحديد موقعك. فعّل صلاحية الموقع وحاول مرة أخرى.' : 'We could not get your location. Enable location access and try again.',
    'deliveryLocation' => $locale === 'ar' ? 'حدد موقع التوصيل قبل تأكيد الطلب.' : 'Set your delivery location before placing the order.',
    'tableContext' => $locale === 'ar' ? 'أعد فتح القائمة من رمز QR الخاص بالطاولة للطلب داخل المطعم.' : 'Reopen the menu from the table QR code to place a dine-in order.',
    'placing' => $locale === 'ar' ? 'جارٍ تأكيد الطلب…' : 'Placing order…',
    'orderPlaced' => $locale === 'ar' ? 'رقم الطلب: :reference' : 'Order reference: :reference',
    'cartUnavailable' => $locale === 'ar' ? 'انتهت جلسة السلة. سننشئ سلة جديدة عند الإضافة التالية.' : 'This cart session expired. A new cart will be created on your next add.',
], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)</script>
<script src="/js/smart-ordering-storefront.js" defer></script>
</body>
</html>
