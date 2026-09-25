<?php

namespace Tests\Feature;

use App\Models\Address;
use App\Models\Customer;
use App\Models\User;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CheckoutDomainTest extends TestCase
{
    use RefreshDatabase;

    private int $storeId;

    private int $productId;

    private int $warehouseId;

    private User $user;

    private Customer $customer;

    private Address $address;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CoreReferenceSeeder::class);

        $b2cTypeId = (int) DB::table('store_types')->where('code', 'B2C')->value('id');
        $unitId = (int) DB::table('units')->insertGetId([
            'code' => 'EA-CHECKOUT',
            'name' => 'Each',
            'decimal_places' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $categoryId = (int) DB::table('categories')->insertGetId([
            'name' => 'Checkout',
            'slug' => 'checkout',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->storeId = (int) DB::table('stores')->insertGetId([
            'store_type_id' => $b2cTypeId,
            'code' => 'CHECKOUT-B2C',
            'name' => 'Checkout Retail',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->productId = (int) DB::table('products')->insertGetId([
            'category_id' => $categoryId,
            'unit_id' => $unitId,
            'sku' => 'CHECKOUT-001',
            'name' => 'Checkout Product',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('store_products')->insert([
            'store_id' => $this->storeId,
            'product_id' => $this->productId,
            'price' => 1.250,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->warehouseId = (int) DB::table('warehouses')->insertGetId([
            'store_id' => $this->storeId,
            'code' => 'WH-CHECKOUT',
            'name' => 'Checkout Warehouse',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('inventories')->insert([
            'warehouse_id' => $this->warehouseId,
            'product_id' => $this->productId,
            'quantity' => 5,
            'reserved_quantity' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->user = User::query()->create([
            'name' => 'Checkout Customer',
            'email' => 'checkout@example.test',
            'password' => 'secret-password',
            'locale' => 'ar',
            'is_active' => true,
        ]);

        $this->customer = Customer::query()->create([
            'user_id' => $this->user->id,
            'type' => 'b2c',
            'name' => 'Checkout Customer',
            'email' => $this->user->email,
        ]);

        $this->address = Address::query()->create([
            'customer_id' => $this->customer->id,
            'label' => 'Home',
            'line1' => 'Street 1',
            'city' => 'Kuwait City',
            'area' => 'Sharq',
            'country_code' => 'KW',
            'is_default' => true,
        ]);

        Sanctum::actingAs($this->user);
    }

    public function test_checkout_creates_order_payment_history_audit_and_stock_reservation(): void
    {
        $this->addCartItem(2);

        $response = $this->withHeader('Idempotency-Key', 'checkout-key-000001')
            ->postJson('/api/v1/checkout', [
                'store_id' => $this->storeId,
                'address_id' => $this->address->id,
                'payment_method' => 'cash_on_delivery',
                'note' => 'Leave at reception',
            ])
            ->assertCreated()
            ->assertJsonPath('channel', 'b2c')
            ->assertJsonPath('status', 'pending')
            ->assertJsonPath('subtotal', 2.5)
            ->assertJsonPath('grand_total', 2.5)
            ->assertJsonPath('payment.provider', 'cash_on_delivery')
            ->assertJsonPath('payment.status', 'pending');

        $orderId = (int) $response->json('id');

        $this->assertDatabaseHas('orders', [
            'id' => $orderId,
            'customer_id' => $this->customer->id,
            'address_id' => $this->address->id,
            'checkout_idempotency_key' => 'checkout-key-000001',
        ]);
        $this->assertDatabaseHas('order_items', [
            'order_id' => $orderId,
            'product_id' => $this->productId,
            'quantity' => 2,
            'unit_price' => 1.250,
        ]);
        $this->assertDatabaseHas('payments', [
            'order_id' => $orderId,
            'provider' => 'cash_on_delivery',
            'status' => 'pending',
            'amount' => 2.500,
        ]);
        $this->assertDatabaseHas('order_status_history', [
            'order_id' => $orderId,
            'from_status' => null,
            'to_status' => 'pending',
        ]);
        $this->assertDatabaseHas('stock_movements', [
            'reference_type' => 'order',
            'reference_id' => $orderId,
            'type' => 'reserve',
            'quantity' => 2,
        ]);
        $this->assertDatabaseHas('inventories', [
            'warehouse_id' => $this->warehouseId,
            'product_id' => $this->productId,
            'reserved_quantity' => 2,
        ]);
        $this->assertDatabaseMissing('carts', [
            'customer_id' => $this->customer->id,
            'store_id' => $this->storeId,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'checkout.order_created',
            'auditable_type' => 'App\\Models\\Order',
            'auditable_id' => $orderId,
        ]);
    }

    public function test_checkout_idempotency_replays_same_order_and_rejects_changed_payload(): void
    {
        $this->addCartItem(1);

        $payload = [
            'store_id' => $this->storeId,
            'address_id' => $this->address->id,
            'payment_method' => 'cash_on_delivery',
        ];

        $first = $this->withHeader('Idempotency-Key', 'checkout-key-000002')
            ->postJson('/api/v1/checkout', $payload)
            ->assertCreated();

        $orderId = (int) $first->json('id');

        $this->withHeader('Idempotency-Key', 'checkout-key-000002')
            ->postJson('/api/v1/checkout', $payload)
            ->assertOk()
            ->assertJsonPath('id', $orderId);

        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseCount('payments', 1);

        $this->withHeader('Idempotency-Key', 'checkout-key-000002')
            ->postJson('/api/v1/checkout', [
                ...$payload,
                'note' => 'Different request',
            ])
            ->assertConflict();

        $this->assertDatabaseCount('orders', 1);
    }

    public function test_checkout_rejects_foreign_address_and_unconfigured_payment_method(): void
    {
        $this->addCartItem(1);

        $otherUser = User::query()->create([
            'name' => 'Other',
            'email' => 'checkout-other@example.test',
            'password' => 'secret-password',
            'is_active' => true,
        ]);
        $otherCustomer = Customer::query()->create([
            'user_id' => $otherUser->id,
            'type' => 'b2c',
            'name' => 'Other',
            'email' => $otherUser->email,
        ]);
        $otherAddress = Address::query()->create([
            'customer_id' => $otherCustomer->id,
            'line1' => 'Other street',
            'city' => 'Kuwait City',
            'country_code' => 'KW',
        ]);

        $this->withHeader('Idempotency-Key', 'checkout-key-000003')
            ->postJson('/api/v1/checkout', [
                'store_id' => $this->storeId,
                'address_id' => $otherAddress->id,
            ])
            ->assertNotFound();

        $this->withHeader('Idempotency-Key', 'checkout-key-000004')
            ->postJson('/api/v1/checkout', [
                'store_id' => $this->storeId,
                'address_id' => $this->address->id,
                'payment_method' => 'unknown-provider',
            ])
            ->assertUnprocessable();
    }

    public function test_checkout_rechecks_stock_inside_transaction_and_preserves_cart_on_conflict(): void
    {
        $this->addCartItem(4);

        DB::table('inventories')
            ->where('warehouse_id', $this->warehouseId)
            ->where('product_id', $this->productId)
            ->update(['reserved_quantity' => 2]);

        $this->withHeader('Idempotency-Key', 'checkout-key-000005')
            ->postJson('/api/v1/checkout', [
                'store_id' => $this->storeId,
                'address_id' => $this->address->id,
            ])
            ->assertConflict();

        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseHas('carts', [
            'customer_id' => $this->customer->id,
            'store_id' => $this->storeId,
        ]);
        $this->assertDatabaseHas('inventories', [
            'warehouse_id' => $this->warehouseId,
            'reserved_quantity' => 2,
        ]);
    }

    public function test_checkout_requires_authentication_and_idempotency_key(): void
    {
        $this->addCartItem(1);

        $this->postJson('/api/v1/checkout', [
            'store_id' => $this->storeId,
            'address_id' => $this->address->id,
        ])->assertUnprocessable();

        $this->app['auth']->forgetGuards();

        $this->withHeader('Idempotency-Key', 'checkout-key-000006')
            ->postJson('/api/v1/checkout', [
                'store_id' => $this->storeId,
                'address_id' => $this->address->id,
            ])
            ->assertUnauthorized();
    }

    private function addCartItem(float $quantity): void
    {
        $this->postJson('/api/v1/cart/items', [
            'store_id' => $this->storeId,
            'product_id' => $this->productId,
            'quantity' => $quantity,
        ])->assertCreated();
    }
}
