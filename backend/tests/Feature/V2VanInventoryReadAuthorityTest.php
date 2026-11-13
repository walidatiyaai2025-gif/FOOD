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

        try {
            app(VanInventoryReadService::class)->page($viewer, (int) $foreignVan->id);
            $this->fail('Cross-store Van stock read must fail closed.');
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
        $global = Role::query()->create([
            'code' => 'P274_VAN_READ_'.Str::random(10),
            'name' => 'Scoped Van stock lookup',
            'scope' => 'global',
            'is_active' => true,
            'is_system' => false,
        ]);
        $global->permissions()->attach(Permission::query()->where('code', 'drivers.b2b.view')->firstOrFail()->id);
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
