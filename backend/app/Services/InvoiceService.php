<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class InvoiceService
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly DashboardOperationalNotifier $notifier,
        private readonly PdfDocumentFactory $pdfDocuments,
    ) {}

    public function issueForOrder(Order $order, ?User $actor = null): Invoice
    {
        $result = DB::transaction(function () use ($order, $actor): Invoice {
            $locked = Order::query()->whereKey($order->getKey())->lockForUpdate()->firstOrFail();

            $existing = Invoice::query()
                ->where('order_id', $locked->getKey())
                ->whereIn('status', ['issued', 'reissued'])
                ->latest('revision')
                ->latest('id')
                ->first();

            if ($existing instanceof Invoice) {
                Payment::query()->where('order_id', $locked->getKey())->update(['invoice_id' => $existing->getKey()]);

                return $existing;
            }

            $items = OrderItem::query()
                ->where('order_id', $locked->getKey())
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if ($items->isEmpty()) {
                throw ValidationException::withMessages([
                    'invoice' => ['An invoice cannot be issued for an order without commercial lines.'],
                ]);
            }

            $payment = Payment::query()
                ->where('order_id', $locked->getKey())
                ->latest('id')
                ->lockForUpdate()
                ->first();

            $store = DB::table('stores')->where('id', $locked->store_id)->first(['name']);
            $domain = strtolower((string) $locked->channel) === 'b2b'
                ? DB::table('b2b_customers')->where('id', $locked->b2b_customer_id)->first()
                : DB::table('b2c_customers')->where('id', $locked->b2c_customer_id)->first();
            $platformCustomerId = $domain?->user_id === null
                ? null
                : DB::table('platform_customers')->where('user_id', $domain->user_id)->value('id');

            $previous = Invoice::query()
                ->where('order_id', $locked->getKey())
                ->orderByDesc('revision')
                ->orderByDesc('id')
                ->first();
            $revision = max(1, ((int) ($previous->revision ?? 0)) + 1);
            $rootInvoiceId = $previous?->revision_of_invoice_id ?: $previous?->getKey();
            $number = 'INV-'.Str::upper((string) $locked->order_number).($revision > 1 ? '-R'.$revision : '');

            $invoice = Invoice::query()->create([
                'order_id' => $locked->getKey(),
                'store_id' => $locked->store_id,
                'customer_id' => $locked->customer_id,
                'platform_customer_id' => $platformCustomerId,
                'b2b_customer_id' => $locked->b2b_customer_id,
                'b2c_customer_id' => $locked->b2c_customer_id,
                'invoice_number' => $number,
                'status' => $revision > 1 ? 'reissued' : 'issued',
                'channel' => strtolower((string) $locked->channel),
                'order_number_snapshot' => $locked->order_number,
                'store_name_snapshot' => $store?->name,
                'customer_name_snapshot' => $domain?->name,
                'customer_email_snapshot' => $domain?->email,
                'customer_phone_snapshot' => $domain?->phone,
                'currency' => (string) $locked->currency,
                'subtotal' => (float) $locked->subtotal,
                'discount_total' => (float) $locked->discount_total,
                'delivery_total' => (float) $locked->delivery_total,
                'tax_total' => (float) ($locked->tax_total ?? 0),
                'total' => (float) $locked->grand_total,
                'payment_method_snapshot' => $payment->provider ?? $locked->payment_method,
                'payment_status_snapshot' => $payment?->status,
                'b2b_account_id_snapshot' => $locked->b2b_account_id_snapshot,
                'price_tier_id_snapshot' => $locked->price_tier_id_snapshot,
                'price_tier_code_snapshot' => $locked->price_tier_code_snapshot,
                'commercial_snapshot' => [
                    'quote_id' => $locked->quote_id,
                    'quoted_at' => $locked->quoted_at?->toAtomString(),
                    'pricing' => $locked->pricing_snapshot,
                    'order_totals' => [
                        'subtotal' => (float) $locked->subtotal,
                        'discount_total' => (float) $locked->discount_total,
                        'delivery_total' => (float) $locked->delivery_total,
                        'tax_total' => (float) ($locked->tax_total ?? 0),
                        'grand_total' => (float) $locked->grand_total,
                    ],
                ],
                'revision' => $revision,
                'revision_of_invoice_id' => $rootInvoiceId,
                'issued_at' => now(),
            ]);

            foreach ($items as $item) {
                InvoiceItem::query()->create([
                    'invoice_id' => $invoice->getKey(),
                    'product_id' => $item->product_id,
                    'description' => (string) $item->name_snapshot,
                    'sku_snapshot' => $item->sku_snapshot,
                    'quantity' => (float) $item->quantity,
                    'quantity_conversion_factor' => (float) ($item->quantity_conversion_factor ?? 1),
                    'base_unit_price_snapshot' => $item->base_unit_price_snapshot,
                    'unit_price' => (float) $item->unit_price,
                    'line_discount_total' => (float) ($item->line_discount_total ?? 0),
                    'line_tax_total' => (float) ($item->line_tax_total ?? 0),
                    'line_total' => (float) $item->line_total,
                    'currency' => (string) ($item->currency ?: $locked->currency),
                    'b2b_account_id_snapshot' => $item->b2b_account_id_snapshot,
                    'price_tier_id_snapshot' => $item->price_tier_id_snapshot,
                    'price_tier_code_snapshot' => $item->price_tier_code_snapshot,
                    'line_snapshot' => [
                        'product_id' => $item->product_id,
                        'sku' => $item->sku_snapshot,
                        'name' => $item->name_snapshot,
                        'quantity' => (float) $item->quantity,
                        'selling_unit_code' => $item->selling_unit_code_snapshot,
                        'selling_unit_name' => $item->selling_unit_name_snapshot,
                        'selling_unit_quantity' => $item->selling_unit_quantity === null
                            ? (float) $item->quantity
                            : (float) $item->selling_unit_quantity,
                        'base_quantity' => $item->base_quantity === null
                            ? (float) $item->quantity
                            : (float) $item->base_quantity,
                        'conversion_factor' => $item->conversion_factor_snapshot === null
                            ? (float) ($item->quantity_conversion_factor ?? 1)
                            : (float) $item->conversion_factor_snapshot,
                        'selling_unit_sku' => $item->selling_unit_sku_snapshot,
                        'selling_unit_barcode' => $item->selling_unit_barcode_snapshot,
                        'unit_price' => (float) $item->unit_price,
                        'line_discount_total' => (float) ($item->line_discount_total ?? 0),
                        'line_tax_total' => (float) ($item->line_tax_total ?? 0),
                        'line_total' => (float) $item->line_total,
                    ],
                ]);
            }

            Payment::query()
                ->where('order_id', $locked->getKey())
                ->update(['invoice_id' => $invoice->getKey()]);

            if ($locked->commercial_locked_at === null) {
                $locked->forceFill(['commercial_locked_at' => now()])->saveQuietly();
            }

            $this->audit->record('invoice.issued', $actor, $invoice, null, [
                'store_id' => (int) $locked->store_id,
                'channel' => (string) $locked->channel,
                'order_id' => (int) $locked->getKey(),
                'invoice_number' => $invoice->invoice_number,
                'revision' => $revision,
                'total' => (float) $invoice->total,
                'currency' => (string) $invoice->currency,
            ]);

            return $invoice->fresh();
        }, 3);

        $this->notifier->invoiceChanged(
            $order->fresh(),
            $result,
            (string) $result->status,
        );

        return $result;
    }

    public function void(Invoice $invoice, User $actor, string $reason): Invoice
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw ValidationException::withMessages(['reason' => ['A void reason is required.']]);
        }

        $result = DB::transaction(function () use ($invoice, $actor, $reason): Invoice {
            $locked = Invoice::query()->whereKey($invoice->getKey())->lockForUpdate()->firstOrFail();

            if (! in_array((string) $locked->status, ['issued', 'reissued'], true)) {
                return $locked;
            }

            $before = ['status' => (string) $locked->status];
            $locked->forceFill([
                'status' => 'voided',
                'voided_at' => now(),
                'void_reason' => $reason,
                'voided_by_user_id' => $actor->getKey(),
            ])->save();

            $this->audit->record('invoice.voided', $actor, $locked, $before, [
                'store_id' => $locked->store_id,
                'status' => 'voided',
                'reason' => $reason,
            ]);

            return $locked->fresh();
        }, 3);

        if ((string) $result->status === 'voided' && $result->order_id !== null) {
            $order = Order::query()->find((int) $result->order_id);
            if ($order instanceof Order) {
                $this->notifier->invoiceChanged($order, $result, 'voided');
            }
        }

        return $result;
    }

    public function voidForOrder(Order $order, User $actor, string $reason): ?Invoice
    {
        $invoice = Invoice::query()
            ->where('order_id', $order->getKey())
            ->whereIn('status', ['issued', 'reissued'])
            ->latest('revision')
            ->latest('id')
            ->first();

        return $invoice instanceof Invoice ? $this->void($invoice, $actor, $reason) : null;
    }

    public function voidAndReissue(Invoice $invoice, User $actor, string $reason): Invoice
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw ValidationException::withMessages(['reason' => ['A correction reason is required.']]);
        }

        return DB::transaction(function () use ($invoice, $actor, $reason): Invoice {
            $voided = $this->void($invoice, $actor, $reason);

            return $this->issueForOrder(Order::query()->findOrFail($voided->order_id), $actor);
        }, 3);
    }

    /** @return array<string, mixed> */
    public function payload(Invoice $invoice, bool $withItems = true): array
    {
        $invoice->refresh();
        $payment = Payment::query()->where('invoice_id', $invoice->getKey())->latest('id')->first();

        $payload = [
            'id' => (int) $invoice->getKey(),
            'invoice_number' => (string) $invoice->invoice_number,
            'revision' => (int) ($invoice->revision ?? 1),
            'status' => (string) $invoice->status,
            'order_id' => $invoice->order_id === null ? null : (int) $invoice->order_id,
            'order_number' => $invoice->order_number_snapshot,
            'store_id' => $invoice->store_id === null ? null : (int) $invoice->store_id,
            'store_name' => $invoice->store_name_snapshot,
            'channel' => $invoice->channel,
            'customer' => [
                'name' => $invoice->customer_name_snapshot,
                'email' => $invoice->customer_email_snapshot,
                'phone' => $invoice->customer_phone_snapshot,
            ],
            'currency' => (string) $invoice->currency,
            'subtotal' => (float) ($invoice->subtotal ?? 0),
            'discount_total' => (float) ($invoice->discount_total ?? 0),
            'delivery_total' => (float) ($invoice->delivery_total ?? 0),
            'tax_total' => (float) ($invoice->tax_total ?? 0),
            'grand_total' => (float) $invoice->total,
            'payment_method' => $payment->provider ?? $invoice->payment_method_snapshot,
            'payment_status' => $payment->status ?? $invoice->payment_status_snapshot,
            'price_tier_id' => $invoice->price_tier_id_snapshot === null ? null : (int) $invoice->price_tier_id_snapshot,
            'price_tier_code' => $invoice->price_tier_code_snapshot,
            'issued_at' => $invoice->issued_at === null ? null : CarbonImmutable::parse((string) $invoice->issued_at)->toAtomString(),
            'voided_at' => $invoice->voided_at === null ? null : CarbonImmutable::parse((string) $invoice->voided_at)->toAtomString(),
            'void_reason' => $invoice->void_reason,
        ];

        if ($withItems) {
            $payload['items'] = InvoiceItem::query()
                ->where('invoice_id', $invoice->getKey())
                ->orderBy('id')
                ->get()
                ->map(static fn (InvoiceItem $item): array => [
                    'product_id' => $item->product_id === null ? null : (int) $item->product_id,
                    'sku' => $item->sku_snapshot,
                    'name' => (string) $item->description,
                    'quantity' => (float) $item->quantity,
                    'unit_price' => (float) $item->unit_price,
                    'discount_total' => (float) ($item->line_discount_total ?? 0),
                    'tax_total' => (float) ($item->line_tax_total ?? 0),
                    'line_total' => (float) $item->line_total,
                    'currency' => $item->currency ?: $invoice->currency,
                    'price_tier_code' => $item->price_tier_code_snapshot,
                ])->values()->all();
        }

        return $payload;
    }

    public function renderPdf(Invoice $invoice, string $locale = 'en'): string
    {
        $locale = strtolower($locale) === 'ar' ? 'ar' : 'en';
        $data = $this->payload($invoice, true);
        $rtl = $locale === 'ar';

        $pdf = $this->pdfDocuments->create($rtl);
        $pdf->AddPage();
        $pdf->writeHTML($this->html($data, $locale), true, false, true, false, '');

        return (string) $pdf->Output('', 'S');
    }

    /** @param array<string, mixed> $data */
    private function html(array $data, string $locale): string
    {
        $ar = $locale === 'ar';
        $e = static fn (mixed $value): string => htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $money = static fn (mixed $value): string => number_format((float) $value, 3, '.', ',');
        $labels = $ar ? [
            'title' => 'فاتورة', 'invoice' => 'رقم الفاتورة', 'order' => 'رقم الطلب', 'store' => 'المتجر',
            'customer' => 'العميل', 'date' => 'تاريخ الإصدار', 'item' => 'الصنف', 'qty' => 'الكمية',
            'unit' => 'سعر الوحدة', 'line' => 'الإجمالي', 'subtotal' => 'الإجمالي الفرعي',
            'discount' => 'الخصم', 'delivery' => 'التوصيل', 'tax' => 'الضريبة', 'total' => 'الإجمالي',
            'payment' => 'الدفع', 'status' => 'الحالة',
        ] : [
            'title' => 'Invoice', 'invoice' => 'Invoice number', 'order' => 'Order number', 'store' => 'Store',
            'customer' => 'Customer', 'date' => 'Issue date', 'item' => 'Item', 'qty' => 'Qty',
            'unit' => 'Unit price', 'line' => 'Line total', 'subtotal' => 'Subtotal',
            'discount' => 'Discount', 'delivery' => 'Delivery', 'tax' => 'Tax', 'total' => 'Grand total',
            'payment' => 'Payment', 'status' => 'Status',
        ];

        $rows = '';
        foreach ((array) ($data['items'] ?? []) as $item) {
            $rows .= '<tr><td>'.$e($item['name']).'<br><small>'.$e($item['sku']).'</small></td>'
                .'<td>'.$e($item['quantity']).'</td><td>'.$money($item['unit_price']).'</td>'
                .'<td>'.$money($item['line_total']).' '.$e($data['currency']).'</td></tr>';
        }

        return '<div style="font-family:dejavusans;"><h1>'.$e($labels['title']).'</h1>'
            .'<table cellpadding="4"><tr><td><b>'.$e($labels['invoice']).':</b> '.$e($data['invoice_number']).'</td>'
            .'<td><b>'.$e($labels['date']).':</b> '.$e($data['issued_at']).'</td></tr>'
            .'<tr><td><b>'.$e($labels['order']).':</b> '.$e($data['order_number']).'</td>'
            .'<td><b>'.$e($labels['store']).':</b> '.$e($data['store_name']).'</td></tr>'
            .'<tr><td><b>'.$e($labels['customer']).':</b> '.$e($data['customer']['name'] ?? '').'</td>'
            .'<td><b>'.$e($labels['status']).':</b> '.$e($data['status']).'</td></tr></table><br>'
            .'<table border="1" cellpadding="5"><thead><tr><th>'.$e($labels['item']).'</th><th>'.$e($labels['qty']).'</th>'
            .'<th>'.$e($labels['unit']).'</th><th>'.$e($labels['line']).'</th></tr></thead><tbody>'.$rows.'</tbody></table><br>'
            .'<table cellpadding="4"><tr><td>'.$e($labels['subtotal']).'</td><td>'.$money($data['subtotal']).' '.$e($data['currency']).'</td></tr>'
            .'<tr><td>'.$e($labels['discount']).'</td><td>'.$money($data['discount_total']).' '.$e($data['currency']).'</td></tr>'
            .'<tr><td>'.$e($labels['delivery']).'</td><td>'.$money($data['delivery_total']).' '.$e($data['currency']).'</td></tr>'
            .'<tr><td>'.$e($labels['tax']).'</td><td>'.$money($data['tax_total']).' '.$e($data['currency']).'</td></tr>'
            .'<tr><td><b>'.$e($labels['total']).'</b></td><td><b>'.$money($data['grand_total']).' '.$e($data['currency']).'</b></td></tr>'
            .'<tr><td>'.$e($labels['payment']).'</td><td>'.$e($data['payment_method']).' / '.$e($data['payment_status']).'</td></tr></table></div>';
    }
}
