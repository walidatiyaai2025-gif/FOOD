@php
    $destinationField = $destinationField ?? 'cta_destination';
    $currentDestination = $currentDestination ?? old($destinationField, 'none');
@endphp
<div class="js-engagement-destination-builder">
    <label for="{{ $destinationField }}-{{ $destinationId ?? 'new' }}">{{ __('engagement_destinations.label') }}</label>
    <select
        id="{{ $destinationField }}-{{ $destinationId ?? 'new' }}"
        name="{{ $destinationField }}"
        class="js-engagement-destination"
        data-current-destination="{{ $currentDestination ?: 'none' }}"
    >
        @foreach($engagementDestinations as $destination)
            <option value="{{ $destination['code'] }}">{{ $destination['label'] }}</option>
        @endforeach
    </select>
    <small class="foodex-muted">{{ __('engagement_destinations.help') }}</small>
    <div
        class="js-engagement-destination-preview"
        role="status"
        aria-live="polite"
        style="margin-top:6px;font-size:.8rem"
    ></div>
</div>
