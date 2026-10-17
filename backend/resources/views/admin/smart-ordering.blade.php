<!doctype html>
<html lang="{{ app()->getLocale() }}" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
    <title>{{ __('smart_ordering.title') }}</title>
    @include('admin._brand-components')
    @include('admin._settings-page-polish')
    <style>
        .so-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,320px),1fr));gap:var(--foodex-space-4)}
        .so-list{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:10px}
        .so-code{font-family:ui-monospace,SFMono-Regular,Menlo,monospace;direction:ltr}
        textarea.so-json{min-height:260px;font-family:ui-monospace,SFMono-Regular,Menlo,monospace;direction:ltr}
    </style>
</head>
<body>
<div class="foodex-admin-layout" data-foodex-mobile-web-page="smart-ordering">
    <aside class="sidebar">@include('admin._sidebar', ['navGroups' => app(\App\Support\AdminNavigation::class)->groupsFor(auth()->user()), 'navContext' => 'smart_ordering', 'user' => auth()->user()])</aside> {{-- localization-gate: allow Blade include expression --}}
    <main class="foodex-admin-main foodex-admin-page foodex-settings-surface">
@include('admin._global-header')
        <div class="foodex-page-toolbar">
            <div><h1>{{ __('smart_ordering.title') }}</h1><p>{{ __('smart_ordering.description') }}</p></div>
            <a class="foodex-action-primary" href="{{ route('admin.smart-ordering.operations.index') }}">{{ __('smart_ordering.operations_tab') }}</a>
        </div>
        @include('admin._smart-ordering-workspace-nav')

        @php
            $ov = $restaurantOverview;
            $ovFilters = $ov['filters'];
            $ovQuery = array_filter([
                'branch' => $ovFilters['branch'] ?? null,
                'from' => $ovFilters['from'] ?? null,
                'to' => $ovFilters['to'] ?? null,
                'channel' => $ovFilters['channel'] ?? null,
                'support_access' => $supportAccess ? 1 : null,
            ], fn($value) => $value !== null && $value !== '');
            $operationsQuery = array_filter([
                'branch' => $ovFilters['branch'] ?? null,
                'from' => $ovFilters['from'] ?? null,
                'to' => $ovFilters['to'] ?? null,
                'fulfillment_mode' => $ovFilters['channel'] ?? null,
            ], fn($value) => $value !== null && $value !== '');
            $analyticsQuery = array_filter([
                'store_id' => $site->store_id,
                'branch_id' => $ovFilters['branch_id'] ?? null,
                'from' => $ovFilters['from'] ?? null,
                'to' => $ovFilters['to'] ?? null,
                'fulfillment' => $ovFilters['channel'] ?? null,
            ], fn($value) => $value !== null && $value !== '');
            $overviewCurrency = $ov['currency'] ?? 'KWD';
            $money = fn($value) => $value === null ? '—' : $overviewCurrency.' '.\App\Support\DisplayNumber::money((float)$value, $overviewCurrency);
            $number = fn($value) => $value === null ? '—' : number_format((int)$value);
            $kpis = [
                ['key'=>'orders_today','value'=>$number($ov['kpis']['orders_today']),'href'=>route('admin.smart-ordering.operations.index',$operationsQuery)],
                ['key'=>'revenue','value'=>$money($ov['kpis']['revenue']),'href'=>route('admin.smart-ordering.analytics.index',$analyticsQuery)],
                ['key'=>'aov','value'=>$money($ov['kpis']['aov']),'href'=>route('admin.smart-ordering.analytics.index',$analyticsQuery)],
                ['key'=>'reservations','value'=>$number($ov['kpis']['reservations']),'href'=>route('admin.smart-ordering.operations.index',$operationsQuery+['tab'=>'reservations'])],
                ['key'=>'waitlist','value'=>$number($ov['kpis']['waitlist']),'href'=>route('admin.smart-ordering.operations.index',$operationsQuery+['tab'=>'waitlist'])],
                ['key'=>'kitchen_backlog','value'=>$number($ov['kpis']['kitchen_backlog']),'href'=>route('admin.smart-ordering.operations.index',$operationsQuery+['tab'=>'kitchen'])],
                ['key'=>'kitchen_late','value'=>$number($ov['kpis']['kitchen_late']),'href'=>route('admin.smart-ordering.operations.index',$operationsQuery+['tab'=>'kitchen'])],
                ['key'=>'on_shift','value'=>$number($ov['kpis']['on_shift']),'href'=>route('admin.smart-ordering.operations.index',$operationsQuery+['tab'=>'workforce'])],
                ['key'=>'attendance_exceptions','value'=>$number($ov['kpis']['attendance_exceptions']),'href'=>route('admin.smart-ordering.operations.index',$operationsQuery+['tab'=>'workforce'])],
                ['key'=>'waste_events','value'=>$number($ov['kpis']['waste_events']),'href'=>route('admin.smart-ordering.supply.index',[$site]+($supportAccess?['support_access'=>1]:[]))],
                ['key'=>'reorder_drafts','value'=>$number($ov['kpis']['reorder_drafts']),'href'=>route('admin.smart-ordering.supply.index',[$site]+($supportAccess?['support_access'=>1]:[]))],
                ['key'=>'integrations_degraded','value'=>$number($ov['kpis']['integrations_degraded']),'href'=>route('admin.smart-ordering.integrations.index',[$site]+($supportAccess?['support_access'=>1]:[]))],
            ];
            $maxTrendOrders = max(1, collect($ov['trend'])->max('orders') ?? 1);
            $statusTotal = max(1, array_sum($ov['order_status']));
        @endphp

        <section class="foodex-module-overview" data-foodex-module-overview data-restaurant-operations-overview>
            <div class="foodex-page-toolbar">
                <div>
                    <h2>{{ __('smart_ordering.overview.title') }}</h2>
                    <p>{{ __('smart_ordering.overview.description') }}</p>
                </div>
                <div class="foodex-header-actions">
                    @if(auth()->user()->hasPermission('smart_ordering.analytics.export',(int)$site->store_id) || auth()->user()->hasPermission('smart_ordering.analytics.export'))
                        <a class="foodex-action-secondary button secondary foodex-module-export" data-foodex-module-export href="{{ route('admin.smart-ordering.analytics.export',$analyticsQuery) }}">{{ __('smart_ordering.overview.export') }}</a>
                    @endif
                    <a class="foodex-action-primary" href="{{ route('admin.smart-ordering.operations.index',$operationsQuery) }}">{{ __('smart_ordering.overview.open_operations') }}</a>
                </div>
            </div>

            <form class="foodex-module-toolbar" data-foodex-module-filters method="get" action="{{ route('admin.smart-ordering.index') }}">
                @if($supportAccess)<input type="hidden" name="support_access" value="1">@endif
                <input type="hidden" name="store_id" value="{{ $site->store_id }}">
                <label class="foodex-module-filter-group foodex-module-scope">
                    <span>{{ __('smart_ordering.overview.filters.branch') }}</span>
                    <select name="branch">
                        <option value="">{{ __('smart_ordering.overview.filters.all_branches') }}</option>
                        @foreach($ov['branches'] as $branchOption)
                            <option value="{{ $branchOption->public_id }}" @selected(($ovFilters['branch'] ?? null)===$branchOption->public_id)>{{ app()->getLocale()==='ar' && $branchOption->name_ar ? $branchOption->name_ar : $branchOption->name_en }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="foodex-module-filter-group">
                    <span>{{ __('smart_ordering.overview.filters.channel') }}</span>
                    <select name="channel">
                        <option value="">{{ __('smart_ordering.overview.filters.all_channels') }}</option>
                        @foreach(['dine_in','pickup','delivery'] as $channel)
                            <option value="{{ $channel }}" @selected(($ovFilters['channel'] ?? null)===$channel)>{{ __('smart_ordering.modes.'.$channel) }}</option>
                        @endforeach
                    </select>
                </label>
                <div class="foodex-module-date-range">
                    <label class="foodex-module-filter-group"><span>{{ __('smart_ordering.overview.filters.from') }}</span><input type="date" name="from" value="{{ $ovFilters['from'] }}"></label>
                    <label class="foodex-module-filter-group"><span>{{ __('smart_ordering.overview.filters.to') }}</span><input type="date" name="to" value="{{ $ovFilters['to'] }}"></label>
                    <button class="foodex-filter-action" type="submit">{{ __('smart_ordering.overview.filters.apply') }}</button>
                </div>
                <div class="foodex-module-toolbar-actions">
                    <a class="foodex-action-secondary button secondary" href="{{ route('admin.smart-ordering.index',['store_id'=>$site->store_id]+($supportAccess?['support_access'=>1]:[])) }}">{{ __('smart_ordering.overview.filters.reset') }}</a>
                </div>
            </form>

            <div class="foodex-module-kpis">
                @foreach($kpis as $metric)
                    <a class="foodex-module-kpi foodex-viz-card" data-foodex-module-kpi data-interactive="true" href="{{ $metric['href'] }}">
                        <span class="foodex-module-kpi-label">{{ __('smart_ordering.overview.kpis.'.$metric['key']) }}</span>
                        <strong class="foodex-module-kpi-value foodex-number">{{ $metric['value'] }}</strong>
                        <small class="foodex-module-updated">{{ __('module_dashboard.updated_at',['time'=>\Carbon\Carbon::parse($ov['last_refresh'])->timezone('Asia/Kuwait')->format('Y-m-d H:i')]) }}</small>
                    </a>
                @endforeach
            </div>

            <div class="foodex-module-panels">
                <article class="foodex-viz-card foodex-module-chart" data-foodex-module-chart>
                    <div class="foodex-viz-header">
                        <div><h3 class="foodex-viz-title">{{ __('smart_ordering.overview.orders_trend') }}</h3><p class="foodex-viz-subtitle">{{ __('smart_ordering.overview.orders_trend_help') }}</p></div>
                        <a href="{{ route('admin.smart-ordering.analytics.index',$analyticsQuery) }}">{{ __('smart_ordering.analytics.title') }}</a>
                    </div>
                    @if(count($ov['trend']))
                        <div class="foodex-viz-bars" role="img" aria-label="{{ __('smart_ordering.overview.orders_trend') }}">
                            @foreach($ov['trend'] as $point)
                                <span class="foodex-viz-bar" title="{{ $point['date'] }} · {{ $point['orders'] }}" style="height:{{ max(5,round(($point['orders']/$maxTrendOrders)*100)) }}%"></span>
                            @endforeach
                        </div>
                        <div class="foodex-module-chart-table-wrap">
                            <table class="foodex-module-chart-table" data-pagination-exempt="bounded-90-day-overview-trend">
                                <thead><tr><th>{{ __('smart_ordering.overview.filters.from') }}</th><th>{{ __('smart_ordering.overview.kpis.orders_period') }}</th><th>{{ __('smart_ordering.overview.kpis.revenue') }}</th></tr></thead>
                                <tbody>@foreach($ov['trend'] as $point)<tr><td class="foodex-number">{{ $point['date'] }}</td><td class="foodex-number">{{ number_format($point['orders']) }}</td><td class="foodex-number">{{ $money($point['revenue']) }}</td></tr>@endforeach</tbody>
                            </table>
                        </div>
                    @else
                        @include('admin._module-dashboard-state',['state'=>'empty','message'=>__('smart_ordering.overview.no_data')])
                    @endif
                </article>

                <article class="foodex-viz-card foodex-module-chart" data-foodex-module-chart>
                    <div class="foodex-viz-header"><div><h3 class="foodex-viz-title">{{ __('smart_ordering.overview.status_mix') }}</h3><p class="foodex-viz-subtitle">{{ __('smart_ordering.overview.status_mix_help') }}</p></div></div>
                    @if(count($ov['order_status']))
                        <div class="foodex-viz-legend">
                            @foreach($ov['order_status'] as $status=>$count)
                                <a class="foodex-viz-legend-item" href="{{ route('admin.smart-ordering.operations.index',$operationsQuery+['status'=>$status]) }}">
                                    <span class="foodex-viz-swatch"></span>
                                    <span>{{ __('smart_ordering.statuses.'.$status) }}</span>
                                    <strong class="foodex-number">{{ $count }} · {{ round(($count/$statusTotal)*100) }}%</strong>
                                </a>
                            @endforeach
                        </div>
                    @else
                        @include('admin._module-dashboard-state',['state'=>'empty','message'=>__('smart_ordering.overview.no_data')])
                    @endif
                </article>
            </div>

            <div class="foodex-module-panels">
                <article class="foodex-viz-card">
                    <div class="foodex-viz-header"><h3 class="foodex-viz-title">{{ __('smart_ordering.overview.exceptions') }}</h3></div>
                    @forelse($ov['exceptions'] as $exception)
                        @php
                            $exceptionHref = match($exception['key']) {
                                'kitchen_late' => route('admin.smart-ordering.operations.index',$operationsQuery+['tab'=>'kitchen']),
                                'attendance' => route('admin.smart-ordering.operations.index',$operationsQuery+['tab'=>'workforce']),
                                'waitlist' => route('admin.smart-ordering.operations.index',$operationsQuery+['tab'=>'waitlist']),
                                'reorder' => route('admin.smart-ordering.supply.index',[$site]+($supportAccess?['support_access'=>1]:[])),
                                default => route('admin.smart-ordering.integrations.index',[$site]+($supportAccess?['support_access'=>1]:[])),
                            };
                        @endphp
                        <a class="foodex-card" style="display:flex;justify-content:space-between;gap:12px;padding:12px;margin-block:8px;text-decoration:none" href="{{ $exceptionHref }}">
                            <span>{{ __('smart_ordering.overview.exceptions_labels.'.$exception['key']) }}</span>
                            <span class="foodex-status-badge" data-tone="warning">{{ number_format($exception['count']) }}</span>
                        </a>
                    @empty
                        <div class="foodex-module-state" data-foodex-module-state="current" role="status"><strong>{{ __('smart_ordering.overview.no_exceptions') }}</strong></div>
                    @endforelse
                </article>

                <article class="foodex-viz-card">
                    <div class="foodex-viz-header"><h3 class="foodex-viz-title">{{ __('smart_ordering.overview.branch_health') }}</h3></div>
                    <div class="so-list">
                        @foreach($ov['branch_health'] as $branchState)
                            <a class="foodex-card" style="padding:12px;text-decoration:none" href="{{ route('admin.smart-ordering.index',['store_id'=>$site->store_id,'branch'=>$branchState['public_id']]+($supportAccess?['support_access'=>1]:[])) }}">
                                <strong>{{ $branchState['label'] }}</strong>
                                <div><span class="foodex-status-badge" data-state="{{ $branchState['state']==='healthy'?'active':($branchState['state']==='disabled'?'inactive':'warning') }}">{{ __('smart_ordering.overview.health.'.$branchState['state']) }}</span></div>
                            </a>
                        @endforeach
                    </div>
                </article>
            </div>
        </section>
        <section class="panel">
            <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap">
                <div><h2>{{ __('smart_ordering_menu.nav_title') }}</h2><p>{{ __('smart_ordering_menu.description') }}</p></div>
                <a class="primary" href="{{ route('admin.smart-ordering.menu.index',['store_id'=>$site->store_id] + ($supportAccess ? ['support_access'=>1] : [])) }}">{{ __('smart_ordering_menu.nav_title') }}</a>
            </div>
        </section>

        @if($entitlements['foodex_supply_intelligence'] ?? false)
        <section class="panel">
            <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap">
                <div>
                    <h2>{{ __('smart_ordering_supply.title') }}</h2>
                    <p>{{ __('smart_ordering_supply.nav_description') }}</p>
                </div>
                <a class="primary" href="{{ route('admin.smart-ordering.supply.index',[$site] + ($supportAccess ? ['support_access'=>1] : [])) }}">{{ __('smart_ordering_supply.open_supply') }}</a>
            </div>
        </section>
        <section class="panel">
            <div class="so-section-head">
                <div>
                    <h2>{{ __('restaurant_integrations.title') }}</h2>
                    <p>{{ __('restaurant_integrations.subtitle') }}</p>
                </div>
                <a class="primary" href="{{ route('admin.smart-ordering.integrations.index',[$site] + ($supportAccess ? ['support_access'=>1] : [])) }}">{{ __('restaurant_integrations.title') }}</a>
            </div>
        </section>
        @endif

        <section class="panel">
            <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap">
                <div>
                    <h2>{{ __('restaurant_growth.title') }}</h2>
                    <p>{{ __('restaurant_growth.subtitle') }}</p>
                </div>
                <a class="primary" href="{{ route('admin.smart-ordering.growth.index',[$site] + ($supportAccess ? ['support_access'=>1] : [])) }}">{{ __('restaurant_growth.title') }}</a>
            </div>
        </section>

        @if(session('status'))<p class="foodex-alert success" role="status">{{ session('status') }}</p>@endif
        @if($errors->any())<div class="foodex-alert error" role="alert">{{ $errors->first() }}</div>@endif

        @include('admin._restaurant-onboarding')

        <section class="panel">
            <form class="foodex-form" method="get" action="{{ route('admin.smart-ordering.index') }}">
                @if($supportAccess)<input type="hidden" name="support_access" value="1">@endif
                <label for="store">{{ __('smart_ordering.store') }}</label>
                <select class="foodex-control" id="store" name="store_id" onchange="this.form.submit()">
                    @foreach($stores as $store)
                    <option value="{{ $store->id }}" @selected((int)$site->store_id === (int)$store->id)>{{ $store->name }}</option>
                    @endforeach
                </select>
            </form>
        </section>

        <div class="so-grid">
            <section class="panel">
                <h2>{{ __('smart_ordering.runtime') }}</h2>
                <form class="foodex-form" method="post" action="{{ route('admin.smart-ordering.runtime', $site) }}">
                    @csrf @method('PUT')
                    @if($supportAccess)<input type="hidden" name="support_access" value="1">@endif
                    <label><input type="checkbox" name="master_enabled" value="1" @checked($site->master_enabled)> {{ __('smart_ordering.master_enabled') }}</label>
                    <label for="runtime-state">{{ __('smart_ordering.runtime_state') }}</label>
                    <select class="foodex-control" id="runtime-state" name="runtime_state">
                        @foreach($states as $state)<option value="{{ $state }}" @selected($site->runtime_state === $state)>{{ __('smart_ordering.states.'.$state) }}</option>@endforeach
                    </select>
                    <button class="primary" type="submit">{{ __('smart_ordering.save') }}</button>
                </form>
            </section>

            <section class="panel">
                <h2>{{ __('smart_ordering.identity') }}</h2>
                <p class="so-code">{{ $site->public_id }}</p>
                <form class="foodex-form" method="post" action="{{ route('admin.smart-ordering.slug', $site) }}">
                    @csrf @method('PUT')
                    @if($supportAccess)<input type="hidden" name="support_access" value="1">@endif
                    <label for="site-slug">{{ __('smart_ordering.slug') }}</label>
                    <input class="foodex-control so-code" id="site-slug" name="slug" value="{{ $site->slug }}" required>
                    <button class="primary" type="submit">{{ __('smart_ordering.save') }}</button>
                </form>
                <form class="foodex-form" method="post" action="{{ route('admin.smart-ordering.custom-domain', $site) }}">
                    @csrf @method('PUT')
                    @if($supportAccess)<input type="hidden" name="support_access" value="1">@endif
                    <label for="custom-domain">{{ __('smart_ordering.custom_domain') }}</label>
                    <input class="foodex-control so-code" id="custom-domain" name="custom_domain" value="{{ $site->custom_domain }}">
                    <p>{{ $site->custom_domain_verified_at ? __('smart_ordering.domain_verified') : __('smart_ordering.domain_pending') }}</p>
                    <button class="primary" type="submit">{{ __('smart_ordering.save') }}</button>
                </form>
            </section>
        </div>

        <section class="panel">
            <h2>{{ __('smart_ordering.entitlements') }}</h2>
            <form class="foodex-form" method="post" action="{{ route('admin.smart-ordering.entitlements', $site) }}">
                @csrf @method('PUT')
                @if($supportAccess)<input type="hidden" name="support_access" value="1">@endif
                <div class="so-list">
                    @foreach($modules as $module)
                    <label><input type="checkbox" name="modules[]" value="{{ $module }}" @checked($entitlements[$module] ?? false)> {{ __('smart_ordering.modules.'.$module) }}</label>
                    @endforeach
                </div>
                <button class="primary" type="submit">{{ __('smart_ordering.save') }}</button>
            </form>
        </section>

        <section class="panel">
            <h2>{{ __('smart_ordering.publishing') }}</h2>
            <p>{{ __('smart_ordering.published_checksum') }} <span class="so-code">{{ $published->checksum }}</span></p>
            @if(!$draft)
            <form method="post" action="{{ route('admin.smart-ordering.draft', $site) }}">
                @csrf
                @if($supportAccess)<input type="hidden" name="support_access" value="1">@endif
                <button class="primary" type="submit">{{ __('smart_ordering.create_draft') }}</button>
            </form>
            @else
            <p>{{ __('smart_ordering.draft_ready') }} <span class="so-code">{{ $draft->checksum }}</span></p>
            <form method="post" action="{{ route('admin.smart-ordering.revisions.publish', $draft) }}">
                @csrf
                @if($supportAccess)<input type="hidden" name="support_access" value="1">@endif
                <button class="primary" type="submit">{{ __('smart_ordering.publish') }}</button>
            </form>
            @endif
        </section>
    </main>
</div>
</body>
</html>
