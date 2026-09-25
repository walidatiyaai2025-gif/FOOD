<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class B2cAdminWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    public function test_b2c_admin_sees_only_assigned_store_and_rtl_workspace(): void
    {
        $this->seed(CoreReferenceSeeder::class);
        $type = (int) DB::table('store_types')->where('code', 'B2C')->value('id');
        $mine = (int) DB::table('stores')->insertGetId(['store_type_id' => $type, 'code' => 'MINE', 'name' => 'Mine', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('stores')->insertGetId(['store_type_id' => $type, 'code' => 'OTHER', 'name' => 'Other', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $user = User::query()->create(['name' => 'Store Admin', 'email' => 'store-admin@example.test', 'password' => 'password', 'locale' => 'ar', 'is_active' => true]);
        $role = Role::query()->where('code', 'B2C_STORE_ADMIN')->firstOrFail();
        $user->roles()->attach($role);
        DB::table('user_store_roles')->insert(['user_id' => $user->id, 'store_id' => $mine, 'role_id' => $role->id, 'created_at' => now(), 'updated_at' => now()]);

        $this->actingAs($user)->get('/admin/b2c/products')->assertOk()->assertSee('dir="rtl"', false)->assertSee('نطاق المتاجر المصرح: 1')->assertDontSee('Other');
    }

    public function test_premium_dashboard_is_rtl_branded_and_server_driven(): void
    {
        $this->seed(CoreReferenceSeeder::class);
        $type = (int) DB::table('store_types')->where('code', 'B2C')->value('id');
        $store = (int) DB::table('stores')->insertGetId(['store_type_id' => $type, 'code' => 'PREMIUM', 'name' => 'Premium', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $warehouse = (int) DB::table('warehouses')->insertGetId(['store_id'=>$store,'code'=>'PREMIUM-WH','name'=>'Premium Warehouse','is_active'=>true,'created_at'=>now(),'updated_at'=>now()]);
        $user = User::query()->create(['name' => 'أحمد السعيد', 'email' => 'premium@example.test', 'password' => 'password', 'locale' => 'ar', 'is_active' => true]);
        $role = Role::query()->where('code', 'B2C_STORE_ADMIN')->firstOrFail();
        $user->roles()->attach($role);
        DB::table('user_store_roles')->insert(['user_id'=>$user->id,'store_id'=>$store,'role_id'=>$role->id,'created_at'=>now(),'updated_at'=>now()]);

        $unit=(int) DB::table('units')->insertGetId(['code'=>'PC','name'=>'Piece','decimal_places'=>0,'created_at'=>now(),'updated_at'=>now()]);
        $product=(int) DB::table('products')->insertGetId(['unit_id'=>$unit,'sku'=>'PREM-1','name'=>'زيت زيتون عضوي','is_active'=>true,'created_at'=>now(),'updated_at'=>now()]);
        DB::table('store_products')->insert(['store_id'=>$store,'product_id'=>$product,'price'=>10,'is_active'=>true,'created_at'=>now(),'updated_at'=>now()]);
        DB::table('inventories')->insert(['warehouse_id'=>$warehouse,'product_id'=>$product,'quantity'=>5,'reserved_quantity'=>0,'created_at'=>now(),'updated_at'=>now()]);
        $customer=(int) DB::table('customers')->insertGetId(['type'=>'b2c','name'=>'سارة محمد','created_at'=>now(),'updated_at'=>now()]);
        $order=(int) DB::table('orders')->insertGetId(['store_id'=>$store,'customer_id'=>$customer,'order_number'=>'#1245','channel'=>'b2c','status'=>'delivered','currency'=>'KWD','subtotal'=>20,'discount_total'=>0,'delivery_total'=>0,'grand_total'=>20,'created_at'=>now(),'updated_at'=>now()]);
        DB::table('order_items')->insert(['order_id'=>$order,'product_id'=>$product,'sku_snapshot'=>'PREM-1','name_snapshot'=>'زيت زيتون عضوي','quantity'=>2,'unit_price'=>10,'line_total'=>20,'created_at'=>now(),'updated_at'=>now()]);

        $this->actingAs($user)
            ->get('/admin/b2c/dashboard')
            ->assertOk()
            ->assertSee('dir="rtl"', false)
            ->assertSee('مرحباً أحمد السعيد')
            ->assertSee('إجمالي الطلبات')
            ->assertSee('KWD 20.000')
            ->assertSee('زيت زيتون عضوي')
            ->assertSee('#1245')
            ->assertSee('--foodex-green:#158A3A', false);

        $this->assertNotNull($user->fresh()->last_seen_at);
    }

    public function test_b2c_admin_without_assigned_store_is_forbidden_and_invalid_module_is_not_found(): void
    {
        $this->seed(CoreReferenceSeeder::class);
        $user = User::query()->create(['name' => 'Unscoped', 'email' => 'unscoped@example.test', 'password' => 'password', 'locale' => 'en', 'is_active' => true]);
        $user->roles()->attach(Role::query()->where('code', 'B2C_STORE_ADMIN')->firstOrFail());

        $this->actingAs($user)->get('/admin/b2c/dashboard')->assertForbidden();

        $scoped = (int) DB::table('stores')->insertGetId(['store_type_id' => (int) DB::table('store_types')->where('code', 'B2C')->value('id'), 'code' => 'SCOPED', 'name' => 'Scoped', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $role = Role::query()->where('code', 'B2C_STORE_ADMIN')->firstOrFail();
        DB::table('user_store_roles')->insert(['user_id' => $user->id, 'store_id' => $scoped, 'role_id' => $role->id, 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAs($user)->get('/admin/b2c/not-real')->assertNotFound();
    }
}
