@php
    $ar = app()->getLocale() === 'ar';
    $scope = ['store_id' => $storeId] + ($supportAccess ? ['support_access' => 1] : []);
    $customerEnabled = in_array('customer', $channels, true);
@endphp
<!doctype html>
<html lang="{{ app()->getLocale() }}" dir="{{ $ar ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>{{ $ar ? 'معاينة العرض السريع' : 'Flash Offer Preview' }} · FOODEX</title>
    @include('admin._brand')
    <style>
        .preview-shell{max-width:1180px;margin:0 auto;padding:22px;display:grid;gap:16px}
        .preview-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:14px}
        .preview-card{padding:18px;border:1px solid var(--foodex-border);border-radius:16px;background:#fff}
        .preview-card h2,.preview-card h3{margin-top:0}.muted{opacity:.72}
        .preview-cta{display:inline-block;padding:10px 14px;border-radius:12px;background:var(--foodex-primary);color:#fff;font-weight:800}
        .preview-table{width:100%;border-collapse:collapse}.preview-table th,.preview-table td{padding:10px;border-bottom:1px solid var(--foodex-border);text-align:start}
    </style>
</head>
<body>
@include('admin._account-menu', ['user' => $user, 'navGroups' => $navGroups, 'navContext' => $navContext])
<main class="preview-shell">
    <a href="{{ route('admin.commercial.flash-offers', $scope) }}">← {{ $ar ? 'العودة للعروض السريعة' : 'Back to Flash Offers' }}</a>
    <header>
        <h1>{{ $ar ? 'معاينة العرض السريع' : 'Flash Offer Preview' }} #{{ $offer->id }}</h1>
        <p class="muted">{{ $ar ? 'معاينة للعرض فقط؛ لا تنفذ أهلية أو حجز أو تسعير من المتصفح.' : 'Read-only presentation preview; eligibility, reservation and pricing remain server-authoritative.' }}</p>
    </header>

    @unless($customerEnabled)
        <section class="preview-card"><strong>{{ $ar ? 'قناة Customer غير مفعلة لهذا العرض.' : 'Customer channel is disabled for this offer.' }}</strong></section>
    @endunless

    <div class="preview-grid">
        <section class="preview-card" data-preview-surface="customer-popup">
            <h2>Customer Popup Preview</h2>
            <strong>{{ $ar ? $offer->title_ar : $offer->title_en }}</strong>
            <p>{{ $ar ? ($offer->body_ar ?: '—') : ($offer->body_en ?: '—') }}</p>
            <div class="preview-cta">BUY NOW</div>
            <p class="muted">Priority {{ $offer->priority }} · {{ $offer->popup_frequency }}</p>
        </section>

        <section class="preview-card" data-preview-surface="product-card">
            <h2>Product Card Preview</h2>
            <strong>{{ $ar ? $offer->title_ar : $offer->title_en }}</strong>
            <p class="muted">{{ $offer->starts_at }} → {{ $offer->ends_at }}</p>
            <span>{{ strtoupper($offer->status) }}</span>
        </section>

        <section class="preview-card" data-preview-surface="notification">
            <h2>Notification Preview</h2>
            <strong>{{ $ar ? $offer->title_ar : $offer->title_en }}</strong>
            <p>{{ $ar ? ($offer->body_ar ?: '—') : ($offer->body_en ?: '—') }}</p>
            <p class="muted">{{ implode(' · ', $channels) }}</p>
        </section>
    </div>

    <section class="preview-card">
        <h2>{{ $ar ? 'منتجات العرض' : 'Offer Products' }}</h2>
        <div style="overflow:auto">
            <table class="preview-table">
                <thead><tr><th>{{ $ar ? 'المنتج' : 'Product' }}</th><th>Unit</th><th>Conversion</th><th>Flash Price</th><th>Allocation</th></tr></thead>
                <tbody>
                @forelse($products as $product)
                    <tr>
                        <td>{{ $product->product_name ?: ('#'.$product->product_id) }}<div class="muted">{{ $product->product_sku ?: '—' }}</div></td>
                        <td>{{ $product->selling_unit_code ?: '—' }}</td>
                        <td>{{ number_format((float) $product->conversion_factor, 3) }}</td>
                        <td>{{ number_format((float) $product->flash_price, 3) }}</td>
                        <td>{{ $product->allocation_base === null ? '—' : number_format((float) $product->allocation_base, 3) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5">{{ $ar ? 'لا توجد منتجات.' : 'No offer products.' }}</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </section>
</main>
</body>
</html>
