<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AdminBusinessManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_super_admin_can_manage_operational_data_from_web_ui(): void
    {
        $user = $this->superAdmin();
        $typeId = (int) DB::table('store_types')->where('code', 'B2C')->value('id');
        $storeId = (int) DB::table('stores')->insertGetId([
            'store_type_id' => $typeId,
            'code' => 'OPS',
            'name' => 'Operations Store',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $unitId = (int) DB::table('units')->insertGetId([
            'code' => 'EA',
            'name' => 'Each',
            'decimal_places' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $productId = (int) DB::table('products')->insertGetId([
            'unit_id' => $unitId,
            'sku' => 'OPS-1',
            'name' => 'Operations Product',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($user)->get('/admin/business?tab=inventory')
            ->assertOk()
            ->assertSee('إدارة العمليات والبيانات');

        $this->actingAs($user)->post('/admin/business/warehouses', [
            'store_id' => $storeId,
            'code' => 'OPS-WH',
            'name' => 'Main Warehouse',
            'is_active' => 1,
        ])->assertSessionHasNoErrors();

        $warehouseId = (int) DB::table('warehouses')->where('code', 'OPS-WH')->value('id');

        $this->actingAs($user)->post('/admin/business/inventory', [
            'warehouse_id' => $warehouseId,
            'product_id' => $productId,
            'quantity' => 10,
        ])->assertSessionHasNoErrors();

        $inventoryId = (int) DB::table('inventories')
            ->where('warehouse_id', $warehouseId)
            ->where('product_id', $productId)
            ->value('id');

        $this->actingAs($user)->patch('/admin/business/inventory/'.$inventoryId, [
            'quantity_delta' => 5,
            'reason' => 'Opening stock correction',
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('inventories', ['id' => $inventoryId, 'quantity' => 15]);

        $this->actingAs($user)->post('/admin/business/customers', [
            'type' => 'b2c',
            'store_id' => $storeId,
            'support_access' => true,
            'name' => 'Customer One',
            'phone' => '5550001',
            'email' => 'customer@example.test',
        ])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('b2c_customers', [
            'store_id' => $storeId,
            'name' => 'Customer One',
        ]);

        $this->actingAs($user)->post('/admin/business/promotions', [
            'store_id' => $storeId,
            'name' => 'Launch Offer',
            'type' => 'percentage',
            'value' => 10,
            'is_active' => 1,
        ])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('promotions', ['name' => 'Launch Offer']);

        $this->actingAs($user)->post('/admin/business/banners', [
            'store_id' => $storeId,
            'title' => 'Launch Banner',
            'image_path' => '/storage/banners/launch.jpg',
            'sort_order' => 1,
            'is_active' => 1,
        ])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('banners', ['title' => 'Launch Banner']);

        $this->actingAs($user)->post('/admin/business/drivers', [
            'name' => 'Driver One',
            'email' => 'driver-one@example.test',
            'password' => 'StrongPass123!',
            'driver_type' => 'b2c',
            'is_available' => 1,
            'is_active' => 1,
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('users', ['email' => 'driver-one@example.test']);
        $this->assertDatabaseHas('drivers', ['driver_type' => 'b2c', 'is_active' => 1]);

        foreach ([
            '/admin/b2c/inventory' => 'إدارة المخازن والأرصدة',
            '/admin/b2c/customers' => 'إضافة / تعديل العملاء',
            '/admin/b2c/promotions' => 'إضافة / تعديل العروض',
            '/admin/b2c/content' => 'إضافة / تعديل البنرات',
            '/admin/b2c/drivers' => 'إضافة / إدارة السائقين',
        ] as $uri => $label) {
            $this->actingAs($user)->get($uri)->assertOk()->assertSee($label);
        }
    }

    public function test_driver_cannot_open_business_management_center(): void
    {
        $user = User::query()->create([
            'name' => 'Driver',
            'email' => 'driver-no-admin@example.test',
            'password' => 'password',
            'locale' => 'ar',
            'is_active' => true,
        ]);
        $user->roles()->attach(Role::query()->where('code', 'B2C_DRIVER')->firstOrFail());

        $this->actingAs($user)->get('/admin/business')->assertForbidden();
    }

    private function superAdmin(): User
    {
        $user = User::query()->create([
            'name' => 'Owner',
            'email' => 'owner-business@example.test',
            'password' => 'password',
            'locale' => 'ar',
            'is_active' => true,
        ]);
        $user->roles()->attach(Role::query()->where('code', 'SUPER_ADMIN')->firstOrFail());

        return $user;
    }
}
