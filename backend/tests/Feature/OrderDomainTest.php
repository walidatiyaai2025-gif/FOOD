<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderStatusHistory;
use App\Models\StockMovement;
use App\Models\User;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class OrderDomainTest extends TestCase
{
    use RefreshDatabase;

    private int $b2cStoreId;

    private int $b2cOtherStoreId;

    private int $b2bStoreId;

    private int $productId;

    private int $inventoryId;

    private User $b2cUser;

    private Customer $b2cCustomer;

    private User $b2bUser;

    private Customer $b2bCustomer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);

        $types = DB::table('store_types')->pluck('id', 'code');

        foreach ([
            ['code' => 'ORD-B2C-1', 'name' => 'Retail One', 'type' => 'B2C'],
            ['code' => 'ORD-B2C-2', 'name' => 'Retail Two', 'type' => 'B2C'],
            ['code' => 'ORD-B2B-1', 'name' => 'Wholesale One', 'type' => 'B2B'],
        ] as $row) {
            $id = (int) DB::table('stores')->insertGetId([
                'store_type_id' => $types[$row['type']],
                'code' => $row['code'],
                'name' => $row['name'],
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            match ($row['code']) {
                'ORD-B2C-1' => $this->b2cStoreId = $id,
                'ORD-B2C-2' => $this->b2cOtherStoreId = $id,
                default => $this->b2bStoreId = $id,
            };
        }

        $unitId = (int) DB::table('units')->insertGetId([
            'code' => 'EA-ORDER',
            'name' => 'Each',
            'decimal_places' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $categoryId = (int) DB::table('categories')->insertGetId([
            'name' => 'Orders',
            'slug' => 'orders',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->productId = (int) DB::table('products')->insertGetId([
            'category_id' => $categoryId,
            'unit_id' => $unitId,
            'sku' => 'ORDER-001',
            'name' => 'Order Product',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $warehouseId = (int) DB::table('warehouses')->insertGetId([
            'store_id' => $this->b2cStoreId,
            'code' => 'WH-ORDER',
            'name' => 'Order Warehouse',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->inventoryId = (int) DB::table('inventories')->insertGetId([
            'warehouse_id' => $warehouseId,
            'product_id' => $this->productId,
            'quantity' => 10,
            'reserved_quantity' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        [$this->b2cUser, $this->b2cCustomer] = $this->makeCustomer('b2c', 'b2c-order@example.test');
        [$this->b2bUser, $this->b2bCustomer] = $this->makeCustomer('b2b', 'b2b-order@example.test');
    }

    public function test_customers_list_and_view_only_their_channel_owned_orders(): void
    {
        $ownB2c = $this->makeOrder($this->b2cCustomer, $this->b2cStoreId, 'b2c');
        [, $otherB2c] = $this->makeCustomer('b2c', 'other-b2c@example.test');
        $otherOrder = $this->makeOrder($otherB2c, $this->b2cStoreId, 'b2c');
        $ownB2b = $this->makeOrder($this->b2bCustomer, $this->b2bStoreId, 'b2b');

        Sanctum::actingAs($this->b2cUser);

        $this->getJson('/api/v1/orders')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $ownB2c->id)
            ->assertJsonPath('data.0.status_history.0.to_status', 'pending');

        $this->getJson("/api/v1/orders/{$ownB2c->id}")
            ->assertOk()
            ->assertJsonPath('id', $ownB2c->id);

        $this->getJson("/api/v1/orders/{$otherOrder->id}")->assertNotFound();
        $this->getJson('/api/v1/b2b/orders')->assertForbidden();

        Sanctum::actingAs($this->b2bUser);

        $this->getJson('/api/v1/b2b/orders')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $ownB2b->id);

        $this->getJson("/api/v1/b2b/orders/{$ownB2b->id}")
            ->assertOk()
            ->assertJsonPath('channel', 'b2b');

        $this->getJson('/api/v1/orders')->assertForbidden();
    }

    public function test_customer_cannot_transition_order_and_invalid_admin_transition_conflicts(): void
    {
        $order = $this->makeOrder($this->b2cCustomer, $this->b2cStoreId, 'b2c');

        Sanctum::actingAs($this->b2cUser);
        $this->postJson("/api/v1/orders/{$order->id}/status", [
            'status' => 'confirmed',
        ])->assertForbidden();

        $admin = $this->makeGlobalRoleUser('SUPER_ADMIN', 'super-order@example.test');
        Sanctum::actingAs($admin);

        $this->postJson("/api/v1/orders/{$order->id}/status", [
            'status' => 'delivered',
        ])->assertConflict();

        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'status' => 'pending',
        ]);
    }

    public function test_super_admin_can_follow_valid_transition_chain_and_delivery_consumes_reserved_stock(): void
    {
        $order = $this->makeOrder(
            $this->b2cCustomer,
            $this->b2cStoreId,
            'b2c',
            'pending',
            2,
        );

        $admin = $this->makeGlobalRoleUser('SUPER_ADMIN', 'super-delivery@example.test');
        Sanctum::actingAs($admin);

        foreach (['confirmed', 'preparing', 'ready', 'out_for_delivery', 'delivered'] as $status) {
            $this->postJson("/api/v1/orders/{$order->id}/status", [
                'status' => $status,
                'note' => 'Transition '.$status,
            ])->assertOk()
                ->assertJsonPath('status', $status);
        }

        $this->assertDatabaseHas('inventories', [
            'id' => $this->inventoryId,
            'quantity' => 8,
            'reserved_quantity' => 0,
        ]);
        $this->assertDatabaseHas('stock_movements', [
            'inventory_id' => $this->inventoryId,
            'reference_type' => 'order',
            'reference_id' => $order->id,
            'type' => 'sale',
            'quantity' => -2,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'order.status_changed',
            'auditable_type' => 'App\\Models\\Order',
            'auditable_id' => $order->id,
        ]);

        $this->assertSame(
            6,
            OrderStatusHistory::query()->where('order_id', $order->id)->count(),
        );
    }

    public function test_cancellation_releases_reserved_stock(): void
    {
        $order = $this->makeOrder(
            $this->b2cCustomer,
            $this->b2cStoreId,
            'b2c',
            'pending',
            3,
        );

        $admin = $this->makeGlobalRoleUser('SUPER_ADMIN', 'super-cancel@example.test');
        Sanctum::actingAs($admin);

        $this->postJson("/api/v1/orders/{$order->id}/status", [
            'status' => 'cancelled',
            'note' => 'Customer requested cancellation',
        ])->assertOk()
            ->assertJsonPath('status', 'cancelled');

        $this->assertDatabaseHas('inventories', [
            'id' => $this->inventoryId,
            'quantity' => 10,
            'reserved_quantity' => 0,
        ]);
        $this->assertDatabaseHas('stock_movements', [
            'inventory_id' => $this->inventoryId,
            'reference_type' => 'order',
            'reference_id' => $order->id,
            'type' => 'release',
            'quantity' => 3,
        ]);
    }

    public function test_b2c_store_admin_is_limited_to_assigned_store_and_b2b_admin_to_b2b_channel(): void
    {
        $b2cOrder = $this->makeOrder($this->b2cCustomer, $this->b2cStoreId, 'b2c');
        $otherB2cOrder = $this->makeOrder($this->b2cCustomer, $this->b2cOtherStoreId, 'b2c');
        $b2bOrder = $this->makeOrder($this->b2bCustomer, $this->b2bStoreId, 'b2b');

        $storeAdmin = User::query()->create([
            'name' => 'Store Admin',
            'email' => 'store-admin-order@example.test',
            'password' => 'secret-password',
            'is_active' => true,
        ]);
        $storeRoleId = (int) DB::table('roles')->where('code', 'B2C_STORE_ADMIN')->value('id');
        DB::table('user_store_roles')->insert([
            'user_id' => $storeAdmin->id,
            'store_id' => $this->b2cStoreId,
            'role_id' => $storeRoleId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Sanctum::actingAs($storeAdmin);

        $this->postJson("/api/v1/orders/{$b2cOrder->id}/status", [
            'status' => 'confirmed',
        ])->assertOk();

        $this->postJson("/api/v1/orders/{$otherB2cOrder->id}/status", [
            'status' => 'confirmed',
        ])->assertForbidden();

        $this->postJson("/api/v1/orders/{$b2bOrder->id}/status", [
            'status' => 'confirmed',
        ])->assertForbidden();

        $b2bAdmin = $this->makeGlobalRoleUser('B2B_ADMIN', 'b2b-admin-order@example.test');
        Sanctum::actingAs($b2bAdmin);

        $this->postJson("/api/v1/orders/{$b2bOrder->id}/status", [
            'status' => 'confirmed',
        ])->assertOk();

        $this->postJson("/api/v1/orders/{$otherB2cOrder->id}/status", [
            'status' => 'confirmed',
        ])->assertForbidden();
    }

    /** @return array{0: User, 1: Customer} */
    private function makeCustomer(string $type, string $email): array
    {
        $user = User::query()->create([
            'name' => strtoupper($type).' Customer',
            'email' => $email,
            'password' => 'secret-password',
            'is_active' => true,
        ]);

        $customer = Customer::query()->create([
            'user_id' => $user->id,
            'type' => $type,
            'name' => strtoupper($type).' Customer',
            'email' => $email,
        ]);

        return [$user, $customer];
    }

    private function makeGlobalRoleUser(string $roleCode, string $email): User
    {
        $user = User::query()->create([
            'name' => $roleCode,
            'email' => $email,
            'password' => 'secret-password',
            'is_active' => true,
        ]);

        $roleId = (int) DB::table('roles')->where('code', $roleCode)->value('id');
        DB::table('role_user')->insert([
            'role_id' => $roleId,
            'user_id' => $user->id,
        ]);

        return $user;
    }

    private function makeOrder(
        Customer $customer,
        int $storeId,
        string $channel,
        string $status = 'pending',
        float $reservation = 0,
    ): Order {
        $order = Order::query()->create([
            'store_id' => $storeId,
            'customer_id' => $customer->id,
            'address_id' => null,
            'order_number' => 'TEST-'.strtoupper($channel).'-'.uniqid(),
            'channel' => $channel,
            'status' => $status,
            'currency' => 'KWD',
            'subtotal' => 2.500,
            'discount_total' => 0,
            'delivery_total' => 0,
            'grand_total' => 2.500,
            'payment_method' => 'cash_on_delivery',
        ]);

        OrderItem::query()->create([
            'order_id' => $order->id,
            'product_id' => $this->productId,
            'sku_snapshot' => 'ORDER-001',
            'name_snapshot' => 'Order Product',
            'quantity' => 2,
            'unit_price' => 1.250,
            'line_total' => 2.500,
        ]);

        OrderStatusHistory::query()->create([
            'order_id' => $order->id,
            'user_id' => null,
            'from_status' => null,
            'to_status' => $status,
            'note' => 'created',
        ]);

        if ($reservation > 0) {
            DB::table('inventories')
                ->where('id', $this->inventoryId)
                ->update([
                    'reserved_quantity' => $reservation,
                    'updated_at' => now(),
                ]);

            StockMovement::query()->create([
                'inventory_id' => $this->inventoryId,
                'user_id' => null,
                'type' => 'reserve',
                'quantity' => $reservation,
                'reference_type' => 'order',
                'reference_id' => $order->id,
                'reason' => 'test',
            ]);
        }

        return $order;
    }
}
