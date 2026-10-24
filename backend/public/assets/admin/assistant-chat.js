(() => {
    const roots = [...document.querySelectorAll('[data-foodex-assistant]')];

    roots.forEach(root => {
        if (root.dataset.assistantReady === '1') return;
        root.dataset.assistantReady = '1';

        const drawer = root.querySelector('[data-assistant-drawer]');
        const opener = root.querySelector('[data-assistant-open]');
        const closer = root.querySelector('[data-assistant-close]');
        const newButton = root.querySelector('[data-assistant-new]');
        const clearButton = root.querySelector('[data-assistant-clear]');
        const form = root.querySelector('[data-assistant-form]');
        const input = root.querySelector('[data-assistant-input]');
        const sendButton = root.querySelector('[data-assistant-send]');
        const messages = root.querySelector('[data-assistant-messages]');
        const empty = root.querySelector('[data-assistant-empty]');
        const prompts = root.querySelector('[data-assistant-prompts]');
        const status = root.querySelector('[data-assistant-status]');
        const scope = root.querySelector('[data-assistant-scope]');
        const scopePrimary = root.querySelector('[data-assistant-scope-primary]');
        const scopeSecondary = root.querySelector('[data-assistant-scope-secondary]');

        if (!drawer || !opener || !form || !input || !messages) return;

        const baseUrl = String(root.dataset.baseUrl || '').replace(/\/$/, '');
        const bootstrapUrl = root.dataset.bootstrapUrl || (baseUrl + '/bootstrap');
        const locale = root.dataset.locale || document.documentElement.lang || 'en';
        const arabic = String(locale).toLowerCase().startsWith('ar');
        const userId = root.dataset.userId || 'unknown';
        const csrf = root.dataset.csrfToken || '';
        const conversationKey = 'foodex.assistant.conversation.' + userId;
        const openKey = 'foodex.assistant.open.' + userId;
        const INTERNAL_KEY_PATTERN = /(^_|(^|_)(id|ids|code|route_name|route_parameters|call_id|evidence_id|store_ids|provider|budget|chain_of_thought_stored)$)/i;

        let conversationId = localStorage.getItem(conversationKey) || '';
        let busy = false;
        let bootstrapped = false;
        let contextToken = '';
        let contextSignature = '';
        let serverContext = null;
        let previousFocus = null;

        const label = key => root.dataset[key] || '';

        const unwrap = payload => {
            if (!payload || typeof payload !== 'object') return {};
            if (payload.data && typeof payload.data === 'object' && !Array.isArray(payload.data)) return payload.data;
            return payload;
        };

        const safeText = value => typeof value === 'string' ? value.trim() : '';

        const setStatus = (message = '', state = '') => {
            if (!status) return;
            status.textContent = message;
            if (state) status.dataset.state = state;
            else delete status.dataset.state;
        };

        const setBusy = value => {
            busy = Boolean(value);
            root.dataset.busy = busy ? '1' : '0';
            drawer.dataset.streaming = busy ? 'true' : 'false';
            messages.setAttribute('aria-busy', busy ? 'true' : 'false');
            input.disabled = busy;
            if (sendButton) sendButton.disabled = busy;
        };

        const focusableElements = () => [...drawer.querySelectorAll(
            'a[href],button:not([disabled]),textarea:not([disabled]),input:not([disabled]),select:not([disabled]),[tabindex]:not([tabindex="-1"])',
        )].filter(node => !node.hidden && node.offsetParent !== null);

        const trapFocus = event => {
            if (event.key !== 'Tab' || drawer.hidden || !matchMedia('(max-width:1023px)').matches) return;

            const focusable = focusableElements();
            if (focusable.length === 0) return;

            const first = focusable[0];
            const last = focusable[focusable.length - 1];

            if (event.shiftKey && document.activeElement === first) {
                event.preventDefault();
                last.focus();
            } else if (!event.shiftKey && document.activeElement === last) {
                event.preventDefault();
                first.focus();
            }
        };

        const setOpen = value => {
            const open = Boolean(value);
            if (open && drawer.hidden) previousFocus = document.activeElement;

            drawer.hidden = !open;
            drawer.setAttribute('aria-hidden', open ? 'false' : 'true');
            const modal = matchMedia('(max-width:1023px)').matches && open;
            drawer.setAttribute('aria-modal', modal ? 'true' : 'false');
            opener.setAttribute('aria-expanded', open ? 'true' : 'false');
            document.body.classList.toggle('foodex-assistant-open-mobile', modal);
            localStorage.setItem(openKey, open ? '1' : '0');

            if (open) {
                if (!bootstrapped) bootstrap();
                window.setTimeout(() => input.focus(), 0);
            } else {
                const target = previousFocus instanceof HTMLElement && document.contains(previousFocus)
                    ? previousFocus
                    : opener;
                target.focus();
            }
        };

        const clearRenderedMessages = () => {
            messages.querySelectorAll('[data-assistant-message]').forEach(node => node.remove());
            if (empty) empty.hidden = false;
        };

        const scrollToLatest = () => {
            messages.scrollTop = messages.scrollHeight;
        };

        const safeActionUrl = value => {
            if (typeof value !== 'string' || value.trim() === '') return null;

            try {
                const url = new URL(value, window.location.origin);
                if (url.origin !== window.location.origin) return null;
                if (!url.pathname.startsWith('/admin')) return null;
                return url.pathname + url.search + url.hash;
            } catch {
                return null;
            }
        };

        const isInternalKey = key => INTERNAL_KEY_PATTERN.test(String(key || ''));

        const displayValue = (key, value) => {
            if (typeof value === 'boolean') return value ? label('labelYes') : label('labelNo');
            if (key === 'channel' && typeof value === 'string') return value.toUpperCase();
            if (key === 'flow' && value === 'sell_in') return label('labelSellIn');
            if (key === 'flow' && value === 'sell_out') return label('labelSellOut');

            if (typeof value === 'string') {
                const normalized = value.toLowerCase();
                if (normalized === 'fresh') return label('labelFresh');
                if (normalized === 'stale') return label('labelStale');
                if (normalized === 'partial' || normalized === 'bounded' || normalized === 'incomplete') return label('labelPartial');
                if (normalized === 'complete') return label('labelComplete');
            }

            return String(value);
        };

        const createChip = (text, tone = '') => {
            if (!text) return null;
            const chip = document.createElement('span');
            chip.className = 'foodex-assistant-chip';
            chip.textContent = text;
            if (tone) chip.dataset.tone = tone;
            return chip;
        };

        const renderCards = (container, cards) => {
            if (!Array.isArray(cards) || cards.length === 0) return;

            const list = document.createElement('div');
            list.className = 'foodex-assistant-cards';

            cards.slice(0, 8).forEach(card => {
                if (!card || typeof card !== 'object') return;

                const node = document.createElement('section');
                node.className = 'foodex-assistant-card';

                const title = safeText(card.title) || safeText(card.label) || safeText(card.name);
                if (title) {
                    const heading = document.createElement('strong');
                    heading.className = 'foodex-assistant-card-title';
                    heading.textContent = title;
                    node.appendChild(heading);
                }

                const values = card.data && typeof card.data === 'object' && !Array.isArray(card.data) ? card.data : card;
                const labels = card.labels && typeof card.labels === 'object' && !Array.isArray(card.labels) ? card.labels : {};

                Object.entries(values).slice(0, 12).forEach(([key, value]) => {
                    if (['title', 'label', 'name', 'data', 'actions', 'labels'].includes(key) || isInternalKey(key)) return;
                    if (!['string', 'number', 'boolean'].includes(typeof value)) return;

                    const localizedLabel = safeText(labels[key]);
                    if (!localizedLabel) return;

                    const row = document.createElement('div');
                    row.className = 'foodex-assistant-card-row';

                    const rowLabel = document.createElement('span');
                    rowLabel.textContent = localizedLabel;

                    const rowValue = document.createElement('strong');
                    rowValue.textContent = displayValue(key, value);

                    row.append(rowLabel, rowValue);
                    node.appendChild(row);
                });

                if (title || node.querySelector('.foodex-assistant-card-row')) list.appendChild(node);
            });

            if (list.childElementCount > 0) container.appendChild(list);
        };

        const renderActions = (container, actions) => {
            if (!Array.isArray(actions) || actions.length === 0) return;

            const list = document.createElement('div');
            list.className = 'foodex-assistant-actions';

            actions.slice(0, 8).forEach(action => {
                if (!action || typeof action !== 'object') return;

                const href = safeActionUrl(action.url || action.href || '');
                if (!href) return;

                const link = document.createElement('a');
                link.className = 'foodex-assistant-action';
                link.href = href;
                link.textContent = safeText(action.label) || label('labelOpenAction') || 'Open';
                list.appendChild(link);
            });

            if (list.childElementCount > 0) container.appendChild(list);
        };

        const namedCollections = (value, key, depth = 0, output = []) => {
            if (depth > 5 || !value || typeof value !== 'object') return output;

            if (!Array.isArray(value) && Array.isArray(value[key])) output.push(value[key]);

            const children = Array.isArray(value)
                ? value.slice(0, 12)
                : Object.entries(value)
                    .filter(([childKey]) => childKey !== '_agent')
                    .slice(0, 30)
                    .map(([, child]) => child);

            children.forEach(child => {
                if (child && typeof child === 'object') namedCollections(child, key, depth + 1, output);
            });

            return output;
        };

        const namedObjects = (value, keys, depth = 0, output = []) => {
            if (depth > 5 || !value || typeof value !== 'object') return output;

            if (!Array.isArray(value)) {
                keys.forEach(key => {
                    const candidate = value[key];
                    if (candidate && typeof candidate === 'object' && !Array.isArray(candidate)) output.push(candidate);
                });
            }

            const children = Array.isArray(value)
                ? value.slice(0, 12)
                : Object.entries(value)
                    .filter(([childKey]) => childKey !== '_agent' && !keys.includes(childKey))
                    .slice(0, 30)
                    .map(([, child]) => child);

            children.forEach(child => {
                if (child && typeof child === 'object') namedObjects(child, keys, depth + 1, output);
            });

            return output;
        };

        const severityLabel = severity => {
            const normalized = String(severity || '').toLowerCase();
            if (normalized === 'critical') return [label('labelCritical'), 'danger'];
            if (normalized === 'warning' || normalized === 'high') return [label('labelWarning'), 'warning'];
            return [label('labelWatch'), ''];
        };

        const renderFindings = (container, data) => {
            const groups = namedCollections(data, 'findings');
            const findings = groups.flat().filter(item => item && typeof item === 'object').slice(0, 8);
            if (findings.length === 0) return;

            const section = document.createElement('section');
            section.className = 'foodex-assistant-section';

            const heading = document.createElement('div');
            heading.className = 'foodex-assistant-section-title';
            heading.textContent = label('labelFindings');
            section.appendChild(heading);

            findings.forEach((finding, index) => {
                const title = safeText(finding.title) || safeText(finding.message);
                if (!title) return;

                const row = document.createElement('div');
                row.className = 'foodex-assistant-finding';

                const rank = document.createElement('span');
                rank.className = 'foodex-assistant-rank';
                rank.textContent = String(Number(finding.rank) > 0 ? Number(finding.rank) : index + 1);

                const copy = document.createElement('div');
                copy.className = 'foodex-assistant-finding-copy';
                const strong = document.createElement('strong');
                strong.textContent = title;
                copy.appendChild(strong);

                const evidenceCount = Array.isArray(finding.evidence) ? finding.evidence.length : 0;
                if (evidenceCount > 0) {
                    const meta = document.createElement('small');
                    meta.textContent = label('labelEvidenceItems') + ': ' + evidenceCount;
                    copy.appendChild(meta);
                }

                row.append(rank, copy);
                const [severityText, tone] = severityLabel(finding.severity);
                const chip = createChip(severityText, tone);
                if (chip) row.appendChild(chip);
                section.appendChild(row);
            });

            if (section.querySelector('.foodex-assistant-finding')) container.appendChild(section);
        };

        const renderRecommendations = (container, data) => {
            const groups = namedCollections(data, 'recommendations');
            const recommendations = groups.flat().filter(item => item && typeof item === 'object').slice(0, 8);
            if (recommendations.length === 0) return;

            const section = document.createElement('section');
            section.className = 'foodex-assistant-section';

            const heading = document.createElement('div');
            heading.className = 'foodex-assistant-section-title';
            heading.textContent = label('labelRecommendations');
            section.appendChild(heading);

            recommendations.forEach(recommendation => {
                const text = safeText(recommendation.text) || safeText(recommendation.message);
                if (!text) return;

                const row = document.createElement('div');
                row.className = 'foodex-assistant-recommendation';
                row.textContent = text;
                section.appendChild(row);
            });

            if (section.querySelector('.foodex-assistant-recommendation')) container.appendChild(section);
        };

        const formatDateish = value => {
            if (typeof value !== 'string' || value.trim() === '') return '';
            const parsed = new Date(value);
            if (Number.isNaN(parsed.getTime())) return value.slice(0, 32);

            try {
                return new Intl.DateTimeFormat(arabic ? 'ar-KW' : 'en-GB', {
                    dateStyle: 'medium',
                    timeStyle: value.includes('T') ? 'short' : undefined,
                }).format(parsed);
            } catch {
                return value.slice(0, 32);
            }
        };

        const renderApproval = (container, data) => {
            const drafts = namedObjects(data, ['approval_draft', 'approvalDraft', 'approval']);
            const draft = drafts.find(item => item && typeof item === 'object');
            if (!draft) return;

            const structuredPreview = draft.preview && typeof draft.preview === 'object' && !Array.isArray(draft.preview)
                ? draft.preview
                : {};
            const preview = safeText(draft.localized_preview)
                || safeText(draft.human_preview)
                || safeText(structuredPreview.summary)
                || safeText(draft.preview)
                || safeText(draft.message);
            const warnings = Array.isArray(draft.warnings)
                ? draft.warnings.filter(item => typeof item === 'string' && item.trim() !== '').slice(0, 4)
                : [];
            const approveUrl = safeActionUrl(draft.approve_url || '');
            const executeUrl = safeActionUrl(draft.execute_url || '');
            const canControl = Boolean(approveUrl && executeUrl && typeof draft.payload_hash === 'string' && draft.payload_hash !== '');

            if (!preview && warnings.length === 0 && !canControl) return;

            const panel = document.createElement('section');
            panel.className = 'foodex-assistant-approval';
            panel.dataset.assistantApproval = String(draft.id || '');

            const heading = document.createElement('strong');
            heading.textContent = label('labelApprovalRequired');
            panel.appendChild(heading);

            if (preview) {
                const p = document.createElement('p');
                p.textContent = preview;
                panel.appendChild(p);
            }

            if (structuredPreview.target || structuredPreview.payload) {
                const exact = document.createElement('pre');
                exact.className = 'foodex-assistant-approval-payload';
                exact.textContent = JSON.stringify({
                    target: structuredPreview.target || {},
                    payload: structuredPreview.payload || {},
                }, null, 2);
                panel.appendChild(exact);
            }

            const sideEffects = structuredPreview.side_effects && typeof structuredPreview.side_effects === 'object'
                ? structuredPreview.side_effects[(locale || 'en').toLowerCase().startsWith('ar') ? 'ar' : 'en']
                : [];
            if (Array.isArray(sideEffects) && sideEffects.length > 0) {
                const list = document.createElement('ul');
                sideEffects.forEach(value => {
                    if (typeof value !== 'string' || value === '') return;
                    const item = document.createElement('li');
                    item.textContent = value;
                    list.appendChild(item);
                });
                if (list.childElementCount > 0) panel.appendChild(list);
            }

            warnings.forEach(value => {
                const p = document.createElement('p');
                p.textContent = value;
                panel.appendChild(p);
            });

            if (typeof draft.expires_at === 'string' && draft.expires_at) {
                const expiry = document.createElement('small');
                expiry.textContent = label('labelExpires') + ': ' + formatDateish(draft.expires_at);
                panel.appendChild(expiry);
            }

            if (canControl) {
                const controls = document.createElement('div');
                controls.className = 'foodex-assistant-actions';

                const approve = document.createElement('button');
                approve.type = 'button';
                approve.className = 'foodex-assistant-action';
                approve.textContent = locale.toLowerCase().startsWith('ar') ? 'موافقة' : 'Approve';

                const execute = document.createElement('button');
                execute.type = 'button';
                execute.className = 'foodex-assistant-action';
                execute.textContent = locale.toLowerCase().startsWith('ar') ? 'تنفيذ' : 'Execute';
                execute.disabled = String(draft.status || '') !== 'approved';

                const state = document.createElement('small');
                state.textContent = label('labelApprovalRequired');

                approve.addEventListener('click', async () => {
                    if (busy) return;
                    setBusy(true);
                    try {
                        const approved = await request(approveUrl, {
                            method: 'POST',
                            body: JSON.stringify({
                                confirmed: true,
                                payload_hash: draft.payload_hash,
                            }),
                        });
                        approve.disabled = true;
                        execute.disabled = false;
                        state.textContent = locale.toLowerCase().startsWith('ar') ? 'تم تسجيل الموافقة.' : 'Approval recorded.';
                        if (approved?.payload_hash) draft.payload_hash = approved.payload_hash;
                    } catch {
                        state.textContent = label('labelError');
                    } finally {
                        setBusy(false);
                    }
                });

                execute.addEventListener('click', async () => {
                    if (busy || execute.disabled) return;
                    setBusy(true);
                    try {
                        const executed = await request(executeUrl, {
                            method: 'POST',
                            body: JSON.stringify({}),
                        });
                        approve.disabled = true;
                        execute.disabled = true;
                        const result = executed?.result && typeof executed.result === 'object' ? executed.result : {};
                        state.textContent = locale.toLowerCase().startsWith('ar')
                            ? 'تم التنفيذ عبر FOODEX: ' + String(result.request_number || '')
                            : 'Executed by FOODEX: ' + String(result.request_number || '');
                    } catch {
                        state.textContent = label('labelError');
                    } finally {
                        setBusy(false);
                    }
                });

                controls.append(approve, execute);
                panel.append(controls, state);
            }

            container.appendChild(panel);
        };

        const agentEnvelope = payload => {
            if (!payload || typeof payload !== 'object') return {};
            if (payload.agent && typeof payload.agent === 'object') return payload.agent;
            if (payload.data && typeof payload.data === 'object' && payload.data._agent && typeof payload.data._agent === 'object') {
                return payload.data._agent;
            }
            return {};
        };

        const statusLabel = value => {
            const normalized = String(value || '').toUpperCase();
            if (normalized === 'COMPLETE') return [label('labelComplete'), 'success'];
            if (normalized === 'DEGRADED') return [label('labelDegraded'), 'warning'];
            if (normalized === 'DENIED') return [label('labelDenied'), 'danger'];
            if (normalized === 'NEEDS_CLARIFICATION') return [label('labelNeedsClarification'), ''];
            return ['', ''];
        };

        const freshnessLabel = value => {
            const normalized = String(value || '').toLowerCase();
            if (normalized === 'fresh' || normalized === 'current') return [label('labelFresh'), 'success'];
            if (normalized === 'stale') return [label('labelStale'), 'warning'];
            if (['partial', 'bounded', 'incomplete'].includes(normalized)) return [label('labelPartial'), 'warning'];
            if (normalized === 'complete') return [label('labelComplete'), 'success'];
            return ['', ''];
        };

        const findFreshness = (value, depth = 0) => {
            if (depth > 5 || !value || typeof value !== 'object') return null;
            if (!Array.isArray(value) && value.freshness && typeof value.freshness === 'object' && !Array.isArray(value.freshness)) {
                return value.freshness;
            }

            const children = Array.isArray(value)
                ? value.slice(0, 12)
                : Object.entries(value)
                    .filter(([key]) => key !== '_agent')
                    .slice(0, 30)
                    .map(([, child]) => child);

            for (const child of children) {
                const found = findFreshness(child, depth + 1);
                if (found) return found;
            }

            return null;
        };

        const renderMessageState = (container, payload) => {
            const state = document.createElement('div');
            state.className = 'foodex-assistant-message-state';

            const agent = agentEnvelope(payload);
            const [agentText, agentTone] = statusLabel(agent.status);
            const agentChip = createChip(agentText, agentTone);
            if (agentChip) state.appendChild(agentChip);

            const freshness = findFreshness(payload?.data || payload);
            const [freshText, freshTone] = freshnessLabel(freshness?.state || freshness?.status);
            const freshChip = createChip(freshText, freshTone);
            if (freshChip) state.appendChild(freshChip);

            if (state.childElementCount > 0) container.appendChild(state);
        };

        const evidenceSourceLabel = sourceType => {
            const normalized = String(sourceType || '').toUpperCase();
            if (normalized.includes('METRIC')) return label('labelEvidenceMetric');
            if (normalized.includes('DOMAIN') || normalized.includes('READ_MODEL')) return label('labelEvidenceDomain');
            return label('labelEvidenceAuthorized');
        };

        const renderEvidence = (container, payload) => {
            const agent = agentEnvelope(payload);
            const evidence = Array.isArray(agent.evidence) ? agent.evidence.slice(0, 12) : [];
            if (evidence.length === 0) return;

            const details = document.createElement('details');
            details.className = 'foodex-assistant-evidence';

            const summary = document.createElement('summary');
            const title = document.createElement('span');
            title.textContent = label('labelEvidence');
            const count = document.createElement('span');
            count.textContent = String(evidence.length);
            summary.append(title, count);
            details.appendChild(summary);

            const list = document.createElement('div');
            list.className = 'foodex-assistant-evidence-list';

            evidence.forEach(item => {
                if (!item || typeof item !== 'object') return;

                const row = document.createElement('div');
                row.className = 'foodex-assistant-evidence-row';

                const source = document.createElement('strong');
                source.textContent = evidenceSourceLabel(item.source_type);
                row.appendChild(source);

                const detailsText = [];
                if (typeof item.channel === 'string' && item.channel) detailsText.push(item.channel.toUpperCase());

                const freshness = item.freshness && typeof item.freshness === 'object' ? item.freshness : {};
                const [freshText] = freshnessLabel(freshness.state || freshness.status);
                if (freshText) detailsText.push(freshText);

                const windowData = item.window && typeof item.window === 'object' ? item.window : {};
                const from = formatDateish(windowData.from || windowData.start || '');
                const to = formatDateish(windowData.to || windowData.end || '');
                const asOf = formatDateish(windowData.as_of || '');
                if (from && to) detailsText.push(from + ' – ' + to);
                else if (asOf) detailsText.push(asOf);

                if (detailsText.length > 0) {
                    const meta = document.createElement('span');
                    meta.textContent = detailsText.join(' · ');
                    row.appendChild(meta);
                }

                list.appendChild(row);
            });

            if (list.childElementCount > 0) {
                details.appendChild(list);
                container.appendChild(details);
            }
        };

        const failureMessage = code => {
            const normalized = String(code || '').toUpperCase();
            if (normalized === 'SCOPE_DENIED') return label('labelScopeDenied');
            if (normalized === 'CONSENT_REQUIRED') return label('labelConsentRequired');
            if (normalized === 'TOOL_ARGUMENT_INVALID') return label('labelInvalidRequest');
            return label('labelDataUnavailable');
        };

        const renderFailures = (container, payload) => {
            const agent = agentEnvelope(payload);
            const executions = Array.isArray(agent.tool_executions) ? agent.tool_executions : [];
            const failures = [...new Set(executions
                .filter(item => item && item.status === 'FAILED')
                .map(item => failureMessage(item.failure_code))
                .filter(Boolean))];
            if (failures.length === 0) return;

            const section = document.createElement('section');
            section.className = 'foodex-assistant-section';

            failures.slice(0, 3).forEach(message => {
                const row = document.createElement('div');
                row.className = 'foodex-assistant-recommendation';
                row.textContent = message;
                section.appendChild(row);
            });

            container.appendChild(section);
        };

        const renderStructured = (container, payload) => {
            if (!payload || typeof payload !== 'object') return;

            renderMessageState(container, payload);
            const data = payload.data && typeof payload.data === 'object' ? payload.data : {};

            const insights = document.createElement('div');
            insights.className = 'foodex-assistant-insights';
            renderFindings(insights, data);
            renderRecommendations(insights, data);
            renderApproval(insights, data);
            renderFailures(insights, payload);
            if (insights.childElementCount > 0) container.appendChild(insights);

            renderEvidence(container, payload);
        };

        const renderMessage = (role, content, payload = {}) => {
            if (typeof content !== 'string' || content.trim() === '') return null;

            if (empty) empty.hidden = true;

            const article = document.createElement('article');
            article.className = 'foodex-assistant-message';
            article.dataset.assistantMessage = '1';
            article.dataset.role = role === 'user' ? 'user' : 'assistant';

            const meta = document.createElement('span');
            meta.className = 'foodex-assistant-message-meta';
            meta.textContent = role === 'user' ? label('labelYou') : label('labelAssistant');

            if (role !== 'user') {
                const source = String(payload?.response_source || '').toLowerCase();
                if (source === 'ai' || source === 'database') {
                    const sourceBadge = document.createElement('span');
                    sourceBadge.className = 'foodex-assistant-source-badge';
                    sourceBadge.dataset.source = source;
                    sourceBadge.textContent = source === 'ai'
                        ? label('labelResponseSourceAi')
                        : label('labelResponseSourceDatabase');
                    meta.append(' · ', sourceBadge);
                    article.dataset.responseSource = source;
                }
            }

            const bubble = document.createElement('div');
            bubble.className = 'foodex-assistant-bubble';
            bubble.textContent = content;

            article.append(meta, bubble);

            if (role !== 'user') {
                renderCards(article, payload.cards);
                renderStructured(article, payload);
                renderActions(article, payload.actions);
            }

            messages.appendChild(article);
            scrollToLatest();

            return article;
        };

        const renderTyping = () => {
            const article = document.createElement('article');
            article.className = 'foodex-assistant-message';
            article.dataset.assistantMessage = '1';
            article.dataset.assistantTyping = '1';
            article.dataset.role = 'assistant';

            const meta = document.createElement('span');
            meta.className = 'foodex-assistant-message-meta';
            meta.textContent = label('labelAssistant');

            const bubble = document.createElement('div');
            bubble.className = 'foodex-assistant-bubble';

            const typing = document.createElement('span');
            typing.className = 'foodex-assistant-typing';
            typing.setAttribute('aria-label', label('labelThinking'));
            typing.append(document.createElement('span'), document.createElement('span'), document.createElement('span'));

            bubble.appendChild(typing);
            article.append(meta, bubble);
            messages.appendChild(article);
            scrollToLatest();

            return article;
        };

        const renderRetry = (article, message) => {
            if (!article || !message) return;

            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'foodex-assistant-retry';
            button.dataset.assistantRetry = '1';
            button.textContent = label('labelRetryAction') || label('labelRetry');
            button.addEventListener('click', () => send(message));
            article.appendChild(button);
        };

        const renderPrompts = values => {
            if (!prompts || !Array.isArray(values) || values.length === 0) return;
            prompts.replaceChildren();

            values.slice(0, 6).forEach(value => {
                if (typeof value !== 'string' || value.trim() === '') return;

                const button = document.createElement('button');
                button.type = 'button';
                button.className = 'foodex-assistant-prompt';
                button.dataset.assistantPrompt = '1';
                button.textContent = value;
                prompts.appendChild(button);
            });
        };

        const pageContext = () => {
            const source = document.querySelector('[data-assistant-page-context]');
            let supplied = {};

            if (source?.dataset.assistantPageContext) {
                try {
                    const parsed = JSON.parse(source.dataset.assistantPageContext);
                    if (parsed && typeof parsed === 'object' && !Array.isArray(parsed)) supplied = parsed;
                } catch {
                    supplied = {};
                }
            }

            return {
                ...supplied,
                path: window.location.pathname + window.location.search,
            };
        };

        const sortedObject = value => {
            if (Array.isArray(value)) return value.map(sortedObject);
            if (!value || typeof value !== 'object') return value;

            return Object.keys(value).sort().reduce((accumulator, key) => {
                accumulator[key] = sortedObject(value[key]);
                return accumulator;
            }, {});
        };

        const currentContextSignature = () => JSON.stringify(sortedObject(pageContext()));

        const domainFromPath = path => {
            if (/^\/admin\/b2b(?:\/|$)/.test(path)) return 'wholesale';
            if (/^\/admin\/(?:b2c|retail-stores)(?:\/|$)/.test(path)) return 'retail';
            if (/^\/admin\/reports(?:\/|$)/.test(path)) return 'analytics';
            if (/^\/admin\/operations(?:\/|$)/.test(path)) return 'operations';
            if (/^\/admin\/catalog(?:\/|$)/.test(path)) return 'catalog';
            return 'management';
        };

        const domainLabel = domain => {
            const value = String(domain || '').toLowerCase();
            if (value === 'wholesale') return label('labelDomainWholesale');
            if (value === 'retail') return label('labelDomainRetail');
            if (value === 'analytics') return label('labelDomainAnalytics');
            if (value === 'operations') return label('labelDomainOperations');
            if (value === 'catalog') return label('labelDomainCatalog');
            return label('labelDomainManagement');
        };

        const pageTitle = () => {
            const title = document.querySelector('.foodex-page-toolbar h1, main h1');
            const text = safeText(title?.textContent);
            return text || label('labelCurrentPage');
        };

        const renderScope = context => {
            if (!scope || !scopePrimary || !scopeSecondary) return;

            const domain = context && typeof context === 'object' && typeof context.domain === 'string'
                ? context.domain
                : domainFromPath(window.location.pathname);
            const channel = context && typeof context === 'object' && typeof context.channel === 'string'
                ? context.channel.toUpperCase()
                : '';

            scopePrimary.textContent = domainLabel(domain) || label('labelCurrentPage');

            const parts = [pageTitle()];
            if (channel === 'B2B' || channel === 'B2C') parts.push(channel);
            scopeSecondary.textContent = parts.filter(Boolean).join(' · ') || label('labelScopePending');
        };

        const rememberServerContext = payload => {
            if (!payload || typeof payload !== 'object') return;

            if (typeof payload.context_token === 'string' && payload.context_token) {
                contextToken = payload.context_token;
                contextSignature = currentContextSignature();
            }

            if (payload.context && typeof payload.context === 'object' && !Array.isArray(payload.context)) {
                serverContext = payload.context;
                renderScope(serverContext);
            }
        };

        const requestContext = () => {
            const signature = currentContextSignature();
            if (contextToken && contextSignature === signature) return {context_token: contextToken};

            contextToken = '';
            contextSignature = '';
            serverContext = null;
            renderScope(null);

            return {context: pageContext()};
        };

        const request = async (url, options = {}) => {
            const headers = {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                ...(options.body ? {'Content-Type': 'application/json'} : {}),
                ...(csrf ? {'X-CSRF-TOKEN': csrf} : {}),
                ...(options.headers || {}),
            };

            let response;
            try {
                response = await fetch(url, {
                    credentials: 'same-origin',
                    ...options,
                    headers,
                });
            } catch {
                const error = new Error('Assistant network request failed');
                error.status = 0;
                throw error;
            }

            let payload = {};
            try {
                payload = await response.json();
            } catch {
                payload = {};
            }

            if (!response.ok) {
                const error = new Error('Assistant request failed');
                error.status = response.status;
                error.payload = payload;
                throw error;
            }

            return unwrap(payload);
        };

        const rememberConversation = payload => {
            const conversation = payload?.conversation;
            const candidate = conversation?.public_id
                || conversation?.id
                || payload?.conversation_id
                || payload?.conversationId;

            if (candidate !== undefined && candidate !== null && String(candidate) !== '') {
                conversationId = String(candidate);
                localStorage.setItem(conversationKey, conversationId);
            }

            rememberServerContext(payload);
        };

        const renderHistory = payload => {
            const history = Array.isArray(payload?.messages) ? payload.messages : [];
            if (history.length === 0) return;

            clearRenderedMessages();

            history.forEach(message => {
                if (!message || typeof message !== 'object') return;
                const role = message.role === 'user' ? 'user' : 'assistant';
                const text = message.content || message.message || message.text || '';
                renderMessage(role, String(text), message);
            });
        };

        const loadMessages = async () => {
            if (!conversationId) return;

            const payload = await request(baseUrl + '/conversations/' + encodeURIComponent(conversationId) + '/messages');
            renderHistory(payload);
            renderPrompts(payload.suggested_prompts || payload.suggestedPrompts || []);
        };

        const bootstrap = async () => {
            if (bootstrapped || busy) return;

            setBusy(true);
            setStatus(label('labelThinking'));

            try {
                const payload = await request(bootstrapUrl);
                rememberConversation(payload);
                renderHistory(payload);
                renderPrompts(payload.suggested_prompts || payload.suggestedPrompts || []);

                if (conversationId && (!Array.isArray(payload.messages) || payload.messages.length === 0)) {
                    await loadMessages();
                }

                bootstrapped = true;
                setStatus('');
            } catch {
                bootstrapped = false;
                setStatus(label('labelError'), 'error');
            } finally {
                setBusy(false);
            }
        };

        const ensureConversation = async () => {
            if (conversationId) return conversationId;

            const payload = await request(baseUrl + '/conversations', {
                method: 'POST',
                body: JSON.stringify({
                    locale,
                    context: pageContext(),
                }),
            });

            rememberConversation(payload);

            if (!conversationId) throw new Error('Assistant conversation identity missing');

            return conversationId;
        };

        const friendlyRequestError = error => {
            const statusCode = Number(error?.status || 0);
            if (statusCode === 403) return label('labelScopeDenied') || label('labelError');
            if (statusCode === 422) return label('labelInvalidRequest') || label('labelError');
            if (statusCode === 503 || statusCode === 404) return label('labelDataUnavailable') || label('labelError');
            if (statusCode === 0) return label('labelNetworkError') || label('labelError');
            return label('labelError');
        };

        const send = async message => {
            const text = String(message || '').trim();
            if (text === '' || busy) return;

            renderMessage('user', text);
            input.value = '';
            input.style.height = '';
            setBusy(true);
            setStatus(label('labelThinking'));
            const typing = renderTyping();

            try {
                const id = await ensureConversation();
                const scoped = requestContext();
                const payload = await request(baseUrl + '/conversations/' + encodeURIComponent(id) + '/messages', {
                    method: 'POST',
                    body: JSON.stringify({
                        message: text,
                        locale,
                        ...scoped,
                    }),
                });

                rememberConversation(payload);
                typing?.remove();

                const assistantMessage = payload.assistant_message && typeof payload.assistant_message === 'object'
                    ? payload.assistant_message
                    : payload;

                renderMessage(
                    'assistant',
                    String(assistantMessage.content || assistantMessage.message || payload.message || ''),
                    assistantMessage,
                );
                renderPrompts(
                    assistantMessage.suggested_prompts
                    || assistantMessage.suggestedPrompts
                    || payload.suggested_prompts
                    || payload.suggestedPrompts
                    || [],
                );
                setStatus('');
            } catch (error) {
                typing?.remove();
                const errorText = friendlyRequestError(error);
                const errorMessage = renderMessage('assistant', errorText);
                if (errorMessage) {
                    errorMessage.dataset.state = 'error';
                    renderRetry(errorMessage, text);
                }
                input.value = text;
                setStatus(errorText, 'error');
            } finally {
                setBusy(false);
                input.focus();
            }
        };

        const createConversation = async () => {
            if (busy) return;

            setBusy(true);
            setStatus(label('labelThinking'));

            try {
                const payload = await request(baseUrl + '/conversations', {
                    method: 'POST',
                    body: JSON.stringify({
                        locale,
                        context: pageContext(),
                    }),
                });

                conversationId = '';
                localStorage.removeItem(conversationKey);
                contextToken = '';
                contextSignature = '';
                rememberConversation(payload);
                clearRenderedMessages();
                renderPrompts(payload.suggested_prompts || payload.suggestedPrompts || []);
                setStatus('');
            } catch (error) {
                setStatus(friendlyRequestError(error), 'error');
            } finally {
                setBusy(false);
                input.focus();
            }
        };

        const clearConversation = async () => {
            if (busy) return;

            if (!conversationId) {
                clearRenderedMessages();
                setStatus('');
                return;
            }

            setBusy(true);
            setStatus(label('labelThinking'));

            try {
                const payload = await request(
                    baseUrl + '/conversations/' + encodeURIComponent(conversationId) + '/clear',
                    {method: 'POST', body: JSON.stringify({})},
                );

                clearRenderedMessages();
                renderPrompts(payload.suggested_prompts || payload.suggestedPrompts || []);
                setStatus('');
            } catch (error) {
                setStatus(friendlyRequestError(error), 'error');
            } finally {
                setBusy(false);
                input.focus();
            }
        };

        const contextPromptFor = anchor => {
            if (anchor === 'suggested-purchase-plan') return label('labelPurchasePlanPrompt');
            return label('labelContextPrompt');
        };

        const openFromContext = trigger => {
            setOpen(true);

            const prompt = contextPromptFor(trigger?.dataset?.assistantAnchor || '');
            if (prompt) {
                input.value = prompt;
                input.dispatchEvent(new Event('input', {bubbles: true}));
                input.focus();
            }
        };

        const isContextPage = () => /^\/admin\/(?:b2b|b2c|retail-stores|reports)(?:\/|$)/.test(window.location.pathname);

        const installContextEntry = () => {
            if (!isContextPage()) return;

            const actions = document.querySelector('.foodex-page-toolbar .foodex-header-actions');
            if (!actions || actions.querySelector('[data-assistant-context-open]')) return;

            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'foodex-action-secondary foodex-assistant-context-entry';
            button.dataset.assistantContextOpen = '1';
            button.textContent = label('labelContextOpen');
            actions.prepend(button);
        };

        opener.addEventListener('click', () => setOpen(drawer.hidden));
        closer?.addEventListener('click', () => setOpen(false));
        newButton?.addEventListener('click', createConversation);
        clearButton?.addEventListener('click', clearConversation);

        form.addEventListener('submit', event => {
            event.preventDefault();
            send(input.value);
        });

        input.addEventListener('keydown', event => {
            if (event.key === 'Enter' && !event.shiftKey) {
                event.preventDefault();
                form.requestSubmit();
            }
        });

        input.addEventListener('input', () => {
            input.style.height = 'auto';
            input.style.height = Math.min(input.scrollHeight, 130) + 'px';
        });

        prompts?.addEventListener('click', event => {
            const button = event.target.closest('[data-assistant-prompt]');
            if (!button || busy) return;

            input.value = button.textContent || '';
            form.requestSubmit();
        });

        document.addEventListener('click', event => {
            const trigger = event.target.closest('[data-dashboard-assistant-open],[data-assistant-context-open]');
            if (!trigger) return;

            event.preventDefault();
            openFromContext(trigger);
        });

        document.addEventListener('keydown', event => {
            if (event.key === 'Escape' && !drawer.hidden) {
                setOpen(false);
                return;
            }

            trapFocus(event);
        });

        window.addEventListener('resize', () => {
            if (drawer.hidden) return;

            const modal = matchMedia('(max-width:1023px)').matches;
            drawer.setAttribute('aria-modal', modal ? 'true' : 'false');
            document.body.classList.toggle('foodex-assistant-open-mobile', modal);
        });

        renderScope(null);
        installContextEntry();

        if (localStorage.getItem(openKey) === '1') setOpen(true);
    });
})();