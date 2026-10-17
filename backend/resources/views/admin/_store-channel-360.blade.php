@php($ops = $store->operations_360 ?? [])
@if($ops)
<section class="foodex-card store-360" aria-label="{{ __('store_channels.sections.store360') }}">
    <div class="store-head">
        <div>
            <h4>{{ __('store_channels.sections.store360') }}</h4>
            <div class="store-meta">
                <span class="badge {{ ($ops['lifecycle_state'] ?? '')==='ACTIVE'?'active':'' }}">{{ __('store_channels.states.'.($ops['lifecycle_state'] ?? 'SUSPENDED')) }}</span>
                <span class="badge {{ ($ops['readiness_state'] ?? '')==='ready'?'active':'' }}">{{ __('store_channels.labels.score') }}: {{ $ops['readiness_score'] ?? 0 }}%</span>
            </div>
        </div>
    </div>

    <div class="commerce-status">
        @foreach([
            ['catalog',$ops['catalog_products'] ?? 0],
            ['inventory',$ops['inventory_positions'] ?? 0],
            ['delivery',$ops['delivery_zones'] ?? 0],
            ['integrations',$ops['integrations'] ?? 0],
            ['degraded',$ops['degraded_integrations'] ?? 0],
            ['audit',$ops['audit_events'] ?? 0],
        ] as [$label,$value])
            <div class="commerce-status-item"><small>{{ __('store_channels.labels.'.$label) }}</small><strong>{{ $value }}</strong></div>
        @endforeach
    </div>

    <div>
        <h4>{{ __('store_channels.sections.channels') }}</h4>
        <div class="store-ops-channel-grid">
            @foreach($ops['channels'] ?? [] as $channel => $state)
                <div class="store-ops-channel"><strong>{{ __('store_channels.channels.'.$channel) }}</strong><span>{{ __('store_channels.channel_states.'.$state) }}</span></div>
            @endforeach
        </div>
    </div>

    <div>
        <h4>{{ __('store_channels.sections.readiness') }}</h4>
        <div class="store-ops-channel-grid">
            @foreach($ops['readiness'] ?? [] as $check)
                <div class="store-ops-channel">
                    <strong>{{ __('store_channels.checks.'.$check['key']) }}</strong>
                    <span class="badge {{ $check['ready']?'active':'' }}">{{ $check['ready'] ? __('store_channels.states.ready') : __('store_channels.states.attention') }}</span>
                </div>
            @endforeach
        </div>
    </div>

    <div>
        <h4>{{ __('store_channels.sections.lifecycle') }}</h4>
        <p class="foodex-file-help">{{ __('store_channels.lifecycle_help') }}</p>
        @if(($ops['lifecycle_state'] ?? '') !== 'CLOSED')
            <form method="post" action="{{ route('admin.retail-stores.update',$store) }}" class="store-form-grid" data-store-lifecycle-form>
                @csrf @method('patch')
                <label>{{ __('store_channels.labels.status') }}
                    <select name="lifecycle_action" required>
                        <option value="">{{ __('store_channels.labels.status') }}</option>
                        @if(($ops['lifecycle_state'] ?? '') !== 'ACTIVE')<option value="activate">{{ __('store_channels.actions.activate') }}</option>@endif
                        @if(($ops['lifecycle_state'] ?? '') === 'ACTIVE')<option value="suspend">{{ __('store_channels.actions.suspend') }}</option>@endif
                        <option value="close">{{ __('store_channels.actions.close') }}</option>
                    </select>
                </label>
                <label>{{ __('store_channels.lifecycle_reason') }}<input name="lifecycle_reason" required minlength="5" maxlength="500"></label>
                <button class="foodex-action-primary" type="submit">{{ __('store_channels.sections.lifecycle') }}</button>
            </form>
        @elseif($ops['last_reason'])
            <p><strong>{{ __('store_channels.labels.reason') }}:</strong> {{ $ops['last_reason'] }}</p>
        @endif
    </div>
</section>
@endif
