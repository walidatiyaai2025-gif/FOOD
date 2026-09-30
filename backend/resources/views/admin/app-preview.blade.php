<!doctype html>
<html lang="{{ str_replace('_','-',app()->getLocale()) }}" dir="{{ app()->getLocale()==='ar'?'rtl':'ltr' }}">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="csrf-token" content="{{ csrf_token() }}">
<title>{{ __('admin.preview_center.title') }} · FOODEX</title>
@include('admin._brand-components')
<style>
.preview-layout{display:grid;grid-template-columns:minmax(280px,360px) minmax(0,1fr);gap:var(--foodex-space-5);align-items:start}
.preview-controls{padding:var(--foodex-space-5);display:grid;gap:var(--foodex-space-4);position:sticky;top:18px}
.preview-controls label{display:grid;gap:7px;font-weight:700}.preview-controls select,.preview-controls button{width:100%}
.preview-target-state{font-size:.88rem;color:var(--foodex-muted);min-height:1.35em}
.preview-host-card{padding:var(--foodex-space-5);min-width:0}.preview-host-toolbar{display:flex;gap:10px;align-items:center;justify-content:space-between;flex-wrap:wrap;margin-bottom:14px}
.preview-runtime-shell{min-height:620px;border:1px solid var(--foodex-border);border-radius:20px;background:#f7f9fb;padding:18px;display:grid;place-items:center;overflow:auto}
.preview-device{width:min(100%,var(--preview-device-width,390px));min-height:560px;border:1px solid var(--foodex-border);border-radius:26px;background:#fff;overflow:hidden;box-shadow:0 16px 40px rgba(16,24,40,.08)}
.preview-device iframe{display:block;width:100%;height:720px;border:0;background:#fff}
.preview-unavailable{min-height:560px;padding:34px;display:grid;place-items:center;text-align:center}.preview-unavailable>div{max-width:560px}
.preview-unavailable strong{display:block;font-size:1.15rem;margin-bottom:8px}.preview-unavailable p{color:var(--foodex-muted);line-height:1.65}
.preview-meta{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px;margin-top:14px}.preview-meta div{padding:12px;border:1px solid var(--foodex-border);border-radius:12px;background:#fff}.preview-meta small{display:block;color:var(--foodex-muted);margin-bottom:4px}
.preview-security{padding:14px;border:1px solid var(--foodex-border);border-radius:12px;background:var(--foodex-green-soft);line-height:1.6}
@media(max-width:1050px){.preview-layout{grid-template-columns:1fr}.preview-controls{position:static}.preview-meta{grid-template-columns:1fr}}
</style>
</head>
<body>
<div class="foodex-admin-layout" data-foodex-utility="app-preview">
    <aside class="sidebar">@include('admin._sidebar')</aside>
    <main class="foodex-admin-main foodex-admin-page">
        <header class="foodex-page-header">
            <div>
                <span class="foodex-subtitle">FOODEX · Shared Flutter Runtime</span>
                <h1>{{ __('admin.preview_center.title') }}</h1>
                <p>{{ __('admin.preview_center.description') }}</p>
            </div>
            <div class="foodex-header-actions">@include('admin._live-notifications',['user'=>$user])</div>
        </header>

        <div class="preview-layout">
            <section class="foodex-card preview-controls" aria-label="{{ __('admin.preview_center.controls') }}">
                <label>{{ __('admin.preview_center.application') }}
                    <select id="preview-app" data-preview-control="app">
                        <option value="customer">{{ __('admin.preview_center.customer') }}</option>
                        <option value="driver" @disabled(!$canImpersonateDriver)>{{ __('admin.preview_center.driver') }}</option>
                    </select>
                </label>

                <label>{{ __('admin.preview_center.channel') }}
                    <select id="preview-channel" data-preview-control="channel">
                        @if($wholesaleAvailable)<option value="b2b">{{ __('admin.preview_center.wholesale') }}</option>@endif
                        @if($retailAvailable)<option value="b2c">{{ __('admin.preview_center.retail') }}</option>@endif
                    </select>
                </label>

                @if($retailAvailable)
                <label id="preview-store-wrap" @if($wholesaleAvailable) hidden @endif>{{ __('admin.preview_center.store') }}
                    <select id="preview-store" data-preview-control="store">
                        @foreach($retailStores as $store)
                            <option value="{{ $store->id }}">{{ $store->name }} · {{ $store->code }}</option>
                        @endforeach
                    </select>
                </label>
                @endif

                <label>{{ __('admin.preview_center.persona') }}
                    <select id="preview-persona" data-preview-control="persona">
                        <option value="guest" id="preview-persona-guest">{{ __('admin.preview_center.guest') }}</option>
                        @if($canImpersonateCustomer || $canImpersonateDriver)
                            <option value="authenticated" id="preview-persona-authenticated">{{ __('admin.preview_center.authenticated') }}</option>
                        @endif
                    </select>
                </label>

                <label id="preview-target-wrap" hidden>{{ __('admin.preview_center.target_identity') }}
                    <select id="preview-target" data-preview-control="target" disabled>
                        <option value="">{{ __('admin.preview_center.select_target') }}</option>
                    </select>
                    <span class="preview-target-state" id="preview-target-state" aria-live="polite"></span>
                </label>

                <label>{{ __('admin.preview_center.configuration') }}
                    <select id="preview-config" data-preview-control="configuration">
                        <option value="published">{{ __('admin.preview_center.published') }}</option>
                        <option value="draft">{{ __('admin.preview_center.draft') }}</option>
                    </select>
                </label>

                <label>{{ __('admin.preview_center.locale') }}
                    <select id="preview-locale" data-preview-control="locale">
                        <option value="ar">العربية · RTL</option>
                        <option value="en">English · LTR</option>
                    </select>
                </label>

                <label>{{ __('admin.preview_center.device') }}
                    <select id="preview-device" data-preview-control="device">
                        @foreach($deviceProfiles as $key=>$profile)
                            <option value="{{ $key }}" data-width="{{ (int)($profile['width']??390) }}">{{ $profile['label']??$key }} · {{ (int)($profile['width']??390) }}px</option>
                        @endforeach
                    </select>
                </label>

                <button type="button" class="foodex-button foodex-button-primary" id="preview-launch">
                    {{ __('admin.preview_center.launch') }}
                </button>

                <div class="preview-security" data-preview-safe-mode="read_only">
                    <strong>{{ __('admin.preview_center.safe_mode') }}</strong>
                    <div>{{ __('admin.preview_center.safe_mode_description') }}</div>
                    @if($supportAccessRequired)<div>{{ __('admin.preview_center.support_access') }}</div>@endif
                </div>
            </section>

            <section class="foodex-card preview-host-card">
                <div class="preview-host-toolbar">
                    <div>
                        <strong>{{ __('admin.preview_center.runtime_host') }}</strong>
                        <div class="foodex-subtitle">{{ __('admin.preview_center.runtime_invariant') }}</div>
                    </div>
                    <span class="badge" id="preview-runtime-status" aria-live="polite">{{ __('admin.preview_center.unavailable') }}</span>
                </div>

                <div class="preview-runtime-shell" data-preview-runtime-host data-preview-runtime-contract="shared-flutter-v1">
                    <div class="preview-device" id="preview-device-frame">
                        <div class="preview-unavailable" id="preview-unavailable">
                            <div>
                                <strong>{{ __('admin.preview_center.runtime_unavailable_title') }}</strong>
                                <p>{{ __('admin.preview_center.runtime_unavailable_description') }}</p>
                                <p><b>{{ __('admin.preview_center.no_clone') }}</b></p>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="preview-meta">
                    <div><small>{{ __('admin.preview_center.platform_version') }}</small><strong>{{ $platformVersion ?: '—' }}</strong></div>
                    <div><small>{{ __('admin.preview_center.customer_runtime') }}</small><strong data-runtime-meta="customer">{{ $runtimeConfig['customer']['contract_version'] ?: __('admin.preview_center.not_connected') }}</strong></div>
                    <div><small>{{ __('admin.preview_center.driver_runtime') }}</small><strong data-runtime-meta="driver">{{ $runtimeConfig['driver']['contract_version'] ?: __('admin.preview_center.not_connected') }}</strong></div>
                </div>
            </section>
        </div>
    </main>
</div>

<script>
(() => {
    'use strict';

    const runtimeConfig = @json($runtimeConfig);
    const bridgeConfig = {
        targetsUrl: @json(route('admin.app-preview.targets')),
        sessionsUrl: @json(route('admin.app-preview.sessions.store')),
        sessionsBaseUrl: @json(url('/admin/app-preview/sessions')),
        supportAccessRequired: @json($supportAccessRequired),
        canImpersonateCustomer: @json($canImpersonateCustomer),
        canImpersonateDriver: @json($canImpersonateDriver),
        wholesaleStoreId: @json($wholesaleStoreId),
    };
    const copy = {
        unavailable: @json(__('admin.preview_center.unavailable')),
        connecting: @json(__('admin.preview_center.connecting')),
        connected: @json(__('admin.preview_center.connected')),
        sessionError: @json(__('admin.preview_center.session_error')),
        originRejected: @json(__('admin.preview_center.runtime_origin_rejected')),
        selectTarget: @json(__('admin.preview_center.select_target')),
        selectIdentityFirst: @json(__('admin.preview_center.select_identity_first')),
        loadingTargets: @json(__('admin.preview_center.loading_targets')),
        noTargets: @json(__('admin.preview_center.no_targets')),
        relaunch: @json(__('admin.preview_center.relaunch')),
    };

    const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
    const app = document.getElementById('preview-app');
    const channel = document.getElementById('preview-channel');
    const store = document.getElementById('preview-store');
    const storeWrap = document.getElementById('preview-store-wrap');
    const persona = document.getElementById('preview-persona');
    const guestOption = document.getElementById('preview-persona-guest');
    const authenticatedOption = document.getElementById('preview-persona-authenticated');
    const targetWrap = document.getElementById('preview-target-wrap');
    const target = document.getElementById('preview-target');
    const targetState = document.getElementById('preview-target-state');
    const configuration = document.getElementById('preview-config');
    const locale = document.getElementById('preview-locale');
    const device = document.getElementById('preview-device');
    const frame = document.getElementById('preview-device-frame');
    const status = document.getElementById('preview-runtime-status');
    const launch = document.getElementById('preview-launch');
    const unavailableTemplate = document.getElementById('preview-unavailable')?.cloneNode(true);

    let activeSession = null;
    let runtimeFrame = null;
    let targetRequest = 0;

    const selectedStoreId = () => {
        if (channel?.value === 'b2b') return Number(bridgeConfig.wholesaleStoreId || 0) || null;
        const value = Number(store?.value || 0);
        return value > 0 ? value : null;
    };

    const activeRuntime = () => runtimeConfig?.[app?.value || 'customer'] || null;
    const requiresIdentity = () => app?.value === 'driver' || persona?.value === 'authenticated';
    const canImpersonateSelectedApp = () =>
        app?.value === 'driver' ? bridgeConfig.canImpersonateDriver : bridgeConfig.canImpersonateCustomer;

    const setStatus = (message) => {
        if (status) status.textContent = message;
    };

    const restoreUnavailable = () => {
        runtimeFrame = null;
        if (!frame) return;
        frame.replaceChildren();
        if (unavailableTemplate) frame.appendChild(unavailableTemplate.cloneNode(true));
        setStatus(copy.unavailable);
        if (launch) launch.textContent = @json(__('admin.preview_center.launch'));
    };

    const syncDevice = () => {
        const width = device?.selectedOptions?.[0]?.dataset?.width || '390';
        frame?.style.setProperty('--preview-device-width', width + 'px');
    };

    const syncChannel = () => {
        if (storeWrap) storeWrap.hidden = channel?.value !== 'b2c';
    };

    const syncPersona = () => {
        const driverMode = app?.value === 'driver';
        if (guestOption) guestOption.disabled = driverMode;

        if (authenticatedOption) {
            authenticatedOption.disabled = !canImpersonateSelectedApp();
        }

        if (driverMode && authenticatedOption && !authenticatedOption.disabled) {
            persona.value = 'authenticated';
        } else if (persona?.value === 'authenticated' && authenticatedOption?.disabled) {
            persona.value = 'guest';
        }

        if (targetWrap) targetWrap.hidden = !requiresIdentity();
    };

    const targetPlaceholder = (message) => {
        if (!target) return;
        target.replaceChildren();
        const option = document.createElement('option');
        option.value = '';
        option.textContent = message;
        target.appendChild(option);
    };

    const supportAccess = () =>
        Boolean(bridgeConfig.supportAccessRequired && channel?.value === 'b2c');

    const revokeActive = async (reason = 'context_changed', keepalive = false) => {
        const snapshot = activeSession;
        activeSession = null;
        if (!snapshot?.context?.session_id) return;

        try {
            await fetch(
                bridgeConfig.sessionsBaseUrl + '/' + encodeURIComponent(snapshot.context.session_id),
                {
                    method: 'DELETE',
                    credentials: 'same-origin',
                    keepalive,
                    headers: {
                        'Accept': 'application/json',
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': csrf,
                    },
                    body: JSON.stringify({reason}),
                },
            );
        } catch (_) {
            // Expiry/revocation remains server-authoritative even if page teardown
            // prevents the best-effort Dashboard revoke call from completing.
        }
    };

    const resetSecurityContext = () => {
        void revokeActive('context_changed');
        restoreUnavailable();
        syncChannel();
        syncPersona();
        void loadTargets();
    };

    const resetPresentation = () => {
        restoreUnavailable();
        syncDevice();
    };

    async function loadTargets() {
        const requestId = ++targetRequest;
        syncPersona();

        if (!requiresIdentity()) {
            if (target) target.disabled = true;
            targetPlaceholder(copy.selectTarget);
            if (targetState) targetState.textContent = '';
            return;
        }

        if (!canImpersonateSelectedApp()) {
            if (target) target.disabled = true;
            targetPlaceholder(copy.noTargets);
            if (targetState) targetState.textContent = copy.noTargets;
            return;
        }

        if (target) target.disabled = true;
        targetPlaceholder(copy.loadingTargets);
        if (targetState) targetState.textContent = copy.loadingTargets;

        const params = new URLSearchParams({
            target_type: app.value,
            channel: channel.value,
        });
        const storeId = selectedStoreId();
        if (channel.value === 'b2c' && storeId) params.set('store_id', String(storeId));
        if (supportAccess()) params.set('support_access', '1');

        try {
            const response = await fetch(bridgeConfig.targetsUrl + '?' + params.toString(), {
                credentials: 'same-origin',
                headers: {'Accept': 'application/json'},
            });
            if (!response.ok) throw new Error('target_discovery_failed');
            const payload = await response.json();
            if (requestId !== targetRequest) return;

            const rows = Array.isArray(payload?.data) ? payload.data : [];
            targetPlaceholder(copy.selectTarget);
            for (const row of rows) {
                const userId = Number(row?.user_id || 0);
                if (userId <= 0) continue;
                const option = document.createElement('option');
                option.value = String(userId);
                option.textContent = String(row?.name || ('#' + userId));
                target.appendChild(option);
            }
            target.disabled = rows.length === 0;
            if (targetState) targetState.textContent = rows.length === 0 ? copy.noTargets : '';
        } catch (_) {
            if (requestId !== targetRequest) return;
            targetPlaceholder(copy.noTargets);
            if (target) target.disabled = true;
            if (targetState) targetState.textContent = copy.sessionError;
        }
    }

    async function createAuthenticatedSession() {
        const targetUserId = Number(target?.value || 0);
        if (targetUserId <= 0) throw new Error('target_required');

        const payload = {
            target_user_id: targetUserId,
            target_type: app.value,
            channel: channel.value,
            support_access: supportAccess(),
        };
        const storeId = selectedStoreId();
        if (channel.value === 'b2c' && storeId) payload.store_id = storeId;

        const response = await fetch(bridgeConfig.sessionsUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Accept': 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrf,
            },
            body: JSON.stringify(payload),
        });
        if (!response.ok) throw new Error('session_create_failed');

        const result = await response.json();
        if (!result?.data || typeof result?.credential !== 'string' || result.credential === '') {
            throw new Error('session_contract_invalid');
        }

        return {context: result.data, credential: result.credential};
    }

    function guestSession() {
        const storeId = selectedStoreId();
        if (!storeId) throw new Error('store_required');

        return {
            credential: null,
            context: {
                session_id: null,
                target_type: 'customer',
                channel: channel.value,
                store_id: storeId,
                mode: 'read_only',
                read_only: true,
                support_access: false,
                target: null,
            },
        };
    }

    function mountRuntime() {
        const runtime = activeRuntime();
        if (!runtime?.available || !runtime?.url || !runtime?.origin || !runtime?.contract_version) {
            restoreUnavailable();
            return;
        }

        let parsed;
        try {
            parsed = new URL(runtime.url);
        } catch (_) {
            setStatus(copy.originRejected);
            return;
        }
        if (parsed.origin !== runtime.origin) {
            setStatus(copy.originRejected);
            return;
        }

        const iframe = document.createElement('iframe');
        iframe.src = runtime.url;
        iframe.title = app.value === 'driver'
            ? @json(__('admin.preview_center.driver'))
            : @json(__('admin.preview_center.customer'));
        iframe.referrerPolicy = 'no-referrer';
        iframe.setAttribute('sandbox', 'allow-scripts allow-same-origin');
        iframe.setAttribute('data-preview-shared-runtime', app.value);
        frame.replaceChildren(iframe);
        runtimeFrame = iframe;
        setStatus(copy.connecting);
        if (launch) launch.textContent = copy.relaunch;
    }

    async function launchPreview() {
        const runtime = activeRuntime();
        if (!runtime?.available) {
            restoreUnavailable();
            return;
        }

        await revokeActive('relaunch');
        activeSession = null;

        try {
            if (requiresIdentity()) {
                if (!target?.value) throw new Error('target_required');
                activeSession = await createAuthenticatedSession();
            } else {
                if (app.value !== 'customer') throw new Error('target_required');
                activeSession = guestSession();
            }
            mountRuntime();
        } catch (error) {
            activeSession = null;
            restoreUnavailable();
            setStatus(error?.message === 'target_required' ? copy.selectIdentityFirst : copy.sessionError);
        }
    }

    window.addEventListener('message', (event) => {
        const runtime = activeRuntime();
        if (!runtimeFrame || !activeSession || !runtime) return;
        if (event.source !== runtimeFrame.contentWindow || event.origin !== runtime.origin) return;

        const message = event.data;
        if (!message || typeof message !== 'object') return;

        if (message.type === 'foodex.preview.ready') {
            if (message.version !== runtime.contract_version) {
                setStatus(copy.sessionError);
                return;
            }

            runtimeFrame.contentWindow?.postMessage({
                type: 'foodex.preview.bootstrap',
                version: runtime.contract_version,
                payload: {
                    context: activeSession.context,
                    credential: activeSession.credential,
                    configuration: configuration?.value || 'published',
                    locale: locale?.value || 'ar',
                    device: {
                        profile: device?.value || 'phone_standard',
                        width: Number(device?.selectedOptions?.[0]?.dataset?.width || 390),
                    },
                    safe_mode: 'read_only',
                },
            }, runtime.origin);
            setStatus(copy.connected);
            return;
        }

        if (message.type === 'foodex.preview.status') {
            const state = String(message.state || '');
            if (state === 'ready') setStatus(copy.connected);
            if (state === 'expired' || state === 'forbidden' || state === 'error') {
                setStatus(copy.sessionError);
            }
        }
    });

    app?.addEventListener('change', resetSecurityContext);
    channel?.addEventListener('change', resetSecurityContext);
    store?.addEventListener('change', resetSecurityContext);
    persona?.addEventListener('change', resetSecurityContext);
    target?.addEventListener('change', () => {
        void revokeActive('target_changed');
        restoreUnavailable();
    });
    configuration?.addEventListener('change', resetPresentation);
    locale?.addEventListener('change', resetPresentation);
    device?.addEventListener('change', resetPresentation);
    launch?.addEventListener('click', () => void launchPreview());

    window.addEventListener('pagehide', () => {
        void revokeActive('page_exit', true);
    });

    syncChannel();
    syncPersona();
    syncDevice();
    void loadTargets();
})();
</script>
</body>
</html>
