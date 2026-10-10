<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Driver;
use App\Models\DriverAssignment;
use App\Models\Order;
use App\Models\Store;
use App\Models\StoreType;
use App\Models\User;
use App\Services\OrderManualDispatchService;
use App\Services\VanRegistryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class OrderManualDispatchServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_service_can_assign_b2b_van_idempotently_initialize_execution_state_and_clear(): void
    {
        [$order, $actor] = $this->fixture('VAN', 'b2b');
        $registry = app(VanRegistryService::class);
        $van = $registry->createVan(['code' => 'VAN-CS']);
        $registry->assign($actor, $van, [
            'assignment_type' => 'primary',
            'effective_from' => '2026-10-01T00:00:00Z',
        ]);

        $service = app(OrderManualDispatchService::class);
        $first = $service->assignVan($order, $actor, $van->id, 'Customer Service override', '2026-10-08T18:00:00Z');
        $second = $service->assignVan($order, $actor, $van->id, 'Retry of same decision', '2026-10-08T18:00:01Z');

        $this->assertSame('assigned', $first->status);
        $this->assertSame('van', $first->current_assignee_type);
        $this->assertSame($van->id, $first->current_assignee_id);
        $this->assertSame('manual_customer_service', $first->routing_source);
        $this->assertSame($first->routing_source, $second->routing_source);
        $this->assertDatabaseCount('order_van_assignments', 1);
        $this->assertDatabaseCount('order_van_execution_states', 1);
        $this->assertDatabaseHas('order_van_assignments', [
            'order_id' => $order->id,
            'van_id' => $van->id,
            'status' => 'active',
        ]);
        $this->assertDatabaseHas('order_van_execution_states', [
            'order_id' => $order->id,
            'van_id' => $van->id,
            'status' => 'assigned',
        ]);

        $cleared = $service->clear($order, $actor, 'Van unavailable');

        $this->assertSame('awaiting_dispatch', $cleared->status);
        $this->assertSame('manual_customer_service', $cleared->routing_source);
        $this->assertSame('customer_service_unassigned', $cleared->routing_reason);
        $this->assertNull($cleared->current_assignee_type);
        $this->assertNull($cleared->current_assignee_id);
        $this->assertDatabaseHas('order_van_assignments', [
            'order_id' => $order->id,
            'van_id' => $van->id,
            'status' => 'ended',
        ]);
        $this->assertDatabaseHas('order_van_execution_states', [
            'order_id' => $order->id,
            'van_id' => $van->id,
            'status' => 'assigned',
        ]);
    }

    public function test_customer_service_can_assign_driver_to_b2c_order(): void
    {
        [$order, $actor, $store] = $this->fixture('DRIVER', 'b2c');
        $driverAssignment = $this->driverAssignment($order, $store);

        $state = app(OrderManualDispatchService::class)
            ->assignDriver($order, $actor, $driverAssignment, 'Retail delivery assignment');

        $this->assertSame('assigned', $state->status);
        $this->assertSame('driver', $state->current_assignee_type);
        $this->assertSame($driverAssignment->driver_id, $state->current_assignee_id);
        $this->assertDatabaseCount('order_van_assignments', 0);
        $this->assertDatabaseCount('order_van_execution_states', 0);
    }

    public function test_manual_dispatch_rejects_cross_channel_actor_assignment(): void
    {
        [$b2bOrder, $actor, $b2bStore] = $this->fixture('B2B-WRONG', 'b2b');
        $driverAssignment = $this->driverAssignment($b2bOrder, $b2bStore);

        try {
            app(OrderManualDispatchService::class)
                ->assignDriver($b2bOrder, $actor, $driverAssignment, 'Invalid wholesale driver');
            $this->fail('B2B Driver assignment must be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('assignee_type', $exception->errors());
        }

        [$b2cOrder, $actor, $b2cStore] = $this->fixture('B2C-WRONG', 'b2c');
        $registry = app(VanRegistryService::class);
        $van = $registry->createVan(['code' => 'VAN-B2C-WRONG']);
        $registry->assign($actor, $van, [
            'assignment_type' => 'primary',
            'effective_from' => '2026-10-01T00:00:00Z',
        ]);

        $this->expectException(ValidationException::class);
        app(OrderManualDispatchService::class)
            ->assignVan($b2cOrder, $actor, $van->id, 'Invalid retail Van assignment');
    }

    private function driverAssignment(Order $order, Store $store): DriverAssignment
    {
        $driverUser = User::factory()->create();
        $driver = Driver::query()->create([
            'user_id' => $driverUser->id,
            'store_id' => $store->id,
            'driver_type' => 'b2c',
            'is_available' => true,
            'is_active' => true,
        ]);

        return DriverAssignment::query()->create([
            'driver_id' => $driver->id,
            'order_id' => $order->id,
            'store_id' => $store->id,
            'assignment_type' => 'b2c',
            'status' => 'assigned',
            'assigned_at' => now(),
        ]);
    }

    /** @return array{0:Order,1:User,2:Store} */
    private function fixture(string $suffix, string $channel): array
    {
        $actor = User::factory()->create();
        $type = StoreType::query()->create([
            'code' => 'dispatch-'.strtolower($suffix),
            'name' => 'Dispatch '.$suffix,
        ]);
        $store = Store::query()->create([
            'store_type_id' => $type->id,
            'code' => 'DSP-'.$suffix,
            'name' => 'Dispatch '.$suffix,
        ]);
        $customer = Customer::query()->create([
            'name' => 'Dispatch Customer '.$suffix,
            'type' => $channel,
        ]);
        $order = Order::query()->create([
            'store_id' => $store->id,
            'customer_id' => $customer->id,
            'order_number' => 'DSP-'.$suffix,
            'channel' => $channel,
            'status' => 'pending',
            'currency' => 'KWD',
            'subtotal' => 10,
            'discount_total' => 0,
            'delivery_total' => 0,
            'grand_total' => 10,
        ]);

        return [$order, $actor, $store];
    }
}
