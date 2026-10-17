<?php

namespace App\Http\Controllers\Admin;

use App\Enterprise\Modules\ModuleRegistry;
use App\Enterprise\Procurement\GoodsReceiptManager;
use App\Enterprise\Procurement\ProcurementCompletionManager;
use App\Enterprise\Procurement\PurchaseInvoiceHandoffManager;
use App\Enterprise\Procurement\PurchaseOrderManager;
use App\Enterprise\Procurement\PurchaseRequestManager;
use App\Enterprise\Procurement\PurchaseReturnManager;
use App\Enterprise\Procurement\SupplierDirectory;
use App\Enterprise\Procurement\SupplierStatementAgingManager;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Warehouse;
use App\Support\AdminNavigation;
use App\Support\ResolvedTenantContext;
use App\Support\TenantContextResolver;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

final class EnterpriseProcurementController extends Controller
{
    /** @var array<string,string> */
    private const TABS = [
        'overview' => 'procurement.suppliers.view',
        'suppliers' => 'procurement.suppliers.view',
        'requests' => 'procurement.purchase_requests.view',
        'rfqs' => 'procurement.purchase_requests.view',
        'orders' => 'procurement.purchase_orders.view',
        'contracts' => 'procurement.purchase_orders.view',
        'receipts' => 'procurement.goods_receipts.view',
        'invoices' => 'procurement.purchase_invoices.view',
        'matches' => 'procurement.purchase_invoices.view',
        'returns' => 'procurement.purchase_returns.view',
        'statements' => 'procurement.supplier_statements.view',
        'reports' => 'procurement.supplier_statements.view',
    ];

    public function __construct(
        private readonly SupplierDirectory $suppliers,
        private readonly PurchaseRequestManager $requests,
        private readonly PurchaseOrderManager $orders,
        private readonly GoodsReceiptManager $receipts,
        private readonly PurchaseInvoiceHandoffManager $invoices,
        private readonly PurchaseReturnManager $returns,
        private readonly SupplierStatementAgingManager $statements,
        private readonly ProcurementCompletionManager $completion,
        private readonly TenantContextResolver $tenants,
        private readonly AdminNavigation $navigation,
        private readonly ModuleRegistry $modules,
    ) {}

    public function index(Request $request): View
    {
        $actor = $this->actor($request);
        abort_unless($this->modules->isEnabled('procurement'), 404, 'Procurement module is disabled.');

        [$context, $storeOptions, $storeId] = $this->context($request, $actor);
        $tab = (string) $request->query('tab', $this->firstVisibleTab($actor, $storeId));
        abort_unless(array_key_exists($tab, self::TABS), 404);
        $this->authorizeTab($actor, $storeId, $tab);

        $data = [
            'user' => $actor,
            'navGroups' => $this->navigation->groupsFor($actor),
            'navContext' => 'erp_procurement',
            'activeTab' => $tab,
            'tabs' => $this->visibleTabs($actor, $storeId),
            'storeOptions' => $storeOptions,
            'selectedStoreId' => $storeId,
            'can' => $this->capabilities($actor, $storeId),
            'rows' => null,
            'detail' => null,
            'suppliersForLookup' => collect(),
            'warehousesForLookup' => collect(),
            'sourceOrder' => null,
            'sourceReceipt' => null,
            'statement' => null,
            'aging' => null,
            'dashboard' => null,
            'rfqDetail' => null,
            'contractDetail' => null,
            'rfqRequestOptions' => [],
            'activeContracts' => [],
            'reportData' => null,
            'reportName' => null,
        ];

        if ($this->allowed($actor, $storeId, 'procurement.suppliers.view')) {
            $data['suppliersForLookup'] = $this->suppliers
                ->paginate($actor, $context, ['status' => 'active'], 100)
                ->getCollection();
        }

        switch ($tab) {
            case 'overview':
                $data['dashboard'] = $this->completion->dashboard($actor, $context, [
                    'from' => $request->query('from'),
                    'to' => $request->query('to'),
                ]);
                break;

            case 'suppliers':
                $data['rows'] = $this->suppliers->paginate($actor, $context, [
                    'q' => $request->query('q'),
                    'status' => $request->query('status'),
                    'scope_type' => $request->query('scope_type'),
                ], 25);
                if ($request->integer('supplier') > 0) {
                    $data['detail'] = $this->suppliers->supplier360($actor, $context, $request->integer('supplier'));
                }
                break;

            case 'requests':
                $data['rows'] = $this->requests->paginate($actor, $context, [
                    'q' => $request->query('q'),
                    'status' => $request->query('status'),
                ], 25);
                if ($request->integer('request') > 0) {
                    $data['detail'] = $this->requests->detail($actor, $context, $request->integer('request'));
                }
                break;

            case 'rfqs':
                $data['rows'] = $this->completion->paginateRfqs($actor, $context, [
                    'q' => $request->query('q'),
                    'status' => $request->query('status'),
                ], 25);
                $data['rfqRequestOptions'] = $this->completion->rfqRequestOptions($actor, $context);
                if ($request->integer('rfq') > 0) {
                    $data['rfqDetail'] = $this->completion->rfqDetail($actor, $context, $request->integer('rfq'));
                }
                break;

            case 'orders':
                $data['rows'] = $this->orders->paginate($actor, $context, [
                    'q' => $request->query('q'),
                    'status' => $request->query('status'),
                ], 25);
                if ($request->integer('order') > 0) {
                    $data['detail'] = $this->orders->detail($actor, $context, $request->integer('order'));
                    $data['activeContracts'] = $this->completion->activeContractOptions(
                        $actor,
                        $context,
                        (int) $data['detail']->supplier_id,
                    );
                }
                break;

            case 'contracts':
                $data['rows'] = $this->completion->paginateContracts($actor, $context, [
                    'q' => $request->query('q'),
                    'status' => $request->query('status'),
                ], 25);
                if ($request->integer('contract') > 0) {
                    $data['contractDetail'] = $this->completion->contractDetail(
                        $actor,
                        $context,
                        $request->integer('contract'),
                    );
                }
                break;

            case 'receipts':
                $data['rows'] = $this->receipts->paginate($actor, $context, [
                    'q' => $request->query('q'),
                    'status' => $request->query('status'),
                    'purchase_order_id' => $request->integer('purchase_order_id') ?: null,
                ], 25);
                if ($request->integer('receipt') > 0) {
                    $data['detail'] = $this->receipts
                        ->detail($actor, $context, $request->integer('receipt'))
                        ->load('lines.warehouse');
                }
                if (
                    $request->integer('purchase_order_id') > 0
                    && $this->allowed($actor, $storeId, 'procurement.purchase_orders.view')
                ) {
                    $data['sourceOrder'] = $this->orders->detail($actor, $context, $request->integer('purchase_order_id'));
                    $data['warehousesForLookup'] = Warehouse::query()
                        ->where('store_id', $storeId)
                        ->where('is_active', true)
                        ->orderBy('name')
                        ->orderBy('id')
                        ->get(['id', 'code', 'name']);
                }
                break;

            case 'invoices':
                $data['rows'] = $this->invoices->paginate($actor, $context, [
                    'q' => $request->query('q'),
                    'status' => $request->query('status'),
                    'supplier_id' => $request->integer('supplier_id') ?: null,
                    'goods_receipt_id' => $request->integer('goods_receipt_id') ?: null,
                ], 25);
                if ($request->integer('invoice') > 0) {
                    $data['detail'] = $this->invoices->detail($actor, $context, $request->integer('invoice'));
                }
                if (
                    $request->integer('goods_receipt_id') > 0
                    && $this->allowed($actor, $storeId, 'procurement.goods_receipts.view')
                ) {
                    $data['sourceReceipt'] = $this->receipts->detail($actor, $context, $request->integer('goods_receipt_id'));
                }
                break;

            case 'matches':
                $data['rows'] = $this->completion->paginateMatches($actor, $context, [
                    'q' => $request->query('q'),
                    'status' => $request->query('status'),
                ], 25);
                foreach ($data['rows']->getCollection() as $matchRow) {
                    if ($matchRow->match_review_id === null) {
                        $this->completion->syncMatchForInvoice((int) $matchRow->purchase_invoice_id);
                    }
                }
                if ($data['rows']->getCollection()->contains(fn ($matchRow): bool => $matchRow->match_review_id === null)) {
                    $data['rows'] = $this->completion->paginateMatches($actor, $context, [
                        'q' => $request->query('q'),
                        'status' => $request->query('status'),
                    ], 25);
                }
                break;

            case 'returns':
                $data['rows'] = $this->returns->paginate($actor, $context, [
                    'q' => $request->query('q'),
                    'status' => $request->query('status'),
                    'goods_receipt_id' => $request->integer('goods_receipt_id') ?: null,
                ], 25);
                if ($request->integer('return') > 0) {
                    $data['detail'] = $this->returns->detail($actor, $context, $request->integer('return'));
                }
                if (
                    $request->integer('goods_receipt_id') > 0
                    && $this->allowed($actor, $storeId, 'procurement.goods_receipts.view')
                ) {
                    $data['sourceReceipt'] = $this->receipts->detail($actor, $context, $request->integer('goods_receipt_id'));
                }
                break;

            case 'statements':
                $supplierId = $request->integer('supplier_id');
                if ($supplierId > 0) {
                    $data['statement'] = $this->statements->statement($actor, $context, $supplierId, [
                        'from' => $request->query('from'),
                        'to' => $request->query('to'),
                        'currency_code' => $request->query('currency_code'),
                    ], 25);
                    $data['aging'] = $this->statements->aging($actor, $context, $supplierId, [
                        'as_of' => $request->query('as_of', now()->toDateString()),
                        'currency_code' => $request->query('currency_code', 'KWD'),
                    ]);
                }
                break;

            case 'reports':
                $report = trim((string) $request->query('report', ''));
                if ($report !== '') {
                    $data['reportName'] = $report;
                    $data['reportData'] = $this->completion->report($actor, $context, $report, [
                        'from' => $request->query('from'),
                        'to' => $request->query('to'),
                    ]);
                }
                break;
        }

        return view('admin.enterprise-procurement', $data);
    }

    public function updateSupplierGovernance(Request $request, int $supplier): RedirectResponse
    {
        [$actor, $context, $storeId] = $this->mutationContext($request);
        $this->completion->updateSupplierGovernance($actor, $context, $supplier, $request->all());

        return $this->backTo('suppliers', $storeId, ['supplier' => $supplier])
            ->with('status', __('enterprise_procurement.flash.supplier_governance_updated'));
    }

    public function storeRfq(Request $request): RedirectResponse
    {
        [$actor, $context, $storeId] = $this->mutationContext($request);
        $validated = $request->validate([
            'purchase_request_id' => ['required', 'integer', 'min:1'],
            'title' => ['required', 'string', 'max:190'],
            'response_due_at' => ['nullable', 'date', 'after:now'],
            'contract_id' => ['nullable', 'integer', 'min:1'],
        ]);
        $rfq = $this->completion->createRfq(
            $actor,
            $context,
            (int) $validated['purchase_request_id'],
            $validated,
        );

        return $this->backTo('rfqs', $storeId, ['rfq' => $rfq->id])
            ->with('status', __('enterprise_procurement.flash.rfq_created'));
    }

    public function publishRfq(Request $request, int $rfq): RedirectResponse
    {
        [$actor, $context, $storeId] = $this->mutationContext($request);
        $validated = $request->validate([
            'supplier_ids' => ['required', 'array', 'min:1', 'max:100'],
            'supplier_ids.*' => ['integer', 'min:1', 'distinct'],
        ]);
        $tokens = $this->completion->publishRfq($actor, $context, $rfq, $validated['supplier_ids']);
        $supplierMap = DB::table('enterprise_suppliers')
            ->whereIn('id', collect($tokens)->pluck('supplier_id')->all())
            ->get(['id', 'code', 'name_en', 'name_ar'])
            ->keyBy('id');
        $links = collect($tokens)->map(function (array $entry) use ($supplierMap): array {
            $supplier = $supplierMap->get($entry['supplier_id']);

            return [
                'supplier_code' => $supplier->code ?? '—',
                'supplier_name_en' => $supplier->name_en ?? '—',
                'supplier_name_ar' => $supplier?->name_ar,
                'url' => route('supplier.rfq.show', ['token' => $entry['token']]),
            ];
        })->all();

        return $this->backTo('rfqs', $storeId, ['rfq' => $rfq])
            ->with('status', __('enterprise_procurement.flash.rfq_published'))
            ->with('procurement_portal_links', $links);
    }

    public function cancelRfq(Request $request, int $rfq): RedirectResponse
    {
        [$actor, $context, $storeId] = $this->mutationContext($request);
        $this->completion->cancelRfq($actor, $context, $rfq);

        return $this->backTo('rfqs', $storeId)->with('status', __('enterprise_procurement.flash.rfq_cancelled'));
    }

    public function downloadQuoteDocument(Request $request, int $rfq, int $document)
    {
        [$actor, $context] = $this->mutationContext($request);
        $quoteDocument = $this->completion->quoteDocument($actor, $context, $rfq, $document);
        $disk = Storage::disk('local');
        abort_unless($disk->exists($quoteDocument->storage_path), 404);

        return $disk->download($quoteDocument->storage_path, $quoteDocument->original_name);
    }

    public function awardRfq(Request $request, int $rfq, int $quote): RedirectResponse
    {
        [$actor, $context, $storeId] = $this->mutationContext($request);
        $order = $this->completion->awardRfq($actor, $context, $rfq, $quote);

        return $this->backTo('orders', $storeId, ['order' => $order->id])
            ->with('status', __('enterprise_procurement.flash.rfq_awarded'));
    }

    public function createContract(Request $request): RedirectResponse
    {
        [$actor, $context, $storeId] = $this->mutationContext($request);
        $contract = $this->completion->createContract($actor, $context, $request->all());

        return $this->backTo('contracts', $storeId, ['contract' => $contract->id])
            ->with('status', __('enterprise_procurement.flash.contract_created'));
    }

    public function activateContract(Request $request, int $contract): RedirectResponse
    {
        [$actor, $context, $storeId] = $this->mutationContext($request);
        $this->completion->activateContract($actor, $context, $contract);

        return $this->backTo('contracts', $storeId, ['contract' => $contract])
            ->with('status', __('enterprise_procurement.flash.contract_activated'));
    }

    public function terminateContract(Request $request, int $contract): RedirectResponse
    {
        [$actor, $context, $storeId] = $this->mutationContext($request);
        $this->completion->terminateContract($actor, $context, $contract);

        return $this->backTo('contracts', $storeId)->with('status', __('enterprise_procurement.flash.contract_terminated'));
    }

    public function registerContractDocument(Request $request, int $contract): RedirectResponse
    {
        [$actor, $context, $storeId] = $this->mutationContext($request);
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:190'],
            'reference' => ['nullable', 'string', 'max:190'],
            'attachment' => ['nullable', 'file', 'mimes:pdf,png,jpg,jpeg,xlsx,xls,doc,docx', 'max:10240'],
        ]);
        $this->completion->addContractDocument(
            $actor,
            $context,
            $contract,
            $validated,
            $request->file('attachment'),
        );

        return $this->backTo('contracts', $storeId, ['contract' => $contract])
            ->with('status', __('enterprise_procurement.flash.contract_document_registered'));
    }

    public function downloadContractDocument(Request $request, int $contract, int $document)
    {
        [$actor, $context] = $this->mutationContext($request);
        $contractDocument = $this->completion->contractDocument($actor, $context, $contract, $document);
        abort_if($contractDocument->storage_path === null, 404);

        $disk = Storage::disk('local');
        abort_unless($disk->exists($contractDocument->storage_path), 404);

        return $disk->download(
            $contractDocument->storage_path,
            $contractDocument->original_name ?: ('contract-document-'.$contractDocument->id),
        );
    }

    public function assignContract(Request $request, int $purchaseOrder): RedirectResponse
    {
        [$actor, $context, $storeId] = $this->mutationContext($request);
        $validated = $request->validate(['contract_id' => ['required', 'integer', 'min:1']]);
        $this->completion->assignContractToOrder(
            $actor,
            $context,
            $purchaseOrder,
            (int) $validated['contract_id'],
        );

        return $this->backTo('orders', $storeId, ['order' => $purchaseOrder])
            ->with('status', __('enterprise_procurement.flash.contract_assigned'));
    }

    public function reviewMatch(Request $request, int $purchaseInvoice): RedirectResponse
    {
        [$actor, $context, $storeId] = $this->mutationContext($request);
        $this->completion->reviewMatch($actor, $context, $purchaseInvoice, $request->all());

        return $this->backTo('matches', $storeId)
            ->with('status', __('enterprise_procurement.flash.match_reviewed'));
    }

    public function exportReport(Request $request)
    {
        [$actor, $context, $storeId] = $this->mutationContext($request);
        abort_unless($this->allowed($actor, $storeId, 'reports.export'), 403);
        $validated = $request->validate([
            'report' => ['required', 'string', 'max:64'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);
        $report = $this->completion->report($actor, $context, $validated['report'], [
            'from' => $validated['from'] ?? null,
            'to' => $validated['to'] ?? null,
        ], 5000);

        return response()->streamDownload(function () use ($report): void {
            $handle = fopen('php://output', 'wb');
            if ($handle === false) {
                return;
            }
            fputcsv($handle, $report['columns']);
            foreach ($report['rows'] as $row) {
                fputcsv($handle, collect($report['columns'])->map(
                    fn (string $column) => $row[$column] ?? null,
                )->all());
            }
            fclose($handle);
        }, 'procurement-'.$validated['report'].'-'.now()->format('Ymd-His').'.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    public function storeSupplier(Request $request): RedirectResponse
    {
        [$actor, $context, $storeId] = $this->mutationContext($request);
        $supplier = $this->suppliers->create($actor, $context, $request->only([
            'scope_type', 'code', 'name_en', 'name_ar', 'contact_name', 'email', 'phone',
            'tax_registration_number', 'commercial_registration_number', 'business_type',
            'payment_terms_days', 'notes',
        ]));

        return $this->backTo('suppliers', $storeId, ['supplier' => $supplier->id])
            ->with('status', __('enterprise_procurement.flash.supplier_created'));
    }

    public function archiveSupplier(Request $request, int $supplier): RedirectResponse
    {
        [$actor, $context, $storeId] = $this->mutationContext($request);
        $this->suppliers->archive($actor, $context, $supplier);

        return $this->backTo('suppliers', $storeId)->with('status', __('enterprise_procurement.flash.supplier_archived'));
    }

    public function storeRequest(Request $request): RedirectResponse
    {
        [$actor, $context, $storeId] = $this->mutationContext($request);
        $payload = $request->validate([
            'supplier_id' => ['nullable', 'integer', 'min:1'],
            'currency_code' => ['required', 'string', 'size:3'],
            'needed_by' => ['nullable', 'date'],
            'reason' => ['nullable', 'string', 'max:2000'],
            'notes' => ['nullable', 'string', 'max:4000'],
            'item_code' => ['nullable', 'string', 'max:96'],
            'description' => ['required', 'string', 'max:255'],
            'quantity' => ['required', 'numeric', 'gt:0'],
            'unit' => ['required', 'string', 'max:32'],
            'estimated_unit_cost' => ['nullable', 'numeric', 'min:0'],
        ]);
        $purchaseRequest = $this->requests->create($actor, $context, [
            'supplier_id' => $payload['supplier_id'] ?? null,
            'currency_code' => $payload['currency_code'],
            'needed_by' => $payload['needed_by'] ?? null,
            'reason' => $payload['reason'] ?? null,
            'notes' => $payload['notes'] ?? null,
            'lines' => [[
                'item_code' => $payload['item_code'] ?? null,
                'description' => $payload['description'],
                'quantity' => $payload['quantity'],
                'unit' => $payload['unit'],
                'estimated_unit_cost' => $payload['estimated_unit_cost'] ?? null,
            ]],
        ]);

        return $this->backTo('requests', $storeId, ['request' => $purchaseRequest->id])
            ->with('status', __('enterprise_procurement.flash.request_created'));
    }

    public function submitRequest(Request $request, int $purchaseRequest): RedirectResponse
    {
        [$actor, $context, $storeId] = $this->mutationContext($request);
        $this->requests->submit($actor, $context, $purchaseRequest);

        return $this->backTo('requests', $storeId, ['request' => $purchaseRequest])
            ->with('status', __('enterprise_procurement.flash.request_submitted'));
    }

    public function decideRequest(Request $request, int $purchaseRequest): RedirectResponse
    {
        [$actor, $context, $storeId] = $this->mutationContext($request);
        $data = $request->validate([
            'decision' => ['required', Rule::in(['approve', 'reject'])],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);
        if ($data['decision'] === 'approve') {
            $this->requests->approve($actor, $context, $purchaseRequest, $data['note'] ?? null);
        } else {
            $this->requests->reject($actor, $context, $purchaseRequest, $data['note'] ?? null);
        }

        return $this->backTo('requests', $storeId, ['request' => $purchaseRequest])
            ->with('status', __('enterprise_procurement.flash.request_decided'));
    }

    public function cancelRequest(Request $request, int $purchaseRequest): RedirectResponse
    {
        [$actor, $context, $storeId] = $this->mutationContext($request);
        $this->requests->cancel($actor, $context, $purchaseRequest);

        return $this->backTo('requests', $storeId)->with('status', __('enterprise_procurement.flash.request_cancelled'));
    }

    public function createOrder(Request $request, int $purchaseRequest): RedirectResponse
    {
        [$actor, $context, $storeId] = $this->mutationContext($request);
        $order = $this->orders->createFromApprovedRequest($actor, $context, $purchaseRequest);

        return $this->backTo('orders', $storeId, ['order' => $order->id])
            ->with('status', __('enterprise_procurement.flash.order_created'));
    }

    public function issueOrder(Request $request, int $purchaseOrder): RedirectResponse
    {
        [$actor, $context, $storeId] = $this->mutationContext($request);
        $this->orders->issue($actor, $context, $purchaseOrder);

        return $this->backTo('orders', $storeId, ['order' => $purchaseOrder])
            ->with('status', __('enterprise_procurement.flash.order_issued'));
    }

    public function cancelOrder(Request $request, int $purchaseOrder): RedirectResponse
    {
        [$actor, $context, $storeId] = $this->mutationContext($request);
        $this->orders->cancel($actor, $context, $purchaseOrder);

        return $this->backTo('orders', $storeId)->with('status', __('enterprise_procurement.flash.order_cancelled'));
    }

    public function storeReceipt(Request $request, int $purchaseOrder): RedirectResponse
    {
        [$actor, $context, $storeId] = $this->mutationContext($request);
        $payload = $request->validate([
            'warehouse_id' => ['required', 'integer', 'min:1'],
            'lines' => ['required', 'array', 'min:1', 'max:100'],
            'lines.*.purchase_order_line_id' => ['required', 'integer', 'min:1', 'distinct'],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0'],
        ]);
        $lines = collect($payload['lines'])->map(fn (array $line): array => [
            'purchase_order_line_id' => $line['purchase_order_line_id'],
            'warehouse_id' => $payload['warehouse_id'],
            'quantity' => $line['quantity'],
        ])->all();

        $receipt = $this->receipts->createFromIssuedOrder($actor, $context, $purchaseOrder, ['lines' => $lines]);

        return $this->backTo('receipts', $storeId, ['receipt' => $receipt->id])
            ->with('status', __('enterprise_procurement.flash.receipt_created'));
    }

    public function postReceipt(Request $request, int $goodsReceipt): RedirectResponse
    {
        [$actor, $context, $storeId] = $this->mutationContext($request);
        $this->receipts->post($actor, $context, $goodsReceipt);

        return $this->backTo('receipts', $storeId, ['receipt' => $goodsReceipt])
            ->with('status', __('enterprise_procurement.flash.receipt_posted'));
    }

    public function cancelReceipt(Request $request, int $goodsReceipt): RedirectResponse
    {
        [$actor, $context, $storeId] = $this->mutationContext($request);
        $this->receipts->cancel($actor, $context, $goodsReceipt);

        return $this->backTo('receipts', $storeId)->with('status', __('enterprise_procurement.flash.receipt_cancelled'));
    }

    public function storeInvoice(Request $request, int $goodsReceipt): RedirectResponse
    {
        [$actor, $context, $storeId] = $this->mutationContext($request);
        $payload = $request->validate([
            'supplier_invoice_number' => ['required', 'string', 'max:96'],
            'invoice_date' => ['required', 'date'],
            'due_date' => ['nullable', 'date', 'after_or_equal:invoice_date'],
            'lines' => ['required', 'array', 'min:1', 'max:100'],
            'lines.*.goods_receipt_line_id' => ['required', 'integer', 'min:1', 'distinct'],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0'],
            'lines.*.unit_price' => ['nullable', 'numeric', 'min:0', 'max:999999999'],
        ]);
        $invoice = $this->invoices->createFromPostedReceipt($actor, $context, $goodsReceipt, $payload);

        return $this->backTo('invoices', $storeId, ['invoice' => $invoice->id])
            ->with('status', __('enterprise_procurement.flash.invoice_created'));
    }

    public function handoffInvoice(Request $request, int $purchaseInvoice): RedirectResponse
    {
        [$actor, $context, $storeId] = $this->mutationContext($request);
        $this->invoices->handoff($actor, $context, $purchaseInvoice);

        return $this->backTo('invoices', $storeId, ['invoice' => $purchaseInvoice])
            ->with('status', __('enterprise_procurement.flash.invoice_handed_off'));
    }

    public function cancelInvoice(Request $request, int $purchaseInvoice): RedirectResponse
    {
        [$actor, $context, $storeId] = $this->mutationContext($request);
        $this->invoices->cancel($actor, $context, $purchaseInvoice);

        return $this->backTo('invoices', $storeId)->with('status', __('enterprise_procurement.flash.invoice_cancelled'));
    }

    public function storeReturn(Request $request, int $goodsReceipt): RedirectResponse
    {
        [$actor, $context, $storeId] = $this->mutationContext($request);
        $payload = $request->validate([
            'lines' => ['required', 'array', 'min:1', 'max:100'],
            'lines.*.goods_receipt_line_id' => ['required', 'integer', 'min:1', 'distinct'],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0'],
        ]);
        $return = $this->returns->createFromPostedReceipt($actor, $context, $goodsReceipt, $payload);

        return $this->backTo('returns', $storeId, ['return' => $return->id])
            ->with('status', __('enterprise_procurement.flash.return_created'));
    }

    public function postReturn(Request $request, int $purchaseReturn): RedirectResponse
    {
        [$actor, $context, $storeId] = $this->mutationContext($request);
        $this->returns->post($actor, $context, $purchaseReturn);

        return $this->backTo('returns', $storeId, ['return' => $purchaseReturn])
            ->with('status', __('enterprise_procurement.flash.return_posted'));
    }

    public function cancelReturn(Request $request, int $purchaseReturn): RedirectResponse
    {
        [$actor, $context, $storeId] = $this->mutationContext($request);
        $this->returns->cancel($actor, $context, $purchaseReturn);

        return $this->backTo('returns', $storeId)->with('status', __('enterprise_procurement.flash.return_cancelled'));
    }

    /** @return array{User,ResolvedTenantContext,int} */
    private function mutationContext(Request $request): array
    {
        $actor = $this->actor($request);
        abort_unless($this->modules->isEnabled('procurement'), 404, 'Procurement module is disabled.');
        [$context, , $storeId] = $this->context($request, $actor);

        return [$actor, $context, $storeId];
    }

    /** @return array{ResolvedTenantContext,list<array{id:int,name:string,code:string,channel:string}>,int} */
    private function context(Request $request, User $actor): array
    {
        $options = $this->storeOptions($actor);
        abort_if($options === [], 403);

        $requested = $request->integer('store_id');
        $selected = collect($options)->firstWhere('id', $requested > 0 ? $requested : $options[0]['id']);
        abort_unless(is_array($selected), 403);
        $storeId = (int) $selected['id'];

        $context = $selected['channel'] === 'b2c'
            ? $this->tenants->retail($actor, $storeId, $actor->hasRole('SUPER_ADMIN'), $request)
            : $this->tenants->wholesale($actor, $storeId);

        abort_if($context->enterpriseScope === null, 409, 'Enterprise data scope is required.');

        return [$context, $options, $storeId];
    }

    /** @return list<array{id:int,name:string,code:string,channel:string}> */
    private function storeOptions(User $actor): array
    {
        $retailIds = $this->tenants->retailStoreIds($actor);
        $globalWholesale = $actor->roles()
            ->where('roles.is_active', true)
            ->where('roles.scope', 'global')
            ->whereIn('roles.code', (array) config('admin.channels.b2b.global_roles', []))
            ->exists();

        if (! $actor->hasRole('SUPER_ADMIN') && $retailIds === [] && ! $globalWholesale) {
            return [];
        }

        return DB::table('stores')
            ->join('store_types', 'store_types.id', '=', 'stores.store_type_id')
            ->where('stores.is_active', true)
            ->where(function ($query) use ($actor, $retailIds, $globalWholesale): void {
                if ($actor->hasRole('SUPER_ADMIN')) {
                    $query->whereIn('store_types.code', ['B2B', 'B2C']);

                    return;
                }

                $query->where(function ($scoped) use ($retailIds, $globalWholesale): void {
                    if ($retailIds !== []) {
                        $scoped->where(function ($retail) use ($retailIds): void {
                            $retail->where('store_types.code', 'B2C')->whereIn('stores.id', $retailIds);
                        });
                    }
                    if ($globalWholesale) {
                        $method = $retailIds === [] ? 'where' : 'orWhere';
                        $scoped->{$method}('store_types.code', 'B2B');
                    }
                });
            })
            ->orderBy('store_types.code')
            ->orderBy('stores.name')
            ->get(['stores.id', 'stores.name', 'stores.code', 'store_types.code as channel'])
            ->map(static fn ($row): array => [
                'id' => (int) $row->id,
                'name' => (string) $row->name,
                'code' => (string) $row->code,
                'channel' => strtolower((string) $row->channel),
            ])
            ->values()
            ->all();
    }

    /** @return list<array{key:string,label:string}> */
    private function visibleTabs(User $actor, int $storeId): array
    {
        return collect(self::TABS)
            ->filter(fn (string $permission): bool => $this->allowed($actor, $storeId, $permission))
            ->map(fn (string $permission, string $key): array => [
                'key' => $key,
                'label' => __('enterprise_procurement.tabs.'.$key),
            ])
            ->values()
            ->all();
    }

    private function firstVisibleTab(User $actor, int $storeId): string
    {
        foreach (self::TABS as $tab => $permission) {
            if ($this->allowed($actor, $storeId, $permission)) {
                return $tab;
            }
        }

        abort(403);
    }

    private function authorizeTab(User $actor, int $storeId, string $tab): void
    {
        abort_unless($this->allowed($actor, $storeId, self::TABS[$tab]), 403);
    }

    private function allowed(User $actor, int $storeId, string $permission): bool
    {
        return $actor->hasRole('SUPER_ADMIN') || $actor->hasPermission($permission, $storeId);
    }

    /** @return array<string,bool> */
    private function capabilities(User $actor, int $storeId): array
    {
        $permissions = [
            'supplier_manage' => 'procurement.suppliers.manage',
            'request_manage' => 'procurement.purchase_requests.manage',
            'request_approve' => 'procurement.purchase_requests.approve',
            'order_manage' => 'procurement.purchase_orders.manage',
            'order_issue' => 'procurement.purchase_orders.issue',
            'receipt_manage' => 'procurement.goods_receipts.manage',
            'receipt_post' => 'procurement.goods_receipts.post',
            'invoice_manage' => 'procurement.purchase_invoices.manage',
            'invoice_handoff' => 'procurement.purchase_invoices.handoff',
            'return_manage' => 'procurement.purchase_returns.manage',
            'return_post' => 'procurement.purchase_returns.post',
            'report_export' => 'reports.export',
        ];

        return collect($permissions)
            ->mapWithKeys(fn (string $permission, string $key): array => [
                $key => $this->allowed($actor, $storeId, $permission),
            ])
            ->all();
    }

    private function actor(Request $request): User
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);

        return $actor;
    }

    /** @param array<string,int|string> $extra */
    private function backTo(string $tab, int $storeId, array $extra = []): RedirectResponse
    {
        return redirect()->route('admin.erp.procurement.index', [
            'tab' => $tab,
            'store_id' => $storeId,
            ...$extra,
        ]);
    }
}
