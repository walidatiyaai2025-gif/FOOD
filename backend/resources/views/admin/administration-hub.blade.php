<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>{{ __('admin.administration_hub.title') }} · FOODEX</title>
<style>
body{margin:0;background:var(--foodex-page);color:var(--foodex-ink)}
.foodex-admin-layout{display:grid;grid-template-columns:minmax(0,1fr) var(--foodex-sidebar-width);min-height:100vh;width:100%}
html[dir=ltr] .foodex-admin-layout{grid-template-columns:var(--foodex-sidebar-width) minmax(0,1fr)}
.sidebar{grid-column:2;background:#fff;border-inline-start:1px solid var(--foodex-border);padding:18px}
html[dir=ltr] .sidebar{grid-column:1;border-inline-start:0;border-inline-end:1px solid var(--foodex-border)}
.main{grid-column:1;min-width:0;padding:clamp(18px,2.5vw,36px)}
html[dir=ltr] .main{grid-column:2}
.hub-intro{display:flex;justify-content:space-between;gap:18px;align-items:flex-start;margin-bottom:22px}
.hub-intro h1{margin:4px 0 8px;font-size:clamp(1.55rem,2.4vw,2.2rem)}
.eyebrow{margin:0;color:var(--foodex-green-dark);font-weight:800}
.muted{color:var(--foodex-muted);line-height:1.55}
.section{margin-top:22px}
.section-head{display:flex;justify-content:space-between;gap:16px;align-items:end;margin-bottom:12px}
.section-head h2{margin:0}
.grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:14px}
.card{background:#fff;border:1px solid var(--foodex-border);border-radius:var(--foodex-radius-card);padding:18px;box-shadow:var(--foodex-shadow-sm)}
.card h3{margin:0 0 8px}
.app-card{display:flex;flex-direction:column;min-height:176px}
.app-badge{width:44px;height:44px;border-radius:12px;display:grid;place-items:center;background:var(--foodex-green-soft);color:var(--foodex-green-dark);font-weight:900;margin-bottom:14px}
.actions{display:flex;flex-wrap:wrap;gap:8px;margin-top:auto;padding-top:14px}
.action{display:inline-flex;align-items:center;justify-content:center;min-height:40px;padding:0 13px;border-radius:10px;background:var(--foodex-green);color:#fff;text-decoration:none;font-weight:800}
.action.secondary{background:#fff;color:var(--foodex-green-dark);border:1px solid #b9dfc5}
.restricted{display:inline-flex;min-height:40px;align-items:center;padding:0 13px;border-radius:10px;background:#f3f4f6;color:var(--foodex-muted);font-weight:700}
.utility-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px}
.utility-card{display:flex;justify-content:space-between;gap:16px;align-items:center}
.utility-card h3{margin:0 0 6px}
.utility-card .action{flex:0 0 auto}
@media(max-width:1023px){.foodex-admin-layout,html[dir=ltr] .foodex-admin-layout{grid-template-columns:1fr}.sidebar,.main,html[dir=ltr] .sidebar,html[dir=ltr] .main{grid-column:1}.sidebar{grid-row:1;border-inline:0;border-bottom:1px solid var(--foodex-border)}.main{grid-row:2}.grid{grid-template-columns:1fr 1fr}}
@media(max-width:680px){.hub-intro,.section-head,.utility-card{align-items:stretch;flex-direction:column}.grid,.utility-grid{grid-template-columns:1fr}}
</style>
@include('admin._brand-components')
</head>
<body>
<div class="foodex-admin-layout" data-admin-hub="true">
<aside class="sidebar">
@include('admin._sidebar',['navGroups'=>$navGroups,'navContext'=>$navContext,'user'=>$user])
</aside>
<main class="main foodex-admin-main">
<header class="hub-intro foodex-page-header">
<div>
<p class="eyebrow">{{ __('admin.administration_hub.eyebrow') }}</p>
<h1>{{ __('admin.administration_hub.title') }}</h1>
<p class="muted">{{ __('admin.administration_hub.description') }}</p>
</div>
@include('admin._live-notifications',['user'=>$user])
</header>

<section class="section" data-admin-applications>
<div class="section-head">
<div><h2>{{ __('admin.administration_hub.applications') }}</h2><p class="muted">{{ __('admin.administration_hub.applications_description') }}</p></div>
</div>
<div class="grid">
@foreach(['customer','driver','van'] as $app)
<article class="card app-card" data-admin-app="{{ $app }}">
<div class="app-badge">{{ strtoupper(substr($app,0,1)) }}</div>
<h3>{{ __('admin.administration_hub.'.$app) }}</h3>
<p class="muted">{{ __('admin.administration_hub.applications_description') }}</p>
<div class="actions">
@if($canAppPreview)
<a class="action" href="{{ route('admin.app-preview.index', ['application'=>$app]) }}">{{ __('admin.administration_hub.preview') }}</a>
@else
<span class="restricted">{{ __('admin.administration_hub.restricted') }}</span>
@endif
@if($canPlatformManage)
<a class="action secondary" href="{{ route('admin.app-versions.index', ['app'=>$app]) }}">{{ __('admin.app_versions') }}</a>
@endif
</div>
</article>
@endforeach
</div>
</section>

<section class="section">
<div class="utility-grid">
<article class="card utility-card" data-admin-card="users-permissions"><div><h3>{{ __('admin.administration_hub.users_permissions') }}</h3><p class="muted">{{ __('admin.administration_hub.users_permissions_description') }}</p></div>@if($canSecurity)<a class="action" href="{{ route('admin.security.index') }}">{{ __('admin.administration_hub.open') }}</a>@else<span class="restricted">{{ __('admin.administration_hub.restricted') }}</span>@endif</article>
<article class="card utility-card" data-admin-card="system-settings"><div><h3>{{ __('admin.administration_hub.system_settings') }}</h3><p class="muted">{{ __('admin.administration_hub.system_settings_description') }}</p></div>@if($canMobileSettings)<a class="action" href="{{ route('admin.mobile-settings.index') }}">{{ __('admin.administration_hub.open') }}</a>@elseif($canTranslations)<a class="action" href="{{ route('admin.translations.index') }}">{{ __('admin.administration_hub.open') }}</a>@elseif($canAssistantSettings)<a class="action" href="{{ route('admin.assistant-settings.index') }}">{{ __('admin.administration_hub.open') }}</a>@else<span class="restricted">{{ __('admin.administration_hub.restricted') }}</span>@endif</article>
<article class="card utility-card" data-admin-card="notifications"><div><h3>{{ __('admin.administration_hub.notifications') }}</h3><p class="muted">{{ __('admin.administration_hub.notifications_description') }}</p></div>@if($canMobileSettings)<a class="action" href="{{ route('admin.mobile-settings.index') }}#push">{{ __('admin.administration_hub.open') }}</a>@else<span class="restricted">{{ __('admin.administration_hub.restricted') }}</span>@endif</article>
<article class="card utility-card" data-admin-card="publishing"><div><h3>{{ __('admin.administration_hub.publishing') }}</h3><p class="muted">{{ __('admin.administration_hub.publishing_description') }}</p></div>@if($canPlatformManage)<a class="action" href="{{ route('admin.app-versions.index') }}">{{ __('admin.administration_hub.open') }}</a>@elseif($canMobileSettings)<a class="action" href="{{ route('admin.mobile-settings.index') }}#publishing">{{ __('admin.administration_hub.open') }}</a>@else<span class="restricted">{{ __('admin.administration_hub.restricted') }}</span>@endif</article>
<article class="card utility-card" data-admin-card="integrations"><div><h3>{{ __('admin.administration_hub.integrations') }}</h3><p class="muted">{{ __('admin.administration_hub.integrations_description') }}</p></div>@if($canPlatformManage)<a class="action" href="{{ route('admin.inspector.index') }}">{{ __('admin.administration_hub.open') }}</a>@elseif($canAssistantSettings)<a class="action" href="{{ route('admin.assistant-settings.index') }}">{{ __('admin.administration_hub.open') }}</a>@else<span class="restricted">{{ __('admin.administration_hub.restricted') }}</span>@endif</article>
<article class="card utility-card" data-admin-card="profile"><div><h3>{{ __('admin.administration_hub.profile') }}</h3><p class="muted">{{ $user->name }} · {{ $user->email }}</p></div><a class="action" href="{{ route('admin.profile.index') }}">{{ __('admin.administration_hub.open') }}</a></article>
</div>
</section>
</main>
</div>
</body>
</html>
