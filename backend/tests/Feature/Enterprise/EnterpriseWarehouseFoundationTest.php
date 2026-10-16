<?php

namespace Tests\Feature\Enterprise;

use App\Enterprise\Wms\InventoryCountService;
use App\Enterprise\Wms\WarehouseScope;
use App\Enterprise\Wms\WarehouseTransferService;
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

class EnterpriseWarehouseFoundationTest extends TestCase
{
    use EnablesEnterpriseWms;
    use RefreshDatabase;

    public function test_warehouse_scope_preserves_store_rbac_and_can_narrow_access_to_explicit_warehouses(): void
    {
        $this->seed(CoreReferenceSeeder::class);
        $fixture = $this->fixture();
        $user = $this->inventoryUser($fixture['store_id']);

        DB::table('enterprise_warehouse_user_scopes')->insert([
            'user_id' => $user->id,
            'warehouse_id' => $fixture['source_warehouse_id'],
            'can_manage' => true,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $scope = app(WarehouseScope::class);
        $this->assertSame(
            $fixture['source_warehouse_id'],
            $scope->assertWarehouse($user, $fixture['source_warehouse_id'])->id,
        );

        try {
            $scope->assertWarehouse($user, $fixture['target_warehouse_id']);
            $this->fail('Expected explicit warehouse scope to deny an unassigned warehouse.');
        } catch (NotFoundHttpException) {
            $this->assertTrue(true);
        }

        try {
            $scope->assertWarehouse($user, $fixture['other_store_warehouse_id']);
            $this->fail('Expected store tenancy to deny a warehouse from another store.');
        } catch (NotFoundHttpException) {
            $this->assertTrue(true);
        }

        DB::table('enterprise_warehouse_user_scopes')->insert([
            'user_id' => $user->id,
            'warehouse_id' => $fixture['target_warehouse_id'],
            'can_manage' => true,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $pair = $scope->assertTransferPair(
            $user,
            $fixture['source_warehouse_id'],
            $fixture['target_warehouse_id'],
        );

        $this->assertSame($fixture['source_warehouse_id'], $pair[0]->id);
        $this->assertSame($fixture['target_warehouse_id'], $pair[1]->id);
    }

    public function test_transfer_lifecycle_is_idempotent_and_links_both_sides_to_authoritative_stock_movements(): void
    {
        $this->seed(CoreReferenceSeeder::class);
        $fixture = $this->fixture();
        $user = $this->inventoryUser($fixture['store_id']);
        $service = app(WarehouseTransferService::class);

        $first = $service->create(
            $user,
            $fixture['source_warehouse_id'],
            $fixture['target_warehouse_id'],
            [['product_id' => $fixture['product_id'], 'quantity' => 3]],
            'transfer-001',
        );
        $retry = $service->create(
            $user,
            $fixture['source_warehouse_id'],
            $fixture['target_warehouse_id'],
            [['product_id' => $fixture['product_id'], 'quantity' => 3]],
            'transfer-001',
        );

        $this->assertSame($first->id, $retry->id);
        $this->assertDatabaseCount('enterprise_warehouse_transfers', 1);
        $this->assertDatabaseCount('enterprise_warehouse_transfer_lines', 1);

        $service->dispatch($user, $first->id);
        $service->dispatch($user, $first->id);

        $this->assertSame(
            7.0,
            (float) DB::table('inventories')->where('id', $fixture['source_inventory_id'])->value('quantity'),
        );
        $this->assertSame(1, DB::table('stock_movements')
            ->where('reference_type', 'enterprise_warehouse_transfer')
            ->where('reference_id', $first->id)
            ->where('type', 'warehouse_transfer_out')
            ->count());

        $service->receive($user, $first->id);
        $service->receive($user, $first->id);

        $this->assertSame(
            7.0,
            (float) DB::table('inventories')->where('id', $fixture['target_inventory_id'])->value('quantity'),
        );
        $this->assertSame(1, DB::table('stock_movements')
            ->where('reference_type', 'enterprise_warehouse_transfer')
            ->where('reference_id', $first->id)
            ->where('type', 'warehouse_transfer_in')
            ->count());

        $this->assertDatabaseHas('enterprise_warehouse_transfers', [
            'id' => $first->id,
            'status' => 'received',
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'enterprise.warehouse_transfer.created',
            'store_id' => $fixture['store_id'],
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'enterprise.warehouse_transfer.dispatched',
            'store_id' => $fixture['store_id'],
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'enterprise.warehouse_transfer.received',
            'store_id' => $fixture['store_id'],
        ]);
    }

    public function test_transfer_rejects_idempotency_key_reuse_for_different_payload(): void
    {
        $this->seed(CoreReferenceSeeder::class);
        $fixture = $this->fixture();
        $user = $this->inventoryUser($fixture['store_id']);
        $service = app(WarehouseTransferService::class);

        $service->create(
            $user,
            $fixture['source_warehouse_id'],
            $fixture['target_warehouse_id'],
            [['product_id' => $fixture['product_id'], 'quantity' => 2]],
            'transfer-collision',
        );

        $this->expectException(ValidationException::class);

        $service->create(
            $user,
            $fixture['source_warehouse_id'],
            $fixture['target_warehouse_id'],
            [['product_id' => $fixture['product_id'], 'quantity' => 3]],
            'transfer-collision',
        );
    }

    public function test_inventory_count_is_idempotent_and_rejects_stale_concurrent_inventory(): void
    {
        $this->seed(CoreReferenceSeeder::class);
        $fixture = $this->fixture();
        $user = $this->inventoryUser($fixture['store_id']);
        $service = app(InventoryCountService::class);

        $count = $service->start(
            $user,
            $fixture['source_warehouse_id'],
            [$fixture['source_inventory_id']],
            'count-001',
        );
        $retry = $service->start(
            $user,
            $fixture['source_warehouse_id'],
            [$fixture['source_inventory_id']],
            'count-001',
        );

        $this->assertSame($count->id, $retry->id);

        $service->complete($user, $count->id, [
            $fixture['source_inventory_id'] => 8,
        ]);
        $service->complete($user, $count->id, [
            $fixture['source_inventory_id'] => 8,
        ]);

        $this->assertSame(
            8.0,
            (float) DB::table('inventories')->where('id', $fixture['source_inventory_id'])->value('quantity'),
        );
        $this->assertSame(1, DB::table('stock_movements')
            ->where('reference_type', 'enterprise_inventory_count')
            ->where('reference_id', $count->id)
            ->where('type', 'cycle_count_adjustment')
            ->count());

        $stale = $service->start(
            $user,
            $fixture['target_warehouse_id'],
            [$fixture['target_inventory_id']],
            'count-002',
        );

        DB::table('inventories')
            ->where('id', $fixture['target_inventory_id'])
            ->increment('quantity', 1);

        try {
            $service->complete($user, $stale->id, [
                $fixture['target_inventory_id'] => 5,
            ]);
            $this->fail('Expected a stale inventory count to be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('counts', $exception->errors());
        }

        $this->assertDatabaseHas('enterprise_inventory_counts', [
            'id' => $stale->id,
            'status' => 'open',
        ]);
        $this->assertSame(0, DB::table('stock_movements')
            ->where('reference_type', 'enterprise_inventory_count')
            ->where('reference_id', $stale->id)
            ->count());
    }

    public function test_transfer_and_count_lists_are_bounded_to_one_hundred_records(): void
    {
        $this->seed(CoreReferenceSeeder::class);
        $fixture = $this->fixture();
        $user = $this->inventoryUser($fixture['store_id']);
        $now = now();

        $transfers = [];
        $counts = [];
        for ($index = 1; $index <= 125; $index++) {
            $transfers[] = [
                'store_id' => $fixture['store_id'],
                'source_warehouse_id' => $fixture['source_warehouse_id'],
                'target_warehouse_id' => $fixture['target_warehouse_id'],
                'status' => 'draft',
                'idempotency_key' => 'bulk-transfer-'.$index,
                'request_fingerprint' => hash('sha256', 'transfer-'.$index),
                'created_by' => $user->id,
                'created_at' => $now,
                'updated_at' => $now,
            ];
            $counts[] = [
                'store_id' => $fixture['store_id'],
                'warehouse_id' => $fixture['source_warehouse_id'],
                'status' => 'open',
                'idempotency_key' => 'bulk-count-'.$index,
                'request_fingerprint' => hash('sha256', 'count-'.$index),
                'created_by' => $user->id,
                'started_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        DB::table('enterprise_warehouse_transfers')->insert($transfers);
        DB::table('enterprise_inventory_counts')->insert($counts);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $listedTransfers = app(WarehouseTransferService::class)->listForStore(
            $user,
            $fixture['store_id'],
            limit: 500,
        );
        $transferQueryCount = count(DB::getQueryLog());

        DB::flushQueryLog();
        $listedCounts = app(InventoryCountService::class)->listForStore(
            $user,
            $fixture['store_id'],
            limit: 500,
        );
        $countQueryCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertCount(100, $listedTransfers);
        $this->assertCount(100, $listedCounts);
        $this->assertLessThanOrEqual(12, $transferQueryCount);
        $this->assertLessThanOrEqual(12, $countQueryCount);
    }

    /** @return array{store_id:int,source_warehouse_id:int,target_warehouse_id:int,other_store_warehouse_id:int,product_id:int,source_inventory_id:int,target_inventory_id:int} */
    private function fixture(): array
    {
        $storeTypeId = (int) DB::table('store_types')->where('code', 'B2C')->value('id');
        $storeId = (int) DB::table('stores')->insertGetId([
            'store_type_id' => $storeTypeId,
            'code' => 'WMS-1',
            'name' => 'WMS Store',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $otherStoreId = (int) DB::table('stores')->insertGetId([
            'store_type_id' => $storeTypeId,
            'code' => 'WMS-2',
            'name' => 'Other Store',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $unitId = (int) DB::table('units')->insertGetId([
            'code' => 'EA-WMS',
            'name' => 'Each',
            'decimal_places' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $catalogId = (int) DB::table('catalogs')->insertGetId([
            'store_id' => $storeId,
            'channel' => 'b2c',
            'code' => 'default',
            'name' => 'WMS Catalog',
            'is_active' => true,
            'is_migration_quarantine' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $otherCatalogId = (int) DB::table('catalogs')->insertGetId([
            'store_id' => $otherStoreId,
            'channel' => 'b2c',
            'code' => 'default',
            'name' => 'Other Catalog',
            'is_active' => true,
            'is_migration_quarantine' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $productId = (int) DB::table('products')->insertGetId([
            'catalog_id' => $catalogId,
            'unit_id' => $unitId,
            'sku' => 'WMS-P-1',
            'name' => 'WMS Product',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $otherProductId = (int) DB::table('products')->insertGetId([
            'catalog_id' => $otherCatalogId,
            'unit_id' => $unitId,
            'sku' => 'WMS-P-2',
            'name' => 'Other Product',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $sourceWarehouseId = (int) DB::table('warehouses')->insertGetId([
            'store_id' => $storeId,
            'code' => 'WMS-SOURCE',
            'name' => 'Source Warehouse',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $targetWarehouseId = (int) DB::table('warehouses')->insertGetId([
            'store_id' => $storeId,
            'code' => 'WMS-TARGET',
            'name' => 'Target Warehouse',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $otherStoreWarehouseId = (int) DB::table('warehouses')->insertGetId([
            'store_id' => $otherStoreId,
            'code' => 'WMS-OTHER',
            'name' => 'Other Warehouse',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $sourceInventoryId = (int) DB::table('inventories')->insertGetId([
            'warehouse_id' => $sourceWarehouseId,
            'product_id' => $productId,
            'quantity' => 10,
            'reserved_quantity' => 2,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $targetInventoryId = (int) DB::table('inventories')->insertGetId([
            'warehouse_id' => $targetWarehouseId,
            'product_id' => $productId,
            'quantity' => 4,
            'reserved_quantity' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('inventories')->insert([
            'warehouse_id' => $otherStoreWarehouseId,
            'product_id' => $otherProductId,
            'quantity' => 5,
            'reserved_quantity' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [
            'store_id' => $storeId,
            'source_warehouse_id' => $sourceWarehouseId,
            'target_warehouse_id' => $targetWarehouseId,
            'other_store_warehouse_id' => $otherStoreWarehouseId,
            'product_id' => $productId,
            'source_inventory_id' => $sourceInventoryId,
            'target_inventory_id' => $targetInventoryId,
        ];
    }

    private function inventoryUser(int $storeId): User
    {
        $this->enableEnterpriseWmsForStore($storeId);
        $user = User::query()->create([
            'name' => 'WMS Inventory',
            'email' => 'wms-inventory@example.test',
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
