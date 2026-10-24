@extends('public.wholesale.layout')

@section('title', $pageTitle ?? __('wholesale_public.page_title'))
@section('meta_description', $metaDescription ?? __('wholesale_public.meta_description'))

@section('content')
@php
    $locale = app()->getLocale();
    $localizedSuffix = $locale === 'ar' ? 'ar' : 'en';
    $heroTitle = (string)($branding['hero_title_'.$localizedSuffix] ?? '');
    $heroSubtitle = (string)($branding['hero_subtitle_'.$localizedSuffix] ?? '');
    $heroCta = (string)($branding['hero_cta_'.$localizedSuffix] ?? '');
    $facebookTitle = (string)($branding['facebook_title_'.$localizedSuffix] ?? '');
    $facebookText = (string)($branding['facebook_text_'.$localizedSuffix] ?? '');
    $heroTitle = $heroTitle !== '' ? $heroTitle : __('wholesale_public.hero.title');
    $heroSubtitle = $heroSubtitle !== '' ? $heroSubtitle : __('wholesale_public.hero.subtitle');
    $heroCta = $heroCta !== '' ? $heroCta : __('wholesale_public.hero.cta');
    $facebookTitle = $facebookTitle !== '' ? $facebookTitle : __('wholesale_public.facebook.title');
    $facebookText = $facebookText !== '' ? $facebookText : __('wholesale_public.facebook.body');
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
                <span class="fx-kicker">{{ __('wholesale_public.hero.kicker') }}</span>
                <h1>{{ $heroTitle }}</h1>
                <p>{{ $heroSubtitle }}</p>
                <div class="fx-hero__actions">
                    <a class="fx-primary-button fx-primary-button--lime" href="#products">{{ $heroCta }}</a>
                    <a class="fx-ghost-button" href="{{ $appUrl }}">{{ __('wholesale_public.hero.app_cta') }}</a>
                </div>
                <div class="fx-hero__note"><span aria-hidden="true">🔒</span>{{ __('wholesale_public.hero.price_note') }}</div>
            </div>
        </section>

    @elseif($section['type']==='benefits')
        <section class="fx-section fx-section--tight" data-reveal>
            <div class="fx-shell fx-benefits">
                <div class="fx-benefit"><span aria-hidden="true">❄</span><div><strong>{{ __('wholesale_public.benefits.cold_chain_title') }}</strong><small>{{ __('wholesale_public.benefits.cold_chain_body') }}</small></div></div>
                <div class="fx-benefit"><span aria-hidden="true">✓</span><div><strong>{{ __('wholesale_public.benefits.quality_title') }}</strong><small>{{ __('wholesale_public.benefits.quality_body') }}</small></div></div>
                <div class="fx-benefit"><span aria-hidden="true">🚚</span><div><strong>{{ __('wholesale_public.benefits.business_title') }}</strong><small>{{ __('wholesale_public.benefits.business_body') }}</small></div></div>
                <div class="fx-benefit"><span aria-hidden="true">📦</span><div><strong>{{ __('wholesale_public.benefits.assortment_title') }}</strong><small>{{ __('wholesale_public.benefits.assortment_body') }}</small></div></div>
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
                        <button type="button" class="fx-slider__prev" data-slider-prev aria-label="{{ __('wholesale_public.common.previous') }}">‹</button>
                        <button type="button" class="fx-slider__next" data-slider-next aria-label="{{ __('wholesale_public.common.next') }}">›</button>
                    @endif
                </div>
            </div>
        </section>

    @elseif($section['type']==='categories')
        <section class="fx-section" id="categories" data-reveal>
            <div class="fx-shell">
                <div class="fx-section-head">
                    <div><span class="fx-kicker">{{ __('wholesale_public.common.explore') }}</span><h2>{{ $section['title'] ?: __('wholesale_public.common.main_categories') }}</h2></div>
                    @if($activeCategory>0)<a href="{{ route('public.wholesale.home') }}#products">{{ __('wholesale_public.common.clear_filter') }}</a>@endif
                </div>
                <div class="fx-category-row">
                    @forelse($categories as $category)
                        <a class="fx-category-card @if($activeCategory===$category['id']) is-selected @endif" href="{{ route('public.wholesale.home',['category'=>$category['id']]) }}#products">
                            <span class="fx-category-card__image">
                                @if($category['image_url'])<img src="{{ $category['image_url'] }}" alt="{{ $category['name'] }}" loading="lazy">@else<span aria-hidden="true">❄</span>@endif
                            </span>
                            <strong>{{ $category['name'] }}</strong>
                        </a>
                    @empty
                        <p class="fx-empty">{{ __('wholesale_public.common.empty_categories') }}</p>
                    @endforelse
                </div>
            </div>
        </section>

    @elseif($section['type']==='brands')
        <section class="fx-section fx-section--mint" id="brands" data-reveal>
            <div class="fx-shell">
                <div class="fx-section-head">
                    <div><span class="fx-kicker">{{ __('wholesale_public.common.our_partners') }}</span><h2>{{ $section['title'] ?: __('wholesale_public.common.brands') }}</h2></div>
                    <a href="{{ route('public.wholesale.brands') }}">{{ __('wholesale_public.common.view_all_brands') }}</a>
                </div>
                <div class="fx-brand-row">
                    @forelse($brands as $brand)
                        <a class="fx-brand-card @if($activeBrand===$brand['id']) is-selected @endif" href="{{ route('public.wholesale.home',['brand'=>$brand['id']]) }}#products">
                            @if($brand['image_url'])<img src="{{ $brand['image_url'] }}" alt="{{ $brand['name'] }}" loading="lazy">@else<span class="fx-brand-fallback">{{ mb_substr($brand['name'],0,1) }}</span>@endif
                            <strong>{{ $brand['name'] }}</strong>
                        </a>
                    @empty
                        <p class="fx-empty">{{ __('wholesale_public.common.empty_brands') }}</p>
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
                        <span class="fx-kicker">{{ __('wholesale_public.common.catalog') }}</span>
                        <h2>{{ $section['title'] ?: __('wholesale_public.common.our_products') }}</h2>
                    </div>
                    @if($activeBrand>0 || $activeCategory>0)<a href="{{ route('public.wholesale.home') }}#products">{{ __('wholesale_public.common.show_all_products') }}</a>@endif
                </div>
                <div class="fx-products-grid">
                    @forelse(array_slice($products,0,$limit) as $item)
                        @include('public.wholesale._product-card',['item'=>$item])
                    @empty
                        <p class="fx-empty">{{ __('wholesale_public.common.empty_products') }}</p>
                    @endforelse
                </div>
                <div class="fx-app-lock">
                    <div><span aria-hidden="true">🔒</span><strong>{{ __('wholesale_public.app_gate.title') }}</strong><p>{{ __('wholesale_public.app_gate.body') }}</p></div>
                    <a class="fx-primary-button" href="{{ $appUrl }}">{{ __('wholesale_public.app_gate.cta') }}</a>
                </div>
            </div>
        </section>

    @elseif($section['type']==='social')
        @if(!empty($branding['facebook_url']))
            <section class="fx-section" data-reveal>
                <div class="fx-shell">
                    <div class="fx-social-card">
                        <div>
                            <span class="fx-kicker">{{ __('wholesale_public.facebook.label') }}</span>
                            <h2>{{ $facebookTitle }}</h2>
                            <p>{{ $facebookText }}</p>
                        </div>
                        <a class="fx-primary-button fx-primary-button--lime" href="{{ $branding['facebook_url'] }}" target="_blank" rel="noopener">{{ __('wholesale_public.facebook.cta') }}</a>
                    </div>
                </div>
            </section>
        @endif
    @endif
@endforeach
@endsection
