<script>
(() => {
    const normalize = (value) => (value || '').toLocaleLowerCase();

    document.querySelectorAll('[data-impact-form]').forEach((form) => {
        const summary = form.querySelector('[data-impact-summary]');
        if (!summary) return;

        const parseJson = (value, fallback) => {
            try {
                return value ? JSON.parse(value) : fallback;
            } catch (_) {
                return fallback;
            }
        };

        const updateRolePreview = () => {
            const selected = [...form.querySelectorAll('input[type="checkbox"][data-role-code]:checked')];
            const preview = form.querySelector('[data-role-effective-preview]');
            if (preview) {
                const rolesTarget = preview.querySelector('[data-role-effective-roles]');
                const permissionsTarget = preview.querySelector('[data-role-effective-permissions]');
                const roleNames = [...new Set(selected.map((input) => input.dataset.roleName).filter(Boolean))];
                const summaries = [...new Set(selected.flatMap((input) =>
                    parseJson(input.dataset.rolePermissionSummary, [])
                ).filter(Boolean))];

                if (rolesTarget) {
                    rolesTarget.textContent = roleNames.length
                        ? roleNames.join(', ')
                        : @json(__('job_roles.effective_empty'));
                }
                if (permissionsTarget) permissionsTarget.textContent = summaries.join(', ');
            }

            const warning = form.querySelector('[data-role-risk-warning]');
            if (warning) {
                const selectedCodes = new Set(selected.map((input) => input.dataset.roleCode).filter(Boolean));
                const pairs = parseJson(form.dataset.roleRiskPairs, {});
                const messages = parseJson(form.dataset.roleRiskMessages, {});
                const hits = Object.entries(pairs)
                    .filter(([, definition]) => {
                        const roles = Array.isArray(definition?.roles) ? definition.roles : [];
                        return roles.length > 1 && roles.every((role) => selectedCodes.has(role));
                    })
                    .map(([key]) => messages[key] || key);

                warning.hidden = hits.length === 0;
                const target = warning.querySelector('span');
                if (target) target.textContent = hits.join(' · ');
            }
        };

        const updateImpact = () => {
            const changed = [...form.querySelectorAll('input[type="checkbox"][data-original-checked]')]
                .filter((input) => input.checked !== (input.dataset.originalChecked === '1'));
            const added = changed.filter((input) => input.checked).map((input) => input.dataset.impactLabel);
            const removed = changed.filter((input) => !input.checked).map((input) => input.dataset.impactLabel);
            const none = form.dataset.impactNone;
            const empty = form.dataset.impactEmpty;
            const addedLabel = form.dataset.impactAdded;
            const removedLabel = form.dataset.impactRemoved;
            const detail = changed.length === 0
                ? none
                : `${addedLabel}: ${added.length ? added.join(', ') : empty} · ${removedLabel}: ${removed.length ? removed.join(', ') : empty}`;
            const target = summary.querySelector('span');
            if (target) target.textContent = detail;
            updateRolePreview();
        };

        form.addEventListener('change', (event) => {
            if (event.target.matches('input[type="checkbox"]')) updateImpact();
        });
        updateImpact();
    });

    document.querySelectorAll('[data-list-search]').forEach((input) => {
        const root = input.closest('[data-permission-picker], form') || document;
        const apply = () => {
            const query = normalize(input.value.trim());
            root.querySelectorAll('[data-search-item]').forEach((item) => {
                item.hidden = query !== '' && !normalize(item.dataset.searchText || item.textContent).includes(query);
            });
            root.querySelectorAll('[data-search-group]').forEach((group) => {
                const items = [...group.querySelectorAll('[data-search-item]')];
                const groupMatches = normalize(group.dataset.searchText || '').includes(query);
                group.hidden = query !== '' && !groupMatches && items.length > 0 && items.every((item) => item.hidden);
                if (query !== '' && groupMatches) items.forEach((item) => { item.hidden = false; });
            });
        };
        input.addEventListener('input', apply);
        apply();
    });

    const updateCount = (group) => {
        const target = group.querySelector('[data-selected-count]');
        if (!target) return;
        const count = group.querySelectorAll('input[type="checkbox"]:checked').length;
        const template = @json(__('security_permissions.selected_count'));
        target.textContent = template.replace(':count', String(count));
    };

    document.querySelectorAll('[data-permission-picker] [data-search-group]').forEach((group) => {
        group.addEventListener('change', () => updateCount(group));
        group.querySelectorAll('[data-bulk-action]').forEach((button) => {
            button.addEventListener('click', () => {
                const checked = button.dataset.bulkAction === 'select';
                group.querySelectorAll('[data-search-item]:not([hidden]) input[type="checkbox"]:not(:disabled)').forEach((input) => {
                    input.checked = checked;
                    input.dispatchEvent(new Event('change', { bubbles: true }));
                });
                updateCount(group);
            });
        });
        updateCount(group);
    });
})();
</script>
