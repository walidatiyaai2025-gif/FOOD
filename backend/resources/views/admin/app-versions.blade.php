<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>{{ __('app_versions.title') }} · FOODEX</title>
    @include('admin._brand-components')
</head>
<body>
<main class="foodex-admin-page" data-foodex-utility="app-versions">
    @php($ar = app()->getLocale() === 'ar')
    <h1>{{ __('app_versions.title') }}</h1>
    <p class="foodex-state">
        {{ $ar
            ? 'هذه هي سياسة الإصدار الرسمية التي يعتمد عليها تطبيق الهاتف عند بدء التشغيل. إعدادات Mobile Runtime منفصلة ومعلوماتية ولا تستبدل هذه السياسة.'
            : 'This is the authoritative startup/update policy consumed by the mobile apps. Mobile Runtime settings are separate informational metadata and do not replace this policy.' }}
    </p>
    <p><a href="{{ route('admin.mobile-settings.index', ['app' => $selectedApp, 'environment' => 'production']) }}">{{ $ar ? 'العودة إلى إعدادات الموبايل وجاهزية النشر' : 'Back to Mobile Settings and rollout readiness' }}</a></p>

    @if (session('status')) <p class="foodex-state" role="status">{{ session('status') }}</p> @endif
    @if ($errors->any()) <div class="foodex-state" role="alert"><ul>@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div> @endif

    <form class="foodex-form" method="get" action="{{ route('admin.app-versions.index') }}">
        <label>{{ __('app_versions.app') }}
            <select name="app" required>
                <option value="customer" @selected($selectedApp === 'customer')>{{ __('app_versions.customer') }}</option>
                <option value="driver" @selected($selectedApp === 'driver')>{{ __('app_versions.driver') }}</option>
                <option value="van" @selected($selectedApp === 'van')>{{ __('app_versions.van') }}</option>
            </select>
        </label>
        <label>{{ __('app_versions.platform') }}
            <select name="platform" required>
                <option value="android" @selected($selectedPlatform === 'android')>{{ __('app_versions.android') }}</option>
                <option value="ios" @selected($selectedPlatform === 'ios')>{{ __('app_versions.ios') }}</option>
            </select>
        </label>
        <button class="foodex-primary" type="submit">{{ $ar ? 'تحميل السياسة المحفوظة' : 'Load saved policy' }}</button>
    </form>

    <form class="foodex-form" method="post" action="{{ route('admin.app-versions.store') }}">
        @csrf
        <input type="hidden" name="app" value="{{ $selectedApp }}">
        <input type="hidden" name="platform" value="{{ $selectedPlatform }}">

        <p><strong>{{ __('app_versions.app') }}:</strong> {{ __('app_versions.'.$selectedApp) }} · <strong>{{ __('app_versions.platform') }}:</strong> {{ __('app_versions.'.$selectedPlatform) }}</p>

        <label>{{ __('app_versions.latest_version') }}
            <input name="latest_version" dir="ltr" value="{{ old('latest_version', $selectedPolicy?->latest_version) }}" placeholder="1.0.40" required>
        </label>
        <label>{{ __('app_versions.minimum_supported_version') }}
            <input name="minimum_supported_version" dir="ltr" value="{{ old('minimum_supported_version', $selectedPolicy?->minimum_supported_version) }}" placeholder="1.0.40" required>
        </label>
        <input type="hidden" name="force_update" value="0">
        <label><input type="checkbox" name="force_update" value="1" @checked((bool) old('force_update', $selectedPolicy?->force_update ?? false))> {{ __('app_versions.force_update') }}</label>
        <label>{{ __('app_versions.store_url') }}
            <input name="store_url" type="url" dir="ltr" value="{{ old('store_url', $selectedPolicy?->store_url) }}" placeholder="https://example.com/app" required>
        </label>
        <label>{{ __('app_versions.release_notes') }}
            <textarea name="release_notes" placeholder="{{ $ar ? 'اكتب ملخص التحديث والتغييرات المهمة' : 'Summarize the release changes' }}">{{ old('release_notes', $selectedPolicy?->release_notes) }}</textarea>
        </label>
        <button class="foodex-primary" type="submit">{{ __('app_versions.save') }}</button>
    </form>

    <h2>{{ __('app_versions.configured') }}</h2>
    @if ($policies->isEmpty()) <p class="foodex-empty-state">{{ __('app_versions.empty') }}</p>
    @else
        <div style="overflow-x:auto">
            <table class="foodex-table">
                <thead><tr>
                    @foreach (['app', 'platform', 'latest_version', 'minimum_supported_version', 'force_update', 'store_url'] as $heading)
                        <th scope="col">{{ __('app_versions.'.$heading) }}</th>
                    @endforeach
                </tr></thead>
                <tbody>@foreach ($policies as $policy)<tr>
                    <td>{{ __('app_versions.'.$policy->app) }}</td>
                    <td>{{ __('app_versions.'.$policy->platform) }}</td>
                    <td><bdi dir="ltr">{{ $policy->latest_version }}</bdi></td>
                    <td><bdi dir="ltr">{{ $policy->minimum_supported_version }}</bdi></td>
                    <td>{{ __('app_versions.'.($policy->force_update ? 'yes' : 'no')) }}</td>
                    <td><a href="{{ $policy->store_url }}" rel="noopener">{{ __('app_versions.store_url') }}</a></td>
                </tr>@endforeach</tbody>
            </table>
        </div>
    @endif
</main>
</body></html>
