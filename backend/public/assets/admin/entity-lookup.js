(() => {
    'use strict';

    const asInt = (value, fallback) => {
        const parsed = Number.parseInt(String(value ?? ''), 10);
        return Number.isFinite(parsed) ? parsed : fallback;
    };

    const mount = (root, index) => {
        if (!(root instanceof HTMLElement) || root.dataset.foodexEntityMounted === '1') return;
        const input = root.querySelector('[data-foodex-entity-search]');
        const results = root.querySelector('[data-foodex-entity-results]');
        const valueInput = root.querySelector('[data-foodex-entity-id]');
        if (!(input instanceof HTMLInputElement) || !(results instanceof HTMLElement)) return;

        root.dataset.foodexEntityMounted = '1';
        const resultKey = root.dataset.entityResultKey || 'items';
        const idKey = root.dataset.entityIdKey || 'id';
        const primaryKey = root.dataset.entityPrimaryKey || 'name';
        const metaFields = (root.dataset.entityMetaFields || '').split(',').map(value => value.trim()).filter(Boolean);
        const selectionMode = root.dataset.entitySelectionMode || (valueInput ? 'value' : 'navigate');
        const queryParam = root.dataset.entityQueryParam || 'id';
        const minQuery = Math.max(0, asInt(root.dataset.entityMinQuery, 0));
        const debounceMs = Math.max(0, asInt(root.dataset.entityDebounceMs, 180));
        const optionClass = root.dataset.entityOptionClass || 'foodex-entity-option';
        const optionPrefix = input.id || ('foodex-entity-' + index);
        const selectedLabel = (root.dataset.entitySelectedLabel || input.defaultValue || '').trim();
        const selectedId = () => valueInput instanceof HTMLInputElement ? valueInput.value : '';
        let timer = 0;
        let controller = null;
        let generation = 0;
        let activeIndex = -1;
        let items = [];
        let nextPage = null;

        if (!results.id) results.id = optionPrefix + '-results';
        input.setAttribute('role', 'combobox');
        input.setAttribute('aria-autocomplete', 'list');
        input.setAttribute('aria-controls', results.id);
        input.setAttribute('aria-expanded', 'false');
        results.setAttribute('role', 'listbox');

        const state = document.createElement('div');
        state.className = root.dataset.entityStateClass || 'foodex-entity-state';
        state.dataset.foodexEntityState = '';
        state.setAttribute('role', 'status');
        state.setAttribute('aria-live', 'polite');
        results.insertAdjacentElement('afterend', state);

        const setState = (kind, message = '') => {
            root.dataset.entityState = kind;
            state.replaceChildren();
            if (message) {
                const copy = document.createElement('span');
                copy.textContent = message;
                state.appendChild(copy);
            }
        };

        const clearActive = () => {
            activeIndex = -1;
            input.removeAttribute('aria-activedescendant');
            [...results.querySelectorAll('[role="option"]')].forEach(option => option.setAttribute('aria-selected', 'false'));
        };

        const close = () => {
            results.hidden = true;
            input.setAttribute('aria-expanded', 'false');
            clearActive();
        };

        const open = () => {
            results.hidden = false;
            input.setAttribute('aria-expanded', 'true');
        };

        const optionNodes = () => [...results.querySelectorAll('[role="option"][data-foodex-entity-option]')];

        const activate = (nextIndex) => {
            const options = optionNodes();
            if (options.length === 0) {
                clearActive();
                return;
            }
            activeIndex = (nextIndex + options.length) % options.length;
            options.forEach((option, optionIndex) => {
                const active = optionIndex === activeIndex;
                option.setAttribute('aria-selected', active ? 'true' : 'false');
                if (active) {
                    input.setAttribute('aria-activedescendant', option.id);
                    option.scrollIntoView({ block: 'nearest' });
                }
            });
        };

        const labelFor = item => {
            const primary = String(item?.[primaryKey] ?? '').trim();
            const meta = metaFields
                .map(field => String(item?.[field] ?? '').trim())
                .filter((value, metaIndex, values) => value !== '' && values.indexOf(value) === metaIndex);
            return { primary, meta };
        };

        const select = item => {
            if (!item) return;
            const identity = String(item?.[idKey] ?? '');
            const labels = labelFor(item);
            const label = [labels.primary, ...labels.meta.slice(0, 1)].filter(Boolean).join(' · ');

            if (selectionMode === 'navigate') {
                const url = new URL(window.location.href);
                url.searchParams.set(queryParam, identity);
                window.location.assign(url.toString());
                return;
            }

            if (valueInput instanceof HTMLInputElement) valueInput.value = identity;
            input.value = label || labels.primary;
            root.dataset.entitySelectedLabel = input.value.trim();
            root.dataset.entitySelectedId = identity;
            setState('selected', root.dataset.entitySelected || '');
            close();
            root.dispatchEvent(new CustomEvent('foodex:entity-selected', { bubbles: true, detail: { item } }));
        };

        const render = (incoming, append = false) => {
            const pageItems = Array.isArray(incoming) ? incoming : [];
            if (!append) {
                results.replaceChildren();
                items = [];
            }
            const offset = items.length;
            items = items.concat(pageItems);

            pageItems.forEach((item, localIndex) => {
                const labels = labelFor(item);
                const option = document.createElement('button');
                const itemIndex = offset + localIndex;
                option.type = 'button';
                option.id = optionPrefix + '-option-' + itemIndex;
                option.className = optionClass;
                option.dataset.foodexEntityOption = String(itemIndex);
                option.setAttribute('role', 'option');
                option.setAttribute('aria-selected', String(item?.[idKey] ?? '') === selectedId() ? 'true' : 'false');
                option.tabIndex = -1;

                const primary = document.createElement('strong');
                primary.textContent = labels.primary || String(item?.[idKey] ?? '');
                option.appendChild(primary);

                const meta = labels.meta.slice();
                if (item?.is_active === false && root.dataset.entityInactive) meta.push(root.dataset.entityInactive);
                if (meta.length > 0) {
                    const secondary = document.createElement('small');
                    secondary.textContent = meta.join(' · ');
                    option.appendChild(secondary);
                }

                option.addEventListener('mousedown', event => event.preventDefault());
                option.addEventListener('click', () => select(item));
                results.appendChild(option);
            });

            if (items.length === 0) {
                close();
                setState('empty', root.dataset.entityNoResults || '');
                return;
            }

            setState('ready', '');
            open();

            if (nextPage !== null) {
                const more = document.createElement('button');
                more.type = 'button';
                more.className = root.dataset.entityMoreClass || optionClass;
                more.dataset.foodexEntityMore = '';
                more.textContent = root.dataset.entityLoadMore || '';
                more.addEventListener('click', () => search({ append: true, page: nextPage }));
                results.appendChild(more);
            }
        };

        const showError = () => {
            close();
            setState('error', root.dataset.entityError || '');
            const retry = document.createElement('button');
            retry.type = 'button';
            retry.dataset.foodexEntityRetry = '';
            retry.textContent = root.dataset.entityRetry || '';
            retry.addEventListener('click', () => search());
            state.appendChild(retry);
        };

        const search = async ({ append = false, page = null } = {}) => {
            if (input.disabled) return;
            const rawQuery = input.value.trim();
            const effectiveQuery = selectedLabel !== '' && rawQuery === selectedLabel ? '' : rawQuery;
            if (effectiveQuery.length < minQuery && effectiveQuery !== '') {
                close();
                setState('idle', '');
                return;
            }

            generation += 1;
            const ownedGeneration = generation;
            if (controller) controller.abort();
            controller = new AbortController();
            setState('loading', root.dataset.entityLoading || '');
            if (!append) {
                results.replaceChildren();
                items = [];
                nextPage = null;
            }

            const url = new URL(root.dataset.entitySearchUrl || window.location.href, window.location.origin);
            url.searchParams.set('q', effectiveQuery);
            if (page !== null) url.searchParams.set(root.dataset.entityPageParam || 'page', String(page));

            try {
                const response = await fetch(url.toString(), {
                    headers: { Accept: 'application/json' },
                    signal: controller.signal,
                });
                if (!response.ok) throw new Error('foodex-entity-search');
                const payload = await response.json();
                if (ownedGeneration !== generation) return;
                nextPage = payload?.next_page ?? null;
                render(payload?.[resultKey] || [], append);
            } catch (error) {
                if (error?.name === 'AbortError' || ownedGeneration !== generation) return;
                showError();
            }
        };

        const queueSearch = () => {
            window.clearTimeout(timer);
            timer = window.setTimeout(() => search(), debounceMs);
        };

        input.addEventListener('input', () => {
            if (valueInput instanceof HTMLInputElement && input.value.trim() !== root.dataset.entitySelectedLabel) {
                valueInput.value = '';
                root.dataset.entitySelectedId = '';
                root.dispatchEvent(new CustomEvent('foodex:entity-cleared', { bubbles: true }));
            }
            queueSearch();
        });

        input.addEventListener('focus', () => {
            if (selectedLabel !== '' && input.value.trim() === selectedLabel) input.select();
            if (results.hidden) search();
        });

        input.addEventListener('keydown', event => {
            if (event.key === 'ArrowDown') {
                event.preventDefault();
                if (results.hidden) {
                    search();
                } else {
                    activate(activeIndex + 1);
                }
            } else if (event.key === 'ArrowUp') {
                event.preventDefault();
                if (!results.hidden) activate(activeIndex - 1);
            } else if (event.key === 'Enter' && !results.hidden && activeIndex >= 0) {
                event.preventDefault();
                select(items[activeIndex]);
            } else if (event.key === 'Escape') {
                event.preventDefault();
                close();
            } else if (event.key === 'Tab') {
                close();
            }
        });

        root.querySelectorAll('[data-foodex-entity-clear]').forEach(button => {
            button.addEventListener('click', () => {
                if (valueInput instanceof HTMLInputElement) valueInput.value = '';
                input.value = '';
                root.dataset.entitySelectedId = '';
                root.dataset.entitySelectedLabel = '';
                setState('cleared', root.dataset.entityCleared || '');
                close();
                input.focus();
                root.dispatchEvent(new CustomEvent('foodex:entity-cleared', { bubbles: true }));
            });
        });

        document.addEventListener('click', event => {
            if (!root.contains(event.target)) close();
        });

        if (input.disabled) {
            root.dataset.entityState = 'disabled';
        } else if (input.getAttribute('aria-invalid') === 'true') {
            root.dataset.entityState = 'invalid';
        } else if (root.dataset.entitySelectedInactive === '1') {
            setState('inactive', root.dataset.entityInactive || '');
        } else if (selectedId() !== '' || selectedLabel !== '') {
            root.dataset.entityState = 'selected';
        } else {
            root.dataset.entityState = 'idle';
        }
    };

    const mountAll = scope => {
        (scope || document).querySelectorAll('[data-foodex-entity-picker]').forEach((root, index) => mount(root, index));
    };

    window.FoodexEntityLookup = { mountAll };
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => mountAll(document), { once: true });
    } else {
        mountAll(document);
    }
})();