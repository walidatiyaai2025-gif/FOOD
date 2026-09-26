<!doctype html>
<html lang="{{ app()->getLocale() }}" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>{{ __('admin.translations.title') }} · FOODEX</title>
    <style>
        :root { color: #17202a; background: #f5f7fa; }
        * { box-sizing: border-box; }
        body { margin: 0; padding: 28px; background: #f5f7fa; }
        .wrap { max-width: 1500px; margin: 0 auto; }
        .top { display:flex; justify-content:space-between; gap:16px; align-items:flex-start; margin-bottom:20px; }
        .back { color:#1d4ed8; text-decoration:none; font-weight:700; }
        h1 { margin:0 0 8px; }
        .muted { color:#64748b; }
        .flash { background:#ecfdf5; border:1px solid #a7f3d0; padding:12px 16px; border-radius:12px; margin-bottom:16px; }
        .filters,.card { background:white; border:1px solid #e2e8f0; border-radius:16px; padding:16px; }
        .filters { display:grid; grid-template-columns:minmax(220px,1fr) 220px auto; gap:12px; margin-bottom:16px; }
        input,select,textarea,button { font:inherit; }
        input,select,textarea { width:100%; border:1px solid #cbd5e1; border-radius:10px; padding:10px 12px; background:white; }
        textarea { min-height:88px; resize:vertical; }
        button { border:0; border-radius:10px; padding:10px 14px; cursor:pointer; font-weight:700; }
        .primary { background:#111827; color:white; }
        .secondary { background:#eef2ff; color:#3730a3; }
        .grid { display:grid; gap:14px; }
        .card-head { display:flex; justify-content:space-between; gap:12px; align-items:center; margin-bottom:12px; }
        .key { font-family:ui-monospace,SFMono-Regular,Menlo,monospace; font-size:.9rem; overflow-wrap:anywhere; }
        .badge { background:#f1f5f9; border-radius:999px; padding:5px 9px; font-size:.75rem; }
        .langs { display:grid; grid-template-columns:1fr 1fr; gap:14px; }
        .actions { display:flex; gap:8px; margin-top:10px; }
        label { display:block; font-size:.8rem; font-weight:700; margin-bottom:6px; color:#475569; }
        .empty { text-align:center; padding:30px; color:#64748b; }
        .pagination { margin-top:18px; }
        @media(max-width:800px){ body{padding:16px}.filters{grid-template-columns:1fr}.langs{grid-template-columns:1fr}.top{flex-direction:column} }
    </style>
    @include('admin._brand-components')
</head>
<body>
<div class="wrap foodex-admin-page" data-foodex-utility="translations">
    <div class="top foodex-page-header">
        <div>
            <h1>{{ __('admin.translations.title') }}</h1>
            <p class="muted">{{ __('admin.translations.description') }}</p>
        </div>
        <div class="actions">
            @if(auth()->user()?->hasPermission('notifications.manage'))
                <a class="back" href="{{ route('admin.notifications.index') }}">{{ __('notifications.title') }}</a>
            @endif
            <a class="back" href="{{ route('admin.index') }}">← {{ __('admin.overview') }}</a>
        </div>
    </div>

    @if (session('status'))
        <div class="flash foodex-state" role="status">{{ session('status') }}</div>
    @endif

    <form class="filters" method="get" action="{{ route('admin.translations.index') }}">
        <input name="q" value="{{ $search }}" placeholder="{{ __('admin.translations.search') }}">
        <select name="surface">
            <option value="">{{ __('admin.translations.all_surfaces') }}</option>
            @foreach ($surfaces as $item)
                <option value="{{ $item }}" @selected($surface === $item)>{{ strtoupper($item) }}</option>
            @endforeach
        </select>
        <button class="primary" type="submit">{{ __('admin.translations.filter') }}</button>
    </form>

    <div class="grid">
        @forelse ($translations as $translation)
            <article class="card">
                <div class="card-head">
                    <strong class="key">{{ $translation->key }}</strong>
                    <span class="badge">{{ strtoupper($translation->surface) }}</span>
                </div>
                <form method="post" action="{{ route('admin.translations.update', $translation) }}">
                    @csrf
                    @method('PATCH')
                    <div class="langs">
                        <div>
                            <label>{{ __('admin.translations.arabic') }}</label>
                            <textarea name="ar" lang="ar" dir="rtl" required>{{ old('ar', $translation->ar) }}</textarea>
                        </div>
                        <div>
                            <label>{{ __('admin.translations.english') }}</label>
                            <textarea name="en" lang="en" dir="ltr" required>{{ old('en', $translation->en) }}</textarea>
                        </div>
                    </div>
                    <div class="actions">
                        <button class="primary" type="submit">{{ __('admin.translations.save') }}</button>
                    </div>
                </form>
                <form method="post" action="{{ route('admin.translations.reset', $translation) }}" style="margin-top:8px">
                    @csrf
                    <button class="secondary" type="submit">{{ __('admin.translations.reset') }}</button>
                </form>
            </article>
        @empty
            <div class="card empty">{{ __('admin.translations.empty') }}</div>
        @endforelse
    </div>

    <div class="pagination">{{ $translations->links() }}</div>
</div>
</body>
</html>
