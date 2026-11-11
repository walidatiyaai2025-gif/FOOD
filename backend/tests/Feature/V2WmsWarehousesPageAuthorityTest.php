<?php

namespace Tests\Feature;

use App\Enterprise\Entitlements\EntitlementScope;
use App\Enterprise\Entitlements\ModuleEntitlementManager;
use App\Enterprise\Modules\ModuleLifecycleManager;
use App\Enterprise\Modules\ModuleRegistry;
use App\Enterprise\Modules\ModuleState;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Models\UserStoreRole;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\Feature\Enterprise\Concerns\EnablesEnterpriseWms;
use Tests\TestCase;

/**
 * P254: the warehouse catalog is an authorized PAGE_TAB of the canonical
 * ERP/WMS Workbench, not a second inventory master or unprotected route.
 */
final class V2WmsWarehousesPageAuthorityTest extends TestCase
{
    use EnablesEnterpriseWms;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CoreReferenceSeeder::class);

        // Controlled test-only readiness; never alter production certification.
        config()->set(
            'enterprise_module_readiness.certified_versions.inventory_wms',
            app(ModuleRegistry::class)->manifest('inventory_wms')->version,
        );
    }

    public function test_authorized_warehouse_catalog_is_a_discoverable_localized_tab_with_scoped_record_links(): void
    {
        $fixture = $this->fixture();
        $actor = $this->operator($fixture['store_id']);

        $this->assertTrue(Route::has('admin.erp.wms.index'));

        foreach (['en' => 'ltr', 'ar' => 'rtl'] as $locale => $direction) {
            app()->setLocale($locale);
            $actor->forceFill(['locale' => $locale])->save();

            $response = $this->actingAs($actor->fresh())->get(route('admin.erp.wms.index', [
                'tab' => 'warehouses',
                'store_id' => $fixture['store_id'],
                'warehouse_id' => $fixture['warehouse_id'],
            ]));

            $response->assertOk()
                ->assertViewIs('admin.enterprise-wms')
                ->assertViewHas('tab', 'warehouses')
                ->assertSee('data-wms-warehouses', false)
                ->assertSee(__('enterprise_wms.tabs.warehouses'))
                ->assertSee('dir="'.$direction.'"', false)
                ->assertSee('data-wms-warehouse-id="'.$fixture['warehouse_id'].'"', false)
                ->assertSee('data-wms-warehouse-id="'.$fixture['other_local_warehouse_id'].'"', false)
                ->assertSee(e(route('admin.erp.wms.index', [
                    'tab' => 'overview',
                    'store_id' => $fixture['store_id'],
                    'warehouse_id' => $fixture['warehouse_id'],
                ])), false)
                ->assertDontSee('P254-FOREIGN-WH')
                ->assertDontSee('data-wms-warehouse-id="'.$fixture['foreign_warehouse_id'].'"', false);
        }

        // The warehouse listing is read-only and never creates a second ledger.
        $this->assertSame(0, DB::table('stock_movements')->count());
    }

    public function test_store_and_warehouse_scopes_are_enforced_server_side_and_not_just_hidden_in_the_catalog(): void
    {
        $fixture = $this->fixture();
        $actor = $this->operator($fixture['store_id']);

        DB::table('enterprise_warehouse_user_scopes')->insert([
            'user_id' => $actor->id,
            'warehouse_id' => $fixture['warehouse_id'],
            'can_manage' => false,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $url = route('admin.erp.wms.index', [
            'tab' => 'warehouses',
            'store_id' => $fixture['store_id'],
            'warehouse_id' => $fixture['warehouse_id'],
        ]);
        $this->actingAs($actor)->get($url)
            ->assertOk()
            ->assertSee('data-wms-warehouse-id="'.$fixture['warehouse_id'].'"', false)
            ->assertDontSee('data-wms-warehouse-id="'.$fixture['other_local_warehouse_id'].'"', false);

        $this->actingAs($actor)->get(route('admin.erp.wms.index', [
            'tab' => 'warehouses',
            'store_id' => $fixture['store_id'],
            'warehouse_id' => $fixture['other_local_warehouse_id'],
        ]))->assertNotFound();

        $this->actingAs($actor)->get(route('admin.erp.wms.index', [
            'tab' => 'warehouses',
            'store_id' => $fixture['foreign_store_id'],
            'warehouse_id' => $fixture['foreign_warehouse_id'],
        ]))->assertNotFound();

        $this->assertSame(0, DB::table('stock_movements')->count());
    }

    public function test_explicit_inventory_view_deny_wins_over_multiple_store_roles(): void
    {
        $fixture = $this->fixture();
        $actor = $this->operator($fixture['store_id']);
        $extraRole = Role::query()->create([
            'code' => 'V2_P254_WMS_VIEW_SECOND',
            'name' => 'Second WMS View Role',
            'scope' => 'store',
            'is_active' => true,
            'is_system' => false,
        ]);
        $extraRole->permissions()->attach(
            Permission::query()->where('code', 'inventory.view')->firstOrFail()->id,
        );
        UserStoreRole::query()->create([
            'user_id' => $actor->id,
            'store_id' => $fixture['store_id'],
            'role_id' => $extraRole->id,
        ]);

        $url = route('admin.erp.wms.index', [
            'tab' => 'warehouses',
            'store_id' => $fixture['store_id'],
        ]);
        $this->actingAs($actor)->get($url)->assertOk();

        DB::table('user_permission_overrides')->insert([
            'user_id' => $actor->id,
            'permission_id' => Permission::query()->where('code', 'inventory.view')->firstOrFail()->id,
            'scope_key' => 'store:'.$fixture['store_id'],
            'store_id' => $fixture['store_id'],
            'effect' => 'deny',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $actor->forceFill(['has_permission_overrides' => true])->save();

        $this->actingAs($actor->fresh())->get($url)->assertForbidden();
        $this->assertSame(0, DB::table('stock_movements')->count());
    }

    public function test_role_without_inventory_view_and_revoked_store_entitlement_fail_closed(): void
    {
        $fixture = $this->fixture();
        $url = route('admin.erp.wms.index', [
            'tab' => 'warehouses',
            'store_id' => $fixture['store_id'],
        ]);

        $noRole = User::factory()->create(['is_active' => true]);
        $this->actingAs($noRole)->get($url)->assertForbidden();

        $actor = $this->operator($fixture['store_id']);
        $this->actingAs($actor)->get($url)->assertOk();

        app(ModuleEntitlementManager::class)->set(
            'inventory_wms',
            new EntitlementScope(EntitlementScope::STORE, $fixture['store_id']),
            false,
        );

        $this->actingAs($actor)->get($url)->assertNotFound();
        $this->assertSame(0, DB::table('stock_movements')->count());
    }

    public function test_module_lifecycle_disable_blocks_catalog_without_deleting_warehouse_records(): void
    {
        $fixture = $this->fixture();
        $actor = $this->operator($fixture['store_id']);
        $url = route('admin.erp.wms.index', [
            'tab' => 'warehouses',
            'store_id' => $fixture['store_id'],
        ]);

        $this->actingAs($actor)->get($url)->assertOk();
        app(ModuleLifecycleManager::class)->transition('inventory_wms', ModuleState::DISABLED);
        $this->actingAs($actor)->get($url)->assertForbidden();

        $this->assertDatabaseHas('warehouses', [
            'id' => $fixture['warehouse_id'],
            'store_id' => $fixture['store_id'],
            'is_active' => true,
        ]);
    }

    /** @return array{store_id:int,foreign_store_id:int,warehouse_id:int,other_local_warehouse_id:int,foreign_warehouse_id:int} */
    private function fixture(): array
    {
        $storeType = (int) DB::table('store_types')->where('code', 'B2C')->value('id');
        $storeId = $this->store($storeType, 'P254-OWN-STORE');
        $foreignStore = $this->store($storeType, 'P254-FOREIGN-STORE');
        $warehouseId = $this->warehouse($storeId, 'P254-PRIMARY-WH');
        $otherLocalWarehouse = $this->warehouse($storeId, 'P254-OTHER-WH');
        $foreignWarehouse = $this->warehouse($foreignStore, 'P254-FOREIGN-WH');

        $this->enableEnterpriseWmsForStore($storeId);
        $this->enableEnterpriseWmsForStore($foreignStore);

        return [
            'store_id' => $storeId,
            'foreign_store_id' => $foreignStore,
            'warehouse_id' => $warehouseId,
            'other_local_warehouse_id' => $otherLocalWarehouse,
            'foreign_warehouse_id' => $foreignWarehouse,
        ];
    }

    private function store(int $storeType, string $code): int
    {
        return (int) DB::table('stores')->insertGetId([
            'store_type_id' => $storeType,
            'code' => $code,
            'name' => $code,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function warehouse(int $store, string $code): int
    {
        return (int) DB::table('warehouses')->insertGetId([
            'store_id' => $store,
            'code' => $code,
            'name' => $code,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function operator(int $storeId): User
    {
        $actor = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $this->grantPlatformErpAccess($actor);
        UserStoreRole::query()->create([
            'user_id' => $actor->id,
            'store_id' => $storeId,
            'role_id' => Role::query()->where('code', 'RETAIL_INVENTORY')->firstOrFail()->id,
        ]);

        return $actor;
    }
}
