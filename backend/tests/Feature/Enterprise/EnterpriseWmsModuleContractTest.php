<?php

namespace Tests\Feature\Enterprise;

use App\Enterprise\Entitlements\EntitlementScope;
use App\Enterprise\Entitlements\ModuleEntitlementManager;
use App\Enterprise\Modules\ModuleLifecycleManager;
use App\Enterprise\Modules\ModuleManifestCatalog;
use App\Enterprise\Modules\ModuleRegistry;
use App\Enterprise\Modules\ModuleState;
use App\Enterprise\Organization\OrganizationHierarchyService;
use App\Enterprise\Wms\WarehouseScope;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use App\Models\UserStoreRole;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\TestCase;

final class EnterpriseWmsModuleContractTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_inventory_wms_manifest_is_data_only_and_default_disabled(): void
    {
        $queries = [];
        DB::listen(static function ($query) use (&$queries): void {
            $queries[] = (string) $query->sql;
        });

        $manifest = app(ModuleManifestCatalog::class)->require('inventory_wms');

        $this->assertSame('inventory_wms', $manifest->code);
        $this->assertSame('operations', $manifest->category);
        $this->assertSame(ModuleState::DISABLED, $manifest->defaultState);
        $this->assertContains('catalog', $manifest->requiredCore);
        $this->assertContains('module_registry', $manifest->requiredCore);
        $this->assertContains('inventory.view', $manifest->permissions);
        $this->assertContains('inventory.manage', $manifest->permissions);
        $this->assertContains('wms.read', $manifest->capabilities);
        $this->assertContains('wms.manage', $manifest->capabilities);
        $this->assertSame([], $queries);
    }

    public function test_wms_scope_fails_closed_until_module_is_enabled_and_store_entitled(): void
    {
        $fixture = $this->fixture('GATE');
        $registry = app(ModuleRegistry::class);
        $registry->syncManifest('inventory_wms');

        $this->assertSame(ModuleState::DISABLED, $registry->state('inventory_wms'));
        $this->assertWarehouseDenied($fixture['user'], $fixture['warehouse_id']);

        $lifecycle = app(ModuleLifecycleManager::class);
        $lifecycle->transition('inventory_wms', ModuleState::ENABLING);
        $lifecycle->transition('inventory_wms', ModuleState::ENABLED);

        $this->assertWarehouseDenied($fixture['user'], $fixture['warehouse_id']);

        app(ModuleEntitlementManager::class)->set(
            'inventory_wms',
            new EntitlementScope(EntitlementScope::STORE, $fixture['store_id']),
            true,
        );

        $this->assertSame(
            $fixture['warehouse_id'],
            app(WarehouseScope::class)->assertWarehouse($fixture['user'], $fixture['warehouse_id'])->id,
        );
    }

    public function test_store_entitlement_isolated_and_disable_reenable_preserves_wms_data(): void
    {
        $primary = $this->fixture('PRIMARY');
        $other = $this->fixture('OTHER');

        $registry = app(ModuleRegistry::class);
        $registry->syncManifest('inventory_wms');

        $lifecycle = app(ModuleLifecycleManager::class);
        $lifecycle->transition('inventory_wms', ModuleState::ENABLING);
        $lifecycle->transition('inventory_wms', ModuleState::ENABLED);

        app(ModuleEntitlementManager::class)->set(
            'inventory_wms',
            new EntitlementScope(EntitlementScope::STORE, $primary['store_id']),
            true,
        );

        $inventoryId = (int) DB::table('inventories')->insertGetId([
            'warehouse_id' => $primary['warehouse_id'],
            'product_id' => $primary['product_id'],
            'quantity' => 11,
            'reserved_quantity' => 3,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertSame(
            $primary['warehouse_id'],
            app(WarehouseScope::class)->assertWarehouse($primary['user'], $primary['warehouse_id'])->id,
        );
        $this->assertWarehouseDenied($other['user'], $other['warehouse_id']);

        $lifecycle->transition('inventory_wms', ModuleState::DISABLED);
        $this->assertWarehouseDenied($primary['user'], $primary['warehouse_id']);
        $this->assertDatabaseHas('inventories', [
            'id' => $inventoryId,
            'quantity' => 11,
            'reserved_quantity' => 3,
        ]);
        $this->assertDatabaseHas('enterprise_module_entitlements', [
            'module_code' => 'inventory_wms',
            'scope_key' => 'store:'.$primary['store_id'],
            'is_enabled' => true,
        ]);

        $lifecycle->transition('inventory_wms', ModuleState::ENABLING);
        $lifecycle->transition('inventory_wms', ModuleState::ENABLED);

        $this->assertSame(
            $primary['warehouse_id'],
            app(WarehouseScope::class)->assertWarehouse($primary['user'], $primary['warehouse_id'])->id,
        );
        $this->assertSame(11.0, (float) DB::table('inventories')->where('id', $inventoryId)->value('quantity'));
    }

    /**
     * @return array{store_id:int,warehouse_id:int,product_id:int,user:User}
     */
    private function fixture(string $suffix): array
    {
        $storeTypeId = (int) DB::table('store_types')->where('code', 'B2C')->value('id');
        $store = Store::query()->create([
            'store_type_id' => $storeTypeId,
            'code' => 'WMS-MODULE-'.$suffix,
            'name' => 'WMS Module '.$suffix,
            'is_active' => true,
        ]);
        app(OrganizationHierarchyService::class)->ensureDefaultScopeForStore($store);

        $unitId = (int) DB::table('units')->insertGetId([
            'code' => 'EA-WMS-MODULE-'.$suffix,
            'name' => 'Each',
            'decimal_places' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $catalogId = (int) DB::table('catalogs')->insertGetId([
            'store_id' => $store->id,
            'channel' => 'b2c',
            'code' => 'wms-module-'.$suffix,
            'name' => 'WMS Module Catalog '.$suffix,
            'is_active' => true,
            'is_migration_quarantine' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $productId = (int) DB::table('products')->insertGetId([
            'catalog_id' => $catalogId,
            'unit_id' => $unitId,
            'sku' => 'WMS-MODULE-P-'.$suffix,
            'name' => 'WMS Module Product '.$suffix,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $warehouseId = (int) DB::table('warehouses')->insertGetId([
            'store_id' => $store->id,
            'code' => 'WMS-MODULE-W-'.$suffix,
            'name' => 'WMS Module Warehouse '.$suffix,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $user = User::query()->create([
            'name' => 'WMS Module '.$suffix,
            'email' => strtolower('wms-module-'.$suffix).'@example.test',
            'password' => 'password',
            'is_active' => true,
        ]);
        $role = Role::query()->where('code', 'RETAIL_INVENTORY')->firstOrFail();
        UserStoreRole::query()->create([
            'user_id' => $user->id,
            'store_id' => $store->id,
            'role_id' => $role->id,
        ]);

        return [
            'store_id' => (int) $store->id,
            'warehouse_id' => $warehouseId,
            'product_id' => $productId,
            'user' => $user,
        ];
    }

    private function assertWarehouseDenied(User $user, int $warehouseId): void
    {
        try {
            app(WarehouseScope::class)->assertWarehouse($user, $warehouseId);
            $this->fail('Expected WMS module access to fail closed.');
        } catch (NotFoundHttpException) {
            $this->assertTrue(true);
        }
    }
}
