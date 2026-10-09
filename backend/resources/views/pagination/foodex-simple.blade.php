@if ($paginator->hasPages())
@php($ar = app()->getLocale() === 'ar')
<nav class="foodex-pagination" role="navigation" aria-label="{{ $ar ? 'التنقل بين الصفحات' : 'Pagination Navigation' }}">
    <div class="foodex-pagination-controls">
        @if ($paginator->onFirstPage())
            <span class="foodex-pagination-disabled" aria-disabled="true">‹ {{ $ar ? 'السابق' : 'Previous' }}</span>
        @else
            <a href="{{ $paginator->previousPageUrl() }}" rel="prev">‹ {{ $ar ? 'السابق' : 'Previous' }}</a>
        @endif

        @if ($paginator->hasMorePages())
            <a href="{{ $paginator->nextPageUrl() }}" rel="next">{{ $ar ? 'التالي' : 'Next' }} ›</a>
        @else
            <span class="foodex-pagination-disabled" aria-disabled="true">{{ $ar ? 'التالي' : 'Next' }} ›</span>
        @endif
    </div>
</nav>
@endif
