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
use App\Services\ReportExportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class B2bFinanceController extends Controller
{
    public function __construct(private readonly B2bAccountLedgerService $ledger) {}

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
            'meta' => [
                'total' => $paginator->total(),
                'account' => $this->ledger->summary($customer, $this->storeId($request)),
            ],
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
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $statement = $this->ledger->statement(
            $customer,
            $filters['from'] ?? null,
            $filters['to'] ?? null,
            isset($filters['store_id']) ? (int) $filters['store_id'] : null,
        );
        $transactions = collect($statement['transactions']);
        $page = max(1, (int) ($filters['page'] ?? 1));
        $perPage = min(max((int) ($filters['per_page'] ?? 30), 1), 100);
        $total = $transactions->count();
        $lastPage = max(1, (int) ceil($total / $perPage));

        $statement['transactions'] = $transactions
            ->forPage($page, $perPage)
            ->values()
            ->all();
        $statement['pagination'] = [
            'current_page' => $page,
            'per_page' => $perPage,
            'total' => $total,
            'last_page' => $lastPage,
            'has_more' => $page < $lastPage,
        ];

        app(AuditLogger::class)->record('b2b.finance.statement_viewed', $request->user(), $customer, null, null, $request);

        return response()->json(['data' => $statement]);
    }

    public function statementExport(Request $request): Response
    {
        $customer = $this->approvedCustomer($request);
        $filters = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'store_id' => ['nullable', 'integer', 'min:1'],
            'format' => ['required', 'in:xlsx,pdf'],
            'locale' => ['nullable', 'in:ar,en'],
        ]);
        $statement = $this->ledger->statement(
            $customer,
            $filters['from'] ?? null,
            $filters['to'] ?? null,
            isset($filters['store_id']) ? (int) $filters['store_id'] : null,
        );
        $currency = (string) ($statement['currency'] ?? '');
        $report = [
            'report' => 'account_statement',
            'generated_at' => now()->toIso8601String(),
            'filters' => [
                'from' => $filters['from'] ?? '',
                'to' => $filters['to'] ?? '',
            ],
            'columns' => ['date', 'type', 'reference', 'description', 'debit', 'credit', 'running_balance', 'currency'],
            'rows' => collect($statement['transactions'])->map(static fn (array $row): array => [
                'date' => (string) ($row['occurred_at'] ?? ''),
                'type' => (string) ($row['type'] ?? ''),
                'reference' => (string) ($row['reference'] ?? ''),
                'description' => (string) ($row['description'] ?? ''),
                'debit' => (float) ($row['debit'] ?? 0),
                'credit' => (float) ($row['credit'] ?? 0),
                'running_balance' => (float) ($row['running_balance'] ?? 0),
                'currency' => (string) ($row['currency'] ?? $currency),
            ])->all(),
            'kpis' => [
                'opening_balance' => ((float) $statement['opening_balance']).' '.$currency,
                'period_debits' => ((float) $statement['period_debits']).' '.$currency,
                'period_credits' => ((float) $statement['period_credits']).' '.$currency,
                'closing_balance' => ((float) $statement['closing_balance']).' '.$currency,
                'current_balance' => ((float) $statement['balance']).' '.$currency,
            ],
        ];

        $exports = app(ReportExportService::class);
        $format = (string) $filters['format'];
        $locale = (string) ($filters['locale'] ?? $request->user()->locale ?? 'en');
        $locale = in_array($locale, ['ar', 'en'], true) ? $locale : 'en';
        $export = $exports->build($report, $format, $locale);

        app(AuditLogger::class)->record('b2b.finance.statement_exported', $request->user(), $customer, null, [
            'format' => $format,
            'from' => $filters['from'] ?? null,
            'to' => $filters['to'] ?? null,
        ], $request);

        return response($export['content'], 200, [
            'Content-Type' => $export['mime'],
            'Content-Disposition' => 'attachment; filename="'.$exports->filename($report, $export['extension']).'"',
            'Cache-Control' => 'private, no-store',
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

        $payload = [
            'id' => (int) $invoice->getKey(),
            'invoice_number' => (string) $invoice->invoice_number,
            'status' => (string) $invoice->status,
            'currency' => (string) $invoice->currency,
            'total' => $amounts['invoice_total'],
            'paid_amount' => $amounts['paid_amount'],
            'debit_adjustments' => $amounts['debit_adjustments'],
            'credit_adjustments' => $amounts['credit_adjustments'],
            'outstanding_amount' => $amounts['outstanding_amount'],
            'credit_amount' => $amounts['credit_amount'],
            'issued_at' => $invoice->issued_at,
            'due_at' => $invoice->due_at,
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

    private function storeId(Request $request): ?int
    {
        $storeId = $request->integer('store_id');

        return $storeId > 0 ? $storeId : null;
    }
}
