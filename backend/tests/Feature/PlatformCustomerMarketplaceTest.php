<?php

namespace Tests\Feature;

use App\Exceptions\SelfStorePurchaseNotAllowed;
use App\Models\User;
use App\Services\CustomerDomainResolver;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PlatformCustomerMarketplaceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
        config(['foodex.platform_wholesale_store_code' => 'MAIN-B2B']);
    }

    public function test_guest_platform_home_exposes_platform_retail_placements_without_leaking_store_internal_banners(): void
    {
        [$wholesaleStore, $retailStore, $wholesaleProduct, $retailProduct] = $this->marketplaceFixture();

        $retailWarehouse = (int) DB::table('warehouses')->insertGetId([
            'store_id' => $retailStore,
            'code' => 'RETAIL-OOS-WH',
            'name' => 'Retail Out Of Stock Warehouse',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('inventories')->insert([
            'warehouse_id' => $retailWarehouse,
            'product_id' => $retailProduct,
            'quantity' => 4,
            'reserved_quantity' => 4,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $wholesaleCategory = (int) DB::table('products')
            ->where('id', $wholesaleProduct)
            ->value('category_id');

        DB::table('categories')->where('id', $wholesaleCategory)->update([
            'image_path' => 'storage/categories/platform-category.webp',
        ]);
        $brandId = (int) DB::table('brands')->insertGetId([
            'store_id' => null,
            'scope' => 'global',
            'scope_key' => 'global',
            'name' => 'Platform Brand',
            'name_ar' => 'علامة المنصة',
            'name_en' => 'Platform Brand',
            'slug' => 'platform-brand',
            'image_path' => 'storage/brands/platform-brand.webp',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('products')->where('id', $wholesaleProduct)->update([
            'brand_id' => $brandId,
        ]);

        $this->getJson('/api/v1/platform/storefront')
            ->assertOk()
            ->assertJsonPath('store.id', $wholesaleStore)
            ->assertJsonPath('store.channel', 'b2b')
            ->assertJsonPath('store.is_platform_principal', true)
            ->assertJsonPath('products.data.0.id', $wholesaleProduct)
            ->assertJsonPath('categories.0.id', $wholesaleCategory)
            ->assertJsonPath('categories.0.slug', 'PLATFORM-CAT')
            ->assertJsonPath('categories.0.image_url', url('/storage/categories/platform-category.webp'))
            ->assertJsonPath('brands.0.id', $brandId)
            ->assertJsonPath('brands.0.image_url', url('/storage/brands/platform-brand.webp'))
            ->assertJsonPath('products.data.0.brand_id', $brandId)
            ->assertJsonPath('products.data.0.brand_image_url', url('/storage/brands/platform-brand.webp'))
            ->assertJsonPath('offers.0.name', 'Wholesale Launch Offer')
            ->assertJsonPath('offers.0.value', 2.5)
            ->assertJsonCount(1, 'offers')
            ->assertJsonMissing(['name' => 'Expired Wholesale Offer'])
            ->assertJsonPath('hero.title', 'Platform Wholesale Hero')
            ->assertJsonCount(1, 'banners')
            ->assertJsonPath('retail_banners.0.id', $retailStore)
            ->assertJsonPath('retail_banners.0.store_id', $retailStore)
            ->assertJsonPath('retail_banners.0.placement_scope', 'platform_retail_store')
            ->assertJsonPath('retail_banners.0.target_type', 'retail_store')
            ->assertJsonPath('retail_banners.0.target_id', $retailStore)
            ->assertJsonPath('retail_banners.0.target_url', '/retail/'.$retailStore.'/home')
            ->assertJsonPath('retail_banners.0.banner_url', url('/storage/banners/platform-retail.jpg'))
            ->assertJsonPath('retail_banners.0.sort_order', 1)
            ->assertJsonCount(1, 'retail_banners')
            ->assertJsonMissing(['title' => 'Retail Home Banner'])
            ->assertJsonMissing(['title' => 'Retail Second Banner']);

        $this->getJson('/api/v1/stores/'.$retailStore.'/storefront')
            ->assertOk()
            ->assertJsonPath('banners.0.title', 'Retail Home Banner')
            ->assertJsonPath('banners.1.title', 'Retail Second Banner')
            ->assertJsonCount(2, 'banners')
            ->assertJsonMissing(['title' => 'Platform Retail Placement']);

        $this->getJson('/api/v1/stores/'.$retailStore.'/products')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $retailProduct)
            ->assertJsonPath('data.0.available_quantity', 0)
            ->assertJsonPath('data.0.is_available', false)
            ->assertJsonPath('data.0.availability_state', 'OUT_OF_STOCK');

        $this->getJson('/api/v1/products/'.$retailProduct.'?store='.$retailStore)
            ->assertOk()
            ->assertJsonPath('available_quantity', 0)
            ->assertJsonPath('is_available', false)
            ->assertJsonPath('availability_state', 'OUT_OF_STOCK');

        DB::table('inventories')
            ->where('warehouse_id', $retailWarehouse)
            ->where('product_id', $retailProduct)
            ->update(['reserved_quantity' => 3, 'updated_at' => now()]);

        $this->getJson('/api/v1/stores/'.$retailStore.'/products')
            ->assertOk()
            ->assertJsonPath('data.0.available_quantity', 1)
            ->assertJsonPath('data.0.is_available', true)
            ->assertJsonPath('data.0.availability_state', 'AVAILABLE');
    }

    public function test_retail_merchant_own_store_is_hidden_and_direct_browse_is_forbidden(): void
    {
        [$wholesaleStore, $ownedStore] = $this->marketplaceFixture();
        $b2cType = (int) DB::table('store_types')->where('code', 'B2C')->value('id');
        $now = now();

        $foreignStore = (int) DB::table('stores')->insertGetId([
            'store_type_id' => $b2cType,
            'code' => 'RETAIL-FOREIGN',
            'name' => 'Retail Foreign',
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('banners')->insert([
            'store_id' => $wholesaleStore,
            'title' => 'Foreign Retail Placement',
            'image_path' => 'storage/banners/platform-retail-foreign.jpg',
            'target_type' => 'retail_store',
            'target_id' => $foreignStore,
            'target_url' => '/retail/'.$foreignStore.'/home',
            'sort_order' => 2,
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $merchant = User::query()->create([
            'name' => 'Retail Merchant',
            'email' => 'retail-merchant@example.test',
            'password' => 'password123',
            'locale' => 'en',
            'is_active' => true,
        ]);
        $managerRoleId = (int) DB::table('roles')
            ->where('code', 'B2C_STORE_ADMIN')
            ->where('is_active', true)
            ->value('id');

        DB::table('user_store_roles')->insert([
            'user_id' => $merchant->id,
            'store_id' => $ownedStore,
            'role_id' => $managerRoleId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $this->withToken($merchant->createToken('customer-app')->plainTextToken);

        $this->getJson('/api/v1/platform/storefront')
            ->assertOk()
            ->assertJsonCount(1, 'retail_banners')
            ->assertJsonPath('retail_banners.0.store_id', $foreignStore)
            ->assertJsonMissing(['store_id' => $ownedStore]);

        $this->getJson('/api/v1/store-selector')
            ->assertOk()
            ->assertJsonMissing(['id' => $ownedStore])
            ->assertJsonPath('retail_stores.0.id', $foreignStore);

        $this->getJson('/api/v1/stores')
            ->assertOk()
            ->assertJsonMissing(['id' => $ownedStore])
            ->assertJsonPath('data.0.id', $foreignStore);

        foreach ([
            '/api/v1/stores/'.$ownedStore.'/storefront',
            '/api/v1/stores/'.$ownedStore.'/products',
        ] as $url) {
            $this->getJson($url)
                ->assertForbidden()
                ->assertJsonPath('code', SelfStorePurchaseNotAllowed::ERROR_CODE)
                ->assertJsonPath('store_id', $ownedStore);
        }
    }

    public function test_store_selector_exposes_only_the_authoritative_principal_wholesale_target(): void
    {
        [$wholesaleStore] = $this->marketplaceFixture();
        $now = now();

        $tier = (int) DB::table('b2b_price_tiers')->insertGetId([
            'code' => 'SWITCH-PRINCIPAL',
            'name' => 'Switch Principal',
            'priority' => 10,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $user = User::query()->create([
            'name' => 'Wholesale Switch Buyer',
            'email' => 'wholesale-switch@example.test',
            'password' => 'password123',
            'locale' => 'en',
            'is_active' => true,
        ]);
        $legacyCustomerId = (int) DB::table('customers')->insertGetId([
            'user_id' => $user->id,
            'type' => 'b2b',
            'name' => 'Wholesale Switch Buyer',
            'email' => $user->email,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $b2bCustomerId = (int) DB::table('b2b_customers')->insertGetId([
            'legacy_customer_id' => $legacyCustomerId,
            'user_id' => $user->id,
            'name' => 'Wholesale Switch Buyer',
            'email' => $user->email,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('b2b_accounts')->insert([
            'customer_id' => $legacyCustomerId,
            'b2b_customer_id' => $b2bCustomerId,
            'price_tier_id' => $tier,
            'company_name' => 'Wholesale Switch Buyer',
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $this->withToken($user->createToken('customer-app')->plainTextToken)
            ->getJson('/api/v1/store-selector')
            ->assertOk()
            ->assertJsonCount(1, 'wholesale_stores')
            ->assertJsonPath('wholesale_stores.0.id', $wholesaleStore)
            ->assertJsonPath('wholesale_stores.0.is_platform_principal', true)
            ->assertJsonPath('entitlements.direct_b2b', true)
            ->assertJsonPath('entitlements.principal_wholesale_store_id', $wholesaleStore);
    }

    public function test_registered_customer_identity_materializes_per_store_and_routes_carts_by_purchase_store(): void
    {
        [$wholesaleStore, $retailStore, $wholesaleProduct, $retailProduct] = $this->marketplaceFixture();

        $registration = $this->postJson('/api/v1/auth/register', [
            'name' => 'Platform Shopper',
            'email' => 'shopper@example.test',
            'phone' => '+201000000001',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
            'locale' => 'ar',
        ])->assertCreated()
            ->assertJsonPath('platform_customer', true);

        $token = (string) $registration->json('token');
        $user = User::query()->where('email', 'shopper@example.test')->firstOrFail();
        $platform = DB::table('platform_customers')->where('user_id', $user->id)->first();
        $this->assertNotNull($platform);
        $this->assertDatabaseHas('customers', [
            'id' => $platform->legacy_customer_id,
            'user_id' => $user->id,
            'type' => 'platform',
        ]);

        $resolver = app(CustomerDomainResolver::class);
        [$b2b, $b2bChannel] = $resolver->forStore($user, $wholesaleStore);
        [$b2c, $b2cChannel] = $resolver->forStore($user, $retailStore);

        $this->assertSame('b2b', $b2bChannel);
        $this->assertSame('b2c', $b2cChannel);
        $this->assertSame((int) $platform->legacy_customer_id, (int) $b2b->legacy_customer_id);
        $this->assertSame((int) $platform->legacy_customer_id, (int) $b2c->legacy_customer_id);
        $this->assertSame($retailStore, (int) $b2c->store_id);

        $headers = ['Authorization' => 'Bearer '.$token];
        $b2bHeaders = [
            ...$headers,
            'X-FOODEX-Customer-Domain' => 'b2b',
            'X-FOODEX-Store-ID' => (string) $wholesaleStore,
        ];

        $this->postJson('/api/v1/profile/favorites/'.$wholesaleProduct, [], $b2bHeaders)
            ->assertCreated()
            ->assertJsonPath('product.id', $wholesaleProduct)
            ->assertJsonPath('product.channel', 'b2b')
            ->assertJsonPath('product.store_id', $wholesaleStore);

        $this->getJson('/api/v1/profile/favorites', $b2bHeaders)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $wholesaleProduct)
            ->assertJsonPath('data.0.channel', 'b2b');

        $this->deleteJson('/api/v1/profile/favorites/'.$wholesaleProduct, [], $b2bHeaders)
            ->assertNoContent();

        $this->postJson('/api/v1/cart/items', [
            'store_id' => $wholesaleStore,
            'product_id' => $wholesaleProduct,
            'quantity' => 1,
            // Deliberately forged persona/channel hints must never route the cart.
            'channel' => 'b2c',
            'customer_type' => 'retail',
            'customerType' => 'retail',
        ], $headers)
            ->assertCreated()
            ->assertJsonPath('store_id', $wholesaleStore)
            ->assertJsonPath('channel', 'b2b');

        $this->postJson('/api/v1/cart/items', [
            'store_id' => $retailStore,
            'product_id' => $retailProduct,
            'quantity' => 1,
            // The store/catalog domain remains authoritative regardless of client persona.
            'channel' => 'b2b',
            'customer_type' => 'business',
            'customerType' => 'business',
        ], $headers)
            ->assertCreated()
            ->assertJsonPath('store_id', $retailStore)
            ->assertJsonPath('channel', 'b2c');

        $this->assertDatabaseHas('carts', [
            'store_id' => $wholesaleStore,
            'channel' => 'b2b',
            'b2b_customer_id' => $b2b->id,
        ]);
        $this->assertDatabaseHas('carts', [
            'store_id' => $retailStore,
            'channel' => 'b2c',
            'b2c_customer_id' => $b2c->id,
        ]);
    }

    /** @return array{0:int,1:int,2:int,3:int} */
    private function marketplaceFixture(): array
    {
        $b2bType = (int) DB::table('store_types')->where('code', 'B2B')->value('id');
        $b2cType = (int) DB::table('store_types')->where('code', 'B2C')->value('id');
        $unitId = (int) DB::table('units')->where('code', 'PC')->value('id');
        $now = now();

        $wholesaleStore = (int) DB::table('stores')->insertGetId([
            'store_type_id' => $b2bType,
            'code' => 'MAIN-B2B',
            'name' => 'FOODEX Main Wholesale',
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $retailStore = (int) DB::table('stores')->insertGetId([
            'store_type_id' => $b2cType,
            'code' => 'RETAIL-01',
            'name' => 'Retail One',
            'logo_path' => 'storage/stores/retail-one.png',
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $wholesaleCatalog = (int) DB::table('catalogs')->insertGetId([
            'store_id' => $wholesaleStore,
            'channel' => 'b2b',
            'code' => 'default',
            'name' => 'Wholesale Catalog',
            'is_active' => true,
            'is_migration_quarantine' => false,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $retailCatalog = (int) DB::table('catalogs')->insertGetId([
            'store_id' => $retailStore,
            'channel' => 'b2c',
            'code' => 'default',
            'name' => 'Retail Catalog',
            'is_active' => true,
            'is_migration_quarantine' => false,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $wholesaleCategory = (int) DB::table('categories')->insertGetId([
            'catalog_id' => $wholesaleCatalog,
            'parent_id' => null,
            'name' => 'Wholesale Category',
            'slug' => 'PLATFORM-CAT',
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $wholesaleProduct = (int) DB::table('products')->insertGetId([
            'catalog_id' => $wholesaleCatalog,
            'category_id' => $wholesaleCategory,
            'brand_id' => null,
            'unit_id' => $unitId,
            'sku' => 'W-PLATFORM-1',
            'name' => 'Wholesale Product',
            'description' => 'Public Wholesale product',
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $retailProduct = (int) DB::table('products')->insertGetId([
            'catalog_id' => $retailCatalog,
            'category_id' => null,
            'brand_id' => null,
            'unit_id' => $unitId,
            'sku' => 'R-PLATFORM-1',
            'name' => 'Retail Product',
            'description' => 'Retail product',
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('store_products')->insert([
            [
                'store_id' => $wholesaleStore,
                'product_id' => $wholesaleProduct,
                'price' => 12.500,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'store_id' => $retailStore,
                'product_id' => $retailProduct,
                'price' => 15.000,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);

        DB::table('promotions')->insert([
            [
                'store_id' => $wholesaleStore,
                'name' => 'Wholesale Launch Offer',
                'type' => 'fixed',
                'value' => 2.500,
                'starts_at' => $now->copy()->subHour(),
                'ends_at' => $now->copy()->addHour(),
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'store_id' => $wholesaleStore,
                'name' => 'Expired Wholesale Offer',
                'type' => 'fixed',
                'value' => 1.000,
                'starts_at' => $now->copy()->subDays(2),
                'ends_at' => $now->copy()->subDay(),
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);

        DB::table('banners')->insert([
            [
                'store_id' => $wholesaleStore,
                'title' => 'Platform Wholesale Hero',
                'image_path' => 'storage/banners/platform-wholesale.jpg',
                'target_type' => null,
                'target_id' => null,
                'target_url' => null,
                'sort_order' => 0,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'store_id' => $wholesaleStore,
                'title' => 'Platform Retail Placement',
                'image_path' => 'storage/banners/platform-retail.jpg',
                'target_type' => 'retail_store',
                'target_id' => $retailStore,
                'target_url' => '/retail/'.$retailStore.'/home',
                'sort_order' => 1,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'store_id' => $retailStore,
                'title' => 'Retail Home Banner',
                'image_path' => 'storage/banners/retail-home.jpg',
                'target_type' => null,
                'target_id' => null,
                'target_url' => null,
                'sort_order' => 1,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'store_id' => $retailStore,
                'title' => 'Retail Second Banner',
                'image_path' => 'storage/banners/retail-second.jpg',
                'target_type' => null,
                'target_id' => null,
                'target_url' => null,
                'sort_order' => 2,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'store_id' => $retailStore,
                'title' => 'Retail Inactive Banner',
                'image_path' => 'storage/banners/retail-inactive.jpg',
                'target_type' => null,
                'target_id' => null,
                'target_url' => null,
                'sort_order' => 0,
                'is_active' => false,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);

        return [$wholesaleStore, $retailStore, $wholesaleProduct, $retailProduct];
    }
}
