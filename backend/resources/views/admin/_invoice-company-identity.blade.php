@php
    $brand = $brand ?? app(\App\Services\DocumentBrandingService::class)->identity();
    $brandIsAr = app()->getLocale() === 'ar';
@endphp
<div class="foodex-invoice-company" data-invoice-company-identity>
    <img class="foodex-invoice-company-logo"
         src="{{ $brand['logo_data_uri'] ?? asset('brand/foodex-economical-group.webp') }}"
         alt="{{ $brand['name'] }}">
    <strong class="foodex-invoice-company-name">{{ $brand['name'] }}</strong>
    <div class="foodex-invoice-company-address" dir="auto">{{ $brand['address'] }}</div>
    <div class="foodex-invoice-company-contacts">
        @if(!empty($brand['phone_secondary']))
            <span>{{ $brandIsAr ? 'موبايل' : 'Mobile' }}: <bdi dir="ltr">{{ $brand['phone_secondary'] }}</bdi></span>
        @endif
        @if(!empty($brand['phone_primary']))
            <span>{{ $brandIsAr ? 'تليفون' : 'Telephone' }}: <bdi dir="ltr">{{ $brand['phone_primary'] }}</bdi></span>
        @endif
    </div>
</div>
