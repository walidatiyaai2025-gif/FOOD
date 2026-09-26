<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
    <title>403 · FOODEX</title>
    @include('admin._brand')
    <style>:root{color:#0f172a;background:#f8fafc}body{margin:0;font-family:var(--foodex-font-ui);min-height:100vh;display:grid;place-items:center;padding:24px}.card{max-width:620px;background:#fff;border:1px solid #e2e8f0;border-radius:22px;padding:34px;box-shadow:0 20px 55px rgba(15,23,42,.08)}.code{font-weight:900;color:#64748b;letter-spacing:.12em}h1{margin:10px 0}.muted{color:#64748b;line-height:1.7}a{display:inline-block;margin-top:16px;padding:10px 14px;border-radius:11px;background:#0f172a;color:#fff;text-decoration:none}</style>
</head>
<body><main class="card"><div class="code">403 · FOODEX</div><h1>{{ app()->getLocale() === 'ar' ? 'لا توجد صلاحية للوصول' : 'Access denied' }}</h1><p class="muted">{{ app()->getLocale() === 'ar' ? 'حسابك مسجل، لكن الصلاحيات الحالية لا تسمح بفتح هذه الصفحة أو تنفيذ هذه العملية.' : 'Your account is authenticated, but its current permissions do not allow this page or operation.' }}</p><a href="{{ route('admin.index') }}">{{ app()->getLocale() === 'ar' ? 'العودة للوحة التحكم' : 'Back to dashboard' }}</a></main></body>
</html>
