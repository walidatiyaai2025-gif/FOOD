<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Services\CatalogOwnership;
use App\Services\WholesalePrincipal;
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

    public function test_wholesale_workspace_reads_only_the_main_principal_and_never_retail_catalog_inventory(): void
    {
        $principal = app(WholesalePrincipal::class)->storeId();
        $retailStore = $this->store('B2C', 'RETAIL-HIDDEN');
        $unit = $this->unit();

        [$b2bProduct] = $this->productFixture($principal, 'b2b', $unit, 'WHOLESALE-VISIBLE', 'Wholesale Visible Product');
        [$b2cProduct] = $this->productFixture($retailStore, 'b2c', $unit, 'RETAIL-HIDDEN', 'Retail Hidden Product');

        [$b2bLegacy, $b2bCustomer] = $this->customer('b2b', 'Wholesale Buyer');
        DB::table('b2b_accounts')->insert([
            'customer_id' => $b2bLegacy,
            'b2b_customer_id' => $b2bCustomer,
            'company_name' => 'Wholesale Visible Company',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $admin = $this->userWithRole('B2B_ADMIN', 'wholesale-admin@example.test');

        $this->actingAs($admin)->get('/admin/b2b/products')
            ->assertOk()
            ->assertSee('Wholesale Visible Product')
            ->assertDontSee('Retail Hidden Product')
            ->assertDontSee('Wholesale Stores');

        $this->actingAs($admin)->get('/admin/b2b/inventory')
            ->assertOk()
            ->assertSee('WHOLESALE-VISIBLE')
            ->assertDontSee('RETAIL-HIDDEN');

        $this->actingAs($admin)->get('/admin/b2b/clients')
            ->assertOk()
            ->assertSee('Wholesale Visible Company')
            ->assertSee('Wholesale Buyer');

        $this->assertDatabaseHas('products', ['id' => $b2bProduct]);
        $this->assertDatabaseHas('products', ['id' => $b2cProduct]);
    }

    public function test_b2b_order_reserves_only_selected_principal_warehouse(): void
    {
        $principal = app(WholesalePrincipal::class)->storeId();
        $unit = $this->unit();
        $catalog = app(CatalogOwnership::class)->defaultCatalogForStore($principal, 'b2b');

        $product = (int) DB::table('products')->insertGetId([
            'catalog_id' => $catalog->id,
            'unit_id' => $unit,
            'sku' => 'WAREHOUSE-SOURCE',
            'name' => 'Warehouse Source Product',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('store_products')->insert([
            'store_id' => $principal,
            'product_id' => $product,
            'price' => 20,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $warehouseA = $this->warehouse($principal, 'WH-A', 'Warehouse A');
        $warehouseB = $this->warehouse($principal, 'WH-B', 'Warehouse B');
        $inventoryA = (int) DB::table('inventories')->insertGetId([
            'warehouse_id' => $warehouseA,
            'product_id' => $product,
            'quantity' => 10,
            'reserved_quantity' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $inventoryB = (int) DB::table('inventories')->insertGetId([
            'warehouse_id' => $warehouseB,
            'product_id' => $product,
            'quantity' => 50,
            'reserved_quantity' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $tier = (int) DB::table('b2b_price_tiers')->where('code', 'STANDARD')->value('id');
        [$legacy, $customer] = $this->customer('b2b', 'Warehouse Buyer');
        DB::table('b2b_accounts')->insert([
            'customer_id' => $legacy,
            'b2b_customer_id' => $customer,
            'price_tier_id' => $tier,
            'company_name' => 'Warehouse Buyer',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('b2b_price_rules')->insert([
            'price_tier_id' => $tier,
            'store_id' => $principal,
            'product_id' => $product,
            'unit_price' => 15,
            'minimum_quantity' => 1,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $admin = $this->userWithRole('B2B_ADMIN', 'warehouse-orders@example.test');

        $this->actingAs($admin)->post('/admin/b2b/orders', [
            'warehouse_id' => $warehouseA,
            'customer_id' => $customer,
            'payment_method' => config('checkout.default_payment_method'),
            'discount_total' => 0,
            'delivery_total' => 0,
            'items' => [['product_id' => $product, 'quantity' => 4]],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $order = DB::table('orders')->where('channel', 'b2b')->latest('id')->first();
        $this->assertNotNull($order);
        $this->assertSame($warehouseA, (int) $order->warehouse_id);
        $this->assertSame(4.0, (float) DB::table('inventories')->where('id', $inventoryA)->value('reserved_quantity'));
        $this->assertSame(0.0, (float) DB::table('inventories')->where('id', $inventoryB)->value('reserved_quantity'));
    }

    public function test_forged_store_inputs_cannot_move_wholesale_data_into_retail(): void
    {
        $principal = app(WholesalePrincipal::class)->storeId();
        $retailStore = $this->store('B2C', 'RETAIL-X');
        $unit = $this->unit();
        $admin = $this->userWithRole('B2B_ADMIN', 'forged-store@example.test');

        $this->actingAs($admin)->post('/admin/b2b/products', [
            'store_id' => $retailStore,
            'unit_id' => $unit,
            'sku' => 'FORGED-STORE-PRODUCT',
            'name' => 'Principal Owned Product',
            'is_active' => 1,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $productStore = (int) DB::table('products')
            ->join('catalogs', 'catalogs.id', '=', 'products.catalog_id')
            ->where('products.sku', 'FORGED-STORE-PRODUCT')
            ->value('catalogs.store_id');

        $this->assertSame($principal, $productStore);
        $this->assertNotSame($retailStore, $productStore);
    }

    public function test_retail_store_admin_cannot_enter_or_mutate_wholesale_workspace(): void
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
        $catalog = app(CatalogOwnership::class)->defaultCatalogForStore($storeId, $channel);
        $product = (int) DB::table('products')->insertGetId([
            'catalog_id' => $catalog->id,
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
        $warehouse = $this->warehouse($storeId, 'WH-'.$sku, 'Warehouse '.$name);
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

    private function warehouse(int $storeId, string $code, string $name): int
    {
        return (int) DB::table('warehouses')->insertGetId([
            'store_id' => $storeId,
            'code' => $code,
            'name' => $name,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function unit(): int
    {
        $id = DB::table('units')->where('is_active', true)->value('id');
        if ($id !== null) {
            return (int) $id;
        }

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
