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
 * P249: Lot creation and transition controls follow the exact same scoped
 * authority as the canonical HTTP operations, including immutable context.
 */
final class V2WmsLotsStatusAuthorityTest extends TestCase
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

    public function test_view_only_operator_sees_lots_and_expiry_but_not_create_or_status_forms_in_ar_en(): void
    {
        $f = $this->fixture();
        $manager = $this->operator($f['store_id'], false);
        $viewer = $this->operator($f['store_id'], true);

        $this->actingAs($manager)->post(route('admin.erp.wms.lots.store'), $this->payload($f))
            ->assertRedirect();
        $lotId = (int) DB::table('enterprise_inventory_lots')->where('warehouse_id', $f['warehouse_id'])->value('id');

        foreach (['en' => 'ltr', 'ar' => 'rtl'] as $locale => $dir) {
            app()->setLocale($locale);
            $viewer->forceFill(['locale' => $locale])->save();

            $this->actingAs($viewer->fresh())->get($this->tabUrl($f))
                ->assertOk()
                ->assertViewIs('admin.enterprise-wms')
                ->assertViewHas('canManageLots', false)
                ->assertSee(__('enterprise_wms.lots.title'))
                ->assertSee('LOT-P249-ONE')
                ->assertSee('dir="'.$dir.'"', false)
                ->assertDontSee('action="'.route('admin.erp.wms.lots.store').'"', false)
                ->assertDontSee('action="'.route('admin.erp.wms.lots.status', $lotId).'"', false);

            $this->actingAs($viewer->fresh())->post(route('admin.erp.wms.lots.store'), $this->payload($f))
                ->assertNotFound();
            $this->actingAs($viewer->fresh())->post(route('admin.erp.wms.lots.status', $lotId), [
                'store_id' => $f['store_id'],
                'warehouse_id' => $f['warehouse_id'],
                'status' => 'quarantined',
            ])->assertNotFound();
        }

        $this->assertDatabaseHas('enterprise_inventory_lots', ['id' => $lotId, 'status' => 'active']);
        $this->assertSame(0, DB::table('stock_movements')->count());
        $this->assertDatabaseHas('inventories', ['id' => $f['inventory_id'], 'quantity' => 10]);
    }

    public function test_manager_creates_retries_quarantines_and_closes_lot_with_terminal_ui_and_audit(): void
    {
        $f = $this->fixture();
        $manager = $this->operator($f['store_id'], false);

        $this->actingAs($manager)->get($this->tabUrl($f))
            ->assertOk()
            ->assertViewHas('canManageLots', true)
            ->assertSee('action="'.route('admin.erp.wms.lots.store').'"', false);

        $this->actingAs($manager)->post(route('admin.erp.wms.lots.store'), $this->payload($f))
            ->assertRedirect();
        $this->actingAs($manager)->post(route('admin.erp.wms.lots.store'), $this->payload($f))
            ->assertRedirect();

        $this->assertSame(1, DB::table('enterprise_inventory_lots')
            ->where('warehouse_id', $f['warehouse_id'])->count());
        $lotId = (int) DB::table('enterprise_inventory_lots')
            ->where('warehouse_id', $f['warehouse_id'])->value('id');

        $this->actingAs($manager)->get($this->tabUrl($f))
            ->assertOk()
            ->assertSee('action="'.route('admin.erp.wms.lots.status', $lotId).'"', false)
            ->assertSee('value="quarantined"', false);

        $statusPayload = [
            'store_id' => $f['store_id'],
            'warehouse_id' => $f['warehouse_id'],
            'status' => 'quarantined',
        ];
        $this->actingAs($manager)->post(route('admin.erp.wms.lots.status', $lotId), $statusPayload)
            ->assertRedirect();
        $this->actingAs($manager)->post(route('admin.erp.wms.lots.status', $lotId), $statusPayload)
            ->assertRedirect();
        $this->assertDatabaseHas('enterprise_inventory_lots', ['id' => $lotId, 'status' => 'quarantined']);

        $this->actingAs($manager)->post(route('admin.erp.wms.lots.status', $lotId), [
            ...$statusPayload,
            'status' => 'closed',
        ])->assertRedirect();

        $this->actingAs($manager)->get($this->tabUrl($f))
            ->assertOk()
            ->assertSee(__('enterprise_wms.lots.statuses.closed'))
            ->assertDontSee('action="'.route('admin.erp.wms.lots.status', $lotId).'"', false);

        $this->actingAs($manager)->post(route('admin.erp.wms.lots.status', $lotId), [
            ...$statusPayload,
            'status' => 'active',
        ])->assertSessionHasErrors('status');

        $this->assertDatabaseHas('enterprise_inventory_lots', ['id' => $lotId, 'status' => 'closed']);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'enterprise.wms.inventory_lot.created',
            'user_id' => $manager->id,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'enterprise.wms.inventory_lot.status_changed',
            'user_id' => $manager->id,
        ]);
        $this->assertSame(0, DB::table('stock_movements')->count());
        $this->assertDatabaseHas('inventories', ['id' => $f['inventory_id'], 'quantity' => 10, 'reserved_quantity' => 2]);
    }

    public function test_multi_store_manager_cannot_forge_lot_context_even_with_both_store_roles(): void
    {
        $f = $this->fixture();
        $manager = $this->operator($f['store_id'], false);
        UserStoreRole::query()->create([
            'user_id' => $manager->id,
            'store_id' => $f['foreign_store_id'],
            'role_id' => Role::query()->where('code', 'RETAIL_INVENTORY')->firstOrFail()->id,
        ]);

        $this->actingAs($manager)->post(route('admin.erp.wms.lots.store'), [
            ...$this->payload($f),
            'store_id' => $f['store_id'],
            'warehouse_id' => $f['foreign_warehouse_id'],
            'product_id' => $f['foreign_product_id'],
            'identifier' => 'P249-FORGED-STORE',
        ])->assertNotFound();

        $this->actingAs($manager)->post(route('admin.erp.wms.lots.store'), [
            ...$this->payload($f),
            'store_id' => $f['foreign_store_id'],
            'warehouse_id' => $f['foreign_warehouse_id'],
            'product_id' => $f['foreign_product_id'],
            'identifier' => 'P249-VALID-FOREIGN',
        ])->assertRedirect();

        $lotId = (int) DB::table('enterprise_inventory_lots')
            ->where('warehouse_id', $f['foreign_warehouse_id'])->value('id');

        $this->actingAs($manager)->post(route('admin.erp.wms.lots.status', $lotId), [
            'store_id' => $f['store_id'],
            'warehouse_id' => $f['warehouse_id'],
            'status' => 'quarantined',
        ])->assertNotFound();

        $this->actingAs($manager)->post(route('admin.erp.wms.lots.status', $lotId), [
            'store_id' => $f['foreign_store_id'],
            'warehouse_id' => $f['foreign_warehouse_id'],
            'status' => 'quarantined',
        ])->assertRedirect();

        $this->assertDatabaseHas('enterprise_inventory_lots', [
            'id' => $lotId,
            'store_id' => $f['foreign_store_id'],
            'status' => 'quarantined',
        ]);
        $this->assertSame(1, DB::table('enterprise_inventory_lots')->count());
        $this->assertSame(0, DB::table('stock_movements')->count());
    }

    public function test_explicit_warehouse_scope_and_cross_channel_access_fail_closed(): void
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

        $this->actingAs($manager)->post(route('admin.erp.wms.lots.store'), [
            ...$this->payload($f),
            'warehouse_id' => $f['other_local_warehouse_id'],
            'product_id' => $f['other_local_product_id'],
        ])->assertNotFound();

        $this->actingAs($manager)->get(route('admin.erp.wms.index', [
            'tab' => 'lots',
            'store_id' => $f['store_id'],
            'warehouse_id' => $f['other_local_warehouse_id'],
        ]))->assertNotFound();

        $this->actingAs($manager)->post(route('admin.erp.wms.lots.store'), [
            ...$this->payload($f),
            'store_id' => $f['wholesale_store_id'],
            'warehouse_id' => $f['wholesale_warehouse_id'],
            'product_id' => $f['wholesale_product_id'],
        ])->assertNotFound();

        $this->assertSame(0, DB::table('enterprise_inventory_lots')->count());
        $this->assertSame(0, DB::table('stock_movements')->count());
    }

    public function test_explicit_manage_deny_wins_over_multiple_roles_without_revoking_view(): void
    {
        $f = $this->fixture();
        $manager = $this->operator($f['store_id'], false);
        $extra = Role::query()->create([
            'code' => 'V2_P249_EXTRA_MANAGER',
            'name' => 'Additional lot manager',
            'scope' => 'store',
            'is_active' => true,
            'is_system' => false,
        ]);
        $extra->permissions()->attach(Permission::query()->where('code', 'inventory.manage')->firstOrFail()->id);
        UserStoreRole::query()->create([
            'user_id' => $manager->id,
            'store_id' => $f['store_id'],
            'role_id' => $extra->id,
        ]);

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
            ->assertViewHas('canManageLots', false)
            ->assertDontSee('action="'.route('admin.erp.wms.lots.store').'"', false);
        $this->actingAs($manager->fresh())->post(route('admin.erp.wms.lots.store'), $this->payload($f))
            ->assertNotFound();
        $this->assertSame(0, DB::table('enterprise_inventory_lots')->count());
    }

    public function test_roleless_revoked_entitlement_and_disabled_module_cannot_access_lot_operations(): void
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
        $this->actingAs($manager)->post(route('admin.erp.wms.lots.store'), $this->payload($f))
            ->assertNotFound();

        app(ModuleEntitlementManager::class)->set(
            'inventory_wms',
            new EntitlementScope(EntitlementScope::STORE, $f['store_id']),
            true,
        );
        app(ModuleLifecycleManager::class)->transition('inventory_wms', ModuleState::DISABLED);
        $this->actingAs($manager)->get($this->tabUrl($f))->assertForbidden();
        $this->assertSame(0, DB::table('enterprise_inventory_lots')->count());
        $this->assertSame(0, DB::table('stock_movements')->count());
    }

    /** @param array<string, int> $f */
    private function tabUrl(array $f): string
    {
        return route('admin.erp.wms.index', [
            'tab' => 'lots',
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
            'identifier' => 'LOT-P249-ONE',
            'manufactured_on' => now()->subDays(7)->toDateString(),
            'received_on' => now()->subDays(3)->toDateString(),
            'expires_on' => now()->addDays(10)->toDateString(),
        ];
    }

    /** @return array<string, int> */
    private function fixture(): array
    {
        $retailType = (int) DB::table('store_types')->where('code', 'B2C')->value('id');
        $wholesaleType = (int) DB::table('store_types')->where('code', 'B2B')->value('id');
        $store = $this->store($retailType, 'P249-OWN');
        $foreign = $this->store($retailType, 'P249-FOREIGN');
        $wholesale = $this->store($wholesaleType, 'P249-WHOLESALE');
        $unit = (int) DB::table('units')->insertGetId([
            'code' => 'P249-UNIT',
            'name' => 'Each',
            'decimal_places' => 3,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $primary = $this->stock($store, $unit, 'P249-PRIMARY', 'b2c');
        $otherLocal = $this->stock($store, $unit, 'P249-OTHER', 'b2c');
        $otherStore = $this->stock($foreign, $unit, 'P249-FOREIGN', 'b2c');
        $otherChannel = $this->stock($wholesale, $unit, 'P249-WHOLESALE', 'b2b');

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
            'other_local_product_id' => $otherLocal['product_id'],
            'foreign_warehouse_id' => $otherStore['warehouse_id'],
            'foreign_product_id' => $otherStore['product_id'],
            'wholesale_warehouse_id' => $otherChannel['warehouse_id'],
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
            ['code' => 'V2_P249_LOT_VIEWER'],
            [
                'name' => 'WMS Lot Viewer',
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
