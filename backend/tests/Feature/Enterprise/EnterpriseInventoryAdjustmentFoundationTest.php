<?php

namespace Tests\Feature\Enterprise;

use App\Enterprise\Wms\InventoryAdjustmentService;
use App\Models\Role;
use App\Models\User;
use App\Models\UserStoreRole;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\Feature\Enterprise\Concerns\EnablesEnterpriseWms;
use Tests\TestCase;

final class EnterpriseInventoryAdjustmentFoundationTest extends TestCase
{
    use EnablesEnterpriseWms;
    use RefreshDatabase;

    public function test_adjustment_posts_once_and_exact_replay_is_idempotent(): void
    {
        $this->seed(CoreReferenceSeeder::class);
        $fixture = $this->fixture();
        $user = $this->inventoryUser($fixture['store_id']);
        $service = app(InventoryAdjustmentService::class);

        $movement = $service->post(
            $user,
            $fixture['warehouse_id'],
            $fixture['product_id'],
            2,
            'correction',
            'enterprise_adjustment_test',
            1001,
            'Physical count correction',
            'Supervisor recount confirmed.',
        );

        $this->assertSame('adjustment', $movement->type);
        $this->assertSame(2.0, (float) $movement->quantity);
        $this->assertSame('Supervisor recount confirmed.', $movement->notes);
        $this->assertSame(
            12.0,
            (float) DB::table('inventories')
                ->where('id', $fixture['inventory_id'])
                ->value('quantity'),
        );

        $replayed = $service->post(
            $user,
            $fixture['warehouse_id'],
            $fixture['product_id'],
            2,
            'correction',
            'enterprise_adjustment_test',
            1001,
            'Physical count correction',
            'Supervisor recount confirmed.',
        );

        $this->assertSame($movement->getKey(), $replayed->getKey());
        $this->assertSame(
            1,
            DB::table('stock_movements')
                ->where('inventory_id', $fixture['inventory_id'])
                ->where('reference_type', 'enterprise_adjustment_test')
                ->where('reference_id', 1001)
                ->count(),
        );
        $this->assertSame(
            12.0,
            (float) DB::table('inventories')
                ->where('id', $fixture['inventory_id'])
                ->value('quantity'),
        );

        try {
            $service->post(
                $user,
                $fixture['warehouse_id'],
                $fixture['product_id'],
                1,
                'correction',
                'enterprise_adjustment_test',
                1001,
                'Physical count correction',
                'Supervisor recount confirmed.',
            );
            $this->fail('Expected a conflicting replay to be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('reference', $exception->errors());
        }

        $this->assertDatabaseHas('audit_logs', [
            'event' => 'enterprise.wms.inventory_adjustment.posted',
            'store_id' => $fixture['store_id'],
        ]);
    }

    public function test_damage_and_loss_are_negative_and_cannot_violate_reserved_stock(): void
    {
        $this->seed(CoreReferenceSeeder::class);
        $fixture = $this->fixture();
        $user = $this->inventoryUser($fixture['store_id']);
        $service = app(InventoryAdjustmentService::class);

        $service->post(
            $user,
            $fixture['warehouse_id'],
            $fixture['product_id'],
            -2,
            'damage',
            'enterprise_adjustment_test',
            2001,
            'Damaged during handling',
            'Two units quarantined and written off.',
        );
        $service->post(
            $user,
            $fixture['warehouse_id'],
            $fixture['product_id'],
            -3,
            'loss',
            'enterprise_adjustment_test',
            2002,
            'Warehouse loss investigation',
        );

        $this->assertSame(
            5.0,
            (float) DB::table('inventories')
                ->where('id', $fixture['inventory_id'])
                ->value('quantity'),
        );

        try {
            $service->post(
                $user,
                $fixture['warehouse_id'],
                $fixture['product_id'],
                -3,
                'loss',
                'enterprise_adjustment_test',
                2003,
                'Would consume reserved stock',
            );
            $this->fail('Expected reserved stock protection to reject the loss.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('quantity_delta', $exception->errors());
        }

        try {
            $service->post(
                $user,
                $fixture['warehouse_id'],
                $fixture['product_id'],
                1,
                'damage',
                'enterprise_adjustment_test',
                2004,
                'Invalid positive damage',
            );
            $this->fail('Expected positive damage to be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('quantity_delta', $exception->errors());
        }

        $this->assertDatabaseHas('stock_movements', [
            'inventory_id' => $fixture['inventory_id'],
            'type' => 'damage',
            'quantity' => -2,
            'reference_id' => 2001,
        ]);
        $this->assertDatabaseHas('stock_movements', [
            'inventory_id' => $fixture['inventory_id'],
            'type' => 'loss',
            'quantity' => -3,
            'reference_id' => 2002,
        ]);
    }

    public function test_store_and_explicit_warehouse_scope_are_enforced(): void
    {
        $this->seed(CoreReferenceSeeder::class);
        $fixture = $this->fixture();
        $user = $this->inventoryUser($fixture['store_id']);
        $service = app(InventoryAdjustmentService::class);

        try {
            $service->post(
                $user,
                $fixture['other_store_warehouse_id'],
                $fixture['other_store_product_id'],
                -1,
                'loss',
                'enterprise_adjustment_test',
                3001,
                'Cross-store attempt',
            );
            $this->fail('Expected cross-store adjustment to be denied.');
        } catch (NotFoundHttpException) {
            $this->assertTrue(true);
        }

        DB::table('enterprise_warehouse_user_scopes')->insert([
            'user_id' => $user->id,
            'warehouse_id' => $fixture['warehouse_id'],
            'can_manage' => true,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->expectException(NotFoundHttpException::class);

        $service->post(
            $user,
            $fixture['other_warehouse_id'],
            $fixture['product_id'],
            -1,
            'loss',
            'enterprise_adjustment_test',
            3002,
            'Out-of-scope warehouse attempt',
        );
    }

    public function test_recent_history_is_bounded_and_uses_existing_stock_indexes(): void
    {
        $this->seed(CoreReferenceSeeder::class);
        $fixture = $this->fixture();
        $user = $this->inventoryUser($fixture['store_id']);
        $now = now();
        $rows = [];

        for ($index = 1; $index <= 120; $index++) {
            $rows[] = [
                'inventory_id' => $fixture['inventory_id'],
                'store_id' => $fixture['store_id'],
                'user_id' => $user->id,
                'type' => $index % 2 === 0 ? 'damage' : 'adjustment',
                'quantity' => $index % 2 === 0 ? -1 : 1,
                'reference_type' => 'enterprise_adjustment_history',
                'reference_id' => 4000 + $index,
                'reason' => 'Bounded history fixture',
                'notes' => null,
                'created_at' => $now->copy()->addSeconds($index),
                'updated_at' => $now->copy()->addSeconds($index),
            ];
        }

        DB::table('stock_movements')->insert($rows);

        $service = app(InventoryAdjustmentService::class);
        $history = $service->recentHistory(
            $user,
            $fixture['warehouse_id'],
            $fixture['product_id'],
            null,
            500,
        );
        $damage = $service->recentHistory(
            $user,
            $fixture['warehouse_id'],
            $fixture['product_id'],
            'damage',
            25,
        );

        $this->assertCount(100, $history);
        $this->assertCount(25, $damage);
        $this->assertTrue($damage->every(
            static fn ($movement): bool => $movement->type === 'damage',
        ));

        $indexes = collect(Schema::getIndexes('stock_movements'))->pluck('name')->all();
        $this->assertContains('stock_movements_inventory_created_idx', $indexes);
        $this->assertContains('stock_movements_store_ref_inventory_idx', $indexes);
    }

    public function test_notes_migration_is_additive_and_reversible(): void
    {
        $this->seed(CoreReferenceSeeder::class);
        $this->assertTrue(Schema::hasColumn('stock_movements', 'notes'));

        $migration = require database_path(
            'migrations/2026_10_16_092400_add_notes_to_stock_movements.php',
        );

        $migration->down();

        $this->assertFalse(Schema::hasColumn('stock_movements', 'notes'));
        $this->assertTrue(Schema::hasTable('inventories'));
        $this->assertTrue(Schema::hasTable('stock_movements'));

        $migration->up();

        $this->assertTrue(Schema::hasColumn('stock_movements', 'notes'));
    }

    /** @return array{store_id:int,warehouse_id:int,other_warehouse_id:int,other_store_warehouse_id:int,product_id:int,other_store_product_id:int,inventory_id:int} */
    private function fixture(): array
    {
        $storeTypeId = (int) DB::table('store_types')->where('code', 'B2C')->value('id');
        $storeId = (int) DB::table('stores')->insertGetId([
            'store_type_id' => $storeTypeId,
            'code' => 'ADJUST-STORE',
            'name' => 'Adjustment Store',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $otherStoreId = (int) DB::table('stores')->insertGetId([
            'store_type_id' => $storeTypeId,
            'code' => 'ADJUST-OTHER',
            'name' => 'Other Adjustment Store',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $unitId = (int) DB::table('units')->insertGetId([
            'code' => 'EA-ADJUST',
            'name' => 'Each',
            'decimal_places' => 3,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $catalogId = (int) DB::table('catalogs')->insertGetId([
            'store_id' => $storeId,
            'channel' => 'b2c',
            'code' => 'adjust',
            'name' => 'Adjustment Catalog',
            'is_active' => true,
            'is_migration_quarantine' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $otherCatalogId = (int) DB::table('catalogs')->insertGetId([
            'store_id' => $otherStoreId,
            'channel' => 'b2c',
            'code' => 'adjust-other',
            'name' => 'Other Adjustment Catalog',
            'is_active' => true,
            'is_migration_quarantine' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $productId = (int) DB::table('products')->insertGetId([
            'catalog_id' => $catalogId,
            'unit_id' => $unitId,
            'sku' => 'ADJUST-P1',
            'name' => 'Adjustment Product',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $otherStoreProductId = (int) DB::table('products')->insertGetId([
            'catalog_id' => $otherCatalogId,
            'unit_id' => $unitId,
            'sku' => 'ADJUST-P2',
            'name' => 'Other Adjustment Product',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $warehouseId = (int) DB::table('warehouses')->insertGetId([
            'store_id' => $storeId,
            'code' => 'ADJUST-WH',
            'name' => 'Adjustment Warehouse',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $otherWarehouseId = (int) DB::table('warehouses')->insertGetId([
            'store_id' => $storeId,
            'code' => 'ADJUST-WH-2',
            'name' => 'Other Adjustment Warehouse',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $otherStoreWarehouseId = (int) DB::table('warehouses')->insertGetId([
            'store_id' => $otherStoreId,
            'code' => 'ADJUST-OTHER-WH',
            'name' => 'Other Store Warehouse',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $inventoryId = (int) DB::table('inventories')->insertGetId([
            'warehouse_id' => $warehouseId,
            'product_id' => $productId,
            'quantity' => 10,
            'reserved_quantity' => 3,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('inventories')->insert([
            [
                'warehouse_id' => $otherWarehouseId,
                'product_id' => $productId,
                'quantity' => 8,
                'reserved_quantity' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'warehouse_id' => $otherStoreWarehouseId,
                'product_id' => $otherStoreProductId,
                'quantity' => 8,
                'reserved_quantity' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        return [
            'store_id' => $storeId,
            'warehouse_id' => $warehouseId,
            'other_warehouse_id' => $otherWarehouseId,
            'other_store_warehouse_id' => $otherStoreWarehouseId,
            'product_id' => $productId,
            'other_store_product_id' => $otherStoreProductId,
            'inventory_id' => $inventoryId,
        ];
    }

    private function inventoryUser(int $storeId): User
    {
        $this->enableEnterpriseWmsForStore($storeId);
        $user = User::query()->create([
            'name' => 'WMS Adjustment User',
            'email' => 'wms-adjustment@example.test',
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
