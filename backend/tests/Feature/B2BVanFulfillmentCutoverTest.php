<?php

namespace Tests\Feature;

use App\Models\B2BVanCutoverRun;
use App\Models\Customer;
use App\Models\Driver;
use App\Models\DriverAssignment;
use App\Models\GeographyNode;
use App\Models\Order;
use App\Models\OrderDispatchState;
use App\Models\OrderVanAssignment;
use App\Models\OrderVanExecutionState;
use App\Models\ServiceTerritory;
use App\Models\Store;
use App\Models\StoreType;
use App\Models\User;
use App\Services\B2BVanFulfillmentCutoverService;
use App\Services\TerritoryService;
use App\Services\VanRegistryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class B2BVanFulfillmentCutoverTest extends TestCase
{
    use RefreshDatabase;

    public function test_apply_ends_legacy_b2b_driver_preserves_history_and_routes_to_van_idempotently(): void
    {
        [$order, $territory, $actor, $store] = $this->orderInsideTerritory('CUTOVER-APPLY', 'b2b');
        [$driver, $driverAssignment] = $this->activeDriver($order, $store, 'b2b');

        OrderDispatchState::query()->create([
            'order_id' => $order->id,
            'status' => 'assigned',
            'routing_source' => 'legacy',
            'routing_reason' => 'legacy_driver',
            'current_assignee_type' => 'driver',
            'current_assignee_id' => $driver->id,
            'decision_key' => hash('sha256', 'legacy-driver-'.$order->id),
            'decided_at' => '2026-10-09 12:00:00',
        ]);

        $registry = app(VanRegistryService::class);
        $van = $registry->createVan(['code' => 'VAN-CUTOVER-APPLY']);
        $registry->assign($actor, $van, [
            'territory_key' => $territory->code,
            'assignment_type' => 'primary',
            'effective_from' => '2026-10-01T00:00:00Z',
        ]);

        $service = app(B2BVanFulfillmentCutoverService::class);
        $first = $service->apply($actor, '2026-10-10T08:00:00Z');

        $this->assertSame('completed', $first['status']);
        $this->assertSame(1, $first['snapshot_count']);
        $this->assertSame(0, $first['summary']['postflight']['contradictory_orders']);
        $this->assertSame(0, $first['summary']['postflight']['b2b_active_driver']);
        $this->assertSame(0, $first['summary']['postflight']['b2b_requires_van_routing']);

        $this->assertDatabaseHas('driver_assignments', [
            'id' => $driverAssignment->id,
            'status' => 'reassigned',
        ]);
        $this->assertDatabaseHas('order_van_assignments', [
            'order_id' => $order->id,
            'van_id' => $van->id,
            'status' => 'active',
        ]);
        $this->assertDatabaseHas('order_dispatch_states', [
            'order_id' => $order->id,
            'status' => 'assigned',
            'current_assignee_type' => 'van',
            'current_assignee_id' => $van->id,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'fulfillment.cutover.driver_ended',
            'auditable_id' => $driverAssignment->id,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'fulfillment.cutover.order_reconciled',
            'auditable_id' => $order->id,
        ]);

        $second = $service->apply($actor, '2026-10-10T09:00:00Z');

        $this->assertSame('completed', $second['status']);
        $this->assertSame(0, $second['snapshot_count']);
        $this->assertSame(1, DriverAssignment::query()->where('order_id', $order->id)->count());
        $this->assertSame(
            1,
            OrderVanAssignment::query()
                ->where('order_id', $order->id)
                ->where('status', 'active')
                ->count(),
        );
    }

    public function test_b2b_without_eligible_van_becomes_explicit_awaiting_dispatch_and_retry_is_safe(): void
    {
        [$order, $territory, $actor, $store] = $this->orderInsideTerritory('CUTOVER-AWAIT', 'b2b');
        [$driver, $driverAssignment] = $this->activeDriver($order, $store, 'b2b');

        OrderDispatchState::query()->create([
            'order_id' => $order->id,
            'status' => 'assigned',
            'service_territory_id' => $territory->id,
            'routing_source' => 'legacy',
            'routing_reason' => 'legacy_driver',
            'current_assignee_type' => 'driver',
            'current_assignee_id' => $driver->id,
            'decision_key' => hash('sha256', 'legacy-await-'.$order->id),
            'decided_at' => '2026-10-09 12:00:00',
        ]);

        $service = app(B2BVanFulfillmentCutoverService::class);
        $first = $service->apply($actor, '2026-10-10T08:00:00Z');

        $this->assertSame(0, $first['summary']['postflight']['contradictory_orders']);
        $this->assertDatabaseHas('driver_assignments', [
            'id' => $driverAssignment->id,
            'status' => 'reassigned',
        ]);
        $this->assertDatabaseHas('order_dispatch_states', [
            'order_id' => $order->id,
            'status' => 'awaiting_dispatch',
            'current_assignee_type' => null,
            'current_assignee_id' => null,
        ]);
        $this->assertDatabaseCount('order_van_assignments', 0);

        $second = $service->apply($actor, '2026-10-10T09:00:00Z');

        $this->assertSame('completed', $second['status']);
        $this->assertSame(0, $second['summary']['postflight']['contradictory_orders']);
        $this->assertSame(0, $second['snapshot_count']);
        $this->assertDatabaseCount('driver_assignments', 1);
        $this->assertDatabaseCount('order_van_assignments', 0);
    }

    public function test_b2c_van_contradiction_is_ended_while_active_driver_is_preserved(): void
    {
        [$order, $territory, $actor, $store] = $this->orderInsideTerritory('CUTOVER-B2C', 'b2c');
        [$driver, $driverAssignment] = $this->activeDriver($order, $store, 'b2c');

        $registry = app(VanRegistryService::class);
        $van = $registry->createVan(['code' => 'VAN-CUTOVER-B2C']);
        $vanAssignment = OrderVanAssignment::query()->create([
            'order_id' => $order->id,
            'van_id' => $van->id,
            'service_territory_id' => $territory->id,
            'status' => 'active',
            'source' => 'legacy',
            'reason' => 'wrong_actor',
            'decision_key' => hash('sha256', 'b2c-wrong-van-'.$order->id),
            'assigned_at' => '2026-10-09 12:00:00',
        ]);

        OrderDispatchState::query()->create([
            'order_id' => $order->id,
            'status' => 'assigned',
            'service_territory_id' => $territory->id,
            'routing_source' => 'legacy',
            'routing_reason' => 'wrong_van',
            'current_assignee_type' => 'van',
            'current_assignee_id' => $van->id,
            'decision_key' => hash('sha256', 'b2c-wrong-dispatch-'.$order->id),
            'decided_at' => '2026-10-09 12:00:00',
        ]);

        $result = app(B2BVanFulfillmentCutoverService::class)
            ->apply($actor, '2026-10-10T08:00:00Z');

        $this->assertSame(0, $result['summary']['postflight']['contradictory_orders']);
        $this->assertDatabaseHas('order_van_assignments', [
            'id' => $vanAssignment->id,
            'status' => 'ended',
        ]);
        $this->assertDatabaseHas('driver_assignments', [
            'id' => $driverAssignment->id,
            'status' => 'assigned',
        ]);
        $this->assertDatabaseHas('order_dispatch_states', [
            'order_id' => $order->id,
            'status' => 'assigned',
            'current_assignee_type' => 'driver',
            'current_assignee_id' => $driver->id,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'fulfillment.cutover.b2c_van_ended',
            'auditable_id' => $order->id,
        ]);
    }

    public function test_rollback_restores_pre_cutover_driver_state_without_deleting_cutover_history(): void
    {
        [$order, $territory, $actor, $store] = $this->orderInsideTerritory('CUTOVER-ROLLBACK', 'b2b');
        [$driver, $driverAssignment] = $this->activeDriver($order, $store, 'b2b');

        $legacyDispatch = OrderDispatchState::query()->create([
            'order_id' => $order->id,
            'status' => 'assigned',
            'routing_source' => 'legacy',
            'routing_reason' => 'legacy_driver',
            'current_assignee_type' => 'driver',
            'current_assignee_id' => $driver->id,
            'decision_key' => hash('sha256', 'rollback-legacy-'.$order->id),
            'decided_at' => '2026-10-09 12:00:00',
        ]);

        $registry = app(VanRegistryService::class);
        $van = $registry->createVan(['code' => 'VAN-CUTOVER-ROLLBACK']);
        $registry->assign($actor, $van, [
            'territory_key' => $territory->code,
            'assignment_type' => 'primary',
            'effective_from' => '2026-10-01T00:00:00Z',
        ]);

        $service = app(B2BVanFulfillmentCutoverService::class);
        $applied = $service->apply($actor, '2026-10-10T08:00:00Z');
        $cutoverAssignment = OrderVanAssignment::query()
            ->where('order_id', $order->id)
            ->where('status', 'active')
            ->firstOrFail();

        $rolledBack = $service->rollback($applied['run_id'], $actor, '2026-10-10T08:30:00Z');

        $this->assertSame('rolled_back', $rolledBack['status']);
        $this->assertSame(1, $rolledBack['summary']['rollback']['restored_orders']);
        $this->assertSame(0, $rolledBack['summary']['rollback']['conflicts']);

        $this->assertDatabaseHas('driver_assignments', [
            'id' => $driverAssignment->id,
            'status' => 'assigned',
            'completed_at' => null,
        ]);
        $this->assertDatabaseHas('order_dispatch_states', [
            'id' => $legacyDispatch->id,
            'status' => 'assigned',
            'current_assignee_type' => 'driver',
            'current_assignee_id' => $driver->id,
        ]);
        $this->assertDatabaseHas('order_van_assignments', [
            'id' => $cutoverAssignment->id,
            'status' => 'ended',
            'reason' => 'cutover_rollback',
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'fulfillment.cutover.order_rolled_back',
            'auditable_id' => $order->id,
        ]);
        $this->assertDatabaseHas('b2b_van_cutover_snapshots', [
            'order_id' => $order->id,
            'b2b_van_cutover_run_id' => B2BVanCutoverRun::query()
                ->where('public_id', $applied['run_id'])
                ->value('id'),
        ]);
    }

    public function test_rollback_refuses_to_overwrite_new_van_execution_activity(): void
    {
        [$order, $territory, $actor, $store] = $this->orderInsideTerritory('CUTOVER-CONFLICT', 'b2b');
        [$driver] = $this->activeDriver($order, $store, 'b2b');

        OrderDispatchState::query()->create([
            'order_id' => $order->id,
            'status' => 'assigned',
            'routing_source' => 'legacy',
            'routing_reason' => 'legacy_driver',
            'current_assignee_type' => 'driver',
            'current_assignee_id' => $driver->id,
            'decision_key' => hash('sha256', 'conflict-legacy-'.$order->id),
            'decided_at' => '2026-10-09 12:00:00',
        ]);

        $registry = app(VanRegistryService::class);
        $van = $registry->createVan(['code' => 'VAN-CUTOVER-CONFLICT']);
        $registry->assign($actor, $van, [
            'territory_key' => $territory->code,
            'assignment_type' => 'primary',
            'effective_from' => '2026-10-01T00:00:00Z',
        ]);

        $service = app(B2BVanFulfillmentCutoverService::class);
        $applied = $service->apply($actor, '2026-10-10T08:00:00Z');

        $execution = OrderVanExecutionState::query()
            ->where('order_id', $order->id)
            ->firstOrFail();
        $execution->forceFill([
            'status' => 'picked_up',
            'version' => $execution->version + 1,
            'last_transition_at' => '2026-10-10 08:10:00',
        ])->save();

        $rolledBack = $service->rollback($applied['run_id'], $actor, '2026-10-10T08:30:00Z');

        $this->assertSame('partial_rollback', $rolledBack['status']);
        $this->assertSame(0, $rolledBack['summary']['rollback']['restored_orders']);
        $this->assertSame(1, $rolledBack['summary']['rollback']['conflicts']);
        $this->assertDatabaseHas('order_van_execution_states', [
            'id' => $execution->id,
            'status' => 'picked_up',
        ]);
        $this->assertDatabaseHas('b2b_van_cutover_snapshots', [
            'order_id' => $order->id,
            'rolled_back_at' => null,
        ]);
    }

    public function test_console_command_is_read_only_by_default_and_supports_json_apply(): void
    {
        [$order] = $this->orderInsideTerritory('CUTOVER-CMD', 'b2b');

        $beforeRuns = B2BVanCutoverRun::query()->count();
        $exit = Artisan::call('foodex:cutover-b2b-van', ['--json' => true]);
        $preview = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('dry-run', $preview['mode']);
        $this->assertSame($beforeRuns, B2BVanCutoverRun::query()->count());
        $this->assertSame($order->id, $preview['rows'][0]['order_id']);

        $exit = Artisan::call('foodex:cutover-b2b-van', [
            '--apply' => true,
            '--json' => true,
        ]);
        $applied = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('completed', $applied['status']);
        $this->assertSame(1, B2BVanCutoverRun::query()->count());
        $this->assertDatabaseHas('order_dispatch_states', [
            'order_id' => $order->id,
            'status' => 'awaiting_dispatch',
        ]);
    }

    /** @return array{0:Order,1:ServiceTerritory,2:User,3:Store} */
    private function orderInsideTerritory(string $suffix, string $channel): array
    {
        $actor = User::factory()->create();
        $type = StoreType::query()->create([
            'code' => 'type-'.strtolower($suffix),
            'name' => 'Type '.$suffix,
        ]);
        $store = Store::query()->create([
            'store_type_id' => $type->id,
            'code' => 'STORE-'.$suffix,
            'name' => 'Store '.$suffix,
        ]);
        $customer = Customer::query()->create([
            'name' => 'Customer '.$suffix,
            'type' => $channel,
        ]);

        $country = GeographyNode::query()->create([
            'type' => 'country',
            'code' => 'country-'.strtolower($suffix),
            'name_ar' => '???? '.$suffix,
            'name_en' => 'Country '.$suffix,
            'country_code' => strtoupper(substr(hash('sha256', $suffix), 0, 3)),
            'is_active' => true,
        ]);
        $territory = ServiceTerritory::query()->create([
            'code' => 'TERR-'.$suffix,
            'name_ar' => '????? '.$suffix,
            'name_en' => 'Territory '.$suffix,
            'country_node_id' => $country->id,
            'status' => 'active',
            'priority' => 100,
        ]);
        app(TerritoryService::class)->addGeometry($territory, [
            'type' => 'Polygon',
            'coordinates' => [[
                [30.0, 30.0],
                [31.0, 30.0],
                [31.0, 31.0],
                [30.0, 31.0],
                [30.0, 30.0],
            ]],
        ]);

        $order = Order::query()->create([
            'store_id' => $store->id,
            'customer_id' => $customer->id,
            'order_number' => 'ORDER-'.$suffix,
            'channel' => $channel,
            'status' => 'pending',
            'currency' => 'KWD',
            'subtotal' => 10,
            'discount_total' => 0,
            'delivery_total' => 0,
            'grand_total' => 10,
            'delivery_latitude' => 30.5,
            'delivery_longitude' => 30.5,
        ]);

        return [$order, $territory, $actor, $store];
    }

    /** @return array{0:Driver,1:DriverAssignment} */
    private function activeDriver(Order $order, Store $store, string $type): array
    {
        $driver = Driver::query()->create([
            'user_id' => User::factory()->create()->id,
            'store_id' => $store->id,
            'driver_type' => $type,
            'is_available' => true,
            'is_active' => true,
        ]);
        $assignment = DriverAssignment::query()->create([
            'driver_id' => $driver->id,
            'order_id' => $order->id,
            'store_id' => $store->id,
            'assignment_type' => $type,
            'status' => 'assigned',
            'assigned_at' => '2026-10-09 12:00:00',
        ]);

        return [$driver, $assignment];
    }
}
