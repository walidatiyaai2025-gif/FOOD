<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Driver;
use App\Models\DriverAssignment;
use App\Models\Order;
use App\Models\OrderDispatchState;
use App\Models\OrderVanAssignment;
use App\Models\Store;
use App\Models\StoreType;
use App\Models\User;
use App\Services\B2BVanFulfillmentMigrationAudit;
use App\Services\FulfillmentActorPolicy;
use App\Services\VanRegistryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class B2BVanFulfillmentMigrationAuditTest extends TestCase
{
    use RefreshDatabase;

    public function test_channel_actor_contract_is_explicit(): void
    {
        $policy = app(FulfillmentActorPolicy::class);
        $this->assertSame(FulfillmentActorPolicy::VAN, $policy->actorForChannel('b2b'));
        $this->assertSame(FulfillmentActorPolicy::DRIVER, $policy->actorForChannel('b2c'));
    }

    public function test_dry_run_inventories_cross_channel_contradictions_without_mutation(): void
    {
        [$b2b, $store] = $this->orderFixture('AUDIT-B2B', 'b2b');
        [$b2c] = $this->orderFixture('AUDIT-B2C', 'b2c', $store);

        $driverUser = User::factory()->create();
        $driver = Driver::query()->create([
            'user_id' => $driverUser->id,
            'store_id' => $store->id,
            'driver_type' => 'b2b',
            'is_available' => true,
            'is_active' => true,
        ]);
        $driverAssignment = DriverAssignment::query()->create([
            'driver_id' => $driver->id,
            'order_id' => $b2b->id,
            'store_id' => $store->id,
            'assignment_type' => 'b2b',
            'status' => 'assigned',
            'assigned_at' => now(),
        ]);
        OrderDispatchState::query()->create([
            'order_id' => $b2b->id,
            'status' => 'assigned',
            'routing_source' => 'legacy',
            'routing_reason' => 'legacy_driver',
            'current_assignee_type' => 'driver',
            'current_assignee_id' => $driver->id,
            'decision_key' => hash('sha256', 'audit-b2b'),
            'decided_at' => now(),
        ]);

        $van = app(VanRegistryService::class)->createVan(['code' => 'VAN-AUDIT-B2C']);
        $vanAssignment = OrderVanAssignment::query()->create([
            'order_id' => $b2c->id,
            'van_id' => $van->id,
            'status' => 'active',
            'source' => 'legacy',
            'reason' => 'legacy_van',
            'decision_key' => hash('sha256', 'audit-b2c'),
            'assigned_at' => now(),
        ]);
        OrderDispatchState::query()->create([
            'order_id' => $b2c->id,
            'status' => 'assigned',
            'routing_source' => 'legacy',
            'routing_reason' => 'legacy_van',
            'current_assignee_type' => 'van',
            'current_assignee_id' => $van->id,
            'decision_key' => hash('sha256', 'audit-b2c-dispatch'),
            'decided_at' => now(),
        ]);

        $before = [
            DriverAssignment::query()->count(),
            OrderVanAssignment::query()->count(),
            OrderDispatchState::query()->count(),
        ];

        $first = app(B2BVanFulfillmentMigrationAudit::class)->report();
        $second = app(B2BVanFulfillmentMigrationAudit::class)->report();

        $this->assertSame($first, $second);
        $this->assertFalse($first['destructive_changes']);
        $this->assertSame(2, $first['counts']['contradictory_orders']);
        $this->assertSame(1, $first['counts']['b2b_active_driver']);
        $this->assertSame(1, $first['counts']['b2b_dispatch_driver']);
        $this->assertSame(1, $first['counts']['b2b_requires_van_routing']);
        $this->assertSame(1, $first['counts']['b2c_active_van']);
        $this->assertSame(1, $first['counts']['b2c_dispatch_van']);

        $this->assertSame($before, [
            DriverAssignment::query()->count(),
            OrderVanAssignment::query()->count(),
            OrderDispatchState::query()->count(),
        ]);
        $this->assertDatabaseHas('driver_assignments', ['id' => $driverAssignment->id, 'status' => 'assigned']);
        $this->assertDatabaseHas('order_van_assignments', ['id' => $vanAssignment->id, 'status' => 'active']);
    }

    public function test_console_dry_run_emits_json_and_performs_no_writes(): void
    {
        $this->orderFixture('AUDIT-CMD', 'b2b');
        $before = Order::query()->count();

        $exit = Artisan::call('foodex:audit-b2b-van-cutover', ['--json' => true]);
        $payload = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('dry-run', $payload['mode']);
        $this->assertFalse($payload['destructive_changes']);
        $this->assertSame($before, Order::query()->count());
    }

    private function orderFixture(string $suffix, string $channel, ?Store $store = null): array
    {
        if ($store === null) {
            $type = StoreType::query()->create(['code' => 'audit-'.strtolower($suffix), 'name' => 'Audit '.$suffix]);
            $store = Store::query()->create(['store_type_id' => $type->id, 'code' => 'AUD-'.$suffix, 'name' => 'Audit '.$suffix]);
        }

        $customer = Customer::query()->create(['name' => 'Audit Customer '.$suffix, 'type' => $channel]);
        $order = Order::query()->create([
            'store_id' => $store->id,
            'customer_id' => $customer->id,
            'order_number' => $suffix,
            'channel' => $channel,
            'status' => 'pending',
            'currency' => 'KWD',
            'subtotal' => 10,
            'discount_total' => 0,
            'delivery_total' => 0,
            'grand_total' => 10,
        ]);

        return [$order, $store];
    }
}
