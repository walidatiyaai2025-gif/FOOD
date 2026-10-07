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

<label>{{ __('mobile_settings.display_name') }}</label><input name="display_name" value="{{ old('display_name', $selectedSetting?->display_name) }}" placeholder="{{ __('mobile_settings.ui.display_name_placeholder') }}" required>
<div class="row">
<div><label>{{ __('mobile_settings.ui.android_package_id') }}</label><input name="android_package_id" value="{{ old('android_package_id', $selectedSetting?->android_package_id) }}" placeholder="com.fiftysolution.foodex.customer"></div>
<div><label>{{ __('mobile_settings.ui.ios_bundle_id') }}</label><input name="ios_bundle_id" value="{{ old('ios_bundle_id', $selectedSetting?->ios_bundle_id) }}" placeholder="com.fiftysolution.foodex.customer"></div>
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
<label>{{ __('mobile_settings.ui.google_play_url') }}</label><input type="url" name="google_play_url" value="{{ old('google_play_url', $selectedSetting?->google_play_url) }}" placeholder="https://play.google.com/store/apps/details?id=...">
<label>{{ __('mobile_settings.ui.app_store_url') }}</label><input type="url" name="app_store_url" value="{{ old('app_store_url', $selectedSetting?->app_store_url) }}" placeholder="https://apps.apple.com/app/id...">
<div class="row">
<div><label>{{ __('mobile_settings.ui.privacy_url') }}</label><input type="url" name="privacy_url" value="{{ old('privacy_url', $selectedSetting?->privacy_url) }}" placeholder="https://example.com/privacy"></div>
<div><label>{{ __('mobile_settings.ui.terms_url') }}</label><input type="url" name="terms_url" value="{{ old('terms_url', $selectedSetting?->terms_url) }}" placeholder="https://example.com/terms"></div>
</div>
<label>{{ __('mobile_settings.ui.support_url') }}</label><input type="url" name="support_url" value="{{ old('support_url', $selectedSetting?->support_url) }}" placeholder="https://example.com/support">
<label>{{ __('mobile_settings.ui.delete_account_url') }}</label><input type="url" name="delete_account_url" value="{{ old('delete_account_url', $selectedSetting?->delete_account_url) }}" placeholder="{{ url('/account-deletion') }}">
<label>{{ __('mobile_settings.ui.footer_display') }}</label>
<select name="footer_display_mode">
<option value="persistent" @selected(old('footer_display_mode', $selectedSetting?->footer_display_mode ?? 'persistent')==='persistent')>{{ __('mobile_settings.ui.footer_persistent') }}</option>
<option value="about_only" @selected(old('footer_display_mode', $selectedSetting?->footer_display_mode)==='about_only')>{{ __('mobile_settings.ui.footer_about_only') }}</option>
<option value="hidden" @selected(old('footer_display_mode', $selectedSetting?->footer_display_mode)==='hidden')>{{ __('mobile_settings.ui.footer_hidden') }}</option>
</select>
<p class="muted">{{ __('mobile_settings.ui.footer_help') }}</p>
<div class="row">
<div><label>{{ __('mobile_settings.release_ar') }}</label><textarea name="release_notes_ar" placeholder="ملاحظات الإصدار بالعربية">{{ old('release_notes_ar', $selectedSetting?->release_notes_ar) }}</textarea></div>
<div><label>{{ __('mobile_settings.release_en') }}</label><textarea name="release_notes_en" placeholder="Release notes in English">{{ old('release_notes_en', $selectedSetting?->release_notes_en) }}</textarea></div>
</div>
<label>{{ __('mobile_settings.deep_links') }}</label>
<div class="row">
<div><label>{{ __('mobile_settings.deep_link_scheme') }}</label><input name="deep_link_scheme" value="{{ old('deep_link_scheme', data_get($selectedSetting?->deep_link_config, 'scheme')) }}" placeholder="foodex"></div>
<div><label>{{ __('mobile_settings.deep_link_host') }}</label><input name="deep_link_host" value="{{ old('deep_link_host', data_get($selectedSetting?->deep_link_config, 'host')) }}" placeholder="app"></div>
</div>
<label>{{ __('mobile_settings.readiness') }}</label>
<div class="row">
<div><input type="hidden" name="readiness_android" value="0"><label class="check"><input type="checkbox" name="readiness_android" value="1" @checked((bool) old('readiness_android', data_get($selectedSetting?->store_readiness, 'android', false)))>{{ __('mobile_settings.readiness_android') }}</label></div>
<div><input type="hidden" name="readiness_ios" value="0"><label class="check"><input type="checkbox" name="readiness_ios" value="1" @checked((bool) old('readiness_ios', data_get($selectedSetting?->store_readiness, 'ios', false)))>{{ __('mobile_settings.readiness_ios') }}</label></div>
<div><input type="hidden" name="readiness_privacy" value="0"><label class="check"><input type="checkbox" name="readiness_privacy" value="1" @checked((bool) old('readiness_privacy', data_get($selectedSetting?->store_readiness, 'privacy', false)))>{{ __('mobile_settings.readiness_privacy') }}</label></div>
</div>
<button class="button">{{ __('mobile_settings.save') }}</button>
</form>
</section>

<section class="card" data-driver-location-policy>
<h2>{{ __('mobile_settings.ui.driver_location_title') }}</h2>
<p class="muted">{{ __('mobile_settings.ui.driver_location_info') }}</p>
<p><strong>{{ __('mobile_settings.ui.heartbeat_minimum') }}:</strong> <code>{{ $driverLocationPolicy['minimum_heartbeat_version'] }}</code></p>

<div class="policy-grid">
@foreach(['android','ios'] as $platform)
@php($platformPolicy = $driverLocationPolicy['platforms'][$platform])
<div class="policy" data-driver-policy="{{ $platform }}">
<h3>{{ strtoupper($platform) }} — <span class="{{ $platformPolicy['ready']?'status-ready':'status-blocked' }}">{{ $platformPolicy['ready'] ? __('mobile_settings.ui.ready') : __('mobile_settings.ui.blocked') }}</span></h3>
<p><strong>{{ __('mobile_settings.ui.latest') }}:</strong> <code>{{ $platformPolicy['latest_version'] ?? '—' }}</code></p>
<p><strong>{{ __('mobile_settings.ui.minimum_supported') }}:</strong> <code>{{ $platformPolicy['minimum_supported_version'] ?? '—' }}</code></p>
<p><strong>{{ __('mobile_settings.ui.force_update_label') }}:</strong> {{ $platformPolicy['force_update'] ? __('mobile_settings.ui.yes') : __('mobile_settings.ui.no') }}</p>
<p><strong>{{ __('mobile_settings.ui.update_url') }}:</strong>
@if($platformPolicy['update_url_valid'] && $platformPolicy['update_url'])
<a href="{{ $platformPolicy['update_url'] }}" rel="noopener" target="_blank">{{ __('mobile_settings.ui.valid') }}</a>
@elseif($platformPolicy['update_url'])
<span class="status-blocked">{{ __('mobile_settings.ui.invalid') }}</span> · <code>{{ $platformPolicy['update_url'] }}</code>
@else
<span class="status-blocked">{{ __('mobile_settings.ui.missing') }}</span>
@endif
</p>
@if($platformPolicy['blockers'])
<ul class="blockers">@foreach($platformPolicy['blockers'] as $blocker)<li><code>{{ $blocker }}</code></li>@endforeach</ul>
@endif
@can('platform.manage')
<a class="button secondary" href="{{ route('admin.app-versions.index', ['app'=>'driver','platform'=>$platform]) }}">{{ __('mobile_settings.ui.edit_app_version') }}</a>
@endcan
</div>
@endforeach
</div>

<form method="post" action="{{ route('admin.mobile-settings.driver-location-policy') }}">
@csrf @method('put')
<input type="hidden" name="enabled" value="0">
<label class="check"><input type="checkbox" name="enabled" value="1" @checked($driverLocationPolicy['enabled'])>{{ __('mobile_settings.ui.require_fresh_location') }}</label>
<label>{{ __('mobile_settings.ui.freshness_seconds') }}</label>
<input type="number" name="freshness_seconds" min="30" max="600" step="1" value="{{ $driverLocationPolicy['freshness_seconds'] }}" required>
<p class="muted">{{ __('mobile_settings.ui.freshness_help') }}</p>
<p><strong>{{ __('mobile_settings.ui.current_status') }}:</strong> {{ $driverLocationPolicy['enabled'] ? __('mobile_settings.on') : __('mobile_settings.off') }}</p>
<p><strong>{{ __('mobile_settings.ui.rollout_gate') }}:</strong> <span class="{{ $driverLocationPolicy['rollout_ready']?'status-ready':'status-blocked' }}">{{ $driverLocationPolicy['rollout_ready'] ? __('mobile_settings.ui.rollout_ready') : __('mobile_settings.ui.rollout_blocked') }}</span></p>
@if(!$driverLocationPolicy['rollout_ready'])
<div class="warning">
<strong>{{ __('mobile_settings.ui.rollout_blockers') }}:</strong>
<ul class="blockers">@foreach($driverLocationPolicy['rollout_blockers'] as $blocker)<li><code>{{ $blocker }}</code></li>@endforeach</ul>
</div>
@endif
<button class="button">{{ __('mobile_settings.ui.save_location_policy') }}</button>
</form>
</section>

<section class="card">
<h2>{{ __('mobile_settings.push_title') }}</h2>
<form method="post" action="{{ route('admin.mobile-settings.push') }}">@csrf @method('put')
<div class="row"><div><label>{{ __('mobile_settings.app') }}</label><select name="app"><option value="customer">{{ __('mobile_settings.apps.customer') }}</option><option value="driver">{{ __('mobile_settings.apps.driver') }}</option><option value="van">{{ __('mobile_settings.apps.van') }}</option></select></div><div><label>{{ __('mobile_settings.platform') }}</label><select name="platform"><option value="android">{{ __('mobile_settings.ui.android_fcm') }}</option><option value="ios">{{ __('mobile_settings.ui.ios_fcm') }}</option></select></div></div>
<label>{{ __('mobile_settings.environment') }}</label><select name="environment"><option value="development">{{ __('mobile_settings.environments.development') }}</option><option value="staging">{{ __('mobile_settings.environments.staging') }}</option><option value="production" selected>{{ __('mobile_settings.environments.production') }}</option></select>
<label class="check"><input type="checkbox" name="enabled" value="1">{{ __('mobile_settings.enabled') }}</label>
<label>{{ __('mobile_settings.credentials') }}</label><textarea name="credentials_json" placeholder="{{ __('mobile_settings.ui.credentials_placeholder') }}"></textarea>
<p class="muted">{{ __('mobile_settings.ui.credentials_help') }}</p>
<div class="row"><div><label>{{ __('mobile_settings.ui.sound') }}</label><input name="default_sound" placeholder="default"></div><div><label>{{ __('mobile_settings.ui.channel') }}</label><input name="default_channel" placeholder="foodex_default"></div></div>
<div class="row"><div><label>{{ __('mobile_settings.ui.icon') }}</label><input name="default_icon" placeholder="ic_notification"></div><div><label>{{ __('mobile_settings.ui.category') }}</label><input name="default_category" placeholder="general"></div></div>
<button class="button">{{ __('mobile_settings.save_push') }}</button>
</form>
<h3>{{ __('mobile_settings.configured') }}</h3>
@forelse($providers as $p)
<div class="log" style="display:flex;gap:10px;align-items:center;justify-content:space-between;flex-wrap:wrap"><span>{{ __('mobile_settings.apps.'.$p->app) }} · {{ strtoupper($p->platform) }} · {{ __('mobile_settings.environments.'.$p->environment) }} · {{ $p->enabled ? __('mobile_settings.on') : __('mobile_settings.off') }}</span><form method="post" action="{{ route('admin.mobile-settings.push.test') }}" style="margin:0">@csrf<input type="hidden" name="app" value="{{ $p->app }}"><input type="hidden" name="platform" value="{{ $p->platform }}"><input type="hidden" name="environment" value="{{ $p->environment }}"><button class="button" type="submit" style="margin:0">{{ __('mobile_settings.ui.test_firebase') }}</button></form></div>
@empty<p class="muted">{{ __('mobile_settings.empty') }}</p>@endforelse
</section>

<section class="card">
<h2>{{ __('mobile_settings.test_title') }}</h2>
<form method="post" action="{{ route('admin.mobile-settings.test') }}">@csrf
<label>{{ __('mobile_settings.device') }}</label><select name="device_id">@foreach($devices as $d)<option value="{{ $d->id }}">{{ $d->user?->name ?: ($d->user?->email ?: __('mobile_settings.unknown_user')) }} · {{ __('mobile_settings.apps.'.$d->app) }} · {{ strtoupper($d->platform) }} · {{ __('mobile_settings.environments.'.$d->environment) }}</option>@endforeach</select>
<div class="row"><div><label>{{ __('mobile_settings.title_ar') }}</label><input name="title_ar" placeholder="عنوان الإشعار بالعربية" required></div><div><label>{{ __('mobile_settings.title_en') }}</label><input name="title_en" placeholder="Notification title in English" required></div></div>
<div class="row"><div><label>{{ __('mobile_settings.body_ar') }}</label><textarea name="body_ar" placeholder="نص الإشعار بالعربية" required></textarea></div><div><label>{{ __('mobile_settings.body_en') }}</label><textarea name="body_en" placeholder="Notification body in English" required></textarea></div></div>
<button class="button">{{ __('mobile_settings.send_test') }}</button>
</form>
<h3>{{ __('mobile_settings.delivery_log') }}</h3>
@forelse($logs as $log)<div class="log">{{ __('mobile_settings.apps.'.$log->app) }} · {{ strtoupper($log->platform) }} · {{ __('mobile_settings.environments.'.$log->environment) }} · <strong>{{ __('mobile_settings.delivery_status.'.$log->status) }}</strong>@if($log->error_code)<br><strong>{{ __('mobile_settings.delivery_issue') }}:</strong> {{ $log->error_message ?: __('mobile_settings.test_failed') }}@endif</div>@empty<p class="muted">{{ __('mobile_settings.empty') }}</p>@endforelse
</section>
@php
$submissionListText = static function ($value): string {
    if (! is_array($value)) {
        return '';
    }

    return collect($value)->map(static function ($item, $key): string {
        if (is_string($item) || is_numeric($item)) {
            return trim((string) $item);
        }

        if (is_array($item)) {
            $name = trim((string) ($item['name'] ?? ''));
            $reason = trim((string) ($item['reason'] ?? ''));
            if ($name !== '' || $reason !== '') {
                return trim($name.($reason !== '' ? ' — '.$reason : ''));
            }

            return collect($item)
                ->filter(static fn ($value): bool => is_scalar($value))
                ->map(static fn ($value, $itemKey): string => $itemKey.': '.$value)
                ->implode(' · ');
        }

        return '';
    })->filter()->implode("\n");
};
$submissionMasterAssetStates = [
    'repository-controlled',
    'external-manual',
    'blocked',
];
$submissionUploadAssetStates = [
    'repository-controlled',
    'external-manual-final-upload',
    'external-manual-if-required',
    'blocked',
];
@endphp
<section class="card" style="grid-column:1/-1" data-store-submission-center>
<h2>{{ __('mobile_settings.ui.publishing_title') }}</h2>
<p class="muted">{{ __('mobile_settings.ui.publishing_info') }}</p>
@foreach(['customer','driver','van'] as $submissionApp)
@foreach(['android','ios'] as $submissionPlatform)
@php($submission = $storeSubmissions->first(fn($item)=>$item->app===$submissionApp && $item->platform===$submissionPlatform && $item->environment===$selectedEnvironment))
<form method="post" action="{{ route('admin.mobile-settings.submission') }}" class="policy" style="margin:12px 0">
@csrf @method('put')
<input type="hidden" name="app" value="{{ $submissionApp }}">
<input type="hidden" name="platform" value="{{ $submissionPlatform }}">
<input type="hidden" name="environment" value="{{ $selectedEnvironment }}">
<h3>{{ __('mobile_settings.apps.'.$submissionApp) }} · {{ strtoupper($submissionPlatform) }} · {{ __('mobile_settings.environments.'.$selectedEnvironment) }} — <span class="{{ ($submission?->readiness_state)==='PASS'?'status-ready':'status-blocked' }}">{{ $submission?->readiness_state ?? 'BLOCKED' }}</span></h3>
<div class="row">
@php($submissionPackage = ['customer'=>'com.fiftysolution.foodex.customer','driver'=>'com.fiftysolution.foodex.driver','van'=>'com.foodex.van'][$submissionApp])
<div><label>Package / Bundle ID</label><input name="package_identifier" value="{{ $submission?->package_identifier }}" placeholder="{{ $submissionPackage }}"></div>
<div><label>{{ __('mobile_settings.ui.submission_status') }}</label><select name="submission_status">@foreach(['NOT_READY','READY','SUBMITTED','IN_REVIEW','APPROVED','REJECTED','PUBLISHED'] as $status)<option value="{{ $status }}" @selected(($submission?->submission_status ?? 'NOT_READY')===$status)>{{ $status }}</option>@endforeach</select></div>
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
<label>{{ __('mobile_settings.submission_assets') }}</label>
<div class="policy-grid">
<div><label>{{ __('mobile_settings.asset_icon') }}</label><select name="asset_icon_master"><option value="">—</option>@foreach($submissionMasterAssetStates as $assetState)<option value="{{ $assetState }}" @selected(data_get($submission?->asset_checklist, 'icon_master')===$assetState)>{{ __('mobile_settings.asset_states.'.$assetState) }}</option>@endforeach</select></div>
<div><label>{{ __('mobile_settings.asset_splash') }}</label><select name="asset_splash_master"><option value="">—</option>@foreach($submissionMasterAssetStates as $assetState)<option value="{{ $assetState }}" @selected(data_get($submission?->asset_checklist, 'splash_master')===$assetState)>{{ __('mobile_settings.asset_states.'.$assetState) }}</option>@endforeach</select></div>
<div><label>{{ __('mobile_settings.asset_screenshots') }}</label><select name="asset_screenshots"><option value="">—</option>@foreach($submissionUploadAssetStates as $assetState)<option value="{{ $assetState }}" @selected(data_get($submission?->asset_checklist, 'screenshots')===$assetState)>{{ __('mobile_settings.asset_states.'.$assetState) }}</option>@endforeach</select></div>
<div><label>{{ __('mobile_settings.asset_promotional') }}</label><select name="asset_promotional_assets"><option value="">—</option>@foreach($submissionUploadAssetStates as $assetState)<option value="{{ $assetState }}" @selected(data_get($submission?->asset_checklist, 'promotional_assets')===$assetState)>{{ __('mobile_settings.asset_states.'.$assetState) }}</option>@endforeach</select></div>
</div>
<div class="row">
<div><label>{{ __('mobile_settings.submission_permissions') }}</label><textarea name="permission_declarations_text" placeholder="{{ __('mobile_settings.one_per_line') }}">{{ $submissionListText($submission?->permission_declarations) }}</textarea></div>
<div><label>{{ __('mobile_settings.submission_privacy') }}</label><textarea name="privacy_checklist_text" placeholder="{{ __('mobile_settings.one_per_line') }}">{{ $submissionListText($submission?->privacy_checklist) }}</textarea></div>
</div>
<label>{{ __('mobile_settings.submission_manual_gaps') }}</label><textarea name="manual_gaps_text" placeholder="{{ __('mobile_settings.one_per_line') }}">{{ $submissionListText($submission?->manual_gaps) }}</textarea>
<div class="policy-grid">
@foreach(['signing_readiness'=>'Signing','firebase_readiness'=>'Firebase','apns_readiness'=>'APNs','deep_link_readiness'=>'Deep links','production_environment_readiness'=>'Production env','readiness_state'=>'Overall'] as $field=>$label)
<div><label>{{ $label }}</label><select name="{{ $field }}">@foreach(['PASS','WARN','BLOCKED'] as $state)<option value="{{ $state }}" @selected(($submission?->{$field} ?? 'BLOCKED')===$state)>{{ $state }}</option>@endforeach</select></div>
@endforeach
</div>
<button class="button">{{ __('mobile_settings.ui.save_submission') }}</button>
</form>
@endforeach
@endforeach
</section>

<section class="card" style="grid-column:1/-1" data-reviewer-accounts>
<h2>{{ __('mobile_settings.ui.reviewers_title') }}</h2>
<p class="warning">{{ __('mobile_settings.ui.reviewer_warning') }}</p>
<form method="post" action="{{ route('admin.mobile-settings.reviewer') }}">
@csrf @method('put')
<div class="row"><div><label>{{ __('mobile_settings.app') }}</label><select name="app"><option value="customer">{{ __('mobile_settings.apps.customer') }}</option><option value="driver">{{ __('mobile_settings.apps.driver') }}</option><option value="van">{{ __('mobile_settings.apps.van') }}</option></select></div><div><label>{{ __('mobile_settings.platform') }}</label><select name="platform"><option value="android">Android</option><option value="ios">iOS</option></select></div></div>
<input type="hidden" name="environment" value="{{ $selectedEnvironment }}">
<div class="row"><div><label>Persona</label><input name="persona" placeholder="customer_reviewer" required></div><div><label>Identifier type</label><select name="identifier_type"><option value="email">email</option><option value="username">username</option><option value="phone">phone</option></select></div></div>
<label>Identifier</label><input name="identifier" required>
<label>{{ __('mobile_settings.ui.reviewer_secret') }}</label><input type="password" name="reviewer_secret" autocomplete="new-password">
<div class="row">
<div><label>{{ __('mobile_settings.reviewer_channel') }}</label><select name="reviewer_channel"><option value="">—</option><option value="b2b">{{ __('mobile_settings.channels.b2b') }}</option><option value="b2c">{{ __('mobile_settings.channels.b2c') }}</option></select></div>
<div><label>{{ __('mobile_settings.reviewer_store') }}</label><select name="reviewer_store_id"><option value="">{{ __('mobile_settings.reviewer_no_store') }}</option>@foreach($reviewerStores as $reviewerStore)<option value="{{ $reviewerStore->id }}" data-channel="{{ strtolower((string)$reviewerStore->channel) }}">{{ $reviewerStore->name }} · {{ __('mobile_settings.channels.'.strtolower((string)$reviewerStore->channel)) }}</option>@endforeach</select></div>
</div>
<label>Deterministic reviewer instructions</label><textarea name="reviewer_instructions"></textarea>
<input type="hidden" name="is_active" value="0"><label class="check"><input type="checkbox" name="is_active" value="1" checked>Active</label>
<button class="button">Save reviewer persona</button>
</form>
<h3>Configured personas</h3>
@forelse($reviewerAccounts as $reviewer)
<div class="policy">
<strong>{{ __('mobile_settings.apps.'.$reviewer->app) }} · {{ strtoupper($reviewer->platform) }} · {{ __('mobile_settings.environments.'.$reviewer->environment) }} · {{ $reviewer->persona }}</strong>
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
