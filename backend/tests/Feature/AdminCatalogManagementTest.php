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
            ->assertSee('data-foodex-executive-overview', false);

        $this->actingAs($user)->get('/admin/b2b/dashboard')
            ->assertOk()
            ->assertSee('dir="rtl"', false)
            ->assertSee('.layout{direction:ltr;', false)
            ->assertSee('.sidebar{grid-column:2;grid-row:1;direction:rtl;', false);
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
            'name' => 'مياه',
            'category_id' => $categoryId,
            'unit_id' => $unitId,
            'store_id' => $storeId,
            'price' => 0.500,
            'is_active' => 1,
        ])->assertSessionHasNoErrors();

        $createdProduct = DB::table('products')->where('name', 'مياه')->first(['id', 'sku']);
        $this->assertNotNull($createdProduct);
        $productId = (int) $createdProduct->id;
        $generatedSku = (string) $createdProduct->sku;

        $this->assertMatchesRegularExpression('/^SKU-'.$storeId.'-[A-Z0-9]{10}$/', $generatedSku);
        $this->assertGreaterThan(0, $productId);
        $this->assertDatabaseHas('store_products', [
            'store_id' => $storeId,
            'product_id' => $productId,
        ]);

        $this->actingAs($user)->patch('/admin/catalog/products/'.$productId.'/price', [
            'price' => 0.750,
            'support_access' => 1,
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseHas('store_products', [
            'store_id' => $storeId,
            'product_id' => $productId,
            'price' => 0.750,
        ]);

        $this->actingAs($user)
            ->get('/admin/catalog?tab=products&store_id='.$storeId.'&support_access=1&product_sort=price&product_dir=desc')
            ->assertOk()
            ->assertSee('catalog-inline-price-input', false)
            ->assertSee('catalog-sort-link', false)
            ->assertSee('✎');

        $this->actingAs($user)
            ->get('/admin/catalog?tab=categories&store_id='.$storeId.'&support_access=1&category_sort=slug&category_dir=desc')
            ->assertOk()
            ->assertSee('catalog-sort-link', false);

        $catalogId = (int) DB::table('catalogs')->where('store_id', $storeId)->where('code', 'default')->value('id');
        $this->assertDatabaseHas('products', ['id' => $productId, 'catalog_id' => $catalogId]);
        $this->assertDatabaseHas('categories', ['id' => $categoryId, 'catalog_id' => $catalogId]);

        $this->actingAs($user)->patch('/admin/catalog/products/'.$productId.'/status', [
            'is_active' => 0,
        ])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('products', [
            'id' => $productId,
            'is_active' => 0,
            'lifecycle_state' => 'paused',
        ]);

        $this->actingAs($user)->patch('/admin/catalog/products/'.$productId.'/status', [
            'is_active' => 1,
        ])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('products', [
            'id' => $productId,
            'is_active' => 1,
            'lifecycle_state' => 'active',
        ]);

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

        $fullProductName = 'مياه معدنية طبيعية عبوة عائلية كبيرة للاستخدام اليومي';
        $this->actingAs($user)->patch('/admin/catalog/products/'.$productId, [
            'sku' => 'IGNORED-CLIENT-SKU',
            'name' => $fullProductName,
            'category_id' => $categoryId,
            'unit_id' => $unitId,
            'is_active' => 1,
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('products', [
            'id' => $productId,
            'sku' => $generatedSku,
            'name' => $fullProductName,
        ]);

        $this->actingAs($user)
            ->get('/admin/catalog?tab=products&store_id='.$storeId.'&support_access=1')
            ->assertOk()
            ->assertSee($fullProductName)
            ->assertSee('catalog-status-toggle', false);

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

    public function test_product_edit_preserves_inactive_brand_and_unit_without_offering_them_as_new_choices(): void
    {
        $user = $this->superAdmin('en');
        $b2cTypeId = (int) DB::table('store_types')->where('code', 'B2C')->value('id');
        $storeId = (int) DB::table('stores')->insertGetId([
            'store_type_id' => $b2cTypeId,
            'code' => 'INACTIVE-LOOKUPS',
            'name' => 'Inactive Lookup Store',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $catalog = app(CatalogOwnership::class)->defaultCatalogForStore($storeId, 'b2c');

        $unitId = (int) DB::table('units')->insertGetId([
            'store_id' => $storeId,
            'scope' => 'store',
            'scope_key' => 'store:'.$storeId,
            'code' => 'INACTIVE-U',
            'name' => 'Inactive unit',
            'name_ar' => 'وحدة غير نشطة',
            'name_en' => 'Inactive unit',
            'decimal_places' => 0,
            'is_active' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $brandId = (int) DB::table('brands')->insertGetId([
            'store_id' => $storeId,
            'scope' => 'store',
            'scope_key' => 'store:'.$storeId,
            'slug' => 'inactive-brand-'.$storeId,
            'name' => 'Inactive brand',
            'name_ar' => 'علامة غير نشطة',
            'name_en' => 'Inactive brand',
            'is_active' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $productId = (int) DB::table('products')->insertGetId([
            'catalog_id' => $catalog->id,
            'brand_id' => $brandId,
            'unit_id' => $unitId,
            'sku' => 'INACTIVE-LOOKUP-P',
            'name' => 'Inactive Lookup Product',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('store_products')->insert([
            'store_id' => $storeId,
            'product_id' => $productId,
            'price' => 1,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->actingAs($user)
            ->get('/admin/catalog?tab=products&store_id='.$storeId.'&support_access=1');

        $response->assertOk()
            ->assertSee('value="'.$brandId.'" selected data-current-lookup-option data-lookup-state="inactive"', false)
            ->assertSee('value="'.$unitId.'" selected data-current-lookup-option data-lookup-state="inactive"', false)
            ->assertSee('Inactive brand')
            ->assertSee('Inactive unit')
            ->assertViewHas('brands', fn ($brands): bool => ! $brands->contains('id', $brandId))
            ->assertViewHas('units', fn ($units): bool => ! $units->contains('id', $unitId));

        $this->actingAs($user)->patch('/admin/catalog/products/'.$productId, [
            'name' => 'Inactive Lookup Product Renamed',
            'brand_id' => $brandId,
            'unit_id' => $unitId,
            'is_active' => 1,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertDatabaseHas('products', [
            'id' => $productId,
            'name' => 'Inactive Lookup Product Renamed',
            'brand_id' => $brandId,
            'unit_id' => $unitId,
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

    public function test_catalog_lists_apply_server_filters_and_preserve_store_scope_on_reset(): void
    {
        $user = $this->superAdmin('en');
        $b2cTypeId = (int) DB::table('store_types')->where('code', 'B2C')->value('id');
        $storeId = (int) DB::table('stores')->insertGetId([
            'store_type_id' => $b2cTypeId,
            'code' => 'FILTER-CATALOG',
            'name' => 'Filter Catalog Store',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $catalog = app(CatalogOwnership::class)->defaultCatalogForStore($storeId, 'b2c');
        $unitId = (int) DB::table('units')->where('scope', 'global')->where('is_active', true)->value('id');

        $brandId = (int) DB::table('brands')->insertGetId([
            'store_id' => $storeId,
            'scope' => 'store',
            'scope_key' => 'store:'.$storeId,
            'slug' => 'filter-brand-'.$storeId,
            'name' => 'Filter Brand',
            'name_ar' => 'علامة الفلترة',
            'name_en' => 'Filter Brand',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $parentCategoryId = (int) DB::table('categories')->insertGetId([
            'catalog_id' => $catalog->id,
            'name' => 'Beverages Parent',
            'slug' => 'beverages-parent-'.$storeId,
            'parent_id' => null,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $childCategoryId = (int) DB::table('categories')->insertGetId([
            'catalog_id' => $catalog->id,
            'name' => 'Cold Drinks Child',
            'slug' => 'cold-drinks-child-'.$storeId,
            'parent_id' => $parentCategoryId,
            'is_active' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $availableProductId = (int) DB::table('products')->insertGetId([
            'catalog_id' => $catalog->id,
            'category_id' => $childCategoryId,
            'brand_id' => $brandId,
            'unit_id' => $unitId,
            'sku' => 'FILTER-ALPHA-SKU',
            'name' => 'Filter Alpha Product',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $outOfStockProductId = (int) DB::table('products')->insertGetId([
            'catalog_id' => $catalog->id,
            'category_id' => $parentCategoryId,
            'brand_id' => $brandId,
            'unit_id' => $unitId,
            'sku' => 'FILTER-BETA-SKU',
            'name' => 'Filter Beta Product',
            'is_active' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        foreach ([$availableProductId, $outOfStockProductId] as $productId) {
            DB::table('store_products')->insert([
                'store_id' => $storeId,
                'product_id' => $productId,
                'price' => 1,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
        $warehouseId = (int) DB::table('warehouses')->insertGetId([
            'store_id' => $storeId,
            'code' => 'FILTER-WH',
            'name' => 'Filter Warehouse',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('inventories')->insert([
            [
                'warehouse_id' => $warehouseId,
                'product_id' => $availableProductId,
                'quantity' => 5,
                'reserved_quantity' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'warehouse_id' => $warehouseId,
                'product_id' => $outOfStockProductId,
                'quantity' => 1,
                'reserved_quantity' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $base = '/admin/catalog?tab=products&store_id='.$storeId.'&support_access=1';
        $resetProducts = e(route('admin.catalog.index', [
            'tab' => 'products',
            'store_id' => $storeId,
            'support_access' => 1,
        ]));

        $this->actingAs($user)
            ->get($base.'&product_q=FILTER-ALPHA-SKU')
            ->assertOk()
            ->assertViewHas('products', fn ($products): bool => $products->total() === 1)
            ->assertSee('Filter Alpha Product')
            ->assertDontSee('Filter Beta Product')
            ->assertSee('data-catalog-product-filters', false)
            ->assertSee('data-catalog-filter-reset href="'.$resetProducts.'"', false);

        $this->actingAs($user)
            ->get($base.'&product_category_id='.$childCategoryId.'&product_brand_id='.$brandId.'&product_status=active&product_availability=available')
            ->assertOk()
            ->assertViewHas('products', fn ($products): bool => $products->total() === 1)
            ->assertSee('Filter Alpha Product')
            ->assertDontSee('Filter Beta Product');

        $this->actingAs($user)
            ->get($base.'&product_status=inactive&product_availability=out_of_stock')
            ->assertOk()
            ->assertViewHas('products', fn ($products): bool => $products->total() === 1)
            ->assertSee('Filter Beta Product')
            ->assertDontSee('Filter Alpha Product');

        $resetCategories = e(route('admin.catalog.index', [
            'tab' => 'categories',
            'store_id' => $storeId,
            'support_access' => 1,
        ]));

        $this->actingAs($user)
            ->get('/admin/catalog?tab=categories&store_id='.$storeId.'&support_access=1&category_q=Cold&category_parent='.$parentCategoryId.'&category_status=inactive')
            ->assertOk()
            ->assertViewHas('categoryRows', fn ($rows): bool => $rows->total() === 1
                && (int) $rows->items()[0]->id === $childCategoryId)
            ->assertSee('Cold Drinks Child')
            ->assertSee('data-catalog-category-filters', false)
            ->assertSee('data-catalog-filter-reset href="'.$resetCategories.'"', false);
    }

    public function test_catalog_grid_inline_updates_stock_threshold_visibility_and_core_fields(): void
    {
        $user = $this->superAdmin('en');
        $typeId = (int) DB::table('store_types')->where('code', 'B2C')->value('id');
        $storeId = (int) DB::table('stores')->insertGetId([
            'store_type_id' => $typeId,
            'code' => 'INLINE-CATALOG',
            'name' => 'Inline Catalog Store',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $catalog = app(CatalogOwnership::class)->defaultCatalogForStore($storeId, 'b2c');
        $unitId = (int) DB::table('units')->where('scope', 'global')->where('is_active', true)->value('id');
        $productId = (int) DB::table('products')->insertGetId([
            'catalog_id' => $catalog->id,
            'unit_id' => $unitId,
            'sku' => 'INLINE-P',
            'name' => 'Inline Product',
            'low_stock_threshold' => 1,
            'is_active' => true,
            'lifecycle_state' => 'active',
            'lifecycle_effective_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('store_products')->insert([
            'store_id' => $storeId,
            'product_id' => $productId,
            'price' => 2.5,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $warehouseId = (int) DB::table('warehouses')->insertGetId([
            'store_id' => $storeId,
            'code' => 'INLINE-WH',
            'name' => 'Inline Warehouse',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $inline = '/admin/catalog/products/'.$productId.'/inline';

        $this->actingAs($user)->patch($inline, [
            'field' => 'name',
            'value' => 'Inline Product Renamed',
            'support_access' => 1,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->actingAs($user)->patch($inline, [
            'field' => 'low_stock_threshold',
            'value' => 4.5,
            'support_access' => 1,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->actingAs($user)->patch($inline, [
            'field' => 'stock_quantity',
            'warehouse_id' => $warehouseId,
            'value' => 12.25,
            'support_access' => 1,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->actingAs($user)->patch($inline, [
            'field' => 'price',
            'value' => 3.75,
            'support_access' => 1,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->actingAs($user)->patch($inline, [
            'field' => 'app_visible',
            'value' => 0,
            'support_access' => 1,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertDatabaseHas('products', [
            'id' => $productId,
            'name' => 'Inline Product Renamed',
            'low_stock_threshold' => 4.5,
        ]);
        $this->assertDatabaseHas('inventories', [
            'warehouse_id' => $warehouseId,
            'product_id' => $productId,
            'quantity' => 12.25,
        ]);
        $this->assertDatabaseHas('store_products', [
            'store_id' => $storeId,
            'product_id' => $productId,
            'price' => 3.75,
            'is_active' => 0,
        ]);
        $this->assertDatabaseHas('audit_logs', ['event' => 'catalog.product.inline_updated']);

        $this->actingAs($user)
            ->get('/admin/catalog?tab=products&store_id='.$storeId.'&support_access=1')
            ->assertOk()
            ->assertSee('Show in app')
            ->assertSee('Low stock')
            ->assertSee('data-inline-stock-form', false)
            ->assertSee('name="field" value="app_visible"', false)
            ->assertSee('Inline Product Renamed');
    }

    public function test_wholesale_catalog_inline_stock_uses_principal_warehouse_only(): void
    {
        $user = $this->superAdmin('en');
        $principal = app(WholesalePrincipal::class);
        $storeId = $principal->storeId();
        $warehouseId = $principal->warehouseId();
        $catalog = app(CatalogOwnership::class)->defaultCatalogForStore($storeId, 'b2b');
        $unitId = (int) DB::table('units')->where('scope', 'global')->where('is_active', true)->value('id');
        $productId = (int) DB::table('products')->insertGetId([
            'catalog_id' => $catalog->id,
            'unit_id' => $unitId,
            'sku' => 'INLINE-B2B-P',
            'name' => 'Inline Wholesale Product',
            'low_stock_threshold' => 0,
            'is_active' => true,
            'lifecycle_state' => 'active',
            'lifecycle_effective_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('store_products')->insert([
            'store_id' => $storeId,
            'product_id' => $productId,
            'price' => 10,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($user)->patch('/admin/catalog/products/'.$productId.'/inline', [
            'field' => 'stock_quantity',
            'warehouse_id' => $warehouseId,
            'value' => 33,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertDatabaseHas('inventories', [
            'warehouse_id' => $warehouseId,
            'product_id' => $productId,
            'quantity' => 33,
        ]);

        $otherWarehouseId = (int) DB::table('warehouses')->insertGetId([
            'store_id' => $storeId,
            'code' => 'INLINE-B2B-OTHER',
            'name' => 'Other Wholesale Warehouse',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($user)
            ->from('/admin/catalog?tab=products')
            ->patch('/admin/catalog/products/'.$productId.'/inline', [
                'field' => 'stock_quantity',
                'warehouse_id' => $otherWarehouseId,
                'value' => 44,
            ])
            ->assertRedirect('/admin/catalog?tab=products')
            ->assertSessionHasErrors(['warehouse_id']);

        $this->assertDatabaseMissing('inventories', [
            'warehouse_id' => $otherWarehouseId,
            'product_id' => $productId,
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
