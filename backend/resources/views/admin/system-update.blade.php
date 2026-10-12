<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>{{ __('admin.system_update_surface.title') }} · FOODEX</title>
    <style>
        :root { color: #17202a; background: #f5f7fa; }
        * { box-sizing: border-box; }
        body { margin: 0; padding: 0; }
        main { max-width: 980px; margin: 0 auto; }
        .card { background: #fff; border: 1px solid #e2e8f0; border-radius: 16px; padding: 24px; margin-bottom: 20px; }
        .header { display: flex; align-items: center; justify-content: space-between; gap: 16px; }
        h1, h2 { margin-top: 0; }
        .badge { border: 1px solid #cbd5e1; border-radius: 999px; padding: 6px 10px; }
        .notice, .error { padding: 12px 14px; border-radius: 10px; margin: 12px 0; }
        .notice { background: #f0fdf4; border: 1px solid #86efac; }
        .error { background: #fff7ed; border: 1px solid #fdba74; }
        form { display: grid; gap: 16px; }
        label { display: grid; gap: 7px; font-weight: 700; }
        input, textarea { width: 100%; padding: 10px 12px; border: 1px solid #cbd5e1; border-radius: 8px; font: inherit; }
        .checkbox { display: flex; align-items: center; gap: 8px; }
        .checkbox input { width: auto; }
        button { justify-self: start; border: 0; border-radius: 8px; padding: 11px 18px; background: #111827; color: #fff; font-weight: 700; cursor: pointer; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 10px; text-align: start; border-bottom: 1px solid #e2e8f0; }
        code { direction: ltr; unicode-bidi: embed; }
        @media(max-width:767px){.header{align-items:flex-start;flex-direction:column}.card{padding:14px;margin-bottom:12px}button{width:100%;justify-self:stretch;min-height:44px}}
    </style>
    @include('admin._brand-components')
@include('admin._settings-page-polish')
</head>
<body>
<div class="foodex-admin-layout" data-foodex-mobile-web-page="system-update">
<aside class="sidebar">@include('admin._sidebar', ['navGroups' => app(\App\Support\AdminNavigation::class)->groupsFor(auth()->user()), 'navContext' => 'administration_hub', 'user' => auth()->user()])</aside> {{-- localization-gate: allow Blade include expression --}}
<main class="foodex-admin-main foodex-admin-page foodex-settings-surface" data-foodex-utility="system-update">
    @include('admin._admin-center-switcher',['active'=>'settings'])
<div class="header foodex-page-header">
        <div>
            <a href="{{ route('admin.settings.index') }}">← {{ __('admin.system_update_surface.back_settings') }}</a>
            <h1>{{ __('admin.system_update_surface.title') }}</h1>
        </div>
        <span class="badge">{{ __('admin.system_update_surface.current') }}: {{ $currentVersion }}</span>
    </div>

    @if (session('status'))
        <div class="notice foodex-state" role="status">{{ session('status') }}</div>
    @endif

    @if ($errors->any())
        <div class="error foodex-state" role="alert">
            <strong>{{ __('admin.system_update_surface.not_applied') }}</strong>
            <ul>
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <section class="card">
        <h2>{{ __('admin.system_update_surface.package_title') }}</h2>
        <p>{{ __('admin.system_update_surface.package_description') }}</p>

        <form method="post" action="{{ route('admin.system-update.store') }}" enctype="multipart/form-data" data-csrf-refresh-url="{{ route('admin.csrf-token') }}">
            @csrf
            <label>{{ __('admin.system_update_surface.zip_package') }}
                <input type="file" name="package" accept=".zip,application/zip" required>
            </label>
            <label>{{ __('admin.system_update_surface.target_version') }}
                <input name="target_version" placeholder="1.2.0" required>
            </label>
            <label>{{ __('admin.system_update_surface.minimum_current_version') }}
                <input name="minimum_current_version" value="{{ $currentVersion }}" required>
            </label>
            <label>{{ __('admin.system_update_surface.sha256') }}
                <input name="sha256" maxlength="64" minlength="64" required>
            </label>
            <label>{{ __('admin.system_update_surface.release_notes') }}
                <textarea name="release_notes" rows="4"></textarea>
            </label>
            <div class="notice">
                {{ __('admin.system_update_surface.migration_notice') }}
            </div>
            <button class="foodex-primary" type="submit">{{ __('admin.system_update_surface.install') }}</button>
        </form>
    </section>

    <section class="card">
        <h2>{{ __('admin.system_update_surface.history') }}</h2>
        <table data-pagination-required>
            <thead>
            <tr><th>{{ __('admin.system_update_surface.from') }}</th><th>{{ __('admin.system_update_surface.to') }}</th><th>{{ __('admin.system_update_surface.status') }}</th><th>{{ __('admin.system_update_surface.started') }}</th><th>{{ __('admin.system_update_surface.failure') }}</th></tr>
            </thead>
            <tbody>
            @forelse ($historyRows as $row)
                @php
                    $updateStatusCode = strtolower((string)$row->status);
                    $updateStatusLabel = match($updateStatusCode) {
                        'pending', 'queued' => app()->getLocale()==='ar' ? 'قيد الانتظار' : 'Pending',
                        'running', 'in_progress' => app()->getLocale()==='ar' ? 'قيد التنفيذ' : 'Running',
                        'completed', 'success', 'succeeded' => app()->getLocale()==='ar' ? 'مكتمل' : 'Completed',
                        'failed', 'error' => app()->getLocale()==='ar' ? 'فشل' : 'Failed',
                        'rolled_back' => app()->getLocale()==='ar' ? 'تم التراجع' : 'Rolled back',
                        default => app()->getLocale()==='ar' ? 'حالة التحديث' : 'Update status',
                    };
                @endphp
                <tr>
                    <td>{{ $row->from_version ?: '—' }}</td>
                    <td>{{ $row->to_version }}</td>
                    <td>{{ $updateStatusLabel }}</td>
                    <td>{{ $row->started_at ?: '—' }}</td>
                    <td>{{ $row->failure_reason ?: '—' }}</td>
                </tr>
            @empty
                <tr><td colspan="5">{{ __('admin.system_update_surface.empty') }}</td></tr>
            @endforelse
            </tbody>
        </table>
        {{ $historyRows->links() }}
    </section>
</main>
</div>
<script>
(() => {
    const form = document.querySelector('form[data-csrf-refresh-url]');
    if (!form) return;
    form.addEventListener('submit', async (event) => {
        if (form.dataset.csrfFresh === '1') return;
        event.preventDefault();
        const submit = form.querySelector('button[type="submit"], input[type="submit"]');
        if (submit) submit.disabled = true;
        try {
            const response = await fetch(form.dataset.csrfRefreshUrl, {
                credentials: 'same-origin',
                headers: {'Accept':'application/json','X-Requested-With':'XMLHttpRequest'},
                cache: 'no-store',
            });
            if (response.ok) {
                const payload = await response.json().catch(() => ({}));
                const token = form.querySelector('input[name="_token"]');
                if (token && payload.token) token.value = payload.token;
            }
            form.dataset.csrfFresh = '1';
            form.submit();
        } catch (_) {
            form.dataset.csrfFresh = '1';
            form.submit();
        }
    });
})();
</script>
</body>
</html>
