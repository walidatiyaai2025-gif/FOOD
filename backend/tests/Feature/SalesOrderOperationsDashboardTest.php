<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Services\B2cCustomerService;
use App\Services\SalesOrderOperationsDashboardService;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SalesOrderOperationsDashboardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_dashboard_is_scoped_and_exception_drilldown_is_exact(): void
    {
        $admin = $this->superAdmin('sales-dashboard@example.test');
        $store = $this->retailStore('SALES-DASH');
        $foreign = $this->retailStore('SALES-FOREIGN');
        $customer = app(B2cCustomerService::class)->create($store, ['name' => 'Sales Buyer']);
        $foreignCustomer = app(B2cCustomerService::class)->create($foreign, ['name' => 'Foreign Buyer']);

        $delayed = $this->order($store, (int) $customer->legacy_customer_id, (int) $customer->id, 'SALES-DELAYED', 'pending', 12.5, now()->subDays(2));
        $paymentFailure = $this->order($store, (int) $customer->legacy_customer_id, (int) $customer->id, 'SALES-PAYMENT', 'pending', 8, now()->subHour());
        $deliveredNoInvoice = $this->order($store, (int) $customer->legacy_customer_id, (int) $customer->id, 'SALES-NO-INVOICE', 'delivered', 15, now()->subHour());
        $cancelled = $this->order($store, (int) $customer->legacy_customer_id, (int) $customer->id, 'SALES-CANCELLED', 'cancelled', 4, now()->subHour());

        DB::table('order_dispatch_states')->insert([
            'order_id' => $delayed, 'status' => 'awaiting_dispatch',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('payments')->insert([
            'order_id' => $paymentFailure, 'invoice_id' => null, 'provider' => 'TEST',
            'provider_reference' => 'FAILED-1', 'status' => 'failed', 'amount' => 8,
            'currency' => 'KWD', 'metadata' => null, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->order($foreign, (int) $foreignCustomer->legacy_customer_id, (int) $foreignCustomer->id, 'FOREIGN-DELAYED', 'pending', 99, now()->subDays(3));

        $this->actingAs($admin)
            ->get('/admin/operations/orders?channel=b2c&store_id='.$store)
            ->assertOk()
            ->assertViewHas('dashboard', fn (array $dashboard): bool => $dashboard['kpis']['orders'] === 4
                && $dashboard['kpis']['delayed_orders'] === 1
                && $dashboard['kpis']['awaiting_dispatch'] === 1
                && $dashboard['kpis']['cancelled_refunded'] === 1
                && ! array_key_exists('payment_exceptions', $dashboard['kpis'])
                && ! array_key_exists('invoice_exceptions', $dashboard['kpis'])
            )
            ->assertSee('data-sales-operations-dashboard', false)
            ->assertSee('data-order-kpi-card', false)
            ->assertSee('data-sales-exception="delayed"', false)
            ->assertDontSee('data-sales-exception="payment"', false)
            ->assertDontSee('data-sales-exception="invoice"', false);

        $this->actingAs($admin)
            ->get('/admin/operations/orders?channel=b2c&store_id='.$store.'&exception=payment')
            ->assertOk()
            ->assertViewHas('rows', fn (array $rows): bool => count($rows) === 1
                && (int) $rows[0]['id'] === $paymentFailure
            );
    }

    public function test_dashboard_query_count_stays_bounded_with_many_delivered_orders(): void
    {
        $store = $this->retailStore('SALES-PERF');
        $customer = app(B2cCustomerService::class)->create($store, ['name' => 'Performance Buyer']);
        $deliveredAt = now()->startOfSecond();

        for ($index = 1; $index <= 40; $index++) {
            $createdAt = $deliveredAt->copy()->subMinutes(20 + $index);
            $orderId = $this->order(
                $store,
                (int) $customer->legacy_customer_id,
                (int) $customer->id,
                'SALES-PERF-'.$index,
                'delivered',
                10 + $index,
                $createdAt,
            );

            DB::table('order_status_history')->insert([
                'order_id' => $orderId,
                'store_id' => $store,
                'user_id' => null,
                'from_status' => 'out_for_delivery',
                'to_status' => 'delivered',
                'note' => 'Delivered',
                'created_at' => $deliveredAt,
                'updated_at' => $deliveredAt,
            ]);
        }

        DB::flushQueryLog();
        DB::enableQueryLog();

        $dashboard = app(SalesOrderOperationsDashboardService::class)->build(
            ['b2b' => [], 'b2c' => [$store]],
            $store,
        );

        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertSame(40, $dashboard['kpis']['orders']);
        $this->assertNotNull($dashboard['kpis']['average_fulfillment_minutes']);
        $this->assertSame(
            40,
            array_sum(array_column($dashboard['fulfillment_breakdown'], 'count')),
        );
        $this->assertLessThanOrEqual(
            16,
            count($queries),
            'Dashboard query count must stay bounded instead of growing with delivered-order volume.',
        );

        $sql = strtolower(implode("\n", array_column($queries, 'query')));
        $this->assertStringNotContainsString('ordered_at', $sql);
        $this->assertStringNotContainsString('delivered_at', $sql);
    }

    public function test_sales_exception_report_is_scoped_and_exportable(): void
    {
        $admin = $this->superAdmin('sales-report@example.test');
        $store = $this->retailStore('SALES-REPORT');
        $foreign = $this->retailStore('SALES-REPORT-FOREIGN');
        $customer = app(B2cCustomerService::class)->create($store, ['name' => 'Report Buyer']);
        $foreignCustomer = app(B2cCustomerService::class)->create($foreign, ['name' => 'Foreign Report Buyer']);

        $local = $this->order($store, (int) $customer->legacy_customer_id, (int) $customer->id, 'REPORT-DELAYED', 'pending', 11, now()->subDays(2));
        $foreignOrder = $this->order($foreign, (int) $foreignCustomer->legacy_customer_id, (int) $foreignCustomer->id, 'REPORT-FOREIGN', 'pending', 22, now()->subDays(2));

        $this->actingAs($admin)
            ->get('/admin/reports?report=sales_exceptions&channel=b2c&store_id='.$store)
            ->assertOk()
            ->assertViewHas('data', fn (array $data): bool => $data['report'] === 'sales_exceptions'
                && $data['kpis']['exception_orders'] === 1
                && $data['kpis']['delayed_orders'] === 1
                && count($data['rows']) === 1
                && $data['rows'][0]['order_number'] === '#'.$local
                && $data['rows'][0]['exception'] === 'delayed'
            )
            ->assertSee(__('reports.families.sales_exceptions'));

        $this->actingAs($admin)
            ->get('/admin/reports/export?report=sales_exceptions&channel=b2c&store_id='.$store.'&format=xlsx')
            ->assertOk();
    }

    private function order(int $storeId, int $legacyCustomerId, int $b2cCustomerId, string $number, string $status, float $total, mixed $createdAt): int
    {
        return (int) DB::table('orders')->insertGetId([
            'store_id' => $storeId, 'customer_id' => $legacyCustomerId, 'b2c_customer_id' => $b2cCustomerId,
            'order_number' => $number, 'channel' => 'b2c', 'status' => $status, 'currency' => 'KWD',
            'subtotal' => $total, 'discount_total' => 0, 'delivery_total' => 0, 'grand_total' => $total,
            'created_at' => $createdAt, 'updated_at' => $createdAt,
        ]);
    }

    private function retailStore(string $code): int
    {
        return (int) DB::table('stores')->insertGetId([
            'store_type_id' => DB::table('store_types')->where('code', 'B2C')->value('id'),
            'code' => $code, 'name' => $code, 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function superAdmin(string $email): User
    {
        $user = User::query()->create([
            'name' => 'Sales Operations Admin', 'email' => $email, 'password' => 'password',
            'locale' => 'en', 'is_active' => true,
        ]);
        $user->roles()->attach(Role::query()->where('code', 'SUPER_ADMIN')->firstOrFail());

        return $user;
    }
}
