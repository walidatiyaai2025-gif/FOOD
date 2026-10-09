@if ($paginator->hasPages())
<nav class="foodex-pagination" role="navigation" aria-label="{{ __('pagination.navigation') }}">
    <div class="foodex-pagination-summary">
        {{ __('pagination.showing') }}
        <strong>{{ $paginator->firstItem() }}</strong>
        {{ __('pagination.to') }}
        <strong>{{ $paginator->lastItem() }}</strong>
        {{ __('pagination.of') }}
        <strong>{{ $paginator->total() }}</strong>
        {{ __('pagination.results') }}
    </div>

    <div class="foodex-pagination-pages">
        @if ($paginator->onFirstPage())
            <span class="foodex-pagination-disabled" aria-disabled="true">‹ {{ __('pagination.previous') }}</span>
        @else
            <a href="{{ $paginator->previousPageUrl() }}" rel="prev">‹ {{ __('pagination.previous') }}</a>
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
            <a href="{{ $paginator->nextPageUrl() }}" rel="next">{{ __('pagination.next') }} ›</a>
        @else
            <span class="foodex-pagination-disabled" aria-disabled="true">{{ __('pagination.next') }} ›</span>
        @endif
    </div>
</nav>
@endif
