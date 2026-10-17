@php
$globalIds = $managedUser->roles->pluck('id')->map(fn ($id) => (int) $id)->all();
$byStore = $managedUser->storeRoleAssignments->groupBy('store_id');
$grantableGlobalIds = $globalRoles->pluck('id')->map(fn ($id) => (int) $id);
$ungrantableGlobalIds = collect($globalIds)->diff($grantableGlobalIds);
$ungrantableStoreAssignments = $managedUser->storeRoleAssignments->filter(function ($assignment) use ($grantableStoreRolesByStore) {
    $allowed = $grantableStoreRolesByStore->get((int) $assignment->store_id, collect())->pluck('id')->map(fn ($id) => (int) $id);

    return ! $allowed->contains((int) $assignment->role_id);
});
$assignmentLocked = $ungrantableGlobalIds->isNotEmpty() || $ungrantableStoreAssignments->isNotEmpty();
$rolePresentation = static function ($role) use ($jobRoleMeta): array {
    $meta = $jobRoleMeta[$role->code] ?? null;

    return is_array($meta) ? $meta : [
        'name' => $role->name,
        'description' => $role->description ?? '',
        'domain_label' => __('job_roles.domains.custom'),
        'scope_label' => $role->scope === 'store' ? __('job_roles.scopes.store') : __('job_roles.scopes.tenant'),
        'level_label' => __('job_roles.custom'),
        'responsibilities' => [],
        'permission_summary' => [],
        'built_in' => false,
    ];
};
@endphp

@if($assignmentLocked)
    <div class="note">{{ __('security_permissions.protected_assignments') }}</div>
@else
<form method="post" action="{{ route('admin.security.users.roles', $managedUser) }}" data-impact-form
      data-impact-added="{{ __('security_permissions.impact_added') }}"
      data-impact-removed="{{ __('security_permissions.impact_removed') }}"
      data-impact-none="{{ __('security_permissions.impact_none') }}"
      data-impact-empty="{{ __('security_permissions.impact_empty') }}">
    @csrf
    @method('put')

    <label class="permission-search">
        <span class="muted">{{ __('security_permissions.search_assignments') }}</span>
        <input type="search" data-list-search placeholder="{{ __('security_permissions.search_assignments') }}" autocomplete="off">
    </label>
    <div class="note">{{ __('security_permissions.assignment_boundary_note') }}</div>

    <section data-search-group>
        <h4>{{ __('admin.security.global_roles') }}</h4>
        <div class="checks" data-search-container>
            @foreach($globalRoles as $role)
                @php
                    $isChecked = in_array((int) $role->id, $globalIds, true);
                    $isAvailable = (bool) $globalRoleAvailability->get((int) $role->id, true);
                    $meta = $rolePresentation($role);
                @endphp
                @if($isAvailable || $isChecked)
                    <label class="permission-option {{ $isAvailable ? '' : 'role-unavailable' }}"
                           data-search-item
                           data-search-text="{{ $meta['name'] }} {{ $meta['domain_label'] }} {{ $role->code }}">
                        @if(!$isAvailable && $isChecked)
                            <input type="hidden" name="global_role_ids[]" value="{{ $role->id }}">
                        @endif
                        <input type="checkbox"
                               name="global_role_ids[]"
                               value="{{ $role->id }}"
                               @checked($isChecked)
                               @disabled(!$isAvailable)
                               data-original-checked="{{ $isChecked ? '1' : '0' }}"
                               data-impact-label="{{ __('security_permissions.global_scope') }} · {{ $meta['name'] }}">
                        <span class="job-role-meta">
                            <strong>{{ $meta['name'] }}</strong>
                            <span class="job-role-tags">
                                <span class="badge">{{ $meta['domain_label'] }}</span>
                                <span class="badge">{{ $meta['level_label'] }}</span>
                                @if($meta['built_in'] ?? false)<span class="badge system">{{ __('job_roles.built_in') }}</span>@endif
                                @if(!$isAvailable)<span class="badge">{{ __('job_roles.availability.retained') }}</span>@endif
                            </span>
                            @if(($meta['description'] ?? '') !== '')<small class="muted">{{ $meta['description'] }}</small>@endif
                        </span>
                    </label>
                @endif
            @endforeach
        </div>
    </section>

    @if($stores->isNotEmpty())
        <h4>{{ __('admin.security.store_roles') }}</h4>
        @foreach($stores as $store)
            @php
                $assigned = ($byStore->get($store->id) ?? collect())->pluck('role_id')->map(fn ($id) => (int) $id)->all();
                $availableStoreRoles = $grantableStoreRolesByStore->get((int) $store->id, collect());
                $availability = $storeRoleAvailabilityByStore->get((int) $store->id, collect());
                $visibleStoreRoles = $availableStoreRoles->filter(
                    fn ($role) => (bool) $availability->get((int) $role->id, true)
                        || in_array((int) $role->id, $assigned, true)
                );
            @endphp
            @if($visibleStoreRoles->isNotEmpty())
                <details class="store assignment-store" data-search-group data-search-text="{{ $store->name }} {{ $store->code }}">
                    <summary><strong>{{ $store->name }}</strong> <small class="permission-code">{{ $store->code }}</small></summary>
                    <div class="checks" data-search-container>
                        @foreach($visibleStoreRoles as $role)
                            @php
                                $isChecked = in_array((int) $role->id, $assigned, true);
                                $isAvailable = (bool) $availability->get((int) $role->id, true);
                                $meta = $rolePresentation($role);
                            @endphp
                            <label class="permission-option {{ $isAvailable ? '' : 'role-unavailable' }}"
                                   data-search-item
                                   data-search-text="{{ $store->name }} {{ $store->code }} {{ $meta['name'] }} {{ $meta['domain_label'] }} {{ $role->code }}">
                                @if(!$isAvailable && $isChecked)
                                    <input type="hidden" name="store_role_ids[{{ $store->id }}][]" value="{{ $role->id }}">
                                @endif
                                <input type="checkbox"
                                       name="store_role_ids[{{ $store->id }}][]"
                                       value="{{ $role->id }}"
                                       @checked($isChecked)
                                       @disabled(!$isAvailable)
                                       data-original-checked="{{ $isChecked ? '1' : '0' }}"
                                       data-impact-label="{{ __('security_permissions.store_scope') }} · {{ $store->name }} · {{ $meta['name'] }}">
                                <span class="job-role-meta">
                                    <strong>{{ $meta['name'] }}</strong>
                                    <span class="job-role-tags">
                                        <span class="badge">{{ $meta['domain_label'] }}</span>
                                        <span class="badge">{{ $meta['level_label'] }}</span>
                                        @if($meta['built_in'] ?? false)<span class="badge system">{{ __('job_roles.built_in') }}</span>@endif
                                        @if(!$isAvailable)<span class="badge">{{ __('job_roles.availability.retained') }}</span>@endif
                                    </span>
                                    @if(($meta['description'] ?? '') !== '')<small class="muted">{{ $meta['description'] }}</small>@endif
                                </span>
                            </label>
                        @endforeach
                    </div>
                </details>
            @endif
        @endforeach
    @endif

    <div class="note permission-impact" data-impact-summary role="status" aria-live="polite">
        <strong>{{ __('security_permissions.impact_title') }}</strong>
        <span>{{ __('security_permissions.impact_none') }}</span>
    </div>

    <button class="btn primary">{{ __('admin.security.save_assignments') }}</button>
</form>
@endif
