<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Driver;
use App\Models\Order;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DriverAssignmentLifecycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_assigns_matching_driver_and_driver_completes_lifecycle_with_audit(): void
    {
        $this->seed(CoreReferenceSeeder::class);
        [$storeId, $order] = $this->order('b2c');
        $order->update(['status' => 'ready']);
        $admin = $this->roleUser('B2C_STORE_ADMIN', 'delivery-admin@example.test');
        $roleId = (int) Role::query()->where('code', 'B2C_STORE_ADMIN')->value('id');
        DB::table('user_store_roles')->insert(['user_id' => $admin->id, 'store_id' => $storeId, 'role_id' => $roleId, 'created_at' => now(), 'updated_at' => now()]);
        $driverUser = $this->roleUser('B2C_DRIVER', 'delivery-driver@example.test');
        $driver = Driver::query()->create(['user_id' => $driverUser->id, 'driver_type' => 'b2c', 'is_available' => true, 'is_active' => true]);
        Sanctum::actingAs($admin);
        $created = $this->postJson('/api/v1/admin/deliveries/assign', ['driver_id' => $driver->id, 'order_id' => $order->id])->assertCreated()->assertJsonPath('data.assignment_type', 'b2c');

        $this->assertDatabaseHas('audit_logs', ['event' => 'delivery.assignment.created']);
        Sanctum::actingAs($driverUser);
        $id = $created->json('data.id');
        $this->getJson('/api/v1/driver/assignments')
            ->assertOk()
            ->assertJsonPath('data.0.available_statuses.0', 'accepted')
            ->assertJsonPath('data.0.order.number', $order->order_number)
            ->assertJsonPath('data.0.order.status', 'ready')
            ->assertJsonPath('data.0.order.store.name', 'Delivery Store');

        $this->getJson("/api/v1/driver/assignments/{$id}")
            ->assertOk()
            ->assertJsonPath('data.order.number', $order->order_number);

        $expectedNext = [
            'accepted' => 'picked_up',
            'picked_up' => 'out_for_delivery',
            'out_for_delivery' => 'delivered',
            'delivered' => null,
        ];
        foreach (['accepted', 'picked_up', 'out_for_delivery', 'delivered'] as $status) {
            $response = $this->postJson("/api/v1/driver/assignments/{$id}/status", [
                'status' => $status,
                'note' => 'Driver action '.$status,
            ])
                ->assertOk()
                ->assertJsonPath('data.status', $status);
            if ($expectedNext[$status] === null) {
                $response->assertJsonCount(0, 'data.available_statuses');
            } else {
                $response->assertJsonPath('data.available_statuses.0', $expectedNext[$status]);
            }
        }
        $this->assertDatabaseHas('audit_logs', ['event' => 'delivery.assignment.status_changed']);
        $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => 'delivered']);
        $this->assertDatabaseHas('delivery_proofs', [
            'driver_assignment_id' => $id,
            'proof_type' => 'status_note',
            'note' => 'Driver action delivered',
        ]);
    }

    public function test_admin_can_reassign_and_unassign_order_while_preserving_history(): void
    {
        $this->seed(CoreReferenceSeeder::class);
        [$storeId, $order] = $this->order('b2c');
        $order->update(['status' => 'ready']);

        $admin = $this->roleUser('B2C_STORE_ADMIN', 'delivery-reassign-admin@example.test');
        $roleId = (int) Role::query()->where('code', 'B2C_STORE_ADMIN')->value('id');
        DB::table('user_store_roles')->insert([
            'user_id' => $admin->id,
            'store_id' => $storeId,
            'role_id' => $roleId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $driverOneUser = $this->roleUser('B2C_DRIVER', 'delivery-reassign-one@example.test');
        $driverOne = Driver::query()->create([
            'user_id' => $driverOneUser->id,
            'store_id' => $storeId,
            'driver_type' => 'b2c',
            'is_available' => true,
            'is_active' => true,
        ]);
        $driverTwoUser = $this->roleUser('B2C_DRIVER', 'delivery-reassign-two@example.test');
        $driverTwo = Driver::query()->create([
            'user_id' => $driverTwoUser->id,
            'store_id' => $storeId,
            'driver_type' => 'b2c',
            'is_available' => true,
            'is_active' => true,
        ]);

        Sanctum::actingAs($admin);
        $firstId = $this->postJson('/api/v1/admin/deliveries/assign', [
            'driver_id' => $driverOne->id,
            'order_id' => $order->id,
        ])->assertCreated()->json('data.id');

        $secondId = $this->postJson('/api/v1/admin/deliveries/assign', [
            'driver_id' => $driverTwo->id,
            'order_id' => $order->id,
            'replace_existing' => true,
        ])->assertCreated()->json('data.id');

        $this->assertDatabaseHas('driver_assignments', [
            'id' => $firstId,
            'status' => 'unassigned',
        ]);
        $this->assertDatabaseHas('driver_assignments', [
            'id' => $secondId,
            'driver_id' => $driverTwo->id,
            'status' => 'assigned',
        ]);
        $this->assertDatabaseHas('audit_logs', ['event' => 'delivery.assignment.unassigned']);

        DB::table('driver_assignments')->where('id', $firstId)->update(['status' => 'reassigned']);

        Sanctum::actingAs($driverOneUser);
        $this->getJson('/api/v1/driver/assignments?scope=active')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        Sanctum::actingAs($driverTwoUser);
        $this->getJson('/api/v1/driver/assignments?scope=active')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $secondId)
            ->assertJsonPath('data.0.order.number', $order->order_number);

        Sanctum::actingAs($admin);
        $this->deleteJson('/api/v1/admin/deliveries/orders/'.$order->id, [
            'reason' => 'dispatcher_removed',
        ])->assertOk();

        $this->assertDatabaseHas('driver_assignments', [
            'id' => $secondId,
            'status' => 'unassigned',
        ]);

        Sanctum::actingAs($driverTwoUser);
        $this->getJson('/api/v1/driver/assignments?scope=active')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_legacy_driver_store_and_assignment_scope_are_reconciled_for_visibility(): void
    {
        $this->seed(CoreReferenceSeeder::class);
        [$storeId, $order] = $this->order('b2c');
        $order->update(['status' => 'ready']);

        $driverUser = $this->roleUser('B2C_DRIVER', 'legacy-visible-driver@example.test');
        $driver = Driver::query()->create([
            'user_id' => $driverUser->id,
            'store_id' => null,
            'driver_type' => 'b2c',
            'is_available' => true,
            'is_active' => true,
        ]);
        $assignmentId = (int) DB::table('driver_assignments')->insertGetId([
            'driver_id' => $driver->id,
            'order_id' => $order->id,
            'store_id' => null,
            'assignment_type' => 'b2c',
            'status' => 'assigned',
            'assigned_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Sanctum::actingAs($driverUser);
        $this->getJson('/api/v1/driver/assignments?scope=active')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $assignmentId)
            ->assertJsonPath('data.0.order.number', $order->order_number);

        $this->assertDatabaseHas('drivers', [
            'id' => $driver->id,
            'store_id' => $storeId,
        ]);
        $this->assertDatabaseHas('driver_assignments', [
            'id' => $assignmentId,
            'store_id' => $storeId,
        ]);
    }

    public function test_cancelled_assignment_is_history_not_active_driver_work(): void
    {
        $this->seed(CoreReferenceSeeder::class);
        [$storeId, $order] = $this->order('b2c');
        $order->update(['status' => 'ready']);

        $driverUser = $this->roleUser('B2C_DRIVER', 'cancelled-history-driver@example.test');
        $driver = Driver::query()->create([
            'user_id' => $driverUser->id,
            'store_id' => $storeId,
            'driver_type' => 'b2c',
            'is_available' => true,
            'is_active' => true,
        ]);

        DB::table('driver_assignments')->insert([
            'driver_id' => $driver->id,
            'order_id' => $order->id,
            'store_id' => $storeId,
            'assignment_type' => 'b2c',
            'status' => 'cancelled',
            'assigned_at' => now()->subMinute(),
            'completed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Sanctum::actingAs($driverUser);
        $this->getJson('/api/v1/driver/assignments?scope=active')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->getJson('/api/v1/driver/assignments?scope=all')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.status', 'cancelled');
    }

    public function test_driver_cannot_open_or_transition_another_drivers_assignment(): void
    {
        $this->seed(CoreReferenceSeeder::class);
        [$storeId, $order] = $this->order('b2c');
        $order->update(['status' => 'ready']);

        $admin = $this->roleUser('B2C_STORE_ADMIN', 'delivery-admin-2@example.test');
        $roleId = (int) Role::query()->where('code', 'B2C_STORE_ADMIN')->value('id');
        DB::table('user_store_roles')->insert([
            'user_id' => $admin->id,
            'store_id' => $storeId,
            'role_id' => $roleId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $driverOneUser = $this->roleUser('B2C_DRIVER', 'delivery-driver-one@example.test');
        $driverOne = Driver::query()->create([
            'user_id' => $driverOneUser->id,
            'store_id' => $storeId,
            'driver_type' => 'b2c',
            'is_available' => true,
            'is_active' => true,
        ]);
        $driverTwoUser = $this->roleUser('B2C_DRIVER', 'delivery-driver-two@example.test');
        Driver::query()->create([
            'user_id' => $driverTwoUser->id,
            'store_id' => $storeId,
            'driver_type' => 'b2c',
            'is_available' => true,
            'is_active' => true,
        ]);

        Sanctum::actingAs($admin);
        $assignmentId = $this->postJson('/api/v1/admin/deliveries/assign', [
            'driver_id' => $driverOne->id,
            'order_id' => $order->id,
        ])->assertCreated()->json('data.id');

        Sanctum::actingAs($driverTwoUser);
        $this->getJson("/api/v1/driver/assignments/{$assignmentId}")->assertNotFound();
        $this->postJson("/api/v1/driver/assignments/{$assignmentId}/status", [
            'status' => 'accepted',
        ])->assertNotFound();
    }

    public function test_cross_channel_assignment_and_execution_are_denied(): void
    {
        $this->seed(CoreReferenceSeeder::class);
        [, $order] = $this->order('b2b');
        $admin = $this->roleUser('B2B_ADMIN', 'b2b-delivery-admin@example.test');
        $driverUser = $this->roleUser('B2C_DRIVER', 'wrong-driver@example.test');
        $driver = Driver::query()->create(['user_id' => $driverUser->id, 'driver_type' => 'b2c', 'is_available' => true, 'is_active' => true]);
        Sanctum::actingAs($admin);
        $this->postJson('/api/v1/admin/deliveries/assign', ['driver_id' => $driver->id, 'order_id' => $order->id])->assertConflict();
        Sanctum::actingAs($driverUser);
        $this->getJson('/api/v1/driver/assignments')->assertOk()->assertJsonCount(0, 'data');
    }

    private function order(string $channel): array
    {
        $typeId = (int) DB::table('store_types')->where('code', strtoupper($channel))->value('id');
        $storeId = (int) DB::table('stores')->insertGetId(['store_type_id' => $typeId, 'code' => 'DEL-'.strtoupper($channel), 'name' => 'Delivery Store', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $customerUser = User::query()->create(['name' => 'Customer', 'email' => $channel.'-delivery-customer@example.test', 'password' => 'password', 'is_active' => true]);
        $customer = Customer::query()->create(['user_id' => $customerUser->id, 'type' => $channel, 'name' => 'Customer', 'email' => $customerUser->email]);
        $order = Order::query()->create(['store_id' => $storeId, 'customer_id' => $customer->id, 'order_number' => 'DEL-'.strtoupper($channel).'-1', 'channel' => $channel, 'status' => 'pending', 'currency' => 'KWD', 'subtotal' => 1, 'discount_total' => 0, 'delivery_total' => 0, 'grand_total' => 1]);

        return [$storeId, $order];
    }

    private function roleUser(string $role, string $email): User
    {
        $user = User::query()->create(['name' => $role, 'email' => $email, 'password' => 'password', 'is_active' => true]);
        $user->roles()->attach(Role::query()->where('code', $role)->firstOrFail());

        return $user;
    }
}
