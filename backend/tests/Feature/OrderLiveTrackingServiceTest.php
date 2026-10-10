<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\FleetCurrentLocation;
use App\Models\Order;
use App\Models\OrderDispatchState;
use App\Models\OrderVanAssignment;
use App\Models\User;
use App\Models\Van;
use App\Services\OrderLiveTrackingService;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class OrderLiveTrackingServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_b2b_tracking_uses_van_and_never_legacy_driver(): void
    {
        [$order, $storeId] = $this->order('b2b', 'TRACK-B2B');
        $van = Van::query()->create([
            'public_id' => (string) Str::uuid(),
            'code' => 'TRACK-VAN',
            'status' => 'active',
        ]);
        $assignment = OrderVanAssignment::query()->create([
            'order_id' => $order->id,
            'van_id' => $van->id,
            'status' => 'active',
            'source' => 'test',
            'decision_key' => hash('sha256', 'tracking-b2b-'.$order->id),
            'assigned_at' => now()->subMinute(),
        ]);
        DB::table('order_van_execution_states')->insert([
            'order_van_assignment_id' => $assignment->id,
            'order_id' => $order->id,
            'van_id' => $van->id,
            'status' => 'out_for_delivery',
            'version' => 1,
            'last_transition_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        FleetCurrentLocation::query()->create([
            'actor_type' => 'van',
            'actor_id' => $van->id,
            'vehicle_id' => $van->id,
            'store_id' => $storeId,
            'channel' => 'b2b',
            'latitude' => 29.3759,
            'longitude' => 47.9774,
            'captured_at' => now(),
            'received_at' => now(),
            'source_app' => 'van',
        ]);

        $legacyUser = User::factory()->create();
        $driverId = (int) DB::table('drivers')->insertGetId([
            'user_id' => $legacyUser->id,
            'store_id' => $storeId,
            'driver_type' => 'b2b',
            'is_available' => true,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('driver_assignments')->insert([
            'driver_id' => $driverId,
            'order_id' => $order->id,
            'store_id' => $storeId,
            'assignment_type' => 'b2b',
            'status' => 'out_for_delivery',
            'assigned_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $tracking = app(OrderLiveTrackingService::class)->forOrder($order);

        $this->assertSame('van', $tracking['actor_type']);
        $this->assertSame($van->id, $tracking['van_id']);
        $this->assertSame('TRACK-VAN', $tracking['van_code']);
        $this->assertSame('out_for_delivery', $tracking['status']);
        $this->assertSame('online', $tracking['live_status']);
        $this->assertArrayNotHasKey('driver_id', $tracking);
        $this->assertArrayNotHasKey('driver_name', $tracking);

        FleetCurrentLocation::query()->where('actor_type', 'van')->where('actor_id', $van->id)
            ->update(['received_at' => now()->subSeconds(90), 'captured_at' => now()->subSeconds(90)]);
        $this->assertSame('stale', app(OrderLiveTrackingService::class)->forOrder($order)['live_status']);

        FleetCurrentLocation::query()->where('actor_type', 'van')->where('actor_id', $van->id)->delete();
        $offline = app(OrderLiveTrackingService::class)->forOrder($order);
        $this->assertSame('offline', $offline['live_status']);
        $this->assertNull($offline['location']);
    }

    public function test_b2b_awaiting_dispatch_is_explicit_without_fabricating_van_or_location(): void
    {
        [$order] = $this->order('b2b', 'TRACK-AWAITING');

        OrderDispatchState::query()->create([
            'order_id' => $order->id,
            'status' => 'awaiting_dispatch',
            'routing_source' => 'smart_routing',
            'routing_reason' => 'no_eligible_primary_van',
            'current_assignee_type' => null,
            'current_assignee_id' => null,
            'decision_key' => hash('sha256', 'tracking-awaiting-'.$order->id),
            'decided_at' => now(),
        ]);

        $tracking = app(OrderLiveTrackingService::class)->forOrder($order);

        $this->assertSame('van', $tracking['actor_type']);
        $this->assertSame('awaiting_dispatch', $tracking['status']);
        $this->assertSame('awaiting_dispatch', $tracking['dispatch_status']);
        $this->assertSame('offline', $tracking['live_status']);
        $this->assertNull($tracking['assignment_id']);
        $this->assertNull($tracking['van_id']);
        $this->assertNull($tracking['location']);
        $this->assertArrayNotHasKey('driver_id', $tracking);
    }

    public function test_b2c_tracking_stays_driver_even_if_van_assignment_exists(): void
    {
        [$order, $storeId] = $this->order('b2c', 'TRACK-B2C');
        $driverUser = User::factory()->create(['name' => 'Retail Driver']);
        $driverId = (int) DB::table('drivers')->insertGetId([
            'user_id' => $driverUser->id,
            'store_id' => $storeId,
            'driver_type' => 'b2c',
            'is_available' => true,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $driverAssignmentId = (int) DB::table('driver_assignments')->insertGetId([
            'driver_id' => $driverId,
            'order_id' => $order->id,
            'store_id' => $storeId,
            'assignment_type' => 'b2c',
            'status' => 'out_for_delivery',
            'assigned_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('driver_current_locations')->insert([
            'driver_id' => $driverId,
            'store_id' => $storeId,
            'channel' => 'b2c',
            'latitude' => 29.36,
            'longitude' => 47.99,
            'captured_at' => now(),
            'received_at' => now(),
            'active_assignment_id' => $driverAssignmentId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $van = Van::query()->create([
            'public_id' => (string) Str::uuid(),
            'code' => 'SHOULD-NOT-LEAK',
            'status' => 'active',
        ]);
        OrderVanAssignment::query()->create([
            'order_id' => $order->id,
            'van_id' => $van->id,
            'status' => 'active',
            'source' => 'test',
            'decision_key' => hash('sha256', 'tracking-b2c-'.$order->id),
            'assigned_at' => now(),
        ]);

        $tracking = app(OrderLiveTrackingService::class)->forOrder($order);

        $this->assertSame('driver', $tracking['actor_type']);
        $this->assertSame($driverId, $tracking['driver_id']);
        $this->assertSame('Retail Driver', $tracking['driver_name']);
        $this->assertSame('online', $tracking['live_status']);
        $this->assertArrayNotHasKey('van_id', $tracking);
    }

    /** @return array{0:Order,1:int} */
    private function order(string $channel, string $suffix): array
    {
        $storeTypeId = (int) DB::table('store_types')
            ->where('code', $channel === 'b2b' ? 'B2B' : 'B2C')
            ->value('id');
        $storeId = (int) DB::table('stores')->insertGetId([
            'store_type_id' => $storeTypeId,
            'code' => $suffix,
            'name' => $suffix,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $customer = Customer::query()->create([
            'type' => $channel,
            'name' => $suffix.' Customer',
            'email' => strtolower($suffix).'@example.test',
        ]);
        $order = Order::query()->create([
            'store_id' => $storeId,
            'customer_id' => $customer->id,
            'order_number' => $suffix.'-ORDER',
            'channel' => $channel,
            'status' => 'out_for_delivery',
            'currency' => 'KWD',
            'subtotal' => 10,
            'discount_total' => 0,
            'delivery_total' => 0,
            'tax_total' => 0,
            'grand_total' => 10,
            'payment_method' => 'cash_on_delivery',
        ]);

        return [$order, $storeId];
    }
}
