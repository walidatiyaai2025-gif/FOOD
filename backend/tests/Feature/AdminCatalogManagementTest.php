<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Services\CatalogOwnership;
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

    public function test_super_admin_can_manage_categories_and_products_from_web_ui(): void
    {
        $user = $this->superAdmin('ar');

        $this->actingAs($user)->get('/admin/catalog?tab=categories')
            ->assertOk()
            ->assertSee('إدارة الكتالوج')
            ->assertSee('إضافة تصنيف');

        $this->actingAs($user)->post('/admin/catalog/units', [
            'code' => 'CRUD-PC',
            'name' => 'قطعة',
            'decimal_places' => 0,
        ])->assertSessionHasNoErrors();

        $unitId = (int) DB::table('units')->where('code', 'CRUD-PC')->value('id');
        $typeId = (int) DB::table('store_types')->where('code', 'B2C')->value('id');

        $storeId = (int) DB::table('stores')->insertGetId([
            'store_type_id' => $typeId,
            'code' => 'MAIN',
            'name' => 'المتجر الرئيسي',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

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

        $warehouseId = (int) DB::table('warehouses')->insertGetId([
            'store_id' => $storeId,
            'code' => 'CATALOG-OOS-WH',
            'name' => 'Catalog OOS Warehouse',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('inventories')->insert([
            'warehouse_id' => $warehouseId,
            'product_id' => $productId,
            'quantity' => 2,
            'reserved_quantity' => 2,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($user)
            ->get('/admin/catalog?tab=products&store_id='.$storeId.'&support_access=1')
            ->assertOk()
            ->assertSee('data-availability-state="OUT_OF_STOCK"', false)
            ->assertSee('نفد');

        DB::table('inventories')
            ->where('warehouse_id', $warehouseId)
            ->where('product_id', $productId)
            ->update(['reserved_quantity' => 1, 'updated_at' => now()]);

        $this->actingAs($user)
            ->get('/admin/catalog?tab=products&store_id='.$storeId.'&support_access=1')
            ->assertOk()
            ->assertSee('data-availability-state="AVAILABLE"', false);

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

        $this->actingAs($user)
            ->get('/admin/b2c/products?store_id='.$storeId.'&support_access=1')
            ->assertOk()
            ->assertSee('إضافة / تعديل المنتجات')
            ->assertSee('إدارة التصنيفات');
    }

    public function test_editing_product_with_unchanged_legacy_scoped_lookup_does_not_422(): void
    {
        $user = $this->superAdmin('en');
        $b2cTypeId = (int) DB::table('store_types')->where('code', 'B2C')->value('id');

        $storeA = (int) DB::table('stores')->insertGetId([
            'store_type_id' => $b2cTypeId,
            'code' => 'LEGACY-A',
            'name' => 'Legacy A',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $storeB = (int) DB::table('stores')->insertGetId([
            'store_type_id' => $b2cTypeId,
            'code' => 'LEGACY-B',
            'name' => 'Legacy B',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $catalogA = app(CatalogOwnership::class)->defaultCatalogForStore($storeA, 'b2c');

        $legacyUnitId = (int) DB::table('units')->insertGetId([
            'store_id' => $storeB,
            'scope' => 'store',
            'scope_key' => 'store:'.$storeB,
            'code' => 'LEGACY-U',
            'name' => 'Legacy unit',
            'name_ar' => 'وحدة قديمة',
            'name_en' => 'Legacy unit',
            'decimal_places' => 0,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $productId = (int) DB::table('products')->insertGetId([
            'catalog_id' => $catalogA->id,
            'unit_id' => $legacyUnitId,
            'sku' => 'LEGACY-P',
            'name' => 'Legacy Product',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('store_products')->insert([
            'store_id' => $storeA,
            'product_id' => $productId,
            'price' => 1,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($user)->patch('/admin/catalog/products/'.$productId, [
            'sku' => 'LEGACY-P',
            'name' => 'Legacy Product Updated',
            'unit_id' => $legacyUnitId,
            'is_active' => 1,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertDatabaseHas('products', [
            'id' => $productId,
            'name' => 'Legacy Product Updated',
            'unit_id' => $legacyUnitId,
        ]);
    }

    public function test_product_edit_rejects_new_foreign_scope_as_form_validation_instead_of_generic_422(): void
    {
        $user = $this->superAdmin('en');
        $b2cTypeId = (int) DB::table('store_types')->where('code', 'B2C')->value('id');

        $storeA = (int) DB::table('stores')->insertGetId([
            'store_type_id' => $b2cTypeId,
            'code' => 'EDIT-A',
            'name' => 'Edit A',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $storeB = (int) DB::table('stores')->insertGetId([
            'store_type_id' => $b2cTypeId,
            'code' => 'EDIT-B',
            'name' => 'Edit B',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $catalogA = app(CatalogOwnership::class)->defaultCatalogForStore($storeA, 'b2c');
        $catalogB = app(CatalogOwnership::class)->defaultCatalogForStore($storeB, 'b2c');

        $validUnitId = (int) DB::table('units')->where('scope', 'global')->where('is_active', true)->value('id');
        $foreignUnitId = (int) DB::table('units')->insertGetId([
            'store_id' => $storeB,
            'scope' => 'store',
            'scope_key' => 'store:'.$storeB,
            'code' => 'FOREIGN-U',
            'name' => 'Foreign unit',
            'name_ar' => 'وحدة أجنبية',
            'name_en' => 'Foreign unit',
            'decimal_places' => 0,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $foreignCategoryId = (int) DB::table('categories')->insertGetId([
            'catalog_id' => $catalogB->id,
            'name' => 'Foreign category',
            'slug' => 'foreign-category',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $productId = (int) DB::table('products')->insertGetId([
            'catalog_id' => $catalogA->id,
            'unit_id' => $validUnitId,
            'sku' => 'EDIT-P',
            'name' => 'Edit Product',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($user)
            ->from('/admin/catalog?tab=products')
            ->patch('/admin/catalog/products/'.$productId, [
                'sku' => 'EDIT-P',
                'name' => 'Edit Product',
                'unit_id' => $foreignUnitId,
                'category_id' => $foreignCategoryId,
                'is_active' => 1,
            ])
            ->assertRedirect('/admin/catalog?tab=products')
            ->assertSessionHasErrors(['category_id']);

        $this->assertDatabaseHas('products', [
            'id' => $productId,
            'unit_id' => $validUnitId,
            'category_id' => null,
        ]);
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
