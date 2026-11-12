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
use Tests\Feature\Enterprise\Concerns\EnablesEnterpriseWms;
use Tests\TestCase;

final class V2WmsMovementsPageAuthorityTest extends TestCase
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

    public function test_scoped_movements_are_localized_and_link_to_matching_record_360(): void
    {
        $f = $this->fixture();
        $own = $this->movement($f['inventory_id'], $f['store_id'], 'P256-OWN-REASON');
        $this->movement($f['other_inventory_id'], $f['store_id'], 'P256-OTHER-WAREHOUSE');
        $this->movement($f['foreign_inventory_id'], $f['foreign_store_id'], 'P256-FOREIGN-STORE');
        $actor = $this->operator($f['store_id'], true);
        foreach (['en' => 'ltr', 'ar' => 'rtl'] as $locale => $dir) {
            app()->setLocale($locale);
            $actor->forceFill(['locale' => $locale])->save();
            $response = $this->actingAs($actor->fresh())->get($this->url($f));
            $response->assertOk()
                ->assertViewHas('tab', 'movements')
                ->assertViewHas('movementsHasMore', false)
                ->assertSee('data-wms-movements="true"', false)
                ->assertSee('data-wms-movement-id="'.$own.'"', false)
                ->assertSee(__('enterprise_wms.movements.title'))
                ->assertSee(__('enterprise_wms.movements.movement_types.purchase_receipt'))
                ->assertSee('data-pagination-mode="keyset"', false)
                ->assertSee('dir="'.$dir.'"', false)
                ->assertDontSee('P256-OTHER-WAREHOUSE')
                ->assertDontSee('P256-FOREIGN-STORE')
                ->assertDontSee('data-wms-mutation>', false);
            $this->assertSame([$own], $response->viewData('movementRows')->pluck('id')->all());
            $recordUrl = route('admin.erp.wms.index', [
                'tab' => 'overview', 'store_id' => $f['store_id'],
                'warehouse_id' => $f['warehouse_id'], 'inventory_id' => $f['inventory_id'],
            ]);
            $response->assertSee(e($recordUrl), false);
        }
        $this->assertSame(3, DB::table('stock_movements')->count());
    }

    public function test_three_bounded_keyset_pages_do_not_skip_or_duplicate_movements(): void
    {
        $f = $this->fixture();
        $actor = $this->operator($f['store_id'], true);
        for ($i = 1; $i <= 121; $i++) {
            $this->movement($f['inventory_id'], $f['store_id'], 'P256-'.$i);
        }
        $this->movement($f['other_inventory_id'], $f['store_id'], 'P256-HIDDEN');
        $seen = collect();
        $cursor = null;
        foreach ([50, 50, 21] as $expected) {
            $response = $this->actingAs($actor)->get($this->url($f, $cursor));
            $response->assertOk()->assertDontSee('P256-HIDDEN');
            $rows = $response->viewData('movementRows');
            $this->assertCount($expected, $rows);
            $seen = $seen->merge($rows->pluck('id'));
            $hasNext = $expected !== 21;
            $this->assertSame($hasNext, $response->viewData('movementsHasMore'));
            if ($hasNext) {
                $response->assertSee('rel="next"', false)
                    ->assertSee(__('enterprise_wms.movements.next_page'));
                $cursor = (int) $rows->last()->id;
                $this->assertSame($cursor, $response->viewData('movementsNextCursor'));
            } else {
                $response->assertDontSee('rel="next"', false);
                $this->assertNull($response->viewData('movementsNextCursor'));
            }
        }
        $this->assertCount(121, $seen->unique());
        $this->actingAs($actor)->get($this->url($f, 999999999))
            ->assertOk()->assertViewHas('movementsHasMore', false)
            ->assertSee(__('enterprise_wms.common.none'));
        $this->assertSame(122, DB::table('stock_movements')->count());
    }

    public function test_direct_idor_roleless_override_and_readiness_fail_closed_without_mutation(): void
    {
        $f = $this->fixture();
        $movement = $this->movement($f['inventory_id'], $f['store_id'], 'P256-SAFE');
        $actor = $this->operator($f['store_id'], true);
        $roleless = User::factory()->create(['is_active' => true]);
        $this->actingAs($roleless)->get($this->url($f))->assertForbidden();
        $this->actingAs($actor)->get(route('admin.erp.wms.index', [
            'tab' => 'movements', 'store_id' => $f['foreign_store_id'],
            'warehouse_id' => $f['foreign_warehouse_id'],
        ]))->assertNotFound();
        DB::table('enterprise_warehouse_user_scopes')->insert([
            'user_id' => $actor->id, 'warehouse_id' => $f['warehouse_id'],
            'can_manage' => false, 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->actingAs($actor)->get($this->url($f))->assertOk();
        $this->actingAs($actor)->get(route('admin.erp.wms.index', [
            'tab' => 'movements', 'store_id' => $f['store_id'],
            'warehouse_id' => $f['other_warehouse_id'],
        ]))->assertNotFound();
        $this->actingAs($actor)->get(route('admin.erp.wms.index', [
            'tab' => 'overview', 'store_id' => $f['store_id'],
            'warehouse_id' => $f['warehouse_id'],
            'inventory_id' => $f['foreign_inventory_id'],
        ]))->assertNotFound();

        $extra = Role::query()->create([
            'code' => 'V2_P256_EXTRA_VIEWER', 'name' => 'Movement reader',
            'scope' => 'store', 'is_active' => true, 'is_system' => false,
        ]);
        $perm = Permission::query()->where('code', 'inventory.view')->firstOrFail();
        $extra->permissions()->attach($perm->id);
        UserStoreRole::query()->create([
            'user_id' => $actor->id, 'store_id' => $f['store_id'], 'role_id' => $extra->id,
        ]);
        DB::table('user_permission_overrides')->insert([
            'user_id' => $actor->id, 'permission_id' => $perm->id,
            'scope_key' => 'store:'.$f['store_id'], 'store_id' => $f['store_id'],
            'effect' => 'deny', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $actor->forceFill(['has_permission_overrides' => true])->save();
        $this->actingAs($actor->fresh())->get($this->url($f))->assertForbidden();

        $fresh = $this->operator($f['store_id'], true);
        app(ModuleEntitlementManager::class)->set(
            'inventory_wms', new EntitlementScope(EntitlementScope::STORE, $f['store_id']), false,
        );
        $this->actingAs($fresh)->get($this->url($f))->assertNotFound();
        app(ModuleEntitlementManager::class)->set(
            'inventory_wms', new EntitlementScope(EntitlementScope::STORE, $f['store_id']), true,
        );
        app(ModuleLifecycleManager::class)->transition('inventory_wms', ModuleState::DISABLED);
        $this->actingAs($fresh)->get($this->url($f))->assertForbidden();
        $this->assertDatabaseHas('stock_movements', ['id' => $movement, 'reason' => 'P256-SAFE']);
        $this->assertSame(1, DB::table('stock_movements')->count());
    }

    /** @param array<string,int> $f */
    private function url(array $f, ?int $cursor = null): string
    {
        return route('admin.erp.wms.index', array_filter([
            'tab' => 'movements', 'store_id' => $f['store_id'],
            'warehouse_id' => $f['warehouse_id'], 'after_movement_id' => $cursor,
        ], fn ($value) => $value !== null));
    }

    private function movement(int $inventoryId, int $storeId, string $reason): int
    {
        return (int) DB::table('stock_movements')->insertGetId([
            'inventory_id' => $inventoryId, 'store_id' => $storeId, 'user_id' => null,
            'type' => 'purchase_receipt', 'quantity' => 1,
            'reference_type' => 'test', 'reference_id' => null,
            'reason' => $reason, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** @return array<string,int> */
    private function fixture(): array
    {
        $b2c = (int) DB::table('store_types')->where('code', 'B2C')->value('id');
        $b2b = (int) DB::table('store_types')->where('code', 'B2B')->value('id');
        $own = $this->store($b2c, 'P256-OWN-STORE');
        $foreign = $this->store($b2c, 'P256-FOREIGN-STORE');
        $wholesale = $this->store($b2b, 'P256-WHOLESALE-STORE');

        $primary = $this->warehouse($own, 'P256-PRIMARY-WH');
        $other = $this->warehouse($own, 'P256-OTHER-WH');
        $empty = $this->warehouse($own, 'P256-EMPTY-WH');
        $foreignWarehouse = $this->warehouse($foreign, 'P256-FOREIGN-WH');
        $wholesaleWarehouse = $this->warehouse($wholesale, 'P256-WHOLESALE-WH');

        $unitId = (int) DB::table('units')->insertGetId([
            'code' => 'P256-UNIT',
            'name' => 'Each',
            'decimal_places' => 3,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $ownCatalog = $this->catalog($own, 'b2c', 'P256-OWN-CATALOG');
        $foreignCatalog = $this->catalog($foreign, 'b2c', 'P256-FOREIGN-CATALOG');
        $wholesaleCatalog = $this->catalog($wholesale, 'b2b', 'P256-WHOLESALE-CATALOG');

        $inventoryId = $this->stock($ownCatalog, $unitId, $primary, 'P256-OWN');
        $otherInventoryId = $this->stock($ownCatalog, $unitId, $other, 'P256-LOCAL-OTHER');
        $foreignInventoryId = $this->stock($foreignCatalog, $unitId, $foreignWarehouse, 'P256-FOREIGN');
        $this->stock($wholesaleCatalog, $unitId, $wholesaleWarehouse, 'P256-WHOLESALE');

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
            ['code' => 'V2_P256_MOVEMENTS_VIEWER'],
            [
                'name' => 'Inventory movement viewer',
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
