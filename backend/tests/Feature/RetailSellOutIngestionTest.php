<?php

namespace Tests\Feature;

use App\Domain\Analytics\ReadModel\AnalyticsFactMaterializer;
use App\Domain\Analytics\ReadModel\AnalyticsReadModelService;
use App\Domain\Analytics\SellOut\RetailSellOutIngestionService;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

final class RetailSellOutIngestionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_external_feed_maps_sku_handles_timezone_refund_duplicates_rejections_and_idempotency(): void
    {
        $storeId = $this->store('AI-SO-INGEST');
        $productId = $this->product($storeId, 'AI-SO-CANON');
        $actor = $this->storeUser($storeId);

        $service = app(RetailSellOutIngestionService::class);
        $connection = $service->connect($actor, $storeId, 'pos_api', 'Test POS', 'terminal-1', 'UTC');
        $service->mapSku($actor, $connection['id'], 'POS-001', $productId);

        $run = $service->ingest($actor, $connection['id'], 'batch-001', [
            [
                'event_id' => 'evt-sale-1',
                'transaction_id' => 'tx-1',
                'sku' => 'POS-001',
                'event_type' => 'sale',
                'occurred_at' => '2026-09-10 21:30:00',
                'quantity' => 2,
                'gross_amount' => 10,
                'discount_amount' => 1,
                'net_amount' => 9,
                'currency' => 'KWD',
            ],
            [
                'event_id' => 'evt-refund-1',
                'transaction_id' => 'tx-2',
                'sku' => 'POS-001',
                'event_type' => 'refund',
                'occurred_at' => '2026-09-10 22:00:00',
                'quantity' => 1,
                'gross_amount' => 5,
                'discount_amount' => 0,
                'net_amount' => 5,
                'currency' => 'KWD',
            ],
            [
                'event_id' => 'evt-sale-1',
                'transaction_id' => 'tx-1',
                'sku' => 'POS-001',
                'event_type' => 'sale',
                'occurred_at' => '2026-09-10 21:30:00',
                'quantity' => 2,
                'gross_amount' => 10,
                'discount_amount' => 1,
                'net_amount' => 9,
                'currency' => 'KWD',
            ],
            [
                'event_id' => 'evt-bad-sku',
                'transaction_id' => 'tx-3',
                'sku' => 'UNKNOWN',
                'event_type' => 'sale',
                'occurred_at' => '2026-09-10 23:00:00',
                'quantity' => 1,
                'gross_amount' => 3,
                'discount_amount' => 0,
                'net_amount' => 3,
                'currency' => 'KWD',
            ],
        ]);

        $this->assertSame('partial', $run['status']);
        $this->assertSame(4, $run['rows_received']);
        $this->assertSame(2, $run['rows_accepted']);
        $this->assertSame(1, $run['rows_duplicate']);
        $this->assertSame(1, $run['rows_rejected']);
        $this->assertTrue($run['reconciliation']['balanced']);
        $this->assertSame('bad_sku', $run['rejections'][0]['code']);

        $this->assertSame(
            2,
            DB::table('retail_sellout_events')->where('connection_id', $connection['id'])->count(),
        );
        $this->assertDatabaseHas('retail_sellout_events', [
            'source_event_id' => 'evt-sale-1',
            'business_date' => '2026-09-11',
            'event_type' => 'sale',
        ]);
        $this->assertDatabaseHas('retail_sellout_events', [
            'source_event_id' => 'evt-refund-1',
            'business_date' => '2026-09-11',
            'event_type' => 'refund',
        ]);

        $sameRun = $service->ingest($actor, $connection['id'], 'batch-001', [[
            'event_id' => 'should-not-run',
        ]]);
        $this->assertSame($run['id'], $sameRun['id']);
        $this->assertSame(2, DB::table('retail_sellout_events')->count());

        app(AnalyticsFactMaterializer::class)->rebuild('2026-09-11', '2026-09-11', [$storeId]);
        $fact = DB::table('analytics_daily_commerce_facts')
            ->where('store_id', $storeId)
            ->where('fact_date', '2026-09-11')
            ->firstOrFail();

        $this->assertSame(2, (int) $fact->orders_total);
        $this->assertSame(1, (int) $fact->orders_recognized);
        $this->assertSame(9.0, (float) $fact->net_revenue);
        $this->assertSame(5.0, (float) $fact->refunded_value);
        $this->assertSame(2.0, (float) $fact->units_sold);
        $this->assertSame('incomplete', (string) $fact->quality_state);
    }

    public function test_partial_feed_is_exposed_to_analytics_and_connection_revocation_blocks_future_ingest(): void
    {
        $storeId = $this->store('AI-SO-PARTIAL');
        $otherStore = $this->store('AI-SO-OTHER');
        $productId = $this->product($storeId, 'AI-SO-PARTIAL-SKU');
        $actor = $this->storeUser($storeId);
        $otherActor = $this->storeUser($otherStore);
        $service = app(RetailSellOutIngestionService::class);

        $connection = $service->connect($actor, $storeId, 'csv', 'CSV upload', 'monthly-feed', 'Asia/Kuwait');
        $service->mapSku($actor, $connection['id'], 'CSV-1', $productId);
        $service->ingest($actor, $connection['id'], 'partial-1', [
            [
                'event_id' => 'ok-1',
                'transaction_id' => 'tx-1',
                'sku' => 'CSV-1',
                'event_type' => 'sale',
                'occurred_at' => '2026-09-12 12:00:00',
                'quantity' => 1,
                'gross_amount' => 5,
                'discount_amount' => 0,
                'net_amount' => 5,
                'currency' => 'KWD',
            ],
            [
                'event_id' => 'bad-1',
                'transaction_id' => 'tx-2',
                'sku' => 'MISSING',
                'event_type' => 'sale',
                'occurred_at' => '2026-09-12 13:00:00',
                'quantity' => 1,
                'gross_amount' => 5,
                'discount_amount' => 0,
                'net_amount' => 5,
                'currency' => 'KWD',
            ],
        ]);

        app(AnalyticsFactMaterializer::class)->rebuild('2026-09-12', '2026-09-12', [$storeId]);
        $analytics = app(AnalyticsReadModelService::class)->read(
            $actor,
            'b2c',
            [$storeId],
            '2026-09-12',
            '2026-09-12',
        );

        $this->assertSame('partial', $analytics['sellout_feed'][0]['state']);
        $this->assertFalse($analytics['sellout_feed'][0]['wholesale_purchase_inference']);
        $this->assertSame(50.0, $analytics['sellout_feed'][0]['quality_score']);

        try {
            $service->connections($otherActor, $storeId);
            $this->fail('Expected cross-store connection access to fail.');
        } catch (HttpException $exception) {
            $this->assertSame(404, $exception->getStatusCode());
        }

        $service->revoke($actor, $connection['id']);

        try {
            $service->ingest($actor, $connection['id'], 'after-revoke', [[
                'event_id' => 'blocked',
            ]]);
            $this->fail('Expected revoked connection to reject future ingestion.');
        } catch (HttpException $exception) {
            $this->assertSame(409, $exception->getStatusCode());
        }
    }

    public function test_no_sellout_feed_is_explicit_and_never_substitutes_wholesale_purchase_data(): void
    {
        $storeId = $this->store('AI-SO-NONE');
        $actor = $this->storeUser($storeId);

        app(AnalyticsFactMaterializer::class)->rebuild('2026-09-13', '2026-09-13', [$storeId]);

        $analytics = app(AnalyticsReadModelService::class)->read(
            $actor,
            'b2c',
            [$storeId],
            '2026-09-13',
            '2026-09-13',
        );

        $this->assertSame('none', $analytics['sellout_feed'][0]['state']);
        $this->assertFalse($analytics['sellout_feed'][0]['wholesale_purchase_inference']);
        $this->assertSame(0.0, $analytics['facts'][0]['net_revenue']);
        $this->assertSame(0.0, $analytics['facts'][0]['units']);
    }

    private function store(string $code): int
    {
        $typeId = (int) DB::table('store_types')->where('code', 'B2C')->value('id');

        return (int) DB::table('stores')->insertGetId([
            'store_type_id' => $typeId,
            'code' => $code,
            'name' => $code,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function product(int $storeId, string $sku): int
    {
        $unitId = (int) DB::table('units')->insertGetId([
            'code' => 'AI-SO-U-'.uniqid(),
            'name' => 'Each',
            'decimal_places' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $catalogId = (int) DB::table('catalogs')->insertGetId([
            'store_id' => $storeId,
            'channel' => 'b2c',
            'code' => 'AI-SO-C-'.uniqid(),
            'name' => 'Sell-out catalog',
            'is_active' => true,
            'is_migration_quarantine' => false,
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

    private function storeUser(int $storeId): User
    {
        $user = User::query()->create([
            'name' => 'Sell-out owner',
            'email' => 'sellout-'.$storeId.'-'.uniqid().'@foodex.test',
            'password' => 'password',
            'locale' => 'en',
            'is_active' => true,
        ]);

        DB::table('user_store_roles')->insert([
            'user_id' => $user->getKey(),
            'store_id' => $storeId,
            'role_id' => Role::query()->where('code', 'B2C_STORE_ADMIN')->value('id'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $user;
    }
}
