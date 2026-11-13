<?php

namespace Tests\Feature;

use App\Enterprise\Entitlements\EntitlementScope;
use App\Enterprise\Entitlements\ModuleEntitlementManager;
use App\Enterprise\Modules\ModuleRegistry;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Models\UserStoreRole;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Enterprise\Concerns\EnablesEnterpriseWms;
use Tests\TestCase;

final class V2WmsReservationActionAuthorityTest extends TestCase
{
    use EnablesEnterpriseWms;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);

        // Exact WMS module readiness is authorized for this test process only.
        config()->set(
            'enterprise_module_readiness.certified_versions.inventory_wms',
            app(ModuleRegistry::class)->manifest('inventory_wms')->version,
        );
    }

    public function test_view_only_warehouse_role_can_see_stock_but_cannot_see_or_post_a_reservation_form(): void
    {
        $fixture = $this->fixture();
        $viewer = $this->userWithStoreRole($fixture['store_id'], true);

        foreach (['en', 'ar'] as $locale) {
            $viewer->forceFill(['locale' => $locale])->save();
            $response = $this->actingAs($viewer)->get(route('admin.erp.wms.index', [
                'tab' => 'reservations',
                'store_id' => $fixture['store_id'],
                'warehouse_id' => $fixture['warehouse_id'],
            ]));

            $response->assertOk()
                ->assertSee('data-enterprise-wms="true"', false)
                ->assertSee(__('enterprise_wms.reservations.title'))
                ->assertSee(__('enterprise_wms.common.reserved'))
                ->assertDontSee('action="'.route('admin.erp.wms.reservations.store').'"', false)
                ->assertSee('dir="'.($locale === 'ar' ? 'rtl' : 'ltr').'"', false);
        }

        $this->actingAs($viewer)->post(route('admin.erp.wms.reservations.store'), [
            ...$this->validReservation($fixture),
            'quantity' => 3,
        ])->assertNotFound();

        $this->assertDatabaseHas('inventories', [
            'id' => $fixture['inventory_id'],
            'quantity' => 10,
            'reserved_quantity' => 1,
        ]);
        $this->assertSame(0, DB::table('stock_movements')
            ->where('reference_type', 'wms_workbench')->count());
    }

    public function test_manager_can_reserve_retry_and_release_only_in_an_authorized_warehouse(): void
    {
        $fixture = $this->fixture();
        $manager = $this->userWithStoreRole($fixture['store_id'], false);

        $form = $this->actingAs($manager)->get(route('admin.erp.wms.index', [
            'tab' => 'reservations',
            'store_id' => $fixture['store_id'],
            'warehouse_id' => $fixture['warehouse_id'],
        ]));
        $form->assertOk()
            ->assertSee('action="'.route('admin.erp.wms.reservations.store').'"', false);

        $payload = $this->validReservation($fixture);
        $this->actingAs($manager)->post(route('admin.erp.wms.reservations.store'), $payload)
            ->assertRedirect();
        $this->assertDatabaseHas('inventories', [
            'id' => $fixture['inventory_id'],
            'reserved_quantity' => 4,
        ]);
        $this->actingAs($manager)->post(route('admin.erp.wms.reservations.store'), $payload)
            ->assertRedirect();
        $this->assertSame(1, DB::table('stock_movements')
            ->where('reference_type', 'wms_workbench')
            ->where('type', 'reserve')
            ->count());

        $this->actingAs($manager)->post(route('admin.erp.wms.reservations.store'), [
            ...$payload,
            'quantity' => 0,
        ])->assertRedirect();
        $this->assertDatabaseHas('inventories', [
            'id' => $fixture['inventory_id'],
            'reserved_quantity' => 1,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'enterprise.wms.stock_reservation.changed',
            'user_id' => $manager->id,
        ]);
    }

    public function test_manager_with_explicit_warehouse_scope_cannot_post_to_another_warehouse_in_same_store(): void
    {
        $fixture = $this->fixture();
        $manager = $this->userWithStoreRole($fixture['store_id'], false);

        DB::table('enterprise_warehouse_user_scopes')->insert([
            'user_id' => $manager->id,
            'warehouse_id' => $fixture['warehouse_id'],
            'can_manage' => true,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($manager)->post(route('admin.erp.wms.reservations.store'), [
            ...$this->validReservation($fixture),
            'warehouse_id' => $fixture['other_warehouse_id'],
        ])->assertNotFound();
        $this->assertDatabaseHas('inventories', [
            'id' => $fixture['other_inventory_id'],
            'reserved_quantity' => 0,
        ]);

        $this->actingAs($manager)->get(route('admin.erp.wms.index', [
            'tab' => 'reservations',
            'store_id' => $fixture['store_id'],
            'warehouse_id' => $fixture['other_warehouse_id'],
        ]))->assertNotFound();
    }

    public function test_direct_cross_store_reservation_request_cannot_change_foreign_inventory(): void
    {
        $local = $this->fixture('LOCAL');
        $foreign = $this->fixture('FOREIGN');
        $manager = $this->userWithStoreRole($local['store_id'], false);

        $this->actingAs($manager)->post(route('admin.erp.wms.reservations.store'), [
            ...$this->validReservation($foreign),
            'business_reference' => 'P257 direct cross-store attempt',
        ])->assertNotFound();

        $this->assertDatabaseHas('inventories', [
            'id' => $local['inventory_id'],
            'reserved_quantity' => 1,
        ]);
        $this->assertDatabaseHas('inventories', [
            'id' => $foreign['inventory_id'],
            'reserved_quantity' => 1,
        ]);
        $this->assertSame(0, DB::table('stock_movements')
            ->where('reference_type', 'wms_workbench')->count());
    }

    public function test_disabled_wms_entitlement_denies_reservations_without_modifying_stock(): void
    {
        $fixture = $this->fixture();
        $manager = $this->userWithStoreRole($fixture['store_id'], false);

        app(ModuleEntitlementManager::class)->set(
            'inventory_wms',
            new EntitlementScope(EntitlementScope::STORE, $fixture['store_id']),
            false,
        );

        $this->actingAs($manager)->post(route('admin.erp.wms.reservations.store'), $this->validReservation($fixture))
            ->assertNotFound();

        $this->assertDatabaseHas('inventories', [
            'id' => $fixture['inventory_id'],
            'quantity' => 10,
            'reserved_quantity' => 1,
        ]);
        $this->assertSame(0, DB::table('stock_movements')
            ->where('reference_type', 'wms_workbench')->count());
    }

    /** @return array{store_id:int,warehouse_id:int,other_warehouse_id:int,product_id:int,inventory_id:int,other_inventory_id:int} */
    private function fixture(string $suffix = 'DEFAULT'): array
    {
        $typeId = (int) DB::table('store_types')->where('code', 'B2C')->value('id');
        $storeId = (int) DB::table('stores')->insertGetId([
            'store_type_id' => $typeId,
            'code' => 'V2-P257-'.$suffix,
            'name' => 'WMS Reservation Store',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $unitId = (int) DB::table('units')->orderBy('id')->value('id');
        $catalogId = (int) DB::table('catalogs')->insertGetId([
            'store_id' => $storeId,
            'channel' => 'b2c',
            'code' => 'v2-p257-'.$suffix,
            'name' => 'V2 P257 Catalog',
            'is_active' => true,
            'is_migration_quarantine' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $productId = (int) DB::table('products')->insertGetId([
            'catalog_id' => $catalogId,
            'unit_id' => $unitId,
            'sku' => 'V2-P257-SKU-'.$suffix,
            'name' => 'V2 Reserved Product',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $warehouseId = (int) DB::table('warehouses')->insertGetId([
            'store_id' => $storeId,
            'code' => 'V2-P257-A-'.$suffix,
            'name' => 'Warehouse A',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $otherWarehouseId = (int) DB::table('warehouses')->insertGetId([
            'store_id' => $storeId,
            'code' => 'V2-P257-B-'.$suffix,
            'name' => 'Warehouse B',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $inventoryId = (int) DB::table('inventories')->insertGetId([
            'warehouse_id' => $warehouseId,
            'product_id' => $productId,
            'quantity' => 10,
            'reserved_quantity' => 1,
            'created_at' => now()->subDays(15),
            'updated_at' => now(),
        ]);
        $otherInventoryId = (int) DB::table('inventories')->insertGetId([
            'warehouse_id' => $otherWarehouseId,
            'product_id' => $productId,
            'quantity' => 10,
            'reserved_quantity' => 0,
            'created_at' => now()->subDays(15),
            'updated_at' => now(),
        ]);

        $this->enableEnterpriseWmsForStore($storeId);

        return [
            'store_id' => $storeId,
            'warehouse_id' => $warehouseId,
            'other_warehouse_id' => $otherWarehouseId,
            'product_id' => $productId,
            'inventory_id' => $inventoryId,
            'other_inventory_id' => $otherInventoryId,
        ];
    }

    /** @param array{store_id:int,warehouse_id:int,product_id:int} $fixture
     * @return array{store_id:int,warehouse_id:int,product_id:int,quantity:int,business_reference:string}
     */
    private function validReservation(array $fixture): array
    {
        return [
            'store_id' => $fixture['store_id'],
            'warehouse_id' => $fixture['warehouse_id'],
            'product_id' => $fixture['product_id'],
            'quantity' => 3,
            'business_reference' => 'P257 Business Request One',
        ];
    }

    private function userWithStoreRole(int $storeId, bool $readOnly): User
    {
        $user = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $this->grantPlatformErpAccess($user);

        if ($readOnly) {
            $role = Role::query()->create([
                'code' => 'V2_P257_WMS_VIEWER',
                'name' => 'WMS View-Only Test Role',
                'scope' => 'store',
                'is_active' => true,
            ]);
            $role->permissions()->attach(
                Permission::query()->where('code', 'inventory.view')->firstOrFail()->id,
            );
        } else {
            $role = Role::query()->where('code', 'RETAIL_INVENTORY')->firstOrFail();
        }

        UserStoreRole::query()->create([
            'user_id' => $user->id,
            'store_id' => $storeId,
            'role_id' => $role->id,
        ]);

        return $user;
    }
}
