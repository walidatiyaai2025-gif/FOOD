<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\DemoDataManager;
use Database\Seeders\CoreReferenceSeeder;
use Database\Seeders\ProductDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class DemoDataSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_product_demo_seed_populates_rich_b2c_dataset_and_is_idempotent(): void
    {
        $this->seed(ProductDemoSeeder::class);
        $first = app(DemoDataManager::class)->summary();

        $this->assertSame(10, $first['stores']);
        $this->assertSame(50, $first['customers']);
        $this->assertSame(20, $first['products']);
        $this->assertSame(400, $first['orders']);
        $this->assertSame(10, $first['promotions']);
        $this->assertSame(10, $first['banners']);
        $this->assertSame(10, $first['drivers']);
        $this->assertSame(10, $first['notifications']);
        $this->assertSame(200, DB::table('store_products')->whereIn('store_id', $this->demoStoreIds())->count());
        $this->assertSame(10, DB::table('driver_assignments')->whereIn('order_id', $this->demoOrderIds())->count());

        $this->seed(ProductDemoSeeder::class);

        $this->assertSame($first, app(DemoDataManager::class)->summary());
        $this->assertSame(200, DB::table('store_products')->whereIn('store_id', $this->demoStoreIds())->count());
    }

    public function test_demo_cleanup_preserves_real_business_data_and_core_reference_data(): void
    {
        $this->seed(ProductDemoSeeder::class);

        $storeTypeId = (int) DB::table('store_types')->where('code', 'B2C')->value('id');
        $unitId = (int) DB::table('units')->insertGetId([
            'code' => 'REAL-PC', 'name' => 'Real Piece', 'decimal_places' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $realStoreId = (int) DB::table('stores')->insertGetId([
            'store_type_id' => $storeTypeId, 'code' => 'REAL-B2C-001', 'name' => 'Real Store', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $realProductId = (int) DB::table('products')->insertGetId([
            'category_id' => null, 'unit_id' => $unitId, 'sku' => 'REAL-SKU-001', 'name' => 'Real Product', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $realCustomerId = (int) DB::table('customers')->insertGetId([
            'type' => 'b2c', 'name' => 'Real Customer', 'email' => 'real@example.test',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('orders')->insert([
            'store_id' => $realStoreId, 'customer_id' => $realCustomerId, 'address_id' => null,
            'order_number' => 'REAL-ORDER-001', 'channel' => 'b2c', 'status' => 'processing', 'currency' => 'KWD',
            'subtotal' => 1, 'discount_total' => 0, 'delivery_total' => 0, 'grand_total' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        app(DemoDataManager::class)->clear();

        $this->assertSame([
            'stores' => 0, 'customers' => 0, 'products' => 0, 'orders' => 0,
            'promotions' => 0, 'banners' => 0, 'drivers' => 0, 'notifications' => 0,
        ], app(DemoDataManager::class)->summary());
        $this->assertDatabaseHas('stores', ['id' => $realStoreId, 'code' => 'REAL-B2C-001']);
        $this->assertDatabaseHas('products', ['id' => $realProductId, 'sku' => 'REAL-SKU-001']);
        $this->assertDatabaseHas('customers', ['id' => $realCustomerId, 'email' => 'real@example.test']);
        $this->assertDatabaseHas('orders', ['order_number' => 'REAL-ORDER-001']);
        $this->assertDatabaseHas('store_types', ['code' => 'B2C']);
        $this->assertDatabaseHas('roles', ['code' => 'SUPER_ADMIN']);
    }

    public function test_super_admin_can_clear_demo_data_from_security_center_and_action_is_audited(): void
    {
        $this->seed(ProductDemoSeeder::class);
        $superAdminRoleId = (int) DB::table('roles')->where('code', 'SUPER_ADMIN')->value('id');
        $user = User::query()->create([
            'name' => 'Real Super Admin',
            'email' => 'owner@example.test',
            'password' => Hash::make('Secret123!'),
            'locale' => 'en',
            'is_active' => true,
        ]);
        DB::table('role_user')->insert(['role_id' => $superAdminRoleId, 'user_id' => $user->id]);

        $this->actingAs($user)
            ->delete('/admin/security/demo-data', ['confirmation' => 'DELETE DEMO DATA'])
            ->assertRedirect();

        $this->assertSame(0, app(DemoDataManager::class)->summary()['stores']);
        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $user->id,
            'event' => 'demo_data.cleared',
        ]);
    }

    public function test_non_super_admin_cannot_clear_demo_data(): void
    {
        $this->seed(CoreReferenceSeeder::class);
        $operationsRoleId = (int) DB::table('roles')->where('code', 'OPERATIONS')->value('id');
        $user = User::query()->create([
            'name' => 'Operations User',
            'email' => 'ops@example.test',
            'password' => Hash::make('Secret123!'),
            'locale' => 'en',
            'is_active' => true,
        ]);
        DB::table('role_user')->insert(['role_id' => $operationsRoleId, 'user_id' => $user->id]);

        $this->actingAs($user)
            ->delete('/admin/security/demo-data', ['confirmation' => 'DELETE DEMO DATA'])
            ->assertForbidden();
    }

    /** @return list<int> */
    private function demoStoreIds(): array
    {
        return DB::table('stores')->where('code', 'like', DemoDataManager::STORE_PREFIX.'%')->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    /** @return list<int> */
    private function demoOrderIds(): array
    {
        return DB::table('orders')->where('order_number', 'like', DemoDataManager::ORDER_PREFIX.'%')->pluck('id')->map(fn ($id) => (int) $id)->all();
    }
}
