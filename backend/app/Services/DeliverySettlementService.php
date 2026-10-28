<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\Order;
use App\Models\Payment;
use Illuminate\Support\Facades\DB;

final class DeliverySettlementService
{
    public function __construct(private readonly B2bAccountLedgerService $b2bLedger) {}

    /**
     * Resolve a whole page's settlements with bounded database queries instead of
     * fetching an invoice, payment and ledger separately for every order.
     *
     * @param  list<Order>  $orders
     * @return array<int, array<string, mixed>>
     */
    public function summarizeMany(array $orders): array
    {
        if ($orders === []) {
            return [];
        }

        $orderIds = array_map(
            static fn (Order $order): int => (int) $order->getKey(),
            $orders,
        );

        $invoices = Invoice::query()
            ->whereIn('order_id', $orderIds)
            ->whereIn('status', ['issued', 'reissued'])
            ->orderByDesc('revision')
            ->orderByDesc('id')
            ->get()
            ->unique('order_id')
            ->keyBy('order_id');

        $payments = Payment::query()
            ->whereIn('order_id', $orderIds)
            ->whereIn('id', function ($query) use ($orderIds): void {
                $query->selectRaw('MAX(id)')
                    ->from('payments')
                    ->whereIn('order_id', $orderIds)
                    ->groupBy('order_id');
            })
            ->get()
            ->keyBy('order_id');

        $invoiceIds = $invoices->pluck('id')->all();
        $paidTotals = $invoiceIds === []
            ? collect()
            : DB::table('payments')
                ->whereIn('invoice_id', $invoiceIds)
                ->whereIn('status', ['paid', 'captured'])
                ->selectRaw("invoice_id, SUM(CASE WHEN status = 'paid' THEN amount ELSE 0 END) as paid_amount, SUM(amount) as captured_amount")
                ->groupBy('invoice_id')
                ->get()
                ->keyBy('invoice_id');

        $b2bInvoiceIds = $invoices
            ->filter(static fn (Invoice $invoice): bool => strtolower((string) $invoice->channel) === 'b2b')
            ->pluck('id')
            ->all();
        $ledgerTotals = $b2bInvoiceIds === []
            ? collect()
            : DB::table('customer_account_ledger_entries')
                ->whereIn('invoice_id', $b2bInvoiceIds)
                ->selectRaw('invoice_id, COALESCE(SUM(debit), 0) as debits, COALESCE(SUM(credit), 0) as credits')
                ->selectRaw("COALESCE(SUM(CASE WHEN entry_type = 'payment' THEN credit ELSE 0 END), 0) as payment_credits")
                ->groupBy('invoice_id')
                ->get()
                ->keyBy('invoice_id');

        $summaries = [];
        foreach ($orders as $order) {
            $orderId = (int) $order->getKey();
            $invoice = $invoices->get($orderId);
            $invoiceAmounts = null;

            if ($invoice instanceof Invoice) {
                $invoiceId = (int) $invoice->getKey();
                $totals = $paidTotals->get($invoiceId);

                if (strtolower((string) $invoice->channel) === 'b2b') {
                    $paid = round((float) ($totals->captured_amount ?? 0), 3);
                    $ledger = $ledgerTotals->get($invoiceId);
                    $debits = round((float) ($ledger->debits ?? 0), 3);
                    $credits = round((float) ($ledger->credits ?? 0), 3);
                    $paymentCredits = round((float) ($ledger->payment_credits ?? 0), 3);
                    $invoiceAmounts = [
                        'paid_amount' => round($paid + $paymentCredits, 3),
                        'outstanding_amount' => round(max((float) $invoice->total + $debits - $paid - $credits, 0), 3),
                    ];
                } else {
                    $paid = round((float) ($totals->paid_amount ?? 0), 3);
                    $invoiceAmounts = [
                        'paid_amount' => $paid,
                        'outstanding_amount' => round(max((float) $invoice->total - $paid, 0), 3),
                    ];
                }
            }

            $summaries[$orderId] = $this->summarize(
                $order,
                $invoice instanceof Invoice ? $invoice : null,
                $payments->get($orderId),
                $invoiceAmounts,
            );
        }

        return $summaries;
    }

    /** @return array<string, mixed> */
    public function summarize(Order $order, ?Invoice $invoice, ?object $payment, ?array $invoiceAmounts = null): array
    {
        $metadataRaw = $payment?->metadata;
        $metadata = is_array($metadataRaw)
            ? $metadataRaw
            : (is_string($metadataRaw) ? json_decode($metadataRaw, true) : []);
        if (! is_array($metadata)) {
            $metadata = [];
        }

        $balanceApplied = 0.0;
        foreach (['customer_balance_applied', 'balance_applied', 'applied_balance_amount'] as $key) {
            if (isset($metadata[$key]) && is_numeric($metadata[$key])) {
                $balanceApplied = round(max(0, (float) $metadata[$key]), 3);
                break;
            }
        }

        $contractRemaining = null;
        foreach (['remaining_amount', 'remainder_amount', 'amount_after_balance'] as $key) {
            if (isset($metadata[$key]) && is_numeric($metadata[$key])) {
                $contractRemaining = round(max(0, (float) $metadata[$key]), 3);
                break;
            }
        }
        if ($contractRemaining === null && $balanceApplied > 0.0001) {
            $afterBalance = round(max((float) $order->grand_total - $balanceApplied, 0), 3);
            $paymentAmount = $payment === null ? $afterBalance : round(max(0, (float) $payment->amount), 3);
            $contractRemaining = min($afterBalance, $paymentAmount);
        }

        $paidAmount = 0.0;
        $outstanding = round(max(0, (float) $order->grand_total), 3);
        if ($invoice instanceof Invoice) {
            if ($invoiceAmounts !== null) {
                $paidAmount = round((float) $invoiceAmounts['paid_amount'], 3);
                $outstanding = round((float) $invoiceAmounts['outstanding_amount'], 3);
            } elseif (strtolower((string) $invoice->channel) === 'b2b') {
                $amounts = $this->b2bLedger->invoiceAmounts($invoice);
                $paidAmount = round((float) $amounts['paid_amount'], 3);
                $outstanding = round((float) $amounts['outstanding_amount'], 3);
            } else {
                $paidAmount = round((float) DB::table('payments')
                    ->where('invoice_id', $invoice->getKey())
                    ->where('status', 'paid')
                    ->sum('amount'), 3);
                $outstanding = round(max((float) $invoice->total - $paidAmount, 0), 3);
            }
        } elseif ($payment !== null && (string) $payment->status === 'paid') {
            $paidAmount = round((float) $payment->amount, 3);
            $outstanding = round(max((float) $order->grand_total - $paidAmount, 0), 3);
        }

        if ($contractRemaining !== null) {
            $outstanding = min($outstanding, $contractRemaining);
            if (
                $payment !== null
                && (string) $payment->status === 'paid'
                && (float) $payment->amount + 0.0001 >= $contractRemaining
            ) {
                $outstanding = 0.0;
            }
        }

        $paymentProvider = $payment === null ? '' : (string) $payment->provider;
        $rawRemainderMethod = strtolower(trim((string) (
            $metadata['remainder_method']
            ?? ($paymentProvider !== '' ? $paymentProvider : $order->payment_method)
            ?? ''
        )));
        $remainderMethod = match ($rawRemainderMethod) {
            'cash_on_delivery', 'cod' => 'cash_on_delivery',
            'account_debt', 'account_credit', 'account' => 'account_debt',
            default => $rawRemainderMethod,
        };

        $paymentState = $outstanding <= 0.0001
            ? 'fully_settled'
            : (($paidAmount > 0.0001 || $balanceApplied > 0.0001)
                ? 'partially_settled'
                : 'unpaid');
        $collectNow = $paymentState !== 'fully_settled' && $remainderMethod === 'cash_on_delivery'
            ? $outstanding
            : 0.0;

        return [
            'currency' => (string) ($invoice instanceof Invoice ? $invoice->currency : $order->currency),
            'order_total' => round((float) $order->grand_total, 3),
            'balance_applied' => $balanceApplied,
            'paid_amount' => $paidAmount,
            'remaining_amount' => $outstanding,
            'remainder_method' => $remainderMethod,
            'payment_state' => $paymentState,
            'amount_to_collect_now' => round($collectNow, 3),
            'invoice_outstanding_amount' => $outstanding,
        ];
    }
}
