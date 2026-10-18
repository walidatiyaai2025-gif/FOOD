<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>{{ app()->getLocale()==='ar' ? 'إعدادات مساعد FOODEX' : 'FOODEX Assistant Settings' }} · FOODEX</title>
@include('admin._brand-components')
@include('admin._settings-page-polish')
<style>
body{margin:0;background:var(--foodex-background);color:var(--foodex-ink)}
.assistant-settings-stack{display:grid;gap:16px;max-width:980px}
.assistant-settings-card{background:#fff;border:1px solid var(--foodex-border);border-radius:18px;padding:22px;box-shadow:var(--foodex-shadow-sm)}
.assistant-settings-grid{display:grid;gap:16px}
.assistant-status{display:flex;align-items:center;justify-content:space-between;gap:16px;padding:14px;border-radius:14px;background:var(--foodex-green-soft)}
.assistant-status strong{font-size:1.05rem}
.assistant-note{padding:13px;border:1px solid var(--foodex-border);border-radius:12px;background:var(--foodex-background)}
.assistant-switch{display:flex;align-items:center;gap:10px;font-weight:800}
.assistant-switch input{width:22px;height:22px}
.assistant-actions{display:flex;gap:10px;align-items:center;flex-wrap:wrap}
.assistant-provider-head{display:flex;justify-content:space-between;align-items:flex-start;gap:16px}
.assistant-provider-head h2{margin:0;font-size:1.05rem}.assistant-provider-head p{margin:4px 0 0;color:var(--foodex-muted);font-size:.8rem}
.assistant-provider-fields{display:grid;grid-template-columns:minmax(0,1.6fr) minmax(180px,.7fr);gap:12px}
.assistant-provider-fields label{display:grid;gap:6px;font-weight:700;font-size:.8rem}
.assistant-provider-fields .wide{grid-column:1/-1}
.assistant-provider-fields input{width:100%}
.assistant-secret-state{font-size:.74rem;color:var(--foodex-muted)}
.assistant-provider-test{display:flex;align-items:center;gap:10px;flex-wrap:wrap}
.foodex-state{margin-bottom:16px}
@media(max-width:767px){.assistant-settings-card{padding:16px}.assistant-provider-fields{grid-template-columns:1fr}.assistant-provider-fields .wide{grid-column:auto}.assistant-provider-head{flex-direction:column}}
</style>
</head>
<body>
@php($ar=app()->getLocale()==='ar')
<div class="foodex-admin-layout" data-foodex-utility="assistant-settings">
<aside class="sidebar">@include('admin._sidebar',['navGroups'=>$navGroups,'navContext'=>$navContext,'user'=>$user])</aside>
<main class="foodex-admin-main foodex-admin-page foodex-settings-surface">
@include('admin._global-header')
@include('admin._admin-center-switcher',['active'=>'settings'])
<div class="foodex-page-toolbar">
<div>
<span class="foodex-subtitle">FOODEX Assistant</span>
<h1>{{ $ar?'إعدادات مساعد FOODEX':'FOODEX Assistant Settings' }}</h1>
<p>{{ $ar?'فعّل المساعد واضبط مزود AI اختياريًا مع بقاء الصلاحيات والقراءة فقط وأدوات FOODEX المعتمدة مفروضة من الخادم.':'Enable the Assistant and optionally connect an AI provider while server-side permissions, read-only enforcement and authoritative FOODEX tools remain in control.' }}</p>
</div>
</div>

@if(session('status'))<div class="foodex-state" role="status">{{ session('status') }}</div>@endif
@if(session('provider_test_success'))<div class="foodex-state" role="status">{{ session('provider_test_success') }}</div>@endif
@if(session('provider_test_error'))<div class="foodex-state" role="alert">{{ session('provider_test_error') }}</div>@endif
@if($errors->any())
<div class="foodex-state" role="alert">
<strong>{{ $ar?'تعذر حفظ الإعداد':'The setting could not be saved' }}</strong>
<ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
</div>
@endif

<div class="assistant-settings-stack">
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
<strong>{{ $ar?'القراءة فقط إلزامية':'Read-only remains mandatory' }}</strong>
<p>{{ $ar?'التفعيل لا يمنح صلاحيات جديدة. يظل الاستخدام مشروطًا بصلاحية assistant.use، وكل بيانات الأعمال تأتي من أدوات FOODEX المصرح بها فقط.':'Enabling the Assistant grants no new access. Use still requires assistant.use, and business data can only come from authorized FOODEX tools.' }}</p>
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

<section class="assistant-settings-card" data-assistant-provider-settings>
<div class="assistant-settings-grid">
<div class="assistant-provider-head">
<div>
<h2>{{ $ar?'اتصال الذكاء الاصطناعي الخارجي':'External AI connection' }}</h2>
<p>{{ $ar?'يدعم أي API متوافق مع OpenAI Chat Completions. استخدم رابط endpoint كامل مثل /v1/chat/completions.':'Supports any OpenAI-compatible Chat Completions API. Enter the full endpoint URL, such as /v1/chat/completions.' }}</p>
</div>
<span class="foodex-badge">{{ $providerSettings['enabled'] ? ($ar?'مفعّل':'Enabled') : ($ar?'غير مفعّل':'Disabled') }}</span>
</div>

<div class="assistant-note">
<strong>{{ $ar?'حدود الأمان':'Safety boundary' }}</strong>
<p>{{ $ar?'الـ AI الخارجي يساعد فقط في فهم السؤال واختيار أداة قراءة معتمدة. لا يحصل على صلاحيات كتابة، ولا يستطيع تجاوز RBAC أو نطاق المتجر/القناة، وعند تعطل المزود يعود المساعد تلقائيًا للمحرك المحلي الحتمي.':'External AI is only used to understand the prompt and select an existing read-only tool. It cannot bypass RBAC or store/channel scope, and provider failure automatically falls back to the deterministic local brain.' }}</p>
</div>

@if($canManage)
<form method="post" action="{{ route('admin.assistant-settings.provider.update') }}" class="assistant-settings-grid" autocomplete="off">
@csrf
@method('PUT')
<input type="hidden" name="provider_enabled" value="0">
<label class="assistant-switch">
<input type="checkbox" name="provider_enabled" value="1" @checked(old('provider_enabled',$providerSettings['enabled']))>
<span>{{ $ar?'استخدام مزود AI الخارجي عند الحاجة':'Use the external AI provider when needed' }}</span>
</label>

<div class="assistant-provider-fields">
<label class="wide">
<span>{{ $ar?'رابط API الكامل':'Full API URL' }}</span>
<input type="url" name="api_url" value="{{ old('api_url',$providerSettings['api_url']) }}" placeholder="https://example.com/v1/chat/completions" inputmode="url">
<small class="assistant-secret-state">{{ $ar?'يسمح بـ HTTP/HTTPS العام. لا تستخدم localhost أو عناوين الشبكات الخاصة.':'Public HTTP/HTTPS is allowed. localhost and private/reserved IP endpoints are blocked.' }}</small>
</label>
<label>
<span>{{ $ar?'اسم الموديل':'Model' }}</span>
<input type="text" name="model" value="{{ old('model',$providerSettings['model']) }}" placeholder="model-name" maxlength="160">
</label>
<label>
<span>{{ $ar?'مهلة الاتصال بالثواني':'Timeout (seconds)' }}</span>
<input type="number" name="timeout_seconds" value="{{ old('timeout_seconds',$providerSettings['timeout_seconds']) }}" min="3" max="120">
</label>
<label class="wide">
<span>{{ $ar?'API Key':'API key' }}</span>
<input type="password" name="api_key" value="" autocomplete="new-password" placeholder="{{ $providerSettings['api_key_configured'] ? '••••••••••••' : ($ar?'اختياري للمزودات المحلية':'Optional for self-hosted providers') }}">
<small class="assistant-secret-state">
@if($providerSettings['api_key_configured'])
{{ $ar?'يوجد مفتاح محفوظ ومشفّر. اترك الحقل فارغًا للاحتفاظ به.':'A key is already saved encrypted. Leave this blank to keep it.' }}
@else
{{ $ar?'المفتاح لا يُعرض بعد الحفظ ويُخزن مشفّرًا.':'The key is never shown after save and is encrypted at rest.' }}
@endif
</small>
</label>
@if($providerSettings['api_key_configured'])
<label class="assistant-switch wide">
<input type="checkbox" name="clear_api_key" value="1">
<span>{{ $ar?'حذف المفتاح المحفوظ':'Remove saved API key' }}</span>
</label>
@endif
</div>

<div class="assistant-actions">
<button type="submit" class="foodex-primary">{{ $ar?'حفظ إعدادات AI':'Save AI settings' }}</button>
<span>{{ $ar?'المصدر الحالي: ':'Current source: ' }}<strong>{{ $providerSettings['source']==='dashboard' ? ($ar?'لوحة التحكم':'Dashboard') : '.env' }}</strong></span>
</div>
</form>

<form method="post" action="{{ route('admin.assistant-settings.provider.test') }}" class="assistant-provider-test">
@csrf
<button type="submit" class="secondary">{{ $ar?'اختبار الاتصال المحفوظ':'Test saved connection' }}</button>
<span>{{ $ar?'احفظ الرابط والموديل والمفتاح أولًا، ثم اختبر الاتصال.':'Save URL, model and key first, then test the connection.' }}</span>
</form>
@else
<div class="assistant-note">
<p><strong>{{ $ar?'الرابط':'API URL' }}:</strong> {{ $providerSettings['api_url'] ?: '—' }}</p>
<p><strong>{{ $ar?'الموديل':'Model' }}:</strong> {{ $providerSettings['model'] ?: '—' }}</p>
<p><strong>{{ $ar?'المفتاح':'API key' }}:</strong> {{ $providerSettings['api_key_configured'] ? ($ar?'محفوظ ومشفّر':'Configured and encrypted') : ($ar?'غير مضبوط':'Not configured') }}</p>
</div>
@endif
</div>
</section>
</div>
</main>
</div>
</body>
</html>
