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
        .so-settings-page{display:grid;gap:8px}
        .so-settings-head{display:flex;align-items:center;justify-content:space-between;gap:12px;min-width:0;padding:10px 12px;border:1px solid var(--foodex-border);border-radius:16px;background:var(--foodex-surface);box-shadow:0 5px 18px rgba(15,23,42,.04)}
        .so-settings-head-main{display:flex;align-items:center;gap:10px;min-width:0}
        .so-settings-head-icon{width:38px;height:38px;flex:0 0 38px;display:grid;place-items:center;border-radius:12px;background:var(--foodex-green-soft);color:var(--foodex-green-dark);font-weight:900}
        .so-settings-head h1{margin:0;font-size:1.03rem}.so-settings-head p{margin:2px 0 0;color:var(--foodex-muted);font-size:.7rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:680px}
        .so-settings-head-actions{display:flex;align-items:center;gap:6px;flex:0 0 auto}
        .so-settings-summary{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:6px}
        .so-settings-summary-item{min-width:0;padding:9px 11px;border:1px solid var(--foodex-border);border-radius:13px;background:var(--foodex-surface);display:grid;gap:4px}
        .so-settings-summary-item span{color:var(--foodex-muted);font-size:.61rem;font-weight:800}.so-settings-summary-item strong{font-size:.82rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
        .so-settings-summary-item[data-tone="green"]{border-top:3px solid var(--foodex-palette-16a34a)}.so-settings-summary-item[data-tone="orange"]{border-top:3px solid var(--foodex-palette-f97316)}.so-settings-summary-item[data-tone="blue"]{border-top:3px solid var(--foodex-palette-2563eb)}.so-settings-summary-item[data-tone="violet"]{border-top:3px solid var(--foodex-palette-7c3aed)}
        .so-settings-tabs{display:flex;align-items:center;gap:6px;overflow-x:auto;padding:2px 0 3px;scrollbar-width:thin}
        .so-settings-tab{display:inline-flex;align-items:center;justify-content:center;min-height:38px;padding:0 14px;border:1px solid var(--foodex-border);border-radius:999px;background:var(--foodex-surface);color:var(--foodex-muted);font-size:.73rem;font-weight:900;white-space:nowrap;cursor:pointer;transition:.18s ease}
        .so-settings-tab:hover{border-color:var(--foodex-palette-b9d8c1);color:var(--foodex-green-dark);transform:translateY(-1px)}
        .so-settings-tab[aria-selected="true"]{border-color:var(--foodex-action-green);background:var(--foodex-action-green);color:#fff;box-shadow:0 6px 18px rgba(21,138,58,.14)}
        .so-settings-panel{display:grid;gap:7px;min-width:0}.so-settings-panel[hidden]{display:none!important}
        .so-settings-panel>.panel,.so-settings-panel>.foodex-card,.so-settings-panel>.foodex-module-overview{margin:0!important}
        .so-settings-panel .panel{padding:12px!important}
        .so-quick-links{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:6px}
        .so-quick-link{display:flex;align-items:center;justify-content:space-between;gap:8px;min-height:52px;padding:9px 11px;border:1px solid var(--foodex-border);border-radius:13px;background:var(--foodex-surface);text-decoration:none;color:inherit}
        .so-quick-link:hover{background:var(--foodex-green-soft);border-color:var(--foodex-palette-bedbc5)}.so-quick-link strong{font-size:.74rem}.so-quick-link span{font-size:.63rem;color:var(--foodex-muted)}
        .so-settings-panel .foodex-page-toolbar{margin:0!important;padding-block:6px!important}
        .so-settings-panel .foodex-page-toolbar h2,.so-settings-panel .foodex-page-toolbar h3{margin-block:0 3px!important}
        .so-settings-panel .foodex-page-toolbar p{margin:0!important}
        .so-settings-panel .foodex-module-toolbar{margin-block:4px 6px!important;padding:8px!important}
        .so-settings-panel .foodex-module-kpis{gap:6px!important;margin-block:4px!important}
        .so-settings-panel .foodex-module-kpi{padding:10px!important;min-height:84px!important}
        .so-settings-panel fieldset{border:1px solid var(--foodex-border);border-radius:12px;padding:10px;margin:0 0 8px}
        .so-settings-panel legend{font-weight:900;padding-inline:5px}
        .so-settings-panel[data-so-settings-panel="modules"] .so-list{grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:6px}
        .so-settings-panel[data-so-settings-panel="modules"] .so-list label{display:flex;align-items:center;gap:7px;min-height:38px;padding:7px 9px;border:1px solid var(--foodex-border);border-radius:10px;background:var(--foodex-surface);font-size:.7rem;font-weight:800}
        .so-settings-panel[data-so-settings-panel="modules"] .so-list input{margin:0}
        .so-settings-panel[data-so-settings-panel="site"] .so-grid{gap:7px}
        .so-settings-panel[data-so-settings-panel="site"] .foodex-form{gap:8px}

        @media(max-width:1100px){.so-settings-summary{grid-template-columns:repeat(2,minmax(0,1fr))}.so-quick-links{grid-template-columns:repeat(2,minmax(0,1fr))}}
        @media(max-width:700px){.so-settings-head{align-items:flex-start}.so-settings-head p{white-space:normal}.so-settings-head-actions{display:none}.so-settings-summary{grid-template-columns:1fr 1fr}.so-settings-tab{min-height:35px;padding-inline:11px}.so-quick-links{grid-template-columns:1fr}}
        @media(prefers-reduced-motion:reduce){.so-settings-tab{transition:none}.so-settings-tab:hover{transform:none}}

    </style>
</head>
<body>
<div class="foodex-admin-layout" data-foodex-mobile-web-page="smart-ordering">
    <aside class="sidebar">@include('admin._sidebar', ['navGroups' => app(\App\Support\AdminNavigation::class)->groupsFor(auth()->user()), 'navContext' => 'smart_ordering', 'user' => auth()->user()])</aside> {{-- localization-gate: allow Blade include expression --}}
    <main class="foodex-admin-main foodex-admin-page foodex-settings-surface">
@include('admin._global-header')
        @php($isAr = app()->getLocale() === 'ar')
        @php($restaurantManagementCompact = false)
        <div class="so-settings-page" data-so-settings-page>
            <header class="so-settings-head">
                <div class="so-settings-head-main">
                    <span class="so-settings-head-icon" aria-hidden="true">⚙</span>
                    <div>
                        <h1>{{ $isAr ? 'إعدادات المطعم' : 'Restaurant settings' }}</h1>
                        <p>{{ $isAr ? 'إدارة التشغيل والهوية والمحتوى والصلاحيات والنشر من مكان منظم.' : 'Manage operations, identity, content, permissions and publishing from one organized workspace.' }}</p>
                    </div>
                </div>
                <div class="so-settings-head-actions">
                    <a class="foodex-action-primary" href="{{ route('admin.smart-ordering.operations.index',['store_id'=>$site->store_id]) }}">{{ $isAr ? 'فتح التشغيل' : 'Open operations' }}</a>
                </div>
            </header>
            @include('admin._smart-ordering-workspace-nav')

            <div class="so-settings-summary" data-so-settings-summary aria-label="{{ $isAr ? 'ملخص إعدادات المطعم' : 'Restaurant settings summary' }}">
                <div class="so-settings-summary-item" data-tone="green">
                    <span>{{ $isAr ? 'المطعم' : 'Restaurant' }}</span>
                    <strong>{{ optional($site->store)->name ?: ('#'.$site->store_id) }}</strong>
                </div>
                <div class="so-settings-summary-item" data-tone="{{ ($site->master_enabled ?? false) ? 'green' : 'orange' }}">
                    <span>{{ $isAr ? 'حالة التشغيل' : 'Runtime' }}</span>
                    <strong>{{ __('smart_ordering.states.'.$site->runtime_state) }}</strong>
                </div>
                <div class="so-settings-summary-item" data-tone="blue">
                    <span>{{ $isAr ? 'جاهزية الإطلاق' : 'Launch readiness' }}</span>
                    <strong>{{ (int)($onboardingStatus['completion_percent'] ?? 0) }}%</strong>
                </div>
                <div class="so-settings-summary-item" data-tone="violet">
                    <span>{{ $isAr ? 'النشر' : 'Publishing' }}</span>
                    <strong>{{ $published ? ($isAr ? 'منشور' : 'Published') : ($isAr ? 'غير منشور' : 'Unpublished') }}</strong>
                </div>
            </div>

            <nav class="so-settings-tabs" role="tablist" aria-label="{{ $isAr ? 'أقسام الإعدادات' : 'Settings sections' }}" data-so-settings-tabs data-default-tab="overview">
                <button class="so-settings-tab" type="button" role="tab" aria-selected="true" data-so-settings-tab="overview">{{ $isAr ? 'الملخص' : 'Overview' }}</button>
                <button class="so-settings-tab" type="button" role="tab" aria-selected="false" data-so-settings-tab="launch">{{ $isAr ? 'التجهيز والإطلاق' : 'Setup & launch' }}</button>
                <button class="so-settings-tab" type="button" role="tab" aria-selected="false" data-so-settings-tab="site">{{ $isAr ? 'التشغيل والموقع' : 'Runtime & site' }}</button>
                <button class="so-settings-tab" type="button" role="tab" aria-selected="false" data-so-settings-tab="content">{{ $isAr ? 'المحتوى' : 'Content' }}</button>
                <button class="so-settings-tab" type="button" role="tab" aria-selected="false" data-so-settings-tab="modules">{{ $isAr ? 'الصلاحيات والنشر' : 'Modules & publish' }}</button>
            </nav>

            @if(session('status'))<p class="foodex-alert success" role="status">{{ session('status') }}</p>@endif
            @if($errors->any())<div class="foodex-alert error" role="alert">{{ $errors->first() }}</div>@endif

        <?php
            $ov = is_array($restaurantOverview ?? null) ? $restaurantOverview : [];
            $ovFilters = is_array($ov['filters'] ?? null) ? $ov['filters'] : [];
            $ovKpis = is_array($ov['kpis'] ?? null) ? $ov['kpis'] : [];
            $ovTrend = is_array($ov['trend'] ?? null) ? $ov['trend'] : [];
            $ovOrderStatus = is_array($ov['order_status'] ?? null) ? $ov['order_status'] : [];
            $ovBranches = $ov['branches'] ?? collect();

            $ovQuery = array_filter([
                'branch' => $ovFilters['branch'] ?? null,
                'from' => $ovFilters['from'] ?? null,
                'to' => $ovFilters['to'] ?? null,
                'channel' => $ovFilters['channel'] ?? null,
                'support_access' => ($supportAccess ?? false) ? 1 : null,
            ], static fn($value) => $value !== null && $value !== '');

            $operationsQuery = array_filter([
                'store_id' => (int) $site->store_id,
                'branch' => $ovFilters['branch'] ?? null,
                'from' => $ovFilters['from'] ?? null,
                'to' => $ovFilters['to'] ?? null,
                'fulfillment_mode' => $ovFilters['channel'] ?? null,
                'support_access' => ($supportAccess ?? false) ? 1 : null,
            ], static fn($value) => $value !== null && $value !== '');

            $analyticsQuery = array_filter([
                'store_id' => (int) $site->store_id,
                'branch_id' => $ovFilters['branch_id'] ?? null,
                'from' => $ovFilters['from'] ?? null,
                'to' => $ovFilters['to'] ?? null,
                'fulfillment' => $ovFilters['channel'] ?? null,
                'support_access' => ($supportAccess ?? false) ? 1 : null,
            ], static fn($value) => $value !== null && $value !== '');

            $overviewCurrency = (string) ($ov['currency'] ?? 'KWD');
            $money = static fn($value): string => $value === null
                ? '—'
                : $overviewCurrency.' '.\App\Support\DisplayNumber::money((float)$value, $overviewCurrency);
            $number = static fn($value): string => $value === null ? '—' : number_format((int)$value);

            $kpis = [
                ['key'=>'orders_today','value'=>$number($ovKpis['orders_today'] ?? null),'href'=>route('admin.smart-ordering.operations.index',$operationsQuery+['tab'=>'orders'])],
                ['key'=>'revenue','value'=>$money($ovKpis['revenue'] ?? null),'href'=>route('admin.smart-ordering.analytics.index',$analyticsQuery)],
                ['key'=>'reservations','value'=>$number($ovKpis['reservations'] ?? null),'href'=>route('admin.smart-ordering.operations.index',$operationsQuery+['tab'=>'reservations'])],
                ['key'=>'kitchen_backlog','value'=>$number($ovKpis['kitchen_backlog'] ?? null),'href'=>route('admin.smart-ordering.operations.index',$operationsQuery+['tab'=>'kitchen'])],
                ['key'=>'attendance_exceptions','value'=>$number($ovKpis['attendance_exceptions'] ?? null),'href'=>route('admin.smart-ordering.operations.index',$operationsQuery+['tab'=>'workforce'])],
            ];

            $maxTrendOrders = max(1, (int) collect($ovTrend)->max('orders'));
            $statusTotal = max(1, array_sum(array_map('intval', $ovOrderStatus)));
        ?>
        <section class="so-settings-panel" data-so-settings-panel="overview">

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
                        @foreach($ovBranches as $branchOption)
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
                    <label class="foodex-module-filter-group"><span>{{ __('smart_ordering.overview.filters.from') }}</span><input type="date" name="from" value="{{ $ovFilters['from'] ?? '' }}"></label>
                    <label class="foodex-module-filter-group"><span>{{ __('smart_ordering.overview.filters.to') }}</span><input type="date" name="to" value="{{ $ovFilters['to'] ?? '' }}"></label>
                    <button class="foodex-filter-action" type="submit">{{ __('smart_ordering.overview.filters.apply') }}</button>
                </div>
                <div class="foodex-module-toolbar-actions">
                    <a class="foodex-action-secondary button secondary" href="{{ route('admin.smart-ordering.index',['store_id'=>$site->store_id]+($supportAccess?['support_access'=>1]:[])) }}">{{ __('smart_ordering.overview.filters.reset') }}</a>
                </div>
            </form>

            <div class="foodex-module-kpis" data-restaurant-primary-kpis>
                @foreach($kpis as $metric)
                    <a class="foodex-module-kpi foodex-viz-card" data-foodex-module-kpi data-interactive="true" href="{{ $metric['href'] }}">
                        <span class="foodex-module-kpi-label">{{ __('smart_ordering.overview.kpis.'.$metric['key']) }}</span>
                        <strong class="foodex-module-kpi-value foodex-number">{{ $metric['value'] }}</strong>
                        <small class="foodex-module-updated">{{ __('module_dashboard.updated_at',['time'=>\Carbon\Carbon::parse(($ov['last_refresh'] ?? now()))->timezone('Asia/Kuwait')->format('Y-m-d H:i')]) }}</small>
                    </a>
                @endforeach
            </div>

            <details class="foodex-card" data-restaurant-analytics-drilldown style="margin-block:12px">
                <summary class="foodex-action-secondary button secondary">{{ __('smart_ordering.analytics.title') }}</summary>
            <div class="foodex-module-panels">
                <article class="foodex-viz-card foodex-module-chart" data-foodex-module-chart>
                    <div class="foodex-viz-header">
                        <div><h3 class="foodex-viz-title">{{ __('smart_ordering.overview.orders_trend') }}</h3><p class="foodex-viz-subtitle">{{ __('smart_ordering.overview.orders_trend_help') }}</p></div>
                        <a href="{{ route('admin.smart-ordering.analytics.index',$analyticsQuery) }}">{{ __('smart_ordering.analytics.title') }}</a>
                    </div>
                    @if(count($ovTrend))
                        <div class="foodex-viz-bars" role="img" aria-label="{{ __('smart_ordering.overview.orders_trend') }}">
                            @foreach($ovTrend as $point)
                                <span class="foodex-viz-bar" title="{{ $point['date'] }} · {{ $point['orders'] }}" style="height:{{ max(5,round(($point['orders']/$maxTrendOrders)*100)) }}%"></span>
                            @endforeach
                        </div>
                        <div class="foodex-module-chart-table-wrap">
                            <table class="foodex-module-chart-table" data-pagination-exempt="bounded-90-day-overview-trend">
                                <thead><tr><th>{{ __('smart_ordering.overview.filters.from') }}</th><th>{{ __('smart_ordering.overview.kpis.orders_period') }}</th><th>{{ __('smart_ordering.overview.kpis.revenue') }}</th></tr></thead>
                                <tbody>@foreach($ovTrend as $point)<tr><td class="foodex-number">{{ $point['date'] }}</td><td class="foodex-number">{{ number_format($point['orders']) }}</td><td class="foodex-number">{{ $money($point['revenue']) }}</td></tr>@endforeach</tbody>
                            </table>
                        </div>
                    @else
                        @include('admin._module-dashboard-state',['state'=>'empty','message'=>__('smart_ordering.overview.no_data')])
                    @endif
                </article>

                <article class="foodex-viz-card foodex-module-chart" data-foodex-module-chart>
                    <div class="foodex-viz-header"><div><h3 class="foodex-viz-title">{{ __('smart_ordering.overview.status_mix') }}</h3><p class="foodex-viz-subtitle">{{ __('smart_ordering.overview.status_mix_help') }}</p></div></div>
                    @if(count($ovOrderStatus))
                        <div class="foodex-viz-legend">
                            @foreach($ovOrderStatus as $status=>$count)
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
            </details>

            <div class="foodex-module-panels">
                <article class="foodex-viz-card">
                    <div class="foodex-viz-header"><h3 class="foodex-viz-title">{{ __('smart_ordering.overview.exceptions') }}</h3></div>
                    <?php if (($ov['exceptions'] ?? []) === []): ?>
                        <div class="foodex-module-state" data-foodex-module-state="current" role="status"><strong>{{ __('smart_ordering.overview.no_exceptions') }}</strong></div>
                    <?php else: ?>
                        <?php foreach (($ov['exceptions'] ?? []) as $exception): ?>
                            <?php
                                $exceptionHref = match($exception['key']) {
                                    'kitchen_late' => route('admin.smart-ordering.operations.index',$operationsQuery+['tab'=>'kitchen']),
                                    'attendance' => route('admin.smart-ordering.operations.index',$operationsQuery+['tab'=>'workforce']),
                                    'waitlist' => route('admin.smart-ordering.operations.index',$operationsQuery+['tab'=>'waitlist']),
                                    'reorder' => route('admin.smart-ordering.supply.index',[$site]+($supportAccess?['support_access'=>1]:[])),
                                    default => route('admin.smart-ordering.integrations.index',[$site]+($supportAccess?['support_access'=>1]:[])),
                                };
                            ?>
                            <a class="foodex-card" style="display:flex;justify-content:space-between;gap:12px;padding:12px;margin-block:8px;text-decoration:none" href="{{ $exceptionHref }}">
                                <span>{{ __('smart_ordering.overview.exceptions_labels.'.$exception['key']) }}</span>
                                <span class="foodex-status-badge" data-tone="warning">{{ number_format($exception['count']) }}</span>
                            </a>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </article>

                <article class="foodex-viz-card">
                    <div class="foodex-viz-header"><h3 class="foodex-viz-title">{{ __('smart_ordering.overview.branch_health') }}</h3></div>
                    <div class="so-list">
                        @foreach(($ov['branch_health'] ?? []) as $branchState)
                            <a class="foodex-card" style="padding:12px;text-decoration:none" href="{{ route('admin.smart-ordering.index',['store_id'=>$site->store_id,'branch'=>$branchState['public_id']]+($supportAccess?['support_access'=>1]:[])) }}">
                                <strong>{{ $branchState['label'] }}</strong>
                                <div><span class="foodex-status-badge" data-state="{{ $branchState['state']==='healthy'?'active':($branchState['state']==='disabled'?'inactive':'warning') }}">{{ __('smart_ordering.overview.health.'.$branchState['state']) }}</span></div>
                            </a>
                        @endforeach
                    </div>
                </article>
            </div>
        </section>
        <div class="so-quick-links" aria-label="{{ $isAr ? 'روابط سريعة' : 'Quick links' }}">
            <a class="so-quick-link" href="{{ route('admin.smart-ordering.menu.index',['store_id'=>$site->store_id] + ($supportAccess ? ['support_access'=>1] : [])) }}">
                <strong>{{ __('smart_ordering_menu.nav_title') }}</strong><span>→</span>
            </a>
            @if($entitlements['foodex_supply_intelligence'] ?? false)
                <a class="so-quick-link" href="{{ route('admin.smart-ordering.supply.index',[$site] + ($supportAccess ? ['support_access'=>1] : [])) }}">
                    <strong>{{ __('smart_ordering_supply.title') }}</strong><span>→</span>
                </a>
            @endif
            <a class="so-quick-link" href="{{ route('admin.smart-ordering.integrations.index',[$site] + ($supportAccess ? ['support_access'=>1] : [])) }}">
                <strong>{{ __('restaurant_integrations.title') }}</strong><span>→</span>
            </a>
            <a class="so-quick-link" href="{{ route('admin.smart-ordering.growth.index',[$site] + ($supportAccess ? ['support_access'=>1] : [])) }}">
                <strong>{{ __('restaurant_growth.title') }}</strong><span>→</span>
            </a>
        </div>
        </section>

        <section class="so-settings-panel" data-so-settings-panel="launch" hidden>
            @include('admin._restaurant-onboarding',['restaurantOnboardingCompact'=>true])
        </section>

        <section class="so-settings-panel" data-so-settings-panel="site" hidden>
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

        </section>

        <section class="so-settings-panel" data-so-settings-panel="content" hidden>
            @include('admin._smart-ordering-content-editor',['restaurantContentCompact'=>true])
        </section>

        <section class="so-settings-panel" data-so-settings-panel="modules" hidden>

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
            @if($published)
            <p>{{ __('smart_ordering.published_checksum') }} <span class="so-code">{{ $published->checksum }}</span></p>
            @else
            <p class="foodex-status-badge" data-state="inactive">{{ __('smart_ordering_menu.public_menu.unpublished') }}</p>
            @endif
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
        </section>

        <script>
        (() => {
            const root = document.querySelector('[data-so-settings-page]');
            const tabs = root?.querySelector('[data-so-settings-tabs]');
            if (!root || !tabs) return;

            const buttons = [...tabs.querySelectorAll('[data-so-settings-tab]')];
            const panels = [...root.querySelectorAll('[data-so-settings-panel]')];
            const available = new Set(buttons.map((button) => button.dataset.soSettingsTab));
            const storageKey = 'foodex:restaurant-settings-tab:{{ (int)$site->store_id }}';
            const hashValue = window.location.hash.startsWith('#settings-')
                ? window.location.hash.slice('#settings-'.length)
                : null;
            let storedValue = null;
            try { storedValue = sessionStorage.getItem(storageKey); } catch (_) {}
            const defaultTab = available.has(hashValue)
                ? hashValue
                : (available.has(storedValue)
                    ? storedValue
                    : (available.has(tabs.dataset.defaultTab) ? tabs.dataset.defaultTab : buttons[0]?.dataset.soSettingsTab));

            const activate = (name, updateHistory = true) => {
                if (!available.has(name)) name = defaultTab;
                buttons.forEach((button) => {
                    const active = button.dataset.soSettingsTab === name;
                    button.setAttribute('aria-selected', active ? 'true' : 'false');
                    button.tabIndex = active ? 0 : -1;
                });
                panels.forEach((panel) => {
                    panel.hidden = panel.dataset.soSettingsPanel !== name;
                });
                try { sessionStorage.setItem(storageKey, name); } catch (_) {}
                if (updateHistory) {
                    history.replaceState(null, '', '#settings-' + name);
                }
            };

            buttons.forEach((button) => button.addEventListener('click', () => activate(button.dataset.soSettingsTab)));
            activate(defaultTab, false);
        })();
        </script>
        </div>
    </main>
</div>
</body>
</html>
