<style>
.foodex-invoice-company {
    display: grid;
    justify-items: start;
    gap: 5px;
    min-width: 0;
    padding: 0 0 14px;
    margin-bottom: 14px;
    border-bottom: 2px solid var(--foodex-green-dark, #166534);
    color: var(--foodex-ink, #17271d);
    break-inside: avoid;
}
.foodex-invoice-company-logo {
    display: block;
    width: min(190px, 100%);
    max-height: 96px;
    object-fit: contain;
    object-position: left center;
}
[dir="rtl"] .foodex-invoice-company-logo { object-position: right center; }
.foodex-invoice-company-name { font-size: 1.05rem; line-height: 1.4; }
.foodex-invoice-company-address { line-height: 1.6; overflow-wrap: anywhere; font-size: .85rem; }
.foodex-invoice-company-contacts {
    display: flex;
    flex-wrap: wrap;
    gap: 5px 18px;
    font-size: .85rem;
    line-height: 1.6;
}
.foodex-invoice-company-contacts span { unicode-bidi: isolate; }
@media print {
    .foodex-invoice-company { color: #111 !important; background: #fff !important; }
    .foodex-invoice-company-logo { max-height: 67px; width: 150px; }
    .order-popup-content[data-order-popup-content="invoice"] .order-print-header { display: none !important; }
}
</style>
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
