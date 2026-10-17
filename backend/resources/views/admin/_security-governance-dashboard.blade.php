<style>
.security-kpis{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:10px;margin:14px 0}.security-kpi{display:grid;gap:6px;padding:14px;border:1px solid var(--foodex-border);border-radius:14px;background:#fff}.security-kpi strong{font-size:1.55rem}.security-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}.security-bars{display:grid;gap:9px}.security-bar{display:grid;grid-template-columns:minmax(120px,.8fr) minmax(100px,1fr) auto;gap:8px;align-items:center}.security-bar-track{height:9px;border-radius:999px;background:var(--foodex-background);overflow:hidden}.security-bar-fill{height:100%;background:var(--foodex-green);border-radius:inherit}.security-health{display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:10px}.security-health-item{padding:12px;border:1px solid var(--foodex-border);border-radius:12px}.security-report-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:8px}.security-report-grid a{display:flex;justify-content:space-between;gap:8px;padding:11px;border:1px solid var(--foodex-border);border-radius:10px;text-decoration:none;color:inherit}.security-table-wrap{overflow-x:auto}.security-table{width:100%;border-collapse:collapse}.security-table th,.security-table td{padding:10px;border-bottom:1px solid var(--foodex-border);text-align:start;vertical-align:top}.security-audit-filters{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:8px;margin:12px 0}.security-audit-filters input{width:100%;border:1px solid var(--foodex-border);border-radius:10px;padding:9px 11px}.security-session-agent{max-width:380px;overflow-wrap:anywhere;color:var(--foodex-muted)}@media(max-width:760px){.security-grid{grid-template-columns:1fr}.security-audit-filters{grid-template-columns:1fr}.security-bar{grid-template-columns:minmax(100px,.8fr) minmax(80px,1fr) auto}}
</style>

<section class="panel" data-security-governance-dashboard>
<div class="head"><div><h2>{{ __('security_governance.dashboard.title') }}</h2><div class="muted">{{ __('security_governance.dashboard.description') }}</div></div><a class="btn secondary" href="{{ route('admin.mfa.enroll') }}">{{ __('security_governance.mfa_admin.manage_self') }}</a></div>
<div class="security-kpis">
@foreach($governance['kpis'] as $key=>$value)
<div class="security-kpi"><span class="muted">{{ __('security_governance.dashboard.'.$key) }}</span><strong class="foodex-number">{{ $value }}</strong></div>
@endforeach
</div>

<div class="security-grid">
<div class="card"><h3>{{ __('security_governance.dashboard.users_by_role') }}</h3><div class="security-bars">
@php($roleMax=max(1,...array_map(fn($row)=>(int)$row['value'],$governance['role_rows'] ?: [['value'=>1]])))
@forelse($governance['role_rows'] as $row)
<div class="security-bar"><span>{{ $row['label'] }} <small>({{ $row['scope'] }})</small></span><div class="security-bar-track"><div class="security-bar-fill" style="width:{{ min(100,round(($row['value']/$roleMax)*100)) }}%"></div></div><strong>{{ $row['value'] }}</strong></div>
@empty<div class="note foodex-empty-state">—</div>@endforelse
</div></div>
<div class="card"><h3>{{ __('security_governance.dashboard.delegation_state') }}</h3><div class="security-bars">
@php($delegationMax=max(1,...array_values($governance['delegation_states'] ?: ['none'=>1])))
@foreach($governance['delegation_states'] as $state=>$value)
<div class="security-bar"><span>{{ __('security_governance.states.'.$state) }}</span><div class="security-bar-track"><div class="security-bar-fill" style="width:{{ min(100,round(($value/$delegationMax)*100)) }}%"></div></div><strong>{{ $value }}</strong></div>
@endforeach
</div></div>
<div class="card"><h3>{{ __('security_governance.dashboard.approval_workload') }}</h3><div class="security-bars">
@php($workflowMax=max(1,...array_values($governance['workflow_states'] ?: ['none'=>1])))
@forelse($governance['workflow_states'] as $state=>$value)
<div class="security-bar"><span>{{ Str::headline(strtolower((string)$state)) }}</span><div class="security-bar-track"><div class="security-bar-fill" style="width:{{ min(100,round(($value/$workflowMax)*100)) }}%"></div></div><strong>{{ $value }}</strong></div>
@empty<div class="note foodex-empty-state">—</div>@endforelse
</div></div>
<div class="card"><h3>{{ __('security_governance.dashboard.security_events') }}</h3><div class="security-bars">
@php($eventMax=max(1,...array_map(fn($row)=>(int)$row['value'],$governance['event_trend'] ?: [['value'=>1]])))
@forelse($governance['event_trend'] as $row)
<div class="security-bar"><span dir="ltr">{{ $row['label'] }}</span><div class="security-bar-track"><div class="security-bar-fill" style="width:{{ min(100,round(($row['value']/$eventMax)*100)) }}%"></div></div><strong>{{ $row['value'] }}</strong></div>
@empty<div class="note foodex-empty-state">—</div>@endforelse
</div></div>
</div>

<div class="card"><h3>{{ __('security_governance.dashboard.health') }}</h3><div class="security-health">
<div class="security-health-item"><strong>{{ __('security_governance.dashboard.mfa_compliance') }}</strong><div>{{ $governance['health']['mfa_enabled'] }} / {{ $governance['health']['privileged_total'] }}</div><span class="badge {{ $governance['health']['mfa_missing']===0?'active':'inactive' }}">{{ $governance['health']['mfa_missing']===0?__('security_governance.states.healthy'):__('security_governance.states.warning') }}</span>@if(!$governance['health']['mfa_enforced'])<div class="muted">{{ __('security_governance.states.disabled') }}</div>@endif</div>
<div class="security-health-item"><strong>{{ __('security_governance.dashboard.sso') }}</strong><div>{{ $governance['health']['sso']['provider'] }}</div><span class="badge {{ ($governance['health']['sso']['enabled']&&$governance['health']['sso']['configured'])?'active':'inactive' }}">{{ ($governance['health']['sso']['enabled']&&$governance['health']['sso']['configured'])?__('security_governance.states.configured'):__('security_governance.states.not_configured') }}</span></div>
<div class="security-health-item"><strong>{{ __('security_governance.dashboard.privileged_users') }}</strong><div>{{ $governance['health']['stale_privileged'] }} {{ __('security_governance.dashboard.audit_exceptions') }}</div><span class="muted">{{ __('security_governance.dashboard.stale_privilege_window') }}</span></div>
<div class="security-health-item"><strong>{{ __('security_governance.dashboard.active_delegations') }}</strong><div>{{ $governance['health']['risky_delegations'] }}</div><span class="muted">{{ __('security_governance.dashboard.risky_delegation_window') }}</span></div>
</div></div>

<div class="card"><h3>{{ __('security_governance.reports.title') }}</h3><div class="security-report-grid">
@foreach(['effective-access','privileged-changes','delegations','mfa','auth-audit'] as $report)
<a href="{{ route('admin.security.reports.export',['report'=>$report]) }}"><span>{{ __('security_governance.reports.'.str_replace('-','_',$report)) }}</span><strong>{{ __('security_governance.reports.export') }}</strong></a>
@endforeach
</div></div>
</section>

<section class="panel" data-security-session-registry>
<div class="head"><div><h2>{{ __('security_governance.dashboard.sessions') }}</h2><div class="muted">{{ __('security_governance.sessions.last_seen') }}</div></div><span class="badge">{{ $securitySessions->count() }}</span></div>
<div class="security-table-wrap"><table class="security-table foodex-table" data-pagination-required><thead><tr><th>{{ __('admin.security.users') }}</th><th>{{ __('security_governance.sessions.ip') }}</th><th>{{ __('security_governance.sessions.agent') }}</th><th>{{ __('security_governance.sessions.last_seen') }}</th><th></th></tr></thead><tbody>
@forelse($securitySessions as $trackedSession)
<tr>
<td>{{ $trackedSession->user?->name }}@if(hash_equals($currentSecuritySessionHash,$trackedSession->session_hash)) <span class="badge active">{{ __('security_governance.sessions.current') }}</span>@endif</td>
<td dir="ltr">{{ $trackedSession->ip_address ?: '—' }}</td>
<td class="security-session-agent">{{ $trackedSession->user_agent ?: '—' }}</td>
<td dir="ltr">{{ $trackedSession->last_seen_at?->format('Y-m-d H:i') }}</td>
<td>
@can('users.status.manage')
    @if(!hash_equals($currentSecuritySessionHash,$trackedSession->session_hash))
        <form method="post" action="{{ route('admin.security.sessions.revoke',$trackedSession) }}">
            @csrf
            @method('delete')
            <button class="btn danger">{{ __('security_governance.sessions.revoke') }}</button>
        </form>
    @endif
@endcan
</td>
</tr>
@empty<tr><td colspan="5"><div class="note foodex-empty-state">{{ __('security_governance.sessions.none') }}</div></td></tr>@endforelse
</tbody></table></div>
</section>

<section class="panel" data-security-audit-explorer>
<div class="head"><div><h2>{{ __('security_governance.dashboard.audit') }}</h2></div></div>
<form method="get" class="security-audit-filters">
<input name="audit_actor" value="{{ $auditFilters['audit_actor'] }}" placeholder="{{ __('security_governance.audit.actor') }}">
<input name="audit_event" value="{{ $auditFilters['audit_event'] }}" placeholder="{{ __('security_governance.audit.event') }}">
<input name="audit_entity" value="{{ $auditFilters['audit_entity'] }}" placeholder="{{ __('security_governance.audit.entity') }}">
<label>{{ __('security_governance.audit.date_from') }}<input type="date" name="audit_from" value="{{ $auditFilters['audit_from'] }}"></label>
<label>{{ __('security_governance.audit.date_to') }}<input type="date" name="audit_to" value="{{ $auditFilters['audit_to'] }}"></label>
<input name="audit_correlation" value="{{ $auditFilters['audit_correlation'] }}" placeholder="{{ __('security_governance.audit.correlation') }}">
<button class="btn primary foodex-filter-action" type="submit">{{ __('security_governance.audit.apply') }}</button>
</form>
<div class="security-table-wrap"><table class="security-table foodex-table" data-pagination-required><thead><tr><th>{{ __('security_governance.audit.time') }}</th><th>{{ __('security_governance.audit.actor') }}</th><th>{{ __('security_governance.audit.event') }}</th><th>{{ __('security_governance.audit.entity') }}</th><th>{{ __('security_governance.sessions.ip') }}</th></tr></thead><tbody>
@forelse($auditRows as $auditRow)
<tr><td dir="ltr">{{ $auditRow->created_at }}</td><td>{{ $auditRow->actor_name ?: ($auditRow->actor_email ?: '—') }}</td><td dir="ltr">{{ $auditRow->event }}</td><td>{{ $auditRow->auditable_type ?: '—' }}@if($auditRow->auditable_id) #{{ $auditRow->auditable_id }}@endif</td><td dir="ltr">{{ $auditRow->ip_address ?: '—' }}</td></tr>
@empty<tr><td colspan="5"><div class="note foodex-empty-state">{{ __('security_governance.audit.empty') }}</div></td></tr>@endforelse
</tbody></table></div><div class="pager">{{ $auditRows->links() }}</div>
</section>
