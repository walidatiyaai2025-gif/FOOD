<section class="so-template so-elegant">
<div class="so-elegant-hero">@if($site['cover_url'])<img src="{{ $site['cover_url'] }}" alt="" fetchpriority="high" decoding="async">@endif<div class="so-elegant-overlay"><p>{{ $site['tagline'] }}</p><h1>{{ $site['name'] }}</h1>@if($site['description'])<span>{{ $site['description'] }}</span>@endif</div></div>
<div class="so-elegant-toolbar"><label class="so-search"><span aria-hidden="true">⌕</span><input type="search" data-menu-search placeholder="{{ $locale === 'ar' ? 'ابحث عن طبق' : 'Find a dish' }}"></label><nav aria-label="{{ $locale === 'ar' ? 'الأقسام' : 'Sections' }}">@foreach($menu['categories'] as $category)<a href="#elegant-{{ $loop->index }}">{{ $category['name'] }}</a>@endforeach</nav></div>
@forelse($menu['categories'] as $category)
<section class="so-elegant-section" id="elegant-{{ $loop->index }}" data-category><header><span>{{ str_pad((string) $loop->iteration, 2, '0', STR_PAD_LEFT) }}</span><h2>{{ $category['name'] }}</h2><p>{{ $category['description'] }}</p></header>
<div class="so-elegant-grid">@foreach($category['items'] as $item)
<article class="so-elegant-dish {{ $loop->even ? 'is-reverse' : '' }}" data-menu-item data-search="{{ mb_strtolower($item['name'].' '.$item['description']) }}">@if($item['image_url'])<img src="{{ $item['image_url'] }}" alt="" loading="lazy" decoding="async">@endif<div><p class="so-kicker">{{ $item['best_seller'] ? ($locale === 'ar' ? 'اختيار الضيوف' : 'Guest favourite') : ($locale === 'ar' ? 'من القائمة' : 'From the menu') }}</p><h3>{{ $item['name'] }}</h3><p>{{ $item['description'] }}</p><div class="so-dish-footer"><strong>{{ $item['price_display'] }}</strong><button type="button" data-open-item data-item-id="{{ $item['public_key'] }}" data-name="{{ $item['name'] }}" data-description="{{ $item['description'] }}" data-image="{{ $item['image_url'] }}" data-modifiers='@json($item["modifiers"], JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_TAG | JSON_HEX_QUOT)' @disabled($item['sold_out'] || ! $is_open)>{{ $item['sold_out'] ? ($locale === 'ar' ? 'غير متاح' : 'Unavailable') : ($locale === 'ar' ? 'اختيار' : 'Choose') }}</button></div></div></article>
@endforeach</div></section>
@empty
<div class="so-empty">{{ $locale === 'ar' ? 'سيتم نشر القائمة قريباً.' : 'The menu will be published soon.' }}</div>
@endforelse
</section>
