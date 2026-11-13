<?php

namespace Tests\Feature;

use App\Enterprise\Modules\ModuleLifecycleManager;
use App\Enterprise\Modules\ModuleRegistry;
use App\Enterprise\Modules\ModuleState;
use App\Models\Role;
use App\Models\User;
use App\Models\UserStoreRole;
use App\Support\AdminNavigation;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Enterprise\Concerns\EnablesEnterpriseWms;
use Tests\TestCase;

final class V2WarehouseSidebarGroupAuthorityTest extends TestCase
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

    public function test_p253_group_projects_exact_authorized_wms_warehouses_tab_in_both_locales(): void
    {
        [$storeId, $warehouseId] = $this->fixture();
        $actor = $this->warehouseOperator($storeId);

        $groups = collect(app(AdminNavigation::class)->groupsFor($actor));
        $warehouses = $groups->firstWhere('key', 'warehouses');
        $this->assertNotNull($warehouses);
        $this->assertSame('admin.nav_groups.warehouses', $warehouses['label']);

        $items = collect($warehouses['children'])->keyBy('key');
        $this->assertCount(1, $items);
        $this->assertSame('admin.erp.wms.index', $items['erp_wms']['route']);
        $this->assertSame('warehouses', $items['erp_wms']['params']['tab']);

        $catalog = $groups->firstWhere('key', 'catalog');
        $this->assertNotContains('erp_wms', array_column($catalog['children'] ?? [], 'key'));

        foreach (['en' => 'ltr', 'ar' => 'rtl'] as $locale => $direction) {
            app()->setLocale($locale);
            $actor->forceFill(['locale' => $locale])->save();

            $this->actingAs($actor->fresh())->get(route('admin.erp.wms.index', [
                'tab' => 'warehouses',
                'store_id' => $storeId,
                'warehouse_id' => $warehouseId,
            ]))->assertOk()
                ->assertSee(__('admin.nav_groups.warehouses'))
                ->assertSee('dir="'.$direction.'"', false)
                ->assertSee('data-wms-warehouses', false);
        }

        $this->assertSame(0, DB::table('stock_movements')->count());
    }

    public function test_unprivileged_actor_has_no_warehouse_group_or_direct_route(): void
    {
        [$storeId] = $this->fixture();
        $actor = User::factory()->create(['is_active' => true]);

        $groups = collect(app(AdminNavigation::class)->groupsFor($actor));
        $this->assertFalse($groups->contains('key', 'warehouses'));
        $this->actingAs($actor)->get(route('admin.erp.wms.index', [
            'tab' => 'warehouses',
            'store_id' => $storeId,
        ]))->assertForbidden();
    }

    public function test_disabled_wms_hides_warehouse_group_and_server_denies_direct_entry(): void
    {
        [$storeId] = $this->fixture();
        $actor = $this->warehouseOperator($storeId);

        $this->assertTrue(collect(app(AdminNavigation::class)->groupsFor($actor))
            ->contains('key', 'warehouses'));
        $this->actingAs($actor)->get(route('admin.erp.wms.index', [
            'tab' => 'warehouses',
            'store_id' => $storeId,
        ]))->assertOk();

        app(ModuleLifecycleManager::class)->transition('inventory_wms', ModuleState::DISABLED);

        $this->assertFalse(collect(app(AdminNavigation::class)->groupsFor($actor->fresh()))
            ->contains('key', 'warehouses'));
        $this->actingAs($actor)->get(route('admin.erp.wms.index', [
            'tab' => 'warehouses',
            'store_id' => $storeId,
        ]))->assertForbidden();

        $this->assertSame(0, DB::table('stock_movements')->count());
    }

    /** @return array{0:int,1:int} */
    private function fixture(): array
    {
        $storeId = (int) DB::table('stores')->insertGetId([
            'store_type_id' => DB::table('store_types')->where('code', 'B2C')->value('id'),
            'code' => 'P253-WMS-STORE',
            'name' => 'P253 Warehouse Store',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $warehouseId = (int) DB::table('warehouses')->insertGetId([
            'store_id' => $storeId,
            'code' => 'P253-WMS-01',
            'name' => 'P253 Warehouse',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->enableEnterpriseWmsForStore($storeId);

        return [$storeId, $warehouseId];
    }

    private function warehouseOperator(int $storeId): User
    {
        $actor = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $this->grantPlatformErpAccess($actor);
        UserStoreRole::query()->create([
            'user_id' => $actor->id,
            'store_id' => $storeId,
            'role_id' => Role::query()->where('code', 'RETAIL_INVENTORY')->firstOrFail()->id,
        ]);

        return $actor->fresh();
    }
}
