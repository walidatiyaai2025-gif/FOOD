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
            ->assertJsonMissingPath('data.0.status_history')
            ->assertJsonMissingPath('data.0.items')
            ->assertJsonPath('data.0.item_count', 1);

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

        $vanId = (int) DB::table('vans')->insertGetId([
            'public_id' => '00000000-0000-0000-0000-000000000077',
            'code' => 'VAN-CUSTOMER-77',
            'status' => 'active',
            'created_at' => $base,
            'updated_at' => $base,
        ]);
        $assignmentId = (int) DB::table('order_van_assignments')->insertGetId([
            'order_id' => $order->id,
            'van_id' => $vanId,
            'status' => 'active',
            'source' => 'smart_routing',
            'decision_key' => 'customer-timeline-'.$order->id,
            'assigned_at' => $base->copy()->addMinutes(4),
            'created_at' => $base->copy()->addMinutes(4),
            'updated_at' => $base->copy()->addMinutes(4),
        ]);
        $stateId = (int) DB::table('order_van_execution_states')->insertGetId([
            'order_van_assignment_id' => $assignmentId,
            'order_id' => $order->id,
            'van_id' => $vanId,
            'status' => 'delivered',
            'version' => 5,
            'last_transition_at' => $base->copy()->addMinutes(8),
            'created_at' => $base->copy()->addMinutes(4),
            'updated_at' => $base->copy()->addMinutes(8),
        ]);

        $from = 'assigned';
        foreach ([
            ['accepted', 5],
            ['picked_up', 6],
            ['out_for_delivery', 7],
            ['delivered', 8],
        ] as [$status, $minutes]) {
            DB::table('order_van_execution_events')->insert([
                'order_van_assignment_id' => $assignmentId,
                'order_van_execution_state_id' => $stateId,
                'order_id' => $order->id,
                'van_id' => $vanId,
                'user_id' => null,
                'action' => $status,
                'idempotency_key' => 'customer-'.$status,
                'from_status' => $from,
                'to_status' => $status,
                'proof_type' => $status === 'delivered' ? 'delivery_image' : null,
                'proof_path' => $status === 'delivered'
                    ? 'van-proofs/PRIVATE-CUSTOMER-PROOF.jpg'
                    : null,
                'reason_code' => null,
                'note' => 'PRIVATE VAN NOTE '.$status,
                'captured_at' => $base->copy()->addMinutes($minutes),
                'created_at' => $base->copy()->addMinutes($minutes),
                'updated_at' => $base->copy()->addMinutes($minutes),
            ]);
            $from = $status;
        }

        Sanctum::actingAs($this->b2bUser);

        $list = $this->getJson('/api/v1/b2b/orders')
            ->assertOk()
            ->assertJsonPath('data.0.id', $order->id)
            ->assertJsonPath('data.0.timeline', null);

        $response = $this->getJson("/api/v1/b2b/orders/{$order->id}")
            ->assertOk()
            ->assertJsonPath('channel', 'b2b')
            ->assertJsonPath('status', 'delivered')
            ->assertJsonPath('timeline.0.stage', 'placed')
            ->assertJsonPath('timeline.1.stage', 'confirmed')
            ->assertJsonPath('timeline.2.stage', 'preparing')
            ->assertJsonPath('timeline.3.stage', 'ready')
            ->assertJsonPath('timeline.4.stage', 'van_assigned')
            ->assertJsonPath('timeline.4.van_code', 'VAN-CUSTOMER-77')
            ->assertJsonPath('timeline.5.stage', 'accepted')
            ->assertJsonPath('timeline.6.stage', 'picked_up')
            ->assertJsonPath('timeline.7.stage', 'out_for_delivery')
            ->assertJsonPath('timeline.8.stage', 'delivered')
            ->assertJsonPath('tracking.actor_type', 'van')
            ->assertJsonPath('tracking.van_code', 'VAN-CUSTOMER-77')
            ->assertJsonPath('tracking.status', 'delivered')
            ->assertJsonPath('allowed_actions.cancel', false)
            ->assertJsonPath('allowed_actions.reorder', false)
            ->assertJsonPath('is_terminal', true)
            ->assertJsonPath('tax_total', 0);

        $payload = (string) $response->getContent();
        $this->assertStringNotContainsString('driver_name', $payload);
        $this->assertStringNotContainsString('driver_id', $payload);
        $this->assertStringNotContainsString('INTERNAL ORDER NOTE', $payload);
        $this->assertStringNotContainsString('PRIVATE VAN NOTE', $payload);
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

        $vanId = (int) DB::table('vans')->insertGetId([
            'public_id' => '00000000-0000-0000-0000-000000000078',
            'code' => 'VAN-FAILED-78',
            'status' => 'active',
            'created_at' => $base,
            'updated_at' => $base,
        ]);
        $assignmentId = (int) DB::table('order_van_assignments')->insertGetId([
            'order_id' => $order->id,
            'van_id' => $vanId,
            'status' => 'active',
            'source' => 'smart_routing',
            'decision_key' => 'customer-failed-'.$order->id,
            'assigned_at' => $base->copy()->addMinute(),
            'created_at' => $base->copy()->addMinute(),
            'updated_at' => $base->copy()->addMinute(),
        ]);
        $stateId = (int) DB::table('order_van_execution_states')->insertGetId([
            'order_van_assignment_id' => $assignmentId,
            'order_id' => $order->id,
            'van_id' => $vanId,
            'status' => 'failed',
            'failure_reason_code' => 'customer_no_answer',
            'failure_note' => 'DO NOT SHOW THIS STATE NOTE',
            'version' => 2,
            'last_transition_at' => $base->copy()->addMinutes(2),
            'created_at' => $base->copy()->addMinute(),
            'updated_at' => $base->copy()->addMinutes(2),
        ]);
        DB::table('order_van_execution_events')->insert([
            'order_van_assignment_id' => $assignmentId,
            'order_van_execution_state_id' => $stateId,
            'order_id' => $order->id,
            'van_id' => $vanId,
            'user_id' => null,
            'action' => 'failed',
            'idempotency_key' => 'customer-failed-event',
            'from_status' => 'out_for_delivery',
            'to_status' => 'failed',
            'proof_type' => 'failure_note',
            'proof_path' => null,
            'reason_code' => 'customer_no_answer',
            'note' => 'DO NOT SHOW THIS FAILURE NOTE',
            'captured_at' => $base->copy()->addMinutes(2),
            'created_at' => $base->copy()->addMinutes(2),
            'updated_at' => $base->copy()->addMinutes(2),
        ]);

        Sanctum::actingAs($this->b2bUser);

        $response = $this->getJson("/api/v1/b2b/orders/{$order->id}")
            ->assertOk()
            ->assertJsonPath('timeline.1.stage', 'van_assigned')
            ->assertJsonPath('timeline.1.van_code', 'VAN-FAILED-78')
            ->assertJsonPath('timeline.2.stage', 'failed')
            ->assertJsonPath('timeline.2.reason_code', 'customer_no_answer')
            ->assertJsonPath('tracking.actor_type', 'van')
            ->assertJsonPath('tracking.van_code', 'VAN-FAILED-78')
            ->assertJsonPath('tracking.status', 'failed')
            ->assertJsonPath('is_terminal', true);

        $payload = (string) $response->getContent();
        $this->assertStringNotContainsString('driver_name', $payload);
        $this->assertStringNotContainsString('DO NOT SHOW THIS FAILURE NOTE', $payload);
        $this->assertStringNotContainsString('DO NOT SHOW THIS STATE NOTE', $payload);
    }

    public function test_b2b_retry_timeline_preserves_failed_reason_then_returns_to_out_for_delivery(): void
    {
        $order = $this->makeOrder(
            $this->b2bCustomer,
            $this->b2bStoreId,
            'b2b',
            'out_for_delivery',
        );
        $base = now()->subMinutes(10)->startOfSecond();

        DB::table('orders')->where('id', $order->id)->update([
            'created_at' => $base,
            'updated_at' => $base->copy()->addMinutes(3),
        ]);
        DB::table('order_status_history')->where('order_id', $order->id)->delete();

        $vanId = (int) DB::table('vans')->insertGetId([
            'public_id' => '00000000-0000-0000-0000-000000000079',
            'code' => 'VAN-RETRY-79',
            'status' => 'active',
            'created_at' => $base,
            'updated_at' => $base,
        ]);
        $assignmentId = (int) DB::table('order_van_assignments')->insertGetId([
            'order_id' => $order->id,
            'van_id' => $vanId,
            'status' => 'active',
            'source' => 'smart_routing',
            'decision_key' => 'customer-retry-'.$order->id,
            'assigned_at' => $base->copy()->addMinute(),
            'created_at' => $base->copy()->addMinute(),
            'updated_at' => $base->copy()->addMinute(),
        ]);
        $stateId = (int) DB::table('order_van_execution_states')->insertGetId([
            'order_van_assignment_id' => $assignmentId,
            'order_id' => $order->id,
            'van_id' => $vanId,
            'status' => 'out_for_delivery',
            'failure_reason_code' => null,
            'failure_note' => null,
            'version' => 3,
            'last_transition_at' => $base->copy()->addMinutes(3),
            'created_at' => $base->copy()->addMinute(),
            'updated_at' => $base->copy()->addMinutes(3),
        ]);

        foreach ([
            [
                'action' => 'failed',
                'key' => 'customer-retry-failed',
                'from' => 'out_for_delivery',
                'to' => 'failed',
                'reason' => 'customer_no_answer',
                'note' => 'DO NOT SHOW RETRY FAILURE NOTE',
                'minute' => 2,
            ],
            [
                'action' => 'retry',
                'key' => 'customer-retry-success',
                'from' => 'failed',
                'to' => 'out_for_delivery',
                'reason' => null,
                'note' => 'DO NOT SHOW RETRY NOTE',
                'minute' => 3,
            ],
        ] as $event) {
            DB::table('order_van_execution_events')->insert([
                'order_van_assignment_id' => $assignmentId,
                'order_van_execution_state_id' => $stateId,
                'order_id' => $order->id,
                'van_id' => $vanId,
                'user_id' => null,
                'action' => $event['action'],
                'idempotency_key' => $event['key'],
                'from_status' => $event['from'],
                'to_status' => $event['to'],
                'proof_type' => $event['to'] === 'failed' ? 'failure_note' : null,
                'proof_path' => null,
                'reason_code' => $event['reason'],
                'note' => $event['note'],
                'captured_at' => $base->copy()->addMinutes($event['minute']),
                'created_at' => $base->copy()->addMinutes($event['minute']),
                'updated_at' => $base->copy()->addMinutes($event['minute']),
            ]);
        }

        Sanctum::actingAs($this->b2bUser);

        $response = $this->getJson("/api/v1/b2b/orders/{$order->id}")
            ->assertOk()
            ->assertJsonPath('timeline.0.stage', 'placed')
            ->assertJsonPath('timeline.1.stage', 'van_assigned')
            ->assertJsonPath('timeline.1.van_code', 'VAN-RETRY-79')
            ->assertJsonPath('timeline.2.stage', 'failed')
            ->assertJsonPath('timeline.2.reason_code', 'customer_no_answer')
            ->assertJsonPath('timeline.3.stage', 'out_for_delivery')
            ->assertJsonMissingPath('timeline.3.reason_code')
            ->assertJsonPath('tracking.actor_type', 'van')
            ->assertJsonPath('tracking.van_code', 'VAN-RETRY-79')
            ->assertJsonPath('tracking.status', 'out_for_delivery');

        $payload = (string) $response->getContent();
        $this->assertStringNotContainsString('driver_name', $payload);
        $this->assertStringNotContainsString('DO NOT SHOW RETRY FAILURE NOTE', $payload);
        $this->assertStringNotContainsString('DO NOT SHOW RETRY NOTE', $payload);
    }

    public function test_b2b_awaiting_dispatch_is_exposed_without_driver_fallback(): void
    {
        $order = $this->makeOrder($this->b2bCustomer, $this->b2bStoreId, 'b2b');
        $base = now()->subMinutes(5)->startOfSecond();

        DB::table('orders')->where('id', $order->id)->update([
            'created_at' => $base,
            'updated_at' => $base->copy()->addMinute(),
        ]);
        DB::table('order_status_history')->where('order_id', $order->id)->delete();
        DB::table('order_dispatch_states')->insert([
            'order_id' => $order->id,
            'status' => 'awaiting_dispatch',
            'routing_source' => 'smart_routing',
            'routing_reason' => 'no_eligible_van',
            'decided_at' => $base->copy()->addMinute(),
            'created_at' => $base->copy()->addMinute(),
            'updated_at' => $base->copy()->addMinute(),
        ]);

        Sanctum::actingAs($this->b2bUser);

        $response = $this->getJson("/api/v1/b2b/orders/{$order->id}")
            ->assertOk()
            ->assertJsonPath('timeline.0.stage', 'placed')
            ->assertJsonPath('timeline.1.stage', 'awaiting_dispatch')
            ->assertJsonPath('tracking.actor_type', 'van')
            ->assertJsonPath('tracking.status', 'awaiting_dispatch')
            ->assertJsonPath('tracking.van_code', null);

        $payload = (string) $response->getContent();
        $this->assertStringNotContainsString('driver_name', $payload);
        $this->assertStringNotContainsString('no_eligible_van', $payload);
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
