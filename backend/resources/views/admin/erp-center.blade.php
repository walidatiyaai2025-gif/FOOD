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
.erp-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,260px),1fr));gap:var(--foodex-space-4)}
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
<header class="foodex-page-toolbar">
<div>
<p class="erp-eyebrow">{{ __('admin.erp_center.eyebrow') }}</p>
<h1>{{ __('admin.erp_center.title') }}</h1>
<p class="erp-subtitle">{{ __('admin.erp_center.description') }}</p>
</div>

</header>

<div class="foodex-module-overview">
<section class="erp-section" aria-labelledby="erp-snapshot-title">
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

<section class="erp-analytics" aria-label="{{ __('admin.erp_center.analytics.label') }}">
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
@php($workMax = max(1, (int) collect($workload)->max('total')))
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
    href="{{ route('admin.erp.index',['status'=>$state]) }}#erp-modules"
    data-erp-state="{{ $state }}"
>
<span>{{ __('admin.erp_center.statuses.'.$state) }}</span>
<strong>{{ $count }}</strong>
</a>
@endforeach
</div>
</article>
</section>

<section class="erp-preview-grid" aria-label="{{ __('admin.erp_center.follow_up.label') }}">
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

<section class="erp-section" aria-labelledby="erp-modules-title" id="erp-modules">
<div class="erp-section-head">
<div>
<h2 id="erp-modules-title">{{ __('admin.erp_center.modules_title') }}</h2>
<p class="erp-subtitle">{{ __('admin.erp_center.modules_description') }}</p>
</div>
<div class="erp-filter-rail" aria-label="{{ __('admin.erp_center.filters.label') }}">
<a class="erp-filter {{ $selectedStatus === '' ? 'is-active' : '' }}" href="{{ route('admin.erp.index') }}#erp-modules">{{ __('admin.erp_center.filters.all') }} <span class="foodex-number">{{ count($cards) }}</span></a>
@foreach($summary as $state => $count)
@if($count > 0)
<a class="erp-filter {{ $selectedStatus === $state ? 'is-active' : '' }}" href="{{ route('admin.erp.index',['status'=>$state]) }}#erp-modules">{{ __('admin.erp_center.statuses.'.$state) }} <span class="foodex-number">{{ $count }}</span></a>
@endif
@endforeach
</div>
</div>

@if($visibleCards === [])
<div class="foodex-empty-state" role="status">{{ __('admin.erp_center.empty_filtered') }}</div>
@else
<div class="erp-grid" data-erp-modules>
@foreach($visibleCards as $card)
<article class="foodex-card erp-module {{ $selectedModule === $card['key'] ? 'is-selected' : '' }}" data-erp-key="{{ $card['key'] }}" data-erp-status="{{ $card['status'] }}"> {{-- localization-gate: allow — data attributes are deterministic non-user-facing state tokens; visible state labels are translated. --}}
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
<span class="erp-note">{{ __('admin.erp_center.status_help.'.$card['status']) }}</span>
@endif
</div>
</article>
@endforeach
</div>
@endif
</section>
</div>
</main>
</div>
</body>
</html>
