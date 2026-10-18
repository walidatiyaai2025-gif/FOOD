<?php

namespace Tests\Feature;

use App\Domain\RestaurantSaaS\RestaurantSaasStoreFeatureService;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use App\Services\RetailWholesaleAccountService;
use App\Services\WholesalePrincipal;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class RestaurantWholesalePurchasesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_restaurant_operator_gets_isolated_buyer_workbench_and_cross_store_access_fails_closed(): void
    {
        $restaurant = $this->store('B2C', 'REST-PROC-A');
        $operator = $this->storeAdmin($restaurant, 'restaurant-procurement@example.test');
        $this->enableRestaurant($restaurant);
        $this->linkWholesaleBuyer($restaurant, $operator);

        $this->actingAs($operator)
            ->get(route('admin.restaurant.wholesale-purchases.index', [
                'retail_store_id' => $restaurant->id,
            ]))
            ->assertOk()
            ->assertSee('data-restaurant-wholesale-purchases', false)
            ->assertSee('Wholesale Purchases')
            ->assertSee('Customer Orders')
            ->assertSee('Purchase Orders')
            ->assertDontSee('Pricing administration')
            ->assertDontSee('B2B Admin');

        $retailOnly = $this->store('B2C', 'RETAIL-NOT-RESTAURANT');
        $this->assignStoreAdmin($operator, $retailOnly);
        $this->linkWholesaleBuyer($retailOnly, $operator);

        $this->actingAs($operator)
            ->get(route('admin.restaurant.wholesale-purchases.index', [
                'retail_store_id' => $retailOnly->id,
            ]))
            ->assertNotFound();

        $foreignOperator = $this->storeAdmin($this->store('B2C', 'REST-FOREIGN'), 'foreign-operator@example.test');
        $this->actingAs($foreignOperator)
            ->get(route('admin.restaurant.wholesale-purchases.index', [
                'retail_store_id' => $restaurant->id,
            ]))
            ->assertNotFound();
    }

    public function test_purchase_cart_reuses_canonical_b2b_cart_and_exact_linked_buyer_account(): void
    {
        $restaurant = $this->store('B2C', 'REST-PROC-CART');
        $operator = $this->storeAdmin($restaurant, 'restaurant-cart@example.test');
        $this->enableRestaurant($restaurant);
        $customerId = $this->linkWholesaleBuyer($restaurant, $operator);

        $wholesaleStoreId = app(WholesalePrincipal::class)->storeId();
        $productId = $this->wholesaleProduct($wholesaleStoreId, 'REST-WHOLESALE-SKU');

        $tierId = (int) DB::table('b2b_accounts')
            ->where('b2b_customer_id', $customerId)
            ->value('price_tier_id');

        DB::table('b2b_price_rules')->insert([
            'price_tier_id' => $tierId,
            'store_id' => $wholesaleStoreId,
            'product_id' => $productId,
            'unit_price' => 12.500,
            'minimum_quantity' => 2,
            'ordering_increment' => 2,
            'pack_size' => 1,
            'case_size' => 2,
            'pack_label' => 'Case',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($operator)
            ->post(route('admin.restaurant.wholesale-purchases.cart.add'), [
                'retail_store_id' => $restaurant->id,
                'product_id' => $productId,
                'quantity' => 2,
            ])
            ->assertRedirect(route('admin.restaurant.wholesale-purchases.index', [
                'retail_store_id' => $restaurant->id,
                'tab' => 'cart',
            ]));

        $cart = DB::table('carts')
            ->where('store_id', $wholesaleStoreId)
            ->where('b2b_customer_id', $customerId)
            ->where('channel', 'b2b')
            ->first();

        $this->assertNotNull($cart);
        $this->assertNull($cart->b2c_customer_id);
        $this->assertDatabaseHas('cart_items', [
            'cart_id' => $cart->id,
            'product_id' => $productId,
            'quantity' => 2,
        ]);

        $this->actingAs($operator)
            ->get(route('admin.restaurant.wholesale-purchases.index', [
                'retail_store_id' => $restaurant->id,
                'tab' => 'cart',
            ]))
            ->assertOk()
            ->assertSee('REST-WHOLESALE-SKU')
            ->assertSee('Purchase Cart');
    }

    private function linkWholesaleBuyer(Store $store, User $owner): int
    {
        $tierId = (int) DB::table('b2b_price_tiers')
            ->where('code', 'STANDARD')
            ->value('id');

        $customer = app(RetailWholesaleAccountService::class)
            ->ensureForStore($store, $tierId, $owner);

        return (int) $customer->getKey();
    }

    private function enableRestaurant(Store $store): void
    {
        $actor = User::query()->create([
            'name' => 'restaurant-saas-owner-'.$store->id,
            'email' => 'restaurant-saas-owner-'.$store->id.'@example.test',
            'password' => 'password',
            'locale' => 'en',
            'is_active' => true,
        ]);
        $actor->roles()->attach(Role::query()->where('code', 'SUPER_ADMIN')->firstOrFail());

        app(RestaurantSaasStoreFeatureService::class)->setEnabled($store, true, $actor);

        if (Schema::hasColumn('stores', 'business_profile')) {
            DB::table('stores')->where('id', $store->id)->update([
                'business_profile' => 'RESTAURANT',
                'updated_at' => now(),
            ]);
        }
    }

    private function wholesaleProduct(int $storeId, string $sku): int
    {
        DB::table('settings')->updateOrInsert(
            ['store_id' => $storeId, 'key' => 'b2b.pricing_tiers_enabled'],
            [
                'value' => json_encode(true, JSON_THROW_ON_ERROR),
                'is_secret' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );

        $catalogId = (int) DB::table('catalogs')->insertGetId([
            'store_id' => $storeId,
            'channel' => 'b2b',
            'code' => 'restaurant-procurement',
            'name' => 'Restaurant Procurement',
            'is_active' => true,
            'is_migration_quarantine' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $unitId = (int) DB::table('units')->orderBy('id')->value('id');
        $productId = (int) DB::table('products')->insertGetId([
            'catalog_id' => $catalogId,
            'unit_id' => $unitId,
            'sku' => $sku,
            'name' => $sku,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('store_products')->insert([
            'store_id' => $storeId,
            'product_id' => $productId,
            'price' => 15,
            'cost_price' => 9,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $warehouseId = (int) DB::table('warehouses')->insertGetId([
            'store_id' => $storeId,
            'code' => 'WH-'.$sku,
            'name' => 'Warehouse '.$sku,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('inventories')->insert([
            'warehouse_id' => $warehouseId,
            'product_id' => $productId,
            'quantity' => 100,
            'reserved_quantity' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $productId;
    }

    private function storeAdmin(Store $store, string $email): User
    {
        $user = User::query()->create([
            'name' => $email,
            'email' => $email,
            'password' => 'password',
            'locale' => 'en',
            'is_active' => true,
        ]);
        $this->assignStoreAdmin($user, $store);

        return $user;
    }

    private function assignStoreAdmin(User $user, Store $store): void
    {
        $role = Role::query()->where('code', 'B2C_STORE_ADMIN')->firstOrFail();

        DB::table('user_store_roles')->insertOrIgnore([
            'user_id' => $user->id,
            'store_id' => $store->id,
            'role_id' => $role->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function store(string $type, string $code): Store
    {
        $typeId = (int) DB::table('store_types')->where('code', $type)->value('id');

        return Store::query()->create([
            'store_type_id' => $typeId,
            'code' => $code,
            'name' => $code,
            'is_active' => true,
        ]);
    }
}
