<?php

namespace Tests\Deployment;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use SplFileInfo;
use Tests\TestCase;

class MultiTenantUpgradeTest extends TestCase
{
    private const FIRST_MULTI_TENANT_MIGRATION = '2026_09_27_001700_create_store_owned_catalogs.php';

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame('true', getenv('FOODEX_DISPOSABLE_SERVICES'));
        $this->assertSame('mysql', DB::connection()->getDriverName());
        $this->assertSame('foodex_acceptance', DB::connection()->getDatabaseName());

        $this->migrateLegacySchemaOnly();
    }

    protected function tearDown(): void
    {
        if (getenv('FOODEX_DISPOSABLE_SERVICES') === 'true') {
            Artisan::call('migrate:fresh', ['--force' => true]);
        }

        parent::tearDown();
    }

    public function test_pre_multi_tenant_data_upgrades_without_reset_and_reconciles_ownership(): void
    {
        $b2cType = $this->storeType('B2C', 'Retail');
        $b2bType = $this->storeType('B2B', 'Wholesale');

        $storeA = $this->store($b2cType, 'LEGACY-A');
        $storeB = $this->store($b2cType, 'LEGACY-B');
        $wholesale = $this->store($b2bType, 'LEGACY-B2B');

        $unit = (int) DB::table('units')->insertGetId([
            'code' => 'LEGACY-EA',
            'name' => 'Legacy Each',
            'decimal_places' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $category = (int) DB::table('categories')->insertGetId([
            'name' => 'Legacy Shared Category',
            'slug' => 'legacy-shared-category',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $sharedProduct = (int) DB::table('products')->insertGetId([
            'category_id' => $category,
            'unit_id' => $unit,
            'sku' => 'LEGACY-SHARED-SKU',
            'name' => 'Legacy Shared Product',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach ([$storeA, $storeB] as $storeId) {
            DB::table('store_products')->insert([
                'store_id' => $storeId,
                'product_id' => $sharedProduct,
                'price' => 5,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $warehouseA = $this->warehouse($storeA, 'LEGACY-WH-A');
        $warehouseB = $this->warehouse($storeB, 'LEGACY-WH-B');

        DB::table('inventories')->insert([
            [
                'warehouse_id' => $warehouseA,
                'product_id' => $sharedProduct,
                'quantity' => 11,
                'reserved_quantity' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'warehouse_id' => $warehouseB,
                'product_id' => $sharedProduct,
                'quantity' => 22,
                'reserved_quantity' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $retailCustomer = $this->customer('b2c', 'Legacy Retail Buyer', 'legacy-retail@example.test');
        $wholesaleCustomer = $this->customer('b2b', 'Legacy Wholesale Buyer', 'legacy-wholesale@example.test');

        $account = (int) DB::table('b2b_accounts')->insertGetId([
            'customer_id' => $wholesaleCustomer,
            'company_name' => 'Legacy Wholesale Company',
            'tax_number' => 'LEGACY-TAX',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $retailOrder = $this->order($storeA, $retailCustomer, 'b2c', 'LEGACY-B2C-ORDER');
        $wholesaleOrder = $this->order($wholesale, $wholesaleCustomer, 'b2b', 'LEGACY-B2B-ORDER');

        $this->assertFalse(Schema::hasTable('catalogs'));
        $this->assertFalse(Schema::hasTable('b2b_customers'));
        $this->assertFalse(Schema::hasColumn('units', 'scope'));

        $this->assertSame(0, Artisan::call('migrate', ['--force' => true]), Artisan::output());

        $this->assertTrue(Schema::hasTable('catalogs'));
        $this->assertTrue(Schema::hasTable('b2b_customers'));
        $this->assertTrue(Schema::hasTable('b2c_customers'));
        $this->assertTrue(Schema::hasColumn('units', 'scope'));

        // The upgrade is non-destructive: legacy identities and business rows remain.
        $this->assertDatabaseHas('stores', ['id' => $storeA, 'code' => 'LEGACY-A']);
        $this->assertDatabaseHas('stores', ['id' => $storeB, 'code' => 'LEGACY-B']);
        $this->assertDatabaseHas('customers', ['id' => $retailCustomer, 'name' => 'Legacy Retail Buyer']);
        $this->assertDatabaseHas('customers', ['id' => $wholesaleCustomer, 'name' => 'Legacy Wholesale Buyer']);
        $this->assertDatabaseHas('orders', ['id' => $retailOrder, 'order_number' => 'LEGACY-B2C-ORDER']);
        $this->assertDatabaseHas('orders', ['id' => $wholesaleOrder, 'order_number' => 'LEGACY-B2B-ORDER']);

        // A formerly shared product becomes independently owned by each retail catalog.
        $productA = (int) DB::table('store_products')->where('store_id', $storeA)->where('price', 5)->value('product_id');
        $productB = (int) DB::table('store_products')->where('store_id', $storeB)->where('price', 5)->value('product_id');
        $this->assertGreaterThan(0, $productA);
        $this->assertGreaterThan(0, $productB);
        $this->assertNotSame($productA, $productB);

        $this->assertSame($storeA, $this->productOwnerStore($productA));
        $this->assertSame($storeB, $this->productOwnerStore($productB));
        $this->assertSame($productA, (int) DB::table('inventories')->where('warehouse_id', $warehouseA)->value('product_id'));
        $this->assertSame($productB, (int) DB::table('inventories')->where('warehouse_id', $warehouseB)->value('product_id'));

        $catalogA = (int) DB::table('products')->where('id', $productA)->value('catalog_id');
        $catalogB = (int) DB::table('products')->where('id', $productB)->value('catalog_id');
        $this->assertDatabaseHas('categories', ['catalog_id' => $catalogA, 'slug' => 'legacy-shared-category']);
        $this->assertDatabaseHas('categories', ['catalog_id' => $catalogB, 'slug' => 'legacy-shared-category']);

        // Legacy customer identities are reconciled into independent B2B/B2C domains.
        $b2cCustomer = (int) DB::table('b2c_customers')
            ->where('legacy_customer_id', $retailCustomer)
            ->where('store_id', $storeA)
            ->value('id');
        $b2bCustomer = (int) DB::table('b2b_customers')
            ->where('legacy_customer_id', $wholesaleCustomer)
            ->value('id');

        $this->assertGreaterThan(0, $b2cCustomer);
        $this->assertGreaterThan(0, $b2bCustomer);
        $this->assertDatabaseHas('orders', [
            'id' => $retailOrder,
            'b2c_customer_id' => $b2cCustomer,
            'b2b_customer_id' => null,
        ]);
        $this->assertDatabaseHas('orders', [
            'id' => $wholesaleOrder,
            'b2b_customer_id' => $b2bCustomer,
            'b2c_customer_id' => null,
        ]);
        $this->assertDatabaseHas('b2b_accounts', [
            'id' => $account,
            'b2b_customer_id' => $b2bCustomer,
        ]);
        $this->assertDatabaseCount('customer_domain_migration_issues', 0);

        // Existing master data remains valid and is promoted to explicit global scope.
        $this->assertDatabaseHas('units', [
            'id' => $unit,
            'scope' => 'global',
            'scope_key' => 'global',
            'name_ar' => 'Legacy Each',
            'name_en' => 'Legacy Each',
            'is_active' => true,
        ]);
    }

    private function migrateLegacySchemaOnly(): void
    {
        $paths = collect(File::files(database_path('migrations')))
            ->filter(fn (SplFileInfo $file): bool => strcmp($file->getFilename(), self::FIRST_MULTI_TENANT_MIGRATION) < 0)
            ->sortBy(fn (SplFileInfo $file): string => $file->getFilename())
            ->map(fn (SplFileInfo $file): string => 'database/migrations/'.$file->getFilename())
            ->values()
            ->all();

        $this->assertNotEmpty($paths);
        $this->assertSame(0, Artisan::call('migrate:fresh', [
            '--force' => true,
            '--path' => $paths,
        ]), Artisan::output());
    }

    private function storeType(string $code, string $name): int
    {
        return (int) DB::table('store_types')->insertGetId([
            'code' => $code,
            'name' => $name,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function store(int $typeId, string $code): int
    {
        return (int) DB::table('stores')->insertGetId([
            'store_type_id' => $typeId,
            'code' => $code,
            'name' => $code,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function warehouse(int $storeId, string $code): int
    {
        return (int) DB::table('warehouses')->insertGetId([
            'store_id' => $storeId,
            'code' => $code,
            'name' => $code,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function customer(string $type, string $name, string $email): int
    {
        return (int) DB::table('customers')->insertGetId([
            'type' => $type,
            'name' => $name,
            'email' => $email,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function order(int $storeId, int $customerId, string $channel, string $number): int
    {
        return (int) DB::table('orders')->insertGetId([
            'store_id' => $storeId,
            'customer_id' => $customerId,
            'order_number' => $number,
            'channel' => $channel,
            'status' => 'pending',
            'currency' => 'KWD',
            'subtotal' => 5,
            'discount_total' => 0,
            'delivery_total' => 0,
            'grand_total' => 5,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function productOwnerStore(int $productId): int
    {
        return (int) DB::table('products')
            ->join('catalogs', 'catalogs.id', '=', 'products.catalog_id')
            ->where('products.id', $productId)
            ->value('catalogs.store_id');
    }
}
