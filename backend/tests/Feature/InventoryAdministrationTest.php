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

class InventoryAdministrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_store_scoped_inventory_adjustment_is_validated_and_audited(): void
    {
        $this->seed(CoreReferenceSeeder::class);
        [$ownInventory, $otherInventory, $storeId] = $this->inventoryFixture();
        $user = User::query()->create(['name' => 'Inventory', 'email' => 'inventory@example.test', 'password' => 'password', 'is_active' => true]);
        $role = Role::query()->where('code', 'INVENTORY')->firstOrFail();
        UserStoreRole::query()->create(['user_id' => $user->id, 'store_id' => $storeId, 'role_id' => $role->id]);
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/admin/inventory')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $ownInventory);
        $this->postJson("/api/v1/admin/inventory/{$ownInventory}/adjust", ['quantity_delta' => -3, 'reason' => 'count correction'])->assertOk()->assertJsonPath('data.quantity', 7);
        $this->assertDatabaseHas('stock_movements', ['inventory_id' => $ownInventory, 'type' => 'adjustment']);
        $this->assertDatabaseHas('audit_logs', ['event' => 'inventory.adjusted']);
        $this->postJson("/api/v1/admin/inventory/{$ownInventory}/adjust", ['quantity_delta' => -7, 'reason' => 'invalid'])->assertUnprocessable();
        $this->postJson("/api/v1/admin/inventory/{$otherInventory}/adjust", ['quantity_delta' => 1, 'reason' => 'cross store'])->assertNotFound();
    }

    public function test_role_without_inventory_permission_is_denied(): void
    {
        $this->seed(CoreReferenceSeeder::class);
        $this->inventoryFixture();
        $user = User::query()->create(['name' => 'Support', 'email' => 'support-inventory@example.test', 'password' => 'password', 'is_active' => true]);
        $user->roles()->attach(Role::query()->where('code', 'CUSTOMER_SUPPORT')->firstOrFail());
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/admin/inventory')->assertForbidden();
    }

    private function inventoryFixture(): array
    {
        $type = (int) DB::table('store_types')->where('code', 'B2C')->value('id');
        $store1 = (int) DB::table('stores')->insertGetId(['store_type_id' => $type, 'code' => 'INV-1', 'name' => 'Inventory 1', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $store2 = (int) DB::table('stores')->insertGetId(['store_type_id' => $type, 'code' => 'INV-2', 'name' => 'Inventory 2', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $unit = (int) DB::table('units')->insertGetId(['code' => 'EA-INV', 'name' => 'Each', 'decimal_places' => 0, 'created_at' => now(), 'updated_at' => now()]);
        $product = (int) DB::table('products')->insertGetId(['unit_id' => $unit, 'sku' => 'INV-P', 'name' => 'Inventory Product', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $ids = [];
        foreach ([$store1, $store2] as $store) {
            $warehouse = (int) DB::table('warehouses')->insertGetId(['store_id' => $store, 'code' => 'WH-'.$store, 'name' => 'Warehouse', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
            $ids[] = (int) DB::table('inventories')->insertGetId(['warehouse_id' => $warehouse, 'product_id' => $product, 'quantity' => 10, 'reserved_quantity' => 2, 'created_at' => now(), 'updated_at' => now()]);
        }

        return [$ids[0], $ids[1], $store1];
    }
}
