<?php

namespace Tests\Feature;

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

    public function test_guest_platform_home_exposes_principal_wholesale_catalog_and_retail_store_banners(): void
    {
        [$wholesaleStore, $retailStore, $wholesaleProduct] = $this->marketplaceFixture();

        $this->getJson('/api/v1/platform/storefront')
            ->assertOk()
            ->assertJsonPath('store.id', $wholesaleStore)
            ->assertJsonPath('store.channel', 'b2b')
            ->assertJsonPath('store.is_platform_principal', true)
            ->assertJsonPath('products.data.0.id', $wholesaleProduct)
            ->assertJsonPath('retail_banners.0.id', $retailStore)
            ->assertJsonPath('retail_banners.0.banner_url', url('/storage/banners/retail-home.jpg'));
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

        $this->postJson('/api/v1/cart/items', [
            'store_id' => $wholesaleStore,
            'product_id' => $wholesaleProduct,
            'quantity' => 1,
        ], $headers)->assertCreated();

        $this->postJson('/api/v1/cart/items', [
            'store_id' => $retailStore,
            'product_id' => $retailProduct,
            'quantity' => 1,
        ], $headers)->assertCreated();

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

        $wholesaleProduct = (int) DB::table('products')->insertGetId([
            'catalog_id' => $wholesaleCatalog,
            'category_id' => null,
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

        DB::table('banners')->insert([
            'store_id' => $retailStore,
            'title' => 'Retail Home Banner',
            'image_path' => 'storage/banners/retail-home.jpg',
            'target_url' => null,
            'sort_order' => 1,
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return [$wholesaleStore, $retailStore, $wholesaleProduct, $retailProduct];
    }
}
