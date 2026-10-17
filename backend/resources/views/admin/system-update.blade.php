<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>{{ __('admin.system_update_surface.title') }} · FOODEX</title>
    @include('admin._brand-components')
    @include('admin._settings-page-polish')
    <style>
        .system-runtime-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:var(--foodex-space-3);min-width:0}
        .system-runtime-panels{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:var(--foodex-space-3);min-width:0}
        .system-runtime-card{padding:var(--foodex-space-4);min-width:0}
        .system-runtime-table-wrap{max-width:100%;overflow:auto;overscroll-behavior-inline:contain}
        .system-runtime-table{min-width:980px;width:100%;border-collapse:collapse}
        .system-runtime-table th,.system-runtime-table td{padding:10px;border-bottom:1px solid var(--foodex-border);text-align:start;vertical-align:top}
        .system-runtime-form{display:grid;gap:var(--foodex-space-3);min-width:0}
        .system-runtime-form label{display:grid;gap:6px;font-weight:700;min-width:0}
        .system-runtime-form input,.system-runtime-form textarea{width:100%;min-width:0}
        .system-runtime-actions{display:flex;flex-wrap:wrap;gap:var(--foodex-space-2);align-items:center}
        .system-runtime-code{font:var(--foodex-text-xs) ui-monospace,SFMono-Regular,Consolas,monospace;overflow-wrap:anywhere}.system-runtime-grid .foodex-module-kpi-value{overflow-wrap:anywhere}
        .system-runtime-chart-row{display:grid;grid-template-columns:minmax(120px,1fr) minmax(0,3fr) auto;gap:10px;align-items:center;margin-block:10px}
        .system-runtime-warning{margin-block:var(--foodex-space-3)}
        details.system-runtime-restore{min-width:260px}
        details.system-runtime-restore summary{cursor:pointer;font-weight:700}
        @media(max-width:1023px){.system-runtime-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.system-runtime-panels{grid-template-columns:minmax(0,1fr)}}
        @media(max-width:767px){.system-runtime-grid{grid-template-columns:minmax(0,1fr)}.system-runtime-card{padding:var(--foodex-space-3)}.system-runtime-chart-row{grid-template-columns:minmax(0,1fr)}.system-runtime-actions>*{width:100%}}
    </style>
</head>
<body>
@php
    $statusLabel = static fn(string $state): string => match($state) {
        'healthy' => __('admin.system_update_surface.healthy'),
        'success', 'succeeded', 'completed' => __('admin.system_update_surface.completed'),
        'warning' => __('admin.system_update_surface.warning'),
        'pending', 'queued' => __('admin.system_update_surface.pending_state'),
        'running', 'in_progress' => __('admin.system_update_surface.running_state'),
        'error' => __('admin.system_update_surface.error'),
        'failed' => __('admin.system_update_surface.failed_state'),
        'rolled_back' => __('admin.system_update_surface.rolled_back_state'),
        'disabled' => __('admin.system_update_surface.disabled'),
        default => __('admin.system_update_surface.unknown'),
    };
    $lastBackup = $runtimeHealth['last_backup'] ?? null;
    $backupAgeDays = null;
    if (is_array($lastBackup) && !empty($lastBackup['created_at'])) {
        try { $backupAgeDays = max(0, (int) now()->diffInDays(\Carbon\CarbonImmutable::parse($lastBackup['created_at']))); } catch (\Throwable) { $backupAgeDays = null; }
    }
    $statusTotal = max(1, array_sum($updateStatusCounts));
    $databaseBackups = count(array_filter($backupRows, static fn(array $row): bool => $row['kind'] === 'database'));
    $fileBackups = count(array_filter($backupRows, static fn(array $row): bool => $row['kind'] === 'files'));
    $availableBackups = count(array_filter($backupRows, static fn(array $row): bool => $row['status'] === 'available'));
    $backupTotal = max(1, count($backupRows));
    $runtimeCode = (string)($runtimeHealth['state'] ?? 'unknown');
    $runtimeHealthLabel = $statusLabel($runtimeCode);
    $runtimeBadgeState = match($runtimeCode) {
        'healthy' => 'success',
        'warning' => 'warning',
        default => 'error',
    };
@endphp
<div class="foodex-admin-layout" data-foodex-mobile-web-page="system-update">
<aside class="sidebar">
    @include('admin._sidebar', [
        'navGroups' => app(\App\Support\AdminNavigation::class)->groupsFor(auth()->user()),
        'navContext' => 'administration_hub',
        'user' => auth()->user(),
    ])
</aside>
<main class="foodex-admin-main foodex-admin-page foodex-settings-surface foodex-module-overview" data-foodex-utility="system-update" data-foodex-module-overview>
@include('admin._global-header')
    @include('admin._admin-center-switcher',['active'=>'settings'])

    <div class="foodex-page-toolbar">
        <div>
            <a href="{{ route('admin.settings.index') }}">← {{ __('admin.system_update_surface.back_settings') }}</a>
            <span class="foodex-subtitle">FOODEX · {{ __('admin.system_update_surface.dashboard_title') }}</span>
            <h1>{{ __('admin.system_update_surface.title') }}</h1>
            <p>{{ __('admin.system_update_surface.dashboard_description') }}</p>
        </div>
        <div class="system-runtime-actions">
            <span class="badge">{{ __('admin.system_update_surface.current') }}: {{ $currentVersion }}</span>
            <a class="foodex-action-secondary button secondary" href="{{ route('admin.inspector.index') }}">{{ __('admin.system_update_surface.open_inspector') }}</a>
        </div>
    </div>

    @if (session('status'))
        <div class="foodex-alert success foodex-state" role="status">{{ session('status') }}</div>
    @endif
    @if ($errors->any())
        <div class="foodex-alert error foodex-state" role="alert">
            <strong>{{ __('admin.system_update_surface.not_applied') }}</strong>
            <ul>@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
        </div>
    @endif

    <section aria-labelledby="runtime-health-title">
        <div class="foodex-viz-header">
            <div>
                <h2 id="runtime-health-title" class="foodex-viz-title">{{ __('admin.system_update_surface.dashboard_title') }}</h2>
                <p class="foodex-viz-subtitle">{{ __('admin.system_update_surface.observed_at') }}: {{ $runtimeHealth['observed_at'] ?? '—' }}</p>
            </div>
            <span class="foodex-status-badge" data-state="{{ $runtimeBadgeState }}">{{ $runtimeHealthLabel }}</span>
        </div>
        <div class="system-runtime-grid foodex-module-kpis">
            @php($runtimeMetrics = [
                ['label'=>__('admin.system_update_surface.database_health'),'value'=>$statusLabel((string)($runtimeHealth['database']['state'] ?? 'unknown')),'url'=>route('admin.inspector.index').'#system-runtime'],
                ['label'=>__('admin.system_update_surface.cache_health'),'value'=>$statusLabel((string)($runtimeHealth['cache']['state'] ?? 'unknown')),'url'=>route('admin.inspector.index').'#system-runtime'],
                ['label'=>__('admin.system_update_surface.failed_jobs'),'value'=>$runtimeHealth['queue']['failed_jobs'] ?? '—','url'=>'#failed-jobs'],
                ['label'=>__('admin.system_update_surface.scheduler_health'),'value'=>$statusLabel((string)($runtimeHealth['scheduler']['state'] ?? 'unknown')),'url'=>route('admin.inspector.index').'#system-runtime'],
                ['label'=>__('admin.system_update_surface.pending_migrations'),'value'=>$runtimeHealth['pending_migrations'] ?? '—','url'=>route('admin.inspector.index').'#system-runtime'],
                ['label'=>__('admin.system_update_surface.storage_health'),'value'=>$statusLabel((string)($runtimeHealth['storage']['state'] ?? 'unknown')),'url'=>route('admin.inspector.index').'#system-runtime'],
                ['label'=>__('admin.system_update_surface.config_warnings'),'value'=>count($runtimeHealth['configuration_warnings'] ?? []),'url'=>route('admin.inspector.index').'#system-runtime'],
                ['label'=>__('admin.system_update_surface.last_backup'),'value'=>is_array($lastBackup) ? ($lastBackup['reference'] ?? __('admin.system_update_surface.not_available')) : __('admin.system_update_surface.not_available'),'url'=>'#backup-center'],
                ['label'=>__('admin.system_update_surface.backup_age'),'value'=>$backupAgeDays === null ? __('admin.system_update_surface.not_available') : $backupAgeDays.' '.__('admin.system_update_surface.days'),'url'=>'#backup-center'],
            ])
            @foreach($runtimeMetrics as $metric)
                <a class="foodex-viz-card foodex-module-kpi" data-foodex-module-kpi data-interactive="true" href="{{ $metric['url'] }}">
                    <span class="foodex-module-kpi-label">{{ $metric['label'] }}</span>
                    <strong class="foodex-module-kpi-value foodex-number">{{ $metric['value'] }}</strong>
                </a>
            @endforeach
        </div>
    </section>

    <section class="system-runtime-panels foodex-module-panels">
        <article class="foodex-viz-card foodex-module-chart" data-foodex-module-chart>
            <div class="foodex-viz-header"><div><h2 class="foodex-viz-title"><a href="#update-history">{{ __('admin.system_update_surface.status_distribution') }}</a></h2><p class="foodex-viz-subtitle">{{ __('admin.system_update_surface.status_distribution_description') }}</p></div></div>
            @forelse($updateStatusCounts as $status => $count)
                @php($percent = max(2, min(100, (int) round(($count / $statusTotal) * 100))))
                <div class="system-runtime-chart-row">
                    <span>{{ $statusLabel((string)$status) }}</span>
                    <div class="foodex-viz-progress" role="img" aria-label="{{ $statusLabel((string)$status) }} {{ $count }}"><span style="width:{{ $percent }}%"></span></div>
                    <strong class="foodex-number">{{ $count }}</strong>
                </div>
            @empty
                @include('admin._module-dashboard-state',['state'=>'empty'])
            @endforelse
            <div class="foodex-module-chart-summary">{{ __('admin.system_update_surface.history') }}</div>
        </article>

        <article class="foodex-viz-card foodex-module-chart" data-foodex-module-chart>
            <div class="foodex-viz-header"><div><h2 class="foodex-viz-title"><a href="#backup-center">{{ __('admin.system_update_surface.backup_distribution') }}</a></h2><p class="foodex-viz-subtitle">{{ __('admin.system_update_surface.backup_distribution_description') }}</p></div></div>
            @foreach([
                __('admin.system_update_surface.database') => $databaseBackups,
                __('admin.system_update_surface.release_files') => $fileBackups,
                __('admin.system_update_surface.available') => $availableBackups,
            ] as $label => $count)
                @php($percent = max(2, min(100, (int) round(($count / $backupTotal) * 100))))
                <div class="system-runtime-chart-row">
                    <span>{{ $label }}</span>
                    <div class="foodex-viz-progress" role="img" aria-label="{{ $label }} {{ $count }}"><span style="width:{{ $percent }}%"></span></div>
                    <strong class="foodex-number">{{ $count }}</strong>
                </div>
            @endforeach
            <div class="foodex-module-chart-summary">{{ __('admin.system_update_surface.backup_center') }}</div>
        </article>
    </section>

    <section id="failed-jobs" class="foodex-card system-runtime-card" data-system-failed-jobs>
        <div class="foodex-viz-header">
            <div><h2 class="foodex-viz-title">{{ __('admin.system_update_surface.failed_jobs_title') }}</h2><p class="foodex-viz-subtitle">{{ __('admin.system_update_surface.failed_jobs_description') }}</p></div>
            <span class="badge">{{ __('admin.system_update_surface.failed_jobs') }} · 24h: <span class="foodex-number">{{ $runtimeHealth['queue']['failed_jobs_24h'] ?? 0 }}</span></span>
        </div>
        <div class="system-runtime-table-wrap">
            <table class="foodex-table system-runtime-table" data-pagination-exempt="bounded-50-failed-job-diagnostics">
                <thead><tr>
                    <th>ID</th>
                    <th>{{ __('admin.system_update_surface.connection') }}</th>
                    <th>{{ __('admin.system_update_surface.queue') }}</th>
                    <th>{{ __('admin.system_update_surface.failure_fingerprint') }}</th>
                    <th>{{ __('admin.system_update_surface.failed_at') }}</th>
                </tr></thead>
                <tbody>
                @forelse(($runtimeHealth['queue']['recent_failures'] ?? []) as $job)
                    <tr>
                        <td class="foodex-number">{{ $job['id'] }}</td>
                        <td><code>{{ $job['connection'] ?: '—' }}</code></td>
                        <td><code>{{ $job['queue'] ?: '—' }}</code></td>
                        <td><code>{{ $job['failure_fingerprint'] ?: '—' }}</code></td>
                        <td>{{ $job['failed_at'] ?: '—' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5">{{ __('admin.system_update_surface.no_failed_jobs') }}</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </section>

    <section id="backup-center" class="foodex-card system-runtime-card" data-system-backup-center>
        <div class="foodex-viz-header">
            <div><h2 class="foodex-viz-title">{{ __('admin.system_update_surface.backup_center') }}</h2><p class="foodex-viz-subtitle">{{ __('admin.system_update_surface.backup_description') }}</p></div>
        </div>
        <form class="system-runtime-form" method="post" action="{{ route('admin.system-update.backups.store') }}" data-mutation-lock>
            @csrf
            <label>{{ __('admin.system_update_surface.backup_reason') }}
                <textarea name="reason" rows="2" minlength="5" maxlength="500" required placeholder="{{ __('admin.system_update_surface.backup_reason_placeholder') }}"></textarea>
            </label>
            <button class="foodex-primary" type="submit">{{ __('admin.system_update_surface.create_backup') }}</button>
        </form>

        <div class="system-runtime-table-wrap" style="margin-top:var(--foodex-space-4)">
            <table class="foodex-table system-runtime-table" data-pagination-exempt="bounded-50-recovery-inventory">
                <thead><tr>
                    <th>{{ __('admin.system_update_surface.backup_reference') }}</th>
                    <th>{{ __('admin.system_update_surface.backup_type') }}</th>
                    <th>{{ __('admin.system_update_surface.backup_scope') }}</th>
                    <th>{{ __('admin.system_update_surface.backup_created_at') }}</th>
                    <th>{{ __('admin.system_update_surface.backup_size') }}</th>
                    <th>{{ __('admin.system_update_surface.backup_integrity') }}</th>
                    <th>{{ __('admin.system_update_surface.backup_status') }}</th>
                    <th>{{ __('admin.system_update_surface.backup_retention') }}</th>
                    <th>{{ __('admin.system_update_surface.backup_origin') }}</th>
                    <th>{{ __('admin.system_update_surface.restore') }}</th>
                </tr></thead>
                <tbody>
                @forelse($backupRows as $backup)
                    <tr>
                        <td><code class="system-runtime-code">{{ $backup['reference'] }}</code></td>
                        <td>{{ $backup['kind']==='database' ? __('admin.system_update_surface.database') : __('admin.system_update_surface.release_files') }}</td>
                        <td><code>{{ $backup['scope'] }}</code></td>
                        <td>{{ $backup['created_at'] ?: '—' }}</td>
                        <td class="foodex-number">{{ number_format((int)$backup['size_bytes']) }} {{ __('admin.system_update_surface.bytes') }}</td>
                        <td><code class="system-runtime-code">{{ $backup['integrity_reference'] ? substr($backup['integrity_reference'],0,16).'…' : '—' }}</code></td>
                        <td>{{ $backup['status']==='available' ? __('admin.system_update_surface.available') : __('admin.system_update_surface.incomplete') }}</td>
                        <td>{{ $backup['retention_until'] ?: '—' }}</td>
                        <td>{{ $backup['origin']==='manual_backup' ? __('admin.system_update_surface.manual_backup') : __('admin.system_update_surface.system_update_origin') }}</td>
                        <td>
                            @if($backup['kind']==='database' && $backup['status']==='available')
                            <details class="system-runtime-restore">
                                <summary>{{ __('admin.system_update_surface.restore') }}</summary>
                                <div class="foodex-alert warning system-runtime-warning">{{ __('admin.system_update_surface.restore_warning') }}</div>
                                <form class="system-runtime-form" method="post" action="{{ route('admin.system-update.backups.restore',['backupReference'=>$backup['reference']]) }}" data-mutation-lock>
                                    @csrf
                                    <label>{{ __('admin.system_update_surface.restore_reason') }}<textarea name="reason" rows="2" minlength="5" maxlength="500" required></textarea></label>
                                    <label>{{ __('admin.system_update_surface.confirm_reference') }}<input name="confirm_reference" autocomplete="off" required></label>
                                    @if($isProduction)
                                    <label>{{ __('admin.system_update_surface.production_confirmation') }}
                                        <input name="production_confirmation" autocomplete="off" required>
                                        <small>{{ __('admin.system_update_surface.production_phrase_help') }}</small>
                                    </label>
                                    @endif
                                    <button class="button danger" type="submit">{{ __('admin.system_update_surface.restore') }}</button>
                                </form>
                            </details>
                            @else
                                —
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="10">
                            @include('admin._module-dashboard-state',[
                                'state'=>'empty',
                                'message'=>__('admin.system_update_surface.no_backups'),
                            ])
                        </td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </section>

    <section class="foodex-card system-runtime-card">
        <h2>{{ __('admin.system_update_surface.package_title') }}</h2>
        <p>{{ __('admin.system_update_surface.package_description') }}</p>
        <form class="system-runtime-form" method="post" action="{{ route('admin.system-update.store') }}" enctype="multipart/form-data" data-csrf-refresh-url="{{ route('admin.csrf-token') }}" data-mutation-lock>
            @csrf
            <label>{{ __('admin.system_update_surface.zip_package') }}<input type="file" name="package" accept=".zip,application/zip" required></label>
            <label>{{ __('admin.system_update_surface.target_version') }}<input name="target_version" placeholder="1.2.0" required></label>
            <label>{{ __('admin.system_update_surface.minimum_current_version') }}<input name="minimum_current_version" value="{{ $currentVersion }}" required></label>
            <label>{{ __('admin.system_update_surface.sha256') }}<input name="sha256" maxlength="64" minlength="64" required></label>
            <label>{{ __('admin.system_update_surface.release_notes') }}<textarea name="release_notes" rows="4"></textarea></label>
            <div class="foodex-alert info">{{ __('admin.system_update_surface.migration_notice') }}</div>
            <button class="foodex-primary" type="submit">{{ __('admin.system_update_surface.install') }}</button>
        </form>
    </section>

    <section id="update-history" class="foodex-card system-runtime-card">
        <h2>{{ __('admin.system_update_surface.history') }}</h2>
        <div class="system-runtime-table-wrap">
            <table class="foodex-table system-runtime-table" data-pagination-required>
                <thead><tr><th>{{ __('admin.system_update_surface.from') }}</th><th>{{ __('admin.system_update_surface.to') }}</th><th>{{ __('admin.system_update_surface.status') }}</th><th>{{ __('admin.system_update_surface.started') }}</th><th>{{ __('admin.system_update_surface.failure') }}</th></tr></thead>
                <tbody>
                @forelse ($historyRows as $row)
                    <tr>
                        <td>{{ $row->from_version ?: '—' }}</td>
                        <td>{{ $row->to_version }}</td>
                        @php($localizedUpdateState = $statusLabel(strtolower((string)$row->status)))
                        <td>{{ $localizedUpdateState }}</td>
                        <td>{{ $row->started_at ?: '—' }}</td>
                        <td>{{ $row->failure_reason ?: '—' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5">{{ __('admin.system_update_surface.empty') }}</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        {{ $historyRows->links() }}
    </section>

    <section class="foodex-card system-runtime-card">
        <div class="foodex-viz-header"><div><h2 class="foodex-viz-title">{{ __('admin.system_update_surface.recovery_log') }}</h2><p class="foodex-viz-subtitle">{{ __('admin.system_update_surface.recovery_log_description') }}</p></div></div>
        <div class="system-runtime-table-wrap">
            <table class="foodex-table system-runtime-table" data-pagination-exempt="bounded-50-runtime-audit">
                <thead><tr><th>{{ __('admin.system_update_surface.operation') }}</th><th>{{ __('admin.system_update_surface.actor') }}</th><th>{{ __('admin.system_update_surface.environment') }}</th><th>{{ __('admin.system_update_surface.source_target') }}</th><th>{{ __('admin.system_update_surface.reason') }}</th><th>{{ __('admin.system_update_surface.backup_references') }}</th><th>{{ __('admin.system_update_surface.stage') }}</th><th>{{ __('admin.system_update_surface.outcome') }}</th><th>{{ __('admin.system_update_surface.started') }}</th></tr></thead>
                <tbody>
                @forelse($recoveryOperations as $operation)
                    @php($evidence = array_merge($operation['before'] ?? [], $operation['after'] ?? []))
                    <tr>
                        <td><code>{{ $operation['event'] }}</code></td>
                        <td>{{ $operation['actor'] ?: '—' }}</td>
                        <td>{{ $evidence['environment'] ?? '—' }}</td>
                        <td><code>{{ $evidence['source_version'] ?? '—' }} → {{ $evidence['target_version'] ?? '—' }}</code></td>
                        <td>{{ $evidence['reason'] ?? '—' }}</td>
                        <td>
                            @php($backupEvidence = array_values(array_filter([
                                $evidence['reference'] ?? null,
                                $evidence['database_backup_reference'] ?? null,
                                $evidence['file_backup_reference'] ?? null,
                            ])))
                            @forelse($backupEvidence as $backupReference)
                                <code class="system-runtime-code">{{ $backupReference }}</code>
                                @if(! $loop->last)
                                    <br>
                                @endif
                            @empty
                                —
                            @endforelse
                        </td>
                        <td><code>{{ $evidence['stage'] ?? '—' }}</code></td>
                        <td>{{ $evidence['outcome'] ?? '—' }}</td>
                        <td>{{ $operation['created_at'] ?? '—' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="9">{{ __('admin.system_update_surface.no_recovery_operations') }}</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </section>
</main>
</div>
<script>
(() => {
    document.querySelectorAll('form[data-mutation-lock]').forEach((form) => {
        form.addEventListener('submit', () => {
            form.querySelectorAll('button[type="submit"],input[type="submit"]').forEach((control) => {
                control.disabled = true;
                control.setAttribute('aria-busy', 'true');
            });
        });
    });

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
