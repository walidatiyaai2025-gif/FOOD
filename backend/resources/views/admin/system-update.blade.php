<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>{{ __('admin.system_update_surface.title') }} · FOODEX</title>
    @include('admin._brand-components')
    @include('admin._settings-page-polish')
    <style>
        body{margin:0}.update-page{padding:clamp(14px,2.2vw,28px);min-width:0}.update-header{display:flex;align-items:flex-start;justify-content:space-between;gap:16px;margin-bottom:18px}.update-header h1{margin:4px 0 0}.update-header-actions{display:flex;gap:8px;align-items:center;flex-wrap:wrap}.update-current{border:1px solid var(--foodex-border);border-radius:999px;padding:7px 10px;background:#fff;font-weight:800}.update-grid{display:grid;grid-template-columns:minmax(0,1fr);gap:16px}.update-card{padding:clamp(14px,2vw,22px)}.update-card h2{margin-top:0}.update-form{display:grid;gap:14px}.update-form label{display:grid;gap:6px;font-weight:700}.update-form input,.update-form textarea{width:100%;min-width:0}.update-notice{padding:12px 14px;border:1px solid var(--foodex-border);border-radius:10px;background:var(--foodex-background)}.update-table-wrap{max-width:100%;overflow:auto}.update-table{width:100%;border-collapse:collapse;min-width:1120px}.update-table th,.update-table td{padding:9px 10px;text-align:start;border-bottom:1px solid var(--foodex-border);vertical-align:top}.update-table th{color:var(--foodex-muted);font-size:.78rem;background:var(--foodex-background)}.update-table code{direction:ltr;unicode-bidi:embed}.update-evidence{display:grid;gap:5px;min-width:150px}.update-evidence span{display:flex;justify-content:space-between;gap:8px}.update-evidence small{color:var(--foodex-muted)}.update-state{padding:12px 14px;border:1px solid var(--foodex-border);border-radius:10px;margin-bottom:12px}.update-state[role="alert"]{background:var(--foodex-danger-soft,#fff0f0)}.update-state[role="status"]{background:var(--foodex-green-soft,#eef8f2)}
        @media(max-width:767px){.update-header{flex-direction:column}.update-header-actions{width:100%;display:grid;grid-template-columns:1fr}.update-header-actions>a{justify-content:center}.update-card{padding:12px}.update-form button{width:100%;min-height:44px}}
    </style>
</head>
<body>
<div class="foodex-admin-layout" data-foodex-mobile-web-page="system-update">
<aside class="sidebar">@include('admin._sidebar', ['navGroups' => app(\App\Support\AdminNavigation::class)->groupsFor(auth()->user()), 'navContext' => 'administration_hub', 'user' => auth()->user()])</aside>
<main class="foodex-admin-main foodex-admin-page foodex-settings-surface update-page" data-foodex-utility="system-update">
    @include('admin._admin-center-switcher',['active'=>'settings'])
    <header class="update-header foodex-page-header">
        <div>
            <div class="foodex-subtitle">FOODEX · {{ __('system_admin.eyebrow') }}</div>
            <h1>{{ __('admin.system_update_surface.title') }}</h1>
        </div>
        <div class="update-header-actions">
            <span class="update-current">{{ __('admin.system_update_surface.current') }}: <code>{{ $currentVersion }}</code></span>
            <a class="foodex-action-secondary" href="{{ route('admin.enterprise-system-health.index') }}">{{ __('system_admin.title') }}</a>
            <a class="foodex-action-secondary" href="{{ route('admin.settings.index') }}">{{ __('admin.system_update_surface.back_settings') }}</a>
        </div>
    </header>

    @if (session('status'))
        <div class="update-state foodex-state" role="status">{{ session('status') }}</div>
    @endif
    @if ($errors->any())
        <div class="update-state foodex-state" role="alert">
            <strong>{{ __('admin.system_update_surface.not_applied') }}</strong>
            <ul>@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
        </div>
    @endif

    <div class="update-grid">
        <section class="foodex-card update-card">
            <h2>{{ __('admin.system_update_surface.package_title') }}</h2>
            <p>{{ __('admin.system_update_surface.package_description') }}</p>
            <form class="update-form" method="post" action="{{ route('admin.system-update.store') }}" enctype="multipart/form-data" data-csrf-refresh-url="{{ route('admin.csrf-token') }}">
                @csrf
                <label>{{ __('admin.system_update_surface.zip_package') }}<input type="file" name="package" accept=".zip,application/zip" required></label>
                <label>{{ __('admin.system_update_surface.target_version') }}<input name="target_version" placeholder="1.2.0" required></label>
                <label>{{ __('admin.system_update_surface.minimum_current_version') }}<input name="minimum_current_version" value="{{ $currentVersion }}" required></label>
                <label>{{ __('admin.system_update_surface.sha256') }}<input name="sha256" maxlength="64" minlength="64" required></label>
                <label>{{ __('admin.system_update_surface.release_notes') }}<textarea name="release_notes" rows="4"></textarea></label>
                <div class="update-notice">{{ __('admin.system_update_surface.migration_notice') }}</div>
                <button class="foodex-action-primary" type="submit">{{ __('admin.system_update_surface.install') }}</button>
            </form>
        </section>

        <section class="foodex-card update-card">
            <h2>{{ __('system_admin.update_center.evidence_title') }}</h2>
            <p>{{ __('system_admin.update_center.evidence_description') }}</p>
            <div class="update-table-wrap">
            <table class="update-table" data-pagination-required>
                <thead><tr>
                    <th>{{ __('admin.system_update_surface.from') }} → {{ __('admin.system_update_surface.to') }}</th>
                    <th>{{ __('admin.system_update_surface.status') }}</th>
                    <th>{{ __('system_admin.labels.package_integrity') }} / {{ __('system_admin.labels.preflight') }}</th>
                    <th>{{ __('system_admin.labels.backup') }}</th>
                    <th>{{ __('system_admin.labels.migrations') }} / {{ __('system_admin.labels.health_check') }}</th>
                    <th>{{ __('system_admin.labels.rollback') }}</th>
                    <th>{{ __('system_admin.update_center.environment') }}</th>
                    <th>{{ __('system_admin.labels.origin') }}</th>
                    <th>{{ __('admin.system_update_surface.failure') }}</th>
                </tr></thead>
                <tbody>
                @forelse ($historyRows as $row)
                    @php
                        $evidence=is_array($row->execution_evidence)?$row->execution_evidence:[];
                        $normalizeEvidence=static fn($value)=>in_array($value,['pending','passed','failed','not_required','available','partial','not_created','succeeded','incomplete','automatic','manual_recovery'],true)?$value:'unknown';
                        $statusKey=match(strtolower((string)$row->status)) {
                            'completed','success','succeeded'=>'succeeded',
                            'failed','error'=>'failed',
                            default=>'pending',
                        };
                        $fileBackup=is_array($evidence['file_backup']??null)?$evidence['file_backup']:null;
                        $databaseBackup=is_array($evidence['database_backup']??null)?$evidence['database_backup']:null;
                    @endphp
                    <tr>
                        <td><code>{{ $row->from_version ?: '—' }}</code> → <code>{{ $row->to_version }}</code><br><small dir="ltr">{{ optional($row->started_at)->toIso8601String() ?: '—' }}</small></td>
                        <td>{{ __('system_admin.evidence.'.$statusKey) }}</td>
                        <td>
                            <div class="update-evidence">
                                <span><small>{{ __('system_admin.labels.package_integrity') }}</small><strong>{{ __('system_admin.evidence.'.$normalizeEvidence($evidence['package_integrity']??'pending')) }}</strong></span>
                                <span><small>{{ __('system_admin.labels.preflight') }}</small><strong>{{ __('system_admin.evidence.'.$normalizeEvidence($evidence['preflight']??'pending')) }}</strong></span>
                            </div>
                        </td>
                        <td>
                            <div class="update-evidence">
                                <span><small>{{ __('system_admin.labels.status') }}</small><strong>{{ __('system_admin.evidence.'.$normalizeEvidence($row->backup_status?:'unknown')) }}</strong></span>
                                @if($fileBackup)<span><small>{{ __('system_admin.labels.file_backup') }}</small><code>{{ $fileBackup['reference']??'—' }}</code></span>@endif
                                @if($databaseBackup)<span><small>{{ __('system_admin.labels.database_backup') }}</small><code>{{ $databaseBackup['reference']??'—' }}</code></span>@endif
                            </div>
                        </td>
                        <td>
                            <div class="update-evidence">
                                <span><small>{{ __('system_admin.labels.migrations') }}</small><strong>{{ __('system_admin.evidence.'.$normalizeEvidence($evidence['migrations']??'not_required')) }}</strong></span>
                                <span><small>{{ __('system_admin.labels.health_check') }}</small><strong>{{ __('system_admin.evidence.'.$normalizeEvidence($evidence['health_check']??'pending')) }}</strong></span>
                            </div>
                        </td>
                        <td>
                            <div class="update-evidence">
                                <span><small>{{ __('system_admin.labels.rollback') }}</small><strong>{{ __('system_admin.evidence.'.$normalizeEvidence($row->rollback_status?:'unknown')) }}</strong></span>
                                @if($row->failed_stage)<span><small>{{ __('system_admin.labels.failed_stage') }}</small><code>{{ $row->failed_stage }}</code></span>@endif
                            </div>
                        </td>
                        <td>{{ $row->environment ?: '—' }}</td>
                        <td>{{ $row->actor?->name ?: '—' }}</td>
                        <td>{{ $row->failure_reason ?: '—' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="9">{{ __('admin.system_update_surface.empty') }}</td></tr>
                @endforelse
                </tbody>
            </table>
            </div>
            {{ $historyRows->links() }}
        </section>
    </div>
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
