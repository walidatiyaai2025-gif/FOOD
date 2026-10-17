@if($record360)
@php
    $record = $record360['inventory'];
    $closeUrl = route('admin.erp.wms.index', array_filter([
        'tab' => $tab,
        'store_id' => $storeId,
        'warehouse_id' => $warehouseId,
    ]));
@endphp
<dialog class="foodex-record-drawer" data-wms-record-360 open>
    <div class="foodex-record-drawer-shell">
        <header class="foodex-record-drawer-header">
            <div>
                <h2>{{ __('enterprise_wms.record360.title') }}</h2>
                <p>{{ $record->product_name }} · {{ $record->sku }}</p>
            </div>
            <a class="foodex-record-drawer-close" href="{{ $closeUrl }}" aria-label="{{ __('enterprise_wms.record360.close') }}">×</a>
        </header>

        <div class="foodex-record-grid">
            <section class="foodex-record-section foodex-viz-card">
                <h3>{{ __('enterprise_wms.record360.stock') }}</h3>
                <div class="wms-360-facts">
                    <span>{{ __('enterprise_wms.common.warehouse') }}<strong>{{ $record->warehouse_name }}</strong></span>
                    <span>{{ __('enterprise_wms.common.quantity') }}<strong>{{ \App\Support\DisplayNumber::quantity((float)$record->quantity) }}</strong></span>
                    <span>{{ __('enterprise_wms.common.reserved') }}<strong>{{ \App\Support\DisplayNumber::quantity((float)$record->reserved_quantity) }}</strong></span>
                    <span>{{ __('enterprise_wms.common.available') }}<strong>{{ \App\Support\DisplayNumber::quantity((float)$record->quantity-(float)$record->reserved_quantity) }}</strong></span>
                    <span>{{ __('enterprise_wms.common.zone') }}<strong>{{ $record->zone_name ?: '—' }}</strong></span>
                    <span>{{ __('enterprise_wms.common.bin') }}<strong>{{ $record->bin_name ? $record->bin_code.' · '.$record->bin_name : __('enterprise_wms.record360.no_bin') }}</strong></span>
                </div>
            </section>

            <section class="foodex-record-section foodex-viz-card">
                <h3>{{ __('enterprise_wms.record360.lots') }}</h3>
                <div class="wms-table-wrap"><table class="wms-table foodex-table" data-pagination-exempt="bounded-record-360"><thead><tr><th>{{ __('enterprise_wms.lots.identifier') }}</th><th>{{ __('enterprise_wms.common.status') }}</th><th>{{ __('enterprise_wms.lots.expires_on') }}</th><th>{{ __('enterprise_wms.common.bin') }}</th></tr></thead><tbody>
                @forelse($record360['lots'] as $row)<tr><td>{{ $row->identifier }}</td><td>{{ __('enterprise_wms.lots.statuses.'.$row->status) }}</td><td>{{ $row->expires_on ?: '—' }}</td><td>{{ $row->bin_code ?: '—' }}</td></tr>@empty<tr><td colspan="4">{{ __('enterprise_wms.common.none') }}</td></tr>@endforelse
                </tbody></table></div>
            </section>
        </div>

        <section class="foodex-record-section foodex-viz-card">
            <h3>{{ __('enterprise_wms.record360.movements') }}</h3>
            <div class="wms-table-wrap"><table class="wms-table foodex-table" data-pagination-exempt="bounded-record-360"><thead><tr><th>{{ __('enterprise_wms.movements.type') }}</th><th>{{ __('enterprise_wms.common.quantity') }}</th><th>{{ __('enterprise_wms.common.bin') }}</th><th>{{ __('enterprise_wms.common.created') }}</th></tr></thead><tbody>
            @forelse($record360['movements'] as $row)<tr><td>{{ $movementLabel((string)$row->type) }}</td><td>{{ \App\Support\DisplayNumber::quantity((float)$row->quantity) }}</td><td>{{ $row->bin_code ?: '—' }}</td><td>{{ \Illuminate\Support\Carbon::parse($row->created_at)->format('Y-m-d H:i') }}</td></tr>@empty<tr><td colspan="4">{{ __('enterprise_wms.common.none') }}</td></tr>@endforelse
            </tbody></table></div>
        </section>

        <div class="foodex-record-grid">
            <section class="foodex-record-section foodex-viz-card">
                <h3>{{ __('enterprise_wms.record360.transfers') }}</h3>
                <div class="wms-table-wrap"><table class="wms-table foodex-table" data-pagination-exempt="bounded-record-360"><thead><tr><th>#</th><th>{{ __('enterprise_wms.common.status') }}</th><th>{{ __('enterprise_wms.common.quantity') }}</th><th>{{ __('enterprise_wms.common.bin') }}</th></tr></thead><tbody>
                @forelse($record360['transfers'] as $row)<tr><td>{{ $row->id }}</td><td>{{ __('enterprise_wms.transfers.statuses.'.$row->status) }}</td><td>{{ \App\Support\DisplayNumber::quantity((float)$row->quantity) }}</td><td>{{ $row->bin_code ?: '—' }}</td></tr>@empty<tr><td colspan="4">{{ __('enterprise_wms.common.none') }}</td></tr>@endforelse
                </tbody></table></div>
            </section>

            <section class="foodex-record-section foodex-viz-card">
                <h3>{{ __('enterprise_wms.record360.counts') }}</h3>
                <div class="wms-table-wrap"><table class="wms-table foodex-table" data-pagination-exempt="bounded-record-360"><thead><tr><th>#</th><th>{{ __('enterprise_wms.common.status') }}</th><th>{{ __('enterprise_wms.counts.expected') }}</th><th>{{ __('enterprise_wms.counts.counted') }}</th><th>{{ __('enterprise_wms.common.bin') }}</th></tr></thead><tbody>
                @forelse($record360['counts'] as $row)<tr><td>{{ $row->id }}</td><td>{{ __('enterprise_wms.counts.statuses.'.$row->status) }}</td><td>{{ \App\Support\DisplayNumber::quantity((float)$row->expected_quantity) }}</td><td>{{ $row->counted_quantity===null ? '—' : \App\Support\DisplayNumber::quantity((float)$row->counted_quantity) }}</td><td>{{ $row->bin_code ?: '—' }}</td></tr>@empty<tr><td colspan="5">{{ __('enterprise_wms.common.none') }}</td></tr>@endforelse
                </tbody></table></div>
            </section>
        </div>

        <div class="foodex-record-grid">
            <section class="foodex-record-section foodex-viz-card">
                <h3>{{ __('enterprise_wms.record360.adjustments') }}</h3>
                <div class="wms-table-wrap"><table class="wms-table foodex-table" data-pagination-exempt="bounded-record-360"><thead><tr><th>{{ __('enterprise_wms.movements.type') }}</th><th>{{ __('enterprise_wms.common.quantity') }}</th><th>{{ __('enterprise_wms.common.created') }}</th></tr></thead><tbody>
                @forelse($record360['adjustments'] as $row)<tr><td>{{ $movementLabel((string)$row->type) }}</td><td>{{ \App\Support\DisplayNumber::quantity((float)$row->quantity) }}</td><td>{{ \Illuminate\Support\Carbon::parse($row->created_at)->format('Y-m-d H:i') }}</td></tr>@empty<tr><td colspan="3">{{ __('enterprise_wms.common.none') }}</td></tr>@endforelse
                </tbody></table></div>
            </section>

            <section class="foodex-record-section foodex-viz-card">
                <h3>{{ __('enterprise_wms.record360.reservations') }}</h3>
                <div class="wms-table-wrap"><table class="wms-table foodex-table" data-pagination-exempt="bounded-record-360"><thead><tr><th>{{ __('enterprise_wms.movements.type') }}</th><th>{{ __('enterprise_wms.common.quantity') }}</th><th>{{ __('enterprise_wms.common.created') }}</th></tr></thead><tbody>
                @forelse($record360['reservations'] as $row)<tr><td>{{ $movementLabel((string)$row->type) }}</td><td>{{ \App\Support\DisplayNumber::quantity((float)$row->quantity) }}</td><td>{{ \Illuminate\Support\Carbon::parse($row->created_at)->format('Y-m-d H:i') }}</td></tr>@empty<tr><td colspan="3">{{ __('enterprise_wms.common.none') }}</td></tr>@endforelse
                </tbody></table></div>
            </section>
        </div>
    </div>
</dialog>
@endif
