<?php

namespace Tests\Feature;

use App\Domain\Analytics\ReadModel\AnalyticsFactMaterializer;
use App\Domain\Analytics\ReadModel\AnalyticsReadModelService;
use App\Domain\Assistant\Security\RetailerSellOutAccessPolicy;
use App\Models\Role;
use App\Models\User;
use App\Services\WholesalePrincipal;
use Carbon\CarbonImmutable;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

final class AnalyticsReadModelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_rebuild_matches_transactional_semantics_is_idempotent_and_respects_kuwait_day_boundary(): void
    {
        $storeId = $this->store('b2c', 'AI-RM-PARITY');
        $productId = $this->product($storeId, 'b2c');

        $delivered = $this->order(
            $storeId,
            'b2c',
            'delivered',
            80,
            10,
            5,
            75,
            '2026-09-10 21:05:00',
        );
        $this->item($delivered, $productId, 2, 75);

        $this->order($storeId, 'b2c', 'cancelled', 40, 0, 0, 40, '2026-09-11 10:00:00');
        $this->order($storeId, 'b2c', 'refunded', 30, 0, 0, 30, '2026-09-11 11:00:00');

        $nextKuwaitDay = $this->order(
            $storeId,
            'b2c',
            'delivered',
            200,
            0,
            0,
            200,
            '2026-09-11 21:05:00',
        );
        $this->item($nextKuwaitDay, $productId, 9, 200);

        DB::table('payments')->insert([
            [
                'order_id' => $delivered,
                'invoice_id' => null,
                'provider' => 'test',
                'provider_reference' => 'AI-RM-PAY-1',
                'status' => 'captured',
                'amount' => 75,
                'currency' => 'KWD',
                'metadata' => null,
                'created_at' => '2026-09-11 08:00:00',
                'updated_at' => '2026-09-11 08:00:00',
            ],
            [
                'order_id' => DB::table('orders')->where('store_id', $storeId)->where('status', 'refunded')->value('id'),
                'invoice_id' => null,
                'provider' => 'test',
                'provider_reference' => 'AI-RM-PAY-R',
                'status' => 'refunded',
                'amount' => 30,
                'currency' => 'KWD',
                'metadata' => null,
                'created_at' => '2026-09-11 11:05:00',
                'updated_at' => '2026-09-11 11:05:00',
            ],
        ]);

        $materializer = app(AnalyticsFactMaterializer::class);
        $first = $materializer->rebuild('2026-09-11', '2026-09-11', [$storeId]);
        $second = $materializer->rebuild('2026-09-11', '2026-09-11', [$storeId]);

        $this->assertSame(1, $first['commerce_rows']);
        $this->assertSame(1, $second['commerce_rows']);
        $this->assertSame(
            1,
            DB::table('analytics_daily_commerce_facts')
                ->where('store_id', $storeId)
                ->where('fact_date', '2026-09-11')
                ->count(),
        );

        $fact = DB::table('analytics_daily_commerce_facts')
            ->where('store_id', $storeId)
            ->where('fact_date', '2026-09-11')
            ->firstOrFail();

        $this->assertSame(3, (int) $fact->orders_total);
        $this->assertSame(1, (int) $fact->orders_recognized);
        $this->assertSame(145.0, (float) $fact->gross_value);
        $this->assertSame(85.0, (float) $fact->gross_revenue);
        $this->assertSame(10.0, (float) $fact->discounts_total);
        $this->assertSame(75.0, (float) $fact->net_revenue);
        $this->assertSame(30.0, (float) $fact->refunded_value);
        $this->assertSame(1, (int) $fact->refunded_orders);
        $this->assertSame(40.0, (float) $fact->cancelled_value);
        $this->assertSame(1, (int) $fact->cancelled_orders);
        $this->assertSame(2.0, (float) $fact->units_sold);
        $this->assertSame(75.0, (float) $fact->average_order_value);
        $this->assertSame(75.0, (float) $fact->payments_collected);
        $this->assertSame(30.0, (float) $fact->payments_refunded);
        $this->assertSame('complete', $fact->quality_state);

        $this->assertDatabaseHas('analytics_pipeline_watermarks', [
            'pipeline' => 'commerce_daily',
            'store_id' => $storeId,
            'channel' => 'b2c',
            'materialized_through_date' => '2026-09-11',
        ]);
    }

    public function test_current_inventory_snapshot_exposes_stock_and_watermark_without_inventing_history(): void
    {
        $storeId = $this->store('b2c', 'AI-RM-INV');
        $productId = $this->product($storeId, 'b2c');
        $warehouseId = (int) DB::table('warehouses')->insertGetId([
            'store_id' => $storeId,
            'code' => 'AI-RM-WH-'.uniqid(),
            'name' => 'Analytics warehouse',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('inventories')->insert([
            'warehouse_id' => $warehouseId,
            'product_id' => $productId,
            'quantity' => 10,
            'reserved_quantity' => 2.5,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $today = CarbonImmutable::now(AnalyticsFactMaterializer::TIMEZONE)->toDateString();
        app(AnalyticsFactMaterializer::class)->rebuild($today, $today, [$storeId]);

        $snapshot = DB::table('analytics_inventory_snapshots')
            ->where('store_id', $storeId)
            ->where('snapshot_date', $today)
            ->firstOrFail();

        $this->assertSame(10.0, (float) $snapshot->on_hand_units);
        $this->assertSame(2.5, (float) $snapshot->reserved_units);
        $this->assertSame(7.5, (float) $snapshot->available_units);
        $this->assertSame(1, (int) $snapshot->sku_count);
        $this->assertDatabaseHas('analytics_pipeline_watermarks', [
            'pipeline' => 'inventory_snapshot',
            'store_id' => $storeId,
            'channel' => 'all',
            'materialized_through_date' => $today,
        ]);
    }

    public function test_read_service_enforces_tenant_isolation_and_never_expands_requested_store_scope(): void
    {
        $storeA = $this->store('b2c', 'AI-RM-ISO-A');
        $storeB = $this->store('b2c', 'AI-RM-ISO-B');
        $actor = $this->storeUser($storeA, 'B2C_STORE_ADMIN');

        app(AnalyticsFactMaterializer::class)->rebuild('2026-09-01', '2026-09-01', [$storeA, $storeB]);

        $own = app(AnalyticsReadModelService::class)->read(
            $actor,
            'b2c',
            [$storeA],
            '2026-09-01',
            '2026-09-01',
        );

        $this->assertSame([$storeA], $own['store_ids']);
        $this->assertCount(1, $own['facts']);
        $this->assertSame($storeA, $own['facts'][0]['store_id']);
        $this->assertFalse($own['limits']['model_sql_allowed']);

        $this->expectException(HttpException::class);

        app(AnalyticsReadModelService::class)->read(
            $actor,
            'b2c',
            [$storeA, $storeB],
            '2026-09-01',
            '2026-09-01',
        );
    }

    public function test_wholesale_sellout_read_requires_live_consent_and_honors_revocation(): void
    {
        $wholesaleStore = app(WholesalePrincipal::class)->storeId();
        $retailStore = $this->store('b2c', 'AI-RM-CONSENT');
        $this->linkRetailWholesaleAccount($retailStore);
        $wholesaleActor = $this->globalUser('B2B_ADMIN');
        $retailOwner = $this->storeUser($retailStore, 'B2C_STORE_ADMIN');
        $today = CarbonImmutable::now(AnalyticsFactMaterializer::TIMEZONE)->toDateString();

        app(AnalyticsFactMaterializer::class)->rebuild($today, $today, [$retailStore]);

        $this->assertSelloutDenied(fn () => app(AnalyticsReadModelService::class)->read(
            $wholesaleActor,
            'b2c',
            [$retailStore],
            $today,
            $today,
            $wholesaleStore,
        ));

        app(RetailerSellOutAccessPolicy::class)->grant(
            $retailOwner,
            $retailStore,
            $wholesaleStore,
            RetailerSellOutAccessPolicy::BASIS_OPT_IN,
            retentionDays: 30,
            expiresAt: now()->addDays(10),
        );

        $allowed = app(AnalyticsReadModelService::class)->read(
            $wholesaleActor,
            'b2c',
            [$retailStore],
            $today,
            $today,
            $wholesaleStore,
        );
        $this->assertSame([$retailStore], $allowed['store_ids']);
        $this->assertSame('sell_out', $allowed['flow']);

        app(RetailerSellOutAccessPolicy::class)->revoke(
            $retailOwner,
            $retailStore,
            $wholesaleStore,
        );

        $this->assertSelloutDenied(fn () => app(AnalyticsReadModelService::class)->read(
            $wholesaleActor,
            'b2c',
            [$retailStore],
            $today,
            $today,
            $wholesaleStore,
        ));
    }

    public function test_missing_or_old_recent_materialization_is_reported_as_incomplete_or_stale(): void
    {
        $storeId = $this->store('b2c', 'AI-RM-FRESH');
        $actor = $this->storeUser($storeId, 'B2C_STORE_ADMIN');
        $today = CarbonImmutable::now(AnalyticsFactMaterializer::TIMEZONE)->startOfDay();
        $yesterday = $today->subDay();

        $this->insertAggregateRow($storeId, $today->toDateString(), now('UTC')->subMinutes(30)->toDateTimeString());

        $stale = app(AnalyticsReadModelService::class)->read(
            $actor,
            'b2c',
            [$storeId],
            $today->toDateString(),
            $today->toDateString(),
        );
        $this->assertSame('stale', $stale['freshness']['state']);

        $incomplete = app(AnalyticsReadModelService::class)->read(
            $actor,
            'b2c',
            [$storeId],
            $yesterday->toDateString(),
            $today->toDateString(),
        );
        $this->assertSame('incomplete', $incomplete['freshness']['state']);
        $this->assertSame(2, $incomplete['freshness']['expected_rows']);
        $this->assertSame(1, $incomplete['freshness']['actual_rows']);
    }

    public function test_realistic_volume_90_day_query_is_indexed_bounded_and_result_capped(): void
    {
        $stores = [];
        for ($i = 1; $i <= 30; $i++) {
            $stores[] = $this->store('b2c', 'AI-RM-VOL-'.str_pad((string) $i, 2, '0', STR_PAD_LEFT));
        }

        $from = CarbonImmutable::parse('2026-06-01', AnalyticsFactMaterializer::TIMEZONE)->startOfDay();
        $rows = [];
        foreach ($stores as $storeId) {
            for ($offset = 0; $offset < 90; $offset++) {
                $day = $from->addDays($offset)->toDateString();
                $rows[] = $this->aggregateRow($storeId, $day, now('UTC')->toDateTimeString());

                if (count($rows) === 400) {
                    DB::table('analytics_daily_commerce_facts')->insert($rows);
                    $rows = [];
                }
            }
        }
        if ($rows !== []) {
            DB::table('analytics_daily_commerce_facts')->insert($rows);
        }

        $actor = $this->globalUser('SUPER_ADMIN');
        $selected = array_slice($stores, 0, 10);
        $started = microtime(true);

        $result = app(AnalyticsReadModelService::class)->read(
            $actor,
            'b2c',
            $selected,
            '2026-06-01',
            '2026-08-29',
        );

        $elapsed = microtime(true) - $started;
        $this->assertCount(900, $result['facts']);
        $this->assertSame('fresh', $result['freshness']['state']);
        $this->assertLessThan(3.0, $elapsed);

        $this->expectException(ValidationException::class);
        app(AnalyticsReadModelService::class)->read(
            $actor,
            'b2c',
            array_slice($stores, 0, 12),
            '2026-06-01',
            '2026-08-29',
        );
    }

    private function store(string $channel, string $code): int
    {
        $typeId = (int) DB::table('store_types')->where('code', strtoupper($channel))->value('id');

        return (int) DB::table('stores')->insertGetId([
            'store_type_id' => $typeId,
            'code' => $code,
            'name' => $code,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function product(int $storeId, string $channel): int
    {
        $unitId = (int) DB::table('units')->insertGetId([
            'code' => 'AI-RM-U-'.uniqid(),
            'name' => 'Each',
            'decimal_places' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $catalogId = (int) DB::table('catalogs')->insertGetId([
            'store_id' => $storeId,
            'channel' => $channel,
            'code' => 'AI-RM-C-'.uniqid(),
            'name' => 'Analytics catalog',
            'is_active' => true,
            'is_migration_quarantine' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return (int) DB::table('products')->insertGetId([
            'catalog_id' => $catalogId,
            'unit_id' => $unitId,
            'sku' => 'AI-RM-SKU-'.uniqid(),
            'name' => 'Analytics product',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function order(
        int $storeId,
        string $channel,
        string $status,
        float $subtotal,
        float $discount,
        float $delivery,
        float $grandTotal,
        string $createdAt,
    ): int {
        $customerId = (int) DB::table('customers')->insertGetId([
            'type' => $channel,
            'name' => 'Analytics customer',
            'email' => uniqid().'@analytics-read-model.test',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return (int) DB::table('orders')->insertGetId([
            'store_id' => $storeId,
            'customer_id' => $customerId,
            'order_number' => 'AI-RM-O-'.uniqid(),
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

    private function item(int $orderId, int $productId, float $quantity, float $lineTotal): void
    {
        DB::table('order_items')->insert([
            'order_id' => $orderId,
            'product_id' => $productId,
            'sku_snapshot' => 'AI-RM-SKU',
            'name_snapshot' => 'Analytics product',
            'quantity' => $quantity,
            'unit_price' => $quantity > 0 ? $lineTotal / $quantity : 0,
            'line_total' => $lineTotal,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function storeUser(int $storeId, string $roleCode): User
    {
        $user = User::query()->create([
            'name' => $roleCode,
            'email' => strtolower($roleCode).'-'.$storeId.'-'.uniqid().'@analytics-read-model.test',
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
            'email' => strtolower($roleCode).'-'.uniqid().'@analytics-read-model.test',
            'password' => 'password',
            'locale' => 'en',
            'is_active' => true,
        ]);
        $user->roles()->attach(Role::query()->where('code', $roleCode)->firstOrFail());

        return $user;
    }

    private function linkRetailWholesaleAccount(int $retailStoreId): void
    {
        $customerId = (int) DB::table('b2b_customers')->insertGetId([
            'name' => 'Analytics retail link '.$retailStoreId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('retail_wholesale_accounts')->insert([
            'retail_store_id' => $retailStoreId,
            'b2b_customer_id' => $customerId,
            'owner_user_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function assertSelloutDenied(callable $callback): void
    {
        try {
            $callback();
            $this->fail('Expected sell-out analytics access to be denied.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
    }

    private function insertAggregateRow(int $storeId, string $date, string $materializedAt): void
    {
        DB::table('analytics_daily_commerce_facts')->insert(
            $this->aggregateRow($storeId, $date, $materializedAt),
        );
    }

    /** @return array<string,mixed> */
    private function aggregateRow(int $storeId, string $date, string $materializedAt): array
    {
        return [
            'store_id' => $storeId,
            'channel' => 'b2c',
            'fact_date' => $date,
            'orders_total' => 1,
            'orders_recognized' => 1,
            'gross_value' => 10,
            'gross_revenue' => 10,
            'discounts_total' => 0,
            'net_revenue' => 10,
            'refunded_value' => 0,
            'refunded_orders' => 0,
            'cancelled_value' => 0,
            'cancelled_orders' => 0,
            'units_sold' => 1,
            'average_order_value' => 10,
            'payments_collected' => 10,
            'payments_refunded' => 0,
            'wholesale_purchases' => 0,
            'wholesale_purchase_units' => 0,
            'currency' => 'KWD',
            'source_max_updated_at' => $materializedAt,
            'materialized_at' => $materializedAt,
            'quality_state' => 'complete',
            'incomplete_reason' => null,
            'created_at' => $materializedAt,
            'updated_at' => $materializedAt,
        ];
    }
}
