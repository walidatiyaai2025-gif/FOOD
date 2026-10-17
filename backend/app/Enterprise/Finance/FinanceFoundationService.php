<?php

namespace App\Enterprise\Finance;

use App\Enterprise\Entitlements\EntitlementScope;
use App\Enterprise\Entitlements\ModuleEntitlementResolver;
use App\Models\EnterpriseCostCenter;
use App\Models\EnterpriseFinanceAccount;
use App\Models\EnterpriseFiscalPeriod;
use App\Models\EnterpriseFiscalYear;
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

final class FinanceFoundationService
{
    public const MODULE = 'finance';

    private const VIEW_PERMISSION = 'finance.view';

    private const MANAGE_PERMISSION = 'finance.manage';

    private const READ_CAPABILITY = 'finance.foundation.read';

    private const MANAGE_CAPABILITY = 'finance.foundation.manage';

    private const ACCOUNT_TYPES = ['asset', 'liability', 'equity', 'revenue', 'expense'];

    private const NORMAL_BALANCES = ['debit', 'credit'];

    private const ACCOUNT_STATUSES = ['active', 'inactive'];

    private const PERIOD_STATUSES = ['open', 'closed'];

    public function __construct(
        private readonly EnterpriseScopeAuthorizer $authorizer,
        private readonly ModuleEntitlementResolver $entitlements,
        private readonly AuditLogger $audit,
        private readonly FinancePeriodCloseService $periodClose,
    ) {}

    /**
     * @param  array{q?:string,type?:string,status?:string,parent_id?:int}  $filters
     * @return LengthAwarePaginator<int, EnterpriseFinanceAccount>
     */
    public function paginateAccounts(
        User $actor,
        ResolvedTenantContext $context,
        array $filters = [],
        int $perPage = 25,
    ): LengthAwarePaginator {
        $scope = $this->authorizedScope($actor, $context, self::VIEW_PERMISSION, self::READ_CAPABILITY);
        $validated = Validator::make($filters, [
            'q' => ['nullable', 'string', 'max:120'],
            'type' => ['nullable', Rule::in(self::ACCOUNT_TYPES)],
            'status' => ['nullable', Rule::in(self::ACCOUNT_STATUSES)],
            'parent_id' => ['nullable', 'integer', 'min:1'],
        ])->validate();

        $term = $this->safePrefix((string) ($validated['q'] ?? ''));
        $perPage = max(10, min(100, $perPage));

        return $this->accountQuery($scope)
            ->when(
                $validated['type'] ?? null,
                fn (Builder $query, string $type): Builder => $query->where('type', $type),
            )
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
    public function createAccount(
        User $actor,
        ResolvedTenantContext $context,
        array $attributes,
    ): EnterpriseFinanceAccount {
        $scope = $this->authorizedScope($actor, $context, self::MANAGE_PERMISSION, self::MANAGE_CAPABILITY);
        $data = $this->validatedAccount($attributes);
        $this->assertAccountCodeAvailable($scope, (string) $data['code']);

        $parent = null;
        if (isset($data['parent_id'])) {
            $parent = $this->accountQuery($scope)->whereKey((int) $data['parent_id'])->firstOrFail();
            if (! $parent->is_control || $parent->status !== 'active') {
                throw ValidationException::withMessages([
                    'parent_id' => ['A parent account must be an active control account in the same legal entity.'],
                ]);
            }
        }

        $isControl = (bool) ($data['is_control'] ?? false);
        $allowPosting = array_key_exists('allow_posting', $data)
            ? (bool) $data['allow_posting']
            : ! $isControl;

        if ($isControl && $allowPosting) {
            throw ValidationException::withMessages([
                'allow_posting' => ['Control accounts cannot allow direct posting.'],
            ]);
        }

        return DB::transaction(function () use (
            $actor,
            $context,
            $scope,
            $data,
            $parent,
            $isControl,
            $allowPosting,
        ): EnterpriseFinanceAccount {
            $account = EnterpriseFinanceAccount::query()->create([
                'organization_id' => $scope->organizationId,
                'company_id' => $scope->companyId,
                'legal_entity_id' => $scope->legalEntityId,
                'parent_id' => $parent?->id,
                'code' => $data['code'],
                'name' => $data['name'],
                'type' => $data['type'],
                'normal_balance' => $data['normal_balance'],
                'is_control' => $isControl,
                'allow_posting' => $allowPosting,
                'status' => $data['status'] ?? 'active',
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
            ]);

            $this->audit->record(
                'enterprise.finance.account_created',
                $actor,
                $account,
                null,
                $this->auditSnapshot($account->toArray(), $context),
            );

            return $account->refresh();
        }, 3);
    }

    public function setAccountStatus(
        User $actor,
        ResolvedTenantContext $context,
        int $accountId,
        string $status,
    ): EnterpriseFinanceAccount {
        $scope = $this->authorizedScope($actor, $context, self::MANAGE_PERMISSION, self::MANAGE_CAPABILITY);
        Validator::make(['status' => $status], [
            'status' => ['required', Rule::in(self::ACCOUNT_STATUSES)],
        ])->validate();

        return DB::transaction(function () use ($actor, $context, $scope, $accountId, $status): EnterpriseFinanceAccount {
            /** @var EnterpriseFinanceAccount $account */
            $account = $this->accountQuery($scope)->whereKey($accountId)->lockForUpdate()->firstOrFail();
            if ($account->status === $status) {
                return $account;
            }

            $before = $account->toArray();
            $account->forceFill([
                'status' => $status,
                'updated_by' => $actor->id,
            ])->save();

            $this->audit->record(
                'enterprise.finance.account_status_changed',
                $actor,
                $account,
                $this->auditSnapshot($before, $context),
                $this->auditSnapshot($account->fresh()->toArray(), $context),
            );

            return $account->refresh();
        }, 3);
    }

    /**
     * @param  array{q?:string,status?:string,parent_id?:int}  $filters
     * @return LengthAwarePaginator<int, EnterpriseCostCenter>
     */
    public function paginateCostCenters(
        User $actor,
        ResolvedTenantContext $context,
        array $filters = [],
        int $perPage = 25,
    ): LengthAwarePaginator {
        $scope = $this->authorizedScope($actor, $context, self::VIEW_PERMISSION, self::READ_CAPABILITY);
        $validated = Validator::make($filters, [
            'q' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', Rule::in(self::ACCOUNT_STATUSES)],
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
    public function createCostCenter(
        User $actor,
        ResolvedTenantContext $context,
        array $attributes,
    ): EnterpriseCostCenter {
        $scope = $this->authorizedScope($actor, $context, self::MANAGE_PERMISSION, self::MANAGE_CAPABILITY);
        $data = $this->validatedCostCenter($attributes);
        $this->assertCostCenterCodeAvailable($scope, (string) $data['code']);

        $parent = null;
        if (isset($data['parent_id'])) {
            $parent = $this->costCenterQuery($scope)->whereKey((int) $data['parent_id'])->firstOrFail();
            if ($parent->status !== 'active') {
                throw ValidationException::withMessages([
                    'parent_id' => ['A parent cost center must be active in the same legal entity.'],
                ]);
            }
        }

        return DB::transaction(function () use ($actor, $context, $scope, $data, $parent): EnterpriseCostCenter {
            $costCenter = EnterpriseCostCenter::query()->create([
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

    public function setCostCenterStatus(
        User $actor,
        ResolvedTenantContext $context,
        int $costCenterId,
        string $status,
    ): EnterpriseCostCenter {
        $scope = $this->authorizedScope($actor, $context, self::MANAGE_PERMISSION, self::MANAGE_CAPABILITY);
        Validator::make(['status' => $status], [
            'status' => ['required', Rule::in(self::ACCOUNT_STATUSES)],
        ])->validate();

        return DB::transaction(function () use ($actor, $context, $scope, $costCenterId, $status): EnterpriseCostCenter {
            /** @var EnterpriseCostCenter $costCenter */
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

    /**
     * @param  array{q?:string,status?:string}  $filters
     * @return LengthAwarePaginator<int, EnterpriseFiscalYear>
     */
    public function paginateFiscalYears(
        User $actor,
        ResolvedTenantContext $context,
        array $filters = [],
        int $perPage = 25,
    ): LengthAwarePaginator {
        $scope = $this->authorizedScope($actor, $context, self::VIEW_PERMISSION, self::READ_CAPABILITY);
        $validated = Validator::make($filters, [
            'q' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', Rule::in(self::PERIOD_STATUSES)],
        ])->validate();

        $term = $this->safePrefix((string) ($validated['q'] ?? ''));
        $perPage = max(10, min(100, $perPage));

        return $this->fiscalYearQuery($scope)
            ->when(
                $validated['status'] ?? null,
                fn (Builder $query, string $status): Builder => $query->where('status', $status),
            )
            ->when($term !== '', function (Builder $query) use ($term): void {
                $query->where(function (Builder $search) use ($term): void {
                    $search->where('code', 'like', $term.'%')
                        ->orWhere('name', 'like', $term.'%');
                });
            })
            ->orderByDesc('starts_on')
            ->orderByDesc('id')
            ->paginate($perPage)
            ->withQueryString();
    }

    /** @param array<string, mixed> $attributes */
    public function createFiscalYear(
        User $actor,
        ResolvedTenantContext $context,
        array $attributes,
    ): EnterpriseFiscalYear {
        $scope = $this->authorizedScope($actor, $context, self::MANAGE_PERMISSION, self::MANAGE_CAPABILITY);
        $data = $this->validatedDateRange($attributes, false);
        $this->assertFiscalYearCodeAvailable($scope, (string) $data['code']);

        $startsOn = CarbonImmutable::parse((string) $data['starts_on'])->toDateString();
        $endsOn = CarbonImmutable::parse((string) $data['ends_on'])->toDateString();

        $overlap = $this->fiscalYearQuery($scope)
            ->whereDate('starts_on', '<=', $endsOn)
            ->whereDate('ends_on', '>=', $startsOn)
            ->exists();

        if ($overlap) {
            throw ValidationException::withMessages([
                'starts_on' => ['Fiscal years cannot overlap within the same legal entity.'],
            ]);
        }

        return DB::transaction(function () use (
            $actor,
            $context,
            $scope,
            $data,
            $startsOn,
            $endsOn,
        ): EnterpriseFiscalYear {
            $year = EnterpriseFiscalYear::query()->create([
                'organization_id' => $scope->organizationId,
                'company_id' => $scope->companyId,
                'legal_entity_id' => $scope->legalEntityId,
                'code' => $data['code'],
                'name' => $data['name'],
                'starts_on' => $startsOn,
                'ends_on' => $endsOn,
                'status' => 'open',
                'created_by' => $actor->id,
            ]);

            $this->audit->record(
                'enterprise.finance.fiscal_year_created',
                $actor,
                $year,
                null,
                $this->auditSnapshot($year->toArray(), $context),
            );

            return $year->refresh();
        }, 3);
    }

    /**
     * @param  array{q?:string,status?:string,fiscal_year_id?:int}  $filters
     * @return LengthAwarePaginator<int, EnterpriseFiscalPeriod>
     */
    public function paginateFiscalPeriods(
        User $actor,
        ResolvedTenantContext $context,
        array $filters = [],
        int $perPage = 25,
    ): LengthAwarePaginator {
        $scope = $this->authorizedScope($actor, $context, self::VIEW_PERMISSION, self::READ_CAPABILITY);
        $validated = Validator::make($filters, [
            'q' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', Rule::in(self::PERIOD_STATUSES)],
            'fiscal_year_id' => ['nullable', 'integer', 'min:1'],
        ])->validate();

        $term = $this->safePrefix((string) ($validated['q'] ?? ''));
        $perPage = max(10, min(100, $perPage));

        return $this->fiscalPeriodQuery($scope)
            ->when(
                $validated['status'] ?? null,
                fn (Builder $query, string $status): Builder => $query->where('status', $status),
            )
            ->when(
                $validated['fiscal_year_id'] ?? null,
                fn (Builder $query, int $yearId): Builder => $query->where('fiscal_year_id', $yearId),
            )
            ->when($term !== '', function (Builder $query) use ($term): void {
                $query->where(function (Builder $search) use ($term): void {
                    $search->where('code', 'like', $term.'%')
                        ->orWhere('name', 'like', $term.'%');
                });
            })
            ->orderByDesc('starts_on')
            ->orderBy('sequence')
            ->orderBy('id')
            ->paginate($perPage)
            ->withQueryString();
    }

    /** @param array<string, mixed> $attributes */
    public function createFiscalPeriod(
        User $actor,
        ResolvedTenantContext $context,
        int $fiscalYearId,
        array $attributes,
    ): EnterpriseFiscalPeriod {
        $scope = $this->authorizedScope($actor, $context, self::MANAGE_PERMISSION, self::MANAGE_CAPABILITY);
        $year = $this->fiscalYearQuery($scope)->whereKey($fiscalYearId)->firstOrFail();
        abort_if($year->status !== 'open', 409, 'Fiscal periods can only be added to an open fiscal year.');

        $data = $this->validatedDateRange($attributes, true);
        $startsOn = CarbonImmutable::parse((string) $data['starts_on'])->toDateString();
        $endsOn = CarbonImmutable::parse((string) $data['ends_on'])->toDateString();

        if (
            CarbonImmutable::parse($startsOn)->lt($year->starts_on)
            || CarbonImmutable::parse($endsOn)->gt($year->ends_on)
        ) {
            throw ValidationException::withMessages([
                'starts_on' => ['Fiscal period dates must remain inside the fiscal year.'],
            ]);
        }

        $overlap = $this->fiscalPeriodQuery($scope)
            ->where('fiscal_year_id', $year->id)
            ->whereDate('starts_on', '<=', $endsOn)
            ->whereDate('ends_on', '>=', $startsOn)
            ->exists();

        if ($overlap) {
            throw ValidationException::withMessages([
                'starts_on' => ['Fiscal periods cannot overlap within the same fiscal year.'],
            ]);
        }

        return DB::transaction(function () use (
            $actor,
            $context,
            $scope,
            $year,
            $data,
            $startsOn,
            $endsOn,
        ): EnterpriseFiscalPeriod {
            $period = EnterpriseFiscalPeriod::query()->create([
                'organization_id' => $scope->organizationId,
                'company_id' => $scope->companyId,
                'legal_entity_id' => $scope->legalEntityId,
                'fiscal_year_id' => $year->id,
                'sequence' => $data['sequence'],
                'code' => $data['code'],
                'name' => $data['name'],
                'starts_on' => $startsOn,
                'ends_on' => $endsOn,
                'status' => 'open',
                'created_by' => $actor->id,
            ]);

            $this->audit->record(
                'enterprise.finance.fiscal_period_created',
                $actor,
                $period,
                null,
                $this->auditSnapshot($period->toArray(), $context),
            );

            return $period->refresh();
        }, 3);
    }

    public function closeFiscalPeriod(
        User $actor,
        ResolvedTenantContext $context,
        int $periodId,
    ): EnterpriseFiscalPeriod {
        return $this->changeFiscalPeriodState($actor, $context, $periodId, 'closed');
    }

    public function reopenFiscalPeriod(
        User $actor,
        ResolvedTenantContext $context,
        int $periodId,
    ): EnterpriseFiscalPeriod {
        return $this->changeFiscalPeriodState($actor, $context, $periodId, 'open');
    }

    public function closeFiscalYear(
        User $actor,
        ResolvedTenantContext $context,
        int $fiscalYearId,
    ): EnterpriseFiscalYear {
        $scope = $this->authorizedScope($actor, $context, self::MANAGE_PERMISSION, self::MANAGE_CAPABILITY);

        return DB::transaction(function () use ($actor, $context, $scope, $fiscalYearId): EnterpriseFiscalYear {
            /** @var EnterpriseFiscalYear $year */
            $year = $this->fiscalYearQuery($scope)->whereKey($fiscalYearId)->lockForUpdate()->firstOrFail();
            if ($year->status === 'closed') {
                return $year;
            }

            $periods = $this->fiscalPeriodQuery($scope)
                ->where('fiscal_year_id', $year->id)
                ->lockForUpdate()
                ->get(['id', 'status']);

            if ($periods->isEmpty()) {
                throw ValidationException::withMessages([
                    'fiscal_year' => ['A fiscal year cannot close before at least one period exists.'],
                ]);
            }

            if ($periods->contains(static fn (EnterpriseFiscalPeriod $period): bool => $period->status !== 'closed')) {
                throw ValidationException::withMessages([
                    'fiscal_year' => ['All fiscal periods must be closed before the fiscal year can close.'],
                ]);
            }

            $before = $year->toArray();
            $year->forceFill([
                'status' => 'closed',
                'closed_at' => now(),
                'closed_by' => $actor->id,
            ])->save();

            $this->audit->record(
                'enterprise.finance.fiscal_year_closed',
                $actor,
                $year,
                $this->auditSnapshot($before, $context),
                $this->auditSnapshot($year->fresh()->toArray(), $context),
            );

            return $year->refresh();
        }, 3);
    }

    public function reopenFiscalYear(
        User $actor,
        ResolvedTenantContext $context,
        int $fiscalYearId,
    ): EnterpriseFiscalYear {
        $scope = $this->authorizedScope($actor, $context, self::MANAGE_PERMISSION, self::MANAGE_CAPABILITY);

        return DB::transaction(function () use ($actor, $context, $scope, $fiscalYearId): EnterpriseFiscalYear {
            /** @var EnterpriseFiscalYear $year */
            $year = $this->fiscalYearQuery($scope)->whereKey($fiscalYearId)->lockForUpdate()->firstOrFail();
            if ($year->status === 'open') {
                return $year;
            }

            $before = $year->toArray();
            $year->forceFill([
                'status' => 'open',
                'closed_at' => null,
                'closed_by' => null,
            ])->save();

            $this->audit->record(
                'enterprise.finance.fiscal_year_reopened',
                $actor,
                $year,
                $this->auditSnapshot($before, $context),
                $this->auditSnapshot($year->fresh()->toArray(), $context),
            );

            return $year->refresh();
        }, 3);
    }

    private function changeFiscalPeriodState(
        User $actor,
        ResolvedTenantContext $context,
        int $periodId,
        string $targetStatus,
    ): EnterpriseFiscalPeriod {
        $scope = $this->authorizedScope($actor, $context, self::MANAGE_PERMISSION, self::MANAGE_CAPABILITY);
        if ($targetStatus === 'closed') {
            $this->periodClose->assertReady($actor, $context, $periodId);
        }

        return DB::transaction(function () use ($actor, $context, $scope, $periodId, $targetStatus): EnterpriseFiscalPeriod {
            /** @var EnterpriseFiscalPeriod $period */
            $period = $this->fiscalPeriodQuery($scope)->whereKey($periodId)->lockForUpdate()->firstOrFail();
            if ($period->status === $targetStatus) {
                return $period;
            }

            /** @var EnterpriseFiscalYear $year */
            $year = $this->fiscalYearQuery($scope)->whereKey($period->fiscal_year_id)->lockForUpdate()->firstOrFail();
            if ($targetStatus === 'open' && $year->status !== 'open') {
                throw ValidationException::withMessages([
                    'fiscal_period' => ['A period cannot reopen while its fiscal year is closed.'],
                ]);
            }

            $before = $period->toArray();
            $period->forceFill([
                'status' => $targetStatus,
                'closed_at' => $targetStatus === 'closed' ? now() : null,
                'closed_by' => $targetStatus === 'closed' ? $actor->id : null,
            ])->save();

            $event = $targetStatus === 'closed'
                ? 'enterprise.finance.fiscal_period_closed'
                : 'enterprise.finance.fiscal_period_reopened';

            $this->audit->record(
                $event,
                $actor,
                $period,
                $this->auditSnapshot($before, $context),
                $this->auditSnapshot($period->fresh()->toArray(), $context),
            );

            return $period->refresh();
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

    /** @return Builder<EnterpriseFinanceAccount> */
    private function accountQuery(EnterpriseDataScope $scope): Builder
    {
        return EnterpriseFinanceAccount::query()
            ->where('organization_id', $scope->organizationId)
            ->where('company_id', $scope->companyId)
            ->where('legal_entity_id', $scope->legalEntityId);
    }

    /** @return Builder<EnterpriseCostCenter> */
    private function costCenterQuery(EnterpriseDataScope $scope): Builder
    {
        return EnterpriseCostCenter::query()
            ->where('organization_id', $scope->organizationId)
            ->where('company_id', $scope->companyId)
            ->where('legal_entity_id', $scope->legalEntityId);
    }

    /** @return Builder<EnterpriseFiscalYear> */
    private function fiscalYearQuery(EnterpriseDataScope $scope): Builder
    {
        return EnterpriseFiscalYear::query()
            ->where('organization_id', $scope->organizationId)
            ->where('company_id', $scope->companyId)
            ->where('legal_entity_id', $scope->legalEntityId);
    }

    /** @return Builder<EnterpriseFiscalPeriod> */
    private function fiscalPeriodQuery(EnterpriseDataScope $scope): Builder
    {
        return EnterpriseFiscalPeriod::query()
            ->where('organization_id', $scope->organizationId)
            ->where('company_id', $scope->companyId)
            ->where('legal_entity_id', $scope->legalEntityId);
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function validatedAccount(array $attributes): array
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
            'type' => ['required', Rule::in(self::ACCOUNT_TYPES)],
            'normal_balance' => ['required', Rule::in(self::NORMAL_BALANCES)],
            'is_control' => ['nullable', 'boolean'],
            'allow_posting' => ['nullable', 'boolean'],
            'status' => ['nullable', Rule::in(self::ACCOUNT_STATUSES)],
        ])->validate();
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
            'status' => ['nullable', Rule::in(self::ACCOUNT_STATUSES)],
        ])->validate();
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function validatedDateRange(array $attributes, bool $period): array
    {
        if (array_key_exists('code', $attributes)) {
            $attributes['code'] = strtoupper(trim((string) $attributes['code']));
        }
        if (array_key_exists('name', $attributes) && is_string($attributes['name'])) {
            $attributes['name'] = trim($attributes['name']);
        }

        $rules = [
            'code' => ['required', 'string', 'max:64', 'regex:/^[A-Z0-9][A-Z0-9._-]*$/'],
            'name' => ['required', 'string', 'max:190'],
            'starts_on' => ['required', 'date'],
            'ends_on' => ['required', 'date', 'after_or_equal:starts_on'],
        ];

        if ($period) {
            $rules['sequence'] = ['required', 'integer', 'min:1', 'max:366'];
        }

        return Validator::make($attributes, $rules)->validate();
    }

    private function assertAccountCodeAvailable(EnterpriseDataScope $scope, string $code): void
    {
        if ($this->accountQuery($scope)->where('code', $code)->exists()) {
            throw ValidationException::withMessages([
                'code' => ['Account code already exists in this legal entity.'],
            ]);
        }
    }

    private function assertCostCenterCodeAvailable(EnterpriseDataScope $scope, string $code): void
    {
        if ($this->costCenterQuery($scope)->where('code', $code)->exists()) {
            throw ValidationException::withMessages([
                'code' => ['Cost center code already exists in this legal entity.'],
            ]);
        }
    }

    private function assertFiscalYearCodeAvailable(EnterpriseDataScope $scope, string $code): void
    {
        if ($this->fiscalYearQuery($scope)->where('code', $code)->exists()) {
            throw ValidationException::withMessages([
                'code' => ['Fiscal year code already exists in this legal entity.'],
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
