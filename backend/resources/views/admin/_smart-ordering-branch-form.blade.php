@php
$model=$branchModel;
$hours=$model ? IlluminateSupportFacadesDB::table('smart_ordering_branch_hours')->where('branch_id',$model->id)->get()->keyBy('day_of_week') : collect();
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
<label><input type="hidden" name="{{ $field }}" value="0"><input type="checkbox" name="{{ $field }}" value="1" @checked(old($field,$model?->{$field} ?? in_array($field,['is_active','accepting_orders','supports_dine_in','supports_pickup','supports_delivery'],true)))> {{ __('smart_ordering.branch_fields.'.$label) }}</label>
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
