@php
$model=$branchModel;
$hours=$model ? \Illuminate\Support\Facades\DB::table('smart_ordering_branch_hours')->where('branch_id',$model->id)->get()->keyBy('day_of_week') : collect();
$zones=$model ? \Illuminate\Support\Facades\DB::table('smart_ordering_delivery_zones')->where('branch_id',$model->id)->orderBy('name')->get() : collect();
@endphp
<form method="post" action="{{ $formAction }}" class="foodex-form so-grid">
@csrf
@if($method==='PUT')@method('PUT')@endif
@if(!$model)
<label>{{ __('smart_ordering.branch_fields.store') }}<select name="store_id" required>@foreach($stores as $store)<option value="{{ $store->id }}">{{ $store->name }}</option>@endforeach</select></label>
@endif
<label>{{ __('smart_ordering.branch_fields.name') }}<input name="name" required maxlength="160" value="{{ old('name',$model?->name) }}"></label>
<label>{{ __('smart_ordering.branch_fields.timezone') }}<input name="timezone" required value="{{ old('timezone',$model?->timezone ?? 'Asia/Kuwait') }}"></label>
<label>{{ __('smart_ordering.branch_fields.pickup_lead') }}<input type="number" name="pickup_lead_minutes" min="0" max="1440" value="{{ old('pickup_lead_minutes',$model?->pickup_lead_minutes ?? 15) }}"></label>
<label>{{ __('smart_ordering.branch_fields.max_schedule_days') }}<input type="number" name="max_schedule_days" min="1" max="90" value="{{ old('max_schedule_days',$model?->max_schedule_days ?? 7) }}"></label>
<label>{{ __('smart_ordering.branch_fields.service_charge') }}<input type="number" step="0.001" min="0" name="service_charge" value="{{ old('service_charge',$model?->service_charge ?? 0) }}"></label>
<label>{{ __('smart_ordering.branch_fields.whatsapp_identity') }}<input name="whatsapp_routing_identity" maxlength="190" value="{{ old('whatsapp_routing_identity',$model?->whatsapp_routing_identity) }}"></label>
<div class="so-checks" style="grid-column:1/-1">
@foreach([
'is_active'=>'active',
'accepting_orders'=>'accepting',
'supports_dine_in'=>'dine_in',
'supports_pickup'=>'pickup',
'supports_delivery'=>'delivery',
'allow_scheduled'=>'scheduled'
] as $field=>$label)
@php($checked=old($field,$model ? data_get($model,$field) : in_array($field,['is_active','accepting_orders','supports_dine_in','supports_pickup','supports_delivery'],true)))
<label><input type="hidden" name="{{ $field }}" value="0"><input type="checkbox" name="{{ $field }}" value="1" @checked($checked)> {{ __('smart_ordering.branch_fields.'.$label) }}</label>
@endforeach
</div>
<div class="so-hours">
<strong>{{ __('smart_ordering.branch_fields.hours') }}</strong>
@foreach(__('smart_ordering.days') as $day=>$dayLabel)
@php($hour=$hours->get($day))
<div class="so-hour-row">
<strong>{{ $dayLabel }}</strong>
<label>{{ __('smart_ordering.branch_fields.opens') }}<input type="time" name="hours[{{ $day }}][opens_at]" value="{{ old('hours.'.$day.'.opens_at',$hour?->opens_at ? substr($hour->opens_at,0,5) : '') }}"></label>
<label>{{ __('smart_ordering.branch_fields.closes') }}<input type="time" name="hours[{{ $day }}][closes_at]" value="{{ old('hours.'.$day.'.closes_at',$hour?->closes_at ? substr($hour->closes_at,0,5) : '') }}"></label>
<label><input type="hidden" name="hours[{{ $day }}][is_closed]" value="0"><input type="checkbox" name="hours[{{ $day }}][is_closed]" value="1" @checked(old('hours.'.$day.'.is_closed',$hour?->is_closed ?? false))> {{ __('smart_ordering.branch_fields.closed') }}</label>
</div>
@endforeach
</div>
<div class="so-actions" style="grid-column:1/-1"><button class="foodex-primary" type="submit">{{ __('smart_ordering.save_branch') }}</button></div>
</form>

@if($model)
<section class="so-zones">
<div class="so-zone-head"><div><h3>{{ __('smart_ordering.zones.title') }}</h3><p>{{ __('smart_ordering.zones.description') }}</p></div></div>
@foreach($zones as $zone)
<div class="foodex-card so-zone-card">
<form method="post" action="{{ route('admin.smart-ordering.zones.update',[$model->public_id,$zone->public_id]) }}" class="so-zone-grid">
@csrf
@method('PUT')
<label>{{ __('smart_ordering.zones.name') }}<input name="name" required maxlength="160" value="{{ $zone->name }}"></label>
<label>{{ __('smart_ordering.zones.latitude') }}<input type="number" step="0.0000001" min="-90" max="90" name="center_latitude" required value="{{ $zone->center_latitude }}"></label>
<label>{{ __('smart_ordering.zones.longitude') }}<input type="number" step="0.0000001" min="-180" max="180" name="center_longitude" required value="{{ $zone->center_longitude }}"></label>
<label>{{ __('smart_ordering.zones.radius') }}<input type="number" step="0.001" min="0.001" name="radius_km" required value="{{ $zone->radius_km }}"></label>
<label>{{ __('smart_ordering.zones.fee') }}<input type="number" step="0.001" min="0" name="delivery_fee" required value="{{ $zone->delivery_fee }}"></label>
<label>{{ __('smart_ordering.zones.minimum') }}<input type="number" step="0.001" min="0" name="minimum_order" required value="{{ $zone->minimum_order }}"></label>
<label class="so-zone-check"><input type="hidden" name="is_active" value="0"><input type="checkbox" name="is_active" value="1" @checked($zone->is_active)> {{ __('smart_ordering.zones.active') }}</label>
<div class="so-actions"><button class="foodex-primary" type="submit">{{ __('smart_ordering.zones.save') }}</button></div>
</form>
<form method="post" action="{{ route('admin.smart-ordering.zones.destroy',[$model->public_id,$zone->public_id]) }}" onsubmit="return confirm({{ \Illuminate\Support\Js::from(__('smart_ordering.zones.confirm_delete')) }})">
@csrf
@method('DELETE')
<button class="foodex-action-secondary" type="submit">{{ __('smart_ordering.zones.delete') }}</button>
</form>
</div>
@endforeach

<div class="foodex-card so-zone-card">
<h4>{{ __('smart_ordering.zones.add') }}</h4>
<form method="post" action="{{ route('admin.smart-ordering.zones.store',$model->public_id) }}" class="so-zone-grid">
@csrf
<label>{{ __('smart_ordering.zones.name') }}<input name="name" required maxlength="160"></label>
<label>{{ __('smart_ordering.zones.latitude') }}<input type="number" step="0.0000001" min="-90" max="90" name="center_latitude" required></label>
<label>{{ __('smart_ordering.zones.longitude') }}<input type="number" step="0.0000001" min="-180" max="180" name="center_longitude" required></label>
<label>{{ __('smart_ordering.zones.radius') }}<input type="number" step="0.001" min="0.001" name="radius_km" required></label>
<label>{{ __('smart_ordering.zones.fee') }}<input type="number" step="0.001" min="0" name="delivery_fee" required value="0"></label>
<label>{{ __('smart_ordering.zones.minimum') }}<input type="number" step="0.001" min="0" name="minimum_order" required value="0"></label>
<label class="so-zone-check"><input type="hidden" name="is_active" value="0"><input type="checkbox" name="is_active" value="1" checked> {{ __('smart_ordering.zones.active') }}</label>
<div class="so-actions"><button class="foodex-primary" type="submit">{{ __('smart_ordering.zones.add') }}</button></div>
</form>
</div>
</section>
@endif
