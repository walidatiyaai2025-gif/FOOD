<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>System Update · FOODEX</title>
    <style>
        :root { font-family: Inter, system-ui, sans-serif; color: #17202a; background: #f5f7fa; }
        * { box-sizing: border-box; }
        body { margin: 0; padding: 32px; }
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
    </style>
    @include('admin._brand-components')
</head>
<body>
<main class="foodex-admin-page" data-foodex-utility="system-update">
    <div class="header foodex-page-header">
        <div>
            <a href="{{ route('admin.index') }}">← FOODEX Dashboard</a>
            <h1>System Update</h1>
        </div>
        <span class="badge">Current: {{ $currentVersion }}</span>
    </div>

    @if (session('status'))
        <div class="notice foodex-state" role="status">{{ session('status') }}</div>
    @endif

    @if ($errors->any())
        <div class="error foodex-state" role="alert">
            <strong>Update was not applied.</strong>
            <ul>
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <section class="card">
        <h2>Validated update package</h2>
        <p>The package is verified and backed up before maintenance mode or release extraction begins.</p>

        <form method="post" action="{{ route('admin.system-update.store') }}" enctype="multipart/form-data">
            @csrf
            <label>ZIP package
                <input type="file" name="package" accept=".zip,application/zip" required>
            </label>
            <label>Target version
                <input name="target_version" placeholder="1.2.0" required>
            </label>
            <label>Minimum current version
                <input name="minimum_current_version" value="{{ $currentVersion }}" required>
            </label>
            <label>Expected SHA-256
                <input name="sha256" maxlength="64" minlength="64" required>
            </label>
            <label>Release notes
                <textarea name="release_notes" rows="4"></textarea>
            </label>
            <label class="checkbox">
                <input type="checkbox" name="contains_migrations" value="1">
                Package contains database migrations
            </label>
            <button class="foodex-primary" type="submit">Validate and install update</button>
        </form>
    </section>

    <section class="card">
        <h2>Recent update history</h2>
        <table>
            <thead>
            <tr><th>From</th><th>To</th><th>Status</th><th>Started</th><th>Failure</th></tr>
            </thead>
            <tbody>
            @forelse ($historyRows as $row)
                <tr>
                    <td>{{ $row->from_version ?: '—' }}</td>
                    <td>{{ $row->to_version }}</td>
                    <td>{{ $row->status }}</td>
                    <td>{{ $row->started_at ?: '—' }}</td>
                    <td>{{ $row->failure_reason ?: '—' }}</td>
                </tr>
            @empty
                <tr><td colspan="5">No updates have been executed yet.</td></tr>
            @endforelse
            </tbody>
        </table>
    </section>
</main>
</body>
</html>
