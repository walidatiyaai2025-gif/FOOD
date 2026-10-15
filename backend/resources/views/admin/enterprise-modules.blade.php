<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>{{ __('enterprise_modules.title') }} · FOODEX</title>
@include('admin._brand-components')
<style>
body{margin:0;background:var(--foodex-background);color:var(--foodex-ink)}
.module-page{padding:clamp(18px,2.5vw,36px)}
.module-header{display:flex;justify-content:space-between;gap:18px;align-items:flex-start;margin-block:18px 24px}
.module-header h1{margin:4px 0 8px;font-size:clamp(1.55rem,2.5vw,2.2rem)}
.module-header p{margin:0;color:var(--foodex-muted);max-width:760px;line-height:1.55}
.module-back{display:inline-flex;min-height:42px;align-items:center;padding-inline:14px;border:1px solid var(--foodex-border);border-radius:10px;background:#fff;color:var(--foodex-green-dark);text-decoration:none;font-weight:800}
.module-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px}
.module-card{background:#fff;border:1px solid var(--foodex-border);border-radius:var(--foodex-radius-card);padding:18px;box-shadow:var(--foodex-shadow-sm)}
.module-card h2,.module-card h3{margin:0}
.module-card__head{display:flex;justify-content:space-between;gap:12px;align-items:flex-start}
.module-state{display:inline-flex;align-items:center;min-height:30px;padding-inline:10px;border-radius:999px;background:var(--foodex-green-soft);color:var(--foodex-green-dark);font-weight:900;font-size:.85rem}
.module-meta{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px;margin-top:16px}
.module-meta>div,.module-block{padding:12px;border:1px solid var(--foodex-border);border-radius:10px;background:var(--foodex-background)}
.module-meta span,.module-block>span{display:block;color:var(--foodex-muted);font-size:.82rem;margin-bottom:5px}
.module-list{margin:6px 0 0;padding-inline-start:20px}
.module-entitlements{display:flex;flex-wrap:wrap;gap:7px;margin-top:7px}
.module-chip{display:inline-flex;align-items:center;min-height:30px;padding-inline:9px;border:1px solid var(--foodex-border);border-radius:999px;background:#fff;font-size:.84rem}
.module-actions{display:flex;flex-wrap:wrap;gap:8px;margin-top:14px}
.module-actions form{margin:0}
.module-action,.module-open{display:inline-flex;align-items:center;justify-content:center;min-height:40px;padding-inline:13px;border-radius:10px;border:1px solid var(--foodex-green);background:var(--foodex-green);color:#fff;text-decoration:none;font:inherit;font-weight:800;cursor:pointer}
.module-action.secondary,.module-open{background:#fff;color:var(--foodex-green-dark)}
.module-selected{margin-bottom:18px;border:2px solid var(--foodex-green)}
.module-state-message{margin-bottom:14px;padding:12px;border-radius:10px;background:#fff;border:1px solid var(--foodex-border)}
.module-empty{padding:24px;background:#fff;border:1px dashed var(--foodex-border);border-radius:12px;color:var(--foodex-muted)}
@media(max-width:900px){.module-grid{grid-template-columns:1fr}.module-meta{grid-template-columns:1fr}.module-header{flex-direction:column}.module-back{width:100%;justify-content:center}}
</style>
</head>
<body>
<div class="foodex-admin-layout" data-foodex-mobile-web-page="enterprise-modules">
<aside class="sidebar">@include('admin._sidebar', ['navGroups' => $navGroups, 'navContext' => $navContext, 'user' => $user])</aside> {{-- localization-gate: allow Blade include expression --}}
<main class="foodex-admin-main foodex-admin-page module-page" data-enterprise-module-control-plane="true">
@include('admin._admin-center-switcher',['active'=>'administration'])
<header class="module-header">
<div>
<div class="foodex-subtitle">FOODEX · {{ __('enterprise_modules.eyebrow') }}</div>
<h1>{{ __('enterprise_modules.title') }}</h1>
<p>{{ __('enterprise_modules.description') }}</p>
</div>
<a class="module-back" href="{{ route('admin.administration.index') }}">{{ __('enterprise_modules.back') }}</a>
</header>

@if (session('status')) <div class="module-state-message" role="status">{{ session('status') }}</div> @endif
@if ($errors->any()) <div class="module-state-message" role="alert"><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div> @endif

@if($selected)
<section class="module-card module-selected" aria-labelledby="selected-module-title">
<div class="module-card__head">
<div><div class="foodex-subtitle">{{ __('enterprise_modules.details') }}</div><h2 id="selected-module-title">{{ $selected['name'] }}</h2></div>
<span class="module-state">{{ __('enterprise_modules.states.'.$selected['state']) }}</span>
</div>
<div class="module-meta">
<div><span>{{ __('enterprise_modules.category') }}</span><strong>{{ $selected['category'] }}</strong></div>
<div><span>{{ __('enterprise_modules.manifest_version') }}</span><strong>{{ $selected['manifest_version'] }}</strong></div>
<div><span>{{ __('enterprise_modules.capabilities') }} / {{ __('enterprise_modules.permissions') }}</span><strong>{{ $selected['capability_count'] }} / {{ $selected['permission_count'] }}</strong></div>
</div>
<div class="module-grid" style="margin-top:12px">
<div class="module-block"><span>{{ __('enterprise_modules.dependencies') }}</span>
@if($selected['dependencies'] === []) <strong>{{ __('enterprise_modules.none') }}</strong>
@else <ul class="module-list">@foreach($selected['dependencies'] as $dependency)<li>{{ $dependency }}</li>@endforeach</ul>@endif
</div>
<div class="module-block"><span>{{ __('enterprise_modules.integrations') }}</span>
@if($selected['optional_integrations'] === []) <strong>{{ __('enterprise_modules.none') }}</strong>
@else <ul class="module-list">@foreach($selected['optional_integrations'] as $integration)<li>{{ $integration }}</li>@endforeach</ul>@endif
</div>
</div>
<div class="module-block" style="margin-top:12px"><span>{{ __('enterprise_modules.entitlements') }}</span>
@if($selected['entitlements'] === []) <strong>{{ __('enterprise_modules.none') }}</strong>
@else <div class="module-entitlements">@foreach($selected['entitlements'] as $entitlement)
<span class="module-chip">{{ __("enterprise_modules.scope_types.{$entitlement['scope_type']}") }} · {{ $entitlement['enabled'] ? __('enterprise_modules.enabled') : __('enterprise_modules.denied') }} · {{ $entitlement['count'] }}</span>
@endforeach</div>@endif
</div>
@if($selected['actions'] !== [])
<div class="module-actions" aria-label="{{ __('enterprise_modules.actions') }}">
@foreach($selected['actions'] as $action)
<form method="post" action="{{ route('admin.enterprise-modules.lifecycle', ['module'=>$selected['code']]) }}">
@csrf
<input type="hidden" name="action" value="{{ $action }}">
<button class="module-action {{ $action === 'enable' ? '' : 'secondary' }}" type="submit">{{ __('enterprise_modules.action.'.$action) }}</button>
</form>
@endforeach
</div>
@endif
</section>
@endif

<h2>{{ __('enterprise_modules.all_modules') }}</h2>
@if($modules->isEmpty())
<p class="module-empty">{{ __('enterprise_modules.empty') }}</p>
@else
<div class="module-grid">
@foreach($modules as $module)
<article class="module-card">
<div class="module-card__head">
<div><h3>{{ $module['name'] }}</h3><div class="foodex-subtitle">{{ $module['category'] }}</div></div>
<span class="module-state">{{ __('enterprise_modules.states.'.$module['state']) }}</span>
</div>
<div class="module-meta">
<div><span>{{ __('enterprise_modules.dependencies') }}</span><strong>{{ count($module['dependencies']) }}</strong></div>
<div><span>{{ __('enterprise_modules.entitlements') }}</span><strong>{{ collect($module['entitlements'])->sum('count') }}</strong></div>
<div><span>{{ __('enterprise_modules.capabilities') }}</span><strong>{{ $module['capability_count'] }}</strong></div>
</div>
<div class="module-actions">
<a class="module-open" href="{{ route('admin.enterprise-modules.show', ['module'=>$module['code']]) }}">{{ __('enterprise_modules.open') }}</a>
</div>
</article>
@endforeach
</div>
@endif
</main>
</div>
</body>
</html>
