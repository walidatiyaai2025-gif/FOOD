<?php

namespace Tests\Feature;

use App\Models\B2bCustomer;
use App\Models\B2cCustomer;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderStatusHistory;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\CustomerDomainResolver;
use App\Services\WholesalePrincipal;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
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
        $catalogId = (int) DB::table('catalogs')->insertGetId([
            'store_id' => $this->b2cStoreId,
            'channel' => 'b2c',
            'code' => 'default',
            'name' => 'Order Catalog',
            'is_active' => true,
            'is_migration_quarantine' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $categoryId = (int) DB::table('categories')->insertGetId([
            'catalog_id' => $catalogId,
            'name' => 'Orders',
            'slug' => 'orders',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->productId = (int) DB::table('products')->insertGetId([
            'catalog_id' => $catalogId,
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

    public function test_customer_order_list_exposes_authoritative_status_counts_and_filtered_totals(): void
    {
        $pending = $this->makeOrder(
            $this->b2bCustomer,
            $this->b2bStoreId,
            'b2b',
            'pending',
        );
        $delivered = $this->makeOrder(
            $this->b2bCustomer,
            $this->b2bStoreId,
            'b2b',
            'delivered',
        );

        Sanctum::actingAs($this->b2bUser);

        $this->getJson('/api/v1/b2b/orders')
            ->assertOk()
            ->assertJsonPath('meta.all_total', 2)
            ->assertJsonPath('meta.status_counts.pending', 1)
            ->assertJsonPath('meta.status_counts.delivered', 1)
            ->assertJsonPath('data.0.item_count', 1);

        $this->getJson('/api/v1/b2b/orders?status=delivered')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $delivered->id)
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('meta.all_total', 2)
            ->assertJsonPath('meta.status_counts.pending', 1)
            ->assertJsonPath('meta.status_counts.delivered', 1);

        $this->getJson('/api/v1/b2b/orders?status=pending')
            ->assertOk()
            ->assertJsonPath('data.0.id', $pending->id)
            ->assertJsonPath('data.0.next_statuses.0', 'confirmed');
    }

    public function test_b2b_order_detail_exposes_authoritative_customer_safe_delivery_timeline(): void
    {
        $this->assertSame($this->b2bStoreId, app(WholesalePrincipal::class)->storeId());

        $order = $this->makeOrder($this->b2bCustomer, $this->b2bStoreId, 'b2b');
        $base = now()->subHour()->startOfSecond();

        DB::table('orders')->where('id', $order->id)->update([
            'status' => 'delivered',
            'created_at' => $base,
            'updated_at' => $base->copy()->addMinutes(8),
        ]);
        DB::table('order_status_history')->where('order_id', $order->id)->delete();

        foreach ([
            ['confirmed', 1],
            ['preparing', 2],
            ['ready', 3],
            ['out_for_delivery', 7],
            ['delivered', 8],
        ] as [$status, $minutes]) {
            DB::table('order_status_history')->insert([
                'order_id' => $order->id,
                'store_id' => $this->b2bStoreId,
                'user_id' => null,
                'from_status' => null,
                'to_status' => $status,
                'note' => 'INTERNAL ORDER NOTE '.$status,
                'created_at' => $base->copy()->addMinutes($minutes),
                'updated_at' => $base->copy()->addMinutes($minutes),
            ]);
        }

        $driverUser = User::query()->create([
            'name' => 'Wholesale Driver',
            'email' => 'wholesale-timeline-driver@example.test',
            'password' => 'secret-password',
            'is_active' => true,
        ]);
        $driverId = (int) DB::table('drivers')->insertGetId([
            'user_id' => $driverUser->id,
            'store_id' => $this->b2bStoreId,
            'driver_type' => 'b2b',
            'is_available' => true,
            'is_active' => true,
            'created_at' => $base,
            'updated_at' => $base,
        ]);
        $assignmentId = (int) DB::table('driver_assignments')->insertGetId([
            'driver_id' => $driverId,
            'order_id' => $order->id,
            'store_id' => $this->b2bStoreId,
            'assignment_type' => 'b2b',
            'status' => 'delivered',
            'assigned_at' => $base->copy()->addMinutes(4),
            'completed_at' => $base->copy()->addMinutes(8),
            'created_at' => $base->copy()->addMinutes(4),
            'updated_at' => $base->copy()->addMinutes(8),
        ]);

        foreach ([
            ['accepted', 5, 'status_note'],
            ['picked_up', 6, 'status_note'],
            ['out_for_delivery', 7, 'status_note'],
            ['delivered', 8, 'delivery_image'],
        ] as [$status, $minutes, $proofType]) {
            DB::table('delivery_proofs')->insert([
                'driver_assignment_id' => $assignmentId,
                'order_id' => $order->id,
                'user_id' => $driverUser->id,
                'proof_type' => $proofType,
                'from_status' => null,
                'to_status' => $status,
                'file_path' => $status === 'delivered'
                    ? 'delivery-proofs/PRIVATE-CUSTOMER-PROOF.jpg'
                    : null,
                'otp_hash' => null,
                'reason_code' => null,
                'note' => 'PRIVATE DRIVER NOTE '.$status,
                'captured_at' => $base->copy()->addMinutes($minutes),
                'created_at' => $base->copy()->addMinutes($minutes),
                'updated_at' => $base->copy()->addMinutes($minutes),
            ]);
        }

        Sanctum::actingAs($this->b2bUser);

        $list = $this->getJson('/api/v1/b2b/orders')
            ->assertOk()
            ->assertJsonPath('data.0.id', $order->id)
            ->assertJsonPath('data.0.timeline', null);
        $this->assertStringNotContainsString(
            'PRIVATE DRIVER NOTE',
            (string) $list->getContent(),
        );

        $response = $this->getJson("/api/v1/b2b/orders/{$order->id}")
            ->assertOk()
            ->assertJsonPath('channel', 'b2b')
            ->assertJsonPath('status', 'delivered')
            ->assertJsonPath('timeline.0.stage', 'placed')
            ->assertJsonPath('timeline.1.stage', 'confirmed')
            ->assertJsonPath('timeline.2.stage', 'preparing')
            ->assertJsonPath('timeline.3.stage', 'ready')
            ->assertJsonPath('timeline.4.stage', 'driver_assigned')
            ->assertJsonPath('timeline.4.driver_name', 'Wholesale Driver')
            ->assertJsonPath('timeline.5.stage', 'accepted')
            ->assertJsonPath('timeline.6.stage', 'picked_up')
            ->assertJsonPath('timeline.7.stage', 'out_for_delivery')
            ->assertJsonPath('timeline.8.stage', 'delivered');

        $payload = (string) $response->getContent();
        $this->assertStringNotContainsString('INTERNAL ORDER NOTE', $payload);
        $this->assertStringNotContainsString('PRIVATE DRIVER NOTE', $payload);
        $this->assertStringNotContainsString('PRIVATE-CUSTOMER-PROOF.jpg', $payload);
        $this->assertStringNotContainsString('"note"', $payload);
    }

    public function test_b2b_failed_delivery_exposes_reason_code_without_driver_note(): void
    {
        $this->assertSame($this->b2bStoreId, app(WholesalePrincipal::class)->storeId());

        $order = $this->makeOrder($this->b2bCustomer, $this->b2bStoreId, 'b2b', 'failed');
        $base = now()->subMinutes(20)->startOfSecond();

        DB::table('orders')->where('id', $order->id)->update([
            'created_at' => $base,
            'updated_at' => $base->copy()->addMinutes(2),
        ]);
        DB::table('order_status_history')->where('order_id', $order->id)->delete();

        $driverUser = User::query()->create([
            'name' => 'Failed Wholesale Driver',
            'email' => 'failed-wholesale-driver@example.test',
            'password' => 'secret-password',
            'is_active' => true,
        ]);
        $driverId = (int) DB::table('drivers')->insertGetId([
            'user_id' => $driverUser->id,
            'store_id' => $this->b2bStoreId,
            'driver_type' => 'b2b',
            'is_available' => true,
            'is_active' => true,
            'created_at' => $base,
            'updated_at' => $base,
        ]);
        $assignmentId = (int) DB::table('driver_assignments')->insertGetId([
            'driver_id' => $driverId,
            'order_id' => $order->id,
            'store_id' => $this->b2bStoreId,
            'assignment_type' => 'b2b',
            'status' => 'failed',
            'assigned_at' => $base->copy()->addMinute(),
            'completed_at' => $base->copy()->addMinutes(2),
            'created_at' => $base->copy()->addMinute(),
            'updated_at' => $base->copy()->addMinutes(2),
        ]);
        DB::table('delivery_proofs')->insert([
            'driver_assignment_id' => $assignmentId,
            'order_id' => $order->id,
            'user_id' => $driverUser->id,
            'proof_type' => 'failure_note',
            'from_status' => 'out_for_delivery',
            'to_status' => 'failed',
            'file_path' => null,
            'otp_hash' => null,
            'reason_code' => 'customer_no_answer',
            'note' => 'DO NOT SHOW THIS FAILURE NOTE',
            'captured_at' => $base->copy()->addMinutes(2),
            'created_at' => $base->copy()->addMinutes(2),
            'updated_at' => $base->copy()->addMinutes(2),
        ]);

        Sanctum::actingAs($this->b2bUser);

        $response = $this->getJson("/api/v1/b2b/orders/{$order->id}")
            ->assertOk()
            ->assertJsonPath('timeline.2.stage', 'failed')
            ->assertJsonPath('timeline.2.reason_code', 'customer_no_answer');

        $this->assertStringNotContainsString(
            'DO NOT SHOW THIS FAILURE NOTE',
            (string) $response->getContent(),
        );
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

    public function test_b2b_customer_commerce_rejects_non_principal_wholesale_store(): void
    {
        $principalStoreId = app(WholesalePrincipal::class)->storeId();
        $this->assertSame($this->b2bStoreId, $principalStoreId);

        $rogueWholesaleStoreId = (int) DB::table('stores')->insertGetId([
            'store_type_id' => DB::table('store_types')->where('code', 'B2B')->value('id'),
            'code' => 'ORD-B2B-ROGUE',
            'name' => 'Non Principal Wholesale',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->expectException(NotFoundHttpException::class);

        app(CustomerDomainResolver::class)->forStore(
            $this->b2bUser,
            $rogueWholesaleStoreId,
        );
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
        if ($channel === 'b2b') {
            $domainCustomer = B2bCustomer::query()->firstOrCreate(
                ['legacy_customer_id' => $customer->id],
                [
                    'user_id' => $customer->user_id,
                    'name' => $customer->name,
                    'phone' => $customer->phone,
                    'email' => $customer->email,
                ],
            );
            $domainReferences = ['b2b_customer_id' => $domainCustomer->id];
        } else {
            $domainCustomer = B2cCustomer::query()->firstOrCreate(
                ['legacy_customer_id' => $customer->id, 'store_id' => $storeId],
                [
                    'user_id' => $customer->user_id,
                    'name' => $customer->name,
                    'phone' => $customer->phone,
                    'email' => $customer->email,
                ],
            );
            $domainReferences = ['b2c_customer_id' => $domainCustomer->id];
        }

        $order = Order::query()->create([
            'store_id' => $storeId,
            'customer_id' => $customer->id,
            ...$domainReferences,
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
            'store_id' => $storeId,
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
                'store_id' => $storeId,
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
