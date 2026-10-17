<!doctype html>
<html lang="{{ app()->getLocale() }}" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>{{ __('production_launch.title') }} · FOODEX</title>
    @include('admin._brand-components')
    <style>
        body{margin:0}
        .production-launch-summary{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,180px),1fr));gap:var(--foodex-space-3);margin-block:var(--foodex-space-4)}
        .production-launch-summary .foodex-card{padding:var(--foodex-space-4);min-width:0}
        .production-launch-summary strong{display:block;margin-top:var(--foodex-space-1);font-size:var(--foodex-text-lg);overflow-wrap:anywhere}
        .production-launch-checks{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,280px),1fr));gap:var(--foodex-space-3);margin-block:var(--foodex-space-4) var(--foodex-space-6)}
        .production-launch-check{padding:var(--foodex-space-4);display:grid;gap:var(--foodex-space-2);align-content:start}
        .production-launch-check-head{display:flex;align-items:center;justify-content:space-between;gap:var(--foodex-space-3)}
        .production-launch-check h3{margin:0;font-size:var(--foodex-text-md)}
        .production-launch-check p{margin:0;color:var(--foodex-muted)}
        .production-launch-form{max-width:760px;grid-template-columns:minmax(0,1fr)}
        .production-launch-form input{width:100%;min-width:0}
        .production-launch-form code{direction:ltr;unicode-bidi:isolate;overflow-wrap:anywhere}
        .production-launch-form button:disabled{opacity:.58;cursor:not-allowed}
        .production-launch-blockers{margin:0;padding-inline-start:1.25rem}
        @media(max-width:479px){
            .production-launch-check-head{align-items:flex-start;flex-direction:column}
        }
    </style>
</head>
<body>
<div class="foodex-admin-layout" data-foodex-mobile-web-page="production-launch">
    <aside class="sidebar">@include('admin._sidebar', ['navGroups' => app(\App\Support\AdminNavigation::class)->groupsFor(auth()->user()), 'navContext' => 'administration_hub', 'user' => auth()->user()])</aside> {{-- localization-gate: allow Blade include expression --}}
    <main class="foodex-admin-main foodex-admin-page" data-foodex-production-launch="true">
@include('admin._global-header')
        @include('admin._admin-center-switcher',['active'=>'settings'])

        <div class="foodex-page-toolbar">
            <div>
                <h1>{{ __('production_launch.title') }}</h1>
                <p>{{ __('production_launch.description') }}</p>
            </div>
            <div class="foodex-header-actions">
                <a class="foodex-action-secondary" href="{{ route('admin.production-launch.report') }}">{{ __('production_launch.report.download') }}</a>
                <a class="foodex-action-secondary" href="{{ route('admin.settings.index') }}">{{ __('production_launch.back') }}</a>
            </div>
        </div>

        @if(session('status'))
            <div class="foodex-state" role="status">{{ session('status') }}</div>
        @endif

        @if($errors->any())
            <div class="foodex-state" role="alert">
                <ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
            </div>
        @endif

        @if($report['ready'])
            <div class="foodex-state" role="status" data-launch-ready="true">{{ __('production_launch.ready') }}</div>
        @else
            <div class="foodex-state" role="alert" data-launch-blocked="true">
                <strong>{{ __('production_launch.blocked') }}</strong>
                <ul class="production-launch-blockers">
                    @foreach($report['blockers'] as $code)
                        <li>{{ __("production_launch.checks.$code.title") }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <section aria-labelledby="release-lineage">
            <h2 id="release-lineage">{{ __('production_launch.release.title') }}</h2>
            <p>{{ __('production_launch.release.description') }}</p>
            <div class="production-launch-summary">
                <div class="foodex-card">
                    <span>{{ __('production_launch.release.version') }}</span>
                    <strong class="foodex-number" dir="ltr">{{ $release['version'] ?? __('production_launch.release.unknown') }}</strong>
                </div>
                <div class="foodex-card">
                    <span>{{ __('production_launch.release.source') }}</span>
                    <strong class="foodex-number" dir="ltr">{{ $release['source_commit'] ? substr($release['source_commit'], 0, 12) : __('production_launch.release.unknown') }}</strong>
                </div>
                <div class="foodex-card">
                    <span>{{ __('production_launch.release.run') }}</span>
                    <strong class="foodex-number" dir="ltr">{{ $release['github_run_number'] ?? __('production_launch.release.unknown') }}</strong>
                </div>
                <div class="foodex-card">
                    <span>{{ __('production_launch.release.mobile_builds') }}</span>
                    <strong class="foodex-number" dir="ltr">
                        {{ $release['customer_frozen_identity'] ?? __('production_launch.release.unknown') }} /
                        {{ $release['driver_version'] ? $release['driver_version'].'+'.($release['driver_build_number'] ?? '?') : __('production_launch.release.unknown') }} /
                        {{ $release['van_version'] ? $release['van_version'].'+'.($release['van_build_number'] ?? '?') : __('production_launch.release.unknown') }}
                    </strong>
                </div>
            </div>
        </section>

        <section aria-labelledby="preflight-checks">
            <h2 id="preflight-checks">{{ __('production_launch.preflight') }}</h2>
            <p>{{ __('production_launch.preflight_description') }}</p>
            <div class="production-launch-checks">
                @foreach($report['checks'] as $code => $check)
                    <article class="foodex-card production-launch-check" data-launch-check="{{ $code }}" data-launch-check-passed="{{ $check['passed'] ? 'true' : 'false' }}">
                        <div class="production-launch-check-head">
                            <h3>{{ __("production_launch.checks.$code.title") }}</h3>
                            <span class="badge {{ $check['passed'] ? 'active' : 'warning' }}">
                                {{ $check['passed'] ? __('production_launch.status.passed') : __('production_launch.status.blocked') }}
                            </span>
                        </div>
                        <p>{{ __("production_launch.checks.$code.description") }}</p>
                    </article>
                @endforeach
            </div>
        </section>

        <form
            class="foodex-form production-launch-form"
            method="post"
            action="{{ url('/admin/settings/production-launch') }}"
            data-production-launch-form="true"
            @if(!$report['ready']) aria-disabled="true" @endif
        >
            @csrf
            <h2>{{ __('production_launch.confirm.title') }}</h2>
            <p>{{ __('production_launch.confirm.description') }}</p>

            <label for="launch-confirmation">
                {{ __('production_launch.confirm.label') }}
                <code>{{ \App\Services\ProductionLaunchPreflight::LAUNCH_CONFIRMATION }}</code>
            </label>
            <input
                id="launch-confirmation"
                name="confirmation"
                autocomplete="off"
                placeholder="{{ __('production_launch.confirm.placeholder') }}"
                required
                @disabled(!$report['ready'])
            >

            <label for="launch-password">{{ __('production_launch.confirm.password') }}</label>
            <input
                id="launch-password"
                type="password"
                name="current_password"
                autocomplete="current-password"
                placeholder="{{ __('production_launch.confirm.password_placeholder') }}"
                required
                @disabled(!$report['ready'])
            >

            <button class="foodex-primary" type="submit" @disabled(!$report['ready'])>
                {{ __('production_launch.confirm.submit') }}
            </button>
            <a href="{{ url('/admin/settings/production-launch') }}">{{ __('production_launch.refresh') }}</a>
        </form>
    </main>
</div>

<script>
const productionLaunchForm = document.querySelector('[data-production-launch-form]');
productionLaunchForm?.addEventListener('submit', event => {
    if (productionLaunchForm.dataset.busy === 'true') {
        event.preventDefault();
        return;
    }

    productionLaunchForm.dataset.busy = 'true';
    productionLaunchForm.setAttribute('aria-busy', 'true');
    const button = productionLaunchForm.querySelector('button[type=submit]');
    if (button) {
        button.disabled = true;
        button.textContent = @json(__('production_launch.confirm.submitting'));
    }
});
</script>
</body>
</html>
