(() => {
    'use strict';

    const debounceMs = 280;

    document.querySelectorAll('[data-foodex-global-search]').forEach((root) => {
        if (root.dataset.globalSearchReady === '1') return;
        root.dataset.globalSearchReady = '1';

        const input = root.querySelector('[data-foodex-global-search-input]');
        const results = root.querySelector('[data-foodex-global-search-results]');
        const status = root.querySelector('[data-foodex-global-search-status]');
        if (!input || !results || !status) return;

        const endpoint = root.dataset.searchUrl || '';
        const labels = {
            loading: root.dataset.loading || '',
            empty: root.dataset.empty || '',
            error: root.dataset.error || '',
        };

        let timer = null;
        let controller = null;
        let generation = 0;
        let activeIndex = -1;

        const options = () => Array.from(results.querySelectorAll('[role="option"]'));

        function close() {
            results.hidden = true;
            input.setAttribute('aria-expanded', 'false');
            input.removeAttribute('aria-activedescendant');
            activeIndex = -1;
        }

        function setActive(index) {
            const rows = options();
            if (!rows.length) return;
            activeIndex = Math.max(0, Math.min(index, rows.length - 1));
            rows.forEach((row, rowIndex) => row.setAttribute('aria-selected', rowIndex === activeIndex ? 'true' : 'false'));
            input.setAttribute('aria-activedescendant', rows[activeIndex].id);
            rows[activeIndex].scrollIntoView({block: 'nearest'});
        }

        function render(items) {
            results.replaceChildren();
            activeIndex = -1;

            if (!items.length) {
                status.textContent = labels.empty;
                close();
                return;
            }

            const fragment = document.createDocumentFragment();
            items.forEach((item, index) => {
                const link = document.createElement('a');
                link.id = input.id + '-option-' + index;
                link.className = 'foodex-global-search-result';
                link.href = String(item.url || '#');
                link.setAttribute('role', 'option');
                link.setAttribute('aria-selected', 'false');

                const type = document.createElement('span');
                type.textContent = String(item.type_label || '');
                const title = document.createElement('strong');
                title.textContent = String(item.title || '');
                const subtitle = document.createElement('small');
                subtitle.textContent = String(item.subtitle || '');

                link.append(type, title, subtitle);
                fragment.appendChild(link);
            });

            results.appendChild(fragment);
            results.hidden = false;
            input.setAttribute('aria-expanded', 'true');
            status.textContent = '';
        }

        async function search(term, currentGeneration) {
            if (controller) controller.abort();
            controller = new AbortController();
            status.textContent = labels.loading;

            try {
                const url = new URL(endpoint, window.location.origin);
                url.searchParams.set('q', term);
                url.searchParams.set('limit', '5');
                const response = await fetch(url, {
                    credentials: 'same-origin',
                    headers: {'Accept': 'application/json'},
                    signal: controller.signal,
                });
                if (!response.ok) throw new Error('search_failed');
                const payload = await response.json();
                if (currentGeneration !== generation) return;
                render(Array.isArray(payload.data) ? payload.data : []);
            } catch (error) {
                if (error && error.name === 'AbortError') return;
                if (currentGeneration !== generation) return;
                close();
                status.textContent = labels.error;
            }
        }

        input.addEventListener('input', () => {
            generation += 1;
            const currentGeneration = generation;
            const term = input.value.trim();
            if (timer) window.clearTimeout(timer);
            if (controller) controller.abort();

            if (term.length < 2) {
                results.replaceChildren();
                close();
                status.textContent = '';
                return;
            }

            timer = window.setTimeout(() => search(term, currentGeneration), debounceMs);
        });

        input.addEventListener('keydown', async (event) => {
            const rows = options();
            if (event.key === 'ArrowDown' && rows.length) {
                event.preventDefault();
                setActive(activeIndex + 1);
            } else if (event.key === 'ArrowUp' && rows.length) {
                event.preventDefault();
                setActive(activeIndex <= 0 ? rows.length - 1 : activeIndex - 1);
            } else if (event.key === 'Enter') {
                const selected = activeIndex >= 0 ? rows[activeIndex] : rows[0];
                if (selected) {
                    event.preventDefault();
                    selected.click();
                    return;
                }

                const term = input.value.trim();
                if (term.length < 2) return;

                event.preventDefault();
                if (timer) {
                    window.clearTimeout(timer);
                    timer = null;
                }
                generation += 1;
                const currentGeneration = generation;
                await search(term, currentGeneration);

                const firstResult = options()[0];
                if (firstResult && currentGeneration === generation) {
                    firstResult.click();
                }
            } else if (event.key === 'Escape') {
                close();
            }
        });

        document.addEventListener('pointerdown', (event) => {
            if (!root.contains(event.target)) close();
        });
    });
})();
