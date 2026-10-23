@extends('public.wholesale.layout')

@section('title', $product['name'].' | FOODEX')

@section('content')
@php($ar=app()->getLocale()==='ar')
<section class="fx-breadcrumbs">
    <div class="fx-shell">
        <a href="{{ route('public.wholesale.home') }}">{{ $ar?'الرئيسية':'Home' }}</a>
        <span>›</span>
        @if(!empty($product['brand_name']))<a href="{{ route('public.wholesale.brands') }}">{{ $product['brand_name'] }}</a><span>›</span>@endif
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
                        <span class="fx-image-placeholder">❄</span>
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
                @if(!empty($product['category_name']))<span><strong>{{ $ar?'القسم':'Category' }}</strong> {{ $product['category_name'] }}</span>@endif
                @if(!empty($product['unit_name']))<span><strong>{{ $ar?'الوحدة':'Unit' }}</strong> {{ $product['unit_name'] }}</span>@endif
            </div>

            <div class="fx-price-gate">
                <div><span class="fx-lock-icon">🔒</span><strong>{{ $ar?'سجّل من التطبيق لمعرفة السعر والشراء':'Use the app to see pricing and order' }}</strong></div>
                <p>{{ $ar?'الأسعار تختلف حسب حساب الجملة وسياسة التسعير؛ لذلك لا نعرض أسعاراً على الموقع العام.':'Wholesale prices are account-specific, so public web pages never expose pricing.' }}</p>
                <a class="fx-primary-button" href="{{ $appUrl }}">{{ $ar?'تحميل تطبيق فوديكس':'Get the FOODEX app' }}</a>
            </div>

            @if(!empty($product['description']))
                <div class="fx-copy-block">
                    <h2>{{ $ar?'نبذة عن المنتج':'About this product' }}</h2>
                    <p>{{ $product['description'] }}</p>
                </div>
            @endif

            <div class="fx-spec-grid">
                @if(!empty($product['brand_name']))<div><span>{{ $ar?'العلامة التجارية':'Brand' }}</span><strong>{{ $product['brand_name'] }}</strong></div>@endif
                @if(!empty($product['category_name']))<div><span>{{ $ar?'التصنيف':'Category' }}</span><strong>{{ $product['category_name'] }}</strong></div>@endif
                <div><span>{{ $ar?'القناة':'Channel' }}</span><strong>{{ $ar?'جملة':'Wholesale' }}</strong></div>
                <div><span>{{ $ar?'الطلب':'Ordering' }}</span><strong>{{ $ar?'من التطبيق':'In app' }}</strong></div>
            </div>
        </div>
    </div>
</section>

@if(count($related)>0)
<section class="fx-section fx-section--mint" data-reveal>
    <div class="fx-shell">
        <div class="fx-section-head"><div><span class="fx-kicker">{{ $ar?'قد يعجبك أيضاً':'You may also like' }}</span><h2>{{ $ar?'منتجات مشابهة':'Related products' }}</h2></div></div>
        <div class="fx-products-grid">
            @foreach($related as $item)
                @include('public.wholesale._product-card',['item'=>$item])
            @endforeach
        </div>
    </div>
</section>
@endif
@endsection
