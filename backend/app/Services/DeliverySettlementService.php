<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\Order;
use Illuminate\Support\Facades\DB;

final class DeliverySettlementService
{
    public function __construct(private readonly B2bAccountLedgerService $b2bLedger) {}

    /** @return array<string, mixed> */
    public function summarize(Order $order, ?Invoice $invoice, ?object $payment): array
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
            if (strtolower((string) $invoice->channel) === 'b2b') {
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
