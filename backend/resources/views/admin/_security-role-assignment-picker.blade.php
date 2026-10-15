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
                @php $isChecked = in_array((int) $role->id, $globalIds, true); @endphp
                <label class="permission-option" data-search-item data-search-text="{{ $role->name }} {{ $role->code }}">
                    <input type="checkbox"
                           name="global_role_ids[]"
                           value="{{ $role->id }}"
                           @checked($isChecked)
                           data-original-checked="{{ $isChecked ? '1' : '0' }}"
                           data-impact-label="{{ __('security_permissions.global_scope') }} · {{ $role->name }}">
                    <span><strong>{{ $role->name }}</strong> <small class="permission-code">{{ $role->code }}</small></span>
                </label>
            @endforeach
        </div>
    </section>

    @if($stores->isNotEmpty())
        <h4>{{ __('admin.security.store_roles') }}</h4>
        @foreach($stores as $store)
            @php
                $assigned = ($byStore->get($store->id) ?? collect())->pluck('role_id')->map(fn ($id) => (int) $id)->all();
                $availableStoreRoles = $grantableStoreRolesByStore->get((int) $store->id, collect());
            @endphp
            @if($availableStoreRoles->isNotEmpty())
                <details class="store assignment-store" data-search-group data-search-text="{{ $store->name }} {{ $store->code }}">
                    <summary><strong>{{ $store->name }}</strong> <small class="permission-code">{{ $store->code }}</small></summary>
                    <div class="checks" data-search-container>
                        @foreach($availableStoreRoles as $role)
                            @php $isChecked = in_array((int) $role->id, $assigned, true); @endphp
                            <label class="permission-option" data-search-item data-search-text="{{ $store->name }} {{ $store->code }} {{ $role->name }} {{ $role->code }}">
                                <input type="checkbox"
                                       name="store_role_ids[{{ $store->id }}][]"
                                       value="{{ $role->id }}"
                                       @checked($isChecked)
                                       data-original-checked="{{ $isChecked ? '1' : '0' }}"
                                       data-impact-label="{{ __('security_permissions.store_scope') }} · {{ $store->name }} · {{ $role->name }}">
                                <span><strong>{{ $role->name }}</strong> <small class="permission-code">{{ $role->code }}</small></span>
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
