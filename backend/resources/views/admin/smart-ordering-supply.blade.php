<!doctype html>
<html lang="{{ app()->getLocale() }}" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
    <title>{{ app()->getLocale() === 'ar' ? 'ذكاء توريد FOODEX' : 'FOODEX Supply Intelligence' }}</title>
    @include('admin._brand-components')
    @include('admin._settings-page-polish')
    <style>
        .si-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,300px),1fr));gap:16px}
        .si-kpis{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:12px}
        .si-kpi{padding:14px;border:1px solid var(--foodex-border,#dbe2e8);border-radius:14px}
        .si-kpi strong{display:block;font-size:1.45rem}
        .si-table-wrap{overflow:auto}
        .si-table{width:100%;border-collapse:collapse;min-width:880px}
        .si-table th,.si-table td{padding:10px;border-bottom:1px solid var(--foodex-border,#e5e7eb);text-align:start;vertical-align:top}
        .si-note{padding:14px;border:1px solid #c9a227;border-radius:14px;background:rgba(201,162,39,.08);font-weight:650}
        .si-badge{display:inline-flex;padding:3px 9px;border-radius:999px;background:rgba(127,127,127,.12);font-size:.82rem}
        .si-actions{display:flex;gap:8px;flex-wrap:wrap;align-items:center}
        .si-muted{opacity:.72}
        .si-form{display:grid;gap:12px}
        .si-form label{display:grid;gap:6px}
        .si-danger{border:1px solid #b91c1c;color:#b91c1c;background:transparent;border-radius:10px;padding:8px 12px}
        .si-good{font-weight:700}
    </style>
</head>
<body>
@php
    $isAr = app()->getLocale() === 'ar';
    $itemIndex = collect($menuItems)->keyBy('public_id');
@endphp
<div class="foodex-admin-layout" data-foodex-mobile-web-page="smart-ordering-supply">
    <aside class="sidebar">@include('admin._sidebar', ['navGroups' => app(\App\Support\AdminNavigation::class)->groupsFor(auth()->user()), 'navContext' => 'smart_ordering', 'user' => auth()->user()])</aside>
    <main class="foodex-admin-main foodex-admin-page foodex-settings-surface">
        <header class="foodex-page-header">
            <div>
                <h1>{{ $isAr ? 'ذكاء توريد FOODEX' : 'FOODEX Supply Intelligence' }}</h1>
                <p>{{ $isAr ? 'ربط وصفات المنيو باستهلاك تقديري قابل للتفسير مع إعادة طلب آمنة كمسودة فقط.' : 'Map menu recipes to explainable estimated consumption and safe draft-only replenishment.' }}</p>
            </div>
            <a class="foodex-action-primary" href="{{ route('admin.smart-ordering.index',['store_id'=>$site->store_id]) }}">{{ $isAr ? 'إعدادات Smart Ordering' : 'Smart Ordering settings' }}</a>
        </header>

        @if(session('status'))<p class="foodex-alert success" role="status">{{ session('status') }}</p>@endif
        @if($errors->any())<div class="foodex-alert error" role="alert">{{ $errors->first() }}</div>@endif

        <section class="panel">
            <div class="si-note">
                {{ $isAr
                    ? 'تنبيه: كل أرقام الاستهلاك هنا تقديرية ومشتقة من طلبات Smart Ordering المكتملة. ليست جرداً فعلياً ولا رصيد مخزون محسوباً. لا يتم تنفيذ شراء تلقائي أبداً.'
                    : 'Important: every consumption figure here is an estimate derived from completed Smart Ordering orders. It is not counted/physical inventory. Purchases are never submitted automatically.' }}
            </div>
        </section>

        <section class="panel">
            <h2>{{ $isAr ? 'الفترة ونطاق الفرع' : 'Period & branch scope' }}</h2>
            <form class="si-grid" method="get" action="{{ route('admin.smart-ordering.supply.index',$site) }}">
                <label>{{ $isAr ? 'من' : 'From' }}<input class="foodex-control" type="date" name="from" value="{{ $from->toDateString() }}"></label>
                <label>{{ $isAr ? 'إلى' : 'To' }}<input class="foodex-control" type="date" name="to" value="{{ $to->toDateString() }}"></label>
                <label>{{ $isAr ? 'الفرع' : 'Branch' }}
                    <select class="foodex-control" name="branch_id">
                        <option value="">{{ $isAr ? 'كل الفروع' : 'All branches' }}</option>
                        @foreach($branches as $branch)
                            <option value="{{ $branch->id }}" @selected($branchId === (int)$branch->id)>{{ $isAr ? ($branch->name_ar ?: $branch->name_en) : ($branch->name_en ?: $branch->name_ar) }}</option>
                        @endforeach
                    </select>
                </label>
                <div class="si-actions"><button class="primary" type="submit">{{ $isAr ? 'تحديث التحليل' : 'Refresh analysis' }}</button></div>
            </form>
        </section>

        <section class="panel">
            <h2>{{ $isAr ? 'تغطية الاستهلاك التقديري' : 'Estimated-consumption coverage' }}</h2>
            <div class="si-kpis">
                <div class="si-kpi"><span>{{ $isAr ? 'طلبات مكتملة' : 'Completed orders' }}</span><strong>{{ $consumption['total_completed_orders'] }}</strong></div>
                <div class="si-kpi"><span>{{ $isAr ? 'سطور مغطاة' : 'Mapped lines' }}</span><strong>{{ $consumption['mapped_lines'] }}/{{ $consumption['total_lines'] }}</strong></div>
                <div class="si-kpi"><span>{{ $isAr ? 'التغطية' : 'Coverage' }}</span><strong>{{ number_format($consumption['coverage']*100,1) }}%</strong></div>
                <div class="si-kpi"><span>{{ $isAr ? 'الثقة' : 'Confidence' }}</span><strong>{{ strtoupper($consumption['confidence']) }}</strong></div>
            </div>
        </section>

        <div class="si-grid">
            <section class="panel">
                <h2>{{ $isAr ? 'ربط صنف المنيو بمكونات FOODEX' : 'Map menu item to FOODEX ingredients' }}</h2>
                <p class="si-muted">{{ $isAr ? 'يمكن ربط الطبق بأكثر من SKU. عامل الهدر 0.05 يعني 5%.' : 'A dish may map to multiple SKUs. Waste factor 0.05 means 5%.' }}</p>
                <form class="si-form" method="post" action="{{ route('admin.smart-ordering.supply.mappings.store',$site) }}">
                    @csrf
                    <label>{{ $isAr ? 'صنف المنيو' : 'Menu item' }}
                        <select class="foodex-control" name="menu_item_public_id" required>
                            @foreach($menuItems as $item)
                                <option value="{{ $item['public_id'] }}">{{ $isAr ? ($item['name_ar'] ?: $item['name_en']) : ($item['name_en'] ?: $item['name_ar']) }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label>{{ $isAr ? 'الفرع (اختياري)' : 'Branch (optional)' }}
                        <select class="foodex-control" name="branch_id">
                            <option value="">{{ $isAr ? 'كل الفروع' : 'All branches' }}</option>
                            @foreach($branches as $branch)<option value="{{ $branch->id }}">{{ $isAr ? ($branch->name_ar ?: $branch->name_en) : ($branch->name_en ?: $branch->name_ar) }}</option>@endforeach
                        </select>
                    </label>
                    <label>{{ $isAr ? 'FOODEX SKU' : 'FOODEX SKU' }}
                        <select class="foodex-control" name="foodex_product_id" required>
                            @foreach($foodexProducts as $product)
                                <option value="{{ $product['id'] }}">{{ $product['sku'] }} — {{ $product['name'] }} ({{ number_format($product['available_quantity'],3) }})</option>
                            @endforeach
                        </select>
                    </label>
                    <label>{{ $isAr ? 'Variant Product ID (اختياري)' : 'Variant product ID (optional)' }}<input class="foodex-control" type="number" min="1" name="variant_product_id"></label>
                    <div class="si-grid">
                        <label>{{ $isAr ? 'كمية المكوّن لكل بيع' : 'Ingredient quantity per sale' }}<input class="foodex-control" type="number" step="0.000001" min="0.000001" name="quantity_per_item" required></label>
                        <label>{{ $isAr ? 'عامل الهدر' : 'Waste factor' }}<input class="foodex-control" type="number" step="0.000001" min="0" max="1" name="waste_factor" value="0"></label>
                    </div>
                    <div class="si-grid">
                        <label>{{ $isAr ? 'ساري من' : 'Effective from' }}<input class="foodex-control" type="datetime-local" name="effective_from"></label>
                        <label>{{ $isAr ? 'ساري حتى' : 'Effective until' }}<input class="foodex-control" type="datetime-local" name="effective_to"></label>
                    </div>
                    <button class="primary" type="submit">{{ $isAr ? 'حفظ الربط' : 'Save mapping' }}</button>
                </form>
            </section>

            <section class="panel">
                <h2>{{ $isAr ? 'رصيد موثق اختياري' : 'Optional authoritative stock' }}</h2>
                <p class="si-muted">{{ $isAr ? 'أدخل الرصيد فقط إذا كان فعلياً ومصدره معروفاً. بدونه يعرض النظام اتجاه الاستهلاك فقط ولا يدّعي Days of Supply.' : 'Enter stock only when it is authoritative and sourced. Without it, the system shows consumption trend only and does not claim days of supply.' }}</p>
                <form class="si-form" method="post" action="{{ route('admin.smart-ordering.supply.stock',$site) }}">
                    @csrf @method('PUT')
                    <label>FOODEX SKU
                        <select class="foodex-control" name="foodex_product_id" required>
                            @foreach($foodexProducts as $product)<option value="{{ $product['id'] }}">{{ $product['sku'] }} — {{ $product['name'] }}</option>@endforeach
                        </select>
                    </label>
                    <label>{{ $isAr ? 'الفرع' : 'Branch' }}
                        <select class="foodex-control" name="branch_id"><option value="">{{ $isAr ? 'كل الفروع' : 'All branches' }}</option>@foreach($branches as $branch)<option value="{{ $branch->id }}">{{ $isAr ? ($branch->name_ar ?: $branch->name_en) : ($branch->name_en ?: $branch->name_ar) }}</option>@endforeach</select>
                    </label>
                    <label>{{ $isAr ? 'الكمية الموجودة فعلياً' : 'Authoritative on-hand quantity' }}<input class="foodex-control" type="number" step="0.000001" min="0" name="on_hand_quantity" required></label>
                    <label>{{ $isAr ? 'المصدر' : 'Source' }}<input class="foodex-control" name="source" placeholder="{{ $isAr ? 'جرد يدوي / ERP / WMS' : 'Physical count / ERP / WMS' }}" required></label>
                    <label>{{ $isAr ? 'وقت الجرد' : 'Counted at' }}<input class="foodex-control" type="datetime-local" name="counted_at" value="{{ now('Asia/Kuwait')->format('Y-m-d\TH:i') }}" required></label>
                    <button class="primary" type="submit">{{ $isAr ? 'حفظ الرصيد الموثق' : 'Save authoritative stock' }}</button>
                </form>
            </section>
        </div>

        <section class="panel">
            <h2>{{ $isAr ? 'الروابط الحالية والتاريخية' : 'Current & historical mappings' }}</h2>
            <div class="si-table-wrap"><table class="si-table">
                <thead><tr><th>{{ $isAr?'صنف المنيو':'Menu item' }}</th><th>FOODEX SKU</th><th>{{ $isAr?'الفرع':'Branch' }}</th><th>{{ $isAr?'عامل التحويل':'Conversion' }}</th><th>{{ $isAr?'الهدر':'Waste' }}</th><th>{{ $isAr?'الصلاحية':'Validity' }}</th><th></th></tr></thead>
                <tbody>
                @forelse($mappings as $mapping)
                    @php($menuItem = $itemIndex->get($mapping['menu_item_public_id']))
                    <tr>
                        <td>{{ $menuItem ? ($isAr ? ($menuItem['name_ar'] ?: $menuItem['name_en']) : ($menuItem['name_en'] ?: $menuItem['name_ar'])) : $mapping['menu_item_public_id'] }}</td>
                        <td>{{ $mapping['foodex_sku'] }} — {{ $mapping['foodex_name'] }} @if(!$mapping['catalog_active'])<span class="si-badge">{{ $isAr?'غير نشط':'inactive' }}</span>@endif</td>
                        <td>{{ $mapping['branch_id'] ? ($isAr ? ($mapping['branch_name_ar'] ?: $mapping['branch_name_en']) : ($mapping['branch_name_en'] ?: $mapping['branch_name_ar'])) : ($isAr?'كل الفروع':'All') }}</td>
                        <td>{{ number_format($mapping['quantity_per_item'],6) }}</td>
                        <td>{{ number_format($mapping['waste_factor']*100,2) }}%</td>
                        <td>{{ $mapping['effective_from'] }} → {{ $mapping['effective_to'] ?: '∞' }}<br><span class="si-badge">{{ $mapping['is_active'] ? ($isAr?'نشط':'active') : ($isAr?'تاريخي':'historical') }}</span></td>
                        <td>
                            @if($mapping['is_active'])
                            <form method="post" action="{{ route('admin.smart-ordering.supply.mappings.deactivate',[$site,$mapping['public_id']]) }}">@csrf<button class="si-danger" type="submit">{{ $isAr?'إنهاء':'End' }}</button></form>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7">{{ $isAr ? 'لا توجد روابط بعد.' : 'No supply mappings yet.' }}</td></tr>
                @endforelse
                </tbody>
            </table></div>
        </section>

        <section class="panel">
            <h2>{{ $isAr ? 'الاستهلاك التقديري حسب FOODEX SKU' : 'Estimated consumption by FOODEX SKU' }}</h2>
            <div class="si-table-wrap"><table class="si-table">
                <thead><tr><th>SKU</th><th>{{ $isAr?'المنتج':'Product' }}</th><th>{{ $isAr?'الاستهلاك التقديري':'Estimated usage' }}</th><th>{{ $isAr?'متوسط يومي':'Daily avg.' }}</th><th>{{ $isAr?'نوع الدليل':'Evidence' }}</th></tr></thead>
                <tbody>
                @forelse($recommendations['rows'] as $row)
                    <tr>
                        <td>{{ $row['sku'] }}</td><td>{{ $row['name'] }}</td>
                        <td>{{ number_format($row['estimated_usage'],6) }}</td>
                        <td>{{ number_format($row['daily_estimated_usage'],6) }}</td>
                        <td>
                            @if($row['authoritative_stock'])
                                <span class="si-badge">{{ $isAr?'رصيد موثق':'authoritative stock' }}</span>
                                {{ $isAr?'المتوفر':'on hand' }}: {{ number_format($row['authoritative_stock']['on_hand_quantity'],6) }}
                                · DoS: {{ $row['days_of_supply'] ?? '—' }}
                            @else
                                <span class="si-badge">{{ $isAr?'اتجاه استهلاك فقط':'consumption trend only' }}</span>
                            @endif
                        </td>
                    </tr>
                @empty<tr><td colspan="5">{{ $isAr?'لا يوجد استهلاك مغطى في الفترة.':'No mapped completed-order consumption in this period.' }}</td></tr>@endforelse
                </tbody>
            </table></div>
        </section>

        <section class="panel">
            <h2>{{ $isAr ? 'اقتراح إعادة الطلب — مسودة فقط' : 'Reorder suggestions — draft only' }}</h2>
            <p class="si-muted">{{ $isAr ? 'السعر والتوفر وقواعد MOQ/Increment يعاد فحصها الآن ومرة أخرى قبل تعديل سلة B2B. الزر لا ينشئ Order.' : 'Price, availability, MOQ and increment are revalidated now and again before the B2B cart is changed. The button never creates an order.' }}</p>
            <form method="post" action="{{ route('admin.smart-ordering.supply.draft',$site) }}">
                @csrf
                @if($branchId)<input type="hidden" name="branch_id" value="{{ $branchId }}">@endif
                <div class="si-table-wrap"><table class="si-table">
                    <thead><tr><th>SKU</th><th>{{ $isAr?'الحالة':'State' }}</th><th>{{ $isAr?'القاعدة':'Basis' }}</th><th>{{ $isAr?'سعر الآن':'Live price' }}</th><th>{{ $isAr?'توفر FOODEX':'FOODEX available' }}</th><th>{{ $isAr?'الكمية المقترحة':'Suggested qty' }}</th><th>{{ $isAr?'التكلفة':'Cost' }}</th><th></th></tr></thead>
                    <tbody>
                    @forelse($recommendations['rows'] as $row)
                        <tr>
                            <td>{{ $row['sku'] }}<br><span class="si-muted">{{ $row['name'] }}</span></td>
                            <td><span class="si-badge">{{ $row['state'] }}</span></td>
                            <td>{{ $row['reorder_basis'] }}<br><span class="si-muted">{{ $row['explanation']['formula'] }}</span></td>
                            <td>{{ $row['current_price'] === null ? '—' : number_format($row['current_price'],3) }}</td>
                            <td>{{ number_format($row['foodex_available_quantity'],3) }}</td>
                            <td>
                                @if($row['is_executable'])
                                    <input class="foodex-control" style="min-width:110px" type="number" step="{{ max(0.001,(float)$row['ordering_increment']) }}" min="{{ $row['minimum_quantity'] }}" max="{{ $row['recommended_quantity'] }}" name="quantities[{{ $row['foodex_product_id'] }}]" value="{{ $row['recommended_quantity'] }}">
                                @else
                                    {{ number_format($row['recommended_quantity'],3) }}
                                @endif
                            </td>
                            <td>{{ $row['estimated_cost'] === null ? '—' : number_format($row['estimated_cost'],3) }}</td>
                            <td>
                                <button class="si-danger" form="dismiss-{{ $row['foodex_product_id'] }}" type="submit">{{ $isAr?'إخفاء 7 أيام':'Dismiss 7d' }}</button>
                            </td>
                        </tr>
                    @empty<tr><td colspan="8">{{ $isAr?'لا توجد توصيات بعد.':'No recommendations yet.' }}</td></tr>@endforelse
                    </tbody>
                </table></div>
                @if(collect($recommendations['rows'])->contains(fn($row)=>$row['is_executable']))
                    <button class="primary" type="submit">{{ $isAr ? 'إنشاء/تحديث مسودة سلة FOODEX' : 'Create/update FOODEX draft basket' }}</button>
                @endif
            </form>
            @foreach($recommendations['rows'] as $row)
                <form id="dismiss-{{ $row['foodex_product_id'] }}" method="post" action="{{ route('admin.smart-ordering.supply.dismiss',[$site,$row['foodex_product_id']]) }}" style="display:none">
                    @csrf<input type="hidden" name="days" value="7">@if($branchId)<input type="hidden" name="branch_id" value="{{ $branchId }}">@endif
                </form>
            @endforeach
        </section>

        <div class="si-grid">
            <section class="panel">
                <h2>{{ $isAr ? 'الأثر التجاري' : 'Commercial impact' }}</h2>
                <div class="si-kpis">
                    <div class="si-kpi"><span>{{ $isAr?'أصناف منيو مرتبطة':'Mapped menu items' }}</span><strong>{{ $commercial['mapped_menu_items'] }}</strong></div>
                    <div class="si-kpi"><span>{{ $isAr?'مسودات مساعدة':'Assisted drafts' }}</span><strong>{{ $commercial['assisted_drafts'] }}</strong></div>
                    <div class="si-kpi"><span>{{ $isAr?'قيمة مسودات':'Draft value' }}</span><strong>{{ number_format($commercial['assisted_draft_value'],3) }}</strong></div>
                    <div class="si-kpi"><span>{{ $isAr?'طلبات تحولت فعلياً':'Converted orders' }}</span><strong>{{ $commercial['converted_assisted_orders'] }}</strong></div>
                    <div class="si-kpi"><span>{{ $isAr?'مبيعات FOODEX المنسوبة':'Attributed FOODEX sales' }}</span><strong>{{ number_format($commercial['attributed_foodex_sales_value'],3) }}</strong></div>
                </div>
                <p class="si-muted">{{ $commercial['attribution_rule'] }}</p>
            </section>

            <section class="panel">
                <h2>{{ $isAr ? 'منتجات FOODEX مرتبطة مقترحة' : 'Related FOODEX products' }}</h2>
                <p class="si-muted">{{ $isAr ? 'من نفس تصنيفات المنتجات المرتبطة، من الكتالوج الحقيقي والمتاح فقط. ليست إضافة تلقائية للسلة.' : 'Same-category items from the real active/available FOODEX catalog only. Never auto-added to the basket.' }}</p>
                @forelse($relatedProducts as $product)
                    <p><strong>{{ $product['sku'] }}</strong> — {{ $product['name'] }} · {{ number_format($product['available_quantity'],3) }}</p>
                @empty
                    <p>{{ $isAr ? 'لا توجد اقتراحات مرتبطة متاحة حالياً.' : 'No currently available related suggestions.' }}</p>
                @endforelse
            </section>
        </div>
    </main>
</div>
</body>
</html>
