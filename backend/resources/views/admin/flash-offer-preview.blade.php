@php
    $ar = app()->getLocale() === 'ar';
    $scope = ['store_id' => $storeId] + ($supportAccess ? ['support_access' => 1] : []);
    $customerEnabled = in_array('customer', $channels, true);
    $localizedChannels = collect($channels)->map(
        fn (string $channel): string => __('commercial.channels.'.$channel)
    );
@endphp
<!doctype html>
<html lang="{{ app()->getLocale() }}" dir="{{ $ar ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>{{ __('commercial.preview.title') }} · FOODEX</title>
    @include('admin._brand-components')
    <style>
        body{margin:0;overflow-x:hidden;background:var(--foodex-background);color:var(--foodex-ink)}
        a{color:inherit}
        .commercial-evidence-layout{direction:ltr;display:grid;grid-template-columns:minmax(0,1fr) var(--foodex-sidebar-width);min-height:100vh}
        .commercial-evidence-layout>.sidebar{grid-column:2;grid-row:1;direction:rtl;background:var(--foodex-surface);border-inline-start:1px solid var(--foodex-border);min-height:100vh}
        .commercial-evidence-layout>.evidence-shell{grid-column:1;grid-row:1;direction:rtl;min-width:0}
        html[dir=ltr] .commercial-evidence-layout{grid-template-columns:var(--foodex-sidebar-width) minmax(0,1fr)}
        html[dir=ltr] .commercial-evidence-layout>.sidebar{grid-column:1;direction:ltr;border-inline-start:0;border-inline-end:1px solid var(--foodex-border)}
        html[dir=ltr] .commercial-evidence-layout>.evidence-shell{grid-column:2;direction:ltr}
        .evidence-shell{width:100%;max-width:none!important;margin:0;padding:var(--foodex-space-6) clamp(var(--foodex-space-4),2vw,var(--foodex-space-8)) var(--foodex-space-8);display:grid;gap:var(--foodex-space-4)}
        .evidence-header{display:flex;align-items:flex-start;justify-content:space-between;gap:var(--foodex-space-4);flex-wrap:wrap}
        .evidence-header h1{margin:3px 0 6px;font-size:clamp(1.55rem,2.2vw,2rem)}.muted{color:var(--foodex-muted)}
        .commercial-tabs{display:flex;gap:8px;flex-wrap:wrap;align-items:center;background:var(--foodex-surface);border:1px solid var(--foodex-border);border-radius:var(--foodex-radius-card);padding:8px;box-shadow:var(--foodex-shadow-sm)}
        .commercial-tabs a{min-height:40px;padding:0 14px;border:1px solid transparent;border-radius:var(--foodex-radius-control);text-decoration:none;font-weight:800;display:inline-flex;align-items:center;justify-content:center;color:var(--foodex-muted)}
        .commercial-tabs a.active{background:var(--foodex-green);color:#fff;border-color:var(--foodex-green)}
        .preview-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:14px}
        .preview-card{padding:18px;border:1px solid var(--foodex-border);border-radius:var(--foodex-radius-card);background:var(--foodex-surface);box-shadow:var(--foodex-shadow-sm)}
        .preview-card h2,.preview-card h3{margin-top:0}
        .preview-cta{display:inline-flex;min-height:42px;align-items:center;padding:0 14px;border-radius:var(--foodex-radius-control);background:var(--foodex-green);color:#fff;font-weight:800}
        .preview-table{width:100%;border-collapse:collapse}.preview-table th,.preview-table td{padding:10px;border-bottom:1px solid var(--foodex-border);text-align:start}
        @media(max-width:1023px){.commercial-evidence-layout{grid-template-columns:1fr!important}.commercial-evidence-layout>.sidebar,.commercial-evidence-layout>.evidence-shell{grid-column:1!important;grid-row:auto!important}.commercial-evidence-layout>.sidebar{min-height:auto}.evidence-shell{padding:14px}}
    </style>
</head>
<body>
<div class="foodex-admin-layout commercial-evidence-layout" data-commercial-preview>
    <aside class="sidebar">
        @include('admin._sidebar', ['user' => $user, 'navGroups' => $navGroups, 'navContext' => $navContext])
    </aside>
    <main class="foodex-admin-main foodex-admin-page evidence-shell">
        <header class="evidence-header">
            <div>
                <span class="foodex-subtitle">FOODEX · {{ __('commercial.flash.title') }}</span>
                <h1>{{ __('commercial.preview.title') }} · {{ $offer->name }}</h1>
                <p class="muted">{{ __('commercial.preview.description') }}</p>
            </div>
            @include('admin._live-notifications', ['user' => $user])
        </header>

        <nav class="commercial-tabs" aria-label="{{ __('commercial.tabs.aria') }}">
            <a href="{{ route('admin.commercial.sales-control', $scope) }}">{{ __('commercial.tabs.sales_control') }}</a>
            <a class="active" href="{{ route('admin.commercial.flash-offers', $scope) }}">{{ __('commercial.tabs.flash_offers') }}</a>
            <a href="{{ route('admin.b2c.module', ['module' => 'promotions'] + $scope) }}">{{ __('commercial.tabs.back_store') }}</a>
        </nav>

        <a href="{{ route('admin.commercial.flash-offers', $scope) }}">← {{ __('commercial.preview.back') }}</a>

        @unless($customerEnabled)
            <section class="preview-card"><strong>{{ __('commercial.preview.customer_disabled') }}</strong></section>
        @endunless

        <div class="preview-grid">
            <section class="preview-card" data-preview-surface="customer-popup">
                <h2>{{ __('commercial.preview.customer_popup') }}</h2>
                <strong>{{ $ar ? $offer->title_ar : $offer->title_en }}</strong>
                <p>{{ $ar ? ($offer->body_ar ?: '—') : ($offer->body_en ?: '—') }}</p>
                <div class="preview-cta">{{ __('commercial.preview.buy_now') }}</div>
                <p class="muted">{{ __('commercial.preview.priority') }} {{ $offer->priority }} · {{ __('commercial.flash.'.$offer->popup_frequency) }}</p>
            </section>

            <section class="preview-card" data-preview-surface="product-card">
                <h2>{{ __('commercial.preview.product_card') }}</h2>
                <strong>{{ $ar ? $offer->title_ar : $offer->title_en }}</strong>
                <p class="muted">{{ $offer->starts_at }} → {{ $offer->ends_at }}</p>
                <span>{{ __('commercial.flash.statuses.'.$offer->status) }}</span>
            </section>

            <section class="preview-card" data-preview-surface="notification">
                <h2>{{ __('commercial.preview.notification') }}</h2>
                <strong>{{ $ar ? $offer->title_ar : $offer->title_en }}</strong>
                <p>{{ $ar ? ($offer->body_ar ?: '—') : ($offer->body_en ?: '—') }}</p>
                <p class="muted">{{ $localizedChannels->implode(' · ') }}</p>
            </section>
        </div>

        <section class="preview-card">
            <h2>{{ __('commercial.preview.offer_products') }}</h2>
            <div style="overflow:auto">
                <table class="preview-table">
                    <thead><tr><th>{{ __('commercial.preview.product') }}</th><th>{{ __('commercial.preview.selling_unit') }}</th><th>{{ __('commercial.preview.conversion') }}</th><th>{{ __('commercial.preview.flash_price') }}</th><th>{{ __('commercial.preview.allocation') }}</th></tr></thead>
                    <tbody>
                    @forelse($products as $product)
                        <tr>
                            <td>{{ $product->product_name ?: __('commercial.preview.product_unavailable') }}<div class="muted">{{ $product->product_sku ?: '—' }}</div></td>
                            <td>{{ $product->selling_unit_name ?: __('commercial.preview.selling_unit_unavailable') }}</td>
                            <td>{{ number_format((float) $product->conversion_factor, 3) }}</td>
                            <td>{{ number_format((float) $product->flash_price, 3) }}</td>
                            <td>{{ $product->allocation_base === null ? '—' : number_format((float) $product->allocation_base, 3) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="5">{{ __('commercial.preview.no_products') }}</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </section>
    </main>
</div>
</body>
</html>
