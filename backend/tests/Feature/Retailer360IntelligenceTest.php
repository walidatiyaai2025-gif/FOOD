<?php

namespace Tests\Feature;

use App\Domain\Analytics\ReadModel\AnalyticsFactMaterializer;
use App\Domain\Analytics\Retailer\Retailer360IntelligenceService;
use App\Domain\Assistant\Security\RetailerSellOutAccessPolicy;
use App\Models\Role;
use App\Models\User;
use App\Services\WholesalePrincipal;
use Carbon\CarbonImmutable;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

final class Retailer360IntelligenceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_sell_in_is_wholesaler_specific_and_never_substituted_for_missing_sell_out(): void
    {
        $wholesaleStore = app(WholesalePrincipal::class)->storeId();
        $retailStore = $this->store('B2C', 'R360-NONE');
        $account = $this->linkRetailWholesaleAccount($retailStore);
        $wholesaleActor = $this->globalUser('B2B_ADMIN');
        $retailOwner = $this->storeUser($retailStore, 'B2C_STORE_ADMIN');
        $today = CarbonImmutable::now(AnalyticsFactMaterializer::TIMEZONE)->startOfDay();

        $this->receipt($wholesaleStore, $retailStore, $account, 24, 12.500, $today->addHours(10), 'R360-IN');

        app(AnalyticsFactMaterializer::class)->rebuild(
            $today->toDateString(),
            $today->toDateString(),
            [$retailStore],
        );

        try {
            app(Retailer360IntelligenceService::class)->read(
                $wholesaleActor,
                [$retailStore],
                $wholesaleStore,
                $today->toDateString(),
                $today->toDateString(),
            );
            $this->fail('Remote Retailer 360 must require live sell-out consent.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }

        $this->grant($retailOwner, $retailStore, $wholesaleStore);

        $result = app(Retailer360IntelligenceService::class)->read(
            $wholesaleActor,
            [$retailStore],
            $wholesaleStore,
            $today->toDateString(),
            $today->toDateString(),
        );

        $this->assertSame(Retailer360IntelligenceService::SCHEMA_VERSION, $result['schema_version']);
        $this->assertSame(12.5, $result['flows']['sell_in']['value']);
        $this->assertSame(24.0, $result['flows']['sell_in']['units']);
        $this->assertFalse($result['flows']['sell_in']['is_retail_sales']);
        $this->assertSame(0.0, $result['flows']['sell_out']['net_revenue']);
        $this->assertSame(0.0, $result['flows']['sell_out']['units']);
        $this->assertSame('none', $result['flows']['sell_out']['source']['state']);
        $this->assertFalse($result['flows']['sell_out']['wholesale_purchase_inference']);
        $this->assertFalse($result['sell_through']['available']);
        $this->assertSame('withheld', $result['benchmark']['state']);
        $this->assertSame('COHORT_PRIVACY_POLICY_PENDING_2229', $result['benchmark']['reason']);
        $this->assertFalse($result['benchmark']['peer_rows_exposed']);
        $this->assertSame('limited', $result['data_quality']['state']);
    }

    public function test_multi_store_account_aggregates_actual_sell_out_and_excludes_other_wholesaler_purchases(): void
    {
        $wholesaleStore = app(WholesalePrincipal::class)->storeId();
        $otherWholesaleStore = $this->store('B2B', 'R360-OTHER-WHOLESALE');
        $wholesaleActor = $this->globalUser('B2B_ADMIN');
        $today = CarbonImmutable::now(AnalyticsFactMaterializer::TIMEZONE)->startOfDay();
        $retailStores = [];

        foreach ([1, 2] as $index) {
            $retailStore = $this->store('B2C', 'R360-MULTI-'.$index);
            $account = $this->linkRetailWholesaleAccount($retailStore);
            $owner = $this->storeUser($retailStore, 'B2C_STORE_ADMIN');
            $this->grant($owner, $retailStore, $wholesaleStore);

            $product = $this->product($retailStore, 'b2c', 'R360-RETAIL-'.$index, 5.000, 20);
            $this->retailSale($retailStore, $product, 2, 10.000, $today->addHours(12), 'R360-SALE-'.$index);
            $this->receipt($wholesaleStore, $retailStore, $account, 12, 6.000, $today->addHours(9), 'R360-OWN-'.$index);
            $this->receipt($otherWholesaleStore, $retailStore, $account, 99, 99.000, $today->addHours(8), 'R360-OTHER-'.$index);

            $retailStores[] = $retailStore;
        }

        app(AnalyticsFactMaterializer::class)->rebuild(
            $today->toDateString(),
            $today->toDateString(),
            $retailStores,
        );

        $result = app(Retailer360IntelligenceService::class)->read(
            $wholesaleActor,
            $retailStores,
            $wholesaleStore,
            $today->toDateString(),
            $today->toDateString(),
        );

        $this->assertSame(2, $result['account']['store_count']);
        $this->assertSame(12.0, $result['flows']['sell_in']['value']);
        $this->assertSame(24.0, $result['flows']['sell_in']['units']);
        $this->assertSame(20.0, $result['flows']['sell_out']['net_revenue']);
        $this->assertSame(4.0, $result['flows']['sell_out']['units']);
        $this->assertSame('available', $result['flows']['sell_out']['source']['state']);
        $this->assertCount(2, $result['drilldown']['store']);
        $this->assertNotEmpty($result['drilldown']['sku']);

        $storeSellIn = collect($result['drilldown']['store'])->sum('sell_in_value');
        $this->assertSame(12.0, (float) $storeSellIn);
    }

    public function test_cohort_benchmark_stays_fail_closed_even_when_five_peers_exist_until_2229(): void
    {
        $wholesaleStore = app(WholesalePrincipal::class)->storeId();
        $wholesaleActor = $this->globalUser('B2B_ADMIN');
        $today = CarbonImmutable::now(AnalyticsFactMaterializer::TIMEZONE)->startOfDay();

        $target = $this->store('B2C', 'R360-TARGET');
        $this->linkRetailWholesaleAccount($target);
        $targetOwner = $this->storeUser($target, 'B2C_STORE_ADMIN');
        $this->grant($targetOwner, $target, $wholesaleStore);

        $allStores = [$target];
        $peerIds = [];
        for ($index = 1; $index <= 5; $index++) {
            $peer = $this->store('B2C', 'R360-PEER-'.$index);
            $this->linkRetailWholesaleAccount($peer);
            $owner = $this->storeUser($peer, 'B2C_STORE_ADMIN');
            $this->grant($owner, $peer, $wholesaleStore);
            $allStores[] = $peer;
            $peerIds[] = $peer;
        }

        app(AnalyticsFactMaterializer::class)->rebuild(
            $today->toDateString(),
            $today->toDateString(),
            $allStores,
        );

        $result = app(Retailer360IntelligenceService::class)->read(
            $wholesaleActor,
            [$target],
            $wholesaleStore,
            $today->toDateString(),
            $today->toDateString(),
        );

        $this->assertFalse($result['benchmark']['answerable']);
        $this->assertSame('withheld', $result['benchmark']['state']);
        $this->assertSame('COHORT_PRIVACY_POLICY_PENDING_2229', $result['benchmark']['reason']);
        $this->assertSame(2229, $result['benchmark']['policy_issue']);
        $this->assertFalse($result['benchmark']['peer_rows_exposed']);
        $this->assertFalse($result['benchmark']['peer_identifiers_exposed']);
        $this->assertTrue($result['benchmark']['differencing_protected']);
        $this->assertArrayNotHasKey('_privacy', $result);

        $encodedBenchmark = json_encode($result['benchmark'], JSON_THROW_ON_ERROR);
        foreach ($peerIds as $peerId) {
            $this->assertStringNotContainsString('"'.$peerId.'"', $encodedBenchmark);
        }
    }

    /** @return array{b2b_customer:int,legacy_customer:int} */
    private function linkRetailWholesaleAccount(int $retailStoreId): array
    {
        $legacy = (int) DB::table('customers')->insertGetId([
            'type' => 'b2b',
            'name' => 'Retail 360 '.$retailStoreId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $b2b = (int) DB::table('b2b_customers')->insertGetId([
            'legacy_customer_id' => $legacy,
            'name' => 'Retail 360 '.$retailStoreId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('retail_wholesale_accounts')->insert([
            'retail_store_id' => $retailStoreId,
            'b2b_customer_id' => $b2b,
            'owner_user_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return ['b2b_customer' => $b2b, 'legacy_customer' => $legacy];
    }

    private function grant(User $owner, int $retailStore, int $wholesaleStore): void
    {
        app(RetailerSellOutAccessPolicy::class)->grant(
            $owner,
            $retailStore,
            $wholesaleStore,
            RetailerSellOutAccessPolicy::BASIS_OPT_IN,
            retentionDays: 30,
            expiresAt: now()->addDays(7),
        );
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
        float $price = 1.000,
        float $stock = 0,
    ): int {
        $catalogId = (int) (DB::table('catalogs')
            ->where('store_id', $storeId)
            ->where('channel', $channel)
            ->value('id') ?? 0);
        if ($catalogId <= 0) {
            $catalogId = (int) DB::table('catalogs')->insertGetId([
                'store_id' => $storeId,
                'channel' => $channel,
                'code' => 'R360-CAT-'.uniqid(),
                'name' => 'Retail 360 Catalog',
                'is_active' => true,
                'is_migration_quarantine' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $unitId = (int) DB::table('units')->orderBy('id')->value('id');
        $productId = (int) DB::table('products')->insertGetId([
            'catalog_id' => $catalogId,
            'unit_id' => $unitId,
            'sku' => $sku,
            'name' => $sku,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('store_products')->insert([
            'store_id' => $storeId,
            'product_id' => $productId,
            'price' => $price,
            'cost_price' => null,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        if ($stock > 0) {
            $warehouse = (int) DB::table('warehouses')->insertGetId([
                'store_id' => $storeId,
                'code' => 'R360-WH-'.uniqid(),
                'name' => 'Retail 360 Warehouse',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            DB::table('inventories')->insert([
                'warehouse_id' => $warehouse,
                'product_id' => $productId,
                'quantity' => $stock,
                'reserved_quantity' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return $productId;
    }

    private function retailSale(
        int $storeId,
        int $productId,
        float $quantity,
        float $lineTotal,
        CarbonImmutable $at,
        string $number,
    ): void {
        $customer = (int) DB::table('customers')->insertGetId([
            'type' => 'b2c',
            'name' => $number.' Customer',
            'created_at' => $at->utc()->toDateTimeString(),
            'updated_at' => $at->utc()->toDateTimeString(),
        ]);
        $order = (int) DB::table('orders')->insertGetId([
            'store_id' => $storeId,
            'customer_id' => $customer,
            'order_number' => $number,
            'channel' => 'b2c',
            'status' => 'delivered',
            'currency' => 'KWD',
            'subtotal' => $lineTotal,
            'discount_total' => 0,
            'delivery_total' => 0,
            'grand_total' => $lineTotal,
            'created_at' => $at->utc()->toDateTimeString(),
            'updated_at' => $at->utc()->toDateTimeString(),
        ]);
        DB::table('order_items')->insert([
            'order_id' => $order,
            'product_id' => $productId,
            'sku_snapshot' => 'SKU-'.$productId,
            'name_snapshot' => 'Retail product',
            'quantity' => $quantity,
            'unit_price' => $lineTotal / $quantity,
            'line_total' => $lineTotal,
            'created_at' => $at->utc()->toDateTimeString(),
            'updated_at' => $at->utc()->toDateTimeString(),
        ]);
    }

    /** @param array{b2b_customer:int,legacy_customer:int} $account */
    private function receipt(
        int $wholesaleStoreId,
        int $retailStoreId,
        array $account,
        float $retailUnits,
        float $lineTotal,
        CarbonImmutable $receivedAt,
        string $number,
    ): void {
        $wholesaleProduct = $this->product($wholesaleStoreId, 'b2b', $number.'-W', $lineTotal, 100);
        $retailProduct = $this->product($retailStoreId, 'b2c', $number.'-R');

        $order = (int) DB::table('orders')->insertGetId([
            'store_id' => $wholesaleStoreId,
            'customer_id' => $account['legacy_customer'],
            'b2b_customer_id' => $account['b2b_customer'],
            'order_number' => $number,
            'channel' => 'b2b',
            'status' => 'delivered',
            'currency' => 'KWD',
            'subtotal' => $lineTotal,
            'discount_total' => 0,
            'delivery_total' => 0,
            'grand_total' => $lineTotal,
            'created_at' => $receivedAt->subHour()->utc()->toDateTimeString(),
            'updated_at' => $receivedAt->subHour()->utc()->toDateTimeString(),
        ]);
        $orderItem = (int) DB::table('order_items')->insertGetId([
            'order_id' => $order,
            'product_id' => $wholesaleProduct,
            'sku_snapshot' => $number.'-W',
            'name_snapshot' => 'Wholesale product',
            'quantity' => 1,
            'quantity_conversion_factor' => $retailUnits,
            'unit_price' => $lineTotal,
            'line_total' => $lineTotal,
            'created_at' => $receivedAt->subHour()->utc()->toDateTimeString(),
            'updated_at' => $receivedAt->subHour()->utc()->toDateTimeString(),
        ]);
        $replenishment = (int) DB::table('retail_replenishments')->insertGetId([
            'retail_store_id' => $retailStoreId,
            'source_order_id' => $order,
            'source_wholesale_store_id' => $wholesaleStoreId,
            'b2b_customer_id' => $account['b2b_customer'],
            'received_by_user_id' => null,
            'received_at' => $receivedAt->utc()->toDateTimeString(),
            'created_at' => $receivedAt->utc()->toDateTimeString(),
            'updated_at' => $receivedAt->utc()->toDateTimeString(),
        ]);
        DB::table('retail_replenishment_items')->insert([
            'replenishment_id' => $replenishment,
            'source_order_item_id' => $orderItem,
            'source_product_id' => $wholesaleProduct,
            'retail_product_id' => $retailProduct,
            'quantity' => $retailUnits,
            'quantity_conversion_factor' => $retailUnits,
            'unit_cost' => $lineTotal / $retailUnits,
            'line_total' => $lineTotal,
            'created_at' => $receivedAt->utc()->toDateTimeString(),
            'updated_at' => $receivedAt->utc()->toDateTimeString(),
        ]);
    }

    private function storeUser(int $storeId, string $roleCode): User
    {
        $user = User::query()->create([
            'name' => $roleCode,
            'email' => strtolower($roleCode).'-'.$storeId.'-'.uniqid().'@retailer360.test',
            'password' => 'password',
            'locale' => 'en',
            'is_active' => true,
        ]);
        DB::table('user_store_roles')->insert([
            'user_id' => $user->getKey(),
            'store_id' => $storeId,
            'role_id' => Role::query()->where('code', $roleCode)->value('id'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $user;
    }

    private function globalUser(string $roleCode): User
    {
        $user = User::query()->create([
            'name' => $roleCode,
            'email' => strtolower($roleCode).'-'.uniqid().'@retailer360.test',
            'password' => 'password',
            'locale' => 'en',
            'is_active' => true,
        ]);
        $user->roles()->attach(Role::query()->where('code', $roleCode)->firstOrFail());

        return $user;
    }
}
