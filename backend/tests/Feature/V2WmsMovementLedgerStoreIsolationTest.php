<?php

namespace Tests\Feature;

use App\Enterprise\Entitlements\EntitlementScope;
use App\Enterprise\Entitlements\ModuleEntitlementManager;
use App\Enterprise\Modules\ModuleRegistry;
use App\Models\Role;
use App\Models\User;
use App\Models\UserStoreRole;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Enterprise\Concerns\EnablesEnterpriseWms;
use Tests\TestCase;

/**
 * P247: the existing WMS Movements page must enforce the canonical store
 * and warehouse identity of every stock ledger entry, not just its inventory.
 */
final class V2WmsMovementLedgerStoreIsolationTest extends TestCase
{
    use EnablesEnterpriseWms;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);

        // A test-only exact-version certification; not a production approval.
        config()->set(
            'enterprise_module_readiness.certified_versions.inventory_wms',
            app(ModuleRegistry::class)->manifest('inventory_wms')->version,
        );
    }

    public function test_authorized_movement_ledger_shows_only_same_store_entries_in_arabic_and_english(): void
    {
        $fixture = $this->fixture();
        $actor = $this->inventoryOperator($fixture['store_id']);

        $this->movement($fixture['inventory_id'], $fixture['store_id'], 'P247-VALID', 4);
        $this->movement($fixture['inventory_id'], $fixture['other_store_id'], 'P247-FOREIGN-STORE-ID', 99);
        $this->movement($fixture['other_inventory_id'], $fixture['other_store_id'], 'P247-OTHER-WAREHOUSE', 7);

        foreach (['en' => 'ltr', 'ar' => 'rtl'] as $locale => $direction) {
            $actor->forceFill(['locale' => $locale])->save();

            $response = $this->actingAs($actor)->get(route('admin.erp.wms.index', [
                'tab' => 'movements',
                'store_id' => $fixture['store_id'],
                'warehouse_id' => $fixture['warehouse_id'],
            ]));

            $response->assertOk()
                ->assertViewIs('admin.enterprise-wms')
                ->assertViewHas('tab', 'movements')
                ->assertViewHas('movementRows', fn ($rows): bool => $rows->count() === 1
                    && $rows->first()->reason === 'P247-VALID')
                ->assertSee('dir="'.$direction.'"', false)
                ->assertSee(__('enterprise_wms.movements.title', [], $locale))
                ->assertSee('P247-VALID')
                ->assertDontSee('P247-FOREIGN-STORE-ID')
                ->assertDontSee('P247-OTHER-WAREHOUSE');
        }
    }

    public function test_foreign_store_or_warehouse_cannot_be_selected_for_ledger(): void
    {
        $fixture = $this->fixture();
        $actor = $this->inventoryOperator($fixture['store_id']);

        $this->movement($fixture['inventory_id'], $fixture['store_id'], 'P247-OWN', 2);
        $this->movement($fixture['other_inventory_id'], $fixture['other_store_id'], 'P247-FOREIGN', 6);

        $this->actingAs($actor)->get(route('admin.erp.wms.index', [
            'tab' => 'movements',
            'store_id' => $fixture['other_store_id'],
            'warehouse_id' => $fixture['other_warehouse_id'],
        ]))->assertNotFound();

        $this->actingAs($actor)->get(route('admin.erp.wms.index', [
            'tab' => 'movements',
            'store_id' => $fixture['store_id'],
            'warehouse_id' => $fixture['other_warehouse_id'],
        ]))->assertNotFound();
    }

    public function test_explicit_warehouse_scope_cannot_reveal_unassigned_warehouse_movements(): void
    {
        $fixture = $this->fixture();
        $actor = $this->inventoryOperator($fixture['store_id']);

        $secondWarehouseId = $this->warehouse($fixture['store_id'], 'P247-SECOND');
        $secondInventoryId = $this->inventory($secondWarehouseId, $fixture['product_id']);
        $this->movement($secondInventoryId, $fixture['store_id'], 'P247-UNASSIGNED', 5);

        DB::table('enterprise_warehouse_user_scopes')->insert([
            'user_id' => $actor->id,
            'warehouse_id' => $fixture['warehouse_id'],
            'can_manage' => false,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($actor)->get(route('admin.erp.wms.index', [
            'tab' => 'movements',
            'store_id' => $fixture['store_id'],
            'warehouse_id' => $secondWarehouseId,
        ]))->assertNotFound();

        $this->actingAs($actor)->get(route('admin.erp.wms.index', [
            'tab' => 'movements',
            'store_id' => $fixture['store_id'],
            'warehouse_id' => $fixture['warehouse_id'],
        ]))->assertOk()->assertDontSee('P247-UNASSIGNED');
    }

    public function test_roleless_account_and_disabled_wms_entitlement_fail_closed(): void
    {
        $fixture = $this->fixture();

        $unauthorized = User::query()->create([
            'name' => 'P247 no role',
            'email' => 'p247-roleless@example.test',
            'password' => 'password',
            'locale' => 'en',
            'is_active' => true,
        ]);

        $url = route('admin.erp.wms.index', [
            'tab' => 'movements',
            'store_id' => $fixture['store_id'],
            'warehouse_id' => $fixture['warehouse_id'],
        ]);
        $this->actingAs($unauthorized)->get($url)->assertForbidden();

        $actor = $this->inventoryOperator($fixture['store_id']);
        app(ModuleEntitlementManager::class)->set(
            'inventory_wms',
            new EntitlementScope(EntitlementScope::STORE, $fixture['store_id']),
            false,
        );

        $this->actingAs($actor)->get($url)->assertNotFound();
    }

    /** @return array<string, int> */
    private function fixture(): array
    {
        $typeId = (int) DB::table('store_types')->where('code', 'B2C')->value('id');
        $storeId = $this->store($typeId, 'P247-OWN');
        $otherStoreId = $this->store($typeId, 'P247-OTHER');
        $unitId = (int) DB::table('units')->insertGetId([
            'code' => 'P247-EA',
            'name' => 'Each',
            'decimal_places' => 3,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $productId = $this->product($storeId, $unitId, 'P247-OWN');
        $otherProductId = $this->product($otherStoreId, $unitId, 'P247-OTHER');
        $warehouseId = $this->warehouse($storeId, 'P247-OWN');
        $otherWarehouseId = $this->warehouse($otherStoreId, 'P247-OTHER');
        $inventoryId = $this->inventory($warehouseId, $productId);
        $otherInventoryId = $this->inventory($otherWarehouseId, $otherProductId);

        $this->enableEnterpriseWmsForStore($storeId);
        $this->enableEnterpriseWmsForStore($otherStoreId);

        return [
            'store_id' => $storeId,
            'other_store_id' => $otherStoreId,
            'warehouse_id' => $warehouseId,
            'other_warehouse_id' => $otherWarehouseId,
            'inventory_id' => $inventoryId,
            'other_inventory_id' => $otherInventoryId,
            'product_id' => $productId,
        ];
    }

    private function store(int $typeId, string $code): int
    {
        return (int) DB::table('stores')->insertGetId([
            'store_type_id' => $typeId,
            'code' => $code,
            'name' => $code,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function product(int $storeId, int $unitId, string $code): int
    {
        $catalogId = (int) DB::table('catalogs')->insertGetId([
            'store_id' => $storeId,
            'channel' => 'b2c',
            'code' => strtolower($code),
            'name' => $code,
            'is_active' => true,
            'is_migration_quarantine' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return (int) DB::table('products')->insertGetId([
            'catalog_id' => $catalogId,
            'unit_id' => $unitId,
            'sku' => $code,
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

    private function inventory(int $warehouseId, int $productId): int
    {
        return (int) DB::table('inventories')->insertGetId([
            'warehouse_id' => $warehouseId,
            'product_id' => $productId,
            'quantity' => 10,
            'reserved_quantity' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function movement(int $inventoryId, int $storeId, string $reason, float $quantity): int
    {
        return (int) DB::table('stock_movements')->insertGetId([
            'inventory_id' => $inventoryId,
            'store_id' => $storeId,
            'type' => 'adjustment',
            'quantity' => $quantity,
            'reason' => $reason,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function inventoryOperator(int $storeId): User
    {
        $actor = User::query()->create([
            'name' => 'P247 Inventory',
            'email' => 'p247-inventory@example.test',
            'password' => 'password',
            'locale' => 'en',
            'is_active' => true,
        ]);

        $this->grantPlatformErpAccess($actor);
        $role = Role::query()->where('code', 'RETAIL_INVENTORY')->firstOrFail();
        UserStoreRole::query()->create([
            'user_id' => $actor->id,
            'store_id' => $storeId,
            'role_id' => $role->id,
        ]);

        return $actor;
    }
}
