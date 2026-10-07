<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ app()->getLocale()==='ar'?'rtl':'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>{{ app()->getLocale()==='ar'?'فاتورة':'Invoice' }} {{ $invoice['invoice_number'] }} · FOODEX</title>
    @include('admin._brand-components')
    <style>
        body{margin:0;background:var(--foodex-background);color:var(--foodex-text)}
        .wrap{max-width:1120px;margin:0 auto;padding:28px}
        .invoice-card{padding:24px;background:var(--foodex-surface);border:1px solid var(--foodex-border);border-radius:var(--foodex-radius-card);box-shadow:var(--foodex-shadow-sm)}
        .head{display:flex;justify-content:space-between;gap:16px;align-items:flex-start;flex-wrap:wrap}
        .muted{color:var(--foodex-muted)} .actions{display:flex;gap:8px;flex-wrap:wrap}
        .grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px;margin:20px 0}
        .summary{max-width:440px;margin-inline-start:auto;margin-top:20px;display:grid;gap:7px}
        .grand{font-size:20px;font-weight:800;border-top:1px solid var(--foodex-border);padding-top:8px}
        .correction{margin-top:24px;display:flex;gap:8px;flex-wrap:wrap}
        .correction input{min-width:320px;flex:1}
        a{text-decoration:none}
        @media(max-width:640px){.wrap{padding:14px}.invoice-card{padding:16px}.correction input{min-width:100%}}
    </style>
</head>
<body>
@php
    $isAr = app()->getLocale() === 'ar';
    $channelCode = strtolower((string)($invoice['channel'] ?? ''));
    $channelLabel = match($channelCode) {
        'b2b', 'wholesale' => $isAr ? 'جملة' : 'Wholesale',
        'b2c', 'retail' => $isAr ? 'تجزئة' : 'Retail',
        default => $isAr ? 'قناة العميل' : 'Customer channel',
    };
    $paymentMethodCode = strtolower((string)($invoice['payment_method'] ?? ''));
    $paymentMethodLabel = match($paymentMethodCode) {
        'cash', 'cod', 'cash_on_delivery' => $isAr ? 'الدفع عند الاستلام' : 'Cash on delivery',
        'card', 'credit_card', 'debit_card' => $isAr ? 'بطاقة' : 'Card',
        'knet' => 'KNET',
        'bank_transfer' => $isAr ? 'تحويل بنكي' : 'Bank transfer',
        'account_credit', 'credit' => $isAr ? 'رصيد الحساب' : 'Account credit',
        default => $paymentMethodCode === '' ? '—' : ($isAr ? 'طريقة دفع' : 'Payment method'),
    };
    $paymentStatusCode = strtolower((string)($invoice['payment_status'] ?? ''));
    $paymentStatusLabel = match($paymentStatusCode) {
        'paid', 'captured', 'completed' => $isAr ? 'مدفوع' : 'Paid',
        'pending', 'processing' => $isAr ? 'قيد المعالجة' : 'Processing',
        'unpaid', 'due' => $isAr ? 'مستحق' : 'Due',
        'failed' => $isAr ? 'فشل الدفع' : 'Payment failed',
        'refunded' => $isAr ? 'تم رد المبلغ' : 'Refunded',
        default => $paymentStatusCode === '' ? '—' : ($isAr ? 'حالة الدفع' : 'Payment status'),
    };
    $invoiceStatusCode = strtolower((string)($invoice['status'] ?? ''));
    $invoiceStatusLabel = match($invoiceStatusCode) {
        'draft' => $isAr ? 'مسودة' : 'Draft',
        'issued', 'open' => $isAr ? 'صادرة' : 'Issued',
        'paid' => $isAr ? 'مدفوعة' : 'Paid',
        'void', 'voided', 'cancelled', 'canceled' => $isAr ? 'ملغاة' : 'Cancelled',
        default => $isAr ? 'حالة الفاتورة' : 'Invoice status',
    };
@endphp
<div class="wrap">
    <div style="margin-bottom:12px"><a href="javascript:history.back()">← {{ $isAr?'رجوع':'Back' }}</a></div>
    <section class="invoice-card foodex-card">
        <div class="head">
            <div>
                <h1 style="margin:0 0 8px">{{ $isAr ? 'فاتورة' : 'Invoice' }} {{ $invoice['invoice_number'] }}</h1>
                <div class="muted">{{ $isAr ? 'طلب' : 'Order' }}: {{ $invoice['order_number'] }} · {{ $invoice['store_name'] }} · {{ $channelLabel }}</div>
            </div>
            <div class="actions">
                <button class="foodex-primary" type="button" onclick="window.print()">{{ $isAr?'طباعة':'Print' }}</button>
                <a class="foodex-primary" href="{{ route('admin.invoices.download',['invoice'=>$model->id,'locale'=>'ar']) }}">PDF عربي</a>
                <a class="foodex-primary" href="{{ route('admin.invoices.download',['invoice'=>$model->id,'locale'=>'en']) }}">English PDF</a>
            </div>
        </div>

        <div class="grid">
            <div><strong>{{ $isAr ? 'العميل' : 'Customer' }}</strong><br>{{ $invoice['customer']['name'] ?: '—' }}</div>
            <div><strong>{{ $isAr ? 'تاريخ الإصدار' : 'Issued' }}</strong><br>{{ $invoice['issued_at'] }}</div>
            <div><strong>{{ $isAr ? 'طريقة الدفع' : 'Payment method' }}</strong><br>{{ $paymentMethodLabel }}</div>
            <div><strong>{{ $isAr ? 'حالة الدفع' : 'Payment status' }}</strong><br>{{ $paymentStatusLabel }}</div>
            @if($invoice['price_tier_code'])<div><strong>{{ $isAr?'شريحة سعر الجملة':'Wholesale price tier' }}</strong><br>{{ $invoice['price_tier_code'] }}</div>@endif
            <div><strong>{{ $isAr?'المراجعة':'Revision' }}</strong><br>{{ $invoice['revision'] }} · {{ $invoiceStatusLabel }}</div>
        </div>

        <div style="overflow:auto">
            <table class="foodex-table" style="width:100%">
                <thead><tr><th>{{ $isAr?'الصنف':'Item' }}</th><th>SKU</th><th>{{ $isAr?'الكمية':'Qty' }}</th><th>{{ $isAr?'سعر الوحدة':'Unit price' }}</th><th>{{ $isAr?'الإجمالي':'Total' }}</th></tr></thead>
                <tbody>
                @foreach($invoice['items'] as $item)
                    <tr><td>{{ $item['name'] }}</td><td>{{ $item['sku'] }}</td><td>{{ $item['quantity'] }}</td><td>{{ number_format($item['unit_price'],3) }}</td><td>{{ number_format($item['line_total'],3) }} {{ $invoice['currency'] }}</td></tr>
                @endforeach
                </tbody>
            </table>
        </div>

        <div class="summary">
            <div>{{ $isAr?'الإجمالي الفرعي':'Subtotal' }}: {{ number_format($invoice['subtotal'],3) }} {{ $invoice['currency'] }}</div>
            <div>{{ $isAr?'الخصم':'Discount' }}: {{ number_format($invoice['discount_total'],3) }} {{ $invoice['currency'] }}</div>
            <div>{{ $isAr?'التوصيل':'Delivery' }}: {{ number_format($invoice['delivery_total'],3) }} {{ $invoice['currency'] }}</div>
            <div>{{ $isAr?'الضريبة':'Tax' }}: {{ number_format($invoice['tax_total'],3) }} {{ $invoice['currency'] }}</div>
            <div class="grand">{{ $isAr?'الإجمالي':'Grand total' }}: {{ number_format($invoice['grand_total'],3) }} {{ $invoice['currency'] }}</div>
        </div>

        @if($canManage && in_array($invoice['status'],['issued','reissued'],true))
        <form method="post" action="{{ route('admin.invoices.reissue',['invoice'=>$model->id]) }}" class="correction">
            @csrf
            <input name="reason" required minlength="3" maxlength="1000" placeholder="{{ $isAr?'سبب الإلغاء وإعادة الإصدار':'Reason for void and reissue' }}">
            <button class="foodex-primary" type="submit">{{ $isAr?'إلغاء وإعادة إصدار':'Void & reissue' }}</button>
        </form>
        @endif
    </section>
</div>
@if(request()->boolean('print'))
<script>window.addEventListener('load',()=>window.print());</script>
@endif
</body>
</html>
