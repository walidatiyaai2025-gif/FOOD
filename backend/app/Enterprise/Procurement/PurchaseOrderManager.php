<?php

namespace App\Enterprise\Procurement;

use App\Models\EnterprisePurchaseOrder;
use App\Models\EnterprisePurchaseRequest;
use App\Models\EnterpriseSupplier;
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\EnterpriseDataScope;
use App\Support\EnterpriseScopeAuthorizer;
use App\Support\ResolvedTenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class PurchaseOrderManager
{
    private const VIEW_PERMISSION = 'procurement.purchase_orders.view';

    private const MANAGE_PERMISSION = 'procurement.purchase_orders.manage';

    private const ISSUE_PERMISSION = 'procurement.purchase_orders.issue';

    private const STATUSES = ['draft', 'issued', 'cancelled'];

    public function __construct(
        private readonly EnterpriseScopeAuthorizer $authorizer,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array{q?:string,status?:string}  $filters
     * @return LengthAwarePaginator<int, EnterprisePurchaseOrder>
     */
    public function paginate(
        User $actor,
        ResolvedTenantContext $context,
        array $filters = [],
        int $perPage = 25,
    ): LengthAwarePaginator {
        $scope = $this->authorizedScope($actor, $context, self::VIEW_PERMISSION);
        $validated = Validator::make($filters, [
            'q' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', Rule::in(self::STATUSES)],
        ])->validate();

        $perPage = max(10, min(100, $perPage));
        $term = str_replace(['%', '_'], '', trim((string) ($validated['q'] ?? '')));

        return $this->visibleQuery($scope)
            ->with('supplier:id,code,name_en,name_ar,status')
            ->when(
                $validated['status'] ?? null,
                fn (Builder $query, string $status): Builder => $query->where('status', $status),
            )
            ->when(
                $term !== '',
                fn (Builder $query): Builder => $query->where('order_number', 'like', $term.'%'),
            )
            ->orderByDesc('id')
            ->paginate($perPage)
            ->withQueryString();
    }

    public function detail(
        User $actor,
        ResolvedTenantContext $context,
        int $orderId,
    ): EnterprisePurchaseOrder {
        $scope = $this->authorizedScope($actor, $context, self::VIEW_PERMISSION);

        return $this->findVisible($scope, $orderId)
            ->load([
                'supplier:id,code,name_en,name_ar,status',
                'purchaseRequest:id,request_number,status',
                'lines' => fn ($query) => $query->orderBy('line_no'),
            ]);
    }

    public function createFromApprovedRequest(
        User $actor,
        ResolvedTenantContext $context,
        int $requestId,
    ): EnterprisePurchaseOrder {
        $scope = $this->authorizedScope($actor, $context, self::MANAGE_PERMISSION);

        return DB::transaction(function () use ($actor, $scope, $requestId): EnterprisePurchaseOrder {
            $request = $this->visibleRequestQuery($scope)
                ->whereKey($requestId)
                ->lockForUpdate()
                ->firstOrFail();

            $existing = $this->visibleQuery($scope)
                ->where('purchase_request_id', $request->id)
                ->first();

            if ($existing instanceof EnterprisePurchaseOrder) {
                return $existing->load('lines');
            }

            if ($request->status !== 'approved') {
                throw ValidationException::withMessages([
                    'purchase_request_id' => [
                        sprintf(
                            'Purchase request %s must be approved before creating a purchase order.',
                            $request->request_number,
                        ),
                    ],
                ]);
            }

            if ($request->supplier_id === null) {
                throw ValidationException::withMessages([
                    'supplier_id' => ['An approved purchase request must have a supplier before PO creation.'],
                ]);
            }

            $supplier = $this->activeVisibleSupplier($scope, (int) $request->supplier_id);
            $lines = $request->lines()->orderBy('line_no')->get();
            abort_if($lines->isEmpty(), 409, 'A purchase order must contain at least one line.');

            $publicId = (string) Str::uuid();
            $order = EnterprisePurchaseOrder::query()->create([
                'public_id' => $publicId,
                'order_number' => 'PO-'.strtoupper(substr(str_replace('-', '', $publicId), 0, 12)),
                'purchase_request_id' => $request->id,
                'source_request_number' => $request->request_number,
                'organization_id' => $scope->organizationId,
                'company_id' => $scope->companyId,
                'store_id' => $scope->storeId,
                'supplier_id' => $supplier->id,
                'supplier_code' => $supplier->code,
                'supplier_name_en' => $supplier->name_en,
                'supplier_name_ar' => $supplier->name_ar,
                'status' => 'draft',
                'currency_code' => $request->currency_code,
                'needed_by' => $request->getRawOriginal('needed_by'),
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
            ]);

            foreach ($lines as $line) {
                $order->lines()->create([
                    'source_purchase_request_line_id' => $line->id,
                    'line_no' => $line->line_no,
                    'item_code' => $line->item_code,
                    'description' => $line->description,
                    'quantity' => $line->quantity,
                    'unit' => $line->unit,
                    'unit_price' => $line->estimated_unit_cost,
                ]);
            }

            $this->audit->record(
                'enterprise.purchase_order.created',
                $actor,
                $order,
                null,
                [
                    'order_number' => $order->order_number,
                    'source_request_number' => $order->source_request_number,
                    'purchase_request_id' => $request->id,
                    'supplier_id' => $supplier->id,
                    'status' => $order->status,
                    'organization_id' => $order->organization_id,
                    'company_id' => $order->company_id,
                    'store_id' => $order->store_id,
                    'line_count' => $lines->count(),
                ],
            );

            return $order->load('lines');
        }, 3);
    }

    public function issue(
        User $actor,
        ResolvedTenantContext $context,
        int $orderId,
    ): EnterprisePurchaseOrder {
        $scope = $this->authorizedScope($actor, $context, self::ISSUE_PERMISSION);

        return DB::transaction(function () use ($actor, $scope, $orderId): EnterprisePurchaseOrder {
            $order = $this->lockVisible($scope, $orderId);
            if ($order->status === 'issued') {
                return $order->refresh();
            }

            $this->requireStatus($order, ['draft'], 'issued');
            abort_if($order->lines()->count() < 1, 409, 'A purchase order must contain at least one line.');

            $before = $order->toArray();
            $order->forceFill([
                'status' => 'issued',
                'issued_by' => $actor->id,
                'issued_at' => now(),
                'updated_by' => $actor->id,
            ])->save();

            $this->audit->record(
                'enterprise.purchase_order.issued',
                $actor,
                $order,
                $before,
                $order->fresh()->toArray(),
            );

            return $order->refresh();
        }, 3);
    }

    public function cancel(
        User $actor,
        ResolvedTenantContext $context,
        int $orderId,
    ): EnterprisePurchaseOrder {
        $scope = $this->authorizedScope($actor, $context, self::MANAGE_PERMISSION);

        return DB::transaction(function () use ($actor, $scope, $orderId): EnterprisePurchaseOrder {
            $order = $this->lockVisible($scope, $orderId);
            if ($order->status === 'cancelled') {
                return $order->refresh();
            }

            $this->requireStatus($order, ['draft', 'issued'], 'cancelled');
            $before = $order->toArray();
            $order->forceFill([
                'status' => 'cancelled',
                'cancelled_by' => $actor->id,
                'cancelled_at' => now(),
                'updated_by' => $actor->id,
            ])->save();

            $this->audit->record(
                'enterprise.purchase_order.cancelled',
                $actor,
                $order,
                $before,
                $order->fresh()->toArray(),
            );

            return $order->refresh();
        }, 3);
    }

    private function authorizedScope(
        User $actor,
        ResolvedTenantContext $context,
        string $permission,
    ): EnterpriseDataScope {
        abort_if($context->enterpriseScope === null, 409, 'Enterprise data scope is required.');

        $scope = $context->enterpriseScope;
        $this->authorizer->authorize(
            $actor,
            $context,
            $permission,
            'company',
            $scope->companyId,
        );

        return $scope;
    }

    /** @return Builder<EnterprisePurchaseOrder> */
    private function visibleQuery(EnterpriseDataScope $scope): Builder
    {
        return EnterprisePurchaseOrder::query()
            ->where('organization_id', $scope->organizationId)
            ->where('company_id', $scope->companyId)
            ->where('store_id', $scope->storeId);
    }

    /** @return Builder<EnterprisePurchaseRequest> */
    private function visibleRequestQuery(EnterpriseDataScope $scope): Builder
    {
        return EnterprisePurchaseRequest::query()
            ->where('organization_id', $scope->organizationId)
            ->where('company_id', $scope->companyId)
            ->where('store_id', $scope->storeId);
    }

    private function findVisible(
        EnterpriseDataScope $scope,
        int $orderId,
    ): EnterprisePurchaseOrder {
        return $this->visibleQuery($scope)->whereKey($orderId)->firstOrFail();
    }

    private function lockVisible(
        EnterpriseDataScope $scope,
        int $orderId,
    ): EnterprisePurchaseOrder {
        return $this->visibleQuery($scope)
            ->whereKey($orderId)
            ->lockForUpdate()
            ->firstOrFail();
    }

    private function activeVisibleSupplier(
        EnterpriseDataScope $scope,
        int $supplierId,
    ): EnterpriseSupplier {
        return EnterpriseSupplier::query()
            ->whereKey($supplierId)
            ->where('organization_id', $scope->organizationId)
            ->where('company_id', $scope->companyId)
            ->where('status', 'active')
            ->where(function (Builder $query) use ($scope): void {
                $query
                    ->where('scope_type', 'company')
                    ->orWhere(function (Builder $store) use ($scope): void {
                        $store
                            ->where('scope_type', 'store')
                            ->where('store_id', $scope->storeId);
                    });
            })
            ->firstOrFail();
    }

    /** @param list<string> $allowed */
    private function requireStatus(
        EnterprisePurchaseOrder $order,
        array $allowed,
        string $action,
    ): void {
        if (in_array((string) $order->status, $allowed, true)) {
            return;
        }

        throw ValidationException::withMessages([
            'status' => [
                sprintf(
                    'Purchase order %s cannot be %s from status %s.',
                    $order->order_number,
                    $action,
                    $order->status,
                ),
            ],
        ]);
    }
}
