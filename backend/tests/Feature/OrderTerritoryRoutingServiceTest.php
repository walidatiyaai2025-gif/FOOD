<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\GeographyNode;
use App\Models\Order;
use App\Models\ServiceTerritory;
use App\Models\Store;
use App\Models\StoreType;
use App\Models\User;
use App\Models\VanAssignment;
use App\Services\OrderTerritoryRoutingService;
use App\Services\RoutingPolicyService;
use App\Services\TerritoryService;
use App\Services\VanRegistryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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

    public function test_automatic_reroute_preserves_previous_order_van_assignment_history(): void
    {
        [$order, $territory, $actor] = $this->orderInsideTerritory('HISTORY');
        $registry = app(VanRegistryService::class);
        $firstVan = $registry->createVan(['code' => 'VAN-HISTORY-A']);
        $firstTerritoryAssignment = $registry->assign($actor, $firstVan, [
            'territory_key' => $territory->code,
            'assignment_type' => 'primary',
            'effective_from' => now()->subMinutes(5),
        ]);

        $service = app(OrderTerritoryRoutingService::class);
        $first = $service->route($order, $actor);
        $this->assertSame($firstVan->id, $first->current_assignee_id);

        $firstTerritoryAssignment->forceFill([
            'status' => 'ended',
            'effective_until' => now(),
        ])->save();

        $secondVan = $registry->createVan(['code' => 'VAN-HISTORY-B']);
        $registry->assign($actor, $secondVan, [
            'territory_key' => $territory->code,
            'assignment_type' => 'primary',
            'effective_from' => now(),
        ]);

        $second = $service->route($order, $actor);

        $this->assertSame('assigned', $second->status);
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
        $this->assertSame(
            2,
            DB::table('order_van_assignments')->where('order_id', $order->id)->count(),
        );
    }

    public function test_automatic_routing_does_not_move_order_after_physical_custody(): void
    {
        [$order, $territory, $actor] = $this->orderInsideTerritory('CUSTODY');
        $registry = app(VanRegistryService::class);
        $firstVan = $registry->createVan(['code' => 'VAN-CUSTODY-A']);
        $firstTerritoryAssignment = $registry->assign($actor, $firstVan, [
            'territory_key' => $territory->code,
            'assignment_type' => 'primary',
            'effective_from' => now()->subMinutes(5),
        ]);

        $service = app(OrderTerritoryRoutingService::class);
        $first = $service->route($order, $actor);
        $orderAssignmentId = (int) DB::table('order_van_assignments')
            ->where('order_id', $order->id)
            ->where('status', 'active')
            ->value('id');

        DB::table('order_van_execution_states')
            ->where('order_van_assignment_id', $orderAssignmentId)
            ->update([
                'status' => 'picked_up',
                'last_transition_at' => now(),
                'updated_at' => now(),
            ]);

        $firstTerritoryAssignment->forceFill([
            'status' => 'ended',
            'effective_until' => now(),
        ])->save();

        $secondVan = $registry->createVan(['code' => 'VAN-CUSTODY-B']);
        $registry->assign($actor, $secondVan, [
            'territory_key' => $territory->code,
            'assignment_type' => 'primary',
            'effective_from' => now(),
        ]);

        $second = $service->route($order, $actor);

        $this->assertSame($first->decision_key, $second->decision_key);
        $this->assertSame($firstVan->id, $second->current_assignee_id);
        $this->assertDatabaseHas('order_van_assignments', [
            'id' => $orderAssignmentId,
            'order_id' => $order->id,
            'van_id' => $firstVan->id,
            'status' => 'active',
        ]);
        $this->assertDatabaseMissing('order_van_assignments', [
            'order_id' => $order->id,
            'van_id' => $secondVan->id,
        ]);
        $this->assertDatabaseHas('order_van_execution_states', [
            'order_van_assignment_id' => $orderAssignmentId,
            'status' => 'picked_up',
        ]);
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
