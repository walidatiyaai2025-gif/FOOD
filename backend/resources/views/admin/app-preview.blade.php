<!doctype html>
<html lang="{{ str_replace('_','-',app()->getLocale()) }}" dir="{{ app()->getLocale()==='ar'?'rtl':'ltr' }}">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>{{ __('admin.preview_center.title') }} · FOODEX</title>
@include('admin._brand-components')
<style>
.preview-layout{display:grid;grid-template-columns:minmax(280px,360px) minmax(0,1fr);gap:var(--foodex-space-5);align-items:start}
.preview-controls{padding:var(--foodex-space-5);display:grid;gap:var(--foodex-space-4);position:sticky;top:18px}
.preview-controls label{display:grid;gap:7px;font-weight:700}.preview-controls select{width:100%}
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
                        <option value="driver">{{ __('admin.preview_center.driver') }}</option>
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
                        <option value="guest">{{ __('admin.preview_center.guest') }}</option>
                        @if($canImpersonateCustomer || $canImpersonateDriver)
                            <option value="authenticated">{{ __('admin.preview_center.authenticated') }}</option>
                        @endif
                    </select>
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
                    <span class="badge" id="preview-runtime-status">{{ __('admin.preview_center.unavailable') }}</span>
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
    const channel = document.getElementById('preview-channel');
    const storeWrap = document.getElementById('preview-store-wrap');
    const device = document.getElementById('preview-device');
    const frame = document.getElementById('preview-device-frame');

    const syncChannel = () => {
        if (storeWrap) storeWrap.hidden = channel?.value !== 'b2c';
    };
    const syncDevice = () => {
        const width = device?.selectedOptions?.[0]?.dataset?.width || '390';
        frame?.style.setProperty('--preview-device-width', width + 'px');
    };

    channel?.addEventListener('change', syncChannel);
    device?.addEventListener('change', syncDevice);
    syncChannel();
    syncDevice();
})();
</script>
</body>
</html>
