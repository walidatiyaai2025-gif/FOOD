@extends('public.wholesale.layout')

@section('title', __('wholesale_public.brands_page.title'))

@section('content')
<section class="fx-page-hero">
    <div class="fx-shell">
        <span class="fx-kicker">{{ __('wholesale_public.brands_page.kicker') }}</span>
        <h1>{{ __('wholesale_public.brands_page.heading') }}</h1>
        <p>{{ __('wholesale_public.brands_page.body') }}</p>
        <form class="fx-search" method="get" action="{{ route('public.wholesale.brands') }}">
            <input name="q" value="{{ $query }}" placeholder="{{ __('wholesale_public.brands_page.search_placeholder') }}" aria-label="{{ __('wholesale_public.brands_page.search_label') }}">
            <button class="fx-primary-button" type="submit">{{ __('wholesale_public.brands_page.search_action') }}</button>
        </form>
    </div>
</section>

<section class="fx-section" data-reveal>
    <div class="fx-shell">
        <div class="fx-brand-directory">
            @forelse($brands as $brand)
                <a class="fx-brand-directory__card" href="{{ route('public.wholesale.home',['brand'=>$brand['id']]) }}#products">
                    <span class="fx-brand-directory__logo">
                        @if($brand['image_url'])
                            <img src="{{ $brand['image_url'] }}" alt="{{ $brand['name'] }}" loading="lazy">
                        @else
                            <span class="fx-brand-fallback">{{ mb_substr($brand['name'],0,1) }}</span>
                        @endif
                    </span>
                    <strong>{{ $brand['name'] }}</strong>
                    <small>{{ __('wholesale_public.brands_page.view_products') }}</small>
                </a>
            @empty
                <p class="fx-empty">{{ __('wholesale_public.brands_page.empty') }}</p>
            @endforelse
        </div>
    </div>
</section>
@endsection
