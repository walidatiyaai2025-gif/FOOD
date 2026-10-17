<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>{{ __('enterprise_security.title') }} · FOODEX</title>
@include('admin._brand-components')
<style>
body{margin:0;background:var(--foodex-background);color:var(--foodex-ink)}
.es-page{padding:clamp(16px,2.4vw,34px)}.es-head{display:flex;justify-content:space-between;gap:18px;align-items:flex-start;margin-block:18px 18px}.es-head h1{margin:4px 0 8px;font-size:clamp(1.55rem,2.5vw,2.15rem)}.es-head p{margin:0;color:var(--foodex-muted);max-width:820px}
.es-back,.es-link{display:inline-flex;min-height:42px;align-items:center;justify-content:center;padding-inline:13px;border:1px solid var(--foodex-border);border-radius:10px;background:#fff;color:var(--foodex-green-dark);text-decoration:none;font-weight:800}
.es-tabs{display:flex;gap:8px;overflow:auto;margin-bottom:18px}.es-tabs a{white-space:nowrap}.es-tabs .active{background:var(--foodex-green-soft);border-color:var(--foodex-green);color:var(--foodex-green-dark)}
.es-notice{padding:12px 14px;border:1px solid var(--foodex-border);border-radius:10px;background:#fff;margin-bottom:12px}.es-note{padding:12px;border-radius:10px;background:var(--foodex-green-soft);color:var(--foodex-green-dark);margin-bottom:16px}
.es-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px;margin-bottom:16px}.es-card{background:#fff;border:1px solid var(--foodex-border);border-radius:var(--foodex-radius-card);padding:17px;box-shadow:var(--foodex-shadow-sm);min-width:0}.es-card h2,.es-card h3{margin-top:0}.es-help{color:var(--foodex-muted);line-height:1.55;margin-top:-5px}
.es-form{display:grid;gap:10px}.es-form label{display:grid;gap:5px;font-weight:700}.es-form select,.es-form input,.es-form textarea{min-height:42px;border:1px solid var(--foodex-border);border-radius:9px;padding:8px 10px;background:#fff;color:var(--foodex-ink);font:inherit;max-width:100%}.es-form textarea{min-height:72px;resize:vertical}
.es-button{min-height:42px;border:0;border-radius:9px;background:var(--foodex-green);color:#fff;font:inherit;font-weight:800;padding:8px 13px;cursor:pointer}.es-button.secondary{background:var(--foodex-green-soft);color:var(--foodex-green-dark);border:1px solid var(--foodex-green)}.es-button.danger{background:var(--foodex-danger-soft);color:var(--foodex-danger-text);border:1px solid var(--foodex-border)}
.es-list{display:grid;gap:10px}.es-row{display:grid;grid-template-columns:minmax(0,1fr) auto;gap:12px;align-items:start;padding:12px;border:1px solid var(--foodex-border);border-radius:11px}.es-row p{margin:4px 0}.es-meta{color:var(--foodex-muted);font-size:.9rem;line-height:1.55}.es-actions{display:flex;gap:7px;flex-wrap:wrap;align-items:center;justify-content:flex-end}.es-badge{display:inline-flex;align-items:center;min-height:26px;padding:3px 8px;border-radius:999px;background:var(--foodex-green-soft);color:var(--foodex-green-dark);font-size:.82rem;font-weight:800}.es-badge.muted{background:var(--foodex-background);color:var(--foodex-muted)}
.es-pager{margin-top:12px}.es-empty{color:var(--foodex-muted);padding:12px 0}.es-wide{grid-column:1/-1}
@media(max-width:900px){.es-grid{grid-template-columns:1fr}.es-head{flex-direction:column}.es-back{width:100%}.es-row{grid-template-columns:1fr}.es-actions{justify-content:flex-start}.es-wide{grid-column:auto}}
</style>
</head>
<body>
<div class="foodex-admin-layout" data-foodex-mobile-web-page="enterprise-security">
<aside class="sidebar">@include('admin._sidebar',['navGroups'=>$navGroups,'navContext'=>$navContext,'user'=>$user])</aside> {{-- localization-gate: allow Blade include expression --}}
<main class="foodex-admin-main foodex-admin-page es-page" data-enterprise-security-center="true">
@include('admin._global-header')
@include('admin._admin-center-switcher',['active'=>'administration'])
<header class="es-head foodex-page-toolbar"><div><div class="foodex-subtitle">FOODEX · {{ __('enterprise_security.eyebrow') }}</div><h1>{{ __('enterprise_security.title') }}</h1><p>{{ __('enterprise_security.description') }}</p></div><a class="es-back" href="{{ route('admin.security.index') }}">{{ __('enterprise_security.back') }}</a></header>
<nav class="es-tabs foodex-tabs" aria-label="{{ __('enterprise_security.eyebrow') }}"><a class="es-link" href="{{ route('admin.security.index') }}">{{ __('admin.security.title') }}</a><a class="es-link active" aria-current="page" href="{{ route('admin.enterprise-security.index') }}">{{ __('enterprise_security.title') }}</a></nav>

@if(session('status'))<div class="es-notice" role="status">{{ session('status') }}</div>@endif
@if($errors->any())<div class="es-notice" role="alert"><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
<div class="es-note"><strong>{{ __('enterprise_security.maker_checker') }}</strong> · {{ __('enterprise_security.overview_help') }}</div>

<div class="es-grid">
@can('security.scopes.manage')
<section class="es-card" data-enterprise-security-scope-form>
<h2>{{ __('enterprise_security.role_scopes') }}</h2><p class="es-help">{{ __('enterprise_security.role_scopes_help') }}</p>
<form class="es-form" method="post" action="{{ route('admin.enterprise-security.role-scopes.store') }}">@csrf
<label>{{ __('enterprise_security.role') }}<select name="role_id" required>@foreach($roles as $role)<option value="{{ $role->id }}">{{ $role->name }}</option>@endforeach</select></label>
<label>{{ __('enterprise_security.scope') }}<select name="scope_key" required>@foreach($scopeOptions as $scope)<option value="{{ $scope['key'] }}">{{ $scope['label'] }}</option>@endforeach</select></label>
<button class="es-button" type="submit">{{ __('enterprise_security.create_scope') }}</button>
</form>
</section>
@endcan

@can('security.approval_limits.manage')
<section class="es-card" data-enterprise-security-limit-form>
<h2>{{ __('enterprise_security.approval_limits') }}</h2><p class="es-help">{{ __('enterprise_security.approval_limits_help') }}</p>
<form class="es-form" method="post" action="{{ route('admin.enterprise-security.approval-limits.store') }}">@csrf
<label>{{ __('enterprise_security.role') }}<select name="role_id" required>@foreach($roles as $role)<option value="{{ $role->id }}">{{ $role->name }}</option>@endforeach</select></label>
<label>{{ __('enterprise_security.permission') }}<select name="permission_code" required>@foreach($permissionLabels as $code=>$label)<option value="{{ $code }}">{{ $label }}</option>@endforeach</select></label>
<label>{{ __('enterprise_security.scope') }}<select name="scope_key" required>@foreach($scopeOptions as $scope)<option value="{{ $scope['key'] }}">{{ $scope['label'] }}</option>@endforeach</select></label>
<div style="display:grid;grid-template-columns:minmax(100px,.35fr) minmax(0,1fr);gap:8px">
<label>{{ __('enterprise_security.currency') }}<input name="currency" value="KWD" maxlength="3" required dir="ltr"></label>
<label>{{ __('enterprise_security.max_amount') }}<input name="max_amount" inputmode="decimal" placeholder="5000.000" required dir="ltr"></label>
</div>
<button class="es-button" type="submit">{{ __('enterprise_security.create_limit') }}</button>
</form>
</section>
@endcan

@can('security.delegations.manage')
<section class="es-card es-wide" data-enterprise-security-delegation-form>
<h2>{{ __('enterprise_security.delegations') }}</h2><p class="es-help">{{ __('enterprise_security.delegations_help') }}</p>
<div class="es-note">{{ __('enterprise_security.delegator_is_you') }}</div>
<form class="es-form" method="post" action="{{ route('admin.enterprise-security.delegations.store') }}">@csrf
<div style="display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px">
<label>{{ __('enterprise_security.delegate') }}
<select name="delegate_id" required>
@foreach($users as $managedUser)
@if((int) $managedUser->id !== (int) $user->id)
<option value="{{ $managedUser->id }}">{{ $managedUser->name }}</option>
@endif
@endforeach
</select>
</label>
<label>{{ __('enterprise_security.permission') }}<select name="permission_code" required>@foreach($permissionLabels as $code=>$label)<option value="{{ $code }}">{{ $label }}</option>@endforeach</select></label>
</div>
<label>{{ __('enterprise_security.scope') }}<select name="scope_key" required>@foreach($scopeOptions as $scope)<option value="{{ $scope['key'] }}">{{ $scope['label'] }}</option>@endforeach</select></label>
<div style="display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px">
<label>{{ __('enterprise_security.starts_at') }}<input type="datetime-local" name="starts_at" required></label>
<label>{{ __('enterprise_security.ends_at') }}<input type="datetime-local" name="ends_at" required></label>
</div>
<button class="es-button" type="submit">{{ __('enterprise_security.create_delegation') }}</button>
</form>
</section>
@endcan
</div>

<div class="es-grid">
<section class="es-card">
<h2>{{ __('enterprise_security.role_scopes') }}</h2>
<div class="es-list">
@forelse($dataScopes as $dataScope)
<div class="es-row"><div><strong>{{ $dataScope->role?->name ?? '—' }}</strong><p>{{ $scopeLabels[$dataScope->scope_key] ?? __('enterprise_security.unavailable_scope') }}</p><span class="es-badge {{ $dataScope->is_active ? '' : 'muted' }}">{{ $dataScope->is_active ? __('enterprise_security.active') : __('enterprise_security.inactive') }}</span></div>
@can('security.scopes.manage')@if($dataScope->is_active)<form method="post" action="{{ route('admin.enterprise-security.role-scopes.destroy',$dataScope) }}">@csrf @method('delete')<button class="es-button danger" type="submit">{{ __('enterprise_security.remove_scope') }}</button></form>@endif @endcan</div>
@empty<p class="es-empty">{{ __('enterprise_security.none') }}</p>@endforelse
</div><div class="es-pager">{{ $dataScopes->links() }}</div>
</section>

<section class="es-card">
<h2>{{ __('enterprise_security.approval_limits') }}</h2>
<div class="es-list">
@forelse($approvalLimits as $limit)
<div class="es-row"><div><strong>{{ $limit->role?->name ?? '—' }}</strong><p>{{ $permissionLabels[$limit->permission_code] ?? $limit->permission_code }}</p><div class="es-meta">{{ $scopeLabels[$limit->scope_key] ?? __('enterprise_security.unavailable_scope') }} · <span dir="ltr">{{ $limit->currency }} {{ $limit->max_amount }}</span></div><span class="es-badge {{ $limit->is_active ? '' : 'muted' }}">{{ $limit->is_active ? __('enterprise_security.active') : __('enterprise_security.inactive') }}</span></div>
@can('security.approval_limits.manage')@if($limit->is_active)<form method="post" action="{{ route('admin.enterprise-security.approval-limits.destroy',$limit) }}">@csrf @method('delete')<button class="es-button danger" type="submit">{{ __('enterprise_security.remove_limit') }}</button></form>@endif @endcan</div>
@empty<p class="es-empty">{{ __('enterprise_security.none') }}</p>@endforelse
</div><div class="es-pager">{{ $approvalLimits->links() }}</div>
</section>

<section class="es-card es-wide">
<h2>{{ __('enterprise_security.delegations') }}</h2>
<div class="es-list">
@forelse($delegations as $delegation)
@php
$delegationState = $delegation->revoked_at ? 'revoked' : ($delegation->ends_at->isPast() ? 'expired' : ($delegation->starts_at->isFuture() ? 'scheduled' : 'active'));
@endphp
<div class="es-row"><div><strong>{{ $delegation->delegator?->name ?? __('enterprise_security.unknown_user') }} → {{ $delegation->delegate?->name ?? __('enterprise_security.unknown_user') }}</strong><p>{{ $permissionLabels[$delegation->permission_code] ?? $delegation->permission_code }}</p><div class="es-meta">{{ $scopeLabels[$delegation->scope_key] ?? __('enterprise_security.unavailable_scope') }} · {{ __('enterprise_security.starts_at') }}: {{ $delegation->starts_at->format('Y-m-d H:i') }} · {{ __('enterprise_security.ends_at') }}: {{ $delegation->ends_at->format('Y-m-d H:i') }}</div><span class="es-badge {{ $delegationState === 'active' ? '' : 'muted' }}">{{ __('enterprise_security.'.$delegationState) }}</span></div>
@can('security.delegations.manage')
@if(
    ! $delegation->revoked_at
    && ! $delegation->ends_at->isPast()
    && (
        (int) $delegation->delegator_id === (int) $user->id
        || $user->hasRole('SUPER_ADMIN')
    )
)
<form method="post" action="{{ route('admin.enterprise-security.delegations.destroy', $delegation) }}">
@csrf
@method('delete')
<button class="es-button danger" type="submit">{{ __('enterprise_security.revoke_delegation') }}</button>
</form>
@endif
@endcan
</div>
@empty<p class="es-empty">{{ __('enterprise_security.none') }}</p>@endforelse
</div><div class="es-pager">{{ $delegations->links() }}</div>
</section>

<section class="es-card es-wide" data-enterprise-security-change-queue>
<h2>{{ __('enterprise_security.pending_changes') }}</h2><p class="es-help">{{ __('enterprise_security.pending_changes_help') }}</p>
<div class="es-list">
@forelse($pendingChanges as $change)
@php
$context = is_array($change->context) ? $change->context : [];
$payload = is_array($context['payload'] ?? null) ? $context['payload'] : [];
$changeType = (string)($context['change_type'] ?? '');
$statusValue = is_object($change->status) && property_exists($change->status,'value') ? $change->status->value : (string)$change->status;
$scopeKey = isset($payload['scope_type']) ? ((string)$payload['scope_type'].':'.(($payload['scope_id'] ?? null) === null ? '*' : (string)$payload['scope_id'])) : null;
@endphp
<div class="es-row"><div><strong>{{ __('enterprise_security.change_types.'.$changeType) }}</strong>
@if(isset($payload['role_label']))<p>{{ __('enterprise_security.role') }}: {{ $payload['role_label'] }}</p>@endif
@if(isset($payload['delegate_label']))<p>{{ __('enterprise_security.delegate') }}: {{ $payload['delegate_label'] }}</p>@endif
@if(isset($payload['permission_code']))<p>{{ __('enterprise_security.permission') }}: {{ $permissionLabels[$payload['permission_code']] ?? $payload['permission_code'] }}</p>@endif
@if($scopeKey)<div class="es-meta">{{ __('enterprise_security.scope') }}: {{ $scopeLabels[$scopeKey] ?? __('enterprise_security.unavailable_scope') }}</div>@endif
<div class="es-meta">{{ __('enterprise_security.requested_by') }}: {{ $change->requester?->name ?? __('enterprise_security.unknown_user') }} · {{ __('enterprise_security.requested_at') }}: {{ $change->created_at->format('Y-m-d H:i') }}</div>
<span class="es-badge {{ strtolower($statusValue) === 'pending' ? '' : 'muted' }}">{{ __('enterprise_security.statuses.'.strtolower($statusValue)) }}</span>
</div>
<div class="es-actions">
@can('security.approve')
@if($statusValue === 'PENDING' && (int)$change->requester_id !== (int)$user->id)
<form class="es-form" method="post" action="{{ route('admin.enterprise-security.changes.decide',$change) }}">@csrf
<label>{{ __('enterprise_security.comment') }}<input name="comment" maxlength="1000"></label>
<div class="es-actions"><button class="es-button" name="decision" value="APPROVED" type="submit">{{ __('enterprise_security.approve') }}</button><button class="es-button danger" name="decision" value="REJECTED" type="submit">{{ __('enterprise_security.reject') }}</button></div>
</form>
@endif
@endcan
</div></div>
@empty<p class="es-empty">{{ __('enterprise_security.none') }}</p>@endforelse
</div><div class="es-pager">{{ $pendingChanges->links() }}</div>
</section>
</div>
</main></div>
</body></html>
