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

        if (!drawer || !opener || !form || !input || !messages) return;

        const baseUrl = String(root.dataset.baseUrl || '').replace(/\/$/, '');
        const bootstrapUrl = root.dataset.bootstrapUrl || (baseUrl + '/bootstrap');
        const locale = root.dataset.locale || document.documentElement.lang || 'en';
        const userId = root.dataset.userId || 'unknown';
        const csrf = root.dataset.csrfToken || '';
        const conversationKey = 'foodex.assistant.conversation.' + userId;
        const openKey = 'foodex.assistant.open.' + userId;

        let conversationId = localStorage.getItem(conversationKey) || '';
        let busy = false;
        let bootstrapped = false;

        const label = key => root.dataset[key] || '';

        const unwrap = payload => {
            if (!payload || typeof payload !== 'object') return {};
            if (payload.data && typeof payload.data === 'object' && !Array.isArray(payload.data)) return payload.data;
            return payload;
        };

        const setStatus = (message = '', state = '') => {
            if (!status) return;
            status.textContent = message;
            if (state) status.dataset.state = state;
            else delete status.dataset.state;
        };

        const setBusy = value => {
            busy = Boolean(value);
            messages.setAttribute('aria-busy', busy ? 'true' : 'false');
            input.disabled = busy;
            if (sendButton) sendButton.disabled = busy;
        };

        const setOpen = value => {
            const open = Boolean(value);
            drawer.hidden = !open;
            drawer.setAttribute('aria-hidden', open ? 'false' : 'true');
            drawer.setAttribute('aria-modal', matchMedia('(max-width:1023px)').matches && open ? 'true' : 'false');
            opener.setAttribute('aria-expanded', open ? 'true' : 'false');
            localStorage.setItem(openKey, open ? '1' : '0');

            if (open) {
                if (!bootstrapped) bootstrap();
                window.setTimeout(() => input.focus(), 0);
            } else {
                opener.focus();
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

                return url.pathname + url.search + url.hash;
            } catch {
                return null;
            }
        };

        const renderCards = (container, cards) => {
            if (!Array.isArray(cards) || cards.length === 0) return;

            const list = document.createElement('div');
            list.className = 'foodex-assistant-cards';

            cards.slice(0, 8).forEach(card => {
                if (!card || typeof card !== 'object') return;

                const node = document.createElement('section');
                node.className = 'foodex-assistant-card';

                const title = card.title || card.label || card.name;
                if (typeof title === 'string' && title !== '') {
                    const heading = document.createElement('strong');
                    heading.className = 'foodex-assistant-card-title';
                    heading.textContent = title;
                    node.appendChild(heading);
                }

                const values = card.data && typeof card.data === 'object' ? card.data : card;

                Object.entries(values).slice(0, 8).forEach(([key, value]) => {
                    if (['title', 'label', 'name', 'data', 'actions'].includes(key)) return;
                    if (!['string', 'number', 'boolean'].includes(typeof value)) return;

                    const row = document.createElement('div');
                    row.className = 'foodex-assistant-card-row';

                    const rowLabel = document.createElement('span');
                    rowLabel.textContent = key.replaceAll('_', ' ');

                    const rowValue = document.createElement('strong');
                    rowValue.textContent = String(value);

                    row.append(rowLabel, rowValue);
                    node.appendChild(row);
                });

                list.appendChild(node);
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
                link.textContent = String(action.label || label('labelOpenAction') || 'Open');
                list.appendChild(link);
            });

            if (list.childElementCount > 0) container.appendChild(list);
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

            const bubble = document.createElement('div');
            bubble.className = 'foodex-assistant-bubble';
            bubble.textContent = content;

            article.append(meta, bubble);
            renderCards(article, payload.cards);
            renderActions(article, payload.actions);
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
                path: window.location.pathname,
            };
        };

        const request = async (url, options = {}) => {
            const headers = {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                ...(options.body ? {'Content-Type': 'application/json'} : {}),
                ...(csrf ? {'X-CSRF-TOKEN': csrf} : {}),
                ...(options.headers || {}),
            };

            const response = await fetch(url, {
                credentials: 'same-origin',
                ...options,
                headers,
            });

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

            if (candidate === undefined || candidate === null || String(candidate) === '') return;

            conversationId = String(candidate);
            localStorage.setItem(conversationKey, conversationId);
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

                if (
                    conversationId
                    && (!Array.isArray(payload.messages) || payload.messages.length === 0)
                ) {
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
                const payload = await request(baseUrl + '/conversations/' + encodeURIComponent(id) + '/messages', {
                    method: 'POST',
                    body: JSON.stringify({
                        message: text,
                        locale,
                        context: pageContext(),
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
            } catch {
                typing?.remove();
                const errorMessage = renderMessage('assistant', label('labelError'));
                if (errorMessage) errorMessage.dataset.state = 'error';
                input.value = text;
                setStatus(label('labelError'), 'error');
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
                rememberConversation(payload);
                clearRenderedMessages();
                renderPrompts(payload.suggested_prompts || payload.suggestedPrompts || []);
                setStatus('');
            } catch {
                setStatus(label('labelError'), 'error');
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
            } catch {
                setStatus(label('labelError'), 'error');
            } finally {
                setBusy(false);
                input.focus();
            }
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
            if (!button) return;

            input.value = button.textContent || '';
            form.requestSubmit();
        });

        document.addEventListener('keydown', event => {
            if (event.key === 'Escape' && !drawer.hidden) setOpen(false);
        });

        if (localStorage.getItem(openKey) === '1') setOpen(true);
    });
})();
