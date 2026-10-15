@php
    $scopeOptions = $enterpriseSecurity['scope_options'];
    $dataScopes = $enterpriseSecurity['data_scopes'];
    $approvalLimits = $enterpriseSecurity['approval_limits'];
    $delegations = $enterpriseSecurity['delegations'];
    $pendingChanges = $enterpriseSecurity['pending_changes'];
@endphp

<section class="panel" data-enterprise-security-governance>
<div class="head">
    <div>
        <h2>{{ __('enterprise_security.title') }}</h2>
        <div class="muted">{{ __('enterprise_security.description') }}</div>
    </div>
    <span class="badge system">{{ __('enterprise_security.maker_checker') }}</span>
</div>

@if(!$enterpriseSecurityAvailable)
<div class="note foodex-state">{{ __('enterprise_security.unavailable') }}</div>
@else
<div class="role-grid">
    <article class="card" data-enterprise-security-section="data-scopes">
        <div class="head">
            <div><h3>{{ __('enterprise_security.data_scopes.title') }}</h3><div class="muted">{{ __('enterprise_security.data_scopes.description') }}</div></div>
        </div>
        @can('roles.manage')
        <details data-foodex-operational-modal>
            <summary>{{ __('enterprise_security.data_scopes.add') }}</summary>
            <form method="post" action="{{ route('admin.security.enterprise.changes.store') }}" class="filters" data-submit-lock>
                @csrf
                <input type="hidden" name="operation" value="data_scope.upsert">
                <input type="hidden" name="request_token" value="{{ IlluminateSupportStr::uuid() }}">
                <label class="grow">{{ __('enterprise_security.fields.user') }}
                    <select name="target_user_id" required style="width:100%">
                        @foreach($enterprisePolicyCandidates as $candidate)
                        <option value="{{ $candidate->id }}">{{ $candidate->name }} · {{ $candidate->email }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="grow">{{ __('enterprise_security.fields.scope') }}
                    <select name="scope_reference" required style="width:100%">
                        @foreach($scopeOptions as $option)
                            @if($option['value'] !== 'global')
                            <option value="{{ $option['value'] }}">{{ $option['label'] }}</option>
                            @endif
                        @endforeach
                    </select>
                </label>
                <label>{{ __('enterprise_security.fields.starts_at') }}<input type="datetime-local" name="starts_at"></label>
                <label>{{ __('enterprise_security.fields.ends_at') }}<input type="datetime-local" name="ends_at"></label>
                <button type="submit" class="btn primary">{{ __('enterprise_security.data_scopes.add') }}</button>
            </form>
        </details>
        @endcan

        @if($dataScopes->isEmpty())
        <div class="note foodex-empty-state">{{ __('enterprise_security.data_scopes.empty') }}</div>
        @else
        <div class="foodex-table-wrap">
        <table class="foodex-table foodex-ops-grid">
            <thead><tr><th>{{ __('enterprise_security.fields.user') }}</th><th>{{ __('enterprise_security.fields.scope') }}</th><th>{{ __('enterprise_security.fields.status') }}</th><th>{{ __('enterprise_security.fields.granted_by') }}</th>@can('roles.manage')<th></th>@endcan</tr></thead>
            <tbody>
            @foreach($dataScopes as $row)
                @php
                    $scopeStarts = $row->starts_at ? IlluminateSupportCarbon::parse($row->starts_at) : null;
                    $scopeEnds = $row->ends_at ? IlluminateSupportCarbon::parse($row->ends_at) : null;
                    $scopeStatus = $scopeEnds && $scopeEnds->isPast() ? 'expired' : (($scopeStarts && $scopeStarts->isFuture()) ? 'scheduled' : 'active');
                @endphp
                <tr>
                    <td><strong>{{ $row->user_name }}</strong><div class="muted">{{ $row->user_email }}</div></td>
                    <td>{{ $row->scope_label }}<div class="muted">@if($scopeStarts){{ $scopeStarts->format('Y-m-d H:i') }}@endif @if($scopeEnds)→ {{ $scopeEnds->format('Y-m-d H:i') }}@endif</div></td>
                    <td><span class="badge {{ $scopeStatus === 'active' ? 'active' : ($scopeStatus === 'expired' ? 'inactive' : '') }}">{{ __('enterprise_security.status.'.$scopeStatus) }}</span></td>
                    <td>{{ $row->granted_by_name ?: __('enterprise_security.not_available') }}</td>
                    @can('roles.manage')
                    <td>
                        <form method="post" action="{{ route('admin.security.enterprise.changes.store') }}" data-submit-lock>
                            @csrf
                            <input type="hidden" name="operation" value="data_scope.remove">
                            <input type="hidden" name="request_token" value="{{ IlluminateSupportStr::uuid() }}">
                            <input type="hidden" name="policy_id" value="{{ $row->id }}">
                            <button type="submit" class="btn danger">{{ __('enterprise_security.data_scopes.remove') }}</button>
                        </form>
                    </td>
                    @endcan
                </tr>
            @endforeach
            </tbody>
        </table>
        </div>
        @endif
    </article>

    <article class="card" data-enterprise-security-section="approval-limits">
        <div class="head">
            <div><h3>{{ __('enterprise_security.approval_limits.title') }}</h3><div class="muted">{{ __('enterprise_security.approval_limits.description') }}</div></div>
        </div>
        @can('roles.manage')
        <details data-foodex-operational-modal>
            <summary>{{ __('enterprise_security.approval_limits.add') }}</summary>
            <form method="post" action="{{ route('admin.security.enterprise.changes.store') }}" class="filters" data-submit-lock>
                @csrf
                <input type="hidden" name="operation" value="approval_limit.upsert">
                <input type="hidden" name="request_token" value="{{ IlluminateSupportStr::uuid() }}">
                <label class="grow">{{ __('enterprise_security.fields.user') }}
                    <select name="target_user_id" required style="width:100%">
                        @foreach($enterprisePolicyCandidates as $candidate)
                        <option value="{{ $candidate->id }}">{{ $candidate->name }} · {{ $candidate->email }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="grow">{{ __('enterprise_security.fields.workflow_key') }}
                    <input type="text" name="workflow_key" maxlength="120" required placeholder="purchase_order" title="{{ __('enterprise_security.fields.workflow_key_hint') }}">
                </label>
                <label>{{ __('enterprise_security.fields.currency') }}<input type="text" name="currency" maxlength="3" size="4" required placeholder="KWD"></label>
                <label>{{ __('enterprise_security.fields.amount') }}<input type="text" name="amount" inputmode="decimal" required placeholder="1000.000"></label>
                <label class="grow">{{ __('enterprise_security.fields.scope') }}
                    <select name="scope_reference" required style="width:100%">
                        @foreach($scopeOptions as $option)
                        <option value="{{ $option['value'] }}">{{ $option['label'] }}</option>
                        @endforeach
                    </select>
                </label>
                <label>{{ __('enterprise_security.fields.starts_at') }}<input type="datetime-local" name="starts_at"></label>
                <label>{{ __('enterprise_security.fields.ends_at') }}<input type="datetime-local" name="ends_at"></label>
                <button type="submit" class="btn primary">{{ __('enterprise_security.approval_limits.add') }}</button>
            </form>
        </details>
        @endcan

        @if($approvalLimits->isEmpty())
        <div class="note foodex-empty-state">{{ __('enterprise_security.approval_limits.empty') }}</div>
        @else
        <div class="foodex-table-wrap">
        <table class="foodex-table foodex-ops-grid">
            <thead><tr><th>{{ __('enterprise_security.fields.user') }}</th><th>{{ __('enterprise_security.fields.workflow_key') }}</th><th>{{ __('enterprise_security.fields.amount') }}</th><th>{{ __('enterprise_security.fields.scope') }}</th>@can('roles.manage')<th></th>@endcan</tr></thead>
            <tbody>
            @foreach($approvalLimits as $row)
                <tr>
                    <td><strong>{{ $row->user_name }}</strong><div class="muted">{{ $row->user_email }}</div></td>
                    <td><code>{{ $row->workflow_key }}</code></td>
                    <td><span class="foodex-number">{{ $row->amount }}</span> {{ $row->currency }}</td>
                    <td>{{ $row->scope_label }}</td>
                    @can('roles.manage')
                    <td>
                        <form method="post" action="{{ route('admin.security.enterprise.changes.store') }}" data-submit-lock>
                            @csrf
                            <input type="hidden" name="operation" value="approval_limit.remove">
                            <input type="hidden" name="request_token" value="{{ IlluminateSupportStr::uuid() }}">
                            <input type="hidden" name="policy_id" value="{{ $row->id }}">
                            <button type="submit" class="btn danger">{{ __('enterprise_security.approval_limits.remove') }}</button>
                        </form>
                    </td>
                    @endcan
                </tr>
            @endforeach
            </tbody>
        </table>
        </div>
        @endif
    </article>
</div>

<div class="role-grid">
    <article class="card" data-enterprise-security-section="delegations">
        <div class="head">
            <div><h3>{{ __('enterprise_security.delegations.title') }}</h3><div class="muted">{{ __('enterprise_security.delegations.description') }}</div></div>
        </div>
        @can('roles.manage')
        <details data-foodex-operational-modal>
            <summary>{{ __('enterprise_security.delegations.add') }}</summary>
            <form method="post" action="{{ route('admin.security.enterprise.changes.store') }}" class="filters" data-submit-lock>
                @csrf
                <input type="hidden" name="operation" value="delegation.create">
                <input type="hidden" name="request_token" value="{{ IlluminateSupportStr::uuid() }}">
                <div class="note">{{ __('enterprise_security.delegations.delegator_you') }}</div>
                <label class="grow">{{ __('enterprise_security.fields.delegate') }}
                    <select name="delegate_id" required style="width:100%">
                        @foreach($enterpriseSecurity['delegation_candidates'] as $candidate)
                        <option value="{{ $candidate->id }}">{{ $candidate->name }} · {{ $candidate->email }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="grow">{{ __('enterprise_security.fields.permission') }}
                    <select name="permission_code" required style="width:100%">
                        @foreach($delegationPermissionOptions as $permission)
                        <option value="{{ $permission->code }}">{{ $permissionLabels[(int)$permission->id] ?? $permission->name }} · {{ $permission->code }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="grow">{{ __('enterprise_security.fields.scope') }}
                    <select name="scope_reference" required style="width:100%">
                        @foreach($scopeOptions as $option)
                        <option value="{{ $option['value'] }}">{{ $option['label'] }}</option>
                        @endforeach
                    </select>
                </label>
                <label>{{ __('enterprise_security.fields.starts_at') }}<input type="datetime-local" name="starts_at" required></label>
                <label>{{ __('enterprise_security.fields.ends_at') }}<input type="datetime-local" name="ends_at" required></label>
                <button type="submit" class="btn primary">{{ __('enterprise_security.delegations.add') }}</button>
            </form>
        </details>
        @endcan

        @if($delegations->isEmpty())
        <div class="note foodex-empty-state">{{ __('enterprise_security.delegations.empty') }}</div>
        @else
        <div class="foodex-table-wrap">
        <table class="foodex-table foodex-ops-grid">
            <thead><tr><th>{{ __('enterprise_security.fields.delegate') }}</th><th>{{ __('enterprise_security.fields.permission') }}</th><th>{{ __('enterprise_security.fields.scope') }}</th><th>{{ __('enterprise_security.fields.status') }}</th>@can('roles.manage')<th></th>@endcan</tr></thead>
            <tbody>
            @foreach($delegations as $row)
                @php
                    $delegationStarts = IlluminateSupportCarbon::parse($row->starts_at);
                    $delegationEnds = IlluminateSupportCarbon::parse($row->ends_at);
                    $delegationStatus = $row->revoked_at ? 'revoked' : ($delegationEnds->isPast() ? 'expired' : ($delegationStarts->isFuture() ? 'scheduled' : 'active'));
                @endphp
                <tr>
                    <td><strong>{{ $row->delegate_name }}</strong><div class="muted">{{ $row->delegate_email }} · {{ $row->delegator_name }}</div></td>
                    <td><code>{{ $row->permission_code }}</code></td>
                    <td>{{ $row->scope_label }}<div class="muted">{{ $delegationStarts->format('Y-m-d H:i') }} → {{ $delegationEnds->format('Y-m-d H:i') }}</div></td>
                    <td><span class="badge {{ $delegationStatus === 'active' ? 'active' : 'inactive' }}">{{ __('enterprise_security.status.'.$delegationStatus) }}</span></td>
                    @can('roles.manage')
                    <td>
                        @if(!$row->revoked_at && !$delegationEnds->isPast())
                        <form method="post" action="{{ route('admin.security.enterprise.changes.store') }}" data-submit-lock>
                            @csrf
                            <input type="hidden" name="operation" value="delegation.revoke">
                            <input type="hidden" name="request_token" value="{{ IlluminateSupportStr::uuid() }}">
                            <input type="hidden" name="policy_id" value="{{ $row->id }}">
                            <button type="submit" class="btn danger">{{ __('enterprise_security.delegations.revoke') }}</button>
                        </form>
                        @endif
                    </td>
                    @endcan
                </tr>
            @endforeach
            </tbody>
        </table>
        </div>
        @endif
    </article>

    <article class="card" data-enterprise-security-section="pending-changes">
        <div class="head">
            <div><h3>{{ __('enterprise_security.pending.title') }}</h3><div class="muted">{{ __('enterprise_security.pending.description') }}</div></div>
            <span class="badge">{{ $pendingChanges->count() }}</span>
        </div>
        @forelse($pendingChanges as $workflow)
            @php $summary = $pendingSecuritySummaries[(int)$workflow->getKey()] ?? []; @endphp
            <div class="store">
                <strong>{{ $summary['operation_label'] ?? __('enterprise_security.not_available') }}</strong>
                <div class="muted">{{ __('enterprise_security.pending.requested_by') }}: {{ $workflow->requester?->name ?? __('enterprise_security.not_available') }}</div>
                <div>{{ __('enterprise_security.fields.target') }}: {{ $summary['target'] ?? __('enterprise_security.not_applicable') }}</div>
                <div>{{ __('enterprise_security.fields.scope') }}: {{ $summary['scope_label'] ?? __('enterprise_security.not_available') }}</div>
                @if(!empty($summary['workflow_key']))<div>{{ __('enterprise_security.fields.workflow_key') }}: <code>{{ $summary['workflow_key'] }}</code></div>@endif
                @if(!empty($summary['amount']))<div>{{ __('enterprise_security.fields.amount') }}: <span class="foodex-number">{{ $summary['amount'] }}</span> {{ $summary['currency'] }}</div>@endif
                @if(!empty($summary['permission_code']))<div>{{ __('enterprise_security.fields.permission') }}: <code>{{ $summary['permission_code'] }}</code></div>@endif
                @can('roles.manage')
                <form method="post" action="{{ route('admin.security.enterprise.changes.decision', $workflow) }}" class="filters" data-submit-lock>
                    @csrf
                    <input class="grow" type="text" name="comment" maxlength="1000" placeholder="{{ __('enterprise_security.pending.comment') }}">
                    <button type="submit" name="decision" value="APPROVED" class="btn success">{{ __('enterprise_security.pending.approve') }}</button>
                    <button type="submit" name="decision" value="REJECTED" class="btn danger">{{ __('enterprise_security.pending.reject') }}</button>
                </form>
                @endcan
            </div>
        @empty
            <div class="note foodex-empty-state">{{ __('enterprise_security.pending.empty') }}</div>
        @endforelse
    </article>
</div>
@endif
</section>
