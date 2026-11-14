<?php

namespace Tests\Feature;

use App\Enterprise\Entitlements\EntitlementScope;
use App\Enterprise\Entitlements\ModuleEntitlementManager;
use App\Enterprise\Modules\ModuleLifecycleManager;
use App\Enterprise\Modules\ModuleRegistry;
use App\Enterprise\Modules\ModuleState;
use App\Enterprise\Wms\VanInventoryReadService;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Models\UserStoreRole;
use App\Models\Van;
use App\Models\VanAssignment;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Feature\Enterprise\Concerns\EnablesEnterpriseWms;
use Tests\TestCase;

final class V2VanInventoryReadAuthorityTest extends TestCase
{
    use EnablesEnterpriseWms;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
        config()->set(
            'enterprise_module_readiness.certified_versions.inventory_wms',
            app(ModuleRegistry::class)->manifest('inventory_wms')->version,
        );
    }

    public function test_active_primary_wholesale_assignment_drives_paginated_stock_not_home_or_other_warehouse(): void
    {
        $own = $this->store('B2B', 'P274-OWN');
        $foreign = $this->store('B2B', 'P274-FOREIGN');
        $warehouse = $this->warehouse($own, 'P274-ASSIGNED');
        $foreignWarehouse = $this->warehouse($foreign, 'P274-HOME');
        $this->enableEnterpriseWmsForStore($own);
        $this->enableEnterpriseWmsForStore($foreign);
        $viewer = $this->viewer($own);

        $van = $this->van($foreignWarehouse);
        $this->assign($van, $warehouse, $viewer);

        for ($i = 1; $i <= 51; $i++) {
            $this->stock($warehouse, $own, 'P274-OWN-'.$i);
        }
        $this->stock($foreignWarehouse, $foreign, 'P274-FOREIGN');

        $service = app(VanInventoryReadService::class);
        $first = $service->page($viewer, (int) $van->id);
        $this->assertSame($warehouse, $first['warehouse_id']);
        $this->assertSame($own, $first['store_id']);
        $this->assertCount(50, $first['rows']);
        $this->assertNotNull($first['next_cursor']);
        $this->assertSame(8.0, $first['rows']->first()['available_quantity']);
        $this->assertFalse($first['rows']->contains(fn ($row) => $row['sku'] === 'P274-FOREIGN'));

        $second = $service->page($viewer, (int) $van->id, $first['next_cursor']);
        $this->assertCount(1, $second['rows']);
        $this->assertNull($second['next_cursor']);
        $this->assertCount(
            51,
            $first['rows']->pluck('inventory_id')->merge($second['rows']->pluck('inventory_id'))->unique(),
        );
        $this->assertSame(0, DB::table('stock_movements')->count());
    }

    public function test_cross_store_reader_and_retail_assignment_fail_closed(): void
    {
        $own = $this->store('B2B', 'P274-OWN');
        $other = $this->store('B2B', 'P274-OTHER');
        $retail = $this->store('B2C', 'P274-RETAIL');
        $ownWarehouse = $this->warehouse($own, 'P274-OWN-WH');
        $otherWarehouse = $this->warehouse($other, 'P274-OTHER-WH');
        $retailWarehouse = $this->warehouse($retail, 'P274-RETAIL-WH');
        foreach ([$own, $other, $retail] as $storeId) {
            $this->enableEnterpriseWmsForStore($storeId);
        }
        $viewer = $this->viewer($own);
        $foreignVan = $this->van($ownWarehouse);
        $this->assign($foreignVan, $otherWarehouse, $viewer);

        // Wholesale roles are global by contract; a store-specific explicit
        // DENY is what removes the otherwise accessible foreign B2B store.
        $inventoryPermission = Permission::query()->where('code', 'inventory.view')->firstOrFail();
        DB::table('user_permission_overrides')->insert([
            'user_id' => $viewer->id,
            'permission_id' => $inventoryPermission->id,
            'scope_key' => 'store:'.$other,
            'store_id' => $other,
            'effect' => 'deny',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $viewer->forceFill(['has_permission_overrides' => true])->save();
        $viewer = $viewer->fresh();

        try {
            app(VanInventoryReadService::class)->page($viewer, (int) $foreignVan->id);
            $this->fail('Cross-store Van stock read must fail closed.');
        } catch (HttpException $exception) {
            $this->assertSame(404, $exception->getStatusCode());
        }

        // Synthetic global + B2B STORE grants cannot manufacture Wholesale
        // eligibility: only configured B2B global role codes may do so.
        $unrecognized = User::factory()->create(['is_active' => true]);
        $synthetic = Role::query()->create([
            'code' => 'P274_UNRECOGNIZED_'.Str::random(10),
            'name' => 'Unrecognized wholesale role',
            'scope' => 'global',
            'is_active' => true,
            'is_system' => false,
        ]);
        $synthetic->permissions()->sync(
            Permission::query()->whereIn('code', ['inventory.view', 'drivers.b2b.view'])->pluck('id')->all(),
        );
        $unrecognized->roles()->attach($synthetic->id);
        $unrecognizedVan = $this->van($ownWarehouse);
        $this->assign($unrecognizedVan, $ownWarehouse, $unrecognized);
        try {
            app(VanInventoryReadService::class)->page($unrecognized->fresh(), (int) $unrecognizedVan->id);
            $this->fail('An unregistered Wholesale global role must not grant store access.');
        } catch (HttpException $exception) {
            $this->assertSame(404, $exception->getStatusCode());
        }

        $retailViewer = $this->viewer($retail);
        $retailVan = $this->van($retailWarehouse);
        $this->assign($retailVan, $retailWarehouse, $retailViewer);

        try {
            app(VanInventoryReadService::class)->page($retailViewer, (int) $retailVan->id);
            $this->fail('Van stock must never use a B2C warehouse.');
        } catch (HttpException $exception) {
            $this->assertSame(404, $exception->getStatusCode());
        }
    }

    public function test_store_specific_van_view_deny_blocks_stock_even_with_inventory_view_granted(): void
    {
        $storeId = $this->store('B2B', 'P274-VAN-VIEW-DENY');
        $warehouse = $this->warehouse($storeId, 'P274-VAN-VIEW-DENY-WH');
        $this->enableEnterpriseWmsForStore($storeId);
        $viewer = $this->viewer($storeId);
        $van = $this->van($warehouse);
        $this->assign($van, $warehouse, $viewer);
        $this->stock($warehouse, $storeId, 'P274-VAN-VIEW-SKU');

        $service = app(VanInventoryReadService::class);
        $this->assertCount(1, $service->page($viewer, (int) $van->id)['rows']);

        // Retain inventory.view for this store, but deny the independent Van
        // read permission. A B2B global role must not override store DENY.
        $vanView = Permission::query()->where('code', 'drivers.b2b.view')->firstOrFail();
        DB::table('user_permission_overrides')->insert([
            'user_id' => $viewer->id,
            'permission_id' => $vanView->id,
            'scope_key' => 'store:'.$storeId,
            'store_id' => $storeId,
            'effect' => 'deny',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $viewer->forceFill(['has_permission_overrides' => true])->save();
        $viewer = $viewer->fresh();

        $this->assertTrue($viewer->hasPermission('inventory.view', $storeId));
        try {
            $service->page($viewer, (int) $van->id);
            $this->fail('Store-specific Van view DENY must reject stock reads.');
        } catch (HttpException $exception) {
            $this->assertSame(404, $exception->getStatusCode());
        }
        $this->assertSame(0, DB::table('stock_movements')->count());
    }

    public function test_revoked_store_entitlement_and_disabled_wms_block_stock_reads(): void
    {
        $storeId = $this->store('B2B', 'P274-ENTITLEMENT');
        $warehouse = $this->warehouse($storeId, 'P274-ENTITLEMENT-WH');
        $this->enableEnterpriseWmsForStore($storeId);
        $viewer = $this->viewer($storeId);
        $van = $this->van($warehouse);
        $this->assign($van, $warehouse, $viewer);

        $this->assertCount(0, app(VanInventoryReadService::class)->page($viewer, (int) $van->id)['rows']);
        app(ModuleEntitlementManager::class)->set(
            'inventory_wms',
            new EntitlementScope(EntitlementScope::STORE, $storeId),
            false,
        );

        try {
            app(VanInventoryReadService::class)->page($viewer, (int) $van->id);
            $this->fail('Missing WMS entitlement must fail closed.');
        } catch (HttpException $exception) {
            $this->assertSame(404, $exception->getStatusCode());
        }

        app(ModuleEntitlementManager::class)->set(
            'inventory_wms',
            new EntitlementScope(EntitlementScope::STORE, $storeId),
            true,
        );
        app(ModuleLifecycleManager::class)->transition('inventory_wms', ModuleState::DISABLED);

        try {
            app(VanInventoryReadService::class)->page($viewer, (int) $van->id);
            $this->fail('Disabled WMS must fail closed.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
        $this->assertSame(0, DB::table('stock_movements')->count());
    }

    public function test_overlapping_primary_van_assignments_cannot_choose_an_arbitrary_warehouse(): void
    {
        $own = $this->store('B2B', 'P274-AMBIGUOUS-OWN');
        $foreign = $this->store('B2B', 'P274-AMBIGUOUS-FOREIGN');
        $ownWarehouse = $this->warehouse($own, 'P274-AMBIGUOUS-OWN-WH');
        $foreignWarehouse = $this->warehouse($foreign, 'P274-AMBIGUOUS-FOREIGN-WH');
        $this->enableEnterpriseWmsForStore($own);
        $viewer = $this->viewer($own);
        $van = $this->van($ownWarehouse);
        $this->assign($van, $ownWarehouse, $viewer);
        $this->stock($ownWarehouse, $own, 'P274-AMBIGUOUS-STOCK');

        // A valid single primary assignment remains readable.
        $this->assertCount(1, app(VanInventoryReadService::class)->page($viewer, (int) $van->id)['rows']);

        // Bypass registry validation to model corrupt imported/legacy data:
        // a second overlapping primary assignment points at another store.
        $this->assign($van, $foreignWarehouse, $viewer);
        try {
            app(VanInventoryReadService::class)->page($viewer, (int) $van->id);
            $this->fail('Conflicting effective primary Van assignments must fail closed.');
        } catch (HttpException $exception) {
            $this->assertSame(404, $exception->getStatusCode());
        }

        $this->assertSame(0, DB::table('stock_movements')->count());
    }

    public function test_b2c_or_inactive_driver_on_primary_van_assignment_cannot_expose_b2b_stock(): void
    {
        $storeId = $this->store('B2B', 'P274-DRIVER-CHANNEL-B2B');
        $retailId = $this->store('B2C', 'P274-DRIVER-CHANNEL-B2C');
        $warehouseId = $this->warehouse($storeId, 'P274-DRIVER-CHANNEL-WH');
        $this->enableEnterpriseWmsForStore($storeId);
        $viewer = $this->viewer($storeId);
        $van = $this->van($warehouseId);
        $assignment = $this->assign($van, $warehouseId, $viewer);
        $this->stock($warehouseId, $storeId, 'P274-DRIVER-CHANNEL-SKU');
        $service = app(VanInventoryReadService::class);

        // An authorized supervisor may inspect a Van before assigning a driver.
        $this->assertCount(1, $service->page($viewer, (int) $van->id)['rows']);

        // Deliberately bypass the assignment writer to model legacy/imported
        // data. Even a properly scoped WMS reader cannot launder a B2C driver
        // into a B2B Van stock authority.
        $driverUser = User::factory()->create(['is_active' => true]);
        $driverId = (int) DB::table('drivers')->insertGetId([
            'user_id' => $driverUser->id,
            'store_id' => $retailId,
            'driver_type' => 'b2c',
            'is_active' => true,
            'is_available' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $assignment->forceFill(['driver_id' => $driverId])->save();

        try {
            $service->page($viewer, (int) $van->id);
            $this->fail('Retail driver may not authorize reading Wholesale Van stock.');
        } catch (HttpException $exception) {
            $this->assertSame(404, $exception->getStatusCode());
        }

        // An imported Driver can claim a B2B type while still belonging to a
        // Retail store. Type alone must not authorize Wholesale Van inventory.
        DB::table('drivers')->where('id', $driverId)->update([
            'driver_type' => 'b2b',
            'updated_at' => now(),
        ]);
        try {
            $service->page($viewer, (int) $van->id);
            $this->fail('B2B driver type with a B2C store must not expose Van stock.');
        } catch (HttpException $exception) {
            $this->assertSame(404, $exception->getStatusCode());
        }

        // Correcting the persisted Driver store to Wholesale preserves a
        // legitimate B2B read without changing Van or inventory permissions.
        DB::table('drivers')->where('id', $driverId)->update([
            'store_id' => $storeId,
            'updated_at' => now(),
        ]);
        $this->assertCount(1, $service->page($viewer, (int) $van->id)['rows']);

        // The canonical assignment writer requires both the Driver and its
        // linked User to be active; legacy assignments must obey this on read.
        $driverUser->forceFill(['is_active' => false])->save();
        try {
            $service->page($viewer, (int) $van->id);
            $this->fail('Disabled driver user account must not expose Van stock.');
        } catch (HttpException $exception) {
            $this->assertSame(404, $exception->getStatusCode());
        }
        $driverUser->forceFill(['is_active' => true])->save();
        $this->assertCount(1, $service->page($viewer, (int) $van->id)['rows']);

        DB::table('drivers')->where('id', $driverId)->update([
            'is_active' => false,
            'updated_at' => now(),
        ]);
        try {
            $service->page($viewer, (int) $van->id);
            $this->fail('Inactive linked B2B driver must not authorize Van stock reads.');
        } catch (HttpException $exception) {
            $this->assertSame(404, $exception->getStatusCode());
        }
        $this->assertSame(0, DB::table('stock_movements')->count());
    }

    public function test_expired_assignment_cannot_fall_back_to_home_warehouse(): void
    {
        $storeId = $this->store('B2B', 'P274-EXPIRED');
        $warehouse = $this->warehouse($storeId, 'P274-EXPIRED-WH');
        $this->enableEnterpriseWmsForStore($storeId);
        $viewer = $this->viewer($storeId);
        $van = $this->van($warehouse);
        $assignment = $this->assign($van, $warehouse, $viewer);
        $assignment->forceFill(['effective_until' => now()->subMinute()])->save();

        $this->expectException(ModelNotFoundException::class);
        app(VanInventoryReadService::class)->page($viewer, (int) $van->id);
    }

    public function test_inactive_van_cannot_expose_stock_from_a_still_active_primary_assignment(): void
    {
        $storeId = $this->store('B2B', 'P274-INACTIVE-VAN');
        $warehouse = $this->warehouse($storeId, 'P274-INACTIVE-VAN-WH');
        $this->enableEnterpriseWmsForStore($storeId);
        $viewer = $this->viewer($storeId);
        $van = $this->van($warehouse);
        $this->assign($van, $warehouse, $viewer);
        $this->stock($warehouse, $storeId, 'P274-INACTIVE-SKU');

        // Establish that the same active assignment was previously readable.
        $this->assertCount(1, app(VanInventoryReadService::class)->page($viewer, (int) $van->id)['rows']);

        $van->forceFill(['status' => 'inactive'])->save();
        $this->assertDatabaseHas('van_assignments', [
            'van_id' => $van->id,
            'status' => 'active',
        ]);

        // The Van's own operational state is an independent authority guard.
        $this->expectException(ModelNotFoundException::class);
        app(VanInventoryReadService::class)->page($viewer, (int) $van->id);
    }

    private function store(string $channel, string $code): int
    {
        return (int) DB::table('stores')->insertGetId([
            'store_type_id' => DB::table('store_types')->where('code', $channel)->value('id'),
            'code' => $code,
            'name' => $code,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function warehouse(int $storeId, string $code): int
    {
        return (int) DB::table('warehouses')->insertGetId([
            'store_id' => $storeId,
            'code' => $code,
            'name' => $code,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function van(int $homeWarehouseId): Van
    {
        return Van::query()->create([
            'public_id' => (string) Str::uuid(),
            'code' => 'P274-VAN-'.Str::random(10),
            'status' => 'active',
            'home_warehouse_id' => $homeWarehouseId,
        ]);
    }

    private function assign(Van $van, int $warehouseId, User $actor): VanAssignment
    {
        return VanAssignment::query()->create([
            'public_id' => (string) Str::uuid(),
            'van_id' => $van->id,
            'warehouse_id' => $warehouseId,
            'assignment_type' => 'primary',
            'status' => 'active',
            'effective_from' => now()->subHour(),
            'created_by' => $actor->id,
        ]);
    }

    private function viewer(int $storeId): User
    {
        $viewer = User::factory()->create(['is_active' => true, 'locale' => 'en']);

        // B2B stock authority is derived from a configured Wholesale global
        // role, not an invented global role or a Retail STORE-only assignment.
        $global = Role::query()->where('code', 'B2B_ADMIN')->firstOrFail();
        $this->assertTrue($global->permissions()->where('code', 'drivers.b2b.view')->exists());
        $this->assertTrue($global->permissions()->where('code', 'inventory.view')->exists());
        $viewer->roles()->attach($global->id);

        $storeRole = Role::query()->create([
            'code' => 'P274_STOCK_'.Str::random(10),
            'name' => 'Scoped inventory viewer',
            'scope' => 'store',
            'is_active' => true,
            'is_system' => false,
        ]);
        $storeRole->permissions()->attach(Permission::query()->where('code', 'inventory.view')->firstOrFail()->id);
        UserStoreRole::query()->create([
            'user_id' => $viewer->id,
            'store_id' => $storeId,
            'role_id' => $storeRole->id,
        ]);

        return $viewer->fresh();
    }

    private function stock(int $warehouseId, int $storeId, string $sku): void
    {
        $catalogId = DB::table('catalogs')->where('store_id', $storeId)->value('id');
        if ($catalogId === null) {
            $catalogId = DB::table('catalogs')->insertGetId([
                'store_id' => $storeId,
                'channel' => 'b2b',
                'code' => 'p274-'.strtolower((string) $storeId),
                'name' => 'P274 Catalog',
                'is_active' => true,
                'is_migration_quarantine' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
        $unitId = DB::table('units')->where('code', 'P274-EACH')->value('id');
        if ($unitId === null) {
            $unitId = DB::table('units')->insertGetId([
                'code' => 'P274-EACH',
                'name' => 'Each',
                'decimal_places' => 3,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
        $productId = DB::table('products')->insertGetId([
            'catalog_id' => $catalogId,
            'unit_id' => $unitId,
            'sku' => $sku,
            'name' => $sku,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('inventories')->insert([
            'warehouse_id' => $warehouseId,
            'product_id' => $productId,
            'quantity' => 10,
            'reserved_quantity' => 2,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
