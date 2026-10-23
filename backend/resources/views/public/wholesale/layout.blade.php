<!doctype html>
<html lang="{{ app()->getLocale() }}" dir="{{ app()->getLocale()==='ar' ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <meta name="theme-color" content="#00452F">
    <title>@yield('title', 'FOODEX Wholesale')</title>
    <meta name="description" content="@yield('meta_description', app()->getLocale()==='ar' ? 'فوديكس لتوريد الأغذية المجمدة بالجملة للمؤسسات والمطاعم والفنادق.' : 'FOODEX wholesale frozen-food supply for businesses, restaurants and hotels.')">
    <link rel="stylesheet" href="{{ asset('assets/wholesale/foodex-wholesale.css') }}">
</head>
<body>
<header class="fx-header">
    <div class="fx-shell fx-header__row">
        <a class="fx-brand" href="{{ route('public.wholesale.home') }}" aria-label="FOODEX">
            @if(!empty($store['logo_url']))
                <img src="{{ $store['logo_url'] }}" alt="FOODEX">
            @else
                <span class="fx-brand__mark" aria-hidden="true">✦</span>
            @endif
            <span>Foodex</span>
        </a>
        <nav class="fx-nav" aria-label="{{ app()->getLocale()==='ar'?'التنقل الرئيسي':'Primary navigation' }}">
            <a href="{{ route('public.wholesale.home') }}">{{ app()->getLocale()==='ar'?'الرئيسية':'Home' }}</a>
            <a href="{{ route('public.wholesale.home') }}#categories">{{ app()->getLocale()==='ar'?'الأقسام':'Categories' }}</a>
            <a href="{{ route('public.wholesale.brands') }}">{{ app()->getLocale()==='ar'?'العلامات التجارية':'Brands' }}</a>
            <a href="{{ route('public.wholesale.home') }}#products">{{ app()->getLocale()==='ar'?'المنتجات':'Products' }}</a>
        </nav>
        <div class="fx-header__actions">
            <a class="fx-link-button" href="{{ $adminUrl }}">{{ app()->getLocale()==='ar'?'دخول الإدارة':'Management login' }}</a>
            <a class="fx-primary-button" href="{{ $appUrl }}">{{ app()->getLocale()==='ar'?'تحميل التطبيق':'Get the app' }}</a>
        </div>
        <button class="fx-menu-button" type="button" data-menu-toggle aria-label="{{ app()->getLocale()==='ar'?'فتح القائمة':'Open menu' }}">☰</button>
    </div>
    <div class="fx-mobile-nav" data-mobile-nav hidden>
        <a href="{{ route('public.wholesale.home') }}">{{ app()->getLocale()==='ar'?'الرئيسية':'Home' }}</a>
        <a href="{{ route('public.wholesale.home') }}#categories">{{ app()->getLocale()==='ar'?'الأقسام':'Categories' }}</a>
        <a href="{{ route('public.wholesale.brands') }}">{{ app()->getLocale()==='ar'?'العلامات التجارية':'Brands' }}</a>
        <a href="{{ route('public.wholesale.home') }}#products">{{ app()->getLocale()==='ar'?'المنتجات':'Products' }}</a>
        <a href="{{ $adminUrl }}">{{ app()->getLocale()==='ar'?'دخول الإدارة':'Management login' }}</a>
    </div>
</header>

<main>
    @yield('content')
</main>

<footer class="fx-footer">
    <div class="fx-shell fx-footer__grid">
        <div>
            <div class="fx-brand fx-brand--footer"><span class="fx-brand__mark" aria-hidden="true">✦</span><span>Foodex</span></div>
            <p>{{ app()->getLocale()==='ar'?'توريد مجمدات بالجملة بجودة موثوقة للأعمال.':'Reliable wholesale frozen-food supply for businesses.' }}</p>
        </div>
        <div>
            <strong>{{ app()->getLocale()==='ar'?'روابط سريعة':'Quick links' }}</strong>
            <a href="{{ route('public.wholesale.brands') }}">{{ app()->getLocale()==='ar'?'العلامات التجارية':'Brands' }}</a>
            <a href="{{ route('public.privacy') }}">{{ app()->getLocale()==='ar'?'الخصوصية':'Privacy' }}</a>
            <a href="{{ route('public.terms') }}">{{ app()->getLocale()==='ar'?'الشروط':'Terms' }}</a>
        </div>
        <div>
            <strong>{{ app()->getLocale()==='ar'?'للتواصل':'Connect' }}</strong>
            @if(!empty($branding['facebook_url']))
                <a href="{{ $branding['facebook_url'] }}" target="_blank" rel="noopener">Facebook</a>
            @endif
            <a href="{{ route('public.support') }}">{{ app()->getLocale()==='ar'?'الدعم':'Support' }}</a>
        </div>
        <div>
            <strong>{{ app()->getLocale()==='ar'?'الأسعار والشراء':'Prices & ordering' }}</strong>
            <p>{{ app()->getLocale()==='ar'?'الأسعار والطلبات متاحة حصرياً من خلال تطبيق فوديكس.':'Prices and ordering are available exclusively in the FOODEX app.' }}</p>
            <a class="fx-primary-button" href="{{ $appUrl }}">{{ app()->getLocale()==='ar'?'فتح/تحميل التطبيق':'Open / get app' }}</a>
        </div>
    </div>
</footer>
<script src="{{ asset('assets/wholesale/foodex-wholesale.js') }}" defer></script>
</body>
</html>
