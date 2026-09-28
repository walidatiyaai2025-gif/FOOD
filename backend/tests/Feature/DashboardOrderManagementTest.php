<?php

namespace Tests\Feature;

use App\Models\B2bAccount;
use App\Models\Role;
use App\Models\User;
use App\Services\B2bCustomerService;
use App\Services\B2cCustomerService;
use App\Services\WholesalePrincipal;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DashboardOrderManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_b2c_store_admin_can_create_edit_and_cancel_manual_order_with_correct_reservations(): void
    {
        $store = $this->store('B2C', 'ORDER-RETAIL');
        [$product, $inventory] = $this->product($store, 'b2c', 'RETAIL-ORDER-SKU', 4.500, 10);
        $admin = $this->storeAdmin($store, 'retail-orders@example.test');
        $customer = app(B2cCustomerService::class)->create($store, [
            'name' => 'Retail Buyer',
            'email' => 'retail-buyer@example.test',
        ]);

        $this->actingAs($admin)
            ->get('/admin/b2c/orders?store_id='.$store)
            ->assertOk()
            ->assertSee('Create new order');

        $this->actingAs($admin)->post('/admin/b2c/orders', [
            'store_id' => $store,
            'customer_id' => $customer->id,
            'payment_method' => 'cash_on_delivery',
            'discount_total' => 1,
            'delivery_total' => 0.5,
            'customer_note' => 'Phone order',
            'items' => [
                ['product_id' => $product, 'quantity' => 2],
            ],
        ])->assertRedirect();

        $order = DB::table('orders')->where('store_id', $store)->where('channel', 'b2c')->first();
        $this->assertNotNull($order);
        $this->assertSame((int) $customer->id, (int) $order->b2c_customer_id);
        $this->assertSame(9.0, (float) $order->subtotal);
        $this->assertSame(1.0, (float) $order->discount_total);
        $this->assertSame(0.5, (float) $order->delivery_total);
        $this->assertSame(8.5, (float) $order->grand_total);
        $this->assertSame(2.0, (float) DB::table('inventories')->where('id', $inventory)->value('reserved_quantity'));
        $this->assertDatabaseHas('payments', ['order_id' => $order->id, 'provider' => 'cash_on_delivery', 'status' => 'pending']);
        $this->assertDatabaseHas('audit_logs', ['event' => 'dashboard.order_created', 'store_id' => $store]);

        $this->actingAs($admin)->patch('/admin/b2c/orders/'.$order->id, [
            'store_id' => $store,
            'customer_id' => $customer->id,
            'payment_method' => 'cash_on_delivery',
            'discount_total' => 0,
            'delivery_total' => 0,
            'customer_note' => 'Quantity changed',
            'items' => [
                ['product_id' => $product, 'quantity' => 3],
            ],
        ])->assertRedirect();

        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'customer_note' => 'Quantity changed',
        ]);
        $this->assertSame(13.5, (float) DB::table('orders')->where('id', $order->id)->value('grand_total'));
        $this->assertSame(3.0, (float) DB::table('inventories')->where('id', $inventory)->value('reserved_quantity'));
        $this->assertDatabaseHas('audit_logs', ['event' => 'dashboard.order_updated', 'store_id' => $store]);

        $this->actingAs($admin)->post('/admin/b2c/orders/'.$order->id.'/status', [
            'store_id' => $store,
            'status' => 'cancelled',
            'note' => 'Customer cancelled',
        ])->assertRedirect();

        $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => 'cancelled']);
        $this->assertSame(0.0, (float) DB::table('inventories')->where('id', $inventory)->value('reserved_quantity'));
    }

    public function test_b2c_manual_order_rejects_foreign_store_product_and_customer_ids(): void
    {
        $storeA = $this->store('B2C', 'ORDER-A');
        $storeB = $this->store('B2C', 'ORDER-B');
        [$productA] = $this->product($storeA, 'b2c', 'ORDER-A-P', 2, 10);
        [$productB] = $this->product($storeB, 'b2c', 'ORDER-B-P', 3, 10);
        $admin = $this->storeAdmin($storeA, 'store-a-orders@example.test');
        $customerA = app(B2cCustomerService::class)->create($storeA, ['name' => 'Store A Buyer']);
        $customerB = app(B2cCustomerService::class)->create($storeB, ['name' => 'Store B Buyer']);

        $this->actingAs($admin)->post('/admin/b2c/orders', [
            'store_id' => $storeA,
            'customer_id' => $customerB->id,
            'payment_method' => 'cash_on_delivery',
            'items' => [['product_id' => $productA, 'quantity' => 1]],
        ])->assertNotFound();

        $this->actingAs($admin)->post('/admin/b2c/orders', [
            'store_id' => $storeA,
            'customer_id' => $customerA->id,
            'payment_method' => 'cash_on_delivery',
            'items' => [['product_id' => $productB, 'quantity' => 1]],
        ])->assertNotFound();

        $this->assertDatabaseCount('orders', 0);
    }

    public function test_b2b_dashboard_order_uses_approved_customer_tier_price_and_minimum_quantity(): void
    {
        $store = app(WholesalePrincipal::class)->storeId();
        [$product, $inventory] = $this->product($store, 'b2b', 'WHOLESALE-ORDER-SKU', 10, 20);
        $warehouse = (int) DB::table('inventories')->where('inventories.id', $inventory)
            ->join('warehouses', 'warehouses.id', '=', 'inventories.warehouse_id')
            ->value('warehouses.id');
        $tier = (int) DB::table('b2b_price_tiers')->insertGetId([
            'code' => 'ORDER-GOLD',
            'name' => 'Order Gold',
            'priority' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('b2b_price_rules')->insert([
            'price_tier_id' => $tier,
            'store_id' => $store,
            'product_id' => $product,
            'unit_price' => 7.250,
            'minimum_quantity' => 5,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $customer = app(B2bCustomerService::class)->create([
            'name' => 'Wholesale Buyer',
            'email' => 'wholesale-buyer@example.test',
        ]);
        B2bAccount::query()->create([
            'customer_id' => $customer->legacy_customer_id,
            'b2b_customer_id' => $customer->id,
            'price_tier_id' => $tier,
            'company_name' => 'Wholesale Buyer Co',
            'status' => 'active',
        ]);

        $admin = $this->globalAdmin('B2B_ADMIN', 'wholesale-orders@example.test');

        $this->actingAs($admin)
            ->get('/admin/b2b/orders')
            ->assertOk()
            ->assertSee('Create new order');

        $this->actingAs($admin)->post('/admin/b2b/orders', [
            'warehouse_id' => $warehouse,
            'customer_id' => $customer->id,
            'payment_method' => 'cash_on_delivery',
            'items' => [['product_id' => $product, 'quantity' => 5]],
        ])->assertRedirect();

        $order = DB::table('orders')->where('store_id', $store)->where('channel', 'b2b')->first();
        $this->assertNotNull($order);
        $this->assertSame((int) $customer->id, (int) $order->b2b_customer_id);
        $this->assertSame($warehouse, (int) $order->warehouse_id);
        $this->assertSame(36.25, (float) $order->subtotal);
        $this->assertSame(36.25, (float) $order->grand_total);
        $this->assertDatabaseHas('order_items', [
            'order_id' => $order->id,
            'product_id' => $product,
            'quantity' => 5,
            'unit_price' => 7.250,
            'line_total' => 36.250,
        ]);
        $this->assertSame(5.0, (float) DB::table('inventories')->where('id', $inventory)->value('reserved_quantity'));

        $this->actingAs($admin)->post('/admin/b2b/orders', [
            'warehouse_id' => $warehouse,
            'customer_id' => $customer->id,
            'payment_method' => 'cash_on_delivery',
            'items' => [['product_id' => $product, 'quantity' => 1]],
        ])->assertSessionHasErrors('items');

        $this->assertSame(1, DB::table('orders')->where('store_id', $store)->where('channel', 'b2b')->count());
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

    /** @return array{0:int,1:int} */
    private function product(int $storeId, string $channel, string $sku, float $price, float $stock): array
    {
        $catalog = (int) DB::table('catalogs')->insertGetId([
            'store_id' => $storeId,
            'channel' => $channel,
            'code' => 'default',
            'name' => $sku.' Catalog',
            'is_active' => true,
            'is_migration_quarantine' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $unit = (int) DB::table('units')->insertGetId([
            'scope' => 'global',
            'scope_key' => 'global',
            'code' => 'EA-'.$sku,
            'name' => 'Each',
            'name_ar' => 'قطعة',
            'name_en' => 'Each',
            'decimal_places' => 0,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $product = (int) DB::table('products')->insertGetId([
            'catalog_id' => $catalog,
            'unit_id' => $unit,
            'sku' => $sku,
            'name' => $sku,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('store_products')->insert([
            'store_id' => $storeId,
            'product_id' => $product,
            'price' => $price,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $warehouse = (int) DB::table('warehouses')->insertGetId([
            'store_id' => $storeId,
            'code' => 'WH-'.$sku,
            'name' => 'Warehouse '.$sku,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $inventory = (int) DB::table('inventories')->insertGetId([
            'warehouse_id' => $warehouse,
            'product_id' => $product,
            'quantity' => $stock,
            'reserved_quantity' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [$product, $inventory];
    }

    private function storeAdmin(int $storeId, string $email): User
    {
        $user = User::query()->create([
            'name' => 'Store Admin',
            'email' => $email,
            'password' => 'password',
            'locale' => 'en',
            'is_active' => true,
        ]);
        $role = Role::query()->where('code', 'B2C_STORE_ADMIN')->firstOrFail();
        DB::table('user_store_roles')->insert([
            'user_id' => $user->id,
            'store_id' => $storeId,
            'role_id' => $role->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $user;
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
