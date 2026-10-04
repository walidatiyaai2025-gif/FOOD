<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>{{ app()->getLocale()==='ar' ? 'إعدادات مساعد FOODEX' : 'FOODEX Assistant Settings' }} · FOODEX</title>
@include('admin._brand-components')
<style>
body{margin:0;background:var(--foodex-background);color:var(--foodex-ink)}
.assistant-settings-card{max-width:760px;background:#fff;border:1px solid var(--foodex-border);border-radius:18px;padding:24px;box-shadow:var(--foodex-shadow-sm)}
.assistant-settings-grid{display:grid;gap:16px}
.assistant-status{display:flex;align-items:center;justify-content:space-between;gap:16px;padding:16px;border-radius:14px;background:var(--foodex-green-soft)}
.assistant-status strong{font-size:1.1rem}
.assistant-note{padding:14px;border:1px solid var(--foodex-border);border-radius:12px;background:var(--foodex-surface-subtle)}
.assistant-switch{display:flex;align-items:center;gap:10px;font-weight:800}
.assistant-switch input{width:22px;height:22px}
.assistant-actions{display:flex;gap:10px;align-items:center;flex-wrap:wrap}
.foodex-state{margin-bottom:16px}
</style>
</head>
<body>
@php($ar=app()->getLocale()==='ar')
<div class="foodex-admin-layout" data-foodex-utility="assistant-settings">
<aside class="sidebar">@include('admin._sidebar',['navGroups'=>$navGroups,'navContext'=>$navContext,'user'=>$user])</aside>
<main class="foodex-admin-main foodex-admin-page">
<header class="foodex-page-header">
<div>
<span class="foodex-subtitle">FOODEX Assistant V1</span>
<h1>{{ $ar?'إعدادات مساعد FOODEX':'FOODEX Assistant Settings' }}</h1>
<p>{{ $ar?'تحكم في التفعيل العام للمساعد التشغيلي مع بقاء الصلاحيات والقراءة فقط مفروضة من الخادم.':'Control global Assistant availability while server-side permissions and read-only enforcement remain authoritative.' }}</p>
</div>
@include('admin._live-notifications',['user'=>$user])
</header>

@if(session('status'))<div class="foodex-state" role="status">{{ session('status') }}</div>@endif
@if($errors->any())
<div class="foodex-state" role="alert">
<strong>{{ $ar?'تعذر حفظ الإعداد':'The setting could not be saved' }}</strong>
<ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
</div>
@endif

<section class="assistant-settings-card">
<div class="assistant-settings-grid">
<div class="assistant-status">
<div>
<div>{{ $ar?'الحالة الحالية':'Current status' }}</div>
<strong>{{ $assistantSettings['enabled'] ? ($ar?'مفعّل':'Enabled') : ($ar?'معطّل':'Disabled') }}</strong>
</div>
<span class="foodex-badge">
@if($assistantSettings['source']==='dashboard')
{{ $ar?'إعداد محفوظ':'Saved setting' }}
@elseif($assistantSettings['source']==='fail_closed')
{{ $ar?'تعطيل آمن':'Fail-closed' }}
@else
{{ $ar?'القيمة الآمنة الافتراضية':'Safe environment default' }}
@endif
</span>
</div>

<div class="assistant-note">
<strong>{{ $ar?'القراءة فقط إلزامية في الإصدار V1':'V1 is read-only by design' }}</strong>
<p>{{ $ar?'هذا المفتاح لا يمنح أي صلاحيات جديدة. يظل الوصول الفعلي مشروطًا بصلاحية assistant.use، ولا توجد إجراءات كتابة للمساعد.':'This switch grants no user access. Actual use still requires assistant.use, and Assistant V1 exposes no write actions.' }}</p>
<p>{{ $ar?'حالة القراءة فقط الحالية: ':'Current read-only enforcement: ' }}<strong>{{ $assistantSettings['read_only'] ? 'ON' : 'OFF — BLOCKED' }}</strong></p>
</div>

@if($canManage)
<form method="post" action="{{ route('admin.assistant-settings.update') }}" class="assistant-settings-grid">
@csrf
@method('PUT')
<input type="hidden" name="enabled" value="0">
<label class="assistant-switch">
<input type="checkbox" name="enabled" value="1" @checked($assistantSettings['configured_enabled']) @disabled(!$assistantSettings['read_only'])>
<span>{{ $ar?'تفعيل مساعد FOODEX على مستوى المنصة':'Enable FOODEX Assistant globally' }}</span>
</label>
<div class="assistant-actions">
<button type="submit" class="foodex-primary">{{ $ar?'حفظ وتطبيق':'Save and apply' }}</button>
<span>{{ $ar?'يطبق التغيير فورًا ولا يكتب إلى ملف .env.':'Applies immediately and never writes to .env.' }}</span>
</div>
</form>
@else
<div class="assistant-note">{{ $ar?'لديك صلاحية عرض الحالة فقط. يلزم settings.manage لتغييرها.':'You have read-only access to this status. settings.manage is required to change it.' }}</div>
@endif
</div>
</section>
</main>
</div>
</body>
</html>
