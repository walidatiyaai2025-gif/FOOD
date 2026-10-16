<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>{{ __('admin.erp_center.title') }} · FOODEX</title>
@include('admin._brand-components')
<style>
body{margin:0}
.erp-center{min-width:0}
.erp-eyebrow{margin:0;color:var(--foodex-green-dark);font-weight:800}
.erp-subtitle{margin:.35rem 0 0;color:var(--foodex-muted);max-width:72ch}
.erp-summary{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:var(--foodex-space-3);margin-block-end:var(--foodex-space-6)}
.erp-summary .foodex-card{padding:var(--foodex-space-4)}
.erp-summary strong{display:block;font-size:var(--foodex-text-xl);margin-block-start:var(--foodex-space-1)}
.erp-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,260px),1fr));gap:var(--foodex-space-4)}
.erp-module{padding:var(--foodex-space-4);display:flex;flex-direction:column;gap:var(--foodex-space-3);min-width:0}
.erp-module h2{margin:0;font-size:var(--foodex-text-lg)}
.erp-module p{margin:0;color:var(--foodex-muted);line-height:var(--foodex-leading-normal)}
.erp-module.is-selected{outline:2px solid var(--foodex-green);outline-offset:2px}
.erp-module-footer{display:flex;align-items:center;justify-content:space-between;gap:var(--foodex-space-3);margin-block-start:auto;flex-wrap:wrap}
.erp-status{display:inline-flex;align-items:center;min-height:32px;padding-inline:var(--foodex-space-3);border-radius:999px;font-weight:800;font-size:var(--foodex-text-sm)}
.erp-status[data-state="available"]{background:var(--foodex-green-soft);color:var(--foodex-green-dark)}
.erp-status[data-state="disabled"],.erp-status[data-state="not_entitled"]{background:var(--foodex-background);color:var(--foodex-muted);border:1px solid var(--foodex-border)}
.erp-note{font-size:var(--foodex-text-sm);color:var(--foodex-muted)}
@media(max-width:767px){.erp-summary{grid-template-columns:1fr}.erp-module-footer{align-items:stretch}.erp-module-footer .foodex-action-primary{width:100%;justify-content:center}}
</style>
</head>
<body>
<div class="foodex-admin-layout" data-foodex-mobile-web-page="erp-center" data-erp-center="true">
<aside class="sidebar">@include('admin._sidebar',['navGroups'=>$navGroups,'navContext'=>$navContext,'user'=>$user])</aside>
<main class="foodex-admin-main foodex-admin-page erp-center">
<header class="foodex-page-header">
<div>
<p class="erp-eyebrow">{{ __('admin.erp_center.eyebrow') }}</p>
<h1>{{ __('admin.erp_center.title') }}</h1>
<p class="erp-subtitle">{{ __('admin.erp_center.description') }}</p>
</div>
@include('admin._live-notifications',['user'=>$user])
</header>

<section class="erp-summary" aria-label="{{ __('admin.erp_center.summary.label') }}" data-erp-summary>
<article class="foodex-card">
<span>{{ __('admin.erp_center.summary.available') }}</span>
<strong>{{ $summary['available'] }}</strong>
</article>
<article class="foodex-card">
<span>{{ __('admin.erp_center.summary.disabled') }}</span>
<strong>{{ $summary['disabled'] }}</strong>
</article>
<article class="foodex-card">
<span>{{ __('admin.erp_center.summary.restricted') }}</span>
<strong>{{ $summary['restricted'] }}</strong>
</article>
</section>

<section aria-labelledby="erp-modules-title">
<div class="foodex-page-header">
<div>
<h2 id="erp-modules-title">{{ __('admin.erp_center.modules_title') }}</h2>
<p class="erp-subtitle">{{ __('admin.erp_center.modules_description') }}</p>
</div>
</div>

@if($cards === [])
<div class="foodex-empty-state" role="status">{{ __('admin.erp_center.empty') }}</div>
@else
<div class="erp-grid" data-erp-modules>
@foreach($cards as $card)
<article class="foodex-card erp-module {{ $selectedModule === $card['key'] ? 'is-selected' : '' }}" data-erp-key="{{ $card['key'] }}" data-erp-status="{{ $card['status'] }}"> {{-- localization-gate: allow — data-erp-status is a non-user-facing deterministic state token; visible status text is translated below. --}}
<div>
<h2>{{ $card['title'] }}</h2>
<p>{{ $card['description'] }}</p>
</div>
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
</main>
</div>
</body>
</html>
