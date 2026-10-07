<!doctype html>
<html lang="{{ app()->getLocale() }}" dir="{{ app()->getLocale()==='ar'?'rtl':'ltr' }}">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>{{ __('mobile_settings.title') }}</title>
<style>
body{margin:0;background:#f6f7f9;color:#17202a}.layout{display:grid;grid-template-columns:var(--foodex-sidebar-width) minmax(0,1fr);min-height:100vh;width:100%}.sidebar{background:#111827;color:#fff;padding:20px}.main{min-width:0;width:100%;padding:clamp(16px,1.8vw,32px)}.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(330px,1fr));gap:18px}.card{background:#fff;border:1px solid #e2e8f0;border-radius:18px;padding:20px}.row{display:grid;grid-template-columns:1fr 1fr;gap:10px}label{display:block;font-weight:650;font-size:.85rem;margin:9px 0 5px}input,select,textarea{width:100%;box-sizing:border-box;padding:9px;border:1px solid #cbd5e1;border-radius:10px}textarea{min-height:70px}.check{display:flex;align-items:center;gap:8px}.check input{width:auto}.button{display:inline-block;margin-top:12px;padding:10px 14px;border:0;border-radius:10px;background:#0f172a;color:#fff;text-decoration:none}.button.secondary{background:#475569}.notice{padding:12px;background:#ecfdf5;border-radius:10px;margin-bottom:14px}.warning{padding:12px;background:#fff7ed;border:1px solid #fed7aa;border-radius:10px}.muted{color:#64748b}.log{padding:8px 0;border-top:1px solid #e2e8f0}.policy-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px;margin:12px 0}.policy{border:1px solid #e2e8f0;border-radius:12px;padding:12px}.status-ready{color:#166534}.status-blocked{color:#991b1b}.blockers{margin:8px 0;padding-inline-start:20px}.runtime-picker{padding:12px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:12px;margin-bottom:14px}@media(max-width:1023px){.layout{grid-template-columns:1fr}.row,.policy-grid{grid-template-columns:1fr}}
</style>
@include('admin._brand-components')
</head>
<body>
@php($ar = app()->getLocale()==='ar')
<div class="layout foodex-admin-layout" data-foodex-utility="mobile-settings">
<aside class="sidebar">@include('admin._sidebar',['navGroups'=>app(\App\Support\AdminNavigation::class)->groupsFor(auth()->user()),'navContext'=>'mobile_settings','user'=>auth()->user()])</aside>
<main class="main foodex-admin-main">
<header class="foodex-page-header"><div><h1>{{ __('mobile_settings.title') }}</h1><p class="muted">{{ __('mobile_settings.description') }}</p></div>@include('admin._live-notifications',['user'=>auth()->user()])</header>
@if(session('status'))<div class="notice foodex-state" role="status">{{ session('status') }}</div>@endif
@if($errors->any())<div class="error foodex-state" role="alert">{{ $errors->first() }}</div>@endif

<div class="grid">
<section class="card">
<h2>{{ __('mobile_settings.runtime_title') }}</h2>
<p class="muted">{{ __('mobile_settings.runtime_info') }}</p>
<p><strong>{{ __('mobile_settings.release_identity') }}:</strong> <code>{{ $currentReleaseVersion }}</code></p>

<form class="runtime-picker" method="get" action="{{ route('admin.mobile-settings.index') }}">
<div class="row">
<div><label>{{ __('mobile_settings.app') }}</label><select name="app"><option value="customer" @selected($selectedApp==='customer')>{{ __('mobile_settings.apps.customer') }}</option><option value="driver" @selected($selectedApp==='driver')>{{ __('mobile_settings.apps.driver') }}</option><option value="van" @selected($selectedApp==='van')>{{ __('mobile_settings.apps.van') }}</option></select></div>
<div><label>{{ __('mobile_settings.environment') }}</label><select name="environment"><option value="development" @selected($selectedEnvironment==='development')>{{ __('mobile_settings.environments.development') }}</option><option value="staging" @selected($selectedEnvironment==='staging')>{{ __('mobile_settings.environments.staging') }}</option><option value="production" @selected($selectedEnvironment==='production')>{{ __('mobile_settings.environments.production') }}</option></select></div>
</div>
<button class="button secondary" type="submit">{{ __('mobile_settings.load_saved') }}</button>
</form>

<form method="post" action="{{ route('admin.mobile-settings.app') }}">
@csrf @method('put')
<input type="hidden" name="app" value="{{ $selectedApp }}">
<input type="hidden" name="environment" value="{{ $selectedEnvironment }}">
<p><strong>{{ __('mobile_settings.selected_record') }}:</strong> {{ __('mobile_settings.apps.'.$selectedApp) }} · {{ __('mobile_settings.environments.'.$selectedEnvironment) }} @if(!$selectedSetting)<span class="muted">({{ __('mobile_settings.new_record') }})</span>@endif</p>

<label>{{ __('mobile_settings.display_name') }}</label><input name="display_name" value="{{ old('display_name', $selectedSetting?->display_name) }}" placeholder="{{ $ar?'مثال: فودكس العميل':'e.g. FOODEX Customer' }}" required>
<div class="row">
<div><label>{{ $ar?'معرّف حزمة أندرويد':'Android package ID' }}</label><input name="android_package_id" value="{{ old('android_package_id', $selectedSetting?->android_package_id) }}" placeholder="com.fiftysolution.foodex.customer"></div>
<div><label>{{ $ar?'معرّف حزمة آي أو إس':'iOS bundle ID' }}</label><input name="ios_bundle_id" value="{{ old('ios_bundle_id', $selectedSetting?->ios_bundle_id) }}" placeholder="com.fiftysolution.foodex.customer"></div>
</div>
<div class="row">
<div><label>{{ __('mobile_settings.published_version') }}</label><input name="published_version" value="{{ old('published_version', $selectedSetting?->published_version) }}" placeholder="1.0.40"></div>
<div><label>{{ __('mobile_settings.published_build') }}</label><input name="published_build" value="{{ old('published_build', $selectedSetting?->published_build) }}" placeholder="40"></div>
</div>
<div class="row">
<div><label>{{ __('mobile_settings.minimum_version') }}</label><input name="minimum_supported_version" value="{{ old('minimum_supported_version', $selectedSetting?->minimum_supported_version) }}" placeholder="1.0.40"></div>
<div><label>{{ __('mobile_settings.recommended_version') }}</label><input name="recommended_version" value="{{ old('recommended_version', $selectedSetting?->recommended_version) }}" placeholder="1.0.40"></div>
</div>
<input type="hidden" name="force_update" value="0"><label class="check"><input type="checkbox" name="force_update" value="1" @checked((bool) old('force_update', $selectedSetting?->force_update ?? false))>{{ __('mobile_settings.force_update') }}</label>
<input type="hidden" name="maintenance_mode" value="0"><label class="check"><input type="checkbox" name="maintenance_mode" value="1" @checked((bool) old('maintenance_mode', $selectedSetting?->maintenance_mode ?? false))>{{ __('mobile_settings.maintenance') }}</label>
<div class="row">
<div><label>{{ __('mobile_settings.message_ar') }}</label><textarea name="maintenance_message_ar" placeholder="رسالة الصيانة بالعربية">{{ old('maintenance_message_ar', $selectedSetting?->maintenance_message_ar) }}</textarea></div>
<div><label>{{ __('mobile_settings.message_en') }}</label><textarea name="maintenance_message_en" placeholder="Maintenance message in English">{{ old('maintenance_message_en', $selectedSetting?->maintenance_message_en) }}</textarea></div>
</div>
<label>{{ $ar?'رابط متجر جوجل بلاي':'Google Play URL' }}</label><input type="url" name="google_play_url" value="{{ old('google_play_url', $selectedSetting?->google_play_url) }}" placeholder="https://play.google.com/store/apps/details?id=...">
<label>{{ $ar?'رابط متجر آب ستور':'App Store URL' }}</label><input type="url" name="app_store_url" value="{{ old('app_store_url', $selectedSetting?->app_store_url) }}" placeholder="https://apps.apple.com/app/id...">
<div class="row">
<div><label>{{ $ar?'رابط سياسة الخصوصية':'Privacy URL' }}</label><input type="url" name="privacy_url" value="{{ old('privacy_url', $selectedSetting?->privacy_url) }}" placeholder="https://example.com/privacy"></div>
<div><label>{{ $ar?'رابط الشروط والأحكام':'Terms URL' }}</label><input type="url" name="terms_url" value="{{ old('terms_url', $selectedSetting?->terms_url) }}" placeholder="https://example.com/terms"></div>
</div>
<label>{{ $ar?'رابط الدعم':'Support URL' }}</label><input type="url" name="support_url" value="{{ old('support_url', $selectedSetting?->support_url) }}" placeholder="https://example.com/support">
<label>{{ $ar?'رابط حذف الحساب العام':'Delete Account public URL' }}</label><input type="url" name="delete_account_url" value="{{ old('delete_account_url', $selectedSetting?->delete_account_url) }}" placeholder="{{ url('/account-deletion') }}">
<label>{{ $ar?'عرض شريط الإصدار الدائم':'Persistent footer display' }}</label>
<select name="footer_display_mode">
<option value="persistent" @selected(old('footer_display_mode', $selectedSetting?->footer_display_mode ?? 'persistent')==='persistent')>{{ $ar?'تشغيل':'ON — persistent' }}</option>
<option value="about_only" @selected(old('footer_display_mode', $selectedSetting?->footer_display_mode)==='about_only')>{{ $ar?'إخفاء الدائم وإبقاؤه في حول/التشخيص':'OFF — About/Diagnostics only' }}</option>
<option value="hidden" @selected(old('footer_display_mode', $selectedSetting?->footer_display_mode)==='hidden')>{{ $ar?'إخفاء الشريط الدائم':'OFF — zero persistent footer space' }}</option>
</select>
<p class="muted">{{ $ar?'هذا الإعداد يتحكم في الظهور فقط. رقم الإصدار/البناء الفعلي يأتي من التطبيق المثبت ولا يتم تزويره من الإعدادات البعيدة.':'Visibility only. The installed binary remains authoritative for version/build; remote settings never fake it.' }}</p>
<div class="row">
<div><label>{{ __('mobile_settings.release_ar') }}</label><textarea name="release_notes_ar" placeholder="ملاحظات الإصدار بالعربية">{{ old('release_notes_ar', $selectedSetting?->release_notes_ar) }}</textarea></div>
<div><label>{{ __('mobile_settings.release_en') }}</label><textarea name="release_notes_en" placeholder="Release notes in English">{{ old('release_notes_en', $selectedSetting?->release_notes_en) }}</textarea></div>
</div>
<label>{{ __('mobile_settings.deep_links') }}</label><textarea name="deep_link_json" placeholder="{&quot;scheme&quot;:&quot;foodex&quot;,&quot;host&quot;:&quot;app&quot;}">{{ old('deep_link_json', $selectedSetting?->deep_link_config ? json_encode($selectedSetting->deep_link_config, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) : '') }}</textarea>
<label>{{ __('mobile_settings.readiness') }}</label><textarea name="store_readiness_json" placeholder="{&quot;android&quot;:true,&quot;ios&quot;:true}">{{ old('store_readiness_json', $selectedSetting?->store_readiness ? json_encode($selectedSetting->store_readiness, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) : '') }}</textarea>
<button class="button">{{ __('mobile_settings.save') }}</button>
</form>
</section>

<section class="card" data-driver-location-policy>
<h2>{{ $ar?'سياسة الموقع الإلزامية للسائق':'Driver location enforcement' }}</h2>
<p class="muted">{{ $ar?'تتحكم هذه السياسة في واجهات عمليات السائق على الخادم. الجاهزية أدناه مأخوذة من AppVersion الرسمية، وليس من حقول Mobile Runtime الوصفية.':'This server policy gates Driver operational APIs. Readiness below is derived from authoritative AppVersion policy, not informational Mobile Runtime fields.' }}</p>
<p><strong>{{ $ar?'الإصدار الداعم للنبضات من':'Heartbeat-capable minimum' }}:</strong> <code>{{ $driverLocationPolicy['minimum_heartbeat_version'] }}</code></p>

<div class="policy-grid">
@foreach(['android','ios'] as $platform)
@php($platformPolicy = $driverLocationPolicy['platforms'][$platform])
<div class="policy" data-driver-policy="{{ $platform }}">
<h3>{{ strtoupper($platform) }} — <span class="{{ $platformPolicy['ready']?'status-ready':'status-blocked' }}">{{ $platformPolicy['ready']?($ar?'جاهز':'READY'):($ar?'محجوب':'BLOCKED') }}</span></h3>
<p><strong>{{ $ar?'أحدث إصدار':'Latest' }}:</strong> <code>{{ $platformPolicy['latest_version'] ?? '—' }}</code></p>
<p><strong>{{ $ar?'الحد الأدنى':'Minimum supported' }}:</strong> <code>{{ $platformPolicy['minimum_supported_version'] ?? '—' }}</code></p>
<p><strong>{{ $ar?'إجبار التحديث':'Force update' }}:</strong> {{ $platformPolicy['force_update']?($ar?'نعم':'ON'):($ar?'لا':'OFF') }}</p>
<p><strong>{{ $ar?'رابط التحديث':'Update URL' }}:</strong>
@if($platformPolicy['update_url_valid'] && $platformPolicy['update_url'])
<a href="{{ $platformPolicy['update_url'] }}" rel="noopener" target="_blank">{{ $ar?'صالح':'valid' }}</a>
@elseif($platformPolicy['update_url'])
<span class="status-blocked">{{ $ar?'غير صالح':'invalid' }}</span> · <code>{{ $platformPolicy['update_url'] }}</code>
@else
<span class="status-blocked">{{ $ar?'مفقود':'missing' }}</span>
@endif
</p>
@if($platformPolicy['blockers'])
<ul class="blockers">@foreach($platformPolicy['blockers'] as $blocker)<li><code>{{ $blocker }}</code></li>@endforeach</ul>
@endif
@can('platform.manage')
<a class="button secondary" href="{{ route('admin.app-versions.index', ['app'=>'driver','platform'=>$platform]) }}">{{ $ar?'تعديل سياسة AppVersion':'Edit authoritative AppVersion' }}</a>
@endcan
</div>
@endforeach
</div>

<form method="post" action="{{ route('admin.mobile-settings.driver-location-policy') }}">
@csrf @method('put')
<input type="hidden" name="enabled" value="0">
<label class="check"><input type="checkbox" name="enabled" value="1" @checked($driverLocationPolicy['enabled'])>{{ $ar?'تفعيل فرض الموقع الحديث على عمليات السائق':'Require a fresh location for Driver operations' }}</label>
<label>{{ $ar?'الحد الأقصى لعمر آخر موقع مستلم (ثانية)':'Maximum received-location age (seconds)' }}</label>
<input type="number" name="freshness_seconds" min="30" max="600" step="1" value="{{ $driverLocationPolicy['freshness_seconds'] }}" required>
<p class="muted">{{ $ar?'القيمة الافتراضية 90 ثانية. يعتمد الخادم على received_at الذي يسجله الخادم، وليس ساعة جهاز السائق.':'Default is 90 seconds. Enforcement uses server-owned received_at, not the Driver device clock.' }}</p>
<p><strong>{{ $ar?'الحالة الحالية':'Current status' }}:</strong> {{ $driverLocationPolicy['enabled'] ? ($ar?'مفعّل':'ON') : ($ar?'متوقف':'OFF') }}</p>
<p><strong>{{ $ar?'جاهزية التفعيل':'Rollout gate' }}:</strong> <span class="{{ $driverLocationPolicy['rollout_ready']?'status-ready':'status-blocked' }}">{{ $driverLocationPolicy['rollout_ready'] ? ($ar?'جاهز':'READY') : ($ar?'غير جاهز':'BLOCKED') }}</span></p>
@if(!$driverLocationPolicy['rollout_ready'])
<div class="warning">
<strong>{{ $ar?'أسباب الحظر الفعلية':'Exact rollout blockers' }}:</strong>
<ul class="blockers">@foreach($driverLocationPolicy['rollout_blockers'] as $blocker)<li><code>{{ $blocker }}</code></li>@endforeach</ul>
</div>
@endif
<button class="button">{{ $ar?'حفظ سياسة الموقع':'Save location policy' }}</button>
</form>
</section>

<section class="card">
<h2>{{ __('mobile_settings.push_title') }}</h2>
<form method="post" action="{{ route('admin.mobile-settings.push') }}">@csrf @method('put')
<div class="row"><div><label>{{ __('mobile_settings.app') }}</label><select name="app"><option value="customer">{{ $ar?'العميل':'Customer' }}</option><option value="driver">{{ $ar?'السائق':'Driver' }}</option></select></div><div><label>{{ __('mobile_settings.platform') }}</label><select name="platform"><option value="android">{{ $ar?'أندرويد / إشعارات فايربيز':'Android / FCM' }}</option><option value="ios">{{ $ar?'آي أو إس / إشعارات فايربيز':'iOS / FCM (APNs)' }}</option></select></div></div>
<label>{{ __('mobile_settings.environment') }}</label><select name="environment"><option value="development">{{ $ar?'تطوير':'Development' }}</option><option value="staging">{{ $ar?'اختبار':'Staging' }}</option><option value="production" selected>{{ $ar?'إنتاج':'Production' }}</option></select>
<label class="check"><input type="checkbox" name="enabled" value="1">{{ __('mobile_settings.enabled') }}</label>
<label>{{ __('mobile_settings.credentials') }}</label><textarea name="credentials_json" placeholder="{{ $ar?'ألصق Service Account JSON من Firebase / Google Cloud ويشمل project_id و client_email و private_key':'Paste the Firebase / Google Cloud Service Account JSON including project_id, client_email and private_key' }}"></textarea>
<p class="muted">{{ $ar?'يحفظ مشفراً. يقوم FOODEX بإنشاء وتجديد OAuth access token تلقائياً؛ لا تحتاج لإدخال access_token يدوياً.':'Stored encrypted. FOODEX generates and refreshes the OAuth access token automatically; no manual access_token is required.' }}</p>
<div class="row"><div><label>{{ $ar?'الصوت الافتراضي':'Sound' }}</label><input name="default_sound" placeholder="default"></div><div><label>{{ $ar?'قناة الإشعارات':'Channel' }}</label><input name="default_channel" placeholder="foodex_default"></div></div>
<div class="row"><div><label>{{ $ar?'أيقونة الإشعار':'Icon' }}</label><input name="default_icon" placeholder="ic_notification"></div><div><label>{{ $ar?'فئة الإشعار':'Category' }}</label><input name="default_category" placeholder="general"></div></div>
<button class="button">{{ __('mobile_settings.save_push') }}</button>
</form>
<h3>{{ __('mobile_settings.configured') }}</h3>
@forelse($providers as $p)
<div class="log" style="display:flex;gap:10px;align-items:center;justify-content:space-between;flex-wrap:wrap"><span>{{ $p->app }} · {{ $p->platform }} · {{ $p->environment }} · {{ $p->enabled?__('mobile_settings.on'):__('mobile_settings.off') }}</span><form method="post" action="{{ route('admin.mobile-settings.push.test') }}" style="margin:0">@csrf<input type="hidden" name="app" value="{{ $p->app }}"><input type="hidden" name="platform" value="{{ $p->platform }}"><input type="hidden" name="environment" value="{{ $p->environment }}"><button class="button" type="submit" style="margin:0">{{ $ar?'اختبار اتصال Firebase':'Test Firebase connection' }}</button></form></div>
@empty<p class="muted">{{ __('mobile_settings.empty') }}</p>@endforelse
</section>

<section class="card">
<h2>{{ __('mobile_settings.test_title') }}</h2>
<form method="post" action="{{ route('admin.mobile-settings.test') }}">@csrf
<label>{{ __('mobile_settings.device') }}</label><select name="device_id">@foreach($devices as $d)<option value="{{ $d->id }}">#{{ $d->id }} · {{ $d->app }} · {{ $d->platform }} · {{ $d->environment }}</option>@endforeach</select>
<div class="row"><div><label>{{ __('mobile_settings.title_ar') }}</label><input name="title_ar" placeholder="عنوان الإشعار بالعربية" required></div><div><label>{{ __('mobile_settings.title_en') }}</label><input name="title_en" placeholder="Notification title in English" required></div></div>
<div class="row"><div><label>{{ __('mobile_settings.body_ar') }}</label><textarea name="body_ar" placeholder="نص الإشعار بالعربية" required></textarea></div><div><label>{{ __('mobile_settings.body_en') }}</label><textarea name="body_en" placeholder="Notification body in English" required></textarea></div></div>
<button class="button">{{ __('mobile_settings.send_test') }}</button>
</form>
<h3>{{ __('mobile_settings.delivery_log') }}</h3>
@forelse($logs as $log)<div class="log">#{{ $log->id }} · {{ $log->app }}/{{ $log->platform }}/{{ $log->environment }} · <strong>{{ $log->status }}</strong>@if($log->response_code) · HTTP {{ $log->response_code }}@endif @if($log->error_code)<br><strong>{{ $ar?'السبب':'Reason' }}:</strong> {{ $log->error_code }}@if($log->error_message) — {{ $log->error_message }}@endif @endif</div>@empty<p class="muted">{{ __('mobile_settings.empty') }}</p>@endforelse
</section>
<section class="card" style="grid-column:1/-1" data-store-submission-center>
<h2>{{ $ar?'النشر / تجهيز الرفع للمتاجر':'Publishing / Store Submission' }}</h2>
<p class="muted">{{ $ar?'أربع مسارات مستقلة: العميل أندرويد، العميل iOS، السائق/الفان أندرويد، السائق/الفان iOS. حالة الجاهزية هنا لا تتجاوز نتائج التدقيق الآلي أو المتطلبات الخارجية.':'Four independent lanes: Customer Android, Customer iOS, Driver/Van Android, Driver/Van iOS. Readiness does not override automated audit evidence or external console/signing requirements.' }}</p>
@foreach(['customer','driver'] as $submissionApp)
@foreach(['android','ios'] as $submissionPlatform)
@php($submission = $storeSubmissions->first(fn($item)=>$item->app===$submissionApp && $item->platform===$submissionPlatform && $item->environment===$selectedEnvironment))
<form method="post" action="{{ route('admin.mobile-settings.submission') }}" class="policy" style="margin:12px 0">
@csrf @method('put')
<input type="hidden" name="app" value="{{ $submissionApp }}">
<input type="hidden" name="platform" value="{{ $submissionPlatform }}">
<input type="hidden" name="environment" value="{{ $selectedEnvironment }}">
<h3>{{ ucfirst($submissionApp) }} · {{ strtoupper($submissionPlatform) }} · {{ $selectedEnvironment }} — <span class="{{ ($submission?->readiness_state)==='PASS'?'status-ready':'status-blocked' }}">{{ $submission?->readiness_state ?? 'BLOCKED' }}</span></h3>
<div class="row">
<div><label>Package / Bundle ID</label><input name="package_identifier" value="{{ $submission?->package_identifier }}" placeholder="{{ $submissionApp==='customer'?'com.fiftysolution.foodex.customer':'com.fiftysolution.foodex.driver' }}"></div>
<div><label>{{ $ar?'الحالة في المتجر':'Submission status' }}</label><select name="submission_status">@foreach(['NOT_READY','READY','SUBMITTED','IN_REVIEW','APPROVED','REJECTED','PUBLISHED'] as $status)<option value="{{ $status }}" @selected(($submission?->submission_status ?? 'NOT_READY')===$status)>{{ $status }}</option>@endforeach</select></div>
</div>
<div class="row"><div><label>Current version</label><input name="current_version" value="{{ $submission?->current_version }}"></div><div><label>Current build</label><input name="current_build" value="{{ $submission?->current_build }}"></div></div>
<div class="row"><div><label>Minimum version</label><input name="minimum_version" value="{{ $submission?->minimum_version }}"></div><div><label>Recommended version</label><input name="recommended_version" value="{{ $submission?->recommended_version }}"></div></div>
<label>Update policy</label><select name="update_policy">@foreach(['optional','recommended','required'] as $policy)<option value="{{ $policy }}" @selected(($submission?->update_policy ?? 'optional')===$policy)>{{ $policy }}</option>@endforeach</select>
<label>Store URL</label><input type="url" name="store_url" value="{{ $submission?->store_url }}">
<div class="row"><div><label>Privacy URL</label><input type="url" name="privacy_url" value="{{ $submission?->privacy_url ?: url('/privacy') }}"></div><div><label>Terms URL</label><input type="url" name="terms_url" value="{{ $submission?->terms_url ?: url('/terms') }}"></div></div>
<div class="row"><div><label>Support URL</label><input type="url" name="support_url" value="{{ $submission?->support_url ?: url('/support') }}"></div><div><label>Delete Account URL</label><input type="url" name="delete_account_url" value="{{ $submission?->delete_account_url ?: url('/account-deletion') }}"></div></div>
<div class="row"><div><label>Release notes AR</label><textarea name="release_notes_ar">{{ $submission?->release_notes_ar }}</textarea></div><div><label>Release notes EN</label><textarea name="release_notes_en">{{ $submission?->release_notes_en }}</textarea></div></div>
<label>Store title</label><input name="title" value="{{ $submission?->title }}">
<label>Short description</label><input name="short_description" value="{{ $submission?->short_description }}">
<label>Full description</label><textarea name="full_description">{{ $submission?->full_description }}</textarea>
<div class="row"><div><label>Category</label><input name="category" value="{{ $submission?->category }}"></div><div><label>Keywords</label><input name="keywords" value="{{ $submission?->keywords }}"></div></div>
<label>Reviewer notes</label><textarea name="reviewer_notes">{{ $submission?->reviewer_notes }}</textarea>
<div class="row"><div><label>Screenshot / assets readiness JSON</label><textarea name="asset_checklist_json">{{ $submission?->asset_checklist ? json_encode($submission->asset_checklist, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES) : '{}' }}</textarea></div><div><label>Permissions / store privacy impact JSON</label><textarea name="permission_declarations_json">{{ $submission?->permission_declarations ? json_encode($submission->permission_declarations, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES) : '{}' }}</textarea></div></div>
<div class="row"><div><label>Privacy/data declaration checklist JSON</label><textarea name="privacy_checklist_json">{{ $submission?->privacy_checklist ? json_encode($submission->privacy_checklist, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES) : '{}' }}</textarea></div><div><label>EXTERNAL MANUAL gaps JSON</label><textarea name="manual_gaps_json">{{ $submission?->manual_gaps ? json_encode($submission->manual_gaps, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES) : '[]' }}</textarea></div></div>
<div class="policy-grid">
@foreach(['signing_readiness'=>'Signing','firebase_readiness'=>'Firebase','apns_readiness'=>'APNs','deep_link_readiness'=>'Deep links','production_environment_readiness'=>'Production env','readiness_state'=>'Overall'] as $field=>$label)
<div><label>{{ $label }}</label><select name="{{ $field }}">@foreach(['PASS','WARN','BLOCKED'] as $state)<option value="{{ $state }}" @selected(($submission?->{$field} ?? 'BLOCKED')===$state)>{{ $state }}</option>@endforeach</select></div>
@endforeach
</div>
<button class="button">{{ $ar?'حفظ بيانات الرفع':'Save submission metadata' }}</button>
</form>
@endforeach
@endforeach
</section>

<section class="card" style="grid-column:1/-1" data-reviewer-accounts>
<h2>{{ $ar?'حسابات مراجعي المتاجر':'Store Reviewer / Test Accounts' }}</h2>
<p class="warning">{{ $ar?'كلمة المرور لا تُعرض أبداً بعد الحفظ، ولا تُعاد في API أو السجلات أو الـAudit. الاختبار يتم على الخادم فقط.':'Reviewer passwords are write-only: never returned in API, Dashboard, logs, or audit. Readiness testing occurs server-side.' }}</p>
<form method="post" action="{{ route('admin.mobile-settings.reviewer') }}">
@csrf @method('put')
<div class="row"><div><label>App</label><select name="app"><option value="customer">Customer</option><option value="driver">Driver/Van</option></select></div><div><label>Platform</label><select name="platform"><option value="android">Android</option><option value="ios">iOS</option></select></div></div>
<input type="hidden" name="environment" value="{{ $selectedEnvironment }}">
<div class="row"><div><label>Persona</label><input name="persona" placeholder="customer_reviewer" required></div><div><label>Identifier type</label><select name="identifier_type"><option value="email">email</option><option value="username">username</option><option value="phone">phone</option></select></div></div>
<label>Identifier</label><input name="identifier" required>
<label>{{ $ar?'كلمة مرور/سر المراجع (كتابة فقط)':'Reviewer secret (write-only)' }}</label><input type="password" name="reviewer_secret" autocomplete="new-password">
<label>Store / tenant / customer / driver context JSON</label><textarea name="context_json" placeholder='{"store_id":1,"channel":"b2c"}'></textarea>
<label>Deterministic reviewer instructions</label><textarea name="reviewer_instructions"></textarea>
<input type="hidden" name="is_active" value="0"><label class="check"><input type="checkbox" name="is_active" value="1" checked>Active</label>
<button class="button">Save reviewer persona</button>
</form>
<h3>Configured personas</h3>
@forelse($reviewerAccounts as $reviewer)
<div class="policy">
<strong>{{ $reviewer->app }} · {{ $reviewer->platform }} · {{ $reviewer->environment }} · {{ $reviewer->persona }}</strong>
<p>{{ $reviewer->identifier_type }}: <code>{{ $reviewer->identifier }}</code> · secret: <strong>{{ $reviewer->maskedSecret() }}</strong> · readiness: <strong>{{ $reviewer->readiness_status }}</strong></p>
@if($reviewer->reviewer_instructions)<p class="muted">{{ $reviewer->reviewer_instructions }}</p>@endif
<div style="display:flex;gap:8px;flex-wrap:wrap">
<form method="post" action="{{ route('admin.mobile-settings.reviewer.test',$reviewer) }}">@csrf<button class="button secondary" type="submit">Readiness test</button></form>
<form method="post" action="{{ route('admin.mobile-settings.reviewer.rotate',$reviewer) }}">@csrf<label>Rotate secret</label><input type="password" name="reviewer_secret" autocomplete="new-password" required><button class="button secondary" type="submit">Rotate</button></form>
</div>
</div>
@empty<p class="muted">{{ __('mobile_settings.empty') }}</p>@endforelse
</section>

</div>
</main>
</div>
</body>
</html>
