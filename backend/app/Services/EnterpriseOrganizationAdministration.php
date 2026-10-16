<?php

namespace App\Services;

use App\Enterprise\Organization\OrganizationHierarchyService;
use App\Models\EnterpriseBusinessUnit;
use App\Models\EnterpriseCompany;
use App\Models\EnterpriseLegalEntity;
use App\Models\EnterpriseOrganization;
use App\Models\Store;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class EnterpriseOrganizationAdministration
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly OrganizationHierarchyService $hierarchy,
    ) {}

    public function createOrganization(array $data, Authenticatable $actor): EnterpriseOrganization
    {
        return DB::transaction(function () use ($data, $actor): EnterpriseOrganization {
            $organization = EnterpriseOrganization::query()->create($data);
            $this->audit->record('enterprise.organization.created', $actor, $organization, null, $this->snapshot($organization));

            return $organization->refresh();
        });
    }

    public function updateOrganization(EnterpriseOrganization $organization, array $data, Authenticatable $actor): EnterpriseOrganization
    {
        if (($data['is_active'] ?? true) === false && $organization->companies()->where('is_active', true)->exists()) {
            throw ValidationException::withMessages(['is_active' => [__('enterprise_organization.active_children')]]);
        }

        return $this->update($organization, $data, $actor, 'enterprise.organization.updated');
    }

    public function createCompany(EnterpriseOrganization $organization, array $data, Authenticatable $actor): EnterpriseCompany
    {
        $this->assertActive($organization);

        return DB::transaction(function () use ($organization, $data, $actor): EnterpriseCompany {
            $company = $organization->companies()->create($data);
            $this->audit->record('enterprise.company.created', $actor, $company, null, $this->snapshot($company));

            return $company->refresh();
        });
    }

    public function updateCompany(EnterpriseCompany $company, array $data, Authenticatable $actor): EnterpriseCompany
    {
        if (($data['is_active'] ?? true) === false && $company->legalEntities()->where('is_active', true)->exists()) {
            throw ValidationException::withMessages(['is_active' => [__('enterprise_organization.active_children')]]);
        }

        return $this->update($company, $data, $actor, 'enterprise.company.updated');
    }

    public function createLegalEntity(EnterpriseCompany $company, array $data, Authenticatable $actor): EnterpriseLegalEntity
    {
        $this->assertActive($company);
        $this->assertActive($company->organization);

        return DB::transaction(function () use ($company, $data, $actor): EnterpriseLegalEntity {
            $entity = $company->legalEntities()->create($data);
            $this->audit->record('enterprise.legal_entity.created', $actor, $entity, null, $this->snapshot($entity));

            return $entity->refresh();
        });
    }

    public function updateLegalEntity(EnterpriseLegalEntity $entity, array $data, Authenticatable $actor): EnterpriseLegalEntity
    {
        if (($data['is_active'] ?? true) === false && $entity->businessUnits()->where('is_active', true)->exists()) {
            throw ValidationException::withMessages(['is_active' => [__('enterprise_organization.active_children')]]);
        }

        return $this->update($entity, $data, $actor, 'enterprise.legal_entity.updated');
    }

    public function createBusinessUnit(EnterpriseLegalEntity $entity, array $data, Authenticatable $actor): EnterpriseBusinessUnit
    {
        $entity->loadMissing('company.organization');
        $this->assertActive($entity);
        $this->assertActive($entity->company);
        $this->assertActive($entity->company->organization);

        return DB::transaction(function () use ($entity, $data, $actor): EnterpriseBusinessUnit {
            $unit = $entity->businessUnits()->create($data);
            $this->audit->record('enterprise.business_unit.created', $actor, $unit, null, $this->snapshot($unit));

            return $unit->refresh();
        });
    }

    public function updateBusinessUnit(EnterpriseBusinessUnit $unit, array $data, Authenticatable $actor): EnterpriseBusinessUnit
    {
        if (($data['is_active'] ?? true) === false && $unit->storeScopes()->exists()) {
            throw ValidationException::withMessages(['is_active' => [__('enterprise_organization.assigned_stores')]]);
        }

        return $this->update($unit, $data, $actor, 'enterprise.business_unit.updated');
    }

    public function assignStore(Store $store, EnterpriseBusinessUnit $unit, Authenticatable $actor): void
    {
        if (! $unit->is_active) {
            throw ValidationException::withMessages(['store' => [__('enterprise_organization.inactive_parent')]]);
        }

        try {
            $this->hierarchy->assignStore($store, $unit, $actor);
        } catch (\LogicException) {
            throw ValidationException::withMessages(['store' => [__('enterprise_organization.cross_scope_denied')]]);
        }
    }

    private function assertActive(Model $model): void
    {
        if (! (bool) $model->getAttribute('is_active')) {
            throw ValidationException::withMessages(['parent' => [__('enterprise_organization.inactive_parent')]]);
        }
    }

    private function update(Model $model, array $data, Authenticatable $actor, string $event): mixed
    {
        return DB::transaction(function () use ($model, $data, $actor, $event) {
            $before = $this->snapshot($model);
            $model->fill($data)->save();
            $this->audit->record($event, $actor, $model, $before, $this->snapshot($model));

            return $model->refresh();
        });
    }

    private function snapshot(Model $model): array
    {
        return [
            'code' => (string) $model->getAttribute('code'),
            'name' => (string) $model->getAttribute('name'),
            'is_active' => (bool) $model->getAttribute('is_active'),
        ];
    }
}
