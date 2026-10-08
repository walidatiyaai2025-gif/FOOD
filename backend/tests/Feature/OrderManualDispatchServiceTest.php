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
use Tests\TestCase;

class OrderManualDispatchServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_service_can_assign_van_idempotently_and_clear_to_pending_queue(): void
    {
        [$order, $actor] = $this->fixture('VAN');
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
        $this->assertDatabaseHas('order_van_assignments', [
            'order_id' => $order->id,
            'van_id' => $van->id,
            'status' => 'active',
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
    }

    public function test_driver_dispatch_supersedes_active_van_without_leaving_two_executors(): void
    {
        [$order, $actor, $store] = $this->fixture('DRIVER');
        $registry = app(VanRegistryService::class);
        $van = $registry->createVan(['code' => 'VAN-FIRST']);
        $registry->assign($actor, $van, [
            'assignment_type' => 'primary',
            'effective_from' => '2026-10-01T00:00:00Z',
        ]);

        $service = app(OrderManualDispatchService::class);
        $service->assignVan($order, $actor, $van->id, 'Initial Van assignment', '2026-10-08T18:00:00Z');

        $driverUser = User::factory()->create();
        $driver = Driver::query()->create([
            'user_id' => $driverUser->id,
            'store_id' => $store->id,
            'driver_type' => 'b2c',
            'is_available' => true,
            'is_active' => true,
        ]);
        $driverAssignment = DriverAssignment::query()->create([
            'driver_id' => $driver->id,
            'order_id' => $order->id,
            'store_id' => $store->id,
            'assignment_type' => 'b2c',
            'status' => 'assigned',
            'assigned_at' => now(),
        ]);

        $state = $service->assignDriver($order, $actor, $driverAssignment, 'Customer requested direct driver');

        $this->assertSame('assigned', $state->status);
        $this->assertSame('driver', $state->current_assignee_type);
        $this->assertSame($driver->id, $state->current_assignee_id);
        $this->assertSame('reassignment_override', $state->routing_source);
        $this->assertDatabaseMissing('order_van_assignments', [
            'order_id' => $order->id,
            'status' => 'active',
        ]);
        $this->assertDatabaseHas('order_van_assignments', [
            'order_id' => $order->id,
            'van_id' => $van->id,
            'status' => 'reassigned',
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'order.dispatch.manual_assigned',
            'auditable_type' => Order::class,
            'auditable_id' => $order->id,
        ]);
    }

    public function test_van_dispatch_rejects_when_active_driver_was_not_ended(): void
    {
        [$order, $actor, $store] = $this->fixture('DUAL');
        $registry = app(VanRegistryService::class);
        $van = $registry->createVan(['code' => 'VAN-DUAL']);
        $registry->assign($actor, $van, [
            'assignment_type' => 'primary',
            'effective_from' => '2026-10-01T00:00:00Z',
        ]);

        $driverUser = User::factory()->create();
        $driver = Driver::query()->create([
            'user_id' => $driverUser->id,
            'store_id' => $store->id,
            'driver_type' => 'b2c',
            'is_available' => true,
            'is_active' => true,
        ]);
        DriverAssignment::query()->create([
            'driver_id' => $driver->id,
            'order_id' => $order->id,
            'store_id' => $store->id,
            'assignment_type' => 'b2c',
            'status' => 'assigned',
            'assigned_at' => now(),
        ]);

        $this->expectException(\Illuminate\Validation\ValidationException::class);
        app(OrderManualDispatchService::class)
            ->assignVan($order, $actor, $van->id, 'Unsafe dual assignment attempt');
    }

    /** @return array{0:Order,1:User,2:Store} */
    private function fixture(string $suffix): array
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
            'type' => 'b2c',
        ]);
        $order = Order::query()->create([
            'store_id' => $store->id,
            'customer_id' => $customer->id,
            'order_number' => 'DSP-'.$suffix,
            'channel' => 'b2c',
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
