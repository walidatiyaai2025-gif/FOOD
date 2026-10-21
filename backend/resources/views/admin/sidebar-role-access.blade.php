<!doctype html>
<html lang="{{ app()->getLocale() }}" dir="{{ app()->getLocale()==='ar'?'rtl':'ltr' }}">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>{{ __('sidebar_admin.role_access_title') }}</title>
@include('admin._theme')
<style>
body{margin:0}.sidebar-access{padding:24px;max-width:1500px;margin:auto}.hero,.role-picker,.access-group,.access-item{background:var(--foodex-surface);border:1px solid var(--foodex-border);border-radius:16px}.hero{padding:20px;margin-bottom:16px}.hero h1{margin:0 0 6px}.muted{color:var(--foodex-muted)}.role-picker{display:flex;gap:12px;align-items:end;flex-wrap:wrap;padding:16px;margin-bottom:16px}.role-picker label{display:grid;gap:6px;font-weight:800;min-width:min(420px,100%)}select,button{font:inherit}.role-picker select{min-height:42px;border:1px solid var(--foodex-border);border-radius:10px;padding:8px 10px;background:var(--foodex-surface);color:var(--foodex-ink)}.notice,.errors{padding:12px 14px;border-radius:12px;margin-bottom:14px}.notice{background:#f0fdf4}.errors{background:#fff1f2}.matrix{display:grid;gap:14px}.access-group{padding:16px}.access-group h2{margin:0 0 12px;font-size:1.05rem}.access-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(300px,1fr));gap:10px}.access-item{padding:12px;display:grid;gap:8px}.access-item.unavailable{opacity:.65;background:var(--foodex-background)}.access-check{display:flex;gap:9px;align-items:flex-start;font-weight:800}.access-check input{margin-top:4px}.meta{display:flex;gap:6px;flex-wrap:wrap}.badge{border-radius:999px;padding:3px 8px;font-size:.75rem;background:var(--foodex-background);border:1px solid var(--foodex-border)}.code{font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:.75rem;overflow-wrap:anywhere}.actions{position:sticky;bottom:0;display:flex;justify-content:flex-end;padding:14px 0;background:linear-gradient(transparent,var(--foodex-background) 35%)}.save{border:0;border-radius:11px;background:var(--foodex-green);color:#fff;padding:11px 18px;font-weight:900;cursor:pointer}@media(max-width:767px){.sidebar-access{padding:12px}.access-grid{grid-template-columns:1fr}.role-picker{align-items:stretch}.role-picker label{min-width:0;width:100%}}
</style>
@include('admin._brand-components')
</head>
<body>
<div class="foodex-admin-layout" data-foodex-mobile-web-page="sidebar-role-access">
<aside class="sidebar">@include('admin._sidebar',['navGroups'=>$navGroups,'navContext'=>$navContext,'user'=>$user])</aside>
<main class="foodex-admin-main sidebar-access">
@include('admin._global-header')
<div class="hero foodex-page-toolbar">
<div><div class="muted">{{ __('sidebar_admin.eyebrow') }}</div><h1>{{ __('sidebar_admin.role_access_title') }}</h1><p class="muted">{{ __('sidebar_admin.role_access_description') }}</p></div>
<a href="{{ route('admin.security.index',['section'=>'roles']) }}">{{ __('sidebar_admin.edit_rbac') }}</a>
</div>
@if(session('status'))<div class="notice" role="status">{{ session('status') }}</div>@endif
@if($errors->any())<div class="errors" role="alert"><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
<form method="get" class="role-picker">
<label>{{ __('sidebar_admin.choose_role') }}
<select name="role_id" onchange="this.form.submit()">
@foreach($roles as $role)<option value="{{ $role->id }}" @selected($role->id===$selectedRole->id)>{{ $role->name }} · {{ $role->code }}</option>@endforeach
</select></label>
<noscript><button type="submit">{{ __('sidebar_admin.load_role') }}</button></noscript>
<div class="muted">{{ __('sidebar_admin.role_scope') }}: <strong>{{ $selectedRole->scope }}</strong></div>
</form>

<form method="post" action="{{ route('admin.sidebar-access.roles.update',$selectedRole) }}">
@csrf @method('put')
<div class="matrix">
@foreach($matrix['groups'] as $group)
<section class="access-group">
<h2>{{ ($group['label_'.app()->getLocale()] ?? null) ?: __($group['label']) }}</h2>
<div class="access-grid">
@foreach($group['items'] as $item)
@php($canToggle = $item['base_allowed'] && $item['global_enabled'])
<article class="access-item {{ $canToggle?'':'unavailable' }}">
<label class="access-check">
<input type="checkbox" name="item_keys[]" value="{{ $item['key'] }}" @checked($item['visible_for_role']) @disabled(!$canToggle)>
<span>{{ ($item['label_'.app()->getLocale()] ?? null) ?: __($item['label']) }}</span>
</label>
<div class="meta">
<span class="badge code">{{ $item['key'] }}</span>
@if(!$item['base_allowed'])<span class="badge">{{ __('sidebar_admin.not_allowed_by_rbac') }}</span>@endif
@if(!$item['global_enabled'])<span class="badge">{{ __('sidebar_admin.globally_disabled') }}</span>@endif
@if($item['override']===null)<span class="badge">{{ __('sidebar_admin.inherited') }}</span>@elseif($item['override'])<span class="badge">{{ __('sidebar_admin.explicit_visible') }}</span>@else<span class="badge">{{ __('sidebar_admin.explicit_hidden') }}</span>@endif
</div>
@if($item['permissions']!==[])
<div class="muted code">{{ __('sidebar_admin.required_permissions') }}: {{ implode($item['permission_mode']==='any'?' | ':' + ',$item['permissions']) }}</div>
@endif
</article>
@endforeach
</div>
</section>
@endforeach
</div>
<div class="actions"><button class="save" type="submit">{{ __('sidebar_admin.save_role_access') }}</button></div>
</form>
</main>
</div>
</body></html>
