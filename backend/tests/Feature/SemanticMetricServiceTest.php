<?php

namespace Tests\Feature;

use App\Domain\Analytics\Semantic\MetricDictionary;
use App\Domain\Analytics\Semantic\SemanticMetricService;
use Carbon\CarbonImmutable;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class SemanticMetricServiceTest extends TestCase
{
    use RefreshDatabase;

    private int $storeId;

    private int $customerId;

    private int $productId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CoreReferenceSeeder::class);

        $typeId = (int) DB::table('store_types')->where('code', 'B2C')->value('id');
        $this->storeId = (int) DB::table('stores')->insertGetId([
            'store_type_id' => $typeId,
            'code' => 'SEM-STORE',
            'name' => 'Semantic Store',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->customerId = (int) DB::table('customers')->insertGetId([
            'type' => 'b2c',
            'name' => 'Semantic Customer',
            'email' => 'semantic@example.test',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $unitId = (int) DB::table('units')->insertGetId([
            'code' => 'SEM-EA',
            'name' => 'Each',
            'decimal_places' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $catalogId = (int) DB::table('catalogs')->insertGetId([
            'store_id' => $this->storeId,
            'channel' => 'b2c',
            'code' => 'SEM-CAT',
            'name' => 'Semantic Catalog',
            'is_active' => true,
            'is_migration_quarantine' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->productId = (int) DB::table('products')->insertGetId([
            'catalog_id' => $catalogId,
            'unit_id' => $unitId,
            'sku' => 'SEM-1',
            'name' => 'Semantic Product',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_sell_in_and_sell_out_are_computed_separately_with_golden_values(): void
    {
        $sellIn = $this->order('SEM-B2B-1', 'b2b', 'delivered', 120, 20, 0, 100, '2026-09-11 08:00:00');
        $this->item($sellIn, 3);
        $this->order('SEM-B2B-C', 'b2b', 'cancelled', 50, 0, 0, 50, '2026-09-11 09:00:00');

        $sellOut = $this->order('SEM-B2C-1', 'b2c', 'delivered', 80, 10, 5, 75, '2026-09-10 21:30:00');
        $this->item($sellOut, 2);
        $this->order('SEM-B2C-R', 'b2c', 'refunded', 40, 0, 0, 40, '2026-09-11 10:00:00');

        $from = CarbonImmutable::parse('2026-09-11', 'Asia/Kuwait')->startOfDay()->utc()->toDateTimeString();
        $to = CarbonImmutable::parse('2026-09-11', 'Asia/Kuwait')->endOfDay()->utc()->toDateTimeString();
        $query = DB::table('orders')
            ->where('orders.store_id', $this->storeId)
            ->whereBetween('orders.created_at', [$from, $to]);

        $service = app(SemanticMetricService::class);
        $sellInMetrics = $service->orderSnapshot(
            $query,
            MetricDictionary::FLOW_SELL_IN,
            '2026-09-11',
            '2026-09-11',
            ['store_ids' => [$this->storeId]],
            'KWD',
        );
        $sellOutMetrics = $service->orderSnapshot(
            $query,
            MetricDictionary::FLOW_SELL_OUT,
            '2026-09-11',
            '2026-09-11',
            ['store_ids' => [$this->storeId]],
            'KWD',
            'ar',
        );

        $this->assertSame(2, $sellInMetrics['values']['orders']);
        $this->assertSame(1, $sellInMetrics['values']['recognized_orders']);
        $this->assertSame(120.0, $sellInMetrics['values']['gross_revenue']);
        $this->assertSame(20.0, $sellInMetrics['values']['discounts']);
        $this->assertSame(100.0, $sellInMetrics['values']['net_revenue']);
        $this->assertSame(3.0, $sellInMetrics['values']['units']);
        $this->assertSame(1, $sellInMetrics['values']['cancelled_orders']);
        $this->assertSame(50.0, $sellInMetrics['values']['cancelled_value']);
        $this->assertSame('b2b', $sellInMetrics['provenance']['channel']);
        $this->assertSame('sell_in', $sellInMetrics['provenance']['flow']);

        $this->assertSame(2, $sellOutMetrics['values']['orders']);
        $this->assertSame(1, $sellOutMetrics['values']['recognized_orders']);
        $this->assertSame(85.0, $sellOutMetrics['values']['gross_revenue']);
        $this->assertSame(10.0, $sellOutMetrics['values']['discounts']);
        $this->assertSame(75.0, $sellOutMetrics['values']['net_revenue']);
        $this->assertSame(2.0, $sellOutMetrics['values']['units']);
        $this->assertSame(1, $sellOutMetrics['values']['refunded_orders']);
        $this->assertSame(40.0, $sellOutMetrics['values']['refunded_value']);
        $this->assertSame('b2c', $sellOutMetrics['provenance']['channel']);
        $this->assertSame('sell_out', $sellOutMetrics['provenance']['flow']);
        $this->assertSame('Asia/Kuwait', $sellOutMetrics['provenance']['timezone']);
        $this->assertSame('صافي الإيراد', $sellOutMetrics['definitions']['revenue.net']['label']);
    }

    public function test_empty_window_returns_zero_metrics_and_keeps_provenance(): void
    {
        $query = DB::table('orders')
            ->where('orders.store_id', $this->storeId)
            ->whereBetween('orders.created_at', ['2030-01-01 00:00:00', '2030-01-01 23:59:59']);

        $metrics = app(SemanticMetricService::class)->orderSnapshot(
            $query,
            MetricDictionary::FLOW_SELL_OUT,
            '2030-01-01',
            '2030-01-01',
            ['store_ids' => [$this->storeId]],
            'KWD',
        );

        $this->assertSame(0, $metrics['values']['orders']);
        $this->assertSame(0, $metrics['values']['recognized_orders']);
        $this->assertSame(0.0, $metrics['values']['net_revenue']);
        $this->assertSame(0.0, $metrics['values']['average_order_value']);
        $this->assertSame(['2030-01-01', '2030-01-01'], array_values($metrics['provenance']['window']));
        $this->assertFalse($metrics['provenance']['model_arithmetic_allowed']);
    }

    public function test_dictionary_defines_inventory_and_reorder_semantics_in_both_languages(): void
    {
        $dictionary = app(MetricDictionary::class);
        $english = $dictionary->catalog('en');
        $arabic = $dictionary->catalog('ar');

        $this->assertArrayHasKey('inventory.stock', $english);
        $this->assertArrayHasKey('inventory.stock_cover', $english);
        $this->assertArrayHasKey('inventory.velocity', $english);
        $this->assertArrayHasKey('inventory.reorder', $english);
        $this->assertArrayHasKey('performance.index', $english);
        $this->assertSame('Stock cover', $english['inventory.stock_cover']['label']);
        $this->assertSame('تغطية المخزون', $arabic['inventory.stock_cover']['label']);
    }

    private function order(
        string $number,
        string $channel,
        string $status,
        float $subtotal,
        float $discount,
        float $delivery,
        float $grandTotal,
        string $createdAt,
    ): int {
        return (int) DB::table('orders')->insertGetId([
            'store_id' => $this->storeId,
            'customer_id' => $this->customerId,
            'order_number' => $number,
            'channel' => $channel,
            'status' => $status,
            'currency' => 'KWD',
            'subtotal' => $subtotal,
            'discount_total' => $discount,
            'delivery_total' => $delivery,
            'grand_total' => $grandTotal,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);
    }

    private function item(int $orderId, float $quantity): void
    {
        DB::table('order_items')->insert([
            'order_id' => $orderId,
            'product_id' => $this->productId,
            'sku_snapshot' => 'SEM-1',
            'name_snapshot' => 'Semantic Product',
            'quantity' => $quantity,
            'unit_price' => 10,
            'line_total' => $quantity * 10,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
