@php($searchable = $searchable ?? false)
<article class="fm-product-card {{ $item['sold_out'] ? 'is-sold-out' : '' }}"
    @if($searchable)
        data-menu-item
        data-search="{{ mb_strtolower(($item['name'] ?? '').' '.($item['description'] ?? '').' '.($item['_category_name'] ?? '')) }}"
    @endif>
    <div class="fm-product-media">
        @if($item['image_url'])
            <img src="{{ $item['image_url'] }}" alt="" loading="lazy" decoding="async">
        @endif
        <span class="fm-product-badge {{ $item['sold_out'] ? 'is-unavailable' : '' }}">
            <b aria-hidden="true">{{ $item['sold_out'] ? '×' : '✓' }}</b>
            {{ $item['sold_out'] ? ($locale === 'ar' ? 'غير متاح' : 'Unavailable') : ($locale === 'ar' ? 'طازج' : 'Fresh') }}
        </span>
    </div>

    <div class="fm-product-copy">
        <h3>{{ $item['name'] }}</h3>
        @if(($item['description'] ?? '') !== '')
            <p>{{ $item['description'] }}</p>
        @else
            <p>{{ $item['_category_name'] ?? '' }}</p>
        @endif

        <div class="fm-product-foot">
            <strong>{{ $item['price_display'] }}</strong>
            <button type="button"
                class="fm-add"
                data-open-item
                data-item-id="{{ $item['public_key'] }}"
                data-name="{{ $item['name'] }}"
                data-description="{{ $item['description'] }}"
                data-image="{{ $item['image_url'] }}"
                data-modifiers='@json($item["modifiers"], JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_TAG | JSON_HEX_QUOT)'
                aria-label="{{ $locale === 'ar' ? 'إضافة '.$item['name'] : 'Add '.$item['name'] }}"
                @disabled($item['sold_out'] || ! $is_open || ! ($ordering['enabled'] ?? false))>+</button>
        </div>
    </div>
</article>
