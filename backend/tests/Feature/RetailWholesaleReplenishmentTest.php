<?php

namespace Tests\Feature;

use App\Models\B2bAccount;
use App\Models\Order;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use App\Services\B2bCustomerService;
use App\Services\RetailWholesaleAccountService;
use App\Services\RetailWholesaleLineageService;
use App\Services\RetailWholesaleReplenishmentService;
use App\Services\WholesalePrincipal;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class RetailWholesaleReplenishmentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_delivered_wholesale_order_materializes_product_and_quantity_into_exact_retail_store_once(): void
    {
        $wholesaleStore = app(WholesalePrincipal::class)->storeId();
        $retailStore = $this->store('B2C', 'RETAIL-REPL');
        $otherRetailStore = $this->store('B2C', 'RETAIL-OTHER');

        $retailCustomer = app(RetailWholesaleAccountService::class)
            ->ensureForStore(Store::query()->findOrFail($retailStore));
        app(RetailWholesaleAccountService::class)
            ->ensureForStore(Store::query()->findOrFail($otherRetailStore));

        $sourceCatalog = (int) DB::table('catalogs')->insertGetId([
            'store_id' => $wholesaleStore,
            'channel' => 'b2b',
            'code' => 'default',
            'name' => 'Wholesale Replenishment Catalog',
            'is_active' => true,
            'is_migration_quarantine' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $sourceUnit = (int) DB::table('units')->insertGetId([
            'store_id' => null,
            'scope' => 'b2b',
            'scope_key' => 'b2b',
            'code' => 'CASE-12',
            'name' => 'Case 12',
            'name_ar' => 'كرتونة 12',
            'name_en' => 'Case 12',
            'decimal_places' => 0,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $sourceBrand = (int) DB::table('brands')->insertGetId([
            'store_id' => null,
            'scope' => 'b2b',
            'scope_key' => 'b2b',
            'name' => 'Wholesale Brand',
            'name_ar' => 'علامة الجملة',
            'name_en' => 'Wholesale Brand',
            'slug' => 'wholesale-brand',
            'image_path' => 'brands/wholesale-brand.png',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $parentCategory = (int) DB::table('categories')->insertGetId([
            'catalog_id' => $sourceCatalog,
            'parent_id' => null,
            'name' => 'Beverages',
            'slug' => 'beverages',
            'image_path' => 'categories/beverages.png',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $sourceCategory = (int) DB::table('categories')->insertGetId([
            'catalog_id' => $sourceCatalog,
            'parent_id' => $parentCategory,
            'name' => 'Juices',
            'slug' => 'juices',
            'image_path' => 'categories/juices.png',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $sourceProduct = (int) DB::table('products')->insertGetId([
            'catalog_id' => $sourceCatalog,
            'category_id' => $sourceCategory,
            'brand_id' => $sourceBrand,
            'unit_id' => $sourceUnit,
            'sku' => 'JUICE-CASE-12',
            'name' => 'Orange Juice Case',
            'description' => '12 premium orange juice bottles.',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('product_images')->insert([
            'product_id' => $sourceProduct,
            'path' => 'products/orange-juice.png',
            'sort_order' => 0,
            'is_primary' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('store_products')->insert([
            'store_id' => $wholesaleStore,
            'product_id' => $sourceProduct,
            'price' => 7.250,
            'cost_price' => null,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $sourceWarehouse = (int) DB::table('warehouses')->insertGetId([
            'store_id' => $wholesaleStore,
            'code' => 'WHOLESALE-REPL-WH',
            'name' => 'Wholesale Replenishment Warehouse',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $sourceInventory = (int) DB::table('inventories')->insertGetId([
            'warehouse_id' => $sourceWarehouse,
            'product_id' => $sourceProduct,
            'quantity' => 20,
            'reserved_quantity' => 5,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $admin = $this->globalAdmin('B2B_ADMIN', 'replenishment-admin@example.test');
        $order = Order::query()->create([
            'store_id' => $wholesaleStore,
            'warehouse_id' => $sourceWarehouse,
            'customer_id' => $retailCustomer->legacy_customer_id,
            'b2b_customer_id' => $retailCustomer->id,
            'b2c_customer_id' => null,
            'order_number' => 'WHOLESALE-REPL-ORDER-1',
            'channel' => 'b2b',
            'status' => 'out_for_delivery',
            'currency' => 'KWD',
            'subtotal' => 36.250,
            'discount_total' => 0,
            'delivery_total' => 0,
            'grand_total' => 36.250,
            'payment_method' => 'cash_on_delivery',
        ]);
        $orderItemId = (int) DB::table('order_items')->insertGetId([
            'order_id' => $order->id,
            'product_id' => $sourceProduct,
            'sku_snapshot' => 'JUICE-CASE-12',
            'name_snapshot' => 'Orange Juice Case',
            'quantity' => 5,
            'unit_price' => 7.250,
            'line_total' => 36.250,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('stock_movements')->insert([
            'inventory_id' => $sourceInventory,
            'store_id' => $wholesaleStore,
            'user_id' => $admin->id,
            'type' => 'reserve',
            'quantity' => 5,
            'reference_type' => 'order',
            'reference_id' => $order->id,
            'reason' => 'test_reservation',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($admin)
            ->post('/admin/b2b/orders/'.$order->id.'/status', [
                'status' => 'delivered',
                'note' => 'Received by Retail store',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => 'delivered']);
        $this->assertSame(15.0, (float) DB::table('inventories')->where('id', $sourceInventory)->value('quantity'));
        $this->assertSame(0.0, (float) DB::table('inventories')->where('id', $sourceInventory)->value('reserved_quantity'));

        $retailProduct = DB::table('products')
            ->join('catalogs', 'catalogs.id', '=', 'products.catalog_id')
            ->where('catalogs.store_id', $retailStore)
            ->where('catalogs.channel', 'b2c')
            ->where('products.sku', 'JUICE-CASE-12')
            ->first(['products.*']);
        $this->assertNotNull($retailProduct);
        $this->assertSame('Orange Juice Case', $retailProduct->name);
        $this->assertSame('12 premium orange juice bottles.', $retailProduct->description);

        $retailUnit = DB::table('units')->where('id', $retailProduct->unit_id)->first();
        $this->assertSame('store', $retailUnit->scope);
        $this->assertSame($retailStore, (int) $retailUnit->store_id);
        $this->assertSame('CASE-12', $retailUnit->code);

        $retailBrand = DB::table('brands')->where('id', $retailProduct->brand_id)->first();
        $this->assertSame('store', $retailBrand->scope);
        $this->assertSame($retailStore, (int) $retailBrand->store_id);
        $this->assertSame('brands/wholesale-brand.png', $retailBrand->image_path);

        $retailCategory = DB::table('categories')->where('id', $retailProduct->category_id)->first();
        $this->assertSame('juices', $retailCategory->slug);
        $this->assertSame('categories/juices.png', $retailCategory->image_path);
        $this->assertNotNull($retailCategory->parent_id);
        $this->assertDatabaseHas('categories', [
            'id' => $retailCategory->parent_id,
            'catalog_id' => $retailProduct->catalog_id,
            'slug' => 'beverages',
        ]);

        $this->assertDatabaseHas('product_images', [
            'product_id' => $retailProduct->id,
            'path' => 'products/orange-juice.png',
            'is_primary' => true,
        ]);
        $this->assertDatabaseHas('store_products', [
            'store_id' => $retailStore,
            'product_id' => $retailProduct->id,
            'price' => 7.250,
            'cost_price' => 7.250,
            'is_active' => true,
        ]);

        $retailInventory = DB::table('inventories')
            ->join('warehouses', 'warehouses.id', '=', 'inventories.warehouse_id')
            ->where('warehouses.store_id', $retailStore)
            ->where('inventories.product_id', $retailProduct->id)
            ->first(['inventories.id', 'inventories.quantity']);
        $this->assertNotNull($retailInventory);
        $this->assertSame(5.0, (float) $retailInventory->quantity);

        $replenishment = DB::table('retail_replenishments')->where('source_order_id', $order->id)->first();
        $this->assertNotNull($replenishment);
        $this->assertSame($retailStore, (int) $replenishment->retail_store_id);
        $this->assertDatabaseHas('retail_replenishment_items', [
            'replenishment_id' => $replenishment->id,
            'source_order_item_id' => $orderItemId,
            'source_product_id' => $sourceProduct,
            'retail_product_id' => $retailProduct->id,
            'quantity' => 5,
            'quantity_conversion_factor' => 1,
            'unit_cost' => 7.250,
            'line_total' => 36.250,
        ]);

        $lineage = app(RetailWholesaleLineageService::class);
        $mappings = $lineage->mappingsForStore($retailStore);
        $this->assertCount(1, $mappings);
        $this->assertSame($sourceProduct, $mappings[0]['source_wholesale_product_id']);
        $this->assertSame('JUICE-CASE-12', $mappings[0]['source_sku']);
        $this->assertSame((int) $retailProduct->id, $mappings[0]['retail_product_id']);
        $this->assertSame(1.0, $mappings[0]['quantity_conversion_factor']);
        $this->assertSame(7.25, $mappings[0]['current_unit_cost']);
        $this->assertSame(5.0, $mappings[0]['received_quantity_total']);
        $this->assertSame('valid', $mappings[0]['status']);
        $this->assertSame([], $mappings[0]['issues']);
        $this->assertNotNull($mappings[0]['last_received_at']);

        $received = $lineage->receivedItemsForStore(
            $retailStore,
            (int) $retailProduct->id,
        );
        $this->assertCount(1, $received);
        $this->assertSame($order->id, $received[0]['source_order_id']);
        $this->assertSame('WHOLESALE-REPL-ORDER-1', $received[0]['source_order_number']);
        $this->assertSame($sourceProduct, $received[0]['source_product_id']);
        $this->assertSame((int) $retailProduct->id, $received[0]['retail_product_id']);
        $this->assertSame(5.0, $received[0]['source_quantity']);
        $this->assertSame(5.0, $received[0]['received_quantity']);
        $this->assertSame(1.0, $received[0]['quantity_conversion_factor']);
        $this->assertSame(7.25, $received[0]['source_unit_price']);
        $this->assertSame(7.25, $received[0]['retail_unit_cost']);
        $this->assertSame(36.25, $received[0]['line_total']);

        DB::table('retail_wholesale_product_mappings')
            ->where('retail_store_id', $retailStore)
            ->where('source_wholesale_product_id', $sourceProduct)
            ->update([
                'quantity_conversion_factor' => 2,
                'updated_at' => now(),
            ]);

        $historicalReceipt = $lineage->receivedItemsForStore(
            $retailStore,
            (int) $retailProduct->id,
        );
        $this->assertSame(1.0, $historicalReceipt[0]['quantity_conversion_factor']);

        $this->assertDatabaseHas('audit_logs', [
            'event' => 'retail.wholesale_order_received',
            'auditable_id' => $retailStore,
        ]);
        $this->assertDatabaseHas('stock_movements', [
            'inventory_id' => $retailInventory->id,
            'store_id' => $retailStore,
            'type' => 'purchase_receipt',
            'reference_type' => 'retail_replenishment',
            'reference_id' => $replenishment->id,
        ]);
        $this->assertDatabaseMissing('products', [
            'catalog_id' => DB::table('catalogs')->where('store_id', $otherRetailStore)->value('id'),
            'sku' => 'JUICE-CASE-12',
        ]);

        $this->actingAs($admin)
            ->post('/admin/b2b/orders/'.$order->id.'/status', ['status' => 'delivered'])
            ->assertRedirect();

        $this->assertSame(1, DB::table('retail_replenishments')->where('source_order_id', $order->id)->count());
        $this->assertSame(5.0, (float) DB::table('inventories')->where('id', $retailInventory->id)->value('quantity'));
    }

    public function test_corrupt_or_cross_tenant_mapping_is_rejected_without_partial_receipt(): void
    {
        $wholesaleStore = app(WholesalePrincipal::class)->storeId();
        $retailStore = $this->store('B2C', 'RETAIL-LINEAGE-A');
        $foreignRetailStore = $this->store('B2C', 'RETAIL-LINEAGE-B');
        $retailCustomer = app(RetailWholesaleAccountService::class)
            ->ensureForStore(Store::query()->findOrFail($retailStore));
        $admin = $this->globalAdmin('B2B_ADMIN', 'lineage-guard@example.test');

        $unitId = (int) DB::table('units')->insertGetId([
            'store_id' => null,
            'scope' => 'global',
            'scope_key' => 'global',
            'code' => 'LINEAGE-EACH',
            'name' => 'Lineage Each',
            'name_ar' => 'وحدة تتبع',
            'name_en' => 'Lineage Each',
            'decimal_places' => 0,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $sourceCatalog = (int) DB::table('catalogs')->insertGetId([
            'store_id' => $wholesaleStore,
            'channel' => 'b2b',
            'code' => 'lineage-guard-source',
            'name' => 'Lineage Guard Source',
            'is_active' => true,
            'is_migration_quarantine' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $sourceProduct = (int) DB::table('products')->insertGetId([
            'catalog_id' => $sourceCatalog,
            'category_id' => null,
            'brand_id' => null,
            'unit_id' => $unitId,
            'sku' => 'LINEAGE-SOURCE',
            'name' => 'Lineage Source',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $retailCatalog = (int) DB::table('catalogs')->insertGetId([
            'store_id' => $retailStore,
            'channel' => 'b2c',
            'code' => 'lineage-guard-retail',
            'name' => 'Lineage Guard Retail',
            'is_active' => true,
            'is_migration_quarantine' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $retailProduct = (int) DB::table('products')->insertGetId([
            'catalog_id' => $retailCatalog,
            'category_id' => null,
            'brand_id' => null,
            'unit_id' => $unitId,
            'sku' => 'LINEAGE-RETAIL',
            'name' => 'Lineage Retail',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $foreignCatalog = (int) DB::table('catalogs')->insertGetId([
            'store_id' => $foreignRetailStore,
            'channel' => 'b2c',
            'code' => 'lineage-guard-foreign',
            'name' => 'Lineage Guard Foreign',
            'is_active' => true,
            'is_migration_quarantine' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $foreignRetailProduct = (int) DB::table('products')->insertGetId([
            'catalog_id' => $foreignCatalog,
            'category_id' => null,
            'brand_id' => null,
            'unit_id' => $unitId,
            'sku' => 'LINEAGE-FOREIGN',
            'name' => 'Lineage Foreign',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('retail_wholesale_product_mappings')->insert([
            'retail_store_id' => $retailStore,
            'source_wholesale_product_id' => $sourceProduct,
            'retail_product_id' => $retailProduct,
            'quantity_conversion_factor' => 0,
            'mapped_by_user_id' => $admin->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $makeOrder = function (string $number) use (
            $wholesaleStore,
            $retailCustomer,
            $sourceProduct,
        ): Order {
            $order = Order::query()->create([
                'store_id' => $wholesaleStore,
                'customer_id' => $retailCustomer->legacy_customer_id,
                'b2b_customer_id' => $retailCustomer->id,
                'order_number' => $number,
                'channel' => 'b2b',
                'status' => 'delivered',
                'currency' => 'KWD',
                'subtotal' => 4,
                'discount_total' => 0,
                'delivery_total' => 0,
                'grand_total' => 4,
            ]);

            DB::table('order_items')->insert([
                'order_id' => $order->id,
                'product_id' => $sourceProduct,
                'sku_snapshot' => 'LINEAGE-SOURCE',
                'name_snapshot' => 'Lineage Source',
                'quantity' => 2,
                'quantity_conversion_factor' => 1,
                'unit_price' => 2,
                'line_total' => 4,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return $order;
        };

        $service = app(RetailWholesaleReplenishmentService::class);
        $invalidFactorOrder = $makeOrder('LINEAGE-INVALID-FACTOR');

        try {
            $service->receive($invalidFactorOrder, $admin);
            $this->fail('Invalid mapping conversion factor must reject receipt.');
        } catch (HttpException $exception) {
            $this->assertSame(409, $exception->getStatusCode());
        }

        $this->assertDatabaseMissing('retail_replenishments', [
            'source_order_id' => $invalidFactorOrder->id,
        ]);

        DB::table('retail_wholesale_product_mappings')
            ->where('retail_store_id', $retailStore)
            ->where('source_wholesale_product_id', $sourceProduct)
            ->update([
                'retail_product_id' => $foreignRetailProduct,
                'quantity_conversion_factor' => 1,
                'updated_at' => now(),
            ]);

        $foreignTargetOrder = $makeOrder('LINEAGE-FOREIGN-TARGET');

        try {
            $service->receive($foreignTargetOrder, $admin);
            $this->fail('Cross-tenant retail mapping must reject receipt.');
        } catch (HttpException $exception) {
            $this->assertSame(409, $exception->getStatusCode());
        }

        $this->assertDatabaseMissing('retail_replenishments', [
            'source_order_id' => $foreignTargetOrder->id,
        ]);
        $this->assertDatabaseMissing('stock_movements', [
            'store_id' => $retailStore,
            'reference_type' => 'retail_replenishment',
        ]);
    }

    public function test_normal_wholesale_customer_delivery_does_not_create_retail_replenishment(): void
    {
        $wholesaleStore = app(WholesalePrincipal::class)->storeId();
        $sourceWarehouse = (int) DB::table('warehouses')->insertGetId([
            'store_id' => $wholesaleStore,
            'code' => 'WHOLESALE-NORMAL-WH',
            'name' => 'Wholesale Normal Warehouse',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $customer = app(B2bCustomerService::class)->create(['name' => 'Independent Wholesale Buyer']);
        B2bAccount::query()->create([
            'customer_id' => $customer->legacy_customer_id,
            'b2b_customer_id' => $customer->id,
            'company_name' => 'Independent Wholesale Buyer',
            'status' => 'active',
        ]);

        $admin = $this->globalAdmin('B2B_ADMIN', 'normal-wholesale@example.test');
        $order = Order::query()->create([
            'store_id' => $wholesaleStore,
            'warehouse_id' => $sourceWarehouse,
            'customer_id' => $customer->legacy_customer_id,
            'b2b_customer_id' => $customer->id,
            'order_number' => 'WHOLESALE-NORMAL-1',
            'channel' => 'b2b',
            'status' => 'out_for_delivery',
            'currency' => 'KWD',
            'subtotal' => 0,
            'discount_total' => 0,
            'delivery_total' => 0,
            'grand_total' => 0,
        ]);

        $this->actingAs($admin)
            ->post('/admin/b2b/orders/'.$order->id.'/status', ['status' => 'delivered'])
            ->assertRedirect();

        $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => 'delivered']);
        $this->assertDatabaseCount('retail_replenishments', 0);
    }

    private function store(string $type, string $code): int
    {
        return (int) DB::table('stores')->insertGetId([
            'store_type_id' => DB::table('store_types')->where('code', $type)->value('id'),
            'code' => $code,
            'name' => $code,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function globalAdmin(string $roleCode, string $email): User
    {
        $user = User::query()->create([
            'name' => $roleCode,
            'email' => $email,
            'password' => 'password',
            'locale' => 'en',
            'is_active' => true,
        ]);
        $user->roles()->attach(Role::query()->where('code', $roleCode)->firstOrFail());

        return $user;
    }
}
