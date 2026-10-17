@php
    $templateVariant = $templateVariant ?? 'modern-minimal';
    $templateLayout = $templateLayout ?? 'minimal-list';
    $featuredItems = collect($menu['categories'] ?? [])
        ->flatMap(fn (array $category) => $category['items'] ?? [])
        ->filter(fn (array $item) => ($item['featured'] ?? false) || ($item['best_seller'] ?? false))
        ->take(4);
@endphp

<section class="rm-template rm-template--{{ $templateLayout }}" data-rm-template="{{ $templateVariant }}">
    <section class="rm-hero">
        <div class="rm-hero__copy">
            <p class="rm-eyebrow">{{ $site['tagline'] }}</p>
            <h1>{{ $site['name'] }}</h1>
            @if($site['description'] !== '')<p class="rm-lead">{{ $site['description'] }}</p>@endif
            <div class="rm-hero__actions">
                <a href="#rm-menu">{{ $locale === 'ar' ? 'استكشف القائمة' : 'Explore menu' }}</a>
                @if($site['whatsapp_url'])<a href="{{ $site['whatsapp_url'] }}" rel="nofollow noopener">{{ $locale === 'ar' ? 'تواصل معنا' : 'Contact us' }}</a>@endif
            </div>
        </div>
        @if($site['cover_url'])
            <figure class="rm-hero__media"><img src="{{ $site['cover_url'] }}" alt="" fetchpriority="high" decoding="async"></figure>
        @endif
    </section>

    <div class="rm-menu-tools" id="rm-menu">
        <label class="rm-search">
            <span class="sr-only">{{ $locale === 'ar' ? 'البحث في القائمة' : 'Search menu' }}</span>
            <input type="search" data-menu-search placeholder="{{ $locale === 'ar' ? 'ابحث عن طبق' : 'Find a dish' }}">
        </label>
        <nav class="rm-category-nav" aria-label="{{ $locale === 'ar' ? 'فئات القائمة' : 'Menu categories' }}">
            @foreach($menu['categories'] as $category)
                <a href="#rm-{{ $templateVariant }}-{{ $category['public_key'] ?: $loop->index }}">{{ $category['name'] }}</a>
            @endforeach
        </nav>
    </div>

    @if($featuredItems->isNotEmpty())
        <section class="rm-featured" aria-labelledby="rm-featured-title">
            <header>
                <p class="rm-eyebrow">{{ $locale === 'ar' ? 'اختيارات مميزة' : 'Featured' }}</p>
                <h2 id="rm-featured-title">{{ $locale === 'ar' ? 'من توقيع المطعم' : 'Restaurant signatures' }}</h2>
            </header>
            <div class="rm-featured__grid">
                @foreach($featuredItems as $item)
                    <article class="rm-featured-card">
                        @if($item['image_url'])<img src="{{ $item['image_url'] }}" alt="" loading="lazy" decoding="async">@endif
                        <div>
                            <h3>{{ $item['name'] }}</h3>
                            <p>{{ $item['description'] }}</p>
                            <strong>{{ $item['price_display'] }}</strong>
                        </div>
                    </article>
                @endforeach
            </div>
        </section>
    @endif

    <div class="rm-sections">
        @forelse($menu['categories'] as $category)
            <section class="rm-section" id="rm-{{ $templateVariant }}-{{ $category['public_key'] ?: $loop->index }}" data-category>
                <header class="rm-section__header">
                    <span class="rm-section__index">{{ str_pad((string) $loop->iteration, 2, '0', STR_PAD_LEFT) }}</span>
                    <div>
                        <h2>{{ $category['name'] }}</h2>
                        @if($category['description'] !== '')<p>{{ $category['description'] }}</p>@endif
                    </div>
                </header>

                <div class="rm-dish-list">
                    @forelse($category['items'] as $item)
                        <article class="rm-dish {{ $item['sold_out'] ? 'is-sold-out' : '' }}" data-menu-item data-search="{{ mb_strtolower($item['name'].' '.$item['description']) }}">
                            @if($item['image_url'])<img class="rm-dish__media" src="{{ $item['image_url'] }}" alt="" loading="lazy" decoding="async">@endif
                            <div class="rm-dish__body">
                                <div class="rm-dish__heading">
                                    <h3>{{ $item['name'] }}</h3>
                                    <strong>{{ $item['price_display'] }}</strong>
                                </div>
                                @if($item['description'] !== '')<p>{{ $item['description'] }}</p>@endif
                                @if(count($item['tags']))
                                    <ul class="rm-tags" aria-label="{{ $locale === 'ar' ? 'معلومات الطبق' : 'Dish information' }}">
                                        @foreach($item['tags'] as $tag)<li>{{ $tag }}</li>@endforeach
                                    </ul>
                                @endif
                                <div class="rm-dish__actions">
                                    @if($item['sold_out'])<span class="rm-state">{{ $locale === 'ar' ? 'غير متاح حاليًا' : 'Currently unavailable' }}</span>@endif
                                    <button type="button"
                                        data-open-item
                                        data-item-id="{{ $item['public_key'] }}"
                                        data-name="{{ $item['name'] }}"
                                        data-description="{{ $item['description'] }}"
                                        data-image="{{ $item['image_url'] }}"
                                        data-modifiers='@json($item["modifiers"], JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_TAG | JSON_HEX_QUOT)'
                                        @disabled($item['sold_out'] || ! $is_open)>
                                        {{ $item['sold_out'] ? ($locale === 'ar' ? 'نفد' : 'Sold out') : ($locale === 'ar' ? 'اختيار' : 'Choose') }}
                                    </button>
                                </div>
                            </div>
                        </article>
                    @empty
                        <p class="rm-empty">{{ $locale === 'ar' ? 'لا توجد أطباق متاحة في هذا القسم.' : 'No dishes are available in this section.' }}</p>
                    @endforelse
                </div>
            </section>
        @empty
            <section class="rm-empty rm-empty--menu" role="status">
                <h2>{{ $locale === 'ar' ? 'القائمة قيد التجهيز' : 'Menu coming soon' }}</h2>
                <p>{{ $locale === 'ar' ? 'لم يتم نشر أطباق متاحة بعد.' : 'No available dishes have been published yet.' }}</p>
            </section>
        @endforelse
    </div>

    @if($appearance['customization']['show_about'] && $site['about'])
        <section class="rm-about">
            <p class="rm-eyebrow">{{ $locale === 'ar' ? 'قصتنا' : 'Our story' }}</p>
            <h2>{{ $site['name'] }}</h2>
            <p>{{ $site['about'] }}</p>
        </section>
    @endif

    @if($appearance['customization']['show_branches'] && count($site['branches']))
        <section class="rm-branches">
            <header><p class="rm-eyebrow">{{ $locale === 'ar' ? 'الموقع وساعات العمل' : 'Visit us' }}</p><h2>{{ $locale === 'ar' ? 'فروعنا' : 'Locations' }}</h2></header>
            <div class="rm-branches__grid">
                @foreach($site['branches'] as $branch)
                    <article>
                        <h3>{{ $branch['name'] }}</h3>
                        <p>{{ $branch['address'] }}</p>
                        <span>{{ $branch['hours'] }}</span>
                        @if($branch['map_url'])<a href="{{ $branch['map_url'] }}" rel="noopener">{{ $locale === 'ar' ? 'عرض الخريطة' : 'View map' }}</a>@endif
                    </article>
                @endforeach
            </div>
        </section>
    @endif
</section>
