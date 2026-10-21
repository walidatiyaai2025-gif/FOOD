<?php

namespace Tests\Feature;

use App\Services\ProductAvailabilityService;
use App\Services\WholesalePrincipal;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ProductAvailabilityServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_missing_inventory_row_is_out_of_stock_for_principal_wholesale_warehouse(): void
    {
        $this->seed(CoreReferenceSeeder::class);
        [$storeId, $productId] = $this->wholesaleProduct();

        $availability = app(ProductAvailabilityService::class)
            ->forStoreProduct($storeId, $productId);

        $this->assertSame(0.0, $availability['available_quantity']);
        $this->assertFalse($availability['is_available']);
        $this->assertSame(
            ProductAvailabilityService::OUT_OF_STOCK,
            $availability['availability_state'],
        );
    }

    public function test_wholesale_available_quantity_still_uses_quantity_minus_reserved_quantity(): void
    {
        $this->seed(CoreReferenceSeeder::class);
        [$storeId, $productId, $warehouseId] = $this->wholesaleProduct();

        DB::table('inventories')->insert([
            'warehouse_id' => $warehouseId,
            'product_id' => $productId,
            'quantity' => 10,
            'reserved_quantity' => 2,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $availability = app(ProductAvailabilityService::class)
            ->forStoreProduct($storeId, $productId);

        $this->assertSame(8.0, $availability['available_quantity']);
        $this->assertTrue($availability['is_available']);
        $this->assertSame(
            ProductAvailabilityService::AVAILABLE,
            $availability['availability_state'],
        );
    }

    /** @return array{0:int,1:int,2:int} */
    private function wholesaleProduct(): array
    {
        $typeId = (int) DB::table('store_types')
            ->where('code', 'B2B')
            ->value('id');

        $storeId = (int) DB::table('stores')->insertGetId([
            'store_type_id' => $typeId,
            'code' => WholesalePrincipal::STORE_CODE,
            'name' => 'FOODEX Wholesale',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $catalogId = (int) DB::table('catalogs')->insertGetId([
            'store_id' => $storeId,
            'channel' => 'b2b',
            'code' => 'availability-test',
            'name' => 'Availability Test Catalog',
            'is_active' => true,
            'is_migration_quarantine' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $unitId = (int) DB::table('units')->insertGetId([
            'code' => 'EA-AVAIL',
            'name' => 'Each',
            'decimal_places' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $productId = (int) DB::table('products')->insertGetId([
            'catalog_id' => $catalogId,
            'unit_id' => $unitId,
            'sku' => 'AVAIL-P-1',
            'name' => 'Availability Product',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $warehouseId = (int) DB::table('warehouses')->insertGetId([
            'store_id' => $storeId,
            'code' => WholesalePrincipal::WAREHOUSE_CODE,
            'name' => 'Main Warehouse',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [$storeId, $productId, $warehouseId];
    }
}
