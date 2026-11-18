@if($record360)
@php
    $record = $record360['inventory'];
    // The existing Record 360 route has already enforced exact store and
    // warehouse scope. Reuse the separate authoritative P110 read boundary:
    // never derive title from stock balances, SKU or valuation unit cost.
    $ownerTitle = app(\App\Enterprise\Wms\InventoryOwnerProvenanceReadService::class)
        ->snapshot($user, (int) $record->id);
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


            <section class="foodex-record-section foodex-viz-card" data-v2-owner-title-report data-v2-owner-state="{{ $ownerTitle['state'] }}" data-v2-owner-certification="{{ $ownerTitle['certified'] ? 'certified' : 'unverified' }}">
                <h3>{{ __('enterprise_wms.record360.ownership.title') }}</h3>
                <p class="wms-sub" role="status">{{ __('enterprise_wms.record360.ownership.notice') }}</p>
                <div role="status" data-v2-owner-setup-readiness="{{ $ownerTitle['certified'] && $ownerTitle['state'] === 'CERTIFIED' ? 'verified' : 'hold' }}" class="wms-sub">
                    <strong>{{ __('enterprise_wms.record360.ownership.setup_readiness_label') }}:</strong>
                    {{ $ownerTitle['certified'] && $ownerTitle['state'] === 'CERTIFIED'
                        ? __('enterprise_wms.record360.ownership.setup_verified')
                        : __('enterprise_wms.record360.ownership.setup_hold') }}
                </div>
                <details data-v2-owner-setup-help>
                    <summary>{{ __('enterprise_wms.record360.ownership.setup_help_title') }}</summary>
                    <p class="wms-sub">{{ __('enterprise_wms.record360.ownership.setup_purpose') }}</p>
                    <p class="wms-sub">{{ __('enterprise_wms.record360.ownership.setup_source') }}</p>
                    <p class="wms-sub">{{ __('enterprise_wms.record360.ownership.setup_example') }}</p>
                    <p class="wms-sub">{{ __('enterprise_wms.record360.ownership.setup_default') }}</p>
                    <p class="wms-sub">{{ __('enterprise_wms.record360.ownership.setup_validation') }}</p>
                    <p class="wms-sub">{{ __('enterprise_wms.record360.ownership.setup_permissions') }}</p>
                    <p class="wms-sub">{{ __('enterprise_wms.record360.ownership.setup_save_boundary') }}</p>
                    <p class="wms-sub">{{ __('enterprise_wms.record360.ownership.setup_recheck_help') }}</p>
                    <a data-v2-owner-evidence-recheck href="{{ route('admin.erp.wms.index', array_filter(['tab' => $tab, 'store_id' => $storeId, 'warehouse_id' => $warehouseId, 'inventory_id' => (int) $record->id])) }}">{{ __('enterprise_wms.record360.ownership.setup_recheck') }}</a>
                </details>
                <div class="wms-360-facts">
                    <span>{{ __('enterprise_wms.record360.ownership.state_label') }}<strong>{{ __('enterprise_wms.record360.ownership.states.'.$ownerTitle['state']) }}</strong></span>
                    <span data-v2-stock-custodian="warehouse">{{ __('enterprise_wms.record360.ownership.custodian') }}<strong>{{ __('enterprise_wms.record360.ownership.warehouse_custodian') }} · {{ $record->warehouse_name }}</strong></span>
                    <span>{{ __('enterprise_wms.common.quantity') }}<strong>{{ $ownerTitle['quantity'] }}</strong></span>
                    <span>{{ __('enterprise_wms.common.reserved') }}<strong>{{ $ownerTitle['reserved_quantity'] }}</strong></span>
                </div>
                <p class="wms-sub" data-v2-legal-valuation-status="unavailable">{{ __('enterprise_wms.record360.ownership.owner_valuation_unavailable') }}</p>
                <div class="wms-table-wrap"><table class="wms-table foodex-table" data-pagination-exempt="bounded-record-360">
                    <thead><tr>
                        <th scope="col">{{ __('enterprise_wms.record360.ownership.owner') }}</th>
                        <th scope="col">{{ __('enterprise_wms.common.quantity') }}</th>
                        <th scope="col">{{ __('enterprise_wms.common.reserved') }}</th>
                        <th scope="col">{{ __('enterprise_wms.record360.ownership.certification') }}</th>
                    </tr></thead>
                    <tbody>
                    @foreach($ownerTitle['positions'] as $ownerPosition)
                        <tr>
                            <td>{{ __('enterprise_wms.record360.ownership.kinds.'.$ownerPosition['owner_kind']) }}@if($ownerPosition['supplier_name'] !== null) · {{ $ownerPosition['supplier_name'] }}@endif</td>
                            <td>{{ $ownerPosition['quantity'] }}</td>
                            <td>{{ $ownerPosition['reserved_quantity'] }}</td>
                            <td>
                                @if($ownerTitle['certified'] && $ownerPosition['certified'])
                                    <span data-v2-certified-title>{{ __('enterprise_wms.record360.ownership.certified') }}</span>
                                @else
                                    {{ __('enterprise_wms.record360.ownership.unverified') }}
                                @endif
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table></div>
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
