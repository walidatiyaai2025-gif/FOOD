<?php

namespace App\Enterprise\Finance;

use App\Enterprise\Entitlements\EntitlementScope;
use App\Enterprise\Entitlements\ModuleEntitlementResolver;
use App\Models\EnterpriseFinanceCostCenter;
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\EnterpriseDataScope;
use App\Support\EnterpriseScopeAuthorizer;
use App\Support\ResolvedTenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class CostCenterService
{
    public const MODULE = 'finance';

    private const VIEW_PERMISSION = 'finance.view';

    private const MANAGE_PERMISSION = 'finance.manage';

    private const READ_CAPABILITY = 'finance.cost_centers.read';

    private const MANAGE_CAPABILITY = 'finance.cost_centers.manage';

    private const STATUSES = ['active', 'inactive'];

    public function __construct(
        private readonly EnterpriseScopeAuthorizer $authorizer,
        private readonly ModuleEntitlementResolver $entitlements,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array{q?:string,status?:string,parent_id?:int}  $filters
     * @return LengthAwarePaginator<int, EnterpriseFinanceCostCenter>
     */
    public function paginate(
        User $actor,
        ResolvedTenantContext $context,
        array $filters = [],
        int $perPage = 25,
    ): LengthAwarePaginator {
        $scope = $this->authorizedScope($actor, $context, self::VIEW_PERMISSION, self::READ_CAPABILITY);
        $validated = Validator::make($filters, [
            'q' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', Rule::in(self::STATUSES)],
            'parent_id' => ['nullable', 'integer', 'min:1'],
        ])->validate();

        $term = $this->safePrefix((string) ($validated['q'] ?? ''));
        $perPage = max(10, min(100, $perPage));

        return $this->costCenterQuery($scope)
            ->when(
                $validated['status'] ?? null,
                fn (Builder $query, string $status): Builder => $query->where('status', $status),
            )
            ->when(
                $validated['parent_id'] ?? null,
                fn (Builder $query, int $parentId): Builder => $query->where('parent_id', $parentId),
            )
            ->when($term !== '', function (Builder $query) use ($term): void {
                $query->where(function (Builder $search) use ($term): void {
                    $search->where('code', 'like', $term.'%')
                        ->orWhere('name', 'like', $term.'%');
                });
            })
            ->orderBy('code')
            ->orderBy('id')
            ->paginate($perPage)
            ->withQueryString();
    }

    /** @param array<string, mixed> $attributes */
    public function create(
        User $actor,
        ResolvedTenantContext $context,
        array $attributes,
    ): EnterpriseFinanceCostCenter {
        $scope = $this->authorizedScope($actor, $context, self::MANAGE_PERMISSION, self::MANAGE_CAPABILITY);
        $data = $this->validatedCostCenter($attributes);
        $this->assertCodeAvailable($scope, (string) $data['code']);

        $parent = null;
        if (isset($data['parent_id'])) {
            $parent = $this->costCenterQuery($scope)->whereKey((int) $data['parent_id'])->firstOrFail();
            if ($parent->status !== 'active') {
                throw ValidationException::withMessages([
                    'parent_id' => ['A parent cost center must be active and in the same legal entity.'],
                ]);
            }
        }

        return DB::transaction(function () use (
            $actor,
            $context,
            $scope,
            $data,
            $parent,
        ): EnterpriseFinanceCostCenter {
            $costCenter = EnterpriseFinanceCostCenter::query()->create([
                'organization_id' => $scope->organizationId,
                'company_id' => $scope->companyId,
                'legal_entity_id' => $scope->legalEntityId,
                'parent_id' => $parent?->id,
                'code' => $data['code'],
                'name' => $data['name'],
                'status' => $data['status'] ?? 'active',
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
            ]);

            $this->audit->record(
                'enterprise.finance.cost_center_created',
                $actor,
                $costCenter,
                null,
                $this->auditSnapshot($costCenter->toArray(), $context),
            );

            return $costCenter->refresh();
        }, 3);
    }

    public function setStatus(
        User $actor,
        ResolvedTenantContext $context,
        int $costCenterId,
        string $status,
    ): EnterpriseFinanceCostCenter {
        $scope = $this->authorizedScope($actor, $context, self::MANAGE_PERMISSION, self::MANAGE_CAPABILITY);
        Validator::make(['status' => $status], [
            'status' => ['required', Rule::in(self::STATUSES)],
        ])->validate();

        return DB::transaction(function () use (
            $actor,
            $context,
            $scope,
            $costCenterId,
            $status,
        ): EnterpriseFinanceCostCenter {
            /** @var EnterpriseFinanceCostCenter $costCenter */
            $costCenter = $this->costCenterQuery($scope)->whereKey($costCenterId)->lockForUpdate()->firstOrFail();
            if ($costCenter->status === $status) {
                return $costCenter;
            }

            if (
                $status === 'inactive'
                && $this->costCenterQuery($scope)
                    ->where('parent_id', $costCenter->id)
                    ->where('status', 'active')
                    ->exists()
            ) {
                throw ValidationException::withMessages([
                    'status' => ['A cost center with active children cannot be made inactive.'],
                ]);
            }

            $before = $costCenter->toArray();
            $costCenter->forceFill([
                'status' => $status,
                'updated_by' => $actor->id,
            ])->save();

            $this->audit->record(
                'enterprise.finance.cost_center_status_changed',
                $actor,
                $costCenter,
                $this->auditSnapshot($before, $context),
                $this->auditSnapshot($costCenter->fresh()->toArray(), $context),
            );

            return $costCenter->refresh();
        }, 3);
    }

    private function authorizedScope(
        User $actor,
        ResolvedTenantContext $context,
        string $permission,
        string $capability,
    ): EnterpriseDataScope {
        abort_if($context->enterpriseScope === null, 409, 'Enterprise data scope is required.');

        $scope = $context->enterpriseScope;
        $this->authorizer->authorize(
            $actor,
            $context,
            $permission,
            'legal_entity',
            $scope->legalEntityId,
        );

        abort_unless(
            $this->entitlements->allows(
                self::MODULE,
                new EntitlementScope(EntitlementScope::LEGAL_ENTITY, $scope->legalEntityId),
                $capability,
            ),
            403,
        );

        return $scope;
    }

    /** @return Builder<EnterpriseFinanceCostCenter> */
    private function costCenterQuery(EnterpriseDataScope $scope): Builder
    {
        return EnterpriseFinanceCostCenter::query()
            ->where('organization_id', $scope->organizationId)
            ->where('company_id', $scope->companyId)
            ->where('legal_entity_id', $scope->legalEntityId);
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function validatedCostCenter(array $attributes): array
    {
        if (array_key_exists('code', $attributes)) {
            $attributes['code'] = strtoupper(trim((string) $attributes['code']));
        }
        if (array_key_exists('name', $attributes) && is_string($attributes['name'])) {
            $attributes['name'] = trim($attributes['name']);
        }

        return Validator::make($attributes, [
            'parent_id' => ['nullable', 'integer', 'min:1'],
            'code' => ['required', 'string', 'max:64', 'regex:/^[A-Z0-9][A-Z0-9._-]*$/'],
            'name' => ['required', 'string', 'max:190'],
            'status' => ['nullable', Rule::in(self::STATUSES)],
        ])->validate();
    }

    private function assertCodeAvailable(EnterpriseDataScope $scope, string $code): void
    {
        if ($this->costCenterQuery($scope)->where('code', $code)->exists()) {
            throw ValidationException::withMessages([
                'code' => ['Cost center code already exists in this legal entity.'],
            ]);
        }
    }

    private function safePrefix(string $term): string
    {
        return str_replace(['%', '_'], '', trim($term));
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function auditSnapshot(array $values, ResolvedTenantContext $context): array
    {
        return [
            ...$values,
            'store_id' => $context->storeId,
        ];
    }
}
