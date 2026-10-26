@php
    use App\Support\DisplayNumber;
@endphp
<div data-driver-orders-content>
    <div style="display:flex;justify-content:space-between;gap:12px;align-items:flex-start;flex-wrap:wrap;margin-bottom:14px">
        <div>
            <h3 style="margin:0">{{ $driver->name }}</h3>
            @if(!empty($driver->phone_e164))<div class="muted">{{ $driver->phone_e164 }}</div>@endif
        </div>
        <span class="badge">{{ strtoupper($channel) }}</span>
    </div>

    <form method="get" action="{{ route('admin.drivers.orders',['driver'=>$driver->id]) }}" data-driver-orders-filter class="links workspace-inline-form" style="margin-bottom:14px">
        <input type="hidden" name="channel" value="{{ $channel }}">
        @if($storeId)<input type="hidden" name="store_id" value="{{ $storeId }}">@endif
        <label>{{ app()->getLocale()==='ar'?'من تاريخ':'From' }}<input type="date" name="from" value="{{ $from }}"></label>
        <label>{{ app()->getLocale()==='ar'?'إلى تاريخ':'To' }}<input type="date" name="to" value="{{ $to }}"></label>
        <button type="submit" class="foodex-primary">{{ app()->getLocale()==='ar'?'تطبيق':'Apply' }}</button>
    </form>

    <div style="overflow:auto">
        <table class="foodex-table data module-table" style="min-width:720px;width:100%">
            <thead><tr>
                <th>{{ app()->getLocale()==='ar'?'الطلب':'Order' }}</th>
                <th>{{ app()->getLocale()==='ar'?'المتجر':'Store' }}</th>
                <th>{{ app()->getLocale()==='ar'?'حالة الطلب':'Order status' }}</th>
                <th>{{ app()->getLocale()==='ar'?'حالة التعيين':'Assignment status' }}</th>
                <th>{{ app()->getLocale()==='ar'?'تاريخ الطلب':'Order date' }}</th>
            </tr></thead>
            <tbody>
            @forelse($orders as $order)
                <tr>
                    <td><strong>{{ DisplayNumber::reference($order->id) }}</strong></td>
                    <td>{{ $order->store_name }}</td>
                    <td>{{ str_replace('_',' ',(string)$order->order_status) }}</td>
                    <td>{{ str_replace('_',' ',(string)$order->assignment_status) }}</td>
                    <td>{{ $order->created_at }}</td>
                </tr>
            @empty
                <tr><td colspan="5" class="muted" style="text-align:center;padding:22px">{{ app()->getLocale()==='ar'?'لا توجد طلبات في الفترة المحددة.':'No orders in the selected date range.' }}</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>

    @if($orders->hasPages())
        <div style="margin-top:12px">{{ $orders->onEachSide(1)->links() }}</div>
    @endif
</div>
