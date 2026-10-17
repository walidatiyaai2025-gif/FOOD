<!doctype html>
<html lang="{{ str_replace('_','-',app()->getLocale()) }}" dir="{{ app()->getLocale()==='ar' ? 'rtl' : 'ltr' }}">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>{{ __('enterprise_health.title') }} · FOODEX</title>
@include('admin._brand-components')
<style>
body{margin:0;background:var(--foodex-background);color:var(--foodex-ink)}.health-page{padding:clamp(18px,2.5vw,36px)}.health-head{display:flex;justify-content:space-between;gap:18px;align-items:flex-start;margin-block:18px 24px}.health-head h1{margin:4px 0 8px;font-size:clamp(1.55rem,2.5vw,2.2rem)}.health-head p{margin:0;color:var(--foodex-muted);max-width:780px;line-height:1.55}.health-actions{display:flex;gap:8px;flex-wrap:wrap}.health-link{display:inline-flex;align-items:center;justify-content:center;min-height:40px;padding-inline:13px;border:1px solid var(--foodex-border);border-radius:10px;background:#fff;color:var(--foodex-green-dark);text-decoration:none;font-weight:800}.health-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px}.health-card{background:#fff;border:1px solid var(--foodex-border);border-radius:var(--foodex-radius-card);padding:18px;box-shadow:var(--foodex-shadow-sm)}.health-card__head{display:flex;justify-content:space-between;gap:12px;align-items:flex-start}.health-card h2,.health-card h3{margin:0}.health-badge{display:inline-flex;min-height:30px;align-items:center;padding-inline:10px;border-radius:999px;background:var(--foodex-green-soft);color:var(--foodex-green-dark);font-weight:900;font-size:.84rem}.health-meta{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:8px;margin-top:14px}.health-meta>div,.signal{padding:11px;border:1px solid var(--foodex-border);border-radius:10px;background:var(--foodex-background)}.health-meta span,.signal span{display:block;color:var(--foodex-muted);font-size:.82rem;margin-bottom:4px}.dep-list{display:flex;flex-wrap:wrap;gap:7px;margin-top:12px}.dep{display:inline-flex;min-height:30px;align-items:center;padding-inline:9px;border:1px solid var(--foodex-border);border-radius:999px}.signal-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:14px;margin:16px 0 24px}.counts{display:flex;flex-wrap:wrap;gap:7px;margin-top:8px}.count{display:inline-flex;gap:5px;align-items:center;padding:7px 9px;background:#fff;border:1px solid var(--foodex-border);border-radius:999px}.health-stale{font-weight:900;color:var(--foodex-orange)}
@media(max-width:900px){.health-grid,.signal-grid{grid-template-columns:1fr}.health-meta{grid-template-columns:1fr}.health-head{flex-direction:column}.health-actions,.health-link{width:100%}}
</style></head><body>
<div class="foodex-admin-layout" data-foodex-mobile-web-page="enterprise-system-health">
<aside class="sidebar">@include('admin._sidebar',['navGroups'=>$navGroups,'navContext'=>$navContext,'user'=>$user])</aside> {{-- localization-gate: allow Blade include expression --}}
<main class="foodex-admin-main foodex-admin-page health-page" data-enterprise-system-health="true">
@include('admin._global-header')
@include('admin._admin-center-switcher',['active'=>'administration'])
<header class="health-head foodex-page-toolbar"><div><div class="foodex-subtitle">FOODEX · {{ __('enterprise_health.eyebrow') }}</div><h1>{{ __('enterprise_health.title') }}</h1><p>{{ __('enterprise_health.description') }}</p></div><div class="health-actions"><a class="health-link" href="{{ route('admin.inspector.index') }}">{{ __('enterprise_health.inspector') }}</a><a class="health-link" href="{{ route('admin.administration.index') }}">{{ __('enterprise_health.back') }}</a></div></header>

<h2>{{ __('enterprise_health.system_signals') }}</h2>
<div class="signal-grid">
<section class="signal"><span>{{ __('enterprise_health.outbox') }}</span>@if($outboxCounts===[])<strong>{{ __('enterprise_health.no_activity') }}</strong>@else<div class="counts">@foreach($outboxCounts as $status=>$count)<span class="count">{{ \Illuminate\Support\Str::headline($status) }} · {{ $count }}</span>@endforeach</div>@endif</section>
<section class="signal"><span>{{ __('enterprise_health.workflow') }}</span>@if($workflowCounts===[])<strong>{{ __('enterprise_health.no_activity') }}</strong>@else<div class="counts">@foreach($workflowCounts as $status=>$count)<span class="count">{{ \Illuminate\Support\Str::headline($status) }} · {{ $count }}</span>@endforeach</div>@endif</section>
<section class="signal"><span>{{ __('enterprise_health.queue') }}</span><strong>{{ \Illuminate\Support\Str::headline($queueDriver !== '' ? $queueDriver : __('enterprise_health.none')) }}</strong></section>
</div>

<h2>{{ __('enterprise_health.modules') }}</h2>
@if($modules->isEmpty())<p>{{ __('enterprise_health.none') }}</p>@else
<div class="health-grid">@foreach($modules as $module)<article class="health-card">
<div class="health-card__head"><div><h3>{{ $module['name'] }}</h3><div class="foodex-subtitle">{{ $module['category'] }}</div></div><span class="health-badge">{{ __('enterprise_health.health_states.'.$module['health']) }}</span></div>
<div class="health-meta"><div><span>{{ __('enterprise_health.lifecycle') }}</span><strong>{{ __('enterprise_health.states.'.$module['lifecycle']) }}</strong></div><div><span>{{ __('enterprise_health.checks') }}</span><strong>{{ $module['check_count'] }}</strong></div><div><span>{{ __('enterprise_health.stale') }}</span><strong class="{{ $module['stale_count'] > 0 ? 'health-stale' : '' }}">{{ $module['stale_count'] }}</strong></div></div>
<div class="dep-list">@if($module['dependencies']===[])<span class="dep">{{ __('enterprise_health.dependencies') }} · {{ __('enterprise_health.none') }}</span>@else @foreach($module['dependencies'] as $dependency)<span class="dep">{{ $dependency['name'] }} · {{ __('enterprise_health.states.'.$dependency['state']) }}</span>@endforeach @endif</div>
</article>@endforeach</div>
@endif
</main></div></body></html>
