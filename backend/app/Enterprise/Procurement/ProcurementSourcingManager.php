<?php

namespace App\Enterprise\Procurement;

use App\Enterprise\Modules\ModuleRegistry;
use App\Models\EnterpriseProcurementContract;
use App\Models\EnterpriseProcurementQuote;
use App\Models\EnterpriseProcurementRfq;
use App\Models\EnterprisePurchaseOrder;
use App\Models\EnterprisePurchaseRequest;
use App\Models\EnterpriseSupplier;
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\EnterpriseDataScope;
use App\Support\EnterpriseScopeAuthorizer;
use App\Support\ResolvedTenantContext;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class ProcurementSourcingManager
{
    private const RFQ_VIEW = 'procurement.purchase_requests.view';

    private const RFQ_MANAGE = 'procurement.purchase_requests.manage';

    private const RFQ_AWARD = 'procurement.purchase_orders.manage';

    private const CONTRACT_VIEW = 'procurement.suppliers.view';

    private const CONTRACT_MANAGE = 'procurement.suppliers.manage';

    public function __construct(
        private readonly EnterpriseScopeAuthorizer $authorizer,
        private readonly ModuleRegistry $modules,
        private readonly AuditLogger $audit,
        private readonly PurchaseOrderManager $orders,
    ) {}

    /** @return LengthAwarePaginator<int, object> */
    public function paginateRfqs(
        User $actor,
        ResolvedTenantContext $context,
        array $filters = [],
        int $perPage = 25,
    ): LengthAwarePaginator {
        $scope = $this->authorizedScope($actor, $context, self::RFQ_VIEW);
        $validated = Validator::make($filters, [
            'status' => ['nullable', Rule::in(['draft', 'published', 'evaluating', 'awarded', 'cancelled'])],
            'supplier_id' => ['nullable', 'integer', 'min:1'],
        ])->validate();

        return DB::table('enterprise_procurement_rfqs as rfq')
            ->leftJoin('enterprise_procurement_quotes as awarded', 'awarded.id', '=', 'rfq.awarded_quote_id')
            ->leftJoin('enterprise_suppliers as supplier', 'supplier.id', '=', 'awarded.supplier_id')
            ->where('rfq.organization_id', $scope->organizationId)
            ->where('rfq.company_id', $scope->companyId)
            ->where('rfq.store_id', $scope->storeId)
            ->when($validated['status'] ?? null, fn ($query, string $status) => $query->where('rfq.status', $status))
            ->when($validated['supplier_id'] ?? null, function ($query, mixed $supplierId): void {
                $query->whereExists(function ($sub) use ($supplierId): void {
                    $sub->selectRaw('1')
                        ->from('enterprise_procurement_rfq_suppliers as invited')
                        ->whereColumn('invited.rfq_id', 'rfq.id')
                        ->where('invited.supplier_id', (int) $supplierId);
                });
            })
            ->select([
                'rfq.id',
                'rfq.rfq_number',
                'rfq.purchase_request_id',
                'rfq.title',
                'rfq.currency_code',
                'rfq.status',
                'rfq.closes_at',
                'rfq.published_at',
                'rfq.awarded_quote_id',
                'supplier.code as awarded_supplier_code',
                'awarded.total_amount as awarded_total_amount',
            ])
            ->orderByDesc('rfq.id')
            ->paginate(max(10, min(100, $perPage)))
            ->withQueryString();
    }

    /** @return array<string,mixed> */
    public function rfqDetail(
        User $actor,
        ResolvedTenantContext $context,
        int $rfqId,
    ): array {
        $scope = $this->authorizedScope($actor, $context, self::RFQ_VIEW);
        $rfq = $this->visibleRfq($scope, $rfqId);

        $lines = DB::table('enterprise_procurement_rfq_lines')
            ->where('rfq_id', $rfq->id)
            ->orderBy('line_no')
            ->get();

        $suppliers = DB::table('enterprise_procurement_rfq_suppliers as invited')
            ->join('enterprise_suppliers as supplier', 'supplier.id', '=', 'invited.supplier_id')
            ->where('invited.rfq_id', $rfq->id)
            ->orderBy('supplier.name_en')
            ->get([
                'invited.supplier_id',
                'invited.status',
                'invited.invited_at',
                'invited.responded_at',
                'invited.declined_at',
                'supplier.code',
                'supplier.name_en',
                'supplier.name_ar',
            ]);

        $quotes = DB::table('enterprise_procurement_quotes as quote')
            ->join('enterprise_suppliers as supplier', 'supplier.id', '=', 'quote.supplier_id')
            ->where('quote.rfq_id', $rfq->id)
            ->orderBy('quote.total_amount')
            ->orderBy('quote.id')
            ->get([
                'quote.id',
                'quote.quote_number',
                'quote.supplier_id',
                'quote.supplier_reference',
                'quote.status',
                'quote.total_amount',
                'quote.lead_time_days',
                'quote.valid_until',
                'quote.submitted_at',
                'supplier.code as supplier_code',
                'supplier.name_en as supplier_name_en',
                'supplier.name_ar as supplier_name_ar',
            ]);

        $quoteLines = DB::table('enterprise_procurement_quote_lines as quote_line')
            ->join('enterprise_procurement_rfq_lines as rfq_line', 'rfq_line.id', '=', 'quote_line.rfq_line_id')
            ->whereIn('quote_line.quote_id', $quotes->pluck('id')->all())
            ->orderBy('quote_line.quote_id')
            ->orderBy('quote_line.line_no')
            ->get([
                'quote_line.quote_id',
                'quote_line.rfq_line_id',
                'quote_line.line_no',
                'quote_line.quantity',
                'quote_line.unit_price',
                'quote_line.line_total',
                'rfq_line.description',
                'rfq_line.unit',
            ])
            ->groupBy('quote_id');

        return [
            'rfq' => $rfq,
            'lines' => $lines,
            'suppliers' => $suppliers,
            'quotes' => $quotes,
            'quoteLines' => $quoteLines,
        ];
    }

    /** @param array<string,mixed> $attributes */
    public function createRfq(
        User $actor,
        ResolvedTenantContext $context,
        array $attributes,
    ): EnterpriseProcurementRfq {
        $scope = $this->authorizedScope($actor, $context, self::RFQ_MANAGE);
        $data = Validator::make($attributes, [
            'purchase_request_id' => ['required', 'integer', 'min:1'],
            'title' => ['required', 'string', 'max:190'],
            'closes_at' => ['nullable', 'date', 'after:now'],
            'notes' => ['nullable', 'string', 'max:4000'],
            'supplier_ids' => ['required', 'array', 'min:1', 'max:100'],
            'supplier_ids.*' => ['integer', 'min:1', 'distinct'],
        ])->validate();

        return DB::transaction(function () use ($actor, $scope, $data): EnterpriseProcurementRfq {
            $purchaseRequest = EnterprisePurchaseRequest::query()
                ->where('organization_id', $scope->organizationId)
                ->where('company_id', $scope->companyId)
                ->where('store_id', $scope->storeId)
                ->whereKey((int) $data['purchase_request_id'])
                ->lockForUpdate()
                ->firstOrFail();

            if ($purchaseRequest->status !== 'approved') {
                throw ValidationException::withMessages([
                    'purchase_request_id' => ['Only an approved purchase request can source an RFQ.'],
                ]);
            }

            if (
                EnterpriseProcurementRfq::query()
                    ->where('purchase_request_id', $purchaseRequest->id)
                    ->whereNotIn('status', ['cancelled'])
                    ->exists()
            ) {
                throw ValidationException::withMessages([
                    'purchase_request_id' => ['This purchase request already has an active RFQ.'],
                ]);
            }

            $supplierIds = EnterpriseSupplier::query()
                ->where('organization_id', $scope->organizationId)
                ->where('company_id', $scope->companyId)
                ->whereIn('id', $data['supplier_ids'])
                ->where('status', 'active')
                ->where(function ($query) use ($scope): void {
                    $query->where('scope_type', 'company')
                        ->orWhere(fn ($store) => $store
                            ->where('scope_type', 'store')
                            ->where('store_id', $scope->storeId));
                })
                ->pluck('id')
                ->map(static fn ($id): int => (int) $id)
                ->all();

            if (count($supplierIds) !== count(array_unique($data['supplier_ids']))) {
                throw ValidationException::withMessages([
                    'supplier_ids' => ['Every invited supplier must be active and visible in the selected scope.'],
                ]);
            }

            $requestLines = $purchaseRequest->lines()->orderBy('line_no')->get();
            abort_if($requestLines->isEmpty(), 409, 'The approved purchase request has no lines.');

            $publicId = (string) Str::uuid();
            $rfq = EnterpriseProcurementRfq::query()->create([
                'public_id' => $publicId,
                'rfq_number' => 'RFQ-'.strtoupper(substr(str_replace('-', '', $publicId), 0, 11)),
                'organization_id' => $scope->organizationId,
                'company_id' => $scope->companyId,
                'store_id' => $scope->storeId,
                'purchase_request_id' => $purchaseRequest->id,
                'title' => $data['title'],
                'currency_code' => $purchaseRequest->currency_code,
                'status' => 'draft',
                'closes_at' => $data['closes_at'] ?? null,
                'notes' => $data['notes'] ?? null,
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
            ]);

            foreach ($requestLines as $line) {
                DB::table('enterprise_procurement_rfq_lines')->insert([
                    'rfq_id' => $rfq->id,
                    'purchase_request_line_id' => $line->id,
                    'line_no' => $line->line_no,
                    'item_code' => $line->item_code,
                    'description' => $line->description,
                    'quantity' => $line->quantity,
                    'unit' => $line->unit,
                    'estimated_unit_cost' => $line->estimated_unit_cost,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            foreach ($supplierIds as $supplierId) {
                DB::table('enterprise_procurement_rfq_suppliers')->insert([
                    'rfq_id' => $rfq->id,
                    'supplier_id' => $supplierId,
                    'status' => 'invited',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            $this->audit->record(
                'enterprise.procurement.rfq_created',
                $actor,
                $rfq,
                null,
                [
                    'store_id' => $scope->storeId,
                    'rfq_number' => $rfq->rfq_number,
                    'purchase_request_id' => $purchaseRequest->id,
                    'supplier_ids' => $supplierIds,
                    'line_count' => $requestLines->count(),
                ],
            );

            return $rfq->refresh();
        }, 3);
    }

    public function publishRfq(
        User $actor,
        ResolvedTenantContext $context,
        int $rfqId,
    ): EnterpriseProcurementRfq {
        $scope = $this->authorizedScope($actor, $context, self::RFQ_MANAGE);

        return DB::transaction(function () use ($actor, $scope, $rfqId): EnterpriseProcurementRfq {
            $rfq = $this->lockVisibleRfq($scope, $rfqId);
            if ($rfq->status === 'published') {
                return $rfq;
            }
            if ($rfq->status !== 'draft') {
                throw ValidationException::withMessages(['status' => ['Only a draft RFQ can be published.']]);
            }
            abort_if(
                DB::table('enterprise_procurement_rfq_suppliers')->where('rfq_id', $rfq->id)->count() < 1,
                409,
                'An RFQ requires at least one invited supplier.',
            );

            $before = $rfq->toArray();
            $rfq->forceFill([
                'status' => 'published',
                'published_by' => $actor->id,
                'published_at' => now(),
                'updated_by' => $actor->id,
            ])->save();

            DB::table('enterprise_procurement_rfq_suppliers')
                ->where('rfq_id', $rfq->id)
                ->whereNull('invited_at')
                ->update(['invited_at' => now(), 'updated_at' => now()]);

            $this->audit->record(
                'enterprise.procurement.rfq_published',
                $actor,
                $rfq,
                $before,
                $rfq->fresh()->toArray(),
            );

            return $rfq->refresh();
        }, 3);
    }

    public function cancelRfq(
        User $actor,
        ResolvedTenantContext $context,
        int $rfqId,
    ): EnterpriseProcurementRfq {
        $scope = $this->authorizedScope($actor, $context, self::RFQ_MANAGE);

        return DB::transaction(function () use ($actor, $scope, $rfqId): EnterpriseProcurementRfq {
            $rfq = $this->lockVisibleRfq($scope, $rfqId);
            if ($rfq->status === 'cancelled') {
                return $rfq;
            }
            if ($rfq->status === 'awarded') {
                throw ValidationException::withMessages(['status' => ['An awarded RFQ cannot be cancelled.']]);
            }

            $before = $rfq->toArray();
            $rfq->forceFill([
                'status' => 'cancelled',
                'cancelled_by' => $actor->id,
                'cancelled_at' => now(),
                'updated_by' => $actor->id,
            ])->save();

            $this->audit->record(
                'enterprise.procurement.rfq_cancelled',
                $actor,
                $rfq,
                $before,
                $rfq->fresh()->toArray(),
            );

            return $rfq->refresh();
        }, 3);
    }

    /** @param array<string,mixed> $attributes */
    public function recordQuote(
        User $actor,
        ResolvedTenantContext $context,
        int $rfqId,
        array $attributes,
    ): EnterpriseProcurementQuote {
        $scope = $this->authorizedScope($actor, $context, self::RFQ_MANAGE);

        return $this->recordQuoteForScope($scope, $rfqId, $attributes, $actor);
    }

    /** @param array<string,mixed> $attributes */
    public function recordSupplierQuote(
        EnterpriseDataScope $scope,
        int $rfqId,
        array $attributes,
    ): EnterpriseProcurementQuote {
        return $this->recordQuoteForScope($scope, $rfqId, $attributes, null);
    }

    public function awardRfq(
        User $actor,
        ResolvedTenantContext $context,
        int $rfqId,
        int $quoteId,
    ): EnterprisePurchaseOrder {
        $scope = $this->authorizedScope($actor, $context, self::RFQ_AWARD);

        return DB::transaction(function () use ($actor, $context, $scope, $rfqId, $quoteId): EnterprisePurchaseOrder {
            $rfq = $this->lockVisibleRfq($scope, $rfqId);
            if ($rfq->status === 'awarded' && $rfq->awarded_quote_id !== null) {
                $existing = EnterprisePurchaseOrder::query()
                    ->where('sourcing_rfq_id', $rfq->id)
                    ->first();

                if ($existing instanceof EnterprisePurchaseOrder) {
                    return $existing->load('lines');
                }
            }
            if (! in_array($rfq->status, ['published', 'evaluating'], true)) {
                throw ValidationException::withMessages(['status' => ['Only a live RFQ can be awarded.']]);
            }

            $quote = EnterpriseProcurementQuote::query()
                ->where('rfq_id', $rfq->id)
                ->whereKey($quoteId)
                ->where('status', 'submitted')
                ->lockForUpdate()
                ->firstOrFail();

            $request = EnterprisePurchaseRequest::query()
                ->where('organization_id', $scope->organizationId)
                ->where('company_id', $scope->companyId)
                ->where('store_id', $scope->storeId)
                ->whereKey($rfq->purchase_request_id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($request->status !== 'approved') {
                throw ValidationException::withMessages([
                    'purchase_request_id' => ['The RFQ source request must remain approved at award time.'],
                ]);
            }

            $before = $rfq->toArray();
            $request->forceFill([
                'supplier_id' => $quote->supplier_id,
                'updated_by' => $actor->id,
            ])->save();

            $order = $this->orders->createFromApprovedRequest($actor, $context, $request->id);

            $quoteLines = DB::table('enterprise_procurement_quote_lines as quote_line')
                ->join('enterprise_procurement_rfq_lines as rfq_line', 'rfq_line.id', '=', 'quote_line.rfq_line_id')
                ->where('quote_line.quote_id', $quote->id)
                ->orderBy('rfq_line.line_no')
                ->get(['rfq_line.line_no', 'quote_line.unit_price']);

            foreach ($quoteLines as $quoteLine) {
                $order->lines()
                    ->where('line_no', (int) $quoteLine->line_no)
                    ->update(['unit_price' => $quoteLine->unit_price, 'updated_at' => now()]);
            }

            $order->forceFill([
                'sourcing_rfq_id' => $rfq->id,
                'sourcing_quote_id' => $quote->id,
                'updated_by' => $actor->id,
            ])->save();

            $quote->forceFill(['status' => 'awarded', 'awarded_at' => now(), 'updated_by' => $actor->id])->save();
            EnterpriseProcurementQuote::query()
                ->where('rfq_id', $rfq->id)
                ->whereKeyNot($quote->id)
                ->where('status', 'submitted')
                ->update(['status' => 'rejected', 'updated_at' => now()]);

            $rfq->forceFill([
                'status' => 'awarded',
                'awarded_quote_id' => $quote->id,
                'updated_by' => $actor->id,
            ])->save();

            DB::table('enterprise_procurement_rfq_suppliers')
                ->where('rfq_id', $rfq->id)
                ->where('supplier_id', $quote->supplier_id)
                ->update(['status' => 'awarded', 'responded_at' => now(), 'updated_at' => now()]);

            $this->audit->record(
                'enterprise.procurement.rfq_awarded',
                $actor,
                $rfq,
                $before,
                [
                    ...$rfq->fresh()->toArray(),
                    'quote_id' => $quote->id,
                    'supplier_id' => $quote->supplier_id,
                    'purchase_order_id' => $order->id,
                ],
            );

            return $order->refresh()->load('lines');
        }, 3);
    }

    /** @return LengthAwarePaginator<int, EnterpriseProcurementContract> */
    public function paginateContracts(
        User $actor,
        ResolvedTenantContext $context,
        array $filters = [],
        int $perPage = 25,
    ): LengthAwarePaginator {
        $scope = $this->authorizedScope($actor, $context, self::CONTRACT_VIEW);
        $validated = Validator::make($filters, [
            'status' => ['nullable', Rule::in(['draft', 'active', 'terminated'])],
            'supplier_id' => ['nullable', 'integer', 'min:1'],
            'expiring_days' => ['nullable', 'integer', 'min:1', 'max:3650'],
        ])->validate();

        return EnterpriseProcurementContract::query()
            ->where('organization_id', $scope->organizationId)
            ->where('company_id', $scope->companyId)
            ->where('store_id', $scope->storeId)
            ->with('supplier:id,code,name_en,name_ar')
            ->when($validated['status'] ?? null, fn ($query, string $status) => $query->where('status', $status))
            ->when($validated['supplier_id'] ?? null, fn ($query, mixed $supplierId) => $query->where('supplier_id', (int) $supplierId))
            ->when(
                $validated['expiring_days'] ?? null,
                fn ($query, mixed $days) => $query
                    ->where('status', 'active')
                    ->whereBetween('valid_to', [now()->toDateString(), now()->addDays((int) $days)->toDateString()]),
            )
            ->orderBy('valid_to')
            ->orderByDesc('id')
            ->paginate(max(10, min(100, $perPage)))
            ->withQueryString();
    }

    /** @return array<string,mixed> */
    public function contractDetail(
        User $actor,
        ResolvedTenantContext $context,
        int $contractId,
    ): array {
        $scope = $this->authorizedScope($actor, $context, self::CONTRACT_VIEW);
        $contract = $this->visibleContract($scope, $contractId)->load('supplier:id,code,name_en,name_ar');

        $documents = DB::table('enterprise_procurement_contract_documents')
            ->where('contract_id', $contract->id)
            ->orderByDesc('id')
            ->get();
        $orders = DB::table('enterprise_procurement_contract_orders as link')
            ->join('enterprise_purchase_orders as purchase_order', 'purchase_order.id', '=', 'link.purchase_order_id')
            ->where('link.contract_id', $contract->id)
            ->orderByDesc('purchase_order.id')
            ->get([
                'purchase_order.id',
                'purchase_order.order_number',
                'purchase_order.status',
                'purchase_order.currency_code',
                'purchase_order.needed_by',
            ]);

        return [
            'contract' => $contract,
            'documents' => $documents,
            'orders' => $orders,
            'committed_amount' => $this->contractCommittedAmount($contract->id),
            'committed_quantity' => $this->contractCommittedQuantity($contract->id),
        ];
    }

    /** @param array<string,mixed> $attributes */
    public function createContract(
        User $actor,
        ResolvedTenantContext $context,
        array $attributes,
    ): EnterpriseProcurementContract {
        $scope = $this->authorizedScope($actor, $context, self::CONTRACT_MANAGE);
        $data = Validator::make($attributes, [
            'supplier_id' => ['required', 'integer', 'min:1'],
            'title' => ['required', 'string', 'max:190'],
            'currency_code' => ['required', 'string', 'size:3'],
            'valid_from' => ['required', 'date'],
            'valid_to' => ['required', 'date', 'after_or_equal:valid_from'],
            'ceiling_amount' => ['nullable', 'numeric', 'gt:0'],
            'ceiling_quantity' => ['nullable', 'numeric', 'gt:0'],
            'notes' => ['nullable', 'string', 'max:4000'],
        ])->validate();

        $supplier = $this->visibleSupplier($scope, (int) $data['supplier_id']);
        $publicId = (string) Str::uuid();
        $contract = EnterpriseProcurementContract::query()->create([
            'public_id' => $publicId,
            'contract_number' => 'PC-'.strtoupper(substr(str_replace('-', '', $publicId), 0, 12)),
            'organization_id' => $scope->organizationId,
            'company_id' => $scope->companyId,
            'store_id' => $scope->storeId,
            'supplier_id' => $supplier->id,
            'title' => $data['title'],
            'status' => 'draft',
            'currency_code' => strtoupper((string) $data['currency_code']),
            'valid_from' => $data['valid_from'],
            'valid_to' => $data['valid_to'],
            'ceiling_amount' => $data['ceiling_amount'] ?? null,
            'ceiling_quantity' => $data['ceiling_quantity'] ?? null,
            'notes' => $data['notes'] ?? null,
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
        ]);

        $this->audit->record('enterprise.procurement.contract_created', $actor, $contract, null, $contract->toArray());

        return $contract->refresh();
    }

    public function activateContract(
        User $actor,
        ResolvedTenantContext $context,
        int $contractId,
    ): EnterpriseProcurementContract {
        $scope = $this->authorizedScope($actor, $context, self::CONTRACT_MANAGE);

        return DB::transaction(function () use ($actor, $scope, $contractId): EnterpriseProcurementContract {
            $contract = $this->lockVisibleContract($scope, $contractId);
            if ($contract->status === 'active') {
                return $contract;
            }
            if ($contract->status !== 'draft') {
                throw ValidationException::withMessages(['status' => ['Only a draft contract can be activated.']]);
            }
            if ($contract->valid_to->isBefore(now()->startOfDay())) {
                throw ValidationException::withMessages(['valid_to' => ['An expired contract cannot be activated.']]);
            }

            $before = $contract->toArray();
            $contract->forceFill([
                'status' => 'active',
                'activated_by' => $actor->id,
                'activated_at' => now(),
                'updated_by' => $actor->id,
            ])->save();
            $this->audit->record(
                'enterprise.procurement.contract_activated',
                $actor,
                $contract,
                $before,
                $contract->fresh()->toArray(),
            );

            return $contract->refresh();
        }, 3);
    }

    public function terminateContract(
        User $actor,
        ResolvedTenantContext $context,
        int $contractId,
        string $reason,
    ): EnterpriseProcurementContract {
        $scope = $this->authorizedScope($actor, $context, self::CONTRACT_MANAGE);
        $reason = (string) Validator::make(
            ['reason' => $reason],
            ['reason' => ['required', 'string', 'max:2000']],
        )->validate()['reason'];

        return DB::transaction(function () use ($actor, $scope, $contractId, $reason): EnterpriseProcurementContract {
            $contract = $this->lockVisibleContract($scope, $contractId);
            if ($contract->status === 'terminated') {
                return $contract;
            }
            if (! in_array($contract->status, ['draft', 'active'], true)) {
                throw ValidationException::withMessages(['status' => ['This contract cannot be terminated.']]);
            }

            $before = $contract->toArray();
            $contract->forceFill([
                'status' => 'terminated',
                'terminated_by' => $actor->id,
                'terminated_at' => now(),
                'termination_reason' => $reason,
                'updated_by' => $actor->id,
            ])->save();
            $this->audit->record(
                'enterprise.procurement.contract_terminated',
                $actor,
                $contract,
                $before,
                $contract->fresh()->toArray(),
            );

            return $contract->refresh();
        }, 3);
    }

    public function attachOrder(
        User $actor,
        ResolvedTenantContext $context,
        int $contractId,
        int $orderId,
    ): void {
        $scope = $this->authorizedScope($actor, $context, self::CONTRACT_MANAGE);
        $contract = $this->visibleContract($scope, $contractId);
        $order = EnterprisePurchaseOrder::query()
            ->where('organization_id', $scope->organizationId)
            ->where('company_id', $scope->companyId)
            ->where('store_id', $scope->storeId)
            ->whereKey($orderId)
            ->firstOrFail();

        if ($contract->status !== 'active' || now()->toDateString() < $contract->valid_from->toDateString() || now()->toDateString() > $contract->valid_to->toDateString()) {
            throw ValidationException::withMessages(['contract' => ['The purchase contract must be active and in its validity period.']]);
        }
        if ((int) $contract->supplier_id !== (int) $order->supplier_id) {
            throw ValidationException::withMessages(['purchase_order_id' => ['Contract and purchase order supplier must match.']]);
        }

        $orderAmount = (float) $order->lines()->selectRaw('COALESCE(SUM(quantity * unit_price), 0) as total')->value('total');
        $orderQuantity = (float) $order->lines()->sum('quantity');
        if ($contract->ceiling_amount !== null && $this->contractCommittedAmount($contract->id) + $orderAmount > (float) $contract->ceiling_amount + 0.0005) {
            throw ValidationException::withMessages(['purchase_order_id' => ['This order would exceed the contract value ceiling.']]);
        }
        if ($contract->ceiling_quantity !== null && $this->contractCommittedQuantity($contract->id) + $orderQuantity > (float) $contract->ceiling_quantity + 0.0005) {
            throw ValidationException::withMessages(['purchase_order_id' => ['This order would exceed the contract quantity ceiling.']]);
        }

        DB::table('enterprise_procurement_contract_orders')->updateOrInsert(
            ['purchase_order_id' => $order->id],
            [
                'contract_id' => $contract->id,
                'created_by' => $actor->id,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );

        $this->audit->record(
            'enterprise.procurement.contract_order_attached',
            $actor,
            $contract,
            null,
            [
                'store_id' => $scope->storeId,
                'contract_id' => $contract->id,
                'purchase_order_id' => $order->id,
                'order_amount' => round($orderAmount, 3),
                'order_quantity' => round($orderQuantity, 3),
            ],
        );
    }

    public function registerContractDocument(
        User $actor,
        ResolvedTenantContext $context,
        int $contractId,
        array $attributes,
    ): void {
        $scope = $this->authorizedScope($actor, $context, self::CONTRACT_MANAGE);
        $contract = $this->visibleContract($scope, $contractId);
        $data = Validator::make($attributes, [
            'title' => ['required', 'string', 'max:190'],
            'reference' => ['nullable', 'string', 'max:190'],
            'expires_at' => ['nullable', 'date'],
        ])->validate();

        $documentId = DB::table('enterprise_procurement_contract_documents')->insertGetId([
            'contract_id' => $contract->id,
            'title' => $data['title'],
            'reference' => $data['reference'] ?? null,
            'expires_at' => $data['expires_at'] ?? null,
            'created_by' => $actor->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->audit->record(
            'enterprise.procurement.contract_document_registered',
            $actor,
            $contract,
            null,
            ['store_id' => $scope->storeId, 'document_id' => $documentId, ...$data],
        );
    }

    /** @param array<string,mixed> $attributes */
    private function recordQuoteForScope(
        EnterpriseDataScope $scope,
        int $rfqId,
        array $attributes,
        ?User $actor,
    ): EnterpriseProcurementQuote {
        $data = Validator::make($attributes, [
            'supplier_id' => ['required', 'integer', 'min:1'],
            'supplier_reference' => ['nullable', 'string', 'max:96'],
            'lead_time_days' => ['nullable', 'integer', 'min:0', 'max:3650'],
            'valid_until' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:4000'],
            'lines' => ['required', 'array', 'min:1', 'max:200'],
            'lines.*.rfq_line_id' => ['required', 'integer', 'min:1', 'distinct'],
            'lines.*.unit_price' => ['required', 'numeric', 'min:0'],
        ])->validate();

        return DB::transaction(function () use ($scope, $rfqId, $data, $actor): EnterpriseProcurementQuote {
            $rfq = $this->lockVisibleRfq($scope, $rfqId);
            if (! in_array($rfq->status, ['published', 'evaluating'], true)) {
                throw ValidationException::withMessages(['status' => ['Quotes can be submitted only to a live RFQ.']]);
            }
            if ($rfq->closes_at !== null && $rfq->closes_at->isPast()) {
                throw ValidationException::withMessages(['status' => ['The RFQ submission window is closed.']]);
            }

            $supplierId = (int) $data['supplier_id'];
            $invited = DB::table('enterprise_procurement_rfq_suppliers')
                ->where('rfq_id', $rfq->id)
                ->where('supplier_id', $supplierId)
                ->first();
            if ($invited === null || $invited->status === 'declined') {
                throw ValidationException::withMessages(['supplier_id' => ['The supplier is not an active invitee for this RFQ.']]);
            }

            $rfqLines = DB::table('enterprise_procurement_rfq_lines')
                ->where('rfq_id', $rfq->id)
                ->orderBy('line_no')
                ->get()
                ->keyBy('id');

            if ($rfqLines->count() !== count($data['lines'])) {
                throw ValidationException::withMessages(['lines' => ['A quote must price every RFQ line exactly once.']]);
            }

            $publicId = (string) Str::uuid();
            $quote = EnterpriseProcurementQuote::query()->firstOrNew([
                'rfq_id' => $rfq->id,
                'supplier_id' => $supplierId,
            ]);
            if ($quote->exists && in_array($quote->status, ['awarded', 'rejected'], true)) {
                throw ValidationException::withMessages(['status' => ['The evaluated quote is immutable.']]);
            }

            $quote->fill([
                'public_id' => $quote->public_id ?: $publicId,
                'quote_number' => $quote->quote_number ?: 'Q-'.strtoupper(substr(str_replace('-', '', $publicId), 0, 12)),
                'supplier_reference' => $data['supplier_reference'] ?? null,
                'currency_code' => $rfq->currency_code,
                'status' => 'submitted',
                'lead_time_days' => $data['lead_time_days'] ?? null,
                'valid_until' => $data['valid_until'] ?? null,
                'notes' => $data['notes'] ?? null,
                'created_by' => $quote->created_by ?? $actor?->id,
                'updated_by' => $actor?->id,
                'submitted_at' => now(),
            ])->save();

            DB::table('enterprise_procurement_quote_lines')->where('quote_id', $quote->id)->delete();
            $total = 0.0;
            foreach ($data['lines'] as $line) {
                $rfqLine = $rfqLines->get((int) $line['rfq_line_id']);
                if ($rfqLine === null) {
                    throw ValidationException::withMessages(['lines' => ['Every quote line must belong to the selected RFQ.']]);
                }
                $unitPrice = round((float) $line['unit_price'], 3);
                $lineTotal = round((float) $rfqLine->quantity * $unitPrice, 3);
                $total += $lineTotal;
                DB::table('enterprise_procurement_quote_lines')->insert([
                    'quote_id' => $quote->id,
                    'rfq_line_id' => $rfqLine->id,
                    'line_no' => $rfqLine->line_no,
                    'quantity' => $rfqLine->quantity,
                    'unit_price' => $unitPrice,
                    'line_total' => $lineTotal,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            $quote->forceFill(['total_amount' => round($total, 3)])->save();
            $rfq->forceFill(['status' => 'evaluating', 'updated_by' => $actor?->id ?? $rfq->updated_by])->save();

            DB::table('enterprise_procurement_rfq_suppliers')
                ->where('rfq_id', $rfq->id)
                ->where('supplier_id', $supplierId)
                ->update(['status' => 'responded', 'responded_at' => now(), 'updated_at' => now()]);

            $this->audit->record(
                'enterprise.procurement.quote_submitted',
                $actor,
                $quote,
                null,
                [
                    'store_id' => $scope->storeId,
                    'rfq_id' => $rfq->id,
                    'supplier_id' => $supplierId,
                    'total_amount' => round($total, 3),
                    'line_count' => count($data['lines']),
                ],
            );

            return $quote->refresh();
        }, 3);
    }

    private function visibleRfq(EnterpriseDataScope $scope, int $rfqId): EnterpriseProcurementRfq
    {
        return EnterpriseProcurementRfq::query()
            ->where('organization_id', $scope->organizationId)
            ->where('company_id', $scope->companyId)
            ->where('store_id', $scope->storeId)
            ->whereKey($rfqId)
            ->firstOrFail();
    }

    private function lockVisibleRfq(EnterpriseDataScope $scope, int $rfqId): EnterpriseProcurementRfq
    {
        return EnterpriseProcurementRfq::query()
            ->where('organization_id', $scope->organizationId)
            ->where('company_id', $scope->companyId)
            ->where('store_id', $scope->storeId)
            ->whereKey($rfqId)
            ->lockForUpdate()
            ->firstOrFail();
    }

    private function visibleContract(EnterpriseDataScope $scope, int $contractId): EnterpriseProcurementContract
    {
        return EnterpriseProcurementContract::query()
            ->where('organization_id', $scope->organizationId)
            ->where('company_id', $scope->companyId)
            ->where('store_id', $scope->storeId)
            ->whereKey($contractId)
            ->firstOrFail();
    }

    private function lockVisibleContract(EnterpriseDataScope $scope, int $contractId): EnterpriseProcurementContract
    {
        return EnterpriseProcurementContract::query()
            ->where('organization_id', $scope->organizationId)
            ->where('company_id', $scope->companyId)
            ->where('store_id', $scope->storeId)
            ->whereKey($contractId)
            ->lockForUpdate()
            ->firstOrFail();
    }

    private function visibleSupplier(EnterpriseDataScope $scope, int $supplierId): EnterpriseSupplier
    {
        return EnterpriseSupplier::query()
            ->where('organization_id', $scope->organizationId)
            ->where('company_id', $scope->companyId)
            ->whereKey($supplierId)
            ->where(function ($query) use ($scope): void {
                $query->where('scope_type', 'company')
                    ->orWhere(fn ($store) => $store
                        ->where('scope_type', 'store')
                        ->where('store_id', $scope->storeId));
            })
            ->firstOrFail();
    }

    private function contractCommittedAmount(int $contractId): float
    {
        return round((float) DB::table('enterprise_procurement_contract_orders as link')
            ->join('enterprise_purchase_order_lines as line', 'line.purchase_order_id', '=', 'link.purchase_order_id')
            ->where('link.contract_id', $contractId)
            ->selectRaw('COALESCE(SUM(line.quantity * line.unit_price), 0) as committed')
            ->value('committed'), 3);
    }

    private function contractCommittedQuantity(int $contractId): float
    {
        return round((float) DB::table('enterprise_procurement_contract_orders as link')
            ->join('enterprise_purchase_order_lines as line', 'line.purchase_order_id', '=', 'link.purchase_order_id')
            ->where('link.contract_id', $contractId)
            ->sum('line.quantity'), 3);
    }

    private function authorizedScope(
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
}
