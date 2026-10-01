<?php

namespace Tests\Feature;

use App\Domain\Assistant\Business\AssistantBusinessReadService;
use App\Domain\Assistant\Business\AssistantBusinessTool;
use App\Domain\Assistant\Business\AssistantBusinessToolSet;
use App\Domain\Assistant\Data\AssistantToolRequest;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\TestCase;

class AssistantBusinessToolsTest extends TestCase
{
    use RefreshDatabase;

    private int $storeA;

    private int $storeB;

    private int $customerA;

    private int $customerB;

    private int $productA;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CoreReferenceSeeder::class);
        $this->seedFixture();
    }

    public function test_business_tool_set_exposes_exact_v1_business_keys(): void
    {
        $keys = array_map(
            static fn ($tool): string => $tool->key(),
            app(AssistantBusinessToolSet::class)->all(),
        );

        $this->assertSame(AssistantBusinessTool::KEYS, $keys);
    }

    public function test_sales_summary_and_compare_use_exact_authoritative_totals(): void
    {
        $admin = $this->storeAdmin($this->storeA);
        $summary = $this->tool('sales.summary')->execute($this->request(
            $admin,
            ['from' => '2026-09-10', 'to' => '2026-09-12'],
        ));

        $this->assertSame(3, $summary->data['orders']);
        $this->assertSame(2, $summary->data['recognized_orders']);
        $this->assertSame(139.0, $summary->data['gross_value']);
        $this->assertSame(40.0, $summary->data['recognized_revenue']);

        $compare = $this->tool('sales.compare')->execute($this->request(
            $admin,
            ['from' => '2026-09-12', 'to' => '2026-09-12'],
        ));

        $this->assertSame(10.0, $compare->data['current']['recognized_revenue']);
        $this->assertSame(30.0, $compare->data['previous']['recognized_revenue']);
        $this->assertSame(-20.0, $compare->data['recognized_revenue_delta']);
    }

    public function test_order_lookup_cannot_cross_store_scope(): void
    {
        $admin = $this->storeAdmin($this->storeA);

        $own = $this->tool('orders.lookup')->execute($this->request(
            $admin,
            ['order_number' => 'AST-A-1'],
        ));
        $foreign = $this->tool('orders.lookup')->execute($this->request(
            $admin,
            ['order_number' => 'AST-B-1'],
        ));

        $this->assertTrue($own->data['found']);
        $this->assertSame('AST-A-1', $own->data['order']['order_number']);
        $this->assertFalse($foreign->data['found']);
    }

    public function test_store_compare_rejects_unauthorized_store(): void
    {
        $admin = $this->storeAdmin($this->storeA);

        $this->expectException(NotFoundHttpException::class);

        $this->tool('stores.compare')->execute($this->request(
            $admin,
            ['store_ids' => [$this->storeA, $this->storeB], 'from' => '2026-09-01', 'to' => '2026-09-30'],
        ));
    }

    public function test_customer_activity_cannot_leak_foreign_store_customer(): void
    {
        $admin = $this->storeAdmin($this->storeA);

        $own = $this->tool('customers.activity')->execute($this->request(
            $admin,
            ['customer_id' => $this->customerA, 'from' => '2026-09-01', 'to' => '2026-09-30'],
            'b2c',
        ));
        $foreign = $this->tool('customers.activity')->execute($this->request(
            $admin,
            ['customer_id' => $this->customerB, 'from' => '2026-09-01', 'to' => '2026-09-30'],
            'b2c',
        ));

        $this->assertTrue($own->data['found']);
        $this->assertSame('Customer A', $own->data['customer']);
        $this->assertFalse($foreign->data['found']);
    }

    public function test_product_performance_is_store_scoped_and_exact(): void
    {
        $admin = $this->storeAdmin($this->storeA);
        $result = $this->tool('products.performance')->execute($this->request(
            $admin,
            ['from' => '2026-09-01', 'to' => '2026-09-30'],
        ));

        $this->assertSame(4.0, $result->data['quantity_sold']);
        $this->assertSame(40.0, $result->data['product_revenue']);
        $this->assertSame($this->productA, $result->data['products'][0]['product_id']);
    }

    private function tool(string $key): AssistantBusinessTool
    {
        return new AssistantBusinessTool(app(AssistantBusinessReadService::class), $key);
    }

    /**
     * @param  array<string, mixed>  $entities
     */
    private function request(User $user, array $entities, string $channel = 'b2c'): AssistantToolRequest
    {
        return new AssistantToolRequest(
            actorUserId: (int) $user->id,
            locale: 'en',
            channel: $channel,
            storeId: $this->storeA,
            entities: $entities,
        );
    }

    private function seedFixture(): void
    {
        $typeId = (int) DB::table('store_types')->where('code', 'B2C')->value('id');
        $this->storeA = $this->store($typeId, 'AST-A', 'Assistant Store A');
        $this->storeB = $this->store($typeId, 'AST-B', 'Assistant Store B');

        $this->customerA = (int) DB::table('b2c_customers')->insertGetId([
            'store_id' => $this->storeA,
            'name' => 'Customer A',
            'email' => 'customer-a@assistant.test',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->customerB = (int) DB::table('b2c_customers')->insertGetId([
            'store_id' => $this->storeB,
            'name' => 'Customer B',
            'email' => 'customer-b@assistant.test',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $legacyA = (int) DB::table('customers')->insertGetId([
            'type' => 'b2c',
            'name' => 'Customer A',
            'email' => 'customer-a@assistant.test',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $legacyB = (int) DB::table('customers')->insertGetId([
            'type' => 'b2c',
            'name' => 'Customer B',
            'email' => 'customer-b@assistant.test',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $unitId = (int) DB::table('units')->insertGetId([
            'code' => 'AST-EA',
            'name' => 'Each',
            'decimal_places' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $catalogA = $this->catalog($this->storeA, 'AST-CAT-A');
        $catalogB = $this->catalog($this->storeB, 'AST-CAT-B');
        $this->productA = $this->product($catalogA, $unitId, 'AST-P-A', 'Product A');
        $productB = $this->product($catalogB, $unitId, 'AST-P-B', 'Product B');

        $orderA1 = $this->order($this->storeA, $legacyA, $this->customerA, 'AST-A-1', 'delivered', 30, '2026-09-11 10:00:00');
        $orderA2 = $this->order($this->storeA, $legacyA, $this->customerA, 'AST-A-2', 'delivered', 10, '2026-09-12 10:00:00');
        $this->order($this->storeA, $legacyA, $this->customerA, 'AST-A-C', 'cancelled', 99, '2026-09-12 11:00:00');
        $orderB = $this->order($this->storeB, $legacyB, $this->customerB, 'AST-B-1', 'delivered', 70, '2026-09-12 10:00:00');

        DB::table('order_items')->insert([
            [
                'order_id' => $orderA1,
                'product_id' => $this->productA,
                'sku_snapshot' => 'AST-P-A',
                'name_snapshot' => 'Product A',
                'quantity' => 3,
                'unit_price' => 10,
                'line_total' => 30,
                'created_at' => '2026-09-11 10:00:00',
                'updated_at' => '2026-09-11 10:00:00',
            ],
            [
                'order_id' => $orderA2,
                'product_id' => $this->productA,
                'sku_snapshot' => 'AST-P-A',
                'name_snapshot' => 'Product A',
                'quantity' => 1,
                'unit_price' => 10,
                'line_total' => 10,
                'created_at' => '2026-09-12 10:00:00',
                'updated_at' => '2026-09-12 10:00:00',
            ],
            [
                'order_id' => $orderB,
                'product_id' => $productB,
                'sku_snapshot' => 'AST-P-B',
                'name_snapshot' => 'Product B',
                'quantity' => 7,
                'unit_price' => 10,
                'line_total' => 70,
                'created_at' => '2026-09-12 10:00:00',
                'updated_at' => '2026-09-12 10:00:00',
            ],
        ]);
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

    private function storeAdmin(int $storeId): User
    {
        $user = User::query()->create([
            'name' => 'Assistant Store Admin',
            'email' => 'assistant-store-admin-'.$storeId.'@example.test',
            'password' => 'password',
            'is_active' => true,
        ]);
        $roleId = (int) Role::query()->where('code', 'B2C_STORE_ADMIN')->value('id');
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
