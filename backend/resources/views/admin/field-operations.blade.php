@php
    $ar = app()->getLocale() === 'ar';
    $isSuper = $user->hasRole('SUPER_ADMIN');
    $canManageVan = $isSuper || $user->hasPermission('drivers.b2b.manage');
    $canManageTerritories = $isSuper || $user->hasPermission('territories.manage') || $user->hasPermission('field_ops.manage');
    $canManageAddress = $isSuper || $user->hasPermission('customers.edit');
    $overviewVisibility = $overviewVisibility ?? [];
    $canCatalog = $isSuper || $user->hasPermission('catalog.view');
    $canPromotions = $isSuper || $user->hasPermission('promotions.view');
    $titles = [
        'overview' => [$ar ? 'مركز عمليات الفان' : 'Van & Field Operations', $ar ? 'لوحة تحكم تشغيلية تجمع الأسطول والزيارات والمناطق والتحصيل في مكان واحد.' : 'Operational control center for fleet, visits, territories and field finance.'],
        'fleet' => [$ar ? 'خريطة الأسطول الحية' : 'Live Fleet Map', $ar ? 'الموقع الحالي للفانات وحالة آخر اتصال والإسناد والمسار.' : 'Current Van positions, heartbeat health, assignment and route context.'],
        'vans' => [$ar ? 'الفانات' : 'Vans', $ar ? 'سجل الفانات وحالتها التشغيلية وآخر إسناد وموقع.' : 'Van registry, operational state, latest assignment and location health.'],
        'van-detail' => [$ar ? 'تفاصيل الفان' : 'Van Details', $ar ? 'الهوية والحالة والموقع وسجل الإسنادات للفان المحدد.' : 'Identity, operational state, location health and assignment history for the selected Van.'],
        'assignments' => [$ar ? 'إسنادات الفانات' : 'Van Assignments', $ar ? 'إدارة ربط الفان بالسائق أو المندوب والمنطقة والمخزن.' : 'Manage Van-to-driver/operator, territory and warehouse assignments.'],
        'customers' => [$ar ? 'عملاء الفان' : 'Van Customers', $ar ? 'العلاقة الفعلية بين العميل والزيارة والمشغل والفان والمنطقة.' : 'Canonical customer-to-visit/operator/Van/territory relationship view.'],
        'visits' => [$ar ? 'الزيارات والمسارات' : 'Visits & Routes', $ar ? 'خطط الزيارات ومتابعة دورة الحياة ونتائج عدم الطلب.' : 'Plan visits and manage lifecycle, route context and no-order outcomes.'],
        'territories' => [$ar ? 'المناطق والتغطية' : 'Territories & Geography', $ar ? 'إدارة التسلسل الجغرافي ومناطق الخدمة والهندسة الجغرافية.' : 'Manage geography hierarchy, service territories and coverage geometry.'],
        'address-quality' => [$ar ? 'جودة العناوين' : 'Address Quality', $ar ? 'مراجعة العناوين غير المحسومة وتأكيد أو رفض أو إعادة فتح القرار.' : 'Review unresolved address records and confirm, reject or reopen decisions.'],
        'routing' => [$ar ? 'سياسات التوجيه' : 'Routing Policies', $ar ? 'إنشاء ونشر ومحاكاة والتراجع عن سياسات التوجيه المركزية.' : 'Create, publish, simulate and roll back canonical routing policies.'],
        'finance' => [$ar ? 'تحصيل ومالية الفان' : 'Van Finance & Collections', $ar ? 'المحافظ والتحصيلات والتوريدات والمطابقة من السجل المالي المشترك.' : 'Wallets, collections, remittances and reconciliation from the shared finance ledger.'],
    ];
    [$pageTitle, $pageDescription] = $titles[$section] ?? $titles['overview'];
@endphp
<!doctype html>
<html lang="{{ app()->getLocale() }}" dir="{{ $ar ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>{{ $pageTitle }} · FOODEX</title>
    @include('admin._brand-components')
    @if(in_array($section, ['fleet','territories'], true))
        <link rel="stylesheet" href="{{ asset('assets/leaflet/1.9.4/leaflet.css') }}">
        @if($section === 'fleet')<link rel="stylesheet" href="{{ asset('assets/admin/driver-live-map.css') }}">@endif
    @endif
    <style>
        .fieldops-header{display:flex;align-items:flex-start;justify-content:space-between;gap:16px;flex-wrap:wrap}
        .fieldops-header h1{margin:3px 0 8px}.fieldops-header p{margin:0;color:var(--foodex-muted)}
        .fieldops-kpis{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px}
        .fieldops-kpi{padding:16px}.fieldops-kpi strong{display:block;font-size:1.65rem}.fieldops-kpi small{color:var(--foodex-muted)}
        .fieldops-card{background:#fff;border:1px solid var(--foodex-border);border-radius:var(--foodex-radius-card);padding:18px;box-shadow:var(--foodex-shadow-sm)}
        .fieldops-card h2,.fieldops-card h3{margin-top:0}
        .fieldops-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:14px}
        .fieldops-form{display:grid;gap:12px}.fieldops-form-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:12px}
        .fieldops-form label{display:grid;gap:6px;font-weight:700}.fieldops-form input,.fieldops-form select,.fieldops-form textarea{width:100%}
        .fieldops-actions{display:flex;gap:8px;flex-wrap:wrap;align-items:center}
        .fieldops-muted{color:var(--foodex-muted)}.fieldops-danger{color:var(--foodex-red)}
        .fieldops-code{font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:.84rem}
        .fieldops-status{display:inline-flex;padding:4px 9px;border-radius:999px;background:var(--foodex-background);font-size:.78rem;font-weight:700}
        .fieldops-section-nav{display:flex;gap:8px;flex-wrap:wrap}
        .fieldops-section-nav a{padding:8px 11px;border:1px solid var(--foodex-border);border-radius:10px;text-decoration:none;background:#fff}
        .fieldops-coverage-map{height:430px;min-height:320px;border:1px solid var(--foodex-border);border-radius:var(--foodex-radius-md);overflow:hidden;background:#eef2f5}
        @media(max-width:767px){.fieldops-card{padding:13px}.fieldops-header{display:block}.fieldops-kpis{grid-template-columns:repeat(2,minmax(0,1fr))}}
    </style>
</head>
<body>
<div class="foodex-admin-layout" data-field-operations-page="{{ $section }}">
    <aside class="sidebar">@include('admin._sidebar', ['navGroups'=>$navGroups,'navContext'=>$navContext,'user'=>$user])</aside>
    <main class="foodex-admin-main foodex-admin-page">
        <header class="foodex-page-header fieldops-header">
            <div>
                <span class="foodex-subtitle">FOODEX · {{ $ar ? 'عمليات الفان والميدان' : 'Van & Field Operations' }}</span>
                <h1>{{ $pageTitle }}</h1>
                <p>{{ $pageDescription }}</p>
            </div>
            @include('admin._live-notifications',['user'=>$user])
        </header>

        @if(session('status'))<div class="foodex-state" role="status">{{ session('status') }}</div>@endif
        @if($errors->any())<div class="foodex-state" role="alert"><strong>{{ $ar?'تعذر الحفظ':'Unable to save' }}</strong><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif

        <div class="foodex-ops-shell">
        @if($section === 'overview')
            @php($s = $summary ?? [])
            <section class="fieldops-kpis">
                @foreach([
                    ['active_vans',$ar?'فانات نشطة':'Active Vans'],
                    ['suspended_vans',$ar?'فانات موقوفة':'Suspended Vans'],
                    ['assigned_vans',$ar?'فانات مسندة':'Assigned Vans'],
                    ['unassigned_vans',$ar?'فانات بدون إسناد':'Unassigned Vans'],
                    ['operators',$ar?'مشغلون/مندوبون':'Operators'],
                    ['customers_served',$ar?'عملاء مخدومون':'Served Customers'],
                    ['active_visits',$ar?'زيارات نشطة':'Active Visits'],
                    ['completed_visits',$ar?'زيارات مكتملة':'Completed Visits'],
                    ['no_order_visits',$ar?'زيارات بدون طلب':'No-order Visits'],
                    ['territories',$ar?'مناطق نشطة':'Active Territories'],
                    ['unresolved_addresses',$ar?'عناوين تحتاج مراجعة':'Address Reviews'],
                    ['pending_remittances',$ar?'توريدات معلقة':'Pending Remittances'],
                ] as [$key,$label])
                    <article class="fieldops-card fieldops-kpi"><strong>{{ number_format((int)($s[$key] ?? 0)) }}</strong><small>{{ $label }}</small></article>
                @endforeach
            </section>
            <section class="fieldops-grid">
                <article class="fieldops-card">
                    <h2>{{ $ar?'صحة الاتصال بالأسطول':'Fleet reporting health' }}</h2>
                    @foreach(['online'=>$ar?'متصل':'Online','stale'=>$ar?'متأخر':'Stale','offline'=>$ar?'غير متصل':'Offline'] as $key=>$label)
                        <div class="control-row"><span>{{ $label }}</span><strong>{{ (int)($s['location_health'][$key] ?? 0) }}</strong></div>
                    @endforeach
                    @if($overviewVisibility['tracking'] ?? false)<div class="fieldops-actions"><a class="foodex-action-primary" href="{{ route('admin.field-operations.fleet') }}">{{ $ar?'فتح الخريطة':'Open live map' }}</a></div>@endif
                </article>
                <article class="fieldops-card">
                    <h2>{{ $ar?'التحصيلات تحت العهدة':'Outstanding field custody' }}</h2>
                    <strong style="font-size:1.8rem">{{ number_format((float)($s['outstanding_collections'] ?? 0),3) }}</strong>
                    <p class="fieldops-muted">{{ $ar?'القيمة الإجمالية الحالية في سجل عهدة تحصيل الفانات.':'Current aggregate amount in Van collection custody ledger.' }}</p>
                    @if($overviewVisibility['finance'] ?? false)<a href="{{ route('admin.field-operations.finance') }}">{{ $ar?'فتح المالية':'Open finance' }}</a>@endif
                </article>
                <article class="fieldops-card">
                    <h2>{{ $ar?'اختصارات التشغيل':'Operational shortcuts' }}</h2>
                    <div class="fieldops-section-nav">
                        @if($overviewVisibility['drivers'] ?? false)<a href="{{ route('admin.field-operations.vans') }}">{{ $ar?'الفانات':'Vans' }}</a><a href="{{ route('admin.field-operations.assignments') }}">{{ $ar?'الإسنادات':'Assignments' }}</a>@endif
                        @if($overviewVisibility['visits'] ?? false)<a href="{{ route('admin.field-operations.visits') }}">{{ $ar?'الزيارات':'Visits' }}</a>@endif
                        @if($overviewVisibility['territories'] ?? false)<a href="{{ route('admin.field-operations.territories') }}">{{ $ar?'المناطق':'Territories' }}</a>@endif
                    </div>
                </article>
                <article class="fieldops-card">
                    <h2>{{ $ar?'التحكم التجاري المرتبط بالفان':'Van commercial controls' }}</h2>
                    <p class="fieldops-muted">{{ $ar?'تظل القواعد والعروض في صفحاتها التجارية الأصلية؛ هذه اختصارات سياقية فقط لمنع تكرار روابط القائمة.' : 'Rules and offers remain in their canonical commercial pages; these are contextual shortcuts so the sidebar is not duplicated.' }}</p>
                    <div class="fieldops-section-nav">
                        @if(($featureFlags['commercial_rules_enabled'] ?? false) && $canCatalog)<a href="{{ route('admin.commercial.sales-control') }}">{{ $ar?'قواعد البيع والحصص':'Sales rules & quotas' }}</a>@endif
                        @if(($featureFlags['van_offers_enabled'] ?? false) && ($featureFlags['flash_offers_enabled'] ?? false) && $canPromotions)<a href="{{ route('admin.commercial.flash-offers') }}">{{ $ar?'عروض الفان وFlash':'Van & Flash Offers' }}</a>@endif
                    </div>
                </article>
            </section>

        @elseif($section === 'fleet')
            @include('admin._driver-live-map', [
                'feedUrl' => $feedUrl,
                'liveMapMode' => 'full',
                'showFilters' => true,
                'showList' => true,
                'showSummary' => true,
                'trackingActor' => 'van',
                'trackingI18n' => [
                    'noDrivers' => $ar ? 'لا توجد فانات بموقع متاح ضمن النطاق الحالي.' : 'No Vans with available location in the current scope.',
                    'entitySingular' => $ar ? 'فان' : 'Van',
                    'entities' => $ar ? 'الفانات' : 'Vans',
                    'entityId' => $ar ? 'رقم الفان' : 'Van ID',
                    'route' => $ar ? 'المسار' : 'Route',
                ],
            ])

        @elseif($section === 'vans')
            @if($canManageVan)
            <details class="fieldops-card">
                <summary><strong>{{ $ar?'تسجيل فان جديد':'Register Van' }}</strong></summary>
                <form method="post" action="{{ route('admin.field-operations.vans.store') }}" class="fieldops-form" style="margin-top:14px">@csrf
                    <div class="fieldops-form-grid">
                        <label>{{ $ar?'الكود':'Code' }}<input name="code" required></label>
                        <label>{{ $ar?'رقم اللوحة':'Plate number' }}<input name="plate_number"></label>
                        <label>{{ $ar?'نوع المركبة':'Vehicle type' }}<input name="vehicle_type"></label>
                        <label>{{ $ar?'السعة بالوحدات':'Capacity units' }}<input type="number" min="0" name="capacity_units"></label>
                        <label>{{ $ar?'سعة الوزن':'Capacity weight' }}<input type="number" min="0" step=".001" name="capacity_weight"></label>
                    </div>
                    <label>{{ $ar?'ملاحظات':'Notes' }}<textarea name="notes" rows="2"></textarea></label>
                    <button class="foodex-primary" type="submit">{{ $ar?'تسجيل الفان':'Register Van' }}</button>
                </form>
            </details>
            @endif
            <div class="table-wrap"><table class="foodex-ops-grid"><thead><tr>
                <th>{{ $ar?'الفان':'Van' }}</th><th>{{ $ar?'الحالة':'Status' }}</th><th>{{ $ar?'الإسناد الحالي':'Current assignment' }}</th>
                <th>{{ $ar?'الموقع':'Location' }}</th><th>{{ $ar?'آخر تحديث':'Last update' }}</th><th>{{ $ar?'إجراء':'Action' }}</th>
            </tr></thead><tbody>
            @forelse($vans as $van)
                @php($assignment = $van->assignments->firstWhere('status','active'))
                @php($location = $locations->get($van->id))
                <tr>
                    <td><a href="{{ route('admin.field-operations.vans.show',$van) }}"><strong>{{ $van->code }}</strong></a><div class="fieldops-muted">{{ $van->plate_number ?: '—' }} · {{ $van->vehicle_type ?: '—' }}</div></td>
                    <td><span class="fieldops-status">{{ $van->status }}</span></td>
                    <td>@if($assignment)#{{ $assignment->id }} · {{ $assignment->assignment_type }}<br><span class="fieldops-muted">{{ $assignment->territory_key ?: '—' }}</span>@else—@endif</td>
                    <td>@if($location)<span class="fieldops-code">{{ number_format($location->latitude,5) }}, {{ number_format($location->longitude,5) }}</span>@else—@endif</td>
                    <td>{{ $location?->received_at?->diffForHumans() ?: '—' }}</td>
                    <td>
                        @if($canManageVan && $van->status !== 'suspended')
                        <details class="foodex-ops-actions"><summary>⋮</summary><div class="foodex-ops-menu">
                            <form method="post" action="{{ route('admin.field-operations.vans.suspend',$van) }}">@csrf
                                <input name="transfer_target_van_id" type="number" placeholder="{{ $ar?'فان التحويل عند وجود حمولة':'Transfer Van ID if loaded' }}">
                                <input name="reason" placeholder="{{ $ar?'سبب الإيقاف':'Suspension reason' }}">
                                <button class="danger" type="submit">{{ $ar?'إيقاف':'Suspend' }}</button>
                            </form>
                        </div></details>
                        @else—@endif
                    </td>
                </tr>
            @empty<tr><td colspan="6"><div class="foodex-ops-state">{{ $ar?'لا توجد فانات مسجلة.':'No Vans registered.' }}</div></td></tr>@endforelse
            </tbody></table></div>
            {{ $vans->links() }}

        @elseif($section === 'van-detail')
            <div class="fieldops-actions"><a href="{{ route('admin.field-operations.vans') }}">← {{ $ar?'العودة للفانات':'Back to Vans' }}</a></div>
            <section class="fieldops-grid">
                <article class="fieldops-card"><h2>{{ $ar?'بيانات الفان':'Van identity' }}</h2>
                    <div class="control-row"><span>Code</span><strong>{{ $van->code }}</strong></div>
                    <div class="control-row"><span>{{ $ar?'اللوحة':'Plate' }}</span><strong>{{ $van->plate_number ?: '—' }}</strong></div>
                    <div class="control-row"><span>{{ $ar?'النوع':'Vehicle type' }}</span><strong>{{ $van->vehicle_type ?: '—' }}</strong></div>
                    <div class="control-row"><span>{{ $ar?'الحالة':'Status' }}</span><span class="fieldops-status">{{ $van->status }}</span></div>
                    <div class="control-row"><span>{{ $ar?'السعة':'Capacity' }}</span><strong>{{ $van->capacity_units ?? '—' }} / {{ $van->capacity_weight ?? '—' }}</strong></div>
                    <div class="control-row"><span>{{ $ar?'المخزن الرئيسي':'Home warehouse' }}</span><strong>{{ $van->home_warehouse_id ? '#'.$van->home_warehouse_id : '—' }}</strong></div>
                    @if($van->notes)<p class="fieldops-muted">{{ $van->notes }}</p>@endif
                </article>
                <article class="fieldops-card"><h2>{{ $ar?'صحة الموقع':'Location health' }}</h2>
                    @if($location)
                        <div class="control-row"><span>{{ $ar?'الحالة':'Status' }}</span><span class="fieldops-status">{{ $locationStatus }}</span></div>
                        <div class="control-row"><span>{{ $ar?'الإحداثيات':'Coordinates' }}</span><span class="fieldops-code">{{ number_format($location->latitude,6) }}, {{ number_format($location->longitude,6) }}</span></div>
                        <div class="control-row"><span>{{ $ar?'آخر تحديث':'Last heartbeat' }}</span><strong>{{ $location->received_at }}</strong></div>
                        <div class="control-row"><span>{{ $ar?'المسار':'Route' }}</span><strong>{{ $location->route_key ?: '—' }}</strong></div>
                    @else<div class="foodex-ops-state">{{ $ar?'لا يوجد موقع مستلم لهذا الفان بعد.':'No location heartbeat has been received for this Van yet.' }}</div>@endif
                </article>
            </section>
            <section class="fieldops-card"><h2>{{ $ar?'سجل الإسنادات':'Assignment history' }}</h2>
                <div class="table-wrap"><table class="foodex-ops-grid"><thead><tr><th>#</th><th>{{ $ar?'السائق':'Driver' }}</th><th>{{ $ar?'المشغل':'Operator' }}</th><th>{{ $ar?'المنطقة':'Territory' }}</th><th>{{ $ar?'النوع':'Type' }}</th><th>{{ $ar?'الحالة':'Status' }}</th><th>{{ $ar?'الفترة':'Window' }}</th></tr></thead><tbody>
                @forelse($van->assignments as $a)<tr><td>{{ $a->id }}</td><td>{{ $a->driver_id ? '#'.$a->driver_id : '—' }}</td><td>{{ $a->representative_user_id ? '#'.$a->representative_user_id : '—' }}</td><td>{{ $a->territory_key ?: '—' }}</td><td>{{ $a->assignment_type }}</td><td>{{ $a->status }}</td><td>{{ $a->effective_from }} → {{ $a->effective_until ?: '∞' }}</td></tr>
                @empty<tr><td colspan="7">{{ $ar?'لا يوجد سجل إسنادات لهذا الفان.':'No assignment history for this Van.' }}</td></tr>@endforelse
                </tbody></table></div>
            </section>

        @elseif($section === 'assignments')
            @if($canManageVan)
            <details class="fieldops-card">
                <summary><strong>{{ $ar?'إسناد فان':'Assign Van' }}</strong></summary>
                <form method="post" id="fieldops-assignment-form" class="fieldops-form" style="margin-top:14px">@csrf
                    <div class="fieldops-form-grid">
                        <label>{{ $ar?'الفان':'Van' }}<select name="van_id" required onchange="this.form.action='{{ url('/admin/field-operations/vans') }}/'+this.value+'/assignments'"><option value="">{{ $ar?'اختر':'Select' }}</option>@foreach($vans as $van)<option value="{{ $van->id }}">{{ $van->code }}</option>@endforeach</select></label>
                        <label>{{ $ar?'السائق':'Driver' }}<select name="driver_id"><option value="">—</option>@foreach($drivers as $driver)<option value="{{ $driver->id }}" data-user="{{ $driver->user_id }}">{{ $driver->name ?: ('Driver #'.$driver->id) }}</option>@endforeach</select></label>
                        <label>{{ $ar?'المستخدم/المندوب':'Representative user ID' }}<input type="number" min="1" name="representative_user_id"></label>
                        <label>{{ $ar?'المنطقة':'Territory' }}<select name="territory_key"><option value="">—</option>@foreach($territories as $territory)<option value="{{ $territory->code }}">{{ $ar?$territory->name_ar:$territory->name_en }} · {{ $territory->code }}</option>@endforeach</select></label>
                        <label>{{ $ar?'نوع الإسناد':'Assignment type' }}<select name="assignment_type"><option value="primary">primary</option><option value="backup">backup</option></select></label>
                        <label>{{ $ar?'من':'Effective from' }}<input type="datetime-local" name="effective_from" required></label>
                        <label>{{ $ar?'حتى':'Effective until' }}<input type="datetime-local" name="effective_until"></label>
                        <label>{{ $ar?'عدد الأعمال المحملة':'Loaded work' }}<input type="number" min="0" name="loaded_work_count" value="0"></label>
                    </div>
                    <button class="foodex-primary" type="submit">{{ $ar?'حفظ الإسناد':'Save assignment' }}</button>
                </form>
            </details>
            @endif
            <div class="table-wrap"><table class="foodex-ops-grid"><thead><tr><th>#</th><th>{{ $ar?'الفان':'Van' }}</th><th>{{ $ar?'السائق/المشغل':'Driver/operator' }}</th><th>{{ $ar?'المنطقة':'Territory' }}</th><th>{{ $ar?'النوع':'Type' }}</th><th>{{ $ar?'الحالة':'Status' }}</th><th>{{ $ar?'الفترة':'Window' }}</th></tr></thead><tbody>
                @forelse($assignments as $a)<tr><td>{{ $a->id }}</td><td>{{ $a->van?->code ?: ('#'.$a->van_id) }}</td><td>{{ $a->driver_id ? 'Driver #'.$a->driver_id : '' }} {{ $a->representative_user_id ? 'User #'.$a->representative_user_id : '' }}</td><td>{{ $a->territory_key ?: '—' }}</td><td>{{ $a->assignment_type }}</td><td>{{ $a->status }}</td><td>{{ $a->effective_from }} → {{ $a->effective_until ?: '∞' }}</td></tr>
                @empty<tr><td colspan="7">{{ $ar?'لا توجد إسنادات.':'No assignments.' }}</td></tr>@endforelse
            </tbody></table></div>{{ $assignments->links() }}

        @elseif($section === 'customers')
            <div class="fieldops-card">
                <strong>{{ $ar?'كيف يتم الربط؟':'How the relationship is derived' }}</strong>
                <p class="fieldops-muted">{{ $ar?'تُعرض العلاقة من الزيارة الفعلية + المشغل + إسناد الفان والمنطقة، بدون إنشاء نموذج ربط موازٍ.' : 'The relationship is derived from the canonical visit + operator + Van assignment/territory structures; no parallel customer-assignment model is introduced.' }}</p>
            </div>
            <div class="table-wrap"><table class="foodex-ops-grid"><thead><tr><th>{{ $ar?'العميل':'Customer' }}</th><th>{{ $ar?'القناة':'Channel' }}</th><th>{{ $ar?'الفان':'Van' }}</th><th>{{ $ar?'المشغل':'Operator' }}</th><th>{{ $ar?'المنطقة':'Territory' }}</th><th>{{ $ar?'المسار':'Route' }}</th><th>{{ $ar?'آخر زيارة':'Latest visit' }}</th><th>{{ $ar?'الحالة':'Status' }}</th></tr></thead><tbody>
            @forelse($relationships as $visit)
                @php($a = $visit->getRelation('servingAssignment'))
                <tr><td>{{ $visit->customer_display }}</td><td>{{ strtoupper($visit->customer_type) }}</td><td>{{ $a?->van?->code ?: (($visit->metadata['van_id'] ?? null) ? '#'.$visit->metadata['van_id'] : '—') }}</td><td>{{ $visit->actor?->name ?: ('User #'.$visit->actor_user_id) }}</td><td>{{ $a?->territory_key ?: ($visit->metadata['territory_key'] ?? '—') }}</td><td>{{ $visit->metadata['route_key'] ?? ($visit->metadata['route_code'] ?? '—') }}</td><td>#{{ $visit->id }} · {{ $visit->updated_at }}</td><td>{{ $visit->status }}</td></tr>
            @empty<tr><td colspan="8"><div class="foodex-ops-state">{{ $ar?'لا توجد علاقات زيارة/عملاء بعد.':'No customer/visit relationships yet.' }}</div></td></tr>@endforelse
            </tbody></table></div>{{ $relationships->links() }}
            <div class="fieldops-card"><a class="foodex-action-primary" href="{{ route('admin.field-operations.visits') }}">{{ $ar?'خطط زيارة لربط العميل بالإسناد التشغيلي':'Plan a visit to associate a customer with an operational assignment' }}</a></div>

        @elseif($section === 'visits')
            @if($canManageVan)
            <details class="fieldops-card">
                <summary><strong>{{ $ar?'تخطيط زيارة عميل':'Plan customer visit' }}</strong></summary>
                <form method="post" action="{{ route('admin.field-operations.visits.store') }}" class="fieldops-form" style="margin-top:14px">@csrf
                    <div class="fieldops-form-grid">
                        <label>{{ $ar?'إسناد الفان':'Van assignment' }}<select name="assignment_id" required><option value="">—</option>@foreach($assignments as $a)<option value="{{ $a->id }}">{{ $a->van?->code ?: '#'.$a->van_id }} · {{ $a->territory_key ?: '—' }} · {{ $a->representative_user_id ? 'User #'.$a->representative_user_id : 'Driver #'.$a->driver_id }}</option>@endforeach</select></label>
                        <label>{{ $ar?'نوع العميل':'Customer type' }}<select name="customer_type"><option value="b2b">B2B</option><option value="b2c">B2C</option></select></label>
                        <label>{{ $ar?'رقم العميل':'Customer ID' }}<input type="number" min="1" name="customer_id" required></label>
                        <label>{{ $ar?'وقت الزيارة':'Planned at' }}<input type="datetime-local" name="planned_at"></label>
                        <label>{{ $ar?'كود المسار':'Route key' }}<input name="route_key"></label>
                    </div>
                    <button class="foodex-primary">{{ $ar?'إنشاء الزيارة':'Create planned visit' }}</button>
                </form>
            </details>
            @endif
            <div class="table-wrap"><table class="foodex-ops-grid"><thead><tr><th>#</th><th>{{ $ar?'العميل':'Customer' }}</th><th>{{ $ar?'المشغل':'Operator' }}</th><th>{{ $ar?'الحالة':'Status' }}</th><th>{{ $ar?'الأوقات':'Timestamps' }}</th><th>{{ $ar?'النتيجة':'Result' }}</th><th>{{ $ar?'الإجراء':'Action' }}</th></tr></thead><tbody>
            @forelse($visits as $visit)
                @php($allowed = \App\Services\VanVisitLifecycleService::allowedTransitions((string)$visit->status))
                <tr><td>#{{ $visit->id }}</td><td>{{ $visit->customer_display }}<div class="fieldops-muted">{{ strtoupper($visit->customer_type) }}</div></td><td>{{ $visit->actor?->name ?: ('#'.$visit->actor_user_id) }}</td><td>{{ $visit->status }}</td><td><small>{{ $visit->planned_at ?: '—' }}<br>{{ $visit->started_at ?: '' }}<br>{{ $visit->completed_at ?: '' }}</small></td><td>{{ $visit->order_id ? 'Order #'.$visit->order_id : ($visit->noOrderReason?->label_en ?: '—') }}</td><td>
                    @if($canManageVan && $allowed !== [])
                    <form method="post" action="{{ route('admin.field-operations.visits.transition',$visit) }}" class="fieldops-actions">@csrf
                        <select name="status">@foreach($allowed as $target)<option value="{{ $target }}">{{ $target }}</option>@endforeach</select>
                        <select name="no_order_reason_id"><option value="">{{ $ar?'سبب عدم الطلب':'No-order reason' }}</option>@foreach($reasons as $reason)<option value="{{ $reason->id }}">{{ $ar?$reason->label_ar:$reason->label_en }}</option>@endforeach</select>
                        <input type="number" name="order_id" min="1" placeholder="{{ $ar?'رقم الطلب':'Order ID' }}">
                        <button type="submit">{{ $ar?'تنفيذ':'Apply' }}</button>
                    </form>
                    @else—@endif
                </td></tr>
            @empty<tr><td colspan="7"><div class="foodex-ops-state">{{ $ar?'لا توجد زيارات.':'No visits.' }}</div></td></tr>@endforelse
            </tbody></table></div>{{ $visits->links() }}

        @elseif($section === 'territories')
            @if($canManageTerritories)
            <section class="fieldops-grid">
                <details class="fieldops-card"><summary><strong>{{ $ar?'إضافة عنصر جغرافي':'Add geography node' }}</strong></summary>
                    <form method="post" action="{{ route('admin.field-operations.geography.store') }}" class="fieldops-form" style="margin-top:14px">@csrf
                        <div class="fieldops-form-grid">
                            <label>{{ $ar?'النوع':'Type' }}<select name="type">@foreach(['country','governorate','region','city','markaz','district','area'] as $type)<option>{{ $type }}</option>@endforeach</select></label>
                            <label>{{ $ar?'الأصل':'Parent' }}<select name="parent_id"><option value="">—</option>@foreach($nodes as $node)<option value="{{ $node->id }}">{{ $node->name_en }} · {{ $node->type }}</option>@endforeach</select></label>
                            <label>Code<input name="code" required></label><label>Country code<input name="country_code" value="KW" required></label>
                            <label>العربية<input name="name_ar" required></label><label>English<input name="name_en" required></label>
                        </div><button class="foodex-primary">{{ $ar?'حفظ':'Save' }}</button>
                    </form>
                </details>
                <details class="fieldops-card"><summary><strong>{{ $ar?'إنشاء منطقة خدمة':'Create service territory' }}</strong></summary>
                    <form method="post" action="{{ route('admin.field-operations.territories.store') }}" class="fieldops-form" style="margin-top:14px">@csrf
                        <div class="fieldops-form-grid">
                            <label>Code<input name="code" required></label><label>العربية<input name="name_ar" required></label><label>English<input name="name_en" required></label>
                            <label>{{ $ar?'الدولة':'Country node' }}<select name="country_node_id" required>@foreach($nodes->where('type','country') as $node)<option value="{{ $node->id }}">{{ $node->name_en }}</option>@endforeach</select></label>
                            <label>Status<select name="status"><option>draft</option><option>active</option><option>inactive</option></select></label>
                            <label>Priority<input type="number" min="0" name="priority" value="0"></label>
                        </div><label>Notes<textarea name="notes"></textarea></label><button class="foodex-primary">{{ $ar?'حفظ المنطقة':'Save territory' }}</button>
                    </form>
                </details>
            </section>
            @endif
            <section class="fieldops-card"><h2>{{ $ar?'التسلسل الجغرافي':'Geography hierarchy' }}</h2><div class="table-wrap"><table class="foodex-ops-grid"><thead><tr><th>#</th><th>{{ $ar?'النوع':'Type' }}</th><th>Code</th><th>{{ $ar?'الاسم':'Name' }}</th><th>{{ $ar?'الأصل':'Parent' }}</th></tr></thead><tbody>@forelse($nodes as $node)<tr><td>{{ $node->id }}</td><td>{{ $node->type }}</td><td>{{ $node->code }}</td><td>{{ $ar?$node->name_ar:$node->name_en }}</td><td>{{ $node->parent?->name_en ?: '—' }}</td></tr>@empty<tr><td colspan="5">{{ $ar?'لا توجد بيانات جغرافية.':'No geography nodes.' }}</td></tr>@endforelse</tbody></table></div></section>
            <section class="fieldops-card">
                <h2>{{ $ar?'خريطة التغطية':'Coverage map' }}</h2>
                <p class="fieldops-muted">{{ $ar?'تظهر كل هندسات المناطق الحالية. لإضافة تغطية، اختر المنطقة ثم انقر على الخريطة لرسم حدود المضلع؛ الإحداثيات الخام متاحة فقط كخيار متقدم.' : 'All current territory geometries are shown. To add coverage, select a territory then click the map to draw the polygon; raw coordinates remain an advanced option.' }}</p>
                <div class="fieldops-coverage-map" id="fieldops-coverage-map" aria-label="{{ $ar?'خريطة مناطق الخدمة':'Service territory coverage map' }}"></div>
                @if($canManageTerritories && $territories->isNotEmpty())
                <form method="post" id="fieldops-coverage-form" class="fieldops-form" style="margin-top:14px">@csrf
                    <div class="fieldops-form-grid">
                        <label>{{ $ar?'منطقة الخدمة':'Service territory' }}
                            <select id="fieldops-coverage-territory" required>
                                <option value="">{{ $ar?'اختر المنطقة':'Select territory' }}</option>
                                @foreach($territories as $territory)<option value="{{ $territory->id }}">{{ $ar?$territory->name_ar:$territory->name_en }} · {{ $territory->code }}</option>@endforeach
                            </select>
                        </label>
                        <label>{{ $ar?'الرسم':'Drawing' }}
                            <button type="button" id="fieldops-coverage-clear">{{ $ar?'مسح النقاط وإعادة الرسم':'Clear points & redraw' }}</button>
                        </label>
                    </div>
                    <details>
                        <summary>{{ $ar?'متقدم: GeoJSON':'Advanced: GeoJSON' }}</summary>
                        <label style="margin-top:8px">GeoJSON<textarea id="fieldops-coverage-geojson" name="geojson" rows="5" required></textarea></label>
                    </details>
                    <button class="foodex-primary" type="submit">{{ $ar?'حفظ هندسة التغطية':'Save coverage geometry' }}</button>
                </form>
                @endif
            </section>
            <section class="fieldops-card"><h2>{{ $ar?'مناطق الخدمة':'Service territories' }}</h2>
                @forelse($territories as $territory)
                    <article style="padding:14px 0;border-bottom:1px solid var(--foodex-border)">
                        <div class="fieldops-actions"><strong>{{ $ar?$territory->name_ar:$territory->name_en }} · {{ $territory->code }}</strong><span class="fieldops-status">{{ $territory->status }}</span><span>{{ $ar?'أشكال التغطية':'Geometries' }}: {{ $territory->geometries->count() }}</span></div>
                    </article>
                @empty<div class="foodex-ops-state">{{ $ar?'لا توجد مناطق خدمة.':'No service territories.' }}</div>@endforelse
            </section>

        @elseif($section === 'address-quality')
            <form method="get" class="foodex-ops-toolbar fieldops-card"><label>{{ $ar?'بحث':'Search' }}<input name="q" value="{{ $filters['q'] ?? '' }}"></label><label>Status<select name="status"><option value="">All</option>@foreach(['unmapped','confirmed','rejected'] as $st)<option value="{{ $st }}" @selected(($filters['status']??'')===$st)>{{ $st }}</option>@endforeach</select></label><button class="foodex-primary">{{ $ar?'تطبيق':'Apply' }}</button></form>
            <div class="table-wrap"><table class="foodex-ops-grid"><thead><tr><th>#</th><th>{{ $ar?'الموضوع':'Subject' }}</th><th>{{ $ar?'الجودة':'Quality' }}</th><th>{{ $ar?'المنطقة':'Territory' }}</th><th>{{ $ar?'الحالة':'Status' }}</th><th>{{ $ar?'الإجراء':'Action' }}</th></tr></thead><tbody>
            @forelse($reviews as $review)<tr><td>{{ $review->id }}</td><td>{{ $review->subject_type }} #{{ $review->subject_id }}
                <details style="margin-top:6px"><summary>{{ $ar?'التفاصيل والسجل':'Details & history' }}</summary>
                    <div class="fieldops-muted" style="margin-top:6px">{{ $review->reason ?: ($ar?'لا يوجد سبب مسجل.':'No recorded reason.') }}</div>
                    <div>{{ $ar?'المصدر':'Source' }}: {{ $review->resolution_source ?: '—' }} · {{ $ar?'حُل بواسطة':'Resolved by' }}: {{ $review->resolved_by ? '#'.$review->resolved_by : '—' }} · {{ $review->resolved_at ?: '—' }}</div>
                    @foreach($review->events as $event)<div class="fieldops-code">{{ $event->created_at }} · {{ $event->event_type }} · {{ $event->old_status ?: '—' }} → {{ $event->new_status }} · {{ $event->reason ?: '—' }}</div>@endforeach
                </details>
            </td><td>{{ $review->quality_class }} @if($review->confidence!==null)· {{ number_format((float)$review->confidence*100,1) }}%@endif</td><td>{{ $review->territory_key ?: '—' }}</td><td>{{ $review->status }}</td><td>
                @if($canManageAddress)<details class="foodex-ops-actions"><summary>⋮</summary><div class="foodex-ops-menu">
                    @foreach(['confirm','reject','reopen'] as $action)
                    <form method="post" action="{{ route('admin.field-operations.address-quality.action',['review'=>$review,'action'=>$action]) }}">@csrf
                        @if($action==='confirm')<input name="territory_key" placeholder="{{ $ar?'كود المنطقة':'Territory key' }}" required>@endif
                        <input name="reason" placeholder="{{ $ar?'السبب':'Reason' }}" required>
                        <button type="submit">{{ $action }}</button>
                    </form>
                    @endforeach
                </div></details>@else—@endif
            </td></tr>@empty<tr><td colspan="6"><div class="foodex-ops-state">{{ $ar?'لا توجد مراجعات عناوين.':'No address-quality reviews.' }}</div></td></tr>@endforelse
            </tbody></table></div>{{ $reviews->links() }}

        @elseif($section === 'routing')
            @if($canManageTerritories)
            <details class="fieldops-card"><summary><strong>{{ $ar?'إنشاء سياسة توجيه':'Create routing policy' }}</strong></summary>
                <form method="post" action="{{ route('admin.field-operations.routing.store') }}" class="fieldops-form" style="margin-top:14px">@csrf
                    <div class="fieldops-form-grid"><label>Code<input name="code" required></label><label>Mode<input name="mode" value="MANUAL" required></label><label>{{ $ar?'من':'Effective from' }}<input type="datetime-local" name="effective_from"></label><label>{{ $ar?'حتى':'Effective until' }}<input type="datetime-local" name="effective_until"></label></div>
                    <label>Rules JSON<textarea name="rules_json" rows="6" required>[{"name":"Default","conditions":[],"actions":[],"enabled":true}]</textarea></label>
                    <label>{{ $ar?'سبب/ملاحظة':'Reason' }}<input name="reason"></label><button class="foodex-primary">{{ $ar?'إنشاء Draft':'Create draft' }}</button>
                </form>
            </details>
            @endif
            @if(session('simulation_result'))<section class="fieldops-card"><h2>{{ $ar?'نتيجة المحاكاة':'Simulation result' }} · #{{ session('simulation_policy') }}</h2><pre style="white-space:pre-wrap">{{ json_encode(session('simulation_result'),JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) }}</pre></section>@endif
            @forelse($policies as $policy)
            <article class="fieldops-card"><div class="fieldops-actions"><h3 style="margin:0">{{ $policy->code }} v{{ $policy->version }}</h3><span class="fieldops-status">{{ $policy->status }}</span><span>{{ $policy->mode }}</span><span>{{ $policy->rules->count() }} rules</span></div>
                @if($canManageTerritories)<div class="fieldops-grid" style="margin-top:12px">
                    @if($policy->status==='draft')<form method="post" action="{{ route('admin.field-operations.routing.action',['routingPolicy'=>$policy,'action'=>'publish']) }}">@csrf<button>{{ $ar?'نشر':'Publish' }}</button></form>@endif
                    @if(in_array($policy->status,['published','retired'],true))<form method="post" action="{{ route('admin.field-operations.routing.action',['routingPolicy'=>$policy,'action'=>'rollback']) }}">@csrf<input name="reason" placeholder="{{ $ar?'سبب التراجع':'Rollback reason' }}"><button>{{ $ar?'إنشاء Rollback':'Rollback' }}</button></form>@endif
                    <form method="post" action="{{ route('admin.field-operations.routing.action',['routingPolicy'=>$policy,'action'=>'simulate']) }}" class="fieldops-form">@csrf<label>Input JSON<textarea name="input_json" rows="3">{}</textarea></label><label>Scope JSON<textarea name="scope_json" rows="2">{}</textarea></label><button>{{ $ar?'محاكاة':'Simulate' }}</button></form>
                </div>@endif
            </article>
            @empty<div class="foodex-ops-state">{{ $ar?'لا توجد سياسات توجيه.':'No routing policies.' }}</div>@endforelse
            {{ $policies->links() }}

        @elseif($section === 'finance')
            @include('admin._field-operations-finance', [
                'fieldFinance'=>$fieldFinance,
                'opsRouteName'=>'admin.field-operations.finance',
                'opsRouteParams'=>[],
                'opsReviewRouteName'=>'admin.field-operations.finance.remittances.review',
            ])
        @endif
        </div>
    </main>
</div>
@if($section === 'fleet')
    @include('admin._driver-live-map-scripts')
@elseif($section === 'territories')
    <script src="{{ asset('assets/leaflet/1.9.4/leaflet.js') }}"></script>
    <script>
    (() => {
        const node = document.getElementById('fieldops-coverage-map');
        if (!node || !window.L) return;
        const map = L.map(node).setView([29.3759,47.9774],10);
        L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png',{maxZoom:19,attribution:'&copy; OpenStreetMap contributors'}).addTo(map);
        const existing = @json($territories->flatMap(fn($territory)=>$territory->geometries->map(fn($geometry)=>[
            'type'=>'Feature',
            'properties'=>['territory_id'=>$territory->id,'code'=>$territory->code,'name'=>$ar?$territory->name_ar:$territory->name_en],
            'geometry'=>$geometry->geojson,
        ]))->values());
        const existingLayer = L.geoJSON({type:'FeatureCollection',features:existing},{
            onEachFeature:(feature,layer)=>layer.bindPopup((feature.properties?.name||feature.properties?.code||'Territory'))
        }).addTo(map);
        if (existingLayer.getLayers().length) map.fitBounds(existingLayer.getBounds(),{padding:[24,24],maxZoom:13});

        const form=document.getElementById('fieldops-coverage-form');
        if (!form) return;
        const select=document.getElementById('fieldops-coverage-territory');
        const output=document.getElementById('fieldops-coverage-geojson');
        const clear=document.getElementById('fieldops-coverage-clear');
        const base=@json(url('/admin/field-operations/territories'));
        let points=[];
        let draft=L.layerGroup().addTo(map);
        const redraw=()=>{
            draft.clearLayers();
            points.forEach(point=>L.circleMarker([point[1],point[0]],{radius:5}).addTo(draft));
            if(points.length>=2)L.polyline(points.map(p=>[p[1],p[0]])).addTo(draft);
            if(points.length>=3)L.polygon(points.map(p=>[p[1],p[0]])).addTo(draft);
            if(points.length>=3){
                const ring=[...points,points[0]];
                output.value=JSON.stringify({type:'Polygon',coordinates:[ring]});
            } else output.value='';
        };
        map.on('click',event=>{points.push([Number(event.latlng.lng.toFixed(7)),Number(event.latlng.lat.toFixed(7))]);redraw();});
        clear.addEventListener('click',()=>{points=[];redraw();});
        select.addEventListener('change',()=>{form.action=select.value ? base+'/'+select.value+'/geometry' : '';});
        form.addEventListener('submit',event=>{if(!select.value||points.length<3){event.preventDefault();alert(@json($ar?'اختر منطقة وارسم ثلاث نقاط على الأقل.':'Select a territory and draw at least three points.'));}});
    })();
    </script>
@endif
</body>
</html>
