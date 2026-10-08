<?php

namespace Tests\Feature;

use App\Models\Driver;
use App\Models\DriverAssignment;
use App\Models\Notification;
use App\Models\Order;
use App\Models\Role;
use App\Models\User;
use App\Services\B2bCustomerService;
use App\Services\B2cCustomerService;
use App\Services\DashboardOperationalNotifier;
use App\Services\OrderLifecycleNotificationService;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class NotificationAudienceIsolationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
        Queue::fake();
    }

    public function test_retail_operational_events_reach_exact_store_audience_not_platform_super_admin(): void
    {
        $storeA = $this->store('B2C', 'NOTIFY-RETAIL-A');
        $storeB = $this->store('B2C', 'NOTIFY-RETAIL-B');
        $adminA = $this->retailAdmin($storeA, 'notify-retail-a@example.test');
        $adminB = $this->retailAdmin($storeB, 'notify-retail-b@example.test');
        $super = $this->globalAdmin('SUPER_ADMIN', 'notify-platform@example.test');

        $customer = app(B2cCustomerService::class)->create($storeA, [
            'name' => 'Retail Notification Customer',
        ]);
        $order = $this->order(
            $storeA,
            'b2c',
            (int) $customer->legacy_customer_id,
            null,
            (int) $customer->getKey(),
            'NOTIFY-RET-1001',
        );

        $notifier = app(DashboardOperationalNotifier::class);
        $notifier->orderCreated($order);
        $notifier->orderCreated($order);

        $notification = Notification::query()
            ->where('user_id', $adminA->id)
            ->where('app', 'dashboard')
            ->where('type', 'order.created')
            ->sole();

        $this->assertSame('b2c', $notification->target_channel);
        $this->assertSame($storeA, (int) $notification->store_id);
        $this->assertSame('b2c', $notification->data['channel'] ?? null);
        $this->assertSame($storeA, (int) ($notification->data['store_id'] ?? 0));
        $this->assertStringContainsString('store_id='.$storeA, (string) ($notification->data['deep_link'] ?? ''));

        $this->assertDatabaseMissing('notifications', [
            'user_id' => $adminB->id,
            'app' => 'dashboard',
            'type' => 'order.created',
        ]);
        $this->assertDatabaseMissing('notifications', [
            'user_id' => $super->id,
            'app' => 'dashboard',
            'type' => 'order.created',
            'store_id' => $storeA,
        ]);
        $this->assertSame(
            1,
            Notification::query()
                ->where('user_id', $adminA->id)
                ->where('type', 'order.created')
                ->count(),
        );
    }

    public function test_wholesale_operational_events_stay_in_wholesale_audience(): void
    {
        $wholesaleStore = $this->store('B2B', 'NOTIFY-WHOLESALE');
        $retailStore = $this->store('B2C', 'NOTIFY-RETAIL-ONLY');
        $b2bAdmin = $this->globalAdmin('B2B_ADMIN', 'notify-b2b@example.test');
        $super = $this->globalAdmin('SUPER_ADMIN', 'notify-super-b2b@example.test');
        $retailAdmin = $this->retailAdmin($retailStore, 'notify-retail-only@example.test');

        $customer = app(B2bCustomerService::class)->create([
            'name' => 'Wholesale Notification Buyer',
        ]);
        $order = $this->order(
            $wholesaleStore,
            'b2b',
            (int) $customer->legacy_customer_id,
            (int) $customer->getKey(),
            null,
            'NOTIFY-B2B-2001',
        );

        app(DashboardOperationalNotifier::class)->orderCreated($order);

        foreach ([$b2bAdmin, $super] as $recipient) {
            $notification = Notification::query()
                ->where('user_id', $recipient->id)
                ->where('app', 'dashboard')
                ->where('type', 'order.created')
                ->sole();

            $this->assertSame('b2b', $notification->target_channel);
            $this->assertSame($wholesaleStore, (int) $notification->store_id);
            $this->assertSame('b2b', $notification->data['channel'] ?? null);
        }

        $this->assertDatabaseMissing('notifications', [
            'user_id' => $retailAdmin->id,
            'app' => 'dashboard',
            'type' => 'order.created',
        ]);
    }

    public function test_unified_customer_reads_exact_b2b_and_b2c_lifecycle_contexts(): void
    {
        $wholesaleStore = $this->store('B2B', 'NOTIFY-CUSTOMER-B2B');
        $retailStore = $this->store('B2C', 'NOTIFY-CUSTOMER-B2C');
        $user = User::query()->create([
            'name' => 'Unified Notification Customer',
            'email' => 'unified-notify@example.test',
            'password' => 'password',
            'locale' => 'en',
            'is_active' => true,
            'is_platform_customer' => true,
        ]);

        $b2b = app(B2bCustomerService::class)->create([
            'name' => $user->name,
            'email' => $user->email,
        ], $user);
        $b2c = app(B2cCustomerService::class)->create($retailStore, [
            'name' => $user->name,
            'email' => $user->email,
        ], $user);

        $b2bOrder = $this->order(
            $wholesaleStore,
            'b2b',
            (int) $b2b->legacy_customer_id,
            (int) $b2b->getKey(),
            null,
            'NOTIFY-CUST-B2B',
        );
        $b2cOrder = $this->order(
            $retailStore,
            'b2c',
            (int) $b2c->legacy_customer_id,
            null,
            (int) $b2c->getKey(),
            'NOTIFY-CUST-B2C',
        );

        $service = app(OrderLifecycleNotificationService::class);
        $service->customerOrderCreated($b2bOrder);
        $service->customerOrderCreated($b2cOrder);

        $b2bNotification = Notification::query()
            ->where('user_id', $user->id)
            ->where('target_channel', 'b2b')
            ->sole();
        $b2cNotification = Notification::query()
            ->where('user_id', $user->id)
            ->where('target_channel', 'b2c')
            ->sole();

        $this->assertSame($wholesaleStore, (int) $b2bNotification->store_id);
        $this->assertSame($retailStore, (int) $b2cNotification->store_id);
        $this->assertStringContainsString('/b2b/orders/'.$b2bOrder->id, (string) ($b2bNotification->data['deep_link'] ?? ''));
        $this->assertStringContainsString('channel=wholesale', (string) ($b2bNotification->data['deep_link'] ?? ''));
        $this->assertStringContainsString('/orders/'.$b2cOrder->id.'/track', (string) ($b2cNotification->data['deep_link'] ?? ''));
        $this->assertStringContainsString('channel=retail', (string) ($b2cNotification->data['deep_link'] ?? ''));
        $this->assertNotSame($b2bNotification->dedupe_key, $b2cNotification->dedupe_key);

        Sanctum::actingAs($user);
        $this->getJson('/api/v1/notifications?locale=en')
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $this->getJson('/api/v1/b2b/orders/'.$b2bOrder->id)
            ->assertOk()
            ->assertJsonPath('id', $b2bOrder->id);
        $this->getJson('/api/v1/orders/'.$b2cOrder->id.'?channel=b2c&store_id='.$retailStore)
            ->assertOk()
            ->assertJsonPath('id', $b2cOrder->id);
    }

    public function test_revoked_driver_notification_is_visible_but_cannot_authorize_assignment_open(): void
    {
        $store = $this->store('B2C', 'NOTIFY-DRIVER');
        $customer = app(B2cCustomerService::class)->create($store, [
            'name' => 'Driver Notification Customer',
        ]);
        $order = $this->order(
            $store,
            'b2c',
            (int) $customer->legacy_customer_id,
            null,
            (int) $customer->getKey(),
            'NOTIFY-DRV-3001',
        );

        $oldDriver = $this->driver($store, 'old-notify-driver@example.test');
        $newDriver = $this->driver($store, 'new-notify-driver@example.test');

        DriverAssignment::query()->create([
            'driver_id' => $oldDriver->id,
            'order_id' => $order->id,
            'store_id' => $store,
            'assignment_type' => 'b2c',
            'status' => 'reassigned',
            'assigned_at' => now(),
            'completed_at' => now(),
        ]);
        $newAssignment = DriverAssignment::query()->create([
            'driver_id' => $newDriver->id,
            'order_id' => $order->id,
            'store_id' => $store,
            'assignment_type' => 'b2c',
            'status' => 'assigned',
            'assigned_at' => now(),
        ]);

        app(OrderLifecycleNotificationService::class)
            ->driverAssigned($order, $newAssignment, (int) $oldDriver->id);

        $revoked = Notification::query()
            ->where('user_id', $oldDriver->user_id)
            ->where('app', 'driver')
            ->where('type', 'delivery.reassigned_away')
            ->sole();

        $this->assertSame('b2c', $revoked->target_channel);
        $this->assertTrue((bool) ($revoked->data['access_revoked'] ?? false));
        $this->assertSame($store, (int) ($revoked->data['store_id'] ?? 0));
        $this->assertSame($newAssignment->id, (int) ($revoked->data['assignment_id'] ?? 0));
        $this->assertStringContainsString('/driver/b2c/deliveries?', (string) ($revoked->data['deep_link'] ?? ''));

        Sanctum::actingAs(User::query()->findOrFail($oldDriver->user_id), ['app:driver']);
        $this->getJson('/api/v1/notifications')
            ->assertOk()
            ->assertJsonPath('data.0.data.access_revoked', true);

        // Notification metadata is never authorization. The assignment endpoint
        // independently re-checks driver/channel/store/current-assignment scope.
        $this->getJson('/api/v1/driver/assignments/'.$newAssignment->id)
            ->assertNotFound();
    }

    public function test_dashboard_dedupe_key_is_recipient_and_scope_specific(): void
    {
        $store = $this->store('B2C', 'NOTIFY-DEDUPE');
        $adminA = $this->retailAdmin($store, 'notify-dedupe-a@example.test');
        $adminB = $this->retailAdmin($store, 'notify-dedupe-b@example.test');
        $customer = app(B2cCustomerService::class)->create($store, [
            'name' => 'Dedupe Customer',
        ]);
        $order = $this->order(
            $store,
            'b2c',
            (int) $customer->legacy_customer_id,
            null,
            (int) $customer->getKey(),
            'NOTIFY-DEDUPE-4001',
        );

        app(DashboardOperationalNotifier::class)->orderCreated($order);

        $a = Notification::query()
            ->where('user_id', $adminA->id)
            ->where('type', 'order.created')
            ->sole();
        $b = Notification::query()
            ->where('user_id', $adminB->id)
            ->where('type', 'order.created')
            ->sole();

        $this->assertNotSame($a->dedupe_key, $b->dedupe_key);
        $this->assertSame('b2c', $a->target_channel);
        $this->assertSame($store, (int) $a->store_id);
    }

    private function store(string $typeCode, string $code): int
    {
        $typeId = (int) DB::table('store_types')->where('code', $typeCode)->value('id');

        return (int) DB::table('stores')->insertGetId([
            'store_type_id' => $typeId,
            'code' => $code,
            'name' => str_replace('-', ' ', $code),
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function retailAdmin(int $storeId, string $email): User
    {
        $user = $this->user($email);
        $role = Role::query()->where('code', 'B2C_STORE_ADMIN')->firstOrFail();

        DB::table('user_store_roles')->insert([
            'user_id' => $user->id,
            'store_id' => $storeId,
            'role_id' => $role->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $user;
    }

    private function globalAdmin(string $roleCode, string $email): User
    {
        $user = $this->user($email);
        $user->roles()->attach(Role::query()->where('code', $roleCode)->firstOrFail());

        return $user;
    }

    private function user(string $email): User
    {
        return User::query()->create([
            'name' => $email,
            'email' => $email,
            'password' => 'password',
            'locale' => 'en',
            'is_active' => true,
        ]);
    }

    private function driver(int $storeId, string $email): Driver
    {
        $user = $this->user($email);
        $user->roles()->attach(
            Role::query()->where('code', 'B2C_DRIVER')->firstOrFail(),
        );

        return Driver::query()->create([
            'user_id' => $user->id,
            'store_id' => $storeId,
            'driver_type' => 'b2c',
            'is_available' => true,
            'is_active' => true,
        ]);
    }

    private function order(
        int $storeId,
        string $channel,
        int $legacyCustomerId,
        ?int $b2bCustomerId,
        ?int $b2cCustomerId,
        string $number,
    ): Order {
        return Order::query()->create([
            'store_id' => $storeId,
            'customer_id' => $legacyCustomerId,
            'b2b_customer_id' => $b2bCustomerId,
            'b2c_customer_id' => $b2cCustomerId,
            'order_number' => $number,
            'channel' => $channel,
            'status' => 'pending',
            'currency' => 'KWD',
            'subtotal' => 10,
            'discount_total' => 0,
            'delivery_total' => 0,
            'grand_total' => 10,
        ]);
    }
}
