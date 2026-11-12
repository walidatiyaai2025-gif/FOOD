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

final class V2WmsInventoryCountAuthorityTest extends TestCase
{
    use EnablesEnterpriseWms;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);

        // Test-only certification of the exact WMS module under examination.
        config()->set(
            'enterprise_module_readiness.certified_versions.inventory_wms',
            app(ModuleRegistry::class)->manifest('inventory_wms')->version,
        );
    }

    public function test_viewer_can_inspect_localized_counts_but_never_start_or_complete_them(): void
    {
        $f = $this->fixture();
        $manager = $this->operator($f['store_id'], false);
        $viewer = $this->operator($f['store_id'], true);
        $countUrl = route('admin.erp.wms.index', [
            'tab' => 'counts',
            'store_id' => $f['store_id'],
            'warehouse_id' => $f['warehouse_id'],
        ]);

        $this->actingAs($manager)->post(route('admin.erp.wms.counts.store'), $this->startPayload($f))
            ->assertRedirect();
        $countId = (int) DB::table('enterprise_inventory_counts')->where('warehouse_id', $f['warehouse_id'])->value('id');
        $this->assertGreaterThan(0, $countId);

        foreach (['en' => 'ltr', 'ar' => 'rtl'] as $locale => $direction) {
            app()->setLocale($locale);
            $viewer->forceFill(['locale' => $locale])->save();

            $this->actingAs($viewer->fresh())->get($countUrl.'&count_id='.$countId)
                ->assertOk()
                ->assertViewHas('canManageCounts', false)
                ->assertSee(__('enterprise_wms.counts.title'))
                ->assertSee(__('enterprise_wms.counts.statuses.open'))
                ->assertSee('dir="'.$direction.'"', false)
                ->assertDontSee('action="'.route('admin.erp.wms.counts.store').'"', false)
                ->assertDontSee('action="'.route('admin.erp.wms.counts.complete', $countId).'"', false)
                ->assertDontSee(__('enterprise_wms.counts.manage'));

            $this->actingAs($viewer->fresh())->post(route('admin.erp.wms.counts.store'), $this->startPayload($f))
                ->assertNotFound();
            $this->actingAs($viewer->fresh())->post(route('admin.erp.wms.counts.complete', $countId), [
                'store_id' => $f['store_id'],
                'warehouse_id' => $f['warehouse_id'],
                'counts' => [$f['inventory_id'] => 7],
            ])->assertNotFound();
        }

        $this->assertDatabaseHas('inventories', ['id' => $f['inventory_id'], 'quantity' => 10]);
        $this->assertDatabaseHas('enterprise_inventory_counts', ['id' => $countId, 'status' => 'open']);
        $this->assertSame(0, DB::table('stock_movements')->count());
    }

    public function test_manager_starts_and_completes_one_count_with_idempotent_stock_and_audit(): void
    {
        $f = $this->fixture();
        $manager = $this->operator($f['store_id'], false);
        $payload = $this->startPayload($f);

        $this->actingAs($manager)->get(route('admin.erp.wms.index', [
            'tab' => 'counts',
            'store_id' => $f['store_id'],
            'warehouse_id' => $f['warehouse_id'],
        ]))->assertOk()
            ->assertViewHas('canManageCounts', true)
            ->assertSee('action="'.route('admin.erp.wms.counts.store').'"', false);

        $this->actingAs($manager)->post(route('admin.erp.wms.counts.store'), $payload)->assertRedirect();
        $this->actingAs($manager)->post(route('admin.erp.wms.counts.store'), $payload)->assertRedirect();
        $this->assertSame(1, DB::table('enterprise_inventory_counts')->where('warehouse_id', $f['warehouse_id'])->count());

        $countId = (int) DB::table('enterprise_inventory_counts')->where('warehouse_id', $f['warehouse_id'])->value('id');
        $this->actingAs($manager)->get(route('admin.erp.wms.index', [
            'tab' => 'counts',
            'store_id' => $f['store_id'],
            'warehouse_id' => $f['warehouse_id'],
            'count_id' => $countId,
        ]))->assertOk()
            ->assertViewHas('selectedCountId', $countId)
            ->assertSee('action="'.route('admin.erp.wms.counts.complete', $countId).'"', false);

        $complete = [
            'store_id' => $f['store_id'],
            'warehouse_id' => $f['warehouse_id'],
            'counts' => [$f['inventory_id'] => 8],
        ];
        $this->actingAs($manager)->post(route('admin.erp.wms.counts.complete', $countId), $complete)
            ->assertRedirect();
        $this->actingAs($manager)->post(route('admin.erp.wms.counts.complete', $countId), $complete)
            ->assertRedirect();

        $this->assertDatabaseHas('enterprise_inventory_counts', ['id' => $countId, 'status' => 'completed']);
        $this->assertDatabaseHas('inventories', [
            'id' => $f['inventory_id'],
            'quantity' => 8,
            'reserved_quantity' => 1,
        ]);
        $this->assertDatabaseHas('stock_movements', [
            'store_id' => $f['store_id'],
            'inventory_id' => $f['inventory_id'],
            'type' => 'cycle_count_adjustment',
            'quantity' => -2,
            'reference_type' => 'enterprise_inventory_count',
            'reference_id' => $countId,
        ]);
        $this->assertSame(1, DB::table('stock_movements')
            ->where('reference_type', 'enterprise_inventory_count')->count());
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'enterprise.inventory_count.completed',
            'user_id' => $manager->id,
        ]);
    }

    public function test_count_writes_deny_foreign_store_channel_and_explicit_warehouse_scope(): void
    {
        $f = $this->fixture();
        $actor = $this->operator($f['store_id'], false);

        DB::table('enterprise_warehouse_user_scopes')->insert([
            'user_id' => $actor->id,
            'warehouse_id' => $f['warehouse_id'],
            'can_manage' => true,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($actor)->post(route('admin.erp.wms.counts.store'), [
            ...$this->startPayload($f),
            'warehouse_id' => $f['other_local_warehouse_id'],
            'inventory_ids' => [$f['other_local_inventory_id']],
        ])->assertNotFound();

        $this->actingAs($actor)->post(route('admin.erp.wms.counts.store'), [
            ...$this->startPayload($f),
            'store_id' => $f['foreign_store_id'],
            'warehouse_id' => $f['foreign_warehouse_id'],
            'inventory_ids' => [$f['foreign_inventory_id']],
        ])->assertNotFound();

        $this->actingAs($actor)->post(route('admin.erp.wms.counts.store'), [
            ...$this->startPayload($f),
            'store_id' => $f['wholesale_store_id'],
            'warehouse_id' => $f['wholesale_warehouse_id'],
            'inventory_ids' => [$f['wholesale_inventory_id']],
        ])->assertNotFound();

        $this->actingAs($actor)->get(route('admin.erp.wms.index', [
            'tab' => 'counts',
            'store_id' => $f['store_id'],
            'warehouse_id' => $f['other_local_warehouse_id'],
        ]))->assertNotFound();

        $this->assertSame(0, DB::table('enterprise_inventory_counts')->count());
        $this->assertSame(0, DB::table('stock_movements')->count());
    }

    public function test_multi_store_manager_cannot_forge_mismatched_count_context_on_start_or_completion(): void
    {
        $f = $this->fixture();
        $actor = $this->operator($f['store_id'], false);
        UserStoreRole::query()->create([
            'user_id' => $actor->id,
            'store_id' => $f['foreign_store_id'],
            'role_id' => Role::query()->where('code', 'RETAIL_INVENTORY')->firstOrFail()->id,
        ]);

        $this->actingAs($actor)->post(route('admin.erp.wms.counts.store'), [
            'store_id' => $f['store_id'],
            'warehouse_id' => $f['foreign_warehouse_id'],
            'inventory_ids' => [$f['foreign_inventory_id']],
            'request_key' => 'P252-FORGED-CROSS-STORE',
        ])->assertNotFound();

        $this->actingAs($actor)->post(route('admin.erp.wms.counts.store'), [
            'store_id' => $f['foreign_store_id'],
            'warehouse_id' => $f['foreign_warehouse_id'],
            'inventory_ids' => [$f['foreign_inventory_id']],
            'request_key' => 'P252-VALID-FOREIGN',
        ])->assertRedirect();
        $countId = (int) DB::table('enterprise_inventory_counts')
            ->where('warehouse_id', $f['foreign_warehouse_id'])->value('id');

        $this->actingAs($actor)->post(route('admin.erp.wms.counts.complete', $countId), [
            'store_id' => $f['store_id'],
            'warehouse_id' => $f['warehouse_id'],
            'counts' => [$f['foreign_inventory_id'] => 7],
        ])->assertNotFound();

        $this->assertDatabaseHas('enterprise_inventory_counts', ['id' => $countId, 'status' => 'open']);
        $this->assertDatabaseHas('inventories', ['id' => $f['foreign_inventory_id'], 'quantity' => 10]);
        $this->assertSame(0, DB::table('stock_movements')->count());
    }

    public function test_explicit_manage_deny_wins_over_two_store_roles_and_preserves_read_access(): void
    {
        $f = $this->fixture();
        $actor = $this->operator($f['store_id'], false);
        $second = Role::query()->create([
            'code' => 'V2_P252_SECOND_MANAGER',
            'name' => 'Second WMS Manager',
            'scope' => 'store',
            'is_active' => true,
            'is_system' => false,
        ]);
        $second->permissions()->attach(Permission::query()->where('code', 'inventory.manage')->firstOrFail()->id);
        UserStoreRole::query()->create([
            'user_id' => $actor->id,
            'store_id' => $f['store_id'],
            'role_id' => $second->id,
        ]);

        DB::table('user_permission_overrides')->insert([
            'user_id' => $actor->id,
            'permission_id' => Permission::query()->where('code', 'inventory.manage')->firstOrFail()->id,
            'scope_key' => 'store:'.$f['store_id'],
            'store_id' => $f['store_id'],
            'effect' => 'deny',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $actor->forceFill(['has_permission_overrides' => true])->save();

        $this->actingAs($actor->fresh())->get(route('admin.erp.wms.index', [
            'tab' => 'counts',
            'store_id' => $f['store_id'],
            'warehouse_id' => $f['warehouse_id'],
        ]))->assertOk()
            ->assertViewHas('canManageCounts', false)
            ->assertDontSee('action="'.route('admin.erp.wms.counts.store').'"', false);

        $this->actingAs($actor->fresh())->post(route('admin.erp.wms.counts.store'), $this->startPayload($f))
            ->assertNotFound();
        $this->assertSame(0, DB::table('enterprise_inventory_counts')->count());
    }

    public function test_roleless_and_disabled_module_or_entitlement_fail_closed(): void
    {
        $f = $this->fixture();
        $url = route('admin.erp.wms.index', [
            'tab' => 'counts',
            'store_id' => $f['store_id'],
            'warehouse_id' => $f['warehouse_id'],
        ]);
        $noRole = User::factory()->create(['is_active' => true]);
        $this->actingAs($noRole)->get($url)->assertForbidden();

        $manager = $this->operator($f['store_id'], false);
        app(ModuleEntitlementManager::class)->set(
            'inventory_wms',
            new EntitlementScope(EntitlementScope::STORE, $f['store_id']),
            false,
        );
        $this->actingAs($manager)->get($url)->assertNotFound();
        $this->actingAs($manager)->post(route('admin.erp.wms.counts.store'), $this->startPayload($f))
            ->assertNotFound();

        app(ModuleEntitlementManager::class)->set(
            'inventory_wms',
            new EntitlementScope(EntitlementScope::STORE, $f['store_id']),
            true,
        );
        app(ModuleLifecycleManager::class)->transition('inventory_wms', ModuleState::DISABLED);

        $this->actingAs($manager)->get($url)->assertForbidden();
        $this->assertDatabaseHas('inventories', ['id' => $f['inventory_id'], 'quantity' => 10]);
        $this->assertSame(0, DB::table('enterprise_inventory_counts')->count());
        $this->assertSame(0, DB::table('stock_movements')->count());
    }

    /** @param array<string, int> $f
     * @return array{store_id:int,warehouse_id:int,inventory_ids:list<int>,request_key:string}
     */
    private function startPayload(array $f): array
    {
        return [
            'store_id' => $f['store_id'],
            'warehouse_id' => $f['warehouse_id'],
            'inventory_ids' => [$f['inventory_id']],
            'request_key' => 'P252-COUNT-001',
        ];
    }

    /** @return array<string, int> */
    private function fixture(): array
    {
        $retailType = (int) DB::table('store_types')->where('code', 'B2C')->value('id');
        $wholesaleType = (int) DB::table('store_types')->where('code', 'B2B')->value('id');
        $store = $this->store($retailType, 'P252-OWN');
        $foreign = $this->store($retailType, 'P252-FOREIGN');
        $wholesale = $this->store($wholesaleType, 'P252-WHOLESALE');
        $unitId = (int) DB::table('units')->insertGetId([
            'code' => 'P252-UNIT',
            'name' => 'Each',
            'decimal_places' => 3,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $primary = $this->stockFixture($store, $unitId, 'P252-PRIMARY');
        $secondary = $this->stockFixture($store, $unitId, 'P252-SECONDARY');
        $foreignStock = $this->stockFixture($foreign, $unitId, 'P252-FOREIGN');
        $wholesaleStock = $this->stockFixture($wholesale, $unitId, 'P252-WHOLESALE');

        foreach ([$store, $foreign, $wholesale] as $storeId) {
            $this->enableEnterpriseWmsForStore($storeId);
        }

        return [
            'store_id' => $store,
            'foreign_store_id' => $foreign,
            'wholesale_store_id' => $wholesale,
            'warehouse_id' => $primary['warehouse_id'],
            'inventory_id' => $primary['inventory_id'],
            'other_local_warehouse_id' => $secondary['warehouse_id'],
            'other_local_inventory_id' => $secondary['inventory_id'],
            'foreign_warehouse_id' => $foreignStock['warehouse_id'],
            'foreign_inventory_id' => $foreignStock['inventory_id'],
            'wholesale_warehouse_id' => $wholesaleStock['warehouse_id'],
            'wholesale_inventory_id' => $wholesaleStock['inventory_id'],
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

    /** @return array{warehouse_id:int,inventory_id:int} */
    private function stockFixture(int $storeId, int $unitId, string $code): array
    {
        $catalogId = (int) DB::table('catalogs')->insertGetId([
            'store_id' => $storeId,
            'channel' => $code === 'P252-WHOLESALE' ? 'b2b' : 'b2c',
            'code' => strtolower($code),
            'name' => $code.' Catalog',
            'is_active' => true,
            'is_migration_quarantine' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $productId = (int) DB::table('products')->insertGetId([
            'catalog_id' => $catalogId,
            'unit_id' => $unitId,
            'sku' => $code.'-SKU',
            'name' => $code.' Product',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $warehouseId = (int) DB::table('warehouses')->insertGetId([
            'store_id' => $storeId,
            'code' => $code.'-WH',
            'name' => $code.' Warehouse',
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

        return ['warehouse_id' => $warehouseId, 'inventory_id' => $inventoryId];
    }

    private function operator(int $storeId, bool $readOnly): User
    {
        $actor = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $this->grantPlatformErpAccess($actor);
        $role = $readOnly ? $this->viewerRole() : Role::query()->where('code', 'RETAIL_INVENTORY')->firstOrFail();
        UserStoreRole::query()->create([
            'user_id' => $actor->id,
            'store_id' => $storeId,
            'role_id' => $role->id,
        ]);

        return $actor;
    }

    private function viewerRole(): Role
    {
        $role = Role::query()->firstOrCreate(
            ['code' => 'V2_P252_COUNT_VIEWER'],
            [
                'name' => 'WMS Count Read Only',
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
