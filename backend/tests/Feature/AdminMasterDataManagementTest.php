<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AdminMasterDataManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_super_admin_can_manage_categories_stores_products_and_marketing_data(): void
    {
        $user = $this->superAdmin('ar');

        $this->actingAs($user)
            ->get('/admin/manage/categories')
            ->assertOk()
            ->assertSee('إدارة التصنيفات')
            ->assertSee('إضافة سجل جديد');

        $this->actingAs($user)
            ->post('/admin/manage/categories', [
                'name' => 'مشروبات',
                'slug' => 'beverages',
                'is_active' => 1,
            ])
            ->assertRedirect(route('admin.manage.categories'));

        $categoryId = (int) DB::table('categories')->where('slug', 'beverages')->value('id');
        $this->assertGreaterThan(0, $categoryId);

        $typeId = (int) DB::table('store_types')->where('code', 'B2C')->value('id');
        $this->actingAs($user)
            ->post('/admin/manage/stores', [
                'store_type_id' => $typeId,
                'code' => 'KW-001',
                'name' => 'متجر الكويت',
                'is_active' => 1,
            ])
            ->assertRedirect(route('admin.manage.stores'));

        $storeId = (int) DB::table('stores')->where('code', 'KW-001')->value('id');
        $unitId = (int) DB::table('units')->where('code', 'PC')->value('id');

        $this->actingAs($user)
            ->post('/admin/manage/products', [
                'sku' => 'FDX-001',
                'name' => 'مياه معدنية',
                'category_id' => $categoryId,
                'unit_id' => $unitId,
                'store_id' => $storeId,
                'price' => 0.750,
                'is_active' => 1,
                'listing_active' => 1,
            ])
            ->assertRedirect(route('admin.manage.products'));

        $productId = (int) DB::table('products')->where('sku', 'FDX-001')->value('id');
        $this->assertDatabaseHas('products', ['id' => $productId, 'category_id' => $categoryId, 'is_active' => 1]);
        $this->assertDatabaseHas('store_products', ['store_id' => $storeId, 'product_id' => $productId, 'is_active' => 1]);

        $this->actingAs($user)
            ->put('/admin/manage/products/'.$productId, [
                'sku' => 'FDX-001',
                'name' => 'مياه معدنية 500 مل',
                'category_id' => $categoryId,
                'unit_id' => $unitId,
                'store_id' => $storeId,
                'price' => 0.800,
                'is_active' => 1,
                'listing_active' => 1,
            ])
            ->assertRedirect(route('admin.manage.products'));

        $this->assertDatabaseHas('products', ['id' => $productId, 'name' => 'مياه معدنية 500 مل']);
        $this->assertDatabaseHas('store_products', ['store_id' => $storeId, 'product_id' => $productId, 'price' => 0.800]);

        $this->actingAs($user)
            ->post('/admin/manage/promotions', [
                'store_id' => $storeId,
                'name' => 'عرض الافتتاح',
                'type' => 'percentage',
                'value' => 10,
                'is_active' => 1,
            ])
            ->assertRedirect(route('admin.manage.promotions'));

        $this->actingAs($user)
            ->post('/admin/manage/banners', [
                'store_id' => $storeId,
                'title' => 'بانر الافتتاح',
                'image_path' => '/storage/banners/opening.jpg',
                'sort_order' => 1,
                'is_active' => 1,
            ])
            ->assertRedirect(route('admin.manage.banners'));

        $this->assertDatabaseHas('promotions', ['name' => 'عرض الافتتاح', 'store_id' => $storeId]);
        $this->assertDatabaseHas('banners', ['title' => 'بانر الافتتاح', 'store_id' => $storeId]);
    }

    public function test_category_delete_is_available_but_operational_product_delete_is_guarded(): void
    {
        $user = $this->superAdmin();

        $categoryId = (int) DB::table('categories')->insertGetId([
            'name' => 'Temporary',
            'slug' => 'temporary',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($user)
            ->delete('/admin/manage/categories/'.$categoryId)
            ->assertRedirect(route('admin.manage.categories'));

        $this->assertDatabaseMissing('categories', ['id' => $categoryId]);

        $unitId = (int) DB::table('units')->where('code', 'PC')->value('id');
        $productId = (int) DB::table('products')->insertGetId([
            'unit_id' => $unitId,
            'sku' => 'LOCKED-1',
            'name' => 'Referenced Product',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $typeId = (int) DB::table('store_types')->where('code', 'B2C')->value('id');
        $storeId = (int) DB::table('stores')->insertGetId([
            'store_type_id' => $typeId,
            'code' => 'LOCKED-STORE',
            'name' => 'Locked Store',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $warehouseId = (int) DB::table('warehouses')->insertGetId([
            'store_id' => $storeId,
            'code' => 'LOCKED-WH',
            'name' => 'Locked Warehouse',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('inventories')->insert([
            'warehouse_id' => $warehouseId,
            'product_id' => $productId,
            'quantity' => 5,
            'reserved_quantity' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($user)
            ->from('/admin/manage/products')
            ->delete('/admin/manage/products/'.$productId)
            ->assertRedirect('/admin/manage/products')
            ->assertSessionHasErrors('delete');

        $this->assertDatabaseHas('products', ['id' => $productId]);
    }

    public function test_store_scoped_admin_cannot_open_global_master_data_center(): void
    {
        $typeId = (int) DB::table('store_types')->where('code', 'B2C')->value('id');
        $storeId = (int) DB::table('stores')->insertGetId([
            'store_type_id' => $typeId,
            'code' => 'SCOPED-STORE',
            'name' => 'Scoped Store',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $user = User::query()->create([
            'name' => 'Scoped Admin',
            'email' => 'scoped-master@example.test',
            'password' => 'password',
            'locale' => 'ar',
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

        $this->actingAs($user)
            ->get('/admin/manage/categories')
            ->assertForbidden();
    }

    public function test_default_product_units_and_b2b_price_tiers_exist_after_fresh_migration(): void
    {
        foreach (['PC', 'KG', 'G', 'L', 'ML', 'PACK', 'BOX'] as $code) {
            $this->assertDatabaseHas('units', ['code' => $code]);
        }

        foreach (['STANDARD', 'WHOLESALE', 'VIP'] as $code) {
            $this->assertDatabaseHas('b2b_price_tiers', ['code' => $code]);
        }
    }

    private function superAdmin(string $locale = 'en'): User
    {
        $user = User::query()->create([
            'name' => 'Owner',
            'email' => 'owner-master@example.test',
            'password' => 'password',
            'locale' => $locale,
            'is_active' => true,
        ]);

        $user->roles()->attach(Role::query()->where('code', 'SUPER_ADMIN')->firstOrFail());

        return $user;
    }
}
