@php
    $ar = app()->getLocale() === 'ar';
    $scope = ['store_id' => $storeId] + ($supportAccess ? ['support_access' => 1] : []);
@endphp
<!doctype html>
<html lang="{{ app()->getLocale() }}" dir="{{ $ar ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>{{ $section === 'sales-control' ? ($ar ? 'التحكم التجاري للمنتجات' : 'Product Sales Control') : ($ar ? 'العروض السريعة' : 'Flash Offers') }} · FOODEX</title>
    @include('admin._brand')
    <style>
        .commercial-shell{max-width:1280px;margin:0 auto;padding:22px;display:grid;gap:16px}
        .commercial-tabs{display:flex;gap:8px;flex-wrap:wrap}
        .commercial-tabs a{padding:10px 14px;border:1px solid var(--foodex-border);border-radius:12px;text-decoration:none;font-weight:700;background:#fff}
        .commercial-tabs a.active{box-shadow:0 0 0 2px var(--foodex-primary) inset}
        .contract-banner{padding:14px 16px;border:1px solid var(--foodex-border);border-radius:14px;background:#fff}
        .contract-banner strong{display:block;margin-bottom:5px}
        .contract-banner.pending{border-style:dashed}
        .commercial-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:12px}
        .commercial-card{padding:16px;border:1px solid var(--foodex-border);border-radius:14px;background:#fff}
        .commercial-card h3{margin:0 0 8px}
        .muted{opacity:.72}
        .control-list{display:grid;gap:9px;margin-top:12px}
        .control-row{display:flex;justify-content:space-between;gap:12px;align-items:center;padding:10px 0;border-top:1px solid var(--foodex-border)}
        .disabled-action{opacity:.55;cursor:not-allowed}
        .commercial-table{width:100%;border-collapse:collapse}
        .commercial-table th,.commercial-table td{padding:11px;border-bottom:1px solid var(--foodex-border);text-align:start}
        @media(max-width:720px){.commercial-shell{padding:12px}.commercial-table{display:block;overflow:auto}}
    </style>
</head>
<body>
@include('admin._account-menu', ['user' => $user, 'navGroups' => $navGroups, 'navContext' => $navContext])
<main class="commercial-shell">
    <div class="commercial-tabs" aria-label="{{ $ar ? 'إدارة السياسات التجارية' : 'Commercial policy administration' }}">
        <a class="{{ $section === 'sales-control' ? 'active' : '' }}" href="{{ route('admin.commercial.sales-control', $scope) }}">{{ $ar ? 'التحكم في المبيعات' : 'Sales Control' }}</a>
        <a class="{{ $section === 'flash-offers' ? 'active' : '' }}" href="{{ route('admin.commercial.flash-offers', $scope) }}">{{ $ar ? 'العروض السريعة' : 'Flash Offers' }}</a>
        <a href="{{ route('admin.b2c.module', ['module' => $section === 'sales-control' ? 'products' : 'promotions'] + $scope) }}">{{ $ar ? 'العودة لمساحة المتجر' : 'Back to store workspace' }}</a>
    </div>

    <section class="contract-banner {{ $contractReady ? '' : 'pending' }}" data-contract-ready="{{ $contractReady ? '1' : '0' }}">
        <strong>{{ $contractReady ? ($ar ? 'العقد المركزي متاح' : 'Canonical contract detected') : ($ar ? 'بانتظار العقد المركزي' : 'Canonical contract pending') }}</strong>
        <span class="muted">
            {{ $contractReady
                ? ($ar ? 'ستظل قواعد الأهلية والحصص والحسابات معتمدة على الخادم فقط.' : 'Eligibility, quota and pricing decisions remain server-authoritative.')
                : ($ar ? 'تم تجهيز واجهة الإدارة بدون تكرار محرك القواعد. الحفظ والتفعيل يظلان معطلين حتى تنشر #984/#985 العقود المركزية.' : 'The admin surface is prepared without duplicating the rule engine. Save/activation stays disabled until #984/#985 publish canonical contracts.') }}
        </span>
    </section>

    @if($section === 'sales-control')
        <header>
            <h1>{{ $ar ? 'Product Sales Control' : 'Product Sales Control' }}</h1>
            <p class="muted">{{ $ar ? 'التوفر، وحدات البيع، الحصص، القنوات، الاستهداف وصلاحيات التجاوز ستُدار من العقد المركزي.' : 'Availability, selling units, quotas, channels, targeting and override permissions are owned by the canonical backend contract.' }}</p>
        </header>
        <div class="commercial-grid">
            @foreach($products as $product)
                <article class="commercial-card" data-product-id="{{ $product->id }}">
                    <h3>{{ $product->name }}</h3>
                    <div class="muted">{{ $product->sku ?: '—' }} · {{ $product->is_active ? ($ar ? 'نشط' : 'Active') : ($ar ? 'غير نشط' : 'Inactive') }}</div>
                    <div class="control-list">
                        @foreach([
                            $ar ? 'التوفر والمواسم' : 'Availability & seasons',
                            $ar ? 'وحدات البيع والتحويل' : 'Selling units & conversion',
                            $ar ? 'الحصص والحدود' : 'Quotas & limits',
                            $ar ? 'القنوات والاستهداف' : 'Channels & targeting',
                            $ar ? 'صلاحيات التجاوز' : 'Override permissions',
                        ] as $label)
                            <div class="control-row"><span>{{ $label }}</span><button type="button" class="btn disabled-action" disabled>{{ $ar ? 'بانتظار العقد' : 'Contract pending' }}</button></div>
                        @endforeach
                    </div>
                </article>
            @endforeach
        </div>
        @if($products->isEmpty())
            <div class="commercial-card">{{ $ar ? 'لا توجد منتجات في هذا المتجر.' : 'No products exist in this store.' }}</div>
        @endif
    @else
        <header>
            <h1>{{ $ar ? 'Promotions > Flash Offers' : 'Promotions > Flash Offers' }}</h1>
            <p class="muted">{{ $ar ? 'دورة الحياة، التخصيص، الجمهور، القنوات، سياسة الـpopup، الحجز، الأولوية وpreflight تعتمد على #985.' : 'Lifecycle, allocation, audience, channels, popup policy, reservation, priority and preflight depend on #985.' }}</p>
        </header>
        <section class="commercial-card">
            <div class="control-row" style="border-top:0">
                <strong>{{ $ar ? 'إنشاء Flash Offer' : 'Create Flash Offer' }}</strong>
                <button type="button" class="foodex-primary disabled-action" disabled>{{ $ar ? 'بانتظار عقد #985' : 'Awaiting #985 contract' }}</button>
            </div>
            <p class="muted">{{ $ar ? 'لن يتم تحويل العروض العادية إلى Flash ولن يتم إنشاء جداول أو scheduler موازية.' : 'Existing normal promotions are not reclassified as Flash and no duplicate tables or scheduler are created.' }}</p>
        </section>

        <section class="commercial-card">
            <h2>{{ $ar ? 'العروض العادية الحالية' : 'Existing normal promotions' }}</h2>
            <div style="overflow:auto">
                <table class="commercial-table">
                    <thead><tr><th>{{ $ar ? 'الاسم' : 'Name' }}</th><th>{{ $ar ? 'النوع' : 'Type' }}</th><th>{{ $ar ? 'القيمة' : 'Value' }}</th><th>{{ $ar ? 'الفترة' : 'Period' }}</th><th>{{ $ar ? 'الحالة' : 'Status' }}</th></tr></thead>
                    <tbody>
                    @forelse($existingPromotions as $promotion)
                        <tr>
                            <td>{{ $promotion->name }}</td>
                            <td>{{ $promotion->type }}</td>
                            <td>{{ $promotion->value ?? '—' }}</td>
                            <td>{{ $promotion->starts_at ?: '—' }} → {{ $promotion->ends_at ?: '—' }}</td>
                            <td>{{ $promotion->is_active ? ($ar ? 'نشط' : 'Active') : ($ar ? 'غير نشط' : 'Inactive') }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="5">{{ $ar ? 'لا توجد عروض عادية حالياً.' : 'No normal promotions currently exist.' }}</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </section>
    @endif
</main>
</body>
</html>
