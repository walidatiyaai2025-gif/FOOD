<?php

namespace App\Enterprise\Procurement;

use App\Enterprise\Modules\ModuleRegistry;
use App\Models\EnterpriseSupplier;
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\EnterpriseDataScope;
use App\Support\EnterpriseScopeAuthorizer;
use App\Support\ResolvedTenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class ProcurementCompletionManager
{
    private const RFQ_STATUSES = ['draft', 'published', 'awarded', 'cancelled'];

    private const CONTRACT_STATUSES = ['draft', 'active', 'terminated', 'expired'];

    private const QUALIFICATION_STATUSES = ['pending', 'qualified', 'conditional', 'unqualified'];

    private const MATCH_STATUSES = ['auto_approved', 'pending', 'approved', 'rejected'];

    public function __construct(
        private readonly EnterpriseScopeAuthorizer $authorizer,
        private readonly AuditLogger $audit,
        private readonly ModuleRegistry $modules,
        private readonly PurchaseOrderManager $orders,
    ) {}

    /**
     * @param  array{from?:string|null,to?:string|null}  $filters
     * @return array<string,mixed>
     */
    public function dashboard(User $actor, ResolvedTenantContext $context, array $filters = []): array
    {
        $scope = $this->baseScope($actor, $context);
        [$from, $to] = $this->dateWindow($filters['from'] ?? null, $filters['to'] ?? null);
        $storeId = $scope->storeId;
        $lastRefresh = now()->format('Y-m-d H:i');
        $contractWarningDays = max(1, (int) config('procurement.contract_expiry_warning_days', 30));

        $canRequests = $this->can($actor, $context, 'procurement.purchase_requests.view', $scope);
        $canOrders = $this->can($actor, $context, 'procurement.purchase_orders.view', $scope);
        $canInvoices = $this->can($actor, $context, 'procurement.purchase_invoices.view', $scope);
        $canSuppliers = $this->can($actor, $context, 'procurement.suppliers.view', $scope);
        $canStatements = $this->can($actor, $context, 'procurement.supplier_statements.view', $scope);

        $openRequests = $canRequests
            ? DB::table('enterprise_purchase_requests')
                ->where('store_id', $storeId)
                ->whereIn('status', ['draft', 'submitted', 'approved'])
                ->count()
            : null;
        $pendingApprovals = $canRequests
            ? DB::table('enterprise_purchase_requests')
                ->where('store_id', $storeId)
                ->where('status', 'submitted')
                ->count()
            : null;
        $openOrders = $canOrders
            ? DB::table('enterprise_purchase_orders')
                ->where('store_id', $storeId)
                ->whereIn('status', ['draft', 'issued'])
                ->count()
            : null;
        $overdueOrders = $canOrders
            ? DB::table('enterprise_purchase_orders')
                ->where('store_id', $storeId)
                ->where('status', 'issued')
                ->whereNotNull('needed_by')
                ->whereDate('needed_by', '<', now()->toDateString())
                ->count()
            : null;
        $orderedNotReceived = $canOrders ? $this->orderedNotReceivedCount($storeId) : null;
        $unmatchedInvoices = $canInvoices
            ? DB::table('enterprise_purchase_invoices as invoices')
                ->leftJoin(
                    'enterprise_procurement_match_reviews as match_reviews',
                    'match_reviews.purchase_invoice_id',
                    '=',
                    'invoices.id',
                )
                ->where('invoices.store_id', $storeId)
                ->whereNotIn('invoices.status', ['cancelled'])
                ->where(function ($query): void {
                    $query->whereNull('match_reviews.id')
                        ->orWhereIn('match_reviews.status', ['pending', 'rejected']);
                })
                ->count()
            : null;
        $supplierAging = $canStatements
            ? (float) DB::table('enterprise_purchase_invoices')
                ->where('store_id', $storeId)
                ->where('status', 'handed_off')
                ->sum('total_amount')
            : null;
        $activeContracts = $canOrders
            ? DB::table('enterprise_procurement_contracts')
                ->where('store_id', $storeId)
                ->where('status', 'active')
                ->whereDate('starts_on', '<=', now()->toDateString())
                ->whereDate('ends_on', '>=', now()->toDateString())
                ->count()
            : null;
        $expiringContracts = $canOrders
            ? DB::table('enterprise_procurement_contracts')
                ->where('store_id', $storeId)
                ->where('status', 'active')
                ->whereBetween('ends_on', [now()->toDateString(), now()->addDays($contractWarningDays)->toDateString()])
                ->count()
            : null;
        $activeRfqs = $canRequests
            ? DB::table('enterprise_procurement_rfqs')
                ->where('store_id', $storeId)
                ->whereIn('status', ['draft', 'published'])
                ->count()
            : null;

        $kpis = [
            $this->metric('open_requests', $openRequests, 'requests', ['status' => 'submitted,approved,draft'], $lastRefresh),
            $this->metric('pending_approvals', $pendingApprovals, 'requests', ['status' => 'submitted'], $lastRefresh),
            $this->metric('open_orders', $openOrders, 'orders', ['status' => 'draft,issued'], $lastRefresh),
            $this->metric('overdue_orders', $overdueOrders, 'orders', ['overdue' => 1], $lastRefresh),
            $this->metric('ordered_not_received', $orderedNotReceived, 'orders', ['receipt_state' => 'open'], $lastRefresh),
            $this->metric('unmatched_invoices', $unmatchedInvoices, 'matches', ['status' => 'pending,rejected'], $lastRefresh),
            $this->metric('supplier_aging', $supplierAging === null ? null : number_format($supplierAging, 3, '.', ''), 'statements', [], $lastRefresh),
            $this->metric('contracts', $activeContracts, 'contracts', ['status' => 'active'], $lastRefresh, [
                'secondary' => $expiringContracts,
            ]),
            $this->metric('active_rfqs', $activeRfqs, 'rfqs', ['status' => 'draft,published'], $lastRefresh),
        ];

        $spendTrend = $canInvoices ? $this->spendTrend($storeId, $from, $to) : [];
        $spendBySupplier = $canInvoices ? $this->spendBySupplier($storeId, $from, $to) : [];
        $spendByCategory = $canInvoices ? $this->spendByCategory($storeId, $from, $to) : [];
        $poStatuses = $canOrders ? $this->purchaseOrderStatusChart($storeId) : [];
        $poAging = $canOrders ? $this->purchaseOrderAgingChart($storeId) : [];
        $supplierPerformance = $canOrders ? $this->supplierPerformance($storeId, $from, $to) : [];
        $savings = $canRequests ? $this->savingsChart($storeId, $from, $to) : [];
        $contractHorizon = $canOrders ? $this->contractExpiryHorizon($storeId) : [];

        return [
            'selected_from' => $from,
            'selected_to' => $to,
            'last_refresh' => $lastRefresh,
            'kpis' => $kpis,
            'charts' => [
                'spend_trend' => $spendTrend,
                'spend_by_supplier' => $spendBySupplier,
                'spend_by_category' => $spendByCategory,
                'po_status' => $poStatuses,
                'po_aging' => $poAging,
                'supplier_performance' => $supplierPerformance,
                'savings' => $savings,
                'contract_expiry' => $contractHorizon,
            ],
            'exceptions' => $this->exceptions(
                $storeId,
                $scope->companyId,
                $canOrders,
                $canInvoices,
                $canSuppliers,
            ),
            'state' => collect($kpis)->contains(fn (array $metric): bool => $metric['value'] !== null)
                ? 'current'
                : 'permission',
        ];
    }

    /**
     * @param  array{q?:string|null,status?:string|null}  $filters
     * @return LengthAwarePaginator<int,object>
     */
    public function paginateRfqs(
        User $actor,
        ResolvedTenantContext $context,
        array $filters = [],
        int $perPage = 25,
    ): LengthAwarePaginator {
        $scope = $this->scope($actor, $context, 'procurement.purchase_requests.view');
        $validated = Validator::make($filters, [
            'q' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', Rule::in(self::RFQ_STATUSES)],
        ])->validate();
        $term = str_replace(['%', '_'], '', trim((string) ($validated['q'] ?? '')));

        return DB::table('enterprise_procurement_rfqs as rfqs')
            ->join('enterprise_purchase_requests as requests', 'requests.id', '=', 'rfqs.purchase_request_id')
            ->where('rfqs.organization_id', $scope->organizationId)
            ->where('rfqs.company_id', $scope->companyId)
            ->where('rfqs.store_id', $scope->storeId)
            ->when(
                $validated['status'] ?? null,
                fn ($query, string $status) => $query->where('rfqs.status', $status),
            )
            ->when(
                $term !== '',
                fn ($query) => $query->where(function ($search) use ($term): void {
                    $search->where('rfqs.rfq_number', 'like', $term.'%')
                        ->orWhere('rfqs.title', 'like', '%'.$term.'%')
                        ->orWhere('requests.request_number', 'like', $term.'%');
                }),
            )
            ->select([
                'rfqs.*',
                'requests.request_number as source_request_number',
            ])
            ->orderByDesc('rfqs.id')
            ->paginate(max(10, min(100, $perPage)))
            ->withQueryString();
    }

    /** @return array<string,mixed> */
    public function rfqDetail(
        User $actor,
        ResolvedTenantContext $context,
        int $rfqId,
    ): array {
        $scope = $this->scope($actor, $context, 'procurement.purchase_requests.view');
        $rfq = $this->visibleRfq($scope, $rfqId);

        $lines = DB::table('enterprise_procurement_rfq_lines')
            ->where('rfq_id', $rfq->id)
            ->orderBy('line_no')
            ->get();
        $invitations = DB::table('enterprise_procurement_rfq_invitations as invitations')
            ->join('enterprise_suppliers as suppliers', 'suppliers.id', '=', 'invitations.supplier_id')
            ->where('invitations.rfq_id', $rfq->id)
            ->orderBy('suppliers.code')
            ->get([
                'invitations.id',
                'invitations.supplier_id',
                'invitations.status',
                'invitations.expires_at',
                'invitations.last_accessed_at',
                'invitations.submitted_at',
                'suppliers.code as supplier_code',
                'suppliers.name_en as supplier_name_en',
                'suppliers.name_ar as supplier_name_ar',
            ]);
        $quotes = DB::table('enterprise_procurement_quotes as quotes')
            ->join('enterprise_suppliers as suppliers', 'suppliers.id', '=', 'quotes.supplier_id')
            ->where('quotes.rfq_id', $rfq->id)
            ->orderBy('quotes.total_amount')
            ->orderBy('quotes.id')
            ->get([
                'quotes.*',
                'suppliers.code as supplier_code',
                'suppliers.name_en as supplier_name_en',
                'suppliers.name_ar as supplier_name_ar',
            ]);

        $quoteDocuments = $quotes->isEmpty()
            ? collect()
            : DB::table('enterprise_procurement_quote_documents')
                ->whereIn('quote_id', $quotes->pluck('id')->all())
                ->orderByDesc('id')
                ->get()
                ->groupBy('quote_id');

        return compact('rfq', 'lines', 'invitations', 'quotes', 'quoteDocuments');
    }

    public function quoteDocument(
        User $actor,
        ResolvedTenantContext $context,
        int $rfqId,
        int $documentId,
    ): object {
        $scope = $this->scope($actor, $context, 'procurement.purchase_requests.view');
        $rfq = $this->visibleRfq($scope, $rfqId);

        return DB::table('enterprise_procurement_quote_documents as documents')
            ->join('enterprise_procurement_quotes as quotes', 'quotes.id', '=', 'documents.quote_id')
            ->where('quotes.rfq_id', $rfq->id)
            ->where('documents.id', $documentId)
            ->select('documents.*')
            ->firstOrFail();
    }

    /** @param array<string,mixed> $attributes */
    public function createRfq(
        User $actor,
        ResolvedTenantContext $context,
        int $purchaseRequestId,
        array $attributes,
    ): object {
        $scope = $this->scope($actor, $context, 'procurement.purchase_requests.manage');
        $data = Validator::make($attributes, [
            'title' => ['required', 'string', 'max:190'],
            'response_due_at' => ['nullable', 'date', 'after:now'],
            'contract_id' => ['nullable', 'integer', 'min:1'],
        ])->validate();

        return DB::transaction(function () use ($actor, $scope, $purchaseRequestId, $data): object {
            $request = DB::table('enterprise_purchase_requests')
                ->where('organization_id', $scope->organizationId)
                ->where('company_id', $scope->companyId)
                ->where('store_id', $scope->storeId)
                ->where('id', $purchaseRequestId)
                ->lockForUpdate()
                ->first();

            abort_if($request === null, 404);
            if (! in_array($request->status, ['submitted', 'approved'], true)) {
                throw ValidationException::withMessages([
                    'purchase_request_id' => ['RFQ creation requires a submitted or approved purchase request.'],
                ]);
            }

            $existing = DB::table('enterprise_procurement_rfqs')
                ->where('purchase_request_id', $request->id)
                ->first();
            if ($existing !== null) {
                return $existing;
            }

            if (($data['contract_id'] ?? null) !== null) {
                $contract = $this->visibleContract($scope, (int) $data['contract_id']);
                if (
                    $contract->status !== 'active'
                    || now()->toDateString() < (string) $contract->starts_on
                    || now()->toDateString() > (string) $contract->ends_on
                ) {
                    throw ValidationException::withMessages([
                        'contract_id' => ['RFQ contract must be active and within its validity window.'],
                    ]);
                }
            }

            $requestLines = DB::table('enterprise_purchase_request_lines')
                ->where('purchase_request_id', $request->id)
                ->orderBy('line_no')
                ->get();
            abort_if($requestLines->isEmpty(), 409, 'RFQ requires at least one purchase-request line.');

            $publicId = (string) Str::uuid();
            $rfqId = DB::table('enterprise_procurement_rfqs')->insertGetId([
                'public_id' => $publicId,
                'rfq_number' => 'RFQ-'.strtoupper(substr(str_replace('-', '', $publicId), 0, 11)),
                'purchase_request_id' => $request->id,
                'organization_id' => $scope->organizationId,
                'company_id' => $scope->companyId,
                'store_id' => $scope->storeId,
                'contract_id' => $data['contract_id'] ?? null,
                'status' => 'draft',
                'title' => $data['title'],
                'currency_code' => $request->currency_code,
                'response_due_at' => $data['response_due_at'] ?? null,
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            foreach ($requestLines as $line) {
                DB::table('enterprise_procurement_rfq_lines')->insert([
                    'rfq_id' => $rfqId,
                    'purchase_request_line_id' => $line->id,
                    'line_no' => $line->line_no,
                    'item_code' => $line->item_code,
                    'description' => $line->description,
                    'quantity' => $line->quantity,
                    'unit' => $line->unit,
                    'baseline_unit_cost' => $line->estimated_unit_cost,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            $rfq = DB::table('enterprise_procurement_rfqs')->where('id', $rfqId)->firstOrFail();
            $this->audit->record('enterprise.procurement.rfq.created', $actor, null, null, [
                'store_id' => $scope->storeId,
                'rfq_id' => $rfqId,
                'rfq_number' => $rfq->rfq_number,
                'purchase_request_id' => $request->id,
                'status' => 'draft',
            ]);

            return $rfq;
        }, 3);
    }

    /**
     * @param  list<int>  $supplierIds
     * @return list<array{supplier_id:int,token:string}>
     */
    public function publishRfq(
        User $actor,
        ResolvedTenantContext $context,
        int $rfqId,
        array $supplierIds,
    ): array {
        $scope = $this->scope($actor, $context, 'procurement.purchase_requests.manage');
        $supplierIds = collect($supplierIds)->map(fn ($id): int => (int) $id)->filter()->unique()->values()->all();
        if ($supplierIds === []) {
            throw ValidationException::withMessages(['supplier_ids' => ['Select at least one supplier.']]);
        }

        return DB::transaction(function () use ($actor, $scope, $rfqId, $supplierIds): array {
            $rfq = $this->visibleRfq($scope, $rfqId, true);
            if ($rfq->status === 'published') {
                return [];
            }
            if ($rfq->status !== 'draft') {
                throw ValidationException::withMessages(['status' => ['Only draft RFQs can be published.']]);
            }

            $suppliers = EnterpriseSupplier::query()
                ->whereIn('id', $supplierIds)
                ->where('organization_id', $scope->organizationId)
                ->where('company_id', $scope->companyId)
                ->where('status', 'active')
                ->where('qualification_status', '!=', 'unqualified')
                ->where(function ($query) use ($scope): void {
                    $query->where('scope_type', 'company')
                        ->orWhere(function ($store) use ($scope): void {
                            $store->where('scope_type', 'store')->where('store_id', $scope->storeId);
                        });
                })
                ->get(['id']);

            if ($suppliers->count() !== count($supplierIds)) {
                throw ValidationException::withMessages([
                    'supplier_ids' => ['Every invited supplier must be active, visible and not unqualified.'],
                ]);
            }

            $expiresAt = $rfq->response_due_at !== null
                ? CarbonImmutable::parse((string) $rfq->response_due_at)
                : CarbonImmutable::now()->addDays(7);
            if ($expiresAt->isPast()) {
                throw ValidationException::withMessages(['response_due_at' => ['RFQ response due date must be in the future.']]);
            }

            $tokens = [];
            foreach ($supplierIds as $supplierId) {
                $token = Str::random(64);
                DB::table('enterprise_procurement_rfq_invitations')->insert([
                    'rfq_id' => $rfq->id,
                    'supplier_id' => $supplierId,
                    'token_hash' => hash('sha256', $token),
                    'status' => 'invited',
                    'expires_at' => $expiresAt,
                    'created_by' => $actor->id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $tokens[] = ['supplier_id' => $supplierId, 'token' => $token];
            }

            DB::table('enterprise_procurement_rfqs')->where('id', $rfq->id)->update([
                'status' => 'published',
                'published_by' => $actor->id,
                'published_at' => now(),
                'updated_by' => $actor->id,
                'updated_at' => now(),
            ]);

            $this->audit->record('enterprise.procurement.rfq.published', $actor, null, null, [
                'store_id' => $scope->storeId,
                'rfq_id' => $rfq->id,
                'supplier_ids' => $supplierIds,
                'invitation_count' => count($tokens),
            ]);

            return $tokens;
        }, 3);
    }

    public function cancelRfq(
        User $actor,
        ResolvedTenantContext $context,
        int $rfqId,
    ): void {
        $scope = $this->scope($actor, $context, 'procurement.purchase_requests.manage');

        DB::transaction(function () use ($actor, $scope, $rfqId): void {
            $rfq = $this->visibleRfq($scope, $rfqId, true);
            if ($rfq->status === 'cancelled') {
                return;
            }
            if (! in_array($rfq->status, ['draft', 'published'], true)) {
                throw ValidationException::withMessages(['status' => ['Awarded RFQs cannot be cancelled.']]);
            }

            DB::table('enterprise_procurement_rfqs')->where('id', $rfq->id)->update([
                'status' => 'cancelled',
                'cancelled_by' => $actor->id,
                'cancelled_at' => now(),
                'updated_by' => $actor->id,
                'updated_at' => now(),
            ]);
            DB::table('enterprise_procurement_rfq_invitations')
                ->where('rfq_id', $rfq->id)
                ->whereIn('status', ['invited', 'opened'])
                ->update(['status' => 'revoked', 'updated_at' => now()]);

            $this->audit->record('enterprise.procurement.rfq.cancelled', $actor, null, null, [
                'store_id' => $scope->storeId,
                'rfq_id' => $rfq->id,
            ]);
        }, 3);
    }

    public function awardRfq(
        User $actor,
        ResolvedTenantContext $context,
        int $rfqId,
        int $quoteId,
    ): object {
        $scope = $this->scope($actor, $context, 'procurement.purchase_requests.approve');
        $this->authorizer->authorize(
            $actor,
            $context,
            'procurement.purchase_orders.manage',
            'company',
            $scope->companyId,
        );

        return DB::transaction(function () use ($actor, $context, $scope, $rfqId, $quoteId): object {
            $rfq = $this->visibleRfq($scope, $rfqId, true);
            if ($rfq->status === 'awarded') {
                $existingOrder = DB::table('enterprise_purchase_orders')
                    ->where('procurement_rfq_id', $rfq->id)
                    ->first();

                return $existingOrder ?? (object) ['id' => 0, 'order_number' => ''];
            }
            if ($rfq->status !== 'published') {
                throw ValidationException::withMessages(['status' => ['Only published RFQs can be awarded.']]);
            }

            $quote = DB::table('enterprise_procurement_quotes')
                ->where('rfq_id', $rfq->id)
                ->where('id', $quoteId)
                ->where('status', 'submitted')
                ->lockForUpdate()
                ->first();
            abort_if($quote === null, 404);

            if ($rfq->contract_id !== null) {
                $contract = $this->visibleContract($scope, (int) $rfq->contract_id, true);
                if (
                    $contract->status !== 'active'
                    || (int) $contract->supplier_id !== (int) $quote->supplier_id
                    || $contract->currency_code !== $quote->currency_code
                    || now()->toDateString() < (string) $contract->starts_on
                    || now()->toDateString() > (string) $contract->ends_on
                ) {
                    throw ValidationException::withMessages([
                        'contract_id' => ['Awarded supplier/quote is incompatible with the selected active contract.'],
                    ]);
                }
            }

            $request = DB::table('enterprise_purchase_requests')
                ->where('id', $rfq->purchase_request_id)
                ->where('organization_id', $scope->organizationId)
                ->where('company_id', $scope->companyId)
                ->where('store_id', $scope->storeId)
                ->lockForUpdate()
                ->first();
            abort_if($request === null, 404);
            if ($request->status !== 'approved') {
                throw ValidationException::withMessages([
                    'purchase_request_id' => ['Purchase request must be approved before RFQ award creates a purchase order.'],
                ]);
            }

            DB::table('enterprise_purchase_requests')->where('id', $request->id)->update([
                'supplier_id' => $quote->supplier_id,
                'updated_by' => $actor->id,
                'updated_at' => now(),
            ]);

            $quoteLines = DB::table('enterprise_procurement_quote_lines as quote_lines')
                ->join('enterprise_procurement_rfq_lines as rfq_lines', 'rfq_lines.id', '=', 'quote_lines.rfq_line_id')
                ->where('quote_lines.quote_id', $quote->id)
                ->get([
                    'quote_lines.unit_price',
                    'rfq_lines.purchase_request_line_id',
                ]);
            foreach ($quoteLines as $line) {
                DB::table('enterprise_purchase_request_lines')
                    ->where('id', $line->purchase_request_line_id)
                    ->update([
                        'estimated_unit_cost' => $line->unit_price,
                        'updated_at' => now(),
                    ]);
            }

            $order = $this->orders->createFromApprovedRequest($actor, $context, $request->id);
            DB::table('enterprise_purchase_orders')->where('id', $order->id)->update([
                'procurement_contract_id' => $rfq->contract_id,
                'procurement_rfq_id' => $rfq->id,
                'awarded_quote_id' => $quote->id,
                'updated_by' => $actor->id,
                'updated_at' => now(),
            ]);

            $orderLines = DB::table('enterprise_purchase_order_lines')
                ->where('purchase_order_id', $order->id)
                ->get()
                ->keyBy('source_purchase_request_line_id');
            foreach ($quoteLines as $line) {
                $orderLine = $orderLines->get($line->purchase_request_line_id);
                if ($orderLine !== null) {
                    DB::table('enterprise_purchase_order_lines')
                        ->where('id', $orderLine->id)
                        ->update(['unit_price' => $line->unit_price, 'updated_at' => now()]);
                }
            }

            DB::table('enterprise_procurement_quotes')
                ->where('rfq_id', $rfq->id)
                ->where('id', '!=', $quote->id)
                ->where('status', 'submitted')
                ->update(['status' => 'rejected', 'updated_at' => now()]);
            DB::table('enterprise_procurement_quotes')->where('id', $quote->id)->update([
                'status' => 'awarded',
                'awarded_at' => now(),
                'updated_at' => now(),
            ]);
            DB::table('enterprise_procurement_rfqs')->where('id', $rfq->id)->update([
                'status' => 'awarded',
                'awarded_quote_id' => $quote->id,
                'awarded_by' => $actor->id,
                'awarded_at' => now(),
                'updated_by' => $actor->id,
                'updated_at' => now(),
            ]);

            $this->audit->record('enterprise.procurement.rfq.awarded', $actor, null, null, [
                'store_id' => $scope->storeId,
                'rfq_id' => $rfq->id,
                'quote_id' => $quote->id,
                'supplier_id' => $quote->supplier_id,
                'purchase_order_id' => $order->id,
            ]);

            return DB::table('enterprise_purchase_orders')->where('id', $order->id)->firstOrFail();
        }, 3);
    }

    /** @return array<string,mixed> */
    public function portal(string $token): array
    {
        $invitation = $this->portalInvitation($token, true);
        $rfq = DB::table('enterprise_procurement_rfqs')->where('id', $invitation->rfq_id)->firstOrFail();
        if ($rfq->status !== 'published') {
            abort(410);
        }

        $lines = DB::table('enterprise_procurement_rfq_lines')
            ->where('rfq_id', $rfq->id)
            ->orderBy('line_no')
            ->get();
        $supplier = DB::table('enterprise_suppliers')->where('id', $invitation->supplier_id)->firstOrFail();
        $existingQuote = DB::table('enterprise_procurement_quotes')
            ->where('invitation_id', $invitation->id)
            ->first();
        $existingLines = $existingQuote === null
            ? collect()
            : DB::table('enterprise_procurement_quote_lines')
                ->where('quote_id', $existingQuote->id)
                ->get()
                ->keyBy('rfq_line_id');
        $quoteDocuments = $existingQuote === null
            ? collect()
            : DB::table('enterprise_procurement_quote_documents')
                ->where('quote_id', $existingQuote->id)
                ->orderByDesc('id')
                ->limit(25)
                ->get();

        return compact(
            'invitation',
            'rfq',
            'lines',
            'supplier',
            'existingQuote',
            'existingLines',
            'quoteDocuments',
        );
    }

    /** @param array<string,mixed> $attributes */
    public function submitPortalQuote(
        string $token,
        array $attributes,
        ?UploadedFile $attachment = null,
    ): object {
        $invitation = $this->portalInvitation($token, false);
        $rfq = DB::table('enterprise_procurement_rfqs')->where('id', $invitation->rfq_id)->firstOrFail();
        if ($rfq->status !== 'published') {
            abort(410);
        }

        $data = Validator::make($attributes, [
            'valid_until' => ['nullable', 'date', 'after_or_equal:today'],
            'lead_time_days' => ['nullable', 'integer', 'min:0', 'max:3650'],
            'notes' => ['nullable', 'string', 'max:4000'],
            'lines' => ['required', 'array', 'min:1', 'max:100'],
            'lines.*.rfq_line_id' => ['required', 'integer', 'min:1', 'distinct'],
            'lines.*.unit_price' => ['required', 'numeric', 'min:0', 'max:999999999'],
            'lines.*.lead_time_days' => ['nullable', 'integer', 'min:0', 'max:3650'],
        ])->validate();

        return DB::transaction(function () use ($invitation, $rfq, $data, $attachment): object {
            $rfqLines = DB::table('enterprise_procurement_rfq_lines')
                ->where('rfq_id', $rfq->id)
                ->orderBy('line_no')
                ->get()
                ->keyBy('id');
            $submittedIds = collect($data['lines'])->pluck('rfq_line_id')->map(fn ($id): int => (int) $id);
            if ($rfqLines->count() !== $submittedIds->unique()->count() || $submittedIds->diff($rfqLines->keys())->isNotEmpty()) {
                throw ValidationException::withMessages([
                    'lines' => ['Quote must provide exactly one price for every RFQ line.'],
                ]);
            }

            $existing = DB::table('enterprise_procurement_quotes')
                ->where('invitation_id', $invitation->id)
                ->lockForUpdate()
                ->first();
            if ($existing !== null && $existing->status !== 'submitted') {
                throw ValidationException::withMessages(['status' => ['This quote can no longer be edited.']]);
            }

            $publicId = $existing !== null ? (string) $existing->public_id : (string) Str::uuid();
            $quoteId = $existing !== null ? (int) $existing->id : DB::table('enterprise_procurement_quotes')->insertGetId([
                'public_id' => $publicId,
                'quote_number' => 'QT-'.strtoupper(substr(str_replace('-', '', $publicId), 0, 12)),
                'rfq_id' => $rfq->id,
                'invitation_id' => $invitation->id,
                'supplier_id' => $invitation->supplier_id,
                'status' => 'submitted',
                'currency_code' => $rfq->currency_code,
                'submitted_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            if ($existing !== null) {
                DB::table('enterprise_procurement_quote_lines')->where('quote_id', $quoteId)->delete();
            }

            $total = 0.0;
            foreach ($data['lines'] as $line) {
                $rfqLine = $rfqLines->get((int) $line['rfq_line_id']);
                $lineTotal = round((float) $rfqLine->quantity * (float) $line['unit_price'], 3);
                $total += $lineTotal;
                DB::table('enterprise_procurement_quote_lines')->insert([
                    'quote_id' => $quoteId,
                    'rfq_line_id' => $rfqLine->id,
                    'quantity' => $rfqLine->quantity,
                    'unit_price' => $line['unit_price'],
                    'lead_time_days' => $line['lead_time_days'] ?? $data['lead_time_days'] ?? null,
                    'line_total' => $lineTotal,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            DB::table('enterprise_procurement_quotes')->where('id', $quoteId)->update([
                'valid_until' => $data['valid_until'] ?? null,
                'lead_time_days' => $data['lead_time_days'] ?? null,
                'total_amount' => round($total, 3),
                'notes' => $data['notes'] ?? null,
                'submitted_at' => now(),
                'updated_at' => now(),
            ]);
            DB::table('enterprise_procurement_rfq_invitations')->where('id', $invitation->id)->update([
                'status' => 'submitted',
                'submitted_at' => now(),
                'updated_at' => now(),
            ]);

            if ($attachment instanceof UploadedFile) {
                $extension = strtolower((string) ($attachment->guessExtension() ?: $attachment->getClientOriginalExtension()));
                $filename = Str::uuid()->toString().($extension !== '' ? '.'.$extension : '');
                $directory = 'procurement/quotes/'.$publicId;
                $stored = Storage::disk('local')->putFileAs($directory, $attachment, $filename);
                if (! is_string($stored) || $stored === '' || ! Storage::disk('local')->exists($stored)) {
                    throw ValidationException::withMessages([
                        'attachment' => ['The quote attachment could not be stored safely.'],
                    ]);
                }

                try {
                    DB::table('enterprise_procurement_quote_documents')->insert([
                        'quote_id' => $quoteId,
                        'title' => Str::limit($attachment->getClientOriginalName(), 190, ''),
                        'storage_path' => $stored,
                        'original_name' => Str::limit($attachment->getClientOriginalName(), 255, ''),
                        'mime_type' => $attachment->getMimeType() ?: null,
                        'size_bytes' => (int) $attachment->getSize(),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                } catch (\Throwable $exception) {
                    Storage::disk('local')->delete($stored);
                    throw $exception;
                }
            }

            $quote = DB::table('enterprise_procurement_quotes')->where('id', $quoteId)->firstOrFail();
            $this->audit->record('enterprise.procurement.quote.submitted', null, null, null, [
                'store_id' => $rfq->store_id,
                'rfq_id' => $rfq->id,
                'quote_id' => $quoteId,
                'supplier_id' => $invitation->supplier_id,
                'total_amount' => $quote->total_amount,
            ]);

            return $quote;
        }, 3);
    }

    /**
     * @param  array{q?:string|null,status?:string|null}  $filters
     * @return LengthAwarePaginator<int,object>
     */
    public function paginateContracts(
        User $actor,
        ResolvedTenantContext $context,
        array $filters = [],
        int $perPage = 25,
    ): LengthAwarePaginator {
        $scope = $this->scope($actor, $context, 'procurement.purchase_orders.view');
        $validated = Validator::make($filters, [
            'q' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', Rule::in(self::CONTRACT_STATUSES)],
        ])->validate();
        $term = str_replace(['%', '_'], '', trim((string) ($validated['q'] ?? '')));

        return DB::table('enterprise_procurement_contracts as contracts')
            ->join('enterprise_suppliers as suppliers', 'suppliers.id', '=', 'contracts.supplier_id')
            ->where('contracts.organization_id', $scope->organizationId)
            ->where('contracts.company_id', $scope->companyId)
            ->where('contracts.store_id', $scope->storeId)
            ->when(
                $validated['status'] ?? null,
                function ($query, string $status) {
                    if ($status === 'expired') {
                        return $query
                            ->where('contracts.status', 'active')
                            ->whereDate('contracts.ends_on', '<', now()->toDateString());
                    }

                    $query->where('contracts.status', $status);
                    if ($status === 'active') {
                        $query->whereDate('contracts.ends_on', '>=', now()->toDateString());
                    }

                    return $query;
                },
            )
            ->when(
                $term !== '',
                fn ($query) => $query->where(function ($search) use ($term): void {
                    $search->where('contracts.contract_number', 'like', $term.'%')
                        ->orWhere('contracts.title', 'like', '%'.$term.'%')
                        ->orWhere('suppliers.code', 'like', $term.'%');
                }),
            )
            ->select([
                'contracts.*',
                'suppliers.code as supplier_code',
                'suppliers.name_en as supplier_name_en',
                'suppliers.name_ar as supplier_name_ar',
            ])
            ->orderByDesc('contracts.id')
            ->paginate(max(10, min(100, $perPage)))
            ->withQueryString();
    }

    /** @return list<object> */
    public function rfqRequestOptions(
        User $actor,
        ResolvedTenantContext $context,
        int $limit = 100,
    ): array {
        $scope = $this->scope($actor, $context, 'procurement.purchase_requests.view');

        return DB::table('enterprise_purchase_requests as requests')
            ->leftJoin('enterprise_procurement_rfqs as rfqs', 'rfqs.purchase_request_id', '=', 'requests.id')
            ->where('requests.organization_id', $scope->organizationId)
            ->where('requests.company_id', $scope->companyId)
            ->where('requests.store_id', $scope->storeId)
            ->whereIn('requests.status', ['submitted', 'approved'])
            ->whereNull('rfqs.id')
            ->orderByDesc('requests.id')
            ->limit(max(1, min(100, $limit)))
            ->get([
                'requests.id',
                'requests.request_number',
                'requests.status',
                'requests.currency_code',
                'requests.needed_by',
            ])
            ->all();
    }

    /** @return list<object> */
    public function activeContractOptions(
        User $actor,
        ResolvedTenantContext $context,
        ?int $supplierId = null,
        int $limit = 100,
    ): array {
        $scope = $this->scope($actor, $context, 'procurement.purchase_orders.view');

        return DB::table('enterprise_procurement_contracts as contracts')
            ->join('enterprise_suppliers as suppliers', 'suppliers.id', '=', 'contracts.supplier_id')
            ->where('contracts.organization_id', $scope->organizationId)
            ->where('contracts.company_id', $scope->companyId)
            ->where('contracts.store_id', $scope->storeId)
            ->where('contracts.status', 'active')
            ->whereDate('contracts.starts_on', '<=', now()->toDateString())
            ->whereDate('contracts.ends_on', '>=', now()->toDateString())
            ->when($supplierId !== null, fn ($query) => $query->where('contracts.supplier_id', $supplierId))
            ->orderBy('contracts.ends_on')
            ->limit(max(1, min(100, $limit)))
            ->get([
                'contracts.id',
                'contracts.contract_number',
                'contracts.title',
                'contracts.supplier_id',
                'contracts.currency_code',
                'contracts.ends_on',
                'suppliers.code as supplier_code',
            ])
            ->all();
    }

    /** @return array{contract:object,documents:list<object>} */
    public function contractDetail(
        User $actor,
        ResolvedTenantContext $context,
        int $contractId,
    ): array {
        $scope = $this->scope($actor, $context, 'procurement.purchase_orders.view');
        $contract = $this->visibleContract($scope, $contractId);
        $documents = DB::table('enterprise_procurement_contract_documents')
            ->where('contract_id', $contract->id)
            ->orderByDesc('id')
            ->limit(50)
            ->get()
            ->all();

        return compact('contract', 'documents');
    }

    /** @param array<string,mixed> $attributes */
    public function addContractDocument(
        User $actor,
        ResolvedTenantContext $context,
        int $contractId,
        array $attributes,
        ?UploadedFile $attachment = null,
    ): object {
        $scope = $this->scope($actor, $context, 'procurement.purchase_orders.manage');
        $contract = $this->visibleContract($scope, $contractId);
        $data = Validator::make($attributes, [
            'title' => ['required', 'string', 'max:190'],
            'reference' => ['nullable', 'string', 'max:190'],
        ])->validate();

        $storagePath = null;
        $originalName = null;
        $mimeType = null;
        $sizeBytes = null;

        if ($attachment instanceof UploadedFile) {
            $extension = strtolower((string) ($attachment->guessExtension() ?: $attachment->getClientOriginalExtension()));
            $filename = Str::uuid()->toString().($extension !== '' ? '.'.$extension : '');
            $directory = 'procurement/contracts/'.$contract->public_id;
            $stored = Storage::disk('local')->putFileAs($directory, $attachment, $filename);
            if (! is_string($stored) || $stored === '' || ! Storage::disk('local')->exists($stored)) {
                throw ValidationException::withMessages([
                    'attachment' => ['The contract attachment could not be stored safely.'],
                ]);
            }

            $storagePath = $stored;
            $originalName = Str::limit($attachment->getClientOriginalName(), 255, '');
            $mimeType = $attachment->getMimeType() ?: null;
            $sizeBytes = $attachment->getSize();
        }

        try {
            $id = DB::table('enterprise_procurement_contract_documents')->insertGetId([
                'contract_id' => $contract->id,
                'title' => $data['title'],
                'reference' => $data['reference'] ?? null,
                'storage_path' => $storagePath,
                'original_name' => $originalName,
                'mime_type' => $mimeType,
                'size_bytes' => $sizeBytes,
                'created_by' => $actor->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (\Throwable $exception) {
            if ($storagePath !== null) {
                Storage::disk('local')->delete($storagePath);
            }
            throw $exception;
        }

        $document = DB::table('enterprise_procurement_contract_documents')->where('id', $id)->firstOrFail();
        $this->audit->record('enterprise.procurement.contract.document_registered', $actor, null, null, [
            'store_id' => $scope->storeId,
            'contract_id' => $contract->id,
            'document_id' => $id,
            'title' => $document->title,
            'has_attachment' => $storagePath !== null,
        ]);

        return $document;
    }

    public function contractDocument(
        User $actor,
        ResolvedTenantContext $context,
        int $contractId,
        int $documentId,
    ): object {
        $scope = $this->scope($actor, $context, 'procurement.purchase_orders.view');
        $contract = $this->visibleContract($scope, $contractId);

        return DB::table('enterprise_procurement_contract_documents')
            ->where('contract_id', $contract->id)
            ->where('id', $documentId)
            ->firstOrFail();
    }

    /** @param array<string,mixed> $attributes */
    public function createContract(
        User $actor,
        ResolvedTenantContext $context,
        array $attributes,
    ): object {
        $scope = $this->scope($actor, $context, 'procurement.purchase_orders.manage');
        $data = Validator::make($attributes, [
            'supplier_id' => ['required', 'integer', 'min:1'],
            'title' => ['required', 'string', 'max:190'],
            'currency_code' => ['required', 'string', 'size:3'],
            'starts_on' => ['required', 'date'],
            'ends_on' => ['required', 'date', 'after_or_equal:starts_on'],
            'value_ceiling' => ['nullable', 'numeric', 'gt:0'],
            'quantity_ceiling' => ['nullable', 'numeric', 'gt:0'],
            'notes' => ['nullable', 'string', 'max:4000'],
            'renewal_of_id' => ['nullable', 'integer', 'min:1'],
        ])->validate();

        $supplier = $this->visibleSupplier($scope, (int) $data['supplier_id']);
        if ($supplier->status !== 'active' || $supplier->qualification_status === 'unqualified') {
            throw ValidationException::withMessages(['supplier_id' => ['Contract supplier must be active and qualified or conditional.']]);
        }

        if (($data['renewal_of_id'] ?? null) !== null) {
            $previous = $this->visibleContract($scope, (int) $data['renewal_of_id']);
            if ((int) $previous->supplier_id !== (int) $supplier->id) {
                throw ValidationException::withMessages(['renewal_of_id' => ['Renewal must keep the same supplier.']]);
            }
        }

        $publicId = (string) Str::uuid();
        $contractId = DB::table('enterprise_procurement_contracts')->insertGetId([
            'public_id' => $publicId,
            'contract_number' => 'CTR-'.strtoupper(substr(str_replace('-', '', $publicId), 0, 11)),
            'organization_id' => $scope->organizationId,
            'company_id' => $scope->companyId,
            'store_id' => $scope->storeId,
            'supplier_id' => $supplier->id,
            'status' => 'draft',
            'title' => $data['title'],
            'currency_code' => strtoupper($data['currency_code']),
            'starts_on' => $data['starts_on'],
            'ends_on' => $data['ends_on'],
            'value_ceiling' => $data['value_ceiling'] ?? null,
            'quantity_ceiling' => $data['quantity_ceiling'] ?? null,
            'renewal_of_id' => $data['renewal_of_id'] ?? null,
            'notes' => $data['notes'] ?? null,
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $contract = DB::table('enterprise_procurement_contracts')->where('id', $contractId)->firstOrFail();
        $this->audit->record('enterprise.procurement.contract.created', $actor, null, null, [
            'store_id' => $scope->storeId,
            'contract_id' => $contractId,
            'contract_number' => $contract->contract_number,
            'supplier_id' => $supplier->id,
        ]);

        return $contract;
    }

    public function activateContract(
        User $actor,
        ResolvedTenantContext $context,
        int $contractId,
    ): void {
        $scope = $this->scope($actor, $context, 'procurement.purchase_orders.manage');
        $contract = $this->visibleContract($scope, $contractId, true);
        if ($contract->status === 'active') {
            return;
        }
        if ($contract->status !== 'draft') {
            throw ValidationException::withMessages(['status' => ['Only draft contracts can be activated.']]);
        }

        DB::table('enterprise_procurement_contracts')->where('id', $contract->id)->update([
            'status' => 'active',
            'activated_by' => $actor->id,
            'activated_at' => now(),
            'updated_by' => $actor->id,
            'updated_at' => now(),
        ]);
        $this->audit->record('enterprise.procurement.contract.activated', $actor, null, null, [
            'store_id' => $scope->storeId,
            'contract_id' => $contract->id,
        ]);
    }

    public function terminateContract(
        User $actor,
        ResolvedTenantContext $context,
        int $contractId,
    ): void {
        $scope = $this->scope($actor, $context, 'procurement.purchase_orders.manage');
        $contract = $this->visibleContract($scope, $contractId, true);
        if ($contract->status === 'terminated') {
            return;
        }
        if (! in_array($contract->status, ['draft', 'active'], true)) {
            throw ValidationException::withMessages(['status' => ['Contract cannot be terminated from its current state.']]);
        }

        DB::table('enterprise_procurement_contracts')->where('id', $contract->id)->update([
            'status' => 'terminated',
            'terminated_by' => $actor->id,
            'terminated_at' => now(),
            'updated_by' => $actor->id,
            'updated_at' => now(),
        ]);
        $this->audit->record('enterprise.procurement.contract.terminated', $actor, null, null, [
            'store_id' => $scope->storeId,
            'contract_id' => $contract->id,
        ]);
    }

    public function assignContractToOrder(
        User $actor,
        ResolvedTenantContext $context,
        int $orderId,
        int $contractId,
    ): void {
        $scope = $this->scope($actor, $context, 'procurement.purchase_orders.manage');
        DB::transaction(function () use ($actor, $scope, $orderId, $contractId): void {
            $order = DB::table('enterprise_purchase_orders')
                ->where('organization_id', $scope->organizationId)
                ->where('company_id', $scope->companyId)
                ->where('store_id', $scope->storeId)
                ->where('id', $orderId)
                ->lockForUpdate()
                ->first();
            abort_if($order === null, 404);
            if ($order->status !== 'draft') {
                throw ValidationException::withMessages(['status' => ['A contract can only be assigned before PO issue.']]);
            }

            $contract = $this->visibleContract($scope, $contractId, true);
            if ($contract->status !== 'active') {
                throw ValidationException::withMessages(['contract_id' => ['Only active contracts can be assigned.']]);
            }
            if ((int) $contract->supplier_id !== (int) $order->supplier_id) {
                throw ValidationException::withMessages(['contract_id' => ['Contract supplier must match PO supplier.']]);
            }
            if ($contract->currency_code !== $order->currency_code) {
                throw ValidationException::withMessages(['contract_id' => ['Contract currency must match PO currency.']]);
            }
            if (CarbonImmutable::parse((string) $contract->starts_on)->isFuture()
                || CarbonImmutable::parse((string) $contract->ends_on)->isPast()) {
                throw ValidationException::withMessages(['contract_id' => ['Contract is outside its validity window.']]);
            }

            DB::table('enterprise_purchase_orders')->where('id', $order->id)->update([
                'procurement_contract_id' => $contract->id,
                'updated_by' => $actor->id,
                'updated_at' => now(),
            ]);
            $this->audit->record('enterprise.procurement.contract.assigned_to_order', $actor, null, null, [
                'store_id' => $scope->storeId,
                'contract_id' => $contract->id,
                'purchase_order_id' => $order->id,
            ]);
        }, 3);
    }

    /** @param array<string,mixed> $attributes */
    public function updateSupplierGovernance(
        User $actor,
        ResolvedTenantContext $context,
        int $supplierId,
        array $attributes,
    ): EnterpriseSupplier {
        $scope = $this->scope($actor, $context, 'procurement.suppliers.manage');
        $data = Validator::make($attributes, [
            'qualification_status' => ['required', Rule::in(self::QUALIFICATION_STATUSES)],
            'qualification_note' => ['nullable', 'string', 'max:2000'],
            'blocked' => ['required', 'boolean'],
            'blocked_reason' => ['nullable', 'string', 'max:2000', 'required_if:blocked,1'],
        ])->validate();

        return DB::transaction(function () use ($actor, $scope, $supplierId, $data): EnterpriseSupplier {
            $supplier = EnterpriseSupplier::query()
                ->where('id', $supplierId)
                ->where('organization_id', $scope->organizationId)
                ->where('company_id', $scope->companyId)
                ->where(function ($query) use ($scope): void {
                    $query->where('scope_type', 'company')
                        ->orWhere(function ($store) use ($scope): void {
                            $store->where('scope_type', 'store')->where('store_id', $scope->storeId);
                        });
                })
                ->lockForUpdate()
                ->firstOrFail();

            $before = $supplier->toArray();
            $blocked = (bool) $data['blocked'];
            $supplier->forceFill([
                'qualification_status' => $data['qualification_status'],
                'qualification_note' => $data['qualification_note'] ?? null,
                'qualification_updated_at' => now(),
                'status' => $blocked ? 'on_hold' : ($supplier->status === 'on_hold' ? 'active' : $supplier->status),
                'blocked_reason' => $blocked ? $data['blocked_reason'] : null,
                'blocked_at' => $blocked ? now() : null,
                'blocked_by' => $blocked ? $actor->id : null,
                'updated_by' => $actor->id,
            ])->save();

            $this->audit->record(
                'enterprise.supplier.governance_updated',
                $actor,
                $supplier,
                $before,
                $supplier->fresh()->toArray(),
            );

            return $supplier->refresh();
        }, 3);
    }

    public function syncMatchForInvoice(int $invoiceId): object
    {
        return DB::transaction(function () use ($invoiceId): object {
            $invoice = DB::table('enterprise_purchase_invoices')->where('id', $invoiceId)->firstOrFail();
            $rows = DB::table('enterprise_purchase_invoice_lines as invoice_lines')
                ->join('enterprise_goods_receipt_lines as receipt_lines', 'receipt_lines.id', '=', 'invoice_lines.goods_receipt_line_id')
                ->join('enterprise_purchase_order_lines as order_lines', 'order_lines.id', '=', 'invoice_lines.purchase_order_line_id')
                ->where('invoice_lines.purchase_invoice_id', $invoice->id)
                ->get([
                    'invoice_lines.invoice_quantity',
                    'invoice_lines.unit_price as invoice_unit_price',
                    'receipt_lines.received_quantity',
                    'order_lines.quantity as ordered_quantity',
                    'order_lines.unit_price as order_unit_price',
                ]);

            $quantityVariance = 0.0;
            $valueVariance = 0.0;
            foreach ($rows as $row) {
                $quantityVariance += max(0.0, (float) $row->invoice_quantity - (float) $row->received_quantity);
                $orderUnitPrice = (float) ($row->order_unit_price ?? 0);
                $invoiceUnitPrice = (float) $row->invoice_unit_price;
                $valueVariance += abs(($invoiceUnitPrice - $orderUnitPrice) * (float) $row->invoice_quantity);
            }
            $quantityVariance = round($quantityVariance, 3);
            $valueVariance = round($valueVariance, 3);
            $quantityTolerance = max(0.0, (float) config('procurement.three_way_match.quantity_tolerance', 0));
            $valueTolerance = max(0.0, (float) config('procurement.three_way_match.value_tolerance', 0.010));
            $autoApproved = $quantityVariance <= $quantityTolerance && $valueVariance <= $valueTolerance;

            $existing = DB::table('enterprise_procurement_match_reviews')
                ->where('purchase_invoice_id', $invoice->id)
                ->lockForUpdate()
                ->first();
            $status = $existing?->status;
            if ($status === null || in_array($status, ['auto_approved', 'pending'], true)) {
                $status = $autoApproved ? 'auto_approved' : 'pending';
            }

            DB::table('enterprise_procurement_match_reviews')->updateOrInsert(
                ['purchase_invoice_id' => $invoice->id],
                [
                    'status' => $status,
                    'quantity_variance' => $quantityVariance,
                    'value_variance' => $valueVariance,
                    'quantity_tolerance' => $quantityTolerance,
                    'value_tolerance' => $valueTolerance,
                    'exception_reason' => $autoApproved
                        ? null
                        : 'PO / GRN / invoice quantity or value is outside configured tolerance.',
                    'updated_at' => now(),
                    'created_at' => $existing !== null ? $existing->created_at : now(),
                ],
            );

            return DB::table('enterprise_procurement_match_reviews')
                ->where('purchase_invoice_id', $invoice->id)
                ->firstOrFail();
        }, 3);
    }

    public function assertMatchAllowsHandoff(int $invoiceId): void
    {
        $match = $this->syncMatchForInvoice($invoiceId);
        if (! in_array($match->status, ['auto_approved', 'approved'], true)) {
            throw ValidationException::withMessages([
                'three_way_match' => ['PO / GRN / invoice match requires approval before Finance handoff.'],
            ]);
        }
    }

    /**
     * @param  array{decision:string,note?:string|null}  $attributes
     */
    public function reviewMatch(
        User $actor,
        ResolvedTenantContext $context,
        int $invoiceId,
        array $attributes,
    ): object {
        $scope = $this->scope($actor, $context, 'procurement.purchase_invoices.handoff');
        $data = Validator::make($attributes, [
            'decision' => ['required', Rule::in(['approve', 'reject'])],
            'note' => ['nullable', 'string', 'max:2000'],
        ])->validate();

        $invoice = DB::table('enterprise_purchase_invoices')
            ->where('id', $invoiceId)
            ->where('organization_id', $scope->organizationId)
            ->where('company_id', $scope->companyId)
            ->where('store_id', $scope->storeId)
            ->first();
        abort_if($invoice === null, 404);

        $match = $this->syncMatchForInvoice($invoiceId);
        DB::table('enterprise_procurement_match_reviews')->where('id', $match->id)->update([
            'status' => $data['decision'] === 'approve' ? 'approved' : 'rejected',
            'review_note' => $data['note'] ?? null,
            'reviewed_by' => $actor->id,
            'reviewed_at' => now(),
            'updated_at' => now(),
        ]);

        $updated = DB::table('enterprise_procurement_match_reviews')->where('id', $match->id)->firstOrFail();
        $this->audit->record('enterprise.procurement.three_way_match.reviewed', $actor, null, null, [
            'store_id' => $scope->storeId,
            'purchase_invoice_id' => $invoiceId,
            'status' => $updated->status,
            'quantity_variance' => $updated->quantity_variance,
            'value_variance' => $updated->value_variance,
        ]);

        return $updated;
    }

    /**
     * @param  array{q?:string|null,status?:string|null}  $filters
     * @return LengthAwarePaginator<int,object>
     */
    public function paginateMatches(
        User $actor,
        ResolvedTenantContext $context,
        array $filters = [],
        int $perPage = 25,
    ): LengthAwarePaginator {
        $scope = $this->scope($actor, $context, 'procurement.purchase_invoices.view');
        $validated = Validator::make($filters, [
            'q' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', Rule::in(self::MATCH_STATUSES)],
        ])->validate();
        $term = str_replace(['%', '_'], '', trim((string) ($validated['q'] ?? '')));

        return DB::table('enterprise_purchase_invoices as invoices')
            ->leftJoin('enterprise_procurement_match_reviews as match_reviews', 'match_reviews.purchase_invoice_id', '=', 'invoices.id')
            ->where('invoices.organization_id', $scope->organizationId)
            ->where('invoices.company_id', $scope->companyId)
            ->where('invoices.store_id', $scope->storeId)
            ->when(
                $validated['status'] ?? null,
                fn ($query, string $status) => $query->where('match_reviews.status', $status),
            )
            ->when(
                $term !== '',
                fn ($query) => $query->where(function ($search) use ($term): void {
                    $search->where('invoices.invoice_number', 'like', $term.'%')
                        ->orWhere('invoices.supplier_invoice_number', 'like', $term.'%')
                        ->orWhere('invoices.supplier_code', 'like', $term.'%');
                }),
            )
            ->select([
                'invoices.id as purchase_invoice_id',
                'invoices.invoice_number',
                'invoices.supplier_invoice_number',
                'invoices.supplier_code',
                'invoices.total_amount',
                'invoices.currency_code',
                'invoices.status as invoice_status',
                'match_reviews.id as match_review_id',
                'match_reviews.status as match_status',
                'match_reviews.quantity_variance',
                'match_reviews.value_variance',
                'match_reviews.exception_reason',
                'match_reviews.review_note',
            ])
            ->orderByDesc('invoices.id')
            ->paginate(max(10, min(100, $perPage)))
            ->withQueryString();
    }

    /**
     * @param  array{from?:string|null,to?:string|null}  $filters
     * @return array{columns:list<string>,rows:list<array<string,mixed>>}
     */
    public function report(
        User $actor,
        ResolvedTenantContext $context,
        string $report,
        array $filters = [],
        int $limit = 500,
    ): array {
        $scope = $this->scope($actor, $context, 'procurement.supplier_statements.view');

        return $this->reportForStore($report, $scope->storeId, $filters, $limit);
    }

    /**
     * Internal reporting bridge used by Enterprise BI after its own entitlement
     * and store-scope authorization succeeds.
     *
     * @param  array{from?:string|null,to?:string|null}  $filters
     * @return array{columns:list<string>,rows:list<array<string,mixed>>}
     */
    public function reportForStore(
        string $report,
        int $storeId,
        array $filters = [],
        int $limit = 500,
        int $offset = 0,
    ): array {
        [$from, $to] = $this->dateWindow($filters['from'] ?? null, $filters['to'] ?? null);
        $limit = max(1, min(5000, $limit));
        $offset = max(0, $offset);

        return match ($report) {
            'open_orders' => $this->openOrdersReport($storeId, $limit, $offset),
            'spend' => $this->spendReport($storeId, $from, $to, $limit, $offset),
            'supplier_performance' => $this->supplierPerformanceReport($storeId, $from, $to, $limit, $offset),
            'savings' => $this->savingsReport($storeId, $from, $to, $limit, $offset),
            'contract_expiry' => $this->contractExpiryReport($storeId, $limit, $offset),
            'match_exceptions' => $this->matchExceptionReport($storeId, $limit, $offset),
            default => throw ValidationException::withMessages(['report' => ['Unknown procurement report.']]),
        };
    }

    /** @return list<array<string,mixed>> */
    private function exceptions(int $storeId, int $companyId, bool $canOrders, bool $canInvoices, bool $canSuppliers): array
    {
        $exceptions = [];

        if ($canOrders) {
            $overdue = DB::table('enterprise_purchase_orders')
                ->where('store_id', $storeId)
                ->where('status', 'issued')
                ->whereNotNull('needed_by')
                ->whereDate('needed_by', '<', now()->toDateString())
                ->count();
            $receiptVariance = $this->orderedNotReceivedCount($storeId);
            $expiring = DB::table('enterprise_procurement_contracts')
                ->where('store_id', $storeId)
                ->where('status', 'active')
                ->whereBetween('ends_on', [now()->toDateString(), now()->addDays(30)->toDateString()])
                ->count();

            $exceptions[] = ['key' => 'overdue_orders', 'count' => $overdue, 'tab' => 'orders'];
            $exceptions[] = ['key' => 'receipt_variance', 'count' => $receiptVariance, 'tab' => 'receipts'];
            $exceptions[] = ['key' => 'expiring_contracts', 'count' => $expiring, 'tab' => 'contracts'];
        }

        if ($canInvoices) {
            $matchVariance = DB::table('enterprise_purchase_invoices as invoices')
                ->join('enterprise_procurement_match_reviews as matches', 'matches.purchase_invoice_id', '=', 'invoices.id')
                ->where('invoices.store_id', $storeId)
                ->whereIn('matches.status', ['pending', 'rejected'])
                ->count();
            $financeFailed = DB::table('enterprise_purchase_invoices as invoices')
                ->join(
                    'enterprise_outbox_messages as outbox',
                    'outbox.event_id',
                    '=',
                    'invoices.outbox_event_id',
                )
                ->where('invoices.store_id', $storeId)
                ->where('outbox.event_type', 'ProcurementPurchaseInvoiceHandedOff')
                ->where('outbox.status', 'failed')
                ->count();

            $exceptions[] = ['key' => 'match_variance', 'count' => $matchVariance, 'tab' => 'matches'];
            $exceptions[] = ['key' => 'finance_handoff_failed', 'count' => $financeFailed, 'tab' => 'invoices'];
        }

        if ($canSuppliers) {
            $blocked = DB::table('enterprise_suppliers')
                ->where('company_id', $companyId)
                ->where('status', 'on_hold')
                ->where(function ($query) use ($storeId): void {
                    $query->where('scope_type', 'company')
                        ->orWhere(function ($store) use ($storeId): void {
                            $store->where('scope_type', 'store')->where('store_id', $storeId);
                        });
                })
                ->count();
            $exceptions[] = ['key' => 'blocked_suppliers', 'count' => $blocked, 'tab' => 'suppliers'];
        }

        return $exceptions;
    }

    private function orderedNotReceivedCount(int $storeId): int
    {
        $orders = DB::table('enterprise_purchase_orders as orders')
            ->join('enterprise_purchase_order_lines as order_lines', 'order_lines.purchase_order_id', '=', 'orders.id')
            ->leftJoin('enterprise_goods_receipt_lines as receipt_lines', 'receipt_lines.purchase_order_line_id', '=', 'order_lines.id')
            ->leftJoin('enterprise_goods_receipts as receipts', function ($join): void {
                $join->on('receipts.id', '=', 'receipt_lines.goods_receipt_id')
                    ->where('receipts.status', '=', 'posted');
            })
            ->where('orders.store_id', $storeId)
            ->where('orders.status', 'issued')
            ->select('orders.id')
            ->groupBy('orders.id')
            ->havingRaw('SUM(order_lines.quantity) > COALESCE(SUM(CASE WHEN receipts.id IS NOT NULL THEN receipt_lines.received_quantity ELSE 0 END), 0)');

        return DB::query()->fromSub($orders, 'ordered_not_received')->count();
    }

    /** @return list<array<string,mixed>> */
    private function spendTrend(int $storeId, string $from, string $to): array
    {
        return DB::table('enterprise_purchase_invoices')
            ->where('store_id', $storeId)
            ->where('status', 'handed_off')
            ->whereBetween('invoice_date', [$from, $to])
            ->selectRaw('SUBSTR(invoice_date, 1, 7) as bucket, SUM(total_amount) as value')
            ->groupByRaw('SUBSTR(invoice_date, 1, 7)')
            ->orderBy('bucket')
            ->get()
            ->map(fn ($row): array => ['label' => $row->bucket, 'value' => (float) $row->value])
            ->all();
    }

    /** @return list<array<string,mixed>> */
    private function spendBySupplier(int $storeId, string $from, string $to): array
    {
        return DB::table('enterprise_purchase_invoices')
            ->where('store_id', $storeId)
            ->where('status', 'handed_off')
            ->whereBetween('invoice_date', [$from, $to])
            ->groupBy('supplier_id', 'supplier_code')
            ->selectRaw('supplier_id, supplier_code as label, SUM(total_amount) as value')
            ->orderByDesc('value')
            ->limit(8)
            ->get()
            ->map(fn ($row): array => ['label' => $row->label, 'value' => (float) $row->value])
            ->all();
    }

    /** @return list<array<string,mixed>> */
    private function spendByCategory(int $storeId, string $from, string $to): array
    {
        return DB::table('enterprise_purchase_invoices as invoices')
            ->join('enterprise_purchase_invoice_lines as invoice_lines', 'invoice_lines.purchase_invoice_id', '=', 'invoices.id')
            ->leftJoin('products', 'products.sku', '=', 'invoice_lines.item_code')
            ->leftJoin('categories', 'categories.id', '=', 'products.category_id')
            ->where('invoices.store_id', $storeId)
            ->where('invoices.status', 'handed_off')
            ->whereBetween('invoices.invoice_date', [$from, $to])
            ->groupBy('products.category_id', 'categories.name')
            ->selectRaw(
                "COALESCE(categories.name, 'Uncategorized') as label, SUM(invoice_lines.line_total) as value",
            )
            ->orderByDesc('value')
            ->limit(8)
            ->get()
            ->map(fn ($row): array => ['label' => $row->label, 'value' => (float) $row->value])
            ->all();
    }

    /** @return list<array<string,mixed>> */
    private function purchaseOrderStatusChart(int $storeId): array
    {
        return DB::table('enterprise_purchase_orders')
            ->where('store_id', $storeId)
            ->groupBy('status')
            ->selectRaw('status as label, COUNT(*) as value')
            ->orderBy('status')
            ->get()
            ->map(fn ($row): array => ['label' => $row->label, 'value' => (int) $row->value])
            ->all();
    }

    /** @return list<array<string,mixed>> */
    private function purchaseOrderAgingChart(int $storeId): array
    {
        $today = CarbonImmutable::today();
        $buckets = ['current' => 0, '1_7' => 0, '8_30' => 0, '31_60' => 0, '61_plus' => 0];

        DB::table('enterprise_purchase_orders')
            ->where('store_id', $storeId)
            ->where('status', 'issued')
            ->orderBy('id')
            ->select(['id', 'needed_by', 'created_at'])
            ->chunkById(250, function ($orders) use (&$buckets, $today): void {
                foreach ($orders as $order) {
                    $anchor = $order->needed_by !== null
                        ? CarbonImmutable::parse((string) $order->needed_by)->startOfDay()
                        : CarbonImmutable::parse((string) $order->created_at)->startOfDay();

                    if ($anchor->greaterThanOrEqualTo($today)) {
                        $buckets['current']++;

                        continue;
                    }

                    $days = (int) $anchor->diffInDays($today);
                    $bucket = match (true) {
                        $days <= 7 => '1_7',
                        $days <= 30 => '8_30',
                        $days <= 60 => '31_60',
                        default => '61_plus',
                    };
                    $buckets[$bucket]++;
                }
            }, 'id');

        return collect($buckets)
            ->map(fn (int $value, string $label): array => ['label' => $label, 'value' => $value])
            ->values()
            ->all();
    }

    /** @return list<array<string,mixed>> */
    private function supplierPerformance(int $storeId, string $from, string $to): array
    {
        return DB::table('enterprise_purchase_orders as orders')
            ->join('enterprise_goods_receipts as receipts', 'receipts.purchase_order_id', '=', 'orders.id')
            ->where('orders.store_id', $storeId)
            ->where('orders.status', 'issued')
            ->where('receipts.status', 'posted')
            ->whereBetween('orders.created_at', [$from.' 00:00:00', $to.' 23:59:59'])
            ->whereNotNull('orders.issued_at')
            ->whereNotNull('receipts.posted_at')
            ->get([
                'orders.supplier_code',
                'orders.issued_at',
                'receipts.posted_at',
            ])
            ->groupBy('supplier_code')
            ->map(function ($rows, string $supplierCode): array {
                $leadDays = $rows->map(
                    fn ($row): int => (int) CarbonImmutable::parse((string) $row->issued_at)
                        ->diffInDays(CarbonImmutable::parse((string) $row->posted_at)),
                );

                return [
                    'label' => $supplierCode,
                    'value' => round((float) $leadDays->avg(), 1),
                ];
            })
            ->sortBy('value')
            ->take(8)
            ->values()
            ->all();
    }

    /** @return list<array<string,mixed>> */
    private function savingsChart(int $storeId, string $from, string $to): array
    {
        return DB::table('enterprise_procurement_quotes as quotes')
            ->join('enterprise_procurement_rfqs as rfqs', 'rfqs.id', '=', 'quotes.rfq_id')
            ->join('enterprise_procurement_quote_lines as quote_lines', 'quote_lines.quote_id', '=', 'quotes.id')
            ->join('enterprise_procurement_rfq_lines as rfq_lines', 'rfq_lines.id', '=', 'quote_lines.rfq_line_id')
            ->where('rfqs.store_id', $storeId)
            ->where('quotes.status', 'awarded')
            ->whereBetween('quotes.awarded_at', [$from.' 00:00:00', $to.' 23:59:59'])
            ->selectRaw(
                'rfqs.rfq_number as label, '
                .'SUM((COALESCE(rfq_lines.baseline_unit_cost, quote_lines.unit_price) - quote_lines.unit_price) * quote_lines.quantity) as value',
            )
            ->groupBy('rfqs.id', 'rfqs.rfq_number')
            ->orderByDesc('quotes.awarded_at')
            ->limit(8)
            ->get()
            ->map(fn ($row): array => ['label' => $row->label, 'value' => round((float) $row->value, 3)])
            ->all();
    }

    /** @return list<array<string,mixed>> */
    private function contractExpiryHorizon(int $storeId): array
    {
        $today = CarbonImmutable::today();

        return [
            ['label' => '0-30', 'value' => $this->contractCountBetween($storeId, $today, $today->addDays(30))],
            ['label' => '31-60', 'value' => $this->contractCountBetween($storeId, $today->addDays(31), $today->addDays(60))],
            ['label' => '61-90', 'value' => $this->contractCountBetween($storeId, $today->addDays(61), $today->addDays(90))],
            ['label' => '90+', 'value' => DB::table('enterprise_procurement_contracts')
                ->where('store_id', $storeId)
                ->where('status', 'active')
                ->whereDate('ends_on', '>', $today->addDays(90)->toDateString())
                ->count()],
        ];
    }

    private function contractCountBetween(int $storeId, CarbonImmutable $from, CarbonImmutable $to): int
    {
        return DB::table('enterprise_procurement_contracts')
            ->where('store_id', $storeId)
            ->where('status', 'active')
            ->whereBetween('ends_on', [$from->toDateString(), $to->toDateString()])
            ->count();
    }

    /** @return array{columns:list<string>,rows:list<array<string,mixed>>} */
    private function openOrdersReport(int $storeId, int $limit, int $offset = 0): array
    {
        $rows = DB::table('enterprise_purchase_orders as orders')
            ->leftJoin('enterprise_purchase_order_lines as lines', 'lines.purchase_order_id', '=', 'orders.id')
            ->where('orders.store_id', $storeId)
            ->whereIn('orders.status', ['draft', 'issued'])
            ->groupBy(
                'orders.id',
                'orders.order_number',
                'orders.supplier_code',
                'orders.status',
                'orders.needed_by',
                'orders.currency_code',
            )
            ->selectRaw(
                'orders.order_number, orders.supplier_code as supplier_reference, orders.status, '
                .'orders.needed_by, orders.currency_code as currency, '
                .'COALESCE(SUM(COALESCE(lines.unit_price, 0) * lines.quantity), 0) as order_value',
            )
            ->orderByRaw('CASE WHEN orders.needed_by IS NULL THEN 1 ELSE 0 END')
            ->orderBy('orders.needed_by')
            ->offset($offset)
            ->limit($limit)
            ->get()
            ->map(function ($row): array {
                $neededBy = $row->needed_by === null
                    ? null
                    : CarbonImmutable::parse((string) $row->needed_by);
                $daysOverdue = $neededBy !== null && $neededBy->isPast()
                    ? (int) $neededBy->diffInDays(CarbonImmutable::today())
                    : 0;

                return [
                    'order_number' => (string) $row->order_number,
                    'supplier_reference' => (string) $row->supplier_reference,
                    'status' => (string) $row->status,
                    'needed_by' => $row->needed_by === null ? null : (string) $row->needed_by,
                    'currency' => (string) $row->currency,
                    'order_value' => number_format((float) $row->order_value, 3, '.', ''),
                    'days_overdue' => $daysOverdue,
                ];
            })
            ->all();

        return [
            'columns' => ['order_number', 'supplier_reference', 'status', 'needed_by', 'currency', 'order_value', 'days_overdue'],
            'rows' => $rows,
        ];
    }

    /** @return array{columns:list<string>,rows:list<array<string,mixed>>} */
    private function spendReport(int $storeId, string $from, string $to, int $limit, int $offset = 0): array
    {
        $rows = DB::table('enterprise_purchase_invoices')
            ->where('store_id', $storeId)
            ->where('status', 'handed_off')
            ->whereBetween('invoice_date', [$from, $to])
            ->groupBy('supplier_id', 'supplier_code', 'currency_code')
            ->selectRaw('supplier_code as supplier_reference, currency_code as currency, COUNT(*) as invoice_count, SUM(total_amount) as spend')
            ->orderByDesc('spend')
            ->offset($offset)
            ->limit($limit)
            ->get()
            ->map(fn ($row): array => (array) $row)
            ->all();

        return ['columns' => ['supplier_reference', 'currency', 'invoice_count', 'spend'], 'rows' => $rows];
    }

    /** @return array{columns:list<string>,rows:list<array<string,mixed>>} */
    private function supplierPerformanceReport(int $storeId, string $from, string $to, int $limit, int $offset = 0): array
    {
        $source = DB::table('enterprise_purchase_orders as orders')
            ->leftJoin('enterprise_goods_receipts as receipts', function ($join): void {
                $join->on('receipts.purchase_order_id', '=', 'orders.id')
                    ->where('receipts.status', '=', 'posted');
            })
            ->where('orders.store_id', $storeId)
            ->whereBetween('orders.created_at', [$from.' 00:00:00', $to.' 23:59:59'])
            ->get([
                'orders.id as order_id',
                'orders.supplier_code',
                'orders.issued_at',
                'receipts.id as receipt_id',
                'receipts.posted_at',
            ]);

        $rows = $source
            ->groupBy('supplier_code')
            ->map(function ($supplierRows, string $supplierCode): array {
                $leadDays = $supplierRows
                    ->filter(fn ($row): bool => $row->issued_at !== null && $row->posted_at !== null)
                    ->map(
                        fn ($row): int => (int) CarbonImmutable::parse((string) $row->issued_at)
                            ->diffInDays(CarbonImmutable::parse((string) $row->posted_at)),
                    );

                return [
                    'supplier_reference' => $supplierCode,
                    'order_count' => $supplierRows->pluck('order_id')->unique()->count(),
                    'receipt_count' => $supplierRows->pluck('receipt_id')->filter()->unique()->count(),
                    'avg_lead_days' => $leadDays->isEmpty() ? null : round((float) $leadDays->avg(), 1),
                ];
            })
            ->sortByDesc('order_count')
            ->slice($offset, $limit)
            ->values()
            ->all();

        return ['columns' => ['supplier_reference', 'order_count', 'receipt_count', 'avg_lead_days'], 'rows' => $rows];
    }

    /** @return array{columns:list<string>,rows:list<array<string,mixed>>} */
    private function savingsReport(int $storeId, string $from, string $to, int $limit, int $offset = 0): array
    {
        $rows = DB::table('enterprise_procurement_quotes as quotes')
            ->join('enterprise_procurement_rfqs as rfqs', 'rfqs.id', '=', 'quotes.rfq_id')
            ->join('enterprise_suppliers as suppliers', 'suppliers.id', '=', 'quotes.supplier_id')
            ->join('enterprise_procurement_quote_lines as quote_lines', 'quote_lines.quote_id', '=', 'quotes.id')
            ->join('enterprise_procurement_rfq_lines as rfq_lines', 'rfq_lines.id', '=', 'quote_lines.rfq_line_id')
            ->where('rfqs.store_id', $storeId)
            ->where('quotes.status', 'awarded')
            ->whereBetween('quotes.awarded_at', [$from.' 00:00:00', $to.' 23:59:59'])
            ->groupBy('rfqs.id', 'rfqs.rfq_number', 'suppliers.code', 'quotes.currency_code', 'quotes.awarded_at')
            ->selectRaw(
                'rfqs.rfq_number, suppliers.code as supplier_reference, quotes.currency_code as currency, '
                .'SUM(COALESCE(rfq_lines.baseline_unit_cost, quote_lines.unit_price) * quote_lines.quantity) as baseline_value, '
                .'SUM(quote_lines.unit_price * quote_lines.quantity) as awarded_value, '
                .'SUM((COALESCE(rfq_lines.baseline_unit_cost, quote_lines.unit_price) - quote_lines.unit_price) * quote_lines.quantity) as savings',
            )
            ->orderByDesc('quotes.awarded_at')
            ->offset($offset)
            ->limit($limit)
            ->get()
            ->map(fn ($row): array => (array) $row)
            ->all();

        return ['columns' => ['rfq_number', 'supplier_reference', 'currency', 'baseline_value', 'awarded_value', 'savings'], 'rows' => $rows];
    }

    /** @return array{columns:list<string>,rows:list<array<string,mixed>>} */
    private function contractExpiryReport(int $storeId, int $limit, int $offset = 0): array
    {
        $rows = DB::table('enterprise_procurement_contracts as contracts')
            ->join('enterprise_suppliers as suppliers', 'suppliers.id', '=', 'contracts.supplier_id')
            ->where('contracts.store_id', $storeId)
            ->whereIn('contracts.status', ['active', 'draft'])
            ->orderBy('contracts.ends_on')
            ->offset($offset)
            ->limit($limit)
            ->get([
                'contracts.contract_number',
                'suppliers.code as supplier_reference',
                'contracts.status',
                'contracts.currency_code as currency',
                'contracts.starts_on',
                'contracts.ends_on',
                'contracts.value_ceiling',
                'contracts.committed_value',
                'contracts.quantity_ceiling',
                'contracts.committed_quantity',
            ])
            ->map(function ($row): array {
                $status = $row->status === 'active'
                    && (string) $row->ends_on < now()->toDateString()
                        ? 'expired'
                        : (string) $row->status;

                return [
                    'contract_number' => (string) $row->contract_number,
                    'supplier_reference' => (string) $row->supplier_reference,
                    'status' => $status,
                    'currency' => (string) $row->currency,
                    'starts_on' => (string) $row->starts_on,
                    'ends_on' => (string) $row->ends_on,
                    'value_ceiling' => $row->value_ceiling,
                    'committed_value' => $row->committed_value,
                    'quantity_ceiling' => $row->quantity_ceiling,
                    'committed_quantity' => $row->committed_quantity,
                ];
            })
            ->all();

        return [
            'columns' => [
                'contract_number', 'supplier_reference', 'status', 'currency', 'starts_on', 'ends_on',
                'value_ceiling', 'committed_value', 'quantity_ceiling', 'committed_quantity',
            ],
            'rows' => $rows,
        ];
    }

    /** @return array{columns:list<string>,rows:list<array<string,mixed>>} */
    private function matchExceptionReport(int $storeId, int $limit, int $offset = 0): array
    {
        $rows = DB::table('enterprise_purchase_invoices as invoices')
            ->join('enterprise_procurement_match_reviews as matches', 'matches.purchase_invoice_id', '=', 'invoices.id')
            ->where('invoices.store_id', $storeId)
            ->whereIn('matches.status', ['pending', 'rejected'])
            ->orderByDesc('invoices.id')
            ->offset($offset)
            ->limit($limit)
            ->get([
                'invoices.invoice_number',
                'invoices.supplier_invoice_number',
                'invoices.supplier_code as supplier_reference',
                'invoices.currency_code as currency',
                'matches.status',
                'matches.quantity_variance',
                'matches.value_variance',
                'matches.exception_reason',
            ])
            ->map(fn ($row): array => (array) $row)
            ->all();

        return [
            'columns' => ['invoice_number', 'supplier_invoice_number', 'supplier_reference', 'currency', 'status', 'quantity_variance', 'value_variance', 'exception_reason'],
            'rows' => $rows,
        ];
    }

    /** @return array<string,mixed> */
    private function metric(
        string $key,
        mixed $value,
        string $tab,
        array $query,
        string $lastRefresh,
        array $extra = [],
    ): array {
        return [
            'key' => $key,
            'value' => $value,
            'tab' => $tab,
            'query' => $query,
            'last_refresh' => $lastRefresh,
            ...$extra,
        ];
    }

    /** @return array{string,string} */
    private function dateWindow(mixed $from, mixed $to): array
    {
        $end = $to === null || $to === ''
            ? CarbonImmutable::today()
            : CarbonImmutable::parse((string) $to)->startOfDay();
        $start = $from === null || $from === ''
            ? $end->subDays(89)
            : CarbonImmutable::parse((string) $from)->startOfDay();

        if ($start->greaterThan($end)) {
            throw ValidationException::withMessages(['from' => ['From date must be on or before To date.']]);
        }
        if ($start->diffInDays($end) > 366) {
            throw ValidationException::withMessages(['to' => ['Dashboard/report date window cannot exceed 366 days.']]);
        }

        return [$start->toDateString(), $end->toDateString()];
    }

    private function portalInvitation(string $token, bool $touch): object
    {
        if (! preg_match('/^[A-Za-z0-9]{64}$/', $token)) {
            abort(404);
        }

        $invitation = DB::table('enterprise_procurement_rfq_invitations')
            ->where('token_hash', hash('sha256', $token))
            ->first();
        abort_if($invitation === null, 404);
        if (in_array($invitation->status, ['revoked', 'expired'], true)) {
            abort(410);
        }
        if (CarbonImmutable::parse((string) $invitation->expires_at)->isPast()) {
            DB::table('enterprise_procurement_rfq_invitations')
                ->where('id', $invitation->id)
                ->update(['status' => 'expired', 'updated_at' => now()]);
            abort(410);
        }

        if ($touch && in_array($invitation->status, ['invited', 'opened'], true)) {
            DB::table('enterprise_procurement_rfq_invitations')->where('id', $invitation->id)->update([
                'status' => 'opened',
                'last_accessed_at' => now(),
                'updated_at' => now(),
            ]);
            $invitation = DB::table('enterprise_procurement_rfq_invitations')->where('id', $invitation->id)->firstOrFail();
        }

        return $invitation;
    }

    private function visibleRfq(EnterpriseDataScope $scope, int $rfqId, bool $lock = false): object
    {
        $query = DB::table('enterprise_procurement_rfqs')
            ->where('organization_id', $scope->organizationId)
            ->where('company_id', $scope->companyId)
            ->where('store_id', $scope->storeId)
            ->where('id', $rfqId);
        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->firstOrFail();
    }

    private function visibleContract(EnterpriseDataScope $scope, int $contractId, bool $lock = false): object
    {
        $query = DB::table('enterprise_procurement_contracts')
            ->where('organization_id', $scope->organizationId)
            ->where('company_id', $scope->companyId)
            ->where('store_id', $scope->storeId)
            ->where('id', $contractId);
        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->firstOrFail();
    }

    private function visibleSupplier(EnterpriseDataScope $scope, int $supplierId): EnterpriseSupplier
    {
        return EnterpriseSupplier::query()
            ->where('id', $supplierId)
            ->where('organization_id', $scope->organizationId)
            ->where('company_id', $scope->companyId)
            ->where(function ($query) use ($scope): void {
                $query->where('scope_type', 'company')
                    ->orWhere(function ($store) use ($scope): void {
                        $store->where('scope_type', 'store')->where('store_id', $scope->storeId);
                    });
            })
            ->firstOrFail();
    }

    private function baseScope(User $actor, ResolvedTenantContext $context): EnterpriseDataScope
    {
        return $this->scope($actor, $context, 'procurement.suppliers.view');
    }

    private function scope(
        User $actor,
        ResolvedTenantContext $context,
        string $permission,
    ): EnterpriseDataScope {
        abort_unless($this->modules->isEnabled('procurement'), 404, 'Procurement module is disabled.');
        abort_if($context->enterpriseScope === null, 409, 'Enterprise data scope is required.');

        $scope = $context->enterpriseScope;
        $this->authorizer->authorize($actor, $context, $permission, 'company', $scope->companyId);

        return $scope;
    }

    private function can(
        User $actor,
        ResolvedTenantContext $context,
        string $permission,
        EnterpriseDataScope $scope,
    ): bool {
        return $this->authorizer->allows($actor, $context, $permission, 'company', $scope->companyId);
    }
}
