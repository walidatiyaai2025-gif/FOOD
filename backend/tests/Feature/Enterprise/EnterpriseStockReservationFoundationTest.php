<?php

namespace Tests\Feature\Enterprise;

use App\Enterprise\Wms\StockReservationService;
use App\Models\Role;
use App\Models\User;
use App\Models\UserStoreRole;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\TestCase;

final class EnterpriseStockReservationFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_reservation_is_retry_safe_and_preserves_unrelated_reserved_quantity(): void
    {
        $this->seed(CoreReferenceSeeder::class);
        $fixture = $this->fixture();
        $user = $this->inventoryUser($fixture['store_id']);
        $service = app(StockReservationService::class);

        $this->assertSame(
            3.0,
            $service->ensureReservation(
                $user,
                $fixture['warehouse_id'],
                $fixture['product_id'],
                3,
                'enterprise_test_order',
                1001,
                'test reservation',
            ),
        );
        $this->assertSame(
            5.0,
            (float) DB::table('inventories')
                ->where('id', $fixture['inventory_id'])
                ->value('reserved_quantity'),
        );

        $service->ensureReservation(
            $user,
            $fixture['warehouse_id'],
            $fixture['product_id'],
            3,
            'enterprise_test_order',
            1001,
            'retry reservation',
        );

        $this->assertSame(1, DB::table('stock_movements')
            ->where('inventory_id', $fixture['inventory_id'])
            ->where('reference_type', 'enterprise_test_order')
            ->where('reference_id', 1001)
            ->where('type', 'reserve')
            ->count());

        $service->ensureReservation(
            $user,
            $fixture['warehouse_id'],
            $fixture['product_id'],
            1,
            'enterprise_test_order',
            1001,
            'reduce reservation',
        );

        $this->assertSame(
            3.0,
            (float) DB::table('inventories')
                ->where('id', $fixture['inventory_id'])
                ->value('reserved_quantity'),
        );
        $this->assertSame(
            1.0,
            $service->activeReservationQuantity(
                $user,
                $fixture['warehouse_id'],
                $fixture['product_id'],
                'enterprise_test_order',
                1001,
            ),
        );

        $service->releaseReservation(
            $user,
            $fixture['warehouse_id'],
            $fixture['product_id'],
            'enterprise_test_order',
            1001,
            'release reservation',
        );
        $service->releaseReservation(
            $user,
            $fixture['warehouse_id'],
            $fixture['product_id'],
            'enterprise_test_order',
            1001,
            'release retry',
        );

        $this->assertSame(
            2.0,
            (float) DB::table('inventories')
                ->where('id', $fixture['inventory_id'])
                ->value('reserved_quantity'),
        );
        $this->assertSame(2, DB::table('stock_movements')
            ->where('inventory_id', $fixture['inventory_id'])
            ->where('reference_type', 'enterprise_test_order')
            ->where('reference_id', 1001)
            ->where('type', 'release')
            ->count());
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'enterprise.wms.stock_reservation.changed',
            'store_id' => $fixture['store_id'],
        ]);
    }

    public function test_reservation_rejects_overbooking_without_mutating_stock(): void
    {
        $this->seed(CoreReferenceSeeder::class);
        $fixture = $this->fixture();
        $user = $this->inventoryUser($fixture['store_id']);
        DB::table('inventories')
            ->where('id', $fixture['inventory_id'])
            ->update(['reserved_quantity' => 8]);

        try {
            app(StockReservationService::class)->ensureReservation(
                $user,
                $fixture['warehouse_id'],
                $fixture['product_id'],
                3,
                'enterprise_test_order',
                2001,
            );
            $this->fail('Expected overbooking to be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('quantity', $exception->errors());
        }

        $this->assertSame(
            8.0,
            (float) DB::table('inventories')
                ->where('id', $fixture['inventory_id'])
                ->value('reserved_quantity'),
        );
        $this->assertSame(0, DB::table('stock_movements')
            ->where('reference_type', 'enterprise_test_order')
            ->where('reference_id', 2001)
            ->count());
    }

    public function test_store_and_explicit_warehouse_scope_are_enforced(): void
    {
        $this->seed(CoreReferenceSeeder::class);
        $fixture = $this->fixture();
        $user = $this->inventoryUser($fixture['store_id']);
        $service = app(StockReservationService::class);

        try {
            $service->ensureReservation(
                $user,
                $fixture['other_store_warehouse_id'],
                $fixture['other_store_product_id'],
                1,
                'enterprise_test_order',
                3001,
            );
            $this->fail('Expected cross-store reservation to be denied.');
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

        $service->ensureReservation(
            $user,
            $fixture['other_warehouse_id'],
            $fixture['product_id'],
            1,
            'enterprise_test_order',
            3002,
        );
    }

    public function test_reference_lookup_is_indexed_and_query_count_is_bounded(): void
    {
        $this->seed(CoreReferenceSeeder::class);
        $fixture = $this->fixture();
        $user = $this->inventoryUser($fixture['store_id']);

        $indexes = collect(Schema::getIndexes('stock_movements'))->pluck('name')->all();
        $this->assertContains('stock_movements_store_ref_inventory_idx', $indexes);

        DB::flushQueryLog();
        DB::enableQueryLog();
        app(StockReservationService::class)->ensureReservation(
            $user,
            $fixture['warehouse_id'],
            $fixture['product_id'],
            2,
            'enterprise_test_order',
            4001,
        );
        $queryCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertLessThanOrEqual(18, $queryCount);
        $this->assertFalse(Schema::hasTable('enterprise_stock_reservations'));
    }

    public function test_reservation_index_migration_is_reversible_without_dropping_authority_tables(): void
    {
        $this->seed(CoreReferenceSeeder::class);
        $migration = require database_path(
            'migrations/2026_10_16_091300_add_stock_reservation_lookup_index.php',
        );

        $migration->down();
        $indexes = collect(Schema::getIndexes('stock_movements'))->pluck('name')->all();
        $this->assertNotContains('stock_movements_store_ref_inventory_idx', $indexes);
        $this->assertTrue(Schema::hasTable('inventories'));
        $this->assertTrue(Schema::hasTable('stock_movements'));

        $migration->up();
        $indexes = collect(Schema::getIndexes('stock_movements'))->pluck('name')->all();
        $this->assertContains('stock_movements_store_ref_inventory_idx', $indexes);
    }

    /** @return array{store_id:int,warehouse_id:int,other_warehouse_id:int,other_store_warehouse_id:int,product_id:int,other_store_product_id:int,inventory_id:int} */
    private function fixture(): array
    {
        $storeTypeId = (int) DB::table('store_types')->where('code', 'B2C')->value('id');
        $storeId = (int) DB::table('stores')->insertGetId([
            'store_type_id' => $storeTypeId,
            'code' => 'RESERVE-STORE',
            'name' => 'Reservation Store',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $otherStoreId = (int) DB::table('stores')->insertGetId([
            'store_type_id' => $storeTypeId,
            'code' => 'RESERVE-OTHER',
            'name' => 'Other Reservation Store',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $unitId = (int) DB::table('units')->insertGetId([
            'code' => 'EA-RESERVE',
            'name' => 'Each',
            'decimal_places' => 3,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $catalogId = (int) DB::table('catalogs')->insertGetId([
            'store_id' => $storeId,
            'channel' => 'b2c',
            'code' => 'reserve',
            'name' => 'Reservation Catalog',
            'is_active' => true,
            'is_migration_quarantine' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $otherCatalogId = (int) DB::table('catalogs')->insertGetId([
            'store_id' => $otherStoreId,
            'channel' => 'b2c',
            'code' => 'reserve-other',
            'name' => 'Other Reservation Catalog',
            'is_active' => true,
            'is_migration_quarantine' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $productId = (int) DB::table('products')->insertGetId([
            'catalog_id' => $catalogId,
            'unit_id' => $unitId,
            'sku' => 'RESERVE-P1',
            'name' => 'Reservation Product',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $otherStoreProductId = (int) DB::table('products')->insertGetId([
            'catalog_id' => $otherCatalogId,
            'unit_id' => $unitId,
            'sku' => 'RESERVE-P2',
            'name' => 'Other Reservation Product',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $warehouseId = (int) DB::table('warehouses')->insertGetId([
            'store_id' => $storeId,
            'code' => 'RESERVE-WH',
            'name' => 'Reservation Warehouse',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $otherWarehouseId = (int) DB::table('warehouses')->insertGetId([
            'store_id' => $storeId,
            'code' => 'RESERVE-WH-2',
            'name' => 'Other Reservation Warehouse',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $otherStoreWarehouseId = (int) DB::table('warehouses')->insertGetId([
            'store_id' => $otherStoreId,
            'code' => 'RESERVE-OTHER-WH',
            'name' => 'Other Store Warehouse',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $inventoryId = (int) DB::table('inventories')->insertGetId([
            'warehouse_id' => $warehouseId,
            'product_id' => $productId,
            'quantity' => 10,
            'reserved_quantity' => 2,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('inventories')->insert([
            [
                'warehouse_id' => $otherWarehouseId,
                'product_id' => $productId,
                'quantity' => 5,
                'reserved_quantity' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'warehouse_id' => $otherStoreWarehouseId,
                'product_id' => $otherStoreProductId,
                'quantity' => 5,
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
        $user = User::query()->create([
            'name' => 'WMS Reservation User',
            'email' => 'wms-reservation@example.test',
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
