<!doctype html>
<html lang="{{ str_replace('_','-',app()->getLocale()) }}" dir="{{ app()->getLocale()==='ar' ? 'rtl' : 'ltr' }}">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>{{ __('enterprise_workspaces.title') }} · FOODEX</title>
@include('admin._brand-components')
<style>
body{margin:0;background:var(--foodex-background);color:var(--foodex-ink)}
.workspace-page{padding:clamp(18px,2.5vw,36px)}
.workspace-head{display:flex;justify-content:space-between;gap:18px;align-items:flex-start;margin-block:18px 24px}
.workspace-head h1{margin:4px 0 8px;font-size:clamp(1.55rem,2.5vw,2.2rem)}
.workspace-head p{margin:0;color:var(--foodex-muted);max-width:820px;line-height:1.55}
.workspace-tabs{display:flex;flex-wrap:wrap;gap:8px;margin-bottom:20px}
.workspace-tab{display:inline-flex;align-items:center;justify-content:center;min-height:42px;padding-inline:14px;border:1px solid var(--foodex-border);border-radius:10px;background:var(--foodex-surface);color:var(--foodex-ink);text-decoration:none;font-weight:800}
.workspace-tab.is-active{background:var(--foodex-action-green);border-color:var(--foodex-action-green);color:#fff}
.workspace-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:14px}
.workspace-card{display:flex;flex-direction:column;gap:12px;min-height:190px;padding:18px;background:var(--foodex-surface);border:1px solid var(--foodex-border);border-radius:var(--foodex-radius-card);box-shadow:var(--foodex-shadow-sm)}
.workspace-card h2{margin:0;font-size:1.08rem}.workspace-card p{margin:0;color:var(--foodex-muted);line-height:1.55}
.workspace-open{margin-top:auto;display:inline-flex;align-items:center;justify-content:center;min-height:42px;padding-inline:13px;border-radius:10px;background:var(--foodex-action-green);color:#fff;text-decoration:none;font-weight:900}
.workspace-empty{padding:28px;border:1px dashed var(--foodex-border);border-radius:var(--foodex-radius-card);background:var(--foodex-surface);color:var(--foodex-muted);text-align:center}
@media(max-width:980px){.workspace-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media(max-width:680px){.workspace-page{padding:14px}.workspace-head{flex-direction:column}.workspace-tabs{display:grid;grid-template-columns:1fr 1fr}.workspace-tab{width:100%}.workspace-grid{grid-template-columns:1fr}.workspace-card{min-height:0}}
</style>
</head><body>
<div class="foodex-admin-layout" data-foodex-mobile-web-page="enterprise-workspaces">
<aside class="sidebar">@include('admin._sidebar',['navGroups'=>$navGroups,'navContext'=>$navContext,'user'=>$user])</aside> {{-- localization-gate: allow Blade include expression --}}
<main class="foodex-admin-main foodex-admin-page workspace-page" data-enterprise-workspaces="true">
@include('admin._global-header')
<div class="workspace-head foodex-page-toolbar">
<div><div class="foodex-subtitle">FOODEX · {{ __('enterprise_workspaces.eyebrow') }}</div><h1>{{ __('enterprise_workspaces.title') }}</h1><p>{{ __('enterprise_workspaces.description') }}</p></div>
</div>
<nav class="workspace-tabs" aria-label="{{ __('enterprise_workspaces.title') }}">
@foreach($workspace['available'] as $key)
<a class="workspace-tab {{ $workspace['key']===$key ? 'is-active' : '' }}" href="{{ route('admin.enterprise-workspaces.index',['workspace'=>$key]) }}" @if($workspace['key']===$key) aria-current="page" @endif>{{ __('enterprise_workspaces.workspaces.'.$key) }}</a>
@endforeach
</nav>
@if($workspace['cards']===[])
<div class="workspace-empty foodex-empty-state">{{ __('enterprise_workspaces.empty') }}</div>
@else
<section class="workspace-grid">
@foreach($workspace['cards'] as $card)
<article class="workspace-card foodex-card" data-workspace-card="{{ $card['key'] }}">
<h2>{{ __($card['label']) }}</h2>
<p>{{ __($card['description']) }}</p>
<a class="workspace-open" href="{{ $card['url'] }}">{{ __('enterprise_workspaces.open') }}</a>
</article>
@endforeach
</section>
@endif
</main></div>
</body></html>
