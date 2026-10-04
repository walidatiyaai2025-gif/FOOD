<?php

namespace Tests\Feature;

use App\Models\Driver;
use App\Models\DriverAssignment;
use App\Models\Invoice;
use App\Models\Notification;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Role;
use App\Models\User;
use App\Services\B2cCustomerService;
use App\Services\DashboardOperationalNotifier;
use App\Services\NotificationAudience;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DriverInvoiceNotificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_assigned_driver_receives_safe_invoice_and_note_timeline_only_for_own_assignment(): void
    {
        $storeId = $this->store('DRIVER-INVOICE-A');
        $customer = app(B2cCustomerService::class)->create($storeId, [
            'name' => 'Driver Invoice Buyer',
            'email' => 'driver-invoice-buyer@example.test',
            'phone' => '50000001',
        ]);
        $productId = $this->product($storeId, 'DRV-SKU');
        $order = Order::query()->create([
            'store_id' => $storeId,
            'customer_id' => $customer->legacy_customer_id,
            'b2c_customer_id' => $customer->id,
            'order_number' => 'DRV-INV-1',
            'channel' => 'b2c',
            'status' => 'ready',
            'currency' => 'EGP',
            'subtotal' => 25,
            'discount_total' => 2,
            'delivery_total' => 3,
            'tax_total' => 1,
            'grand_total' => 27,
            'payment_method' => 'cash_on_delivery',
        ]);
        DB::table('order_items')->insert([
            'order_id' => $order->id,
            'product_id' => $productId,
            'sku_snapshot' => 'DRV-SKU',
            'name_snapshot' => 'Driver item',
            'quantity' => 2,
            'unit_price' => 12.5,
            'line_total' => 25,
            'currency' => 'EGP',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $invoiceId = (int) DB::table('invoices')->insertGetId([
            'order_id' => $order->id,
            'store_id' => $storeId,
            'customer_id' => $customer->legacy_customer_id,
            'b2c_customer_id' => $customer->id,
            'invoice_number' => 'INV-DRV-1',
            'status' => 'issued',
            'channel' => 'b2c',
            'order_number_snapshot' => $order->order_number,
            'store_name_snapshot' => 'Driver Invoice Store',
            'customer_name_snapshot' => 'Driver Invoice Buyer',
            'currency' => 'EGP',
            'subtotal' => 25,
            'discount_total' => 2,
            'delivery_total' => 3,
            'tax_total' => 1,
            'total' => 27,
            'payment_method_snapshot' => 'cash_on_delivery',
            'payment_status_snapshot' => 'pending',
            'revision' => 1,
            'issued_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('invoice_items')->insert([
            'invoice_id' => $invoiceId,
            'product_id' => $productId,
            'sku_snapshot' => 'DRV-SKU',
            'description' => 'Driver item',
            'quantity' => 2,
            'unit_price' => 12.5,
            'line_total' => 25,
            'currency' => 'EGP',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        Payment::query()->create([
            'order_id' => $order->id,
            'invoice_id' => $invoiceId,
            'provider' => 'cash_on_delivery',
            'status' => 'pending',
            'amount' => 27,
            'currency' => 'EGP',
        ]);

        $admin = $this->storeAdmin($storeId, 'driver-invoice-admin@example.test');
        $driverUser = $this->roleUser('B2C_DRIVER', 'driver-invoice-driver@example.test');
        $otherDriverUser = $this->roleUser('B2C_DRIVER', 'driver-invoice-other@example.test');
        $driver = Driver::query()->create([
            'user_id' => $driverUser->id,
            'store_id' => $storeId,
            'driver_type' => 'b2c',
            'is_available' => true,
            'is_active' => true,
        ]);
        $otherDriver = Driver::query()->create([
            'user_id' => $otherDriverUser->id,
            'store_id' => $storeId,
            'driver_type' => 'b2c',
            'is_available' => true,
            'is_active' => true,
        ]);

        Sanctum::actingAs($admin);
        $assignmentId = $this->postJson('/api/v1/admin/deliveries/assign', [
            'driver_id' => $driver->id,
            'order_id' => $order->id,
        ])->assertCreated()->json('data.id');

        Sanctum::actingAs($driverUser);
        $this->getJson('/api/v1/driver/assignments/'.$assignmentId)
            ->assertOk()
            ->assertJsonPath('data.order.invoice.number', 'INV-DRV-1')
            ->assertJsonPath('data.order.invoice.grand_total', 27)
            ->assertJsonPath('data.order.invoice.payment_method', 'cash_on_delivery')
            ->assertJsonPath('data.order.invoice.outstanding_amount', 27)
            ->assertJsonPath('data.order.invoice.download_path', '/api/v1/driver/assignments/'.$assignmentId.'/invoice/download')
            ->assertJsonPath('data.order.settlement.order_total', 27)
            ->assertJsonPath('data.order.settlement.balance_applied', 0)
            ->assertJsonPath('data.order.settlement.remaining_amount', 27)
            ->assertJsonPath('data.order.settlement.remainder_method', 'cash_on_delivery')
            ->assertJsonPath('data.order.settlement.payment_state', 'unpaid')
            ->assertJsonPath('data.order.settlement.amount_to_collect_now', 27)
            ->assertJsonPath('data.order.invoice.items.0.sku', 'DRV-SKU')
            ->assertJsonMissingPath('data.order.invoice.customer_email')
            ->assertJsonMissingPath('data.order.invoice.b2b_account_id');

        $this->get('/api/v1/driver/assignments/'.$assignmentId.'/invoice/download?locale=en')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');

        $this->postJson('/api/v1/driver/assignments/'.$assignmentId.'/status', [
            'status' => 'accepted',
            'note' => 'Customer confirmed the gate.',
        ])->assertOk()->assertJsonPath('data.status', 'accepted');

        $this->assertDatabaseHas('delivery_proofs', [
            'driver_assignment_id' => $assignmentId,
            'user_id' => $driverUser->id,
            'from_status' => 'assigned',
            'to_status' => 'accepted',
            'note' => 'Customer confirmed the gate.',
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'delivery.assignment.status_changed',
            'user_id' => $driverUser->id,
        ]);

        $this->postJson('/api/v1/driver/assignments/'.$assignmentId.'/status', [
            'status' => 'delivered',
        ])->assertConflict();

        Sanctum::actingAs($otherDriverUser);
        $this->getJson('/api/v1/driver/assignments/'.$assignmentId)->assertNotFound();
        $this->get('/api/v1/driver/assignments/'.$assignmentId.'/invoice/download?locale=en')
            ->assertNotFound();

        Sanctum::actingAs($admin);
        $replacementAssignmentId = $this->postJson('/api/v1/admin/deliveries/assign', [
            'driver_id' => $otherDriver->id,
            'order_id' => $order->id,
            'replace_existing' => true,
        ])->assertCreated()->json('data.id');

        Sanctum::actingAs($driverUser);
        $this->get('/api/v1/driver/assignments/'.$assignmentId.'/invoice/download?locale=en')
            ->assertNotFound();

        Sanctum::actingAs($otherDriverUser);
        $this->get('/api/v1/driver/assignments/'.$replacementAssignmentId.'/invoice/download?locale=en')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');
    }

    public function test_pending_order_cannot_be_assigned_before_customer_service_approval(): void
    {
        $storeId = $this->store('DRIVER-APPROVAL-GATE');
        $customer = app(B2cCustomerService::class)->create($storeId, [
            'name' => 'Pending Buyer',
            'email' => 'pending-driver-buyer@example.test',
        ]);
        $order = Order::query()->create([
            'store_id' => $storeId,
            'customer_id' => $customer->legacy_customer_id,
            'b2c_customer_id' => $customer->id,
            'order_number' => 'DRV-PENDING-1',
            'channel' => 'b2c',
            'status' => 'pending',
            'currency' => 'KWD',
            'subtotal' => 10,
            'discount_total' => 0,
            'delivery_total' => 0,
            'tax_total' => 0,
            'grand_total' => 10,
            'payment_method' => 'cash_on_delivery',
        ]);
        $admin = $this->storeAdmin($storeId, 'driver-approval-admin@example.test');
        $driverUser = $this->roleUser('B2C_DRIVER', 'driver-approval-driver@example.test');
        $driver = Driver::query()->create([
            'user_id' => $driverUser->id,
            'store_id' => $storeId,
            'driver_type' => 'b2c',
            'is_available' => true,
            'is_active' => true,
        ]);

        Sanctum::actingAs($admin);
        $this->postJson('/api/v1/admin/deliveries/assign', [
            'driver_id' => $driver->id,
            'order_id' => $order->id,
        ])->assertConflict();

        $this->assertDatabaseMissing('driver_assignments', [
            'order_id' => $order->id,
            'driver_id' => $driver->id,
        ]);
    }

    public function test_checkout_settlement_snapshot_controls_driver_collection_instruction(): void
    {
        $storeId = $this->store('DRIVER-SETTLEMENT');
        $customer = app(B2cCustomerService::class)->create($storeId, [
            'name' => 'Settlement Buyer',
            'email' => 'driver-settlement@example.test',
        ]);
        $driverUser = $this->roleUser('B2C_DRIVER', 'driver-settlement-driver@example.test');
        $driver = Driver::query()->create([
            'user_id' => $driverUser->id,
            'store_id' => $storeId,
            'driver_type' => 'b2c',
            'is_available' => true,
            'is_active' => true,
        ]);

        foreach ([
            ['number' => 'DRV-COD-70', 'method' => 'cash_on_delivery', 'collect' => 70],
            ['number' => 'DRV-DEBT-70', 'method' => 'account_debt', 'collect' => 0],
        ] as $case) {
            $order = Order::query()->create([
                'store_id' => $storeId,
                'customer_id' => $customer->legacy_customer_id,
                'b2c_customer_id' => $customer->id,
                'order_number' => $case['number'],
                'channel' => 'b2c',
                'status' => 'ready',
                'currency' => 'KWD',
                'subtotal' => 100,
                'discount_total' => 0,
                'delivery_total' => 0,
                'tax_total' => 0,
                'grand_total' => 100,
                'payment_method' => $case['method'],
            ]);
            $invoice = Invoice::query()->create([
                'order_id' => $order->id,
                'store_id' => $storeId,
                'customer_id' => $customer->legacy_customer_id,
                'b2c_customer_id' => $customer->id,
                'invoice_number' => 'INV-'.$case['number'],
                'status' => 'issued',
                'channel' => 'b2c',
                'order_number_snapshot' => $case['number'],
                'currency' => 'KWD',
                'total' => 100,
                'payment_method_snapshot' => $case['method'],
                'payment_status_snapshot' => 'pending',
                'revision' => 1,
                'issued_at' => now(),
            ]);
            Payment::query()->create([
                'order_id' => $order->id,
                'invoice_id' => $invoice->id,
                'provider' => $case['method'],
                'status' => 'pending',
                'amount' => 70,
                'currency' => 'KWD',
                'metadata' => [
                    'customer_balance_applied' => 30,
                    'remaining_amount' => 70,
                    'remainder_method' => $case['method'],
                ],
            ]);
            $assignment = DriverAssignment::query()->create([
                'driver_id' => $driver->id,
                'order_id' => $order->id,
                'store_id' => $storeId,
                'assignment_type' => 'b2c',
                'status' => 'assigned',
                'assigned_at' => now(),
            ]);

            Sanctum::actingAs($driverUser);
            $this->getJson('/api/v1/driver/assignments/'.$assignment->id)
                ->assertOk()
                ->assertJsonPath('data.order.settlement.order_total', 100)
                ->assertJsonPath('data.order.settlement.balance_applied', 30)
                ->assertJsonPath('data.order.settlement.remaining_amount', 70)
                ->assertJsonPath('data.order.settlement.invoice_outstanding_amount', 70)
                ->assertJsonPath('data.order.settlement.remainder_method', $case['method'])
                ->assertJsonPath('data.order.settlement.payment_state', 'partially_settled')
                ->assertJsonPath('data.order.settlement.amount_to_collect_now', $case['collect']);
        }
    }

    public function test_retail_operational_notifications_are_store_scoped_deduplicated_and_have_authorized_deep_links(): void
    {
        $storeA = $this->store('NOTIFY-A');
        $storeB = $this->store('NOTIFY-B');
        $adminA = $this->storeAdmin($storeA, 'notify-a@example.test');
        $adminB = $this->storeAdmin($storeB, 'notify-b@example.test');

        $customer = app(B2cCustomerService::class)->create($storeA, [
            'name' => 'Notify Buyer',
            'email' => 'notify-buyer@example.test',
        ]);
        $order = Order::query()->create([
            'store_id' => $storeA,
            'customer_id' => $customer->legacy_customer_id,
            'b2c_customer_id' => $customer->id,
            'order_number' => 'NOTIFY-ORDER-1',
            'channel' => 'b2c',
            'status' => 'pending',
            'currency' => 'EGP',
            'subtotal' => 10,
            'discount_total' => 0,
            'delivery_total' => 0,
            'tax_total' => 0,
            'grand_total' => 10,
            'payment_method' => 'cash_on_delivery',
        ]);

        $notifier = app(DashboardOperationalNotifier::class);
        $notifier->orderCreated($order);
        $notifier->orderCreated($order);

        $this->assertSame(
            1,
            Notification::query()
                ->where('user_id', $adminA->id)
                ->where('type', 'order.created')
                ->count(),
        );
        $this->assertSame(
            0,
            Notification::query()
                ->where('user_id', $adminB->id)
                ->where('store_id', $storeA)
                ->count(),
        );

        $visibleToA = app(NotificationAudience::class)
            ->apply(Notification::query(), $adminA)
            ->where('type', 'order.created')
            ->count();
        $visibleToB = app(NotificationAudience::class)
            ->apply(Notification::query(), $adminB)
            ->where('type', 'order.created')
            ->count();
        $this->assertSame(1, $visibleToA);
        $this->assertSame(0, $visibleToB);

        $this->actingAs($adminA)
            ->getJson('/admin/notifications/live')
            ->assertOk()
            ->assertJsonPath('data.0.type', 'order.created')
            ->assertJsonPath('data.0.data.store_id', $storeA)
            ->assertJsonPath('data.0.data.channel', 'b2c');

        $this->actingAs($adminB)
            ->getJson('/admin/notifications/live')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $invoice = Invoice::query()->create([
            'order_id' => $order->id,
            'store_id' => $storeA,
            'customer_id' => $customer->legacy_customer_id,
            'b2c_customer_id' => $customer->id,
            'invoice_number' => 'INV-NOTIFY-1',
            'status' => 'issued',
            'channel' => 'b2c',
            'order_number_snapshot' => $order->order_number,
            'currency' => 'EGP',
            'total' => 10,
            'issued_at' => now(),
        ]);
        $notifier->invoiceChanged($order, $invoice, 'issued');

        $invoiceNotification = Notification::query()
            ->where('user_id', $adminA->id)
            ->where('type', 'invoice.issued')
            ->firstOrFail();
        $this->assertSame(
            '/admin/invoices/'.$invoice->id,
            data_get($invoiceNotification->data, 'deep_link'),
        );

        $this->actingAs($adminB)
            ->get('/admin/invoices/'.$invoice->id)
            ->assertNotFound();
    }

    public function test_replaying_same_driver_event_does_not_duplicate_unread_dashboard_event(): void
    {
        $storeId = $this->store('NOTIFY-DRIVER');
        $admin = $this->storeAdmin($storeId, 'notify-driver-admin@example.test');
        $customer = app(B2cCustomerService::class)->create($storeId, [
            'name' => 'Driver Notify Buyer',
        ]);
        $order = Order::query()->create([
            'store_id' => $storeId,
            'customer_id' => $customer->legacy_customer_id,
            'b2c_customer_id' => $customer->id,
            'order_number' => 'NOTIFY-DRV-1',
            'channel' => 'b2c',
            'status' => 'ready',
            'currency' => 'EGP',
            'subtotal' => 10,
            'discount_total' => 0,
            'delivery_total' => 0,
            'tax_total' => 0,
            'grand_total' => 10,
        ]);
        $driverUser = $this->roleUser('B2C_DRIVER', 'notify-driver@example.test');
        $driver = Driver::query()->create([
            'user_id' => $driverUser->id,
            'store_id' => $storeId,
            'driver_type' => 'b2c',
            'is_available' => true,
            'is_active' => true,
        ]);
        $assignment = DriverAssignment::query()->create([
            'driver_id' => $driver->id,
            'order_id' => $order->id,
            'store_id' => $storeId,
            'assignment_type' => 'b2c',
            'status' => 'accepted',
            'assigned_at' => now(),
        ]);
        DB::table('audit_logs')->insert([
            'user_id' => $driverUser->id,
            'store_id' => $storeId,
            'event' => 'delivery.assignment.status_changed',
            'auditable_type' => DriverAssignment::class,
            'auditable_id' => $assignment->id,
            'before' => json_encode(['status' => 'assigned']),
            'after' => json_encode(['status' => 'accepted']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $notifier = app(DashboardOperationalNotifier::class);
        $notifier->deliveryChanged($order, 'accepted', $assignment, 'Gate confirmed', 'assigned');
        $notifier->deliveryChanged($order, 'accepted', $assignment, 'Gate confirmed', 'assigned');

        $this->assertSame(
            1,
            Notification::query()
                ->where('user_id', $admin->id)
                ->where('type', 'delivery.status_changed')
                ->count(),
        );
        $this->assertSame(
            1,
            Notification::query()
                ->where('user_id', $admin->id)
                ->where('type', 'delivery.note_added')
                ->count(),
        );
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

    private function product(int $storeId, string $sku): int
    {
        $catalogId = (int) DB::table('catalogs')->insertGetId([
            'store_id' => $storeId,
            'channel' => 'b2c',
            'code' => 'cat-'.strtolower($sku),
            'name' => $sku.' Catalog',
            'is_active' => true,
            'is_migration_quarantine' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $unitId = (int) DB::table('units')->insertGetId([
            'scope' => 'global',
            'scope_key' => 'global',
            'code' => 'EA-'.$sku,
            'name' => 'Each',
            'name_ar' => 'قطعة',
            'name_en' => 'Each',
            'decimal_places' => 0,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return (int) DB::table('products')->insertGetId([
            'catalog_id' => $catalogId,
            'unit_id' => $unitId,
            'sku' => $sku,
            'name' => $sku,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function storeAdmin(int $storeId, string $email): User
    {
        $user = User::query()->create([
            'name' => 'Store Admin',
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

    private function roleUser(string $roleCode, string $email): User
    {
        $user = User::query()->create([
            'name' => $roleCode,
            'email' => $email,
            'password' => 'password',
            'locale' => 'en',
            'is_active' => true,
        ]);
        $user->roles()->attach(Role::query()->where('code', $roleCode)->firstOrFail());

        return $user;
    }
}
