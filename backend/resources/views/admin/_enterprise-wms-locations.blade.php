@php
    $activeBins = collect($binRows)->where('is_active', true)->values();
@endphp
<div class="foodex-module-overview" data-wms-locations>
    <div class="foodex-page-toolbar">
        <div><h2>{{ __('enterprise_wms.locations.title') }}</h2><p>{{ __('enterprise_wms.locations.subtitle') }}</p></div>
    </div>

    @if($warehouseId)
    <div class="wms-location-forms">
        <form class="wms-form" method="post" action="{{ route('admin.erp.wms.locations.zones.store') }}" data-wms-mutation>
            @csrf
            <input type="hidden" name="store_id" value="{{ $storeId }}">
            <input type="hidden" name="warehouse_id" value="{{ $warehouseId }}">
            <label class="wms-field">{{ __('enterprise_wms.common.code') }}<input name="code" maxlength="64" required></label>
            <label class="wms-field">{{ __('enterprise_wms.common.name') }}<input name="name" maxlength="160" required></label>
            <label class="wms-field">{{ __('enterprise_wms.common.status') }}<select name="is_active"><option value="1">{{ __('enterprise_wms.common.active') }}</option><option value="0">{{ __('enterprise_wms.common.inactive') }}</option></select></label>
            <button class="foodex-action-primary" type="submit">{{ __('enterprise_wms.locations.new_zone') }}</button>
        </form>

        <form class="wms-form" method="post" action="{{ route('admin.erp.wms.locations.bins.store') }}" data-wms-mutation>
            @csrf
            <input type="hidden" name="store_id" value="{{ $storeId }}">
            <input type="hidden" name="warehouse_id" value="{{ $warehouseId }}">
            <label class="wms-field">{{ __('enterprise_wms.common.zone') }}<select name="zone_id"><option value="">—</option>@foreach($zoneRows as $zone)<option value="{{ $zone->id }}">{{ $zone->code }} · {{ $zone->name }}</option>@endforeach</select></label>
            <label class="wms-field">{{ __('enterprise_wms.common.code') }}<input name="code" maxlength="64" required></label>
            <label class="wms-field">{{ __('enterprise_wms.common.name') }}<input name="name" maxlength="160" required></label>
            <label class="wms-field">{{ __('enterprise_wms.common.status') }}<select name="is_active"><option value="1">{{ __('enterprise_wms.common.active') }}</option><option value="0">{{ __('enterprise_wms.common.inactive') }}</option></select></label>
            <button class="foodex-action-primary" type="submit">{{ __('enterprise_wms.locations.new_bin') }}</button>
        </form>

        @if($inventoryLookup->isNotEmpty() && $activeBins->isNotEmpty())
        <form class="wms-form" method="post" action="{{ route('admin.erp.wms.locations.assignments.store') }}" data-wms-mutation>
            @csrf
            <input type="hidden" name="store_id" value="{{ $storeId }}">
            <input type="hidden" name="warehouse_id" value="{{ $warehouseId }}">
            <label class="wms-field">{{ __('enterprise_wms.common.product') }}<select name="inventory_id" required>@foreach($inventoryLookup as $item)<option value="{{ $item->inventory_id }}">{{ $item->product_name }} · {{ $item->sku }}</option>@endforeach</select></label>
            <label class="wms-field">{{ __('enterprise_wms.common.bin') }}<select name="bin_id" required>@foreach($activeBins as $bin)<option value="{{ $bin->id }}">{{ $bin->zone_code ? $bin->zone_code.' · ' : '' }}{{ $bin->code }} · {{ $bin->name }}</option>@endforeach</select></label>
            <button class="foodex-action-primary" type="submit">{{ __('enterprise_wms.locations.assign') }}</button>
        </form>
        @endif
    </div>
    @endif

    <div class="wms-split">
        <section class="foodex-viz-card">
            <h3>{{ __('enterprise_wms.common.zone') }}</h3>
            <div class="wms-table-wrap"><table class="wms-table foodex-table"><thead><tr><th>{{ __('enterprise_wms.common.code') }}</th><th>{{ __('enterprise_wms.common.name') }}</th><th>{{ __('enterprise_wms.common.status') }}</th></tr></thead><tbody>
            @forelse($zoneRows as $zone)<tr><td>{{ $zone->code }}</td><td>{{ $zone->name }}</td><td><span class="foodex-status-badge" data-state="{{ $zone->is_active ? 'active' : 'inactive' }}">{{ $zone->is_active ? __('enterprise_wms.common.active') : __('enterprise_wms.common.inactive') }}</span></td></tr>@empty<tr><td colspan="3" class="wms-empty">{{ __('enterprise_wms.common.none') }}</td></tr>@endforelse
            </tbody></table></div>
        </section>
        <section class="foodex-viz-card">
            <h3>{{ __('enterprise_wms.common.bin') }}</h3>
            <div class="wms-table-wrap"><table class="wms-table foodex-table"><thead><tr><th>{{ __('enterprise_wms.common.zone') }}</th><th>{{ __('enterprise_wms.common.code') }}</th><th>{{ __('enterprise_wms.common.name') }}</th><th>{{ __('enterprise_wms.common.status') }}</th></tr></thead><tbody>
            @forelse($binRows as $bin)<tr><td>{{ $bin->zone_name ?: '—' }}</td><td>{{ $bin->code }}</td><td>{{ $bin->name }}</td><td><span class="foodex-status-badge" data-state="{{ $bin->is_active ? 'active' : 'inactive' }}">{{ $bin->is_active ? __('enterprise_wms.common.active') : __('enterprise_wms.common.inactive') }}</span></td></tr>@empty<tr><td colspan="4" class="wms-empty">{{ __('enterprise_wms.common.none') }}</td></tr>@endforelse
            </tbody></table></div>
        </section>
    </div>

    <section class="foodex-viz-card">
        <h3>{{ __('enterprise_wms.locations.assignment') }}</h3>
        <div class="wms-table-wrap"><table class="wms-table foodex-table" data-pagination-required="server-bounded"><thead><tr><th>{{ __('enterprise_wms.common.product') }}</th><th>{{ __('enterprise_wms.common.zone') }}</th><th>{{ __('enterprise_wms.common.bin') }}</th><th>{{ __('enterprise_wms.common.created') }}</th><th>{{ __('enterprise_wms.common.actions') }}</th></tr></thead><tbody>
        @forelse($binAssignmentRows as $assignment)<tr><td><strong>{{ $assignment->product_name }}</strong><br><small>{{ $assignment->sku }}</small></td><td>{{ $assignment->zone_name ?: '—' }}</td><td>{{ $assignment->bin_code }} · {{ $assignment->bin_name }}</td><td>{{ \Illuminate\Support\Carbon::parse($assignment->assigned_at)->format('Y-m-d H:i') }}</td><td><a class="foodex-action-secondary button secondary" href="{{ route('admin.erp.wms.index',['tab'=>'locations','store_id'=>$storeId,'warehouse_id'=>$warehouseId,'inventory_id'=>$assignment->inventory_id]) }}">{{ __('enterprise_wms.common.view') }}</a></td></tr>@empty<tr><td colspan="5" class="wms-empty">{{ __('enterprise_wms.common.none') }}</td></tr>@endforelse
        </tbody></table></div>
    </section>
</div>
