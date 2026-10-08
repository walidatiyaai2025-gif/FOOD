@php
    $ar = app()->getLocale() === 'ar';
    $isSuper = $user->hasRole('SUPER_ADMIN');
    $canManageVan = $isSuper || $user->hasPermission('drivers.b2b.manage');
    $canManageTerritories = $isSuper || $user->hasPermission('territories.manage') || $user->hasPermission('field_ops.manage');
    $canManageAddress = $isSuper || $user->hasPermission('customers.edit');
    $overviewVisibility = $overviewVisibility ?? [];
    $canCatalog = $isSuper || $user->hasPermission('catalog.view');
    $canPromotions = $isSuper || $user->hasPermission('promotions.view');
    $pageTitle = __('field_operations.pages.'.$section.'.title');
    $pageDescription = __('field_operations.pages.'.$section.'.description');
    if ($pageTitle === 'field_operations.pages.'.$section.'.title') {
        $pageTitle = __('field_operations.pages.overview.title');
        $pageDescription = __('field_operations.pages.overview.description');
    }
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
                <span class="foodex-subtitle">FOODEX · {{ __('field_operations.subtitle') }}</span>
                <h1>{{ $pageTitle }}</h1>
                <p>{{ $pageDescription }}</p>
            </div>
            @include('admin._live-notifications',['user'=>$user])
        </header>

        @if(session('status'))<div class="foodex-state" role="status">{{ session('status') }}</div>@endif
        @if($errors->any())
            <div class="foodex-state" role="alert">
                <strong>{{ __('field_operations.unable_to_save') }}</strong>
                <ul>
                    @foreach($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <div class="foodex-ops-shell">
        @if($section === 'overview')
            @php($s = $summary ?? [])
            <section class="fieldops-kpis">
                @foreach([
                    ['active_vans',__('field_operations.overview.active_vans')],
                    ['suspended_vans',__('field_operations.overview.suspended_vans')],
                    ['assigned_vans',__('field_operations.overview.assigned_vans')],
                    ['unassigned_vans',__('field_operations.overview.unassigned_vans')],
                    ['operators',__('field_operations.overview.operators')],
                    ['customers_served',__('field_operations.overview.customers_served')],
                    ['active_visits',__('field_operations.overview.active_visits')],
                    ['completed_visits',__('field_operations.overview.completed_visits')],
                    ['no_order_visits',__('field_operations.overview.no_order_visits')],
                    ['territories',__('field_operations.overview.territories')],
                    ['unresolved_addresses',__('field_operations.overview.unresolved_addresses')],
                    ['pending_remittances',__('field_operations.overview.pending_remittances')],
                ] as [$key,$label])
                    <article class="fieldops-card fieldops-kpi"><strong>{{ number_format((int)($s[$key] ?? 0)) }}</strong><small>{{ $label }}</small></article>
                @endforeach
            </section>
            <section class="fieldops-grid">
                <article class="fieldops-card">
                    <h2>{{ __('field_operations.overview.fleet_health') }}</h2>
                    @foreach(['online'=>__('field_operations.overview.online'),'stale'=>__('field_operations.overview.stale'),'offline'=>__('field_operations.overview.offline')] as $key=>$label)
                        <div class="control-row"><span>{{ $label }}</span><strong>{{ (int)($s['location_health'][$key] ?? 0) }}</strong></div>
                    @endforeach
                    @if($overviewVisibility['tracking'] ?? false)<div class="fieldops-actions"><a class="foodex-action-primary" href="{{ route('admin.field-operations.fleet') }}">{{ __('field_operations.overview.open_map') }}</a></div>@endif
                </article>
                <article class="fieldops-card">
                    <h2>{{ __('field_operations.overview.custody') }}</h2>
                    <strong style="font-size:1.8rem">{{ number_format((float)($s['outstanding_collections'] ?? 0),3) }}</strong>
                    <p class="fieldops-muted">{{ __('field_operations.overview.custody_help') }}</p>
                    @if($overviewVisibility['finance'] ?? false)<a href="{{ route('admin.field-operations.finance') }}">{{ __('field_operations.overview.open_finance') }}</a>@endif
                </article>
                <article class="fieldops-card">
                    <h2>{{ __('field_operations.overview.shortcuts') }}</h2>
                    <div class="fieldops-section-nav">
                        @if($overviewVisibility['drivers'] ?? false)<a href="{{ route('admin.field-operations.vans') }}">{{ __('field_operations.pages.vans.title') }}</a><a href="{{ route('admin.field-operations.assignments') }}">{{ __('field_operations.overview.assignments') }}</a>@endif
                        @if($overviewVisibility['visits'] ?? false)<a href="{{ route('admin.field-operations.visits') }}">{{ __('field_operations.overview.visits') }}</a>@endif
                        @if($overviewVisibility['territories'] ?? false)<a href="{{ route('admin.field-operations.territories') }}">{{ __('field_operations.overview.territories_shortcut') }}</a>@endif
                    </div>
                </article>
                <article class="fieldops-card">
                    <h2>{{ __('field_operations.overview.commercial_controls') }}</h2>
                    <p class="fieldops-muted">{{ __('field_operations.overview.commercial_help') }}</p>
                    <div class="fieldops-section-nav">
                        @if(($featureFlags['commercial_rules_enabled'] ?? false) && $canCatalog)<a href="{{ route('admin.commercial.sales-control') }}">{{ __('field_operations.overview.sales_rules') }}</a>@endif
                        @if(($featureFlags['van_offers_enabled'] ?? false) && ($featureFlags['flash_offers_enabled'] ?? false) && $canPromotions)<a href="{{ route('admin.commercial.flash-offers') }}">{{ __('field_operations.overview.van_flash_offers') }}</a>@endif
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
                    'noDrivers' => __('field_operations.fleet_i18n.no_vans'),
                    'entitySingular' => __('field_operations.fleet_i18n.entity_singular'),
                    'entities' => __('field_operations.fleet_i18n.entities'),
                    'entityId' => __('field_operations.fleet_i18n.entity_id'),
                    'route' => __('field_operations.fleet_i18n.route'),
                ],
            ])

        @elseif($section === 'vans')
            @if($canManageVan)
            <details class="fieldops-card">
                <summary><strong>{{ __('field_operations.register_van') }}</strong></summary>
                <form method="post" action="{{ route('admin.field-operations.vans.store') }}" class="fieldops-form" style="margin-top:14px">@csrf
                    <div class="fieldops-form-grid">
                        <label>{{ __('field_operations.code') }}<input name="code" required></label>
                        <label>{{ __('field_operations.plate_number') }}<input name="plate_number"></label>
                        <label>{{ __('field_operations.vehicle_type') }}<input name="vehicle_type"></label>
                        <label>{{ __('field_operations.capacity_units') }}<input type="number" min="0" name="capacity_units"></label>
                        <label>{{ __('field_operations.capacity_weight') }}<input type="number" min="0" step=".001" name="capacity_weight"></label>
                        <label>{{ __('field_operations.home_warehouse') }}<select name="home_warehouse_id"><option value="">—</option>@foreach($warehouses as $warehouse)<option value="{{ $warehouse->id }}">{{ $warehouse->name }} · {{ $warehouse->code }}</option>@endforeach</select></label>
                    </div>
                    <label>{{ __('field_operations.notes') }}<textarea name="notes" rows="2"></textarea></label>
                    <button class="foodex-primary" type="submit">{{ __('field_operations.register_van') }}</button>
                </form>
            </details>
            @endif
            <div class="table-wrap"><table class="foodex-ops-grid"><thead><tr>
                <th>{{ __('field_operations.van') }}</th><th>{{ __('field_operations.status') }}</th><th>{{ __('field_operations.current_assignment') }}</th>
                <th>{{ __('field_operations.location') }}</th><th>{{ __('field_operations.last_update') }}</th><th>{{ __('field_operations.action') }}</th>
            </tr></thead><tbody>
            @forelse($vans as $van)
                @php($assignment = $van->assignments->firstWhere('status','active'))
                @php($location = $locations->get($van->id))
                <tr>
                    <td><a href="{{ route('admin.field-operations.vans.show',$van) }}"><strong>{{ $van->code }}</strong></a><div class="fieldops-muted">{{ $van->plate_number ?: '—' }} · {{ $van->vehicle_type ?: '—' }}</div></td>
                    <td><span class="fieldops-status">{{ __('field_operations.statuses.'.$van->status) }}</span></td>
                    <td>@if($assignment){{ $assignment->assignment_type }}<br><span class="fieldops-muted">{{ $assignment->territory_key ?: '—' }}</span>@else—@endif</td>
                    <td>@if($location)<span class="fieldops-code">{{ number_format($location->latitude,5) }}, {{ number_format($location->longitude,5) }}</span>@else—@endif</td>
                    <td>{{ $location?->received_at?->diffForHumans() ?: '—' }}</td>
                    <td>
                        @if($canManageVan && $van->status !== 'suspended')
                        <details class="foodex-ops-actions"><summary>⋮</summary><div class="foodex-ops-menu">
                            <form method="post" action="{{ route('admin.field-operations.vans.suspend',$van) }}">@csrf
                                <select name="transfer_target_van_id" data-van-transfer-lookup>
                                    <option value="">{{ __('field_operations.no_load_transfer') }}</option>
                                    @foreach($transferVans as $targetVan)
                                        @continue($targetVan->id === $van->id)
                                        <option value="{{ $targetVan->id }}">{{ $targetVan->code }}{{ $targetVan->plate_number ? ' · '.$targetVan->plate_number : '' }}</option>
                                    @endforeach
                                </select>
                                <input name="reason" placeholder="{{ __('field_operations.suspension_reason') }}">
                                <button class="danger" type="submit">{{ __('field_operations.suspend') }}</button>
                            </form>
                        </div></details>
                        @else—@endif
                    </td>
                </tr>
            @empty<tr><td colspan="6"><div class="foodex-ops-state">{{ __('field_operations.no_vans') }}</div></td></tr>@endforelse
            </tbody></table></div>
            {{ $vans->links() }}

        @elseif($section === 'van-detail')
            <div class="fieldops-actions"><a href="{{ route('admin.field-operations.vans') }}">← {{ __('field_operations.back_to_vans') }}</a></div>
            <section class="fieldops-grid">
                <article class="fieldops-card"><h2>{{ __('field_operations.van_identity') }}</h2>
                    <div class="control-row"><span>Code</span><strong>{{ $van->code }}</strong></div>
                    <div class="control-row"><span>{{ __('field_operations.plate') }}</span><strong>{{ $van->plate_number ?: '—' }}</strong></div>
                    <div class="control-row"><span>{{ __('field_operations.vehicle_type') }}</span><strong>{{ $van->vehicle_type ?: '—' }}</strong></div>
                    <div class="control-row"><span>{{ __('field_operations.status') }}</span><span class="fieldops-status">{{ __('field_operations.statuses.'.$van->status) }}</span></div>
                    <div class="control-row"><span>{{ __('field_operations.capacity') }}</span><strong>{{ $van->capacity_units ?? '—' }} / {{ $van->capacity_weight ?? '—' }}</strong></div>
                    <div class="control-row"><span>{{ __('field_operations.home_warehouse') }}</span><strong>{{ $homeWarehouseName ?: '—' }}</strong></div>
                    @if($van->notes)<p class="fieldops-muted">{{ $van->notes }}</p>@endif
                </article>
                <article class="fieldops-card"><h2>{{ __('field_operations.location_health') }}</h2>
                    @if($location)
                        <div class="control-row"><span>{{ __('field_operations.status') }}</span><span class="fieldops-status">{{ $locationStatus }}</span></div>
                        <div class="control-row"><span>{{ __('field_operations.coordinates') }}</span><span class="fieldops-code">{{ number_format($location->latitude,6) }}, {{ number_format($location->longitude,6) }}</span></div>
                        <div class="control-row"><span>{{ __('field_operations.last_heartbeat') }}</span><strong>{{ $location->received_at }}</strong></div>
                        <div class="control-row"><span>{{ __('field_operations.route') }}</span><strong>{{ $location->route_key ?: '—' }}</strong></div>
                    @else<div class="foodex-ops-state">{{ __('field_operations.no_location') }}</div>@endif
                </article>
            </section>
            <section class="fieldops-card"><h2>{{ __('field_operations.assignment_history') }}</h2>
                <div class="table-wrap"><table class="foodex-ops-grid"><thead><tr><th>{{ __('field_operations.driver') }}</th><th>{{ __('field_operations.operator') }}</th><th>{{ __('field_operations.territory') }}</th><th>{{ __('field_operations.type') }}</th><th>{{ __('field_operations.status') }}</th><th>{{ __('field_operations.window') }}</th></tr></thead><tbody>
                @forelse($van->assignments as $a)
                    <tr>
                        <td>{{ $driverNames->get($a->driver_id) ?: '—' }}</td>
                        <td>{{ $representativeNames->get($a->representative_user_id) ?: '—' }}</td>
                        <td>@php($territoryLabel=$territoryNames->get($a->territory_key)){{ $territoryLabel?->localized_name ?: ($a->territory_key ?: '—') }}</td>
                        <td>{{ __('field_operations.assignment_types.'.$a->assignment_type) }}</td>
                        <td>{{ __('field_operations.statuses.'.$a->status) }}</td>
                        <td>{{ $a->effective_from }} → {{ $a->effective_until ?: '∞' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="6">{{ __('field_operations.no_assignment_history') }}</td></tr>
                @endforelse
                </tbody></table></div>
            </section>

        @elseif($section === 'assignments')
            @if($canManageVan)
            <details class="fieldops-card">
                <summary><strong>{{ __('field_operations.assign_van') }}</strong></summary>
                <form method="post" id="fieldops-assignment-form" class="fieldops-form" style="margin-top:14px">@csrf
                    <div class="fieldops-form-grid">
                        <label>{{ __('field_operations.van') }}<select name="van_id" required onchange="this.form.action='{{ url('/admin/field-operations/vans') }}/'+this.value+'/assignments'"><option value="">{{ __('field_operations.select') }}</option>@foreach($vans as $van)<option value="{{ $van->id }}">{{ $van->code }}</option>@endforeach</select></label>
                        <label>{{ __('field_operations.driver') }}<select name="driver_id"><option value="">—</option>@foreach($drivers as $driver)<option value="{{ $driver->id }}" data-user="{{ $driver->user_id }}">{{ $driver->name ?: ('Driver #'.$driver->id) }}</option>@endforeach</select></label>
                        <label>{{ __('field_operations.representative_operator') }}<select name="representative_user_id" data-representative-lookup><option value="">—</option>@foreach($representatives as $representative)<option value="{{ $representative->id }}">{{ $representative->name }}{{ $representative->email ? ' · '.$representative->email : '' }}</option>@endforeach</select></label>
                        <label>{{ __('field_operations.territory') }}<select name="territory_key"><option value="">—</option>@foreach($territories as $territory)<option value="{{ $territory->code }}">{{ $territory->localized_name }} · {{ $territory->code }}</option>@endforeach</select></label>
                        <label>{{ __('field_operations.warehouse') }}<select name="warehouse_id" data-warehouse-lookup><option value="">—</option>@foreach($warehouses as $warehouse)<option value="{{ $warehouse->id }}">{{ $warehouse->name }} · {{ $warehouse->code }}</option>@endforeach</select></label>
                        <label>{{ __('field_operations.assignment_type') }}<select name="assignment_type"><option value="primary">{{ __('field_operations.assignment_types.primary') }}</option><option value="backup">{{ __('field_operations.assignment_types.backup') }}</option></select></label>
                        <label>{{ __('field_operations.effective_from') }}<input type="datetime-local" name="effective_from" required></label>
                        <label>{{ __('field_operations.effective_until') }}<input type="datetime-local" name="effective_until"></label>
                        <label>{{ __('field_operations.loaded_work') }}<input type="number" min="0" name="loaded_work_count" value="0"></label>
                    </div>
                    <button class="foodex-primary" type="submit">{{ __('field_operations.save_assignment') }}</button>
                </form>
            </details>
            @endif
            <div class="table-wrap"><table class="foodex-ops-grid"><thead><tr><th>{{ __('field_operations.van') }}</th><th>{{ __('field_operations.driver_operator') }}</th><th>{{ __('field_operations.warehouse') }}</th><th>{{ __('field_operations.territory') }}</th><th>{{ __('field_operations.type') }}</th><th>{{ __('field_operations.status') }}</th><th>{{ __('field_operations.window') }}</th></tr></thead><tbody>
                @forelse($assignments as $a)
                    @php($driverLabel=$drivers->firstWhere('id',$a->driver_id)?->name)
                    @php($representativeLabel=$representatives->firstWhere('id',$a->representative_user_id)?->name)
                    @php($warehouseLabel=$warehouses->firstWhere('id',$a->warehouse_id)?->name)
                    <tr><td>{{ $a->van?->code ?: '—' }}</td><td>{{ trim(($driverLabel ?: '').' '.($representativeLabel ?: '')) ?: '—' }}</td><td>{{ $warehouseLabel ?: '—' }}</td><td>{{ $a->territory_key ?: '—' }}</td><td>{{ __('field_operations.assignment_types.'.$a->assignment_type) }}</td><td>{{ __('field_operations.statuses.'.$a->status) }}</td><td>{{ $a->effective_from }} → {{ $a->effective_until ?: '∞' }}</td></tr>
                @empty<tr><td colspan="7">{{ __('field_operations.no_assignments') }}</td></tr>@endforelse
            </tbody></table></div>{{ $assignments->links() }}

        @elseif($section === 'customers')
            <div class="fieldops-card">
                <strong>{{ __('field_operations.relationship_intro_title') }}</strong>
                <p class="fieldops-muted">{{ __('field_operations.relationship_intro_body') }}</p>
            </div>
            <div class="table-wrap"><table class="foodex-ops-grid"><thead><tr><th>{{ __('field_operations.customer') }}</th><th>{{ __('field_operations.channel') }}</th><th>{{ __('field_operations.van') }}</th><th>{{ __('field_operations.operator') }}</th><th>{{ __('field_operations.territory') }}</th><th>{{ __('field_operations.route') }}</th><th>{{ __('field_operations.collection_context') }}</th><th>{{ __('field_operations.latest_visit') }}</th><th>{{ __('field_operations.status') }}</th></tr></thead><tbody>
            @forelse($relationships as $visit)
                @php($a = $visit->getRelation('servingAssignment'))
                @php($collectionContext = $visit->collection_context)
                <tr>
                    <td>{{ $visit->customer_display }}</td>
                    <td>{{ __('field_operations.customer_types.'.$visit->customer_type) }}</td>
                    <td>{{ $a?->van?->code ?: (($visit->metadata['van_id'] ?? null) ? '#'.$visit->metadata['van_id'] : '—') }}</td>
                    <td>{{ $visit->actor?->name ?: ('User #'.$visit->actor_user_id) }}</td>
                    <td>{{ $a?->territory_key ?: ($visit->metadata['territory_key'] ?? '—') }}</td>
                    <td>{{ $visit->metadata['route_key'] ?? ($visit->metadata['route_code'] ?? '—') }}</td>
                    <td>
                        @if(is_array($collectionContext))
                            <div><strong>Store #{{ $collectionContext['store_id'] }}</strong></div>
                            @forelse($collectionContext['outstanding_total_by_currency'] as $currency => $amount)
                                <div class="fieldops-muted">{{ $currency }} {{ number_format((float) $amount, 3) }}</div>
                            @empty
                                <div class="fieldops-muted">{{ __('field_operations.no_open_balance') }}</div>
                            @endforelse
                        @else
                            —
                        @endif
                    </td>
                    <td>#{{ $visit->id }} · {{ $visit->updated_at }}</td>
                    <td>{{ __('field_operations.visit_statuses.'.$visit->status) }}</td>
                </tr>
            @empty<tr><td colspan="9"><div class="foodex-ops-state">{{ __('field_operations.no_customer_relationships') }}</div></td></tr>@endforelse
            </tbody></table></div>{{ $relationships->links() }}
            <div class="fieldops-card"><a class="foodex-action-primary" href="{{ route('admin.field-operations.visits') }}">{{ __('field_operations.plan_visit_relationship') }}</a></div>

        @elseif($section === 'visits')
            @if($canManageVan)
            <details class="fieldops-card">
                <summary><strong>{{ __('field_operations.plan_customer_visit') }}</strong></summary>
                <form method="post" action="{{ route('admin.field-operations.visits.store') }}" class="fieldops-form" style="margin-top:14px">@csrf
                    <div class="fieldops-form-grid">
                        <label>{{ __('field_operations.van_assignment') }}<select name="assignment_id" required><option value="">—</option>@foreach($assignments as $a)<option value="{{ $a->id }}">{{ $a->van?->code ?: '#'.$a->van_id }} · {{ $a->territory_key ?: '—' }} · {{ $a->representative_user_id ? 'User #'.$a->representative_user_id : 'Driver #'.$a->driver_id }}</option>@endforeach</select></label>
                        <label>{{ __('field_operations.customer_type') }}<select name="customer_type" data-visit-customer-type><option value="b2b">B2B</option><option value="b2c">B2C</option></select></label>
                        <label>{{ __('field_operations.customer') }}<select name="customer_id" data-visit-customer required><option value="">—</option>@foreach($visitCustomers as $customer)<option value="{{ $customer->id }}" data-customer-type="{{ $customer->type }}">{{ $customer->label }} · {{ __('field_operations.customer_types.'.$customer->type) }}</option>@endforeach</select></label>
                        <label>{{ __('field_operations.planned_at') }}<input type="datetime-local" name="planned_at"></label>
                        <label>{{ __('field_operations.store_optional') }}<select name="store_id" data-store-lookup><option value="">—</option>@foreach($visitStores as $store)<option value="{{ $store->id }}">{{ $store->name }} · {{ $store->code }}</option>@endforeach</select></label>
                        <label>{{ __('field_operations.route') }}<select name="route_key" data-route-lookup><option value="">—</option>@foreach($visitRoutes as $route)<option value="{{ $route }}">{{ $route }}</option>@endforeach</select></label>
                    </div>
                    <button class="foodex-primary">{{ __('field_operations.create_planned_visit') }}</button>
                </form>
            </details>
            @endif
            <div class="table-wrap"><table class="foodex-ops-grid"><thead><tr><th>{{ __('field_operations.created') }}</th><th>{{ __('field_operations.customer') }}</th><th>{{ __('field_operations.operator') }}</th><th>{{ __('field_operations.status') }}</th><th>{{ __('field_operations.timestamps') }}</th><th>{{ __('field_operations.result') }}</th><th>{{ __('field_operations.action') }}</th></tr></thead><tbody>
            @forelse($visits as $visit)
                @php($allowed = \App\Services\VanVisitLifecycleService::allowedTransitions((string)$visit->status))
                @php($visitOrder=$visit->order_id ? $visitOrders->firstWhere('id',$visit->order_id) : null)
                <tr><td>{{ $visit->created_at }}</td><td>{{ $visit->customer_display }}<div class="fieldops-muted">{{ __('field_operations.customer_types.'.$visit->customer_type) }}</div></td><td>{{ $visit->actor?->name ?: '—' }}</td><td>{{ __('field_operations.visit_statuses.'.$visit->status) }}</td><td><small>{{ $visit->planned_at ?: '—' }}<br>{{ $visit->started_at ?: '' }}<br>{{ $visit->completed_at ?: '' }}</small></td><td>{{ $visitOrder?->order_number ?: ($visit->noOrderReason?->label_en ?: '—') }}</td><td>
                    @if($canManageVan && $allowed !== [])
                    <details class="foodex-ops-actions"><summary>⋮</summary><div class="foodex-ops-menu">
                        <form method="post" action="{{ route('admin.field-operations.visits.transition',$visit) }}">@csrf
                            <select name="status">@foreach($allowed as $target)<option value="{{ $target }}">{{ __('field_operations.visit_statuses.'.$target) }}</option>@endforeach</select>
                            <select name="no_order_reason_id"><option value="">{{ __('field_operations.no_order_reason') }}</option>@foreach($reasons as $reason)<option value="{{ $reason->id }}">{{ $reason->localized_label }}</option>@endforeach</select>
                            <select name="order_id" data-order-lookup><option value="">{{ __('field_operations.no_linked_order') }}</option>@foreach($visitOrders as $order)<option value="{{ $order->id }}">{{ $order->order_number ?: __('field_operations.order') }} · {{ __('field_operations.order_statuses.'.$order->status) }}</option>@endforeach</select>
                            <button type="submit">{{ __('field_operations.apply') }}</button>
                        </form>
                    </div></details>
                    @else—@endif
                </td></tr>
            @empty<tr><td colspan="7"><div class="foodex-ops-state">{{ __('field_operations.no_visits') }}</div></td></tr>@endforelse
            </tbody></table></div>{{ $visits->links() }}

        @elseif($section === 'territories')
            @if($canManageTerritories)
            <section class="fieldops-grid">
                <details class="fieldops-card" data-foodex-operational-modal><summary><strong>{{ __('field_operations.add_geography_node') }}</strong></summary>
                    <form method="post" action="{{ route('admin.field-operations.geography.store') }}" class="fieldops-form" style="margin-top:14px">@csrf
                        <div class="fieldops-form-grid">
                            <label>{{ __('field_operations.type') }}<select name="type">@foreach(['country','governorate','region','city','markaz','district','area'] as $type)<option value="{{ $type }}">{{ __('field_operations.geography_types.'.$type) }}</option>@endforeach</select></label>
                            <label>{{ __('field_operations.parent') }}<select name="parent_id"><option value="">—</option>@foreach($nodes as $node)<option value="{{ $node->id }}">{{ $node->localized_name }} · {{ __('field_operations.geography_types.'.$node->type) }}</option>@endforeach</select></label>
                            <label>{{ __('field_operations.code') }}<input name="code" required></label><label>{{ __('field_operations.country_code') }}<input name="country_code" value="KW" required></label>
                            <label>{{ __('field_operations.arabic') }}<input name="name_ar" required></label><label>{{ __('field_operations.english') }}<input name="name_en" required></label> {{-- localization-gate: allow — name_ar/name_en are backend field keys; visible labels are localized --}}
                        </div><button class="foodex-primary">{{ __('field_operations.save') }}</button>
                    </form>
                </details>
                <details class="fieldops-card" data-foodex-operational-modal><summary><strong>{{ __('field_operations.create_service_territory') }}</strong></summary>
                    <form method="post" action="{{ route('admin.field-operations.territories.store') }}" class="fieldops-form" style="margin-top:14px">@csrf
                        <div class="fieldops-form-grid">
                            <label>{{ __('field_operations.code') }}<input name="code" required></label><label>{{ __('field_operations.arabic') }}<input name="name_ar" required></label><label>{{ __('field_operations.english') }}<input name="name_en" required></label> {{-- localization-gate: allow — name_ar/name_en are backend field keys; visible labels are localized --}}
                            <label>{{ __('field_operations.country_node') }}<select name="country_node_id" required>
                                @foreach($nodes->where('type','country') as $node)
                                    <option value="{{ $node->id }}">{{ $node->localized_name }}</option>
                                @endforeach
                            </select></label>
                            <label>{{ __('field_operations.status') }}<select name="status"><option value="draft">{{ __('field_operations.statuses.draft') }}</option><option value="active">{{ __('field_operations.statuses.active') }}</option><option value="inactive">{{ __('field_operations.statuses.inactive') }}</option></select></label>
                            <label>{{ __('field_operations.priority') }}<input type="number" min="0" name="priority" value="0"></label>
                        </div><label>{{ __('field_operations.notes') }}<textarea name="notes"></textarea></label><button class="foodex-primary">{{ __('field_operations.save_territory') }}</button>
                    </form>
                </details>
            </section>
            @endif
            <section class="fieldops-card"><h2>{{ __('field_operations.geography_hierarchy') }}</h2><div class="table-wrap"><table class="foodex-ops-grid" data-pagination-required><thead><tr><th>#</th><th>{{ __('field_operations.type') }}</th><th>{{ __('field_operations.code') }}</th><th>{{ __('field_operations.name') }}</th><th>{{ __('field_operations.parent') }}</th></tr></thead><tbody>@forelse($nodeRows as $node)<tr><td>{{ $node->id }}</td><td>{{ __('field_operations.geography_types.'.$node->type) }}</td><td>{{ $node->code }}</td><td>{{ $node->localized_name }}</td><td>{{ $node->parent?->localized_name ?: '—' }}</td></tr>@empty<tr><td colspan="5">{{ __('field_operations.no_geography_nodes') }}</td></tr>@endforelse</tbody></table></div>{{ $nodeRows->links() }}</section>
            <section class="fieldops-card">
                <h2>{{ __('field_operations.coverage_map') }}</h2>
                <p class="fieldops-muted">{{ __('field_operations.coverage_map_help') }}</p>
                <div class="fieldops-coverage-map" id="fieldops-coverage-map" aria-label="{{ __('field_operations.coverage_map_label') }}"></div>
                @if($canManageTerritories && $territories->isNotEmpty())
                <form method="post" id="fieldops-coverage-form" class="fieldops-form" style="margin-top:14px">@csrf
                    <div class="fieldops-form-grid">
                        <label>{{ __('field_operations.service_territory') }}
                            <select id="fieldops-coverage-territory" required>
                                <option value="">{{ __('field_operations.select_territory') }}</option>
                                @foreach($territories as $territory)<option value="{{ $territory->id }}">{{ $territory->localized_name }} · {{ $territory->code }}</option>@endforeach
                            </select>
                        </label>
                        <label>{{ __('field_operations.drawing') }}
                            <span class="fieldops-actions">
                                <button type="button" id="fieldops-coverage-undo">{{ __('field_operations.undo_last_point') }}</button>
                                <button type="button" id="fieldops-coverage-clear">{{ __('field_operations.clear_redraw') }}</button>
                            </span>
                        </label>
                    </div>
                    <p class="fieldops-muted" id="fieldops-coverage-status" aria-live="polite">{{ __('field_operations.draw_help') }}</p>
                    @if($isSuper)
                    <details data-advanced-geojson>
                        <summary>{{ __('field_operations.advanced_geojson') }}</summary>
                        <label style="margin-top:8px">GeoJSON<textarea id="fieldops-coverage-geojson" name="geojson" rows="5" required></textarea></label>
                    </details>
                    @else
                        <input type="hidden" id="fieldops-coverage-geojson" name="geojson" required>
                    @endif
                    <button class="foodex-primary" type="submit">{{ __('field_operations.save_coverage_geometry') }}</button>
                </form>
                @endif
            </section>
            <section class="fieldops-card" data-pagination-required><h2>{{ __('field_operations.service_territories') }}</h2>
                @forelse($territoryRows as $territory)
                    <article style="padding:14px 0;border-bottom:1px solid var(--foodex-border)">
                        <div class="fieldops-actions"><strong>{{ $territory->localized_name }} · {{ $territory->code }}</strong><span class="fieldops-status">{{ __('field_operations.statuses.'.$territory->status) }}</span><span>{{ __('field_operations.geometries') }}: {{ $territory->geometries->count() }}</span></div>
                    </article>
                @empty<div class="foodex-ops-state">{{ __('field_operations.no_service_territories') }}</div>@endforelse
                {{ $territoryRows->links() }}
            </section>

        @elseif($section === 'address-quality')
            <form method="get" class="foodex-ops-toolbar fieldops-card"><label>{{ __('field_operations.search') }}<input name="q" value="{{ $filters['q'] ?? '' }}"></label><label>{{ __('field_operations.status') }}<select name="status"><option value="">{{ __('field_operations.all') }}</option>@foreach(['unmapped','confirmed','rejected'] as $st)<option value="{{ $st }}" @selected(($filters['status']??'')===$st)>{{ __('field_operations.review_statuses.'.$st) }}</option>@endforeach</select></label><button class="foodex-primary">{{ __('field_operations.apply') }}</button></form>
            <div class="table-wrap"><table class="foodex-ops-grid"><thead><tr><th>{{ __('field_operations.subject') }}</th><th>{{ __('field_operations.quality') }}</th><th>{{ __('field_operations.territory') }}</th><th>{{ __('field_operations.status') }}</th><th>{{ __('field_operations.action') }}</th></tr></thead><tbody>
            @forelse($reviews as $review)<tr><td><strong>{{ __('field_operations.address_review') }}</strong><div class="fieldops-muted">{{ $review->public_id ?: __('field_operations.public_reference_unavailable') }}</div>
                <details style="margin-top:6px"><summary>{{ __('field_operations.details_history') }}</summary>
                    <div class="fieldops-muted" style="margin-top:6px">{{ $review->reason ?: __('field_operations.no_recorded_reason') }}</div>
                    @php
                        $sourceKey = 'field_operations.resolution_sources.'.($review->resolution_source ?: 'unknown');
                        $qualityKey = 'field_operations.quality_classes.'.($review->quality_class ?: 'unknown');
                    @endphp
                    <div>{{ __('field_operations.source') }}: {{ \Illuminate\Support\Facades\Lang::has($sourceKey) ? __($sourceKey) : __('field_operations.resolution_sources.unknown') }} · {{ __('field_operations.resolved_by') }}: {{ $resolverNames->get($review->resolved_by) ?: '—' }} · {{ $review->resolved_at ?: '—' }}</div>
                    @foreach($review->events as $event)
                        @php($eventKey = 'field_operations.review_statuses.'.$event->event_type)
                        <div class="fieldops-code">{{ $event->created_at }} · {{ \Illuminate\Support\Facades\Lang::has($eventKey) ? __($eventKey) : __('field_operations.review_statuses.unmapped') }} · {{ $event->old_status ? __('field_operations.review_statuses.'.$event->old_status) : '—' }} → {{ __('field_operations.review_statuses.'.$event->new_status) }} · {{ $event->reason ?: '—' }}</div>
                    @endforeach
                </details>
            </td><td>{{ \Illuminate\Support\Facades\Lang::has($qualityKey) ? __($qualityKey) : __('field_operations.quality_classes.unknown') }} @if($review->confidence!==null)· {{ number_format((float)$review->confidence*100,1) }}%@endif</td><td>{{ $territoryLabels->get($review->territory_key) ?: __('field_operations.unknown_territory') }}</td><td>{{ __('field_operations.review_statuses.'.$review->status) }}</td><td>
                @if($canManageAddress)<details class="foodex-ops-actions"><summary>⋮</summary><div class="foodex-ops-menu">
                    @foreach(['confirm','reject','reopen'] as $action)
                    <form method="post" action="{{ route('admin.field-operations.address-quality.action',['review'=>$review,'action'=>$action]) }}">@csrf
                        @if($action==='confirm')<select name="territory_key" data-territory-lookup required><option value="">{{ __('field_operations.select_territory') }}</option>@foreach($territories as $territory)<option value="{{ $territory->code }}">{{ $territory->localized_name }} · {{ $territory->code }}</option>@endforeach</select>@endif
                        <input name="reason" placeholder="{{ __('field_operations.reason') }}" required>
                        <button type="submit">{{ __('field_operations.actions.'.$action) }}</button>
                    </form>
                    @endforeach
                </div></details>@else—@endif
            </td></tr>@empty<tr><td colspan="5"><div class="foodex-ops-state">{{ __('field_operations.no_address_reviews') }}</div></td></tr>@endforelse
            </tbody></table></div>{{ $reviews->links() }}

        @elseif($section === 'routing')
            @if($canManageTerritories)
            <details class="fieldops-card" data-foodex-operational-modal><summary><strong>{{ __('field_operations.create_routing_policy') }}</strong></summary>
                <form method="post" action="{{ route('admin.field-operations.routing.store') }}" class="fieldops-form" style="margin-top:14px">@csrf
                    <div class="fieldops-form-grid">
                        <label>{{ __('field_operations.code') }}<input name="code" required></label>
                        <label>{{ __('field_operations.mode') }}<select name="mode" required>@foreach(['MANUAL','AUTOMATIC','HYBRID'] as $mode)<option value="{{ $mode }}">{{ __('field_operations.routing_modes.'.strtolower($mode)) }}</option>@endforeach</select></label>
                        <label>{{ __('field_operations.effective_from') }}<input type="datetime-local" name="effective_from"></label>
                        <label>{{ __('field_operations.effective_until') }}<input type="datetime-local" name="effective_until"></label>
                    </div>
                    <div data-routing-rules>
                        <div class="fieldops-card" data-routing-rule style="margin-top:10px">
                            <div class="fieldops-form-grid">
                                <label>{{ __('field_operations.rule_name') }}<input name="rules[0][name]" value="{{ __('field_operations.default_rule') }}" required></label>
                                <label>{{ __('field_operations.condition_key') }}<input name="rules[0][condition_key]"></label>
                                <label>{{ __('field_operations.condition_value') }}<input name="rules[0][condition_value]"></label>
                                <label>{{ __('field_operations.action_key') }}<input name="rules[0][action_key]"></label>
                                <label>{{ __('field_operations.action_value') }}<input name="rules[0][action_value]"></label>
                                <label>{{ __('field_operations.rule_enabled') }}<select name="rules[0][enabled]"><option value="1">{{ __('field_operations.enabled') }}</option><option value="0">{{ __('field_operations.disabled') }}</option></select></label>
                            </div>
                            <button type="button" data-routing-remove-rule>{{ __('field_operations.remove_rule') }}</button>
                        </div>
                    </div>
                    <button type="button" data-routing-add-rule>{{ __('field_operations.add_rule') }}</button>
                    @if($isSuper)
                    <details data-advanced-routing-json style="margin-top:10px">
                        <summary>{{ __('field_operations.advanced_routing_json') }}</summary>
                        <p class="fieldops-muted">{{ __('field_operations.advanced_routing_json_help') }}</p>
                        <label>{{ __('field_operations.rules_json') }}<textarea name="rules_json" rows="6"></textarea></label>
                    </details>
                    @endif
                    <label>{{ __('field_operations.reason') }}<input name="reason"></label><button class="foodex-primary">{{ __('field_operations.create_draft') }}</button>
                </form>
            </details>
            @endif
            @if(session('simulation_result'))<section class="fieldops-card"><h2>{{ __('field_operations.simulation_result') }} · #{{ session('simulation_policy') }}</h2><pre style="white-space:pre-wrap">{{ json_encode(session('simulation_result'),JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) }}</pre></section>@endif
            @forelse($policies as $policy)
            <article class="fieldops-card"><div class="fieldops-actions"><h3 style="margin:0">{{ $policy->code }} v{{ $policy->version }}</h3><span class="fieldops-status">{{ __('field_operations.statuses.'.$policy->status) }}</span><span>{{ __('field_operations.routing_modes.'.strtolower((string)$policy->mode)) }}</span><span>{{ $policy->rules->count() }} {{ __('field_operations.rules') }}</span></div>
                @if($canManageTerritories)<div class="fieldops-grid" style="margin-top:12px">
                    @if($policy->status === 'draft')
                        <form method="post" action="{{ route('admin.field-operations.routing.action',['routingPolicy'=>$policy,'action'=>'publish']) }}">@csrf<button>{{ __('field_operations.publish') }}</button></form>
                    @endif
                    @if(in_array($policy->status, ['published', 'retired'], true))
                        <form method="post" action="{{ route('admin.field-operations.routing.action',['routingPolicy'=>$policy,'action'=>'rollback']) }}">@csrf<input name="reason" placeholder="{{ __('field_operations.rollback_reason') }}"><button>{{ __('field_operations.rollback') }}</button></form>
                    @endif
                    <form method="post" action="{{ route('admin.field-operations.routing.action',['routingPolicy'=>$policy,'action'=>'simulate']) }}" class="fieldops-form">@csrf
                        <strong>{{ __('field_operations.simulation_input') }}</strong>
                        <div data-routing-pair-group="input"><div class="fieldops-form-grid" data-routing-pair><label>{{ __('field_operations.key') }}<input name="input_keys[]" required></label><label>{{ __('field_operations.value') }}<input name="input_values[]"></label><button type="button" data-routing-remove-pair>{{ __('field_operations.remove_pair') }}</button></div></div>
                        <button type="button" data-routing-add-pair="input">{{ __('field_operations.add_input') }}</button>
                        <strong>{{ __('field_operations.simulation_scope') }}</strong>
                        <div data-routing-pair-group="scope"><div class="fieldops-form-grid" data-routing-pair><label>{{ __('field_operations.key') }}<input name="scope_keys[]"></label><label>{{ __('field_operations.value') }}<input name="scope_values[]"></label><button type="button" data-routing-remove-pair>{{ __('field_operations.remove_pair') }}</button></div></div>
                        <button type="button" data-routing-add-pair="scope">{{ __('field_operations.add_scope') }}</button>
                        <label>{{ __('field_operations.simulation_at') }}<input type="datetime-local" name="at"></label>
                        <button>{{ __('field_operations.simulate') }}</button>
                    </form>
                </div>@endif
            </article>
            @empty<div class="foodex-ops-state">{{ __('field_operations.no_routing_policies') }}</div>@endforelse
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
@if($section === 'visits')
<script>
(() => {
    const type = document.querySelector('[data-visit-customer-type]');
    const customer = document.querySelector('[data-visit-customer]');
    if (!type || !customer) return;
    const sync = () => {
        let first = null;
        [...customer.options].forEach((option) => {
            if (!option.dataset.customerType) return;
            const visible = option.dataset.customerType === type.value;
            option.hidden = !visible;
            option.disabled = !visible;
            if (visible && first === null) first = option;
        });
        if (customer.selectedOptions[0]?.disabled) customer.value = '';
    };
    type.addEventListener('change', sync);
    sync();
})();
</script>
@endif
@if($section === 'routing')
<script>
(() => {
    const rules = document.querySelector('[data-routing-rules]');
    const addRule = document.querySelector('[data-routing-add-rule]');
    if (rules && addRule) {
        let ruleIndex = rules.querySelectorAll('[data-routing-rule]').length;
        addRule.addEventListener('click', () => {
            const template = rules.querySelector('[data-routing-rule]');
            if (!template) return;
            const clone = template.cloneNode(true);
            clone.querySelectorAll('[name]').forEach(control => {
                control.name = control.name.replace(/rules\[\d+\]/, 'rules[' + ruleIndex + ']');
                if (control.tagName === 'SELECT') control.value = '1';
                else control.value = '';
            });
            rules.appendChild(clone);
            ruleIndex++;
        });
        rules.addEventListener('click', event => {
            const button = event.target.closest('[data-routing-remove-rule]');
            if (!button) return;
            const rows = rules.querySelectorAll('[data-routing-rule]');
            if (rows.length === 1) return;
            button.closest('[data-routing-rule]')?.remove();
        });
    }

    document.querySelectorAll('[data-routing-add-pair]').forEach(button => {
        button.addEventListener('click', () => {
            const kind = button.dataset.routingAddPair;
            const group = document.querySelector('[data-routing-pair-group="' + kind + '"]');
            const template = group?.querySelector('[data-routing-pair]');
            if (!group || !template) return;
            const clone = template.cloneNode(true);
            clone.querySelectorAll('input').forEach(input => { input.value = ''; input.required = false; });
            group.appendChild(clone);
        });
    });
    document.addEventListener('click', event => {
        const button = event.target.closest('[data-routing-remove-pair]');
        if (!button) return;
        const group = button.closest('[data-routing-pair-group]');
        const rows = group?.querySelectorAll('[data-routing-pair]');
        if (!group || !rows || rows.length === 1) {
            button.closest('[data-routing-pair]')?.querySelectorAll('input').forEach(input => { input.value = ''; });
            return;
        }
        button.closest('[data-routing-pair]')?.remove();
    });
})();
</script>
@endif
@if($section === 'fleet')
    @include('admin._driver-live-map-scripts')
@elseif($section === 'territories')
    <script src="{{ asset('assets/leaflet/1.9.4/leaflet.js') }}"></script>
    <script>
    (() => {
        const node = document.getElementById('fieldops-coverage-map');
        if (!node || !window.L) return;
        const map = L.map(node,{doubleClickZoom:false}).setView([26.8206,30.8025],6);
        L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png',{maxZoom:19,attribution:'&copy; OpenStreetMap contributors'}).addTo(map);
        const existing = [];
        @foreach($territories as $territory)
            @foreach($territory->geometries as $geometry)
                existing.push({
                    type: 'Feature',
                    properties: {
                        territory_id: @json($territory->id),
                        code: @json($territory->code),
                        name: @json($territory->localized_name),
                        version: @json($geometry->version),
                    },
                    geometry: @json($geometry->geojson),
                });
            @endforeach
        @endforeach
        const existingLayer = L.geoJSON({type:'FeatureCollection',features:existing},{
            onEachFeature:(feature,layer)=>layer.bindPopup((feature.properties?.name||feature.properties?.code||'Territory'))
        }).addTo(map);
        if (existingLayer.getLayers().length) map.fitBounds(existingLayer.getBounds(),{padding:[24,24],maxZoom:13});

        const form=document.getElementById('fieldops-coverage-form');
        if (!form) return;
        const select=document.getElementById('fieldops-coverage-territory');
        const output=document.getElementById('fieldops-coverage-geojson');
        const clear=document.getElementById('fieldops-coverage-clear');
        const undo=document.getElementById('fieldops-coverage-undo');
        const status=document.getElementById('fieldops-coverage-status');
        const base=@json(url('/admin/field-operations/territories'));
        let points=[];
        let draft=L.layerGroup().addTo(map);
        const polygonArea=()=>Math.abs(points.reduce((sum,point,index)=>{
            const next=points[(index+1)%points.length]||point;
            return sum+(point[0]*next[1])-(next[0]*point[1]);
        },0)/2);
        const validPolygon=()=>points.length>=3&&new Set(points.map(point=>point.join(','))).size>=3&&polygonArea()>0.000000001;
        const redraw=()=>{
            draft.clearLayers();
            points.forEach((point,index)=>{
                const marker=L.marker([point[1],point[0]],{draggable:true,title:@json(__('field_operations.map_drag_delete'))}).addTo(draft);
                marker.on('dragend',event=>{
                    const pos=event.target.getLatLng();
                    points[index]=[Number(pos.lng.toFixed(7)),Number(pos.lat.toFixed(7))];
                    redraw();
                });
                marker.on('dblclick',event=>{
                    L.DomEvent.stopPropagation(event);
                    points.splice(index,1);
                    redraw();
                });
            });
            if(points.length>=2)L.polyline(points.map(p=>[p[1],p[0]])).addTo(draft);
            if(validPolygon()){
                L.polygon(points.map(p=>[p[1],p[0]])).addTo(draft);
                const ring=[...points,points[0]];
                output.value=JSON.stringify({type:'Polygon',coordinates:[ring]});
                if(status) status.textContent=@json(__('field_operations.polygon_valid'));
            } else {
                output.value='';
                if(status) status.textContent=points.length<3
                    ? @json(__('field_operations.polygon_need_three'))
                    : @json(__('field_operations.polygon_invalid'));
            }
        };
        const editableRing=feature=>{
            const geometry=feature?.geometry;
            if(!geometry) return null;
            if(geometry.type==='Polygon') return geometry.coordinates?.[0]||null;
            if(geometry.type==='MultiPolygon') return geometry.coordinates?.[0]?.[0]||null;
            return null;
        };
        const loadSelectedGeometry=()=>{
            form.action=select.value ? base+'/'+select.value+'/geometry' : '';
            if(!select.value){
                points=[];
                redraw();
                return;
            }
            const candidates=existing
                .filter(feature=>String(feature.properties?.territory_id)===String(select.value))
                .sort((a,b)=>Number(b.properties?.version||0)-Number(a.properties?.version||0));
            const ring=editableRing(candidates[0]);
            if(!Array.isArray(ring)||ring.length<4){
                points=[];
                redraw();
                return;
            }
            points=ring.map(point=>[Number(point[0]),Number(point[1])]);
            if(points.length>1&&points[0][0]===points[points.length-1][0]&&points[0][1]===points[points.length-1][1]){
                points.pop();
            }
            redraw();
            if(points.length){
                map.fitBounds(L.latLngBounds(points.map(point=>[point[1],point[0]])),{padding:[24,24],maxZoom:15});
            }
        };
        map.on('click',event=>{points.push([Number(event.latlng.lng.toFixed(7)),Number(event.latlng.lat.toFixed(7))]);redraw();});
        undo.addEventListener('click',()=>{points.pop();redraw();});
        clear.addEventListener('click',()=>{points=[];redraw();});
        select.addEventListener('change',loadSelectedGeometry);
        form.addEventListener('submit',event=>{if(!select.value||!validPolygon()){event.preventDefault();alert(@json(__('field_operations.polygon_submit_invalid')));}});
        redraw();
    })();
    </script>
@endif
</body>
</html>
