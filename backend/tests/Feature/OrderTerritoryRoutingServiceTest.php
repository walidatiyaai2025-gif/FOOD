<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\GeographyNode;
use App\Models\Order;
use App\Models\OrderVanAssignment;
use App\Models\OrderVanExecutionState;
use App\Models\ServiceTerritory;
use App\Models\Store;
use App\Models\StoreType;
use App\Models\User;
use App\Models\VanAssignment;
use App\Services\B2BOrderRoutingCoordinator;
use App\Services\OrderTerritoryRoutingService;
use App\Services\RoutingPolicyService;
use App\Services\TerritoryService;
use App\Services\VanRegistryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class OrderTerritoryRoutingServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_no_policy_polygon_with_unique_primary_van_auto_assigns_and_is_idempotent(): void
    {
        [$order, $territory, $actor] = $this->orderInsideTerritory('UNIQUE');
        $registry = app(VanRegistryService::class);
        $van = $registry->createVan(['code' => 'VAN-UNIQUE']);
        $registry->assign($actor, $van, [
            'territory_key' => $territory->code,
            'assignment_type' => 'primary',
            'effective_from' => '2026-10-01T00:00:00Z',
        ]);

        $service = app(OrderTerritoryRoutingService::class);
        $first = $service->route($order, $actor, '2026-10-08T12:00:00Z');
        $second = $service->route($order, $actor, '2026-10-08T12:00:00Z');

        $this->assertSame('assigned', $first->status);
        $this->assertSame('van', $first->current_assignee_type);
        $this->assertSame($van->id, $first->current_assignee_id);
        $this->assertSame('auto_territory_fallback', $first->routing_source);
        $this->assertSame('unique_eligible_primary_van', $first->routing_reason);
        $this->assertSame($first->decision_key, $second->decision_key);
        $this->assertDatabaseCount('order_van_assignments', 1);
        $this->assertDatabaseHas('order_van_assignments', [
            'order_id' => $order->id,
            'van_id' => $van->id,
            'status' => 'active',
            'source' => 'auto_territory_fallback',
        ]);
    }

    public function test_unmapped_or_missing_van_enters_awaiting_dispatch_without_guessing(): void
    {
        [$order, $territory, $actor] = $this->orderInsideTerritory('NO-VAN');

        $state = app(OrderTerritoryRoutingService::class)->route($order, $actor, '2026-10-08T12:00:00Z');

        $this->assertSame('awaiting_dispatch', $state->status);
        $this->assertSame($territory->id, $state->service_territory_id);
        $this->assertSame('no_eligible_primary_van', $state->routing_reason);
        $this->assertNull($state->current_assignee_id);
        $this->assertDatabaseCount('order_van_assignments', 0);
    }

    public function test_manual_published_policy_suppresses_polygon_fallback(): void
    {
        [$order, $territory, $actor] = $this->orderInsideTerritory('MANUAL');
        $registry = app(VanRegistryService::class);
        $van = $registry->createVan(['code' => 'VAN-MANUAL']);
        $registry->assign($actor, $van, [
            'territory_key' => $territory->code,
            'assignment_type' => 'primary',
            'effective_from' => '2026-10-01T00:00:00Z',
        ]);

        $routing = app(RoutingPolicyService::class);
        $policy = $routing->createDraft($actor, 'order_dispatch', 'MANUAL', [
            ['name' => 'all', 'conditions' => [], 'actions' => ['van_id' => $van->id]],
        ]);
        $routing->publish($actor, $policy);

        $state = app(OrderTerritoryRoutingService::class)->route($order, $actor, '2026-10-08T12:00:00Z');

        $this->assertSame('awaiting_dispatch', $state->status);
        $this->assertSame('routing_policy', $state->routing_source);
        $this->assertSame('manual_policy_mode', $state->routing_reason);
        $this->assertNull($state->current_assignee_id);
        $this->assertDatabaseCount('order_van_assignments', 0);
        $this->assertDatabaseCount('routing_decision_traces', 1);
    }

    public function test_legacy_ambiguous_primary_vans_are_sent_to_manual_queue(): void
    {
        [$order, $territory, $actor] = $this->orderInsideTerritory('AMBIG');
        $registry = app(VanRegistryService::class);
        $first = $registry->createVan(['code' => 'VAN-A']);
        $second = $registry->createVan(['code' => 'VAN-B']);

        foreach ([$first, $second] as $van) {
            VanAssignment::query()->create([
                'public_id' => (string) Str::uuid(),
                'van_id' => $van->id,
                'territory_key' => $territory->code,
                'assignment_type' => 'primary',
                'status' => 'active',
                'effective_from' => '2026-10-01 00:00:00',
                'loaded_work_count' => 0,
                'created_by' => $actor->id,
            ]);
        }

        $state = app(OrderTerritoryRoutingService::class)->route($order, $actor, '2026-10-08T12:00:00Z');

        $this->assertSame('awaiting_dispatch', $state->status);
        $this->assertSame('ambiguous_primary_vans', $state->routing_reason);
        $this->assertDatabaseCount('order_van_assignments', 0);
    }

    public function test_post_create_coordinator_routes_supported_b2b_sources_and_never_falls_back_to_driver(): void
    {
        [$firstOrder, $territory, $actor] = $this->orderInsideTerritory('POST-SOURCES');
        $registry = app(VanRegistryService::class);
        $van = $registry->createVan(['code' => 'VAN-POST-SOURCES']);
        $registry->assign($actor, $van, [
            'territory_key' => $territory->code,
            'assignment_type' => 'primary',
            'effective_from' => '2026-10-01T00:00:00Z',
        ]);

        foreach (['customer', 'dashboard', 'van', 'integration'] as $index => $source) {
            $order = $index === 0 ? $firstOrder : $firstOrder->replicate();
            if ($index !== 0) {
                $order->order_number = 'ROUTE-POST-SOURCES-'.$index;
                $order->save();
            }

            $state = app(B2BOrderRoutingCoordinator::class)->routeCreatedOrder($order, $actor, $source);

            $this->assertNotNull($state);
            $this->assertSame('assigned', $state->status);
            $this->assertSame('van', $state->current_assignee_type);
            $this->assertSame($van->id, $state->current_assignee_id);
            $this->assertDatabaseHas('order_van_assignments', [
                'order_id' => $order->id,
                'van_id' => $van->id,
                'status' => 'active',
            ]);
        }

        $this->assertDatabaseCount('driver_assignments', 0);
    }

    public function test_post_create_retry_keeps_existing_van_assignment_stable(): void
    {
        [$order, $territory, $actor] = $this->orderInsideTerritory('POST-RETRY');
        $registry = app(VanRegistryService::class);
        $firstVan = $registry->createVan(['code' => 'VAN-POST-RETRY-A']);
        $firstTerritoryAssignment = $registry->assign($actor, $firstVan, [
            'territory_key' => $territory->code,
            'assignment_type' => 'primary',
            'effective_from' => '2026-10-01T00:00:00Z',
        ]);

        $coordinator = app(B2BOrderRoutingCoordinator::class);
        $first = $coordinator->routeCreatedOrder($order, $actor, 'customer');

        $firstTerritoryAssignment->forceFill([
            'status' => 'ended',
            'effective_until' => '2026-10-08 13:00:00',
        ])->save();

        $secondVan = $registry->createVan(['code' => 'VAN-POST-RETRY-B']);
        $registry->assign($actor, $secondVan, [
            'territory_key' => $territory->code,
            'assignment_type' => 'primary',
            'effective_from' => '2026-10-08T13:00:00Z',
        ]);

        $second = $coordinator->routeCreatedOrder($order, $actor, 'customer');

        $this->assertNotNull($first);
        $this->assertNotNull($second);
        $this->assertSame($firstVan->id, $first->current_assignee_id);
        $this->assertSame($firstVan->id, $second->current_assignee_id);
        $this->assertDatabaseCount('order_van_assignments', 1);
        $this->assertDatabaseMissing('order_van_assignments', [
            'order_id' => $order->id,
            'van_id' => $secondVan->id,
        ]);
    }

    public function test_post_create_routing_inability_persists_explicit_awaiting_dispatch(): void
    {
        [$order, $territory, $actor] = $this->orderInsideTerritory('POST-PENDING');

        $state = app(B2BOrderRoutingCoordinator::class)->routeCreatedOrder($order, $actor, 'customer');

        $this->assertNotNull($state);
        $this->assertSame('awaiting_dispatch', $state->status);
        $this->assertSame($territory->id, $state->service_territory_id);
        $this->assertSame('no_eligible_primary_van', $state->routing_reason);
        $this->assertNull($state->current_assignee_id);
        $this->assertDatabaseCount('driver_assignments', 0);
    }

    public function test_reassignment_preserves_history_and_writes_explicit_audit(): void
    {
        [$order, $territory, $actor] = $this->orderInsideTerritory('REASSIGN');
        $registry = app(VanRegistryService::class);
        $firstVan = $registry->createVan(['code' => 'VAN-REASSIGN-A']);
        $firstTerritoryAssignment = $registry->assign($actor, $firstVan, [
            'territory_key' => $territory->code,
            'assignment_type' => 'primary',
            'effective_from' => '2026-10-01T00:00:00Z',
        ]);

        $service = app(OrderTerritoryRoutingService::class);
        $first = $service->route($order, $actor, '2026-10-08T12:00:00Z');

        $firstTerritoryAssignment->forceFill([
            'status' => 'ended',
            'effective_until' => '2026-10-08 13:00:00',
        ])->save();

        $secondVan = $registry->createVan(['code' => 'VAN-REASSIGN-B']);
        $registry->assign($actor, $secondVan, [
            'territory_key' => $territory->code,
            'assignment_type' => 'primary',
            'effective_from' => '2026-10-08T13:00:00Z',
        ]);

        $second = $service->route($order, $actor, '2026-10-08T14:00:00Z');

        $this->assertSame($firstVan->id, $first->current_assignee_id);
        $this->assertSame($secondVan->id, $second->current_assignee_id);
        $this->assertDatabaseHas('order_van_assignments', [
            'order_id' => $order->id,
            'van_id' => $firstVan->id,
            'status' => 'reassigned',
        ]);
        $this->assertDatabaseHas('order_van_assignments', [
            'order_id' => $order->id,
            'van_id' => $secondVan->id,
            'status' => 'active',
        ]);
        $this->assertSame(2, OrderVanAssignment::query()->where('order_id', $order->id)->count());
        $this->assertTrue(
            AuditLog::query()
                ->where('event', 'order.dispatch.reassigned')
                ->where('auditable_id', $order->id)
                ->exists(),
        );
    }

    public function test_picked_up_execution_blocks_silent_reroute_to_another_van(): void
    {
        [$order, $territory, $actor] = $this->orderInsideTerritory('LOCKED');
        $registry = app(VanRegistryService::class);
        $firstVan = $registry->createVan(['code' => 'VAN-LOCKED-A']);
        $firstTerritoryAssignment = $registry->assign($actor, $firstVan, [
            'territory_key' => $territory->code,
            'assignment_type' => 'primary',
            'effective_from' => '2026-10-01T00:00:00Z',
        ]);

        $service = app(OrderTerritoryRoutingService::class);
        $first = $service->route($order, $actor, '2026-10-08T12:00:00Z');
        $orderAssignment = OrderVanAssignment::query()
            ->where('order_id', $order->id)
            ->where('status', 'active')
            ->firstOrFail();
        OrderVanExecutionState::query()
            ->where('order_van_assignment_id', $orderAssignment->id)
            ->update([
                'status' => 'picked_up',
                'last_transition_at' => '2026-10-08 12:30:00',
            ]);

        $firstTerritoryAssignment->forceFill([
            'status' => 'ended',
            'effective_until' => '2026-10-08 13:00:00',
        ])->save();

        $secondVan = $registry->createVan(['code' => 'VAN-LOCKED-B']);
        $registry->assign($actor, $secondVan, [
            'territory_key' => $territory->code,
            'assignment_type' => 'primary',
            'effective_from' => '2026-10-08T13:00:00Z',
        ]);

        $second = $service->route($order, $actor, '2026-10-08T14:00:00Z');

        $this->assertSame($firstVan->id, $first->current_assignee_id);
        $this->assertSame($firstVan->id, $second->current_assignee_id);
        $this->assertDatabaseHas('order_van_assignments', [
            'id' => $orderAssignment->id,
            'van_id' => $firstVan->id,
            'status' => 'active',
        ]);
        $this->assertDatabaseMissing('order_van_assignments', [
            'order_id' => $order->id,
            'van_id' => $secondVan->id,
        ]);
        $this->assertTrue(
            AuditLog::query()
                ->where('event', 'order.dispatch.reroute_blocked')
                ->where('auditable_id', $order->id)
                ->exists(),
        );
    }

    public function test_production_order_creation_paths_use_shared_post_create_routing_coordinator(): void
    {
        $checkout = file_get_contents(app_path('Http/Controllers/Api/V1/CheckoutController.php'));
        $adminOrders = file_get_contents(app_path('Services/AdminOrderManagementService.php'));
        $vanOrders = file_get_contents(app_path('Http/Controllers/Api/V1/VanOrderController.php'));

        $this->assertIsString($checkout);
        $this->assertStringContainsString('B2BOrderRoutingCoordinator $routing', $checkout);
        $this->assertStringContainsString('$routing->routeCreatedOrder($order, $user, \'customer\')', $checkout);

        $this->assertIsString($adminOrders);
        $this->assertStringContainsString('private readonly B2BOrderRoutingCoordinator $routing', $adminOrders);
        $this->assertStringContainsString('$this->routing->routeCreatedOrder($order, $actor, $source)', $adminOrders);
        $this->assertStringContainsString('[\'dashboard\', \'van\', \'integration\']', $adminOrders);

        $this->assertIsString($vanOrders);
        $this->assertStringContainsString("'van',\n            'van',", $vanOrders);
    }

    public function test_b2c_order_cannot_enter_van_routing_runtime(): void
    {
        [$order, $territory, $actor] = $this->orderInsideTerritory('B2C-GUARD');
        $order->forceFill(['channel' => 'b2c'])->save();

        $registry = app(VanRegistryService::class);
        $van = $registry->createVan(['code' => 'VAN-B2C-GUARD']);
        $registry->assign($actor, $van, [
            'territory_key' => $territory->code,
            'assignment_type' => 'primary',
            'effective_from' => '2026-10-01T00:00:00Z',
        ]);

        $this->expectException(ValidationException::class);
        app(OrderTerritoryRoutingService::class)->route($order, $actor, '2026-10-08T12:00:00Z');
    }

    /** @return array{0:Order,1:ServiceTerritory,2:User} */
    private function orderInsideTerritory(string $suffix): array
    {
        $actor = User::factory()->create();
        $type = StoreType::query()->create(['code' => 'type-'.strtolower($suffix), 'name' => 'Type '.$suffix]);
        $store = Store::query()->create(['store_type_id' => $type->id, 'code' => 'STORE-'.$suffix, 'name' => 'Store '.$suffix]);
        $customer = Customer::query()->create(['name' => 'Customer '.$suffix, 'type' => 'b2b']);

        $country = GeographyNode::query()->create([
            'type' => 'country',
            'code' => 'country-'.strtolower($suffix),
            'name_ar' => 'مصر',
            'name_en' => 'Egypt',
            'country_code' => substr(str_pad($suffix, 3, 'X'), 0, 3),
            'is_active' => true,
        ]);
        $territory = ServiceTerritory::query()->create([
            'code' => 'TERR-'.$suffix,
            'name_ar' => 'منطقة '.$suffix,
            'name_en' => 'Territory '.$suffix,
            'country_node_id' => $country->id,
            'status' => 'active',
            'priority' => 100,
        ]);
        app(TerritoryService::class)->addGeometry($territory, [
            'type' => 'Polygon',
            'coordinates' => [[
                [30.0, 30.0], [31.0, 30.0], [31.0, 31.0], [30.0, 31.0], [30.0, 30.0],
            ]],
        ]);

        $order = Order::query()->create([
            'store_id' => $store->id,
            'customer_id' => $customer->id,
            'order_number' => 'ROUTE-'.$suffix,
            'channel' => 'b2b',
            'status' => 'pending',
            'currency' => 'KWD',
            'subtotal' => 1,
            'discount_total' => 0,
            'delivery_total' => 0,
            'grand_total' => 1,
            'delivery_latitude' => 30.5,
            'delivery_longitude' => 30.5,
        ]);

        return [$order, $territory, $actor];
    }
}
