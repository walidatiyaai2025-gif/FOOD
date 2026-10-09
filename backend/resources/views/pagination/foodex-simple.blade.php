@if ($paginator->hasPages())
<nav class="foodex-pagination" role="navigation" aria-label="{{ __('pagination.navigation') }}">
    <div class="foodex-pagination-controls">
        @if ($paginator->onFirstPage())
            <span class="foodex-pagination-disabled" aria-disabled="true">‹ {{ __('pagination.previous') }}</span>
        @else
            <a href="{{ $paginator->previousPageUrl() }}" rel="prev">‹ {{ __('pagination.previous') }}</a>
        @endif

        @if ($paginator->hasMorePages())
            <a href="{{ $paginator->nextPageUrl() }}" rel="next">{{ __('pagination.next') }} ›</a>
        @else
            <span class="foodex-pagination-disabled" aria-disabled="true">{{ __('pagination.next') }} ›</span>
        @endif
    </div>
</nav>
@endif
