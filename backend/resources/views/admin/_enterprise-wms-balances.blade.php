{{-- P248: canonical, read-only stock ledger. Only server-authorized warehouse rows are present. --}}
<div data-wms-balances="true">
    <h2>{{ __('enterprise_wms.balances.title') }}</h2>
    <p class="wms-sub">{{ __('enterprise_wms.balances.subtitle') }}</p>
    <div class="wms-table-wrap">
        <table class="wms-table foodex-table" data-pagination-required="server-bounded" data-pagination-mode="keyset">
            <thead><tr>
                <th scope="col">{{ __('enterprise_wms.common.product') }}</th>
                <th scope="col">{{ __('enterprise_wms.common.warehouse') }}</th>
                <th scope="col">{{ __('enterprise_wms.common.quantity') }}</th>
                <th scope="col">{{ __('enterprise_wms.common.reserved') }}</th>
                <th scope="col">{{ __('enterprise_wms.common.available') }}</th>
                <th scope="col">{{ __('enterprise_wms.common.actions') }}</th>
            </tr></thead>
            <tbody>
                @forelse($inventoryRows as $row)
                    <tr data-wms-balance-id="{{ (int)$row->inventory_id }}">
                        <td><strong>{{ $row->product_name }}</strong><small style="display:block">{{ $row->product_sku }}</small></td>
                        <td>{{ $row->warehouse_name }}</td>
                        <td>{{ \App\Support\DisplayNumber::quantity((float)$row->quantity) }}</td>
                        <td>{{ \App\Support\DisplayNumber::quantity((float)$row->reserved_quantity) }}</td>
                        <td>{{ \App\Support\DisplayNumber::quantity((float)$row->available_quantity) }}</td>
                        <td><a class="foodex-action-primary" href="{{ route('admin.erp.wms.index', ['tab'=>'overview','store_id'=>$storeId,'warehouse_id'=>$row->warehouse_id,'inventory_id'=>$row->inventory_id]) }}">{{ __('enterprise_wms.common.details') }}</a></td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="wms-empty">{{ __('enterprise_wms.common.none') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    @if($balancesHasMore)
        <nav class="wms-actions" aria-label="{{ __('enterprise_wms.balances.pagination') }}">
            <a class="foodex-action-primary" rel="next" href="{{ route('admin.erp.wms.index',['tab'=>'balances','store_id'=>$storeId,'warehouse_id'=>$warehouseId,'after_inventory_id'=>$balancesNextCursor]) }}">{{ __('enterprise_wms.balances.next_page') }}</a>
        </nav>
    @endif
</div>
