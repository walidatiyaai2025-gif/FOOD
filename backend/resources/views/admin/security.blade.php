<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>{{ __('admin.security.title') }} · FOODEX</title>
<style>
:root{color:#17202a;background:#f5f7fa}*{box-sizing:border-box}body{margin:0}.page{max-width:1500px;margin:auto;padding:28px}.top,.head,.row{display:flex;gap:14px;justify-content:space-between;align-items:flex-start}.top{margin-bottom:22px}.muted{color:var(--foodex-muted);line-height:1.55}.back,.card,.panel{background:#fff;border:1px solid #e2e8f0;border-radius:16px}.back{padding:10px 14px;text-decoration:none;color:#0f172a}.panel{padding:22px;margin-bottom:22px;box-shadow:0 14px 35px rgba(15,23,42,.05)}.card{padding:16px;margin-top:12px}.filters,.checks,.actions{display:flex;flex-wrap:wrap;gap:9px}.filters{margin:16px 0}.checks{margin:10px 0}.actions{align-items:center}input,select,textarea,button{font:inherit}input[type=text],select,textarea{border:1px solid #cbd5e1;border-radius:10px;padding:9px 11px;background:#fff}textarea{width:100%;min-height:65px}.grow{flex:1;min-width:240px}.btn{border:0;border-radius:10px;padding:9px 13px;font-weight:750;cursor:pointer}.primary{background:#0f172a;color:#fff}.danger{background:#fff1f2;color:#9f1239}.success{background:#ecfdf5;color:#065f46}.secondary{background:#eef2ff;color:#3730a3}.badge{display:inline-block;border-radius:999px;padding:4px 8px;font-size:.75rem;background:#e2e8f0;margin:3px}.active{background:#dcfce7;color:#166534}.inactive{background:#fee2e2;color:#991b1b}.system{background:#e0e7ff;color:#3730a3}details{margin-top:12px}summary{cursor:pointer;font-weight:800}.store{border:1px dashed #cbd5e1;border-radius:12px;padding:10px;margin:8px 0}.permissions{display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:8px}.permission-group{border:0;border-top:1px solid #e2e8f0;padding:10px 0 0;min-width:0}.permission-group legend{font-weight:800;padding:0}.permission-group-tools{display:flex;flex-wrap:wrap;align-items:center;gap:6px;margin:8px 0}.permission-group-tools .btn{padding:6px 9px}.permission-search{display:grid;gap:5px;margin:12px 0}.permission-search input{width:100%}.permission-option{display:flex;gap:8px;align-items:flex-start;padding:7px 4px;border-radius:8px}.permission-option input{margin-top:4px}.permission-code{display:block;color:#64748b;margin-top:2px;overflow-wrap:anywhere}.permission-impact{display:grid;gap:4px;margin:12px 0}.assignment-store>summary{padding:4px 0}.job-role-meta{display:grid;gap:5px;margin-top:6px}.job-role-tags{display:flex;flex-wrap:wrap;gap:5px}.job-role-responsibilities{margin:8px 0 0;padding-inline-start:20px}.role-unavailable{opacity:.72}.role-unavailable .badge{background:var(--foodex-orange-soft,#FFF1E6);color:var(--foodex-ink)}.notice,.errors{padding:12px 14px;border-radius:12px;margin-bottom:14px}.notice{background:#f0fdf4}.errors{background:#fff1f2}.role-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(330px,1fr));gap:12px}.note{background:#f8fafc;padding:12px;border-radius:12px;color:#475569}.pager{margin-top:14px}@media(max-width:760px){.page{padding:12px}.top,.head,.row{flex-direction:column}.role-grid{grid-template-columns:1fr}.panel{padding:14px}.card{padding:12px}.filters{display:grid;grid-template-columns:minmax(0,1fr);width:100%}.grow{min-width:0;width:100%}.checks{display:grid;grid-template-columns:minmax(0,1fr)}.actions{align-items:stretch}.actions form{max-width:100%}.permissions{grid-template-columns:1fr}}
</style>
    @include('admin._brand-components')
</head>
<body>
<div class="foodex-admin-layout" data-foodex-mobile-web-page="security">
<aside class="sidebar">@include('admin._sidebar', ['navGroups' => app(\App\Support\AdminNavigation::class)->groupsFor(auth()->user()), 'navContext' => 'administration_hub', 'user' => auth()->user()])</aside> {{-- localization-gate: allow Blade include expression --}}
<main class="page foodex-admin-main foodex-admin-page" data-foodex-utility="security">
@include('admin._admin-center-switcher',['active'=>'administration'])
<header class="top foodex-page-header"><div><div class="muted">FOODEX · {{ __('admin.security.eyebrow') }}</div><h1>{{ __('admin.security.title') }}</h1><p class="muted">{{ __('admin.security.description') }}</p></div><a class="back" href="{{ route('admin.administration.index') }}">{{ __('admin.security.back') }}</a></header>
<nav class="foodex-tabs" aria-label="{{ __('admin.security.title') }}" style="margin-bottom:18px"><a class="back" aria-current="page" href="{{ route('admin.security.index') }}">{{ __('admin.security.title') }}</a><a class="back" href="{{ route('admin.enterprise-security.index') }}">{{ __('enterprise_security.title') }}</a></nav>
@if(session('status'))<div class="notice foodex-state" role="status">{{ session('status') }}</div>@endif
@if($errors->any())<div class="errors foodex-state" role="alert"><strong>{{ __('admin.security.validation_failed') }}</strong><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif

@include('admin._security-governance-dashboard')

<section class="panel">
<div class="head"><div><h2>{{ __('admin.security.users_title') }}</h2><div class="muted">{{ __('admin.security.users_description') }}</div></div><span class="badge">{{ $users->total() }} {{ __('admin.security.users') }}</span></div>
<form method="get" class="filters"><input class="grow" type="text" name="q" value="{{ $search }}" placeholder="{{ __('admin.security.search_users') }}"><select name="status"><option value="all" @selected($statusFilter==='all')>{{ __('admin.security.all_statuses') }}</option><option value="active" @selected($statusFilter==='active')>{{ __('admin.security.active') }}</option><option value="inactive" @selected($statusFilter==='inactive')>{{ __('admin.security.inactive') }}</option></select><button class="btn primary foodex-filter-action">{{ __('admin.security.filter') }}</button></form>

@php
$roleLabel = static function ($role): string {
    if (! $role) {
        return '—';
    }

    $key = 'admin.role_names.'.$role->code;

    return \Illuminate\Support\Facades\Lang::has($key) ? __($key) : $role->name;
};
@endphp
@forelse($users as $managedUser)
<article class="card">
<div class="head">
<div>
<h3 style="margin:0">{{ $managedUser->name }}</h3>
<div class="muted">
    {{ $managedUser->email }} · <strong>{{ __('admin.security.username') }}:</strong> {{ $managedUser->username ?: '—' }}
</div>
<div>
    <span class="badge {{ $managedUser->is_active?'active':'inactive' }}">{{ $managedUser->is_active?__('admin.security.active'):__('admin.security.inactive') }}</span>
    @foreach($managedUser->roles as $role)
        <span class="badge">{{ $roleLabel($role) }} <small>({{ $role->code }})</small></span> {{-- localization-gate: allow technical RBAC role identifier --}}
    @endforeach
    @foreach($managedUser->storeRoleAssignments as $a)
        <span class="badge">{{ $a->store?->name }} · {{ $roleLabel($a->role) }} <small>({{ $a->role?->code }})</small></span> {{-- localization-gate: allow technical RBAC role identifier --}}
    @endforeach
    <span class="badge {{ $mfaByUser->has($managedUser->id)?'active':'inactive' }}">{{ $mfaByUser->has($managedUser->id)?__('security_governance.mfa_admin.enabled'):__('security_governance.mfa_admin.missing') }}</span>
</div>
@if($ssoByUser->has($managedUser->id))
    <div class="muted">
        {{ __('security_governance.sso.identity') }}:
        @foreach($ssoByUser->get($managedUser->id) as $identity)
            <span dir="ltr">{{ $identity->provider }} · {{ $identity->subject }}</span> {{-- localization-gate: allow external SSO provider and subject identifiers --}}
            @if(!$loop->last) · @endif
        @endforeach
    </div>
@endif
@if(!$managedUser->is_active&&$managedUser->deactivation_reason)<div class="muted">{{ __('admin.security.reason') }}: {{ $managedUser->deactivation_reason }}</div>@endif
</div>
@can('users.status.manage')<form method="post" action="{{ route('admin.security.users.status',$managedUser) }}" onsubmit="return confirm('{{ $managedUser->is_active?__('admin.security.confirm_deactivate'):__('admin.security.confirm_activate') }}')">@csrf @method('patch')<input type="hidden" name="is_active" value="{{ $managedUser->is_active?0:1 }}">@if($managedUser->is_active)<input type="text" name="reason" maxlength="500" placeholder="{{ __('admin.security.optional_reason') }}"><button class="btn danger">{{ __('admin.security.deactivate') }}</button>@else<button class="btn success">{{ __('admin.security.activate') }}</button>@endif</form>@endcan
</div>
@can('users.roles.manage')
<div class="actions" style="margin-top:10px">
@if($mfaByUser->has($managedUser->id))
<form method="post" action="{{ route('admin.security.users.mfa.reset',$managedUser) }}" onsubmit="return confirm('{{ __('security_governance.mfa_admin.reset_confirm') }}')">@csrf @method('delete')<button class="btn danger" type="submit">{{ __('security_governance.mfa_admin.reset') }}</button></form>
@endif
@if(($governance['health']['sso']['configured'] ?? false))
<form method="post" action="{{ route('admin.security.users.sso.store',$managedUser) }}">@csrf
<input type="hidden" name="provider" value="{{ $governance['health']['sso']['provider'] }}">
<input name="subject" maxlength="191" required placeholder="{{ __('security_governance.sso.subject') }}">
<button class="btn secondary" type="submit">{{ __('security_governance.sso.link') }}</button>
</form>
@endif
@foreach($ssoByUser->get($managedUser->id, collect()) as $identity)
<form method="post" action="{{ route('admin.security.users.sso.destroy',$identity) }}">@csrf @method('delete')<button class="btn danger" type="submit">{{ __('security_governance.sso.unlink') }} · {{ $identity->provider }}</button></form>
@endforeach
</div>
<details data-foodex-operational-modal><summary>{{ __('admin.security.manage_assignments') }}</summary>
@include('admin._security-role-assignment-picker', [
    'managedUser' => $managedUser,
    'globalRoles' => $globalRoles,
    'stores' => $stores,
    'grantableStoreRolesByStore' => $grantableStoreRolesByStore,
    'jobRoleMeta' => $jobRoleMeta,
    'globalRoleAvailability' => $globalRoleAvailability,
    'storeRoleAvailabilityByStore' => $storeRoleAvailabilityByStore,
])
</details>
@endcan
</article>
@empty<div class="note foodex-empty-state">{{ __('admin.security.no_users') }}</div>@endforelse
<div class="pager">{{ $users->links() }}</div>
</section>

@can('demo_data.manage')
<section class="panel" data-demo-data-admin>
<div class="head"><div><h2>{{ __('admin.security.demo_data.title') }}</h2><div class="muted">{{ __('admin.security.demo_data.description') }}</div></div><span class="badge system">{{ __('admin.security.demo_data.non_production') }}</span></div>
<div class="role-grid">
@foreach($demoSummary as $key=>$count)
<div class="card"><strong>{{ __('admin.security.demo_data.counts.'.$key) }}</strong><div style="font-size:1.55rem;font-weight:700;margin-top:6px" class="foodex-number">{{ $count }}</div></div>
@endforeach
</div>
<form method="post" action="{{ route('admin.security.demo-data.seed') }}" class="card" onsubmit="return confirm('{{ __('admin.security.demo_data.seed_confirm') }}')">
@csrf
<p class="muted">{{ __('admin.security.demo_data.seed_description') }}</p>
<button class="btn success">{{ __('admin.security.demo_data.seed') }}</button>
</form>
<form method="post" action="{{ route('admin.security.demo-data.clear') }}" class="card" onsubmit="return confirm('{{ __('admin.security.demo_data.confirm') }}')">@csrf @method('delete')
<label style="display:block;font-weight:700;margin-bottom:7px">{{ __('admin.security.demo_data.confirmation_label') }}</label>
<input class="grow" type="text" name="confirmation" required autocomplete="off" placeholder="DELETE DEMO DATA">
<p class="muted">{{ __('admin.security.demo_data.safety') }}</p>
<button class="btn danger">{{ __('admin.security.demo_data.clear') }}</button>
</form>
</section>
@endcan

<section class="panel"><div class="head"><div><h2>{{ __('admin.security.roles_title') }}</h2><div class="muted">{{ __('admin.security.roles_description') }}</div></div><span class="badge">{{ $roles->count() }} {{ __('admin.security.roles') }}</span></div>
@can('roles.manage')
<details class="card" data-foodex-operational-modal><summary>{{ __('admin.security.create_role') }}</summary><form method="post" action="{{ route('admin.security.roles.store') }}">@csrf
<div class="filters"><input class="grow" name="code" required maxlength="50" pattern="[A-Z][A-Z0-9_]{2,49}" placeholder="CUSTOM_ROLE"><input class="grow" name="name" required maxlength="120" placeholder="{{ __('admin.security.role_name') }}"><select name="scope"><option value="global">{{ __('admin.security.scope_global') }}</option><option value="store">{{ __('admin.security.scope_store') }}</option></select><label><input type="hidden" name="is_active" value="0"><input type="checkbox" name="is_active" value="1" checked> {{ __('admin.security.active') }}</label></div><textarea name="description" placeholder="{{ __('admin.security.role_description') }}"></textarea>
@include('admin._security-permission-picker', [
    'selectedPermissionIds' => [],
    'permissionGroups' => $permissionGroups,
    'permissionLabels' => $permissionLabels,
    'permissionGroupLabels' => $permissionGroupLabels,
    'privilegedPermissionCodes' => $privilegedPermissionCodes,
])
<button class="btn primary">{{ __('admin.security.create_role') }}</button></form></details>
@endcan

<div class="role-grid">@foreach($roles as $role)@php $pids=$role->permissions->pluck('id')->map(fn($id)=>(int)$id)->all(); @endphp
<details class="card" data-foodex-operational-modal><summary>{{ $role->name }} · {{ str_ireplace('B2C','RETAIL',$role->code) }} <span class="badge {{ $role->is_active?'active':'inactive' }}">{{ $role->is_active?__('admin.security.active'):__('admin.security.inactive') }}</span>@if($role->is_system)<span class="badge system">{{ __('admin.security.system_role') }}</span>@endif</summary><p class="muted">{{ $role->description?:__('admin.security.no_description') }}</p><div class="muted">{{ __('admin.security.assignments_count') }}: {{ (int)$role->users_count+(int)$role->store_assignments_count }}</div>
@can('roles.manage')
@if(in_array((int) $role->id, $editableRoleIds, true))
<form method="post" action="{{ route('admin.security.roles.update',$role) }}">@csrf @method('patch')<div class="filters"><input class="grow" name="name" value="{{ $role->name }}" required>@if($role->is_system)<input type="hidden" name="scope" value="{{ $role->scope }}"><span class="note">{{ __('admin.security.scope') }}: {{ $role->scope === 'global' ? __('admin.security.scope_global') : __('admin.security.scope_store') }}</span>@else<select name="scope"><option value="global" @selected($role->scope==='global')>{{ __('admin.security.scope_global') }}</option><option value="store" @selected($role->scope==='store')>{{ __('admin.security.scope_store') }}</option></select>@endif @if($role->code==='SUPER_ADMIN') {{-- localization-gate: allow technical RBAC role identifier --}}<input type="hidden" name="is_active" value="1"><span class="note">{{ __('admin.security.super_admin_protected') }}</span>@else<label><input type="hidden" name="is_active" value="0"><input type="checkbox" name="is_active" value="1" @checked($role->is_active)> {{ __('admin.security.active') }}</label>@endif</div><textarea name="description">{{ $role->description }}</textarea>
@if($role->code==='SUPER_ADMIN') {{-- localization-gate: allow technical RBAC role identifier --}}
<div class="note">{{ __('security_permissions.super_admin_permissions_protected') }}</div>
@else
@include('admin._security-permission-picker', [
    'selectedPermissionIds' => $pids,
    'permissionGroups' => $permissionGroups,
    'permissionLabels' => $permissionLabels,
    'permissionGroupLabels' => $permissionGroupLabels,
    'privilegedPermissionCodes' => $privilegedPermissionCodes,
])
@endif
<button class="btn primary">{{ __('admin.security.save_role') }}</button></form>
@else
<div class="note">{{ __('security_permissions.role_outside_authority') }}</div>
@endif
<div class="actions"><form method="post" action="{{ route('admin.security.roles.clone',$role) }}">@csrf <input name="code" required pattern="[A-Z][A-Z0-9_]{2,49}" placeholder="{{ str_ireplace('B2C','RETAIL',$role->code) }}_COPY"><input name="name" required value="{{ $role->name }} Copy"><button class="btn secondary">{{ __('admin.security.clone') }}</button></form>@if(!$role->is_system)<form method="post" action="{{ route('admin.security.roles.destroy',$role) }}" onsubmit="return confirm('{{ __('admin.security.confirm_delete_role') }}')">@csrf @method('delete')<button class="btn danger">{{ __('admin.security.delete_role') }}</button></form>@endif</div>
@endcan</details>@endforeach</div>
</section>
<div class="note">{{ __('admin.security.server_authoritative') }}</div>
@include('admin._security-interaction-runtime')
</main>
</div>
</body></html>
