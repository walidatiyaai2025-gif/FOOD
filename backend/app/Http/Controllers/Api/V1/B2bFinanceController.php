<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\B2bAccount;
use App\Models\B2bCustomer;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Payment;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\CustomerDomainResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class B2bFinanceController extends Controller
{
    public function invoices(Request $request): JsonResponse
    {
        $customer = $this->approvedCustomer($request);
        app(AuditLogger::class)->record('b2b.finance.invoices_viewed', $request->user(), $customer, null, null, $request);

        $paginator = Invoice::query()
            ->where('b2b_customer_id', $customer->getKey())
            ->latest('id')
            ->paginate(min(max($request->integer('per_page', 20), 1), 100));

        return response()->json([
            'data' => collect($paginator->items())->map(fn (Invoice $invoice) => $this->invoicePayload($invoice))->values(),
            'meta' => ['total' => $paginator->total()],
        ]);
    }

    public function invoice(Request $request, Invoice $invoice): JsonResponse
    {
        $customer = $this->approvedCustomer($request);
        abort_unless((int) $invoice->b2b_customer_id === (int) $customer->getKey(), 404);

        app(AuditLogger::class)->record('b2b.finance.invoice_viewed', $request->user(), $invoice, null, null, $request);

        return response()->json(['data' => $this->invoicePayload($invoice, true)]);
    }

    public function statement(Request $request): JsonResponse
    {
        $customer = $this->approvedCustomer($request);
        $invoices = Invoice::query()
            ->where('b2b_customer_id', $customer->getKey())
            ->orderBy('issued_at')
            ->orderBy('id')
            ->get();
        $invoiceIds = $invoices->pluck('id');

        app(AuditLogger::class)->record('b2b.finance.statement_viewed', $request->user(), $customer, null, null, $request);

        $payments = Payment::query()
            ->whereIn('invoice_id', $invoiceIds)
            ->where('status', 'paid')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        $debits = round((float) $invoices->sum('total'), 3);
        $credits = round((float) $payments->sum('amount'), 3);
        $transactions = collect();

        foreach ($invoices as $invoice) {
            $transactions->push([
                'type' => 'invoice',
                'reference' => $invoice->invoice_number,
                'debit' => (float) $invoice->total,
                'credit' => 0.0,
                'occurred_at' => $invoice->issued_at ?? $invoice->created_at,
            ]);
        }

        foreach ($payments as $payment) {
            $transactions->push([
                'type' => 'payment',
                'reference' => $payment->provider_reference ?? ('PAY-'.$payment->id),
                'debit' => 0.0,
                'credit' => (float) $payment->amount,
                'occurred_at' => $payment->created_at,
            ]);
        }

        return response()->json([
            'data' => [
                'currency' => 'KWD',
                'total_debits' => $debits,
                'total_credits' => $credits,
                'balance' => round($debits - $credits, 3),
                'transactions' => $transactions->sortBy('occurred_at')->values(),
            ],
        ]);
    }

    private function approvedCustomer(Request $request): B2bCustomer
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $customer = app(CustomerDomainResolver::class)->b2bFromRequest($user, $request);
        abort_unless(
            B2bAccount::query()
                ->where('b2b_customer_id', $customer->getKey())
                ->where('status', 'active')
                ->exists(),
            403,
            'Approved B2B account is required.',
        );

        return $customer;
    }

    private function invoicePayload(Invoice $invoice, bool $withItems = false): array
    {
        $payload = [
            'id' => (int) $invoice->getKey(),
            'invoice_number' => (string) $invoice->invoice_number,
            'status' => (string) $invoice->status,
            'currency' => (string) $invoice->currency,
            'total' => (float) $invoice->total,
            'issued_at' => $invoice->issued_at,
            'due_at' => $invoice->due_at,
        ];

        if ($withItems) {
            $payload['items'] = InvoiceItem::query()
                ->where('invoice_id', $invoice->getKey())
                ->orderBy('id')
                ->get();
        }

        return $payload;
    }
}
