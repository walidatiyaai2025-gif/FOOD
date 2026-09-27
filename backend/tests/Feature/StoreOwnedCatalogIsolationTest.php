<?php

namespace Tests\Feature;

use App\Models\Catalog;
use App\Services\CatalogOwnership;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class StoreOwnedCatalogIsolationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_same_sku_and_slug_are_allowed_in_different_catalogs(): void
    {
        $storeA = $this->store('B2C', 'CAT-A');
        $storeB = $this->store('B2C', 'CAT-B');
        $catalogA = $this->catalog($storeA, 'b2c');
        $catalogB = $this->catalog($storeB, 'b2c');
        $unit = $this->unit();

        $categoryA = DB::table('categories')->insertGetId([
            'catalog_id' => $catalogA,
            'name' => 'Fresh',
            'slug' => 'fresh',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $categoryB = DB::table('categories')->insertGetId([
            'catalog_id' => $catalogB,
            'name' => 'Fresh',
            'slug' => 'fresh',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('products')->insert([
            [
                'catalog_id' => $catalogA,
                'category_id' => $categoryA,
                'unit_id' => $unit,
                'sku' => 'SAME-SKU',
                'name' => 'A',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'catalog_id' => $catalogB,
                'category_id' => $categoryB,
                'unit_id' => $unit,
                'sku' => 'SAME-SKU',
                'name' => 'B',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $this->assertSame(2, DB::table('products')->where('sku', 'SAME-SKU')->count());
    }

    public function test_product_and_category_ownership_is_store_scoped(): void
    {
        $storeA = $this->store('B2C', 'OWN-A');
        $storeB = $this->store('B2C', 'OWN-B');
        $catalogA = $this->catalog($storeA, 'b2c');
        $catalogB = $this->catalog($storeB, 'b2c');
        $unit = $this->unit();

        $categoryA = DB::table('categories')->insertGetId([
            'catalog_id' => $catalogA,
            'name' => 'A',
            'slug' => 'a',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $productA = DB::table('products')->insertGetId([
            'catalog_id' => $catalogA,
            'category_id' => $categoryA,
            'unit_id' => $unit,
            'sku' => 'OWN-A',
            'name' => 'Owned by A',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $ownership = app(CatalogOwnership::class);
        $this->assertSame($productA, (int) $ownership->productForStore($productA, $storeA)->id);
        $this->assertSame($categoryA, (int) $ownership->categoryForStore($categoryA, $storeA)->id);

        // Keep catalog B referenced so this fixture also proves separate tenant roots exist.
        $this->assertDatabaseHas('catalogs', ['id' => $catalogB, 'store_id' => $storeB]);

        $this->expectException(ModelNotFoundException::class);
        $ownership->productForStore($productA, $storeB);
    }

    public function test_b2b_catalog_is_separate_from_retail_catalogs(): void
    {
        $b2bStore = $this->store('B2B', 'WHOLESALE-CAT');
        $retailStore = $this->store('B2C', 'RETAIL-CAT');
        $b2bCatalog = $this->catalog($b2bStore, 'b2b');
        $retailCatalog = $this->catalog($retailStore, 'b2c');

        $this->assertNotSame($b2bCatalog, $retailCatalog);
        $this->assertDatabaseHas('catalogs', ['id' => $b2bCatalog, 'store_id' => $b2bStore, 'channel' => 'b2b']);
        $this->assertDatabaseHas('catalogs', ['id' => $retailCatalog, 'store_id' => $retailStore, 'channel' => 'b2c']);
    }

    private function store(string $typeCode, string $code): int
    {
        $typeId = (int) DB::table('store_types')->where('code', $typeCode)->value('id');

        return (int) DB::table('stores')->insertGetId([
            'store_type_id' => $typeId,
            'code' => $code,
            'name' => $code,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function catalog(int $storeId, string $channel): int
    {
        return (int) Catalog::query()->create([
            'store_id' => $storeId,
            'channel' => $channel,
            'code' => 'default',
            'name' => 'Default',
            'is_active' => true,
        ])->id;
    }

    private function unit(): int
    {
        return (int) DB::table('units')->insertGetId([
            'code' => 'EA-'.DB::table('units')->count(),
            'name' => 'Each',
            'decimal_places' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
