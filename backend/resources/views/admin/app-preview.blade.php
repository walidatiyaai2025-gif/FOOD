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
.preview-device{width:min(100%,var(--preview-device-width,390px));height:var(--preview-device-height,844px);max-height:80vh;min-height:0;border:1px solid var(--foodex-border);border-radius:26px;background:#fff;overflow:hidden;box-shadow:0 16px 40px rgba(16,24,40,.08)}
.preview-device iframe{display:block;width:100%;height:100%;border:0;background:#fff}
.preview-unavailable{min-height:560px;padding:34px;display:grid;place-items:center;text-align:center}.preview-unavailable>div{max-width:560px}
.preview-unavailable strong{display:block;font-size:1.15rem;margin-bottom:8px}.preview-unavailable p{color:var(--foodex-muted);line-height:1.65}
.preview-meta{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px;margin-top:14px}.preview-meta div{padding:12px;border:1px solid var(--foodex-border);border-radius:12px;background:#fff}.preview-meta small{display:block;color:var(--foodex-muted);margin-bottom:4px}
.preview-security{padding:14px;border:1px solid var(--foodex-border);border-radius:12px;background:var(--foodex-green-soft);line-height:1.6}
.preview-inspector{margin-top:16px;padding:16px;border:1px solid var(--foodex-border);border-radius:16px;background:#fff}
.preview-inspector-head{display:flex;gap:12px;align-items:center;justify-content:space-between;flex-wrap:wrap;margin-bottom:12px}
.preview-inspector-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px}
.preview-inspector-item{padding:10px 12px;border:1px solid var(--foodex-border);border-radius:12px;background:#f8fafc;min-width:0}
.preview-inspector-item small{display:block;color:var(--foodex-muted);margin-bottom:4px}
.preview-inspector-item strong{display:block;overflow-wrap:anywhere}
.preview-inspector-empty{color:var(--foodex-muted);font-size:.9rem}
.preview-export{width:auto!important}
@media(max-width:1050px){.preview-layout{grid-template-columns:1fr}.preview-controls{position:static}.preview-meta,.preview-inspector-grid{grid-template-columns:1fr}}
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

        <div class="preview-layout" data-preview-entry="auto" data-preview-default-app="customer" data-preview-default-configuration="published">
            <section class="foodex-card preview-controls" aria-label="{{ __('admin.preview_center.controls') }}">
                <label>{{ __('admin.preview_center.application') }}
                    <select id="preview-app" data-preview-control="app">
                        <option value="customer" @selected(request()->query('app', 'customer') === 'customer')>{{ __('admin.preview_center.customer') }}</option>
                        <option value="driver" @selected(request()->query('app') === 'driver') @disabled(!$canImpersonateDriver)>{{ __('admin.preview_center.driver') }}</option>
                        <option value="van" @selected(request()->query('app') === 'van')>{{ __('admin.preview_center.van') }}</option>
                    </select>
                </label>

                <label>{{ __('admin.preview_center.channel') }}
                    <select id="preview-channel" data-preview-control="channel">
                        @if($wholesaleAvailable)<option value="b2b" @selected(request()->query('channel') === 'b2b')>{{ __('admin.preview_center.wholesale') }}</option>@endif
                        @if($retailAvailable)<option value="b2c" @selected(request()->query('channel') === 'b2c')>{{ __('admin.preview_center.retail') }}</option>@endif
                    </select>
                </label>

                @if($retailAvailable)
                <label id="preview-store-wrap" @if($wholesaleAvailable) hidden @endif>{{ __('admin.preview_center.store') }}
                    <select id="preview-store" data-preview-control="store">
                        @foreach($retailStores as $store)
                            <option value="{{ $store->id }}" @selected((int) request()->query('store_id', 0) === (int) $store->id)>{{ $store->name }} · {{ $store->code }}</option>
                        @endforeach
                    </select>
                </label>
                @endif

                <label>{{ __('admin.preview_center.persona') }}
                    <select id="preview-persona" data-preview-control="persona">
                        <option value="guest" id="preview-persona-guest" @selected(request()->query('persona', 'guest') === 'guest')>{{ __('admin.preview_center.guest') }}</option>
                        @if($canImpersonateCustomer || $canImpersonateDriver)
                            <option value="authenticated" id="preview-persona-authenticated" @selected(request()->query('persona') === 'authenticated')>{{ __('admin.preview_center.authenticated') }}</option>
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
                        <option value="published" @selected(request()->query('mode', request()->query('configuration', 'published')) === 'published')>{{ __('admin.preview_center.published') }}</option>
                        <option value="draft" @selected(request()->query('mode', request()->query('configuration', 'published')) === 'draft')>{{ __('admin.preview_center.draft') }}</option>
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
                            <option value="{{ $key }}"
                                data-width="{{ (int)($profile['width']??390) }}"
                                data-height="{{ (int)($profile['height']??844) }}"
                                data-safe-top="{{ (int)($profile['safe_area']['top']??0) }}"
                                data-safe-right="{{ (int)($profile['safe_area']['right']??0) }}"
                                data-safe-bottom="{{ (int)($profile['safe_area']['bottom']??0) }}"
                                data-safe-left="{{ (int)($profile['safe_area']['left']??0) }}"
                                data-orientation="{{ $profile['orientation']??'portrait' }}"
                                data-text-scale="{{ (float)($profile['text_scale']??1) }}"
                                data-view-inset-bottom="{{ (int)($profile['view_insets']['bottom']??0) }}"
                            >{{ $profile['label']??$key }} · {{ (int)($profile['width']??390) }}×{{ (int)($profile['height']??844) }}</option>
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
                    <div><small>{{ __('admin.preview_center.van_runtime') }}</small><strong data-runtime-meta="van">{{ $runtimeConfig['van']['contract_version'] ?: __('admin.preview_center.not_connected') }}</strong></div>
                </div>

                <section class="preview-inspector" id="preview-inspector" aria-label="{{ __('admin.preview_center.inspector') }}">
                    <div class="preview-inspector-head">
                        <div>
                            <strong>{{ __('admin.preview_center.inspector') }}</strong>
                            <div class="foodex-subtitle">{{ __('admin.preview_center.inspector_description') }}</div>
                        </div>
                        <button type="button" class="foodex-button preview-export" id="preview-inspector-export">{{ __('admin.preview_center.export_diagnostic') }}</button>
                    </div>
                    <div class="preview-inspector-grid" id="preview-inspector-grid">
                        @foreach([
                            'app' => 'inspector_app',
                            'route' => 'inspector_route',
                            'channel' => 'inspector_channel',
                            'store_id' => 'inspector_store',
                            'auth_mode' => 'inspector_auth',
                            'locale' => 'inspector_locale',
                            'device' => 'inspector_device',
                            'configuration' => 'inspector_configuration',
                            'revision' => 'inspector_revision',
                            'runtime_version' => 'inspector_runtime_version',
                            'state' => 'inspector_state',
                            'error_code' => 'inspector_error',
                            'api' => 'inspector_api',
                            'updated_at' => 'inspector_updated',
                            'component_key' => 'inspector_component',
                            'capabilities' => 'inspector_capabilities',
                        ] as $key => $label)
                            <div class="preview-inspector-item">
                                <small>{{ __('admin.preview_center.'.$label) }}</small>
                                <strong data-preview-inspector="{{ $key }}">—</strong>
                            </div>
                        @endforeach
                    </div>
                    <p class="preview-inspector-empty" id="preview-inspector-note">{{ __('admin.preview_center.inspector_empty') }}</p>
                </section>
            </section>
        </div>
    </main>
</div>

<script id="foodex-preview-bridge">
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
        wholesaleDriversUrl: @json(route('admin.b2b.module', ['module' => 'drivers'])),
        retailDriversUrl: @json(route('admin.b2c.module', ['module' => 'drivers'])),
        canManageWholesaleDrivers: @json($canManageWholesaleDrivers),
        manageableRetailDriverStoreIds: @json($manageableRetailDriverStoreIds),
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
        driverRequiredStatus: @json(__('admin.preview_center.driver_required_status')),
        driverRequiredTitle: @json(__('admin.preview_center.driver_required_title')),
        driverRequiredDescription: @json(__('admin.preview_center.driver_required_description')),
        manageDrivers: @json(__('admin.preview_center.manage_drivers')),
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
    const inspectorExport = document.getElementById('preview-inspector-export');
    const inspectorNote = document.getElementById('preview-inspector-note');
    const inspectorFields = Object.fromEntries(
        Array.from(document.querySelectorAll('[data-preview-inspector]')).map((node) => [node.dataset.previewInspector, node]),
    );

    let inspectorRuntime = Object.freeze({});
    let activeSession = null;
    let runtimeFrame = null;
    let targetRequest = 0;
    let contextRequest = 0;
    let launchRequest = 0;

    const selectedStoreId = () => {
        if (channel?.value === 'b2b') return Number(bridgeConfig.wholesaleStoreId || 0) || null;
        const value = Number(store?.value || 0);
        return value > 0 ? value : null;
    };

    const activeRuntime = () => runtimeConfig?.[app?.value || 'customer'] || null;
    const requiresIdentity = () => app?.value !== 'van' && (app?.value === 'driver' || persona?.value === 'authenticated');
    const canImpersonateSelectedApp = () =>
        app?.value === 'van'
            ? false
            : (app?.value === 'driver' ? bridgeConfig.canImpersonateDriver : bridgeConfig.canImpersonateCustomer);

    const setStatus = (message) => {
        if (status) status.textContent = message;
    };

    const diagnosticSchema = 'foodex.preview.diagnostic.v1';
    const safeText = (value, max = 160) => {
        if (typeof value !== 'string') return null;
        const text = value.trim();
        if (!text || text.length > max) return null;
        return text;
    };
    const safeInteger = (value, min = 0, max = Number.MAX_SAFE_INTEGER) => {
        const parsed = Number(value);
        return Number.isInteger(parsed) && parsed >= min && parsed <= max ? parsed : null;
    };
    const safeIso = (value) => {
        const text = safeText(value, 64);
        if (!text) return null;
        const parsed = new Date(text);
        return Number.isNaN(parsed.getTime()) ? null : parsed.toISOString();
    };
    const safeEndpoint = (value) => {
        const text = safeText(value, 500);
        if (!text) return null;
        try {
            return new URL(text, window.location.origin).pathname;
        } catch (_) {
            return null;
        }
    };
    const safeCapabilities = (value) => {
        if (!value || typeof value !== 'object' || Array.isArray(value)) return null;
        const output = {};
        for (const key of Object.keys(value).sort().slice(0, 50)) {
            if (!/^[a-z0-9_.-]{1,64}$/i.test(key) || typeof value[key] !== 'boolean') continue;
            output[key] = value[key];
        }
        return Object.keys(output).length ? output : null;
    };
    const firstSafeText = (...values) => {
        for (const value of values) {
            const text = safeText(value);
            if (text) return text;
        }
        return null;
    };
    const sanitizeInspectorStatus = (message) => {
        const source = message?.inspector && typeof message.inspector === 'object'
            ? message.inspector
            : (message?.metadata && typeof message.metadata === 'object'
                ? message.metadata
                : message);
        const sanitized = {};
        const textFields = {
            state: [message?.state, source?.state],
            route: [source?.route, source?.screen],
            screen: [source?.screen],
            auth_mode: [source?.auth_mode],
            runtime_version: [source?.runtime_version, source?.app_version],
            app_version: [source?.app_version],
            config_version: [source?.config_version],
            revision_id: [source?.revision_id],
            revision_checksum: [source?.revision_checksum, source?.checksum],
            schema_version: [source?.schema_version],
            error_code: [source?.error_code, source?.code],
            component_key: [source?.component_key],
            entity_id: [source?.entity_id],
            cta_target: [source?.cta_target],
        };
        for (const [key, values] of Object.entries(textFields)) {
            const value = firstSafeText(...values);
            if (value) sanitized[key] = value;
        }
        const apiEndpoint = safeEndpoint(source?.last_api_endpoint ?? source?.api_endpoint);
        if (apiEndpoint) sanitized.last_api_endpoint = apiEndpoint;
        const apiStatus = safeInteger(source?.last_api_status ?? source?.api_status, 100, 599);
        if (apiStatus !== null) sanitized.last_api_status = apiStatus;
        for (const key of ['loaded_at', 'updated_at']) {
            const value = safeIso(source?.[key]);
            if (value) sanitized[key] = value;
        }
        const runtimeChannel = source?.channel === 'b2c'
            ? 'b2c'
            : (source?.channel === 'b2b' ? 'b2b' : null);
        if (runtimeChannel) sanitized.channel = runtimeChannel;
        const runtimeStoreId = safeInteger(source?.store_id, 1);
        if (runtimeStoreId !== null) sanitized.store_id = runtimeStoreId;
        const capabilities = safeCapabilities(source?.capability_flags ?? source?.capabilities);
        if (capabilities) sanitized.capability_flags = capabilities;
        return Object.freeze(sanitized);
    };

    const inspectorContext = () => {
        const runtime = activeRuntime();
        const option = device?.selectedOptions?.[0];
        const width = safeInteger(option?.dataset?.width, 240, 2000);
        const height = safeInteger(option?.dataset?.height, 320, 2400);
        const textScale = Number(option?.dataset?.textScale || 1);
        const storeId = selectedStoreId();
        return {
            app: ['customer', 'driver', 'van'].includes(app?.value) ? app.value : 'customer',
            channel: channel?.value === 'b2c' ? 'b2c' : 'b2b',
            store_id: storeId,
            auth_mode: app?.value === 'van'
                ? 'preview-van-readonly'
                : (app?.value === 'driver'
                    ? 'preview-driver'
                    : (persona?.value === 'authenticated' ? 'preview-customer' : 'guest')),
            locale: locale?.value === 'en' ? 'en' : 'ar',
            device_profile: safeText(device?.value, 64),
            device_width: width,
            device_height: height,
            text_scale: Number.isFinite(textScale) ? textScale : 1,
            configuration: configuration?.value === 'draft' ? 'draft' : 'published',
            runtime_contract: safeText(runtime?.contract_version, 64),
        };
    };

    const buildDiagnostic = () => {
        const context = inspectorContext();
        const runtime = inspectorRuntime;
        return {
            schema: diagnosticSchema,
            context: {
                app: context.app,
                channel: context.channel,
                store_id: context.store_id,
                auth_mode: context.auth_mode,
                locale: context.locale,
                device_profile: context.device_profile,
                device_width: context.device_width,
                device_height: context.device_height,
                text_scale: context.text_scale,
                configuration: context.configuration,
                runtime_contract: context.runtime_contract,
            },
            runtime: {
                state: runtime.state ?? 'unavailable',
                route: runtime.route ?? null,
                screen: runtime.screen ?? null,
                auth_mode: runtime.auth_mode ?? null,
                channel: runtime.channel ?? null,
                store_id: runtime.store_id ?? null,
                runtime_version: runtime.runtime_version ?? null,
                app_version: runtime.app_version ?? null,
                config_version: runtime.config_version ?? null,
                revision_id: runtime.revision_id ?? null,
                revision_checksum: runtime.revision_checksum ?? null,
                schema_version: runtime.schema_version ?? null,
                error_code: runtime.error_code ?? null,
                last_api_endpoint: runtime.last_api_endpoint ?? null,
                last_api_status: runtime.last_api_status ?? null,
                loaded_at: runtime.loaded_at ?? null,
                updated_at: runtime.updated_at ?? null,
                component_key: runtime.component_key ?? null,
                entity_id: runtime.entity_id ?? null,
                cta_target: runtime.cta_target ?? null,
                capability_flags: runtime.capability_flags ?? null,
            },
        };
    };

    const displayValue = (value) => {
        if (value === null || value === undefined || value === '') return '—';
        if (typeof value === 'object') return Object.entries(value).map(([key, enabled]) => key + ':' + (enabled ? 'on' : 'off')).join(', ') || '—';
        return String(value);
    };

    const renderInspector = () => {
        const diagnostic = buildDiagnostic();
        const runtime = diagnostic.runtime;
        const context = diagnostic.context;
        const revision = [runtime.revision_id, runtime.revision_checksum].filter(Boolean).join(' · ');
        const api = [runtime.last_api_endpoint, runtime.last_api_status].filter((value) => value !== null && value !== undefined).join(' · ');
        const values = {
            app: context.app,
            route: runtime.route ?? runtime.screen,
            channel: runtime.channel ?? context.channel,
            store_id: runtime.store_id ?? context.store_id,
            auth_mode: runtime.auth_mode ?? context.auth_mode,
            locale: context.locale,
            device: [
                context.device_profile,
                context.device_width && context.device_height ? context.device_width + '×' + context.device_height : null,
                context.text_scale ? 'text×' + context.text_scale : null,
            ].filter(Boolean).join(' · '),
            configuration: context.configuration,
            revision,
            runtime_version: runtime.runtime_version ?? runtime.app_version ?? runtime.config_version,
            state: runtime.state,
            error_code: runtime.error_code,
            api,
            updated_at: runtime.updated_at ?? runtime.loaded_at,
            component_key: runtime.component_key,
            capabilities: runtime.capability_flags,
        };
        for (const [key, node] of Object.entries(inspectorFields)) {
            node.textContent = displayValue(values[key]);
        }
        if (inspectorNote) inspectorNote.hidden = Object.keys(inspectorRuntime).length > 0;
    };

    const clearInspector = (state = 'unavailable') => {
        inspectorRuntime = Object.freeze(state ? {state} : {});
        renderInspector();
    };

    const updateInspectorFromStatus = (message) => {
        inspectorRuntime = sanitizeInspectorStatus(message);
        renderInspector();
    };

    const exportDiagnostic = () => {
        const payload = JSON.stringify(buildDiagnostic(), null, 2);
        const blob = new Blob([payload], {type: 'application/json;charset=utf-8'});
        const href = URL.createObjectURL(blob);
        const anchor = document.createElement('a');
        anchor.href = href;
        anchor.download = 'foodex-preview-diagnostic.json';
        document.body.appendChild(anchor);
        anchor.click();
        anchor.remove();
        URL.revokeObjectURL(href);
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
        const option = device?.selectedOptions?.[0];
        const width = option?.dataset?.width || '390';
        const height = option?.dataset?.height || '844';
        frame?.style.setProperty('--preview-device-width', width + 'px');
        frame?.style.setProperty('--preview-device-height', height + 'px');
    };

    const syncChannel = () => {
        if (storeWrap) storeWrap.hidden = channel?.value !== 'b2c';
    };

    const syncPersona = () => {
        const driverMode = app?.value === 'driver';
        const vanMode = app?.value === 'van';
        if (guestOption) guestOption.disabled = driverMode;
        if (persona) persona.disabled = vanMode;

        if (authenticatedOption) {
            authenticatedOption.disabled = !canImpersonateSelectedApp();
        }

        if (driverMode && authenticatedOption && !authenticatedOption.disabled) {
            persona.value = 'authenticated';
        } else if (persona?.value === 'authenticated' && authenticatedOption?.disabled) {
            persona.value = 'guest';
        }

        if (vanMode && persona) persona.value = 'guest';
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

    const driverManagementUrl = () => {
        const storeId = selectedStoreId();
        if (channel?.value === 'b2b') {
            return bridgeConfig.canManageWholesaleDrivers ? bridgeConfig.wholesaleDriversUrl : null;
        }

        const allowedStoreIds = Array.isArray(bridgeConfig.manageableRetailDriverStoreIds)
            ? bridgeConfig.manageableRetailDriverStoreIds.map(Number)
            : [];
        if (!storeId || !allowedStoreIds.includes(storeId)) return null;

        try {
            const url = new URL(bridgeConfig.retailDriversUrl, window.location.origin);
            url.searchParams.set('store_id', String(storeId));
            if (supportAccess()) url.searchParams.set('support_access', '1');
            return url.toString();
        } catch (_) {
            return null;
        }
    };

    const restoreDriverRequired = () => {
        runtimeFrame = null;
        activeSession = null;
        if (!frame) return;

        const shell = document.createElement('div');
        shell.className = 'preview-unavailable';

        const body = document.createElement('div');
        const title = document.createElement('strong');
        title.textContent = copy.driverRequiredTitle;
        const description = document.createElement('p');
        description.textContent = copy.driverRequiredDescription;
        body.append(title, description);

        const manageUrl = driverManagementUrl();
        if (manageUrl) {
            const action = document.createElement('a');
            action.className = 'foodex-button foodex-button-primary';
            action.href = manageUrl;
            action.textContent = copy.manageDrivers;
            body.appendChild(action);
        }

        shell.appendChild(body);
        frame.replaceChildren(shell);
        clearInspector('driver_required');
        setStatus(copy.driverRequiredStatus);
        if (launch) launch.textContent = @json(__('admin.preview_center.launch'));
    };

    const revokeSession = async (snapshot, reason = 'context_changed', keepalive = false) => {
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

    const revokeActive = async (reason = 'context_changed', keepalive = false) => {
        const snapshot = activeSession;
        activeSession = null;
        await revokeSession(snapshot, reason, keepalive);
    };

    const resetSecurityContext = async () => {
        const requestId = ++contextRequest;
        ++launchRequest;
        await revokeActive('context_changed');
        if (requestId !== contextRequest) return;

        clearInspector('unavailable');
        restoreUnavailable();
        syncChannel();
        syncPersona();

        const discovery = await loadTargets({
            selectFirstDriver: app?.value === 'driver',
        });
        if (requestId !== contextRequest || discovery?.status === 'stale') return;

        if (app?.value === 'driver') {
            if (discovery?.status === 'ok' && discovery.rows.length === 0) {
                restoreDriverRequired();
                return;
            }
            if (discovery?.status === 'ok' && target?.value) {
                await launchPreview();
            }
            return;
        }

        if (persona?.value === 'guest') {
            await launchPreview();
        }
    };

    const resetPresentation = () => {
        ++launchRequest;
        clearInspector('unavailable');
        restoreUnavailable();
        syncDevice();
        renderInspector();
    };

    async function loadTargets({selectFirstDriver = false} = {}) {
        const requestId = ++targetRequest;
        syncPersona();

        if (!requiresIdentity()) {
            if (target) target.disabled = true;
            targetPlaceholder(copy.selectTarget);
            if (targetState) targetState.textContent = '';
            return {status: 'not_required', rows: []};
        }

        if (!canImpersonateSelectedApp()) {
            if (target) target.disabled = true;
            targetPlaceholder(copy.noTargets);
            if (targetState) targetState.textContent = copy.noTargets;
            return {status: 'forbidden', rows: []};
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
            if (requestId !== targetRequest) return {status: 'stale', rows: []};

            const rows = Array.isArray(payload?.data) ? payload.data : [];
            const eligibleRows = [];
            targetPlaceholder(copy.selectTarget);
            for (const row of rows) {
                const userId = Number(row?.user_id || 0);
                if (userId <= 0) continue;
                const option = document.createElement('option');
                option.value = String(userId);
                option.textContent = String(row?.name || ('#' + userId));
                target.appendChild(option);
                eligibleRows.push(row);
            }

            if (selectFirstDriver && app?.value === 'driver' && eligibleRows.length > 0) {
                target.value = String(Number(eligibleRows[0].user_id));
            }

            target.disabled = eligibleRows.length === 0;
            if (targetState) targetState.textContent = eligibleRows.length === 0 ? copy.noTargets : '';
            return {status: 'ok', rows: eligibleRows};
        } catch (_) {
            if (requestId !== targetRequest) return {status: 'stale', rows: []};
            targetPlaceholder(copy.noTargets);
            if (target) target.disabled = true;
            if (targetState) targetState.textContent = copy.sessionError;
            return {status: 'error', rows: []};
        }
    }

    async function createAuthenticatedSession(snapshot) {
        if (snapshot.targetUserId <= 0) throw new Error('target_required');

        const payload = {
            target_user_id: snapshot.targetUserId,
            target_type: snapshot.app,
            channel: snapshot.channel,
            support_access: snapshot.supportAccess,
        };
        if (snapshot.channel === 'b2c' && snapshot.storeId) payload.store_id = snapshot.storeId;

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

    function guestSession(snapshot) {
        if (!snapshot.storeId) throw new Error('store_required');

        return {
            credential: null,
            context: {
                session_id: null,
                target_type: 'customer',
                auth_mode: 'guest',
                channel: snapshot.channel,
                store_id: snapshot.storeId,
                commerce_context: {
                    channel: snapshot.channel,
                    store_id: snapshot.storeId,
                },
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
            : (app.value === 'van'
                ? @json(__('admin.preview_center.van'))
                : @json(__('admin.preview_center.customer')));
        iframe.referrerPolicy = 'no-referrer';
        iframe.setAttribute('sandbox', 'allow-scripts allow-same-origin');
        iframe.setAttribute('data-preview-shared-runtime', app.value);
        frame.replaceChildren(iframe);
        runtimeFrame = iframe;
        setStatus(copy.connecting);
        if (launch) launch.textContent = copy.relaunch;
    }

    async function launchPreview() {
        const launchId = ++launchRequest;
        const snapshot = {
            app: ['customer', 'driver', 'van'].includes(app?.value) ? app.value : 'customer',
            channel: channel?.value === 'b2c' ? 'b2c' : 'b2b',
            storeId: selectedStoreId(),
            targetUserId: Number(target?.value || 0),
            requiresIdentity: requiresIdentity(),
            supportAccess: supportAccess(),
        };
        const runtime = runtimeConfig?.[snapshot.app] || null;
        if (!runtime?.available) {
            restoreUnavailable();
            return;
        }

        await revokeActive('relaunch');
        if (launchId !== launchRequest) return;

        try {
            let nextSession;
            if (snapshot.requiresIdentity) {
                nextSession = await createAuthenticatedSession(snapshot);
            } else {
                if (snapshot.app === 'van') {
                    nextSession = {
                        credential: null,
                        context: {
                            session_id: null,
                            target_type: 'van',
                            auth_mode: 'preview-van-readonly',
                            channel: snapshot.channel,
                            store_id: snapshot.storeId,
                            commerce_context: {
                                channel: snapshot.channel,
                                store_id: snapshot.storeId,
                            },
                            mode: 'read_only',
                            read_only: true,
                            support_access: false,
                            target: null,
                        },
                    };
                } else {
                    if (snapshot.app !== 'customer') throw new Error('target_required');
                    nextSession = guestSession(snapshot);
                }
            }

            if (launchId !== launchRequest) {
                await revokeSession(nextSession, 'context_changed');
                return;
            }

            activeSession = nextSession;
            clearInspector('connecting');
            mountRuntime();
            renderInspector();
        } catch (error) {
            if (launchId !== launchRequest) return;
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

            const bootstrap = {
                type: 'foodex.preview.bootstrap',
                version: runtime.contract_version,
                payload: {
                    context: activeSession.context,
                    credential: activeSession.credential,
                    configuration: configuration?.value || 'published',
                    locale: locale?.value || 'ar',
                    device: {
                        profile: device?.value || 'android_common',
                        width: Number(device?.selectedOptions?.[0]?.dataset?.width || 390),
                        height: Number(device?.selectedOptions?.[0]?.dataset?.height || 844),
                        safe_area: {
                            top: Number(device?.selectedOptions?.[0]?.dataset?.safeTop || 0),
                            right: Number(device?.selectedOptions?.[0]?.dataset?.safeRight || 0),
                            bottom: Number(device?.selectedOptions?.[0]?.dataset?.safeBottom || 0),
                            left: Number(device?.selectedOptions?.[0]?.dataset?.safeLeft || 0),
                        },
                        orientation: device?.selectedOptions?.[0]?.dataset?.orientation || 'portrait',
                        text_scale: Number(device?.selectedOptions?.[0]?.dataset?.textScale || 1),
                        view_insets: {
                            bottom: Number(device?.selectedOptions?.[0]?.dataset?.viewInsetBottom || 0),
                        },
                    },
                    safe_mode: 'read_only',
                },
            };
            runtimeFrame.contentWindow?.postMessage(
                bootstrap,
                runtime.origin,
            );
            setStatus(copy.connected);
            return;
        }

        if (message.type === 'foodex.preview.status') {
            updateInspectorFromStatus(message);
            const state = String(message.state || '');
            if (state === 'ready') setStatus(copy.connected);
            if (state === 'expired' || state === 'forbidden' || state === 'error') {
                setStatus(copy.sessionError);
            }
        }
    });

    app?.addEventListener('change', () => {
        if (app?.value === 'customer' && persona) persona.value = 'guest';
        void resetSecurityContext();
    });
    channel?.addEventListener('change', () => void resetSecurityContext());
    store?.addEventListener('change', () => void resetSecurityContext());
    persona?.addEventListener('change', () => void resetSecurityContext());
    target?.addEventListener('change', () => {
        ++launchRequest;
        void revokeActive('target_changed');
        clearInspector('unavailable');
        restoreUnavailable();
    });
    configuration?.addEventListener('change', resetPresentation);
    locale?.addEventListener('change', resetPresentation);
    device?.addEventListener('change', resetPresentation);
    launch?.addEventListener('click', () => void launchPreview());
    inspectorExport?.addEventListener('click', exportDiagnostic);

    window.addEventListener('pagehide', () => {
        void revokeActive('page_exit', true);
    });

    syncChannel();
    syncPersona();
    syncDevice();
    clearInspector('unavailable');
    renderInspector();
    void resetSecurityContext();
})();
</script>
</body>
</html>
