<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>{{ __('sms.title') }} · FOODEX</title>
<style>
body{margin:0;background:var(--foodex-page);color:var(--foodex-ink)}.wrap{max-width:1180px;margin:0 auto;padding:28px 20px 48px}.head{display:flex;justify-content:space-between;gap:16px;align-items:flex-start;margin-bottom:18px}.card{background:#fff;border:1px solid var(--foodex-border);border-radius:var(--foodex-radius-card);padding:20px;box-shadow:var(--foodex-shadow-sm);margin-bottom:16px}.grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px}.field{display:flex;flex-direction:column;gap:7px}.full{grid-column:1/-1}label{font-weight:800}input,select,textarea{border:1px solid var(--foodex-border);border-radius:10px;padding:11px;background:#fff;color:inherit;width:100%;box-sizing:border-box}.switches{display:flex;flex-wrap:wrap;gap:16px}.switches label{display:flex;gap:8px;align-items:center}.switches input{width:auto}.actions{display:flex;gap:10px;flex-wrap:wrap;margin-top:16px}.btn{border:0;border-radius:10px;padding:11px 15px;font-weight:800;cursor:pointer;background:var(--foodex-green);color:#fff;text-decoration:none}.btn.secondary{background:#fff;color:var(--foodex-green-dark);border:1px solid var(--foodex-border)}.notice{padding:12px 14px;border-radius:10px;margin-bottom:14px;background:#eef9f1}.error{background:#fff1f1;color:#9b1c1c}.table-wrap{overflow:auto}table{width:100%;border-collapse:collapse;min-width:760px}th,td{text-align:start;border-bottom:1px solid var(--foodex-border);padding:10px;font-size:.92rem}code{font-size:.82rem}dialog{border:0;border-radius:16px;padding:0;max-width:620px;width:calc(100% - 30px);box-shadow:0 20px 70px #0003}dialog::backdrop{background:#0007}.modal{padding:22px}.muted{color:var(--foodex-muted)}@media(max-width:720px){.grid{grid-template-columns:1fr}.head{flex-direction:column}.full{grid-column:auto}}
</style>@include('admin._brand-components')
</head><body><div class="wrap">
<header class="head"><div><p class="muted">{{ __('sms.eyebrow') }}</p><h1>{{ __('sms.title') }}</h1><p class="muted">{{ __('sms.description') }}</p></div><a class="btn secondary" href="{{ route('admin.administration.index') }}">{{ __('sms.back') }}</a></header>
@if(session('status'))<div class="notice">{{ session('status') }}</div>@endif
@if($errors->any())<div class="notice error"><strong>{{ $errors->first() }}</strong></div>@endif

<section class="card"><h2>{{ __('sms.settings') }}</h2><form method="post" action="{{ route('admin.sms-settings.update') }}">@csrf @method('PUT')
<div class="grid">
<div class="field"><label>{{ __('sms.provider_label') }}</label><select name="provider"><option value="advansys_bulk_sms">{{ __('sms.providers.advansys_bulk_sms') }}</option></select></div>
<div class="field"><label>{{ __('sms.token') }}</label><input type="password" name="api_token" autocomplete="new-password" placeholder="{{ $maskedToken }}"><small class="muted">{{ __('sms.token_hint') }}</small></div>
<div class="field"><label>{{ __('sms.base_url') }}</label><input name="api_base_url" value="{{ old('api_base_url',$setting?->api_base_url ?? 'https://hub.advansystelecom.com') }}" required></div>
<div class="field"><label>{{ __('sms.endpoint') }}</label><input name="endpoint_path" value="{{ old('endpoint_path',$setting?->endpoint_path ?? '/generalapiv12/api/bulkSMS/ForwardSMS') }}" required></div>
<div class="field"><label>{{ __('sms.sender') }}</label><input name="default_sender_name" value="{{ old('default_sender_name',$setting?->default_sender_name) }}" required></div>
<div class="field"><label>{{ __('sms.country_code') }}</label><input name="default_country_code" value="{{ old('default_country_code',$setting?->default_country_code ?? '20') }}" required></div>
<div class="field"><label>{{ __('sms.operator_mode') }}</label><select name="operator_resolution_mode"><option value="automatic" @selected(old('operator_resolution_mode',$setting?->operator_resolution_mode ?? 'automatic')==='automatic')>{{ __('sms.automatic') }}</option><option value="manual" @selected(old('operator_resolution_mode',$setting?->operator_resolution_mode)==='manual')>{{ __('sms.manual') }}</option></select></div>
<div class="field"><label>{{ __('sms.timeout') }}</label><input type="number" min="1" max="30" name="request_timeout" value="{{ old('request_timeout',$setting?->request_timeout ?? 10) }}" required></div>
<div class="field"><label>{{ __('sms.retry_count') }}</label><input type="number" min="0" max="4" name="retry_count" value="{{ old('retry_count',$setting?->retry_count ?? 2) }}" required></div>
<div class="field"><label>{{ __('sms.retry_backoff') }}</label><input type="number" min="0" max="10" name="retry_backoff_seconds" value="{{ old('retry_backoff_seconds',$setting?->retry_backoff_seconds ?? 2) }}" required></div>
<div class="full switches">
<label><input type="checkbox" name="enabled" value="1" @checked(old('enabled',$setting?->enabled))>{{ __('sms.enabled') }}</label>
<label><input type="checkbox" name="delivery_logging" value="1" @checked(old('delivery_logging',$setting?->delivery_logging ?? true))>{{ __('sms.delivery_logging') }}</label>
<label><input type="checkbox" name="otp_sender_enabled" value="1" @checked(old('otp_sender_enabled',$setting?->otp_sender_enabled))>{{ __('sms.otp_enabled') }}</label>
<label><input type="checkbox" name="notification_sender_enabled" value="1" @checked(old('notification_sender_enabled',$setting?->notification_sender_enabled))>{{ __('sms.notifications_enabled') }}</label>
</div></div>
<div class="actions"><button class="btn" type="submit">{{ __('sms.save') }}</button><button class="btn secondary" type="button" onclick="document.getElementById('testSmsDialog').showModal()">{{ __('sms.test') }}</button></div>
</form></section>

<section class="card"><h2>{{ __('sms.recent') }}</h2><div class="table-wrap"><table><thead><tr><th>{{ __('sms.request_id') }}</th><th>{{ __('sms.recipient') }}</th><th>{{ __('sms.purpose') }}</th><th>{{ __('sms.status') }}</th><th>{{ __('sms.attempts') }}</th><th>{{ __('sms.latency') }}</th><th>{{ __('sms.provider_code') }}</th><th>{{ __('sms.timestamp') }}</th></tr></thead><tbody>
@forelse($recentLogs as $log)
<tr><td><code>{{ $log->request_id }}</code></td><td>{{ $log->recipient_masked }}</td><td>{{ $log->purpose }}</td><td>{{ $log->status }}</td><td>{{ $log->attempts }}</td><td>{{ $log->latency_ms ?? '—' }} ms</td><td>{{ $log->status === 'sent' ? __('sms.provider.sent') : ($log->error_message ?: __('sms.provider.unknown_response')) }} @if($log->provider_response_code)<small>({{ $log->provider_response_code }})</small>@endif</td><td>{{ $log->created_at }}</td></tr>
@empty<tr><td colspan="8">{{ __('sms.no_logs') }}</td></tr>@endforelse
</tbody></table></div></section>
</div>

<dialog id="testSmsDialog"><form class="modal" method="post" action="{{ route('admin.sms-settings.test') }}">@csrf
<h2>{{ __('sms.test_title') }}</h2>
@if(session('sms_test_result'))
<div class="notice {{ session('sms_test_result.status') === 'sent' ? '' : 'error' }}">
<strong>{{ session('sms_test_result.meaning') }}</strong><br>
<small>{{ __('sms.request_id') }}: <code>{{ session('sms_test_result.request_id') }}</code> · {{ __('sms.timestamp') }}: {{ session('sms_test_result.timestamp') }} · {{ __('sms.latency') }}: {{ session('sms_test_result.latency_ms') }} ms</small>
</div>
@endif<div class="grid">
<div class="field"><label>{{ __('sms.phone') }}</label><input name="phone" required placeholder="01012345678"></div>
<div class="field"><label>{{ __('sms.operator') }}</label><select name="operator_id"><option value="">{{ __('sms.operator_auto') }}</option><option value="1">{{ __('sms.operators.vodafone') }}</option><option value="2">{{ __('sms.operators.orange') }}</option><option value="3">{{ __('sms.operators.etisalat') }}</option><option value="7">{{ __('sms.operators.we') }}</option></select></div>
<div class="field full"><label>{{ __('sms.message') }}</label><textarea name="message" rows="4" required></textarea></div>
<div class="field full"><label>{{ __('sms.sender_override') }}</label><input name="sender"></div>
</div><div class="actions"><button class="btn" type="submit">{{ __('sms.send') }}</button><button class="btn secondary" type="button" onclick="document.getElementById('testSmsDialog').close()">{{ __('sms.cancel') }}</button></div>
</form></dialog>
@if(session('sms_test_result'))
<script>document.getElementById('testSmsDialog').showModal();</script> {{-- localization-gate: allow — technical dialog control, no user-facing prose. --}}
@endif
</body></html>
