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
 * P248: one read-only, authorized view of the canonical inventory ledger,
 * with keyset paging and exact store/warehouse drill-down provenance.
 */
final class V2WmsBalancesPageAuthorityTest extends TestCase
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

    public function test_authorized_balance_tab_is_discoverable_and_localized_with_canonical_stock(): void
    {
        $f = $this->fixture();
        $viewer = $this->operator($f['store_id'], true);

        $this->assertTrue(Route::has('admin.erp.wms.index'));

        foreach (['en' => 'ltr', 'ar' => 'rtl'] as $locale => $direction) {
            app()->setLocale($locale);
            $viewer->forceFill(['locale' => $locale])->save();

            $response = $this->actingAs($viewer->fresh())->get($this->url($f));
            $response
                ->assertOk()
                ->assertViewIs('admin.enterprise-wms')
                ->assertViewHas('tab', 'balances')
                ->assertViewHas('balancesHasMore', false)
                ->assertSee('data-wms-balances="true"', false)
                ->assertSee('data-wms-balance-id="'.$f['inventory_id'].'"', false)
                ->assertSee(__('enterprise_wms.tabs.balances'))
                ->assertSee(__('enterprise_wms.balances.title'))
                ->assertSee('dir="'.$direction.'"', false)
                ->assertSee('data-pagination-mode="keyset"', false)
                ->assertDontSee('P248-FOREIGN-PRODUCT')
                ->assertDontSee('P248-LOCAL-OTHER-PRODUCT')
                ->assertDontSee('data-wms-mutation>', false);

            $rows = $response->viewData('inventoryRows');
            $this->assertCount(1, $rows);
            $this->assertSame($f['inventory_id'], (int) $rows->first()->inventory_id);
            $this->assertSame(10.0, (float) $rows->first()->quantity);
            $this->assertSame(2.0, (float) $rows->first()->reserved_quantity);
            $this->assertSame(8.0, (float) $rows->first()->available_quantity);

            $recordUrl = route('admin.erp.wms.index', [
                'tab' => 'overview',
                'store_id' => $f['store_id'],
                'warehouse_id' => $f['warehouse_id'],
                'inventory_id' => $f['inventory_id'],
            ]);
            $response->assertSee(e($recordUrl), false);
        }

        $this->assertSame(0, DB::table('stock_movements')->count());
        $this->assertDatabaseHas('inventories', ['id' => $f['inventory_id'], 'quantity' => 10, 'reserved_quantity' => 2]);
    }

    public function test_balance_scope_never_exposes_another_warehouse_store_or_channel(): void
    {
        $f = $this->fixture();
        $actor = $this->operator($f['store_id'], true);

        DB::table('enterprise_warehouse_user_scopes')->insert([
            'user_id' => $actor->id,
            'warehouse_id' => $f['warehouse_id'],
            'can_manage' => false,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($actor)->get($this->url($f))
            ->assertOk()
            ->assertSee('P248-OWN-PRODUCT')
            ->assertDontSee('P248-LOCAL-OTHER-PRODUCT')
            ->assertDontSee('P248-FOREIGN-PRODUCT')
            ->assertDontSee('P248-WHOLESALE-PRODUCT');

        $this->actingAs($actor)->get(route('admin.erp.wms.index', [
            'tab' => 'balances',
            'store_id' => $f['store_id'],
            'warehouse_id' => $f['other_warehouse_id'],
        ]))->assertNotFound();

        $this->actingAs($actor)->get(route('admin.erp.wms.index', [
            'tab' => 'balances',
            'store_id' => $f['foreign_store_id'],
            'warehouse_id' => $f['foreign_warehouse_id'],
        ]))->assertNotFound();

        $this->actingAs($actor)->get(route('admin.erp.wms.index', [
            'tab' => 'balances',
            'store_id' => $f['wholesale_store_id'],
            'warehouse_id' => $f['wholesale_warehouse_id'],
        ]))->assertNotFound();

        $this->assertSame(0, DB::table('stock_movements')->count());
    }

    public function test_record_360_rejects_mismatched_context_even_for_a_dual_store_viewer(): void
    {
        $f = $this->fixture();
        $actor = $this->operator($f['store_id'], true);
        $viewerRole = Role::query()->where('code', 'V2_P248_BALANCES_VIEWER')->firstOrFail();

        UserStoreRole::query()->create([
            'user_id' => $actor->id,
            'store_id' => $f['foreign_store_id'],
            'role_id' => $viewerRole->id,
        ]);

        $this->actingAs($actor)->get(route('admin.erp.wms.index', [
            'tab' => 'overview',
            'store_id' => $f['store_id'],
            'warehouse_id' => $f['warehouse_id'],
            'inventory_id' => $f['inventory_id'],
        ]))->assertOk()->assertSee('data-wms-record-360', false);

        $this->actingAs($actor)->get(route('admin.erp.wms.index', [
            'tab' => 'overview',
            'store_id' => $f['store_id'],
            'warehouse_id' => $f['warehouse_id'],
            'inventory_id' => $f['foreign_inventory_id'],
        ]))->assertNotFound();

        $this->actingAs($actor)->get(route('admin.erp.wms.index', [
            'tab' => 'overview',
            'store_id' => $f['store_id'],
            'warehouse_id' => $f['warehouse_id'],
            'inventory_id' => $f['other_inventory_id'],
        ]))->assertNotFound();

        $this->actingAs($actor)->get(route('admin.erp.wms.index', [
            'tab' => 'overview',
            'store_id' => $f['foreign_store_id'],
            'warehouse_id' => $f['foreign_warehouse_id'],
            'inventory_id' => $f['foreign_inventory_id'],
        ]))->assertOk();

        $this->assertSame(0, DB::table('stock_movements')->count());
    }

    public function test_balances_are_keyset_paginated_with_no_duplicate_or_missing_records(): void
    {
        $f = $this->fixture();
        $viewer = $this->operator($f['store_id'], true);
        for ($i = 1; $i <= 55; $i++) {
            $this->stock($f['catalog_id'], $f['unit_id'], $f['warehouse_id'], sprintf('P248-BULK-%03d', $i));
        }

        $first = $this->actingAs($viewer)->get($this->url($f));
        $first->assertOk()
            ->assertViewHas('balancesHasMore', true)
            ->assertSee('rel="next"', false)
            ->assertSee(__('enterprise_wms.balances.next_page'));
        $firstRows = $first->viewData('inventoryRows');
        $this->assertCount(50, $firstRows);
        $lastId = (int) $firstRows->last()->inventory_id;
        $this->assertSame($lastId, (int) $first->viewData('balancesNextCursor'));

        $second = $this->actingAs($viewer)->get($this->url($f, $lastId));
        $second->assertOk()
            ->assertViewHas('balancesHasMore', false)
            ->assertDontSee('rel="next"', false);
        $secondRows = $second->viewData('inventoryRows');
        $this->assertCount(6, $secondRows);

        $allIds = $firstRows->pluck('inventory_id')->merge($secondRows->pluck('inventory_id'));
        $this->assertCount(56, $allIds->unique());
        $this->assertSame(56, DB::table('inventories')->where('warehouse_id', $f['warehouse_id'])->count());

        $this->actingAs($viewer)->get($this->url($f, 999999999))
            ->assertOk()
            ->assertViewHas('balancesHasMore', false)
            ->assertSee(__('enterprise_wms.common.none'));
        $this->assertSame(0, DB::table('stock_movements')->count());
    }

    public function test_no_stock_renders_localized_empty_state_without_a_false_next_link(): void
    {
        $f = $this->fixture();
        $actor = $this->operator($f['store_id'], true);

        foreach (['en' => 'ltr', 'ar' => 'rtl'] as $locale => $direction) {
            app()->setLocale($locale);
            $actor->forceFill(['locale' => $locale])->save();

            $this->actingAs($actor->fresh())->get(route('admin.erp.wms.index', [
                'tab' => 'balances',
                'store_id' => $f['store_id'],
                'warehouse_id' => $f['empty_warehouse_id'],
            ]))
                ->assertOk()
                ->assertViewHas('balancesHasMore', false)
                ->assertSee(__('enterprise_wms.common.none'))
                ->assertSee('dir="'.$direction.'"', false)
                ->assertDontSee('rel="next"', false)
                ->assertDontSee('data-wms-balance-id=', false);
        }
    }

    public function test_roleless_and_two_role_explicit_view_deny_are_not_bypassed(): void
    {
        $f = $this->fixture();
        $roleless = User::factory()->create(['is_active' => true]);
        $this->actingAs($roleless)->get($this->url($f))->assertForbidden();

        $actor = $this->operator($f['store_id'], true);
        $extra = Role::query()->create([
            'code' => 'V2_P248_EXTRA_VIEWER',
            'name' => 'Secondary balances reader',
            'scope' => 'store',
            'is_active' => true,
            'is_system' => false,
        ]);
        $viewPermission = Permission::query()->where('code', 'inventory.view')->firstOrFail();
        $extra->permissions()->attach($viewPermission->id);
        UserStoreRole::query()->create([
            'user_id' => $actor->id,
            'store_id' => $f['store_id'],
            'role_id' => $extra->id,
        ]);
        $this->actingAs($actor)->get($this->url($f))->assertOk();

        DB::table('user_permission_overrides')->insert([
            'user_id' => $actor->id,
            'permission_id' => $viewPermission->id,
            'scope_key' => 'store:'.$f['store_id'],
            'store_id' => $f['store_id'],
            'effect' => 'deny',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $actor->forceFill(['has_permission_overrides' => true])->save();

        $this->actingAs($actor->fresh())->get($this->url($f))->assertForbidden();
        $this->assertSame(0, DB::table('stock_movements')->count());
    }

    public function test_revoked_entitlement_and_disabled_module_block_balances_without_data_loss(): void
    {
        $f = $this->fixture();
        $actor = $this->operator($f['store_id'], true);
        $this->actingAs($actor)->get($this->url($f))->assertOk();

        app(ModuleEntitlementManager::class)->set(
            'inventory_wms',
            new EntitlementScope(EntitlementScope::STORE, $f['store_id']),
            false,
        );
        $this->actingAs($actor)->get($this->url($f))->assertNotFound();

        app(ModuleEntitlementManager::class)->set(
            'inventory_wms',
            new EntitlementScope(EntitlementScope::STORE, $f['store_id']),
            true,
        );
        app(ModuleLifecycleManager::class)->transition('inventory_wms', ModuleState::DISABLED);
        $this->actingAs($actor)->get($this->url($f))->assertForbidden();

        $this->assertDatabaseHas('inventories', [
            'id' => $f['inventory_id'],
            'quantity' => 10,
            'reserved_quantity' => 2,
        ]);
        $this->assertSame(0, DB::table('stock_movements')->count());
    }

    /** @param array<string,int> $f */
    private function url(array $f, ?int $afterInventoryId = null): string
    {
        return route('admin.erp.wms.index', array_filter([
            'tab' => 'balances',
            'store_id' => $f['store_id'],
            'warehouse_id' => $f['warehouse_id'],
            'after_inventory_id' => $afterInventoryId,
        ], fn ($value) => $value !== null));
    }

    /** @return array<string,int> */
    private function fixture(): array
    {
        $b2c = (int) DB::table('store_types')->where('code', 'B2C')->value('id');
        $b2b = (int) DB::table('store_types')->where('code', 'B2B')->value('id');
        $own = $this->store($b2c, 'P248-OWN-STORE');
        $foreign = $this->store($b2c, 'P248-FOREIGN-STORE');
        $wholesale = $this->store($b2b, 'P248-WHOLESALE-STORE');

        $primary = $this->warehouse($own, 'P248-PRIMARY-WH');
        $other = $this->warehouse($own, 'P248-OTHER-WH');
        $empty = $this->warehouse($own, 'P248-EMPTY-WH');
        $foreignWarehouse = $this->warehouse($foreign, 'P248-FOREIGN-WH');
        $wholesaleWarehouse = $this->warehouse($wholesale, 'P248-WHOLESALE-WH');

        $unitId = (int) DB::table('units')->insertGetId([
            'code' => 'P248-UNIT',
            'name' => 'Each',
            'decimal_places' => 3,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $ownCatalog = $this->catalog($own, 'b2c', 'P248-OWN-CATALOG');
        $foreignCatalog = $this->catalog($foreign, 'b2c', 'P248-FOREIGN-CATALOG');
        $wholesaleCatalog = $this->catalog($wholesale, 'b2b', 'P248-WHOLESALE-CATALOG');

        $inventoryId = $this->stock($ownCatalog, $unitId, $primary, 'P248-OWN');
        $otherInventoryId = $this->stock($ownCatalog, $unitId, $other, 'P248-LOCAL-OTHER');
        $foreignInventoryId = $this->stock($foreignCatalog, $unitId, $foreignWarehouse, 'P248-FOREIGN');
        $this->stock($wholesaleCatalog, $unitId, $wholesaleWarehouse, 'P248-WHOLESALE');

        foreach ([$own, $foreign, $wholesale] as $storeId) {
            $this->enableEnterpriseWmsForStore($storeId);
        }

        return [
            'store_id' => $own,
            'foreign_store_id' => $foreign,
            'wholesale_store_id' => $wholesale,
            'warehouse_id' => $primary,
            'other_warehouse_id' => $other,
            'empty_warehouse_id' => $empty,
            'foreign_warehouse_id' => $foreignWarehouse,
            'wholesale_warehouse_id' => $wholesaleWarehouse,
            'inventory_id' => $inventoryId,
            'other_inventory_id' => $otherInventoryId,
            'foreign_inventory_id' => $foreignInventoryId,
            'catalog_id' => $ownCatalog,
            'unit_id' => $unitId,
        ];
    }

    private function store(int $storeTypeId, string $code): int
    {
        return (int) DB::table('stores')->insertGetId([
            'store_type_id' => $storeTypeId,
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

    private function catalog(int $storeId, string $channel, string $code): int
    {
        return (int) DB::table('catalogs')->insertGetId([
            'store_id' => $storeId,
            'channel' => $channel,
            'code' => strtolower($code),
            'name' => $code,
            'is_active' => true,
            'is_migration_quarantine' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function stock(int $catalogId, int $unitId, int $warehouseId, string $code): int
    {
        $productId = (int) DB::table('products')->insertGetId([
            'catalog_id' => $catalogId,
            'unit_id' => $unitId,
            'sku' => $code.'-SKU',
            'name' => $code.'-PRODUCT',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return (int) DB::table('inventories')->insertGetId([
            'warehouse_id' => $warehouseId,
            'product_id' => $productId,
            'quantity' => 10,
            'reserved_quantity' => 2,
            'created_at' => now()->subDays(15),
            'updated_at' => now(),
        ]);
    }

    private function operator(int $storeId, bool $viewOnly): User
    {
        $actor = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $this->grantPlatformErpAccess($actor);
        $role = $viewOnly
            ? $this->viewRole()
            : Role::query()->where('code', 'RETAIL_INVENTORY')->firstOrFail();

        UserStoreRole::query()->create([
            'user_id' => $actor->id,
            'store_id' => $storeId,
            'role_id' => $role->id,
        ]);

        return $actor;
    }

    private function viewRole(): Role
    {
        $role = Role::query()->firstOrCreate(
            ['code' => 'V2_P248_BALANCES_VIEWER'],
            [
                'name' => 'Inventory balance viewer',
                'scope' => 'store',
                'is_active' => true,
                'is_system' => false,
            ],
        );
        $role->permissions()->syncWithoutDetaching([
            Permission::query()->where('code', 'inventory.view')->firstOrFail()->id,
        ]);

        return $role;
    }
}
