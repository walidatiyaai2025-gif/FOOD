<?php

namespace Tests\Feature;

use App\Jobs\DispatchPushNotification;
use App\Models\Role;
use App\Models\User;
use App\Services\B2cCustomerService;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class OrderOperationsIsolationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_retail_operations_page_lists_only_assigned_store_orders(): void
    {
        $mine = $this->store('OPS-MINE');
        $foreign = $this->store('OPS-FOREIGN');
        $admin = $this->storeAdmin($mine, 'ops-mine@example.test');

        $mineCustomer = app(B2cCustomerService::class)->create($mine, ['name' => 'Mine Buyer']);
        $foreignCustomer = app(B2cCustomerService::class)->create($foreign, ['name' => 'Foreign Buyer']);

        $mineOrder = $this->order($mine, (int) $mineCustomer->legacy_customer_id, (int) $mineCustomer->id, 'OPS-MINE-1001');
        $foreignOrder = $this->order($foreign, (int) $foreignCustomer->legacy_customer_id, (int) $foreignCustomer->id, 'OPS-FOREIGN-2001');

        $this->actingAs($admin)
            ->get('/admin/operations/orders')
            ->assertOk()
            ->assertSee('OPS-MINE-1001')
            ->assertDontSee('OPS-FOREIGN-2001');

        $this->actingAs($admin)->get('/admin/operations/orders?store_id='.$foreign)->assertNotFound();
        $this->actingAs($admin)->get('/admin/operations/orders?order='.$foreignOrder)->assertNotFound();

        $this->actingAs($admin)
            ->get('/admin/operations/orders?order='.$mineOrder)
            ->assertOk()
            ->assertSee('Order status timeline');
    }

    public function test_order_detail_renders_driver_assignment_timestamps_without_500(): void
    {
        $store = $this->store('OPS-DETAIL-DRIVER');
        $admin = $this->storeAdmin($store, 'ops-detail-driver@example.test');
        $customer = app(B2cCustomerService::class)->create($store, ['name' => 'Detail Buyer']);
        $order = $this->order(
            $store,
            (int) $customer->legacy_customer_id,
            (int) $customer->id,
            'OPS-DETAIL-1001',
        );

        $driverUser = User::query()->create([
            'name' => 'Detail Driver',
            'email' => 'ops-detail-driver-user@example.test',
            'password' => 'password',
            'locale' => 'en',
            'is_active' => true,
        ]);
        $driverId = (int) DB::table('drivers')->insertGetId([
            'user_id' => $driverUser->id,
            'store_id' => $store,
            'driver_type' => 'b2c',
            'is_available' => true,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('driver_assignments')->insert([
            'driver_id' => $driverId,
            'order_id' => $order,
            'store_id' => $store,
            'assignment_type' => 'b2c',
            'status' => 'delivered',
            'assigned_at' => now()->subMinutes(10),
            'completed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($admin)
            ->get('/admin/operations/orders?order='.$order)
            ->assertOk()
            ->assertSee('Driver assignment history')
            ->assertSee('Detail Driver');
    }

    public function test_order_detail_shows_immutable_delivery_address_and_map_action(): void
    {
        $store = $this->store('OPS-DELIVERY-SNAPSHOT');
        $admin = $this->storeAdmin($store, 'ops-delivery-snapshot@example.test');
        $customer = app(B2cCustomerService::class)->create($store, ['name' => 'Snapshot Buyer']);
        $order = $this->order(
            $store,
            (int) $customer->legacy_customer_id,
            (int) $customer->id,
            'OPS-SNAPSHOT-1001',
        );

        DB::table('orders')->where('id', $order)->update([
            'delivery_address_snapshot' => json_encode([
                'version' => 1,
                'address_id' => 55,
                'label' => 'Home',
                'recipient_name' => 'Snapshot Buyer',
                'delivery_phone' => '+201000000555',
                'line1' => 'Immutable Street 5',
                'line2' => null,
                'city' => 'Cairo',
                'area' => 'Nasr City',
                'country_code' => 'EG',
                'country' => 'Egypt',
                'governorate' => 'Cairo',
                'block' => null,
                'street' => 'Immutable Street 5',
                'avenue' => null,
                'building' => '10',
                'floor' => '3',
                'apartment' => '8',
                'landmark' => 'Snapshot landmark',
                'delivery_notes' => 'Snapshot note',
                'latitude' => 30.04442,
                'longitude' => 31.235712,
                'location_accuracy_meters' => 5.0,
                'location_source' => 'map_pin',
            ], JSON_THROW_ON_ERROR),
            'delivery_latitude' => 30.0444200,
            'delivery_longitude' => 31.2357120,
            'updated_at' => now(),
        ]);

        $this->actingAs($admin)
            ->get('/admin/operations/orders?order='.$order)
            ->assertOk()
            ->assertSee('Delivery address')
            ->assertSee('Immutable Street 5')
            ->assertSee('Snapshot landmark')
            ->assertSee('Open in map')
            ->assertSee('30.0444200')
            ->assertSee('31.2357120');
    }

    public function test_driver_reminder_is_actionable_persisted_and_queued(): void
    {
        Queue::fake();

        $store = $this->store('OPS-REMINDER');
        $admin = $this->storeAdmin($store, 'ops-reminder@example.test');
        $customer = app(B2cCustomerService::class)->create($store, [
            'name' => 'Reminder Buyer',
        ]);
        $order = $this->order(
            $store,
            (int) $customer->legacy_customer_id,
            (int) $customer->id,
            'OPS-REMINDER-1001',
        );

        $driverUser = User::query()->create([
            'name' => 'Reminder Driver',
            'email' => 'ops-reminder-driver@example.test',
            'password' => 'password',
            'locale' => 'ar',
            'is_active' => true,
        ]);
        $driverId = (int) DB::table('drivers')->insertGetId([
            'user_id' => $driverUser->id,
            'store_id' => $store,
            'driver_type' => 'b2c',
            'is_available' => true,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $assignmentId = (int) DB::table('driver_assignments')->insertGetId([
            'driver_id' => $driverId,
            'order_id' => $order,
            'store_id' => $store,
            'assignment_type' => 'b2c',
            'status' => 'assigned',
            'assigned_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($admin)
            ->post("/admin/operations/orders/{$order}/remind-driver")
            ->assertRedirect();

        $notification = DB::table('notifications')
            ->where('user_id', $driverUser->id)
            ->where('type', 'order.driver_reminder')
            ->latest('id')
            ->first();

        $this->assertNotNull($notification);
        $this->assertSame('both', $notification->channel);
        $this->assertSame('driver', $notification->app);

        $data = json_decode((string) $notification->data, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame($order, (int) $data['order_id']);
        $this->assertSame($assignmentId, (int) $data['assignment_id']);
        $this->assertFalse((bool) $data['access_revoked']);

        Queue::assertPushed(
            DispatchPushNotification::class,
            fn (DispatchPushNotification $job): bool => $job->notificationId === (int) $notification->id,
        );
    }

    public function test_dashboard_status_transition_notifies_the_order_customer(): void
    {
        Queue::fake();

        $store = $this->store('OPS-CUSTOMER-PUSH');
        $admin = $this->storeAdmin($store, 'ops-customer-push-admin@example.test');
        $customerUser = User::query()->create([
            'name' => 'Push Buyer',
            'email' => 'ops-customer-push@example.test',
            'password' => 'password',
            'locale' => 'en',
            'is_active' => true,
        ]);
        $customer = app(B2cCustomerService::class)->create(
            $store,
            [
                'name' => 'Push Buyer',
                'email' => $customerUser->email,
            ],
            $customerUser,
        );
        $order = $this->order(
            $store,
            (int) $customer->legacy_customer_id,
            (int) $customer->id,
            'OPS-CUSTOMER-PUSH-1001',
        );

        $this->actingAs($admin)
            ->post("/admin/operations/orders/{$order}/status", [
                'status' => 'confirmed',
                'note' => 'Confirmed by dashboard',
            ])
            ->assertRedirect();

        $notification = DB::table('notifications')
            ->where('user_id', $customerUser->id)
            ->where('app', 'customer')
            ->where('type', 'order.status_changed')
            ->latest('id')
            ->first();

        $this->assertNotNull($notification);
        $this->assertSame('both', $notification->channel);
        $data = json_decode((string) $notification->data, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame($order, (int) $data['order_id']);
        $this->assertSame('confirmed', $data['status']);
        $this->assertSame('pending', $data['from_status']);

        Queue::assertPushed(
            DispatchPushNotification::class,
            fn (DispatchPushNotification $job): bool => $job->notificationId === (int) $notification->id,
        );
    }

    public function test_dashboard_cancellation_revokes_driver_and_notifies_customer_and_driver(): void
    {
        Queue::fake();

        $store = $this->store('OPS-CANCEL-PUSH');
        $admin = $this->storeAdmin($store, 'ops-cancel-push-admin@example.test');
        $customerUser = User::query()->create([
            'name' => 'Cancelled Buyer',
            'email' => 'ops-cancel-push@example.test',
            'password' => 'password',
            'locale' => 'en',
            'is_active' => true,
        ]);
        $customer = app(B2cCustomerService::class)->create(
            $store,
            [
                'name' => 'Cancelled Buyer',
                'email' => $customerUser->email,
            ],
            $customerUser,
        );
        $order = $this->order(
            $store,
            (int) $customer->legacy_customer_id,
            (int) $customer->id,
            'OPS-CANCEL-PUSH-1001',
        );

        $driverUser = User::query()->create([
            'name' => 'Cancelled Driver',
            'email' => 'ops-cancel-driver@example.test',
            'password' => 'password',
            'locale' => 'en',
            'is_active' => true,
        ]);
        $driverId = (int) DB::table('drivers')->insertGetId([
            'user_id' => $driverUser->id,
            'store_id' => $store,
            'driver_type' => 'b2c',
            'is_available' => true,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $assignmentId = (int) DB::table('driver_assignments')->insertGetId([
            'driver_id' => $driverId,
            'order_id' => $order,
            'store_id' => $store,
            'assignment_type' => 'b2c',
            'status' => 'assigned',
            'assigned_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($admin)
            ->post("/admin/operations/orders/{$order}/status", [
                'status' => 'cancelled',
                'note' => 'Cancelled by operations',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('driver_assignments', [
            'id' => $assignmentId,
            'status' => 'cancelled',
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'delivery.assignment.cancelled',
            'auditable_id' => $assignmentId,
        ]);

        $customerNotification = DB::table('notifications')
            ->where('user_id', $customerUser->id)
            ->where('app', 'customer')
            ->where('type', 'order.status_changed')
            ->latest('id')
            ->first();
        $driverNotification = DB::table('notifications')
            ->where('user_id', $driverUser->id)
            ->where('app', 'driver')
            ->where('type', 'order.status_changed')
            ->latest('id')
            ->first();

        $this->assertNotNull($customerNotification);
        $this->assertNotNull($driverNotification);

        $customerData = json_decode(
            (string) $customerNotification->data,
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $driverData = json_decode(
            (string) $driverNotification->data,
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        $this->assertSame('cancelled', $customerData['status']);
        $this->assertSame($assignmentId, (int) $driverData['assignment_id']);
        $this->assertTrue((bool) $driverData['access_revoked']);
        $this->assertArrayHasKey('event_at', $driverData);
        $this->assertArrayHasKey('state_version', $driverData);
    }

    public function test_retail_admin_cannot_operate_foreign_order_but_can_transition_own_order(): void
    {
        $mine = $this->store('OPS-ACTION-MINE');
        $foreign = $this->store('OPS-ACTION-FOREIGN');
        $admin = $this->storeAdmin($mine, 'ops-actions@example.test');

        $mineCustomer = app(B2cCustomerService::class)->create($mine, ['name' => 'Mine Buyer']);
        $foreignCustomer = app(B2cCustomerService::class)->create($foreign, ['name' => 'Foreign Buyer']);

        $mineOrder = $this->order($mine, (int) $mineCustomer->legacy_customer_id, (int) $mineCustomer->id, 'OPS-ACTION-1001');
        $foreignOrder = $this->order($foreign, (int) $foreignCustomer->legacy_customer_id, (int) $foreignCustomer->id, 'OPS-ACTION-2001');

        $this->actingAs($admin)
            ->post("/admin/operations/orders/{$foreignOrder}/status", ['status' => 'confirmed'])
            ->assertNotFound();

        $this->assertDatabaseHas('orders', ['id' => $foreignOrder, 'status' => 'pending']);

        $this->actingAs($admin)
            ->post("/admin/operations/orders/{$mineOrder}/status", [
                'status' => 'confirmed',
                'note' => 'Confirmed by operations',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('orders', ['id' => $mineOrder, 'status' => 'confirmed']);
        $this->assertDatabaseHas('order_status_history', [
            'order_id' => $mineOrder,
            'to_status' => 'confirmed',
        ]);
    }

    private function store(string $code): int
    {
        return (int) DB::table('stores')->insertGetId([
            'store_type_id' => DB::table('store_types')->where('code', 'B2C')->value('id'),
            'code' => $code,
            'name' => $code,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function order(int $storeId, int $legacyCustomerId, int $customerId, string $number): int
    {
        $catalog = (int) DB::table('catalogs')->insertGetId([
            'store_id' => $storeId,
            'channel' => 'b2c',
            'code' => 'cat-'.strtolower($number),
            'name' => $number.' Catalog',
            'is_active' => true,
            'is_migration_quarantine' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $unit = (int) DB::table('units')->insertGetId([
            'scope' => 'global',
            'scope_key' => 'global',
            'code' => 'EA-'.$number,
            'name' => 'Each',
            'name_ar' => 'قطعة',
            'name_en' => 'Each',
            'decimal_places' => 0,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $product = (int) DB::table('products')->insertGetId([
            'catalog_id' => $catalog,
            'unit_id' => $unit,
            'sku' => $number.'-SKU',
            'name' => $number.' Item',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $order = (int) DB::table('orders')->insertGetId([
            'store_id' => $storeId,
            'customer_id' => $legacyCustomerId,
            'b2c_customer_id' => $customerId,
            'order_number' => $number,
            'channel' => 'b2c',
            'status' => 'pending',
            'currency' => 'KWD',
            'subtotal' => 10,
            'discount_total' => 0,
            'delivery_total' => 0,
            'grand_total' => 10,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('order_items')->insert([
            'order_id' => $order,
            'product_id' => $product,
            'sku_snapshot' => $number.'-SKU',
            'name_snapshot' => $number.' Item',
            'quantity' => 1,
            'unit_price' => 10,
            'line_total' => 10,
            'currency' => 'KWD',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $order;
    }

    private function storeAdmin(int $storeId, string $email): User
    {
        $user = User::query()->create([
            'name' => 'Retail Operations Admin',
            'email' => $email,
            'password' => 'password',
            'locale' => 'en',
            'is_active' => true,
        ]);
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
}
