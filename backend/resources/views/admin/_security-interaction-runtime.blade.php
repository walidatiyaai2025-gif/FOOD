<script>
(() => {
    const normalize = (value) => (value || '').toLocaleLowerCase();

    document.querySelectorAll('[data-impact-form]').forEach((form) => {
        const summary = form.querySelector('[data-impact-summary]');
        if (!summary) return;

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

    document.querySelectorAll('form[data-submit-lock]').forEach((form) => {
        form.addEventListener('submit', () => {
            form.querySelectorAll('button[type="submit"]').forEach((button) => {
                button.disabled = true;
                button.setAttribute('aria-busy', 'true');
            });
        });
    });

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
