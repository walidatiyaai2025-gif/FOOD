<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\B2bAccount;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class B2bFinanceController extends Controller
{
    public function invoices(Request $request): JsonResponse
    {
        $customer = $this->approvedCustomer($request);
        $paginator = Invoice::query()->where('customer_id', $customer->getKey())->latest('id')->paginate(min(max($request->integer('per_page', 20), 1), 100));

        return response()->json(['data' => collect($paginator->items())->map(fn (Invoice $invoice) => $this->invoicePayload($invoice))->values(), 'meta' => ['total' => $paginator->total()]]);
    }

    public function invoice(Request $request, Invoice $invoice): JsonResponse
    {
        $customer = $this->approvedCustomer($request);
        abort_unless((int) $invoice->customer_id === (int) $customer->getKey(), 404);

        return response()->json(['data' => $this->invoicePayload($invoice, true)]);
    }

    public function statement(Request $request): JsonResponse
    {
        $customer = $this->approvedCustomer($request);
        $invoices = Invoice::query()->where('customer_id', $customer->getKey())->orderBy('issued_at')->orderBy('id')->get();
        $invoiceIds = $invoices->pluck('id');
        $payments = Payment::query()->whereIn('invoice_id', $invoiceIds)->where('status', 'paid')->orderBy('created_at')->orderBy('id')->get();
        $debits = round((float) $invoices->sum('total'), 3);
        $credits = round((float) $payments->sum('amount'), 3);
        $transactions = collect();
        foreach ($invoices as $invoice) $transactions->push(['type' => 'invoice', 'reference' => $invoice->invoice_number, 'debit' => (float) $invoice->total, 'credit' => 0.0, 'occurred_at' => $invoice->issued_at ?? $invoice->created_at]);
        foreach ($payments as $payment) $transactions->push(['type' => 'payment', 'reference' => $payment->provider_reference ?? ('PAY-'.$payment->id), 'debit' => 0.0, 'credit' => (float) $payment->amount, 'occurred_at' => $payment->created_at]);

        return response()->json(['data' => ['currency' => 'KWD', 'total_debits' => $debits, 'total_credits' => $credits, 'balance' => round($debits - $credits, 3), 'transactions' => $transactions->sortBy('occurred_at')->values()]]);
    }

    private function approvedCustomer(Request $request): Customer
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $customer = Customer::query()->where('user_id', $user->getKey())->where('type', 'b2b')->first();
        abort_unless($customer instanceof Customer, 403, 'B2B customer profile is required.');
        abort_unless(B2bAccount::query()->where('customer_id', $customer->getKey())->where('status', 'active')->exists(), 403, 'Approved B2B account is required.');

        return $customer;
    }

    private function invoicePayload(Invoice $invoice, bool $withItems = false): array
    {
        $payload = ['id' => (int) $invoice->getKey(), 'invoice_number' => (string) $invoice->invoice_number, 'status' => (string) $invoice->status, 'currency' => (string) $invoice->currency, 'total' => (float) $invoice->total, 'issued_at' => $invoice->issued_at, 'due_at' => $invoice->due_at];
        if ($withItems) $payload['items'] = InvoiceItem::query()->where('invoice_id', $invoice->getKey())->orderBy('id')->get();

        return $payload;
    }
}
