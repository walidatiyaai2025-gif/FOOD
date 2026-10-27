<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>{{ __('admin.security.title') }} · FOODEX</title>
<style>
:root{color:#17202a;background:#f5f7fa}*{box-sizing:border-box}body{margin:0}.page{max-width:1500px;margin:auto;padding:28px}.top,.head,.row{display:flex;gap:14px;justify-content:space-between;align-items:flex-start}.top{margin-bottom:22px}.muted{color:var(--foodex-muted);line-height:1.55}.back,.card,.panel{background:#fff;border:1px solid #e2e8f0;border-radius:16px}.back{padding:10px 14px;text-decoration:none;color:#0f172a}.panel{padding:22px;margin-bottom:22px;box-shadow:0 14px 35px rgba(15,23,42,.05)}.card{padding:16px;margin-top:12px}.filters,.checks,.actions{display:flex;flex-wrap:wrap;gap:9px}.filters{margin:16px 0}.checks{margin:10px 0}.actions{align-items:center}input,select,textarea,button{font:inherit}input[type=text],select,textarea{border:1px solid var(--foodex-border);border-radius:10px;padding:9px 11px;background:#fff}textarea{width:100%;min-height:65px}.grow{flex:1;min-width:240px}.btn{border:0;border-radius:10px;padding:9px 13px;font-weight:750;cursor:pointer}.primary{background:#0f172a;color:#fff}.danger{background:#fff1f2;color:#9f1239}.success{background:#ecfdf5;color:#065f46}.secondary{background:#eef2ff;color:#3730a3}.badge{display:inline-block;border-radius:999px;padding:4px 8px;font-size:.75rem;background:#e2e8f0;margin:3px}.active{background:#dcfce7;color:#166534}.inactive{background:#fee2e2;color:#991b1b}.system{background:#e0e7ff;color:#3730a3}details{margin-top:12px}summary{cursor:pointer;font-weight:800}.store{border:1px dashed var(--foodex-border);border-radius:12px;padding:10px;margin:8px 0}.permissions{display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:8px}.permission-group{border:0;border-top:1px solid #e2e8f0;padding:10px 0 0;min-width:0}.permission-group legend{font-weight:800;padding:0}.permission-group-tools{display:flex;flex-wrap:wrap;align-items:center;gap:6px;margin:8px 0}.permission-group-tools .btn{padding:6px 9px}.permission-search{display:grid;gap:5px;margin:12px 0}.permission-search input{width:100%}.permission-option{display:flex;gap:8px;align-items:flex-start;padding:7px 4px;border-radius:8px}.permission-option input{margin-top:4px}.permission-code{display:block;color:#64748b;margin-top:2px;overflow-wrap:anywhere}.permission-impact{display:grid;gap:4px;margin:12px 0}.assignment-store>summary{padding:4px 0}.job-role-meta{display:grid;gap:5px;margin-top:6px}.job-role-tags{display:flex;flex-wrap:wrap;gap:5px}.job-role-responsibilities{margin:8px 0 0;padding-inline-start:20px}.role-unavailable{opacity:.72}.role-unavailable .badge{background:var(--foodex-orange-soft);color:var(--foodex-ink)}.security-tabs{display:flex;flex-wrap:wrap;gap:8px;margin-bottom:18px}.security-tab{display:inline-flex;align-items:center;min-height:42px;padding:9px 14px;border:1px solid var(--foodex-border);border-radius:12px;background:#fff;color:var(--foodex-ink);font-weight:800;text-decoration:none}.security-tab[aria-current="page"]{background:var(--foodex-green);border-color:var(--foodex-green);color:#fff}.security-users-head-actions{display:flex;flex-wrap:wrap;align-items:center;gap:8px}.security-users-head-actions .primary,.security-create-form .primary{background:var(--foodex-green);color:#fff}.security-create-form{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px;min-width:min(720px,90vw);padding-top:12px}.security-create-form label{display:grid;gap:6px;font-weight:700}.security-create-form input,.security-create-form select{width:100%;border:1px solid var(--foodex-border);border-radius:10px;padding:9px 11px;background:#fff}.security-create-form .span-2{grid-column:1/-1}.security-user-table-wrap{overflow-x:auto}.security-user-table{width:100%;border-collapse:collapse;min-width:920px}.security-user-table th,.security-user-table td{padding:12px 10px;border-bottom:1px solid var(--foodex-border);text-align:start;vertical-align:middle}.security-user-table th{font-size:.82rem;color:var(--foodex-muted);background:var(--foodex-background)}.security-user-identity{display:grid;gap:3px;min-width:220px}.security-user-role-list{display:flex;flex-wrap:wrap;gap:4px;max-width:420px}.security-user-actions{display:flex;flex-wrap:wrap;gap:7px;align-items:center}.security-user-actions details{margin:0}.security-user-actions summary{list-style:none}.security-user-actions summary::-webkit-details-marker{display:none}.security-modal-stack{display:grid;gap:12px;min-width:min(760px,90vw);padding-top:12px}.notice,.errors{padding:12px 14px;border-radius:12px;margin-bottom:14px}.notice{background:#f0fdf4}.errors{background:#fff1f2}.role-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(330px,1fr));gap:12px}.note{background:#f8fafc;padding:12px;border-radius:12px;color:#475569}.pager{margin-top:14px}@media(max-width:760px){.page{padding:12px}.top,.head,.row{flex-direction:column}.role-grid{grid-template-columns:1fr}.panel{padding:14px}.card{padding:12px}.filters{display:grid;grid-template-columns:minmax(0,1fr);width:100%}.grow{min-width:0;width:100%}.checks{display:grid;grid-template-columns:minmax(0,1fr)}.actions{align-items:stretch}.actions form{max-width:100%}.permissions{grid-template-columns:1fr}.security-create-form{grid-template-columns:1fr;min-width:0}.security-create-form .span-2{grid-column:auto}.security-tabs{overflow-x:auto;flex-wrap:nowrap;padding-bottom:4px}.security-tab{white-space:nowrap}}
</style>
    @include('admin._brand-components')
</head>
<body>
<div class="foodex-admin-layout" data-foodex-mobile-web-page="security">
<aside class="sidebar">@include('admin._sidebar', ['navGroups' => app(\App\Support\AdminNavigation::class)->groupsFor(auth()->user()), 'navContext' => 'administration_hub', 'user' => auth()->user()])</aside> {{-- localization-gate: allow Blade include expression --}}
<main class="page foodex-admin-main foodex-admin-page" data-foodex-utility="security">
@include('admin._global-header')
@include('admin._admin-center-switcher',['active'=>'administration'])
<div class="top foodex-page-toolbar"><div><div class="muted">FOODEX · {{ __('admin.security.eyebrow') }}</div><h1>{{ __('admin.security.title') }}</h1><p class="muted">{{ __('admin.security.description') }}</p></div><a class="back" href="{{ route('admin.administration.index') }}">{{ __('admin.security.back') }}</a></div>
<nav class="security-tabs" aria-label="{{ __('admin.security.title') }}">
<a class="security-tab" @if($securitySection==='users') aria-current="page" @endif href="{{ route('admin.security.index',['section'=>'users']) }}">{{ __('admin.security.tabs.users') }}</a>
<a class="security-tab" @if($securitySection==='roles') aria-current="page" @endif href="{{ route('admin.security.index',['section'=>'roles']) }}">{{ __('admin.security.tabs.roles') }}</a>
<a class="security-tab" @if($securitySection==='governance') aria-current="page" @endif href="{{ route('admin.security.index',['section'=>'governance']) }}">{{ __('admin.security.tabs.governance') }}</a>
<a class="security-tab" @if($securitySection==='sessions') aria-current="page" @endif href="{{ route('admin.security.index',['section'=>'sessions']) }}">{{ __('admin.security.tabs.sessions') }}</a>
<a class="security-tab" @if($securitySection==='audit') aria-current="page" @endif href="{{ route('admin.security.index',['section'=>'audit']) }}">{{ __('admin.security.tabs.audit') }}</a>
<a class="security-tab" href="{{ route('admin.enterprise-security.index') }}">{{ __('enterprise_security.title') }}</a>
</nav>
@if(session('status'))<div class="notice foodex-state" role="status">{{ session('status') }}</div>@endif
@if($errors->any())<div class="errors foodex-state" role="alert"><strong>{{ __('admin.security.validation_failed') }}</strong><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif

@include('admin._security-governance-dashboard',['securitySection'=>$securitySection])

@if($securitySection==='users')
@php
$roleLabel = static function ($role): string {
    if (! $role) {
        return '—';
    }

    $key = 'admin.role_names.'.$role->code;

    return \Illuminate\Support\Facades\Lang::has($key) ? __($key) : $role->name;
};
@endphp
<section class="panel" data-security-users>
<div class="head">
<div><h2>{{ __('admin.security.users_title') }}</h2><div class="muted">{{ __('admin.security.users_description') }}</div></div>
<div class="security-users-head-actions">
@can('users.roles.manage')
<details data-foodex-operational-modal>
<summary class="btn primary">{{ __('admin.security.create_user') }}</summary>
<form method="post" action="{{ route('admin.security.users.store') }}" class="security-create-form">
@csrf
<label>{{ __('admin.security.user_name') }}<input name="name" value="{{ old('name') }}" required maxlength="120" autocomplete="name"></label>
<label>{{ __('admin.security.username') }}<input name="username" value="{{ old('username') }}" maxlength="80" autocomplete="username" placeholder="{{ __('admin.security.username_optional') }}"></label>
<label>{{ __('admin.security.email') }}<input type="email" name="email" value="{{ old('email') }}" required maxlength="190" autocomplete="email"></label>
<label>{{ __('admin.security.locale') }}<select name="locale" required><option value="ar" @selected(old('locale','ar')==='ar')>{{ __('admin.security.locale_ar') }}</option><option value="en" @selected(old('locale')==='en')>{{ __('admin.security.locale_en') }}</option></select></label>
<label>{{ __('admin.security.password') }}<input type="password" name="password" required minlength="10" maxlength="100" autocomplete="new-password"></label>
<label>{{ __('admin.security.password_confirmation') }}<input type="password" name="password_confirmation" required minlength="10" maxlength="100" autocomplete="new-password"></label>
<label class="span-2">{{ __('admin.security.initial_role') }}
<select name="role_id" required>
<option value="">{{ __('admin.security.select_role') }}</option>
@foreach($globalRoles as $role)
@if((bool)$globalRoleAvailability->get((int)$role->id,false))
<option value="{{ $role->id }}" @selected((string)old('role_id')===(string)$role->id)>{{ $jobRoleMeta[$role->code]['name'] ?? $roleLabel($role) }}</option>
@endif
@endforeach
</select>
<span class="muted">{{ __('admin.security.initial_role_help') }}</span>
</label>
<label class="span-2" style="display:flex;grid-template-columns:auto 1fr;align-items:center;gap:8px"><input type="hidden" name="is_active" value="0"><input style="width:auto" type="checkbox" name="is_active" value="1" @checked(old('is_active','1')==='1')> {{ __('admin.security.create_active') }}</label>
<div class="span-2 actions"><button class="btn primary" type="submit">{{ __('admin.security.create_user_submit') }}</button></div>
</form>
</details>
@endcan
<span class="badge">{{ $users->total() }} {{ __('admin.security.users') }}</span>
</div>
</div>
<form method="get" class="filters"><input type="hidden" name="section" value="users"><input class="grow" type="text" name="q" value="{{ $search }}" placeholder="{{ __('admin.security.search_users') }}"><select name="status"><option value="all" @selected($statusFilter==='all')>{{ __('admin.security.all_statuses') }}</option><option value="active" @selected($statusFilter==='active')>{{ __('admin.security.active') }}</option><option value="inactive" @selected($statusFilter==='inactive')>{{ __('admin.security.inactive') }}</option></select><button class="btn primary foodex-filter-action">{{ __('admin.security.filter') }}</button></form>

<div class="security-user-table-wrap">
<table class="security-user-table foodex-table" data-security-users-table>
<thead><tr><th>{{ __('admin.security.user') }}</th><th>{{ __('admin.security.assigned_roles') }}</th><th>{{ __('admin.security.access_state') }}</th><th>{{ __('admin.security.actions') }}</th></tr></thead>
<tbody>
@forelse($users as $managedUser)
<tr>
<td><div class="security-user-identity"><strong>{{ $managedUser->name }}</strong><span class="muted">{{ $managedUser->email }}</span><span class="muted">{{ __('admin.security.username') }}: {{ $managedUser->username ?: '—' }}</span></div></td>
<td><div class="security-user-role-list">
@foreach($managedUser->roles as $role)<span class="badge">{{ $roleLabel($role) }}</span>@endforeach
@foreach($managedUser->storeRoleAssignments as $a)<span class="badge">{{ $a->store?->name }} · {{ $roleLabel($a->role) }}</span>@endforeach
@if($managedUser->roles->isEmpty() && $managedUser->storeRoleAssignments->isEmpty())<span class="muted">—</span>@endif
</div></td>
<td>
<span class="badge {{ $managedUser->is_active?'active':'inactive' }}">{{ $managedUser->is_active?__('admin.security.active'):__('admin.security.inactive') }}</span>
<span class="badge {{ $mfaByUser->has($managedUser->id)?'active':'inactive' }}">{{ $mfaByUser->has($managedUser->id)?__('security_governance.mfa_admin.enabled'):__('security_governance.mfa_admin.missing') }}</span>
</td>
<td><div class="security-user-actions">
@can('users.roles.manage')
<details data-foodex-operational-modal><summary class="btn secondary">{{ __('admin.security.manage') }}</summary>
<div class="security-modal-stack">
@include('admin._security-role-assignment-picker', [
    'managedUser' => $managedUser,
    'globalRoles' => $globalRoles,
    'stores' => $stores,
    'grantableStoreRolesByStore' => $grantableStoreRolesByStore,
    'jobRoleMeta' => $jobRoleMeta,
    'globalRoleAvailability' => $globalRoleAvailability,
    'storeRoleAvailabilityByStore' => $storeRoleAvailabilityByStore,
])
<details class="card" data-user-permission-overrides>
<summary>
    {{ __('security_permissions.user_permissions_title') }}
    <span class="badge">{{ __('security_permissions.user_permissions_override_count', ['count' => (int) $permissionOverrideCountsByUser->get($managedUser->id, 0)]) }}</span>
</summary>
<div class="security-modal-stack">
    <div class="note">{{ __('security_permissions.user_permissions_description') }}</div>
    @if($managedUser->roles->contains(fn($role) => $role->code === 'SUPER_ADMIN'))
        <div class="note">{{ __('security_permissions.user_permissions_super_admin_locked') }}</div>
    @else
        @if($managedUser->roles->where('is_active', true)->isNotEmpty())
        <form method="post" action="{{ route('admin.security.users.permissions', $managedUser) }}" data-impact-form>
            @csrf
            @method('put')
            <input type="hidden" name="scope_type" value="global">
            <h3>{{ __('security_permissions.user_permissions_global') }}</h3>
            @include('admin._security-permission-picker', [
                'selectedPermissionIds' => $effectivePermissionIdsByUser->get($managedUser->id, []),
                'permissionGroups' => $permissionGroups,
                'permissionLabels' => $permissionLabels,
                'permissionGroupLabels' => $permissionGroupLabels,
                'privilegedPermissionCodes' => $privilegedPermissionCodes,
                'permissionBoundaryNote' => __('security_permissions.user_permissions_description'),
                'showPermissionScopeRule' => false,
            ])
            <button class="btn primary" type="submit">{{ __('security_permissions.user_permissions_save') }}</button>
        </form>
        @endif

        @foreach($managedUser->storeRoleAssignments->pluck('store')->filter()->unique('id') as $permissionStore)
        <form method="post" action="{{ route('admin.security.users.permissions', $managedUser) }}" data-impact-form>
            @csrf
            @method('put')
            <input type="hidden" name="scope_type" value="store">
            <input type="hidden" name="store_id" value="{{ $permissionStore->id }}">
            <h3>{{ __('security_permissions.user_permissions_store', ['store' => $permissionStore->name]) }}</h3>
            @include('admin._security-permission-picker', [
                'selectedPermissionIds' => ($effectiveStorePermissionIdsByUser->get($managedUser->id, [])[$permissionStore->id] ?? []),
                'permissionGroups' => $userPermissionGroupsByStore->get((int) $permissionStore->id, collect()),
                'permissionLabels' => $userPermissionLabels,
                'permissionGroupLabels' => $userPermissionGroupLabels,
                'privilegedPermissionCodes' => $privilegedPermissionCodes,
                'permissionBoundaryNote' => __('security_permissions.user_permissions_description'),
                'showPermissionScopeRule' => false,
            ])
            <button class="btn primary" type="submit">{{ __('security_permissions.user_permissions_save') }}</button>
        </form>
        @endforeach
    @endif
</div>
</details>
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
</details>
@endcan
@can('users.status.manage')
@if($managedUser->is_active)
<details data-foodex-operational-modal><summary class="btn danger">{{ __('admin.security.deactivate') }}</summary>
<form method="post" action="{{ route('admin.security.users.status',$managedUser) }}" class="security-modal-stack" onsubmit="return confirm('{{ __('admin.security.confirm_deactivate') }}')">@csrf @method('patch')<input type="hidden" name="is_active" value="0"><input type="text" name="reason" maxlength="500" placeholder="{{ __('admin.security.optional_reason') }}"><button class="btn danger">{{ __('admin.security.deactivate') }}</button></form>
</details>
@else
<form method="post" action="{{ route('admin.security.users.status',$managedUser) }}" onsubmit="return confirm('{{ __('admin.security.confirm_activate') }}')">@csrf @method('patch')<input type="hidden" name="is_active" value="1"><button class="btn success">{{ __('admin.security.activate') }}</button></form>
@endif
@endcan
</div></td>
</tr>
@empty<tr><td colspan="4"><div class="note foodex-empty-state">{{ __('admin.security.no_users') }}</div></td></tr>@endforelse
</tbody></table>
</div>
<div class="pager">{{ $users->links() }}</div>
</section>
@endif

@if($securitySection==='governance')
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
@endif

@if($securitySection==='roles')
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

<div class="role-grid">@foreach($roles as $role)@php
    $pids=$role->permissions->pluck('id')->map(fn($id)=>(int)$id)->all();
    $roleMeta=$jobRoleMeta[$role->code] ?? null;
    $roleAvailable=(bool)$roleAvailabilitySummary->get((int)$role->id, true);
    $displayRoleName=is_array($roleMeta) ? $roleMeta['name'] : $role->name;
    $displayRoleDescription=is_array($roleMeta) ? $roleMeta['description'] : ($role->description?:__('admin.security.no_description'));
@endphp
<details class="card {{ $roleAvailable ? '' : 'role-unavailable' }}" data-foodex-operational-modal>
<summary>{{ $displayRoleName }} <span class="badge {{ $role->is_active?'active':'inactive' }}">{{ $role->is_active?__('admin.security.active'):__('admin.security.inactive') }}</span>@if($role->is_system)<span class="badge system">{{ __('admin.security.system_role') }}</span>@endif @if(is_array($roleMeta))<span class="badge">{{ $roleAvailable?__('job_roles.availability.available'):__('job_roles.availability.unavailable') }}</span>@endif</summary>
<div class="job-role-meta">
<p class="muted">{{ $displayRoleDescription }}</p>
@if(is_array($roleMeta))
<div class="job-role-tags">
    <span class="badge">{{ __('job_roles.domain') }}: {{ $roleMeta['domain_label'] }}</span>
    <span class="badge">{{ __('job_roles.scope') }}: {{ $roleMeta['scope_label'] }}</span>
    <span class="badge">{{ __('job_roles.level') }}: {{ $roleMeta['level_label'] }}</span>
    <span class="badge system">{{ __('job_roles.built_in') }}</span>
</div>
@if(($roleMeta['required_modules'] ?? []) !== [])
<p class="muted">{{ __('job_roles.module_required', ['modules'=>implode(', ', $roleMeta['required_modules'])]) }}</p>
@endif
@if(($roleMeta['responsibilities'] ?? []) !== [])
<strong>{{ __('job_roles.responsibilities') }}</strong>
<ul class="job-role-responsibilities">@foreach($roleMeta['responsibilities'] as $responsibility)<li>{{ $responsibility }}</li>@endforeach</ul>
@endif
@if(($roleMeta['permission_summary'] ?? []) !== [])
<strong>{{ __('job_roles.permission_summary') }}</strong>
<div class="job-role-tags">@foreach($roleMeta['permission_summary'] as $summary)<span class="badge">{{ $summary }}</span>@endforeach</div>
@endif
@else
<div class="job-role-tags"><span class="badge">{{ __('job_roles.custom') }}</span></div>
@endif
<div class="muted">{{ __('admin.security.assignments_count') }}: {{ (int)$role->users_count+(int)$role->store_assignments_count }}</div>
</div>
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
@if(is_array($roleMeta))
<div class="note">{{ __('job_roles.errors.canonical_locked') }}</div>
@if(in_array((int) $role->id, $roleOutsideGrantAuthorityIds, true))
<div class="note">{{ __('security_permissions.role_outside_authority') }}</div>
@endif
@else
<div class="note">{{ __('security_permissions.role_outside_authority') }}</div>
@endif
@endif
<div class="actions"><form method="post" action="{{ route('admin.security.roles.clone',$role) }}">@csrf <input name="code" required pattern="[A-Z][A-Z0-9_]{2,49}" placeholder="{{ str_ireplace('B2C','RETAIL',$role->code) }}_COPY"><input name="name" required value="{{ $role->name }} Copy"><button class="btn secondary">{{ __('admin.security.clone') }}</button></form>@if(!$role->is_system)<form method="post" action="{{ route('admin.security.roles.destroy',$role) }}" onsubmit="return confirm('{{ __('admin.security.confirm_delete_role') }}')">@csrf @method('delete')<button class="btn danger">{{ __('admin.security.delete_role') }}</button></form>@endif</div>
@endcan</details>@endforeach</div>
</section>
<div class="note">{{ __('admin.security.server_authoritative') }}</div>
@endif
@include('admin._security-interaction-runtime')
</main>
</div>
</body></html>
