<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>{{ __('enterprise_organization.title') }} · FOODEX</title>
@include('admin._brand-components')
<style>
body{margin:0;background:var(--foodex-background);color:var(--foodex-ink)}
.org-page{padding:clamp(18px,2.5vw,36px)}.org-head{display:flex;justify-content:space-between;gap:18px;align-items:flex-start;margin-block:18px 24px}.org-head h1{margin:4px 0 8px;font-size:clamp(1.55rem,2.5vw,2.2rem)}.org-head p{margin:0;color:var(--foodex-muted);max-width:760px}
.org-back,.org-open{display:inline-flex;min-height:40px;align-items:center;justify-content:center;padding-inline:13px;border:1px solid var(--foodex-border);border-radius:10px;background:#fff;color:var(--foodex-green-dark);text-decoration:none;font-weight:800}
.org-grid{display:grid;grid-template-columns:minmax(0,1fr) minmax(280px,.55fr);gap:16px}.org-card{background:#fff;border:1px solid var(--foodex-border);border-radius:var(--foodex-radius-card);padding:18px;box-shadow:var(--foodex-shadow-sm)}.org-card h2,.org-card h3{margin-top:0}
.org-form{display:grid;gap:10px}.org-form label{display:grid;gap:5px;font-weight:700}.org-form input[type=text]{min-height:42px;border:1px solid var(--foodex-border);border-radius:9px;padding-inline:10px;font:inherit}.org-form button{min-height:40px;border:0;border-radius:9px;background:var(--foodex-green);color:#fff;font:inherit;font-weight:800;cursor:pointer;padding-inline:13px}
.org-row{display:grid;grid-template-columns:minmax(0,1fr) auto;gap:12px;align-items:center;padding:13px 0;border-bottom:1px solid var(--foodex-border)}.org-row:last-child{border-bottom:0}.org-row small{color:var(--foodex-muted)}.org-actions{display:flex;gap:7px;flex-wrap:wrap;justify-content:flex-end}.org-inline{display:flex;gap:9px;align-items:center;flex-wrap:wrap}.org-inline input[type=text]{width:min(190px,100%)}.org-status{padding:11px;border:1px solid var(--foodex-border);border-radius:9px;background:#fff;margin-bottom:12px}.org-path{display:flex;gap:6px;flex-wrap:wrap;margin-bottom:14px}.org-path a{color:var(--foodex-green-dark);font-weight:800}.org-empty{color:var(--foodex-muted);padding:18px 0}
@media(max-width:900px){.org-grid{grid-template-columns:1fr}.org-head{flex-direction:column}.org-back{width:100%}.org-row{grid-template-columns:1fr}.org-actions{justify-content:flex-start}.org-inline{align-items:stretch;flex-direction:column}.org-inline input[type=text]{width:100%}}
</style>
</head><body>
<div class="foodex-admin-layout" data-foodex-mobile-web-page="enterprise-organization">
<aside class="sidebar">@include('admin._sidebar',['navGroups'=>$navGroups,'navContext'=>$navContext,'user'=>$user])</aside> {{-- localization-gate: allow Blade include expression --}}
<main class="foodex-admin-main foodex-admin-page org-page" data-enterprise-organization-admin="true">
@include('admin._global-header')
@include('admin._admin-center-switcher',['active'=>'administration'])
<header class="org-head foodex-page-toolbar"><div><div class="foodex-subtitle">FOODEX · {{ __('enterprise_organization.eyebrow') }}</div><h1>{{ __('enterprise_organization.title') }}</h1><p>{{ __('enterprise_organization.description') }}</p></div><a class="org-back" href="{{ route('admin.administration.index') }}">{{ __('enterprise_organization.back') }}</a></header>
@if(session('status'))<div class="org-status" role="status">{{ session('status') }}</div>@endif
@if($errors->any())<div class="org-status" role="alert"><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif

@if($screen==='organizations')
<div class="org-grid"><section class="org-card"><h2>{{ __('enterprise_organization.organizations') }}</h2>
@if($organizations->isEmpty())<p class="org-empty">{{ __('enterprise_organization.none') }}</p>@else
@foreach($organizations as $organization)<div class="org-row"><div><strong>{{ $organization->name }}</strong><br><small>{{ $organization->code }} · {{ $organization->is_active ? __('enterprise_organization.active') : __('enterprise_organization.inactive') }} · {{ __('enterprise_organization.children') }}: {{ $organization->companies_count }}</small></div><div class="org-actions"><a class="org-open" href="{{ route('admin.enterprise-organizations.show',$organization) }}">{{ __('enterprise_organization.open') }}</a></div></div>@endforeach
{{ $organizations->links() }}@endif</section>
<section class="org-card"><h2>{{ __('enterprise_organization.create_organization') }}</h2>
<form class="org-form" method="post" action="{{ route('admin.enterprise-organizations.store') }}">@csrf
<label>{{ __('enterprise_organization.name') }}<input type="text" name="name" required maxlength="255"></label>
<label>{{ __('enterprise_organization.code') }}<input type="text" name="code" required maxlength="100" dir="ltr"></label>
<label><span><input type="hidden" name="is_active" value="0"><input type="checkbox" name="is_active" value="1" checked> {{ __('enterprise_organization.active') }}</span></label>
<button type="submit">{{ __('enterprise_organization.create') }}</button></form></section></div>
@else
<div class="org-path"><a href="{{ route('admin.enterprise-organizations.index') }}">{{ __('enterprise_organization.organizations') }}</a>
@if(isset($organization))<span>›</span><a href="{{ route('admin.enterprise-organizations.show',$organization) }}">{{ $organization->name }}</a>@endif
@if(isset($company))<span>›</span><a href="{{ route('admin.enterprise-companies.show',$company) }}">{{ $company->name }}</a>@endif
@if(isset($parent) && $screen==='company')<span>›</span><span>{{ $record->name }}</span>@endif
@if(isset($parent) && $screen==='legal_entity')<span>›</span><a href="{{ route('admin.enterprise-companies.show',$parent) }}">{{ $parent->name }}</a><span>›</span><span>{{ $record->name }}</span>@endif
@if($screen==='business_unit')<span>›</span><a href="{{ route('admin.enterprise-legal-entities.show',$parent) }}">{{ $parent->name }}</a><span>›</span><span>{{ $record->name }}</span>@endif
</div>
<div class="org-grid"><section class="org-card">
<h2>{{ $record->name }}</h2>
<form class="org-form" method="post" action="@if($screen==='organization'){{ route('admin.enterprise-organizations.update',$record) }}@elseif($screen==='company'){{ route('admin.enterprise-companies.update',$record) }}@elseif($screen==='legal_entity'){{ route('admin.enterprise-legal-entities.update',$record) }}@else{{ route('admin.enterprise-business-units.update',$record) }}@endif">@csrf @method('patch')
<label>{{ __('enterprise_organization.name') }}<input type="text" name="name" value="{{ $record->name }}" required maxlength="255"></label>
<label>{{ __('enterprise_organization.code') }}<input type="text" name="code" value="{{ $record->code }}" required maxlength="100" dir="ltr"></label>
<label><span><input type="hidden" name="is_active" value="0"><input type="checkbox" name="is_active" value="1" @checked($record->is_active)> {{ __('enterprise_organization.active') }}</span></label>
<button type="submit">{{ __('enterprise_organization.save') }}</button></form>
</section>
<section class="org-card">
@if($screen==='organization')<h2>{{ __('enterprise_organization.create_company') }}</h2><form class="org-form" method="post" action="{{ route('admin.enterprise-companies.store',$record) }}">@csrf
@elseif($screen==='company')<h2>{{ __('enterprise_organization.create_legal_entity') }}</h2><form class="org-form" method="post" action="{{ route('admin.enterprise-legal-entities.store',$record) }}">@csrf
@elseif($screen==='legal_entity')<h2>{{ __('enterprise_organization.create_business_unit') }}</h2><form class="org-form" method="post" action="{{ route('admin.enterprise-business-units.store',$record) }}">@csrf
@else<h2>{{ __('enterprise_organization.branches') }}</h2><p>{{ $organization->name }} · {{ $company->name }} · {{ $parent->name }}</p>@endif
@if($screen!=='business_unit')
<label>{{ __('enterprise_organization.name') }}<input type="text" name="name" required maxlength="255"></label><label>{{ __('enterprise_organization.code') }}<input type="text" name="code" required maxlength="100" dir="ltr"></label><label><span><input type="hidden" name="is_active" value="0"><input type="checkbox" name="is_active" value="1" checked> {{ __('enterprise_organization.active') }}</span></label><button type="submit">{{ __('enterprise_organization.create') }}</button></form>
@endif
</section></div>

@if($screen!=='business_unit')
<section class="org-card" style="margin-top:16px"><h2>@if($screen==='organization'){{ __('enterprise_organization.companies') }}@elseif($screen==='company'){{ __('enterprise_organization.legal_entities') }}@else{{ __('enterprise_organization.business_units') }}@endif</h2>
@if($items->isEmpty())<p class="org-empty">{{ __('enterprise_organization.none') }}</p>@else
@foreach($items as $item)<div class="org-row"><div><strong>{{ $item->name }}</strong><br><small>{{ $item->code }} · {{ $item->is_active ? __('enterprise_organization.active') : __('enterprise_organization.inactive') }} · {{ __('enterprise_organization.children') }}: @if($screen==='organization'){{ $item->legal_entities_count }}@elseif($screen==='company'){{ $item->business_units_count }}@else{{ $item->store_scopes_count }}@endif</small></div><div class="org-actions">
@if($screen==='organization')<a class="org-open" href="{{ route('admin.enterprise-companies.show',$item) }}">{{ __('enterprise_organization.open') }}</a>
@elseif($screen==='company')<a class="org-open" href="{{ route('admin.enterprise-legal-entities.show',$item) }}">{{ __('enterprise_organization.open') }}</a>
@else<a class="org-open" href="{{ route('admin.enterprise-business-units.show',$item) }}">{{ __('enterprise_organization.open') }}</a>@endif
</div></div>@endforeach{{ $items->links() }}@endif</section>
@else
<section class="org-card" style="margin-top:16px"><h2>{{ __('enterprise_organization.branches') }}</h2>
@if($stores->isEmpty())<p class="org-empty">{{ __('enterprise_organization.none') }}</p>@else
@foreach($stores as $store)<div class="org-row"><div><strong>{{ $store->name }}</strong><br><small>{{ $store->code }} · {{ __('enterprise_organization.current_unit') }}: {{ $store->business_unit_name }}</small></div><div class="org-actions">
@if((int)$store->business_unit_id !== (int)$record->id)<form method="post" action="{{ route('admin.enterprise-business-units.stores.assign',['businessUnit'=>$record,'store'=>$store->id]) }}"> {{-- localization-gate: allow route parameter expression --}} @csrf<button class="org-open" type="submit">{{ __('enterprise_organization.assign_here') }}</button></form>@endif
</div></div>@endforeach{{ $stores->links() }}@endif</section>
@endif
@endif
</main></div></body></html>
