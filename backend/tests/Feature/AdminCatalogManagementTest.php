<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AdminCatalogManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_arabic_admin_shell_keeps_sidebar_on_the_right(): void
    {
        $user = $this->superAdmin('ar');

        $this->actingAs($user)->get('/admin')
            ->assertOk()
            ->assertSee('dir="rtl"', false)
            ->assertSee('.shell{direction:ltr;', false)
            ->assertSee('.shell-sidebar{grid-column:2;grid-row:1;direction:rtl;', false);
    }

    public function test_super_admin_can_manage_categories_stores_and_products_from_web_ui(): void
    {
        $user = $this->superAdmin('ar');

        $this->actingAs($user)->get('/admin/catalog?tab=categories')
            ->assertOk()
            ->assertSee('إدارة الكتالوج والمتاجر')
            ->assertSee('إضافة تصنيف');

        $this->actingAs($user)->post('/admin/catalog/units', [
            'code' => 'CRUD-PC',
            'name' => 'قطعة',
            'decimal_places' => 0,
        ])->assertSessionHasNoErrors();

        $unitId = (int) DB::table('units')->where('code', 'CRUD-PC')->value('id');
        $typeId = (int) DB::table('store_types')->where('code', 'B2C')->value('id');

        $this->actingAs($user)->post('/admin/catalog/stores', [
            'store_type_id' => $typeId,
            'code' => 'MAIN',
            'name' => 'المتجر الرئيسي',
            'is_active' => 1,
        ])->assertSessionHasNoErrors();

        $storeId = (int) DB::table('stores')->where('code', 'MAIN')->value('id');

        $this->actingAs($user)->post('/admin/catalog/categories', [
            'store_id' => $storeId,
            'name' => 'مشروبات',
            'slug' => 'beverages',
            'is_active' => 1,
        ])->assertSessionHasNoErrors();

        $categoryId = (int) DB::table('categories')->where('slug', 'beverages')->value('id');

        $this->actingAs($user)->post('/admin/catalog/products', [
            'sku' => 'SKU-001',
            'name' => 'مياه',
            'category_id' => $categoryId,
            'unit_id' => $unitId,
            'store_id' => $storeId,
            'price' => 0.500,
            'is_active' => 1,
        ])->assertSessionHasNoErrors();

        $productId = (int) DB::table('products')->where('sku', 'SKU-001')->value('id');

        $this->assertGreaterThan(0, $productId);
        $this->assertDatabaseHas('store_products', [
            'store_id' => $storeId,
            'product_id' => $productId,
        ]);
        $catalogId = (int) DB::table('catalogs')->where('store_id', $storeId)->where('code', 'default')->value('id');
        $this->assertDatabaseHas('products', ['id' => $productId, 'catalog_id' => $catalogId]);
        $this->assertDatabaseHas('categories', ['id' => $categoryId, 'catalog_id' => $catalogId]);

        $this->actingAs($user)->patch('/admin/catalog/products/'.$productId, [
            'sku' => 'SKU-001',
            'name' => 'مياه معدنية',
            'category_id' => $categoryId,
            'unit_id' => $unitId,
            'is_active' => 1,
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('products', [
            'id' => $productId,
            'name' => 'مياه معدنية',
        ]);

        $this->actingAs($user)->get('/admin/b2c/products')
            ->assertOk()
            ->assertSee('إضافة / تعديل المنتجات')
            ->assertSee('إدارة التصنيفات');
    }

    public function test_catalog_management_requires_catalog_permission(): void
    {
        $user = User::query()->create([
            'name' => 'Driver',
            'email' => 'driver-catalog@example.test',
            'password' => 'password',
            'locale' => 'ar',
            'is_active' => true,
        ]);
        $user->roles()->attach(Role::query()->where('code', 'B2C_DRIVER')->firstOrFail());

        $this->actingAs($user)->get('/admin/catalog')->assertForbidden();
    }

    private function superAdmin(string $locale): User
    {
        $user = User::query()->create([
            'name' => 'Owner',
            'email' => 'owner-catalog@example.test',
            'password' => 'password',
            'locale' => $locale,
            'is_active' => true,
        ]);
        $user->roles()->attach(Role::query()->where('code', 'SUPER_ADMIN')->firstOrFail());

        return $user;
    }
}
