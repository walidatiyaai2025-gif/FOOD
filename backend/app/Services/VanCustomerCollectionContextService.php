<?php

namespace App\Services;

use App\Models\Invoice;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class VanCustomerCollectionContextService
{
    public function __construct(private readonly B2bAccountLedgerService $ledger) {}

    /** @return array{customer_type:string,customer_id:int,store_id:int,invoices:Collection<int,array{id:int,number:string,currency:uppercase-string,total:float,outstanding_amount:float,due_at:string|null}>,outstanding_total_by_currency:Collection<string,float>} */
    public function context(string $type, int $customer, int $storeId): array
    {
        $invoices = $this->openInvoices($type, $customer, $storeId);

        return [
            'customer_type' => $type,
            'customer_id' => $customer,
            'store_id' => $storeId,
            'invoices' => $invoices,
            'outstanding_total_by_currency' => $invoices
                ->groupBy('currency')
                ->map(
                    fn (Collection $rows): float => round(
                        (float) $rows->sum('outstanding_amount'),
                        3,
                    ),
                ),
        ];
    }

    public function customerColumn(string $type): string
    {
        return match ($type) {
            'b2b' => 'b2b_customer_id',
            'b2c' => 'b2c_customer_id',
            default => abort(404),
        };
    }

    /** @return Collection<int,array{id:int,number:string,currency:uppercase-string,total:float,outstanding_amount:float,due_at:string|null}> */
    public function openInvoices(string $type, int $customer, int $storeId): Collection
    {
        $column = $this->customerColumn($type);

        return Invoice::query()
            ->where($column, $customer)
            ->where('store_id', $storeId)
            ->where('channel', $type)
            ->whereIn('status', ['issued', 'reissued'])
            ->orderBy('due_at')
            ->orderBy('id')
            ->get()
            ->map(function (Invoice $invoice) use ($type): array {
                return [
                    'id' => (int) $invoice->getKey(),
                    'number' => (string) $invoice->invoice_number,
                    'currency' => strtoupper((string) $invoice->currency),
                    'total' => round((float) $invoice->total, 3),
                    'outstanding_amount' => $this->outstandingAmount($invoice, $type),
                    'due_at' => $invoice->due_at === null ? null : (string) $invoice->due_at,
                ];
            })
            ->filter(fn (array $invoice): bool => (float) $invoice['outstanding_amount'] > 0.0001)
            ->values();
    }

    public function outstandingAmount(Invoice $invoice, string $type): float
    {
        if ($type === 'b2b') {
            return round((float) $this->ledger->invoiceAmounts($invoice)['outstanding_amount'], 3);
        }

        $paid = (float) DB::table('payments')
            ->where('invoice_id', $invoice->getKey())
            ->whereIn('status', ['paid', 'captured'])
            ->sum('amount');

        return round(max((float) $invoice->total - $paid, 0), 3);
    }
}
