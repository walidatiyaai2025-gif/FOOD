<!doctype html>
<html lang="{{ app()->getLocale() }}" dir="{{ app()->getLocale()==='ar'?'rtl':'ltr' }}">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>{{ __('sidebar_admin.layout_title') }}</title>
<style>
body{margin:0}.layout-page{padding:24px;max-width:1600px;margin:auto}.hero,.panel{background:var(--foodex-surface);border:1px solid var(--foodex-border);border-radius:16px}.hero{padding:20px;margin-bottom:16px}.hero h1{margin:0 0 6px}.muted{color:var(--foodex-muted)}.notice,.errors{padding:12px 14px;border-radius:12px;margin-bottom:14px}.notice{background:var(--foodex-green-soft)}.errors{background:var(--foodex-orange-soft)}.panel{padding:16px;margin-bottom:16px}.panel h2{margin-top:0}.table-wrap{overflow:auto}.config-table{width:100%;border-collapse:collapse;min-width:1080px}.config-table th,.config-table td{padding:9px;border-bottom:1px solid var(--foodex-border);text-align:start;vertical-align:middle}.config-table th{background:var(--foodex-background);font-size:.8rem;color:var(--foodex-muted)}input,select{font:inherit}.config-table input[type=text],.config-table input[type=number],.config-table select{width:100%;min-height:38px;border:1px solid var(--foodex-border);border-radius:9px;padding:7px 9px;background:var(--foodex-surface);color:var(--foodex-ink)}.config-table input[type=number]{min-width:84px}.key{font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:.76rem}.protected{font-size:.72rem;border-radius:999px;padding:3px 7px;background:var(--foodex-background)}.actions{position:sticky;bottom:0;display:flex;justify-content:flex-end;padding:14px 0;background:linear-gradient(transparent,var(--foodex-background) 35%)}.save{border:0;border-radius:11px;background:var(--foodex-green);color:#fff;padding:11px 18px;font-weight:900;cursor:pointer}@media(max-width:767px){.layout-page{padding:12px}.panel{padding:10px}}
</style>
@include('admin._brand-components')
</head>
<body>
<div class="foodex-admin-layout" data-foodex-mobile-web-page="sidebar-layout">
<aside class="sidebar">@include('admin._sidebar',['navGroups'=>$navGroups,'navContext'=>$navContext,'user'=>$user])</aside>
<main class="foodex-admin-main layout-page">
@include('admin._global-header')
<div class="hero foodex-page-toolbar"><div><div class="muted">{{ __('sidebar_admin.eyebrow') }}</div><h1>{{ __('sidebar_admin.layout_title') }}</h1><p class="muted">{{ __('sidebar_admin.layout_description') }}</p></div></div>
@if(session('status'))<div class="notice" role="status">{{ session('status') }}</div>@endif
@if($errors->any())<div class="errors" role="alert"><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif

<form method="post" action="{{ route('admin.sidebar-layout.update') }}">
@csrf @method('put')
<section class="panel">
<h2>{{ __('sidebar_admin.groups_title') }}</h2>
<div class="table-wrap"><table class="config-table" data-pagination-exempt="static-sidebar-configuration">
<thead><tr><th>{{ __('sidebar_admin.enabled') }}</th><th>{{ __('sidebar_admin.key') }}</th><th>{{ __('sidebar_admin.arabic_text') }}</th><th>{{ __('sidebar_admin.english_text') }}</th><th>{{ __('sidebar_admin.icon') }}</th><th>{{ __('sidebar_admin.order') }}</th></tr></thead>
<tbody>
@foreach($layout['groups'] as $group)
<tr>
<td><input type="hidden" name="groups[{{ $group['key'] }}][is_enabled]" value="0"><input type="checkbox" name="groups[{{ $group['key'] }}][is_enabled]" value="1" @checked($group['is_enabled']) @disabled(in_array($group['key'],$protectedGroups,true))>@if(in_array($group['key'],$protectedGroups,true))<input type="hidden" name="groups[{{ $group['key'] }}][is_enabled]" value="1"><span class="protected">{{ __('sidebar_admin.protected') }}</span>@endif</td>
<td class="key">{{ $group['key'] }}</td>
<td><input type="text" name="groups[{{ $group['key'] }}][label_ar]" value="{{ $group['label_ar'] }}" placeholder="{{ __($group['label'],[],'ar') }}"></td>
<td><input type="text" name="groups[{{ $group['key'] }}][label_en]" value="{{ $group['label_en'] }}" placeholder="{{ __($group['label'],[],'en') }}"></td>
<td><input type="text" name="groups[{{ $group['key'] }}][icon]" value="{{ $group['icon'] }}" maxlength="16"></td>
<td><input type="number" name="groups[{{ $group['key'] }}][sort_order]" value="{{ $group['sort_order'] }}"></td>
</tr>
@endforeach
</tbody></table></div>
</section>

<section class="panel">
<h2>{{ __('sidebar_admin.items_title') }}</h2>
<p class="muted">{{ __('sidebar_admin.parent_help') }}</p>
<div class="table-wrap"><table class="config-table">
<thead><tr><th>{{ __('sidebar_admin.enabled') }}</th><th>{{ __('sidebar_admin.page') }}</th><th>{{ __('sidebar_admin.group') }}</th><th>{{ __('sidebar_admin.parent') }}</th><th>{{ __('sidebar_admin.arabic_text') }}</th><th>{{ __('sidebar_admin.english_text') }}</th><th>{{ __('sidebar_admin.order') }}</th></tr></thead>
<tbody>
@foreach($layout['items'] as $item)
<tr>
<td>
<input type="hidden" name="items[{{ $item['key'] }}][is_enabled]" value="0">
<input type="checkbox" name="items[{{ $item['key'] }}][is_enabled]" value="1" @checked($item['is_enabled']) @disabled(in_array($item['key'],$protectedItems,true))>
@if(in_array($item['key'],$protectedItems,true))<input type="hidden" name="items[{{ $item['key'] }}][is_enabled]" value="1">@endif
</td>
<td><div>{{ __($item['label']) }}</div><div class="key">{{ $item['key'] }}</div>@if(in_array($item['key'],$protectedItems,true))<span class="protected">{{ __('sidebar_admin.protected') }}</span>@endif</td>
<td><select name="items[{{ $item['key'] }}][group_key]">@foreach($layout['groups'] as $group)<option value="{{ $group['key'] }}" @selected($item['group_key']===$group['key'])>{{ ($group['label_'.app()->getLocale()] ?? null) ?: __($group['label']) }}</option>@endforeach</select></td>
<td><select name="items[{{ $item['key'] }}][parent_key]"><option value="">{{ __('sidebar_admin.no_parent') }}</option>@foreach($layout['items'] as $candidate)@if($candidate['key']!==$item['key'])<option value="{{ $candidate['key'] }}" @selected($item['parent_key']===$candidate['key'])>{{ __($candidate['label']) }} · {{ $candidate['key'] }}</option>@endif @endforeach</select></td>
<td><input type="text" name="items[{{ $item['key'] }}][label_ar]" value="{{ $item['label_ar'] }}" placeholder="{{ __($item['label'],[],'ar') }}"></td>
<td><input type="text" name="items[{{ $item['key'] }}][label_en]" value="{{ $item['label_en'] }}" placeholder="{{ __($item['label'],[],'en') }}"></td>
<td><input type="number" name="items[{{ $item['key'] }}][sort_order]" value="{{ $item['sort_order'] }}"></td>
</tr>
@endforeach
</tbody></table></div>
</section>
<div class="actions"><button class="save" type="submit">{{ __('sidebar_admin.save_layout') }}</button></div>
</form>
</main>
</div>
</body></html>
