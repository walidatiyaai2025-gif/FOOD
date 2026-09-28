<?php

namespace Tests\Feature;

use App\Models\B2bAccount;
use App\Models\Order;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use App\Services\B2bCustomerService;
use App\Services\CatalogOwnership;
use App\Services\RetailWholesaleAccountService;
use App\Services\WholesalePrincipal;
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

    public function test_delivered_wholesale_order_uses_explicit_mapping_conversion_and_never_duplicates_retail_product(): void
    {
        $wholesaleStore = app(WholesalePrincipal::class)->storeId();
        $retailStore = $this->store('B2C', 'RETAIL-REPL');

        $retailCustomer = app(RetailWholesaleAccountService::class)
            ->ensureForStore(Store::query()->findOrFail($retailStore));

        $unitId = (int) DB::table('units')->where('is_active', true)->value('id');
        $sourceCatalog = app(CatalogOwnership::class)->defaultCatalogForStore($wholesaleStore, 'b2b');
        $retailCatalog = app(CatalogOwnership::class)->defaultCatalogForStore($retailStore, 'b2c');

        $sourceProduct = (int) DB::table('products')->insertGetId([
            'catalog_id' => $sourceCatalog->id,
            'unit_id' => $unitId,
            'sku' => 'JUICE-CASE-12',
            'name' => 'Orange Juice Case',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $retailProduct = (int) DB::table('products')->insertGetId([
            'catalog_id' => $retailCatalog->id,
            'unit_id' => $unitId,
            'sku' => 'JUICE-BOTTLE',
            'name' => 'Orange Juice Bottle',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('store_products')->insert([
            ['store_id' => $wholesaleStore, 'product_id' => $sourceProduct, 'price' => 120, 'cost_price' => 100, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['store_id' => $retailStore, 'product_id' => $retailProduct, 'price' => 15, 'cost_price' => 10, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
        ]);

        $admin = $this->globalAdmin('B2B_ADMIN', 'replenishment-admin@example.test');
        DB::table('retail_wholesale_product_mappings')->insert([
            'retail_store_id' => $retailStore,
            'wholesale_product_id' => $sourceProduct,
            'retail_product_id' => $retailProduct,
            'quantity_conversion_factor' => 12,
            'updated_by_user_id' => $admin->id,
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
        DB::table('inventories')->insert([
            'warehouse_id' => $sourceWarehouse,
            'product_id' => $sourceProduct,
            'quantity' => 20,
            'reserved_quantity' => 5,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $order = Order::query()->create([
            'store_id' => $wholesaleStore,
            'warehouse_id' => $sourceWarehouse,
            'customer_id' => $retailCustomer->legacy_customer_id,
            'b2b_customer_id' => $retailCustomer->id,
            'order_number' => 'WHOLESALE-REPL-ORDER-1',
            'channel' => 'b2b',
            'status' => 'out_for_delivery',
            'currency' => 'EGP',
            'subtotal' => 600,
            'discount_total' => 0,
            'delivery_total' => 0,
            'grand_total' => 600,
            'payment_method' => 'cash_on_delivery',
        ]);
        $orderItemId = (int) DB::table('order_items')->insertGetId([
            'order_id' => $order->id,
            'product_id' => $sourceProduct,
            'sku_snapshot' => 'JUICE-CASE-12',
            'name_snapshot' => 'Orange Juice Case',
            'quantity' => 5,
            'unit_price' => 120,
            'line_total' => 600,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $productCountBefore = DB::table('products')->count();

        $this->actingAs($admin)
            ->post('/admin/b2b/orders/'.$order->id.'/status', ['status' => 'delivered'])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame($productCountBefore, DB::table('products')->count());

        $retailInventory = DB::table('inventories')
            ->join('warehouses', 'warehouses.id', '=', 'inventories.warehouse_id')
            ->where('warehouses.store_id', $retailStore)
            ->where('inventories.product_id', $retailProduct)
            ->first(['inventories.id', 'inventories.quantity']);

        $this->assertNotNull($retailInventory);
        $this->assertSame(60.0, (float) $retailInventory->quantity);

        $replenishment = DB::table('retail_replenishments')->where('source_order_id', $order->id)->first();
        $this->assertNotNull($replenishment);
        $this->assertDatabaseHas('retail_replenishment_items', [
            'replenishment_id' => $replenishment->id,
            'source_order_item_id' => $orderItemId,
            'source_product_id' => $sourceProduct,
            'retail_product_id' => $retailProduct,
            'source_quantity' => 5,
            'quantity_conversion_factor' => 12,
            'quantity' => 60,
        ]);
        $this->assertDatabaseHas('stock_movements', [
            'inventory_id' => $retailInventory->id,
            'store_id' => $retailStore,
            'type' => 'purchase_receipt',
            'reference_type' => 'retail_replenishment',
        ]);

        $this->actingAs($admin)
            ->post('/admin/b2b/orders/'.$order->id.'/status', ['status' => 'delivered'])
            ->assertRedirect();

        $this->assertSame(60.0, (float) DB::table('inventories')->where('id', $retailInventory->id)->value('quantity'));
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
