<?php

namespace Tests\Feature;

use App\Jobs\DispatchPushNotification;
use App\Models\Customer;
use App\Models\Driver;
use App\Models\DriverAssignment;
use App\Models\Order;
use App\Models\User;
use App\Services\OrderLifecycleNotificationService;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class OrderLifecycleNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_and_driver_notifications_are_user_scoped_deduplicated_and_queued(): void
    {
        $this->seed(CoreReferenceSeeder::class);
        Queue::fake();

        [$order, $customerUser] = $this->orderWithCustomer();
        $oldDriver = $this->driver('lifecycle-old-driver@example.test', (int) $order->store_id);
        $newDriver = $this->driver('lifecycle-new-driver@example.test', (int) $order->store_id);

        $assignment = DriverAssignment::query()->create([
            'driver_id' => $newDriver->id,
            'order_id' => $order->id,
            'store_id' => $order->store_id,
            'assignment_type' => 'b2c',
            'status' => 'assigned',
            'assigned_at' => now(),
        ]);

        $service = app(OrderLifecycleNotificationService::class);

        $service->customerOrderCreated($order);
        $service->customerOrderCreated($order);

        $this->assertDatabaseCount('notifications', 1);
        $this->assertDatabaseHas('notifications', [
            'user_id' => $customerUser->id,
            'app' => 'customer',
            'audience' => 'user',
            'channel' => 'both',
            'type' => 'order.created',
            'status' => 'published',
        ]);

        $service->driverAssigned($order, $assignment, $oldDriver->id);

        $this->assertDatabaseHas('notifications', [
            'user_id' => $newDriver->user_id,
            'app' => 'driver',
            'type' => 'delivery.assigned',
        ]);
        $this->assertDatabaseHas('notifications', [
            'user_id' => $oldDriver->user_id,
            'app' => 'driver',
            'type' => 'delivery.reassigned_away',
        ]);
        $this->assertDatabaseHas('notifications', [
            'user_id' => $customerUser->id,
            'app' => 'customer',
            'type' => 'delivery.reassigned',
        ]);

        DB::table('order_status_history')->insert([
            'order_id' => $order->id,
            'store_id' => $order->store_id,
            'user_id' => null,
            'from_status' => 'pending',
            'to_status' => 'confirmed',
            'note' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $order->forceFill(['status' => 'confirmed'])->save();

        $service->orderStatusChanged($order->fresh(), 'pending', 'confirmed');

        $this->assertDatabaseHas('notifications', [
            'user_id' => $customerUser->id,
            'app' => 'customer',
            'type' => 'order.status_changed',
        ]);
        $this->assertDatabaseHas('notifications', [
            'user_id' => $newDriver->user_id,
            'app' => 'driver',
            'type' => 'order.status_changed',
        ]);

        $service->driverUnassigned($order, $assignment, 'dispatcher_removed');
        $this->assertDatabaseHas('notifications', [
            'user_id' => $newDriver->user_id,
            'app' => 'driver',
            'type' => 'delivery.unassigned',
        ]);
        $this->assertDatabaseHas('notifications', [
            'user_id' => $customerUser->id,
            'app' => 'customer',
            'type' => 'delivery.unassigned',
        ]);

        Queue::assertPushed(DispatchPushNotification::class, 8);
    }

    public function test_driver_delivery_status_does_not_duplicate_customer_push_when_order_status_also_changes(): void
    {
        $this->seed(CoreReferenceSeeder::class);
        Queue::fake();

        [$order, $customerUser] = $this->orderWithCustomer();
        $driver = $this->driver('lifecycle-driver@example.test', (int) $order->store_id);
        $assignment = DriverAssignment::query()->create([
            'driver_id' => $driver->id,
            'order_id' => $order->id,
            'store_id' => $order->store_id,
            'assignment_type' => 'b2c',
            'status' => 'out_for_delivery',
            'assigned_at' => now(),
        ]);

        $service = app(OrderLifecycleNotificationService::class);
        $service->deliveryStatusChanged($order, $assignment, 'picked_up', 'out_for_delivery');

        $this->assertDatabaseMissing('notifications', [
            'user_id' => $customerUser->id,
            'type' => 'delivery.status_changed',
        ]);

        DB::table('order_status_history')->insert([
            'order_id' => $order->id,
            'store_id' => $order->store_id,
            'user_id' => $driver->user_id,
            'from_status' => 'ready',
            'to_status' => 'out_for_delivery',
            'note' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $order->forceFill(['status' => 'out_for_delivery'])->save();
        $service->orderStatusChanged($order->fresh(), 'ready', 'out_for_delivery');

        $this->assertDatabaseHas('notifications', [
            'user_id' => $customerUser->id,
            'app' => 'customer',
            'type' => 'order.status_changed',
        ]);
    }

    /** @return array{0:Order,1:User} */
    private function orderWithCustomer(): array
    {
        $typeId = (int) DB::table('store_types')->where('code', 'B2C')->value('id');
        $storeId = (int) DB::table('stores')->insertGetId([
            'store_type_id' => $typeId,
            'code' => 'LIFECYCLE-RETAIL',
            'name' => 'Lifecycle Retail',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $user = User::query()->create([
            'name' => 'Lifecycle Customer',
            'email' => 'lifecycle-customer@example.test',
            'password' => 'password',
            'locale' => 'en',
            'is_active' => true,
        ]);
        $legacy = Customer::query()->create([
            'user_id' => $user->id,
            'type' => 'b2c',
            'name' => $user->name,
            'email' => $user->email,
        ]);
        $b2cId = (int) DB::table('b2c_customers')->insertGetId([
            'legacy_customer_id' => $legacy->id,
            'store_id' => $storeId,
            'user_id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $order = Order::query()->create([
            'store_id' => $storeId,
            'customer_id' => $legacy->id,
            'b2c_customer_id' => $b2cId,
            'order_number' => 'FDX-LIFECYCLE-1',
            'channel' => 'b2c',
            'status' => 'pending',
            'currency' => 'EGP',
            'subtotal' => 10,
            'discount_total' => 0,
            'delivery_total' => 0,
            'grand_total' => 10,
        ]);

        return [$order, $user];
    }

    private function driver(string $email, int $storeId): Driver
    {
        $user = User::query()->create([
            'name' => 'Lifecycle Driver',
            'email' => $email,
            'password' => 'password',
            'locale' => 'en',
            'is_active' => true,
        ]);

        return Driver::query()->create([
            'user_id' => $user->id,
            'store_id' => $storeId,
            'driver_type' => 'b2c',
            'is_available' => true,
            'is_active' => true,
        ]);
    }
}
