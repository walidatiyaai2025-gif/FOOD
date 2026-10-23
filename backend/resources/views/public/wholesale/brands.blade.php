@extends('public.wholesale.layout')

@section('title', app()->getLocale()==='ar' ? 'العلامات التجارية | فوديكس' : 'Brands | FOODEX')

@section('content')
@php($ar=app()->getLocale()==='ar')
<section class="fx-page-hero">
    <div class="fx-shell">
        <span class="fx-kicker">{{ $ar?'كتالوج الجملة':'Wholesale catalog' }}</span>
        <h1>{{ $ar?'العلامات التجارية':'Brands' }}</h1>
        <p>{{ $ar?'تصفح العلامات المتوفرة داخل كتالوج فوديكس للمجمدات بالجملة.':'Browse brands available in the FOODEX wholesale frozen-food catalog.' }}</p>
        <form class="fx-search" method="get" action="{{ route('public.wholesale.brands') }}">
            <input name="q" value="{{ $query }}" placeholder="{{ $ar?'ابحث عن علامة تجارية...':'Search brands...' }}" aria-label="{{ $ar?'البحث في العلامات التجارية':'Search brands' }}">
            <button class="fx-primary-button" type="submit">{{ $ar?'بحث':'Search' }}</button>
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
                    <small>{{ $ar?'عرض المنتجات':'View products' }}</small>
                </a>
            @empty
                <p class="fx-empty">{{ $ar?'لا توجد علامات مطابقة للبحث.':'No brands match your search.' }}</p>
            @endforelse
        </div>
    </div>
</section>
@endsection
