<section class="so-template so-quick-bite">
@if($site['cover_url'])<div class="so-quick-hero"><img src="{{ $site['cover_url'] }}" alt="" fetchpriority="high" decoding="async"><div><p>{{ $locale === 'ar' ? 'اطلب أسرع' : 'Order fast' }}</p><h1>{{ $site['name'] }}</h1></div></div>@endif
<div class="so-search-wrap"><label class="so-search"><span aria-hidden="true">⌕</span><input type="search" data-menu-search placeholder="{{ $locale === 'ar' ? 'ابحث في القائمة' : 'Search the menu' }}"></label></div>
<nav class="so-category-rail" aria-label="{{ $locale === 'ar' ? 'فئات القائمة' : 'Menu categories' }}">@foreach($menu['categories'] as $category)<a href="#cat-{{ $category['public_key'] ?: $loop->index }}">{{ $category['name'] }}</a>@endforeach</nav>
<div class="so-quick-sections">
@forelse($menu['categories'] as $category)
<section id="cat-{{ $category['public_key'] ?: $loop->index }}" data-category>
<div class="so-section-heading"><h2>{{ $category['name'] }}</h2><span>{{ count($category['items']) }}</span></div>
<div class="so-quick-list">
@foreach($category['items'] as $item)
<article class="so-item-card so-quick-card" data-menu-item data-search="{{ mb_strtolower($item['name'].' '.$item['description']) }}">
<div class="so-item-copy"><div class="so-item-badges">@if($item['best_seller'])<span>{{ $locale === 'ar' ? 'الأكثر طلباً' : 'Best seller' }}</span>@endif @if($item['new'])<span>{{ $locale === 'ar' ? 'جديد' : 'New' }}</span>@endif</div><h3>{{ $item['name'] }}</h3>@if($item['description'])<p>{{ $item['description'] }}</p>@endif<strong>{{ $item['price_display'] }}</strong></div>
<div class="so-item-media">@if($item['image_url'])<img src="{{ $item['image_url'] }}" alt="" loading="lazy" decoding="async">@endif
<button type="button" class="so-add-button" data-open-item data-name="{{ $item['name'] }}" data-description="{{ $item['description'] }}" data-image="{{ $item['image_url'] }}" data-modifiers='@json($item["modifiers"])' @disabled($item['sold_out'] || ! $is_open)>{{ $item['sold_out'] ? ($locale === 'ar' ? 'نفد' : 'Sold out') : '+' }}</button></div>
</article>
@endforeach
</div></section>
@empty
<div class="so-empty">{{ $locale === 'ar' ? 'القائمة غير متاحة حالياً.' : 'The menu is currently unavailable.' }}</div>
@endforelse
</div></section>
