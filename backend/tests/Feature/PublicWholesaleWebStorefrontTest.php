<?php

namespace Tests\Feature;

use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class PublicWholesaleWebStorefrontTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_disabled_public_landing_redirects_to_b2b_management_login(): void
    {
        $storeId = $this->seedWholesaleCatalog(false);

        $this->assertGreaterThan(0, $storeId);
        $this->get('/')->assertRedirect(route('admin.b2b.login'));
        $this->get('/brands')->assertRedirect(route('admin.b2b.login'));
    }

    public function test_enabled_landing_renders_catalog_and_brands_without_price_leakage(): void
    {
        $this->seedWholesaleCatalog(true);

        $response = $this->get('/');

        $response
            ->assertOk()
            ->assertSee('FOODEX Test Chicken')
            ->assertSee('FOODEX Test Brand')
            ->assertSee('السعر داخل التطبيق')
            ->assertDontSee('98765.432')
            ->assertDontSee('EGP 98,765')
            ->assertDontSee('Add to cart');
    }

    public function test_brands_directory_and_product_detail_are_public_read_only_surfaces(): void
    {
        $storeId = $this->seedWholesaleCatalog(true);
        $productId = (int) DB::table('products')
            ->join('catalogs', 'catalogs.id', '=', 'products.catalog_id')
            ->where('catalogs.store_id', $storeId)
            ->where('products.sku', 'WEB-B2B-001')
            ->value('products.id');

        $this->get('/brands')
            ->assertOk()
            ->assertSee('FOODEX Test Brand')
            ->assertSee('عرض المنتجات');

        $this->get('/products/'.$productId)
            ->assertOk()
            ->assertSee('FOODEX Test Chicken')
            ->assertSee('سجّل من التطبيق لمعرفة السعر والشراء')
            ->assertDontSee('98765.432')
            ->assertDontSee('Add to cart');
    }

    public function test_product_detail_rejects_products_outside_active_wholesale_store_scope(): void
    {
        $this->seedWholesaleCatalog(true);

        $retailTypeId = (int) DB::table('store_types')->where('code', 'B2C')->value('id');
        $retailStoreId = (int) DB::table('stores')->insertGetId([
            'store_type_id' => $retailTypeId,
            'code' => 'WEB-RETAIL',
            'name' => 'Retail Store',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $catalogId = (int) DB::table('catalogs')->insertGetId([
            'store_id' => $retailStoreId,
            'channel' => 'b2c',
            'code' => 'WEB-RETAIL-CAT',
            'name' => 'Retail Catalog',
            'is_active' => true,
            'is_migration_quarantine' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $unitId = (int) DB::table('units')->where('code', 'WEB-CASE')->value('id');
        $productId = (int) DB::table('products')->insertGetId([
            'catalog_id' => $catalogId,
            'unit_id' => $unitId,
            'sku' => 'WEB-B2C-001',
            'name' => 'Retail Only Product',
            'description' => 'Must never appear on Wholesale public detail.',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('store_products')->insert([
            'store_id' => $retailStoreId,
            'product_id' => $productId,
            'price' => 12.345,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->get('/products/'.$productId)->assertNotFound();
    }

    private function seedWholesaleCatalog(bool $enabled): int
    {
        $typeId = (int) DB::table('store_types')->where('code', 'B2B')->value('id');
        $storeId = (int) DB::table('stores')->insertGetId([
            'store_type_id' => $typeId,
            'code' => 'WEB-WHOLESALE',
            'name' => 'FOODEX Wholesale Test',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('storefront_settings')->insert([
            'store_id' => $storeId,
            'theme_code' => 'wholesale_b2b',
            'primary_color' => '#00452F',
            'primary_dark_color' => '#003C2A',
            'accent_color' => '#9BE252',
            'background_color' => '#EDF7F1',
            'branding' => json_encode([
                'public_web_enabled' => $enabled,
                'brand_title_ar' => 'فوديكس للجملة',
                'facebook_url' => 'https://www.facebook.com/foodex.test',
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach ([
            ['hero', 'hero', 10],
            ['categories', 'categories', 20],
            ['brands', 'brands', 30],
            ['featured_products', 'featured_products', 40],
            ['social_facebook', 'social', 50],
        ] as [$key, $type, $sort]) {
            DB::table('storefront_sections')->insert([
                'store_id' => $storeId,
                'section_key' => $key,
                'section_type' => $type,
                'title_ar' => $key === 'brands' ? 'العلامات التجارية' : null,
                'title_en' => null,
                'sort_order' => $sort,
                'config' => null,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $catalogId = (int) DB::table('catalogs')->insertGetId([
            'store_id' => $storeId,
            'channel' => 'b2b',
            'code' => 'WEB-WHOLESALE-CAT',
            'name' => 'Wholesale Web Catalog',
            'is_active' => true,
            'is_migration_quarantine' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $unitId = (int) DB::table('units')->insertGetId([
            'code' => 'WEB-CASE',
            'name' => 'Case',
            'decimal_places' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $brandId = (int) DB::table('brands')->insertGetId([
            'name' => 'FOODEX Test Brand',
            'slug' => 'foodex-test-brand',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $categoryId = (int) DB::table('categories')->insertGetId([
            'catalog_id' => $catalogId,
            'name' => 'Frozen Poultry',
            'slug' => 'frozen-poultry-web',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $productId = (int) DB::table('products')->insertGetId([
            'catalog_id' => $catalogId,
            'category_id' => $categoryId,
            'brand_id' => $brandId,
            'unit_id' => $unitId,
            'sku' => 'WEB-B2B-001',
            'name' => 'FOODEX Test Chicken',
            'description' => 'Frozen chicken for wholesale businesses.',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('store_products')->insert([
            'store_id' => $storeId,
            'product_id' => $productId,
            'price' => 98765.432,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $storeId;
    }
}
