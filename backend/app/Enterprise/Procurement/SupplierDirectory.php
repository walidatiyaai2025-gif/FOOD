<?php

namespace App\Enterprise\Procurement;

use App\Models\AuditLog;
use App\Models\EnterpriseSupplier;
use App\Models\EnterpriseSupplierDocument;
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\EnterpriseDataScope;
use App\Support\EnterpriseScopeAuthorizer;
use App\Support\ResolvedTenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class SupplierDirectory
{
    private const VIEW_PERMISSION = 'procurement.suppliers.view';

    private const MANAGE_PERMISSION = 'procurement.suppliers.manage';

    private const STATUSES = ['active', 'inactive', 'on_hold'];

    public function __construct(
        private readonly EnterpriseScopeAuthorizer $authorizer,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array{q?:string,status?:string,scope_type?:string}  $filters
     * @return LengthAwarePaginator<int, EnterpriseSupplier>
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
            'scope_type' => ['nullable', Rule::in(['company', 'store'])],
        ])->validate();

        $perPage = max(10, min(100, $perPage));
        $term = trim((string) ($validated['q'] ?? ''));
        $term = str_replace(['%', '_'], '', $term);

        return $this->visibleQuery($scope)
            ->when(
                $validated['status'] ?? null,
                fn (Builder $query, string $status): Builder => $query->where('status', $status),
            )
            ->when(
                $validated['scope_type'] ?? null,
                fn (Builder $query, string $scopeType): Builder => $query->where('scope_type', $scopeType),
            )
            ->when($term !== '', function (Builder $query) use ($term): void {
                $query->where(function (Builder $search) use ($term): void {
                    $search
                        ->where('code', 'like', $term.'%')
                        ->orWhere('name_en', 'like', $term.'%')
                        ->orWhere('name_ar', 'like', $term.'%')
                        ->orWhere('email', 'like', $term.'%')
                        ->orWhere('phone', 'like', $term.'%')
                        ->orWhere('tax_registration_number', 'like', $term.'%');
                });
            })
            ->orderBy('name_en')
            ->orderBy('id')
            ->paginate($perPage)
            ->withQueryString();
    }

    /** @param array<string, mixed> $attributes */
    public function create(
        User $actor,
        ResolvedTenantContext $context,
        array $attributes,
    ): EnterpriseSupplier {
        $scope = $this->authorizedScope($actor, $context, self::MANAGE_PERMISSION);
        $data = $this->validatedSupplier($attributes);
        $this->assertCodeAvailable($scope, (string) $data['code']);

        return DB::transaction(function () use ($actor, $scope, $data): EnterpriseSupplier {
            $supplier = EnterpriseSupplier::query()->create([
                ...$data,
                'organization_id' => $scope->organizationId,
                'company_id' => $scope->companyId,
                'store_id' => $data['scope_type'] === 'company' ? null : $scope->storeId,
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
            ]);

            $this->audit->record(
                'enterprise.supplier.created',
                $actor,
                $supplier,
                null,
                $supplier->toArray(),
            );

            return $supplier->refresh();
        }, 3);
    }

    /** @param array<string, mixed> $attributes */
    public function update(
        User $actor,
        ResolvedTenantContext $context,
        int $supplierId,
        array $attributes,
    ): EnterpriseSupplier {
        $scope = $this->authorizedScope($actor, $context, self::MANAGE_PERMISSION);
        $supplier = $this->findVisible($scope, $supplierId);
        $data = $this->validatedSupplier($attributes, true);

        if (isset($data['code']) && $data['code'] !== $supplier->code) {
            $this->assertCodeAvailable($scope, (string) $data['code'], $supplier->id);
        }

        return DB::transaction(function () use ($actor, $scope, $supplier, $data): EnterpriseSupplier {
            /** @var EnterpriseSupplier $locked */
            $locked = EnterpriseSupplier::query()->whereKey($supplier->id)->lockForUpdate()->firstOrFail();
            abort_unless($this->isVisible($locked, $scope), 404);

            $before = $locked->toArray();
            if (isset($data['scope_type'])) {
                $data['store_id'] = $data['scope_type'] === 'company' ? null : $scope->storeId;
            }
            $locked->fill([...$data, 'updated_by' => $actor->id]);
            $locked->save();

            $this->audit->record(
                'enterprise.supplier.updated',
                $actor,
                $locked,
                $before,
                $locked->fresh()->toArray(),
            );

            return $locked->refresh();
        }, 3);
    }

    public function archive(
        User $actor,
        ResolvedTenantContext $context,
        int $supplierId,
    ): EnterpriseSupplier {
        return $this->update($actor, $context, $supplierId, ['status' => 'inactive']);
    }

    /** @param array<string, mixed> $attributes */
    public function registerDocument(
        User $actor,
        ResolvedTenantContext $context,
        int $supplierId,
        array $attributes,
    ): EnterpriseSupplierDocument {
        $scope = $this->authorizedScope($actor, $context, self::MANAGE_PERMISSION);
        $supplier = $this->findVisible($scope, $supplierId);
        $data = Validator::make($attributes, [
            'category' => ['required', 'string', 'max:64'],
            'title' => ['required', 'string', 'max:190'],
            'reference' => ['nullable', 'string', 'max:190'],
            'expires_at' => ['nullable', 'date'],
        ])->validate();

        return DB::transaction(function () use ($actor, $supplier, $data): EnterpriseSupplierDocument {
            $document = $supplier->documents()->create([
                ...$data,
                'created_by' => $actor->id,
            ]);

            $this->audit->record(
                'enterprise.supplier.document_registered',
                $actor,
                $supplier,
                null,
                [
                    'supplier_id' => $supplier->id,
                    'document_id' => $document->id,
                    'category' => $document->category,
                    'title' => $document->title,
                    'expires_at' => $document->getRawOriginal('expires_at') === null
                        ? null
                        : (string) $document->getRawOriginal('expires_at'),
                ],
            );

            return $document->refresh();
        }, 3);
    }

    /**
     * @return array{
     *     supplier: EnterpriseSupplier,
     *     documents: list<EnterpriseSupplierDocument>,
     *     activity: list<AuditLog>,
     *     performance: array{order_count:int,posted_receipt_count:int,average_lead_days:float|null,invoice_count:int,spend:string},
     *     contracts:list<object>
     * }
     */
    public function supplier360(
        User $actor,
        ResolvedTenantContext $context,
        int $supplierId,
    ): array {
        $scope = $this->authorizedScope($actor, $context, self::VIEW_PERMISSION);
        $supplier = $this->findVisible($scope, $supplierId);

        $documents = EnterpriseSupplierDocument::query()
            ->where('supplier_id', $supplier->id)
            ->orderByDesc('id')
            ->limit(50)
            ->get()
            ->all();

        $activity = AuditLog::query()
            ->where('auditable_type', EnterpriseSupplier::class)
            ->where('auditable_id', $supplier->id)
            ->orderByDesc('id')
            ->limit(50)
            ->get()
            ->all();

        $orders = DB::table('enterprise_purchase_orders')
            ->where('organization_id', $scope->organizationId)
            ->where('company_id', $scope->companyId)
            ->where('store_id', $scope->storeId)
            ->where('supplier_id', $supplier->id)
            ->get(['id', 'issued_at']);
        $orderIds = $orders->pluck('id')->all();
        $receipts = $orderIds === []
            ? collect()
            : DB::table('enterprise_goods_receipts')
                ->whereIn('purchase_order_id', $orderIds)
                ->where('status', 'posted')
                ->get(['purchase_order_id', 'posted_at']);
        $issuedAt = $orders->keyBy('id');
        $leadDays = $receipts
            ->filter(fn ($receipt): bool => $receipt->posted_at !== null && $issuedAt->get($receipt->purchase_order_id)?->issued_at !== null)
            ->map(function ($receipt) use ($issuedAt): int {
                $order = $issuedAt->get($receipt->purchase_order_id);

                return (int) CarbonImmutable::parse((string) $order->issued_at)
                    ->diffInDays(CarbonImmutable::parse((string) $receipt->posted_at));
            });

        $invoiceSummary = DB::table('enterprise_purchase_invoices')
            ->where('organization_id', $scope->organizationId)
            ->where('company_id', $scope->companyId)
            ->where('store_id', $scope->storeId)
            ->where('supplier_id', $supplier->id)
            ->where('status', 'handed_off')
            ->selectRaw('COUNT(*) as invoice_count, COALESCE(SUM(total_amount), 0) as spend')
            ->first();

        $contracts = DB::table('enterprise_procurement_contracts')
            ->where('organization_id', $scope->organizationId)
            ->where('company_id', $scope->companyId)
            ->where('store_id', $scope->storeId)
            ->where('supplier_id', $supplier->id)
            ->orderByDesc('id')
            ->limit(25)
            ->get()
            ->all();

        return [
            'supplier' => $supplier,
            'documents' => $documents,
            'activity' => $activity,
            'performance' => [
                'order_count' => $orders->count(),
                'posted_receipt_count' => $receipts->count(),
                'average_lead_days' => $leadDays->isEmpty() ? null : round((float) $leadDays->avg(), 1),
                'invoice_count' => (int) ($invoiceSummary->invoice_count ?? 0),
                'spend' => number_format((float) ($invoiceSummary->spend ?? 0), 3, '.', ''),
            ],
            'contracts' => $contracts,
        ];
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

    /** @return Builder<EnterpriseSupplier> */
    private function visibleQuery(EnterpriseDataScope $scope): Builder
    {
        return EnterpriseSupplier::query()
            ->where('organization_id', $scope->organizationId)
            ->where('company_id', $scope->companyId)
            ->where(function (Builder $query) use ($scope): void {
                $query
                    ->where('scope_type', 'company')
                    ->orWhere(function (Builder $store) use ($scope): void {
                        $store
                            ->where('scope_type', 'store')
                            ->where('store_id', $scope->storeId);
                    });
            });
    }

    private function findVisible(EnterpriseDataScope $scope, int $supplierId): EnterpriseSupplier
    {
        return $this->visibleQuery($scope)->whereKey($supplierId)->firstOrFail();
    }

    private function isVisible(EnterpriseSupplier $supplier, EnterpriseDataScope $scope): bool
    {
        if (
            (int) $supplier->organization_id !== $scope->organizationId
            || (int) $supplier->company_id !== $scope->companyId
        ) {
            return false;
        }

        return $supplier->scope_type === 'company'
            || ($supplier->scope_type === 'store' && (int) $supplier->store_id === $scope->storeId);
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function validatedSupplier(array $attributes, bool $partial = false): array
    {
        if (array_key_exists('code', $attributes)) {
            $attributes['code'] = strtoupper(trim((string) $attributes['code']));
        }

        foreach (['name_en', 'name_ar', 'contact_name', 'email', 'phone'] as $field) {
            if (array_key_exists($field, $attributes) && is_string($attributes[$field])) {
                $attributes[$field] = trim($attributes[$field]);
            }
        }

        $required = $partial ? 'sometimes' : 'required';

        return Validator::make($attributes, [
            'scope_type' => [$required, Rule::in(['company', 'store'])],
            'code' => [$required, 'string', 'max:64', 'regex:/^[A-Z0-9][A-Z0-9._-]*$/'],
            'name_en' => [$required, 'string', 'max:190'],
            'name_ar' => ['nullable', 'string', 'max:190'],
            'status' => [$partial ? 'sometimes' : 'nullable', Rule::in(self::STATUSES)],
            'contact_name' => ['nullable', 'string', 'max:190'],
            'email' => ['nullable', 'email:rfc', 'max:190'],
            'phone' => ['nullable', 'string', 'max:64'],
            'tax_registration_number' => ['nullable', 'string', 'max:96'],
            'commercial_registration_number' => ['nullable', 'string', 'max:96'],
            'business_type' => ['nullable', 'string', 'max:96'],
            'payment_terms_days' => ['nullable', 'integer', 'min:0', 'max:3650'],
            'notes' => ['nullable', 'string', 'max:4000'],
        ])->validate();
    }

    private function assertCodeAvailable(
        EnterpriseDataScope $scope,
        string $code,
        ?int $exceptSupplierId = null,
    ): void {
        $exists = EnterpriseSupplier::query()
            ->where('organization_id', $scope->organizationId)
            ->where('company_id', $scope->companyId)
            ->where('code', $code)
            ->when(
                $exceptSupplierId !== null,
                fn (Builder $query): Builder => $query->where('id', '!=', $exceptSupplierId),
            )
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'code' => ['Supplier code already exists in this company.'],
            ]);
        }
    }
}
