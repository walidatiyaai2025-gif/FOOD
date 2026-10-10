<?php

namespace Tests\Feature;

use App\Jobs\DispatchPushNotification;
use App\Models\Customer;
use App\Models\Driver;
use App\Models\DriverAssignment;
use App\Models\Notification;
use App\Models\Order;
use App\Models\OrderVanAssignment;
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

    public function test_b2b_notifications_target_active_van_and_never_driver_app(): void
    {
        $this->seed(CoreReferenceSeeder::class);
        Queue::fake();

        $typeId = (int) DB::table('store_types')->where('code', 'B2B')->value('id');
        $storeId = (int) DB::table('stores')->insertGetId([
            'store_type_id' => $typeId,
            'code' => 'LIFECYCLE-WHOLESALE',
            'name' => 'Lifecycle Wholesale',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $order = Order::query()->create([
            'store_id' => $storeId,
            'order_number' => 'FDX-B2B-VAN-PUSH-1',
            'channel' => 'b2b',
            'status' => 'pending',
            'currency' => 'KWD',
            'subtotal' => 10,
            'discount_total' => 0,
            'delivery_total' => 0,
            'grand_total' => 10,
        ]);

        $vanUser = User::query()->create([
            'name' => 'Van Runtime User',
            'email' => 'van-runtime-push@example.test',
            'password' => 'password',
            'locale' => 'en',
            'is_active' => true,
        ]);
        $vanId = (int) DB::table('vans')->insertGetId([
            'public_id' => '00000000-0000-0000-0000-000000001200',
            'code' => 'VAN-PUSH-1200',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $runtimeAssignmentId = (int) DB::table('van_assignments')->insertGetId([
            'public_id' => '00000000-0000-0000-0000-000000001201',
            'van_id' => $vanId,
            'representative_user_id' => $vanUser->id,
            'assignment_type' => 'primary',
            'status' => 'active',
            'effective_from' => now()->subMinute(),
            'loaded_work_count' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $orderVanId = (int) DB::table('order_van_assignments')->insertGetId([
            'order_id' => $order->id,
            'van_id' => $vanId,
            'van_assignment_id' => $runtimeAssignmentId,
            'status' => 'active',
            'source' => 'smart_routing',
            'reason' => 'territory_match',
            'decision_key' => hash('sha256', 'push-'.$order->id),
            'assigned_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $orderVan = OrderVanAssignment::query()->findOrFail($orderVanId);

        $historicalDriverUser = User::query()->create([
            'name' => 'Historical B2B Driver',
            'email' => 'historical-b2b-driver-push@example.test',
            'password' => 'password',
            'locale' => 'en',
            'is_active' => true,
        ]);
        $historicalDriver = Driver::query()->create([
            'user_id' => $historicalDriverUser->id,
            'store_id' => $storeId,
            'driver_type' => 'b2b',
            'is_available' => true,
            'is_active' => true,
        ]);
        $historicalAssignment = DriverAssignment::query()->create([
            'driver_id' => $historicalDriver->id,
            'order_id' => $order->id,
            'store_id' => $storeId,
            'assignment_type' => 'b2b',
            'status' => 'assigned',
            'assigned_at' => now(),
        ]);

        $service = app(OrderLifecycleNotificationService::class);
        $service->driverAssigned($order, $historicalAssignment);
        $service->vanAssigned($order, $orderVan);

        $assigned = Notification::query()
            ->where('user_id', $vanUser->id)
            ->where('app', 'van')
            ->where('type', 'van.delivery.assigned')
            ->firstOrFail();

        $this->assertSame('b2b', $assigned->target_channel);
        $this->assertSame($storeId, (int) $assigned->store_id);
        $this->assertSame('order_detail', $assigned->data['route'] ?? null);
        $this->assertSame(
            '/van/orders/'.$order->id.'?'.http_build_query([
                'channel' => 'b2b',
                'store_id' => $storeId,
                'order_van_assignment_id' => $orderVan->id,
            ]),
            $assigned->data['deep_link'] ?? null,
        );
        $this->assertSame($order->id, (int) ($assigned->data['order_id'] ?? 0));

        DB::table('order_status_history')->insert([
            'order_id' => $order->id,
            'store_id' => $storeId,
            'user_id' => null,
            'from_status' => 'out_for_delivery',
            'to_status' => 'failed',
            'note' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $order->forceFill(['status' => 'failed'])->save();
        $service->orderStatusChanged($order->fresh(), 'out_for_delivery', 'failed');

        $this->assertDatabaseHas('notifications', [
            'user_id' => $vanUser->id,
            'app' => 'van',
            'target_channel' => 'b2b',
            'type' => 'van.action_required',
        ]);
        $this->assertDatabaseMissing('notifications', [
            'user_id' => $historicalDriverUser->id,
            'app' => 'driver',
        ]);

        $orderVan->forceFill(['status' => 'reassigned', 'ended_at' => now()])->save();
        $service->vanAssignmentRevoked($order, $orderVan->fresh(), 'reassigned');
        $this->assertDatabaseHas('notifications', [
            'user_id' => $vanUser->id,
            'app' => 'van',
            'type' => 'van.delivery.reassigned_away',
        ]);

        Queue::assertPushed(DispatchPushNotification::class, 3);
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
