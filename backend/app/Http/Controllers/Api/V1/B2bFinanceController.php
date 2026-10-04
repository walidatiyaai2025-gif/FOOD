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
use App\Services\B2bAccountLedgerService;
use App\Services\CustomerDomainResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class B2bFinanceController extends Controller
{
    public function __construct(private readonly B2bAccountLedgerService $ledger) {}

    public function invoices(Request $request): JsonResponse
    {
        $customer = $this->approvedCustomer($request);
        $filters = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'store_id' => ['nullable', 'integer', 'min:1'],
            'status' => ['nullable', 'string', 'max:40'],
            'q' => ['nullable', 'string', 'max:120'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        app(AuditLogger::class)->record('b2b.finance.invoices_viewed', $request->user(), $customer, null, null, $request);

        $page = (int) ($filters['page'] ?? 1);
        $perPage = (int) ($filters['per_page'] ?? 20);
        $storeId = isset($filters['store_id']) ? (int) $filters['store_id'] : null;
        $search = trim((string) ($filters['q'] ?? ''));
        $status = strtolower(trim((string) ($filters['status'] ?? '')));

        $rows = Invoice::query()
            ->where('b2b_customer_id', $customer->getKey())
            ->when($storeId, fn ($query, int $id) => $query->where('store_id', $id))
            ->when($filters['from'] ?? null, fn ($query, string $from) => $query->whereDate('issued_at', '>=', $from))
            ->when($filters['to'] ?? null, fn ($query, string $to) => $query->whereDate('issued_at', '<=', $to))
            ->when($search !== '', fn ($query) => $query->where('invoice_number', 'like', '%'.addcslashes($search, '%_\\').'%'))
            ->latest('issued_at')
            ->latest('id')
            ->get()
            ->map(fn (Invoice $invoice): array => $this->invoicePayload($invoice));

        if ($status !== '') {
            $rows = $rows
                ->filter(fn (array $row): bool => $row['display_status'] === $status)
                ->values();
        }

        $totals = $rows
            ->groupBy(fn (array $row): string => (string) $row['currency'])
            ->map(function ($currencyRows, string $currency): array {
                return [
                    'currency' => $currency,
                    'total' => round((float) $currencyRows->sum('total'), 3),
                    'paid' => round((float) $currencyRows->sum('paid_amount'), 3),
                    'outstanding' => round((float) $currencyRows->sum('outstanding_amount'), 3),
                    'credit' => round((float) $currencyRows->sum('credit_amount'), 3),
                    'overdue' => round((float) $currencyRows
                        ->where('display_status', 'overdue')
                        ->sum('outstanding_amount'), 3),
                ];
            })
            ->values();

        $total = $rows->count();
        $offset = ($page - 1) * $perPage;
        $pageRows = $rows->slice($offset, $perPage)->values();

        return response()->json([
            'data' => $pageRows,
            'filters' => [
                'from' => $filters['from'] ?? null,
                'to' => $filters['to'] ?? null,
                'store_id' => $storeId,
                'status' => $status !== '' ? $status : null,
                'q' => $search !== '' ? $search : null,
            ],
            'summary' => [
                'invoice_count' => $total,
                'overdue_count' => $rows->where('display_status', 'overdue')->count(),
                'totals' => $totals,
            ],
            'meta' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'has_more' => ($offset + $pageRows->count()) < $total,
                'account' => $this->ledger->summary($customer, $storeId),
            ],
            'generated_at' => now()->toAtomString(),
        ]);
    }

    public function invoice(Request $request, Invoice $invoice): JsonResponse
    {
        $customer = $this->approvedCustomer($request);
        abort_unless((int) $invoice->b2b_customer_id === (int) $customer->getKey(), 404);

        app(AuditLogger::class)->record('b2b.finance.invoice_viewed', $request->user(), $invoice, null, null, $request);

        return response()->json([
            'data' => $this->invoicePayload($invoice, true),
            'account' => $this->ledger->summary($customer, $this->storeId($request)),
        ]);
    }

    public function summary(Request $request): JsonResponse
    {
        $customer = $this->approvedCustomer($request);
        app(AuditLogger::class)->record('b2b.finance.account_summary_viewed', $request->user(), $customer, null, null, $request);

        return response()->json([
            'data' => $this->ledger->summary($customer, $this->storeId($request)),
        ]);
    }

    public function statement(Request $request): JsonResponse
    {
        $customer = $this->approvedCustomer($request);
        $filters = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'store_id' => ['nullable', 'integer', 'min:1'],
        ]);

        app(AuditLogger::class)->record('b2b.finance.statement_viewed', $request->user(), $customer, null, null, $request);

        return response()->json([
            'data' => $this->ledger->statement(
                $customer,
                $filters['from'] ?? null,
                $filters['to'] ?? null,
                isset($filters['store_id']) ? (int) $filters['store_id'] : null,
            ),
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
        $amounts = $this->ledger->invoiceAmounts($invoice);

        $displayStatus = $this->invoiceDisplayStatus($invoice, $amounts);

        $payload = [
            'id' => (int) $invoice->getKey(),
            'invoice_number' => (string) $invoice->invoice_number,
            'status' => (string) $invoice->status,
            'display_status' => $displayStatus,
            'currency' => (string) $invoice->currency,
            'total' => $amounts['invoice_total'],
            'paid_amount' => $amounts['paid_amount'],
            'debit_adjustments' => $amounts['debit_adjustments'],
            'credit_adjustments' => $amounts['credit_adjustments'],
            'outstanding_amount' => $amounts['outstanding_amount'],
            'credit_amount' => $amounts['credit_amount'],
            'store_id' => (int) $invoice->store_id,
            'order_id' => $invoice->order_id === null ? null : (int) $invoice->order_id,
            'issued_at' => $invoice->issued_at,
            'due_at' => $invoice->due_at,
            'pdf_path' => '/api/v1/invoices/'.(int) $invoice->getKey().'/download?channel=b2b&store_id='.(int) $invoice->store_id,
        ];

        if ($withItems) {
            $payload['items'] = InvoiceItem::query()
                ->where('invoice_id', $invoice->getKey())
                ->orderBy('id')
                ->get();
            $payload['payments'] = Payment::query()
                ->where('invoice_id', $invoice->getKey())
                ->where('status', 'paid')
                ->orderBy('created_at')
                ->orderBy('id')
                ->get(['id', 'provider', 'provider_reference', 'amount', 'currency', 'created_at']);
            $payload['ledger_entries'] = $this->ledger->invoiceLedgerEntries($invoice)->values();
        }

        return $payload;
    }

    /** @param array{invoice_total:float,paid_amount:float,debit_adjustments:float,credit_adjustments:float,outstanding_amount:float,credit_amount:float} $amounts */
    private function invoiceDisplayStatus(Invoice $invoice, array $amounts): string
    {
        $raw = strtolower(trim((string) $invoice->status));
        if (in_array($raw, ['cancelled', 'canceled', 'void', 'voided'], true)) {
            return 'cancelled';
        }
        if ($amounts['credit_amount'] > 0.0005) {
            return 'credited';
        }
        if ($amounts['outstanding_amount'] <= 0.0005) {
            return 'paid';
        }
        if ($invoice->due_at !== null && Carbon::parse((string) $invoice->due_at)->isPast()) {
            return 'overdue';
        }
        if ($amounts['paid_amount'] > 0.0005 || $amounts['credit_adjustments'] > 0.0005) {
            return 'partially_paid';
        }

        return 'open';
    }

    private function storeId(Request $request): ?int
    {
        $storeId = $request->integer('store_id');

        return $storeId > 0 ? $storeId : null;
    }
}
