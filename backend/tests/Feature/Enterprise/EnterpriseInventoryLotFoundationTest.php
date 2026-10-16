<?php

namespace Tests\Feature\Enterprise;

use App\Enterprise\Wms\InventoryLotService;
use App\Models\Role;
use App\Models\User;
use App\Models\UserStoreRole;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\Feature\Enterprise\Concerns\EnablesEnterpriseWms;
use Tests\TestCase;

final class EnterpriseInventoryLotFoundationTest extends TestCase
{
    use EnablesEnterpriseWms;
    use RefreshDatabase;

    public function test_lot_identifier_is_normalized_idempotent_and_status_lifecycle_is_audited(): void
    {
        $this->seed(CoreReferenceSeeder::class);
        $fixture = $this->fixture();
        $user = $this->inventoryUser($fixture['store_id']);
        $service = app(InventoryLotService::class);

        $lot = $service->upsert(
            $user,
            $fixture['warehouse_id'],
            $fixture['product_id'],
            [
                'identifier' => ' batch-01 ',
                'manufactured_on' => '2026-09-01',
                'received_on' => '2026-09-03',
                'expires_on' => '2026-11-15',
            ],
        );
        $retry = $service->upsert(
            $user,
            $fixture['warehouse_id'],
            $fixture['product_id'],
            [
                'identifier' => 'BATCH-01',
                'manufactured_on' => '2026-09-01',
                'received_on' => '2026-09-03',
                'expires_on' => '2026-11-15',
            ],
        );

        $this->assertSame($lot->id, $retry->id);
        $this->assertSame('BATCH-01', $retry->normalized_identifier);
        $this->assertSame('BATCH-01', $retry->identifier);
        $this->assertDatabaseCount('enterprise_inventory_lots', 1);

        $listed = $service->lotsForWarehouse(
            $user,
            $fixture['warehouse_id'],
            $fixture['product_id'],
            ' batch-01 ',
        );
        $this->assertCount(1, $listed);
        $this->assertSame($lot->id, $listed->firstOrFail()->id);

        $quarantined = $service->transitionStatus($user, $lot->id, 'quarantined');
        $quarantineRetry = $service->transitionStatus($user, $lot->id, 'QUARANTINED');
        $closed = $service->transitionStatus($user, $lot->id, 'closed');

        $this->assertSame('quarantined', $quarantined->status);
        $this->assertSame($quarantined->id, $quarantineRetry->id);
        $this->assertSame('closed', $closed->status);

        try {
            $service->transitionStatus($user, $lot->id, 'active');
            $this->fail('Expected a closed lot to reject reopening.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('status', $exception->errors());
        }

        $this->assertDatabaseHas('audit_logs', [
            'event' => 'enterprise.wms.inventory_lot.created',
            'store_id' => $fixture['store_id'],
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'enterprise.wms.inventory_lot.status_changed',
            'store_id' => $fixture['store_id'],
        ]);
    }

    public function test_lot_scope_denies_unassigned_and_cross_tenant_warehouses(): void
    {
        $this->seed(CoreReferenceSeeder::class);
        $fixture = $this->fixture();
        $user = $this->inventoryUser($fixture['store_id']);
        $service = app(InventoryLotService::class);

        DB::table('enterprise_warehouse_user_scopes')->insert([
            'user_id' => $user->id,
            'warehouse_id' => $fixture['warehouse_id'],
            'can_manage' => true,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        try {
            $service->upsert(
                $user,
                $fixture['other_warehouse_id'],
                $fixture['product_id'],
                ['identifier' => 'DENIED-WH'],
            );
            $this->fail('Expected explicit warehouse scope denial.');
        } catch (NotFoundHttpException) {
            $this->assertTrue(true);
        }

        try {
            $service->upsert(
                $user,
                $fixture['other_store_warehouse_id'],
                $fixture['other_store_product_id'],
                ['identifier' => 'DENIED-TENANT'],
            );
            $this->fail('Expected cross-tenant warehouse denial.');
        } catch (NotFoundHttpException) {
            $this->assertTrue(true);
        }
    }

    public function test_expiry_window_is_date_bounded_authorized_and_limited_to_one_hundred_rows(): void
    {
        $this->seed(CoreReferenceSeeder::class);
        $fixture = $this->fixture();
        $user = $this->inventoryUser($fixture['store_id']);
        $now = now();

        $rows = [];
        for ($index = 1; $index <= 105; $index++) {
            $rows[] = [
                'store_id' => $fixture['store_id'],
                'warehouse_id' => $fixture['warehouse_id'],
                'product_id' => $fixture['product_id'],
                'identifier' => 'EXP-'.$index,
                'normalized_identifier' => 'EXP-'.$index,
                'status' => 'active',
                'manufactured_on' => null,
                'received_on' => null,
                'expires_on' => '2026-11-'.str_pad((string) (($index % 28) + 1), 2, '0', STR_PAD_LEFT),
                'created_by' => $user->id,
                'updated_by' => $user->id,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        DB::table('enterprise_inventory_lots')->insert($rows);

        DB::table('enterprise_inventory_lots')->insert([
            'store_id' => $fixture['store_id'],
            'warehouse_id' => $fixture['other_warehouse_id'],
            'product_id' => $fixture['product_id'],
            'identifier' => 'OTHER-WH',
            'normalized_identifier' => 'OTHER-WH',
            'status' => 'active',
            'expires_on' => '2026-11-10',
            'created_by' => $user->id,
            'updated_by' => $user->id,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('enterprise_warehouse_user_scopes')->insert([
            'user_id' => $user->id,
            'warehouse_id' => $fixture['warehouse_id'],
            'can_manage' => true,
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $rows = app(InventoryLotService::class)->expiryWindow(
            $user,
            $fixture['store_id'],
            '2026-11-01',
            '2026-11-30',
            limit: 500,
        );
        $queryCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertCount(100, $rows);
        $this->assertTrue($rows->every(
            fn ($lot): bool => (int) $lot->warehouse_id === $fixture['warehouse_id'],
        ));
        $this->assertLessThanOrEqual(13, $queryCount);

        try {
            app(InventoryLotService::class)->expiryWindow(
                $user,
                $fixture['store_id'],
                '2026-01-01',
                '2026-12-31',
            );
            $this->fail('Expected an expiry window over 90 days to be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('to_date', $exception->errors());
        }
    }

    public function test_movement_link_is_idempotent_scope_safe_and_does_not_change_inventory_balance(): void
    {
        $this->seed(CoreReferenceSeeder::class);
        $fixture = $this->fixture();
        $user = $this->inventoryUser($fixture['store_id']);
        $service = app(InventoryLotService::class);

        $lot = $service->upsert(
            $user,
            $fixture['warehouse_id'],
            $fixture['product_id'],
            ['identifier' => 'MOVE-LOT-1'],
        );

        $movementId = (int) DB::table('stock_movements')->insertGetId([
            'inventory_id' => $fixture['inventory_id'],
            'store_id' => $fixture['store_id'],
            'user_id' => $user->id,
            'type' => 'manual_adjustment',
            'quantity' => 2,
            'reference_type' => 'test',
            'reference_id' => 1,
            'reason' => 'lot linkage test',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $before = (float) DB::table('inventories')
            ->where('id', $fixture['inventory_id'])
            ->value('quantity');

        $link = $service->linkMovement($user, $lot->id, $movementId);
        $retry = $service->linkMovement($user, $lot->id, $movementId);

        $this->assertSame($link->id, $retry->id);
        $this->assertDatabaseCount('enterprise_inventory_lot_movements', 1);
        $this->assertSame(
            $before,
            (float) DB::table('inventories')
                ->where('id', $fixture['inventory_id'])
                ->value('quantity'),
        );
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'enterprise.wms.inventory_lot.movement_linked',
            'store_id' => $fixture['store_id'],
        ]);

        $otherMovementId = (int) DB::table('stock_movements')->insertGetId([
            'inventory_id' => $fixture['other_inventory_id'],
            'store_id' => $fixture['store_id'],
            'user_id' => $user->id,
            'type' => 'manual_adjustment',
            'quantity' => 1,
            'reference_type' => 'test',
            'reference_id' => 2,
            'reason' => 'wrong warehouse',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        try {
            $service->linkMovement($user, $lot->id, $otherMovementId);
            $this->fail('Expected a movement from another warehouse to be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('stock_movement_id', $exception->errors());
        }
    }

    public function test_lot_date_order_validation_rejects_expiry_before_receipt(): void
    {
        $this->seed(CoreReferenceSeeder::class);
        $fixture = $this->fixture();
        $user = $this->inventoryUser($fixture['store_id']);

        $this->expectException(ValidationException::class);

        app(InventoryLotService::class)->upsert(
            $user,
            $fixture['warehouse_id'],
            $fixture['product_id'],
            [
                'identifier' => 'BAD-DATES',
                'received_on' => '2026-11-10',
                'expires_on' => '2026-11-01',
            ],
        );
    }

    /**
     * @return array{
     *   store_id:int,
     *   warehouse_id:int,
     *   other_warehouse_id:int,
     *   other_store_warehouse_id:int,
     *   product_id:int,
     *   other_store_product_id:int,
     *   inventory_id:int,
     *   other_inventory_id:int
     * }
     */
    private function fixture(): array
    {
        $storeTypeId = (int) DB::table('store_types')->where('code', 'B2C')->value('id');
        $storeId = (int) DB::table('stores')->insertGetId([
            'store_type_id' => $storeTypeId,
            'code' => 'LOT-STORE',
            'name' => 'Lot Store',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $otherStoreId = (int) DB::table('stores')->insertGetId([
            'store_type_id' => $storeTypeId,
            'code' => 'LOT-OTHER-STORE',
            'name' => 'Other Lot Store',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $unitId = (int) DB::table('units')->insertGetId([
            'code' => 'EA-LOT',
            'name' => 'Each',
            'decimal_places' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $catalogId = (int) DB::table('catalogs')->insertGetId([
            'store_id' => $storeId,
            'channel' => 'b2c',
            'code' => 'default',
            'name' => 'Lot Catalog',
            'is_active' => true,
            'is_migration_quarantine' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $otherCatalogId = (int) DB::table('catalogs')->insertGetId([
            'store_id' => $otherStoreId,
            'channel' => 'b2c',
            'code' => 'default',
            'name' => 'Other Lot Catalog',
            'is_active' => true,
            'is_migration_quarantine' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $productId = (int) DB::table('products')->insertGetId([
            'catalog_id' => $catalogId,
            'unit_id' => $unitId,
            'sku' => 'LOT-P-1',
            'name' => 'Lot Product',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $otherStoreProductId = (int) DB::table('products')->insertGetId([
            'catalog_id' => $otherCatalogId,
            'unit_id' => $unitId,
            'sku' => 'LOT-P-OTHER',
            'name' => 'Other Lot Product',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $warehouseId = (int) DB::table('warehouses')->insertGetId([
            'store_id' => $storeId,
            'code' => 'LOT-WH',
            'name' => 'Lot Warehouse',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $otherWarehouseId = (int) DB::table('warehouses')->insertGetId([
            'store_id' => $storeId,
            'code' => 'LOT-WH-2',
            'name' => 'Other Lot Warehouse',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $otherStoreWarehouseId = (int) DB::table('warehouses')->insertGetId([
            'store_id' => $otherStoreId,
            'code' => 'LOT-OTHER-WH',
            'name' => 'Other Store Lot Warehouse',
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
        $otherInventoryId = (int) DB::table('inventories')->insertGetId([
            'warehouse_id' => $otherWarehouseId,
            'product_id' => $productId,
            'quantity' => 5,
            'reserved_quantity' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('inventories')->insert([
            'warehouse_id' => $otherStoreWarehouseId,
            'product_id' => $otherStoreProductId,
            'quantity' => 7,
            'reserved_quantity' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [
            'store_id' => $storeId,
            'warehouse_id' => $warehouseId,
            'other_warehouse_id' => $otherWarehouseId,
            'other_store_warehouse_id' => $otherStoreWarehouseId,
            'product_id' => $productId,
            'other_store_product_id' => $otherStoreProductId,
            'inventory_id' => $inventoryId,
            'other_inventory_id' => $otherInventoryId,
        ];
    }

    private function inventoryUser(int $storeId): User
    {
        $this->enableEnterpriseWmsForStore($storeId);
        $user = User::query()->create([
            'name' => 'Lot Inventory',
            'email' => 'lot-inventory@example.test',
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
