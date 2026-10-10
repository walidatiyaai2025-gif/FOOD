<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\FleetCurrentLocation;
use App\Models\Order;
use App\Models\OrderDispatchState;
use App\Models\OrderVanAssignment;
use App\Models\Role;
use App\Models\User;
use App\Models\Van;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class OrderLiveTrackingDashboardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_b2b_order_filter_resolves_current_van_location_without_location_scope_guessing(): void
    {
        [$order] = $this->order('b2b', 'DASH-TRACK-B2B');
        $van = Van::query()->create([
            'public_id' => (string) Str::uuid(),
            'code' => 'DASH-TRACK-VAN',
            'status' => 'active',
        ]);
        $assignment = OrderVanAssignment::query()->create([
            'order_id' => $order->id,
            'van_id' => $van->id,
            'status' => 'active',
            'source' => 'smart_routing',
            'reason' => 'territory_match',
            'decision_key' => hash('sha256', 'dashboard-tracking-'.$order->id),
            'assigned_at' => now(),
        ]);
        OrderDispatchState::query()->create([
            'order_id' => $order->id,
            'status' => 'assigned',
            'routing_source' => 'smart_routing',
            'routing_reason' => 'territory_match',
            'current_assignee_type' => 'van',
            'current_assignee_id' => $van->id,
            'decision_key' => hash('sha256', 'dashboard-dispatch-'.$order->id),
            'context' => ['order_van_assignment_id' => $assignment->id],
            'decided_at' => now(),
        ]);
        FleetCurrentLocation::query()->create([
            'actor_type' => 'van',
            'actor_id' => $van->id,
            'vehicle_id' => $van->id,
            'store_id' => null,
            'channel' => null,
            'latitude' => 29.3759,
            'longitude' => 47.9774,
            'accuracy' => 4.5,
            'captured_at' => now(),
            'received_at' => now(),
            'source_app' => 'van',
        ]);

        Sanctum::actingAs($this->admin());

        $this->getJson('/api/v1/admin/field-operations/fleet/feed?order_id='.$order->id)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.actor_type', 'van')
            ->assertJsonPath('data.0.actor_id', $van->id)
            ->assertJsonPath('data.0.status', 'online')
            ->assertJsonPath('meta.order_tracking.actor_type', 'van')
            ->assertJsonPath('meta.order_tracking.van_id', $van->id)
            ->assertJsonPath('meta.order_tracking.live_status', 'online')
            ->assertJsonPath('meta.order_tracking.location.latitude', 29.3759);
    }

    public function test_b2b_awaiting_dispatch_filter_is_explicit_and_never_fabricates_a_pin(): void
    {
        [$order] = $this->order('b2b', 'DASH-TRACK-AWAITING');
        OrderDispatchState::query()->create([
            'order_id' => $order->id,
            'status' => 'awaiting_dispatch',
            'routing_source' => 'smart_routing',
            'routing_reason' => 'no_eligible_primary_van',
            'current_assignee_type' => null,
            'current_assignee_id' => null,
            'decision_key' => hash('sha256', 'dashboard-awaiting-'.$order->id),
            'decided_at' => now(),
        ]);

        $unrelatedVan = Van::query()->create([
            'public_id' => (string) Str::uuid(),
            'code' => 'UNRELATED-VAN',
            'status' => 'active',
        ]);
        FleetCurrentLocation::query()->create([
            'actor_type' => 'van',
            'actor_id' => $unrelatedVan->id,
            'vehicle_id' => $unrelatedVan->id,
            'latitude' => 29.5,
            'longitude' => 48.1,
            'captured_at' => now(),
            'received_at' => now(),
            'source_app' => 'van',
        ]);

        Sanctum::actingAs($this->admin());

        $this->getJson('/api/v1/admin/field-operations/fleet/feed?order_id='.$order->id)
            ->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('meta.order_tracking.actor_type', 'van')
            ->assertJsonPath('meta.order_tracking.status', 'awaiting_dispatch')
            ->assertJsonPath('meta.order_tracking.live_status', 'offline')
            ->assertJsonPath('meta.order_tracking.van_id', null)
            ->assertJsonPath('meta.order_tracking.location', null);
    }

    public function test_b2c_order_filter_keeps_driver_truth_and_never_resolves_contradictory_van_location(): void
    {
        [$order, $storeId] = $this->order('b2c', 'DASH-TRACK-B2C');
        $driverUser = User::factory()->create(['name' => 'Retail Tracking Driver']);
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

        $wrongVan = Van::query()->create([
            'public_id' => (string) Str::uuid(),
            'code' => 'WRONG-B2C-VAN',
            'status' => 'active',
        ]);
        OrderVanAssignment::query()->create([
            'order_id' => $order->id,
            'van_id' => $wrongVan->id,
            'status' => 'active',
            'source' => 'legacy',
            'reason' => 'contradiction',
            'decision_key' => hash('sha256', 'wrong-b2c-van-'.$order->id),
            'assigned_at' => now(),
        ]);
        FleetCurrentLocation::query()->create([
            'actor_type' => 'van',
            'actor_id' => $wrongVan->id,
            'vehicle_id' => $wrongVan->id,
            'latitude' => 29.9,
            'longitude' => 48.4,
            'captured_at' => now(),
            'received_at' => now(),
            'source_app' => 'van',
        ]);

        Sanctum::actingAs($this->admin());

        $this->getJson('/api/v1/admin/field-operations/fleet/feed?order_id='.$order->id)
            ->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('meta.order_tracking.actor_type', 'driver')
            ->assertJsonPath('meta.order_tracking.driver_id', $driverId)
            ->assertJsonPath('meta.order_tracking.live_status', 'online')
            ->assertJsonMissingPath('meta.order_tracking.van_id');

        $this->getJson('/api/v1/admin/driver-live-tracking/feed?order_id='.$order->id)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.driver_id', $driverId)
            ->assertJsonPath('data.0.channel', 'b2c');
    }

    public function test_web_dashboard_fleet_feed_passes_order_tracking_resolver_to_van_feed(): void
    {
        [$order] = $this->order('b2b', 'WEB-TRACK-B2B');
        $van = Van::query()->create([
            'public_id' => (string) Str::uuid(),
            'code' => 'WEB-TRACK-VAN',
            'status' => 'active',
        ]);
        $assignment = OrderVanAssignment::query()->create([
            'order_id' => $order->id,
            'van_id' => $van->id,
            'status' => 'active',
            'source' => 'smart_routing',
            'reason' => 'territory_match',
            'decision_key' => hash('sha256', 'web-dashboard-tracking-'.$order->id),
            'assigned_at' => now(),
        ]);
        OrderDispatchState::query()->create([
            'order_id' => $order->id,
            'status' => 'assigned',
            'routing_source' => 'smart_routing',
            'routing_reason' => 'territory_match',
            'current_assignee_type' => 'van',
            'current_assignee_id' => $van->id,
            'decision_key' => hash('sha256', 'web-dashboard-dispatch-'.$order->id),
            'context' => ['order_van_assignment_id' => $assignment->id],
            'decided_at' => now(),
        ]);
        FleetCurrentLocation::query()->create([
            'actor_type' => 'van',
            'actor_id' => $van->id,
            'vehicle_id' => $van->id,
            'latitude' => 29.3759,
            'longitude' => 47.9774,
            'captured_at' => now(),
            'received_at' => now(),
            'source_app' => 'van',
        ]);

        $this->actingAs($this->admin())
            ->getJson(route('admin.field-operations.fleet.feed', ['order_id' => $order->id]))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.actor_type', 'van')
            ->assertJsonPath('data.0.actor_id', $van->id)
            ->assertJsonPath('meta.order_tracking.van_id', $van->id);
    }

    public function test_live_map_sends_order_filter_to_both_driver_and_van_feeds(): void
    {
        $javascript = file_get_contents(public_path('assets/admin/driver-live-map.js'));
        $view = file_get_contents(resource_path('views/admin/_driver-live-map.blade.php'));

        $this->assertIsString($javascript);
        $this->assertIsString($view);
        $this->assertStringContainsString("order_id: inputValue('order-id')", $javascript);
        $this->assertStringNotContainsString("order_id: actorKind === 'driver'", $javascript);
        $this->assertStringContainsString('data-live-map="order-id"', $view);
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

    private function admin(): User
    {
        $user = User::query()->create([
            'name' => 'Tracking Super Admin',
            'email' => 'tracking-w10-admin@example.test',
            'password' => 'password',
            'is_active' => true,
        ]);
        $user->roles()->attach(Role::query()->where('code', 'SUPER_ADMIN')->firstOrFail());

        return $user;
    }
}
