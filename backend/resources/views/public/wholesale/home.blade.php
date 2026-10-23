@extends('public.wholesale.layout')

@section('title', app()->getLocale()==='ar' ? 'فوديكس | متجر الجملة للمجمدات' : 'FOODEX | Wholesale Frozen Foods')

@section('content')
@php
    $ar = app()->getLocale()==='ar';
    $heroTitle = $ar
        ? ($branding['brand_title_ar'] ?? 'فوديكس للجملة')
        : ($branding['brand_title_en'] ?? 'FOODEX Wholesale');
    $heroSubtitle = $ar
        ? ($branding['brand_subtitle_ar'] ?? 'مجمدات عالية الجودة لأعمال الجملة')
        : ($branding['brand_subtitle_en'] ?? 'Quality frozen foods for wholesale businesses');
    $heroCta = $ar
        ? ($branding['hero_cta_ar'] ?? 'تصفح المنتجات')
        : ($branding['hero_cta_en'] ?? 'Browse products');
    $productTypes = ['featured_products','best_sellers','offers','product_grid','product_carousel','reorder'];
@endphp

@foreach($sections as $section)
    @if($section['type']==='hero')
        @php($heroBanner = $banners[0] ?? null)
        <section class="fx-hero" data-reveal>
            @if(!empty($heroBanner['image_url']))
                <img class="fx-hero__media" src="{{ $heroBanner['image_url'] }}" alt="{{ $heroBanner['title'] }}">
            @endif
            <div class="fx-hero__shade"></div>
            <div class="fx-shell fx-hero__content">
                <span class="fx-kicker">{{ $ar?'توريد مجمدات بالجملة':'Wholesale frozen-food supply' }}</span>
                <h1>{{ $heroTitle }}</h1>
                <p>{{ $heroSubtitle }}</p>
                <div class="fx-hero__actions">
                    <a class="fx-primary-button fx-primary-button--lime" href="#products">{{ $heroCta }}</a>
                    <a class="fx-ghost-button" href="{{ $appUrl }}">{{ $ar?'اعرف الأسعار واطلب من التطبيق':'See prices & order in app' }}</a>
                </div>
                <div class="fx-hero__note"><span>🔒</span>{{ $ar?'الأسعار والشراء غير معروضين على الموقع العام':'Prices and purchasing are intentionally hidden on the public website' }}</div>
            </div>
        </section>

    @elseif($section['type']==='benefits')
        <section class="fx-section fx-section--tight" data-reveal>
            <div class="fx-shell fx-benefits">
                <div class="fx-benefit"><span>❄</span><div><strong>{{ $ar?'سلسلة تبريد موثوقة':'Reliable cold chain' }}</strong><small>{{ $ar?'للحفاظ على جودة المجمدات':'Protecting frozen-food quality' }}</small></div></div>
                <div class="fx-benefit"><span>✓</span><div><strong>{{ $ar?'جودة معتمدة':'Quality focused' }}</strong><small>{{ $ar?'منتجات مختارة للأعمال':'Business-ready selection' }}</small></div></div>
                <div class="fx-benefit"><span>🚚</span><div><strong>{{ $ar?'توريد للأعمال':'Business supply' }}</strong><small>{{ $ar?'مطاعم وفنادق ومؤسسات':'Restaurants, hotels & institutions' }}</small></div></div>
                <div class="fx-benefit"><span>📦</span><div><strong>{{ $ar?'تشكيلة جملة':'Wholesale assortment' }}</strong><small>{{ $ar?'دواجن ولحوم وأسماك وأكثر':'Poultry, meat, seafood & more' }}</small></div></div>
            </div>
        </section>

    @elseif($section['type']==='banner_slider' && count($banners)>0)
        <section class="fx-section" data-reveal>
            <div class="fx-shell">
                <div class="fx-slider" data-slider>
                    @foreach($banners as $index=>$banner)
                        <a class="fx-slide @if($index===0) is-active @endif" href="{{ $banner['target_url'] ?: '#products' }}" data-slide>
                            @if(!empty($banner['image_url']))<img src="{{ $banner['image_url'] }}" alt="{{ $banner['title'] }}" loading="{{ $index===0?'eager':'lazy' }}">@endif
                            <span>{{ $banner['title'] }}</span>
                        </a>
                    @endforeach
                    @if(count($banners)>1)
                        <button type="button" class="fx-slider__prev" data-slider-prev aria-label="{{ $ar?'السابق':'Previous' }}">‹</button>
                        <button type="button" class="fx-slider__next" data-slider-next aria-label="{{ $ar?'التالي':'Next' }}">›</button>
                    @endif
                </div>
            </div>
        </section>

    @elseif($section['type']==='categories')
        <section class="fx-section" id="categories" data-reveal>
            <div class="fx-shell">
                <div class="fx-section-head">
                    <div><span class="fx-kicker">{{ $ar?'استكشف':'Explore' }}</span><h2>{{ $section['title'] ?: ($ar?'الأقسام الرئيسية':'Main categories') }}</h2></div>
                    @if($activeCategory>0)<a href="{{ route('public.wholesale.home') }}#products">{{ $ar?'إلغاء التصفية':'Clear filter' }}</a>@endif
                </div>
                <div class="fx-category-row">
                    @forelse($categories as $category)
                        <a class="fx-category-card @if($activeCategory===$category['id']) is-selected @endif" href="{{ route('public.wholesale.home',['category'=>$category['id']]) }}#products">
                            <span class="fx-category-card__image">
                                @if($category['image_url'])<img src="{{ $category['image_url'] }}" alt="{{ $category['name'] }}" loading="lazy">@else<span>❄</span>@endif
                            </span>
                            <strong>{{ $category['name'] }}</strong>
                        </a>
                    @empty
                        <p class="fx-empty">{{ $ar?'لا توجد أقسام متاحة حالياً.':'No categories are currently available.' }}</p>
                    @endforelse
                </div>
            </div>
        </section>

    @elseif($section['type']==='brands')
        <section class="fx-section fx-section--mint" id="brands" data-reveal>
            <div class="fx-shell">
                <div class="fx-section-head">
                    <div><span class="fx-kicker">{{ $ar?'شركاؤنا':'Our partners' }}</span><h2>{{ $section['title'] ?: ($ar?'العلامات التجارية':'Brands') }}</h2></div>
                    <a href="{{ route('public.wholesale.brands') }}">{{ $ar?'عرض كل العلامات':'View all brands' }}</a>
                </div>
                <div class="fx-brand-row">
                    @forelse($brands as $brand)
                        <a class="fx-brand-card @if($activeBrand===$brand['id']) is-selected @endif" href="{{ route('public.wholesale.home',['brand'=>$brand['id']]) }}#products">
                            @if($brand['image_url'])<img src="{{ $brand['image_url'] }}" alt="{{ $brand['name'] }}" loading="lazy">@else<span class="fx-brand-fallback">{{ mb_substr($brand['name'],0,1) }}</span>@endif
                            <strong>{{ $brand['name'] }}</strong>
                        </a>
                    @empty
                        <p class="fx-empty">{{ $ar?'لا توجد علامات مرتبطة بالمنتجات حالياً.':'No brands are currently linked to products.' }}</p>
                    @endforelse
                </div>
            </div>
        </section>

    @elseif(in_array($section['type'],$productTypes,true))
        @php($limit = max(4,min(30,(int)($section['config']['limit'] ?? 10))))
        <section class="fx-section" id="products" data-reveal>
            <div class="fx-shell">
                <div class="fx-section-head">
                    <div>
                        <span class="fx-kicker">{{ $ar?'الكتالوج':'Catalog' }}</span>
                        <h2>{{ $section['title'] ?: ($ar?'منتجاتنا':'Our products') }}</h2>
                    </div>
                    @if($activeBrand>0 || $activeCategory>0)<a href="{{ route('public.wholesale.home') }}#products">{{ $ar?'عرض كل المنتجات':'Show all products' }}</a>@endif
                </div>
                <div class="fx-products-grid">
                    @forelse(array_slice($products,0,$limit) as $item)
                        @include('public.wholesale._product-card',['item'=>$item])
                    @empty
                        <p class="fx-empty">{{ $ar?'لا توجد منتجات مطابقة حالياً.':'No matching products are currently available.' }}</p>
                    @endforelse
                </div>
                <div class="fx-app-lock">
                    <div><span>🔒</span><strong>{{ $ar?'الأسعار والطلب داخل تطبيق فوديكس':'Prices and ordering live in the FOODEX app' }}</strong><p>{{ $ar?'الموقع للتصفح والتعرف على المنتجات. سجّل أو افتح التطبيق لمعرفة أسعار حسابك وإتمام الطلب.':'Use the website to browse the catalog. Open the app to see account pricing and place orders.' }}</p></div>
                    <a class="fx-primary-button" href="{{ $appUrl }}">{{ $ar?'تحميل التطبيق':'Get the app' }}</a>
                </div>
            </div>
        </section>

    @elseif($section['type']==='social')
        @if(!empty($branding['facebook_url']))
            <section class="fx-section" data-reveal>
                <div class="fx-shell">
                    <div class="fx-social-card">
                        <div>
                            <span class="fx-kicker">Facebook</span>
                            <h2>{{ $ar?($branding['facebook_title_ar'] ?? 'تابع فوديكس على فيسبوك'):($branding['facebook_title_en'] ?? 'Follow FOODEX on Facebook') }}</h2>
                            <p>{{ $ar?($branding['facebook_text_ar'] ?? 'تابع أحدث المنتجات والعروض وأخبار التوريد.'):($branding['facebook_text_en'] ?? 'See the latest products, offers and supply updates.') }}</p>
                        </div>
                        <a class="fx-primary-button fx-primary-button--lime" href="{{ $branding['facebook_url'] }}" target="_blank" rel="noopener">{{ $ar?'زيارة صفحتنا':'Visit our page' }}</a>
                    </div>
                </div>
            </section>
        @endif
    @endif
@endforeach
@endsection
