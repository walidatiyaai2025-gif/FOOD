{{-- P098: read-only role configuration, not a user-level effective access grant. --}}
@php
    $rolePermissionNamespaces = $role->permissions
        ->groupBy(static fn ($permission): string => \Illuminate\Support\Str::before((string) $permission->code, '.'))
        ->sortKeys();
    $configuredApprovalLimits = $roleApprovalLimits->get((int) $role->id, collect());
    $roleStores = $roleAssignedStores->get((int) $role->id, collect());
@endphp
<section class="note" data-v2-custom-role-details="{{ (int) $role->id }}" aria-label="{{ __('admin.security.custom_role_details.title') }}">
    <h4>{{ __('admin.security.custom_role_details.title') }}</h4>
    <dl>
        <dt>{{ __('admin.security.custom_role_details.identity') }}</dt>
        <dd><code>{{ $role->code }}</code></dd>
        <dt>{{ __('admin.security.custom_role_details.saved_description') }}</dt>
        <dd>{{ $role->description ?: __('admin.security.no_description') }}</dd>
        <dt>{{ __('admin.security.custom_role_details.assignment_scope') }}</dt>
        <dd>{{ $role->scope === 'global' ? __('admin.security.scope_global') : __('admin.security.scope_store') }}</dd>
        <dt>{{ __('admin.security.custom_role_details.saved_assignments') }}</dt>
        <dd>{{ __('admin.security.custom_role_details.assignment_totals', ['global' => (int) $role->users_count, 'store' => (int) $role->store_assignments_count]) }}</dd>
    </dl>
    <h5>{{ __('admin.security.custom_role_details.assigned_stores') }}</h5>
    @if($roleStores->isEmpty())
        <p class="muted">{{ __('admin.security.custom_role_details.no_assigned_stores') }}</p>
    @else
        <ul data-v2-custom-role-assigned-stores>
            @foreach($roleStores as $assignedStore)
                <li>{{ $assignedStore->store_name }} <span class="muted">{{ __('admin.security.custom_role_details.store_assignment_count', ['count' => (int) $assignedStore->assignments_count]) }}</span></li>
            @endforeach
        </ul>
    @endif
    <p class="muted">{{ __('admin.security.custom_role_details.store_boundary') }}</p>
    <h5>{{ __('admin.security.custom_role_details.permission_modules') }}</h5>
    @if($rolePermissionNamespaces->isEmpty())
        <p class="muted" role="status">{{ __('admin.security.custom_role_details.no_permissions') }}</p>
    @else
        @foreach($rolePermissionNamespaces as $namespace => $modulePermissions)
            <div class="card" data-v2-role-permission-module>
                <strong>{{ \Illuminate\Support\Facades\Lang::has('security_permissions.groups.'.$namespace) ? __('security_permissions.groups.'.$namespace) : __('admin.security.custom_role_details.other_module') }}</strong>
                <ul>
                    @foreach($modulePermissions as $permission)
                        <li>
                            {{ $permissionLabels->get((int) $permission->id, __('admin.security.custom_role_details.registered_permission')) }}
                            <code>{{ $permission->code }}</code>
                        </li>
                    @endforeach
                </ul>
            </div>
        @endforeach
    @endif
    <p class="muted">{{ __('admin.security.custom_role_details.module_boundary') }}</p>
    <h5>{{ __('admin.security.custom_role_details.approval_limits') }}</h5>
    @if($configuredApprovalLimits->isEmpty())
        <p class="muted" data-v2-custom-role-no-limits>{{ __('admin.security.custom_role_details.no_active_limits') }}</p>
    @else
        <ul data-v2-custom-role-limits>
            @foreach($configuredApprovalLimits as $limit)
                <li>
                    {{ $limit->max_amount }} {{ $limit->currency }}
                    <span class="muted">· {{ __('admin.security.custom_role_details.limit_for') }}</span>
                    <code>{{ $limit->permission_code }}</code>
                    <span class="muted">· {{ __('admin.security.custom_role_details.limit_scope') }}: {{ $limit->scope_type === 'global' ? __('admin.security.scope_global') : __('admin.security.custom_role_details.contextual_scope') }}</span>
                </li>
            @endforeach
        </ul>
    @endif
    <p class="muted">{{ __('admin.security.custom_role_details.limit_boundary') }}</p>
</section>
