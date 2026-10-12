<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>{{ app()->getLocale() === 'ar' ? 'تحديث النظام' : 'System Update' }} · FOODEX</title>
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
@php($ar = app()->getLocale() === 'ar')
<div class="foodex-admin-layout" data-foodex-mobile-web-page="system-update">
<aside class="sidebar">@include('admin._sidebar', ['navGroups' => app(\App\Support\AdminNavigation::class)->groupsFor(auth()->user()), 'navContext' => 'administration_hub', 'user' => auth()->user()])</aside> {{-- localization-gate: allow Blade include expression --}}
<main class="foodex-admin-main foodex-admin-page foodex-settings-surface" data-foodex-utility="system-update">
    @include('admin._admin-center-switcher',['active'=>'settings'])
<div class="header foodex-page-header">
        <div>
            <a href="{{ route('admin.settings.index') }}">← {{ app()->getLocale()==='ar' ? 'مركز الإعدادات' : 'Settings Center' }}</a>
            <h1>{{ $ar ? 'تحديث النظام' : 'System Update' }}</h1>
        </div>
        <span class="badge">{{ $ar ? 'الإصدار الحالي' : 'Current' }}: {{ $currentVersion }}</span>
    </div>

    @if (session('status'))
        <div class="notice foodex-state" role="status">{{ session('status') }}</div>
    @endif

    @if ($errors->any())
        <div class="error foodex-state" role="alert">
            <strong>{{ $ar ? 'لم يتم تطبيق التحديث.' : 'Update was not applied.' }}</strong>
            <ul>
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <section class="card">
        <h2>{{ $ar ? 'حزمة تحديث موثقة' : 'Validated update package' }}</h2>
        <p>{{ $ar ? 'يتم التحقق من الحزمة وإنشاء نسخة احتياطية قبل بدء وضع الصيانة أو استخراج الإصدار.' : 'The package is verified and backed up before maintenance mode or release extraction begins.' }}</p>

        <form method="post" action="{{ route('admin.system-update.store') }}" enctype="multipart/form-data" data-csrf-refresh-url="{{ route('admin.csrf-token') }}">
            @csrf
            <label>{{ $ar ? 'حزمة ZIP' : 'ZIP package' }}
                <input type="file" name="package" accept=".zip,application/zip" required>
            </label>
            <label>{{ $ar ? 'الإصدار المستهدف' : 'Target version' }}
                <input name="target_version" placeholder="1.2.0" required>
            </label>
            <label>{{ $ar ? 'أقل إصدار حالي مسموح' : 'Minimum current version' }}
                <input name="minimum_current_version" value="{{ $currentVersion }}" required>
            </label>
            <label>{{ $ar ? 'قيمة SHA-256 المتوقعة' : 'Expected SHA-256' }}
                <input name="sha256" maxlength="64" minlength="64" required>
            </label>
            <label>{{ $ar ? 'ملاحظات الإصدار' : 'Release notes' }}
                <textarea name="release_notes" rows="4"></textarea>
            </label>
            <div class="notice">
                {{ $ar ? 'يتم اكتشاف ترحيلات قاعدة البيانات تلقائيًا من حزمة التحديث المرفوعة، ولا يلزم اختيار يدوي.' : 'Database migrations are detected automatically from the uploaded update package. No manual migration checkbox is required.' }}
            </div>
            <button class="foodex-primary" type="submit">{{ $ar ? 'تحقق وثبّت التحديث' : 'Validate and install update' }}</button>
        </form>
    </section>

    <section class="card">
        <h2>{{ $ar ? 'سجل التحديثات الأخيرة' : 'Recent update history' }}</h2>
        <table data-pagination-required>
            <thead>
            <tr><th>{{ $ar?'من':'From' }}</th><th>{{ $ar?'إلى':'To' }}</th><th>{{ $ar?'الحالة':'Status' }}</th><th>{{ $ar?'بدأ':'Started' }}</th><th>{{ $ar?'سبب الفشل':'Failure' }}</th></tr>
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
                <tr><td colspan="5">{{ $ar ? 'لم يتم تنفيذ أي تحديثات بعد.' : 'No updates have been executed yet.' }}</td></tr>
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
