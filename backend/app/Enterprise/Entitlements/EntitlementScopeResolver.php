<?php

namespace App\Enterprise\Entitlements;

use Illuminate\Support\Facades\DB;

final class EntitlementScopeResolver
{
    /**
     * Narrowest scope first. The first explicit entitlement is authoritative,
     * allowing a child scope to deny a broader inherited entitlement.
     *
     * @return list<EntitlementScope>
     */
    public function lineage(EntitlementScope $scope): array
    {
        if ($scope->type === EntitlementScope::PLATFORM) {
            return [EntitlementScope::platform()];
        }

        if ($scope->type === EntitlementScope::ORGANIZATION) {
            return $this->organizationLineage((int) $scope->id);
        }

        if ($scope->type === EntitlementScope::COMPANY) {
            return $this->companyLineage((int) $scope->id);
        }

        if ($scope->type === EntitlementScope::LEGAL_ENTITY) {
            return $this->legalEntityLineage((int) $scope->id);
        }

        if ($scope->type === EntitlementScope::BUSINESS_UNIT) {
            return $this->businessUnitLineage((int) $scope->id);
        }

        if ($scope->type === EntitlementScope::STORE) {
            return $this->storeLineage((int) $scope->id);
        }

        return [];
    }

    /**
     * @return list<EntitlementScope>
     */
    private function organizationLineage(int $organizationId): array
    {
        $exists = DB::table('enterprise_organizations')
            ->where('id', $organizationId)
            ->where('is_active', true)
            ->exists();

        return $exists
            ? [new EntitlementScope(EntitlementScope::ORGANIZATION, $organizationId), EntitlementScope::platform()]
            : [];
    }

    /**
     * @return list<EntitlementScope>
     */
    private function companyLineage(int $companyId): array
    {
        $row = DB::table('enterprise_companies')
            ->join('enterprise_organizations', 'enterprise_organizations.id', '=', 'enterprise_companies.organization_id')
            ->where('enterprise_companies.id', $companyId)
            ->where('enterprise_companies.is_active', true)
            ->where('enterprise_organizations.is_active', true)
            ->first([
                'enterprise_companies.id as company_id',
                'enterprise_organizations.id as organization_id',
            ]);

        if ($row === null) {
            return [];
        }

        return [
            new EntitlementScope(EntitlementScope::COMPANY, (int) $row->company_id),
            new EntitlementScope(EntitlementScope::ORGANIZATION, (int) $row->organization_id),
            EntitlementScope::platform(),
        ];
    }

    /**
     * @return list<EntitlementScope>
     */
    private function legalEntityLineage(int $legalEntityId): array
    {
        $row = DB::table('enterprise_legal_entities')
            ->join('enterprise_companies', 'enterprise_companies.id', '=', 'enterprise_legal_entities.company_id')
            ->join('enterprise_organizations', 'enterprise_organizations.id', '=', 'enterprise_companies.organization_id')
            ->where('enterprise_legal_entities.id', $legalEntityId)
            ->where('enterprise_legal_entities.is_active', true)
            ->where('enterprise_companies.is_active', true)
            ->where('enterprise_organizations.is_active', true)
            ->first([
                'enterprise_legal_entities.id as legal_entity_id',
                'enterprise_companies.id as company_id',
                'enterprise_organizations.id as organization_id',
            ]);

        if ($row === null) {
            return [];
        }

        return [
            new EntitlementScope(EntitlementScope::LEGAL_ENTITY, (int) $row->legal_entity_id),
            new EntitlementScope(EntitlementScope::COMPANY, (int) $row->company_id),
            new EntitlementScope(EntitlementScope::ORGANIZATION, (int) $row->organization_id),
            EntitlementScope::platform(),
        ];
    }

    /**
     * @return list<EntitlementScope>
     */
    private function businessUnitLineage(int $businessUnitId): array
    {
        $row = DB::table('enterprise_business_units')
            ->join('enterprise_legal_entities', 'enterprise_legal_entities.id', '=', 'enterprise_business_units.legal_entity_id')
            ->join('enterprise_companies', 'enterprise_companies.id', '=', 'enterprise_legal_entities.company_id')
            ->join('enterprise_organizations', 'enterprise_organizations.id', '=', 'enterprise_companies.organization_id')
            ->where('enterprise_business_units.id', $businessUnitId)
            ->where('enterprise_business_units.is_active', true)
            ->where('enterprise_legal_entities.is_active', true)
            ->where('enterprise_companies.is_active', true)
            ->where('enterprise_organizations.is_active', true)
            ->first([
                'enterprise_business_units.id as business_unit_id',
                'enterprise_legal_entities.id as legal_entity_id',
                'enterprise_companies.id as company_id',
                'enterprise_organizations.id as organization_id',
            ]);

        if ($row === null) {
            return [];
        }

        return [
            new EntitlementScope(EntitlementScope::BUSINESS_UNIT, (int) $row->business_unit_id),
            new EntitlementScope(EntitlementScope::LEGAL_ENTITY, (int) $row->legal_entity_id),
            new EntitlementScope(EntitlementScope::COMPANY, (int) $row->company_id),
            new EntitlementScope(EntitlementScope::ORGANIZATION, (int) $row->organization_id),
            EntitlementScope::platform(),
        ];
    }

    /**
     * @return list<EntitlementScope>
     */
    private function storeLineage(int $storeId): array
    {
        $row = DB::table('enterprise_store_scopes')
            ->join('stores', 'stores.id', '=', 'enterprise_store_scopes.store_id')
            ->join('enterprise_business_units', 'enterprise_business_units.id', '=', 'enterprise_store_scopes.business_unit_id')
            ->join('enterprise_legal_entities', 'enterprise_legal_entities.id', '=', 'enterprise_business_units.legal_entity_id')
            ->join('enterprise_companies', 'enterprise_companies.id', '=', 'enterprise_legal_entities.company_id')
            ->join('enterprise_organizations', 'enterprise_organizations.id', '=', 'enterprise_companies.organization_id')
            ->where('enterprise_store_scopes.store_id', $storeId)
            ->where('stores.is_active', true)
            ->where('enterprise_business_units.is_active', true)
            ->where('enterprise_legal_entities.is_active', true)
            ->where('enterprise_companies.is_active', true)
            ->where('enterprise_organizations.is_active', true)
            ->first([
                'stores.id as store_id',
                'enterprise_business_units.id as business_unit_id',
                'enterprise_legal_entities.id as legal_entity_id',
                'enterprise_companies.id as company_id',
                'enterprise_organizations.id as organization_id',
            ]);

        if ($row === null) {
            return [];
        }

        return [
            new EntitlementScope(EntitlementScope::STORE, (int) $row->store_id),
            new EntitlementScope(EntitlementScope::BUSINESS_UNIT, (int) $row->business_unit_id),
            new EntitlementScope(EntitlementScope::LEGAL_ENTITY, (int) $row->legal_entity_id),
            new EntitlementScope(EntitlementScope::COMPANY, (int) $row->company_id),
            new EntitlementScope(EntitlementScope::ORGANIZATION, (int) $row->organization_id),
            EntitlementScope::platform(),
        ];
    }
}
