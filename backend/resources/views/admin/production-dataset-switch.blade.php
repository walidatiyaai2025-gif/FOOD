<!doctype html>
<html lang="{{ app()->getLocale() }}" dir="{{ app()->getLocale()==='ar'?'rtl':'ltr' }}"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>{{ __('production_dataset_switch.title') }} · FOODEX</title>@include('admin._brand-components')
<style>body{margin:0}.dataset-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,210px),1fr));gap:var(--foodex-space-3);margin-block:var(--foodex-space-4)}.dataset-card{padding:var(--foodex-space-4)}.dataset-card strong{display:block;margin-top:var(--foodex-space-1);overflow-wrap:anywhere}.dataset-actions{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,320px),1fr));gap:var(--foodex-space-4);margin-top:var(--foodex-space-5)}.dataset-action{padding:var(--foodex-space-5)}.dataset-action form{display:grid;gap:var(--foodex-space-3)}.dataset-action input{width:100%;min-width:0}.danger{border-color:var(--foodex-red)}</style></head>
<body><div class="foodex-admin-layout" data-foodex-mobile-web-page="production-dataset-switch">
<aside class="sidebar">
@include('admin._sidebar',['navGroups'=>app(\App\Support\AdminNavigation::class)->groupsFor(auth()->user()),'navContext'=>'administration_hub','user'=>auth()->user()])
</aside>
<main class="foodex-admin-main foodex-admin-page" data-production-dataset-switch="true" data-active-mode="{{ $activeMode }}">
@include('admin._global-header')
@include('admin._admin-center-switcher',['active'=>'settings'])
<div class="foodex-page-toolbar"><div><h1>{{ __('production_dataset_switch.title') }}</h1><p>{{ __('production_dataset_switch.description') }}</p></div><div class="foodex-header-actions"><a class="foodex-action-secondary" href="{{ route('admin.settings.index') }}">{{ __('production_dataset_switch.back') }}</a></div></div>
@if(session('status'))<div class="foodex-state" role="status">{{ session('status') }}</div>@endif
@if($errors->any())<div class="foodex-state" role="alert"><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
<section><h2>{{ __('production_dataset_switch.status_title') }}</h2><div class="dataset-grid">
<div class="foodex-card dataset-card"><span>{{ __('production_dataset_switch.active_mode') }}</span><strong data-dataset-mode="{{ $activeMode }}">{{ __("production_dataset_switch.modes.$activeMode") }}</strong></div>
<div class="foodex-card dataset-card"><span>{{ __('production_dataset_switch.snapshot_id') }}</span><strong dir="ltr">{{ $dataset['snapshot_id']??'—' }}</strong></div>
<div class="foodex-card dataset-card"><span>{{ __('production_dataset_switch.snapshot_status') }}</span><strong>{{ __("production_dataset_switch.states.".($dataset['state']??'no_snapshot')) }}</strong></div>
<div class="foodex-card dataset-card"><span>{{ __('production_dataset_switch.snapshot_time') }}</span><strong dir="ltr">{{ $dataset['created_at']??'—' }}</strong></div>
<div class="foodex-card dataset-card"><span>{{ __('production_dataset_switch.restore_verified') }}</span><strong>{{ ($dataset['verified']??false)?__('production_dataset_switch.yes'):__('production_dataset_switch.no') }}</strong></div>
<div class="foodex-card dataset-card"><span>{{ __('production_dataset_switch.launch_ready') }}</span><strong>{{ $preview['ready']?__('production_dataset_switch.yes'):__('production_dataset_switch.no') }}</strong></div>
<div class="foodex-card dataset-card"><span>{{ __('production_dataset_switch.impact') }}</span><strong>{{ trans_choice('production_dataset_switch.rows',$preview['total'],['count'=>$preview['total']]) }}</strong></div>
<div class="foodex-card dataset-card"><span>{{ __('production_dataset_switch.last_transition') }}</span><strong dir="ltr">{{ $lastTransition?->created_at?->toIso8601String()??'—' }}</strong></div>
<div class="foodex-card dataset-card"><span>{{ __('production_dataset_switch.audit_reference') }}</span><strong dir="ltr">{{ $lastTransition?'#'.$lastTransition->id:'—' }}</strong></div>
</div></section>
<section class="dataset-actions">
<article class="foodex-card dataset-action"><h2>{{ __('production_dataset_switch.launch_title') }}</h2><p>{{ __('production_dataset_switch.launch_help') }}</p><form method="post" action="{{ route('admin.production-dataset-switch.launch') }}" data-switch-form="launch" data-switch-enabled="{{ $activeMode==='legacy'&&$preview['ready']?'true':'false' }}">@csrf
<label>{{ __('production_dataset_switch.confirm_label') }} <code>{{ \App\Http\Controllers\Admin\ProductionDatasetSwitchController::LAUNCH_CONFIRMATION }}</code></label><input name="confirmation" autocomplete="off" required @disabled($activeMode!=='legacy'||!$preview['ready'])>
<label>{{ __('production_dataset_switch.password') }}</label><input type="password" name="current_password" autocomplete="current-password" required @disabled($activeMode!=='legacy'||!$preview['ready'])>
<label><input type="checkbox" name="understood" value="1" required @disabled($activeMode!=='legacy'||!$preview['ready'])> {{ __('production_dataset_switch.launch_ack') }}</label><button class="foodex-primary" type="submit" @disabled($activeMode!=='legacy'||!$preview['ready'])>{{ __('production_dataset_switch.launch_submit') }}</button></form></article>
<article class="foodex-card dataset-action danger"><h2>{{ __('production_dataset_switch.legacy_title') }}</h2><p>{{ __('production_dataset_switch.legacy_help') }}</p><form method="post" action="{{ route('admin.production-dataset-switch.legacy') }}" data-switch-form="legacy" data-switch-enabled="{{ $activeMode==='launch'?'true':'false' }}">@csrf
<label>{{ __('production_dataset_switch.confirm_label') }} <code>{{ \App\Http\Controllers\Admin\ProductionDatasetSwitchController::LEGACY_CONFIRMATION }}</code></label><input name="confirmation" autocomplete="off" required @disabled($activeMode!=='launch')>
<label>{{ __('production_dataset_switch.password') }}</label><input type="password" name="current_password" autocomplete="current-password" required @disabled($activeMode!=='launch')>
<label><input type="checkbox" name="understood" value="1" required @disabled($activeMode!=='launch')> {{ __('production_dataset_switch.legacy_ack') }}</label><button class="foodex-primary" type="submit" @disabled($activeMode!=='launch')>{{ __('production_dataset_switch.legacy_submit') }}</button></form></article>
</section></main></div>
<script>document.querySelectorAll('[data-switch-form]').forEach(form=>form.addEventListener('submit',event=>{if(form.dataset.busy==='true'){event.preventDefault();return;}form.dataset.busy='true';form.setAttribute('aria-busy','true');const b=form.querySelector('button');if(b)b.disabled=true;}));</script></body></html>
