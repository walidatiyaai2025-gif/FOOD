<!doctype html>
<html lang="{{ str_replace('_','-',app()->getLocale()) }}" dir="{{ app()->getLocale()==='ar'?'rtl':'ltr' }}">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>{{ __('admin.system_inspector') }} · FOODEX</title>
@include('admin._brand-components')
<style>
.inspector-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:var(--foodex-space-4);margin-bottom:var(--foodex-space-5)}
.inspector-stat{padding:var(--foodex-space-5)}.inspector-stat strong{display:block;color:var(--foodex-muted);font-size:var(--foodex-text-xs)}.inspector-stat b{display:block;margin-top:6px;font-size:1.8rem}
.inspector-toolbar{display:flex;flex-wrap:wrap;gap:var(--foodex-space-3);align-items:end;padding:var(--foodex-space-4);margin-bottom:var(--foodex-space-4)}
.inspector-toolbar label{display:grid;gap:6px;font-weight:700}.inspector-toolbar input,.inspector-toolbar select{min-width:170px}
.inspector-health{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:var(--foodex-space-3);padding:var(--foodex-space-5);margin-bottom:var(--foodex-space-5)}
.inspector-health-row{display:flex;justify-content:space-between;gap:12px;padding:10px 12px;border:1px solid var(--foodex-border);border-radius:var(--foodex-radius-control);background:#fbfcfd}
.inspector-health-row code{font-family:var(--foodex-font-en);font-size:.78rem;overflow-wrap:anywhere;text-align:end}
.inspector-table-wrap{overflow:auto}.inspector-table{min-width:1180px}.severity-error{color:var(--foodex-red);font-weight:700}.severity-warning{color:#9a4b0b;font-weight:700}
.inspector-message{max-width:420px;white-space:normal;overflow-wrap:anywhere}.inspector-context{max-width:560px;white-space:pre-wrap;overflow-wrap:anywhere;font:12px/1.55 ui-monospace,SFMono-Regular,Consolas,monospace}
.inspector-actions{display:flex;gap:var(--foodex-space-2);flex-wrap:wrap}
@media(max-width:900px){.inspector-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.inspector-health{grid-template-columns:1fr}}
@media(max-width:560px){.inspector-grid{grid-template-columns:1fr}}
</style>
</head>
<body>
<div class="foodex-admin-layout">
    <aside class="sidebar">@include('admin._sidebar')</aside>
    <main class="foodex-admin-main foodex-admin-page">
        @php($ar=app()->getLocale()==='ar')
        <header class="foodex-page-header">
            <div>
                <span class="foodex-subtitle">FOODEX · Runtime Diagnostics</span>
                <h1>{{ __('admin.system_inspector') }}</h1>
                <p>{{ $ar?'يجمع أخطاء الخادم والراوت وJavaScript وطلبات Fetch، ويصدر تقريرًا واحدًا قابلًا للتنزيل والمشاركة.':'Captures server, route, JavaScript and Fetch failures and exports one shareable diagnostic report.' }}</p>
            </div>
            <div class="inspector-actions">
                @include('admin._live-notifications',['user'=>auth()->user()])
                <a class="foodex-action-primary" href="{{ route('admin.inspector.export') }}">⇩ {{ $ar?'تنزيل التقرير':'Download report' }}</a>
                <form method="post" action="{{ route('admin.inspector.storage-link') }}">@csrf
                    <button class="foodex-action-secondary button secondary" type="submit">↻ {{ $ar?'فحص/إصلاح رابط الصور':'Check / repair image link' }}</button>
                </form>
            </div>
        </header>

        <section class="inspector-grid">
            <article class="foodex-card inspector-stat"><strong>{{ $ar?'إجمالي الأحداث':'Total events' }}</strong><b class="foodex-number">{{ $stats['total'] }}</b></article>
            <article class="foodex-card inspector-stat"><strong>{{ $ar?'أخطاء آخر 24 ساعة':'Errors · 24h' }}</strong><b class="foodex-number">{{ $stats['errors_24h'] }}</b></article>
            <article class="foodex-card inspector-stat"><strong>{{ $ar?'JavaScript / Fetch · 24 ساعة':'JavaScript / Fetch · 24h' }}</strong><b class="foodex-number">{{ $stats['javascript_24h'] }}</b></article>
            <article class="foodex-card inspector-stat"><strong>{{ $ar?'تطبيقات الموبايل · 24 ساعة':'Mobile apps · 24h' }}</strong><b class="foodex-number">{{ $stats['mobile_24h'] }}</b></article>
            <article class="foodex-card inspector-stat"><strong>{{ $ar?'أخطاء الراوت · 24 ساعة':'Route errors · 24h' }}</strong><b class="foodex-number">{{ $stats['routes_24h'] }}</b></article>
        </section>

        <section class="foodex-card inspector-health">
            <div class="inspector-health-row"><strong>{{ $ar?'الإصدار':'Version' }}</strong><code>{{ $diagnostics['version'] }}</code></div>
            <div class="inspector-health-row"><strong>APP_URL</strong><code>{{ $diagnostics['app_url'] }}</code></div>
            <div class="inspector-health-row"><strong>{{ $ar?'مجلد الصور قابل للكتابة':'Public image storage writable' }}</strong><span class="badge {{ $diagnostics['public_disk_writable']?'active':'' }}">{{ $diagnostics['public_disk_writable']?($ar?'نعم':'Yes'):($ar?'لا':'No') }}</span></div>
            <div class="inspector-health-row"><strong>{{ $ar?'رابط public/storage':'public/storage link' }}</strong><span class="badge {{ $diagnostics['public_link_exists']?'active':'' }}">{{ $diagnostics['public_link_exists']?($ar?'سليم':'Ready'):($ar?'مفقود':'Missing') }}</span></div>
            <div class="inspector-health-row"><strong>{{ $ar?'مسار التخزين':'Storage root' }}</strong><code>{{ $diagnostics['public_disk_root'] }}</code></div>
            <div class="inspector-health-row"><strong>{{ $ar?'الرابط العام للصور':'Public media URL' }}</strong><code>{{ $diagnostics['public_disk_url'] }}</code></div>
            <div class="inspector-health-row"><strong>{{ $ar?'المسارات المسماة':'Named routes' }}</strong><code>{{ $diagnostics['named_routes'] }}</code></div>
            <div class="inspector-health-row"><strong>{{ $ar?'البيئة':'Environment' }}</strong><code>{{ $diagnostics['environment'] }}</code></div>
        </section>

        <form class="foodex-card inspector-toolbar" method="get" action="{{ route('admin.inspector.index') }}">
            <label>{{ $ar?'المصدر':'Source' }}
                <select name="source"><option value="">{{ $ar?'الكل':'All' }}</option>
                    @foreach(['dashboard','server','api','route','javascript','fetch','customer_app','driver_app'] as $item)<option value="{{ $item }}" @selected($source===$item)>{{ $item }}</option>@endforeach
                </select>
            </label>
            <label>{{ $ar?'الخطورة':'Severity' }}
                <select name="severity"><option value="">{{ $ar?'الكل':'All' }}</option><option value="error" @selected($severity==='error')>error</option><option value="warning" @selected($severity==='warning')>warning</option></select>
            </label>
            <label>{{ $ar?'إصدار التطبيق':'App version' }}<input name="app_version" value="{{ $appVersion }}" placeholder="1.0.53"></label>
            <label>Build<input name="app_build" value="{{ $appBuild }}" placeholder="53"></label>
            <label>{{ $ar?'القناة':'Channel' }}
                <select name="channel"><option value="">{{ $ar?'الكل':'All' }}</option><option value="b2b" @selected($channel==='b2b')>B2B</option><option value="b2c" @selected($channel==='b2c')>B2C</option></select>
            </label>
            <label>{{ __('admin.system_inspector_surface.store') }}
                <select name="store_id"><option value="">{{ __('admin.system_inspector_surface.all_stores') }}</option>@foreach($stores as $store)<option value="{{ $store->id }}" @selected($storeId===(int)$store->id)>{{ $store->name }} · {{ $store->code }}</option>@endforeach</select>
            </label>
            <label>{{ $ar?'بحث':'Search' }}<input name="q" value="{{ $search }}" placeholder="{{ $ar?'الرسالة أو الراوت أو Correlation ID':'Message, route or correlation ID' }}"></label>
            <button class="foodex-action-primary foodex-filter-action" type="submit">{{ $ar?'تصفية':'Filter' }}</button>
        </form>

        <section class="foodex-card inspector-table-wrap">
            <table class="foodex-table inspector-table">
                <thead><tr>
                    <th>{{ $ar?'الوقت':'Time' }}</th><th>{{ $ar?'المصدر':'Source' }}</th><th>{{ $ar?'الخطورة':'Severity' }}</th><th>HTTP</th>
                    <th>{{ $ar?'الراوت / الرابط':'Route / URL' }}</th><th>{{ $ar?'الرسالة':'Message' }}</th><th>{{ $ar?'المستخدم / المتجر':'User / Store' }}</th><th>{{ $ar?'التفاصيل':'Context' }}</th>
                </tr></thead>
                <tbody>
                @forelse($events as $event)
                    <tr>
                        <td class="foodex-number">{{ $event->occurred_at?->format('Y-m-d H:i:s') }}</td>
                        <td><span class="badge">{{ $event->source }}</span></td>
                        <td class="severity-{{ $event->severity }}">{{ $event->severity }}</td>
                        <td class="foodex-number">{{ $event->status_code ?: '—' }} @if($event->method)<small>{{ $event->method }}</small>@endif</td>
                        <td><strong>{{ $event->route_name ?: '—' }}</strong><div class="foodex-subtitle">{{ $event->url }}</div>@if($event->correlation_id)<div class="foodex-subtitle">CID: {{ $event->correlation_id }}</div>@endif</td>
                        <td class="inspector-message">{{ $event->message }}@if($event->exception_class)<div class="foodex-subtitle">{{ $event->exception_class }}</div>@endif</td>
                        <td>@if($event->user){{ $event->user->name }}<div class="foodex-subtitle">{{ $event->user->email }}</div>@else—@endif @if($event->store)<div class="foodex-subtitle">{{ $event->store->name }} · {{ $event->store->code }}</div>@endif</td>
                        <td>@if($event->context)<details><summary>{{ $ar?'عرض':'View' }}</summary><pre class="inspector-context">{{ json_encode($event->context,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) }}</pre></details>@else—@endif</td>
                    </tr>
                @empty
                    <tr><td colspan="8"><div class="foodex-empty-state">{{ $ar?'لا توجد أخطاء مسجلة بهذه الفلاتر.':'No recorded errors match these filters.' }}</div></td></tr>
                @endforelse
                </tbody>
            </table>
        </section>
        {{ $events->links() }}
    </main>
</div>
</body></html>
