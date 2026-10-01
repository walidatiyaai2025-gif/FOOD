<?php

namespace Tests\Feature;

use App\Domain\Assistant\Data\AssistantToolRequest;
use App\Domain\Assistant\Operations\AssistantOperationsReadService;
use App\Domain\Assistant\Operations\AssistantOperationsTool;
use App\Domain\Assistant\Operations\AssistantOperationsToolSet;
use App\Models\Role;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class AssistantOperationsToolsTest extends TestCase
{
    use RefreshDatabase;

    private int $storeA;

    private int $storeB;

    private int $warehouseA;

    private int $productA;

    private int $lateOrderA;

    private int $driverA;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-01 10:00:00', 'Asia/Kuwait'));
        $this->seed(CoreReferenceSeeder::class);
        $this->seedFixture();
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_operations_tool_set_exposes_exact_v1_keys(): void
    {
        $keys = array_map(
            static fn ($tool): string => $tool->key(),
            app(AssistantOperationsToolSet::class)->all(),
        );

        $this->assertSame(AssistantOperationsTool::KEYS, $keys);
    }

    public function test_late_orders_are_reproducible_and_store_scoped(): void
    {
        $admin = $this->storeAdmin($this->storeA);
        $result = $this->tool('orders.late')->execute($this->request($admin));

        $this->assertSame(60, $result->data['late_after_minutes']);
        $this->assertSame(1, $result->data['late_orders']);
        $this->assertSame($this->lateOrderA, $result->data['rows'][0]['id']);
        $this->assertSame([$this->storeA], $result->data['store_ids']);
    }

    public function test_cancellation_results_and_rate_use_exact_seeded_orders(): void
    {
        $admin = $this->storeAdmin($this->storeA);

        $cancelled = $this->tool('orders.cancelled')->execute($this->request($admin));
        $summary = $this->tool('cancellations.summary')->execute($this->request($admin));

        $this->assertSame(1, $cancelled->data['cancelled_orders']);
        $this->assertSame(4, $summary->data['orders']);
        $this->assertSame(1, $summary->data['cancelled_orders']);
        $this->assertSame(25.0, $summary->data['cancellation_rate_percent']);
        $this->assertSame(99.0, $summary->data['cancelled_value']);
    }

    public function test_driver_status_and_assignments_cannot_leak_foreign_store(): void
    {
        $admin = $this->storeAdmin($this->storeA);

        $status = $this->tool('drivers.status')->execute($this->request($admin));
        $assignments = $this->tool('drivers.assignments')->execute($this->request($admin));

        $this->assertSame(1, $status->data['driver_count']);
        $this->assertSame($this->driverA, $status->data['drivers'][0]['driver_id']);
        $this->assertSame('online', $status->data['drivers'][0]['location_status']);
        $this->assertSame('busy', $status->data['drivers'][0]['operational_status']);
        $this->assertSame(1, $assignments->data['assignment_count']);
        $this->assertSame($this->driverA, $assignments->data['assignments'][0]['driver_id']);
    }

    public function test_inventory_alerts_use_platform_threshold_and_scope(): void
    {
        $admin = $this->storeAdmin($this->storeA);
        $result = $this->tool('inventory.alerts')->execute($this->request($admin));

        $this->assertSame(12.0, $result->data['threshold']);
        $this->assertSame(1, $result->data['alert_count']);
        $this->assertSame($this->storeA, $result->data['alerts'][0]['store_id']);
        $this->assertSame($this->productA, $result->data['alerts'][0]['product_id']);
        $this->assertSame(9.0, $result->data['alerts'][0]['available_quantity']);
    }

    public function test_inventory_alerts_require_domain_permission_in_addition_to_assistant_use(): void
    {
        $operations = $this->storeRoleUser($this->storeA, 'RETAIL_OPERATIONS');

        try {
            $this->tool('inventory.alerts')->execute($this->request($operations));
            $this->fail('Expected inventory Assistant tool to reject a role without inventory.view.');
        } catch (HttpException $exception) {
            $this->assertSame(404, $exception->getStatusCode());
        }
    }

    public function test_daily_brief_is_deterministic_and_composed_from_authorized_sections(): void
    {
        $admin = $this->storeAdmin($this->storeA);
        $result = $this->tool('brief.daily')->execute($this->request($admin));

        $this->assertSame(
            ['late_orders', 'cancellations', 'drivers', 'inventory'],
            $result->data['available_sections'],
        );
        $this->assertSame(1, $result->data['sections']['late_orders']['late_orders']);
        $this->assertSame(1, $result->data['sections']['cancellations']['cancelled_orders']);
        $this->assertSame(1, $result->data['sections']['drivers']['driver_count']);
        $this->assertSame(1, $result->data['sections']['inventory']['alert_count']);
    }

    private function tool(string $key): AssistantOperationsTool
    {
        return new AssistantOperationsTool(app(AssistantOperationsReadService::class), $key);
    }

    private function request(User $user): AssistantToolRequest
    {
        return new AssistantToolRequest(
            actorUserId: (int) $user->id,
            locale: 'en',
            channel: 'b2c',
            storeId: $this->storeA,
            entities: [
                'from' => '2026-10-01',
                'to' => '2026-10-01',
            ],
        );
    }

    private function seedFixture(): void
    {
        $typeId = (int) DB::table('store_types')->where('code', 'B2C')->value('id');
        $this->storeA = $this->store($typeId, 'ASO-A', 'Assistant Ops Store A');
        $this->storeB = $this->store($typeId, 'ASO-B', 'Assistant Ops Store B');

        $this->warehouseA = $this->warehouse($this->storeA, 'ASO-W-A', 'Ops Warehouse A');
        $warehouseB = $this->warehouse($this->storeB, 'ASO-W-B', 'Ops Warehouse B');

        $unitId = (int) DB::table('units')->insertGetId([
            'code' => 'ASO-EA',
            'name' => 'Each',
            'decimal_places' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $catalogA = $this->catalog($this->storeA, 'ASO-CAT-A');
        $catalogB = $this->catalog($this->storeB, 'ASO-CAT-B');
        $this->productA = $this->product($catalogA, $unitId, 'ASO-P-A', 'Ops Product A');
        $productB = $this->product($catalogB, $unitId, 'ASO-P-B', 'Ops Product B');

        DB::table('inventories')->insert([
            [
                'warehouse_id' => $this->warehouseA,
                'product_id' => $this->productA,
                'quantity' => 10,
                'reserved_quantity' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'warehouse_id' => $warehouseB,
                'product_id' => $productB,
                'quantity' => 1,
                'reserved_quantity' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $legacyA = $this->legacyCustomer('Ops Customer A');
        $legacyB = $this->legacyCustomer('Ops Customer B');
        $customerA = $this->b2cCustomer($this->storeA, 'Ops Customer A');
        $customerB = $this->b2cCustomer($this->storeB, 'Ops Customer B');

        $this->lateOrderA = $this->order(
            $this->storeA,
            $legacyA,
            $customerA,
            'ASO-A-LATE',
            'ready',
            40,
            '2026-10-01 05:30:00',
        );
        $this->order(
            $this->storeA,
            $legacyA,
            $customerA,
            'ASO-A-RECENT',
            'processing',
            20,
            '2026-10-01 06:45:00',
        );
        $this->order(
            $this->storeA,
            $legacyA,
            $customerA,
            'ASO-A-CANCEL',
            'cancelled',
            99,
            '2026-10-01 06:30:00',
        );
        $this->order(
            $this->storeA,
            $legacyA,
            $customerA,
            'ASO-A-DONE',
            'delivered',
            30,
            '2026-10-01 06:00:00',
        );
        $foreignLate = $this->order(
            $this->storeB,
            $legacyB,
            $customerB,
            'ASO-B-LATE',
            'ready',
            70,
            '2026-10-01 05:00:00',
        );
        $this->order(
            $this->storeB,
            $legacyB,
            $customerB,
            'ASO-B-CANCEL',
            'cancelled',
            55,
            '2026-10-01 06:00:00',
        );

        $this->driverA = $this->driver($this->storeA, 'ASO Driver A');
        $driverB = $this->driver($this->storeB, 'ASO Driver B');

        $assignmentA = (int) DB::table('driver_assignments')->insertGetId([
            'driver_id' => $this->driverA,
            'order_id' => $this->lateOrderA,
            'store_id' => $this->storeA,
            'assignment_type' => 'b2c',
            'status' => 'assigned',
            'assigned_at' => '2026-10-01 06:00:00',
            'created_at' => '2026-10-01 06:00:00',
            'updated_at' => '2026-10-01 06:00:00',
        ]);
        $assignmentB = (int) DB::table('driver_assignments')->insertGetId([
            'driver_id' => $driverB,
            'order_id' => $foreignLate,
            'store_id' => $this->storeB,
            'assignment_type' => 'b2c',
            'status' => 'assigned',
            'assigned_at' => '2026-10-01 06:00:00',
            'created_at' => '2026-10-01 06:00:00',
            'updated_at' => '2026-10-01 06:00:00',
        ]);

        $this->location($this->driverA, $this->storeA, $assignmentA, '2026-10-01 06:59:40');
        $this->location($driverB, $this->storeB, $assignmentB, '2026-10-01 06:59:40');
    }

    private function store(int $typeId, string $code, string $name): int
    {
        return (int) DB::table('stores')->insertGetId([
            'store_type_id' => $typeId,
            'code' => $code,
            'name' => $name,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function warehouse(int $storeId, string $code, string $name): int
    {
        return (int) DB::table('warehouses')->insertGetId([
            'store_id' => $storeId,
            'code' => $code,
            'name' => $name,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function catalog(int $storeId, string $code): int
    {
        return (int) DB::table('catalogs')->insertGetId([
            'store_id' => $storeId,
            'channel' => 'b2c',
            'code' => $code,
            'name' => $code,
            'is_active' => true,
            'is_migration_quarantine' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function product(int $catalogId, int $unitId, string $sku, string $name): int
    {
        return (int) DB::table('products')->insertGetId([
            'catalog_id' => $catalogId,
            'unit_id' => $unitId,
            'sku' => $sku,
            'name' => $name,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function legacyCustomer(string $name): int
    {
        return (int) DB::table('customers')->insertGetId([
            'type' => 'b2c',
            'name' => $name,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function b2cCustomer(int $storeId, string $name): int
    {
        return (int) DB::table('b2c_customers')->insertGetId([
            'store_id' => $storeId,
            'name' => $name,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function order(
        int $storeId,
        int $legacyCustomerId,
        int $b2cCustomerId,
        string $number,
        string $status,
        float $total,
        string $date,
    ): int {
        return (int) DB::table('orders')->insertGetId([
            'store_id' => $storeId,
            'customer_id' => $legacyCustomerId,
            'b2c_customer_id' => $b2cCustomerId,
            'order_number' => $number,
            'channel' => 'b2c',
            'status' => $status,
            'currency' => 'EGP',
            'subtotal' => $total,
            'discount_total' => 0,
            'delivery_total' => 0,
            'grand_total' => $total,
            'created_at' => $date,
            'updated_at' => $date,
        ]);
    }

    private function driver(int $storeId, string $name): int
    {
        $userId = (int) DB::table('users')->insertGetId([
            'name' => $name,
            'username' => strtolower(str_replace(' ', '-', $name)),
            'email' => strtolower(str_replace(' ', '-', $name)).'@assistant.test',
            'password' => bcrypt('password'),
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return (int) DB::table('drivers')->insertGetId([
            'user_id' => $userId,
            'store_id' => $storeId,
            'driver_type' => 'b2c',
            'is_available' => true,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function location(int $driverId, int $storeId, int $assignmentId, string $receivedAt): void
    {
        DB::table('driver_current_locations')->insert([
            'driver_id' => $driverId,
            'store_id' => $storeId,
            'channel' => 'b2c',
            'latitude' => 29.3,
            'longitude' => 47.9,
            'captured_at' => $receivedAt,
            'received_at' => $receivedAt,
            'active_assignment_id' => $assignmentId,
            'created_at' => $receivedAt,
            'updated_at' => $receivedAt,
        ]);
    }

    private function storeAdmin(int $storeId): User
    {
        return $this->storeRoleUser($storeId, 'B2C_STORE_ADMIN');
    }

    private function storeRoleUser(int $storeId, string $roleCode): User
    {
        $user = User::query()->create([
            'name' => 'Assistant '.$roleCode,
            'email' => strtolower($roleCode).'-'.$storeId.'@assistant.test',
            'password' => 'password',
            'is_active' => true,
        ]);
        $roleId = (int) Role::query()->where('code', $roleCode)->value('id');

        DB::table('user_store_roles')->insert([
            'user_id' => $user->id,
            'store_id' => $storeId,
            'role_id' => $roleId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $user;
    }
}
