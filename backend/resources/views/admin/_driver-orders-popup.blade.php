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

    <div data-driver-collection-totals style="display:flex;flex-wrap:wrap;gap:8px;margin:8px 0 14px">
        @forelse($collectionTotals as $total)
            <div class="foodex-card" style="padding:10px 14px">
                <span>{{ app()->getLocale()==='ar' ? 'إجمالي ما حصّله السائق' : 'Driver total collected' }}</span>
                <strong>{{ \App\Support\DisplayNumber::money((float)$total->collected_amount, (string)$total->currency) }} {{ $total->currency }}</strong>
            </div>
        @empty
            <small>{{ app()->getLocale()==='ar' ? 'لا توجد تحصيلات في الفترة المحددة' : 'No collections for the selected period' }}</small>
        @endforelse
    </div>

    <div style="overflow:auto">
        <table class="foodex-table data module-table" style="min-width:720px;width:100%">
            <thead><tr>
                <th>{{ app()->getLocale()==='ar'?'الطلب':'Order' }}</th>
                <th>{{ app()->getLocale()==='ar'?'المتجر':'Store' }}</th>
                <th>{{ app()->getLocale()==='ar'?'حالة الطلب':'Order status' }}</th>
                <th>{{ app()->getLocale()==='ar'?'حالة التعيين':'Assignment status' }}</th>
                <th>{{ app()->getLocale()==='ar'?'حالة الدفع':'Payment state' }}</th>
                <th>{{ app()->getLocale()==='ar'?'المحصّل بواسطة السائق':'Driver collected' }}</th>
                <th>{{ app()->getLocale()==='ar'?'المتبقي على الطلب':'Order remaining' }}</th>
                <th>{{ app()->getLocale()==='ar'?'تاريخ الطلب':'Order date' }}</th>
            </tr></thead>
            <tbody>
            @forelse($orders as $order)
                <tr>
                    <td><strong>{{ \App\Support\DisplayNumber::reference($order->id) }}</strong></td>
                    <td>{{ $order->store_name }}</td>
                    <td>{{ str_replace('_',' ',(string)$order->order_status) }}</td>
                    <td>{{ str_replace('_',' ',(string)$order->assignment_status) }}</td>
                    @php
                        $paymentState = (string)($order->payment_state ?? 'unpaid');
                        $color = match($paymentState) {
                            'fully_settled' => 'var(--foodex-green-dark)',
                            'partially_settled' => 'var(--foodex-orange)',
                            default => 'var(--foodex-danger-text)',
                        };
                        $label = match($paymentState) {
                            'fully_settled' => app()->getLocale()==='ar' ? 'مدفوع بالكامل' : 'Fully paid',
                            'partially_settled' => app()->getLocale()==='ar' ? 'مدفوع جزئياً' : 'Partially paid',
                            default => app()->getLocale()==='ar' ? 'غير مدفوع' : 'Unpaid',
                        };
                    @endphp
                    <td><strong style="color:{{ $color }}">{{ $label }}</strong></td>
                    <td data-driver-order-collected="{{ $order->id }}">{{ \App\Support\DisplayNumber::money((float)($order->collected_amount ?? 0), (string)$order->currency) }} {{ $order->currency }}</td>
                    <td data-driver-order-remaining="{{ $order->id }}">{{ \App\Support\DisplayNumber::money((float)($order->remaining_amount ?? $order->grand_total), (string)$order->currency) }} {{ $order->currency }}</td>
                    <td>{{ $order->created_at }}</td>
                </tr>
            @empty
                <tr><td colspan="8" class="muted" style="text-align:center;padding:22px">{{ app()->getLocale()==='ar'?'لا توجد طلبات في الفترة المحددة.':'No orders in the selected date range.' }}</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>

    @if($orders->hasPages())
        <div style="margin-top:12px">{{ $orders->onEachSide(1)->links() }}</div>
    @endif
</div>
