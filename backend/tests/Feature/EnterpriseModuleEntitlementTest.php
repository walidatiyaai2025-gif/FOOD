<?php

namespace Tests\Feature;

use App\Enterprise\Entitlements\EntitlementScope;
use App\Enterprise\Entitlements\ModuleEntitlementManager;
use App\Enterprise\Entitlements\ModuleEntitlementResolver;
use App\Enterprise\Modules\ModuleLifecycleManager;
use App\Enterprise\Modules\ModuleRegistry;
use App\Enterprise\Modules\ModuleState;
use App\Enterprise\Organization\OrganizationHierarchyService;
use App\Models\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class EnterpriseModuleEntitlementTest extends TestCase
{
    use RefreshDatabase;

    public function test_disabled_module_short_circuits_before_entitlement_or_hierarchy_queries(): void
    {
        $this->configureSampleManifest();
        app(ModuleRegistry::class)->syncManifest('sample_optional');

        $queries = [];
        DB::listen(static function ($query) use (&$queries): void {
            $queries[] = (string) $query->sql;
        });

        $allowed = app(ModuleEntitlementResolver::class)->allows(
            'sample_optional',
            new EntitlementScope(EntitlementScope::STORE, 999999),
            'sample.read',
        );

        $this->assertFalse($allowed);
        $this->assertFalse(collect($queries)->contains(
            static fn (string $sql): bool => str_contains($sql, 'enterprise_module_entitlements')
                || str_contains($sql, 'enterprise_store_scopes'),
        ));
    }

    public function test_organization_entitlement_inherits_to_store_and_capability_subset_is_enforced(): void
    {
        $this->configureSampleManifest();
        $store = $this->createScopedStore('ENT-STORE-A');
        $this->enableSampleModule();

        $hierarchy = app(OrganizationHierarchyService::class)->scopeForStore($store->id);
        $this->assertNotNull($hierarchy);

        app(ModuleEntitlementManager::class)->set(
            'sample_optional',
            new EntitlementScope(EntitlementScope::ORGANIZATION, $hierarchy['organization_id']),
            true,
            ['sample.read'],
        );

        $resolver = app(ModuleEntitlementResolver::class);
        $scope = new EntitlementScope(EntitlementScope::STORE, $store->id);

        $this->assertTrue($resolver->allows('sample_optional', $scope));
        $this->assertTrue($resolver->allows('sample_optional', $scope, 'sample.read'));
        $this->assertFalse($resolver->allows('sample_optional', $scope, 'sample.write'));
    }

    public function test_explicit_store_deny_overrides_broader_organization_allow(): void
    {
        $this->configureSampleManifest();
        $store = $this->createScopedStore('ENT-STORE-B');
        $this->enableSampleModule();

        $hierarchy = app(OrganizationHierarchyService::class)->scopeForStore($store->id);
        $this->assertNotNull($hierarchy);

        $manager = app(ModuleEntitlementManager::class);
        $manager->set(
            'sample_optional',
            new EntitlementScope(EntitlementScope::ORGANIZATION, $hierarchy['organization_id']),
            true,
        );
        $manager->set(
            'sample_optional',
            new EntitlementScope(EntitlementScope::STORE, $store->id),
            false,
        );

        $this->assertFalse(app(ModuleEntitlementResolver::class)->allows(
            'sample_optional',
            new EntitlementScope(EntitlementScope::STORE, $store->id),
            'sample.read',
        ));
    }

    public function test_unknown_scope_and_unentitled_scope_deny_by_default(): void
    {
        $this->configureSampleManifest();
        $this->enableSampleModule();

        $this->assertFalse(app(ModuleEntitlementResolver::class)->allows(
            'sample_optional',
            new EntitlementScope(EntitlementScope::STORE, 999999),
        ));
    }

    public function test_manager_rejects_capability_not_declared_by_manifest(): void
    {
        $this->configureSampleManifest();

        $this->expectException(ValidationException::class);

        app(ModuleEntitlementManager::class)->set(
            'sample_optional',
            EntitlementScope::platform(),
            true,
            ['sample.unknown'],
        );
    }

    public function test_entitlement_changes_are_audited_without_deleting_module_data(): void
    {
        $this->configureSampleManifest();
        app(ModuleEntitlementManager::class)->set(
            'sample_optional',
            EntitlementScope::platform(),
            true,
            ['sample.read'],
        );
        app(ModuleEntitlementManager::class)->set(
            'sample_optional',
            EntitlementScope::platform(),
            false,
        );

        $this->assertDatabaseHas('enterprise_module_entitlements', [
            'module_code' => 'sample_optional',
            'scope_key' => 'platform:0',
            'is_enabled' => false,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'enterprise.module.entitlement_changed',
        ]);
    }

    public function test_entitlement_migration_is_additive_and_reversible(): void
    {
        $migration = require database_path(
            'migrations/2026_10_15_132000_create_enterprise_module_entitlements.php',
        );

        $migration->down();
        $this->assertFalse(Schema::hasTable('enterprise_module_entitlements'));
        $this->assertTrue(Schema::hasTable('enterprise_modules'));
        $this->assertTrue(Schema::hasTable('stores'));

        $migration->up();
        $this->assertTrue(Schema::hasTable('enterprise_module_entitlements'));
    }

    private function configureSampleManifest(): void
    {
        config()->set('enterprise_modules.modules.sample_optional.capabilities', [
            'sample.read',
            'sample.write',
        ]);
    }

    private function enableSampleModule(): void
    {
        $manager = app(ModuleLifecycleManager::class);
        $manager->transition('sample_optional', ModuleState::ENABLING);
        $manager->transition('sample_optional', ModuleState::ENABLED);
    }

    private function createScopedStore(string $code): Store
    {
        $typeId = DB::table('store_types')->insertGetId([
            'code' => 'TYPE-'.$code,
            'name' => 'Entitlement Test Type',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $store = Store::query()->create([
            'store_type_id' => $typeId,
            'code' => $code,
            'name' => $code,
            'is_active' => true,
        ]);

        app(OrganizationHierarchyService::class)->ensureDefaultScopeForStore($store);

        return $store;
    }
}
