<?php

namespace Tests\Feature\Enterprise;

use App\Enterprise\Wms\InventoryAdjustmentService;
use App\Enterprise\Wms\WmsReportingService;
use App\Models\Role;
use App\Models\User;
use App\Models\UserStoreRole;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class EnterpriseWmsReportingFoundationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_inventory_summary_is_bounded_single_query_composition_over_canonical_authorities(): void
    {
        $fixture = $this->fixture('WMS-REPORT-SUMMARY');
        $user = $this->inventoryUser($fixture['store_id'], 'summary');

        DB::table('enterprise_inventory_valuation_policies')->insert([
            'store_id' => $fixture['store_id'],
            'warehouse_id' => $fixture['warehouse_id'],
            'product_id' => $fixture['product_id'],
            'cost_method' => 'standard',
            'unit_cost' => 2.5,
            'is_active' => true,
            'created_by' => $user->id,
            'updated_by' => $user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('stock_movements')->insert([
            'inventory_id' => $fixture['inventory_id'],
            'store_id' => $fixture['store_id'],
            'user_id' => $user->id,
            'type' => 'purchase_receipt',
            'quantity' => 10,
            'reference_type' => 'wms-report-test',
            'reference_id' => 1,
            'reason' => 'aging source',
            'created_at' => now()->subDays(120),
            'updated_at' => now()->subDays(120),
        ]);

        $beforeQuantity = (float) DB::table('inventories')
            ->where('id', $fixture['inventory_id'])
            ->value('quantity');

        DB::flushQueryLog();
        DB::enableQueryLog();
        $rows = app(WmsReportingService::class)->inventorySummary(
            $user,
            $fixture['store_id'],
            $fixture['warehouse_id'],
            limit: 500,
        );
        $queryCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertCount(1, $rows);
        $row = $rows->first();
        $this->assertSame($fixture['inventory_id'], (int) $row->inventory_id);
        $this->assertSame(10.0, (float) $row->quantity);
        $this->assertSame(2.0, (float) $row->reserved_quantity);
        $this->assertSame(8.0, (float) $row->available_quantity);
        $this->assertSame(25.0, (float) $row->inventory_value);
        $this->assertSame('91_plus', $row->aging_bucket);
        $this->assertSame('slow', $row->stock_status);
        $this->assertSame($fixture['inventory_id'], $row->drill_down['inventory_id']);
        $this->assertLessThanOrEqual(12, $queryCount);
        $this->assertSame(
            $beforeQuantity,
            (float) DB::table('inventories')->where('id', $fixture['inventory_id'])->value('quantity'),
        );
    }

    public function test_lot_expiry_is_scope_safe_cursor_bounded_and_has_constant_query_count(): void
    {
        $fixture = $this->fixture('WMS-REPORT-LOTS');
        $user = $this->inventoryUser($fixture['store_id'], 'lots');

        DB::table('enterprise_warehouse_user_scopes')->insert([
            'user_id' => $user->id,
            'warehouse_id' => $fixture['warehouse_id'],
            'can_manage' => true,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $rows = [];
        for ($index = 1; $index <= 105; $index++) {
            $rows[] = [
                'store_id' => $fixture['store_id'],
                'warehouse_id' => $fixture['warehouse_id'],
                'product_id' => $fixture['product_id'],
                'identifier' => 'REPORT-LOT-'.$index,
                'normalized_identifier' => 'REPORT-LOT-'.$index,
                'status' => 'active',
                'manufactured_on' => null,
                'received_on' => null,
                'expires_on' => '2026-11-'.str_pad((string) (($index % 28) + 1), 2, '0', STR_PAD_LEFT),
                'created_by' => $user->id,
                'updated_by' => $user->id,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }
        DB::table('enterprise_inventory_lots')->insert($rows);

        DB::table('enterprise_inventory_lots')->insert([
            'store_id' => $fixture['store_id'],
            'warehouse_id' => $fixture['other_warehouse_id'],
            'product_id' => $fixture['product_id'],
            'identifier' => 'OUT-OF-SCOPE',
            'normalized_identifier' => 'OUT-OF-SCOPE',
            'status' => 'active',
            'manufactured_on' => null,
            'received_on' => null,
            'expires_on' => '2026-11-10',
            'created_by' => $user->id,
            'updated_by' => $user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $page = app(WmsReportingService::class)->lotExpiry(
            $user,
            $fixture['store_id'],
            '2026-11-01',
            '2026-11-30',
            limit: 500,
        );
        $queryCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertCount(100, $page);
        $this->assertTrue($page->every(
            fn (\stdClass $row): bool => (int) $row->warehouse_id === $fixture['warehouse_id'],
        ));
        $this->assertLessThanOrEqual(12, $queryCount);

        $lastId = (int) $page->last()->inventory_lot_id;
        $next = app(WmsReportingService::class)->lotExpiry(
            $user,
            $fixture['store_id'],
            '2026-11-01',
            '2026-11-30',
            afterLotId: $lastId,
            limit: 100,
        );

        $this->assertCount(5, $next);
        $this->assertTrue($next->every(
            fn (\stdClass $row): bool => (int) $row->inventory_lot_id > $lastId,
        ));

        $this->expectException(ValidationException::class);
        app(WmsReportingService::class)->lotExpiry(
            $user,
            $fixture['store_id'],
            '2026-01-01',
            '2026-12-31',
        );
    }

    public function test_adjustment_report_reads_canonical_movements_without_mutating_inventory(): void
    {
        $fixture = $this->fixture('WMS-REPORT-ADJUST');
        $user = $this->inventoryUser($fixture['store_id'], 'adjustment');

        $movement = app(InventoryAdjustmentService::class)->post(
            $user,
            $fixture['warehouse_id'],
            $fixture['product_id'],
            -1,
            'damage',
            'wms-report-adjustment',
            77,
            'damaged during handling',
            'report source',
        );

        $afterPosting = (float) DB::table('inventories')
            ->where('id', $fixture['inventory_id'])
            ->value('quantity');

        $today = now()->toDateString();
        $rows = app(WmsReportingService::class)->adjustmentExceptions(
            $user,
            $fixture['store_id'],
            $today,
            $today,
            $fixture['warehouse_id'],
            $fixture['product_id'],
            'damage',
        );

        $this->assertCount(1, $rows);
        $row = $rows->first();
        $this->assertSame($movement->id, (int) $row->stock_movement_id);
        $this->assertSame('damage', $row->classification);
        $this->assertSame($movement->id, $row->drill_down['stock_movement_id']);
        $this->assertSame(
            $afterPosting,
            (float) DB::table('inventories')->where('id', $fixture['inventory_id'])->value('quantity'),
        );
    }

    public function test_large_export_handoff_matches_enterprise_queue_contract_and_preserves_scope(): void
    {
        $fixture = $this->fixture('WMS-REPORT-EXPORT');
        $user = $this->inventoryUser($fixture['store_id'], 'export');

        DB::table('enterprise_warehouse_user_scopes')->insert([
            'user_id' => $user->id,
            'warehouse_id' => $fixture['warehouse_id'],
            'can_manage' => false,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $beforeInventory = DB::table('inventories')->count();
        $beforeMovements = DB::table('stock_movements')->count();

        $handoff = app(WmsReportingService::class)->largeExportHandoff(
            $user,
            'wms.lot_expiry',
            $fixture['store_id'],
            [
                'warehouse_id' => $fixture['warehouse_id'],
                'product_id' => $fixture['product_id'],
            ],
            5001,
            '2026-11-01',
            '2026-11-30',
            'wms-large-export',
        );

        $this->assertSame('wms.lot_expiry', $handoff['report']);
        $this->assertSame('wms_lot_expiry', $handoff['read_model']);
        $this->assertSame('store', $handoff['scope_type']);
        $this->assertSame($fixture['store_id'], $handoff['scope_id']);
        $this->assertSame([$fixture['warehouse_id']], $handoff['authorized_warehouse_ids']);
        $this->assertSame(WmsReportingService::EXPORT_CHUNK_SIZE, $handoff['chunk_size']);
        $this->assertSame(WmsReportingService::EXPORT_QUEUE, $handoff['queue']);
        $this->assertSame('queued_chunked', $handoff['execution_mode']);
        $this->assertFalse($handoff['synchronous_request']);
        $this->assertSame(5001, $handoff['row_count_estimate']);
        $this->assertSame(hash('sha256', 'explicit:wms-large-export'), $handoff['idempotency_key']);
        $this->assertSame($beforeInventory, DB::table('inventories')->count());
        $this->assertSame($beforeMovements, DB::table('stock_movements')->count());

        $this->expectException(ValidationException::class);
        app(WmsReportingService::class)->largeExportHandoff(
            $user,
            'wms.inventory_summary',
            $fixture['store_id'],
            ['warehouse_id' => $fixture['warehouse_id']],
            WmsReportingService::LARGE_EXPORT_ROW_THRESHOLD,
        );
    }

    /**
     * @return array{
     *   store_id:int,
     *   warehouse_id:int,
     *   other_warehouse_id:int,
     *   product_id:int,
     *   inventory_id:int
     * }
     */
    private function fixture(string $code): array
    {
        $storeTypeId = (int) DB::table('store_types')->where('code', 'B2C')->value('id');
        $storeId = (int) DB::table('stores')->insertGetId([
            'store_type_id' => $storeTypeId,
            'code' => $code,
            'name' => $code,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $unitId = (int) DB::table('units')->insertGetId([
            'code' => 'EA-'.$code,
            'name' => 'Each',
            'decimal_places' => 3,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $catalogId = (int) DB::table('catalogs')->insertGetId([
            'store_id' => $storeId,
            'channel' => 'b2c',
            'code' => 'catalog-'.$code,
            'name' => 'Catalog '.$code,
            'is_active' => true,
            'is_migration_quarantine' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $productId = (int) DB::table('products')->insertGetId([
            'catalog_id' => $catalogId,
            'unit_id' => $unitId,
            'sku' => 'SKU-'.$code,
            'name' => 'Product '.$code,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $warehouseId = (int) DB::table('warehouses')->insertGetId([
            'store_id' => $storeId,
            'code' => 'WH-'.$code,
            'name' => 'Warehouse '.$code,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $otherWarehouseId = (int) DB::table('warehouses')->insertGetId([
            'store_id' => $storeId,
            'code' => 'WH2-'.$code,
            'name' => 'Warehouse 2 '.$code,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $inventoryId = (int) DB::table('inventories')->insertGetId([
            'warehouse_id' => $warehouseId,
            'product_id' => $productId,
            'quantity' => 10,
            'reserved_quantity' => 2,
            'created_at' => now()->subDays(150),
            'updated_at' => now(),
        ]);

        return [
            'store_id' => $storeId,
            'warehouse_id' => $warehouseId,
            'other_warehouse_id' => $otherWarehouseId,
            'product_id' => $productId,
            'inventory_id' => $inventoryId,
        ];
    }

    private function inventoryUser(int $storeId, string $suffix): User
    {
        $user = User::query()->create([
            'name' => 'WMS Reporting '.$suffix,
            'email' => 'wms-reporting-'.$suffix.'@example.test',
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
