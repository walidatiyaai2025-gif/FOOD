@php($selectedKitchenBranch = $kitchenWorkspace['selected_branch'])
<section class="foodex-card">
    <h2>{{ __('smart_ordering.kitchen.title') }}</h2>
    <p>{{ __('smart_ordering.kitchen.description') }}</p>
    <form method="get" class="so-toolbar">
        <input type="hidden" name="tab" value="kitchen">
        <label>{{ __('smart_ordering.kitchen.branch') }}
            <select name="branch" onchange="this.form.submit()">
                @foreach($branches as $branch)
                    <option value="{{ $branch->public_id }}" @selected($selectedKitchenBranch && $selectedKitchenBranch->public_id === $branch->public_id)>{{ $branch->name }}</option>
                @endforeach
            </select>
        </label>
        <noscript><button class="foodex-primary" type="submit">{{ __('smart_ordering.filters.apply') }}</button></noscript>
    </form>
</section>

@if(!$selectedKitchenBranch)
    <section class="foodex-card foodex-empty-state">{{ __('smart_ordering.kitchen.no_branch') }}</section>
@elseif(!$kitchenWorkspace['entitled'])
    <section class="foodex-card foodex-state">{{ __('smart_ordering.kitchen.not_entitled') }}</section>
@else
<style>
.so-kitchen-grid{display:grid;grid-template-columns:minmax(0,2fr) minmax(280px,1fr);gap:16px}.so-ticket-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:12px}.so-ticket{display:grid;gap:10px}.so-ticket-head,.so-station-head{display:flex;justify-content:space-between;gap:12px;align-items:flex-start}.so-ticket-actions{display:flex;gap:8px;flex-wrap:wrap}.so-route{padding:8px 0;border-top:1px solid var(--foodex-border)}.so-item-row{display:flex;align-items:center;justify-content:space-between;gap:8px;padding:6px 0}.so-item-main{min-width:0}.so-item-actions{display:flex;align-items:center;gap:6px;flex-wrap:wrap}.so-kitchen-settings{display:grid;gap:12px}.so-kitchen-form{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}.so-kitchen-form .wide{grid-column:1/-1}.so-station-list{display:grid;gap:8px}.so-capacity{font-variant-numeric:tabular-nums}.so-expedited{font-weight:700}.so-ticket-number{white-space:nowrap}.so-rule-row{display:flex;justify-content:space-between;gap:10px;align-items:center;padding:8px 0;border-bottom:1px solid var(--foodex-border)}@media(max-width:900px){.so-kitchen-grid{grid-template-columns:1fr}}@media(max-width:767px){.so-kitchen-form{grid-template-columns:1fr}.so-kitchen-form .wide{grid-column:auto}.so-ticket-grid{grid-template-columns:1fr}.so-ticket-actions form,.so-ticket-actions button{width:100%}.so-item-row{align-items:flex-start;flex-direction:column}.so-item-actions,.so-item-actions form,.so-item-actions button{width:100%}}
</style>

<div class="so-kitchen-grid">
    <section id="restaurant-kds-live" style="scroll-margin-top:72px">
        <div class="foodex-card">
            <h3>{{ __('smart_ordering.kitchen.live_board') }}</h3>
            <p>{{ __('smart_ordering.kitchen.live_board_help') }}</p>
        </div>
        <div class="so-ticket-grid">
            @forelse($kitchenWorkspace['tickets'] as $ticket)
                <article class="foodex-card so-ticket">
                    <div class="so-ticket-head">
                        <div>
                            <strong class="so-ticket-number">#{{ $ticket['order_number'] }}</strong>
                            <div>{{ __('smart_ordering.kitchen.age_minutes', ['count' => $ticket['age_minutes']]) }} · {{ __('smart_ordering.kitchen.sla_states.'.$ticket['sla_state'], ['count' => $ticket['sla_minutes']]) }}</div>
                        </div>
                        <div>
                            <span class="badge">{{ __('smart_ordering.kitchen.statuses.'.$ticket['kitchen_status']) }}</span>
                            @if($ticket['expedited'])<span class="badge so-expedited">{{ __('smart_ordering.kitchen.expedited') }}</span>@endif
                        </div>
                    </div>
                    <div>{{ __('smart_ordering.kitchen.canonical_order_status') }}: {{ __('smart_ordering.statuses.'.$ticket['canonical_status']) }}</div>
                    @forelse($ticket['routes'] as $route)
                        <div class="so-route">
                            <strong>{{ $route['name'] }}</strong>
                            @foreach($route['items'] as $item)
                                <div class="so-item-row">
                                    <div class="so-item-main">
                                        <div>{{ $item['name'] }} × {{ rtrim(rtrim(number_format((float)$item['quantity'],3,'.',''),'0'),'.') }}</div>
                                        <span class="badge">{{ __('smart_ordering.kitchen.statuses.'.$item['status']) }}</span>
                                    </div>
                                    @if($kitchenWorkspace['can_manage'] && count($item['actions']))
                                        <div class="so-item-actions">
                                            @foreach($item['actions'] as $action)
                                                <form method="post" action="{{ route('admin.smart-ordering.kitchen.item-action', [$selectedKitchenBranch->public_id, $ticket['reference'], $item['line_id']]) }}">
                                                    @csrf
                                                    <input type="hidden" name="action" value="{{ $action }}">
                                                    <input type="hidden" name="operation_key" value="{{ $item['operation_keys'][$action] }}">
                                                    <button class="foodex-primary" type="submit">{{ __('smart_ordering.kitchen.item_actions.'.$action) }}</button>
                                                </form>
                                            @endforeach
                                        </div>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    @empty
                        <div class="foodex-state">{{ __('smart_ordering.kitchen.no_route') }}</div>
                    @endforelse
                    @if($kitchenWorkspace['can_manage'] && count($ticket['actions']))
                        <div class="so-ticket-actions">
                            @foreach($ticket['actions'] as $action)
                                <form method="post" action="{{ route('admin.smart-ordering.kitchen.ticket-action', [$selectedKitchenBranch->public_id, $ticket['reference']]) }}">
                                    @csrf
                                    <input type="hidden" name="action" value="{{ $action }}">
                                    <input type="hidden" name="operation_key" value="{{ $ticket['operation_keys'][$action] }}">
                                    <button class="foodex-primary" type="submit">{{ __('smart_ordering.kitchen.actions.'.$action) }}</button>
                                </form>
                            @endforeach
                        </div>
                    @endif
                </article>
            @empty
                <div class="foodex-card foodex-empty-state">{{ __('smart_ordering.kitchen.no_tickets') }}</div>
            @endforelse
        </div>
    </section>

    <aside class="so-kitchen-settings" id="restaurant-kitchen-config" style="scroll-margin-top:72px">
        <section class="foodex-card">
            <h3>{{ __('smart_ordering.kitchen.stations') }}</h3>
            <div class="so-station-list">
                @forelse($kitchenWorkspace['stations'] as $station)
                    <div class="so-station-head">
                        <div><strong>{{ $station['name'] }}</strong><div>{{ $station['is_active'] ? __('smart_ordering.kitchen.active') : __('smart_ordering.kitchen.inactive') }}</div></div>
                        <div class="so-capacity">{{ $station['active_ticket_count'] }}/{{ $station['capacity_limit'] }} · {{ __('smart_ordering.kitchen.capacity_states.'.$station['capacity_state']) }}</div>
                    </div>
                @empty
                    <div class="foodex-empty-state">{{ __('smart_ordering.kitchen.no_stations') }}</div>
                @endforelse
            </div>
            @if($kitchenWorkspace['can_manage'])
                <form method="post" action="{{ route('admin.smart-ordering.kitchen.stations.store', $selectedKitchenBranch->public_id) }}" class="so-kitchen-form">
                    @csrf
                    <label>{{ __('smart_ordering.kitchen.name_en') }}<input name="name_en" maxlength="160" required></label> {{-- localization-gate: allow — name_en is a technical bilingual-master-data submission key; visible copy is localized. --}}
                    <label>{{ __('smart_ordering.kitchen.name_ar') }}<input name="name_ar" maxlength="160" dir="rtl"></label> {{-- localization-gate: allow — name_ar is a technical bilingual-master-data submission key; visible copy is localized. --}}
                    <label>{{ __('smart_ordering.kitchen.capacity_limit') }}<input name="capacity_limit" type="number" min="1" max="500" value="20" required></label>
                    <label>{{ __('smart_ordering.kitchen.sort_order') }}<input name="sort_order" type="number" min="0" max="10000" value="0"></label>
                    <label class="wide"><input type="checkbox" name="is_active" value="1" checked> {{ __('smart_ordering.kitchen.active') }}</label>
                    <button class="foodex-primary wide" type="submit">{{ __('smart_ordering.kitchen.add_station') }}</button>
                </form>
            @endif
        </section>

        <section class="foodex-card">
            <h3>{{ __('smart_ordering.kitchen.routing_rules') }}</h3>
            @if(!$kitchenWorkspace['routing_entitled'])
                <div class="foodex-state">{{ __('smart_ordering.kitchen.routing_not_entitled') }}</div>
            @endif
            @forelse($kitchenWorkspace['rules'] as $rule)
                <div class="so-rule-row">
                    <div><strong>{{ $rule['station_name'] }}</strong><div>{{ __('smart_ordering.kitchen.match_types.'.$rule['match_type']) }} · {{ $rule['match_label'] }} · {{ __('smart_ordering.kitchen.priority_value', ['value'=>$rule['priority']]) }}</div></div>
                    @if($kitchenWorkspace['can_manage'] && $kitchenWorkspace['routing_entitled'])
                        <form method="post" action="{{ route('admin.smart-ordering.kitchen.rules.destroy', [$selectedKitchenBranch->public_id, $rule['public_id']]) }}">
                            @csrf @method('delete')
                            <button type="submit" class="foodex-primary">{{ __('smart_ordering.kitchen.delete_rule') }}</button>
                        </form>
                    @endif
                </div>
            @empty
                <div class="foodex-empty-state">{{ __('smart_ordering.kitchen.no_rules') }}</div>
            @endforelse

            @if($kitchenWorkspace['can_manage'] && $kitchenWorkspace['routing_entitled'] && count($kitchenWorkspace['stations']))
                <form method="post" action="{{ route('admin.smart-ordering.kitchen.rules.store', $selectedKitchenBranch->public_id) }}" class="so-kitchen-form">
                    @csrf
                    <label>{{ __('smart_ordering.kitchen.station') }}
                        <select name="station_public_id" required>
                            @foreach($kitchenWorkspace['stations'] as $station)
                                @if($station['is_active'])<option value="{{ $station['public_id'] }}">{{ $station['name'] }}</option>@endif
                            @endforeach
                        </select>
                    </label>
                    <label>{{ __('smart_ordering.kitchen.match_type') }}
                        <select name="match_type" required>
                            @foreach(['all','category','product'] as $type)<option value="{{ $type }}">{{ __('smart_ordering.kitchen.match_types.'.$type) }}</option>@endforeach
                        </select>
                    </label>
                    <label>{{ __('smart_ordering.kitchen.category') }}
                        <select name="category_id"><option value="">{{ __('smart_ordering.kitchen.none') }}</option>@foreach($kitchenWorkspace['categories'] as $category)<option value="{{ $category['id'] }}">{{ $category['name'] }}</option>@endforeach</select>
                    </label>
                    <label>{{ __('smart_ordering.kitchen.product') }}
                        <select name="product_id"><option value="">{{ __('smart_ordering.kitchen.none') }}</option>@foreach($kitchenWorkspace['products'] as $product)<option value="{{ $product['id'] }}">{{ $product['name'] }} · {{ $product['category_name'] }}</option>@endforeach</select>
                    </label>
                    <label>{{ __('smart_ordering.kitchen.priority') }}<input name="priority" type="number" min="0" max="10000" value="100" required></label>
                    <label><input type="checkbox" name="is_active" value="1" checked> {{ __('smart_ordering.kitchen.active') }}</label>
                    <button class="foodex-primary wide" type="submit">{{ __('smart_ordering.kitchen.add_rule') }}</button>
                </form>
            @endif
        </section>
    </aside>
</div>
@endif
