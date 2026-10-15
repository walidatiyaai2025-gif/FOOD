@php
$model=$branchModel;
$hours=$model ? \Illuminate\Support\Facades\DB::table('smart_ordering_branch_hours')->where('branch_id',$model->id)->get()->keyBy('day_of_week') : collect();
$zones=$model ? \Illuminate\Support\Facades\DB::table('smart_ordering_delivery_zones')->where('branch_id',$model->id)->orderBy('name')->get() : collect();
$timezoneControlId='smart-ordering-timezone-'.($model?->id ?? 'new');
$selectedTimezone=(string) old('timezone',$model?->timezone ?? 'Asia/Kuwait');
$timezoneValues=collect($timezoneOptions)->pluck('value');
$legacyTimezone=$selectedTimezone !== '' && !$timezoneValues->contains($selectedTimezone);
@endphp
<form method="post" action="{{ $formAction }}" class="foodex-form so-grid">
@csrf
@if($method==='PUT')@method('PUT')@endif
@if(!$model)
<label>{{ __('smart_ordering.branch_fields.store') }}<select name="store_id" required>@foreach($stores as $store)<option value="{{ $store->id }}">{{ $store->name }}</option>@endforeach</select></label>
@endif
<label>{{ __('smart_ordering.branch_fields.name') }}<input name="name" required maxlength="160" value="{{ old('name',$model?->name) }}"></label>
<div class="so-timezone-picker" data-timezone-picker>
<label for="{{ $timezoneControlId }}-search">{{ __('smart_ordering.branch_fields.timezone') }}</label>
<input id="{{ $timezoneControlId }}-search" type="search" autocomplete="off" data-timezone-search aria-controls="{{ $timezoneControlId }}" placeholder="{{ __('smart_ordering.branch_fields.timezone_search') }}">
<select id="{{ $timezoneControlId }}" name="timezone" required data-timezone-select>
@if($legacyTimezone)<option value="{{ $selectedTimezone }}" selected disabled data-legacy="1">{{ __('smart_ordering.branch_fields.timezone_legacy', ['zone'=>$selectedTimezone]) }}</option>@endif
@foreach($timezoneOptions as $timezone)
<option value="{{ $timezone['value'] }}" data-search="{{ $timezone['search'] }}" @selected(!$legacyTimezone && $selectedTimezone===$timezone['value'])>{{ $timezone['label'] }}</option>
@endforeach
</select>
<small>{{ __('smart_ordering.branch_fields.timezone_hint') }}</small>
@if($legacyTimezone)<div class="foodex-alert error" role="alert">{{ __('smart_ordering.branch_fields.timezone_legacy_help') }}</div>@endif
<small data-timezone-empty hidden>{{ __('smart_ordering.branch_fields.timezone_empty') }}</small>
<small data-timezone-status role="status" aria-live="polite"></small>
</div>
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
@php($zoneMapPayload=$zones->map(fn($entry)=>['id'=>(string)$entry->public_id,'name'=>(string)$entry->name,'lat'=>(float)$entry->center_latitude,'lng'=>(float)$entry->center_longitude,'radius'=>(float)$entry->radius_km])->values())
<section class="so-zones">
<div class="so-zone-head"><div><h3>{{ __('smart_ordering.zones.title') }}</h3><p>{{ __('smart_ordering.zones.description') }}</p></div></div>
@foreach($zones as $zone)
<div class="foodex-card so-zone-card">
<form method="post" action="{{ route('admin.smart-ordering.zones.update',[$model->public_id,$zone->public_id]) }}" class="so-zone-grid" data-zone-form>
@csrf
@method('PUT')
<label>{{ __('smart_ordering.zones.name') }}<input name="name" required maxlength="160" value="{{ $zone->name }}"></label>
<div class="so-zone-map-editor" data-zone-map-editor data-current-zone="{{ $zone->public_id }}" data-existing-zones="{{ $zoneMapPayload->toJson() }}">
<div class="so-zone-map-head"><div><strong>{{ __('smart_ordering.zones.map_title') }}</strong><p>{{ __('smart_ordering.zones.map_help') }}</p></div></div>
<div class="so-zone-location-search">
<label>{{ __('smart_ordering.zones.map_search') }}<input type="search" autocomplete="street-address" data-zone-location-search placeholder="{{ __('smart_ordering.zones.map_search_placeholder') }}"></label>
<button class="foodex-action-secondary" type="button" data-zone-search-button>{{ __('smart_ordering.zones.map_search_action') }}</button>
</div>
<div class="so-zone-search-results" data-zone-search-results role="listbox" aria-label="{{ __('smart_ordering.zones.map_search') }}" hidden></div>
<div class="so-zone-map-canvas" data-zone-map-canvas tabindex="0" role="application" aria-label="{{ __('smart_ordering.zones.map_title') }}" aria-describedby="zone-map-help-{{ $zone->public_id }}"></div>
<p id="zone-map-help-{{ $zone->public_id }}" class="so-zone-map-hint">{{ __('smart_ordering.zones.map_keyboard') }}</p>
<div class="so-zone-map-status" data-zone-map-status role="status" aria-live="polite"></div>
</div>
<label>{{ __('smart_ordering.zones.radius') }}<input type="number" step="0.001" min="0.001" max="1000" name="radius_km" required value="{{ $zone->radius_km }}" data-zone-radius><small>{{ __('smart_ordering.zones.radius_hint') }}</small></label>
<label>{{ __('smart_ordering.zones.fee') }}<input type="number" step="0.001" min="0" name="delivery_fee" required value="{{ $zone->delivery_fee }}"></label>
<label>{{ __('smart_ordering.zones.minimum') }}<input type="number" step="0.001" min="0" name="minimum_order" required value="{{ $zone->minimum_order }}"></label>
<details class="so-zone-advanced">
<summary>{{ __('smart_ordering.zones.advanced_geometry') }}</summary>
<p>{{ __('smart_ordering.zones.advanced_geometry_help') }}</p>
<div class="so-zone-coordinate-grid">
<label>{{ __('smart_ordering.zones.latitude') }}<input type="number" step="0.0000001" min="-90" max="90" name="center_latitude" required value="{{ $zone->center_latitude }}" data-zone-latitude></label>
<label>{{ __('smart_ordering.zones.longitude') }}<input type="number" step="0.0000001" min="-180" max="180" name="center_longitude" required value="{{ $zone->center_longitude }}" data-zone-longitude></label>
</div>
</details>
<label class="so-zone-check"><input type="hidden" name="is_active" value="0"><input type="checkbox" name="is_active" value="1" @checked($zone->is_active)> {{ __('smart_ordering.zones.active') }}</label>
<div class="so-actions"><button class="foodex-primary" type="submit" data-zone-submit>{{ __('smart_ordering.zones.save') }}</button></div>
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
<form method="post" action="{{ route('admin.smart-ordering.zones.store',$model->public_id) }}" class="so-zone-grid" data-zone-form>
@csrf
<label>{{ __('smart_ordering.zones.name') }}<input name="name" required maxlength="160"></label>
<div class="so-zone-map-editor" data-zone-map-editor data-current-zone="" data-existing-zones="{{ $zoneMapPayload->toJson() }}">
<div class="so-zone-map-head"><div><strong>{{ __('smart_ordering.zones.map_title') }}</strong><p>{{ __('smart_ordering.zones.map_help') }}</p></div></div>
<div class="so-zone-location-search">
<label>{{ __('smart_ordering.zones.map_search') }}<input type="search" autocomplete="street-address" data-zone-location-search placeholder="{{ __('smart_ordering.zones.map_search_placeholder') }}"></label>
<button class="foodex-action-secondary" type="button" data-zone-search-button>{{ __('smart_ordering.zones.map_search_action') }}</button>
</div>
<div class="so-zone-search-results" data-zone-search-results role="listbox" aria-label="{{ __('smart_ordering.zones.map_search') }}" hidden></div>
<div class="so-zone-map-canvas" data-zone-map-canvas tabindex="0" role="application" aria-label="{{ __('smart_ordering.zones.map_title') }}" aria-describedby="zone-map-help-new-{{ $model->public_id }}"></div>
<p id="zone-map-help-new-{{ $model->public_id }}" class="so-zone-map-hint">{{ __('smart_ordering.zones.map_keyboard') }}</p>
<div class="so-zone-map-status" data-zone-map-status role="status" aria-live="polite"></div>
</div>
<label>{{ __('smart_ordering.zones.radius') }}<input type="number" step="0.001" min="0.001" max="1000" name="radius_km" required value="5" data-zone-radius><small>{{ __('smart_ordering.zones.radius_hint') }}</small></label>
<label>{{ __('smart_ordering.zones.fee') }}<input type="number" step="0.001" min="0" name="delivery_fee" required value="0"></label>
<label>{{ __('smart_ordering.zones.minimum') }}<input type="number" step="0.001" min="0" name="minimum_order" required value="0"></label>
<details class="so-zone-advanced">
<summary>{{ __('smart_ordering.zones.advanced_geometry') }}</summary>
<p>{{ __('smart_ordering.zones.advanced_geometry_help') }}</p>
<div class="so-zone-coordinate-grid">
<label>{{ __('smart_ordering.zones.latitude') }}<input type="number" step="0.0000001" min="-90" max="90" name="center_latitude" required data-zone-latitude></label>
<label>{{ __('smart_ordering.zones.longitude') }}<input type="number" step="0.0000001" min="-180" max="180" name="center_longitude" required data-zone-longitude></label>
</div>
</details>
<label class="so-zone-check"><input type="hidden" name="is_active" value="0"><input type="checkbox" name="is_active" value="1" checked> {{ __('smart_ordering.zones.active') }}</label>
<div class="so-actions"><button class="foodex-primary" type="submit" data-zone-submit>{{ __('smart_ordering.zones.add') }}</button></div>
</form>
</div>
</section>
@endif

<script>
(() => {
    if (window.__foodexTimezoneLookupBound) return;
    window.__foodexTimezoneLookupBound = true;
    const sync = (picker) => {
        const search = picker.querySelector('[data-timezone-search]');
        const select = picker.querySelector('[data-timezone-select]');
        const empty = picker.querySelector('[data-timezone-empty]');
        if (!search || !select) return;
        const query = search.value.trim().toLocaleLowerCase();
        let visible = 0;
        [...select.options].forEach((option) => {
            if (option.dataset.legacy === '1') return;
            const match = query === '' || (option.dataset.search || '').includes(query);
            option.hidden = !match;
            if (match) visible += 1;
        });
        if (empty) empty.hidden = visible !== 0;
    };
    document.addEventListener('input', (event) => {
        const search = event.target.closest?.('[data-timezone-search]');
        if (search) sync(search.closest('[data-timezone-picker]'));
    });
    document.addEventListener('change', (event) => {
        const select = event.target.closest?.('[data-timezone-select]');
        if (!select) return;
        const status = select.closest('[data-timezone-picker]')?.querySelector('[data-timezone-status]');
        if (status) status.textContent = @json(__('smart_ordering.branch_fields.timezone_changed'));
    });
})();
</script>
