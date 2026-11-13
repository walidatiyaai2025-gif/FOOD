<!doctype html>
<html lang="{{ app()->getLocale() }}" dir="{{ app()->getLocale()==='ar'?'rtl':'ltr' }}">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>{{ __('sidebar_admin.role_access_title') }}</title>
<style>
body{margin:0}.sidebar-access{padding:24px;max-width:1500px;margin:auto}.hero,.role-picker,.access-group,.access-item{background:var(--foodex-surface);border:1px solid var(--foodex-border);border-radius:16px}.hero{padding:20px;margin-bottom:16px}.hero h1{margin:0 0 6px}.muted{color:var(--foodex-muted)}.role-picker{display:flex;gap:12px;align-items:end;flex-wrap:wrap;padding:16px;margin-bottom:16px}.role-picker label{display:grid;gap:6px;font-weight:800;min-width:min(420px,100%)}select,button{font:inherit}.role-picker select{min-height:42px;border:1px solid var(--foodex-border);border-radius:10px;padding:8px 10px;background:var(--foodex-surface);color:var(--foodex-ink)}.notice,.errors{padding:12px 14px;border-radius:12px;margin-bottom:14px}.notice{background:var(--foodex-green-soft)}.errors{background:var(--foodex-orange-soft)}.matrix{display:grid;gap:14px}.access-group{padding:16px}.access-group h2{margin:0 0 12px;font-size:1.05rem}.access-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(300px,1fr));gap:10px}.access-item{padding:12px;display:grid;gap:8px}.access-item.unavailable{opacity:.65;background:var(--foodex-background)}.access-check{display:flex;gap:9px;align-items:flex-start;font-weight:800}.access-check input{margin-top:4px}.permission-check{display:flex;align-items:center;gap:10px;min-height:44px;padding:4px 2px;font-weight:700}.permission-check input{width:22px;height:22px;flex:0 0 auto;accent-color:var(--foodex-green)}.permission-row{display:grid;gap:4px}.permission-row code{direction:ltr;unicode-bidi:isolate;overflow-wrap:anywhere}.read-only-note{padding:12px;border:1px solid var(--foodex-border);border-radius:12px;background:var(--foodex-background)}.meta{display:flex;gap:6px;flex-wrap:wrap}.node-details{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,150px),1fr));gap:8px;margin:0}.node-details>div{min-width:0;padding:8px;border-radius:9px;background:var(--foodex-background)}.node-details dt{font-size:.75rem;color:var(--foodex-muted);font-weight:800}.node-details dd{margin:4px 0 0;overflow-wrap:anywhere;font-size:.88rem}.node-details .code{font-size:.78rem}.node-help{font-size:.78rem}.badge{border-radius:999px;padding:3px 8px;font-size:.75rem;background:var(--foodex-background);border:1px solid var(--foodex-border)}.code{font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:.75rem;overflow-wrap:anywhere}.preview-panel{padding:14px;border:1px solid var(--foodex-border);border-radius:12px;background:var(--foodex-background);margin:10px 0 16px}.preview-panel h2{font-size:1rem;margin:0 0 6px}.preview-status{font-weight:800;margin:5px 0}.preview-reasons{padding-inline-start:20px;margin:6px 0 0;font-size:.85rem}.preview-reasons li{margin-block:3px}.preview-summary{font-weight:700}.actions{position:sticky;bottom:0;display:flex;justify-content:flex-end;padding:14px 0;background:linear-gradient(transparent,var(--foodex-background) 35%)}.save{border:0;border-radius:11px;background:var(--foodex-green);color:#fff;padding:11px 18px;font-weight:900;cursor:pointer}@media(max-width:767px){.sidebar-access{padding:12px}.access-grid{grid-template-columns:1fr}.role-picker{align-items:stretch}.role-picker label{min-width:0;width:100%}}
</style>
<style>
.v2-role-workbench{display:grid;grid-template-columns:minmax(210px,.7fr) minmax(230px,.9fr) minmax(480px,1.9fr);gap:14px;align-items:start}
.v2-role-workbench>.role-picker{display:grid;margin:0;min-width:0;align-content:start}
.v2-role-workbench>.role-picker label{min-width:0;width:100%}
.v2-role-workbench>[data-v2-role-preview-form]{min-width:0}
.v2-role-business-column{min-width:0;display:grid;gap:10px;padding:16px;border:1px solid var(--foodex-border);border-radius:16px;background:var(--foodex-surface)}
.v2-role-business-column h2{font-size:1.05rem;margin:0}
.v2-role-business-column p{margin:0}
.v2-role-business-groups{display:grid;gap:8px}
.v2-role-business-group{display:flex;justify-content:space-between;align-items:center;gap:10px;padding:9px 11px;border:1px solid var(--foodex-border);border-radius:10px;background:var(--foodex-background)}
.v2-role-business-group span{font-weight:700}
.v2-role-business-group strong{font-variant-numeric:tabular-nums}

.v2-role-workbench{grid-template-columns:minmax(180px,.55fr) minmax(210px,.7fr) minmax(420px,1.55fr) minmax(240px,1fr)}
.v2-resulting-sidebar{min-width:0;max-height:75vh;overflow-y:auto;position:sticky;top:12px;padding:14px;border:1px solid var(--foodex-border);border-radius:16px;background:var(--foodex-surface)}
.v2-resulting-sidebar h2{font-size:1.05rem;margin:0 0 8px}
.v2-resulting-sidebar p{margin:0 0 12px}
.v2-result-group{padding:8px 0;border-top:1px solid var(--foodex-border)}
.v2-result-group summary{cursor:pointer;font-weight:800;padding:5px 0}
.v2-result-group ul{list-style:none;margin:8px 0 0;padding:0;display:grid;gap:5px}
.v2-result-item{display:grid;gap:4px;padding:8px;border:1px solid var(--foodex-border);border-radius:9px;overflow-wrap:anywhere}
.v2-result-item-name{font-weight:700;font-size:.85rem}
.v2-result-state{font-size:.76rem;color:var(--foodex-muted)}
.v2-result-item[data-v2-result-state="restricted"]{opacity:.7;background:var(--foodex-background)}
.v2-result-item[data-v2-result-state="hidden"]{border-style:dashed}
@media(max-width:1200px){.v2-role-workbench{grid-template-columns:minmax(0,1fr)}.v2-resulting-sidebar{position:static;max-height:none}}
.role-change-reason{display:grid;gap:8px;margin:14px 0 6px;font-weight:700}
.role-change-reason textarea{width:100%;min-height:96px;padding:10px;resize:vertical;font:inherit;color:var(--foodex-ink);background:var(--foodex-surface);border:1px solid var(--foodex-border);border-radius:10px}
.role-change-reason textarea:focus-visible{outline:2px solid var(--foodex-green);outline-offset:2px}
</style>
@include('admin._brand-components')
</head>
<body>
<div class="foodex-admin-layout" data-foodex-mobile-web-page="sidebar-role-access">
<aside class="sidebar">@include('admin._sidebar',['navGroups'=>$navGroups,'navContext'=>$navContext,'user'=>$user])</aside>
<main class="foodex-admin-main sidebar-access">
@include('admin._global-header')
<div class="hero foodex-page-toolbar">
<div><div class="muted">{{ __('sidebar_admin.eyebrow') }}</div><h1>{{ __('sidebar_admin.role_access_title') }}</h1><p class="muted">{{ __('v2_role_permissions.description') }}</p></div>
<a href="{{ route('admin.security.index',['section'=>'roles']) }}">{{ __('sidebar_admin.edit_rbac') }}</a>
</div>
@if(session('status'))<div class="notice" role="status">{{ session('status') }}</div>@endif
@if($errors->any())<div class="errors" role="alert"><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
<div class="v2-role-workbench" data-v2-role-workbench>
<form method="get" class="role-picker">
<label>{{ __('sidebar_admin.choose_role') }}
<select name="role_id" onchange="this.form.submit()">
@foreach($roles as $role)<option value="{{ $role->id }}" @selected($role->id===$selectedRole->id)>{{ $role->name }} · {{ $role->code }}</option>@endforeach
</select></label>
<noscript><button type="submit">{{ __('sidebar_admin.load_role') }}</button></noscript>
<div class="muted">{{ __('sidebar_admin.role_scope') }}: <strong>{{ __('v2_role_permissions.scope_'.$selectedRole->scope) }}</strong></div>
</form>

@php
    // A role's saved permission codes are not its assigned users' effective
    // grants; tenant, override, channel and module guards remain server-owned.
    $v2BusinessPermissionRoots = ['b2b', 'stores', 'catalog', 'lookups', 'inventory', 'orders', 'customers', 'promotions', 'finance', 'reports', 'support', 'notifications', 'coupons', 'live_ads', 'drivers', 'deliveries', 'van', 'field_ops', 'territories', 'whatsapp', 'smart_ordering', 'chatbot'];
    $v2RoleBusinessPermissions = $selectedRole->permissions
        ->pluck('code')
        ->map(static fn (string $code): string => explode('.', $code, 2)[0])
        ->filter(static fn (string $domain): bool => in_array($domain, $v2BusinessPermissionRoots, true))
        ->countBy()
        ->sortKeys();
@endphp
<section class="v2-role-business-column" data-v2-business-permission-summary="{{ $selectedRole->code }}" data-v2-business-role-scope="{{ $selectedRole->scope }}" aria-labelledby="v2-role-business-summary-title">
<h2 id="v2-role-business-summary-title">{{ __('v2_role_permissions.business_aggregate_title') }}</h2>
<p class="muted">{{ __('sidebar_admin.role_scope') }}: {{ __('v2_role_permissions.scope_'.$selectedRole->scope) }}</p>
<div class="v2-role-business-groups">
@forelse($v2RoleBusinessPermissions as $domain => $count)
<div class="v2-role-business-group" data-v2-business-domain="{{ $domain }}">
<span>{{ __('security_permissions.groups.'.$domain) }}</span>
<strong>{{ __('v2_role_permissions.business_aggregate_count', ['count' => $count]) }}</strong>
</div>
@empty
<p class="muted">{{ __('v2_role_permissions.business_aggregate_none') }}</p>
@endforelse
</div>
<p class="muted">{{ __('v2_role_permissions.business_aggregate_note') }}</p>
</section>

<form method="post" action="{{ route('admin.security.roles.update', $selectedRole) }}"
    data-v2-role-preview-form data-confirm="{{ __('v2_role_permissions.confirm') }}"
    onsubmit="if (!confirm(this.dataset.confirm)) return false; this.querySelector('button[type=submit]').disabled=true; return true;">
@csrf @method('patch')
{{-- This screen edits canonical permission_role via the already audited SecurityController/RbacManager.
     Unrelated permissions are retained so a partial Page Tree does not silently remove other grants.
     Historical sidebar visibility records are deliberately not mutated here. --}}
<input type="hidden" name="name" value="{{ $selectedRole->name }}">
<input type="hidden" name="description" value="{{ $selectedRole->description }}">
<input type="hidden" name="scope" value="{{ $selectedRole->scope }}">
<input type="hidden" name="is_active" value="{{ $selectedRole->is_active ? 1 : 0 }}">
@foreach($preservedPermissionIds as $preservedId)
<input type="hidden" name="permission_ids[]" value="{{ $preservedId }}">
@endforeach
@if(!$canEditRolePermissions)
<p class="read-only-note" role="status">{{ __('v2_role_permissions.read_only') }}</p>
@endif
<p class="muted">{{ __('v2_role_permissions.scope_note') }}</p>
<section class="preview-panel" aria-labelledby="v2-role-preview-title" aria-describedby="v2-role-preview-action-boundary">
<h2 id="v2-role-preview-title">{{ __('v2_role_permissions.preview_title') }}</h2>
<p class="muted">{{ __('v2_role_permissions.preview_description') }}</p>
<p class="preview-summary" role="status" aria-live="polite" data-v2-preview-summary>{{ __('v2_role_permissions.preview_initial') }}</p>
<p class="muted">{{ __('v2_role_permissions.preview_module') }}</p>
<p class="muted">{{ __('v2_role_permissions.preview_server') }}</p>
<p id="v2-role-preview-action-boundary" class="read-only-note" role="note" data-v2-preview-no-action-grant>{{ __('v2_role_permissions.preview_action_boundary') }}</p>
</section>
<div class="matrix">
@php($seenPermissionCodes = [])
@foreach($matrix['groups'] as $group)
<section class="access-group">
<h2>{{ ($group['label_'.app()->getLocale()] ?? null) ?: __($group['label']) }}</h2>
<div class="access-grid">
@foreach($group['items'] as $item)
@php($nodeScope = (string) ($item['meta']['scope'] ?? 'unspecified'))
@php($nodeChannel = (string) ($item['meta']['channel'] ?? 'unspecified'))
@php($nodeModule = (string) ($item['meta']['module'] ?? ''))
@php($nodeModuleReady = $nodeModule === '' ? null : ($moduleReadinessByCode[$nodeModule] ?? false))
@php($nodeScopeAllowed = ! in_array($nodeScope, ['global', 'store'], true) || $selectedRole->scope === $nodeScope)
@php($roleChannelListKey = $selectedRole->scope === 'store' ? 'store_roles' : 'global_roles')
@php($nodeChannelRoles = in_array($nodeChannel, ['b2b', 'b2c'], true) ? (array) config('admin.channels.'.$nodeChannel.'.'.$roleChannelListKey, []) : [])
@php($nodeChannelAllowed = $nodeChannelRoles === [] || in_array($selectedRole->code, $nodeChannelRoles, true))
@php($nodeRoleExcluded = in_array($selectedRole->code, (array) ($item['meta']['exclude_roles'] ?? []), true))
<article class="access-item {{ $item['global_enabled']?'':'unavailable' }}" data-v2-role-preview-node data-v2-role-node="{{ $item['key'] }}"
    data-preview-permissions="{{ implode('|', $item['permissions']) }}" data-preview-mode="{{ $item['permission_mode'] }}"
    data-preview-scope-ok="{{ $nodeScopeAllowed ? '1' : '0' }}" data-preview-channel-ok="{{ $nodeChannelAllowed ? '1' : '0' }}"
    data-preview-excluded="{{ $nodeRoleExcluded ? '1' : '0' }}" data-preview-privileged="{{ $selectedRole->code === 'SUPER_ADMIN' ? '1' : '0' }}"
    data-preview-enabled="{{ $item['global_enabled'] ? '1' : '0' }}" data-preview-hidden="{{ $item['override'] === false ? '1' : '0' }}"
    data-preview-module="{{ $nodeModule !== '' ? '1' : '0' }}" data-preview-module-ready="{{ $nodeModuleReady === null ? 'unknown' : ($nodeModuleReady ? '1' : '0') }}">
<div class="access-check">
<strong>{{ ($item['label_'.app()->getLocale()] ?? null) ?: __($item['label']) }}</strong>
</div>
<div class="meta">
<span class="badge code">{{ $item['key'] }}</span>
@if(!$item['base_allowed'])<span class="badge">{{ __('sidebar_admin.not_allowed_by_rbac') }}</span>@endif
@if(!$item['global_enabled'])<span class="badge">{{ __('sidebar_admin.globally_disabled') }}</span>@endif
@if($nodeModuleReady === false)<span class="badge">{{ __('v2_role_permissions.preview_module_blocked') }}</span>@endif
@if($item['override']===false)<span class="badge">{{ __('v2_role_permissions.hidden_by_preference') }}</span>@endif
</div>
@if($item['permissions']===[])
<p class="muted">{{ __('v2_role_permissions.no_explicit_code') }}</p>
@else
<div class="permission-row">
@foreach($item['permissions'] as $code)
@php($permission = $pagePermissionsByCode->get($code))
@if($permission===null)
<span class="muted">{{ __('v2_role_permissions.missing_code') }}: <code>{{ $code }}</code></span>
@elseif(!isset($seenPermissionCodes[$code]))
@php($seenPermissionCodes[$code] = true)
<label class="permission-check">
<input type="checkbox" name="permission_ids[]" value="{{ $permission->id }}"
    data-v2-role-permission-code="{{ $code }}" @checked(in_array((int) $permission->id, $selectedPermissionIds, true)) @disabled(!$canEditRolePermissions)>
<span>{{ __('v2_role_permissions.permission_code') }} <code>{{ $code }}</code></span>
</label>
@else
<span class="muted">{{ __('v2_role_permissions.shared_code') }}: <code>{{ $code }}</code></span>
@endif
@endforeach
</div>
@endif
<dl class="node-details">
<div><dt>{{ __('v2_role_tree.name') }}</dt><dd>{{ ($item['label_'.app()->getLocale()] ?? null) ?: __($item['label']) }}</dd></div>
<div><dt>{{ __('v2_role_tree.code') }}</dt><dd class="code">{{ $item['key'] }}</dd></div>
<div><dt>{{ __('v2_role_tree.permissions') }}</dt><dd class="code">@if($item['permissions'] !== []){{ implode($item['permission_mode'] === 'any' ? ' | ' : ' + ', $item['permissions']) }}@else{{ __('v2_role_tree.permissions_unspecified') }}@endif</dd></div>
<div><dt>{{ __('v2_role_tree.scope') }}</dt><dd>{{ __('v2_role_tree.scopes.'.(in_array($nodeScope, ['global', 'store', 'both'], true) ? $nodeScope : 'unspecified')) }}</dd></div>
<div><dt>{{ __('v2_role_tree.channel') }}</dt><dd>{{ __('v2_role_tree.channels.'.(in_array($nodeChannel, ['b2b', 'b2c'], true) ? $nodeChannel : 'unspecified')) }}</dd></div>
<div><dt>{{ __('v2_role_tree.module') }}</dt><dd>
@if($nodeModule === '')
{{ __('v2_role_tree.module_unspecified') }}
@elseif(in_array($nodeModule, ['procurement', 'inventory_wms', 'finance', 'treasury', 'enterprise_bi'], true))
{{ __('v2_role_tree.modules.'.$nodeModule) }} <span class="code">({{ $nodeModule }})</span>
@else
{{ __('v2_role_tree.module_unknown') }} <span class="code">({{ $nodeModule }})</span>
@endif
</dd></div>
</dl>
<div class="preview-panel" data-v2-preview-details>
<p class="preview-status" data-v2-preview-status>{{ __('v2_role_permissions.preview_initial') }}</p>
<ul class="preview-reasons" data-v2-preview-reasons><li>{{ __('v2_role_permissions.preview_server') }}</li></ul>
</div>
</article>
@endforeach
</div>
</section>
@endforeach
</div>
@if($canEditRolePermissions)
<label class="role-change-reason" for="v2-role-change-reason">
    {{ __('security_permissions.change_reason') }}
    <textarea id="v2-role-change-reason" name="change_reason" required minlength="8" maxlength="500" autocomplete="off"
        aria-describedby="v2-role-change-reason-hint" placeholder="{{ __('security_permissions.change_reason_hint') }}">{{ old('change_reason') }}</textarea>
</label>
<p id="v2-role-change-reason-hint" class="muted">{{ __('security_permissions.change_reason_hint') }}</p>
<div class="actions"><button class="save foodex-action-primary" type="submit">{{ __('v2_role_permissions.save') }}</button></div>
@endif
</form>

<aside class="v2-resulting-sidebar" data-v2-result-tree aria-labelledby="v2-result-tree-title" aria-describedby="v2-result-tree-note">
<h2 id="v2-result-tree-title">{{ __('v2_role_permissions.result_tree_title') }}</h2>
<p id="v2-result-tree-note" class="muted">{{ __('v2_role_permissions.result_tree_note') }}</p>
@foreach($matrix['groups'] as $group)
<details class="v2-result-group" open>
<summary>{{ ($group['label_'.app()->getLocale()] ?? null) ?: __($group['label']) }}</summary>
<ul>
@foreach($group['items'] as $item)
<li class="v2-result-item" data-v2-result-item="{{ $item['key'] }}" data-v2-result-state="pending">
<span class="v2-result-item-name">{{ ($item['label_'.app()->getLocale()] ?? null) ?: __($item['label']) }}</span>
<span class="v2-result-state" data-v2-result-status>{{ __('v2_role_permissions.preview_initial') }}</span>
</li>
@endforeach
</ul>
</details>
@endforeach
<p class="muted">{{ __('v2_role_permissions.result_tree_action_boundary') }}</p>
</aside>
</div>
</main>
</div>
<script>
(() => {
    'use strict';
    // Role-level, read-only what-if projection. Never grants RBAC or claims
    // effective tenant/store/module authorization; the canonical server does.
    const form = document.querySelector('[data-v2-role-preview-form]');
    if (!form) return;
    const copy = {
        eligible: @json(__('v2_role_permissions.preview_eligible')),
        denied: @json(__('v2_role_permissions.preview_denied')),
        hiddenStatus: @json(__('v2_role_permissions.preview_hidden_status')),
        disabled: @json(__('v2_role_permissions.preview_disabled')),
        excluded: @json(__('v2_role_permissions.preview_excluded')),
        scope: @json(__('v2_role_permissions.preview_scope')),
        channel: @json(__('v2_role_permissions.preview_channel')),
        permissions: @json(__('v2_role_permissions.preview_permissions')),
        preference: @json(__('v2_role_permissions.preview_preference')),
        module: @json(__('v2_role_permissions.preview_module')),
        moduleBlocked: @json(__('v2_role_permissions.preview_module_blocked')),
        server: @json(__('v2_role_permissions.preview_server')),
        summary: @json(__('v2_role_permissions.preview_summary'))
    };
    const nodes = Array.from(form.querySelectorAll('[data-v2-role-preview-node]'));
    const resultTree = document.querySelector('[data-v2-result-tree]');
    const resultItems = new Map(Array.from(resultTree?.querySelectorAll('[data-v2-result-item]') || [], element => [element.dataset.v2ResultItem, element]));
    const summary = form.querySelector('[data-v2-preview-summary]');
    const refresh = () => {
        const selected = new Set(Array.from(
            form.querySelectorAll('[data-v2-role-permission-code]:checked'),
            input => input.dataset.v2RolePermissionCode
        ));
        let eligibleCount = 0;
        let restrictedCount = 0;
        for (const node of nodes) {
            const reasons = [];
            if (node.dataset.previewEnabled !== '1') reasons.push(copy.disabled);
            if (node.dataset.previewExcluded === '1') reasons.push(copy.excluded);
            if (node.dataset.previewScopeOk !== '1') reasons.push(copy.scope);
            if (node.dataset.previewChannelOk !== '1') reasons.push(copy.channel);
            if (node.dataset.previewModuleReady === '0') reasons.push(copy.moduleBlocked);
            const required = (node.dataset.previewPermissions || '').split('|').filter(Boolean);
            const matched = required.filter(code => selected.has(code)).length;
            const permissionOk = node.dataset.previewPrivileged === '1' || required.length === 0 ||
                (node.dataset.previewMode === 'any' ? matched > 0 : matched === required.length);
            if (!permissionOk) reasons.push(copy.permissions);
            const restricted = reasons.length > 0;
            const hidden = node.dataset.previewHidden === '1';
            if (hidden) reasons.push(copy.preference);
            reasons.push(node.dataset.previewModule === '1' ? copy.module : copy.server);
            if (node.dataset.previewModule === '1') reasons.push(copy.server);
            const status = node.querySelector('[data-v2-preview-status]');
            status.textContent = restricted ? copy.denied : (hidden ? copy.hiddenStatus : copy.eligible);
            const result = resultItems.get(node.dataset.v2RoleNode);
            if (result) {
                result.dataset.v2ResultState = restricted ? 'restricted' : (hidden ? 'hidden' : 'eligible');
                result.querySelector('[data-v2-result-status]').textContent = status.textContent;
            }
            const reasonList = node.querySelector('[data-v2-preview-reasons]');
            reasonList.replaceChildren();
            for (const reason of reasons) {
                const li = document.createElement('li');
                li.textContent = reason;
                reasonList.appendChild(li);
            }
            if (restricted || hidden) restrictedCount += 1;
            else eligibleCount += 1;
        }
        summary.textContent = copy.summary.replace(':eligible', String(eligibleCount))
            .replace(':restricted', String(restrictedCount));
    };
    form.addEventListener('change', event => {
        if (event.target.matches('[data-v2-role-permission-code]')) refresh();
    });
    refresh();
})();
</script>
</body></html>
