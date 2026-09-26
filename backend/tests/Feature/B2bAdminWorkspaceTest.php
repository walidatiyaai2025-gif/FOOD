<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class B2bAdminWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    public function test_b2b_admin_gets_rtl_workspace_and_b2b_scoped_counts(): void
    {
        $this->seed(CoreReferenceSeeder::class);
        $b2bType = (int) DB::table('store_types')->where('code', 'B2B')->value('id');
        $b2cType = (int) DB::table('store_types')->where('code', 'B2C')->value('id');
        DB::table('stores')->insert(['store_type_id' => $b2bType, 'code' => 'B2B-ADMIN', 'name' => 'Wholesale', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('stores')->insert(['store_type_id' => $b2cType, 'code' => 'B2C-HIDDEN', 'name' => 'Retail', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $user = $this->user('B2B_ADMIN', 'ar');

        $this->actingAs($user)->get('/admin/b2b/products')
            ->assertOk()
            ->assertSee('dir="rtl"', false)
            ->assertSee('المنتجات والمخزون')
            ->assertSee('FOODEX · B2B');
    }

    public function test_core_b2b_screens_render_live_scoped_data(): void
    {
        $this->seed(CoreReferenceSeeder::class);
        $b2bType = (int) DB::table('store_types')->where('code', 'B2B')->value('id');
        $store = (int) DB::table('stores')->insertGetId(['store_type_id' => $b2bType, 'code' => 'WHOLESALE-1', 'name' => 'Wholesale One', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $warehouse = (int) DB::table('warehouses')->insertGetId(['store_id' => $store, 'code' => 'WHOLESALE-WH', 'name' => 'Wholesale Warehouse', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $unit = (int) DB::table('units')->insertGetId(['code' => 'B2B-PC', 'name' => 'Piece', 'decimal_places' => 0, 'created_at' => now(), 'updated_at' => now()]);
        $product = (int) DB::table('products')->insertGetId(['unit_id' => $unit, 'sku' => 'B2B-SKU', 'name' => 'B2B Product', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('store_products')->insert(['store_id' => $store, 'product_id' => $product, 'price' => 12.500, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('inventories')->insert(['warehouse_id' => $warehouse, 'product_id' => $product, 'quantity' => 20, 'reserved_quantity' => 3, 'created_at' => now(), 'updated_at' => now()]);
        $customer = (int) DB::table('customers')->insertGetId(['type' => 'b2b', 'name' => 'Buyer One', 'email' => 'buyer-one@example.test', 'phone' => '50000000', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('b2b_accounts')->insert(['customer_id' => $customer, 'company_name' => 'Buyer Co', 'tax_number' => 'TAX-1', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('orders')->insert(['store_id' => $store, 'customer_id' => $customer, 'order_number' => 'B2B-ORDER-1', 'channel' => 'b2b', 'status' => 'processing', 'currency' => 'KWD', 'subtotal' => 12.5, 'discount_total' => 0, 'delivery_total' => 0, 'grand_total' => 12.5, 'created_at' => now(), 'updated_at' => now()]);
        $admin = $this->user('B2B_ADMIN', 'en');

        $this->actingAs($admin)->get('/admin/b2b/dashboard')->assertOk()->assertSee('B2B-ORDER-1')->assertSee('Buyer One');
        $this->actingAs($admin)->get('/admin/b2b/stores')->assertOk()->assertSee('Wholesale One');
        $this->actingAs($admin)->get('/admin/b2b/clients')->assertOk()->assertSee('Buyer Co')->assertSee('TAX-1');
        $this->actingAs($admin)->get('/admin/b2b/products')->assertOk()->assertSee('B2B Product')->assertSee('17.000')->assertSee('12.500 KWD');
    }

    public function test_non_b2b_management_role_is_forbidden_and_invalid_module_is_not_found(): void
    {
        $this->seed(CoreReferenceSeeder::class);
        $finance = $this->user('FINANCE', 'en');
        $this->actingAs($finance)->get('/admin/b2b/dashboard')->assertForbidden();

        $admin = $this->user('B2B_ADMIN', 'en');
        $this->actingAs($admin)->get('/admin/b2b/not-real')->assertNotFound();
    }

    public function test_english_locale_renders_ltr_and_all_approved_modules(): void
    {
        $this->seed(CoreReferenceSeeder::class);
        $admin = $this->user('SUPER_ADMIN', 'en');
        $response = $this->actingAs($admin)->get('/admin/b2b/dashboard')->assertOk()->assertSee('dir="ltr"', false);
        foreach (['Stores', 'B2B Clients', 'Products & Inventory', 'Orders', 'Drivers & Delivery', 'Pricing & Approvals', 'Reports', 'Settings & Permissions'] as $label) {
            $response->assertSee($label);
        }
    }

    private function user(string $role, string $locale): User
    {
        $user = User::query()->create(['name' => $role, 'email' => strtolower($role).'-'.$locale.'@workspace.test', 'password' => 'password', 'locale' => $locale, 'is_active' => true]);
        $user->roles()->attach(Role::query()->where('code', $role)->firstOrFail());

        return $user;
    }
}
