<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Driver;
use App\Models\Order;
use App\Models\Role;
use App\Models\User;
use App\Services\DriverLocationEnforcementPolicy;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DriverLocationLifecycleContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_heartbeat_marks_only_execution_statuses_as_active_tracking(): void
    {
        [$driverUser, $driver, $assignmentId] = $this->assignment('assigned');
        Sanctum::actingAs($driverUser, ['app:driver']);

        $statuses = [
            'assigned' => false,
            'accepted' => true,
            'picked_up' => true,
            'out_for_delivery' => true,
            'delivered' => false,
            'failed' => false,
            'reassigned' => false,
            'cancelled' => false,
            'unassigned' => false,
        ];

        $sequence = 0;
        foreach ($statuses as $status => $trackingRequired) {
            DB::table('driver_assignments')
                ->where('id', $assignmentId)
                ->update([
                    'status' => $status,
                    'completed_at' => in_array(
                        $status,
                        ['delivered', 'failed', 'reassigned', 'cancelled', 'unassigned'],
                        true,
                    ) ? now() : null,
                    'updated_at' => now(),
                ]);

            $response = $this->postJson('/api/v1/driver/location/heartbeat', [
                'latitude' => 29.3759 + ($sequence / 100000),
                'longitude' => 47.9774 + ($sequence / 100000),
                'accuracy' => 5,
                'captured_at' => now()->subSeconds(20 - $sequence)->toISOString(),
                'app_version' => '1.0.38+38',
            ])->assertOk();

            $response->assertJsonPath('data.tracking_required', $trackingRequired);
            if ($trackingRequired) {
                $response
                    ->assertJsonPath('data.active_assignment_id', $assignmentId)
                    ->assertJsonPath('data.active_assignment_status', $status);
            } else {
                $response
                    ->assertJsonPath('data.active_assignment_id', null)
                    ->assertJsonPath('data.active_assignment_status', null);
            }

            $sequence++;
        }

        $this->assertDatabaseHas('driver_current_locations', [
            'driver_id' => $driver->id,
            'active_assignment_id' => null,
        ]);
    }

    public function test_terminal_assignment_is_not_exposed_as_actionable_live_tracking(): void
    {
        [$driverUser, $driver, $assignmentId, $order, $storeId] =
            $this->assignment('out_for_delivery');
        Sanctum::actingAs($driverUser, ['app:driver']);

        $this->postJson('/api/v1/driver/location/heartbeat', [
            'latitude' => 29.3759,
            'longitude' => 47.9774,
            'accuracy' => 5,
            'captured_at' => now()->subSecond()->toISOString(),
            'app_version' => '1.0.38+38',
        ])
            ->assertOk()
            ->assertJsonPath('data.active_assignment_id', $assignmentId)
            ->assertJsonPath('data.tracking_required', true);

        DB::table('driver_assignments')
            ->where('id', $assignmentId)
            ->update([
                'status' => 'delivered',
                'completed_at' => now(),
                'updated_at' => now(),
            ]);

        $admin = $this->roleUser('SUPER_ADMIN', 'tracking-lifecycle-admin@example.test');
        Sanctum::actingAs($admin);

        $this->getJson("/api/v1/admin/driver-live-tracking/feed?channel=b2c&store_id={$storeId}")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.driver_id', $driver->id)
            ->assertJsonPath('data.0.active_assignment_id', null)
            ->assertJsonPath('data.0.active_assignment_status', null)
            ->assertJsonPath('data.0.tracking_required', false)
            ->assertJsonPath('data.0.order', null);

        $this->getJson("/api/v1/admin/driver-live-tracking/feed?order_id={$order->id}")
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_stale_location_blocks_normal_actions_but_not_explicit_failure_recovery(): void
    {
        [$driverUser, , $assignmentId, $order] =
            $this->assignment('out_for_delivery');
        $order->forceFill(['status' => 'out_for_delivery'])->save();

        app(DriverLocationEnforcementPolicy::class)->persist(true, 90);
        Sanctum::actingAs($driverUser, ['app:driver']);

        $this->postJson("/api/v1/driver/assignments/{$assignmentId}/status", [
            'status' => 'delivered',
        ])
            ->assertStatus(428)
            ->assertJsonPath('code', 'DRIVER_LOCATION_HEARTBEAT_REQUIRED');

        $this->postJson("/api/v1/driver/assignments/{$assignmentId}/status", [
            'status' => 'failed',
            'failure_reason' => 'customer_no_answer',
            'note' => 'Customer did not answer.',
        ])
            ->assertOk()
            ->assertJsonPath('data.status', 'failed')
            ->assertJsonPath('data.order.status', 'failed');
    }

    /** @return array{0: User, 1: Driver, 2: int, 3: Order, 4: int} */
    private function assignment(string $status): array
    {
        $this->seed(CoreReferenceSeeder::class);

        $storeTypeId = (int) DB::table('store_types')
            ->where('code', 'B2C')
            ->value('id');
        $storeId = (int) DB::table('stores')->insertGetId([
            'store_type_id' => $storeTypeId,
            'code' => 'LOC-LIFECYCLE-'.strtoupper(substr(md5($status.microtime()), 0, 8)),
            'name' => 'Location Lifecycle Store',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $customerUser = User::query()->create([
            'name' => 'Location Customer',
            'email' => 'location-customer-'.uniqid().'@example.test',
            'password' => 'password',
            'is_active' => true,
        ]);
        $customer = Customer::query()->create([
            'user_id' => $customerUser->id,
            'type' => 'b2c',
            'name' => 'Location Customer',
            'email' => $customerUser->email,
        ]);
        $order = Order::query()->create([
            'store_id' => $storeId,
            'customer_id' => $customer->id,
            'order_number' => 'LOC-'.strtoupper(substr(md5(uniqid()), 0, 10)),
            'channel' => 'b2c',
            'status' => $status === 'out_for_delivery' ? 'out_for_delivery' : 'ready',
            'currency' => 'KWD',
            'subtotal' => 1,
            'discount_total' => 0,
            'delivery_total' => 0,
            'grand_total' => 1,
        ]);

        $driverUser = $this->roleUser(
            'B2C_DRIVER',
            'location-driver-'.uniqid().'@example.test',
        );
        $driver = Driver::query()->create([
            'user_id' => $driverUser->id,
            'store_id' => $storeId,
            'driver_type' => 'b2c',
            'is_available' => true,
            'is_active' => true,
        ]);

        $assignmentId = (int) DB::table('driver_assignments')->insertGetId([
            'driver_id' => $driver->id,
            'order_id' => $order->id,
            'store_id' => $storeId,
            'assignment_type' => 'b2c',
            'status' => $status,
            'assigned_at' => now()->subMinute(),
            'completed_at' => in_array(
                $status,
                ['delivered', 'failed', 'reassigned', 'cancelled', 'unassigned'],
                true,
            ) ? now() : null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [$driverUser, $driver, $assignmentId, $order, $storeId];
    }

    private function roleUser(string $role, string $email): User
    {
        $user = User::query()->create([
            'name' => $role,
            'email' => $email,
            'password' => 'password',
            'is_active' => true,
        ]);
        $user->roles()->attach(
            Role::query()->where('code', $role)->firstOrFail(),
        );

        return $user;
    }
}
