<!doctype html>
<html lang="{{ app()->getLocale() }}" dir="{{ app()->getLocale()==='ar' ? 'rtl':'ltr' }}">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>{{ __('restaurant_growth.title') }}</title>
@include('admin._brand-components')
@include('admin._settings-page-polish')
<style>
.rg-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,330px),1fr));gap:16px}.rg-form{display:grid;gap:10px}.rg-form label{display:grid;gap:5px}.rg-table{width:100%;border-collapse:collapse}.rg-table th,.rg-table td{padding:9px;border-bottom:1px solid var(--foodex-border);text-align:start}.rg-wrap{overflow:auto}.rg-code{font-family:ui-monospace,monospace;word-break:break-all}.rg-kpis{display:grid;grid-template-columns:repeat(4,minmax(120px,1fr));gap:10px}.rg-kpi{border:1px solid var(--foodex-border);border-radius:12px;padding:12px}.rg-kpi strong{display:block;font-size:1.25rem}@media(max-width:767px){.rg-kpis{grid-template-columns:1fr 1fr}.rg-table{min-width:680px}}
</style>
</head>
<body>
<div class="foodex-admin-layout" data-foodex-mobile-web-page="restaurant-growth">
<aside class="sidebar">@include('admin._sidebar',['navGroups'=>app(\App\Support\AdminNavigation::class)->groupsFor($user),'navContext'=>'smart_ordering','user'=>$user])</aside>
<main class="foodex-admin-main foodex-admin-page foodex-settings-surface">
@include('admin._global-header')
<div class="foodex-page-toolbar"><div><h1>{{ __('restaurant_growth.title') }}</h1><p>{{ __('restaurant_growth.subtitle') }}</p></div><a class="foodex-action-primary" href="{{ route('admin.smart-ordering.index',['store_id'=>$site->store_id] + (request()->boolean('support_access') ? ['support_access'=>1] : [])) }}">{{ __('restaurant_growth.back') }}</a></div>
@include('admin._smart-ordering-workspace-nav')
@if(session('status'))<div class="foodex-alert success">{{ session('status') }}</div>@endif
@if(session('restaurant_gift_card_code'))<section class="panel"><strong>{{ __('restaurant_growth.one_time_code') }}</strong><div class="rg-code">{{ session('restaurant_gift_card_code') }}</div></section>@endif
@if($errors->any())<div class="foodex-alert error">{{ $errors->first() }}</div>@endif

<div class="rg-grid">
<section class="panel"><h2>{{ __('restaurant_growth.gift_cards') }}</h2>
<form class="rg-form" method="post" action="{{ route('admin.smart-ordering.growth.gift-cards.issue',$site) }}">@csrf
<input type="hidden" name="idempotency_key" value="{{ (string) \Illuminate\Support\Str::uuid() }}">
<label>{{ __('restaurant_growth.amount') }}<input class="foodex-control" type="number" step="0.001" min="0.001" name="amount" required></label>
<label>{{ __('restaurant_growth.currency') }}<input class="foodex-control" name="currency" value="KWD" maxlength="3" required></label>
<label>{{ __('restaurant_growth.scope') }}<select class="foodex-control" name="scope">@foreach(['brand','location','organization'] as $scope)<option value="{{ $scope }}">{{ __('restaurant_growth.scope_options.'.$scope) }}</option>@endforeach</select></label>
<label>{{ __('restaurant_growth.expires_at') }}<input class="foodex-control" type="datetime-local" name="expires_at"></label>
<button class="primary" type="submit">{{ __('restaurant_growth.issue') }}</button></form>
<hr>
<form class="rg-form" method="post" action="{{ route('admin.smart-ordering.growth.gift-cards.redeem',$site) }}">@csrf
<label>{{ __('restaurant_growth.code') }}<input class="foodex-control" name="code" required></label>
<label>{{ __('restaurant_growth.amount') }}<input class="foodex-control" type="number" step="0.001" min="0.001" name="amount" required></label>
<label>{{ __('restaurant_growth.idempotency_key') }}<input class="foodex-control" name="idempotency_key" value="{{ (string) \Illuminate\Support\Str::uuid() }}" required></label>
<label>{{ __('restaurant_growth.reference') }}<input class="foodex-control" name="reference"></label>
<button class="primary" type="submit">{{ __('restaurant_growth.redeem') }}</button></form>
<div class="rg-wrap"><table class="rg-table" data-pagination-exempt="bounded-100-gift-cards"><thead><tr><th>{{ __('restaurant_growth.code') }}</th><th>{{ __('restaurant_growth.currency') }}</th><th>{{ __('restaurant_growth.status') }}</th></tr></thead><tbody>@foreach($giftCards as $card)<tr><td>{{ $card->display_code }}</td><td>{{ $card->currency }}</td><td><form method="post" action="{{ route('admin.smart-ordering.growth.gift-cards.status',[$site,$card->public_id]) }}">@csrf @method('patch')<select class="foodex-control" name="status">@foreach(['active','suspended','closed'] as $status)<option value="{{ $status }}" @selected($card->status===$status)>{{ __('restaurant_growth.status_options.'.$status) }}</option>@endforeach</select><button class="primary" type="submit">{{ __('restaurant_growth.update_status') }}</button></form></td></tr>@endforeach</tbody></table></div>
</section>

<section class="panel"><h2>{{ __('restaurant_growth.lifecycle') }}</h2>
<form class="rg-form" method="post" action="{{ route('admin.smart-ordering.growth.journeys.store',$site) }}">@csrf
<label>{{ __('restaurant_growth.journey_name') }}<input class="foodex-control" name="name" required></label>
<label>{{ __('restaurant_growth.trigger_event') }}<input class="foodex-control" name="trigger_event" placeholder="guest.lapsed" required></label>
<label>{{ __('restaurant_growth.channel') }}<select class="foodex-control" name="channel">@foreach(['whatsapp','push','email','internal'] as $channel)<option value="{{ $channel }}">{{ __('restaurant_growth.channels.'.$channel) }}</option>@endforeach</select></label>
<label>{{ __('restaurant_growth.segment_key') }}<input class="foodex-control" name="segment_key"></label>
<label>{{ __('restaurant_growth.scope') }}<select class="foodex-control" name="scope">@foreach(['brand','location','organization'] as $scope)<option value="{{ $scope }}">{{ __('restaurant_growth.scope_options.'.$scope) }}</option>@endforeach</select></label>
<label><input type="checkbox" name="consent_required" value="1" checked> {{ __('restaurant_growth.consent_required') }}</label>
<button class="primary" type="submit">{{ __('restaurant_growth.save_journey') }}</button></form>
<div class="rg-wrap"><table class="rg-table" data-pagination-exempt="bounded-100-journeys"><tbody>@foreach($journeys as $journey)<tr><td>{{ $journey->name }}</td><td>{{ $journey->trigger_event }}</td><td>{{ __('restaurant_growth.channels.'.$journey->channel) }}</td></tr>@endforeach</tbody></table></div>
</section>

<section class="panel"><h2>{{ __('restaurant_growth.franchise') }}</h2>
<form class="rg-form" method="post" action="{{ route('admin.smart-ordering.growth.policies.store',$site) }}">@csrf
<label>{{ __('restaurant_growth.level') }}<select class="foodex-control" name="level">@foreach(['organization','brand','location'] as $scope)<option value="{{ $scope }}">{{ __('restaurant_growth.scope_options.'.$scope) }}</option>@endforeach</select></label>
<label>{{ __('restaurant_growth.policy_key') }}<input class="foodex-control" name="policy_key" placeholder="menu.price_override" required></label>
<label>{{ __('restaurant_growth.policy_value') }}<input class="foodex-control" name="policy_value" required></label>
<label><input type="checkbox" name="is_locked" value="1"> {{ __('restaurant_growth.lock_children') }}</label>
<button class="primary" type="submit">{{ __('restaurant_growth.save_policy') }}</button></form>
<div class="rg-wrap"><table class="rg-table" data-pagination-exempt="bounded-200-policies"><tbody>@foreach($policies as $policy)<tr><td>{{ $policy->scope_key }}</td><td>{{ $policy->policy_key }}</td><td>{{ $policy->is_locked?'🔒':'—' }}</td></tr>@endforeach</tbody></table></div>
</section>
</div>

<section class="panel"><h2>{{ __('restaurant_growth.analytics') }}</h2>
<div class="rg-wrap"><table class="rg-table" data-pagination-exempt="bounded-organization-locations"><thead><tr><th>{{ __('restaurant_growth.location') }}</th><th>{{ __('restaurant_growth.orders') }}</th><th>{{ __('restaurant_growth.completed_sales') }}</th><th>{{ __('restaurant_growth.average_ticket') }}</th><th>{{ __('restaurant_growth.reservations') }}</th><th>{{ __('restaurant_growth.no_show_rate') }}</th></tr></thead><tbody>
@foreach($analytics['locations'] as $row)<tr><td>{{ $row['name'] }}</td><td>{{ $row['orders'] }}</td><td>{{ number_format($row['completed_sales'],3) }}</td><td>{{ number_format($row['average_ticket'],3) }}</td><td>{{ $row['reservations'] }}</td><td>{{ number_format($row['reservation_no_show_rate']*100,2) }}%</td></tr>@endforeach
</tbody></table></div></section>

<section class="panel"><h2>{{ __('restaurant_growth.accounting') }}</h2>
<p><a class="primary" href="{{ route('admin.smart-ordering.growth.accounting.download',$site) }}">{{ __('restaurant_growth.download_accounting') }}</a></p>
<div class="rg-wrap"><table class="rg-table" data-pagination-exempt="bounded-accounting-summary"><thead><tr><th>{{ __('restaurant_growth.sales_date') }}</th><th>{{ __('restaurant_growth.store') }}</th><th>{{ __('restaurant_growth.currency') }}</th><th>{{ __('restaurant_growth.completed_sales') }}</th><th>{{ __('restaurant_growth.discounts') }}</th><th>{{ __('restaurant_growth.delivery') }}</th><th>{{ __('restaurant_growth.refunds') }}</th></tr></thead><tbody>
@foreach($accounting['rows'] as $row)<tr><td>{{ $row['sales_date'] }}</td><td>{{ $row['store_id'] }}</td><td>{{ $row['currency'] }}</td><td>{{ number_format($row['net_sales'],3) }}</td><td>{{ number_format($row['discounts'],3) }}</td><td>{{ number_format($row['delivery'],3) }}</td><td>{{ number_format($row['refunded_value'],3) }}</td></tr>@endforeach
</tbody></table></div></section>

<section class="panel"><h2>{{ __('restaurant_growth.portability') }}</h2><p>{{ __('restaurant_growth.export_help') }}</p>
<form method="post" action="{{ route('admin.smart-ordering.growth.exports.store',$site) }}">@csrf<button class="primary" type="submit">{{ __('restaurant_growth.generate_export') }}</button></form>
<div class="rg-wrap"><table class="rg-table" data-pagination-exempt="bounded-50-exports"><thead><tr><th>{{ __('restaurant_growth.status') }}</th><th>{{ __('restaurant_growth.checksum') }}</th><th>{{ __('restaurant_growth.completed_at') }}</th><th></th></tr></thead><tbody>@foreach($exports as $export)<tr><td>{{ $export->status }}</td><td class="rg-code">{{ $export->checksum_sha256 }}</td><td>{{ $export->completed_at }}</td><td><a href="{{ route('admin.smart-ordering.growth.exports.download',[$site,$export->public_id]) }}">{{ __('restaurant_growth.download') }}</a></td></tr>@endforeach</tbody></table></div>
</section>
</main></div></body></html>
