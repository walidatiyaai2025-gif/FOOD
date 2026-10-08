<?php

namespace Tests\Feature;

use App\Services\RetailInventoryIntelligenceService;
use Carbon\CarbonImmutable;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RetailInventoryIntelligenceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_weighted_velocity_excludes_cancelled_and_refunded_sales_and_aggregates_warehouses(): void
    {
        $asOf = CarbonImmutable::parse('2026-10-08 12:00:00', 'Asia/Kuwait');
        [$storeId, $productId] = $this->retailProduct('INTELLIGENCE-WEIGHTED', $asOf->subDays(45), 2.000);
        [$inventoryA, $inventoryB] = $this->stock($storeId, $productId, [
            [10, 2],
            [8, 1],
        ]);

        $this->sale($storeId, $productId, 14, $asOf->subDay(), 'delivered', 'VALID-RECENT');
        $this->sale($storeId, $productId, 7, $asOf->subDays(10), 'completed', 'VALID-MIDDLE');
        $this->sale($storeId, $productId, 16, $asOf->subDays(20), 'processing', 'VALID-OLDER');
        $this->sale($storeId, $productId, 100, $asOf->subDay(), 'cancelled', 'CANCELLED');
        $this->sale($storeId, $productId, 100, $asOf->subDay(), 'refunded', 'REFUNDED');

        $this->movement($storeId, $inventoryA, 5, $asOf->subDays(2), 'purchase_receipt');
        $this->movement($storeId, $inventoryA, 5, $asOf->subDays(20), 'purchase_receipt');
        $this->movement($storeId, $inventoryB, 1, $asOf->subDays(20), 'adjustment');
        $this->movement($storeId, $inventoryB, 7, $asOf->subDays(70), 'seed_opening_balance');

        $result = app(RetailInventoryIntelligenceService::class)->forStore($storeId, $asOf);
        $product = $result['products'][0];

        $this->assertSame('Asia/Kuwait', $result['timezone']);
        $this->assertSame(18.0, $product['stock']['on_hand']);
        $this->assertSame(3.0, $product['stock']['reserved']);
        $this->assertSame(15.0, $product['stock']['available']);
        $this->assertSame(14.0, $product['sales']['units']['days_1_7']);
        $this->assertSame(7.0, $product['sales']['units']['days_8_14']);
        $this->assertSame(16.0, $product['sales']['units']['days_15_30']);
        $this->assertSame(37.0, $product['sales']['units']['days_1_30']);
        $this->assertSame(1.5, $product['sales']['velocity_units_per_day']);
        $this->assertSame(10.0, $product['cover']['days']);
        $this->assertSame('fast', $product['movement_class']);
        $this->assertSame('balanced', $product['stock_risk']);
        $this->assertSame('high', $product['confidence']['state']);

        $this->assertSame(
            5.0,
            $product['aging']['buckets']['days_0_7']['quantity'],
        );
        $this->assertSame(
            6.0,
            $product['aging']['buckets']['days_8_30']['quantity'],
        );
        $this->assertSame(
            7.0,
            $product['aging']['buckets']['days_61_plus']['quantity'],
        );
        $this->assertSame(
            14.0,
            $product['aging']['buckets']['days_61_plus']['value'],
        );
        $this->assertSame(0.0, $product['aging']['buckets']['unknown']['quantity']);
        $this->assertStringStartsWith('2026-10-18T12:00:00', $product['cover']['estimated_stockout_at']);
    }

    public function test_zero_sales_and_sparse_history_are_explicit_not_fabricated(): void
    {
        $asOf = CarbonImmutable::parse('2026-10-08 12:00:00', 'Asia/Kuwait');

        [$matureStore, $matureProduct] = $this->retailProduct(
            'INTELLIGENCE-NO-DEMAND',
            $asOf->subDays(40),
            1.500,
        );
        $this->stock($matureStore, $matureProduct, [[20, 5]]);

        $mature = app(RetailInventoryIntelligenceService::class)
            ->forStore($matureStore, $asOf)['products'][0];

        $this->assertSame(0.0, $mature['sales']['velocity_units_per_day']);
        $this->assertNull($mature['cover']['days']);
        $this->assertNull($mature['cover']['estimated_stockout_at']);
        $this->assertSame('no_demand', $mature['movement_class']);
        $this->assertSame('overstock_no_demand', $mature['stock_risk']);
        $this->assertSame('high', $mature['confidence']['state']);
        $this->assertSame(15.0, $mature['stock']['available']);
        $this->assertSame(20.0, $mature['aging']['buckets']['unknown']['quantity']);

        [$newStore, $newProduct] = $this->retailProduct(
            'INTELLIGENCE-COLD-START',
            $asOf->subDays(2),
            1.000,
        );
        $this->stock($newStore, $newProduct, [[3, 0]]);
        $this->sale($newStore, $newProduct, 3, $asOf->subDay(), 'delivered', 'COLD-SALE');

        $cold = app(RetailInventoryIntelligenceService::class)
            ->forStore($newStore, $asOf)['products'][0];

        $this->assertSame(3, $cold['confidence']['history_days']);
        $this->assertSame('insufficient', $cold['confidence']['state']);
        $this->assertTrue($cold['confidence']['cold_start']);
        $this->assertSame(1.0, $cold['sales']['velocity_units_per_day']);
        $this->assertSame('insufficient_data', $cold['stock_risk']);
        $this->assertSame(1.0, $cold['sales']['effective_weights']['days_1_7']);
        $this->assertSame(0.0, $cold['sales']['effective_weights']['days_8_14']);
    }

    public function test_negative_available_stock_is_reported_as_inventory_inconsistent(): void
    {
        $asOf = CarbonImmutable::parse('2026-10-08 12:00:00', 'Asia/Kuwait');
        [$storeId, $productId] = $this->retailProduct(
            'INTELLIGENCE-INCONSISTENT',
            $asOf->subDays(40),
            1.000,
        );
        $this->stock($storeId, $productId, [[2, 3]]);

        $product = app(RetailInventoryIntelligenceService::class)
            ->forStore($storeId, $asOf)['products'][0];

        $this->assertSame(-1.0, $product['stock']['available']);
        $this->assertSame('inventory_inconsistent', $product['stock_risk']);
    }

    /** @return array{0:int,1:int} */
    private function retailProduct(
        string $code,
        CarbonImmutable $listedAt,
        float $cost,
    ): array {
        $storeId = (int) DB::table('stores')->insertGetId([
            'store_type_id' => DB::table('store_types')->where('code', 'B2C')->value('id'),
            'code' => $code,
            'name' => $code,
            'is_active' => true,
            'created_at' => $listedAt->utc()->toDateTimeString(),
            'updated_at' => $listedAt->utc()->toDateTimeString(),
        ]);
        $catalogId = (int) DB::table('catalogs')->insertGetId([
            'store_id' => $storeId,
            'channel' => 'b2c',
            'code' => 'default',
            'name' => $code.' Catalog',
            'is_active' => true,
            'is_migration_quarantine' => false,
            'created_at' => $listedAt->utc()->toDateTimeString(),
            'updated_at' => $listedAt->utc()->toDateTimeString(),
        ]);
        $unitId = (int) DB::table('units')->orderBy('id')->value('id');
        $productId = (int) DB::table('products')->insertGetId([
            'catalog_id' => $catalogId,
            'category_id' => null,
            'brand_id' => null,
            'unit_id' => $unitId,
            'sku' => $code.'-SKU',
            'name' => $code.' Product',
            'is_active' => true,
            'created_at' => $listedAt->utc()->toDateTimeString(),
            'updated_at' => $listedAt->utc()->toDateTimeString(),
        ]);
        DB::table('store_products')->insert([
            'store_id' => $storeId,
            'product_id' => $productId,
            'price' => $cost * 1.5,
            'cost_price' => $cost,
            'is_active' => true,
            'created_at' => $listedAt->utc()->toDateTimeString(),
            'updated_at' => $listedAt->utc()->toDateTimeString(),
        ]);

        return [$storeId, $productId];
    }

    /**
     * @param list<array{0:float|int,1:float|int}> $rows
     * @return list<int>
     */
    private function stock(int $storeId, int $productId, array $rows): array
    {
        $ids = [];
        foreach ($rows as $index => [$quantity, $reserved]) {
            $warehouseId = (int) DB::table('warehouses')->insertGetId([
                'store_id' => $storeId,
                'code' => 'INT-'.$storeId.'-'.$index,
                'name' => 'Inventory '.$index,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $ids[] = (int) DB::table('inventories')->insertGetId([
                'warehouse_id' => $warehouseId,
                'product_id' => $productId,
                'quantity' => $quantity,
                'reserved_quantity' => $reserved,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return $ids;
    }

    private function sale(
        int $storeId,
        int $productId,
        float $quantity,
        CarbonImmutable $at,
        string $status,
        string $number,
    ): void {
        $customerId = (int) DB::table('customers')->insertGetId([
            'type' => 'b2c',
            'name' => $number.' Customer',
            'created_at' => $at->utc()->toDateTimeString(),
            'updated_at' => $at->utc()->toDateTimeString(),
        ]);
        $orderId = (int) DB::table('orders')->insertGetId([
            'store_id' => $storeId,
            'customer_id' => $customerId,
            'order_number' => $number.'-'.$storeId,
            'channel' => 'b2c',
            'status' => $status,
            'currency' => 'KWD',
            'subtotal' => $quantity,
            'discount_total' => 0,
            'delivery_total' => 0,
            'grand_total' => $quantity,
            'created_at' => $at->utc()->toDateTimeString(),
            'updated_at' => $at->utc()->toDateTimeString(),
        ]);
        DB::table('order_items')->insert([
            'order_id' => $orderId,
            'product_id' => $productId,
            'sku_snapshot' => 'SKU-'.$productId,
            'name_snapshot' => 'Product '.$productId,
            'quantity' => $quantity,
            'unit_price' => 1,
            'line_total' => $quantity,
            'created_at' => $at->utc()->toDateTimeString(),
            'updated_at' => $at->utc()->toDateTimeString(),
        ]);
    }

    private function movement(
        int $storeId,
        int $inventoryId,
        float $quantity,
        CarbonImmutable $at,
        string $type,
    ): void {
        DB::table('stock_movements')->insert([
            'inventory_id' => $inventoryId,
            'store_id' => $storeId,
            'user_id' => null,
            'type' => $type,
            'quantity' => $quantity,
            'reference_type' => 'test',
            'reference_id' => null,
            'reason' => 'inventory intelligence fixture',
            'created_at' => $at->utc()->toDateTimeString(),
            'updated_at' => $at->utc()->toDateTimeString(),
        ]);
    }
}
