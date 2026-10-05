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
use App\Services\InvoiceService;
use App\Services\B2bAccountLedgerService;
use App\Services\CustomerDomainResolver;
use App\Services\ReportExportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
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
        $requestedStoreId = $this->storeId($request);
        abort_unless(
            (int) $invoice->b2b_customer_id === (int) $customer->getKey()
                && ($requestedStoreId === null || (int) $invoice->store_id === $requestedStoreId),
            404,
        );

        app(AuditLogger::class)->record('b2b.finance.invoice_viewed', $request->user(), $invoice, null, null, $request);

        $invoiceStoreId = $invoice->store_id === null ? $requestedStoreId : (int) $invoice->store_id;

        return response()->json([
            'data' => $this->invoicePayload($invoice, true),
            'account' => $this->ledger->summary($customer, $invoiceStoreId),
            'generated_at' => now()->toAtomString(),
        ]);
    }

    public function invoiceDownload(
        Request $request,
        Invoice $invoice,
        InvoiceService $invoices,
    ): Response {
        $customer = $this->approvedCustomer($request);
        $requestedStoreId = $this->storeId($request);
        abort_unless(
            (int) $invoice->b2b_customer_id === (int) $customer->getKey()
                && ($requestedStoreId === null || (int) $invoice->store_id === $requestedStoreId),
            404,
        );

        $validated = $request->validate([
            'locale' => ['nullable', 'in:ar,en'],
        ]);
        $locale = (string) ($validated['locale'] ?? $request->user()->locale ?? 'en');
        $locale = in_array($locale, ['ar', 'en'], true) ? $locale : 'en';

        app(AuditLogger::class)->record(
            'b2b.finance.invoice_downloaded',
            $request->user(),
            $invoice,
            null,
            null,
            $request,
        );

        try {
            $content = $invoices->renderPdf($invoice, $locale);
        } catch (\RuntimeException) {
            abort(503, 'PDF generation is temporarily unavailable.');
        }

        return response($content, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$invoice->invoice_number.'.pdf"',
            'Cache-Control' => 'private, no-store',
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
        try {
            $export = $exports->build($report, $format, $locale);
        } catch (\RuntimeException) {
            abort(503, 'Document export is temporarily unavailable.');
        }

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

        $displayStatus = $this->invoiceDisplayStatus($invoice, $amounts);

        $payload = [
            'id' => (int) $invoice->getKey(),
            'invoice_number' => (string) $invoice->invoice_number,
            'status' => (string) $invoice->status,
            'display_status' => $displayStatus,
            'currency' => (string) $invoice->currency,
            'subtotal' => (float) ($invoice->subtotal ?? $invoice->total),
            'discount_total' => (float) ($invoice->discount_total ?? 0),
            'delivery_total' => (float) ($invoice->delivery_total ?? 0),
            'tax_total' => (float) ($invoice->tax_total ?? 0),
            'total' => $amounts['invoice_total'],
            'paid_amount' => $amounts['paid_amount'],
            'debit_adjustments' => $amounts['debit_adjustments'],
            'credit_adjustments' => $amounts['credit_adjustments'],
            'outstanding_amount' => $amounts['outstanding_amount'],
            'credit_amount' => $amounts['credit_amount'],
            'store_id' => $invoice->store_id === null ? null : (int) $invoice->store_id,
            'order_id' => $invoice->order_id === null ? null : (int) $invoice->order_id,
            'order_number' => $invoice->order_number_snapshot,
            'issued_at' => $invoice->issued_at,
            'due_at' => $invoice->due_at,
            'payment_method' => $invoice->payment_method_snapshot,
            'payment_status' => $invoice->payment_status_snapshot,
            'seller' => [
                'store_id' => $invoice->store_id === null ? null : (int) $invoice->store_id,
                'name' => $invoice->store_name_snapshot,
            ],
            'customer' => [
                'name' => $invoice->customer_name_snapshot,
                'email' => $invoice->customer_email_snapshot,
                'phone' => $invoice->customer_phone_snapshot,
            ],
            'pdf_path' => '/api/v1/b2b/invoices/'.(int) $invoice->getKey().'/download'
                .($invoice->store_id === null ? '' : '?store_id='.(int) $invoice->store_id),
        ];

        if ($withItems) {
            $payload['items'] = InvoiceItem::query()
                ->where('invoice_id', $invoice->getKey())
                ->orderBy('id')
                ->get()
                ->map(function (InvoiceItem $item) use ($invoice): array {
                    $snapshot = $item->getAttribute('line_snapshot');
                    $snapshotSku = is_array($snapshot) && isset($snapshot['sku'])
                        ? (string) $snapshot['sku']
                        : null;

                    return [
                        'id' => (int) $item->getKey(),
                        'product_id' => $item->product_id === null ? null : (int) $item->product_id,
                        'sku' => $item->sku_snapshot ?? $snapshotSku,
                        'description' => (string) $item->description,
                        'quantity' => (float) $item->quantity,
                        'unit_price' => (float) $item->unit_price,
                        'discount_total' => (float) ($item->line_discount_total ?? 0),
                        'tax_total' => (float) ($item->line_tax_total ?? 0),
                        'line_total' => (float) $item->line_total,
                        'currency' => (string) ($item->currency ?: $invoice->currency),
                    ];
                })
                ->values();
            $payload['payments'] = Payment::query()
                ->where('invoice_id', $invoice->getKey())
                ->where('status', 'paid')
                ->orderBy('created_at')
                ->orderBy('id')
                ->get(['id', 'provider', 'provider_reference', 'amount', 'currency', 'created_at'])
                ->map(fn (Payment $payment): array => [
                    'id' => (int) $payment->getKey(),
                    'method' => (string) $payment->provider,
                    'reference' => $payment->provider_reference,
                    'amount' => (float) $payment->amount,
                    'currency' => (string) ($payment->currency ?: $invoice->currency),
                    'paid_at' => $payment->created_at,
                ])
                ->values();
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
