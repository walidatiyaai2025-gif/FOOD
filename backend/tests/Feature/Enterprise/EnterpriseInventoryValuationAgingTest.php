<?php

namespace Tests\Feature\Enterprise;

use App\Enterprise\Wms\InventoryValuationService;
use App\Models\Role;
use App\Models\User;
use App\Models\UserStoreRole;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class EnterpriseInventoryValuationAgingTest extends TestCase
{
    use RefreshDatabase;

    public function test_standard_cost_valuation_uses_canonical_inventory_balance_and_is_idempotent(): void
    {
        $this->seed(CoreReferenceSeeder::class);
        $fixture = $this->fixture();
        $user = $this->inventoryUser($fixture['store_id']);
        $service = app(InventoryValuationService::class);

        $policy = $service->upsertPolicy(
            $user,
            $fixture['warehouse_id'],
            $fixture['product_id'],
            ['unit_cost' => '2.500'],
        );
        $retry = $service->upsertPolicy(
            $user,
            $fixture['warehouse_id'],
            $fixture['product_id'],
            ['unit_cost' => '2.500'],
        );

        $this->assertSame($policy->id, $retry->id);
        $rows = $service->valuation($user, $fixture['store_id'], $fixture['warehouse_id']);
        $this->assertCount(1, $rows);
        $this->assertSame(25.0, (float) $rows->first()->inventory_value);
        $this->assertSame(7.0, (float) $rows->first()->available_quantity);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'enterprise.wms.valuation_policy.created',
            'auditable_id' => $policy->id,
        ]);
    }

    public function test_aging_uses_bounded_inbound_history_without_mutating_stock(): void
    {
        $this->seed(CoreReferenceSeeder::class);
        $fixture = $this->fixture();
        $user = $this->inventoryUser($fixture['store_id']);
        $service = app(InventoryValuationService::class);
        $service->upsertPolicy(
            $user,
            $fixture['warehouse_id'],
            $fixture['product_id'],
            ['unit_cost' => '3.000'],
        );

        DB::table('stock_movements')->insert([
            'inventory_id' => $fixture['inventory_id'],
            'store_id' => $fixture['store_id'],
            'user_id' => $user->id,
            'type' => 'purchase_receipt',
            'quantity' => 10,
            'reference_type' => 'test',
            'reference_id' => 1,
            'reason' => 'aging fixture',
            'created_at' => now()->subDays(95),
            'updated_at' => now()->subDays(95),
        ]);

        $aging = $service->aging($user, $fixture['store_id'], $fixture['warehouse_id']);
        $this->assertCount(1, $aging);
        $this->assertSame('91_plus', $aging->first()->aging_bucket);
        $this->assertSame(10.0, (float) DB::table('inventories')->where('id', $fixture['inventory_id'])->value('quantity'));

        $slow = $service->slowOrDeadStock($user, $fixture['store_id'], 90, $fixture['warehouse_id']);
        $this->assertCount(1, $slow);
    }

    public function test_valuation_schema_is_additive_and_reversible(): void
    {
        $this->seed(CoreReferenceSeeder::class);
        $migration = require database_path(
            'migrations/2026_10_16_074100_create_enterprise_inventory_valuation_policies.php',
        );
        $migration->down();
        $this->assertFalse(Schema::hasTable('enterprise_inventory_valuation_policies'));
        $this->assertTrue(Schema::hasTable('inventories'));
        $this->assertTrue(Schema::hasTable('stock_movements'));
        $migration->up();
        $this->assertTrue(Schema::hasTable('enterprise_inventory_valuation_policies'));
    }

    /** @return array{store_id:int,warehouse_id:int,product_id:int,inventory_id:int} */
    private function fixture(): array
    {
        $storeTypeId = (int) DB::table('store_types')->where('code', 'B2C')->value('id');
        $storeId = (int) DB::table('stores')->insertGetId([
            'store_type_id' => $storeTypeId,
            'code' => 'VALUATION-STORE',
            'name' => 'Valuation Store',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $unitId = (int) DB::table('units')->insertGetId([
            'code' => 'EA-VALUATION',
            'name' => 'Each',
            'decimal_places' => 3,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $catalogId = (int) DB::table('catalogs')->insertGetId([
            'store_id' => $storeId,
            'channel' => 'b2c',
            'code' => 'valuation',
            'name' => 'Valuation Catalog',
            'is_active' => true,
            'is_migration_quarantine' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $productId = (int) DB::table('products')->insertGetId([
            'catalog_id' => $catalogId,
            'unit_id' => $unitId,
            'sku' => 'VALUATION-P1',
            'name' => 'Valuation Product',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $warehouseId = (int) DB::table('warehouses')->insertGetId([
            'store_id' => $storeId,
            'code' => 'VALUATION-WH',
            'name' => 'Valuation Warehouse',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $inventoryId = (int) DB::table('inventories')->insertGetId([
            'warehouse_id' => $warehouseId,
            'product_id' => $productId,
            'quantity' => 10,
            'reserved_quantity' => 3,
            'created_at' => now()->subDays(120),
            'updated_at' => now(),
        ]);

        return compact('storeId', 'warehouseId', 'productId', 'inventoryId');
    }

    private function inventoryUser(int $storeId): User
    {
        $user = User::query()->create([
            'name' => 'Valuation Inventory',
            'email' => 'valuation-inventory@example.test',
            'password' => 'password',
            'is_active' => true,
        ]);
        $role = Role::query()->where('code', 'RETAIL_INVENTORY')->firstOrFail();
        UserStoreRole::query()->create([
            'user_id' => $user->id,
            'store_id' => $storeId,
            'role_id' => $role->id,
        ]);

        return $user;
    }
}
