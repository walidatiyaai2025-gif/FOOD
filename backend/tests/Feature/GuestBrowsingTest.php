<?php

namespace Tests\Feature;

use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class GuestBrowsingTest extends TestCase
{
    use RefreshDatabase;

    private int $storeId;

    private int $otherStoreId;

    private int $productId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CoreReferenceSeeder::class);

        $b2cTypeId = (int) DB::table('store_types')->where('code', 'B2C')->value('id');
        $unitId = (int) DB::table('units')->insertGetId([
            'code' => 'EA',
            'name' => 'Each',
            'decimal_places' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $categoryId = (int) DB::table('categories')->insertGetId([
            'name' => 'Fresh',
            'slug' => 'fresh',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->storeId = (int) DB::table('stores')->insertGetId([
            'store_type_id' => $b2cTypeId,
            'code' => 'B2C-ONE',
            'name' => 'B2C One',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->otherStoreId = (int) DB::table('stores')->insertGetId([
            'store_type_id' => $b2cTypeId,
            'code' => 'B2C-TWO',
            'name' => 'B2C Two',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->productId = (int) DB::table('products')->insertGetId([
            'category_id' => $categoryId,
            'unit_id' => $unitId,
            'sku' => 'SKU-001',
            'name' => 'Guest Product',
            'description' => 'Visible to guests',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('store_products')->insert([
            [
                'store_id' => $this->storeId,
                'product_id' => $this->productId,
                'price' => 1.250,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'store_id' => $this->otherStoreId,
                'product_id' => $this->productId,
                'price' => 1.500,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        DB::table('promotions')->insert([
            'store_id' => $this->storeId,
            'name' => 'Guest Offer',
            'type' => 'fixed',
            'value' => 0.250,
            'starts_at' => now()->subHour(),
            'ends_at' => now()->addHour(),
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_guest_can_browse_b2c_store_catalog_and_offers_without_login(): void
    {
        $this->getJson('/api/v1/stores')
            ->assertOk()
            ->assertJsonPath('data.0.id', $this->storeId)
            ->assertJsonPath('data.0.store_type', 'B2C');

        $this->getJson("/api/v1/stores/{$this->storeId}/categories")
            ->assertOk()
            ->assertJsonPath('data.0.slug', 'fresh');

        $this->getJson("/api/v1/stores/{$this->storeId}/products")
            ->assertOk()
            ->assertJsonPath('data.0.id', $this->productId)
            ->assertJsonPath('data.0.price', 1.25);

        $this->getJson("/api/v1/products/{$this->productId}?store={$this->storeId}")
            ->assertOk()
            ->assertJsonPath('id', $this->productId)
            ->assertJsonPath('price', 1.25);

        $this->getJson("/api/v1/stores/{$this->storeId}/offers")
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Guest Offer');
    }

    public function test_guest_cart_is_created_and_mutated_with_opaque_token(): void
    {
        $empty = $this->getJson("/api/v1/cart?store={$this->storeId}")
            ->assertOk()
            ->assertJsonPath('store_id', $this->storeId)
            ->assertJsonCount(0, 'items');

        $token = (string) $empty->headers->get('X-Guest-Token');

        $this->assertGreaterThanOrEqual(16, strlen($token));

        $cart = $this->withHeader('X-Guest-Token', $token)
            ->postJson('/api/v1/cart/items', [
                'store_id' => $this->storeId,
                'product_id' => $this->productId,
                'quantity' => 2,
            ])
            ->assertCreated()
            ->assertJsonPath('items.0.product.id', $this->productId)
            ->assertJsonPath('items.0.quantity', 2)
            ->assertJsonPath('subtotal', 2.5);

        $itemId = (int) $cart->json('items.0.id');

        $this->withHeader('X-Guest-Token', $token)
            ->patchJson("/api/v1/cart/items/{$itemId}", ['quantity' => 3])
            ->assertOk()
            ->assertJsonPath('items.0.quantity', 3)
            ->assertJsonPath('subtotal', 3.75);

        $this->withHeader('X-Guest-Token', $token)
            ->deleteJson("/api/v1/cart/items/{$itemId}")
            ->assertNoContent();

        $this->withHeader('X-Guest-Token', $token)
            ->getJson('/api/v1/cart')
            ->assertOk()
            ->assertJsonCount(0, 'items');
    }

    public function test_guest_cart_token_cannot_cross_store_boundary(): void
    {
        $cart = $this->getJson("/api/v1/cart?store={$this->storeId}")->assertOk();
        $token = (string) $cart->headers->get('X-Guest-Token');

        $this->withHeader('X-Guest-Token', $token)
            ->postJson('/api/v1/cart/items', [
                'store_id' => $this->otherStoreId,
                'product_id' => $this->productId,
                'quantity' => 1,
            ])
            ->assertConflict();
    }

    public function test_guest_cannot_mutate_another_guest_cart_item(): void
    {
        $first = $this->postJson('/api/v1/cart/items', [
            'store_id' => $this->storeId,
            'product_id' => $this->productId,
            'quantity' => 1,
        ])->assertCreated();

        $itemId = (int) $first->json('items.0.id');

        $second = $this->getJson("/api/v1/cart?store={$this->storeId}")->assertOk();
        $secondToken = (string) $second->headers->get('X-Guest-Token');

        $this->withHeader('X-Guest-Token', $secondToken)
            ->patchJson("/api/v1/cart/items/{$itemId}", ['quantity' => 5])
            ->assertNotFound();
    }

    public function test_protected_account_and_checkout_routes_remain_gated(): void
    {
        $this->getJson('/api/v1/profile')->assertUnauthorized();
        $this->postJson('/api/v1/checkout')->assertUnauthorized();
    }

    public function test_inactive_or_non_b2c_store_is_not_publicly_browsable(): void
    {
        DB::table('stores')->where('id', $this->storeId)->update(['is_active' => false]);

        $this->getJson("/api/v1/stores/{$this->storeId}/products")
            ->assertNotFound();

        $b2bTypeId = (int) DB::table('store_types')->where('code', 'B2B')->value('id');
        $b2bStoreId = (int) DB::table('stores')->insertGetId([
            'store_type_id' => $b2bTypeId,
            'code' => 'B2B-ONLY',
            'name' => 'Wholesale',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->getJson("/api/v1/stores/{$b2bStoreId}/products")
            ->assertNotFound();
    }
}
