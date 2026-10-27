@php
$selectedPermissionIds = $selectedPermissionIds ?? [];
$permissionInputName = $permissionInputName ?? 'permission_ids';
$permissionBoundaryNote = $permissionBoundaryNote ?? __('security_permissions.grant_boundary_note');
$showPermissionScopeRule = $showPermissionScopeRule ?? true;
@endphp
<div class="permission-picker" data-permission-picker data-impact-form
     data-impact-added="{{ __('security_permissions.impact_added') }}"
     data-impact-removed="{{ __('security_permissions.impact_removed') }}"
     data-impact-none="{{ __('security_permissions.impact_none') }}"
     data-impact-empty="{{ __('security_permissions.impact_empty') }}">
    <label class="permission-search">
        <span class="muted">{{ __('security_permissions.search_permissions') }}</span>
        <input type="search" data-list-search placeholder="{{ __('security_permissions.search_permissions') }}" autocomplete="off">
    </label>
    <div class="note">{{ $permissionBoundaryNote }}</div>
    @if($showPermissionScopeRule)
        <div class="note">{{ __('security_permissions.scope_rule') }}</div>
    @endif

    <div class="permissions" data-search-container>
        @foreach($permissionGroups as $module => $group)
            <fieldset class="permission-group" data-search-group>
                <legend>{{ $permissionGroupLabels[$module] ?? IlluminateSupportStr::headline(str_replace('_', ' ', $module)) }}</legend>
                <div class="permission-group-tools">
                    <span class="muted" data-selected-count></span>
                    <button type="button" class="btn secondary" data-bulk-action="select">{{ __('security_permissions.select_visible') }}</button>
                    <button type="button" class="btn secondary" data-bulk-action="clear">{{ __('security_permissions.clear_visible') }}</button>
                </div>
                @foreach($group as $permission)
                    @php
                        $isChecked = in_array((int) $permission->id, $selectedPermissionIds, true);
                        $label = $permissionLabels[(int) $permission->id] ?? $permission->name;
                    @endphp
                    <label class="permission-option" data-search-item data-search-text="{{ $label }} {{ $permission->code }}">
                        <input type="checkbox"
                               name="{{ $permissionInputName }}[]"
                               value="{{ $permission->id }}"
                               @checked($isChecked)
                               data-original-checked="{{ $isChecked ? '1' : '0' }}"
                               data-impact-label="{{ $label }}">
                        <span>
                            <strong>{{ $label }}</strong>
                            <small class="permission-code">{{ __('security_permissions.technical_code') }}: <code>{{ $permission->code }}</code></small>
                            @if(in_array($permission->code, $privilegedPermissionCodes, true))
                                <small class="badge system">{{ __('security_permissions.privileged') }}</small>
                            @endif
                        </span>
                    </label>
                @endforeach
            </fieldset>
        @endforeach
    </div>

    <div class="note permission-impact" data-impact-summary role="status" aria-live="polite">
        <strong>{{ __('security_permissions.impact_title') }}</strong>
        <span>{{ __('security_permissions.impact_none') }}</span>
    </div>
</div>
