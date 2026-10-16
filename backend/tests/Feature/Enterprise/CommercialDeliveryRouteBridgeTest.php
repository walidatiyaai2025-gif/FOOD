<?php

namespace Tests\Feature\Enterprise;

use App\Enterprise\Commercial\CommercialDeliveryRouteBridge;
use App\Enterprise\Commercial\CommercialEventEnvelope;
use App\Models\Customer;
use App\Models\GeographyNode;
use App\Models\Order;
use App\Models\OrderVanAssignment;
use App\Models\Role;
use App\Models\ServiceTerritory;
use App\Models\Store;
use App\Models\StoreType;
use App\Models\User;
use App\Services\TerritoryService;
use App\Services\VanRegistryService;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\TestCase;

final class CommercialDeliveryRouteBridgeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_handoff_is_versioned_retry_stable_and_does_not_touch_delivery_state(): void
    {
        $order = $this->b2bOrder('HANDOFF');

        $bridge = app(CommercialDeliveryRouteBridge::class);
        $first = $bridge->handoff($order, 'checkout');
        $second = $bridge->handoff($order, 'integration_retry');

        $this->assertSame('foodex.commercial.order.changed', $first->name);
        $this->assertSame(1, $first->version);
        $this->assertSame('order', $first->aggregateType);
        $this->assertSame($order->id, $first->aggregateId);
        $this->assertSame($order->store_id, $first->storeId);
        $this->assertSame('b2b', $first->channel);
        $this->assertSame('delivery_route', $first->payload['integration']);
        $this->assertSame($first->payload['handoff_key'], $second->payload['handoff_key']);
        $this->assertSame('checkout', $first->payload['source']);
        $this->assertSame('integration_retry', $second->payload['source']);

        $this->assertDatabaseCount('order_dispatch_states', 0);
        $this->assertDatabaseCount('order_van_assignments', 0);
        $this->assertDatabaseCount('driver_assignments', 0);
    }

    public function test_apply_reuses_existing_routing_authority_and_duplicate_handoff_is_idempotent(): void
    {
        [$order, $territory, $actor] = $this->routableB2bOrder('APPLY');
        $registry = app(VanRegistryService::class);
        $van = $registry->createVan(['code' => 'VAN-APPLY']);
        $registry->assign($actor, $van, [
            'territory_key' => $territory->code,
            'assignment_type' => 'primary',
            'effective_from' => '2026-10-01T00:00:00Z',
        ]);

        $bridge = app(CommercialDeliveryRouteBridge::class);
        $event = $bridge->handoff($order, 'checkout');
        $first = $bridge->apply($event, $actor);
        $second = $bridge->apply($event, $actor);

        $this->assertNotNull($first);
        $this->assertNotNull($second);
        $this->assertSame('assigned', $first->status);
        $this->assertSame('van', $first->current_assignee_type);
        $this->assertSame($van->id, $first->current_assignee_id);
        $this->assertSame($first->decision_key, $second->decision_key);
        $this->assertDatabaseCount('order_van_assignments', 1);
        $this->assertDatabaseCount('driver_assignments', 0);
    }

    public function test_apply_degrades_to_explicit_pending_dispatch_without_invalidating_order(): void
    {
        [$order, $territory, $actor] = $this->routableB2bOrder('DEGRADED');

        $bridge = app(CommercialDeliveryRouteBridge::class);
        $event = $bridge->handoff($order, 'checkout');
        $state = $bridge->apply($event, $actor);

        $this->assertNotNull($state);
        $this->assertSame('awaiting_dispatch', $state->status);
        $this->assertSame($territory->id, $state->service_territory_id);
        $this->assertSame('no_eligible_primary_van', $state->routing_reason);
        $this->assertNull($state->current_assignee_id);
        $this->assertDatabaseHas('orders', ['id' => $order->id]);
        $this->assertDatabaseCount('order_van_assignments', 0);
        $this->assertDatabaseCount('driver_assignments', 0);
    }

    public function test_reference_is_authorized_and_never_resurrects_a_stale_van_assignment(): void
    {
        [$order, $territory, $routingActor] = $this->routableB2bOrder('REFERENCE');
        $registry = app(VanRegistryService::class);
        $van = $registry->createVan(['code' => 'VAN-REFERENCE']);
        $registry->assign($routingActor, $van, [
            'territory_key' => $territory->code,
            'assignment_type' => 'primary',
            'effective_from' => '2026-10-01T00:00:00Z',
        ]);

        $bridge = app(CommercialDeliveryRouteBridge::class);
        $state = $bridge->apply($bridge->handoff($order, 'dashboard'), $routingActor);
        $this->assertNotNull($state);

        $authorized = $this->superAdmin();
        $reference = $bridge->reference($authorized, $order->id);

        $this->assertSame($order->id, $reference['order']['id']);
        $this->assertSame('assigned', $reference['dispatch']['status']);
        $this->assertSame('van', $reference['assignment']['actor_type']);
        $this->assertSame($van->id, $reference['assignment']['actor_id']);
        $this->assertSame('active', $reference['assignment']['status']);
        $this->assertSame('assigned', $reference['assignment']['execution_status']);

        $assignment = OrderVanAssignment::query()
            ->where('order_id', $order->id)
            ->where('status', 'active')
            ->firstOrFail();
        $assignment->forceFill([
            'status' => 'reassigned',
            'ended_at' => now(),
        ])->save();

        $stale = $bridge->reference($authorized, $order->id);
        $this->assertNull($stale['assignment']);

        $unauthorized = User::factory()->create();
        try {
            $bridge->reference($unauthorized, $order->id);
            $this->fail('Expected commercial delivery reference authorization to fail closed.');
        } catch (NotFoundHttpException) {
            $this->assertTrue(true);
        }
    }

    public function test_mismatched_handoff_identity_fails_closed_before_routing(): void
    {
        $order = $this->b2bOrder('INVALID');
        $event = app(CommercialDeliveryRouteBridge::class)->handoff($order, 'checkout');

        $tampered = new CommercialEventEnvelope(
            name: $event->name,
            version: $event->version,
            storeId: $event->storeId,
            channel: $event->channel,
            aggregateType: $event->aggregateType,
            aggregateId: $event->aggregateId,
            payload: array_merge($event->payload, ['store_id' => $event->storeId + 1]),
        );

        $this->expectException(\InvalidArgumentException::class);

        app(CommercialDeliveryRouteBridge::class)->apply($tampered);
    }

    private function b2bOrder(string $suffix): Order
    {
        $storeType = StoreType::query()->where('code', 'B2B')->firstOrFail();
        $store = Store::query()->create([
            'store_type_id' => $storeType->id,
            'code' => 'COMM-'.$suffix,
            'name' => 'Commercial '.$suffix,
            'is_active' => true,
        ]);
        $customer = Customer::query()->create([
            'name' => 'Customer '.$suffix,
            'type' => 'b2b',
        ]);

        return Order::query()->create([
            'store_id' => $store->id,
            'customer_id' => $customer->id,
            'order_number' => 'COMM-'.$suffix,
            'channel' => 'b2b',
            'status' => 'pending',
            'currency' => 'KWD',
            'subtotal' => 1,
            'discount_total' => 0,
            'delivery_total' => 0,
            'grand_total' => 1,
        ]);
    }

    /** @return array{0:Order,1:ServiceTerritory,2:User} */
    private function routableB2bOrder(string $suffix): array
    {
        $order = $this->b2bOrder($suffix);
        $actor = User::factory()->create();

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
                [30.0, 30.0],
                [31.0, 30.0],
                [31.0, 31.0],
                [30.0, 31.0],
                [30.0, 30.0],
            ]],
        ]);

        $order->forceFill([
            'delivery_latitude' => 30.5,
            'delivery_longitude' => 30.5,
        ])->save();

        return [$order->fresh(), $territory, $actor];
    }

    private function superAdmin(): User
    {
        $user = User::factory()->create();
        $role = Role::query()->where('code', 'SUPER_ADMIN')->firstOrFail();
        $user->roles()->attach($role->id);

        return $user;
    }
}
