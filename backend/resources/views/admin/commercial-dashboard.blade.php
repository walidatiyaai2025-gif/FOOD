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
                    @php
                        $policy = $policies->get($product->id);
                        $units = $sellingUnits->get($product->id, collect())->map(fn($u)=>[
                            'unit_id'=>$u->unit_id,'code'=>$u->code,'name'=>$u->name,'conversion_factor'=>(float)$u->conversion_factor,
                            'price'=>$u->price,'sku'=>$u->sku,'barcode'=>$u->barcode,'is_base'=>(bool)$u->is_base,'is_active'=>(bool)$u->is_active,
                        ])->values();
                        $windows = $availabilityWindows->get($product->id, collect())->map(fn($w)=>[
                            'recurrence'=>$w->recurrence,'starts_at'=>$w->starts_at,'ends_at'=>$w->ends_at,
                            'start_month'=>$w->start_month,'start_day'=>$w->start_day,'end_month'=>$w->end_month,'end_day'=>$w->end_day,'is_active'=>(bool)$w->is_active,
                        ])->values();
                        $rules = $commercialRules->get($product->id, collect())->map(fn($r)=>[
                            'customer_id'=>$r->customer_id,'customer_group_id'=>$r->customer_group_id,'channel'=>$r->channel,'is_allowed'=>$r->is_allowed,
                            'max_per_order'=>$r->max_per_order,'max_per_day'=>$r->max_per_day,'max_per_week'=>$r->max_per_week,'max_per_month'=>$r->max_per_month,'max_lifetime'=>$r->max_lifetime,
                        ])->values();
                    @endphp
                    <form method="post" action="{{ route('admin.commercial.sales-control.save', ['product'=>$product->id] + $scope) }}" class="control-list">
                        @csrf @method('put')
                        <div class="control-row"><label>Status</label><select name="status">@foreach(['OPEN','RESTRICTED','CLOSED'] as $status)<option value="{{ $status }}" @selected(($policy->status ?? 'OPEN')===$status)>{{ $status }}</option>@endforeach</select></div>
                        <div class="control-row"><label>Channels JSON</label><input name="channels_json" value="{{ $policy->channels ?? '[&quot;customer&quot;,&quot;van&quot;,&quot;admin&quot;,&quot;api&quot;]' }}"></div>
                        <div class="control-row"><label>Timezone</label><input name="business_timezone" value="{{ $policy->business_timezone ?? 'Asia/Kuwait' }}"><label>Week starts</label><input type="number" min="0" max="6" name="week_starts_on" value="{{ $policy->week_starts_on ?? 1 }}"></div>
                        <div class="commercial-grid">
                            @foreach(['default_max_per_order'=>'Per order','default_max_per_day'=>'Day','default_max_per_week'=>'Week','default_max_per_month'=>'Month','default_max_lifetime'=>'Lifetime'] as $field=>$label)
                                <label>{{ $label }}<input type="number" step="0.001" min="0" name="{{ $field }}" value="{{ $policy?->{$field} }}"></label>
                            @endforeach
                        </div>
                        <label><input type="checkbox" name="hide_when_closed" value="1" @checked((bool)($policy->hide_when_closed ?? false))> Hide when closed</label>
                        <label><input type="checkbox" name="override_allowed" value="1" @checked((bool)($policy->override_allowed ?? false))> Override allowed (permission + audited reason required at order flow)</label>
                        <label>Selling units JSON<textarea name="selling_units_json" rows="5">{{ $units->toJson(JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES) }}</textarea></label>
                        <label>Availability windows JSON<textarea name="availability_windows_json" rows="5">{{ $windows->toJson(JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES) }}</textarea></label>
                        <label>Customer/group/channel rules JSON<textarea name="rules_json" rows="5">{{ $rules->toJson(JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES) }}</textarea></label>
                        <button type="submit" class="foodex-primary">{{ $ar ? 'حفظ التحكم التجاري' : 'Save Sales Control' }}</button>
                    </form>
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
            <h2>{{ $ar ? 'إنشاء / تعديل Flash Offer' : 'Create / Edit Flash Offer' }}</h2>
            <form method="post" action="{{ route('admin.commercial.flash-offers.save', $scope) }}" class="control-list">
                @csrf
                <input type="number" name="offer_id" placeholder="Offer ID (blank = new)">
                <div class="commercial-grid"><label>Name<input name="name" required></label><label>Status<select name="status">@foreach(['draft','scheduled','active','paused','sold_out','expired','cancelled','completed'] as $status)<option>{{ $status }}</option>@endforeach</select></label></div>
                <div class="commercial-grid"><label>Title AR<input name="title_ar" required></label><label>Title EN<input name="title_en" required></label></div>
                <div class="commercial-grid"><label>Body AR<textarea name="body_ar"></textarea></label><label>Body EN<textarea name="body_en"></textarea></label></div>
                <div class="commercial-grid"><label>Starts<input type="datetime-local" name="starts_at" required></label><label>Ends<input type="datetime-local" name="ends_at" required></label><label>Timezone<input name="timezone" value="Asia/Kuwait" required></label></div>
                <label>Channels JSON<input name="channels_json" value='["customer","van"]' required></label>
                <div class="commercial-grid"><label>Allocation mode<select name="allocation_mode"><option value="shared">shared</option><option value="reserved">reserved</option></select></label><label>Total allocation base<input type="number" step="0.001" name="total_allocation_base"></label><label>Per-customer base limit<input type="number" step="0.001" name="per_customer_limit_base"></label></div>
                <div class="commercial-grid"><label>Reservation seconds<input type="number" name="reservation_seconds" value="300" min="30"></label><label>Retry count<input type="number" name="retry_count" value="0" min="0"></label><label>Cooldown seconds<input type="number" name="cooldown_seconds" value="0" min="0"></label><label>Priority<input type="number" name="priority" value="0"></label></div>
                <label>Popup frequency<input name="popup_frequency" value="once_per_session"></label>
                <label><input type="checkbox" name="counts_toward_normal_quota" value="1" checked> Count toward normal quota</label>
                <label><input type="checkbox" name="stackable" value="1"> Stackable</label>
                <label><input type="checkbox" name="kill_switch" value="1"> Kill switch</label>
                <label>Products JSON<textarea name="products_json" rows="6" required>[{"product_id":1,"selling_unit_code":"carton","conversion_factor":10,"flash_price":7,"allocation_base":1000}]</textarea></label>
                <button type="submit" class="foodex-primary">{{ $ar ? 'حفظ العرض' : 'Save Flash Offer' }}</button>
            </form>
        </section>

        <section class="commercial-card">
            <h2>{{ $ar ? 'Flash Offers الحالية' : 'Current Flash Offers' }}</h2>
            @forelse($flashOffers as $offer)
                <div class="control-row">
                    <span>#{{ $offer->id }} · {{ $offer->name }} · <strong>{{ $offer->status }}</strong> · {{ $offer->starts_at }} → {{ $offer->ends_at }}</span>
                    <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
                        <a href="{{ route('admin.commercial.flash-offers.preview', ['offer'=>$offer->id] + $scope) }}">{{ $ar ? 'معاينة' : 'Preview' }}</a>
                        <a href="{{ route('admin.commercial.flash-offers.analytics', ['offer'=>$offer->id] + $scope) }}">{{ $ar ? 'التحليلات' : 'Analytics' }}</a>
                        <form method="post" action="{{ route('admin.commercial.flash-offers.action', ['offer'=>$offer->id] + $scope) }}">@csrf
                            <select name="action">@foreach(['schedule','activate','pause','resume','end','cancel','kill_on','kill_off'] as $action)<option>{{ $action }}</option>@endforeach</select>
                            <button type="submit">{{ $ar?'تنفيذ':'Apply' }}</button>
                        </form>
                    </div>
                </div>
            @empty
                <p class="muted">{{ $ar?'لا توجد Flash Offers.':'No Flash Offers yet.' }}</p>
            @endforelse
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
