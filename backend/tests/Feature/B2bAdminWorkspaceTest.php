<?php

namespace Tests\Feature;

use App\Models\Driver;
use App\Models\Role;
use App\Models\User;
use App\Services\CatalogOwnership;
use App\Services\WholesalePrincipal;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class B2bAdminWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_b2b_admin_gets_rtl_workspace_without_wholesale_store_module(): void
    {
        $user = $this->user('B2B_ADMIN', 'ar');

        $this->actingAs($user)->get('/admin/b2b/products')
            ->assertOk()
            ->assertSee('dir="rtl"', false)
            ->assertSee('كتالوج الجملة')
            ->assertSee('FOODEX · إدارة الجملة')
            ->assertDontSee('فروع الجملة');

        $this->actingAs($user)->get('/admin/b2b/stores')->assertNotFound();
    }

    public function test_b2b_dashboard_accepts_open_date_ranges_beyond_31_days(): void
    {
        $admin = $this->user('B2B_ADMIN', 'ar');
        $to = now('Asia/Kuwait')->startOfDay();
        $from = $to->copy()->subDays(45);

        $this->actingAs($admin)
            ->get('/admin/b2b/dashboard?from='.$from->toDateString().'&to='.$to->toDateString())
            ->assertOk()
            ->assertSee('المبيعات')
            ->assertDontSee('المبيعات اليومية')
            ->assertSee('name="from"', false)
            ->assertSee('name="to"', false);

        $this->actingAs($admin)
            ->get('/admin/b2b/dashboard?to='.$to->toDateString())
            ->assertOk();
    }

    public function test_core_b2b_screens_use_the_single_principal_and_warehouses(): void
    {
        $store = app(WholesalePrincipal::class)->storeId();
        $catalog = app(CatalogOwnership::class)->defaultCatalogForStore($store, 'b2b');
        $warehouse = $this->warehouse($store, 'WHOLESALE-WH', 'Wholesale Warehouse');
        $unit = $this->unit();
        $product = (int) DB::table('products')->insertGetId([
            'catalog_id' => $catalog->id,
            'unit_id' => $unit,
            'sku' => 'B2B-SKU',
            'name' => 'B2B Product',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('store_products')->insert([
            'store_id' => $store,
            'product_id' => $product,
            'price' => 12.500,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('inventories')->insert([
            'warehouse_id' => $warehouse,
            'product_id' => $product,
            'quantity' => 20,
            'reserved_quantity' => 3,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        [$customer, $b2bCustomer] = $this->b2bCustomer('Buyer One', 'buyer-one@example.test', '50000000');
        DB::table('b2b_accounts')->insert([
            'customer_id' => $customer,
            'b2b_customer_id' => $b2bCustomer,
            'company_name' => 'Buyer Co',
            'tax_number' => 'TAX-1',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $order = (int) DB::table('orders')->insertGetId([
            'store_id' => $store,
            'warehouse_id' => $warehouse,
            'customer_id' => $customer,
            'b2b_customer_id' => $b2bCustomer,
            'order_number' => 'B2B-ORDER-1',
            'channel' => 'b2b',
            'status' => 'processing',
            'currency' => 'EGP',
            'subtotal' => 12.5,
            'discount_total' => 0,
            'delivery_total' => 0,
            'grand_total' => 12.5,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('order_items')->insert([
            'order_id' => $order,
            'product_id' => $product,
            'sku_snapshot' => 'B2B-SKU',
            'name_snapshot' => 'B2B Product',
            'quantity' => 2,
            'unit_price' => 6.25,
            'line_total' => 12.5,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $admin = $this->user('B2B_ADMIN', 'en');

        $this->actingAs($admin)->get('/admin/b2b/dashboard')
            ->assertOk()
            ->assertSee('B2B-ORDER-1')
            ->assertSee('Buyer One')
            ->assertSee('Wholesale Warehouse')
            ->assertSee('B2B Product');

        $this->actingAs($admin)->get('/admin/b2b/inventory')
            ->assertOk()
            ->assertSee('Wholesale Warehouse')
            ->assertDontSee('Wholesale store');

        $this->actingAs($admin)->get('/admin/b2b/clients')
            ->assertOk()
            ->assertSee('Buyer Co')
            ->assertSee('TAX-1');

        $this->actingAs($admin)->get('/admin/b2b/products')
            ->assertOk()
            ->assertSee('B2B Product')
            ->assertSee('17.000')
            ->assertSee('12.500 EGP')
            ->assertDontSee('Wholesale store');
    }

    public function test_b2b_operations_use_principal_warehouse_and_reject_driver_fulfillment(): void
    {
        $store = app(WholesalePrincipal::class)->storeId();
        $catalog = app(CatalogOwnership::class)->defaultCatalogForStore($store, 'b2b');
        $warehouse = $this->warehouse($store, 'OPS-WH', 'Operations Warehouse');
        $unit = $this->unit();
        $product = (int) DB::table('products')->insertGetId([
            'catalog_id' => $catalog->id,
            'unit_id' => $unit,
            'sku' => 'OPS-SKU',
            'name' => 'Operations Product',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('store_products')->insert([
            'store_id' => $store,
            'product_id' => $product,
            'price' => 10,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('inventories')->insert([
            'warehouse_id' => $warehouse,
            'product_id' => $product,
            'quantity' => 100,
            'reserved_quantity' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $tier = (int) DB::table('b2b_price_tiers')->insertGetId([
            'code' => 'OPS-GOLD',
            'name' => 'Operations Gold',
            'priority' => 10,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        [$customer, $b2bCustomer] = $this->b2bCustomer('Operations Buyer', 'ops-buyer@example.test');
        DB::table('b2b_accounts')->insert([
            'customer_id' => $customer,
            'b2b_customer_id' => $b2bCustomer,
            'price_tier_id' => $tier,
            'company_name' => 'Operations Buyer',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('b2b_price_rules')->insert([
            'price_tier_id' => $tier,
            'store_id' => $store,
            'product_id' => $product,
            'unit_price' => 7.250,
            'minimum_quantity' => 1,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $admin = $this->user('B2B_ADMIN', 'en');

        $driverResponse = $this->actingAs($admin)->post('/admin/b2b/drivers', [
            'name' => 'Principal Driver',
            'email' => 'principal-driver@example.test',
            'password' => 'password123',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $driverUserId = (int) User::query()->where('email', 'principal-driver@example.test')->value('id');
        $driver = Driver::query()->where('user_id', $driverUserId)->firstOrFail();
        $this->assertSame($store, (int) $driver->store_id);

        $driverUser = User::query()->findOrFail($driverUserId);
        $driverUser->createToken('driver-before-reset');
        $this->actingAs($admin)->patch("/admin/b2b/drivers/{$driver->id}/password", [
            'password' => 'new-password-123',
            'password_confirmation' => 'new-password-123',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertTrue(Hash::check('new-password-123', $driverUser->fresh()->password));
        $this->assertDatabaseMissing('personal_access_tokens', [
            'tokenable_type' => User::class,
            'tokenable_id' => $driverUserId,
        ]);

        $driverPage = $this->actingAs($admin)->get('/admin/b2b/drivers')->assertOk();
        $driverPage->assertSee('Reset driver password')->assertSee('principal-driver@example.test');

        $orderResponse = $this->actingAs($admin)->post('/admin/b2b/orders', [
            'warehouse_id' => $warehouse,
            'customer_id' => $b2bCustomer,
            'payment_method' => config('checkout.default_payment_method'),
            'discount_total' => 0,
            'delivery_total' => 0,
            'items' => [['product_id' => $product, 'quantity' => 2]],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $order = DB::table('orders')->where('channel', 'b2b')->latest('id')->first();
        $this->assertNotNull($order);
        $this->assertSame($store, (int) $order->store_id);
        $this->assertSame($warehouse, (int) $order->warehouse_id);

        $this->actingAs($admin)->get('/admin/b2b/orders')
            ->assertOk()
            ->assertSee('Operations Warehouse')
            ->assertSee('Select source warehouse')
            ->assertDontSee('Select wholesale store');

        $this->actingAs($admin)->get('/admin/b2b/drivers')
            ->assertOk()
            ->assertSee('Principal Driver')
            ->assertDontSee('Wholesale store');

        $this->actingAs($admin)->post('/admin/b2b/drivers/assign', [
            'driver_id' => $driver->id,
            'order_id' => $order->id,
        ])->assertConflict();
        $this->assertDatabaseMissing('driver_assignments', [
            'driver_id' => $driver->id,
            'order_id' => $order->id,
        ]);

        $this->actingAs($admin)->post('/admin/b2b/orders/'.$order->id.'/status', [
            'status' => 'confirmed',
            'note' => 'Customer Service approved',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->actingAs($admin)->post('/admin/b2b/drivers/assign', [
            'driver_id' => $driver->id,
            'order_id' => $order->id,
        ])->assertConflict();

        $this->assertDatabaseMissing('driver_assignments', [
            'driver_id' => $driver->id,
            'order_id' => $order->id,
        ]);

        $this->actingAs($admin)->post('/admin/b2b/pricing', [
            'price_tier_id' => $tier,
            'product_id' => $product,
            'unit_price' => 7.500,
            'minimum_quantity' => 5,
            'is_active' => true,
        ])->assertRedirect();

        $this->assertDatabaseHas('b2b_price_rules', [
            'price_tier_id' => $tier,
            'store_id' => $store,
            'product_id' => $product,
            'unit_price' => 7.500,
        ]);

        $orderResponse->assertSessionHasNoErrors();
        $driverResponse->assertSessionHasNoErrors();
    }

    public function test_b2b_operation_wrappers_reject_cross_channel_resources(): void
    {
        $b2cType = (int) DB::table('store_types')->where('code', 'B2C')->value('id');
        $store = (int) DB::table('stores')->insertGetId([
            'store_type_id' => $b2cType,
            'code' => 'OPS-B2C',
            'name' => 'Retail Hidden',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $customer = (int) DB::table('customers')->insertGetId([
            'type' => 'b2c',
            'name' => 'Retail Buyer',
            'email' => 'retail-buyer@example.test',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $order = (int) DB::table('orders')->insertGetId([
            'store_id' => $store,
            'customer_id' => $customer,
            'order_number' => 'OPS-B2C-1',
            'channel' => 'b2c',
            'status' => 'pending',
            'currency' => 'EGP',
            'subtotal' => 1,
            'discount_total' => 0,
            'delivery_total' => 0,
            'grand_total' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $admin = $this->user('B2B_ADMIN', 'en');

        $this->actingAs($admin)->post("/admin/b2b/orders/{$order}/status", ['status' => 'confirmed'])->assertNotFound();
    }

    public function test_b2b_reports_and_settings_are_principal_scoped(): void
    {
        $store = app(WholesalePrincipal::class)->storeId();
        [$customer, $b2bCustomer] = $this->b2bCustomer('Wholesale Report Buyer', null);
        DB::table('orders')->insert([
            'store_id' => $store,
            'customer_id' => $customer,
            'b2b_customer_id' => $b2bCustomer,
            'order_number' => 'B2B-RPT-1',
            'channel' => 'b2b',
            'status' => 'delivered',
            'currency' => 'EGP',
            'subtotal' => 18,
            'discount_total' => 0,
            'delivery_total' => 0,
            'grand_total' => 18,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('settings')->insert([
            ['store_id' => $store, 'key' => 'wholesale.minimum_order', 'value' => json_encode(25), 'is_secret' => false, 'created_at' => now(), 'updated_at' => now()],
            ['store_id' => $store, 'key' => 'wholesale.private_token', 'value' => json_encode('never-show'), 'is_secret' => true, 'created_at' => now(), 'updated_at' => now()],
        ]);

        $admin = $this->user('B2B_ADMIN', 'en');
        $this->actingAs($admin)->get('/admin/b2b/reports')
            ->assertOk()
            ->assertSee('EGP 18.000')
            ->assertSee('Open reports')
            ->assertDontSee('Wholesale store');

        $this->actingAs($admin)->get('/admin/b2b/settings')
            ->assertOk()
            ->assertSee('wholesale.minimum_order')
            ->assertSee('25')
            ->assertDontSee('wholesale.private_token')
            ->assertDontSee('never-show');
    }

    public function test_retail_linked_status_is_store_managed_and_unpriced_accounts_are_not_order_options(): void
    {
        $retailType = (int) DB::table('store_types')->where('code', 'B2C')->value('id');
        $retailStore = (int) DB::table('stores')->insertGetId([
            'store_type_id' => $retailType,
            'code' => 'INSPECT-RETAIL',
            'name' => 'Inspector Retail',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        [$retailLegacy, $retailCustomer] = $this->b2bCustomer('Retail Linked Buyer', 'retail-linked@example.test');
        $retailAccount = (int) DB::table('b2b_accounts')->insertGetId([
            'customer_id' => $retailLegacy,
            'b2b_customer_id' => $retailCustomer,
            'price_tier_id' => null,
            'company_name' => 'Inspector Retail',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('retail_wholesale_accounts')->insert([
            'retail_store_id' => $retailStore,
            'b2b_customer_id' => $retailCustomer,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $tier = (int) DB::table('b2b_price_tiers')->where('code', 'STANDARD')->value('id');
        [$approvedLegacy, $approvedCustomer] = $this->b2bCustomer('Approved Buyer', 'approved-buyer@example.test');
        $approvedAccount = (int) DB::table('b2b_accounts')->insertGetId([
            'customer_id' => $approvedLegacy,
            'b2b_customer_id' => $approvedCustomer,
            'price_tier_id' => $tier,
            'company_name' => 'Approved Company',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $admin = $this->user('B2B_ADMIN', 'en');

        $clients = $this->actingAs($admin)->get('/admin/b2b/clients')->assertOk();
        $clients->assertSee('Status is controlled by the linked Retail store');
        $this->assertStringNotContainsString(
            route('admin.b2b.clients.status', ['account' => $retailAccount], false),
            $clients->getContent(),
        );
        $this->assertStringContainsString(
            route('admin.b2b.clients.status', ['account' => $approvedAccount], false),
            $clients->getContent(),
        );

        $orders = $this->actingAs($admin)->get('/admin/b2b/orders')->assertOk();
        $orders->assertSee('Approved Buyer');
        $orders->assertDontSee('Retail Linked Buyer');
    }

    public function test_reference_routes_and_permissions_remain_available(): void
    {
        $admin = $this->user('B2B_ADMIN', 'en');

        $this->actingAs($admin)->get('/admin/b2b/pricing-approvals')->assertOk()->assertSee('Pricing & Approvals');
        $this->actingAs($admin)->get('/admin/b2b/settings-permissions')->assertOk()->assertSee('Wholesale Settings');

        $finance = $this->user('FINANCE', 'en');
        $this->actingAs($finance)->get('/admin/b2b/pricing-approvals')->assertForbidden();
        $this->actingAs($finance)->get('/admin/b2b/settings-permissions')->assertForbidden();
    }

    public function test_retail_only_role_is_forbidden_and_invalid_module_is_not_found(): void
    {
        $retailDriver = $this->user('B2C_DRIVER', 'en');
        $this->actingAs($retailDriver)->get('/admin/b2b/dashboard')->assertForbidden();

        $admin = $this->user('B2B_ADMIN', 'en');
        $this->actingAs($admin)->get('/admin/b2b/not-real')->assertNotFound();
    }

    public function test_english_locale_renders_only_approved_wholesale_modules(): void
    {
        $admin = $this->user('SUPER_ADMIN', 'en');
        $response = $this->actingAs($admin)->get('/admin/b2b/dashboard')
            ->assertOk()
            ->assertSee('dir="ltr"', false)
            ->assertDontSee('Wholesale Stores');

        foreach (['B2B Clients', 'Wholesale Catalog', 'Warehouses & Inventory', 'Orders', 'Drivers & Delivery', 'Pricing & Approvals', 'Finance & Invoices', 'Reports'] as $label) {
            $response->assertSee($label);
        }
    }

    public function test_b2b_workspace_inherits_premium_shared_visual_primitives(): void
    {
        $admin = $this->user('SUPER_ADMIN', 'en');

        $this->actingAs($admin)->get('/admin/b2b/dashboard')
            ->assertOk()
            ->assertSee('data-b2b-premium="v1"', false)
            ->assertSee('class="main foodex-admin-page"', false)
            ->assertSee('data-b2b-reference-dashboard="841x564"', false)
            ->assertSee('b2b-ref-kpis', false)
            ->assertSee('b2b-ref-middle', false)
            ->assertSee('b2b-ref-bottom', false)
            ->assertSee('b2b-ref-chart-wrap', false)
            ->assertSee('b2b-ref-donut', false);
    }

    /** @return array{0:int,1:int} */
    private function b2bCustomer(string $name, ?string $email, ?string $phone = null): array
    {
        $legacy = (int) DB::table('customers')->insertGetId([
            'type' => 'b2b',
            'name' => $name,
            'email' => $email,
            'phone' => $phone,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $b2b = (int) DB::table('b2b_customers')->insertGetId([
            'legacy_customer_id' => $legacy,
            'name' => $name,
            'email' => $email,
            'phone' => $phone,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [$legacy, $b2b];
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
            'code' => 'EA',
            'name' => 'Each',
            'name_ar' => 'قطعة',
            'name_en' => 'Each',
            'decimal_places' => 0,
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

    private function user(string $role, string $locale): User
    {
        $user = User::query()->create([
            'name' => $role,
            'email' => strtolower($role).'-'.$locale.'-'.uniqid().'@workspace.test',
            'password' => 'password',
            'locale' => $locale,
            'is_active' => true,
        ]);
        $user->roles()->attach(Role::query()->where('code', $role)->firstOrFail());

        return $user;
    }
}
