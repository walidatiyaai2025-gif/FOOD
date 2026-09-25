<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\User;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CartDomainTest extends TestCase
{
    use RefreshDatabase;

    private int $b2cStoreId;

    private int $b2bStoreId;

    private int $productId;

    private int $b2cWarehouseId;

    private User $b2cUser;

    private Customer $b2cCustomer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CoreReferenceSeeder::class);

        $storeTypes = DB::table('store_types')->pluck('id', 'code');
        $unitId = (int) DB::table('units')->insertGetId([
            'code' => 'EA-CART',
            'name' => 'Each',
            'decimal_places' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $categoryId = (int) DB::table('categories')->insertGetId([
            'name' => 'Cart',
            'slug' => 'cart',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->b2cStoreId = (int) DB::table('stores')->insertGetId([
            'store_type_id' => $storeTypes['B2C'],
            'code' => 'CART-B2C',
            'name' => 'Cart Retail',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->b2bStoreId = (int) DB::table('stores')->insertGetId([
            'store_type_id' => $storeTypes['B2B'],
            'code' => 'CART-B2B',
            'name' => 'Cart Wholesale',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->productId = (int) DB::table('products')->insertGetId([
            'category_id' => $categoryId,
            'unit_id' => $unitId,
            'sku' => 'CART-001',
            'name' => 'Cart Product',
            'description' => 'Cart domain test product',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach ([$this->b2cStoreId, $this->b2bStoreId] as $storeId) {
            DB::table('store_products')->insert([
                'store_id' => $storeId,
                'product_id' => $this->productId,
                'price' => $storeId === $this->b2cStoreId ? 1.250 : 2.500,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $warehouseId = (int) DB::table('warehouses')->insertGetId([
                'store_id' => $storeId,
                'code' => 'WH-'.$storeId,
                'name' => 'Warehouse '.$storeId,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            if ($storeId === $this->b2cStoreId) {
                $this->b2cWarehouseId = $warehouseId;
            }

            DB::table('inventories')->insert([
                'warehouse_id' => $warehouseId,
                'product_id' => $this->productId,
                'quantity' => 5,
                'reserved_quantity' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $this->b2cUser = User::query()->create([
            'name' => 'B2C Customer',
            'email' => 'cart-b2c@example.test',
            'password' => 'secret-password',
            'locale' => 'ar',
            'is_active' => true,
        ]);
        $this->b2cCustomer = Customer::query()->create([
            'user_id' => $this->b2cUser->id,
            'type' => 'b2c',
            'name' => 'B2C Customer',
            'email' => $this->b2cUser->email,
        ]);
    }

    public function test_guest_cart_merges_into_authenticated_customer_and_reprices(): void
    {
        $guest = $this->postJson('/api/v1/cart/items', [
            'store_id' => $this->b2cStoreId,
            'product_id' => $this->productId,
            'quantity' => 1,
        ])->assertCreated();

        $token = (string) $guest->headers->get('X-Guest-Token');

        DB::table('store_products')
            ->where('store_id', $this->b2cStoreId)
            ->where('product_id', $this->productId)
            ->update(['price' => 1.500]);

        Sanctum::actingAs($this->b2cUser);

        $merged = $this->withHeader('X-Guest-Token', $token)
            ->getJson("/api/v1/cart?store={$this->b2cStoreId}")
            ->assertOk()
            ->assertJsonPath('customer_id', $this->b2cCustomer->id)
            ->assertJsonPath('guest_token', null)
            ->assertJsonPath('channel', 'b2c')
            ->assertJsonPath('items.0.quantity', 1)
            ->assertJsonPath('items.0.unit_price_snapshot', 1.5)
            ->assertJsonPath('subtotal', 1.5);

        $this->assertDatabaseMissing('carts', ['guest_token' => $token]);

        $this->postJson('/api/v1/cart/items', [
            'store_id' => $this->b2cStoreId,
            'product_id' => $this->productId,
            'quantity' => 1,
        ])->assertCreated()
            ->assertJsonPath('id', $merged->json('id'))
            ->assertJsonPath('items.0.quantity', 2)
            ->assertJsonPath('subtotal', 3);
    }

    public function test_authenticated_cart_items_are_customer_owned(): void
    {
        Sanctum::actingAs($this->b2cUser);

        $cart = $this->postJson('/api/v1/cart/items', [
            'store_id' => $this->b2cStoreId,
            'product_id' => $this->productId,
            'quantity' => 1,
        ])->assertCreated();

        $itemId = (int) $cart->json('items.0.id');

        $otherUser = User::query()->create([
            'name' => 'Other Customer',
            'email' => 'cart-other@example.test',
            'password' => 'secret-password',
            'is_active' => true,
        ]);
        Customer::query()->create([
            'user_id' => $otherUser->id,
            'type' => 'b2c',
            'name' => 'Other Customer',
            'email' => $otherUser->email,
        ]);

        Sanctum::actingAs($otherUser);

        $this->patchJson("/api/v1/cart/items/{$itemId}", ['quantity' => 2])
            ->assertNotFound();
        $this->deleteJson("/api/v1/cart/items/{$itemId}")
            ->assertNotFound();
    }

    public function test_b2b_customer_is_restricted_to_b2b_cart_channel(): void
    {
        $guest = $this->postJson('/api/v1/cart/items', [
            'store_id' => $this->b2cStoreId,
            'product_id' => $this->productId,
            'quantity' => 1,
        ])->assertCreated();
        $guestToken = (string) $guest->headers->get('X-Guest-Token');

        $b2bUser = User::query()->create([
            'name' => 'B2B Customer',
            'email' => 'cart-b2b@example.test',
            'password' => 'secret-password',
            'is_active' => true,
        ]);
        Customer::query()->create([
            'user_id' => $b2bUser->id,
            'type' => 'b2b',
            'name' => 'B2B Customer',
            'email' => $b2bUser->email,
        ]);

        Sanctum::actingAs($b2bUser);

        $this->postJson('/api/v1/cart/items', [
            'store_id' => $this->b2cStoreId,
            'product_id' => $this->productId,
            'quantity' => 1,
        ])->assertNotFound();

        $this->withHeader('X-Guest-Token', $guestToken)
            ->getJson("/api/v1/cart?store={$this->b2bStoreId}")
            ->assertConflict();

        $this->postJson('/api/v1/cart/items', [
            'store_id' => $this->b2bStoreId,
            'product_id' => $this->productId,
            'quantity' => 1,
        ])->assertCreated()
            ->assertJsonPath('channel', 'b2b');
    }

    public function test_cart_recalculates_price_and_inventory_availability(): void
    {
        Sanctum::actingAs($this->b2cUser);

        $cart = $this->postJson('/api/v1/cart/items', [
            'store_id' => $this->b2cStoreId,
            'product_id' => $this->productId,
            'quantity' => 4,
        ])->assertCreated();

        $itemId = (int) $cart->json('items.0.id');

        DB::table('store_products')
            ->where('store_id', $this->b2cStoreId)
            ->where('product_id', $this->productId)
            ->update(['price' => 1.750]);

        DB::table('inventories')
            ->where('warehouse_id', $this->b2cWarehouseId)
            ->where('product_id', $this->productId)
            ->update(['reserved_quantity' => 3]);

        $this->getJson("/api/v1/cart?store={$this->b2cStoreId}")
            ->assertOk()
            ->assertJsonPath('items.0.is_available', false)
            ->assertJsonPath('items.0.available_quantity', 2)
            ->assertJsonPath('has_unavailable_items', true)
            ->assertJsonPath('subtotal', 0);

        $this->patchJson("/api/v1/cart/items/{$itemId}", ['quantity' => 3])
            ->assertConflict();

        $this->patchJson("/api/v1/cart/items/{$itemId}", ['quantity' => 2])
            ->assertOk()
            ->assertJsonPath('items.0.is_available', true)
            ->assertJsonPath('items.0.unit_price_snapshot', 1.75)
            ->assertJsonPath('subtotal', 3.5);
    }

    public function test_invalid_bearer_token_is_not_downgraded_to_guest_access(): void
    {
        $this->withHeader('Authorization', 'Bearer invalid-token')
            ->getJson("/api/v1/cart?store={$this->b2cStoreId}")
            ->assertUnauthorized();
    }
}
