<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>{{ __('admin.security.title') }} · FOODEX</title>
<style>
:root{font-family:Inter,ui-sans-serif,system-ui,sans-serif;color:#17202a;background:#f5f7fa}*{box-sizing:border-box}body{margin:0}.page{max-width:1500px;margin:auto;padding:28px}.top,.head,.row{display:flex;gap:14px;justify-content:space-between;align-items:flex-start}.top{margin-bottom:22px}.muted{color:#64748b;line-height:1.55}.back,.card,.panel{background:#fff;border:1px solid #e2e8f0;border-radius:16px}.back{padding:10px 14px;text-decoration:none;color:#0f172a}.panel{padding:22px;margin-bottom:22px;box-shadow:0 14px 35px rgba(15,23,42,.05)}.card{padding:16px;margin-top:12px}.filters,.checks,.actions{display:flex;flex-wrap:wrap;gap:9px}.filters{margin:16px 0}.checks{margin:10px 0}.actions{align-items:center}input,select,textarea,button{font:inherit}input[type=text],select,textarea{border:1px solid #cbd5e1;border-radius:10px;padding:9px 11px;background:#fff}textarea{width:100%;min-height:65px}.grow{flex:1;min-width:240px}.btn{border:0;border-radius:10px;padding:9px 13px;font-weight:750;cursor:pointer}.primary{background:#0f172a;color:#fff}.danger{background:#fff1f2;color:#9f1239}.success{background:#ecfdf5;color:#065f46}.secondary{background:#eef2ff;color:#3730a3}.badge{display:inline-block;border-radius:999px;padding:4px 8px;font-size:.75rem;background:#e2e8f0;margin:3px}.active{background:#dcfce7;color:#166534}.inactive{background:#fee2e2;color:#991b1b}.system{background:#e0e7ff;color:#3730a3}details{margin-top:12px}summary{cursor:pointer;font-weight:800}.store{border:1px dashed #cbd5e1;border-radius:12px;padding:10px;margin:8px 0}.permissions{display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:8px}.permission-group{border-top:1px solid #e2e8f0;padding-top:10px}.notice,.errors{padding:12px 14px;border-radius:12px;margin-bottom:14px}.notice{background:#f0fdf4}.errors{background:#fff1f2}.role-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(330px,1fr));gap:12px}.note{background:#f8fafc;padding:12px;border-radius:12px;color:#475569}.pager{margin-top:14px}@media(max-width:760px){.page{padding:16px}.top,.head,.row{flex-direction:column}.role-grid{grid-template-columns:1fr}}
</style>
    @include('admin._brand-components')
</head>
<body><main class="page foodex-admin-page" data-foodex-utility="security">
<header class="top foodex-page-header"><div><div class="muted">FOODEX · {{ __('admin.security.eyebrow') }}</div><h1>{{ __('admin.security.title') }}</h1><p class="muted">{{ __('admin.security.description') }}</p></div><a class="back" href="{{ route('admin.index') }}">{{ __('admin.security.back') }}</a></header>
@if(session('status'))<div class="notice foodex-state" role="status">{{ session('status') }}</div>@endif
@if($errors->any())<div class="errors foodex-state" role="alert"><strong>{{ __('admin.security.validation_failed') }}</strong><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif

<section class="panel">
<div class="head"><div><h2>{{ __('admin.security.users_title') }}</h2><div class="muted">{{ __('admin.security.users_description') }}</div></div><span class="badge">{{ $users->total() }} {{ __('admin.security.users') }}</span></div>
<form method="get" class="filters"><input class="grow" type="text" name="q" value="{{ $search }}" placeholder="{{ __('admin.security.search_users') }}"><select name="status"><option value="all" @selected($statusFilter==='all')>{{ __('admin.security.all_statuses') }}</option><option value="active" @selected($statusFilter==='active')>{{ __('admin.security.active') }}</option><option value="inactive" @selected($statusFilter==='inactive')>{{ __('admin.security.inactive') }}</option></select><button class="btn primary">{{ __('admin.security.filter') }}</button></form>

@forelse($users as $managedUser)
@php
$globalIds=$managedUser->roles->pluck('id')->map(fn($id)=>(int)$id)->all();
$byStore=$managedUser->storeRoleAssignments->groupBy('store_id');
@endphp
<article class="card">
<div class="head"><div><h3 style="margin:0">{{ $managedUser->name }}</h3><div class="muted">{{ $managedUser->email }}</div><div><span class="badge {{ $managedUser->is_active?'active':'inactive' }}">{{ $managedUser->is_active?__('admin.security.active'):__('admin.security.inactive') }}</span>@foreach($managedUser->roles as $role)<span class="badge">{{ $role->code }}</span>@endforeach @foreach($managedUser->storeRoleAssignments as $a)<span class="badge">{{ $a->store?->code }} · {{ $a->role?->code }}</span>@endforeach</div>@if(!$managedUser->is_active&&$managedUser->deactivation_reason)<div class="muted">{{ __('admin.security.reason') }}: {{ $managedUser->deactivation_reason }}</div>@endif</div>
@can('users.status.manage')<form method="post" action="{{ route('admin.security.users.status',$managedUser) }}" onsubmit="return confirm('{{ $managedUser->is_active?__('admin.security.confirm_deactivate'):__('admin.security.confirm_activate') }}')">@csrf @method('patch')<input type="hidden" name="is_active" value="{{ $managedUser->is_active?0:1 }}">@if($managedUser->is_active)<input type="text" name="reason" maxlength="500" placeholder="{{ __('admin.security.optional_reason') }}"><button class="btn danger">{{ __('admin.security.deactivate') }}</button>@else<button class="btn success">{{ __('admin.security.activate') }}</button>@endif</form>@endcan
</div>
@can('users.roles.manage')
<details><summary>{{ __('admin.security.manage_assignments') }}</summary><form method="post" action="{{ route('admin.security.users.roles',$managedUser) }}">@csrf @method('put')
<h4>{{ __('admin.security.global_roles') }}</h4><div class="checks">@foreach($globalRoles as $role)<label><input type="checkbox" name="global_role_ids[]" value="{{ $role->id }}" @checked(in_array((int)$role->id,$globalIds,true))> {{ $role->name }} <small>({{ $role->code }})</small></label>@endforeach</div>
@if($stores->isNotEmpty()&&$storeRoles->isNotEmpty())<h4>{{ __('admin.security.store_roles') }}</h4>@foreach($stores as $store)@php $assigned=($byStore->get($store->id)??collect())->pluck('role_id')->map(fn($id)=>(int)$id)->all(); @endphp<div class="store"><strong>{{ $store->name }} ({{ $store->code }})</strong><div class="checks">@foreach($storeRoles as $role)<label><input type="checkbox" name="store_role_ids[{{ $store->id }}][]" value="{{ $role->id }}" @checked(in_array((int)$role->id,$assigned,true))> {{ $role->name }}</label>@endforeach</div></div>@endforeach @endif
<button class="btn primary">{{ __('admin.security.save_assignments') }}</button></form></details>
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
<div class="note" style="margin-top:14px">{{ __('admin.security.demo_data.seed_command') }} <code>php artisan db:seed --class=Database\\Seeders\\ProductDemoSeeder</code></div>
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
<details class="card"><summary>{{ __('admin.security.create_role') }}</summary><form method="post" action="{{ route('admin.security.roles.store') }}">@csrf
<div class="filters"><input class="grow" name="code" required maxlength="50" pattern="[A-Z][A-Z0-9_]{2,49}" placeholder="CUSTOM_ROLE"><input class="grow" name="name" required maxlength="120" placeholder="{{ __('admin.security.role_name') }}"><select name="scope"><option value="global">{{ __('admin.security.scope_global') }}</option><option value="store">{{ __('admin.security.scope_store') }}</option><option value="both">{{ __('admin.security.scope_both') }}</option></select><label><input type="hidden" name="is_active" value="0"><input type="checkbox" name="is_active" value="1" checked> {{ __('admin.security.active') }}</label></div><textarea name="description" placeholder="{{ __('admin.security.role_description') }}"></textarea>
<div class="permissions">@foreach($permissionGroups as $module=>$group)<div class="permission-group"><strong>{{ $module }}</strong>@foreach($group as $permission)<label style="display:block"><input type="checkbox" name="permission_ids[]" value="{{ $permission->id }}"> {{ $permission->code }}</label>@endforeach</div>@endforeach</div><button class="btn primary">{{ __('admin.security.create_role') }}</button></form></details>
@endcan

<div class="role-grid">@foreach($roles as $role)@php $pids=$role->permissions->pluck('id')->map(fn($id)=>(int)$id)->all(); @endphp
<details class="card"><summary>{{ $role->name }} · {{ $role->code }} <span class="badge {{ $role->is_active?'active':'inactive' }}">{{ $role->is_active?__('admin.security.active'):__('admin.security.inactive') }}</span>@if($role->is_system)<span class="badge system">{{ __('admin.security.system_role') }}</span>@endif</summary><p class="muted">{{ $role->description?:__('admin.security.no_description') }}</p><div class="muted">{{ __('admin.security.assignments_count') }}: {{ (int)$role->users_count+(int)$role->store_assignments_count }}</div>
@can('roles.manage')<form method="post" action="{{ route('admin.security.roles.update',$role) }}">@csrf @method('patch')<div class="filters"><input class="grow" name="name" value="{{ $role->name }}" required>@if($role->is_system)<input type="hidden" name="scope" value="{{ $role->scope }}"><span class="note">{{ __('admin.security.scope') }}: {{ $role->scope }}</span>@else<select name="scope"><option value="global" @selected($role->scope==='global')>{{ __('admin.security.scope_global') }}</option><option value="store" @selected($role->scope==='store')>{{ __('admin.security.scope_store') }}</option><option value="both" @selected($role->scope==='both')>{{ __('admin.security.scope_both') }}</option></select>@endif @if($role->code==='SUPER_ADMIN')<input type="hidden" name="is_active" value="1"><span class="note">{{ __('admin.security.super_admin_protected') }}</span>@else<label><input type="hidden" name="is_active" value="0"><input type="checkbox" name="is_active" value="1" @checked($role->is_active)> {{ __('admin.security.active') }}</label>@endif</div><textarea name="description">{{ $role->description }}</textarea><div class="permissions">@foreach($permissionGroups as $module=>$group)<div class="permission-group"><strong>{{ $module }}</strong>@foreach($group as $permission)<label style="display:block"><input type="checkbox" name="permission_ids[]" value="{{ $permission->id }}" @checked(in_array((int)$permission->id,$pids,true))> {{ $permission->code }}</label>@endforeach</div>@endforeach</div><button class="btn primary">{{ __('admin.security.save_role') }}</button></form>
<div class="actions"><form method="post" action="{{ route('admin.security.roles.clone',$role) }}">@csrf <input name="code" required pattern="[A-Z][A-Z0-9_]{2,49}" placeholder="{{ $role->code }}_COPY"><input name="name" required value="{{ $role->name }} Copy"><button class="btn secondary">{{ __('admin.security.clone') }}</button></form>@if(!$role->is_system)<form method="post" action="{{ route('admin.security.roles.destroy',$role) }}" onsubmit="return confirm('{{ __('admin.security.confirm_delete_role') }}')">@csrf @method('delete')<button class="btn danger">{{ __('admin.security.delete_role') }}</button></form>@endif</div>
@endcan</details>@endforeach</div>
</section>
<div class="note">{{ __('admin.security.server_authoritative') }}</div>
</main></body></html>
