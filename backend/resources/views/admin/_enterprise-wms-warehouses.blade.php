<div class="foodex-module-overview" data-wms-warehouses>
    <div class="foodex-page-toolbar">
        <div>
            <h2>{{ __('enterprise_wms.tabs.warehouses') }}</h2>
            <p>{{ __('enterprise_wms.context.all_warehouses') }}</p>
        </div>
    </div>

    <div class="wms-report-cards">
        @forelse($warehouses as $warehouse)
            <a class="foodex-viz-card wms-report-card" data-wms-warehouse-id="{{ $warehouse->id }}"
               href="{{ route('admin.erp.wms.index', ['tab' => 'overview', 'store_id' => $storeId, 'warehouse_id' => $warehouse->id]) }}"
               @if($warehouseId === (int) $warehouse->id) aria-current="true" @endif>
                <strong>{{ $warehouse->name }}</strong>
                <small>{{ $warehouse->code }}</small>
                <small>{{ __('enterprise_wms.common.open') }}</small>
            </a>
        @empty
            <p class="wms-empty">{{ __('enterprise_wms.common.none') }}</p>
        @endforelse
    </div>
</div>
