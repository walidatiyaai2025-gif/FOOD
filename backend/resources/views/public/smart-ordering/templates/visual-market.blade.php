<section class="so-template so-market">
<div class="so-market-head"><div><p>{{ $locale === 'ar' ? 'كل القائمة في مكان واحد' : 'Everything in one place' }}</p><h1>{{ $site['name'] }}</h1></div><label class="so-search so-market-search"><span aria-hidden="true">⌕</span><input type="search" data-menu-search placeholder="{{ $locale === 'ar' ? 'ابحث عن أي منتج' : 'Search any item' }}"></label></div>
<div class="so-market-layout"><aside><strong>{{ $locale === 'ar' ? 'الفئات' : 'Categories' }}</strong><nav>@foreach($menu['categories'] as $category)<a href="#market-{{ $loop->index }}">{{ $category['name'] }} <span>{{ count($category['items']) }}</span></a>@endforeach</nav></aside><div>
@forelse($menu['categories'] as $category)
<section class="so-market-category" id="market-{{ $loop->index }}" data-category><header><h2>{{ $category['name'] }}</h2><p>{{ $category['description'] }}</p></header><div class="so-market-grid">
@foreach($category['items'] as $item)<article data-menu-item data-search="{{ mb_strtolower($item['name'].' '.$item['description'].' '.implode(' ', $item['tags'])) }}"><button type="button" class="so-market-item" data-open-item data-name="{{ $item['name'] }}" data-description="{{ $item['description'] }}" data-image="{{ $item['image_url'] }}" data-modifiers='@json($item["modifiers"])' @disabled($item['sold_out'] || ! $is_open)><div class="so-market-image">@if($item['image_url'])<img src="{{ $item['image_url'] }}" alt="" loading="lazy" decoding="async">@endif @if($item['sold_out'])<span>{{ $locale === 'ar' ? 'نفد' : 'Sold out' }}</span>@endif</div><span class="so-market-copy"><strong>{{ $item['name'] }}</strong><small>{{ $item['description'] }}</small><b>{{ $item['price_display'] }}</b></span></button></article>@endforeach
</div></section>
@empty
<div class="so-empty">{{ $locale === 'ar' ? 'لا توجد نتائج.' : 'No menu items found.' }}</div>
@endforelse
</div></div></section>
