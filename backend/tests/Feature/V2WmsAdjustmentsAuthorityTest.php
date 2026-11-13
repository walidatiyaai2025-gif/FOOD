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

/**
 * P250: The adjustments tab must project effective mutation authority while
 * all posted movements remain protected by the exact warehouse/store context.
 */
final class V2WmsAdjustmentsAuthorityTest extends TestCase
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

    public function test_view_only_operator_can_read_adjustments_in_both_locales_but_cannot_post(): void
    {
        $f = $this->fixture();
        $viewer = $this->operator($f['store_id'], true);

        foreach (['en' => 'ltr', 'ar' => 'rtl'] as $locale => $direction) {
            app()->setLocale($locale);
            $viewer->forceFill(['locale' => $locale])->save();
            $this->actingAs($viewer->fresh())->get($this->tabUrl($f))
                ->assertOk()
                ->assertViewIs('admin.enterprise-wms')
                ->assertViewHas('canManageAdjustments', false)
                ->assertSee(__('enterprise_wms.adjustments.title'))
                ->assertSee('dir="'.$direction.'"', false)
                ->assertDontSee('action="'.route('admin.erp.wms.adjustments.store').'"', false);

            $this->actingAs($viewer->fresh())->post(route('admin.erp.wms.adjustments.store'), $this->payload($f))
                ->assertNotFound();
        }

        $this->assertDatabaseHas('inventories', [
            'id' => $f['inventory_id'],
            'quantity' => 10,
            'reserved_quantity' => 2,
        ]);
        $this->assertSame(0, DB::table('stock_movements')->count());
    }

    public function test_manager_can_post_replay_and_review_one_scoped_audited_adjustment(): void
    {
        $f = $this->fixture();
        $manager = $this->operator($f['store_id'], false);

        $this->actingAs($manager)->get($this->tabUrl($f))
            ->assertOk()
            ->assertViewHas('canManageAdjustments', true)
            ->assertSee('action="'.route('admin.erp.wms.adjustments.store').'"', false);

        $payload = $this->payload($f);
        $this->actingAs($manager)->post(route('admin.erp.wms.adjustments.store'), $payload)
            ->assertRedirect();
        $this->actingAs($manager)->post(route('admin.erp.wms.adjustments.store'), $payload)
            ->assertRedirect();

        $this->assertDatabaseHas('inventories', [
            'id' => $f['inventory_id'],
            'quantity' => 8,
            'reserved_quantity' => 2,
        ]);
        $this->assertDatabaseHas('stock_movements', [
            'store_id' => $f['store_id'],
            'inventory_id' => $f['inventory_id'],
            'type' => 'damage',
            'quantity' => -2,
            'reference_type' => 'wms_workbench_adjustment',
            'reason' => 'Verified warehouse damage',
        ]);
        $this->assertSame(1, DB::table('stock_movements')->where('reference_type', 'wms_workbench_adjustment')->count());
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'enterprise.wms.inventory_adjustment.posted',
            'user_id' => $manager->id,
        ]);

        $this->actingAs($manager)->get($this->tabUrl($f))
            ->assertOk()
            ->assertSee('Verified warehouse damage');

        $this->actingAs($manager)->post(route('admin.erp.wms.adjustments.store'), [
            ...$payload,
            'quantity_delta' => -3,
        ])->assertSessionHasErrors('reference');
        $this->assertDatabaseHas('inventories', ['id' => $f['inventory_id'], 'quantity' => 8]);
    }

    public function test_multi_store_actor_cannot_use_store_a_hidden_context_to_modify_store_b_warehouse(): void
    {
        $f = $this->fixture();
        $manager = $this->operator($f['store_id'], false);
        UserStoreRole::query()->create([
            'user_id' => $manager->id,
            'store_id' => $f['foreign_store_id'],
            'role_id' => Role::query()->where('code', 'RETAIL_INVENTORY')->firstOrFail()->id,
        ]);

        $this->actingAs($manager)->post(route('admin.erp.wms.adjustments.store'), [
            ...$this->payload($f),
            'store_id' => $f['store_id'],
            'warehouse_id' => $f['foreign_warehouse_id'],
            'product_id' => $f['foreign_product_id'],
            'request_key' => 'P250-FORGED-STORE',
        ])->assertNotFound();

        $this->actingAs($manager)->post(route('admin.erp.wms.adjustments.store'), [
            ...$this->payload($f),
            'store_id' => $f['foreign_store_id'],
            'warehouse_id' => $f['foreign_warehouse_id'],
            'product_id' => $f['foreign_product_id'],
            'request_key' => 'P250-AUTHORIZED-STORE',
        ])->assertRedirect();

        $this->assertDatabaseHas('inventories', [
            'id' => $f['inventory_id'],
            'quantity' => 10,
        ]);
        $this->assertDatabaseHas('inventories', [
            'id' => $f['foreign_inventory_id'],
            'quantity' => 8,
        ]);
        $this->assertSame(1, DB::table('stock_movements')->count());
    }

    public function test_explicit_warehouse_scope_and_cross_channel_are_denied_without_stock_changes(): void
    {
        $f = $this->fixture();
        $manager = $this->operator($f['store_id'], false);
        DB::table('enterprise_warehouse_user_scopes')->insert([
            'user_id' => $manager->id,
            'warehouse_id' => $f['warehouse_id'],
            'can_manage' => true,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($manager)->post(route('admin.erp.wms.adjustments.store'), [
            ...$this->payload($f),
            'warehouse_id' => $f['other_local_warehouse_id'],
            'product_id' => $f['other_local_product_id'],
            'request_key' => 'P250-BLOCKED-WH',
        ])->assertNotFound();

        $this->actingAs($manager)->get(route('admin.erp.wms.index', [
            'tab' => 'adjustments',
            'store_id' => $f['store_id'],
            'warehouse_id' => $f['other_local_warehouse_id'],
        ]))->assertNotFound();

        $this->actingAs($manager)->post(route('admin.erp.wms.adjustments.store'), [
            ...$this->payload($f),
            'store_id' => $f['wholesale_store_id'],
            'warehouse_id' => $f['wholesale_warehouse_id'],
            'product_id' => $f['wholesale_product_id'],
            'request_key' => 'P250-BLOCKED-B2B',
        ])->assertNotFound();

        $this->assertSame(0, DB::table('stock_movements')->count());
        $this->assertDatabaseHas('inventories', ['id' => $f['other_local_inventory_id'], 'quantity' => 10]);
        $this->assertDatabaseHas('inventories', ['id' => $f['wholesale_inventory_id'], 'quantity' => 10]);
    }

    public function test_multi_role_explicit_manage_deny_keeps_view_but_removes_post_action(): void
    {
        $f = $this->fixture();
        $manager = $this->operator($f['store_id'], false);
        $role = Role::query()->create([
            'code' => 'V2_P250_EXTRA_MANAGER',
            'name' => 'Second Warehouse Manager',
            'scope' => 'store',
            'is_active' => true,
            'is_system' => false,
        ]);
        $role->permissions()->attach(Permission::query()->where('code', 'inventory.manage')->firstOrFail()->id);
        UserStoreRole::query()->create([
            'user_id' => $manager->id,
            'store_id' => $f['store_id'],
            'role_id' => $role->id,
        ]);
        $this->actingAs($manager)->get($this->tabUrl($f))
            ->assertViewHas('canManageAdjustments', true);

        DB::table('user_permission_overrides')->insert([
            'user_id' => $manager->id,
            'permission_id' => Permission::query()->where('code', 'inventory.manage')->firstOrFail()->id,
            'scope_key' => 'store:'.$f['store_id'],
            'store_id' => $f['store_id'],
            'effect' => 'deny',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $manager->forceFill(['has_permission_overrides' => true])->save();

        $this->actingAs($manager->fresh())->get($this->tabUrl($f))
            ->assertOk()
            ->assertViewHas('canManageAdjustments', false)
            ->assertDontSee('action="'.route('admin.erp.wms.adjustments.store').'"', false);
        $this->actingAs($manager->fresh())->post(route('admin.erp.wms.adjustments.store'), $this->payload($f))
            ->assertNotFound();
        $this->assertSame(0, DB::table('stock_movements')->count());
    }

    public function test_reserved_stock_and_inconsistent_product_are_never_adjusted(): void
    {
        $f = $this->fixture();
        $manager = $this->operator($f['store_id'], false);

        $this->actingAs($manager)->post(route('admin.erp.wms.adjustments.store'), [
            ...$this->payload($f),
            'quantity_delta' => -9,
            'request_key' => 'P250-RESERVED-BOUND',
        ])->assertSessionHasErrors('quantity_delta');

        $this->actingAs($manager)->post(route('admin.erp.wms.adjustments.store'), [
            ...$this->payload($f),
            'product_id' => $f['foreign_product_id'],
            'request_key' => 'P250-CROSS-PRODUCT',
        ])->assertSessionHasErrors('product_id');

        $this->assertDatabaseHas('inventories', ['id' => $f['inventory_id'], 'quantity' => 10, 'reserved_quantity' => 2]);
        $this->assertSame(0, DB::table('stock_movements')->count());
    }

    public function test_roleless_revoked_entitlement_and_disabled_module_fail_closed(): void
    {
        $f = $this->fixture();
        $roleless = User::factory()->create(['is_active' => true]);
        $this->actingAs($roleless)->get($this->tabUrl($f))->assertForbidden();

        $manager = $this->operator($f['store_id'], false);
        app(ModuleEntitlementManager::class)->set(
            'inventory_wms',
            new EntitlementScope(EntitlementScope::STORE, $f['store_id']),
            false,
        );
        $this->actingAs($manager)->get($this->tabUrl($f))->assertNotFound();
        $this->actingAs($manager)->post(route('admin.erp.wms.adjustments.store'), $this->payload($f))
            ->assertNotFound();

        app(ModuleEntitlementManager::class)->set(
            'inventory_wms',
            new EntitlementScope(EntitlementScope::STORE, $f['store_id']),
            true,
        );
        app(ModuleLifecycleManager::class)->transition('inventory_wms', ModuleState::DISABLED);
        $this->actingAs($manager)->get($this->tabUrl($f))->assertForbidden();
        $this->assertDatabaseHas('inventories', ['id' => $f['inventory_id'], 'quantity' => 10]);
        $this->assertSame(0, DB::table('stock_movements')->count());
    }

    /** @param array<string, int> $f */
    private function tabUrl(array $f): string
    {
        return route('admin.erp.wms.index', [
            'tab' => 'adjustments',
            'store_id' => $f['store_id'],
            'warehouse_id' => $f['warehouse_id'],
        ]);
    }

    /** @param array<string, int> $f
     * @return array<string, int|string>
     */
    private function payload(array $f): array
    {
        return [
            'store_id' => $f['store_id'],
            'warehouse_id' => $f['warehouse_id'],
            'product_id' => $f['product_id'],
            'quantity_delta' => -2,
            'classification' => 'damage',
            'reason' => 'Verified warehouse damage',
            'request_key' => 'P250-ADJUSTMENT-01',
        ];
    }

    /** @return array<string, int> */
    private function fixture(): array
    {
        $retailType = (int) DB::table('store_types')->where('code', 'B2C')->value('id');
        $wholesaleType = (int) DB::table('store_types')->where('code', 'B2B')->value('id');
        $store = $this->store($retailType, 'P250-OWN');
        $foreign = $this->store($retailType, 'P250-FOREIGN');
        $wholesale = $this->store($wholesaleType, 'P250-WHOLESALE');
        $unit = (int) DB::table('units')->insertGetId([
            'code' => 'P250-UNIT',
            'name' => 'Each',
            'decimal_places' => 3,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $primary = $this->stock($store, $unit, 'P250-PRIMARY', 'b2c');
        $otherLocal = $this->stock($store, $unit, 'P250-OTHER', 'b2c');
        $otherStore = $this->stock($foreign, $unit, 'P250-FOREIGN', 'b2c');
        $otherChannel = $this->stock($wholesale, $unit, 'P250-WHOLESALE', 'b2b');

        foreach ([$store, $foreign, $wholesale] as $storeId) {
            $this->enableEnterpriseWmsForStore($storeId);
        }

        return [
            'store_id' => $store,
            'foreign_store_id' => $foreign,
            'wholesale_store_id' => $wholesale,
            'warehouse_id' => $primary['warehouse_id'],
            'inventory_id' => $primary['inventory_id'],
            'product_id' => $primary['product_id'],
            'other_local_warehouse_id' => $otherLocal['warehouse_id'],
            'other_local_inventory_id' => $otherLocal['inventory_id'],
            'other_local_product_id' => $otherLocal['product_id'],
            'foreign_warehouse_id' => $otherStore['warehouse_id'],
            'foreign_inventory_id' => $otherStore['inventory_id'],
            'foreign_product_id' => $otherStore['product_id'],
            'wholesale_warehouse_id' => $otherChannel['warehouse_id'],
            'wholesale_inventory_id' => $otherChannel['inventory_id'],
            'wholesale_product_id' => $otherChannel['product_id'],
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

    /** @return array{warehouse_id:int,inventory_id:int,product_id:int} */
    private function stock(int $storeId, int $unitId, string $code, string $channel): array
    {
        $catalog = (int) DB::table('catalogs')->insertGetId([
            'store_id' => $storeId,
            'channel' => $channel,
            'code' => strtolower($code),
            'name' => $code.' Catalog',
            'is_active' => true,
            'is_migration_quarantine' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $product = (int) DB::table('products')->insertGetId([
            'catalog_id' => $catalog,
            'unit_id' => $unitId,
            'sku' => $code.'-SKU',
            'name' => $code.' Product',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $warehouse = (int) DB::table('warehouses')->insertGetId([
            'store_id' => $storeId,
            'code' => $code.'-WH',
            'name' => $code.' Warehouse',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $inventory = (int) DB::table('inventories')->insertGetId([
            'warehouse_id' => $warehouse,
            'product_id' => $product,
            'quantity' => 10,
            'reserved_quantity' => 2,
            'created_at' => now()->subDays(15),
            'updated_at' => now(),
        ]);

        return [
            'warehouse_id' => $warehouse,
            'inventory_id' => $inventory,
            'product_id' => $product,
        ];
    }

    private function operator(int $storeId, bool $readOnly): User
    {
        $actor = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $this->grantPlatformErpAccess($actor);

        $role = $readOnly ? $this->viewRole() : Role::query()->where('code', 'RETAIL_INVENTORY')->firstOrFail();
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
            ['code' => 'V2_P250_WMS_VIEWER'],
            [
                'name' => 'WMS Adjustment Viewer',
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
