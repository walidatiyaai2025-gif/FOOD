@php
$money = fn($value,$currency) => number_format((float)$value,2).' '.($currency ?: $detail['currency']);
$paymentPaid = strtolower((string)($detail['payment_status'] ?? '')) === 'paid';
$businessLabel = static function ($value): string {
    $key = strtolower(trim((string)($value ?? '')));
    if ($key === '') {
        return '—';
    }
    $translationKey = 'order_operations.business_labels.'.$key;
    $translated = __($translationKey);

    return $translated !== $translationKey
        ? $translated
        : (app()->getLocale() === 'ar' ? 'غير محدد' : ucwords(str_replace(['_','-','.'],' ',$key)));
};
$printTitle = __('order_operations.popup.'.$kind);
@endphp
<div class="order-popup-content" data-order-popup-content="{{ $kind }}">
    <header class="order-print-header" data-print-only>
        <div></div>
        <div class="order-print-type" dir="{{ $isAr ? 'rtl' : 'ltr' }}">
            <small>{{ __('order_operations.popup.print_type') }}</small>
            <strong>{{ $printTitle }}</strong>
        </div>
        <div class="order-print-brand">
            <img src="{{ asset('brand/foodex-economical-group.webp') }}" alt="FOODEX">
            <strong>FOODEX</strong>
        </div>
    </header>

    <div class="order-popup-toolbar" data-no-print>
        <button type="button" class="foodex-primary" data-order-popup-print>{{ __('order_operations.popup.print') }}</button>
    </div>

    <div class="order-popup-summary">
        <div><small>{{ __('order_operations.popup.order') }}</small><strong>{{ $detail['number'] }}</strong></div>
        <div><small>{{ __('order_operations.popup.customer') }}</small><strong>{{ $detail['customer'] }}</strong></div>
        <div><small>{{ __('order_operations.popup.store') }}</small><strong>{{ $detail['store'] }}</strong></div>
        <div><small>{{ __('order_operations.popup.status') }}</small><strong>{{ $detail['status_label'] }}</strong></div>
        <div><small>{{ __('order_operations.popup.payment_status') }}</small><strong>{{ $businessLabel($detail['payment_status']) }}</strong></div>
        <div><small>{{ __('order_operations.popup.order_value') }}</small><strong class="{{ $paymentPaid ? 'is-paid' : 'is-unpaid' }}">{{ $money($detail['total'],$detail['currency']) }}</strong></div>
    </div>

    @if($kind==='details')
        <section class="order-popup-section">
            <h3>{{ __('order_operations.popup.details') }}</h3>
            <div class="order-popup-facts">
                <div><span>{{ __('order_operations.popup.store') }}</span><strong>{{ $detail['store'] }}</strong></div>
                <div><span>{{ __('order_operations.popup.payment_method') }}</span><strong>{{ $businessLabel($detail['payment_method']) }}</strong></div>
                <div><span>{{ __('order_operations.popup.payment_status') }}</span><strong>{{ $businessLabel($detail['payment_status']) }}</strong></div>
                <div><span>{{ __('order_operations.popup.execution') }}</span><strong>{{ $detail['dispatch_assignee'] ?: __('order_operations.popup.unassigned') }}</strong></div>
            </div>
        </section>

        @if(!empty($detail['delivery_address']))
            @php($delivery=$detail['delivery_address'])
            <section class="order-popup-section">
                <h3>{{ __('order_operations.popup.delivery_address') }}</h3>
                <p>{{ $delivery['formatted'] ?: '—' }}</p>
                @if(!empty($delivery['landmark']))<small>{{ __('order_operations.popup.landmark') }}: {{ $delivery['landmark'] }}</small>@endif
            </section>
        @endif

        <section class="order-popup-section">
            <h3>{{ __('order_operations.popup.items') }}</h3>
            <div class="order-popup-table-wrap">
                <table class="order-popup-table">
                    <thead><tr><th>{{ __('order_operations.popup.item') }}</th><th>{{ __('order_operations.popup.quantity') }}</th><th>{{ __('order_operations.popup.total') }}</th></tr></thead>
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
            <h3>{{ __('order_operations.popup.invoice') }} · {{ $detail['invoice_number'] ?: '#'.$detail['invoice_id'] }}</h3>
            <div class="order-popup-facts">
                <div><span>{{ __('order_operations.popup.invoice_status') }}</span><strong>{{ $businessLabel($detail['invoice_status']) }}</strong></div>
                <div><span>{{ __('order_operations.popup.payment_status') }}</span><strong>{{ $businessLabel($detail['payment_status']) }}</strong></div>
                <div><span>{{ __('order_operations.popup.invoice_total') }}</span><strong class="{{ $paymentPaid ? 'is-paid' : 'is-unpaid' }}">{{ $money($detail['invoice_total'] ?? $detail['total'],$detail['invoice_currency'] ?: $detail['currency']) }}</strong></div>
                @if($detail['payment_amount'] !== null)<div><span>{{ __('order_operations.popup.paid_amount') }}</span><strong>{{ $money($detail['payment_amount'],$detail['payment_currency'] ?: $detail['currency']) }}</strong></div>@endif
            </div>
        </section>
        <section class="order-popup-section">
            <h3>{{ __('order_operations.popup.invoice_lines') }}</h3>
            <div class="order-popup-table-wrap">
                <table class="order-popup-table">
                    <thead><tr><th>{{ __('order_operations.popup.item') }}</th><th>{{ __('order_operations.popup.quantity') }}</th><th>{{ __('order_operations.popup.unit_price') }}</th><th>{{ __('order_operations.popup.total') }}</th></tr></thead>
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
        <section class="order-popup-section">
            <h3>{{ __('order_operations.popup.complete_timeline') }}</h3>
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
                                <time>{{ \Illuminate\Support\Carbon::parse($event['created_at'])->timezone('Asia/Kuwait')->format('Y-m-d H:i:s') }}</time>
                            </footer>
                        </div>
                    </article>
                @empty
                    <div class="foodex-empty-state">{{ __('order_operations.popup.no_actions') }}</div>
                @endforelse
            </div>
        </section>
    @endif

    <footer class="order-print-footer" data-print-only>
        <span>{{ __('order_operations.popup.printed_at') }}:</span>
        <strong data-order-print-time>{{ now()->timezone('Asia/Kuwait')->format('Y-m-d H:i:s') }}</strong>
    </footer>
</div>
