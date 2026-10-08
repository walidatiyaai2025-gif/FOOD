<?php

namespace Tests\Feature;

use App\Models\Store;
use App\Services\RetailReorderIntelligenceService;
use App\Services\RetailWholesaleAccountService;
use Carbon\CarbonImmutable;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RetailReorderIntelligenceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_recommendation_uses_historical_lead_time_conversion_moq_increment_price_and_availability(): void
    {
        $asOf = CarbonImmutable::parse('2026-10-08 12:00:00', 'Asia/Kuwait');
        $fixture = $this->merchantFixture($asOf, 10);

        $this->retailSale($fixture['retail_store'], $fixture['retail_product'], 7, $asOf->subDay(), 'RECENT');
        $this->retailSale($fixture['retail_store'], $fixture['retail_product'], 7, $asOf->subDays(10), 'MIDDLE');
        $this->retailSale($fixture['retail_store'], $fixture['retail_product'], 16, $asOf->subDays(20), 'OLDER');

        $this->receipt($fixture, $asOf->subDays(12), $asOf->subDays(3), 'LEAD-9');
        $this->receipt($fixture, $asOf->subDays(9), $asOf->subDays(2), 'LEAD-7');
        $this->receipt($fixture, $asOf->subDays(6), $asOf->subDay(), 'LEAD-5');

        $result = app(RetailReorderIntelligenceService::class)
            ->forStore($fixture['retail_store'], $asOf);
        $row = $result['recommendations'][0];

        $this->assertSame(7.0, $result['lead_time']['average_days']);
        $this->assertSame(7.0, $result['lead_time']['median_days']);
        $this->assertSame(5.0, $result['lead_time']['recent_days']);
        $this->assertSame(3, $result['lead_time']['sample_size']);
        $this->assertSame('medium', $result['lead_time']['confidence']);
        $this->assertSame('historical_median', $result['lead_time']['expected_source']);
        $this->assertSame(7.0, $result['lead_time']['expected_days']);

        $this->assertSame(1.0, $row['inventory']['velocity_units_per_day']);
        $this->assertSame(2.0, $row['inventory']['days_of_cover']);
        $this->assertSame(10.0, $row['reorder_model']['reorder_point']);
        $this->assertSame(24.0, $row['reorder_model']['target_stock']);
        $this->assertSame(22.0, $row['reorder_model']['retail_units_needed']);
        $this->assertSame(24.0, $row['mapping']['quantity_conversion_factor']);

        $this->assertSame(2.0, $row['commercial']['minimum_order_quantity']);
        $this->assertSame(2.0, $row['commercial']['ordering_increment']);
        $this->assertSame(10.0, $row['commercial']['wholesale_available_quantity']);
        $this->assertSame(1.0, $row['commercial']['recommended_case_equivalent']);
        $this->assertSame('reorder_now', $row['recommendation']['action']);
        $this->assertSame('executable', $row['recommendation']['state']);
        $this->assertSame(2.0, $row['recommendation']['unconstrained_wholesale_quantity']);
        $this->assertSame(2.0, $row['recommendation']['recommended_wholesale_quantity']);
        $this->assertSame(48.0, $row['recommendation']['recommended_retail_equivalent']);
        $this->assertSame(25.0, $row['recommendation']['expected_cost']);
        $this->assertTrue($row['recommendation']['is_executable']);
        $this->assertSame('reorder_now_below_lead_time', $row['explanation']['code']);
        $this->assertGreaterThan(0, $row['priority_score']);
    }

    public function test_recommendation_never_exceeds_authoritative_wholesale_availability_or_b2b_quantity_rules(): void
    {
        $asOf = CarbonImmutable::parse('2026-10-08 12:00:00', 'Asia/Kuwait');
        $fixture = $this->merchantFixture($asOf, 1);

        $this->retailSale($fixture['retail_store'], $fixture['retail_product'], 7, $asOf->subDay(), 'RECENT');
        $this->retailSale($fixture['retail_store'], $fixture['retail_product'], 7, $asOf->subDays(10), 'MIDDLE');
        $this->retailSale($fixture['retail_store'], $fixture['retail_product'], 16, $asOf->subDays(20), 'OLDER');
        $this->receipt($fixture, $asOf->subDays(12), $asOf->subDays(3), 'LEAD-9');
        $this->receipt($fixture, $asOf->subDays(9), $asOf->subDays(2), 'LEAD-7');
        $this->receipt($fixture, $asOf->subDays(6), $asOf->subDay(), 'LEAD-5');

        $row = app(RetailReorderIntelligenceService::class)
            ->forStore($fixture['retail_store'], $asOf)['recommendations'][0];

        $this->assertSame(1.0, $row['commercial']['wholesale_available_quantity']);
        $this->assertSame(2.0, $row['recommendation']['unconstrained_wholesale_quantity']);
        $this->assertSame(0.0, $row['recommendation']['recommended_wholesale_quantity']);
        $this->assertSame(0.0, $row['recommendation']['expected_cost']);
        $this->assertFalse($row['recommendation']['is_executable']);
        $this->assertSame('blocked_by_availability', $row['recommendation']['state']);
        $this->assertSame('reorder_blocked_by_availability', $row['explanation']['code']);
    }

    public function test_no_demand_overstock_returns_do_not_reorder_and_lead_time_falls_back_without_history(): void
    {
        $asOf = CarbonImmutable::parse('2026-10-08 12:00:00', 'Asia/Kuwait');
        $fixture = $this->merchantFixture($asOf, 10, 100);

        $result = app(RetailReorderIntelligenceService::class)
            ->forStore($fixture['retail_store'], $asOf);
        $row = $result['recommendations'][0];

        $this->assertSame(0, $result['lead_time']['sample_size']);
        $this->assertSame('fallback', $result['lead_time']['confidence']);
        $this->assertSame('policy_default', $result['lead_time']['expected_source']);
        $this->assertSame(7.0, $result['lead_time']['expected_days']);
        $this->assertSame('no_demand', $row['inventory']['movement_class']);
        $this->assertSame('overstock_no_demand', $row['inventory']['stock_risk']);
        $this->assertSame('do_not_reorder', $row['recommendation']['action']);
        $this->assertSame('working_capital_protection', $row['recommendation']['state']);
        $this->assertSame(0.0, $row['recommendation']['recommended_wholesale_quantity']);
        $this->assertSame('do_not_reorder_no_demand', $row['explanation']['code']);
        $this->assertSame(0.0, $row['priority_score']);
    }

    public function test_missing_mapping_is_an_explicit_business_blocker_instead_of_a_guess(): void
    {
        $asOf = CarbonImmutable::parse('2026-10-08 12:00:00', 'Asia/Kuwait');
        $fixture = $this->merchantFixture($asOf, 10);
        DB::table('retail_wholesale_product_mappings')
            ->where('retail_store_id', $fixture['retail_store'])
            ->delete();

        $this->retailSale($fixture['retail_store'], $fixture['retail_product'], 7, $asOf->subDay(), 'RECENT');
        $this->retailSale($fixture['retail_store'], $fixture['retail_product'], 7, $asOf->subDays(10), 'MIDDLE');
        $this->retailSale($fixture['retail_store'], $fixture['retail_product'], 16, $asOf->subDays(20), 'OLDER');

        $row = app(RetailReorderIntelligenceService::class)
            ->forStore($fixture['retail_store'], $asOf)['recommendations'][0];

        $this->assertSame('missing_mapping', $row['recommendation']['action']);
        $this->assertSame('blocked_missing_mapping', $row['recommendation']['state']);
        $this->assertFalse($row['recommendation']['is_executable']);
        $this->assertSame('missing_wholesale_mapping', $row['explanation']['code']);
        $this->assertArrayHasKey('retail_units_needed', $row['reason_facts']);
    }

    /** @return array<string,int> */
    private function merchantFixture(
        CarbonImmutable $asOf,
        float $wholesaleAvailable,
        float $retailOnHand = 2,
    ): array {
        $retailStore = $this->store('B2C', 'REORDER-RETAIL');
        $wholesaleStore = $this->store('B2B', 'REORDER-WHOLESALE');
        $retailProduct = $this->product(
            $retailStore,
            'b2c',
            'REORDER-RETAIL-SKU',
            3.000,
            0.500,
            $retailOnHand,
            $asOf->subDays(40),
        );
        $wholesaleProduct = $this->product(
            $wholesaleStore,
            'b2b',
            'REORDER-WHOLESALE-SKU',
            15.000,
            null,
            $wholesaleAvailable,
            $asOf->subDays(40),
        );

        $tierId = (int) DB::table('b2b_price_tiers')
            ->where('code', 'STANDARD')
            ->value('id');
        $customer = app(RetailWholesaleAccountService::class)->ensureForStore(
            Store::query()->findOrFail($retailStore),
            $tierId,
        );

        DB::table('b2b_price_rules')->insert([
            'price_tier_id' => $tierId,
            'store_id' => $wholesaleStore,
            'product_id' => $wholesaleProduct,
            'unit_price' => 12.500,
            'minimum_quantity' => 2,
            'ordering_increment' => 2,
            'pack_size' => 1,
            'case_size' => 2,
            'pack_label' => 'Case',
            'is_active' => true,
            'created_at' => $asOf->subDays(40)->utc()->toDateTimeString(),
            'updated_at' => $asOf->subDays(40)->utc()->toDateTimeString(),
        ]);

        DB::table('retail_wholesale_product_mappings')->insert([
            'retail_store_id' => $retailStore,
            'source_wholesale_product_id' => $wholesaleProduct,
            'retail_product_id' => $retailProduct,
            'quantity_conversion_factor' => 24,
            'mapped_by_user_id' => null,
            'created_at' => $asOf->subDays(35)->utc()->toDateTimeString(),
            'updated_at' => $asOf->subDays(35)->utc()->toDateTimeString(),
        ]);

        return [
            'retail_store' => $retailStore,
            'wholesale_store' => $wholesaleStore,
            'retail_product' => $retailProduct,
            'wholesale_product' => $wholesaleProduct,
            'b2b_customer' => (int) $customer->getKey(),
            'legacy_customer' => (int) $customer->legacy_customer_id,
        ];
    }

    private function store(string $type, string $code): int
    {
        return (int) DB::table('stores')->insertGetId([
            'store_type_id' => DB::table('store_types')->where('code', $type)->value('id'),
            'code' => $code,
            'name' => $code,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function product(
        int $storeId,
        string $channel,
        string $sku,
        float $price,
        ?float $cost,
        float $quantity,
        CarbonImmutable $listedAt,
    ): int {
        $catalogId = (int) DB::table('catalogs')->insertGetId([
            'store_id' => $storeId,
            'channel' => $channel,
            'code' => 'default',
            'name' => $sku.' Catalog',
            'is_active' => true,
            'is_migration_quarantine' => false,
            'created_at' => $listedAt->utc()->toDateTimeString(),
            'updated_at' => $listedAt->utc()->toDateTimeString(),
        ]);
        $unitId = (int) DB::table('units')->orderBy('id')->value('id');
        $productId = (int) DB::table('products')->insertGetId([
            'catalog_id' => $catalogId,
            'unit_id' => $unitId,
            'sku' => $sku,
            'name' => $sku,
            'is_active' => true,
            'created_at' => $listedAt->utc()->toDateTimeString(),
            'updated_at' => $listedAt->utc()->toDateTimeString(),
        ]);
        DB::table('store_products')->insert([
            'store_id' => $storeId,
            'product_id' => $productId,
            'price' => $price,
            'cost_price' => $cost,
            'is_active' => true,
            'created_at' => $listedAt->utc()->toDateTimeString(),
            'updated_at' => $listedAt->utc()->toDateTimeString(),
        ]);
        $warehouseId = (int) DB::table('warehouses')->insertGetId([
            'store_id' => $storeId,
            'code' => 'WH-'.$sku,
            'name' => 'Warehouse '.$sku,
            'is_active' => true,
            'created_at' => $listedAt->utc()->toDateTimeString(),
            'updated_at' => $listedAt->utc()->toDateTimeString(),
        ]);
        DB::table('inventories')->insert([
            'warehouse_id' => $warehouseId,
            'product_id' => $productId,
            'quantity' => $quantity,
            'reserved_quantity' => 0,
            'created_at' => $listedAt->utc()->toDateTimeString(),
            'updated_at' => $listedAt->utc()->toDateTimeString(),
        ]);

        return $productId;
    }

    private function retailSale(
        int $storeId,
        int $productId,
        float $quantity,
        CarbonImmutable $at,
        string $number,
    ): void {
        $customerId = (int) DB::table('customers')->insertGetId([
            'type' => 'b2c',
            'name' => $number.' Customer',
            'created_at' => $at->utc()->toDateTimeString(),
            'updated_at' => $at->utc()->toDateTimeString(),
        ]);
        $orderId = (int) DB::table('orders')->insertGetId([
            'store_id' => $storeId,
            'customer_id' => $customerId,
            'order_number' => $number.'-'.$storeId,
            'channel' => 'b2c',
            'status' => 'delivered',
            'currency' => 'KWD',
            'subtotal' => $quantity,
            'discount_total' => 0,
            'delivery_total' => 0,
            'grand_total' => $quantity,
            'created_at' => $at->utc()->toDateTimeString(),
            'updated_at' => $at->utc()->toDateTimeString(),
        ]);
        DB::table('order_items')->insert([
            'order_id' => $orderId,
            'product_id' => $productId,
            'sku_snapshot' => 'SKU-'.$productId,
            'name_snapshot' => 'Product '.$productId,
            'quantity' => $quantity,
            'unit_price' => 1,
            'line_total' => $quantity,
            'created_at' => $at->utc()->toDateTimeString(),
            'updated_at' => $at->utc()->toDateTimeString(),
        ]);
    }

    /** @param array<string,int> $fixture */
    private function receipt(
        array $fixture,
        CarbonImmutable $orderedAt,
        CarbonImmutable $receivedAt,
        string $number,
    ): void {
        $orderId = (int) DB::table('orders')->insertGetId([
            'store_id' => $fixture['wholesale_store'],
            'customer_id' => $fixture['legacy_customer'],
            'b2b_customer_id' => $fixture['b2b_customer'],
            'order_number' => $number,
            'channel' => 'b2b',
            'status' => 'delivered',
            'currency' => 'KWD',
            'subtotal' => 12.500,
            'discount_total' => 0,
            'delivery_total' => 0,
            'grand_total' => 12.500,
            'created_at' => $orderedAt->utc()->toDateTimeString(),
            'updated_at' => $orderedAt->utc()->toDateTimeString(),
        ]);
        $orderItemId = (int) DB::table('order_items')->insertGetId([
            'order_id' => $orderId,
            'product_id' => $fixture['wholesale_product'],
            'sku_snapshot' => 'REORDER-WHOLESALE-SKU',
            'name_snapshot' => 'Wholesale Product',
            'quantity' => 1,
            'quantity_conversion_factor' => 24,
            'unit_price' => 12.500,
            'line_total' => 12.500,
            'created_at' => $orderedAt->utc()->toDateTimeString(),
            'updated_at' => $orderedAt->utc()->toDateTimeString(),
        ]);
        $replenishmentId = (int) DB::table('retail_replenishments')->insertGetId([
            'retail_store_id' => $fixture['retail_store'],
            'source_order_id' => $orderId,
            'source_wholesale_store_id' => $fixture['wholesale_store'],
            'b2b_customer_id' => $fixture['b2b_customer'],
            'received_by_user_id' => null,
            'received_at' => $receivedAt->utc()->toDateTimeString(),
            'created_at' => $receivedAt->utc()->toDateTimeString(),
            'updated_at' => $receivedAt->utc()->toDateTimeString(),
        ]);
        DB::table('retail_replenishment_items')->insert([
            'replenishment_id' => $replenishmentId,
            'source_order_item_id' => $orderItemId,
            'source_product_id' => $fixture['wholesale_product'],
            'retail_product_id' => $fixture['retail_product'],
            'quantity' => 24,
            'quantity_conversion_factor' => 24,
            'unit_cost' => 0.521,
            'line_total' => 12.500,
            'created_at' => $receivedAt->utc()->toDateTimeString(),
            'updated_at' => $receivedAt->utc()->toDateTimeString(),
        ]);
    }
}
