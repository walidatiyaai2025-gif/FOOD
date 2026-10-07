@php
    $ar = app()->getLocale() === 'ar';
    $scope = ['store_id' => $storeId] + ($supportAccess ? ['support_access' => 1] : []);
    $featureFlags = $featureFlags ?? [];
    $canManageFeatureFlags = (bool) ($canManageFeatureFlags ?? false);
@endphp
<!doctype html>
<html lang="{{ app()->getLocale() }}" dir="{{ $ar ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>{{ $section === 'sales-control' ? ($ar ? 'التحكم التجاري للمنتجات' : 'Product Sales Control') : ($ar ? 'العروض السريعة' : 'Flash Offers') }} · FOODEX</title>
    @include('admin._brand-components')
    <style>
        body{margin:0;overflow-x:hidden;background:var(--foodex-background);color:var(--foodex-ink)}
        a{color:inherit}
        .commercial-admin-layout{direction:ltr;display:grid;grid-template-columns:minmax(0,1fr) var(--foodex-sidebar-width);min-height:100vh;background:var(--foodex-background)}
        .commercial-admin-layout>.sidebar{grid-column:2;grid-row:1;direction:rtl;background:var(--foodex-surface);border-inline-start:1px solid var(--foodex-border);min-height:100vh;position:relative;z-index:12}
        .commercial-admin-layout>.commercial-shell{grid-column:1;grid-row:1;direction:rtl;min-width:0}
        html[dir=ltr] .commercial-admin-layout{grid-template-columns:var(--foodex-sidebar-width) minmax(0,1fr)}
        html[dir=ltr] .commercial-admin-layout>.sidebar{grid-column:1;direction:ltr;border-inline-start:0;border-inline-end:1px solid var(--foodex-border)}
        html[dir=ltr] .commercial-admin-layout>.commercial-shell{grid-column:2;direction:ltr}
        .commercial-shell{width:100%;max-width:none!important;margin:0;padding:var(--foodex-space-6) clamp(var(--foodex-space-4),2vw,var(--foodex-space-8)) var(--foodex-space-8);display:grid;gap:var(--foodex-space-4)}
        .commercial-page-header{display:flex;align-items:flex-start;justify-content:space-between;gap:var(--foodex-space-4);flex-wrap:wrap}
        .commercial-page-header h1{margin:3px 0 6px;font-size:clamp(1.55rem,2.2vw,2rem);font-weight:800;line-height:1.2}
        .commercial-page-header p{margin:0;color:var(--foodex-muted);max-width:760px}
        .commercial-eyebrow{font-size:.76rem;font-weight:800;color:var(--foodex-green-dark);letter-spacing:.02em}
        .commercial-tabs{display:flex;gap:8px;flex-wrap:wrap;align-items:center;background:var(--foodex-surface);border:1px solid var(--foodex-border);border-radius:var(--foodex-radius-card);padding:8px;box-shadow:var(--foodex-shadow-sm)}
        .commercial-tabs a{min-height:40px;padding:0 14px;border:1px solid transparent;border-radius:var(--foodex-radius-control);text-decoration:none;font-weight:800;display:inline-flex;align-items:center;justify-content:center;color:var(--foodex-muted)}
        .commercial-tabs a:hover{background:var(--foodex-green-soft);color:var(--foodex-green-dark)}
        .commercial-tabs a.active{background:var(--foodex-green);color:#fff;border-color:var(--foodex-green);box-shadow:0 8px 18px rgba(21,138,58,.14)}
        .contract-banner{padding:14px 16px;border:1px solid #cfe5d6;border-radius:var(--foodex-radius-card);background:linear-gradient(135deg,var(--foodex-green-soft),#fff);box-shadow:var(--foodex-shadow-sm);display:grid;grid-template-columns:auto minmax(0,1fr);gap:10px 14px;align-items:center}
        .contract-banner strong{margin:0;color:var(--foodex-green-dark)}
        .contract-banner.pending{border-style:dashed;background:#fffaf1}
        .muted{color:var(--foodex-muted)}
        .commercial-card{padding:var(--foodex-space-5);border:1px solid var(--foodex-border);border-radius:var(--foodex-radius-card);background:var(--foodex-surface);box-shadow:var(--foodex-shadow-sm)}
        .commercial-card h2,.commercial-card h3{margin-top:0}
        .feature-flags-card{display:grid;gap:14px}
        .feature-flags-head{display:flex;justify-content:space-between;gap:12px;align-items:flex-start;flex-wrap:wrap}
        .feature-flags-head h2{margin:0;font-size:1.05rem}
        .feature-flag-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px}
        .feature-flag{display:flex;align-items:center;gap:9px;min-height:54px;padding:10px 12px;border:1px solid var(--foodex-border);border-radius:12px;background:#fbfcfd;font-weight:700}
        .feature-flag input{width:18px;height:18px;accent-color:var(--foodex-green)}
        .feature-flag .flag-copy{display:grid;gap:2px}.feature-flag small{color:var(--foodex-muted);font-weight:500}
        .feature-save{display:flex;justify-content:flex-end;margin-top:10px}
        .commercial-product-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(460px,1fr));gap:14px}
        .commercial-product{padding:0;overflow:hidden}
        .commercial-product-head{display:flex;justify-content:space-between;gap:12px;align-items:center;padding:16px 18px;border-bottom:1px solid var(--foodex-border);background:linear-gradient(145deg,#fff,#fbfcfd)}
        .commercial-product-head h3{margin:0 0 4px;font-size:1rem}.commercial-product-body{padding:18px;display:grid;gap:16px}
        .commercial-status{display:inline-flex;padding:5px 9px;border-radius:999px;background:var(--foodex-green-soft);color:var(--foodex-green-dark);font-size:.72rem;font-weight:800}
        .policy-section{border:1px solid var(--foodex-border);border-radius:12px;padding:14px;background:#fff;display:grid;gap:12px}
        .policy-section-title{display:flex;justify-content:space-between;gap:10px;align-items:center;font-weight:800;font-size:.88rem}
        .commercial-form-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}
        .commercial-form-grid.five{grid-template-columns:repeat(5,minmax(0,1fr))}
        .commercial-form-grid label,.policy-section>label{display:grid;gap:6px;font-weight:700;font-size:.8rem}
        .commercial-form-grid input,.commercial-form-grid select,.policy-section input,.policy-section select,.policy-section textarea{width:100%}
        .commercial-choice-grid{display:flex;gap:8px;flex-wrap:wrap}
        .commercial-choice{display:inline-flex!important;grid-template-columns:auto 1fr!important;align-items:center;gap:7px!important;padding:8px 10px;border:1px solid var(--foodex-border);border-radius:10px;background:#fbfcfd;font-size:.78rem!important}
        .commercial-choice input{width:16px!important;height:16px!important;accent-color:var(--foodex-green)}
        .commercial-toggles{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}
        .commercial-advanced{border:1px dashed var(--foodex-border);border-radius:12px;background:#fbfcfd}
        .commercial-advanced summary{cursor:pointer;padding:12px 14px;font-weight:800;color:var(--foodex-green-dark)}
        .commercial-advanced-body{padding:0 14px 14px;display:grid;gap:12px}
        .commercial-advanced textarea{font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:.76rem;min-height:110px}
        .commercial-empty{min-height:220px;display:grid;place-items:center;text-align:center;border:1px dashed var(--foodex-border);border-radius:var(--foodex-radius-card);background:linear-gradient(145deg,#fff,#fbfcfd);padding:var(--foodex-space-6)}
        .commercial-empty strong{display:block;font-size:1.05rem;margin-bottom:6px}.commercial-empty p{margin:0;color:var(--foodex-muted)}
        .commercial-table{width:100%;border-collapse:collapse}.commercial-table th,.commercial-table td{padding:11px;border-bottom:1px solid var(--foodex-border);text-align:start}
        .commercial-policy-form.foodex-premium-auto-form,.feature-flags-card form.foodex-premium-auto-form{display:grid!important;grid-template-columns:1fr!important;background:transparent!important;border:0!important;padding:0!important;box-shadow:none!important;gap:12px!important}
        @media(max-width:1100px){.feature-flag-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.commercial-form-grid.five{grid-template-columns:repeat(2,minmax(0,1fr))}}
        @media(max-width:1023px){.commercial-admin-layout,.commercial-admin-layout:has(>.sidebar.foodex-sidebar-collapsed){grid-template-columns:1fr!important}.commercial-admin-layout>.sidebar,.commercial-admin-layout>.commercial-shell{grid-column:1!important;grid-row:auto!important}.commercial-admin-layout>.sidebar{min-height:auto}.commercial-shell{padding:14px}.commercial-product-grid{grid-template-columns:1fr}}
        @media(max-width:680px){.feature-flag-grid,.commercial-form-grid,.commercial-form-grid.five,.commercial-toggles{grid-template-columns:1fr}.contract-banner{grid-template-columns:1fr}.commercial-card{padding:14px}.commercial-product-head{align-items:flex-start;flex-direction:column}}
    </style>
</head>
<body>
<div class="foodex-admin-layout commercial-admin-layout" data-commercial-page="{{ $section }}">
    <aside class="sidebar">@include('admin._sidebar', ['user' => $user, 'navGroups' => $navGroups, 'navContext' => $navContext])</aside>
    <main class="foodex-admin-main foodex-admin-page commercial-shell">
        <header class="commercial-page-header">
            <div>
                <span class="commercial-eyebrow">FOODEX · {{ $ar ? 'التجارة والمبيعات' : 'Commercial & Sales' }}</span>
                <h1>{{ $section === 'sales-control' ? ($ar ? 'التحكم التجاري للمنتجات' : 'Product Sales Control') : ($ar ? 'العروض السريعة' : 'Flash Offers') }}</h1>
                <p>{{ $section === 'sales-control'
                    ? ($ar ? 'إدارة إتاحة المنتجات ووحدات البيع والحصص والقنوات وسياسة فك العبوة من واجهة تشغيل موحدة.' : 'Manage product availability, selling units, quotas, channels and break-pack policy from one operational workspace.')
                    : ($ar ? 'إدارة دورة حياة العروض السريعة والجمهور والتخصيص والحجز والتحليلات.' : 'Manage flash-offer lifecycle, audience, allocation, reservations and analytics.') }}</p>
            </div>
            @include('admin._live-notifications', ['user' => $user])
        </header>
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

    <section class="commercial-card feature-flags-card" data-commercial-feature-flags>
        <div class="feature-flags-head">
            <div>
                <h2>{{ $ar ? 'حالة الوظائف التجارية' : 'Commercial capabilities' }}</h2>
                <p class="muted">{{ $ar ? 'مفاتيح مركزية يفرضها الخادم على كل القنوات.' : 'Server-authoritative switches enforced consistently across channels.' }}</p>
            </div>
            <span class="commercial-status">{{ $ar ? 'إعداد مركزي' : 'Central policy' }}</span>
        </div>
        @if($canManageFeatureFlags)
            <form method="post" action="{{ route('admin.commercial.feature-flags.save', $scope) }}" class="control-list">
                @csrf @method('put')
                <div class="feature-flag-grid">
                @foreach([
                    'commercial_rules_enabled' => [$ar ? 'قواعد البيع' : 'Commercial rules', $ar ? 'الأهلية والحصص' : 'Eligibility & quotas'],
                    'flash_offers_enabled' => [$ar ? 'العروض السريعة' : 'Flash offers', $ar ? 'عروض محدودة' : 'Limited offers'],
                    'customer_flash_popup_enabled' => [$ar ? 'نافذة عروض العميل' : 'Customer Flash popup', $ar ? 'ظهور داخل تطبيق العميل' : 'Customer app popup'],
                    'van_offers_enabled' => [$ar ? 'عروض الفان' : 'Van offers', $ar ? 'العروض داخل تطبيق الفان' : 'Van app offers'],
                ] as $flagKey => $flagMeta)
                    <label class="feature-flag">
                        <input type="hidden" name="{{ $flagKey }}" value="0">
                        <input type="checkbox" name="{{ $flagKey }}" value="1" @checked((bool)($featureFlags[$flagKey] ?? false))>
                        <span class="flag-copy"><span>{{ $flagMeta[0] }}</span><small>{{ $flagMeta[1] }}</small></span>
                    </label>
                @endforeach
                </div>
                <div class="feature-save"><button type="submit" class="foodex-primary">{{ $ar ? 'حفظ حالة الوظائف' : 'Save capability state' }}</button></div>
            </form>
        @else
            <div class="commercial-grid">
                @foreach([
                    'commercial_rules_enabled' => 'Commercial rules',
                    'flash_offers_enabled' => 'Flash offers',
                    'customer_flash_popup_enabled' => 'Customer Flash popup',
                    'van_offers_enabled' => 'Van offers',
                ] as $flagKey => $flagLabel)
                    <div><strong>{{ $flagLabel }}</strong>: {{ ($featureFlags[$flagKey] ?? false) ? 'ON' : 'OFF' }}</div>
                @endforeach
            </div>
        @endif
    </section>

    @if($section === 'sales-control')
        <section class="commercial-card">
            <div class="feature-flags-head">
                <div>
                    <h2>{{ $ar ? 'سياسات المنتجات' : 'Product policies' }}</h2>
                    <p class="muted">{{ $ar ? 'اضبط الإتاحة والقنوات ووحدة البيع والحصص لكل منتج بدون تغيير منطق الخادم.' : 'Configure availability, channels, selling units and quotas per product without changing backend authority.' }}</p>
                </div>
                <span class="commercial-status">{{ $products->count() }} {{ $ar ? 'منتج' : 'products' }}</span>
            </div>
        </section>
        <div class="commercial-product-grid">
            @foreach($products as $product)
                <article class="commercial-card commercial-product" data-product-id="{{ $product->id }}">
                    <div class="commercial-product-head">
                        <div><h3>{{ $product->name }}</h3><div class="muted">{{ $product->sku ?: '—' }}</div></div>
                        <span class="commercial-status">{{ $product->is_active ? ($ar ? 'نشط' : 'Active') : ($ar ? 'غير نشط' : 'Inactive') }}</span>
                    </div>
                    <div class="commercial-product-body">
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
                    <form method="post" action="{{ route('admin.commercial.sales-control.save', ['product'=>$product->id] + $scope) }}" class="commercial-policy-form">
                        @csrf @method('put')
                        @php($selectedChannels = collect(json_decode((string)($policy->channels ?? '["customer","van","admin","api"]'), true) ?: []))
                        <section class="policy-section">
                            <div class="policy-section-title"><span>{{ $ar ? 'الإتاحة والقنوات' : 'Availability & channels' }}</span></div>
                            <div class="commercial-form-grid">
                                <label>{{ $ar ? 'حالة البيع' : 'Sales status' }}<select name="status">@foreach(['OPEN','RESTRICTED','CLOSED'] as $status)<option value="{{ $status }}" @selected(($policy->status ?? 'OPEN')===$status)>{{ $status }}</option>@endforeach</select></label>
                                <label>{{ $ar ? 'المنطقة الزمنية' : 'Business timezone' }}<input name="business_timezone" value="{{ $policy->business_timezone ?? 'Asia/Kuwait' }}"></label>
                            </div>
                            <div class="commercial-choice-grid" data-commercial-channel-picker>
                                @foreach(['customer'=>($ar?'العميل':'Customer'),'van'=>($ar?'الفان':'Van'),'admin'=>($ar?'لوحة الإدارة':'Admin'),'api'=>'API'] as $channel=>$channelLabel)
                                    <label class="commercial-choice"><input type="checkbox" value="{{ $channel }}" data-commercial-channel @checked($selectedChannels->contains($channel))><span>{{ $channelLabel }}</span></label>
                                @endforeach
                                <input type="hidden" name="channels_json" data-commercial-channels-json value="{{ $policy->channels ?? '[&quot;customer&quot;,&quot;van&quot;,&quot;admin&quot;,&quot;api&quot;]' }}">
                            </div>
                        </section>

                        <section class="policy-section">
                            <div class="policy-section-title"><span>{{ $ar ? 'وحدة البيع وفك العبوة' : 'Selling unit & break-pack' }}</span></div>
                            <div class="commercial-form-grid">
                                <label>{{ $ar ? 'سياسة فك العبوة' : 'Break-pack policy' }}
                                    <select name="break_pack_policy">
                                        @foreach(['mixed','full-pack-only','loose-only','one-unit-type'] as $mode)
                                            <option value="{{ $mode }}" @selected(($policy->break_pack_policy ?? 'mixed') === $mode)>{{ $mode }}</option>
                                        @endforeach
                                    </select>
                                </label>
                                <label>{{ $ar ? 'كود الوحدة الإلزامية' : 'One-unit-type code' }}<input name="break_pack_unit_code" value="{{ $policy->break_pack_unit_code ?? '' }}" placeholder="{{ $ar ? 'مثال: CARTON' : 'e.g. CARTON' }}"></label>
                            </div>
                        </section>

                        <section class="policy-section">
                            <div class="policy-section-title"><span>{{ $ar ? 'الحصص الافتراضية' : 'Default quotas' }}</span><span class="muted">{{ $ar ? 'اترك الحقل فارغًا لعدم وضع حد' : 'Leave blank for no limit' }}</span></div>
                            <div class="commercial-form-grid five">
                                @foreach([
                                    'default_max_per_order'=>($ar?'لكل طلب':'Per order'),
                                    'default_max_per_day'=>($ar?'يومي':'Per day'),
                                    'default_max_per_week'=>($ar?'أسبوعي':'Per week'),
                                    'default_max_per_month'=>($ar?'شهري':'Per month'),
                                    'default_max_lifetime'=>($ar?'إجمالي':'Lifetime'),
                                ] as $field=>$label)
                                    <label>{{ $label }}<input type="number" step="0.001" min="0" name="{{ $field }}" value="{{ $policy?->{$field} }}"></label>
                                @endforeach
                            </div>
                            <div class="commercial-form-grid">
                                <label>{{ $ar ? 'بداية الأسبوع (0-6)' : 'Week starts on (0-6)' }}<input type="number" min="0" max="6" name="week_starts_on" value="{{ $policy->week_starts_on ?? 1 }}"></label>
                            </div>
                        </section>

                        <section class="policy-section">
                            <div class="policy-section-title"><span>{{ $ar ? 'سلوك الإغلاق والتجاوز' : 'Close & override behavior' }}</span></div>
                            <div class="commercial-toggles">
                                <label class="commercial-choice"><input type="checkbox" name="hide_when_closed" value="1" @checked((bool)($policy->hide_when_closed ?? false))><span>{{ $ar ? 'إخفاء المنتج عند الإغلاق' : 'Hide product when closed' }}</span></label>
                                <label class="commercial-choice"><input type="checkbox" name="override_allowed" value="1" @checked((bool)($policy->override_allowed ?? false))><span>{{ $ar ? 'السماح بالتجاوز بصلاحية وسبب مدقق' : 'Allow permissioned, audited override' }}</span></label>
                            </div>
                        </section>

                        <details class="commercial-advanced">
                            <summary>{{ $ar ? 'إعدادات متقدمة: وحدات البيع ونوافذ الإتاحة وقواعد الاستهداف' : 'Advanced: selling units, availability windows & targeting rules' }}</summary>
                            <div class="commercial-advanced-body">
                                <label>{{ $ar ? 'وحدات البيع (JSON)' : 'Selling units (JSON)' }}<textarea name="selling_units_json" rows="5">{{ $units->toJson(JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES) }}</textarea></label>
                                <label>{{ $ar ? 'نوافذ الإتاحة (JSON)' : 'Availability windows (JSON)' }}<textarea name="availability_windows_json" rows="5">{{ $windows->toJson(JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES) }}</textarea></label>
                                <label>{{ $ar ? 'قواعد العميل/المجموعة/القناة (JSON)' : 'Customer/group/channel rules (JSON)' }}<textarea name="rules_json" rows="5">{{ $rules->toJson(JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES) }}</textarea></label>
                            </div>
                        </details>
                        <button type="submit" class="foodex-primary">{{ $ar ? 'حفظ سياسة المنتج' : 'Save product policy' }}</button>
                    </form>
                    </div>
                </article>
            @endforeach
        </div>
        @if($products->isEmpty())
            <div class="commercial-empty">
                <div>
                    <strong>{{ $ar ? 'لا توجد منتجات قابلة للإدارة بعد' : 'No products are available for sales control yet' }}</strong>
                    <p>{{ $ar ? 'أضف منتجات إلى كتالوج المتجر أولاً، ثم ارجع لضبط الإتاحة والحصص والقنوات.' : 'Add products to the store catalog first, then return here to configure availability, quotas and channels.' }}</p>
                    <div style="margin-top:14px"><a class="foodex-primary" href="{{ route('admin.b2c.module', ['module'=>'products'] + $scope) }}">{{ $ar ? 'فتح المنتجات' : 'Open products' }}</a></div>
                </div>
            </div>
        @endif
    @else
        <header>
            <h1>{{ $ar ? 'Promotions > Flash Offers' : 'Promotions > Flash Offers' }}</h1>
            <p class="muted">{{ $ar ? 'دورة الحياة، التخصيص، الجمهور، القنوات، سياسة الـpopup، الحجز، الأولوية وpreflight تعتمد على #985.' : 'Lifecycle, allocation, audience, channels, popup policy, reservation, priority and preflight depend on #985.' }}</p>
        </header>
        <section class="commercial-card">
            <h2>{{ $ar ? 'إنشاء / تعديل Flash Offer' : 'Create / Edit Flash Offer' }}</h2>
            @if($storeId > 0)
            <form method="post" action="{{ route('admin.commercial.flash-offers.save', $scope) }}" class="control-list">
                @csrf
                <input type="number" name="offer_id" placeholder="Offer ID (blank = new)">
                <div class="commercial-grid"><label>Name<input name="name" required></label><label>Status<select name="status">@foreach(['draft','scheduled','active','paused','sold_out','expired','cancelled','completed'] as $status)<option>{{ $status }}</option>@endforeach</select></label></div>
                <div class="commercial-grid"><label>Title AR<input name="title_ar" required></label><label>Title EN<input name="title_en" required></label></div>
                <div class="commercial-grid"><label>Body AR<textarea name="body_ar"></textarea></label><label>Body EN<textarea name="body_en"></textarea></label></div>
                <div class="commercial-grid"><label>Starts<input type="datetime-local" name="starts_at" required></label><label>Ends<input type="datetime-local" name="ends_at" required></label><label>Timezone<input name="timezone" value="Asia/Kuwait" required></label></div>
                <label>Channels JSON<input name="channels_json" value='["customer","van"]' required></label>
                <div class="commercial-grid">
                    <label>Audience customer IDs JSON<textarea name="audience_customer_ids_json" rows="3">[]</textarea></label>
                    <label>Audience customer-group IDs JSON<textarea name="audience_customer_group_ids_json" rows="3">[]</textarea></label>
                    <label>Audience regions JSON<textarea name="audience_regions_json" rows="3">[]</textarea></label>
                    <label>Audience routes JSON<textarea name="audience_routes_json" rows="3">[]</textarea></label>
                </div>
                <p class="muted">{{ $ar ? 'عند تحديد أكثر من بُعد جمهور، يجب أن يطابق العميل جميع الأبعاد المحددة. اترك [] للجمهور المفتوح.' : 'When multiple audience dimensions are configured, the customer must match all configured dimensions. Use [] for an unrestricted dimension.' }}</p>
                <div class="commercial-grid"><label>Allocation mode<select name="allocation_mode"><option value="shared">shared</option><option value="reserved">reserved</option></select></label><label>Total allocation base<input type="number" step="0.001" name="total_allocation_base"></label><label>Per-customer base limit<input type="number" step="0.001" name="per_customer_limit_base"></label></div>
                <div class="commercial-grid"><label>Reservation seconds<input type="number" name="reservation_seconds" value="300" min="30"></label><label>Retry count<input type="number" name="retry_count" value="0" min="0"></label><label>Cooldown seconds<input type="number" name="cooldown_seconds" value="0" min="0"></label><label>Priority<input type="number" name="priority" value="0"></label></div>
                <label>Popup frequency<input name="popup_frequency" value="once_per_session"></label>
                <label><input type="checkbox" name="counts_toward_normal_quota" value="1" checked> Count toward normal quota</label>
                <label><input type="checkbox" name="stackable" value="1"> Stackable</label>
                <label><input type="checkbox" name="kill_switch" value="1"> Kill switch</label>
                <label>Products JSON<textarea name="products_json" rows="6" required>[{"product_id":1,"selling_unit_code":"carton","conversion_factor":10,"flash_price":7,"allocation_base":1000}]</textarea></label>
                <button type="submit" class="foodex-primary">{{ $ar ? 'حفظ العرض' : 'Save Flash Offer' }}</button>
            </form>
            @else
                <p class="muted">{{ $ar ? 'الخطة التجارية مفعلة. أنشئ متجر تجزئة أولاً لإضافة عروض Flash.' : 'The commercial plan is enabled. Create a Retail store before adding Flash Offers.' }}</p>
            @endif
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
</div>
<script>
(() => {
    document.querySelectorAll('[data-commercial-channel-picker]').forEach((picker) => {
        const output = picker.querySelector('[data-commercial-channels-json]');
        const boxes = [...picker.querySelectorAll('[data-commercial-channel]')];
        const sync = () => {
            if (output) output.value = JSON.stringify(boxes.filter((box) => box.checked).map((box) => box.value));
        };
        boxes.forEach((box) => box.addEventListener('change', sync));
        sync();
    });
})();
</script>
</body>
</html>
