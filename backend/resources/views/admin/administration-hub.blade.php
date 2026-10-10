<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>{{ __('admin.administration_hub.title') }} · FOODEX</title>
<style>
body{margin:0;background:var(--foodex-page);color:var(--foodex-ink)}
.foodex-admin-layout{display:grid;grid-template-columns:minmax(0,1fr) var(--foodex-sidebar-width);min-height:100vh;width:100%}
html[dir=ltr] .foodex-admin-layout{grid-template-columns:var(--foodex-sidebar-width) minmax(0,1fr)}
.sidebar{grid-column:2;background:#fff;border-inline-start:1px solid var(--foodex-border);padding:18px}
html[dir=ltr] .sidebar{grid-column:1;border-inline-start:0;border-inline-end:1px solid var(--foodex-border)}
.main{grid-column:1;min-width:0;padding:clamp(18px,2.5vw,36px)}
html[dir=ltr] .main{grid-column:2}
.hub-intro{display:flex;justify-content:space-between;gap:18px;align-items:flex-start;margin-bottom:22px}
.hub-intro h1{margin:4px 0 8px;font-size:clamp(1.55rem,2.4vw,2.2rem)}
.eyebrow{margin:0;color:var(--foodex-green-dark);font-weight:800}
.muted{color:var(--foodex-muted);line-height:1.55}
.section{margin-top:22px}
.section-head{display:flex;justify-content:space-between;gap:16px;align-items:end;margin-bottom:12px}
.section-head h2{margin:0}
.grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:14px}
.card{background:#fff;border:1px solid var(--foodex-border);border-radius:var(--foodex-radius-card);padding:18px;box-shadow:var(--foodex-shadow-sm)}
.card h3{margin:0 0 8px}
.app-card{display:flex;flex-direction:column;min-height:176px}
.app-badge{width:44px;height:44px;border-radius:12px;display:grid;place-items:center;background:var(--foodex-green-soft);color:var(--foodex-green-dark);font-weight:900;margin-bottom:14px}
.actions{display:flex;flex-wrap:wrap;gap:8px;margin-top:auto;padding-top:14px}
.action{display:inline-flex;align-items:center;justify-content:center;min-height:40px;padding:0 13px;border-radius:10px;background:var(--foodex-green);color:#fff;text-decoration:none;font-weight:800}
.action.secondary{background:#fff;color:var(--foodex-green-dark);border:1px solid #b9dfc5}
.restricted{display:inline-flex;min-height:40px;align-items:center;padding:0 13px;border-radius:10px;background:var(--foodex-background);color:var(--foodex-muted);font-weight:700}
.utility-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px}
.utility-card{display:flex;justify-content:space-between;gap:16px;align-items:center}
.utility-card h3{margin:0 0 6px}
.utility-card .action{flex:0 0 auto}
.apk-mirror{margin-top:10px;padding:11px;border:1px solid var(--foodex-border);border-radius:10px;background:var(--foodex-background)}
.apk-mirror-head{display:flex;justify-content:space-between;gap:10px;align-items:center;font-size:.9rem;font-weight:800}
.apk-status{font-weight:900}
.apk-progress{height:7px;margin-top:9px;background:var(--foodex-border);border-radius:999px;overflow:hidden}
.apk-progress>span{display:block;height:100%;width:0;background:var(--foodex-green);transition:width .25s ease}
.apk-meta{margin-top:7px;color:var(--foodex-muted);font-size:.82rem}
.apk-modal[hidden]{display:none}
.apk-modal{position:fixed;inset:0;z-index:80;background:rgba(15,23,42,.48);display:grid;place-items:center;padding:18px}
.apk-modal-card{width:min(520px,100%);background:#fff;border-radius:16px;padding:20px;box-shadow:0 24px 70px rgba(15,23,42,.28)}
.apk-modal-head{display:flex;justify-content:space-between;gap:16px;align-items:center}
.apk-modal-head h2{margin:0}
.apk-modal-close{border:0;background:var(--foodex-background);border-radius:9px;padding:8px 11px;cursor:pointer;font-weight:800}
.apk-modal-actions{display:flex;flex-wrap:wrap;gap:9px;margin-top:18px}
.apk-modal-note{margin:10px 0 0}
@media(max-width:1023px){.foodex-admin-layout,html[dir=ltr] .foodex-admin-layout{grid-template-columns:1fr}.sidebar,.main,html[dir=ltr] .sidebar,html[dir=ltr] .main{grid-column:1}.sidebar{grid-row:1;border-inline:0;border-bottom:1px solid var(--foodex-border)}.main{grid-row:2}.grid{grid-template-columns:1fr 1fr}}
@media(max-width:680px){.hub-intro,.section-head,.utility-card{align-items:stretch;flex-direction:column}.grid,.utility-grid{grid-template-columns:1fr}}
</style>
@include('admin._brand-components')
</head>
<body>
<div class="foodex-admin-layout" data-admin-hub="true">
<aside class="sidebar">
@include('admin._sidebar',['navGroups'=>$navGroups,'navContext'=>$navContext,'user'=>$user])
</aside>
<main class="main foodex-admin-main">
<header class="hub-intro foodex-page-header">
<div>
<p class="eyebrow">{{ __('admin.administration_hub.eyebrow') }}</p>
<h1>{{ __('admin.administration_hub.title') }}</h1>
<p class="muted">{{ __('admin.administration_hub.description') }}</p>
</div>
@include('admin._live-notifications',['user'=>$user])
</header>

<section class="section" data-admin-applications>
<div class="section-head">
<div><h2>{{ __('admin.administration_hub.applications') }}</h2><p class="muted">{{ __('admin.administration_hub.applications_description') }}</p></div>
</div>
<div class="grid">
@foreach(['customer','driver','van'] as $app)
<article class="card app-card" data-admin-app="{{ $app }}">
<div class="app-badge">{{ strtoupper(substr($app,0,1)) }}</div>
<h3>{{ __('admin.administration_hub.'.$app) }}</h3>
<p class="muted">{{ __('admin.administration_hub.applications_description') }}</p>
@if($canPlatformManage)
@php($artifact = $mobileReleaseArtifacts->get($app, []))
@php($artifactStatus = $artifact['status'] ?? 'pending')
@php($artifactProgress = (int)($artifact['progress_percent'] ?? 0))
<div class="apk-mirror" data-apk-card="{{ $app }}" data-status="{{ $artifactStatus }}">
<div class="apk-mirror-head">
<span>{{ __('admin.mobile_apps.server_copy') }}</span>
<span class="apk-status" data-apk-status>{{ __('admin.mobile_apps.status_'.$artifactStatus) }}</span>
</div>
<div class="apk-progress" aria-label="{{ __('admin.mobile_apps.progress') }}"><span data-apk-progress style="width:{{ $artifactProgress }}%"></span></div>
<div class="apk-meta"><span data-apk-percent>{{ $artifactProgress }}%</span> · {{ __('admin.mobile_apps.version') }} {{ $mobileReleaseVersion }}</div>
</div>
@endif
<div class="actions">
@if($canPlatformManage)
<button type="button" class="action" data-apk-open="{{ $app }}">{{ __('admin.mobile_apps.download_apk') }}</button>
@endif
@if($canAppPreview)
<a class="action" href="{{ route('admin.app-preview.index', ['application'=>$app]) }}">{{ __('admin.administration_hub.preview') }}</a>
@else
<span class="restricted">{{ __('admin.administration_hub.restricted') }}</span>
@endif
@if($canPlatformManage)
<a class="action secondary" href="{{ route('admin.app-versions.index', ['app'=>$app]) }}">{{ __('admin.app_versions') }}</a>
@endif
@if($canMobileSettings)
<a class="action secondary" href="{{ route('admin.mobile-settings.index', ['app'=>$app, 'environment'=>'production']) }}">{{ __('admin.mobile_settings') }}</a>
@endif
@if($app==='van' && $canFinanceSupport)
<a class="action secondary" href="{{ route('admin.van-finance-support.index') }}">{{ __('admin.administration_hub.van_finance_support') }}</a>
@endif
</div>
</article>
@endforeach
</div>
</section>

<section class="section">
<div class="utility-grid">
<article class="card utility-card" data-admin-card="users-permissions"><div><h3>{{ __('admin.administration_hub.users_permissions') }}</h3><p class="muted">{{ __('admin.administration_hub.users_permissions_description') }}</p></div>@if($canSecurity)<a class="action" href="{{ route('admin.security.index') }}">{{ __('admin.administration_hub.open') }}</a>@else<span class="restricted">{{ __('admin.administration_hub.restricted') }}</span>@endif</article>
<article class="card utility-card" data-admin-card="system-settings"><div><h3>{{ __('admin.administration_hub.system_settings') }}</h3><p class="muted">{{ __('admin.administration_hub.system_settings_description') }}</p></div>@if($canMobileSettings)<a class="action" href="{{ route('admin.mobile-settings.index') }}">{{ __('admin.administration_hub.open') }}</a>@elseif($canTranslations)<a class="action" href="{{ route('admin.translations.index') }}">{{ __('admin.administration_hub.open') }}</a>@elseif($canAssistantSettings)<a class="action" href="{{ route('admin.assistant-settings.index') }}">{{ __('admin.administration_hub.open') }}</a>@else<span class="restricted">{{ __('admin.administration_hub.restricted') }}</span>@endif</article>
<article class="card utility-card" data-admin-card="communications-sms"><div><h3>{{ __('sms.title') }}</h3><p class="muted">{{ __('sms.description') }}</p></div>@if($canSmsSettings)<a class="action" href="{{ route('admin.sms-settings.index') }}">{{ __('admin.administration_hub.open') }}</a>@else<span class="restricted">{{ __('admin.administration_hub.restricted') }}</span>@endif</article>
<article class="card utility-card" data-admin-card="notifications"><div><h3>{{ __('admin.administration_hub.notifications') }}</h3><p class="muted">{{ __('admin.administration_hub.notifications_description') }}</p></div>@if($canMobileSettings)<a class="action" href="{{ route('admin.mobile-settings.index') }}#push">{{ __('admin.administration_hub.open') }}</a>@else<span class="restricted">{{ __('admin.administration_hub.restricted') }}</span>@endif</article>
<article class="card utility-card" data-admin-card="publishing"><div><h3>{{ __('admin.administration_hub.publishing') }}</h3><p class="muted">{{ __('admin.administration_hub.publishing_description') }}</p></div>@if($canPlatformManage)<a class="action" href="{{ route('admin.app-versions.index') }}">{{ __('admin.administration_hub.open') }}</a>@elseif($canMobileSettings)<a class="action" href="{{ route('admin.mobile-settings.index') }}#publishing">{{ __('admin.administration_hub.open') }}</a>@else<span class="restricted">{{ __('admin.administration_hub.restricted') }}</span>@endif</article>
<article class="card utility-card" data-admin-card="integrations"><div><h3>{{ __('admin.administration_hub.integrations') }}</h3><p class="muted">{{ __('admin.administration_hub.integrations_description') }}</p></div>@if($canPlatformManage)<a class="action" href="{{ route('admin.inspector.index') }}">{{ __('admin.administration_hub.open') }}</a>@elseif($canAssistantSettings)<a class="action" href="{{ route('admin.assistant-settings.index') }}">{{ __('admin.administration_hub.open') }}</a>@else<span class="restricted">{{ __('admin.administration_hub.restricted') }}</span>@endif</article>
<article class="card utility-card" data-admin-card="van-finance-support"><div><h3>{{ __('admin.administration_hub.van_finance_support') }}</h3><p class="muted">{{ __('admin.administration_hub.van_finance_support_description') }}</p></div>@if($canFinanceSupport)<a class="action" href="{{ route('admin.van-finance-support.index') }}">{{ __('admin.administration_hub.open') }}</a>@else<span class="restricted">{{ __('admin.administration_hub.restricted') }}</span>@endif</article>
<article class="card utility-card" data-admin-card="profile"><div><h3>{{ __('admin.administration_hub.profile') }}</h3><p class="muted">{{ $user->name }} · {{ $user->email }}</p></div><a class="action" href="{{ route('admin.profile.index') }}">{{ __('admin.administration_hub.open') }}</a></article>
</div>
</section>
</main>
</div>

@if($canPlatformManage)
<div class="apk-modal" id="apk-download-modal" hidden role="dialog" aria-modal="true" aria-labelledby="apk-modal-title">
<div class="apk-modal-card">
<div class="apk-modal-head">
<div>
<h2 id="apk-modal-title">{{ __('admin.mobile_apps.download_apk') }}</h2>
<p class="muted apk-modal-note"><span data-apk-modal-app></span> · {{ __('admin.mobile_apps.version') }} <span data-apk-modal-version>{{ $mobileReleaseVersion }}</span></p>
</div>
<button type="button" class="apk-modal-close" data-apk-close>{{ __('admin.mobile_apps.close') }}</button>
</div>
<div class="apk-mirror">
<div class="apk-mirror-head">
<span>{{ __('admin.mobile_apps.server_copy') }}</span>
<span class="apk-status" data-apk-modal-status></span>
</div>
<div class="apk-progress"><span data-apk-modal-progress></span></div>
<div class="apk-meta"><span data-apk-modal-percent>0%</span></div>
<p class="muted apk-modal-note" data-apk-modal-note>{{ __('admin.mobile_apps.not_ready') }}</p>
</div>
<div class="apk-modal-actions">
<a class="action" data-apk-download hidden href="#">{{ __('admin.mobile_apps.download_from_server') }}</a>
<button type="button" class="action secondary" data-apk-retry hidden>{{ __('admin.mobile_apps.retry') }}</button>
</div>
</div>
</div>

@php
$mobileStatusLabels = [
    'pending' => __('admin.mobile_apps.status_pending'),
    'downloading' => __('admin.mobile_apps.status_downloading'),
    'verifying' => __('admin.mobile_apps.status_verifying'),
    'ready' => __('admin.mobile_apps.status_ready'),
    'failed' => __('admin.mobile_apps.status_failed'),
];
$mobileAppLabels = [
    'customer' => __('admin.administration_hub.customer'),
    'driver' => __('admin.administration_hub.driver'),
    'van' => __('admin.administration_hub.van'),
];
@endphp
<script>
(() => {
    const statusUrl = @json(route('admin.mobile-apps.status'));
    const prepareUrl = @json(route('admin.mobile-apps.prepare'));
    const retryUrl = @json(route('admin.mobile-apps.retry'));
    const csrf = @json(csrf_token());
    const labels = @json($mobileStatusLabels);
    const appLabels = @json($mobileAppLabels);
    let artifacts = @json($mobileReleaseArtifacts->values()->all());
    let selectedApp = null;
    let refreshing = false;

    const modal = document.getElementById('apk-download-modal');
    const modalStatus = modal.querySelector('[data-apk-modal-status]');
    const modalProgress = modal.querySelector('[data-apk-modal-progress]');
    const modalPercent = modal.querySelector('[data-apk-modal-percent]');
    const modalApp = modal.querySelector('[data-apk-modal-app]');
    const modalVersion = modal.querySelector('[data-apk-modal-version]');
    const modalNote = modal.querySelector('[data-apk-modal-note]');
    const download = modal.querySelector('[data-apk-download]');
    const retry = modal.querySelector('[data-apk-retry]');

    function artifactFor(app) {
        return artifacts.find((row) => row.app === app) || {
            app,
            version: @json($mobileReleaseVersion),
            status: 'pending',
            progress_percent: 0,
            download_url: null,
        };
    }

    function renderCard(row) {
        const card = document.querySelector('[data-apk-card="' + row.app + '"]');
        if (!card) return;
        const progress = Math.max(0, Math.min(100, Number(row.progress_percent || 0)));
        card.dataset.status = row.status;
        card.querySelector('[data-apk-status]').textContent = labels[row.status] || row.status;
        card.querySelector('[data-apk-progress]').style.width = progress + '%';
        card.querySelector('[data-apk-percent]').textContent = progress + '%';
    }

    function renderModal() {
        if (!selectedApp) return;
        const row = artifactFor(selectedApp);
        const progress = Math.max(0, Math.min(100, Number(row.progress_percent || 0)));
        modalApp.textContent = appLabels[selectedApp] || selectedApp;
        modalVersion.textContent = row.version || @json($mobileReleaseVersion);
        modalStatus.textContent = labels[row.status] || row.status;
        modalProgress.style.width = progress + '%';
        modalPercent.textContent = progress + '%';
        download.hidden = row.status !== 'ready' || !row.download_url;
        download.href = row.download_url || '#';
        retry.hidden = row.status !== 'failed';
        modalNote.hidden = row.status === 'ready';
        if (row.status === 'failed' && row.last_error) {
            modalNote.hidden = false;
            modalNote.textContent = row.last_error;
        } else if (row.status !== 'ready') {
            modalNote.textContent = @json(__('admin.mobile_apps.not_ready'));
        }
    }

    function renderAll() {
        artifacts.forEach(renderCard);
        renderModal();
    }

    async function refresh() {
        if (refreshing) return;
        refreshing = true;
        try {
            const response = await fetch(statusUrl, {
                credentials: 'same-origin',
                headers: {'Accept': 'application/json'},
            });
            if (!response.ok) return;
            const payload = await response.json();
            artifacts = Array.isArray(payload.artifacts) ? payload.artifacts : artifacts;
            renderAll();
        } finally {
            refreshing = false;
        }
    }

    async function prepare() {
        try {
            const response = await fetch(prepareUrl, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': csrf,
                    'Content-Type': 'application/json',
                },
                body: '{}',
            });
            if (response.ok) {
                const payload = await response.json();
                artifacts = Array.isArray(payload.artifacts) ? payload.artifacts : artifacts;
                renderAll();
            }
        } finally {
            void refresh();
        }
    }

    function openModal(app) {
        selectedApp = app;
        modal.hidden = false;
        renderModal();
        void prepare();
    }

    document.querySelectorAll('[data-apk-open]').forEach((button) => {
        button.addEventListener('click', () => openModal(button.dataset.apkOpen));
    });
    modal.querySelector('[data-apk-close]').addEventListener('click', () => {
        modal.hidden = true;
        selectedApp = null;
    });
    modal.addEventListener('click', (event) => {
        if (event.target === modal) {
            modal.hidden = true;
            selectedApp = null;
        }
    });
    retry.addEventListener('click', async () => {
        retry.disabled = true;
        try {
            const response = await fetch(retryUrl, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': csrf,
                    'Content-Type': 'application/json',
                },
                body: '{}',
            });
            if (response.ok) {
                const payload = await response.json();
                artifacts = Array.isArray(payload.artifacts) ? payload.artifacts : artifacts;
                renderAll();
            }
        } finally {
            retry.disabled = false;
        }
    });

    renderAll();
    const requestedApp = @json(request('download_app'));
    if (requestedApp && appLabels[requestedApp]) {
        openModal(requestedApp);
    }

    window.setInterval(() => {
        const unfinished = artifacts.some((row) => ['pending', 'downloading', 'verifying'].includes(row.status));
        if (!modal.hidden || unfinished) void refresh();
    }, 2000);
})();
</script>
@endif
</body>
</html>
