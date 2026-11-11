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
// Blade's escaped echo performs the single HTML escape. A prior explicit e()\n// made DOM dataset JSON invalid and silently disabled role conflict warnings.\n$roleRiskPairs = (array) config('job_roles.risk_pairs', []);
$roleRiskMessages = collect(array_keys($roleRiskPairs))
    ->mapWithKeys(fn (string $key): array => [$key => __('job_roles.risk_warnings.'.$key)])
    ->all();
@endphp

@if($assignmentLocked)
    <div class="note">{{ __('security_permissions.protected_assignments') }}</div>
@else
<form method="post" action="{{ route('admin.security.users.roles', $managedUser) }}" data-impact-form
      data-impact-added="{{ __('security_permissions.impact_added') }}"
      data-impact-removed="{{ __('security_permissions.impact_removed') }}"
      data-impact-none="{{ __('security_permissions.impact_none') }}"
      data-impact-empty="{{ __('security_permissions.impact_empty') }}"
      data-role-risk-pairs="{{ json_encode($roleRiskPairs, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}"
      data-role-risk-messages="{{ json_encode($roleRiskMessages, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}">
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
                               data-impact-label="{{ __('security_permissions.global_scope') }} · {{ $meta['name'] }}"
                               data-role-code="{{ $role->code }}"
                               data-role-name="{{ $meta['name'] }}"
                               data-role-permission-summary="{{ json_encode($meta['permission_summary'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}">
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
                                       data-impact-label="{{ __('security_permissions.store_scope') }} · {{ $store->name }} · {{ $meta['name'] }}"
                                       data-role-code="{{ $role->code }}"
                                       data-role-name="{{ $store->name }} · {{ $meta['name'] }}"
                                       data-role-permission-summary="{{ json_encode($meta['permission_summary'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}">
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

    <div class="note role-effective-preview" data-role-effective-preview role="status" aria-live="polite">
        <strong>{{ __('job_roles.effective_access_title') }}</strong>
        <div><span class="muted">{{ __('job_roles.effective_roles') }}:</span> <span data-role-effective-roles>{{ __('job_roles.effective_empty') }}</span></div>
        <div><span class="muted">{{ __('job_roles.effective_permissions') }}:</span> <span data-role-effective-permissions></span></div>
    </div>

    {{-- P077: provisional, read-only tree beside the existing role/scope picker.
         HTTP authorization remains definitive; never use this projection as an allow. --}}
    <section class="note v2-user-page-tree" data-v2-user-page-tree
             data-preview-url="{{ route('admin.security.role-tree-preview') }}"
             data-loading="{{ __('admin.security.page_tree_preview_loading') }}"
             data-error="{{ __('admin.security.page_tree_preview_error') }}"
             data-empty="{{ __('admin.security.page_tree_preview_empty') }}">
        <strong>{{ __('admin.security.page_tree_preview_title') }}</strong>
        <label class="v2-tree-scope-picker">
            <span>{{ __('admin.security.page_tree_preview_scope') }}</span>
            <select data-v2-user-tree-scope aria-label="{{ __('admin.security.page_tree_preview_scope') }}">
                <option value="">{{ __('admin.security.global_roles') }}</option>
                @foreach($stores as $previewStore)
                    @if($grantableStoreRolesByStore->get((int)$previewStore->id, collect())->isNotEmpty())
                        <option value="{{ $previewStore->id }}">{{ $previewStore->name }}</option>
                    @endif
                @endforeach
            </select>
        </label>
        <button type="button" class="btn secondary" data-v2-user-tree-refresh>{{ __('admin.security.page_tree_preview_refresh') }}</button>
        <p data-v2-user-tree-status role="status" aria-live="polite">{{ __('admin.security.page_tree_preview_initial') }}</p>
        <div class="v2-tree-results" data-v2-user-tree-results></div>
        <p class="muted">{{ __('admin.security.page_tree_preview_disclaimer') }}</p>
    </section>

    <div class="note role-risk-warning" data-role-risk-warning role="alert" hidden>
        <strong>{{ __('job_roles.risk_warning_title') }}</strong>
        <span></span>
    </div>

    <button class="btn primary">{{ __('admin.security.save_assignments') }}</button>
</form>
@once
<style>
.v2-user-page-tree{display:grid;gap:10px;min-width:0}
.v2-tree-scope-picker{display:grid;gap:6px;min-width:0}
.v2-tree-scope-picker select{width:100%;min-height:44px;font:inherit}
.v2-tree-results{max-height:340px;overflow:auto;overflow-wrap:anywhere}
.v2-tree-results details{border:1px solid var(--foodex-border);border-radius:9px;margin-block:7px;padding:8px}
.v2-tree-results summary{cursor:pointer;font-weight:700}
.v2-tree-results ul{margin-block:8px;padding-inline-start:22px}
.v2-tree-results li{margin-block:4px}
</style>
<script>
(() => {
    'use strict';
    const getForm = (node) => node.closest('form[data-impact-form]');
    const isRoleChoice = (node) => node instanceof HTMLInputElement
        && (node.name === 'global_role_ids[]' || node.name.startsWith('store_role_ids['));

    const refresh = async (form) => {
        if (!form) return;
        const section = form.querySelector('[data-v2-user-page-tree]');
        if (!section) return;
        const status = section.querySelector('[data-v2-user-tree-status]');
        const results = section.querySelector('[data-v2-user-tree-results]');
        const selectedStore = section.querySelector('[data-v2-user-tree-scope]').value;
        const generation = Number(section.dataset.generation || 0) + 1;
        section.dataset.generation = String(generation);
        results.replaceChildren();
        status.textContent = section.dataset.loading;

        const query = new URLSearchParams();
        query.set('scope', selectedStore ? 'store' : 'global');
        if (selectedStore) query.set('store_id', selectedStore);
        const storeName = selectedStore ? 'store_role_ids[' + selectedStore + '][]' : '';
        for (const input of form.querySelectorAll('input')) {
            if (input.type !== 'hidden' && (input.type !== 'checkbox' || !input.checked || input.disabled)) continue;
            if (input.name === 'global_role_ids[]') query.append('global_role_ids[]', input.value);
            if (storeName && input.name === storeName) query.append('store_role_ids[]', input.value);
        }

        try {
            const response = await fetch(section.dataset.previewUrl + '?' + query.toString(), {
                method: 'GET',
                credentials: 'same-origin',
                headers: {'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest'},
                cache: 'no-store'
            });
            if (!response.ok) throw new Error('Preview denied');
            const payload = await response.json();
            if (generation !== Number(section.dataset.generation)) return;
            status.textContent = payload.count ? payload.disclaimer : section.dataset.empty;
            for (const group of (payload.groups || [])) {
                const container = document.createElement('details');
                const heading = document.createElement('summary');
                heading.textContent = group.label + ' (' + group.items.length + ')';
                const list = document.createElement('ul');
                for (const item of group.items) {
                    const node = document.createElement('li');
                    node.textContent = item;
                    list.append(node);
                }
                container.append(heading, list);
                results.append(container);
            }
        } catch (error) {
            if (generation === Number(section.dataset.generation)) {
                results.replaceChildren();
                status.textContent = section.dataset.error;
            }
        }
    };

    document.addEventListener('change', (event) => {
        const node = event.target;
        const form = getForm(node);
        if (form && (isRoleChoice(node) || node.matches('[data-v2-user-tree-scope]'))) refresh(form);
    });
    document.addEventListener('click', (event) => {
        const button = event.target.closest('[data-v2-user-tree-refresh]');
        if (button) refresh(getForm(button));
    });
})();
</script>
@endonce
@endif
