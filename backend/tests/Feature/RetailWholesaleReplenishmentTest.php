<?php

namespace Tests\Feature;

use App\Models\B2bAccount;
use App\Models\Order;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use App\Services\B2bCustomerService;
use App\Services\RetailWholesaleAccountService;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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
        $wholesaleStore = $this->store('B2B', 'WHOLESALE-REPL');
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
            'unit_cost' => 7.250,
            'line_total' => 36.250,
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

    public function test_normal_wholesale_customer_delivery_does_not_create_retail_replenishment(): void
    {
        $wholesaleStore = $this->store('B2B', 'WHOLESALE-NORMAL');
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
