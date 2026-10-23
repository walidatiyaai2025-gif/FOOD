@extends('public.wholesale.layout')

@section('title', $product['name'].' | '.__('wholesale_public.brand_name'))

@section('content')
<section class="fx-breadcrumbs">
    <div class="fx-shell">
        <a href="{{ route('public.wholesale.home') }}">{{ __('wholesale_public.product.home') }}</a>
        <span aria-hidden="true">›</span>
        @if(!empty($product['brand_name']))<a href="{{ route('public.wholesale.brands') }}">{{ $product['brand_name'] }}</a><span aria-hidden="true">›</span>@endif
        <span>{{ $product['name'] }}</span>
    </div>
</section>

<section class="fx-section fx-product-detail" data-reveal>
    <div class="fx-shell fx-product-detail__grid">
        <div>
            <div class="fx-product-gallery">
                <div class="fx-product-gallery__main">
                    @if($product['image_url'])
                        <img src="{{ $product['image_url'] }}" alt="{{ $product['name'] }}" data-main-product-image>
                    @else
                        <span class="fx-image-placeholder" aria-hidden="true">❄</span>
                    @endif
                </div>
                @if(count($product['images'])>1)
                    <div class="fx-product-gallery__thumbs">
                        @foreach($product['images'] as $image)
                            <button type="button" data-product-thumb="{{ $image }}"><img src="{{ $image }}" alt=""></button>
                        @endforeach
                    </div>
                @endif
            </div>
        </div>
        <div class="fx-product-detail__content">
            @if(!empty($product['brand_name']))<span class="fx-kicker">{{ $product['brand_name'] }}</span>@endif
            <h1>{{ $product['name'] }}</h1>
            <div class="fx-product-meta">
                <span><strong>SKU</strong> {{ $product['sku'] }}</span>
                @if(!empty($product['category_name']))<span><strong>{{ __('wholesale_public.product.category') }}</strong> {{ $product['category_name'] }}</span>@endif
                @if(!empty($product['unit_name']))<span><strong>{{ __('wholesale_public.product.unit') }}</strong> {{ $product['unit_name'] }}</span>@endif
            </div>

            <div class="fx-price-gate">
                <div><span class="fx-lock-icon" aria-hidden="true">🔒</span><strong>{{ __('wholesale_public.product.price_gate_title') }}</strong></div>
                <p>{{ __('wholesale_public.product.price_gate_body') }}</p>
                <a class="fx-primary-button" href="{{ $appUrl }}">{{ __('wholesale_public.product.price_gate_cta') }}</a>
            </div>

            @if(!empty($product['description']))
                <div class="fx-copy-block">
                    <h2>{{ __('wholesale_public.product.about') }}</h2>
                    <p>{{ $product['description'] }}</p>
                </div>
            @endif

            <div class="fx-spec-grid">
                @if(!empty($product['brand_name']))<div><span>{{ __('wholesale_public.product.brand') }}</span><strong>{{ $product['brand_name'] }}</strong></div>@endif
                @if(!empty($product['category_name']))<div><span>{{ __('wholesale_public.product.classification') }}</span><strong>{{ $product['category_name'] }}</strong></div>@endif
                <div><span>{{ __('wholesale_public.product.channel') }}</span><strong>{{ __('wholesale_public.product.wholesale') }}</strong></div>
                <div><span>{{ __('wholesale_public.product.ordering') }}</span><strong>{{ __('wholesale_public.product.in_app') }}</strong></div>
            </div>
        </div>
    </div>
</section>

@if(count($related)>0)
<section class="fx-section fx-section--mint" data-reveal>
    <div class="fx-shell">
        <div class="fx-section-head"><div><span class="fx-kicker">{{ __('wholesale_public.product.related_kicker') }}</span><h2>{{ __('wholesale_public.product.related_title') }}</h2></div></div>
        <div class="fx-products-grid">
            @foreach($related as $item)
                @include('public.wholesale._product-card',['item'=>$item])
            @endforeach
        </div>
    </div>
</section>
@endif
@endsection
