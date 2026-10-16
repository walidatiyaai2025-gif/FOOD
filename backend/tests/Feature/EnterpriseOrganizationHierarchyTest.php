<?php

namespace Tests\Feature;

use App\Enterprise\Organization\OrganizationHierarchyService;
use App\Models\EnterpriseBusinessUnit;
use App\Models\EnterpriseCompany;
use App\Models\EnterpriseLegalEntity;
use App\Models\EnterpriseOrganization;
use App\Models\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LogicException;
use Tests\TestCase;

final class EnterpriseOrganizationHierarchyTest extends TestCase
{
    use RefreshDatabase;

    public function test_default_hierarchy_exists_without_changing_current_store_contract(): void
    {
        $this->assertDatabaseHas('enterprise_organizations', [
            'code' => OrganizationHierarchyService::DEFAULT_ORGANIZATION,
        ]);

        $store = $this->createStore('W0-ORG-STORE');
        $scope = app(OrganizationHierarchyService::class)->ensureDefaultScopeForStore($store);

        $this->assertSame($store->id, $scope->store_id);
        $this->assertSame('W0-ORG-STORE', $store->fresh()->code);
        $this->assertTrue((bool) $store->fresh()->is_active);
    }

    public function test_scope_resolves_full_organization_chain_for_store(): void
    {
        $store = $this->createStore('W0-SCOPE-STORE');
        app(OrganizationHierarchyService::class)->ensureDefaultScopeForStore($store);

        $scope = app(OrganizationHierarchyService::class)->scopeForStore($store->id);

        $this->assertNotNull($scope);
        $this->assertSame($store->id, $scope['store_id']);
        $this->assertGreaterThan(0, $scope['organization_id']);
        $this->assertGreaterThan(0, $scope['company_id']);
        $this->assertGreaterThan(0, $scope['legal_entity_id']);
        $this->assertGreaterThan(0, $scope['business_unit_id']);
    }

    public function test_store_cannot_be_silently_reassigned_across_organizations(): void
    {
        $store = $this->createStore('W0-BOUNDARY-STORE');
        $service = app(OrganizationHierarchyService::class);
        $service->ensureDefaultScopeForStore($store);

        $organization = EnterpriseOrganization::query()->create([
            'code' => 'other_org',
            'name' => 'Other Organization',
            'is_active' => true,
        ]);
        $company = EnterpriseCompany::query()->create([
            'organization_id' => $organization->id,
            'code' => 'other_company',
            'name' => 'Other Company',
            'is_active' => true,
        ]);
        $legalEntity = EnterpriseLegalEntity::query()->create([
            'company_id' => $company->id,
            'code' => 'other_entity',
            'name' => 'Other Legal Entity',
            'is_active' => true,
        ]);
        $businessUnit = EnterpriseBusinessUnit::query()->create([
            'legal_entity_id' => $legalEntity->id,
            'code' => 'other_unit',
            'name' => 'Other Business Unit',
            'is_active' => true,
        ]);

        $this->expectException(LogicException::class);
        $service->assignStore($store, $businessUnit);
    }

    public function test_upgrade_backfills_preexisting_stores_without_resetting_them(): void
    {
        $migration = require database_path(
            'migrations/2026_10_15_125000_create_enterprise_organization_hierarchy.php',
        );
        $migration->down();

        $store = $this->createStore('W0-UPGRADE-STORE');
        $migration->up();

        $this->assertDatabaseHas('stores', ['id' => $store->id, 'code' => 'W0-UPGRADE-STORE']);
        $this->assertDatabaseHas('enterprise_store_scopes', ['store_id' => $store->id]);
    }

    public function test_hierarchy_migration_is_additive_and_reversible(): void
    {
        $migration = require database_path(
            'migrations/2026_10_15_125000_create_enterprise_organization_hierarchy.php',
        );

        $migration->down();
        $this->assertFalse(Schema::hasTable('enterprise_organizations'));
        $this->assertTrue(Schema::hasTable('stores'));

        $migration->up();
        $this->assertTrue(Schema::hasTable('enterprise_store_scopes'));
        $this->assertTrue(Schema::hasTable('stores'));
    }

    private function createStore(string $code): Store
    {
        $typeId = DB::table('store_types')->insertGetId([
            'code' => 'W0-'.substr($code, -12),
            'name' => 'Wave 0 Type',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return Store::query()->create([
            'store_type_id' => $typeId,
            'code' => $code,
            'name' => $code,
            'is_active' => true,
        ]);
    }
}
