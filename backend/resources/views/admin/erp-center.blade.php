<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>{{ __('admin.erp_center.title') }} · FOODEX</title>
@include('admin._brand-components')
<link rel="stylesheet" href="{{ asset('assets/admin/foodex-visualization.css') }}">
<style>
body{margin:0}
.erp-center{min-width:0}
.erp-eyebrow{margin:0;color:var(--foodex-green-dark);font-weight:800}
.erp-subtitle{margin:.35rem 0 0;color:var(--foodex-muted);max-width:78ch}
.erp-section{display:grid;gap:var(--foodex-space-3);min-width:0}
.erp-section-head{display:flex;align-items:flex-start;justify-content:space-between;gap:var(--foodex-space-3);flex-wrap:wrap}
.erp-section-head h2,.erp-section-head p{margin:0}
.erp-workspace-tabs{display:flex;flex-wrap:nowrap;max-width:100%;overflow-x:auto;gap:var(--foodex-space-2);margin-block:var(--foodex-space-4);padding-block:var(--foodex-space-2);scrollbar-width:thin}
.erp-workspace-tabs .foodex-tab{flex:0 0 auto;white-space:nowrap;min-height:44px}
.erp-workspace-tabs .foodex-tab:focus-visible{outline:2px solid var(--foodex-green);outline-offset:2px}
.erp-center [data-erp-panel]{min-width:0}
.erp-kpis{grid-template-columns:repeat(5,minmax(0,1fr))}
.erp-kpi-link{color:inherit;text-decoration:none}
.erp-kpi-link:focus-visible{outline:3px solid color-mix(in srgb,var(--foodex-green) 38%,transparent);outline-offset:3px}
.erp-kpi-unavailable{opacity:.72}
.erp-health-meta{color:var(--foodex-muted);font-size:var(--foodex-text-xs)}
.erp-analytics{display:grid;grid-template-columns:minmax(0,1.25fr) minmax(300px,.75fr);gap:var(--foodex-space-3)}
.erp-bars{display:grid;gap:10px;margin-top:14px}
.erp-bar-row{display:grid;grid-template-columns:minmax(110px,.8fr) minmax(0,2fr) auto;align-items:center;gap:10px;color:inherit;text-decoration:none}
.erp-bar-track{height:12px;border-radius:999px;background:var(--foodex-background);overflow:hidden}
.erp-bar-fill{display:block;height:100%;width:var(--erp-bar-width);border-radius:inherit;background:var(--foodex-green)}
.erp-bar-row[data-exceptions="true"] .erp-bar-fill{background:var(--foodex-orange)}
.erp-bar-values{display:flex;gap:7px;white-space:nowrap;color:var(--foodex-muted);font-size:var(--foodex-text-xs)}
.erp-state-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px;margin-top:14px}
.erp-state-link{display:grid;gap:4px;padding:12px;border:1px solid var(--foodex-border);border-radius:var(--foodex-radius-control);color:inherit;text-decoration:none}
.erp-state-link strong{font-size:var(--foodex-text-xl)}
.erp-state-link.is-active{border-color:var(--foodex-green);box-shadow:0 0 0 2px color-mix(in srgb,var(--foodex-green) 16%,transparent)}
.erp-preview-grid{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1fr);gap:var(--foodex-space-3)}
.erp-list{display:grid;gap:8px;margin-top:12px}
.erp-list-row{display:grid;grid-template-columns:minmax(0,1fr) auto;gap:12px;align-items:center;padding:12px;border:1px solid var(--foodex-border);border-radius:var(--foodex-radius-control)}
.erp-list-row strong{display:block}
.erp-list-meta{display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-top:4px;color:var(--foodex-muted);font-size:var(--foodex-text-xs)}
.erp-filter-rail{display:flex;gap:8px;flex-wrap:wrap}
.erp-filter{display:inline-flex;align-items:center;gap:6px;min-height:36px;padding:6px 10px;border:1px solid var(--foodex-border);border-radius:999px;color:var(--foodex-ink);text-decoration:none;background:var(--foodex-surface)}
.erp-filter.is-active{background:var(--foodex-green-soft);border-color:var(--foodex-green);color:var(--foodex-green-dark);font-weight:800}
.erp-module-tablist{display:flex;gap:8px;max-width:100%;overflow-x:auto;overscroll-behavior-inline:contain;padding:2px 1px 8px}
.erp-module-tab{display:grid;gap:4px;flex:0 0 auto;min-width:150px;min-height:54px;padding:9px 13px;border:1px solid var(--foodex-border);border-radius:var(--foodex-radius-control);background:var(--foodex-surface);color:var(--foodex-ink);font:inherit;text-align:start;cursor:pointer}
.erp-module-tab[aria-selected="true"]{border-color:var(--foodex-green);background:var(--foodex-green-soft);color:var(--foodex-green-dark);box-shadow:0 0 0 2px color-mix(in srgb,var(--foodex-green) 14%,transparent);font-weight:800}
.erp-module-tab:focus-visible{outline:3px solid color-mix(in srgb,var(--foodex-green) 38%,transparent);outline-offset:2px}
.erp-module-tab-status{font-size:var(--foodex-text-xs);color:var(--foodex-muted)}
.erp-module-panels{display:grid;grid-template-columns:minmax(0,1fr)}
.erp-module-panels [role="tabpanel"][hidden]{display:none}
.erp-module{padding:var(--foodex-space-4);display:flex;flex-direction:column;gap:var(--foodex-space-3);min-width:0}
.erp-module h3{margin:0;font-size:var(--foodex-text-lg)}
.erp-module p{margin:0;color:var(--foodex-muted);line-height:var(--foodex-leading-normal)}
.erp-module.is-selected{outline:2px solid var(--foodex-green);outline-offset:2px}
.erp-module-footer{display:flex;align-items:center;justify-content:space-between;gap:var(--foodex-space-3);margin-block-start:auto;flex-wrap:wrap}
.erp-status{display:inline-flex;align-items:center;min-height:32px;padding-inline:var(--foodex-space-3);border-radius:999px;font-weight:800;font-size:var(--foodex-text-sm)}
.erp-status[data-state="available"]{background:var(--foodex-green-soft);color:var(--foodex-green-dark)}
.erp-status[data-state="degraded"]{background:var(--foodex-orange-soft);color:var(--foodex-orange)}
.erp-status[data-state="setup_required"]{background:rgba(75,140,245,.12);color:var(--foodex-blue)}
.erp-status[data-state="disabled"],.erp-status[data-state="not_entitled"]{background:var(--foodex-background);color:var(--foodex-muted);border:1px solid var(--foodex-border)}
.erp-note{font-size:var(--foodex-text-sm);color:var(--foodex-muted)}
.erp-readiness-report{display:grid;gap:6px;padding:10px 12px;border-inline-start:3px solid var(--foodex-orange);border-radius:var(--foodex-radius-control);background:var(--foodex-orange-soft);font-size:var(--foodex-text-sm)}
.erp-readiness-report[data-complete="true"]{border-inline-start-color:var(--foodex-green);background:var(--foodex-green-soft)}
.erp-readiness-report ul{margin:0;padding-inline-start:1.25rem}
.erp-readiness-report li{color:var(--foodex-ink);line-height:var(--foodex-leading-normal)}
.erp-readiness-steps{display:grid;gap:8px;list-style:none;padding:0!important}
.erp-readiness-step{display:grid;grid-template-columns:auto minmax(0,1fr) auto;align-items:start;gap:8px;padding:8px 10px;border:1px solid color-mix(in srgb,var(--foodex-orange) 24%,transparent);border-radius:var(--foodex-radius-control);background:var(--foodex-surface)}
.erp-readiness-step[data-complete="true"]{border-color:color-mix(in srgb,var(--foodex-green) 30%,transparent)}
.erp-readiness-step-state{font-weight:800;white-space:nowrap}
.erp-readiness-step a{font-weight:800;white-space:nowrap}
.erp-health{font-size:var(--foodex-text-xs);font-weight:800;color:var(--foodex-muted)}
.erp-table-details{margin-top:12px}
.erp-table-details summary{cursor:pointer;color:var(--foodex-green-dark);font-weight:800}
.erp-table{width:100%;border-collapse:collapse;margin-top:8px;font-size:var(--foodex-text-sm)}
.erp-table th,.erp-table td{padding:8px 10px;border-bottom:1px solid var(--foodex-border);text-align:start}
@media(max-width:1180px){.erp-kpis{grid-template-columns:repeat(3,minmax(0,1fr))}}
@media(max-width:1023px){.erp-analytics,.erp-preview-grid{grid-template-columns:minmax(0,1fr)}}
@media(max-width:767px){.erp-kpis{grid-template-columns:minmax(0,1fr)}.erp-bar-row{grid-template-columns:minmax(90px,.8fr) minmax(0,1.5fr)}.erp-bar-values{grid-column:1/-1}.erp-state-grid{grid-template-columns:1fr}.erp-module-footer{align-items:stretch}.erp-module-footer .foodex-action-primary{width:100%;justify-content:center}.erp-list-row{grid-template-columns:1fr}}
</style>
</head>
<body>
<div class="foodex-admin-layout" data-foodex-mobile-web-page="erp-center" data-erp-center="true">
<aside class="sidebar">@include('admin._sidebar',['navGroups'=>$navGroups,'navContext'=>$navContext,'user'=>$user])</aside>
<main class="foodex-admin-main foodex-admin-page erp-center">
@include('admin._global-header')
<div class="foodex-page-toolbar">
<div>
<p class="erp-eyebrow">{{ __('admin.erp_center.eyebrow') }}</p>
<h1>{{ __('admin.erp_center.title') }}</h1>
<p class="erp-subtitle">{{ __('admin.erp_center.description') }}</p>
</div>

</div>

@php
// Tab selection is server-rendered and strictly allowlisted. The ERP controller
// remains the single authority for module visibility, entitlement and data.
$erpTabKeys = ['snapshot', 'analytics', 'follow_up', 'modules'];
$erpTabInput = request()->query('tab');
$erpTabContext = $selectedStatus !== '' ? ['status' => $selectedStatus] : [];
if (is_string(request()->query('module')) && in_array($selectedModule, array_column($cards, 'key'), true)) {
    $erpTabContext['module'] = $selectedModule;
}
// Existing status and authorized module deep links must open the visible Modules panel.
$erpTabFallback = ($selectedStatus !== '' || array_key_exists('module', $erpTabContext))
    ? 'modules'
    : 'snapshot';
$activeErpTab = is_string($erpTabInput) && in_array($erpTabInput, $erpTabKeys, true)
    ? $erpTabInput
    : $erpTabFallback;
$erpTabLabels = [
    'snapshot' => 'admin.erp_center.snapshot.title',
    'analytics' => 'admin.erp_center.analytics.label',
    'follow_up' => 'admin.erp_center.follow_up.label',
    'modules' => 'admin.erp_center.modules_title',
];
@endphp
<nav class="foodex-tabs erp-workspace-tabs" aria-label="{{ __('admin.erp_center.title') }}" data-erp-tabs>
@foreach($erpTabKeys as $erpTabKey)
<a class="foodex-tab {{ $activeErpTab === $erpTabKey ? 'active' : '' }}"
   id="erp-tab-{{ $erpTabKey }}" data-erp-tab="{{ $erpTabKey }}"
   href="{{ route('admin.erp.index', array_merge($erpTabContext, ['tab' => $erpTabKey])) }}"
   @if($activeErpTab === $erpTabKey) aria-current="page" @endif>{{ __($erpTabLabels[$erpTabKey]) }}</a>
@endforeach
</nav>

<div class="foodex-module-overview">
@if($activeErpTab === 'snapshot')
<section class="erp-section" aria-labelledby="erp-tab-snapshot" data-erp-panel="snapshot">
<div class="erp-section-head">
<div>
<h2 id="erp-snapshot-title">{{ __('admin.erp_center.snapshot.title') }}</h2>
<p class="erp-subtitle">{{ __('admin.erp_center.snapshot.description') }}</p>
</div>
</div>
<div class="foodex-module-kpis erp-kpis" data-erp-kpis>
@foreach($kpis as $kpi)
@if($kpi['url'])
<a
    class="foodex-viz-card foodex-module-kpi erp-kpi-link {{ $kpi['available'] ? '' : 'erp-kpi-unavailable' }}"
    data-erp-kpi="{{ $kpi['key'] }}"
    href="{{ $kpi['url'] }}"
    data-interactive="true"
>
@else
<article
    class="foodex-viz-card foodex-module-kpi erp-kpi-unavailable"
    data-erp-kpi="{{ $kpi['key'] }}"
>
@endif
<span class="foodex-module-kpi-label">{{ __('admin.erp_center.kpis.'.$kpi['key'].'.label') }}</span>
<strong class="foodex-module-kpi-value">{{ $kpi['available'] ? $kpi['value'] : '—' }}</strong>
<div class="foodex-module-kpi-meta">
<span class="foodex-module-updated">
@if(!$kpi['available'])
{{ __('admin.erp_center.kpis.unavailable') }}
@elseif($kpi['key'] === 'module_health')
{{ __('admin.erp_center.kpis.module_health.meta',['healthy'=>$kpi['healthy'],'total'=>$kpi['total']]) }}
@elseif($kpi['bounded'])
{{ __('admin.erp_center.kpis.bounded') }}
@else
{{ __('admin.erp_center.kpis.'.$kpi['key'].'.hint') }}
@endif
</span>
</div>
@if($kpi['url'])
</a>
@else
</article>
@endif
@endforeach
</div>
</section>
@endif

@if($activeErpTab === 'analytics')
<section class="erp-analytics" aria-labelledby="erp-tab-analytics" data-erp-panel="analytics">
<article class="foodex-viz-card" data-erp-chart="workload">
<div class="foodex-viz-header">
<div>
<h2 class="foodex-viz-title">{{ __('admin.erp_center.analytics.workload.title') }}</h2>
<p class="foodex-viz-subtitle">{{ __('admin.erp_center.analytics.workload.description') }}</p>
</div>
</div>
@if($workload === [])
<div class="foodex-viz-empty">{{ __('admin.erp_center.analytics.workload.empty') }}</div>
@else
@php
$workMax = max(1, (int) collect($workload)->max('total'));
@endphp
<div class="erp-bars">
@foreach($workload as $row)
<a class="erp-bar-row" data-erp-work-module="{{ $row['key'] }}" data-exceptions="{{ $row['exceptions'] > 0 ? 'true' : 'false' }}" href="{{ $row['url'] }}">
<strong>{{ $row['label'] }}</strong>
<span class="erp-bar-track" aria-hidden="true"><span class="erp-bar-fill" style="--erp-bar-width:{{ $row['total'] > 0 ? min(100, max(2, (int) round(($row['total'] / $workMax) * 100))) : 0 }}%"></span></span>
<span class="erp-bar-values">
<span>{{ __('admin.erp_center.analytics.workload.total',['count'=>$row['total']]) }}</span>
<span>{{ __('admin.erp_center.analytics.workload.exceptions',['count'=>$row['exceptions']]) }}</span>
</span>
</a>
@endforeach
</div>
<details class="erp-table-details">
<summary>{{ __('admin.erp_center.analytics.table_summary') }}</summary>
<div class="foodex-module-chart-table-wrap">
<table class="foodex-module-chart-table erp-table" data-pagination-exempt="bounded-authorized-module-summary">
<thead><tr><th>{{ __('enterprise_my_work.filters.module') }}</th><th>{{ __('enterprise_my_work.summary.total') }}</th><th>{{ __('enterprise_my_work.summary.attention') }}</th></tr></thead>
<tbody>
@foreach($workload as $row)
<tr><td><a href="{{ $row['url'] }}">{{ $row['label'] }}</a></td><td>{{ $row['total'] }}</td><td>{{ $row['exceptions'] }}</td></tr>
@endforeach
</tbody>
</table>
</div>
</details>
@endif
</article>

<article class="foodex-viz-card" data-erp-chart="states">
<div class="foodex-viz-header">
<div>
<h2 class="foodex-viz-title">{{ __('admin.erp_center.analytics.states.title') }}</h2>
<p class="foodex-viz-subtitle">{{ __('admin.erp_center.analytics.states.description') }}</p>
</div>
</div>
<div class="erp-state-grid">
@foreach($summary as $state => $count)
<a
    class="erp-state-link {{ $selectedStatus === $state ? 'is-active' : '' }}"
    href="{{ route('admin.erp.index',['status'=>$state,'tab'=>'modules']) }}#erp-modules"
    data-erp-state="{{ $state }}"
>
<span>{{ __('admin.erp_center.statuses.'.$state) }}</span>
<strong>{{ $count }}</strong>
</a>
@endforeach
</div>
</article>
</section>
@endif

@if($activeErpTab === 'follow_up')
<section class="erp-preview-grid" aria-labelledby="erp-tab-follow_up" data-erp-panel="follow_up">
<article class="foodex-card erp-section" data-erp-my-work>
<div class="erp-section-head">
<div>
<h2>{{ __('admin.erp_center.follow_up.my_work_title') }}</h2>
<p class="erp-subtitle">{{ __('admin.erp_center.follow_up.my_work_description') }}</p>
</div>
<a class="foodex-action-secondary button secondary" href="{{ route('admin.action-center.index') }}">{{ __('admin.erp_center.follow_up.open_all') }}</a>
</div>
@if($myWorkItems->isEmpty())
<div class="foodex-empty-state">{{ __('enterprise_my_work.empty') }}</div>
@else
<div class="erp-list">
@foreach($myWorkItems as $item)
<div class="erp-list-row">
<div>
<strong>{{ $item['title'] }} · <span class="foodex-number">{{ $item['reference'] }}</span></strong>
<div class="erp-list-meta">
<span>{{ __('enterprise_my_work.modules.'.$item['module']) }}</span>
<span>{{ __('enterprise_my_work.states.'.$item['state']) }}</span>
<span>{{ $item['due_at'] ? $item['due_at']->format('Y-m-d') : __('enterprise_my_work.no_due') }}</span>
</div>
</div>
<a class="foodex-action-secondary button secondary" href="{{ $item['deep_link'] }}">{{ __('enterprise_my_work.open') }}</a>
</div>
@endforeach
</div>
@endif
</article>

<article class="foodex-card erp-section" data-erp-activity>
<div class="erp-section-head">
<div>
<h2>{{ __('admin.erp_center.follow_up.activity_title') }}</h2>
<p class="erp-subtitle">{{ __('admin.erp_center.follow_up.activity_description') }}</p>
</div>
</div>
@if($activity->isEmpty())
<div class="foodex-empty-state">{{ __('admin.erp_center.follow_up.activity_empty') }}</div>
@else
<div class="erp-list">
@foreach($activity as $item)
<a class="erp-list-row" href="{{ $item['deep_link'] }}" style="color:inherit;text-decoration:none">
<div>
<strong>{{ $item['title'] }} · <span class="foodex-number">{{ $item['reference'] }}</span></strong>
<div class="erp-list-meta">
<span>{{ __('enterprise_my_work.modules.'.$item['module']) }}</span>
<span>{{ __('enterprise_my_work.states.'.$item['state']) }}</span>
@if($item['updated_at'])<span>{{ $item['updated_at']->format('Y-m-d H:i') }}</span>@endif
</div>
</div>
<span aria-hidden="true">›</span>
</a>
@endforeach
</div>
@endif
</article>
</section>
@endif

@if($activeErpTab === 'modules')
<section class="erp-section" aria-labelledby="erp-tab-modules" id="erp-modules" data-erp-panel="modules">
<div class="erp-section-head">
<div>
<h2 id="erp-modules-title">{{ __('admin.erp_center.modules_title') }}</h2>
<p class="erp-subtitle">{{ __('admin.erp_center.modules_description') }}</p>
</div>
<div class="erp-filter-rail" aria-label="{{ __('admin.erp_center.filters.label') }}">
<a class="erp-filter {{ $selectedStatus === '' ? 'is-active' : '' }}" href="{{ route('admin.erp.index',['tab'=>'modules']) }}#erp-modules">{{ __('admin.erp_center.filters.all') }} <span class="foodex-number">{{ count($cards) }}</span></a>
@foreach($summary as $state => $count)
@if($count > 0)
<a class="erp-filter {{ $selectedStatus === $state ? 'is-active' : '' }}" href="{{ route('admin.erp.index',['status'=>$state,'tab'=>'modules']) }}#erp-modules">{{ __('admin.erp_center.statuses.'.$state) }} <span class="foodex-number">{{ $count }}</span></a>
@endif
@endforeach
</div>
</div>

@if($visibleCards === [])
<div class="foodex-empty-state" role="status">{{ __('admin.erp_center.empty_filtered') }}</div>
@else
@php
$activeErpModuleKey = collect($visibleCards)->contains(fn ($moduleCard) => $moduleCard['key'] === $selectedModule)
    ? $selectedModule
    : $visibleCards[0]['key'];
@endphp
<div data-erp-module-tabs>
<div class="erp-module-tablist" role="tablist" aria-label="{{ __('admin.erp_center.modules_title') }}" aria-orientation="horizontal">
@foreach($visibleCards as $card)
<button
    class="erp-module-tab"
    id="erp-module-tab-{{ $card['key'] }}"
    type="button"
    role="tab"
    aria-controls="erp-module-panel-{{ $card['key'] }}"
    aria-selected="{{ $activeErpModuleKey === $card['key'] ? 'true' : 'false' }}"
    tabindex="{{ $activeErpModuleKey === $card['key'] ? '0' : '-1' }}"
    data-erp-module-tab="{{ $card['key'] }}"
>
<span>{{ $card['title'] }}</span>
<span class="erp-module-tab-status">{{ __('admin.erp_center.statuses.'.$card['status']) }}</span>
</button>
@endforeach
</div>
<div class="erp-module-panels" data-erp-modules>
@foreach($visibleCards as $card)
@php
$readinessModuleCode = match ($card['key']) {
    'wms' => 'inventory_wms',
    'bi' => 'enterprise_bi',
    'procurement', 'finance', 'treasury' => $card['key'],
    default => null,
};
$readinessManifestVersion = $readinessModuleCode
    ? config('enterprise_modules.modules.'.$readinessModuleCode.'.version')
    : null;
$readinessCertifiedVersion = $readinessModuleCode
    ? config('enterprise_module_readiness.certified_versions.'.$readinessModuleCode)
    : null;
@endphp
<article class="foodex-card erp-module {{ $activeErpModuleKey === $card['key'] ? 'is-selected' : '' }}" id="erp-module-panel-{{ $card['key'] }}" role="tabpanel" aria-labelledby="erp-module-tab-{{ $card['key'] }}" tabindex="0" @if($activeErpModuleKey !== $card['key']) hidden @endif data-erp-key="{{ $card['key'] }}" data-erp-status="{{ $card['status'] }}"> {{-- localization-gate: allow — data attributes are deterministic non-user-facing state tokens; visible state labels are translated. --}}
<div>
<h3>{{ $card['title'] }}</h3>
<p>{{ $card['description'] }}</p>
</div>
@if($card['health'])
<span class="erp-health">{{ __('admin.erp_center.health_statuses.'.$card['health']) }}</span>
@endif
<div class="erp-module-footer">
<span class="erp-status" data-state="{{ $card['status'] }}">{{ __('admin.erp_center.statuses.'.$card['status']) }}</span>
@if($card['url'])
<a class="foodex-action-primary" href="{{ $card['url'] }}">{{ __('admin.erp_center.open') }}</a>
@else
{{-- Degraded modules are not operational; retain the localized denial reason. --}}
<span class="erp-note" role="status">{{ $card['denial_reason']
    ? __('admin.erp_center.denial_reasons.'.$card['denial_reason'])
    : __('admin.erp_center.status_help.'.$card['status']) }}</span>
@endif
</div>
@if($card['status'] === 'degraded' || in_array($card['key'], ['treasury','procurement','wms','finance','bi'], true))
@php
$treasuryReadinessComplete = $card['key'] === 'treasury' && collect($treasuryReadinessSteps)->every(fn ($step) => $step['complete']);
$moduleReadinessSteps = $moduleReadinessReports[$card['key']] ?? [];
$moduleReadinessComplete = $moduleReadinessSteps !== [] && collect($moduleReadinessSteps)->every(fn ($step) => $step['complete']);
$readinessComplete = $card['key'] === 'treasury' ? $treasuryReadinessComplete : $moduleReadinessComplete;
@endphp
<aside class="erp-readiness-report" aria-label="{{ __('enterprise_modules.readiness') }}" data-erp-readiness-report data-complete="{{ $readinessComplete ? 'true' : 'false' }}">
<strong>{{ __('enterprise_modules.readiness') }}</strong>
@if($card['key'] === 'treasury')
<strong>{{ $treasuryReadinessComplete
    ? __('admin.erp_center.treasury_readiness.ready')
    : __('admin.erp_center.treasury_readiness.summary', ['complete' => collect($treasuryReadinessSteps)->where('complete', true)->count(), 'total' => count($treasuryReadinessSteps)]) }}</strong>
<ul class="erp-readiness-steps">
@foreach($treasuryReadinessSteps as $step)
<li class="erp-readiness-step" data-complete="{{ $step['complete'] ? 'true' : 'false' }}" data-step="{{ $step['key'] }}">
<span class="erp-readiness-step-state">{{ $step['complete'] ? __('admin.erp_center.treasury_readiness.complete') : __('admin.erp_center.treasury_readiness.incomplete') }}</span>
<span><strong>{{ __('admin.erp_center.treasury_readiness.steps.'.$step['key'].'.title') }}</strong><br>{{ $step['detail'] }}</span>
@if($step['url'])<a href="{{ $step['url'] }}">{{ __('admin.erp_center.treasury_readiness.open_step') }}</a>@endif
</li>
@endforeach
</ul>
@elseif($moduleReadinessSteps !== [])
<strong>{{ $moduleReadinessComplete
    ? __('admin.erp_center.module_readiness.complete')
    : __('admin.erp_center.module_readiness.summary', ['complete' => collect($moduleReadinessSteps)->where('complete', true)->count(), 'total' => count($moduleReadinessSteps)]) }}</strong>
<ul class="erp-readiness-steps">
@foreach($moduleReadinessSteps as $step)
<li class="erp-readiness-step" data-complete="{{ $step['complete'] ? 'true' : 'false' }}" data-step="{{ $step['key'] }}">
<span class="erp-readiness-step-state">{{ $step['complete'] ? __('admin.erp_center.module_readiness.done') : __('admin.erp_center.module_readiness.missing') }}</span>
<span><strong>{{ $step['title'] }}</strong><br>{{ $step['detail'] }}</span>
@if($step['url'])<a href="{{ $step['url'] }}">{{ __('admin.erp_center.module_readiness.open_step') }}</a>@endif
</li>
@endforeach
</ul>
@else
<ul>
@if($card['denial_reason'] === 'uncertified' && $readinessModuleCode && is_int($readinessManifestVersion))
<li>{{ __('enterprise_modules.readiness_report.missing_version_certification', [
    'current' => __('enterprise_modules.readiness_report.version_value', ['version' => $readinessManifestVersion]),
    'certified' => is_int($readinessCertifiedVersion)
        ? __('enterprise_modules.readiness_report.version_value', ['version' => $readinessCertifiedVersion])
        : __('enterprise_modules.readiness_report.no_certified_version'),
]) }}</li>
<li>{{ __('enterprise_modules.readiness_report.certification_requirement') }}</li>
@else
<li>{{ $card['denial_reason']
    ? __('admin.erp_center.denial_reasons.'.$card['denial_reason'])
    : __('admin.erp_center.status_help.'.$card['status']) }}</li>
@endif
@if($card['health'] && $card['health'] !== 'healthy')
<li>{{ __('admin.erp_center.health_statuses.'.$card['health']) }}</li>
@endif
</ul>
@endif
</aside>
@endif
</article>
@endforeach
</div>
</div>
@endif
</section>
@endif
</div>
</main>
</div>
<script>
document.querySelectorAll('[data-erp-module-tabs]').forEach((root)=>{
    const tabs=[...root.querySelectorAll('[role="tab"]')];
    const activate=(tab,moveFocus=false)=>{
        tabs.forEach((item)=>{
            const selected=item===tab;
            item.setAttribute('aria-selected',selected?'true':'false');
            item.tabIndex=selected?0:-1;
            const panel=root.querySelector(`#${CSS.escape(item.getAttribute('aria-controls'))}`);
            if(panel) panel.hidden=!selected;
        });
        tab.focus({preventScroll:!moveFocus});
        const url=new URL(window.location.href);
        url.searchParams.set('module',tab.dataset.erpModuleTab);
        window.history.replaceState(null,'',url);
    };
    tabs.forEach((tab,index)=>{
        tab.addEventListener('click',()=>activate(tab));
        tab.addEventListener('keydown',(event)=>{
            const rtl=getComputedStyle(root).direction==='rtl';
            const forward=rtl?'ArrowLeft':'ArrowRight';
            const backward=rtl?'ArrowRight':'ArrowLeft';
            let target=null;
            if(event.key===forward) target=tabs[(index+1)%tabs.length];
            else if(event.key===backward) target=tabs[(index-1+tabs.length)%tabs.length];
            else if(event.key==='Home') target=tabs[0];
            else if(event.key==='End') target=tabs[tabs.length-1];
            if(target){event.preventDefault();activate(target,true);}
        });
    });
});
</script>
</body>
</html>
