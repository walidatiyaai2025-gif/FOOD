<?php

namespace Tests\Feature;

use App\Enterprise\Entitlements\EntitlementScope;
use App\Enterprise\Reporting\ReadModels\OrdersSummaryReadModel;
use App\Enterprise\Reporting\ReportQuery;
use App\Enterprise\Reporting\ReportRegistry;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class EnterpriseOrdersSummaryReadModelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_orders_summary_is_store_scoped_deterministic_and_uses_one_order_query(): void
    {
        $store = $this->store('BI-ORDERS-A');
        $otherStore = $this->store('BI-ORDERS-B');
        $customer = $this->customer('BI Orders Buyer');

        $day = now()->subDay()->startOfDay();
        $this->order($store, $customer, 'BI-A-001', 'b2c', 'completed', '10.000', $day->copy()->addHours(9));
        $this->order($store, $customer, 'BI-A-002', 'b2c', 'completed', '20.000', $day->copy()->addHours(10));
        $this->order($store, $customer, 'BI-A-003', 'b2b', 'pending', '9.500', $day->copy()->addHours(11));
        $this->order($otherStore, $customer, 'BI-B-001', 'b2c', 'completed', '999.000', $day->copy()->addHours(12));

        $report = app(ReportRegistry::class)->require('orders.summary');
        $query = ReportQuery::fromArray($report, [
            'from' => $day->toDateString(),
            'to' => $day->toDateString(),
            'per_page' => 10,
        ]);

        $orderQueries = [];
        DB::listen(static function ($event) use (&$orderQueries): void {
            $sql = strtolower((string) $event->sql);
            if (str_contains($sql, 'from "orders"') || str_contains($sql, 'from `orders`')) {
                $orderQueries[] = $sql;
            }
        });

        $result = app(OrdersSummaryReadModel::class)->read(
            $query,
            new EntitlementScope(EntitlementScope::STORE, $store),
        );

        $this->assertCount(2, $result['rows']);
        $completed = collect($result['rows'])->firstWhere('status', 'completed');
        $pending = collect($result['rows'])->firstWhere('status', 'pending');

        $this->assertSame(2, $completed['order_count']);
        $this->assertSame('30.000', $completed['gross_total']);
        $this->assertSame('15.000', $completed['average_order_value']);
        $this->assertSame('b2c', $completed['channel']);
        $this->assertSame(1, $pending['order_count']);
        $this->assertSame('9.500', $pending['gross_total']);
        $this->assertSame(1, count($orderQueries));
    }

    public function test_orders_summary_applies_allowlisted_filters_pagination_and_bounded_default_window(): void
    {
        $store = $this->store('BI-ORDERS-FILTER');
        $customer = $this->customer('BI Filter Buyer');

        $recent = now()->subDays(2)->startOfDay()->addHours(10);
        $old = now()->subDays(40)->startOfDay()->addHours(10);

        $this->order($store, $customer, 'BI-F-001', 'b2c', 'completed', '12.000', $recent);
        $this->order($store, $customer, 'BI-F-002', 'b2b', 'completed', '13.000', $recent);
        $this->order($store, $customer, 'BI-F-003', 'b2c', 'pending', '14.000', $recent);
        $this->order($store, $customer, 'BI-F-OLD', 'b2c', 'completed', '500.000', $old);

        $report = app(ReportRegistry::class)->require('orders.summary');
        $query = ReportQuery::fromArray($report, [
            'filters' => [
                'status' => 'completed',
                'channel' => 'b2c',
                'currency' => 'kwd',
            ],
            'per_page' => 1,
        ]);

        $result = app(OrdersSummaryReadModel::class)->read(
            $query,
            new EntitlementScope(EntitlementScope::STORE, $store),
        );

        $this->assertCount(1, $result['rows']);
        $this->assertSame('12.000', $result['rows'][0]['gross_total']);
        $this->assertSame('KWD', $result['rows'][0]['currency']);
        $this->assertFalse($result['has_more']);
        $this->assertSame(
            $report->maxRangeDays,
            now()->parse($result['from'])->diffInDays(now()->parse($result['to'])) + 1,
        );
    }

    public function test_orders_summary_registry_and_supporting_index_are_canonical(): void
    {
        $report = app(ReportRegistry::class)->require('orders.summary');

        $this->assertSame('orders_summary_daily', $report->readModel);
        $this->assertSame('admin.operations.orders.index', $report->drillDownRoute);
        $this->assertSame(['date', 'status', 'channel', 'currency'], $report->dimensions);
        $this->assertSame(['orders', 'gross_total', 'average_order_value'], $report->measures);
        $this->assertContains(
            'orders_store_created_idx',
            array_column(Schema::getIndexes('orders'), 'name'),
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

    private function customer(string $name): int
    {
        return (int) DB::table('customers')->insertGetId([
            'type' => 'b2c',
            'name' => $name,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function order(
        int $store,
        int $customer,
        string $number,
        string $channel,
        string $status,
        string $total,
        mixed $createdAt,
    ): void {
        DB::table('orders')->insert([
            'store_id' => $store,
            'customer_id' => $customer,
            'order_number' => $number,
            'channel' => $channel,
            'status' => $status,
            'currency' => 'KWD',
            'subtotal' => $total,
            'discount_total' => 0,
            'delivery_total' => 0,
            'grand_total' => $total,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);
    }
}
