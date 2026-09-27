<?php

namespace Tests\Feature;

use App\Models\Driver;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class B2bWholesaleWorkspaceIsolationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_wholesale_workspace_never_reads_retail_customer_catalog_inventory_or_finance(): void
    {
        $b2bStore = $this->store('B2B', 'WHOLESALE-ONLY');
        $b2cStore = $this->store('B2C', 'RETAIL-HIDDEN');
        $unit = $this->unit();

        [$b2bProduct] = $this->productFixture($b2bStore, 'b2b', $unit, 'WHOLESALE-VISIBLE', 'Wholesale Visible Product');
        [$b2cProduct] = $this->productFixture($b2cStore, 'b2c', $unit, 'RETAIL-HIDDEN', 'Retail Hidden Product');

        [$b2bLegacy, $b2bCustomer] = $this->customer('b2b', 'Wholesale Buyer');
        DB::table('b2b_accounts')->insert([
            'customer_id' => $b2bLegacy,
            'b2b_customer_id' => $b2bCustomer,
            'company_name' => 'Wholesale Visible Company',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        [$b2cLegacy, $b2cCustomer] = $this->customer('b2c', 'Retail Hidden Buyer', $b2cStore);

        DB::table('invoices')->insert([
            [
                'customer_id' => $b2bLegacy,
                'b2b_customer_id' => $b2bCustomer,
                'b2c_customer_id' => null,
                'invoice_number' => 'B2B-INVOICE-VISIBLE',
                'status' => 'issued',
                'currency' => 'KWD',
                'total' => 25,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'customer_id' => $b2cLegacy,
                'b2b_customer_id' => null,
                'b2c_customer_id' => $b2cCustomer,
                'invoice_number' => 'B2C-INVOICE-HIDDEN',
                'status' => 'issued',
                'currency' => 'KWD',
                'total' => 99,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $admin = $this->userWithRole('B2B_ADMIN', 'wholesale-admin@example.test');

        $this->actingAs($admin)->get('/admin/b2b/products')
            ->assertOk()
            ->assertSee('Wholesale Visible Product')
            ->assertDontSee('Retail Hidden Product');

        $this->actingAs($admin)->get('/admin/b2b/inventory')
            ->assertOk()
            ->assertSee('WHOLESALE-VISIBLE')
            ->assertDontSee('RETAIL-HIDDEN');

        $this->actingAs($admin)->get('/admin/b2b/clients')
            ->assertOk()
            ->assertSee('Wholesale Visible Company')
            ->assertSee('Wholesale Buyer')
            ->assertDontSee('Retail Hidden Buyer');

        $this->actingAs($admin)->get('/admin/b2b/finance')
            ->assertOk()
            ->assertSee('B2B-INVOICE-VISIBLE')
            ->assertDontSee('B2C-INVOICE-HIDDEN');

        $this->assertDatabaseHas('products', ['id' => $b2bProduct]);
        $this->assertDatabaseHas('products', ['id' => $b2cProduct]);
    }

    public function test_wholesale_mutations_reject_retail_store_product_and_cross_store_delivery(): void
    {
        $storeA = $this->store('B2B', 'WHOLE-A');
        $storeB = $this->store('B2B', 'WHOLE-B');
        $retailStore = $this->store('B2C', 'RETAIL-X');
        $unit = $this->unit();

        [$productA, $warehouseA] = $this->productFixture($storeA, 'b2b', $unit, 'WHOLE-A-P', 'Wholesale A Product');
        [, $warehouseB] = $this->productFixture($storeB, 'b2b', $unit, 'WHOLE-B-P', 'Wholesale B Product');
        [$retailProduct] = $this->productFixture($retailStore, 'b2c', $unit, 'RETAIL-X-P', 'Retail X Product');

        [$legacy, $b2bCustomer] = $this->customer('b2b', 'Wholesale Delivery Buyer');
        $orderB = (int) DB::table('orders')->insertGetId([
            'store_id' => $storeB,
            'customer_id' => $legacy,
            'b2b_customer_id' => $b2bCustomer,
            'order_number' => 'WHOLE-B-ORDER',
            'channel' => 'b2b',
            'status' => 'pending',
            'currency' => 'KWD',
            'subtotal' => 1,
            'discount_total' => 0,
            'delivery_total' => 0,
            'grand_total' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $driverUser = $this->userWithRole('B2B_DRIVER', 'wholesale-driver@example.test');
        $driver = Driver::query()->create([
            'user_id' => $driverUser->id,
            'store_id' => $storeA,
            'driver_type' => 'b2b',
            'is_available' => true,
            'is_active' => true,
        ]);

        $admin = $this->userWithRole('B2B_ADMIN', 'wholesale-mutations@example.test');

        $this->actingAs($admin)->post('/admin/b2b/products', [
            'store_id' => $retailStore,
            'unit_id' => $unit,
            'sku' => 'ILLEGAL-RETAIL-BIND',
            'name' => 'Illegal',
            'is_active' => 1,
        ])->assertNotFound();

        $this->actingAs($admin)->post('/admin/b2b/inventory', [
            'warehouse_id' => $warehouseA,
            'product_id' => $retailProduct,
            'quantity' => 5,
        ])->assertSessionHasErrors('product_id');

        $tier = (int) DB::table('b2b_price_tiers')->insertGetId([
            'code' => 'WHOLE-TIER',
            'name' => 'Wholesale Tier',
            'priority' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($admin)->post('/admin/b2b/pricing', [
            'price_tier_id' => $tier,
            'store_id' => $storeA,
            'product_id' => $retailProduct,
            'unit_price' => 2,
            'minimum_quantity' => 1,
            'is_active' => 1,
        ])->assertStatus(422);

        $this->actingAs($admin)->post('/admin/b2b/drivers/assign', [
            'driver_id' => $driver->id,
            'order_id' => $orderB,
        ])->assertStatus(422);

        $this->assertDatabaseMissing('driver_assignments', [
            'driver_id' => $driver->id,
            'order_id' => $orderB,
        ]);
        $this->assertDatabaseMissing('inventories', [
            'warehouse_id' => $warehouseA,
            'product_id' => $retailProduct,
        ]);
        $this->assertDatabaseHas('inventories', [
            'warehouse_id' => $warehouseA,
            'product_id' => $productA,
        ]);
        $this->assertNotSame($warehouseA, $warehouseB);
    }

    public function test_retail_store_admin_cannot_enter_wholesale_workspace_or_use_wholesale_routes(): void
    {
        $retailStore = $this->store('B2C', 'RETAIL-ADMIN');
        $user = $this->userWithRole('B2C_STORE_ADMIN', 'retail-admin@example.test');
        DB::table('user_store_roles')->insert([
            'user_id' => $user->id,
            'store_id' => $retailStore,
            'role_id' => (int) Role::query()->where('code', 'B2C_STORE_ADMIN')->value('id'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($user)->get('/admin/b2b/dashboard')->assertForbidden();
        $this->actingAs($user)->post('/admin/b2b/warehouses', [
            'store_id' => $retailStore,
            'code' => 'NOPE',
            'name' => 'Nope',
            'is_active' => 1,
        ])->assertForbidden();
    }

    /** @return array{0:int,1:int} */
    private function customer(string $type, string $name, ?int $storeId = null): array
    {
        $legacy = (int) DB::table('customers')->insertGetId([
            'type' => $type,
            'name' => $name,
            'email' => strtolower(str_replace(' ', '-', $name)).'@example.test',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        if ($type === 'b2b') {
            $domain = (int) DB::table('b2b_customers')->insertGetId([
                'legacy_customer_id' => $legacy,
                'name' => $name,
                'email' => strtolower(str_replace(' ', '-', $name)).'@example.test',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return [$legacy, $domain];
        }

        $domain = (int) DB::table('b2c_customers')->insertGetId([
            'legacy_customer_id' => $legacy,
            'store_id' => $storeId,
            'name' => $name,
            'email' => strtolower(str_replace(' ', '-', $name)).'@example.test',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [$legacy, $domain];
    }

    /** @return array{0:int,1:int,2:int} */
    private function productFixture(int $storeId, string $channel, int $unitId, string $sku, string $name): array
    {
        $catalog = (int) DB::table('catalogs')->insertGetId([
            'store_id' => $storeId,
            'channel' => $channel,
            'code' => 'default',
            'name' => $name.' Catalog',
            'is_active' => true,
            'is_migration_quarantine' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $product = (int) DB::table('products')->insertGetId([
            'catalog_id' => $catalog,
            'unit_id' => $unitId,
            'sku' => $sku,
            'name' => $name,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('store_products')->insert([
            'store_id' => $storeId,
            'product_id' => $product,
            'price' => 1,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $warehouse = (int) DB::table('warehouses')->insertGetId([
            'store_id' => $storeId,
            'code' => 'WH-'.$sku,
            'name' => 'Warehouse '.$name,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $inventory = (int) DB::table('inventories')->insertGetId([
            'warehouse_id' => $warehouse,
            'product_id' => $product,
            'quantity' => 10,
            'reserved_quantity' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [$product, $warehouse, $inventory];
    }

    private function store(string $type, string $code): int
    {
        $typeId = (int) DB::table('store_types')->where('code', $type)->value('id');

        return (int) DB::table('stores')->insertGetId([
            'store_type_id' => $typeId,
            'code' => $code,
            'name' => $code,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function unit(): int
    {
        return (int) DB::table('units')->insertGetId([
            'scope' => 'global',
            'scope_key' => 'global',
            'code' => 'EA-WHOLESALE',
            'name' => 'Each',
            'name_ar' => 'قطعة',
            'name_en' => 'Each',
            'decimal_places' => 0,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function userWithRole(string $roleCode, string $email): User
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
