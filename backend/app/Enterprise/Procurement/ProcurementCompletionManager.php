<?php

namespace App\Enterprise\Procurement;

use App\Enterprise\Modules\ModuleRegistry;
use App\Enterprise\Modules\ModuleState;
use App\Enterprise\Organization\OrganizationHierarchyService;
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

    // Maximum 15 integral digits + 3 fractional digits in DECIMAL(18,3).
    private const MAX_QUOTE_AMOUNT_MILLS = 999_999_999_999_999_999;

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
                ->where('organization_id', $scope->organizationId)
                ->where('company_id', $scope->companyId)
                ->where('store_id', $storeId)
                ->whereIn('status', ['draft', 'submitted', 'approved'])
                ->count()
            : null;
        $pendingApprovals = $canRequests
            ? DB::table('enterprise_purchase_requests')
                ->where('organization_id', $scope->organizationId)
                ->where('company_id', $scope->companyId)
                ->where('store_id', $storeId)
                ->where('status', 'submitted')
                ->count()
            : null;
        $openOrders = $canOrders
            ? DB::table('enterprise_purchase_orders')
                ->where('organization_id', $scope->organizationId)
                ->where('company_id', $scope->companyId)
                ->where('store_id', $storeId)
                ->whereIn('status', ['draft', 'issued'])
                ->count()
            : null;
        $overdueOrders = $canOrders
            ? DB::table('enterprise_purchase_orders')
                ->where('organization_id', $scope->organizationId)
                ->where('company_id', $scope->companyId)
                ->where('store_id', $storeId)
                ->where('status', 'issued')
                ->whereNotNull('needed_by')
                ->whereDate('needed_by', '<', now()->toDateString())
                ->count()
            : null;
        $orderedNotReceived = $canOrders ? $this->orderedNotReceivedCount($storeId, $scope->organizationId, $scope->companyId) : null;
        $unmatchedInvoices = $canInvoices
            ? DB::table('enterprise_purchase_invoices as invoices')
                ->leftJoin(
                    'enterprise_procurement_match_reviews as match_reviews',
                    'match_reviews.purchase_invoice_id',
                    '=',
                    'invoices.id',
                )
                ->where('invoices.organization_id', $scope->organizationId)
                ->where('invoices.company_id', $scope->companyId)
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
                ->where('organization_id', $scope->organizationId)
                ->where('company_id', $scope->companyId)
                ->where('store_id', $storeId)
                ->where('status', 'handed_off')
                ->sum('total_amount')
            : null;
        $activeContracts = $canOrders
            ? DB::table('enterprise_procurement_contracts')
                ->where('organization_id', $scope->organizationId)
                ->where('company_id', $scope->companyId)
                ->where('store_id', $storeId)
                ->where('status', 'active')
                ->whereDate('starts_on', '<=', now()->toDateString())
                ->whereDate('ends_on', '>=', now()->toDateString())
                ->count()
            : null;
        $expiringContracts = $canOrders
            ? DB::table('enterprise_procurement_contracts')
                ->where('organization_id', $scope->organizationId)
                ->where('company_id', $scope->companyId)
                ->where('store_id', $storeId)
                ->where('status', 'active')
                ->whereBetween('ends_on', [now()->toDateString(), now()->addDays($contractWarningDays)->toDateString()])
                ->count()
            : null;
        $activeRfqs = $canRequests
            ? DB::table('enterprise_procurement_rfqs')
                ->where('organization_id', $scope->organizationId)
                ->where('company_id', $scope->companyId)
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

        $spendTrend = $canInvoices ? $this->spendTrend($storeId, $from, $to, $scope->organizationId, $scope->companyId) : [];
        $spendBySupplier = $canInvoices ? $this->spendBySupplier($storeId, $from, $to, $scope->organizationId, $scope->companyId) : [];
        $spendByCategory = $canInvoices ? $this->spendByCategory($storeId, $from, $to, $scope->organizationId, $scope->companyId) : [];
        $poStatuses = $canOrders ? $this->purchaseOrderStatusChart($storeId, $scope->organizationId, $scope->companyId) : [];
        $poAging = $canOrders ? $this->purchaseOrderAgingChart($storeId, $scope->organizationId, $scope->companyId) : [];
        $supplierPerformance = $canOrders ? $this->supplierPerformance($storeId, $from, $to, $scope->organizationId, $scope->companyId) : [];
        $savings = $canRequests ? $this->savingsChart($storeId, $from, $to, $scope->organizationId, $scope->companyId) : [];
        $contractHorizon = $canOrders ? $this->contractExpiryHorizon($storeId, $scope->organizationId, $scope->companyId) : [];

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
                $scope->organizationId,
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
        // Treat operator input as literal text. Only the explicit outer pattern
        // wildcards are intentional; never broaden a scoped query by stripping %/_!
        // or interpreting those characters as SQL LIKE operators.
        $term = trim((string) ($validated['q'] ?? ''));
        $literal = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $term);
        $prefix = $literal.'%';
        $contains = '%'.$literal.'%';

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
                fn ($query) => $query->where(function ($search) use ($prefix, $contains): void {
                    $search->whereRaw("rfqs.rfq_number LIKE ? ESCAPE '!'", [$prefix])
                        ->orWhereRaw("rfqs.title LIKE ? ESCAPE '!'", [$contains])
                        ->orWhereRaw("requests.request_number LIKE ? ESCAPE '!'", [$prefix]);
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
            // The module-enabled preflight may become stale before the draft
            // RFQ/lines/audit are written. Share lifecycle's row lock before
            // locking the scoped purchase request and creating an RFQ.
            $module = DB::table('enterprise_modules')
                ->where('code', 'procurement')
                ->lockForUpdate()
                ->first();
            abort_unless(
                $module !== null && $module->state === ModuleState::ENABLED->value,
                404,
                'Procurement module is disabled.',
            );

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
                // Hold the scoped agreement across validity validation and draft
                // creation. Concurrent termination must commit entirely
                // before this check or after the new RFQ is persisted.
                $contract = $this->visibleContract($scope, (int) $data['contract_id'], true);
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
            // Scope authorization is a preflight, not a durable grant. Serialize
            // the final RFQ publication with ModuleLifecycleManager shutdown
            // before taking RFQ and supplier locks or issuing bearer tokens.
            $module = DB::table('enterprise_modules')
                ->where('code', 'procurement')
                ->lockForUpdate()
                ->first();
            abort_unless(
                $module !== null && $module->state === ModuleState::ENABLED->value,
                404,
                'Procurement module is disabled.',
            );

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
                // Lock actual eligible rows through invitation insert + RFQ
                // publication. A supplier hold, qualification or scope move
                // must commit either before this check or after publication.
                // Stable ID order avoids inconsistent multiple-supplier locks.
                ->orderBy('id')
                ->lockForUpdate()
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
            // Cancellation and invitation revocation must be serialized with
            // operator module suspension, just like publish/award and public
            // supplier GET/POST. Preflight permission is not a durable grant.
            $module = DB::table('enterprise_modules')
                ->where('code', 'procurement')
                ->lockForUpdate()
                ->first();
            abort_unless(
                $module !== null && $module->state === ModuleState::ENABLED->value,
                404,
                'Procurement module is disabled.',
            );

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
            // A successful preflight does not authorize awarding after an
            // operator has committed Procurement SUSPENDED. Lock the exact
            // lifecycle row BEFORE RFQ/quote, matching supplier GET/POST and
            // ModuleLifecycleManager shutdown serialization order.
            $module = DB::table('enterprise_modules')
                ->where('code', 'procurement')
                ->lockForUpdate()
                ->first();
            abort_unless(
                $module !== null && $module->state === ModuleState::ENABLED->value,
                404,
                'Procurement module is disabled.',
            );

            $rfq = $this->visibleRfq($scope, $rfqId, true);
            if ((int) $rfq->created_by === (int) $actor->id) {
                throw ValidationException::withMessages([
                    'decision' => [__('enterprise_procurement.errors.self_award')],
                ]);
            }

            if ($rfq->status === 'awarded') {
                // An idempotent retry must reference the original winning quote.
                // A different quote ID is not an authorized re-award.
                if ((int) $rfq->awarded_quote_id !== $quoteId) {
                    throw ValidationException::withMessages([
                        'quote_id' => [__('enterprise_procurement.errors.quote_source_invalid')],
                    ]);
                }

                // Awarded state alone is not proof of a valid scoped purchase
                // order. Never report a synthetic order ID or cross-scope PO.
                $existingOrder = DB::table('enterprise_purchase_orders')
                    ->where('procurement_rfq_id', $rfq->id)
                    ->where('organization_id', $scope->organizationId)
                    ->where('company_id', $scope->companyId)
                    ->where('store_id', $scope->storeId)
                    ->where('purchase_request_id', $rfq->purchase_request_id)
                    ->where('awarded_quote_id', $quoteId)
                    ->first();

                abort_if($existingOrder === null, 409);

                return $existingOrder;
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

            // A published invitation is not permanent authority to procure.
            // Revalidate its supplier, quoted amount and expiry under the
            // locked RFQ before changing the approved request or creating a PO.
            $invitation = DB::table('enterprise_procurement_rfq_invitations')
                ->where('id', $quote->invitation_id)
                ->where('rfq_id', $rfq->id)
                ->where('supplier_id', $quote->supplier_id)
                ->lockForUpdate()
                ->first();
            // An authentic submitted quote is not independent approval after
            // its invitation was revoked, expired or otherwise invalidated.
            // Lock the invitation until award commits, so operator changes
            // cannot race a stale provenance check into a purchase order.
            if ($invitation === null || $invitation->status !== 'submitted') {
                throw ValidationException::withMessages([
                    'quote_id' => [__('enterprise_procurement.errors.quote_source_invalid')],
                ]);
            }
            if ($quote->valid_until !== null
                && CarbonImmutable::parse((string) $quote->valid_until)->startOfDay()->isBefore(CarbonImmutable::today())) {
                throw ValidationException::withMessages([
                    'quote_id' => [__('enterprise_procurement.errors.quote_expired')],
                ]);
            }
            // An operator supplier hold, disqualification or scope move must not
            // commit between eligibility validation and the awarded PO write.
            // Preserve the canonical company/store scope while holding the row
            // until the award transaction either commits or rolls back.
            $supplier = $this->visibleSupplier($scope, (int) $quote->supplier_id, true);
            if ($supplier->status !== 'active' || $supplier->qualification_status === 'unqualified') {
                throw ValidationException::withMessages([
                    'supplier_id' => [__('enterprise_procurement.errors.quote_supplier_ineligible')],
                ]);
            }

            $rfqLines = DB::table('enterprise_procurement_rfq_lines')
                ->where('rfq_id', $rfq->id)
                ->get(['id', 'quantity'])
                ->keyBy('id');
            $submittedLines = DB::table('enterprise_procurement_quote_lines')
                ->where('quote_id', $quote->id)
                ->get(['rfq_line_id', 'quantity', 'unit_price', 'line_total']);
            $totalMills = 0;
            $validLines = $rfqLines->isNotEmpty() && $submittedLines->count() === $rfqLines->count();
            foreach ($submittedLines as $line) {
                $sourceLine = $rfqLines->get((int) $line->rfq_line_id);
                if ($sourceLine === null
                    || (float) $line->unit_price < 0
                    || (int) round((float) $line->quantity * 1000) !== (int) round((float) $sourceLine->quantity * 1000)
                    || (int) round((float) $line->line_total * 1000) !== (int) round((float) $line->quantity * (float) $line->unit_price * 1000)) {
                    $validLines = false;

                    break;
                }
                $totalMills += (int) round((float) $line->line_total * 1000);
            }
            if (! $validLines || $quote->currency_code !== $rfq->currency_code
                || (int) round((float) $quote->total_amount * 1000) !== $totalMills) {
                throw ValidationException::withMessages([
                    'quote_id' => [__('enterprise_procurement.errors.quote_source_invalid')],
                ]);
            }

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
            if (
                (int) $request->created_by === (int) $actor->id
                || (int) $request->submitted_by === (int) $actor->id
            ) {
                throw ValidationException::withMessages([
                    'decision' => [__('enterprise_procurement.errors.self_award')],
                ]);
            }

            if ($request->status !== 'approved') {
                throw ValidationException::withMessages([
                    'purchase_request_id' => ['Purchase request must be approved before RFQ award creates a purchase order.'],
                ]);
            }

            // The locked request may already have a purchase order created
            // through the direct procurement workflow. Never reassign its
            // supplier, RFQ, contract or priced lines during a later award,
            // especially after an order was issued or received.
            abort_if(
                DB::table('enterprise_purchase_orders')
                    ->where('purchase_request_id', $request->id)
                    ->exists(),
                409,
            );

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
        // Deny anonymous bearer-token reads before touching state when the
        // module is already disabled. A later transactional lock is still
        // mandatory because this preflight can become stale.
        abort_unless($this->modules->isEnabled('procurement'), 404, 'Procurement module is disabled.');

        $invitation = $this->portalInvitation($token);
        $rfq = DB::table('enterprise_procurement_rfqs')
            ->where('id', $invitation->rfq_id)
            ->firstOrFail();
        abort_if($rfq->status !== 'published', 410);

        // Keep the existing anonymous public GET preflight, including the
        // second token-hash read exercised by HTTP stale-snapshot regressions.
        // None of these unaudited reads grants final authority.
        $this->eligibleInvitedSupplier($rfq, $invitation);
        $invitation = $this->portalInvitation($token);

        // Serialize public GET authorization, its "opened" side effect and
        // the quote/document snapshot against supplier POST, RFQ cancellation,
        // invitation revocation and module shutdown. Match POST's exact lock
        // order: module -> RFQ -> invitation -> supplier -> quote. Committed
        // governance changes win before a blocked GET; GET-first snapshots
        // complete before the competing governance transaction can commit.
        return DB::transaction(function () use ($token, $rfq, $invitation): array {
            $module = DB::table('enterprise_modules')
                ->where('code', 'procurement')
                ->lockForUpdate()
                ->first();
            abort_unless(
                $module !== null && $module->state === ModuleState::ENABLED->value,
                404,
                'Procurement module is disabled.',
            );

            $rfq = DB::table('enterprise_procurement_rfqs')
                ->where('id', $rfq->id)
                ->lockForUpdate()
                ->first();
            abort_if($rfq === null || $rfq->status !== 'published', 410);

            // The second preflight token SELECT may have gone stale. Recheck
            // status, expiry and token ownership under the invitation row lock,
            // without creating a third preflight token-hash SELECT.
            $invitation = DB::table('enterprise_procurement_rfq_invitations')
                ->where('id', $invitation->id)
                ->where('rfq_id', $rfq->id)
                ->lockForUpdate()
                ->first();
            abort_if(
                $invitation === null
                    || ! hash_equals((string) $invitation->token_hash, hash('sha256', $token)),
                404,
            );
            abort_if(
                in_array($invitation->status, ['revoked', 'expired'], true)
                    || CarbonImmutable::parse((string) $invitation->expires_at)->isPast(),
                410,
            );

            $supplier = $this->eligibleInvitedSupplier($rfq, $invitation, true);

            $lines = DB::table('enterprise_procurement_rfq_lines')
                ->where('rfq_id', $rfq->id)
                ->orderBy('line_no')
                ->get();
            $existingQuote = DB::table('enterprise_procurement_quotes')
                ->where('invitation_id', $invitation->id)
                ->lockForUpdate()
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

            // Only a fully authorized, completely read public GET may record
            // an invitation opening. The locked invitation cannot be revoked
            // or resurrected by another database session while this runs.
            if (in_array($invitation->status, ['invited', 'opened'], true)) {
                DB::table('enterprise_procurement_rfq_invitations')
                    ->where('id', $invitation->id)
                    ->update([
                        'status' => 'opened',
                        'last_accessed_at' => now(),
                        'updated_at' => now(),
                    ]);
                $invitation = DB::table('enterprise_procurement_rfq_invitations')
                    ->where('id', $invitation->id)
                    ->firstOrFail();
            }

            return compact(
                'invitation',
                'rfq',
                'lines',
                'supplier',
                'existingQuote',
                'existingLines',
                'quoteDocuments',
            );
        }, 3);
    }

    /** @param array<string,mixed> $attributes */
    public function submitPortalQuote(
        string $token,
        array $attributes,
        ?UploadedFile $attachment = null,
    ): object {
        // Reject public mutations before any invitation expiry touch, quote,
        // document upload or audit write if Procurement is no longer enabled.
        abort_unless($this->modules->isEnabled('procurement'), 404, 'Procurement module is disabled.');

        $invitation = $this->portalInvitation($token);
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
            // KWD and ERP amounts are persisted at 3dp; reject silent price rounding.
            'lines.*.unit_price' => ['required', 'numeric', 'decimal:0,3', 'min:0', 'max:999999999'],
            'lines.*.lead_time_days' => ['nullable', 'integer', 'min:0', 'max:3650'],
        ])->validate();

        // Database rollback does not roll back local filesystem writes.
        // Track ALL files written across transaction retries, so a later audit
        // failure or commit error cannot leave an orphaned supplier attachment.
        $writtenPaths = [];
        try {
            $quote = DB::transaction(function () use ($token, $invitation, $rfq, $data, $attachment, &$writtenPaths): object {
                // The public preflight check can become stale between HTTP
                // validation and this transaction. Serialize the final write
                // against ModuleLifecycleManager::transition, which locks the
                // same enterprise_modules row. Never create a supplier quote
                // after a completed module suspension or shutdown.
                $module = DB::table('enterprise_modules')
                    ->where('code', 'procurement')
                    ->lockForUpdate()
                    ->first();
                abort_unless(
                    $module !== null && $module->state === ModuleState::ENABLED->value,
                    404,
                    'Procurement module is disabled.',
                );

                // Lock the RFQ BEFORE its invitation and quote, matching the award
                // and cancellation lock order. A pre-transaction read cannot
                // authorize a supplier write after an RFQ was cancelled/awarded.
                $rfq = DB::table('enterprise_procurement_rfqs')
                    ->where('id', $rfq->id)
                    ->lockForUpdate()
                    ->firstOrFail();
                abort_if($rfq->status !== 'published', 410);

                // The public invitation may have been revoked or expired after
                // the initial token check. Revalidate its original token/scope
                // under the same transaction, before deleting any old quote lines.
                $invitation = DB::table('enterprise_procurement_rfq_invitations')
                    ->where('id', $invitation->id)
                    ->where('rfq_id', $rfq->id)
                    ->where('token_hash', hash('sha256', $token))
                    ->lockForUpdate()
                    ->firstOrFail();
                abort_if(
                    in_array($invitation->status, ['revoked', 'expired'], true)
                        || CarbonImmutable::parse((string) $invitation->expires_at)->isPast(),
                    410,
                );

                // Revalidate current supplier governance and original tenant
                // scope under lock before quote/documents/audit mutations.
                $this->eligibleInvitedSupplier($rfq, $invitation, true);

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

                $totalMills = 0;
                foreach ($data['lines'] as $line) {
                    $rfqLine = $rfqLines->get((int) $line['rfq_line_id']);

                    // Both operands are validated or stored at <= 3dp. Scaling
                    // independently fits 64-bit integers; splitting quantity
                    // prevents overflowing multiplication or lossy float totals.
                    $quantityMills = (int) round((float) $rfqLine->quantity * 1000);
                    $priceMills = (int) round((float) $line['unit_price'] * 1000);
                    $wholeQuantity = intdiv($quantityMills, 1000);
                    if ($wholeQuantity > 0
                        && $priceMills > intdiv(self::MAX_QUOTE_AMOUNT_MILLS, $wholeQuantity)) {
                        throw ValidationException::withMessages([
                            'lines' => ['Quoted amount exceeds the supported 18,3 monetary range.'],
                        ]);
                    }
                    $wholeMills = $wholeQuantity * $priceMills;
                    $fractionMills = intdiv(($quantityMills % 1000) * $priceMills + 500, 1000);
                    $lineMills = $wholeMills + $fractionMills;
                    if ($lineMills > self::MAX_QUOTE_AMOUNT_MILLS - $totalMills) {
                        throw ValidationException::withMessages([
                            'lines' => ['Quoted amount exceeds the supported 18,3 monetary range.'],
                        ]);
                    }
                    $totalMills += $lineMills;
                    $lineTotal = intdiv($lineMills, 1000).'.'.str_pad((string) ($lineMills % 1000), 3, '0', STR_PAD_LEFT);

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
                    'total_amount' => intdiv($totalMills, 1000).'.'.str_pad((string) ($totalMills % 1000), 3, '0', STR_PAD_LEFT),
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

                    $writtenPaths[] = $stored;
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
        } catch (\Throwable $exception) {
            // Includes failures after document INSERT (audit, transaction
            // commit, retries); only compensate files written by this call.
            if ($writtenPaths !== []) {
                Storage::disk('local')->delete($writtenPaths);
            }

            throw $exception;
        }

        // A deadlock retry may have written an attachment on an earlier
        // rolled-back attempt. Only the final successful path is referenced.
        if (count($writtenPaths) > 1) {
            Storage::disk('local')->delete(array_slice($writtenPaths, 0, -1));
        }

        return $quote;
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
        // Preserve the operator's literal contract/supplier search characters;
        // only our explicit prefix/substring anchors may act as LIKE wildcards.
        $term = trim((string) ($validated['q'] ?? ''));
        $literal = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $term);
        $prefix = $literal.'%';
        $contains = '%'.$literal.'%';

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
                fn ($query) => $query->where(function ($search) use ($prefix, $contains): void {
                    $search->whereRaw("contracts.contract_number LIKE ? ESCAPE '!'", [$prefix])
                        ->orWhereRaw("contracts.title LIKE ? ESCAPE '!'", [$contains])
                        ->orWhereRaw("suppliers.code LIKE ? ESCAPE '!'", [$prefix]);
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

        // The same record-level SoD used by AP posting and Treasury payment
        // release applies to the supplier agreement itself. Serialize module
        // lifecycle before locking the agreement, matching RFQ transitions.
        DB::transaction(function () use ($actor, $scope, $contractId): void {
            $module = DB::table('enterprise_modules')
                ->where('code', 'procurement')
                ->lockForUpdate()
                ->first();
            abort_unless(
                $module !== null && $module->state === ModuleState::ENABLED->value,
                404,
                'Procurement module is disabled.',
            );

            $contract = $this->visibleContract($scope, $contractId, true);
            if ($contract->status === 'active') {
                return;
            }
            if ($contract->status !== 'draft') {
                throw ValidationException::withMessages(['status' => ['Only draft contracts can be activated.']]);
            }
            if ((int) $contract->created_by === (int) $actor->id) {
                throw ValidationException::withMessages([
                    'approver' => [__('enterprise_procurement.errors.contract_self_approval')],
                ]);
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
        }, 3);
    }

    public function terminateContract(
        User $actor,
        ResolvedTenantContext $context,
        int $contractId,
    ): void {
        $scope = $this->scope($actor, $context, 'procurement.purchase_orders.manage');

        // A locking SELECT outside a transaction releases its row lock before
        // the subsequent UPDATE in MySQL autocommit mode. Hold module and
        // contract locks through termination + audit in one real transaction.
        DB::transaction(function () use ($actor, $scope, $contractId): void {
            $module = DB::table('enterprise_modules')
                ->where('code', 'procurement')
                ->lockForUpdate()
                ->first();
            abort_unless(
                $module !== null && $module->state === ModuleState::ENABLED->value,
                404,
                'Procurement module is disabled.',
            );

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
        }, 3);
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
            // A previous human approval applies only to the reviewed
            // variance/tolerance snapshot. Imports or corrections to a draft
            // invoice must never reuse that approval against changed figures.
            $reviewChanged = $status === 'approved' && (
                (float) $existing->quantity_variance !== $quantityVariance
                || (float) $existing->value_variance !== $valueVariance
                || (float) $existing->quantity_tolerance !== $quantityTolerance
                || (float) $existing->value_tolerance !== $valueTolerance
            );
            if ($reviewChanged) {
                $status = 'pending';
            } elseif ($status === null || in_array($status, ['auto_approved', 'pending'], true)) {
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
                    'review_note' => $reviewChanged ? null : $existing?->review_note,
                    'reviewed_by' => $reviewChanged ? null : $existing?->reviewed_by,
                    'reviewed_at' => $reviewChanged ? null : $existing?->reviewed_at,
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
        // Procurement does not own Finance/AP posting. Before it publishes the
        // immutable handoff event, the invoice must still match its posted
        // receipt and its own persisted line-level monetary snapshot.
        $source = DB::table('enterprise_purchase_invoices as invoices')
            ->join('enterprise_goods_receipts as receipts', 'receipts.id', '=', 'invoices.goods_receipt_id')
            ->where('invoices.id', $invoiceId)
            ->first([
                'invoices.total_amount',
                'invoices.goods_receipt_id',
                'invoices.source_receipt_number',
                'invoices.source_order_number',
                'invoices.supplier_id',
                'invoices.currency_code',
                'invoices.organization_id',
                'invoices.company_id',
                'invoices.store_id',
                'receipts.receipt_number',
                'receipts.source_order_number as receipt_order_number',
                'receipts.supplier_id as receipt_supplier_id',
                'receipts.currency_code as receipt_currency_code',
                'receipts.organization_id as receipt_organization_id',
                'receipts.company_id as receipt_company_id',
                'receipts.store_id as receipt_store_id',
                'receipts.status as receipt_status',
            ]);

        $invalid = $source === null
            || $source->receipt_status !== 'posted'
            || (string) $source->source_receipt_number !== (string) $source->receipt_number
            || (string) $source->source_order_number !== (string) $source->receipt_order_number
            || (int) $source->supplier_id !== (int) $source->receipt_supplier_id
            || (string) $source->currency_code !== (string) $source->receipt_currency_code
            || (int) $source->organization_id !== (int) $source->receipt_organization_id
            || (int) $source->company_id !== (int) $source->receipt_company_id
            || (int) $source->store_id !== (int) $source->receipt_store_id;

        if (! $invalid) {
            $lines = DB::table('enterprise_purchase_invoice_lines as invoice_lines')
                ->leftJoin('enterprise_goods_receipt_lines as receipt_lines', 'receipt_lines.id', '=', 'invoice_lines.goods_receipt_line_id')
                ->where('invoice_lines.purchase_invoice_id', $invoiceId)
                ->get([
                    'invoice_lines.invoice_quantity',
                    'invoice_lines.unit_price',
                    'invoice_lines.line_total',
                    'invoice_lines.purchase_order_line_id',
                    'receipt_lines.goods_receipt_id as source_receipt_id',
                    'receipt_lines.purchase_order_line_id as source_po_line_id',
                ]);

            $invalid = $lines->isEmpty();
            $lineTotal = 0;
            foreach ($lines as $line) {
                $quantity = (float) $line->invoice_quantity;
                $price = (float) $line->unit_price;
                $amount = (float) $line->line_total;
                if (
                    $line->source_receipt_id === null
                    || (int) $line->source_receipt_id !== (int) $source->goods_receipt_id
                    || (int) $line->purchase_order_line_id !== (int) $line->source_po_line_id
                    || ! is_finite($quantity)
                    || ! is_finite($price)
                    || ! is_finite($amount)
                    || $quantity <= 0
                    || $price < 0
                    || (int) round($quantity * $price * 1000) !== (int) round($amount * 1000)
                ) {
                    $invalid = true;
                    break;
                }

                $lineTotal += (int) round($amount * 1000);
            }

            if (! is_finite((float) $source->total_amount)
                || (int) round((float) $source->total_amount * 1000) !== $lineTotal) {
                $invalid = true;
            }
        }

        if ($invalid) {
            throw ValidationException::withMessages([
                'three_way_match' => [__('enterprise_procurement.errors.invoice_line_source_changed')],
            ]);
        }

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

        return DB::transaction(function () use ($actor, $scope, $invoiceId, $data): object {
            // Match review and handoff lock the same invoice before the review
            // row. Never allow post-handoff edits to approval metadata or
            // race a concurrent immutable Finance outbox publication.
            $invoice = DB::table('enterprise_purchase_invoices')
                ->where('id', $invoiceId)
                ->where('organization_id', $scope->organizationId)
                ->where('company_id', $scope->companyId)
                ->where('store_id', $scope->storeId)
                ->lockForUpdate()
                ->first();
            abort_if($invoice === null, 404);

            if ($invoice->status !== 'draft') {
                throw ValidationException::withMessages([
                    'status' => [__('enterprise_procurement.errors.invoice_handed_off_immutable')],
                ]);
            }

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
        }, 3);
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
        $term = trim((string) ($validated['q'] ?? ''));
        // Preserve literal operator text; only the intentional trailing %
        // anchors a prefix search against fixed, parameter-bound columns.
        $prefix = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $term).'%';

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
                fn ($query) => $query->where(function ($search) use ($prefix): void {
                    $search->whereRaw("invoices.invoice_number LIKE ? ESCAPE '!'", [$prefix])
                        ->orWhereRaw("invoices.supplier_invoice_number LIKE ? ESCAPE '!'", [$prefix])
                        ->orWhereRaw("invoices.supplier_code LIKE ? ESCAPE '!'", [$prefix]);
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
        // Reading supplier statements is necessary to enter the Reports tab,
        // but it does not grant access to purchase orders, invoices or RFQs.
        // Apply each report's canonical document permission at the service
        // boundary too, so direct calls and CSV exports cannot bypass it.
        $scope = $this->scope($actor, $context, 'procurement.supplier_statements.view');
        $documentPermission = match ($report) {
            'open_orders', 'supplier_performance', 'contract_expiry' => 'procurement.purchase_orders.view',
            'spend', 'match_exceptions' => 'procurement.purchase_invoices.view',
            'savings' => 'procurement.purchase_requests.view',
            default => throw ValidationException::withMessages([
                'report' => ['Unknown procurement report.'],
            ]),
        };
        $this->authorizer->authorize(
            $actor,
            $context,
            $documentPermission,
            'company',
            $scope->companyId,
        );

        return $this->reportForStore($report, $scope->storeId, $filters, $limit, 0, $scope);
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
        ?EnterpriseDataScope $scope = null,
    ): array {
        // BI can call this bridge after its own permission gate, but store ID
        // alone is not the legal-entity boundary of any exported document.
        // Resolve the authoritative hierarchy before generating SQL.
        $hierarchy = app(OrganizationHierarchyService::class)->scopeForStore($storeId);
        abort_if($hierarchy === null, 404);
        if ($scope !== null) {
            abort_unless(
                $scope->storeId === $storeId
                && $scope->organizationId === $hierarchy['organization_id']
                && $scope->companyId === $hierarchy['company_id'],
                403,
            );
        }
        $organizationId = $hierarchy['organization_id'];
        $companyId = $hierarchy['company_id'];
        [$from, $to] = $this->dateWindow($filters['from'] ?? null, $filters['to'] ?? null);
        $limit = max(1, min(5000, $limit));
        $offset = max(0, $offset);

        return match ($report) {
            'open_orders' => $this->openOrdersReport($storeId, $limit, $offset, $organizationId, $companyId),
            'spend' => $this->spendReport($storeId, $from, $to, $limit, $offset, $organizationId, $companyId),
            'supplier_performance' => $this->supplierPerformanceReport($storeId, $from, $to, $limit, $offset, $organizationId, $companyId),
            'savings' => $this->savingsReport($storeId, $from, $to, $limit, $offset, $organizationId, $companyId),
            'contract_expiry' => $this->contractExpiryReport($storeId, $limit, $offset, $organizationId, $companyId),
            'match_exceptions' => $this->matchExceptionReport($storeId, $limit, $offset, $organizationId, $companyId),
            default => throw ValidationException::withMessages(['report' => ['Unknown procurement report.']]),
        };
    }

    /** @return list<array<string,mixed>> */
    private function exceptions(int $storeId, int $organizationId, int $companyId, bool $canOrders, bool $canInvoices, bool $canSuppliers): array
    {
        $exceptions = [];

        if ($canOrders) {
            $overdue = DB::table('enterprise_purchase_orders')
                ->where('organization_id', $organizationId)
                ->where('company_id', $companyId)
                ->where('store_id', $storeId)
                ->where('status', 'issued')
                ->whereNotNull('needed_by')
                ->whereDate('needed_by', '<', now()->toDateString())
                ->count();
            $receiptVariance = $this->orderedNotReceivedCount($storeId, $organizationId, $companyId);
            $expiring = DB::table('enterprise_procurement_contracts')
                ->where('organization_id', $organizationId)
                ->where('company_id', $companyId)
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
                ->where('invoices.organization_id', $organizationId)
                ->where('invoices.company_id', $companyId)
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
                ->where('invoices.organization_id', $organizationId)
                ->where('invoices.company_id', $companyId)
                ->where('invoices.store_id', $storeId)
                ->where('outbox.event_type', 'ProcurementPurchaseInvoiceHandedOff')
                ->where('outbox.status', 'failed')
                ->count();

            $exceptions[] = ['key' => 'match_variance', 'count' => $matchVariance, 'tab' => 'matches'];
            $exceptions[] = ['key' => 'finance_handoff_failed', 'count' => $financeFailed, 'tab' => 'invoices'];
        }

        if ($canSuppliers) {
            $blocked = DB::table('enterprise_suppliers')
                ->where('organization_id', $organizationId)
                ->where('company_id', $companyId)
                ->where('status', 'on_hold')
                ->where(function ($query) use ($storeId, $organizationId, $companyId): void {
                    $query->where('scope_type', 'company')
                        ->orWhere(function ($store) use ($storeId, $organizationId, $companyId): void {
                            $store->where('scope_type', 'store')->where('organization_id', $organizationId)
                                ->where('company_id', $companyId)
                                ->where('store_id', $storeId);
                        });
                })
                ->count();
            $exceptions[] = ['key' => 'blocked_suppliers', 'count' => $blocked, 'tab' => 'suppliers'];
        }

        return $exceptions;
    }

    private function orderedNotReceivedCount(int $storeId, int $organizationId, int $companyId): int
    {
        // Count each original PO line exactly once. Joining raw receipt rows
        // before SUM(order_lines.quantity) multiplies the ordered denominator
        // for partial deliveries and reports completed orders as outstanding.
        $lineBalances = DB::table('enterprise_purchase_order_lines as order_lines')
            ->leftJoin('enterprise_goods_receipt_lines as receipt_lines', 'receipt_lines.purchase_order_line_id', '=', 'order_lines.id')
            ->leftJoin('enterprise_goods_receipts as receipts', function ($join) use ($storeId, $organizationId, $companyId): void {
                $join->on('receipts.id', '=', 'receipt_lines.goods_receipt_id')
                    ->where('receipts.status', '=', 'posted')
                    ->where('receipts.organization_id', '=', $organizationId)
                    ->where('receipts.company_id', '=', $companyId)
                    ->where('receipts.store_id', '=', $storeId);
            })
            ->select(['order_lines.id', 'order_lines.purchase_order_id'])
            ->selectRaw('MAX(order_lines.quantity) as ordered_quantity')
            ->selectRaw('COALESCE(SUM(CASE WHEN receipts.id IS NOT NULL THEN receipt_lines.received_quantity ELSE 0 END), 0) as received_quantity')
            ->groupBy('order_lines.id', 'order_lines.purchase_order_id');

        $outstanding = DB::query()
            ->fromSub($lineBalances, 'line_balances')
            ->join('enterprise_purchase_orders as orders', 'orders.id', '=', 'line_balances.purchase_order_id')
            ->where('orders.organization_id', $organizationId)
            ->where('orders.company_id', $companyId)
            ->where('orders.store_id', $storeId)
            ->where('orders.status', 'issued')
            ->select('orders.id')
            ->groupBy('orders.id')
            ->havingRaw('SUM(line_balances.ordered_quantity) > SUM(line_balances.received_quantity)');

        return DB::query()->fromSub($outstanding, 'ordered_not_received')->count();
    }

    /** @return list<array<string,mixed>> */
    private function spendTrend(int $storeId, string $from, string $to, int $organizationId, int $companyId): array
    {
        return DB::table('enterprise_purchase_invoices')
            ->where('organization_id', $organizationId)
            ->where('company_id', $companyId)
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
    private function spendBySupplier(int $storeId, string $from, string $to, int $organizationId, int $companyId): array
    {
        return DB::table('enterprise_purchase_invoices')
            ->where('organization_id', $organizationId)
            ->where('company_id', $companyId)
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
    private function spendByCategory(int $storeId, string $from, string $to, int $organizationId, int $companyId): array
    {
        return DB::table('enterprise_purchase_invoices as invoices')
            ->join('enterprise_purchase_invoice_lines as invoice_lines', 'invoice_lines.purchase_invoice_id', '=', 'invoices.id')
            ->leftJoin('products', function ($join) use ($storeId): void {
                $join->on('products.sku', '=', 'invoice_lines.item_code')
                    ->whereIn('products.catalog_id', function ($catalogs) use ($storeId): void {
                        $catalogs->select('id')->from('catalogs')
                            ->where('store_id', $storeId)
                            ->where('is_active', true)
                            ->where('is_migration_quarantine', false);
                    });
            })
            ->leftJoin('categories', 'categories.id', '=', 'products.category_id')
            ->where('invoices.organization_id', $organizationId)
            ->where('invoices.company_id', $companyId)
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
    private function purchaseOrderStatusChart(int $storeId, int $organizationId, int $companyId): array
    {
        return DB::table('enterprise_purchase_orders')
            ->where('organization_id', $organizationId)
            ->where('company_id', $companyId)
            ->where('store_id', $storeId)
            ->groupBy('status')
            ->selectRaw('status as label, COUNT(*) as value')
            ->orderBy('status')
            ->get()
            ->map(fn ($row): array => ['label' => $row->label, 'value' => (int) $row->value])
            ->all();
    }

    /** @return list<array<string,mixed>> */
    private function purchaseOrderAgingChart(int $storeId, int $organizationId, int $companyId): array
    {
        $today = CarbonImmutable::today();
        $buckets = ['current' => 0, '1_7' => 0, '8_30' => 0, '31_60' => 0, '61_plus' => 0];

        DB::table('enterprise_purchase_orders')
            ->where('organization_id', $organizationId)
            ->where('company_id', $companyId)
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
    private function supplierPerformance(int $storeId, string $from, string $to, int $organizationId, int $companyId): array
    {
        return DB::table('enterprise_purchase_orders as orders')
            ->join('enterprise_goods_receipts as receipts', function ($join) use ($storeId, $organizationId, $companyId): void {
                $join->on('receipts.purchase_order_id', '=', 'orders.id')
                    ->where('receipts.organization_id', '=', $organizationId)
                    ->where('receipts.company_id', '=', $companyId)
                    ->where('receipts.store_id', '=', $storeId);
            })
            ->where('orders.organization_id', $organizationId)
            ->where('orders.company_id', $companyId)
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
    private function savingsChart(int $storeId, string $from, string $to, int $organizationId, int $companyId): array
    {
        return DB::table('enterprise_procurement_quotes as quotes')
            ->join('enterprise_procurement_rfqs as rfqs', 'rfqs.id', '=', 'quotes.rfq_id')
            ->join('enterprise_procurement_quote_lines as quote_lines', 'quote_lines.quote_id', '=', 'quotes.id')
            ->join('enterprise_procurement_rfq_lines as rfq_lines', 'rfq_lines.id', '=', 'quote_lines.rfq_line_id')
            ->where('rfqs.organization_id', $organizationId)
            ->where('rfqs.company_id', $companyId)
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
    private function contractExpiryHorizon(int $storeId, int $organizationId, int $companyId): array
    {
        $today = CarbonImmutable::today();

        return [
            ['label' => '0-30', 'value' => $this->contractCountBetween($storeId, $today, $today->addDays(30), $organizationId, $companyId)],
            ['label' => '31-60', 'value' => $this->contractCountBetween($storeId, $today->addDays(31), $today->addDays(60), $organizationId, $companyId)],
            ['label' => '61-90', 'value' => $this->contractCountBetween($storeId, $today->addDays(61), $today->addDays(90), $organizationId, $companyId)],
            ['label' => '90+', 'value' => DB::table('enterprise_procurement_contracts')
                ->where('organization_id', $organizationId)
                ->where('company_id', $companyId)
                ->where('store_id', $storeId)
                ->where('status', 'active')
                ->whereDate('ends_on', '>', $today->addDays(90)->toDateString())
                ->count()],
        ];
    }

    private function contractCountBetween(int $storeId, CarbonImmutable $from, CarbonImmutable $to, int $organizationId, int $companyId): int
    {
        return DB::table('enterprise_procurement_contracts')
            ->where('organization_id', $organizationId)
            ->where('company_id', $companyId)
            ->where('store_id', $storeId)
            ->where('status', 'active')
            ->whereBetween('ends_on', [$from->toDateString(), $to->toDateString()])
            ->count();
    }

    /** @return array{columns:list<string>,rows:list<array<string,mixed>>} */
    private function openOrdersReport(int $storeId, int $limit, int $offset, int $organizationId, int $companyId): array
    {
        $rows = DB::table('enterprise_purchase_orders as orders')
            ->leftJoin('enterprise_purchase_order_lines as lines', 'lines.purchase_order_id', '=', 'orders.id')
            ->where('orders.organization_id', $organizationId)
            ->where('orders.company_id', $companyId)
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
    private function spendReport(int $storeId, string $from, string $to, int $limit, int $offset, int $organizationId, int $companyId): array
    {
        $rows = DB::table('enterprise_purchase_invoices')
            ->where('organization_id', $organizationId)
            ->where('company_id', $companyId)
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
    private function supplierPerformanceReport(int $storeId, string $from, string $to, int $limit, int $offset, int $organizationId, int $companyId): array
    {
        $source = DB::table('enterprise_purchase_orders as orders')
            ->leftJoin('enterprise_goods_receipts as receipts', function ($join) use ($organizationId, $companyId, $storeId): void {
                $join->on('receipts.purchase_order_id', '=', 'orders.id')
                    ->where('receipts.status', '=', 'posted')
                    ->where('receipts.organization_id', '=', $organizationId)
                    ->where('receipts.company_id', '=', $companyId)
                    ->where('receipts.store_id', '=', $storeId);
            })
            ->where('orders.organization_id', $organizationId)
            ->where('orders.company_id', $companyId)
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
    private function savingsReport(int $storeId, string $from, string $to, int $limit, int $offset, int $organizationId, int $companyId): array
    {
        $rows = DB::table('enterprise_procurement_quotes as quotes')
            ->join('enterprise_procurement_rfqs as rfqs', 'rfqs.id', '=', 'quotes.rfq_id')
            ->join('enterprise_suppliers as suppliers', 'suppliers.id', '=', 'quotes.supplier_id')
            ->join('enterprise_procurement_quote_lines as quote_lines', 'quote_lines.quote_id', '=', 'quotes.id')
            ->join('enterprise_procurement_rfq_lines as rfq_lines', 'rfq_lines.id', '=', 'quote_lines.rfq_line_id')
            ->where('rfqs.organization_id', $organizationId)
            ->where('rfqs.company_id', $companyId)
            ->where('suppliers.organization_id', $organizationId)
            ->where('suppliers.company_id', $companyId)
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
    private function contractExpiryReport(int $storeId, int $limit, int $offset, int $organizationId, int $companyId): array
    {
        $rows = DB::table('enterprise_procurement_contracts as contracts')
            ->join('enterprise_suppliers as suppliers', 'suppliers.id', '=', 'contracts.supplier_id')
            ->where('contracts.organization_id', $organizationId)
            ->where('contracts.company_id', $companyId)
            ->where('suppliers.organization_id', $organizationId)
            ->where('suppliers.company_id', $companyId)
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
    private function matchExceptionReport(int $storeId, int $limit, int $offset, int $organizationId, int $companyId): array
    {
        $rows = DB::table('enterprise_purchase_invoices as invoices')
            ->join('enterprise_procurement_match_reviews as matches', 'matches.purchase_invoice_id', '=', 'invoices.id')
            ->where('invoices.organization_id', $organizationId)
            ->where('invoices.company_id', $companyId)
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

    private function portalInvitation(string $token): object
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
            // An expired-token read must not overwrite a concurrent admin
            // revocation with an "expired" status.
            DB::table('enterprise_procurement_rfq_invitations')
                ->where('id', $invitation->id)
                ->whereNotIn('status', ['revoked', 'expired'])
                ->where('expires_at', '<=', now())
                ->update(['status' => 'expired', 'updated_at' => now()]);
            abort(410);
        }

        return $invitation;
    }

    /**
     * A supplier invitation is not independent authority to access an RFQ
     * after the supplier is blocked, disqualified or removed from its scope.
     */
    private function eligibleInvitedSupplier(object $rfq, object $invitation, bool $lock = false): object
    {
        $query = DB::table('enterprise_suppliers')
            ->where('id', $invitation->supplier_id)
            ->where('organization_id', $rfq->organization_id)
            ->where('company_id', $rfq->company_id)
            ->where('status', 'active')
            ->where('qualification_status', '!=', 'unqualified')
            ->where(function ($query) use ($rfq): void {
                $query->where('scope_type', 'company')
                    ->orWhere(function ($store) use ($rfq): void {
                        $store->where('scope_type', 'store')
                            ->where('store_id', $rfq->store_id);
                    });
            });

        if ($lock) {
            $query->lockForUpdate();
        }

        $supplier = $query->first();
        abort_if($supplier === null, 410);

        return $supplier;
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

    private function visibleSupplier(EnterpriseDataScope $scope, int $supplierId, bool $lock = false): EnterpriseSupplier
    {
        $query = EnterpriseSupplier::query()
            ->where('id', $supplierId)
            ->where('organization_id', $scope->organizationId)
            ->where('company_id', $scope->companyId)
            ->where(function ($query) use ($scope): void {
                $query->where('scope_type', 'company')
                    ->orWhere(function ($store) use ($scope): void {
                        $store->where('scope_type', 'store')->where('store_id', $scope->storeId);
                    });
            });

        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->firstOrFail();
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
