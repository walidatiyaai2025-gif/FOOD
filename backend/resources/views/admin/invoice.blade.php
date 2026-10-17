<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ app()->getLocale()==='ar'?'rtl':'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>{{ app()->getLocale()==='ar'?'فاتورة':'Invoice' }} {{ $invoice['display_invoice_number'] ?? $invoice['invoice_number'] }} · FOODEX</title>
    @include('admin._brand-components')
    <style>
        body{margin:0;background:var(--foodex-background);color:var(--foodex-ink)}
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
        dialog{border:0;border-radius:16px;padding:0;max-width:640px;width:calc(100% - 30px);box-shadow:var(--foodex-shadow-raised)}dialog::backdrop{background:rgba(23,32,51,.42)}dialog .correction{padding:18px;margin:0}
        @media(max-width:640px){.wrap{padding:0}.invoice-card{padding:14px}.head{display:grid;grid-template-columns:minmax(0,1fr)}.actions{flex-wrap:nowrap;overflow-x:auto;max-width:100%;padding-bottom:2px}.actions>*{flex:0 0 auto}.grid{grid-template-columns:1fr;margin:14px 0}.summary{max-width:none;margin-inline-start:0}.correction{display:grid;grid-template-columns:minmax(0,1fr);gap:10px}.correction input{min-width:0;width:100%}.correction button{width:100%;min-height:44px}dialog{width:100%;max-width:none;max-height:92dvh;margin:auto 0 0;border-radius:18px 18px 0 0}}
        @media print{body{padding:0!important;background:#fff!important}.sidebar,.foodex-mobile-web-bar,.foodex-mobile-nav-backdrop{display:none!important}.foodex-admin-layout{display:block!important}.foodex-admin-main{padding:0!important}.wrap{max-width:none!important;margin:0!important;padding:0!important}.invoice-card{box-shadow:none!important;border:0!important;padding:0!important}}
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
<div class="foodex-admin-layout" data-foodex-mobile-web-page="invoice">
<aside class="sidebar">@include('admin._sidebar', ['navGroups' => app(\App\Support\AdminNavigation::class)->groupsFor(auth()->user()), 'navContext' => 'order_operations', 'user' => auth()->user()])</aside> {{-- localization-gate: allow Blade include expression --}}
<main class="foodex-admin-main">
@include('admin._global-header')
<div class="wrap foodex-admin-page">
    <div style="margin-bottom:12px"><a href="javascript:history.back()">← {{ $isAr?'رجوع':'Back' }}</a></div>
    <section class="invoice-card foodex-card">
        <div class="head">
            <div>
                <h1 style="margin:0 0 8px">{{ $isAr ? 'فاتورة' : 'Invoice' }} {{ $invoice['display_invoice_number'] ?? $invoice['invoice_number'] }}</h1>
                <div class="muted">{{ $isAr ? 'طلب' : 'Order' }}: {{ $invoice['display_order_number'] ?? $invoice['order_number'] }} · {{ $invoice['store_name'] }} · {{ $channelLabel }}</div>
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
                    <tr><td>{{ $item['name'] }}</td><td>{{ $item['sku'] }}</td><td>{{ $item['quantity'] }}</td><td>{{ \App\Support\DisplayNumber::money($item['unit_price'], $invoice['currency']) }}</td><td>{{ \App\Support\DisplayNumber::money($item['line_total'], $invoice['currency']) }} {{ $invoice['currency'] }}</td></tr>
                @endforeach
                </tbody>
            </table>
        </div>

        <div class="summary">
            <div>{{ $isAr?'الإجمالي الفرعي':'Subtotal' }}: {{ \App\Support\DisplayNumber::money($invoice['subtotal'], $invoice['currency']) }} {{ $invoice['currency'] }}</div>
            <div>{{ $isAr?'الخصم':'Discount' }}: {{ \App\Support\DisplayNumber::money($invoice['discount_total'], $invoice['currency']) }} {{ $invoice['currency'] }}</div>
            <div>{{ $isAr?'التوصيل':'Delivery' }}: {{ \App\Support\DisplayNumber::money($invoice['delivery_total'], $invoice['currency']) }} {{ $invoice['currency'] }}</div>
            <div>{{ $isAr?'الضريبة':'Tax' }}: {{ \App\Support\DisplayNumber::money($invoice['tax_total'], $invoice['currency']) }} {{ $invoice['currency'] }}</div>
            <div class="grand">{{ $isAr?'الإجمالي':'Grand total' }}: {{ \App\Support\DisplayNumber::money($invoice['grand_total'], $invoice['currency']) }} {{ $invoice['currency'] }}</div>
            @if($amounts)<div>{{ $isAr?'المدفوع':'Paid' }}: {{ \App\Support\DisplayNumber::money($amounts['paid_amount'], $invoice['currency']) }} {{ $invoice['currency'] }}</div><div><strong>{{ $isAr?'المتبقي':'Outstanding' }}: {{ \App\Support\DisplayNumber::money($amounts['outstanding_amount'], $invoice['currency']) }} {{ $invoice['currency'] }}</strong></div>@endif
        </div>

        @if($canManage && $amounts && $amounts['outstanding_amount'] > 0.0005 && strtolower((string)$invoice['channel'])==='b2b')
        <button class="foodex-primary" type="button" onclick="document.getElementById('invoice-settlement-dialog').showModal()">{{ $isAr?'تسجيل دفعة / تسوية':'Record payment / Settle' }}</button>
        <dialog id="invoice-settlement-dialog">
            <form method="post" action="{{ route('admin.invoices.settle',['invoice'=>$model->id]) }}" class="correction">
                @csrf
                <input name="amount" type="number" min="0.001" max="{{ number_format($amounts['outstanding_amount'],3,'.','') }}" step="0.001" value="{{ number_format($amounts['outstanding_amount'],3,'.','') }}" required>
                <input name="reference" maxlength="120" placeholder="{{ $isAr?'مرجع الدفعة':'Payment reference' }}">
                <input name="description" maxlength="500" placeholder="{{ $isAr?'ملاحظات':'Notes' }}">
                <button type="button" onclick="document.getElementById('invoice-settlement-dialog').close()">{{ $isAr?'إلغاء':'Cancel' }}</button>
                <button class="foodex-primary" type="submit">{{ $isAr?'تسجيل الدفعة':'Record payment' }}</button>
            </form>
        </dialog>
        @endif

        @if($canManage && in_array($invoice['status'],['issued','reissued'],true))
        <form method="post" action="{{ route('admin.invoices.reissue',['invoice'=>$model->id]) }}" class="correction">
            @csrf
            <input name="reason" required minlength="3" maxlength="1000" placeholder="{{ $isAr?'سبب الإلغاء وإعادة الإصدار':'Reason for void and reissue' }}">
            <button class="foodex-primary" type="submit">{{ $isAr?'إلغاء وإعادة إصدار':'Void & reissue' }}</button>
        </form>
        @endif
    </section>
</div>
</main>
</div>
@if(request()->boolean('print'))
<script>window.addEventListener('load',()=>window.print());</script>
@endif
</body>
</html>
