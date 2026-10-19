@php
    $allItems = collect($menu['categories'] ?? [])
        ->flatMap(fn (array $category) => collect($category['items'] ?? [])->map(fn (array $item) => [
            ...$item,
            '_category_name' => $category['name'] ?? '',
        ]));

    $featuredItems = $allItems
        ->filter(fn (array $item) => ($item['featured'] ?? false) || ($item['best_seller'] ?? false) || ($item['new'] ?? false))
        ->take(8);

    if ($featuredItems->isEmpty()) {
        $featuredItems = $allItems->take(8);
    }

    $heroImage = $site['cover_url'];
    if ($heroImage === '/images/smart-ordering/menu-media-placeholder.svg') {
        $heroImage = '/demo/banners/fresh-market.svg';
    }

    $categoryFallbacks = ['🧺', '🥬', '🍎', '🥩', '🥛', '🥫', '🥖', '🧃'];
@endphp

<section class="fm-template" data-fresh-market-template>
    <section class="fm-hero" aria-labelledby="fm-hero-title">
        <div class="fm-hero__copy">
            <span class="fm-hero__restaurant">{{ $site['name'] }}</span>
            <h1 id="fm-hero-title">{{ $site['tagline'] !== '' ? $site['tagline'] : ($locale === 'ar' ? 'شريكك للنجاح في أعمالك' : 'Your partner for business success') }}</h1>
            <p>{{ $site['description'] !== '' ? $site['description'] : ($locale === 'ar' ? 'نوفر منتجات غذائية عالية الجودة لمطعمك بكل سهولة وثقة' : 'Quality food products for your business, delivered with ease and confidence') }}</p>
            <div class="fm-benefits" aria-label="{{ $locale === 'ar' ? 'مزايا المتجر' : 'Store benefits' }}">
                <span><b aria-hidden="true">⬟</b>{{ $locale === 'ar' ? 'أسعار منافسة' : 'Competitive prices' }}</span>
                <span><b aria-hidden="true">🚚</b>{{ $locale === 'ar' ? 'توريد سريع' : 'Fast supply' }}</span>
                <span><b aria-hidden="true">🌿</b>{{ $locale === 'ar' ? 'جودة عالية' : 'High quality' }}</span>
            </div>
            <a class="fm-hero__cta" href="#fm-products">
                <span>{{ $locale === 'ar' ? 'استكشف القائمة' : 'Explore products' }}</span>
                <b aria-hidden="true">→</b>
            </a>
        </div>
        <figure class="fm-hero__media">
            @if($heroImage)
                <img src="{{ $heroImage }}" alt="" fetchpriority="high" decoding="async">
            @endif
        </figure>
    </section>

    <section class="fm-tools">
        <label class="so-search fm-search">
            <span class="fm-search__icon" aria-hidden="true">⌕</span>
            <span class="so-visually-hidden">{{ $locale === 'ar' ? 'البحث عن منتج' : 'Search products' }}</span>
            <input type="search" data-menu-search placeholder="{{ $locale === 'ar' ? 'إبحث عن منتج...' : 'Search for a product...' }}">
        </label>

        <nav class="fm-categories" id="fm-categories" aria-label="{{ $locale === 'ar' ? 'تصنيفات المنتجات' : 'Product categories' }}">
            <a class="fm-category-card is-active" href="#fm-products">
                <span class="fm-category-icon fm-category-icon--grid" aria-hidden="true">⌗</span>
                <strong>{{ $locale === 'ar' ? 'جميع المنتجات' : 'All products' }}</strong>
            </a>
            @foreach($menu['categories'] as $category)
                <a class="fm-category-card" href="#fm-category-{{ $category['public_key'] ?: $loop->index }}">
                    @if($category['image_url'] && $category['image_url'] !== '/images/smart-ordering/menu-media-placeholder.svg')
                        <img src="{{ $category['image_url'] }}" alt="" loading="lazy" decoding="async">
                    @else
                        <span class="fm-category-icon" aria-hidden="true">{{ $categoryFallbacks[($loop->index - 1) % count($categoryFallbacks)] }}</span>
                    @endif
                    <strong>{{ $category['name'] }}</strong>
                </a>
            @endforeach
        </nav>
    </section>

    <section class="fm-featured" id="fm-products" aria-labelledby="fm-featured-title">
        <header class="fm-section-head">
            <h2 id="fm-featured-title">{{ $locale === 'ar' ? 'المنتجات المميزة' : 'Featured products' }}</h2>
            <a href="#fm-all-products">{{ $locale === 'ar' ? 'عرض الكل' : 'View all' }} <span aria-hidden="true">‹</span></a>
        </header>

        <div class="fm-product-grid">
            @foreach($featuredItems as $item)
                @include('public.smart-ordering.templates.partials.fresh-market-product-card', ['item' => $item])
            @endforeach
        </div>
    </section>

    <div class="fm-all-products" id="fm-all-products">
        @foreach($menu['categories'] as $category)
            <section class="fm-product-section" id="fm-category-{{ $category['public_key'] ?: $loop->index }}" data-category>
                <header class="fm-section-head">
                    <div>
                        <h2>{{ $category['name'] }}</h2>
                        @if($category['description'] !== '')<p>{{ $category['description'] }}</p>@endif
                    </div>
                    <span>{{ count($category['items']) }} {{ $locale === 'ar' ? 'منتج' : 'products' }}</span>
                </header>

                <div class="fm-product-grid">
                    @forelse($category['items'] as $item)
                        @include('public.smart-ordering.templates.partials.fresh-market-product-card', ['item' => $item, 'searchable' => true])
                    @empty
                        <p class="fm-empty">{{ $locale === 'ar' ? 'لا توجد منتجات متاحة في هذا القسم.' : 'No products are available in this category.' }}</p>
                    @endforelse
                </div>
            </section>
        @endforeach
    </div>
</section>
