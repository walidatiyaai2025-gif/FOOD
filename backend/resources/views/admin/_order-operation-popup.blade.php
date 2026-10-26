@php($money = fn($value,$currency) => number_format((float)$value,2).' '.($currency ?: $detail['currency']))
@php($paymentPaid = strtolower((string)($detail['payment_status'] ?? '')) === 'paid')
<div class="order-popup-content" data-order-popup-content="{{ $kind }}">
    <div class="order-popup-summary">
        <div><small>{{ $isAr ? 'الطلب' : 'Order' }}</small><strong>{{ $detail['number'] }}</strong></div>
        <div><small>{{ $isAr ? 'العميل' : 'Customer' }}</small><strong>{{ $detail['customer'] }}</strong></div>
        <div><small>{{ $isAr ? 'الحالة' : 'Status' }}</small><strong>{{ $detail['status_label'] }}</strong></div>
        <div><small>{{ $isAr ? 'قيمة الطلب' : 'Order value' }}</small><strong class="{{ $paymentPaid ? 'is-paid' : 'is-unpaid' }}">{{ $money($detail['total'],$detail['currency']) }}</strong></div>
    </div>

    @if($kind==='details')
        <section class="order-popup-section">
            <h3>{{ $isAr ? 'تفاصيل الطلب' : 'Order details' }}</h3>
            <div class="order-popup-facts">
                <div><span>{{ $isAr ? 'المتجر' : 'Store' }}</span><strong>{{ $detail['store'] }}</strong></div>
                <div><span>{{ $isAr ? 'طريقة الدفع' : 'Payment method' }}</span><strong>{{ $detail['payment_method'] ?: '—' }}</strong></div>
                <div><span>{{ $isAr ? 'حالة الدفع' : 'Payment status' }}</span><strong>{{ $detail['payment_status'] ?: '—' }}</strong></div>
                <div><span>{{ $isAr ? 'التنفيذ' : 'Execution' }}</span><strong>{{ $detail['dispatch_assignee'] ?: ($isAr ? 'غير معين' : 'Unassigned') }}</strong></div>
            </div>
        </section>

        @if(!empty($detail['delivery_address']))
            @php($delivery=$detail['delivery_address'])
            <section class="order-popup-section">
                <h3>{{ $isAr ? 'عنوان التوصيل' : 'Delivery address' }}</h3>
                <p>{{ $delivery['formatted'] ?: '—' }}</p>
                @if(!empty($delivery['landmark']))<small>{{ $isAr ? 'علامة مميزة' : 'Landmark' }}: {{ $delivery['landmark'] }}</small>@endif
            </section>
        @endif

        <section class="order-popup-section">
            <h3>{{ $isAr ? 'الأصناف' : 'Items' }}</h3>
            <div class="order-popup-table-wrap">
                <table class="order-popup-table">
                    <thead><tr><th>{{ $isAr ? 'الصنف' : 'Item' }}</th><th>{{ $isAr ? 'الكمية' : 'Qty' }}</th><th>{{ $isAr ? 'الإجمالي' : 'Total' }}</th></tr></thead>
                    <tbody>
                    @forelse($detail['items'] as $item)
                        <tr><td>{{ $item['name'] }}</td><td>{{ $item['quantity'] }}</td><td>{{ $money($item['line_total'],$item['currency']) }}</td></tr>
                    @empty
                        <tr><td colspan="3">—</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </section>
    @elseif($kind==='invoice')
        <section class="order-popup-section">
            <h3>{{ $isAr ? 'الفاتورة' : 'Invoice' }} · {{ $detail['invoice_number'] ?: '#'.$detail['invoice_id'] }}</h3>
            <div class="order-popup-facts">
                <div><span>{{ $isAr ? 'حالة الفاتورة' : 'Invoice status' }}</span><strong>{{ $detail['invoice_status'] ?: '—' }}</strong></div>
                <div><span>{{ $isAr ? 'حالة الدفع' : 'Payment status' }}</span><strong>{{ $detail['payment_status'] ?: '—' }}</strong></div>
                <div><span>{{ $isAr ? 'قيمة الفاتورة' : 'Invoice total' }}</span><strong class="{{ $paymentPaid ? 'is-paid' : 'is-unpaid' }}">{{ $money($detail['invoice_total'] ?? $detail['total'],$detail['invoice_currency'] ?: $detail['currency']) }}</strong></div>
                @if($detail['payment_amount'] !== null)<div><span>{{ $isAr ? 'المدفوع' : 'Paid amount' }}</span><strong>{{ $money($detail['payment_amount'],$detail['payment_currency'] ?: $detail['currency']) }}</strong></div>@endif
            </div>
        </section>
        <section class="order-popup-section">
            <h3>{{ $isAr ? 'تفاصيل الأصناف' : 'Invoice lines' }}</h3>
            <div class="order-popup-table-wrap">
                <table class="order-popup-table">
                    <thead><tr><th>{{ $isAr ? 'الصنف' : 'Item' }}</th><th>{{ $isAr ? 'الكمية' : 'Qty' }}</th><th>{{ $isAr ? 'سعر الوحدة' : 'Unit price' }}</th><th>{{ $isAr ? 'الإجمالي' : 'Total' }}</th></tr></thead>
                    <tbody>
                    @forelse($detail['items'] as $item)
                        <tr><td>{{ $item['name'] }}</td><td>{{ $item['quantity'] }}</td><td>{{ $money($item['unit_price'],$item['currency']) }}</td><td>{{ $money($item['line_total'],$item['currency']) }}</td></tr>
                    @empty
                        <tr><td colspan="4">—</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </section>
    @else
        <div class="order-popup-toolbar" data-no-print>
            <button type="button" class="foodex-primary" data-order-popup-print>{{ $isAr ? 'طباعة التايم لاين' : 'Print timeline' }}</button>
        </div>
        <section class="order-popup-section">
            <h3>{{ $isAr ? 'جميع الإجراءات على الطلب' : 'Complete order timeline' }}</h3>
            <div class="order-popup-timeline">
                @forelse($timeline as $event)
                    <article class="order-popup-timeline-item" data-kind="{{ $event['kind'] }}">
                        <span class="order-popup-timeline-dot"></span>
                        <div>
                            <strong>{{ $event['title'] }}</strong>
                            @if(!empty($event['summary']))<div>{{ $event['summary'] }}</div>@endif
                            @if(!empty($event['note']))<small>{{ $event['note'] }}</small>@endif
                            <footer>
                                @if(!empty($event['actor']))<span>{{ $event['actor'] }}</span>@endif
                                <time>{{ \Carbon\Carbon::parse($event['created_at'])->timezone('Asia/Kuwait')->format('Y-m-d H:i:s') }}</time>
                            </footer>
                        </div>
                    </article>
                @empty
                    <div class="foodex-empty-state">{{ $isAr ? 'لا توجد إجراءات مسجلة.' : 'No recorded actions.' }}</div>
                @endforelse
            </div>
        </section>
    @endif
</div>
