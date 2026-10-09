@if ($paginator->hasPages())
@php($ar = app()->getLocale() === 'ar')
<nav class="foodex-pagination" role="navigation" aria-label="{{ $ar ? 'التنقل بين الصفحات' : 'Pagination Navigation' }}">
    <div class="foodex-pagination-summary">
        {{ $ar ? 'عرض' : 'Showing' }}
        <strong>{{ $paginator->firstItem() }}</strong>
        {{ $ar ? 'إلى' : 'to' }}
        <strong>{{ $paginator->lastItem() }}</strong>
        {{ $ar ? 'من' : 'of' }}
        <strong>{{ $paginator->total() }}</strong>
        {{ $ar ? 'نتيجة' : 'results' }}
    </div>

    <div class="foodex-pagination-pages">
        @if ($paginator->onFirstPage())
            <span class="foodex-pagination-disabled" aria-disabled="true">‹ {{ $ar ? 'السابق' : 'Previous' }}</span>
        @else
            <a href="{{ $paginator->previousPageUrl() }}" rel="prev">‹ {{ $ar ? 'السابق' : 'Previous' }}</a>
        @endif

        @foreach ($elements as $element)
            @if (is_string($element))
                <span class="foodex-pagination-disabled" aria-disabled="true">{{ $element }}</span>
            @endif

            @if (is_array($element))
                @foreach ($element as $page => $url)
                    @if ($page == $paginator->currentPage())
                        <span class="foodex-pagination-current" aria-current="page">{{ $page }}</span>
                    @else
                        <a href="{{ $url }}">{{ $page }}</a>
                    @endif
                @endforeach
            @endif
        @endforeach

        @if ($paginator->hasMorePages())
            <a href="{{ $paginator->nextPageUrl() }}" rel="next">{{ $ar ? 'التالي' : 'Next' }} ›</a>
        @else
            <span class="foodex-pagination-disabled" aria-disabled="true">{{ $ar ? 'التالي' : 'Next' }} ›</span>
        @endif
    </div>
</nav>
@endif
