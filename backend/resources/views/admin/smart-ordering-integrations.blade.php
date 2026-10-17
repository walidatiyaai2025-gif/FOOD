<!doctype html>
<html lang="{{ app()->getLocale() }}" dir="{{ app()->getLocale()==='ar' ? 'rtl' : 'ltr' }}">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>{{ __('restaurant_integrations.title') }}</title>
@include('admin._brand-components')
@include('admin._settings-page-polish')
<style>
.ri-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,320px),1fr));gap:16px}.ri-kpis{display:grid;grid-template-columns:repeat(4,minmax(120px,1fr));gap:12px}.ri-kpi{border:1px solid var(--foodex-border);border-radius:14px;padding:14px}.ri-kpi strong{display:block;font-size:1.4rem}.ri-form{display:grid;gap:12px}.ri-form label{display:grid;gap:6px}.ri-checks{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px}.ri-list{display:grid;gap:10px}.ri-item{border:1px solid var(--foodex-border);border-radius:14px;padding:14px;display:grid;gap:10px}.ri-head{display:flex;justify-content:space-between;gap:12px;align-items:flex-start}.ri-badge{display:inline-flex;border-radius:999px;padding:4px 9px;background:rgba(127,127,127,.12);font-size:.82rem}.ri-secret{word-break:break-all;font-family:ui-monospace,monospace}
@media(max-width:767px){.ri-kpis{grid-template-columns:1fr 1fr}.ri-checks{grid-template-columns:1fr}.ri-head{display:grid}}
</style>
</head>
<body>
@php
$isAr=app()->getLocale()==='ar';
$localized=static fn($ar,$en)=>trim((string)($isAr?$ar:$en))!==''?(string)($isAr?$ar:$en):(string)($isAr?$en:$ar);
@endphp
<div class="foodex-admin-layout" data-foodex-mobile-web-page="restaurant-integrations">
<aside class="sidebar">@include('admin._sidebar',['navGroups'=>app(AppSupportAdminNavigation::class)->groupsFor($user),'navContext'=>'smart_ordering','user'=>$user])</aside>
<main class="foodex-admin-main foodex-admin-page foodex-settings-surface">
<header class="foodex-page-header"><div><h1>{{ __('restaurant_integrations.title') }}</h1><p>{{ __('restaurant_integrations.subtitle') }}</p></div><a class="foodex-action-primary" href="{{ route('admin.smart-ordering.index',['store_id'=>$site->store_id]) }}">{{ __('restaurant_integrations.back') }}</a></header>
@if(session('status'))<div class="foodex-alert success" role="status">{{ session('status') }}</div>@endif
@if(session('restaurant_api_credential'))<section class="panel"><strong>{{ __('restaurant_integrations.credential_once') }}</strong><div class="ri-secret">{{ session('restaurant_api_credential') }}</div></section>@endif
@if(session('restaurant_webhook_secret'))<section class="panel"><strong>{{ __('restaurant_integrations.secret_once') }}</strong><div class="ri-secret">{{ session('restaurant_webhook_secret') }}</div></section>@endif
@if($errors->any())<div class="foodex-alert error" role="alert">{{ $errors->first() }}</div>@endif

<section class="panel"><h2>{{ __('restaurant_integrations.health') }}</h2><div class="ri-kpis">
<div class="ri-kpi"><span>{{ __('restaurant_integrations.installed') }}</span><strong>{{ $health['installed'] }}</strong></div>
<div class="ri-kpi"><span>{{ __('restaurant_integrations.enabled') }}</span><strong>{{ $health['enabled'] }}</strong></div>
<div class="ri-kpi"><span>{{ __('restaurant_integrations.degraded') }}</span><strong>{{ $health['degraded'] }}</strong></div>
<div class="ri-kpi"><span>{{ __('restaurant_integrations.dead_letters') }}</span><strong>{{ $health['dead_letters'] }}</strong></div>
</div></section>

<div class="ri-grid">
<section class="panel"><h2>{{ __('restaurant_integrations.install') }}</h2>
<form class="ri-form" method="post" action="{{ route('admin.smart-ordering.integrations.install',$site) }}">@csrf
<label>{{ __('restaurant_integrations.branch') }}<select class="foodex-control" name="branch_id"><option value="">{{ __('restaurant_integrations.all_branches') }}</option>@foreach($branches as $branch)<option value="{{ $branch->id }}">{{ $localized($branch->name_ar,$branch->name_en) }}</option>@endforeach</select></label>
<label>{{ __('restaurant_integrations.type') }}<select class="foodex-control" name="connector_type" required>@foreach($connectorTypes as $type)<option value="{{ $type }}">{{ __('restaurant_integrations.connector_types.'.$type) }}</option>@endforeach</select></label>
<label>{{ __('restaurant_integrations.provider_key') }}<input class="foodex-control" name="provider_key" pattern="[A-Za-z0-9_-]+" required></label>
<label>{{ __('restaurant_integrations.display_name') }}<input class="foodex-control" name="display_name" required></label>
<label>{{ __('restaurant_integrations.credential_reference') }}<input class="foodex-control" name="credential_reference" placeholder="vault://restaurant/provider"><small>{{ __('restaurant_integrations.credential_reference_help') }}</small></label>
<fieldset><legend>{{ __('restaurant_integrations.capabilities') }}</legend><div class="ri-checks">@foreach($capabilities as $capability)<label><input type="checkbox" name="capabilities[]" value="{{ $capability }}"> {{ $capability }}</label>@endforeach</div></fieldset>
<button class="primary" type="submit">{{ __('restaurant_integrations.save_connector') }}</button>
</form></section>

<section class="panel"><h2>{{ __('restaurant_integrations.api_clients') }}</h2>
<form class="ri-form" method="post" action="{{ route('admin.smart-ordering.integrations.api-clients.store',$site) }}">@csrf
<label>{{ __('restaurant_integrations.client_name') }}<input class="foodex-control" name="name" required></label>
<label>{{ __('restaurant_integrations.expires_at') }}<input class="foodex-control" type="datetime-local" name="expires_at"></label>
<fieldset><legend>{{ __('restaurant_integrations.scopes') }}</legend><div class="ri-checks">@foreach(['restaurant.read','menu.read','orders.read','integrations.write'] as $scope)<label><input type="checkbox" name="scopes[]" value="{{ $scope }}"> {{ $scope }}</label>@endforeach</div></fieldset>
<button class="primary" type="submit">{{ __('restaurant_integrations.create_client') }}</button>
</form></section>

<section class="panel"><h2>{{ __('restaurant_integrations.webhooks') }}</h2>
<form class="ri-form" method="post" action="{{ route('admin.smart-ordering.integrations.webhooks.store',$site) }}">@csrf
<label>{{ __('restaurant_integrations.target_url') }}<input class="foodex-control" type="url" name="target_url" placeholder="https://example.com/webhooks/foodex" required></label>
<fieldset><legend>{{ __('restaurant_integrations.event_types') }}</legend><small>{{ __('restaurant_integrations.event_types_help') }}</small><div class="ri-checks">@foreach(['order.created','order.status_changed','payment.updated','menu.published','*'] as $event)<label><input type="checkbox" name="event_types[]" value="{{ $event }}"> {{ $event }}</label>@endforeach</div></fieldset>
<button class="primary" type="submit">{{ __('restaurant_integrations.create_webhook') }}</button>
</form>
<div class="ri-list" style="margin-top:14px">@forelse($webhooks as $hook)<div class="ri-item"><div class="ri-head"><strong>{{ $hook['target_url'] }}</strong><span class="ri-badge">{{ $hook['is_active']?__('restaurant_integrations.active'):__('restaurant_integrations.inactive') }}</span></div><div>{{ implode(', ',$hook['event_types']) }}</div></div>@empty<p>{{ __('restaurant_integrations.no_webhooks') }}</p>@endforelse</div>
</section>
</div>

<section class="panel"><h2>{{ __('restaurant_integrations.connectors') }}</h2><div class="ri-list">
@forelse($integrations as $row)
<div class="ri-item"><div class="ri-head"><div><strong>{{ $row['display_name'] }}</strong><div>{{ $row['provider_key'] }} · {{ __('restaurant_integrations.connector_types.'.$row['connector_type']) }}</div></div><span class="ri-badge">{{ __('restaurant_integrations.status_options.'.$row['status']) }}</span></div>
<div>{{ implode(', ',$row['capabilities']) }}</div>
@if($row['branch_name_en'] || $row['branch_name_ar'])<div>{{ __('restaurant_integrations.branch') }}: {{ $localized($row['branch_name_ar'],$row['branch_name_en']) }}</div>@endif
<form method="post" action="{{ route('admin.smart-ordering.integrations.status',[$site,$row['public_id']]) }}">@csrf @method('PATCH')<div class="ri-head"><select class="foodex-control" name="status">@foreach(['disabled','enabled','degraded','suspended'] as $status)<option value="{{ $status }}" @selected($status===$row['status'])>{{ __('restaurant_integrations.status_options.'.$status) }}</option>@endforeach</select><button class="primary" type="submit">{{ __('restaurant_integrations.status') }}</button></div></form>
</div>
@empty<p>{{ __('restaurant_integrations.no_connectors') }}</p>@endforelse
</div></section>
</main></div>
</body></html>
