<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Models\UserStoreRole;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class V2StoreOneStoreTwoAuthorizationAuditTest extends TestCase
{
    use RefreshDatabase;

    public function test_account_named_store1_assigned_only_to_store2_cannot_read_or_mutate_store1_inventory_in_either_locale(): void
    {
        $this->seed(CoreReferenceSeeder::class);
        [$storeOne, $storeTwo, $inventoryOne, $inventoryTwo] = $this->twoRetailStoreInventories();

        // Identity/display names are not an authorization source. Only the
        // persisted active store-role assignment controls Retail access.
        $user = User::query()->create([
            'name' => 'store1',
            'email' => 'store1-assigned-store2@example.test',
            'password' => 'password',
            'is_active' => true,
        ]);
        $role = Role::query()->where('code', 'RETAIL_INVENTORY')->firstOrFail();
        UserStoreRole::query()->create([
            'user_id' => $user->id,
            'store_id' => $storeTwo,
            'role_id' => $role->id,
        ]);
        Sanctum::actingAs($user);

        $this->assertFalse($user->hasPermission('inventory.manage', $storeOne));
        $this->assertTrue($user->hasPermission('inventory.manage', $storeTwo));

        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);

            $this->getJson('/api/v1/admin/inventory')
                ->assertOk()
                ->assertJsonCount(1, 'data')
                ->assertJsonPath('data.0.id', $inventoryTwo);

            $this->postJson("/api/v1/admin/inventory/{$inventoryOne}/adjust", [
                'quantity_delta' => 1,
                'reason' => 'cross-store attempt',
            ])->assertNotFound();
        }

        $this->postJson("/api/v1/admin/inventory/{$inventoryTwo}/adjust", [
            'quantity_delta' => 1,
            'reason' => 'authorized store2 count',
        ])->assertOk()->assertJsonPath('data.quantity', 11);

        $this->assertDatabaseHas('inventories', ['id' => $inventoryOne, 'quantity' => 10]);
        $this->assertDatabaseHas('stock_movements', [
            'inventory_id' => $inventoryTwo,
            'store_id' => $storeTwo,
            'type' => 'adjustment',
        ]);
        $this->assertDatabaseMissing('stock_movements', ['inventory_id' => $inventoryOne]);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'inventory.adjusted',
            'store_id' => $storeTwo,
        ]);
    }

    public function test_account_name_and_unrelated_global_role_do_not_create_retail_store_scope(): void
    {
        $this->seed(CoreReferenceSeeder::class);
        [, , $inventoryOne, $inventoryTwo] = $this->twoRetailStoreInventories();

        $user = User::query()->create([
            'name' => 'store1',
            'email' => 'store1-without-retail-assignment@example.test',
            'password' => 'password',
            'is_active' => true,
        ]);
        $user->roles()->attach(Role::query()->where('code', 'CUSTOMER_SUPPORT')->firstOrFail());
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/admin/inventory')->assertForbidden();
        $this->postJson("/api/v1/admin/inventory/{$inventoryOne}/adjust", [
            'quantity_delta' => 1,
            'reason' => 'missing grant',
        ])->assertNotFound();
        $this->postJson("/api/v1/admin/inventory/{$inventoryTwo}/adjust", [
            'quantity_delta' => 1,
            'reason' => 'foreign store',
        ])->assertNotFound();
        $this->assertDatabaseMissing('stock_movements', ['type' => 'adjustment']);
    }

    /**
     * @return array{int, int, int, int}
     */
    private function twoRetailStoreInventories(): array
    {
        $storeTypeId = (int) DB::table('store_types')->where('code', 'B2C')->value('id');
        $unitId = (int) DB::table('units')->insertGetId([
            'code' => 'EA-V2-2771',
            'name' => 'Each',
            'decimal_places' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $stores = [];
        $inventoryIds = [];
        foreach (['STORE-1-V2-2771', 'STORE-2-V2-2771'] as $code) {
            $storeId = (int) DB::table('stores')->insertGetId([
                'store_type_id' => $storeTypeId,
                'code' => $code,
                'name' => $code,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $catalogId = (int) DB::table('catalogs')->insertGetId([
                'store_id' => $storeId,
                'channel' => 'b2c',
                'code' => 'default',
                'name' => 'Audit Catalog',
                'is_active' => true,
                'is_migration_quarantine' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $productId = (int) DB::table('products')->insertGetId([
                'catalog_id' => $catalogId,
                'unit_id' => $unitId,
                'sku' => 'V2-2771-P-'.$storeId,
                'name' => 'Audit Product',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $warehouseId = (int) DB::table('warehouses')->insertGetId([
                'store_id' => $storeId,
                'code' => 'V2-2771-WH-'.$storeId,
                'name' => 'Audit Warehouse',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $inventoryIds[] = (int) DB::table('inventories')->insertGetId([
                'warehouse_id' => $warehouseId,
                'product_id' => $productId,
                'quantity' => 10,
                'reserved_quantity' => 2,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $stores[] = $storeId;
        }

        return [$stores[0], $stores[1], $inventoryIds[0], $inventoryIds[1]];
    }
}
