<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Driver;
use App\Models\DriverAssignment;
use App\Models\Notification;
use App\Models\Order;
use App\Models\Role;
use App\Models\User;
use App\Services\DashboardOperationalNotifier;
use App\Services\OrderLifecycleNotificationService;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class DriverLifecycleNotificationPrivacyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
        Queue::fake();
    }

    public function test_driver_note_stays_operational_and_never_leaks_to_customer_payload(): void
    {
        [$order, $customerUser] = $this->orderWithCustomer('PRIVACY');
        $admin = $this->storeAdmin((int) $order->store_id, 'privacy-admin@example.test');
        $driver = $this->driver((int) $order->store_id, 'privacy-driver@example.test');
        $assignment = $this->assignment($order, $driver, 'accepted');

        $notifier = app(DashboardOperationalNotifier::class);
        $notifier->deliveryChanged(
            $order,
            'accepted',
            $assignment,
            'Private gate code 8841',
            'assigned',
        );
        $notifier->deliveryChanged(
            $order,
            'accepted',
            $assignment,
            'Private gate code 8841',
            'assigned',
        );

        $customerNotification = Notification::query()
            ->where('user_id', $customerUser->id)
            ->where('app', 'customer')
            ->where('type', 'delivery.status_changed')
            ->sole();

        $customerData = $customerNotification->data ?? [];
        $this->assertArrayNotHasKey('note', $customerData);
        $this->assertArrayNotHasKey('file_path', $customerData);
        $this->assertStringNotContainsString(
            'Private gate code 8841',
            json_encode($customerData, JSON_THROW_ON_ERROR),
        );

        $dashboardNotification = Notification::query()
            ->where('user_id', $admin->id)
            ->where('app', 'dashboard')
            ->where('type', 'delivery.status_changed')
            ->sole();

        $this->assertSame(
            'Private gate code 8841',
            $dashboardNotification->data['note'] ?? null,
        );

        $this->assertSame(1, Notification::query()
            ->where('user_id', $customerUser->id)
            ->where('type', 'delivery.status_changed')
            ->count());
        $this->assertSame(1, Notification::query()
            ->where('user_id', $admin->id)
            ->where('type', 'delivery.status_changed')
            ->count());
        $this->assertSame(1, Notification::query()
            ->where('user_id', $admin->id)
            ->where('type', 'delivery.note_added')
            ->count());
    }

    public function test_reassignment_revokes_old_driver_and_future_updates_target_only_new_driver(): void
    {
        [$order] = $this->orderWithCustomer('REASSIGN');
        $oldDriver = $this->driver((int) $order->store_id, 'old-driver@example.test');
        $newDriver = $this->driver((int) $order->store_id, 'new-driver@example.test');

        $oldAssignment = $this->assignment($order, $oldDriver, 'reassigned');
        $newAssignment = $this->assignment($order, $newDriver, 'assigned');

        $service = app(OrderLifecycleNotificationService::class);
        $service->driverAssigned($order, $newAssignment, $oldDriver->id);

        $oldRemoval = Notification::query()
            ->where('user_id', $oldDriver->user_id)
            ->where('app', 'driver')
            ->where('type', 'delivery.reassigned_away')
            ->sole();

        $this->assertTrue((bool) ($oldRemoval->data['access_revoked'] ?? false));
        $this->assertSame('reassigned', $oldRemoval->data['delivery_status'] ?? null);

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
            'user_id' => $newDriver->user_id,
            'app' => 'driver',
            'type' => 'order.status_changed',
        ]);
        $this->assertDatabaseMissing('notifications', [
            'user_id' => $oldDriver->user_id,
            'app' => 'driver',
            'type' => 'order.status_changed',
        ]);

        $this->assertSame('reassigned', $oldAssignment->fresh()->status);
    }

    public function test_cancelled_order_push_marks_current_driver_access_revoked(): void
    {
        [$order] = $this->orderWithCustomer('CANCEL');
        $driver = $this->driver((int) $order->store_id, 'cancel-driver@example.test');
        $this->assignment($order, $driver, 'accepted');

        DB::table('order_status_history')->insert([
            'order_id' => $order->id,
            'store_id' => $order->store_id,
            'user_id' => null,
            'from_status' => 'confirmed',
            'to_status' => 'cancelled',
            'note' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $order->forceFill(['status' => 'cancelled'])->save();

        app(OrderLifecycleNotificationService::class)
            ->orderStatusChanged($order->fresh(), 'confirmed', 'cancelled');

        $notification = Notification::query()
            ->where('user_id', $driver->user_id)
            ->where('app', 'driver')
            ->where('type', 'order.status_changed')
            ->sole();

        $this->assertTrue((bool) ($notification->data['access_revoked'] ?? false));
        $this->assertSame('cancelled', $notification->data['status'] ?? null);
    }

    /** @return array{0:Order,1:User} */
    private function orderWithCustomer(string $suffix): array
    {
        $typeId = (int) DB::table('store_types')->where('code', 'B2C')->value('id');
        $storeId = (int) DB::table('stores')->insertGetId([
            'store_type_id' => $typeId,
            'code' => 'LIFECYCLE-'.$suffix,
            'name' => 'Lifecycle '.$suffix,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $user = User::query()->create([
            'name' => 'Lifecycle Customer',
            'email' => strtolower($suffix).'-customer@example.test',
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
            'order_number' => 'FDX-'.$suffix,
            'channel' => 'b2c',
            'status' => 'pending',
            'currency' => 'KWD',
            'subtotal' => 10,
            'discount_total' => 0,
            'delivery_total' => 0,
            'grand_total' => 10,
        ]);

        return [$order, $user];
    }

    private function storeAdmin(int $storeId, string $email): User
    {
        $user = User::query()->create([
            'name' => 'Lifecycle Admin',
            'email' => $email,
            'password' => 'password',
            'locale' => 'en',
            'is_active' => true,
        ]);
        $role = Role::query()->where('code', 'B2C_STORE_ADMIN')->firstOrFail();
        $user->roles()->attach($role);
        DB::table('user_store_roles')->insert([
            'user_id' => $user->id,
            'store_id' => $storeId,
            'role_id' => $role->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $user;
    }

    private function driver(int $storeId, string $email): Driver
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

    private function assignment(Order $order, Driver $driver, string $status): DriverAssignment
    {
        return DriverAssignment::query()->create([
            'driver_id' => $driver->id,
            'order_id' => $order->id,
            'store_id' => $order->store_id,
            'assignment_type' => 'b2c',
            'status' => $status,
            'assigned_at' => now(),
        ]);
    }
}
