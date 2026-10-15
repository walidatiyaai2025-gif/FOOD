<?php

namespace App\Enterprise\Organization;

use App\Models\EnterpriseBusinessUnit;
use App\Models\EnterpriseStoreScope;
use App\Models\Store;
use App\Services\AuditLogger;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use LogicException;

final class OrganizationHierarchyService
{
    public const DEFAULT_ORGANIZATION = 'foodex_platform';

    public const DEFAULT_COMPANY = 'default_company';

    public const DEFAULT_LEGAL_ENTITY = 'default_legal_entity';

    public const DEFAULT_BUSINESS_UNIT = 'legacy_operations';

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @return array{organization_id:int, company_id:int, legal_entity_id:int, business_unit_id:int, store_id:int}|null
     */
    public function scopeForStore(int $storeId): ?array
    {
        $row = DB::table('enterprise_store_scopes')
            ->join('enterprise_business_units', 'enterprise_business_units.id', '=', 'enterprise_store_scopes.business_unit_id')
            ->join('enterprise_legal_entities', 'enterprise_legal_entities.id', '=', 'enterprise_business_units.legal_entity_id')
            ->join('enterprise_companies', 'enterprise_companies.id', '=', 'enterprise_legal_entities.company_id')
            ->where('enterprise_store_scopes.store_id', $storeId)
            ->select([
                'enterprise_companies.organization_id',
                'enterprise_companies.id as company_id',
                'enterprise_legal_entities.id as legal_entity_id',
                'enterprise_business_units.id as business_unit_id',
                'enterprise_store_scopes.store_id',
            ])
            ->first();

        if ($row === null) {
            return null;
        }

        return [
            'organization_id' => (int) $row->organization_id,
            'company_id' => (int) $row->company_id,
            'legal_entity_id' => (int) $row->legal_entity_id,
            'business_unit_id' => (int) $row->business_unit_id,
            'store_id' => (int) $row->store_id,
        ];
    }

    public function ensureDefaultScopeForStore(
        Store $store,
        ?Authenticatable $actor = null,
    ): EnterpriseStoreScope {
        $existing = EnterpriseStoreScope::query()->where('store_id', $store->getKey())->first();
        if ($existing instanceof EnterpriseStoreScope) {
            return $existing;
        }

        $businessUnit = EnterpriseBusinessUnit::query()
            ->where('code', self::DEFAULT_BUSINESS_UNIT)
            ->whereHas('legalEntity.company.organization', function ($query): void {
                $query->where('code', self::DEFAULT_ORGANIZATION);
            })
            ->firstOrFail();

        return $this->assignStore($store, $businessUnit, $actor);
    }

    public function assignStore(
        Store $store,
        EnterpriseBusinessUnit $businessUnit,
        ?Authenticatable $actor = null,
    ): EnterpriseStoreScope {
        return DB::transaction(function () use ($store, $businessUnit, $actor): EnterpriseStoreScope {
            $businessUnit->loadMissing('legalEntity.company');
            $targetOrganizationId = (int) $businessUnit->legalEntity->company->organization_id;

            /** @var EnterpriseStoreScope|null $existing */
            $existing = EnterpriseStoreScope::query()
                ->where('store_id', $store->getKey())
                ->lockForUpdate()
                ->first();

            if ($existing instanceof EnterpriseStoreScope) {
                $current = $this->scopeForStore((int) $store->getKey());
                if ($current !== null && $current['organization_id'] !== $targetOrganizationId) {
                    throw new LogicException('A store cannot be silently moved across enterprise organizations.');
                }

                if ((int) $existing->business_unit_id === (int) $businessUnit->getKey()) {
                    return $existing;
                }
            }

            $scope = EnterpriseStoreScope::query()->updateOrCreate(
                ['store_id' => $store->getKey()],
                ['business_unit_id' => $businessUnit->getKey()],
            );

            $this->audit->record(
                'enterprise.organization.store_scope_assigned',
                $actor,
                $store,
                $existing === null ? null : ['business_unit_id' => (int) $existing->business_unit_id],
                ['business_unit_id' => (int) $businessUnit->getKey(), 'store_id' => (int) $store->getKey()],
            );

            return $scope->refresh();
        });
    }
}
