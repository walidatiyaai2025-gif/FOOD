<article class="fx-product-card" data-reveal>
    <a class="fx-product-card__image" href="{{ route('public.wholesale.product',['product'=>$item['id']]) }}">
        @if(!empty($item['image_url']))
            <img src="{{ $item['image_url'] }}" alt="{{ $item['name'] }}" loading="lazy">
        @else
            <span class="fx-image-placeholder" aria-hidden="true">❄</span>
        @endif
    </a>
    <div class="fx-product-card__body">
        @if(!empty($item['brand_name']))<span class="fx-eyebrow">{{ $item['brand_name'] }}</span>@endif
        <a class="fx-product-card__title" href="{{ route('public.wholesale.product',['product'=>$item['id']]) }}">{{ $item['name'] }}</a>
        @if(!empty($item['category_name']))<span class="fx-muted">{{ $item['category_name'] }}</span>@endif
        <div class="fx-price-lock"><span aria-hidden="true">🔒</span>{{ __('wholesale_public.common.price_in_app') }}</div>
    </div>
</article>
