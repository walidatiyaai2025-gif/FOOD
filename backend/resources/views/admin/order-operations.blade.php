<!doctype html>
<html lang="{{ app()->getLocale() }}" dir="{{ $isAr?'rtl':'ltr' }}">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>{{ __('order_operations.title') }} · FOODEX</title>
@include('admin._brand-components')
<style>
body{margin:0}.shell{display:grid;grid-template-columns:minmax(0,1fr) 240px;min-height:100vh}.main{padding:28px}.sidebar{padding:18px;border-inline-start:1px solid var(--foodex-border)}
.order-kpi-card{position:relative;display:grid;grid-template-columns:repeat(10,minmax(132px,1fr));min-width:0;margin:6px 0;overflow-x:auto;overflow-y:hidden;border:1px solid var(--foodex-border);border-radius:18px;background:var(--foodex-surface);box-shadow:0 8px 26px rgba(15,23,42,.055);scrollbar-width:thin}
.order-kpi-card::before{content:"";position:absolute;inset-inline:0;top:0;height:4px;background:linear-gradient(90deg,#158A3A 0%,#2563EB 18%,#7C3AED 34%,#EA580C 50%,#DC2626 66%,#0891B2 82%,#BE123C 100%);background-size:180% 100%;animation:orderKpiEdge 7.5s linear infinite}
.order-kpi-item{--order-kpi-accent:#158A3A;--order-kpi-soft:#EAF7EF;position:relative;min-width:132px;min-height:112px;display:grid;grid-template-rows:auto 1fr;gap:7px;padding:16px 12px 12px;text-decoration:none;color:inherit;border-inline-end:1px solid var(--foodex-border);transition:background .16s ease,transform .16s ease}
.order-kpi-item:last-child{border-inline-end:0}.order-kpi-item:hover{background:color-mix(in srgb,var(--order-kpi-soft) 58%,#fff);transform:translateY(-1px)}
.order-kpi-head{display:flex;align-items:center;gap:8px;min-width:0}.order-kpi-icon{width:32px;height:32px;flex:0 0 32px;display:grid;place-items:center;border-radius:10px;background:var(--order-kpi-soft);color:var(--order-kpi-accent)}.order-kpi-icon .foodex-svg-icon{width:17px;height:17px}
.order-kpi-label{min-width:0;color:var(--foodex-muted);font-size:.63rem;font-weight:800;line-height:1.35}.order-kpi-value{align-self:center;display:block;width:100%;max-width:100%;font-size:1.22rem;line-height:1.12;color:var(--foodex-ink);font-variant-numeric:tabular-nums;white-space:nowrap;overflow:visible;text-overflow:clip}
.order-kpi-state{position:absolute;inset-inline-end:10px;bottom:10px;width:7px;height:7px;border-radius:999px;background:var(--foodex-border)}.order-kpi-state[data-state="success"]{background:#16A34A}.order-kpi-state[data-state="warning"]{background:#EA580C}.order-kpi-state[data-state="danger"],.order-kpi-state[data-state="error"]{background:#DC2626}
.order-kpi-item[data-tone="green"]{--order-kpi-accent:#158A3A;--order-kpi-soft:#EAF7EF}.order-kpi-item[data-tone="blue"]{--order-kpi-accent:#2563EB;--order-kpi-soft:#EFF6FF}.order-kpi-item[data-tone="violet"]{--order-kpi-accent:#7C3AED;--order-kpi-soft:#F5F3FF}.order-kpi-item[data-tone="orange"]{--order-kpi-accent:#EA580C;--order-kpi-soft:#FFF7ED}.order-kpi-item[data-tone="red"]{--order-kpi-accent:#DC2626;--order-kpi-soft:#FEF2F2}.order-kpi-item[data-tone="amber"]{--order-kpi-accent:#B45309;--order-kpi-soft:#FFFBEB}.order-kpi-item[data-tone="cyan"]{--order-kpi-accent:#0E7490;--order-kpi-soft:#ECFEFF}.order-kpi-item[data-tone="rose"]{--order-kpi-accent:#BE123C;--order-kpi-soft:#FFF1F2}
@keyframes orderKpiEdge{from{background-position:0 0}to{background-position:180% 0}}
.filters{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:6px;padding:8px;margin-bottom:6px}.filters label{display:grid;gap:5px;font-weight:700;font-size:.8rem}
.status-tabs{display:flex;gap:6px;overflow:auto;padding:2px 0 6px;margin-bottom:0}.status-tab{display:inline-flex;align-items:center;gap:7px;white-space:nowrap;padding:9px 12px;border:1px solid var(--foodex-border);border-radius:999px;text-decoration:none;color:inherit;background:var(--foodex-surface,#fff);font-weight:800}.status-tab[aria-current="page"]{outline:2px solid currentColor}.status-tab-count{display:inline-flex;min-width:24px;height:24px;align-items:center;justify-content:center;border-radius:999px;background:rgba(0,0,0,.06);font-size:.78rem}
.order-workspace-bar{display:flex;align-items:center;justify-content:space-between;gap:6px;min-width:0;margin:0 0 6px}
.order-workspace-tabs{display:flex;align-items:center;justify-content:flex-start;gap:6px;min-width:0;margin:0;overflow-x:auto;overflow-y:hidden;padding:2px;scrollbar-width:thin;flex:0 1 auto}
.order-workspace-filter-slot{display:flex;align-items:center;justify-content:flex-end;min-width:0;margin-inline-start:auto;flex:0 1 auto}
.order-workspace-tab{display:inline-flex;align-items:center;justify-content:center;gap:8px;min-height:42px;padding:0 18px;border:1px solid var(--foodex-border);border-radius:999px;background:var(--foodex-surface);color:var(--foodex-muted);font-size:.78rem;font-weight:900;white-space:nowrap;cursor:pointer;box-shadow:0 4px 14px rgba(15,23,42,.035);transition:background .16s ease,color .16s ease,border-color .16s ease,transform .16s ease}
.order-workspace-tab:hover{transform:translateY(-1px);border-color:#b8d9c2;color:var(--foodex-green-dark)}
.order-workspace-tab[aria-selected="true"]{border-color:var(--foodex-action-green);background:var(--foodex-action-green);color:#fff;box-shadow:0 8px 22px rgba(21,138,58,.16)}
.order-workspace-tab-count{display:inline-flex;align-items:center;justify-content:center;min-width:24px;height:24px;padding:0 7px;border-radius:999px;background:#DBEAFE;color:#1D4ED8;font-size:.68rem;font-weight:900;font-variant-numeric:tabular-nums}
.order-workspace-tab[aria-selected="true"] .order-workspace-tab-count{background:rgba(255,255,255,.2);color:#fff}
.order-tab-panel{min-width:0;width:100%}.order-tab-panel[hidden]{display:none!important}
.foodex-module-overview{display:grid;gap:6px}
.order-tab-panel[data-order-panel="analytics"]{display:grid;gap:6px}
.order-analytics-tabs{display:flex;align-items:center;gap:5px;overflow-x:auto;padding:0 0 2px;margin:0;scrollbar-width:thin}
.order-analytics-subtab{min-height:34px;padding:0 13px;border:1px solid var(--foodex-border);border-radius:999px;background:var(--foodex-surface);color:var(--foodex-muted);font-size:.7rem;font-weight:900;white-space:nowrap;cursor:pointer;transition:background .22s ease,color .22s ease,border-color .22s ease,transform .22s ease}
.order-analytics-subtab:hover{transform:translateY(-1px);border-color:#bfd5c6;color:var(--foodex-green-dark)}
.order-analytics-subtab[aria-selected="true"]{border-color:#146c36;background:#eaf7ef;color:#146c36;box-shadow:inset 0 0 0 1px rgba(20,108,54,.08)}
[data-analytics-group][hidden]{display:none!important}
.foodex-module-panels,.sales-ops-chart-grid{gap:6px!important;margin:0!important}
.order-tab-panel[data-order-panel="analytics"] .foodex-viz-card{padding:10px!important}
.order-tab-panel[data-order-panel="analytics"] .foodex-viz-header{margin-bottom:5px!important}
.order-tab-panel[data-order-panel="analytics"] .foodex-module-chart-summary{margin:5px 0!important}
.table-wrap{overflow:visible}.ops-table{width:100%;min-width:0;table-layout:fixed;font-size:.78rem}.ops-table th,.ops-table td{padding:8px 6px;vertical-align:top;white-space:normal;overflow-wrap:anywhere;line-height:1.35}.ops-table th:nth-child(1){width:9%}.ops-table th:nth-child(2){width:8%}.ops-table th:nth-child(3){width:11%}.ops-table th:nth-child(4){width:10%}.ops-table th:nth-child(5){width:12%}.ops-table th:nth-child(6){width:15%}.ops-table th:nth-child(7){width:10%}.ops-table th:nth-child(8){width:9%}.ops-table th:nth-child(9){width:11%}.ops-table th:nth-child(10){width:5%}.row-actions{position:relative;display:inline-block}.row-actions summary{list-style:none;width:34px;height:34px;border:1px solid var(--foodex-green,#179c52);border-radius:50%;display:grid;place-items:center;background:var(--foodex-green,#179c52);color:#fff;cursor:pointer;font-size:20px;line-height:1}.row-actions summary::-webkit-details-marker{display:none}.row-actions[open] summary{box-shadow:0 0 0 3px rgba(23,156,82,.14)}.row-action-menu{position:absolute;z-index:40;inset-inline-end:0;top:40px;width:310px;background:#fff;border:1px solid var(--foodex-border);border-radius:12px;box-shadow:0 12px 32px rgba(15,23,42,.14);padding:8px}.row-action-menu>a{display:block;text-decoration:none;color:var(--foodex-ink);font-weight:800;padding:9px 10px;border-radius:8px}.row-action-menu>a:hover{background:#f6f8fa}.row-action-menu form{display:grid;gap:8px;margin:8px 0 0;padding-top:8px;border-top:1px solid var(--foodex-border)}.row-action-menu select,.row-action-menu input{min-width:0;width:100%;box-sizing:border-box}.row-action-menu button{width:100%}.dispatch-cell{display:grid;gap:4px;min-width:0}.dispatch-cell strong,.dispatch-cell span{overflow-wrap:anywhere}.dispatch-warning{padding:7px 9px;border-radius:9px;background:#fff7e6;border:1px solid #f3c36b;font-weight:800}.dispatch-meta{color:var(--foodex-muted);font-size:.78rem}.timeline{display:grid;gap:8px}.timeline-item{padding:10px;border:1px solid var(--foodex-border);border-radius:10px}.sales-ops-chart-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:6px}
.sales-ops-bars{height:180px;align-items:flex-end}
.sales-ops-bar-link{--order-chart-color:#158A3A;--order-chart-color-2:#38BDF8;min-height:8px;text-decoration:none;background:linear-gradient(180deg,var(--order-chart-color-2) 0%,var(--order-chart-color) 72%,color-mix(in srgb,var(--order-chart-color) 80%,#0f172a) 100%)!important;border-radius:8px 8px 3px 3px;box-shadow:0 5px 14px color-mix(in srgb,var(--order-chart-color) 20%,transparent);transform-origin:bottom center;transform:scaleY(0);opacity:.72}
.sales-ops-bar-link:nth-child(8n+1){--order-chart-color:#158A3A;--order-chart-color-2:#22C55E}.sales-ops-bar-link:nth-child(8n+2){--order-chart-color:#2563EB;--order-chart-color-2:#38BDF8}.sales-ops-bar-link:nth-child(8n+3){--order-chart-color:#7C3AED;--order-chart-color-2:#C084FC}.sales-ops-bar-link:nth-child(8n+4){--order-chart-color:#EA580C;--order-chart-color-2:#FACC15}.sales-ops-bar-link:nth-child(8n+5){--order-chart-color:#DC2626;--order-chart-color-2:#FB7185}.sales-ops-bar-link:nth-child(8n+6){--order-chart-color:#0891B2;--order-chart-color-2:#2DD4BF}.sales-ops-bar-link:nth-child(8n+7){--order-chart-color:#BE123C;--order-chart-color-2:#F472B6}.sales-ops-bar-link:nth-child(8n+8){--order-chart-color:#CA8A04;--order-chart-color-2:#FDBA74}
.order-tab-panel.charts-animate .sales-ops-bar-link{animation:orderChartGrow 1.65s cubic-bezier(.16,.84,.22,1) forwards}
.order-tab-panel.charts-animate .sales-ops-bar-link:nth-child(2){animation-delay:.08s}.order-tab-panel.charts-animate .sales-ops-bar-link:nth-child(3){animation-delay:.16s}.order-tab-panel.charts-animate .sales-ops-bar-link:nth-child(4){animation-delay:.24s}.order-tab-panel.charts-animate .sales-ops-bar-link:nth-child(5){animation-delay:.32s}.order-tab-panel.charts-animate .sales-ops-bar-link:nth-child(6){animation-delay:.40s}.order-tab-panel.charts-animate .sales-ops-bar-link:nth-child(7){animation-delay:.48s}.order-tab-panel.charts-animate .sales-ops-bar-link:nth-child(8){animation-delay:.56s}
.sales-ops-exceptions,.sales-ops-simple-list,.sales-ops-ranked-bars{display:grid;gap:5px}
.sales-ops-exceptions a,.sales-ops-simple-list a,.sales-ops-simple-list>div,.sales-ops-ranked-bars a{display:grid;grid-template-columns:minmax(0,1fr) auto;gap:8px;align-items:center;padding:7px 9px;border:1px solid var(--foodex-border);border-radius:9px;color:inherit;text-decoration:none}
.sales-ops-ranked-bars a{grid-template-columns:minmax(90px,.8fr) minmax(100px,2fr) auto;--order-rank-color:#158A3A;--order-rank-color-2:#38BDF8}
.sales-ops-ranked-bars a:nth-child(8n+1){--order-rank-color:#158A3A;--order-rank-color-2:#22C55E}.sales-ops-ranked-bars a:nth-child(8n+2){--order-rank-color:#2563EB;--order-rank-color-2:#38BDF8}.sales-ops-ranked-bars a:nth-child(8n+3){--order-rank-color:#7C3AED;--order-rank-color-2:#C084FC}.sales-ops-ranked-bars a:nth-child(8n+4){--order-rank-color:#EA580C;--order-rank-color-2:#FACC15}.sales-ops-ranked-bars a:nth-child(8n+5){--order-rank-color:#DC2626;--order-rank-color-2:#FB7185}.sales-ops-ranked-bars a:nth-child(8n+6){--order-rank-color:#0891B2;--order-rank-color-2:#2DD4BF}.sales-ops-ranked-bars a:nth-child(8n+7){--order-rank-color:#BE123C;--order-rank-color-2:#F472B6}.sales-ops-ranked-bars a:nth-child(8n+8){--order-rank-color:#CA8A04;--order-rank-color-2:#FDBA74}
.sales-ops-ranked-bars i{display:block;height:9px;border-radius:999px;background:linear-gradient(90deg,#f8fafc,#e2e8f0);overflow:hidden}
.sales-ops-ranked-bars b{display:block;height:100%;background:linear-gradient(90deg,var(--order-rank-color),var(--order-rank-color-2));border-radius:inherit;transform:scaleX(0)}
html[dir="rtl"] .sales-ops-ranked-bars b{transform-origin:right center}html[dir="ltr"] .sales-ops-ranked-bars b{transform-origin:left center}
.order-tab-panel.charts-animate .sales-ops-ranked-bars b{animation:orderRankGrow 1.85s cubic-bezier(.16,.84,.22,1) forwards}
.order-tab-panel.charts-animate [data-foodex-module-chart]{animation:orderChartCardIn .85s ease-out both}
.order-tab-panel.charts-animate [data-foodex-module-chart]:nth-child(2){animation-delay:.12s}.order-tab-panel.charts-animate [data-foodex-module-chart]:nth-child(3){animation-delay:.24s}
@keyframes orderChartGrow{0%{transform:scaleY(0);opacity:.2}55%{opacity:.86}100%{transform:scaleY(1);opacity:1}}
@keyframes orderRankGrow{0%{transform:scaleX(0);filter:saturate(.75)}100%{transform:scaleX(1);filter:saturate(1)}}
@keyframes orderChartCardIn{0%{opacity:.35;transform:translateY(5px)}100%{opacity:1;transform:translateY(0)}}
@media(max-width:1500px) and (min-width:1001px){.shell{grid-template-columns:minmax(0,1fr) 210px}.main{padding:16px 14px}.sidebar{padding:14px}.filters{grid-template-columns:repeat(3,minmax(0,1fr));padding:12px;gap:8px}.ops-table{font-size:.72rem}.ops-table th,.ops-table td{padding:7px 4px}.dispatch-warning{padding:5px 6px}.dispatch-meta{font-size:.7rem}.row-actions summary{width:30px;height:30px;font-size:18px}.row-action-menu{width:280px}}
@media(max-width:1180px){.order-workspace-bar{align-items:center}.order-workspace-tabs{flex:1 1 auto}.order-workspace-filter-slot{flex:0 0 auto}}
@media(max-width:1000px){.shell{grid-template-columns:1fr}.sidebar{grid-row:1}.main{grid-row:2;padding:16px}.filters{grid-template-columns:1fr 1fr}.table-wrap{overflow-x:auto}.ops-table{min-width:980px;table-layout:auto}.sales-ops-chart-grid{grid-template-columns:1fr}}
@media(max-width:700px){.order-workspace-bar{gap:5px}.order-workspace-tab{min-height:36px;padding-inline:11px}.order-workspace-tabs,.order-analytics-tabs{gap:4px}.order-analytics-subtab{min-height:32px;padding-inline:10px}}
@media(max-width:600px){.filters{grid-template-columns:1fr}.sales-ops-ranked-bars a{grid-template-columns:1fr auto}.sales-ops-ranked-bars i{grid-column:1/-1}}
@media(prefers-reduced-motion:reduce){.order-kpi-card::before,.order-tab-panel.charts-animate .sales-ops-bar-link,.order-tab-panel.charts-animate .sales-ops-ranked-bars b,.order-tab-panel.charts-animate [data-foodex-module-chart]{animation:none}.order-kpi-item,.order-workspace-tab,.order-analytics-subtab{transition:none}.order-kpi-item:hover,.order-workspace-tab:hover,.order-analytics-subtab:hover{transform:none}.sales-ops-bar-link{transform:none;opacity:1}.sales-ops-ranked-bars b{transform:none}}
</style>
</head>
<body>
@php
$businessLabel = static function ($value): string {
    $key = strtolower(trim((string) ($value ?? '')));
    if ($key === '') {
        return '—';
    }

    $translated = __('order_operations.business_labels.'.$key);

    return $translated !== 'order_operations.business_labels.'.$key
        ? $translated
        : ucwords(str_replace(['_', '-'], ' ', $key));
};
@endphp
<div class="shell foodex-admin-layout">
<main class="main foodex-admin-main foodex-admin-page">
@include('admin._global-header')
@if(session('status'))<div class="foodex-state" role="status">{{ session('status') }}</div>@endif
@if($errors->any())<div class="foodex-state" role="alert"><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif

@include('admin._order-new-wizard')

<?php
$dashboardKpis = $dashboardKpis ?? [];
$dashboardBaseQuery = $dashboardBaseQuery ?? [];
$dashboardMoney = $dashboardMoney ?? static fn ($value): string => '—';
$dailyMax = isset($dailyMax) ? max(1, (int) $dailyMax) : 1;
$statusMax = isset($statusMax) ? max(1, (int) $statusMax) : 1;
$dashboardVisible = (bool) ($dashboardVisible ?? false);
?>
<?php if ($dashboardVisible && is_array($dashboard ?? null)): ?>
<section class="foodex-module-overview" data-sales-operations-dashboard data-state="current" aria-label="{{ __('order_operations.dashboard.title') }}">
    <div class="order-kpi-card" data-order-kpi-card aria-label="{{ __('order_operations.dashboard.title') }}">
        <?php foreach ($dashboardKpis as $metric): ?>
            <a class="order-kpi-item"
               data-order-kpi="{{ $metric['key'] }}"
               data-tone="{{ $metric['tone'] }}"
               href="{{ $metric['drilldown_url'] }}">
                <span class="order-kpi-head">
                    <span class="order-kpi-icon" aria-hidden="true">@include('admin._premium-icon',['name'=>$metric['icon']])</span>
                    <span class="order-kpi-label">{{ $metric['label'] }}</span>
                </span>
                <strong class="order-kpi-value foodex-number"
                        data-order-counter
                        data-counter-target="{{ $metric['value'] }}"
                        <?php if (is_numeric($metric['raw'] ?? null)): ?> data-counter-raw="{{ $metric['raw'] }}" data-counter-decimals="{{ $metric['decimals'] ?? 0 }}" <?php endif; ?>>{{ $metric['value'] }}</strong>
                <?php if (is_array($metric['status'] ?? null)): ?>
                    <span class="order-kpi-state"
                          data-state="{{ data_get($metric,'status.state','current') }}"
                          title="{{ data_get($metric,'status.label','') }}"
                          aria-label="{{ data_get($metric,'status.label','') }}"></span>
                <?php endif; ?>
            </a>
        <?php endforeach; ?>
    </div>

    <div class="order-workspace-bar" data-order-workspace-bar>
        <nav class="order-workspace-tabs"
             role="tablist"
             aria-label="{{ $isAr ? 'أقسام إدارة الطلبات' : 'Order management sections' }}"
             data-order-tabs
             data-default-tab="{{ request()->has('page') ? 'orders' : 'analytics' }}">
            <button class="order-workspace-tab"
                    type="button"
                    role="tab"
                    aria-selected="{{ request()->has('page') ? 'false' : 'true' }}"
                    data-order-tab="analytics">{{ $isAr ? 'التحليلات' : 'Analytics' }}</button>
            <button class="order-workspace-tab"
                    type="button"
                    role="tab"
                    aria-selected="{{ request()->has('page') ? 'true' : 'false' }}"
                    data-order-tab="orders">{{ $isAr ? 'الطلبات' : 'Orders' }} <span class="order-workspace-tab-count">{{ number_format((int)$statusTotal) }}</span></button>
        </nav>
        <div class="order-workspace-filter-slot" data-order-workspace-filter>
            @include('admin._order-operations-header-filters')
        </div>
    </div>

    <div class="order-tab-panel" role="tabpanel" data-order-panel="analytics" <?php if (request()->has('page')): ?>hidden<?php endif; ?>>
    <nav class="order-analytics-tabs"
         role="tablist"
         aria-label="{{ $isAr ? 'أقسام التحليلات' : 'Analytics sections' }}"
         data-order-analytics-tabs
         data-default-analytics-tab="performance">
        <button class="order-analytics-subtab" type="button" role="tab" aria-selected="true" data-analytics-tab="performance">{{ $isAr ? 'الأداء' : 'Performance' }}</button>
        <button class="order-analytics-subtab" type="button" role="tab" aria-selected="false" data-analytics-tab="operations">{{ $isAr ? 'التشغيل' : 'Operations' }}</button>
        <button class="order-analytics-subtab" type="button" role="tab" aria-selected="false" data-analytics-tab="channels">{{ $isAr ? 'القنوات والدفع' : 'Channels & Payment' }}</button>
        <button class="order-analytics-subtab" type="button" role="tab" aria-selected="false" data-analytics-tab="commerce">{{ $isAr ? 'العملاء والمنتجات' : 'Customers & Products' }}</button>
    </nav>
    <div class="foodex-module-panels">
        <article class="foodex-viz-card foodex-module-chart" data-foodex-module-chart data-analytics-group="performance" aria-labelledby="sales-trend-title">
            <div class="foodex-viz-header">
                <div><h3 class="foodex-viz-title" id="sales-trend-title">{{ __('order_operations.dashboard.charts.trend') }}</h3><p class="foodex-viz-subtitle">{{ __('order_operations.dashboard.charts.trend_help') }}</p></div>
                <a class="foodex-action-secondary button secondary" href="{{ route('admin.reports.index', array_merge($dashboardBaseQuery, ['report' => 'orders'])) }}">{{ __('order_operations.dashboard.open_report') }}</a>
            </div>
            <?php if (($dashboard['daily'] ?? []) === []): ?>
                <div class="foodex-viz-empty">{{ __('order_operations.dashboard.empty_chart') }}</div>
            <?php else: ?>
                <div class="foodex-viz-bars sales-ops-bars" role="img" aria-label="{{ __('order_operations.dashboard.charts.trend') }}">
                    <?php foreach (($dashboard['daily'] ?? []) as $point): ?>
                        <a class="foodex-viz-bar sales-ops-bar-link" style="height:{{ max(8, round(((int)$point['orders'] / $dailyMax) * 100)) }}%" href="{{ route('admin.operations.orders.index', array_merge($dashboardBaseQuery, ['from'=>$point['day'],'to'=>$point['day']])) }}" aria-label="{{ $point['day'] }} · {{ $point['orders'] }} {{ __('order_operations.dashboard.columns.orders') }}"></a>
                    <?php endforeach; ?>
                </div>
                <p class="foodex-module-chart-summary">{{ __('order_operations.dashboard.period') }}: {{ $dashboard['period']['from'] }} → {{ $dashboard['period']['to'] }}</p>
                <details><summary>{{ __('order_operations.dashboard.details') }}</summary><div class="foodex-module-chart-table-wrap"><table class="foodex-module-chart-table" data-pagination-exempt="bounded-sales-daily-summary"><thead><tr><th>{{ __('order_operations.dashboard.columns.day') }}</th><th>{{ __('order_operations.dashboard.columns.orders') }}</th><th>{{ __('order_operations.dashboard.columns.revenue') }}</th></tr></thead><tbody><?php foreach (($dashboard['daily'] ?? []) as $point): ?><tr><td>{{ $point['day'] }}</td><td>{{ number_format((int)$point['orders']) }}</td><td>{{ $point['revenue'] === null ? __('order_operations.dashboard.multiple_currencies') : $dashboardMoney($point['revenue']) }}</td></tr><?php endforeach; ?></tbody></table></div></details>
            <?php endif; ?>
        </article>

        <article class="foodex-viz-card foodex-module-chart" data-foodex-module-chart data-analytics-group="performance" aria-labelledby="sales-exception-title">
            <div class="foodex-viz-header"><div><h3 class="foodex-viz-title" id="sales-exception-title">{{ __('order_operations.dashboard.charts.exceptions') }}</h3><p class="foodex-viz-subtitle">{{ __('order_operations.dashboard.charts.exceptions_help') }}</p></div></div>
            <div class="sales-ops-exceptions">
                <?php foreach (($dashboard['exceptions'] ?? []) as $exception): ?>
                    <a data-sales-exception="{{ $exception['key'] }}" href="{{ route('admin.operations.orders.index', array_merge($dashboardBaseQuery, ['exception'=>$exception['key']])) }}"><span>{{ __('order_operations.dashboard.exceptions.'.$exception['key']) }}</span><strong>{{ number_format((int)$exception['count']) }}</strong></a>
                <?php endforeach; ?>
            </div>
        </article>
    </div>

    <div class="sales-ops-chart-grid">
        <article class="foodex-viz-card foodex-module-chart" data-foodex-module-chart data-analytics-group="operations" aria-labelledby="sales-status-title">
            <div class="foodex-viz-header"><div><h3 class="foodex-viz-title" id="sales-status-title">{{ __('order_operations.dashboard.charts.status') }}</h3><p class="foodex-viz-subtitle">{{ __('order_operations.dashboard.charts.status_help') }}</p></div></div>
            <?php if (($dashboard['status_breakdown'] ?? []) === []): ?>
                <div class="foodex-viz-empty">{{ __('order_operations.dashboard.empty_chart') }}</div>
            <?php else: ?>
                <div class="sales-ops-ranked-bars">
                    <?php foreach (($dashboard['status_breakdown'] ?? []) as $point): ?>
                        <a href="{{ route('admin.operations.orders.index', array_merge($dashboardBaseQuery, ['status' => (string)$point['status']])) }}"><span>{{ $businessLabel((string)$point['status']) }}</span><i><b style="width:{{ round(((int)$point['orders'] / $statusMax) * 100) }}%"></b></i><strong>{{ number_format((int)$point['orders']) }}</strong></a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </article>

        <article class="foodex-viz-card foodex-module-chart" data-foodex-module-chart data-analytics-group="channels" aria-labelledby="sales-channel-title">
            <div class="foodex-viz-header"><div><h3 class="foodex-viz-title" id="sales-channel-title">{{ __('order_operations.dashboard.charts.channels') }}</h3><p class="foodex-viz-subtitle">{{ __('order_operations.dashboard.charts.channels_help') }}</p></div></div>
            <div class="sales-ops-simple-list">
                <?php if (($dashboard['channel_breakdown'] ?? []) === []): ?>
                    <div class="foodex-viz-empty">{{ __('order_operations.dashboard.empty_chart') }}</div>
                <?php else: ?>
                    <?php foreach (($dashboard['channel_breakdown'] ?? []) as $point): ?>
                        <a href="{{ route('admin.operations.orders.index', array_merge($dashboardBaseQuery, ['channel' => (string)$point['channel']])) }}"><span>{{ $businessLabel((string)$point['channel']) }}</span><strong>{{ number_format((int)$point['orders']) }}</strong></a>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </article>

        <article class="foodex-viz-card foodex-module-chart" data-foodex-module-chart data-analytics-group="channels" aria-labelledby="sales-payment-title">
            <div class="foodex-viz-header"><div><h3 class="foodex-viz-title" id="sales-payment-title">{{ __('order_operations.dashboard.charts.payments') }}</h3><p class="foodex-viz-subtitle">{{ __('order_operations.dashboard.charts.payments_help') }}</p></div></div>
            <div class="sales-ops-simple-list"><?php if (($dashboard['payment_breakdown'] ?? []) === []): ?><div class="foodex-viz-empty">{{ __('order_operations.dashboard.empty_chart') }}</div><?php else: ?><?php foreach (($dashboard['payment_breakdown'] ?? []) as $point): ?><div><span>{{ $point['provider'] }} · {{ $businessLabel($point['status']) }}</span><strong>{{ number_format((int)$point['payments']) }}</strong></div><?php endforeach; ?><?php endif; ?></div>
        </article>

        <article class="foodex-viz-card foodex-module-chart" data-foodex-module-chart data-analytics-group="commerce" aria-labelledby="sales-products-title">
            <div class="foodex-viz-header"><div><h3 class="foodex-viz-title" id="sales-products-title">{{ __('order_operations.dashboard.charts.products') }}</h3><p class="foodex-viz-subtitle">{{ __('order_operations.dashboard.charts.products_help') }}</p></div></div>
            <div class="sales-ops-simple-list"><?php if (($dashboard['top_products'] ?? []) === []): ?><div class="foodex-viz-empty">{{ __('order_operations.dashboard.empty_chart') }}</div><?php else: ?><?php foreach (($dashboard['top_products'] ?? []) as $point): ?><a href="{{ route('admin.reports.index', array_merge($dashboardBaseQuery, ['report'=>'products','product_id'=>$point['product_id']])) }}"><span>{{ $point['sku'] }} · {{ $point['name'] }}</span><strong>{{ \App\Support\DisplayNumber::quantity((float)$point['quantity']) }}</strong></a><?php endforeach; ?><?php endif; ?></div>
        </article>

        <article class="foodex-viz-card foodex-module-chart" data-foodex-module-chart data-analytics-group="commerce" aria-labelledby="sales-stores-title">
            <div class="foodex-viz-header"><div><h3 class="foodex-viz-title" id="sales-stores-title">{{ __('order_operations.dashboard.charts.stores') }}</h3><p class="foodex-viz-subtitle">{{ __('order_operations.dashboard.charts.stores_help') }}</p></div></div>
            <div class="sales-ops-simple-list"><?php if (($dashboard['store_breakdown'] ?? []) === []): ?><div class="foodex-viz-empty">{{ __('order_operations.dashboard.empty_chart') }}</div><?php else: ?><?php foreach (($dashboard['store_breakdown'] ?? []) as $point): ?><a href="{{ route('admin.operations.orders.index', array_merge($dashboardBaseQuery, ['store_id'=>$point['store_id']])) }}"><span>{{ $point['store'] }}</span><strong>{{ number_format((int)$point['orders']) }}</strong></a><?php endforeach; ?><?php endif; ?></div>
        </article>

        <article class="foodex-viz-card foodex-module-chart" data-foodex-module-chart data-analytics-group="commerce" aria-labelledby="sales-customers-title">
            <div class="foodex-viz-header"><div><h3 class="foodex-viz-title" id="sales-customers-title">{{ __('order_operations.dashboard.charts.customers') }}</h3><p class="foodex-viz-subtitle">{{ __('order_operations.dashboard.charts.customers_help') }}</p></div></div>
            <div class="sales-ops-simple-list">
                <?php if (($dashboard['top_customers'] ?? []) === []): ?>
                    <div class="foodex-viz-empty">{{ __('order_operations.dashboard.empty_chart') }}</div>
                <?php else: ?>
                    <?php foreach (($dashboard['top_customers'] ?? []) as $point): ?>
                        <?php
                            $customerChannelCode = (string) $point['channel'];
                            $customerReportParams = array_merge(
                                $dashboardBaseQuery,
                                ['report' => 'customers', 'channel' => $customerChannelCode],
                                $point['customer_id'] ? ['customer_id' => $point['customer_id']] : [],
                            );
                        ?>
                        <a href="{{ route('admin.reports.index', $customerReportParams) }}"><span>{{ $point['customer'] }} · {{ $businessLabel($customerChannelCode) }}</span><strong>{{ number_format((int)$point['orders']) }}</strong></a>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </article>

        <article class="foodex-viz-card foodex-module-chart" data-foodex-module-chart data-analytics-group="operations" aria-labelledby="sales-cancel-title">
            <div class="foodex-viz-header"><div><h3 class="foodex-viz-title" id="sales-cancel-title">{{ __('order_operations.dashboard.charts.cancellations') }}</h3><p class="foodex-viz-subtitle">{{ __('order_operations.dashboard.charts.cancellations_help') }}</p></div></div>
            <div class="sales-ops-simple-list"><?php if (($dashboard['cancellation_reasons'] ?? []) === []): ?><div class="foodex-viz-empty">{{ __('order_operations.dashboard.empty_chart') }}</div><?php else: ?><?php foreach (($dashboard['cancellation_reasons'] ?? []) as $point): ?><div><span>{{ $point['reason']==='unspecified' ? __('order_operations.dashboard.unspecified_reason') : $businessLabel($point['reason']) }}</span><strong>{{ number_format((int)$point['count']) }}</strong></div><?php endforeach; ?><?php endif; ?></div>
        </article>

        <article class="foodex-viz-card foodex-module-chart" data-foodex-module-chart data-analytics-group="operations" aria-labelledby="sales-fulfillment-title">
            <div class="foodex-viz-header"><div><h3 class="foodex-viz-title" id="sales-fulfillment-title">{{ __('order_operations.dashboard.charts.fulfillment') }}</h3><p class="foodex-viz-subtitle">{{ __('order_operations.dashboard.charts.fulfillment_help') }}</p></div></div>
            <div class="sales-ops-simple-list"><?php foreach (($dashboard['fulfillment_breakdown'] ?? []) as $point): ?><div><span>{{ __('order_operations.dashboard.fulfillment_buckets.'.$point['bucket']) }}</span><strong>{{ number_format((int)$point['count']) }}</strong></div><?php endforeach; ?></div>
        </article>

        <?php if (is_array($dashboard['source_breakdown'] ?? null)): ?>
        <article class="foodex-viz-card foodex-module-chart" data-foodex-module-chart data-analytics-group="channels" aria-labelledby="sales-source-title">
            <div class="foodex-viz-header"><div><h3 class="foodex-viz-title" id="sales-source-title">{{ __('order_operations.dashboard.charts.sources') }}</h3><p class="foodex-viz-subtitle">{{ __('order_operations.dashboard.charts.sources_help') }}</p></div></div>
            <div class="sales-ops-simple-list"><?php if (($dashboard['source_breakdown'] ?? []) === []): ?><div class="foodex-viz-empty">{{ __('order_operations.dashboard.empty_chart') }}</div><?php else: ?><?php foreach (($dashboard['source_breakdown'] ?? []) as $point): ?><div><span>{{ $businessLabel($point['source']) }}</span><strong>{{ number_format((int)$point['orders']) }}</strong></div><?php endforeach; ?><?php endif; ?></div>
        </article>
        <?php endif; ?>
    </div>
    </div>
</section>
<?php endif; ?>

<?php if (!$dashboardVisible): ?>
<div class="order-workspace-bar" data-order-workspace-bar>
    <nav class="order-workspace-tabs"
         role="tablist"
         aria-label="{{ $isAr ? 'أقسام إدارة الطلبات' : 'Order management sections' }}"
         data-order-tabs
         data-default-tab="orders">
        <button class="order-workspace-tab" type="button" role="tab" aria-selected="true" data-order-tab="orders">{{ $isAr ? 'الطلبات' : 'Orders' }} <span class="order-workspace-tab-count">{{ number_format((int)$orders->total()) }}</span></button>
    </nav>
    <div class="order-workspace-filter-slot" data-order-workspace-filter>
        @include('admin._order-operations-header-filters')
    </div>
</div>
<?php endif; ?>

<div class="order-tab-panel" role="tabpanel" data-order-panel="orders" @if($dashboardVisible && !request()->has('page')) hidden @endif>
@php($channelQuery=request()->except(['channel','store_id','driver_id','dispatch_status','exception','page','order']))
@php($channelCodes=collect($availableChannels ?? [])->pluck('code'))
@php($orderGridResetQuery=['channel'=>$selectedChannel] + ($selectedStoreId !== null ? ['store_id'=>$selectedStoreId] : []))
@if(count($availableChannels ?? []) > 1)
<nav class="status-tabs" aria-label="{{ $isAr ? 'قنوات الطلبات' : 'Order channels' }}" data-order-channel-tabs data-order-channel-selected="{{ $selectedChannel }}">
@foreach($availableChannels as $channelOption)
<a class="status-tab" data-order-channel-tab="{{ $channelOption['code'] }}" href="{{ route('admin.operations.orders.index',array_merge($channelQuery,['channel'=>$channelOption['code']])) }}" @if($selectedChannel===$channelOption['code']) aria-current="page" @endif><span>{{ $channelOption['label'] }}</span></a>
@endforeach
</nav>
@endif

@php($tabQuery=request()->except(['status','page','order','edit']))
@php($dispatchQueueQuery=request()->except(['status','dispatch_status','channel','page','order','edit']))
<nav class="status-tabs" aria-label="{{ __('order_operations.statuses') }}" data-order-status-tabs data-order-status-selected="{{ $selectedStatus ?? 'all' }}">
<a class="status-tab" data-order-status-tab="all" href="{{ route('admin.operations.orders.index',$tabQuery) }}" @if($selectedStatus===null) aria-current="page" @endif>
<span>{{ __('order_operations.all') }}</span><span class="status-tab-count">{{ $statusTotal }}</span>
</a>
@foreach($statusTabs as $tab)
<a class="status-tab" data-order-status-tab="{{ $tab['code'] }}" href="{{ route('admin.operations.orders.index',array_merge($tabQuery,['status'=>$tab['code']])) }}" @if($selectedStatus===$tab['code']) aria-current="page" @endif>
<span>{{ $tab['label'] }}</span><span class="status-tab-count">{{ $tab['count'] }}</span>
</a>
@endforeach
@if($channelCodes->contains('b2b') && $selectedChannel !== 'b2c')
<a class="status-tab" data-order-dispatch-workspace href="{{ route('admin.operations.orders.index',array_merge($dispatchQueueQuery,['channel'=>'b2b','dispatch_status'=>'awaiting_dispatch'])) }}" @if($selectedChannel==='b2b' && request('dispatch_status')==='awaiting_dispatch') aria-current="page" @endif><span>{{ __('order_operations.dispatch.awaiting_queue') }}</span></a>
@endif
</nav>

<form method="get" class="filters foodex-card" data-order-filter-context data-selected-channel="{{ $selectedChannel }}" data-selected-store="{{ $selectedStoreId ?? '' }}">
<input type="hidden" name="channel" value="{{ $selectedChannel }}">
@if(count($availableStores ?? []) > 1)
<label>{{ __('order_operations.filters.store') }}<select name="store_id"><option value="">{{ __('order_operations.filters.all_authorized') }}</option>@foreach($availableStores as $storeOption)<option value="{{ $storeOption['id'] }}" @selected($selectedStoreId===$storeOption['id'])>{{ $storeOption['name'] }}{{ $storeOption['code'] !== '' ? ' · '.$storeOption['code'] : '' }}</option>@endforeach</select></label>
@elseif($selectedStoreId !== null)
<input type="hidden" name="store_id" value="{{ $selectedStoreId }}">
@endif
<label>{{ __('order_operations.filters.from') }}<input type="date" name="from" value="{{ request('from') }}"></label>
<label>{{ __('order_operations.filters.to') }}<input type="date" name="to" value="{{ request('to') }}"></label>
<label>{{ __('order_operations.filters.order_number') }}<input name="order_number" value="{{ request('order_number') }}"></label>
<label>{{ __('order_operations.filters.status') }}<select name="status"><option value="">{{ __('order_operations.all') }}</option>@foreach($statusOptions as $status)<option value="{{ $status['code'] }}" @selected(request('status')===$status['code'])>{{ $status['label'] }}</option>@endforeach</select></label>
<label>{{ __('order_operations.filters.driver') }}<select name="driver_id"><option value="">{{ __('order_operations.all') }}</option>@foreach($drivers as $driver)<option value="{{ $driver->id }}" @selected((string)request('driver_id')===(string)$driver->id)>{{ $driver->name ?: __('order_operations.filters.unnamed_driver') }}</option>@endforeach</select></label>
<label>{{ __('order_operations.filters.dispatch_status') }}<select name="dispatch_status"><option value="">{{ __('order_operations.all') }}</option><option value="awaiting_dispatch" @selected(request('dispatch_status')==='awaiting_dispatch')>{{ __('order_operations.dispatch.awaiting_queue') }}</option><option value="assigned" @selected(request('dispatch_status')==='assigned')>{{ $businessLabel('assigned') }}</option></select></label>
<label>{{ __('order_operations.filters.exception') }}<select name="exception"><option value="">{{ __('order_operations.all') }}</option>@foreach($exceptionOptions as $exceptionOption)<option value="{{ $exceptionOption }}" @selected($selectedException===$exceptionOption)>{{ __('order_operations.dashboard.exceptions.'.$exceptionOption) }}</option>@endforeach</select></label>
<div><button class="foodex-filter-action">{{ __('order_operations.filters.apply') }}</button> <a class="btn secondary" data-order-filter-reset-context href="{{ route('admin.operations.orders.index',$orderGridResetQuery) }}">{{ __('order_operations.filters.reset') }}</a></div>
</form>

<section class="foodex-card panel table-wrap">
<table class="foodex-table ops-table"><thead><tr>
<th>{{ __('order_operations.columns.order') }}</th><th>{{ __('order_operations.columns.source') }}</th><th>{{ __('order_operations.columns.customer') }}</th><th>{{ __('order_operations.customer_service_owner.column') }}</th><th>{{ __('order_operations.columns.status') }}</th><th>{{ __('order_operations.columns.current_executor') }}</th><th>{{ __('order_operations.columns.dispatch') }}</th><th>{{ __('order_operations.columns.payment') }}</th><th>{{ __('order_operations.columns.total') }}</th><th>{{ __('order_operations.columns.created') }}</th><th>{{ __('order_operations.columns.actions') }}</th>
</tr></thead><tbody>
@forelse($rows as $row)
<tr>
<td><a href="{{ route('admin.operations.orders.index',array_merge(request()->query(),['order'=>$row['id']])) }}"><strong>{{ $row['number'] }}</strong></a></td>
<td>{{ $businessLabel($row['source']) }}</td><td>{{ $row['customer'] }}</td> {{-- localization-gate: source resolved through localized businessLabel --}}
<td>@if($row['customer_service_owner'])<strong>{{ $row['customer_service_owner'] }}</strong>@if($row['customer_service_owner_email'])<br><small>{{ $row['customer_service_owner_email'] }}</small>@endif @else {{ __('order_operations.unassigned') }} @endif</td>
@php($badgeState=strtolower((string) $row['status']))
<td><span class="badge foodex-status-badge" data-state="{{ $badgeState }}">{{ $row['status_label'] }}</span></td>
<td>
@if($row['channel']==='b2b')
{{ $row['dispatch_assignee_type']==='van' && $row['dispatch_assignee'] ? $row['dispatch_assignee'] : __('order_operations.unassigned') }}
@else
{{ $row['driver'] ?? __('order_operations.unassigned') }}
@if($row['assignment_status'])<small>· {{ $businessLabel($row['assignment_status']) }}</small>@endif
@endif
</td>
<td><div class="dispatch-cell" data-order-dispatch-status="{{ $row['dispatch_status'] }}">
@php($dispatchBadgeState=strtolower((string) $row['dispatch_status']))
@if($row['dispatch_status']==='awaiting_dispatch')<div class="dispatch-warning">{{ __('order_operations.dispatch.warning') }}</div>@else<span class="badge foodex-status-badge" data-state="{{ $dispatchBadgeState }}">{{ $businessLabel($row['dispatch_status']) }}</span>@endif
@if($row['dispatch_assignee'])<strong>{{ $businessLabel($row['dispatch_assignee_type']) }} · {{ $row['dispatch_assignee'] }}</strong>@endif
@if($row['dispatch_territory'])<span class="dispatch-meta">{{ __('order_operations.dispatch.territory') }}: {{ $row['dispatch_territory'] }}</span>@endif
@if($row['dispatch_source'])<span class="dispatch-meta">{{ __('order_operations.dispatch.source') }}: {{ $businessLabel($row['dispatch_source']) }}</span>@endif
@if($row['dispatch_reason'])<span class="dispatch-meta">{{ __('order_operations.dispatch.routing_reason') }}: {{ $businessLabel($row['dispatch_reason']) }}</span>@endif
</div></td>
<td>{{ $businessLabel($row['payment_status']) }} @if($row['payment_provider'])<small>· {{ strtoupper(str_replace(['_','-'],' ',(string)$row['payment_provider'])) }}</small>@endif</td>
<td>{{ number_format($row['total'],2) }} {{ $row['currency'] }}</td>
<td>{{ optional($row['created_at'])->timezone('Asia/Kuwait')?->format('Y-m-d H:i') ?? '—' }}</td>
<td>
<details class="row-actions" data-order-row-actions>
<summary aria-label="{{ __('order_operations.order_actions') }}">⋮</summary>
<div class="row-action-menu">
<a href="{{ route('admin.operations.orders.index',array_merge(request()->except('edit'),['order'=>$row['id']])) }}">{{ __('order_operations.view_order') }}</a>
@if($row['can_edit'])
<a data-order-edit href="{{ route('admin.operations.orders.index',array_merge(request()->query(),['order'=>$row['id'],'edit'=>1])) }}">{{ $isAr ? 'تعديل الطلب' : 'Edit order' }}</a>
@endif
@if($row['invoice_id'] && $row['can_view_invoice'])
<a data-order-invoice href="{{ route('admin.invoices.show',$row['invoice_id']) }}">{{ $isAr ? 'عرض / طباعة الفاتورة' : 'View / Print invoice' }}</a>
@endif
@if($row['channel']==='b2c' && $row['driver_id'] && $row['can_manage'])
<form method="post" action="{{ route('admin.operations.orders.remind',$row['id']) }}" data-order-remind-driver>@csrf
<button type="submit">{{ $isAr ? 'تذكير السائق' : 'Remind driver' }}</button>
</form>
@endif

@if($canAssignCustomerService && $row['can_manage'])
<form method="post" action="{{ route('admin.operations.orders.customer-service-owner',$row['id']) }}" data-order-customer-service-owner>@csrf @method('PATCH')
<label style="display:grid;gap:4px">
<span>{{ __('order_operations.customer_service_owner.assign_follow_up') }}</span>
<select name="assigned_user_id">
<option value="">{{ __('order_operations.customer_service_owner.remove_owner') }}</option>
@foreach($customerServiceAgents as $agent)
<option value="{{ $agent->id }}" @selected((int)($row['customer_service_owner_id'] ?? 0)===(int)$agent->id)>{{ $agent->name }} · {{ $agent->roles->first()?->code==='CUSTOMER_SERVICE_SUPERVISOR' ? __('order_operations.customer_service_owner.supervisor') : __('order_operations.customer_service_owner.agent') }}</option>
@endforeach
</select>
</label>
<input name="reason" maxlength="500" placeholder="{{ __('order_operations.customer_service_owner.reason_placeholder') }}">
<button class="foodex-primary" type="submit">{{ __('order_operations.customer_service_owner.save') }}</button>
</form>
@endif

@if($row['channel']==='b2b' && $row['status']==='pending' && $row['can_manage'])
<div data-b2b-customer-service-approval style="display:grid;gap:8px;margin-top:8px;padding-top:8px;border-top:1px solid var(--foodex-border)">
<strong>{{ $isAr ? 'قرار خدمة العملاء' : 'Customer Service approval' }}</strong>
<form method="post" action="{{ route('admin.operations.orders.transition',$row['id']) }}">@csrf
<input type="hidden" name="status" value="confirmed">
<input type="hidden" name="note" value="customer_service_approved">
<button class="foodex-primary" type="submit">{{ $isAr ? 'اعتماد الطلب' : 'Approve order' }}</button>
</form>
<form method="post" action="{{ route('admin.operations.orders.transition',$row['id']) }}">@csrf
<input type="hidden" name="status" value="cancelled">
<input name="note" maxlength="1000" required placeholder="{{ $isAr ? 'سبب الرفض (إلزامي)' : 'Rejection reason (required)' }}">
<button class="danger btn" type="submit">{{ $isAr ? 'رفض الطلب' : 'Reject order' }}</button>
</form>
</div>
@endif

@if($row['channel']==='b2b' && $row['settlement'])
@php($settlement=$row['settlement'])
<div data-b2b-settlement-review style="display:grid;gap:5px;margin-top:8px;padding:9px;border-top:1px solid var(--foodex-border)">
<strong>{{ $isAr ? 'مراجعة التسوية المالية' : 'Financial settlement review' }}</strong>
<div>{{ $isAr ? 'رصيد العميل المتاح' : 'Customer credit balance' }}: <strong>{{ number_format((float)$settlement['customer_credit_balance'],3) }} {{ $settlement['currency'] }}</strong></div>
<div>{{ $isAr ? 'إجمالي المديونية الحالية' : 'Aggregate outstanding' }}: <strong>{{ number_format((float)$settlement['aggregate_outstanding'],3) }} {{ $settlement['currency'] }}</strong></div>
<div>{{ $isAr ? 'حد الائتمان' : 'Credit limit' }}: <strong>{{ number_format((float)$settlement['credit_limit'],3) }} {{ $settlement['currency'] }}</strong></div>
<div>{{ $isAr ? 'الحد المتاح' : 'Available credit line' }}: <strong>{{ number_format((float)$settlement['available_credit_line'],3) }} {{ $settlement['currency'] }}</strong></div>
@if($settlement['invoice_outstanding']!==null)
<div>{{ $isAr ? 'المتبقي على الفاتورة' : 'Invoice outstanding' }}: <strong>{{ number_format((float)$settlement['invoice_outstanding'],3) }} {{ $settlement['currency'] }}</strong></div>
@endif
</div>
@endif

@if($row['can_dispatch'])
@if($row['channel']==='b2c')
<form method="post" action="{{ route('admin.operations.orders.dispatch',$row['id']) }}" data-order-dispatch-driver>@csrf @method('PATCH')
<input type="hidden" name="assignee_type" value="driver">
<select name="assignee_id" required aria-label="{{ __('order_operations.dispatch.choose_driver') }}">
<option value="">{{ __('order_operations.dispatch.choose_driver') }}</option>
@foreach($drivers as $driver)
@if((int)$driver->store_id===(int)$row['store_id'] && strtolower((string)$driver->driver_type)===$row['channel'])
<option value="{{ $driver->id }}">{{ $driver->name ?: __('order_operations.filters.unnamed_driver') }}</option>
@endif
@endforeach
</select>
<input name="reason" required maxlength="500" placeholder="{{ __('order_operations.dispatch.reason_required') }}">
<button class="foodex-primary">{{ $row['dispatch_status']==='assigned' ? __('order_operations.dispatch.reassign') : __('order_operations.dispatch.assign') }} · {{ __('order_operations.dispatch.driver') }}</button>
</form>
@endif
@if($row['channel']==='b2b')
<form method="post" action="{{ route('admin.operations.orders.dispatch',$row['id']) }}" data-order-dispatch-van>@csrf @method('PATCH')
<input type="hidden" name="assignee_type" value="van">
<select name="assignee_id" required aria-label="{{ __('order_operations.dispatch.choose_van') }}">
<option value="">{{ __('order_operations.dispatch.choose_van') }}</option>
@foreach($vans as $van)<option value="{{ $van->id }}">{{ $van->code }}{{ $van->plate_number ? ' · '.$van->plate_number : '' }}</option>@endforeach
</select>
<input name="reason" required maxlength="500" placeholder="{{ __('order_operations.dispatch.reason_required') }}">
<button class="foodex-primary">{{ $row['dispatch_status']==='assigned' ? __('order_operations.dispatch.reassign') : __('order_operations.dispatch.assign') }} · {{ __('order_operations.dispatch.van') }}</button>
</form>
@endif
@if($row['dispatch_status']==='assigned')
<form method="post" action="{{ route('admin.operations.orders.dispatch.clear',$row['id']) }}" data-order-dispatch-clear>@csrf @method('DELETE')
<input name="reason" required maxlength="500" placeholder="{{ __('order_operations.dispatch.reason_required') }}">
<button type="submit">{{ __('order_operations.dispatch.unassign') }}</button>
</form>
@endif
@endif
@if($row['can_manage'] && !($row['channel']==='b2b' && $row['status']==='pending'))
@if(count($row['available_statuses']))
<form method="post" action="{{ route('admin.operations.orders.transition',$row['id']) }}">@csrf
<select name="status" required aria-label="{{ __('order_operations.next_status') }}"><option value="">{{ __('order_operations.choose_next_status') }}</option>@foreach($row['available_statuses'] as $status)<option value="{{ $status['code'] }}">{{ $status['label'] }}</option>@endforeach</select>
<button class="foodex-primary">{{ __('order_operations.update_status') }}</button>
</form>
@else
<span class="badge foodex-status-badge {{ $row['status'] }}" data-state="{{ strtolower((string) $row['status']) }}">{{ __('order_operations.terminal') }}</span>
@endif
@endif
</div>
</details>
</td>
</tr>
@empty<tr><td colspan="11"><div class="foodex-empty-state">{{ __('order_operations.empty') }}</div></td></tr>@endforelse
</tbody></table>
</section>
{{ $orders->links() }}
</div>

@if($detail)
@php($detailCloseQuery=request()->except(['order','edit']))
<dialog class="order-detail-drawer foodex-record-drawer" open aria-modal="true" aria-label="{{ __('order_operations.view_order') }}">
<div class="order-detail-drawer-shell foodex-record-drawer-shell">
<div class="order-detail-drawer-head foodex-record-drawer-header"><div><strong>{{ __('order_operations.view_order') }}</strong><div>{{ $detail['number'] }}</div></div><a class="order-detail-close foodex-record-drawer-close" href="{{ route('admin.operations.orders.index',$detailCloseQuery) }}" aria-label="{{ __('order_operations.detail.close') }}">×</a></div>
<section class="detail-grid foodex-record-grid">
<div class="foodex-card panel">
<h2>{{ __('order_operations.detail.context') }} · {{ $detail['number'] }}</h2>
<div class="timeline-item" data-order-authoritative-context>
<strong>{{ $businessLabel($detail['channel']) }} · {{ $detail['store'] }}</strong> {{-- localization-gate: allow channel resolved through localized businessLabel --}}
<div>{{ __('order_operations.detail.source') }}: {{ $businessLabel($detail['source']) }}</div>
<small>{{ __('order_operations.detail.authorized_channel') }}: {{ $businessLabel($detail['channel']) }}</small>
</div>
<h2 style="margin-top:16px">{{ __('order_operations.detail.delivery_address') }}</h2>
@if($detail['delivery_address'])
@php($delivery=$detail['delivery_address'])
<div class="timeline">
<div class="timeline-item">
<strong>{{ $delivery['formatted'] ?: __('order_operations.detail.saved_address') }}</strong>
@if(!empty($delivery['recipient_name']))<div>{{ __('order_operations.detail.recipient') }}: {{ $delivery['recipient_name'] }}</div>@endif
@if(!empty($delivery['delivery_phone']))<div>{{ __('order_operations.detail.phone') }}: {{ $delivery['delivery_phone'] }}</div>@endif
@if(!empty($delivery['landmark']))<div>{{ __('order_operations.detail.landmark') }}: {{ $delivery['landmark'] }}</div>@endif
@if(!empty($delivery['delivery_notes']))<div>{{ __('order_operations.detail.delivery_notes') }}: {{ $delivery['delivery_notes'] }}</div>@endif
@if(!empty($delivery['has_coordinates']))
<div style="margin-top:10px">
<small>{{ number_format((float)$delivery['latitude'],7,'.','') }}, {{ number_format((float)$delivery['longitude'],7,'.','') }}</small>
<br>
<a class="foodex-primary" target="_blank" rel="noopener" href="https://www.google.com/maps/search/?api=1&query={{ urlencode((string)$delivery['latitude'].','.(string)$delivery['longitude']) }}">{{ __('order_operations.detail.open_map') }}</a>
</div>
@else
<small>{{ __('order_operations.detail.no_coordinates') }}</small>
@endif
</div>
</div>
@else
<div class="foodex-empty-state">{{ __('order_operations.detail.no_snapshot') }}</div>
@endif
</div>
<div class="foodex-card panel" data-order-financials>
<h2>{{ $isAr ? 'الدفع والفاتورة' : 'Payment & invoice' }}</h2>
<div class="timeline">
<div class="timeline-item">
<strong>{{ $isAr ? 'الدفع' : 'Payment' }} · {{ $businessLabel($detail['payment_status']) }}</strong>
@if($detail['payment_provider'])<div>{{ strtoupper(str_replace(['_','-'],' ',(string)$detail['payment_provider'])) }}</div>@endif
@if($detail['payment_amount'] !== null)<small>{{ number_format((float)$detail['payment_amount'],3) }} {{ $detail['payment_currency'] ?: $detail['currency'] }}</small>@endif
</div>
@if($detail['invoice_id'] && $detail['can_view_invoice'])
<div class="timeline-item">
<strong>{{ $isAr ? 'الفاتورة' : 'Invoice' }} · {{ $detail['invoice_number'] ?: '#'.$detail['invoice_id'] }}</strong>
<div>{{ $businessLabel($detail['invoice_status']) }}</div>
@if($detail['invoice_total'] !== null)<small>{{ number_format((float)$detail['invoice_total'],3) }} {{ $detail['invoice_currency'] ?: $detail['currency'] }}</small>@endif
<div style="margin-top:8px"><a class="foodex-primary" href="{{ route('admin.invoices.show',$detail['invoice_id']) }}">{{ $isAr ? 'فتح الفاتورة والتحصيل' : 'Open invoice & settlement' }}</a></div>
</div>
@endif
</div>
</div>
<div class="foodex-card panel" data-order-dispatch-detail>
<h2>{{ __('order_operations.dispatch.current_assignment') }}</h2>
@if($detail['dispatch_status']==='awaiting_dispatch')<div class="dispatch-warning">{{ __('order_operations.dispatch.warning') }}</div>@endif
<div class="timeline">
<div class="timeline-item">
<strong>{{ $businessLabel($detail['dispatch_status']) }}</strong>
@if($detail['dispatch_assignee'])<div>{{ __('order_operations.columns.assignee') }}: {{ $businessLabel($detail['dispatch_assignee_type']) }} · {{ $detail['dispatch_assignee'] }}</div>@endif
@if($detail['dispatch_territory'])<div>{{ __('order_operations.dispatch.territory') }}: {{ $detail['dispatch_territory'] }}</div>@endif
@if($detail['dispatch_source'])<div>{{ __('order_operations.dispatch.source') }}: {{ $businessLabel($detail['dispatch_source']) }}</div>@endif
@if($detail['dispatch_reason'])<div>{{ __('order_operations.dispatch.routing_reason') }}: {{ $businessLabel($detail['dispatch_reason']) }}</div>@endif
</div>
</div>
<h3>{{ __('order_operations.dispatch.history') }}</h3>
<div class="timeline">
@forelse($detail['van_assignments'] as $entry)
<div class="timeline-item"><strong>{{ __('order_operations.dispatch.van') }} · {{ $entry['van'] }}</strong><div>{{ $businessLabel($entry['status']) }} · {{ $businessLabel($entry['source']) }}</div>@if($entry['reason'])<div>{{ $businessLabel($entry['reason']) }}</div>@endif<small>{{ optional($entry['assigned_at'])->timezone('Asia/Kuwait')?->format('Y-m-d H:i') ?? '—' }} @if($entry['ended_at'])→ {{ optional($entry['ended_at'])->timezone('Asia/Kuwait')?->format('Y-m-d H:i') ?? '—' }}@endif</small></div>
@empty<div class="foodex-empty-state">{{ __('order_operations.dispatch.no_history') }}</div>@endforelse
</div>
<h3>{{ __('order_operations.dispatch.audit_title') }}</h3>
<div class="timeline" data-order-dispatch-audit>
@forelse($detail['dispatch_audit'] as $entry)
<div class="timeline-item">
<strong>{{ __('order_operations.dispatch.audit_events.'.$entry['event_key']) }}</strong>
<div>{{ $entry['actor'] !== '' ? $entry['actor'] : __('order_operations.dispatch.system_actor') }}</div>
@if($entry['status'])<div>{{ $businessLabel($entry['status']) }}</div>@endif {{-- localization-gate: allow status resolved through localized businessLabel --}}
@if($entry['assignee_type'])<div>{{ __('order_operations.columns.assignee') }}: {{ $businessLabel($entry['assignee_type']) }}</div>@endif
@if($entry['source'])<div>{{ __('order_operations.dispatch.source') }}: {{ $businessLabel($entry['source']) }}</div>@endif
@if($entry['reason'])<div>{{ __('order_operations.dispatch.routing_reason') }}: {{ $businessLabel($entry['reason']) }}</div>@endif
<small>{{ optional($entry['created_at'])->timezone('Asia/Kuwait')?->format('Y-m-d H:i') ?? '—' }}</small>
</div>
@empty<div class="foodex-empty-state">{{ __('order_operations.dispatch.audit_empty') }}</div>@endforelse
</div>
</div>
@if($detail['channel']==='b2b')
<div class="foodex-card panel" data-b2b-van-operations>
<h2>{{ __('order_operations.detail.van_execution_timeline') }}</h2>
@if($detail['van_execution'])
<div class="timeline-item" data-van-execution-state>
<strong>{{ $businessLabel($detail['van_execution']->status) }}</strong> {{-- localization-gate: allow execution status is resolved through localized businessLabel --}}
@if($detail['van_execution']->failure_reason_code)<div>{{ __('order_operations.detail.failure_reason') }}: {{ $businessLabel($detail['van_execution']->failure_reason_code) }}</div>@endif
@if($detail['van_execution']->failure_note)<div>{{ __('order_operations.detail.van_note') }}: {{ $detail['van_execution']->failure_note }}</div>@endif
<small>{{ $detail['van_execution']->last_transition_at ? \Carbon\Carbon::parse($detail['van_execution']->last_transition_at)->timezone('Asia/Kuwait')->format('Y-m-d H:i') : '—' }}</small>
</div>
@endif
<div class="timeline" style="margin-top:10px">
@forelse($detail['van_events'] as $event)
<div class="timeline-item" data-van-execution-event="{{ $event['id'] }}">
<strong>{{ $businessLabel($event['action']) }} · {{ $businessLabel($event['from_status']) }} → {{ $businessLabel($event['to_status']) }}</strong>
@if($event['actor']!=='')<div>{{ $event['actor'] }}</div>@endif
@if($event['reason_code'])<div>{{ __('order_operations.detail.failure_reason') }}: {{ $businessLabel($event['reason_code']) }}</div>@endif
@if($event['note'])<div>{{ __('order_operations.detail.van_note') }}: {{ $event['note'] }}</div>@endif
@if($event['proof_path'])<div><a class="foodex-primary" href="{{ asset('storage/'.ltrim((string)$event['proof_path'],'/')) }}" target="_blank" rel="noopener" data-van-proof-link>{{ __('order_operations.detail.van_proof') }}</a></div>@endif
<small>{{ $event['captured_at'] ? \Carbon\Carbon::parse($event['captured_at'])->timezone('Asia/Kuwait')->format('Y-m-d H:i') : '—' }}</small>
</div>
@empty<div class="foodex-empty-state">{{ __('order_operations.detail.no_van_events') }}</div>@endforelse
</div>
<h3>{{ __('order_operations.detail.collection_timeline') }}</h3>
<div class="timeline" data-van-collection-timeline>
@forelse($detail['collections'] as $collection)
<div class="timeline-item"><strong>{{ \App\Support\DisplayNumber::money($collection['amount'], $collection['currency']) }} {{ $collection['currency'] }} · {{ $businessLabel($collection['status']) }}</strong><div>{{ $businessLabel($collection['source']) }}</div><small>{{ $collection['created_at'] ? \Carbon\Carbon::parse($collection['created_at'])->timezone('Asia/Kuwait')->format('Y-m-d H:i') : '—' }}</small></div> {{-- localization-gate: allow collection status/source are resolved through localized businessLabel --}}
@empty<div class="foodex-empty-state">{{ __('order_operations.detail.no_collections') }}</div>@endforelse
</div>
</div>
@endif
@if(request('edit') && $detail['can_edit'] && $detail['edit_options'])
@php($editOptions=$detail['edit_options'])
<div class="foodex-card panel" data-order-edit-panel>
<h2>{{ $isAr ? 'تعديل الطلب' : 'Edit order' }} · {{ $detail['number'] }}</h2>
<p>{{ $isAr ? 'يتم إعادة التسعير والتحقق من المخزون عند الحفظ. التعديل متاح للطلبات المعلقة غير المقفلة فقط.' : 'Saving re-prices and re-validates stock. Editing is limited to unlocked pending orders.' }}</p>
<form method="post" action="{{ route('admin.operations.orders.update',$detail['id']) }}" data-order-edit-form data-lookup-url="{{ route('admin.operations.orders.lookups',['type'=>'__TYPE__'],false) }}" data-edit-channel="{{ $detail['channel'] }}" data-edit-store="{{ $detail['store_id'] }}"> {{-- localization-gate: allow channel/store values are machine-only scoped lookup attributes, not user-visible labels --}}
@csrf @method('PATCH')
<div class="new-order-grid">
<label class="new-order-field"><span>{{ $isAr ? 'العميل' : 'Customer' }}</span>
<input type="search" data-edit-customer-search autocomplete="off" placeholder="{{ __('order_operations.lookup.search_customer') }}">
<select name="customer_id" data-edit-customer required>
@if($editOptions['selected_customer'])
<option value="{{ $editOptions['selected_customer']['id'] }}" selected>{{ $editOptions['selected_customer']['label'] }}@if($editOptions['selected_customer']['meta']) · {{ $editOptions['selected_customer']['meta'] }}@endif</option>
@else
<option value="{{ $detail['customer_id'] }}" selected>#{{ $detail['customer_id'] }} · {{ __('order_operations.lookup.selected_unavailable') }}</option>
@endif
</select>
<small data-edit-customer-status role="status" aria-live="polite">{{ __('order_operations.lookup.results_help') }}</small>
<button type="button" class="btn secondary" data-edit-customer-more hidden>{{ __('order_operations.lookup.load_more') }}</button>
</label>
@if($detail['channel']==='b2b')
<label class="new-order-field"><span>{{ $isAr ? 'مخزن الصرف' : 'Source warehouse' }}</span><select name="warehouse_id" required>
@foreach($editOptions['warehouses'] as $warehouse)<option value="{{ $warehouse['id'] }}" @selected((int)$detail['warehouse_id']===(int)$warehouse['id'])>{{ $warehouse['code'] }} · {{ $warehouse['name'] }}</option>@endforeach
</select></label>
@endif
<label class="new-order-field"><span>{{ $isAr ? 'العنوان' : 'Address' }}</span><select name="address_id" data-edit-address>
<option value="">{{ $isAr ? 'بدون عنوان / استلام' : 'No address / pickup' }}</option>
@if($editOptions['selected_address'])
<option value="{{ $editOptions['selected_address']['id'] }}" selected>{{ $editOptions['selected_address']['label'] }}@if(($editOptions['selected_address']['active'] ?? true) === false) · {{ __('order_operations.lookup.selected_unavailable') }}@endif</option>
@endif
</select><small data-edit-address-status role="status" aria-live="polite">{{ __('order_operations.lookup.results_help') }}</small>
<button type="button" class="btn secondary" data-edit-address-retry hidden>{{ __('order_operations.lookup.retry') }}</button></label>
<label class="new-order-field"><span>{{ $isAr ? 'طريقة الدفع' : 'Payment method' }}</span><select name="payment_method" required>
@foreach($newOrderWizard['payment_methods'] as $method)<option value="{{ $method['code'] }}" @selected((string)$detail['payment_method']===(string)$method['code'])>{{ $method['label'] }}</option>@endforeach
</select></label>
<label class="new-order-field"><span>{{ $isAr ? 'كود الكوبون' : 'Coupon code' }}</span><input name="coupon_code" maxlength="80" value="{{ $detail['coupon_code'] }}"></label>
</div>
<label class="new-order-field" style="margin-top:14px"><span>{{ $isAr ? 'ملاحظة الطلب' : 'Order note' }}</span><textarea name="customer_note" maxlength="1000" rows="3">{{ $detail['customer_note'] }}</textarea></label>
<h3>{{ $isAr ? 'الأصناف والكميات' : 'Items & quantities' }}</h3>
<div class="new-order-lines" data-edit-order-lines>
@foreach($detail['items'] as $index=>$item)
<div class="new-order-line" data-edit-order-line>
<label class="new-order-field"><span>{{ $isAr ? 'المنتج' : 'Product' }}</span>
<input type="search" data-edit-product-search autocomplete="off" placeholder="{{ __('order_operations.lookup.search_product') }}">
<select name="items[{{ $index }}][product_id]" data-edit-product required>
@php($selectedProduct=$editOptions['selected_products'][(int)$item['product_id']] ?? null)
@if($selectedProduct)
<option value="{{ $selectedProduct['id'] }}" selected>{{ $selectedProduct['meta'] }} · {{ $selectedProduct['label'] }}@if(($selectedProduct['active'] ?? true) === false) · {{ __('order_operations.lookup.selected_unavailable') }}@endif</option>
@else
<option value="{{ $item['product_id'] }}" selected>#{{ $item['product_id'] }} · {{ __('order_operations.lookup.selected_unavailable') }}</option>
@endif
</select>
<small data-edit-product-status role="status" aria-live="polite">{{ __('order_operations.lookup.results_help') }}</small>
<button type="button" class="btn secondary" data-edit-product-more hidden>{{ __('order_operations.lookup.load_more') }}</button>
</label>
<label class="new-order-field"><span>{{ $isAr ? 'الكمية' : 'Quantity' }}</span><input type="number" min="0.001" step="0.001" name="items[{{ $index }}][quantity]" value="{{ $item['quantity'] }}" required></label>
<button type="button" class="btn secondary" data-edit-remove-line>{{ $isAr ? 'حذف' : 'Remove' }}</button>
</div>
@endforeach
</div>
<template data-edit-line-template>
<div class="new-order-line" data-edit-order-line>
<label class="new-order-field"><span>{{ $isAr ? 'المنتج' : 'Product' }}</span>
<input type="search" data-edit-product-search autocomplete="off" placeholder="{{ __('order_operations.lookup.search_product') }}">
<select data-edit-product required disabled><option value="">{{ $isAr ? 'اختر المنتج' : 'Select product' }}</option></select>
<small data-edit-product-status role="status" aria-live="polite">{{ __('order_operations.lookup.results_help') }}</small>
<button type="button" class="btn secondary" data-edit-product-more hidden>{{ __('order_operations.lookup.load_more') }}</button>
</label>
<label class="new-order-field"><span>{{ $isAr ? 'الكمية' : 'Quantity' }}</span><input type="number" min="0.001" step="0.001" value="1" required></label>
<button type="button" class="btn secondary" data-edit-remove-line>{{ $isAr ? 'حذف' : 'Remove' }}</button>
</div>
</template>
<div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:12px">
<button type="button" class="btn secondary" data-edit-add-line>+ {{ $isAr ? 'إضافة صنف' : 'Add item' }}</button>
<button type="submit" class="foodex-primary">{{ $isAr ? 'حفظ وإعادة التسعير' : 'Save & re-price' }}</button>
<a class="btn secondary" href="{{ route('admin.operations.orders.index',array_merge(request()->except('edit'),['order'=>$detail['id']])) }}">{{ $isAr ? 'إلغاء التعديل' : 'Cancel edit' }}</a>
</div>
</form>
</div>
@endif
<div class="foodex-card panel"><h2>{{ __('order_operations.detail.status_timeline') }} · {{ $detail['number'] }}</h2><div class="timeline">@forelse($detail['history'] as $entry)<div class="timeline-item"><strong>{{ $businessLabel($entry['from']) }} → {{ $businessLabel($entry['to']) }}</strong><div>{{ $businessLabel($entry['note']) }}</div><small>{{ optional($entry['created_at'])->timezone('Asia/Kuwait')?->format('Y-m-d H:i') ?? '—' }}</small></div>@empty<div class="foodex-empty-state">{{ __('order_operations.detail.no_history') }}</div>@endforelse</div></div>
<div class="foodex-card panel"><h2>{{ __('order_operations.detail.driver_history') }}</h2><div class="timeline">@forelse($detail['assignments'] as $entry)<div class="timeline-item"><strong>{{ str_starts_with((string)$entry['driver'],'#') ? __('order_operations.detail.unnamed_driver') : $entry['driver'] }}</strong><div>{{ $businessLabel($entry['status']) }}</div><small>{{ optional($entry['assigned_at'])->timezone('Asia/Kuwait')?->format('Y-m-d H:i') ?? '—' }} @if($entry['completed_at'])→ {{ optional($entry['completed_at'])->timezone('Asia/Kuwait')?->format('Y-m-d H:i') ?? '—' }}@endif</small></div>@empty<div class="foodex-empty-state">{{ __('order_operations.detail.no_assignments') }}</div>@endforelse</div></div>
<div class="foodex-card panel" data-delivery-evidence>
<h2>{{ __('order_operations.detail.delivery_timeline') }}</h2>
<div class="timeline">
@forelse($detail['delivery_evidence'] as $assignment)
<div class="timeline-item" data-delivery-evidence-assignment="{{ $assignment['id'] }}">
<strong>{{ str_starts_with((string)$assignment['driver_name'],'#') ? __('order_operations.detail.unnamed_driver') : $assignment['driver_name'] }} · {{ $businessLabel($assignment['status']) }}</strong>
@if(empty($assignment['timeline']))
<small>{{ __('order_operations.detail.no_assignment_events') }}</small>
@else
<div class="timeline" style="margin-top:10px">
@foreach($assignment['timeline'] as $event)
<div class="timeline-item" data-delivery-evidence-event="{{ $event['id'] }}">
<strong>{{ $businessLabel($event['from_status']) }} → {{ $businessLabel($event['to_status']) }}</strong>
@if($event['reason_code'])<div>{{ __('order_operations.detail.failure_reason') }}: {{ $businessLabel($event['reason_code']) }}</div>@endif
@if($event['note'])<div>{{ __('order_operations.detail.driver_note') }}: {{ $event['note'] }}</div>@endif
@if($event['proof'])
<div><a class="foodex-primary" href="{{ $event['proof']['url'] }}" target="_blank" rel="noopener" data-delivery-proof-link>{{ __('order_operations.detail.view_proof') }}</a></div>
@endif
<small>{{ $event['captured_at'] ? \Carbon\Carbon::parse($event['captured_at'])->timezone('Asia/Kuwait')->format('Y-m-d H:i') : '—' }}</small>
</div>
@endforeach
</div>
@endif
</div>
@empty
<div class="foodex-empty-state">{{ __('order_operations.detail.no_delivery_evidence') }}</div>
@endforelse
</div>
</div>
<div class="foodex-card panel" data-van-delivery-evidence>
<h2>{{ __('order_operations.detail.van_delivery_timeline') }}</h2>
<div class="timeline">
@forelse($detail['van_delivery_evidence'] as $assignment)
<div class="timeline-item" data-van-delivery-evidence-assignment="{{ $assignment['id'] }}">
<strong>{{ $assignment['van_name'] }} · {{ $businessLabel($assignment['execution_status'] ?? $assignment['assignment_status']) }}</strong>
@if(empty($assignment['timeline']))
<small>{{ __('order_operations.detail.no_assignment_events') }}</small>
@else
<div class="timeline" style="margin-top:10px">
@foreach($assignment['timeline'] as $event)
<div class="timeline-item" data-van-delivery-evidence-event="{{ $event['id'] }}">
<strong>{{ $businessLabel($event['from_status']) }} → {{ $businessLabel($event['to_status']) }}</strong>
@if($event['reason_code'])<div>{{ __('order_operations.detail.failure_reason') }}: {{ $businessLabel($event['reason_code']) }}</div>@endif
@if($event['note'])<div>{{ __('order_operations.detail.van_note') }}: {{ $event['note'] }}</div>@endif
@if($event['proof'])
<div><a class="foodex-primary" href="{{ $event['proof']['url'] }}" target="_blank" rel="noopener" data-van-delivery-proof-link>{{ __('order_operations.detail.view_proof') }}</a></div>
@endif
<small>{{ $event['captured_at'] ? \Carbon\Carbon::parse($event['captured_at'])->timezone('Asia/Kuwait')->format('Y-m-d H:i') : '—' }}</small>
</div>
@endforeach
</div>
@endif
</div>
@empty
<div class="foodex-empty-state">{{ __('order_operations.detail.no_van_delivery_evidence') }}</div>
@endforelse
</div>
</div>
</section>
</div>
</dialog>
@endif
</main>
<aside class="sidebar">@include('admin._sidebar')</aside>
</div>
<script>
(() => {
    const tabs = document.querySelector('[data-order-tabs]');
    if (!tabs) return;

    const buttons = [...tabs.querySelectorAll('[data-order-tab]')];
    const panels = [...document.querySelectorAll('[data-order-panel]')];
    if (!buttons.length || !panels.length) return;

    const available = new Set(buttons.map((button) => button.dataset.orderTab));
    const defaultTab = available.has(tabs.dataset.defaultTab) ? tabs.dataset.defaultTab : buttons[0].dataset.orderTab;
    const hashTab = window.location.hash.startsWith('#order-tab-')
        ? window.location.hash.slice('#order-tab-'.length)
        : null;

    const activate = (name, updateHistory = true) => {
        if (!available.has(name)) name = defaultTab;

        buttons.forEach((button) => {
            const active = button.dataset.orderTab === name;
            button.setAttribute('aria-selected', active ? 'true' : 'false');
            button.tabIndex = active ? 0 : -1;
        });
        panels.forEach((panel) => {
            panel.hidden = panel.dataset.orderPanel !== name;
        });

        const analyticsPanel = panels.find((panel) => panel.dataset.orderPanel === 'analytics');
        if (name === 'analytics' && analyticsPanel) {
            analyticsPanel.classList.remove('charts-animate');
            if (!window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
                void analyticsPanel.offsetWidth;
                analyticsPanel.classList.add('charts-animate');
            }
        }

        if (updateHistory) {
            history.replaceState(null, '', '#order-tab-' + name);
        }
    };

    buttons.forEach((button) => {
        button.addEventListener('click', () => activate(button.dataset.orderTab));
    });

    activate(hashTab && available.has(hashTab) ? hashTab : defaultTab, false);
})();

(() => {
    const tabs = document.querySelector('[data-order-analytics-tabs]');
    const panel = document.querySelector('[data-order-panel="analytics"]');
    if (!tabs || !panel) return;

    const buttons = [...tabs.querySelectorAll('[data-analytics-tab]')];
    const cards = [...panel.querySelectorAll('[data-analytics-group]')];
    if (!buttons.length || !cards.length) return;

    const available = new Set(buttons.map((button) => button.dataset.analyticsTab));
    const defaultTab = available.has(tabs.dataset.defaultAnalyticsTab)
        ? tabs.dataset.defaultAnalyticsTab
        : buttons[0].dataset.analyticsTab;

    const animateVisible = () => {
        panel.classList.remove('charts-animate');
        if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;
        void panel.offsetWidth;
        panel.classList.add('charts-animate');
    };

    const activate = (name) => {
        if (!available.has(name)) name = defaultTab;

        buttons.forEach((button) => {
            const active = button.dataset.analyticsTab === name;
            button.setAttribute('aria-selected', active ? 'true' : 'false');
            button.tabIndex = active ? 0 : -1;
        });

        cards.forEach((card) => {
            card.hidden = card.dataset.analyticsGroup !== name;
        });

        animateVisible();
    };

    buttons.forEach((button) => {
        button.addEventListener('click', () => activate(button.dataset.analyticsTab));
    });

    activate(defaultTab);
})();

(() => {
    const values = [...document.querySelectorAll('[data-order-counter]')];
    if (!values.length) return;

    const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    const formatLikeTarget = (element, value) => {
        const target = element.dataset.counterTarget || String(value);
        const decimals = Number.parseInt(element.dataset.counterDecimals || '0', 10);
        const formatted = new Intl.NumberFormat('en-US', {
            minimumFractionDigits: decimals,
            maximumFractionDigits: decimals,
        }).format(value);
        return target.replace(/[-+]?\d[\d,]*(?:\.\d+)?/, formatted);
    };

    const fit = (element) => {
        element.style.fontSize = '';
        let size = Number.parseFloat(getComputedStyle(element).fontSize);
        const minimum = 11;
        let guard = 0;
        while (element.scrollWidth > element.clientWidth && size > minimum && guard < 24) {
            size -= .5;
            element.style.fontSize = size + 'px';
            guard += 1;
        }
    };

    const fitAll = () => values.forEach(fit);
    const duration = 1200;

    values.forEach((element) => {
        const raw = Number.parseFloat(element.dataset.counterRaw ?? '');
        if (!Number.isFinite(raw) || reducedMotion) {
            fit(element);
            return;
        }

        const started = performance.now();
        const tick = (now) => {
            const progress = Math.min(1, (now - started) / duration);
            const eased = 1 - Math.pow(1 - progress, 3);
            element.textContent = formatLikeTarget(element, raw * eased);
            fit(element);
            if (progress < 1) {
                window.requestAnimationFrame(tick);
            } else {
                element.textContent = element.dataset.counterTarget || element.textContent;
                fit(element);
            }
        };

        element.textContent = formatLikeTarget(element, 0);
        window.requestAnimationFrame(tick);
    });

    let resizeFrame = 0;
    window.addEventListener('resize', () => {
        window.cancelAnimationFrame(resizeFrame);
        resizeFrame = window.requestAnimationFrame(fitAll);
    }, {passive:true});
})();
</script>
</body></html>
@if($detail)
<script>
document.addEventListener('DOMContentLoaded', () => {
    const drawer = document.querySelector('.order-detail-drawer');
    const closeLink = drawer?.querySelector('.order-detail-close');
    if (!(drawer instanceof HTMLElement) || !window.FoodexModalA11y) return;

    let modalSession = null;
    const leaveDrawer = () => {
        modalSession?.deactivate({ restoreFocus: false });
        modalSession = null;
    };
    const dismissDrawer = () => {
        leaveDrawer();
        if (closeLink instanceof HTMLAnchorElement) window.location.assign(closeLink.href);
    };

    modalSession = window.FoodexModalA11y.activate({
        root: drawer,
        dialog: drawer,
        initialFocus: closeLink instanceof HTMLElement ? closeLink : drawer,
        onEscape: dismissDrawer,
    });
    closeLink?.addEventListener('click', leaveDrawer, { once: true });
    window.addEventListener('pagehide', leaveDrawer, { once: true });
});
</script>
@endif
@if($detail && request('edit') && $detail['can_edit'])
<script>
document.addEventListener('DOMContentLoaded',()=>{
 const form=document.querySelector('[data-order-edit-form]');
 const box=document.querySelector('[data-edit-order-lines]');
 const tpl=document.querySelector('[data-edit-line-template]');
 const add=document.querySelector('[data-edit-add-line]');
 if(!form||!box||!tpl||!add)return;
 const channel=form.dataset.editChannel;
 const storeId=form.dataset.editStore;
 const labels={
   chooseProduct:@json($isAr ? 'اختر المنتج' : 'Select product'),
   pickup:@json($isAr ? 'بدون عنوان / استلام' : 'No address / pickup'),
   searching:@json(__('order_operations.lookup.searching')),
   failed:@json(__('order_operations.lookup.failed')),
   empty:@json(__('order_operations.lookup.no_results')),
   help:@json(__('order_operations.lookup.results_help')),
   unavailable:@json(__('order_operations.lookup.selected_unavailable')),
 };
 const endpoint=(type)=>String(form.dataset.lookupUrl||'').replace('__TYPE__',encodeURIComponent(type));
 const fetchLookup=async(type,params,signal)=>{
   const url=new URL(endpoint(type),window.location.origin);
   Object.entries({channel,store_id:storeId,...params}).forEach(([key,value])=>{
     if(value!==''&&value!==null&&value!==undefined)url.searchParams.set(key,String(value));
   });
   const response=await fetch(url,{headers:{'Accept':'application/json','X-Requested-With':'XMLHttpRequest'},signal});
   const payload=await response.json().catch(()=>({}));
   if(!response.ok)throw new Error(payload?.message||labels.failed);
   return payload.data||{items:[],page:1,has_more:false};
 };
 const itemText=(item)=>{
   const base=[item.meta,item.label].filter(Boolean).join(' · ');
   return item.active===false?base+' · '+labels.unavailable:base;
 };
 const applyOptions=(select,items,{append=false,selectedId=null,placeholder=labels.chooseProduct}={})=>{
   const keep=selectedId?String(selectedId):select.value;
   const previous=keep?[...select.options].find(o=>o.value===keep)?.cloneNode(true):null;
   if(!append){select.innerHTML='';select.append(new Option(placeholder,''));}
   const existing=new Set([...select.options].map(o=>o.value));
   (items||[]).forEach(item=>{
     const value=String(item.id); if(existing.has(value))return;
     select.append(new Option(itemText(item),value));existing.add(value);
   });
   if(keep&&!existing.has(keep)&&previous)select.append(previous);
   if(keep&&[...select.options].some(o=>o.value===keep))select.value=keep;
 };
 const bindProduct=(row,selectedId=null)=>{
   const search=row.querySelector('[data-edit-product-search]');
   const select=row.querySelector('[data-edit-product]');
   const status=row.querySelector('[data-edit-product-status]');
   const more=row.querySelector('[data-edit-product-more]');
   if(!search||!select||!status||!more)return;
   let timer=null,page=1,query='',controller=null,sequence=0;
   const load=async(nextQuery=query,nextPage=1,keepId=null)=>{
     controller?.abort();controller=new AbortController();const mine=++sequence;
     status.textContent=labels.searching;more.dataset.retry='0';more.textContent=@json(__('order_operations.lookup.load_more'));select.disabled=true;
     try{
       const result=await fetchLookup('product',{q:nextQuery,page:nextPage,selected_id:keepId},controller.signal);
       if(mine!==sequence)return;
       query=nextQuery;page=nextPage;
       applyOptions(select,result.items,{append:nextPage>1,selectedId:keepId});
       select.disabled=false;status.textContent=result.items.length?labels.help:labels.empty;more.dataset.retry='0';more.textContent=@json(__('order_operations.lookup.load_more'));more.hidden=!result.has_more;
       if(keepId)select.value=String(keepId);
     }catch(error){
       if(error.name==='AbortError')return;
       select.disabled=false;status.textContent=labels.failed;more.dataset.retry='1';more.textContent=@json(__('order_operations.lookup.retry'));more.hidden=false;
     }
   };
   search.addEventListener('input',()=>{clearTimeout(timer);timer=setTimeout(()=>load(search.value.trim(),1),250);});
   more.addEventListener('click',()=>load(query,more.dataset.retry==='1'?page:page+1));
   if(select.options.length<=1||selectedId!==null)load('',1,selectedId);
 };
 const reindex=()=>box.querySelectorAll('[data-edit-order-line]').forEach((row,index)=>{
   const select=row.querySelector('select[data-edit-product]');
   const qty=row.querySelector('input[type="number"]');
   if(select)select.name='items['+index+'][product_id]';
   if(qty)qty.name='items['+index+'][quantity]';
 });
 const bind=(row)=>{
   bindProduct(row,row.querySelector('[data-edit-product]')?.value||null);
   row.querySelector('[data-edit-remove-line]')?.addEventListener('click',()=>{
     if(box.querySelectorAll('[data-edit-order-line]').length<=1)return;
     row.remove();reindex();
   });
 };
 box.querySelectorAll('[data-edit-order-line]').forEach(bind);
 add.addEventListener('click',()=>{
   const fragment=tpl.content.cloneNode(true);const row=fragment.querySelector('[data-edit-order-line]');
   box.appendChild(fragment);bind(row);reindex();
 });
 const customerSearch=form.querySelector('[data-edit-customer-search]');
 const customer=form.querySelector('[data-edit-customer]');
 const customerStatus=form.querySelector('[data-edit-customer-status]');
 const customerMore=form.querySelector('[data-edit-customer-more]');
 const address=form.querySelector('[data-edit-address]');
 const addressStatus=form.querySelector('[data-edit-address-status]');
 const addressRetry=form.querySelector('[data-edit-address-retry]');
 let customerTimer=null,customerPage=1,customerQuery='',customerController=null,customerSequence=0,addressController=null;
 const loadCustomers=async(query=customerQuery,page=1)=>{
   customerController?.abort();customerController=new AbortController();const mine=++customerSequence;
   customerStatus.textContent=labels.searching;customerMore.dataset.retry='0';customerMore.textContent=@json(__('order_operations.lookup.load_more'));customer.disabled=true;
   try{
     const result=await fetchLookup('customer',{q,page,selected_id:customer.value||null},customerController.signal);
     if(mine!==customerSequence)return;
     customerQuery=query;customerPage=page;applyOptions(customer,result.items,{append:page>1,selectedId:customer.value||null,placeholder:''});
     customer.disabled=false;customerStatus.textContent=result.items.length?labels.help:labels.empty;customerMore.dataset.retry='0';customerMore.textContent=@json(__('order_operations.lookup.load_more'));customerMore.hidden=!result.has_more;
   }catch(error){
     if(error.name==='AbortError')return;
     customer.disabled=false;customerStatus.textContent=labels.failed;customerMore.dataset.retry='1';customerMore.textContent=@json(__('order_operations.lookup.retry'));customerMore.hidden=false;
   }
 };
 const loadAddresses=async()=>{
   addressController?.abort();addressController=new AbortController();const customerId=customer.value;
   address.innerHTML='';address.append(new Option(labels.pickup,''));
   if(!customerId){address.disabled=true;return;}
   address.disabled=true;addressRetry.hidden=true;addressStatus.textContent=labels.searching;
   try{
     const result=await fetchLookup('address',{customer_id:customerId},addressController.signal);
     applyOptions(address,result.items,{placeholder:labels.pickup});
     address.disabled=false;addressRetry.hidden=true;addressStatus.textContent=result.items.length?labels.help:labels.empty;
   }catch(error){
     if(error.name==='AbortError')return;
     address.disabled=false;addressStatus.textContent=labels.failed;addressRetry.hidden=false;
   }
 };
 customerSearch?.addEventListener('input',()=>{clearTimeout(customerTimer);customerTimer=setTimeout(()=>loadCustomers(customerSearch.value.trim(),1),250);});
 customerMore?.addEventListener('click',()=>loadCustomers(customerQuery,customerMore.dataset.retry==='1'?customerPage:customerPage+1));
 addressRetry?.addEventListener('click',loadAddresses);
 customer?.addEventListener('change',loadAddresses);
 reindex();
});
</script>
@endif
