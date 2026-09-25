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
        foreach (['accepted', 'picked_up', 'out_for_delivery', 'delivered'] as $status) {
            $this->postJson("/api/v1/driver/assignments/{$id}/status", ['status' => $status])->assertOk()->assertJsonPath('data.status', $status);
        }
        $this->assertDatabaseHas('audit_logs', ['event' => 'delivery.assignment.status_changed']);
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
