<?php

namespace Tests\Feature;

use App\Models\Store;
use App\Models\User;
use App\Services\RetailMerchantDashboardService;
use App\Services\RetailWholesaleAccountService;
use Carbon\CarbonImmutable;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RetailMerchantDashboardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);

        DB::table('settings')->updateOrInsert(
            ['store_id' => null, 'key' => 'checkout.currency'],
            [
                'value' => json_encode('KWD', JSON_THROW_ON_ERROR),
                'is_secret' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );
    }

    public function test_owner_dashboard_combines_retail_intelligence_with_owner_only_wholesale_finance(): void
    {
        $asOf = CarbonImmutable::parse('2026-10-08 12:00:00', 'Asia/Kuwait');
        $owner = $this->user('merchant-dashboard-owner@example.test');
        $fixture = $this->merchantFixture($owner, $asOf);

        $this->retailSale($fixture['retail_store'], $fixture['retail_product'], 7, $asOf->subDay(), 'RECENT');
        $this->retailSale($fixture['retail_store'], $fixture['retail_product'], 7, $asOf->subDays(10), 'MIDDLE');
        $this->retailSale($fixture['retail_store'], $fixture['retail_product'], 16, $asOf->subDays(20), 'OLDER');

        $result = app(RetailMerchantDashboardService::class)->forUserStore(
            $owner,
            $fixture['retail_store'],
            $asOf->subDays(29),
            $asOf,
        );

        $this->assertTrue($result['owner_context']['is_owner']);
        $this->assertTrue($result['owner_context']['wholesale_account_visible']);
        $this->assertSame('available', $result['wholesale_account']['finance_status']);
        $this->assertSame('KWD', $result['wholesale_account']['finance']['currency']);
        $this->assertSame(100.0, $result['wholesale_account']['finance']['purchasing_power']);

        $this->assertSame(1, $result['summary']['reorder_now']);
        $this->assertSame(25.0, $result['summary']['expected_reorder_spend']);
        $this->assertSame($fixture['retail_product'], $result['attention'][0]['retail_product_id']);
        $this->assertSame('reorder_now', $result['attention'][0]['recommendation']['action']);
        $this->assertTrue($result['attention'][0]['recommendation']['is_executable']);
        $this->assertNotEmpty($result['attention'][0]['reason_facts']);

        $this->assertCount(30, $result['visualizations']['retail_vs_wholesale']);
        $this->assertArrayHasKey('critical_understock', $result['visualizations']['stock_risk']);
        $this->assertArrayHasKey('reorder_now', $result['visualizations']['reorder_spend']);
        $this->assertArrayHasKey('days_61_plus', $result['visualizations']['inventory_aging']);
        $this->assertNotEmpty($result['visualizations']['margin_velocity']);
    }

    public function test_retail_manager_gets_store_intelligence_without_owner_personal_wholesale_finance(): void
    {
        $asOf = CarbonImmutable::parse('2026-10-08 12:00:00', 'Asia/Kuwait');
        $owner = $this->user('merchant-finance-owner@example.test');
        $manager = $this->user('merchant-dashboard-manager@example.test');
        $fixture = $this->merchantFixture($owner, $asOf);

        $this->retailSale($fixture['retail_store'], $fixture['retail_product'], 7, $asOf->subDay(), 'RECENT');
        $this->retailSale($fixture['retail_store'], $fixture['retail_product'], 7, $asOf->subDays(10), 'MIDDLE');
        $this->retailSale($fixture['retail_store'], $fixture['retail_product'], 16, $asOf->subDays(20), 'OLDER');

        $result = app(RetailMerchantDashboardService::class)->forUserStore(
            $manager,
            $fixture['retail_store'],
            $asOf->subDays(6),
            $asOf,
        );

        $this->assertFalse($result['owner_context']['is_owner']);
        $this->assertFalse($result['owner_context']['wholesale_account_visible']);
        $this->assertNull($result['wholesale_account']);
        $this->assertSame(1, $result['summary']['reorder_now']);
        $this->assertSame($fixture['retail_product'], $result['recommendations'][0]['retail_product_id']);
    }

    public function test_canonical_dashboard_and_inventory_drill_down_are_wired_in_place(): void
    {
        $workspace = file_get_contents(resource_path('views/admin/b2c-workspace.blade.php'));
        $merchant = file_get_contents(resource_path('views/admin/_merchant-intelligence-dashboard.blade.php'));
        $controller = file_get_contents(app_path('Http/Controllers/Admin/B2cWorkspaceController.php'));

        $this->assertIsString($workspace);
        $this->assertIsString($merchant);
        $this->assertIsString($controller);
        $this->assertStringContainsString("@include('admin._merchant-intelligence-dashboard')", $workspace);
        $this->assertStringContainsString('data-focused-recommendation-product', $workspace);
        $this->assertStringContainsString('data-merchant-intelligence', $merchant);
        $this->assertStringContainsString('data-owner-wholesale-account', $merchant);
        $this->assertStringContainsString('suggested-purchase-plan', $merchant);
        $this->assertStringContainsString('foodex-viz-donut', $merchant);
        $this->assertStringContainsString("'focus_product'", $merchant);
        $this->assertStringContainsString("integer('focus_product')", $controller);
        $this->assertStringContainsString("where('products.id', \$focusProductId)", $controller);
        $this->assertStringNotContainsString('name="account_id"', $merchant);
        $this->assertStringNotContainsString('name="customer_id"', $merchant);
    }

    /** @return array<string,int> */
    private function merchantFixture(User $owner, CarbonImmutable $asOf): array
    {
        $retailStore = $this->store('B2C', 'SMART-RETAIL');
        $wholesaleStore = $this->store('B2B', 'SMART-WHOLESALE');
        $retailProduct = $this->product(
            $retailStore,
            'b2c',
            'SMART-RETAIL-SKU',
            3.000,
            0.500,
            2,
            $asOf->subDays(40),
        );
        $wholesaleProduct = $this->product(
            $wholesaleStore,
            'b2b',
            'SMART-WHOLESALE-SKU',
            15.000,
            null,
            10,
            $asOf->subDays(40),
        );

        $tierId = (int) DB::table('b2b_price_tiers')
            ->where('code', 'STANDARD')
            ->value('id');
        $customer = app(RetailWholesaleAccountService::class)->ensureForStore(
            Store::query()->findOrFail($retailStore),
            $tierId,
            $owner,
        );

        DB::table('b2b_accounts')
            ->where('b2b_customer_id', $customer->getKey())
            ->update(['credit_limit' => 100, 'updated_at' => now()]);

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

    private function user(string $email): User
    {
        return User::query()->create([
            'name' => $email,
            'email' => $email,
            'password' => 'password123',
            'locale' => 'en',
            'is_active' => true,
        ]);
    }
}
