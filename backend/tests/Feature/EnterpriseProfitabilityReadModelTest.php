<?php

namespace Tests\Feature;

use App\Enterprise\Entitlements\EntitlementScope;
use App\Enterprise\Reporting\CommercialProfitabilityProjectionRefresher;
use App\Enterprise\Reporting\ReadModels\CommercialProfitabilityReadModel;
use App\Enterprise\Reporting\ReportQuery;
use App\Enterprise\Reporting\ReportRegistry;
use App\Jobs\RefreshCommercialProfitabilityReadModel;
use App\Models\EnterpriseOutboxMessage;
use Carbon\CarbonImmutable;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class EnterpriseProfitabilityReadModelTest extends TestCase
{
    use RefreshDatabase;

    private const VALUATION = 'bi_test_profitability_valuation';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CoreReferenceSeeder::class);
        config()->set('enterprise_reporting.sources.valuation_policies', self::VALUATION);

        Schema::create(self::VALUATION, function (Blueprint $table): void {
            $table->unsignedBigInteger('id')->primary();
            $table->unsignedBigInteger('store_id');
            $table->unsignedBigInteger('warehouse_id');
            $table->unsignedBigInteger('product_id');
            $table->decimal('unit_cost', 18, 3);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists(self::VALUATION);

        parent::tearDown();
    }

    public function test_refresh_projects_discount_cost_and_latest_event_idempotently(): void
    {
        $store = $this->store('BI-PROFIT-A');
        $customer = $this->customer('Profitability Buyer');
        $createdAt = CarbonImmutable::now('UTC')->subDays(2);
        $order = $this->order($store, $customer, 'PROFIT-A-001', $createdAt);
        $occurredAt = $createdAt->addHour();

        $this->valuation($store, 11, 1001, '20.000', $createdAt->subDay());
        $this->valuation($store, 11, 1002, '10.000', $createdAt->subDay());

        $firstOutboxId = $this->event(
            $store,
            $order,
            $customer,
            11,
            'completed',
            '10.000',
            [
                ['order_item_id' => 101, 'product_id' => 1001, 'quantity' => '2.000', 'line_total' => '60.000'],
                ['order_item_id' => 102, 'product_id' => 1002, 'quantity' => '4.000', 'line_total' => '40.000'],
            ],
            $occurredAt,
        );

        $refresh = app(CommercialProfitabilityProjectionRefresher::class)->refreshStore($store);

        $this->assertSame(1, $refresh['scanned']);
        $this->assertSame(2, $refresh['projected']);
        $this->assertFalse($refresh['has_more']);

        $rows = DB::table('enterprise_bi_profitability_facts')
            ->where('store_id', $store)
            ->where('order_id', $order)
            ->orderBy('order_item_id')
            ->get();

        $this->assertCount(2, $rows);
        $this->assertSame('60.000', (string) $rows[0]->gross_revenue);
        $this->assertSame('6.000', (string) $rows[0]->discount_amount);
        $this->assertSame('54.000', (string) $rows[0]->net_revenue);
        $this->assertSame('40.000', (string) $rows[0]->cost_total);
        $this->assertSame('14.000', (string) $rows[0]->margin);
        $this->assertSame('40.000', (string) $rows[1]->gross_revenue);
        $this->assertSame('4.000', (string) $rows[1]->discount_amount);
        $this->assertSame('36.000', (string) $rows[1]->net_revenue);
        $this->assertSame('40.000', (string) $rows[1]->cost_total);
        $this->assertSame('-4.000', (string) $rows[1]->margin);

        $retry = app(CommercialProfitabilityProjectionRefresher::class)->refreshStore($store);
        $this->assertSame(0, $retry['projected']);
        $this->assertSame(1, $retry['skipped']);
        $this->assertSame(2, DB::table('enterprise_bi_profitability_facts')->where('order_id', $order)->count());

        $secondOutboxId = $this->event(
            $store,
            $order,
            $customer,
            11,
            'completed',
            '10.000',
            [
                ['order_item_id' => 101, 'product_id' => 1001, 'quantity' => '2.000', 'line_total' => '70.000'],
                ['order_item_id' => 102, 'product_id' => 1002, 'quantity' => '3.000', 'line_total' => '30.000'],
            ],
            $occurredAt->addHour(),
        );

        $this->assertGreaterThan($firstOutboxId, $secondOutboxId);

        $latest = app(CommercialProfitabilityProjectionRefresher::class)->refreshStore(
            $store,
            $firstOutboxId,
        );
        $this->assertSame(2, $latest['projected']);

        $rows = DB::table('enterprise_bi_profitability_facts')
            ->where('order_id', $order)
            ->orderBy('order_item_id')
            ->get();

        $this->assertCount(2, $rows);
        $this->assertSame($secondOutboxId, (int) $rows[0]->source_outbox_id);
        $this->assertSame('70.000', (string) $rows[0]->gross_revenue);
        $this->assertSame('7.000', (string) $rows[0]->discount_amount);
        $this->assertSame('63.000', (string) $rows[0]->net_revenue);
        $this->assertSame('23.000', (string) $rows[0]->margin);

        $staleReplay = app(CommercialProfitabilityProjectionRefresher::class)->refreshStore(
            $store,
            0,
            1,
        );
        $this->assertSame(0, $staleReplay['projected']);
        $this->assertSame($secondOutboxId, (int) DB::table('enterprise_bi_profitability_facts')
            ->where('order_id', $order)
            ->value('source_outbox_id'));
    }

    public function test_missing_or_changed_historical_cost_stays_explicit_and_read_model_is_store_scoped(): void
    {
        $store = $this->store('BI-PROFIT-MISSING');
        $otherStore = $this->store('BI-PROFIT-OTHER');
        $customer = $this->customer('Missing Cost Buyer');
        $otherCustomer = $this->customer('Other Cost Buyer');
        $createdAt = CarbonImmutable::now('UTC')->subDays(3);
        $order = $this->order($store, $customer, 'PROFIT-MISSING-001', $createdAt);
        $otherOrder = $this->order($otherStore, $otherCustomer, 'PROFIT-OTHER-001', $createdAt);

        // This policy changed after the commercial event. Historical cost is no longer
        // authoritative, so the projection must not backfill current cost into the past.
        $this->valuation($store, 21, 2001, '5.000', $createdAt->subDay(), $createdAt->addDays(2));

        $this->event(
            $store,
            $order,
            $customer,
            21,
            'completed',
            '0.000',
            [
                ['order_item_id' => 201, 'product_id' => 2001, 'quantity' => '2.000', 'line_total' => '30.000'],
            ],
            $createdAt->addHour(),
        );
        $this->event(
            $otherStore,
            $otherOrder,
            $otherCustomer,
            null,
            'completed',
            '0.000',
            [
                ['order_item_id' => 202, 'product_id' => 2002, 'quantity' => '1.000', 'line_total' => '999.000'],
            ],
            $createdAt->addHour(),
        );

        app(CommercialProfitabilityProjectionRefresher::class)->refreshStore($store);
        app(CommercialProfitabilityProjectionRefresher::class)->refreshStore($otherStore);

        $fact = DB::table('enterprise_bi_profitability_facts')
            ->where('store_id', $store)
            ->where('order_id', $order)
            ->first();

        $this->assertNotNull($fact);
        $this->assertSame('missing', $fact->cost_state);
        $this->assertNull($fact->unit_cost_snapshot);
        $this->assertNull($fact->cost_total);
        $this->assertNull($fact->margin);

        $report = app(ReportRegistry::class)->require('commercial.profitability');
        $query = ReportQuery::fromArray($report, [
            'from' => $createdAt->toDateString(),
            'to' => $createdAt->toDateString(),
            'dimensions' => ['product_id', 'channel', 'currency'],
            'measures' => ['orders', 'gross_revenue', 'discount_total', 'net_revenue', 'cost', 'margin', 'unvalued_lines'],
            'group_by' => ['product_id', 'channel', 'currency'],
        ]);

        $factQueries = [];
        DB::listen(static function ($event) use (&$factQueries): void {
            $sql = strtolower((string) $event->sql);
            if (str_contains($sql, 'enterprise_bi_profitability_facts')) {
                $factQueries[] = $sql;
            }
        });

        $result = app(CommercialProfitabilityReadModel::class)->read(
            $query,
            new EntitlementScope(EntitlementScope::STORE, $store),
        );

        $this->assertCount(1, $result['rows']);
        $row = $result['rows'][0];
        $this->assertSame(2001, $row['product_id']);
        $this->assertSame('b2c', $row['channel']);
        $this->assertSame('KWD', $row['currency']);
        $this->assertSame(1, $row['orders']);
        $this->assertSame('30.000', $row['gross_revenue']);
        $this->assertSame('30.000', $row['net_revenue']);
        $this->assertNull($row['cost']);
        $this->assertNull($row['margin']);
        $this->assertSame(1, $row['unvalued_lines']);
        $this->assertSame($store, $row['drill_down']['store_id']);
        $this->assertLessThanOrEqual(1, count($factQueries));

        $this->expectException(ValidationException::class);
        $mixedCurrencyQuery = ReportQuery::fromArray($report, [
            'from' => $createdAt->toDateString(),
            'to' => $createdAt->toDateString(),
            'dimensions' => ['product_id'],
            'measures' => ['net_revenue'],
            'group_by' => ['product_id'],
        ]);
        app(CommercialProfitabilityReadModel::class)->read(
            $mixedCurrencyQuery,
            new EntitlementScope(EntitlementScope::STORE, $store),
        );
    }

    public function test_refresh_is_bounded_resumable_and_job_chains_from_cursor(): void
    {
        $store = $this->store('BI-PROFIT-RESUME');
        $customer = $this->customer('Resume Buyer');
        $createdAt = CarbonImmutable::now('UTC')->subDay();

        $firstOrder = $this->order($store, $customer, 'PROFIT-R-001', $createdAt);
        $secondOrder = $this->order($store, $customer, 'PROFIT-R-002', $createdAt->addMinute());

        $firstOutbox = $this->event(
            $store,
            $firstOrder,
            $customer,
            null,
            'completed',
            '0.000',
            [
                ['order_item_id' => 301, 'product_id' => 3001, 'quantity' => '1.000', 'line_total' => '10.000'],
            ],
            $createdAt->addHour(),
        );
        $secondOutbox = $this->event(
            $store,
            $secondOrder,
            $customer,
            null,
            'completed',
            '0.000',
            [
                ['order_item_id' => 302, 'product_id' => 3002, 'quantity' => '1.000', 'line_total' => '20.000'],
            ],
            $createdAt->addHours(2),
        );

        $refresher = app(CommercialProfitabilityProjectionRefresher::class);
        $first = $refresher->refreshStore($store, 0, 1);

        $this->assertSame(1, $first['scanned']);
        $this->assertTrue($first['has_more']);
        $this->assertSame($firstOutbox, $first['next_cursor']);
        $this->assertSame(1, DB::table('enterprise_bi_profitability_facts')->count());

        $second = $refresher->refreshStore($store, $first['next_cursor'], 1);

        $this->assertSame(1, $second['scanned']);
        $this->assertFalse($second['has_more']);
        $this->assertSame($secondOutbox, $second['next_cursor']);
        $this->assertSame(2, DB::table('enterprise_bi_profitability_facts')->count());

        DB::table('enterprise_bi_profitability_facts')->delete();

        Bus::fake([RefreshCommercialProfitabilityReadModel::class]);
        (new RefreshCommercialProfitabilityReadModel($store, 0, 1))->handle($refresher);

        Bus::assertDispatched(
            RefreshCommercialProfitabilityReadModel::class,
            static fn (RefreshCommercialProfitabilityReadModel $job): bool =>
                $job->storeId === $store
                && $job->afterId === $firstOutbox
                && $job->limit === 1,
        );
    }

    public function test_registry_and_projection_indexes_are_bounded_contracts(): void
    {
        $report = app(ReportRegistry::class)->require('commercial.profitability');

        $this->assertSame('commercial_profitability', $report->readModel);
        $this->assertSame(31, $report->maxRangeDays);
        $this->assertContains('product_id', $report->dimensions);
        $this->assertContains('customer_id', $report->dimensions);
        $this->assertContains('channel', $report->dimensions);
        $this->assertContains('margin', $report->measures);
        $this->assertContains('unvalued_lines', $report->measures);

        $indexes = array_column(Schema::getIndexes('enterprise_bi_profitability_facts'), 'name');
        $this->assertContains('bi_profitability_store_created_idx', $indexes);
        $this->assertContains('bi_profitability_store_product_created_idx', $indexes);
        $this->assertContains('bi_profitability_store_customer_created_idx', $indexes);
        $this->assertContains('bi_profitability_store_channel_created_idx', $indexes);
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
        CarbonImmutable $createdAt,
    ): int {
        return (int) DB::table('orders')->insertGetId([
            'store_id' => $store,
            'customer_id' => $customer,
            'order_number' => $number,
            'channel' => 'b2c',
            'status' => 'completed',
            'currency' => 'KWD',
            'subtotal' => 100,
            'discount_total' => 0,
            'delivery_total' => 0,
            'grand_total' => 100,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);
    }

    private function valuation(
        int $store,
        int $warehouse,
        int $product,
        string $unitCost,
        CarbonImmutable $createdAt,
        ?CarbonImmutable $updatedAt = null,
    ): void {
        DB::table(self::VALUATION)->insert([
            'id' => DB::table(self::VALUATION)->count() + 1,
            'store_id' => $store,
            'warehouse_id' => $warehouse,
            'product_id' => $product,
            'unit_cost' => $unitCost,
            'is_active' => true,
            'created_at' => $createdAt,
            'updated_at' => $updatedAt ?? $createdAt,
        ]);
    }

    /**
     * @param list<array{
     *   order_item_id:int,
     *   product_id:int,
     *   quantity:string,
     *   line_total:string
     * }> $items
     */
    private function event(
        int $store,
        int $order,
        int $customer,
        ?int $warehouse,
        string $status,
        string $discount,
        array $items,
        CarbonImmutable $occurredAt,
    ): int {
        $eventId = (string) Str::uuid();
        $payload = [
            'id' => $eventId,
            'type' => CommercialProfitabilityProjectionRefresher::EVENT_TYPE,
            'version' => CommercialProfitabilityProjectionRefresher::EVENT_VERSION,
            'occurred_at' => $occurredAt->utc()->toIso8601String(),
            'scope' => [
                'store_id' => $store,
                'channel' => 'b2c',
                'customer_id' => $customer,
            ],
            'correlation_id' => null,
            'causation_id' => null,
            'payload' => [
                'schema' => CommercialProfitabilityProjectionRefresher::SCHEMA,
                'aggregate_type' => 'order',
                'aggregate_id' => $order,
                'fingerprint' => hash('sha256', $eventId),
                'data' => [
                    'contract_version' => 1,
                    'fact' => 'order_snapshot',
                    'source' => [
                        'order_id' => $order,
                        'order_number' => 'ORDER-'.$order,
                        'status' => $status,
                        'store_id' => $store,
                        'warehouse_id' => $warehouse,
                        'channel' => 'b2c',
                    ],
                    'customer' => [
                        'legacy_customer_id' => $customer,
                        'b2b_customer_id' => null,
                        'b2c_customer_id' => null,
                    ],
                    'money' => [
                        'currency' => 'KWD',
                        'subtotal' => '100.000',
                        'discount_total' => $discount,
                        'delivery_total' => '0.000',
                        'tax_total' => '0.000',
                        'grand_total' => '100.000',
                    ],
                    'pricing' => [],
                    'collection' => [
                        'collected_amount' => '0.000',
                        'collection_count' => 0,
                    ],
                    'items' => array_map(
                        static fn (array $item): array => [
                            ...$item,
                            'sku' => 'SKU-'.$item['product_id'],
                            'unit_price' => $item['quantity'] === '0.000'
                                ? '0.000'
                                : number_format(
                                    (float) $item['line_total'] / (float) $item['quantity'],
                                    3,
                                    '.',
                                    '',
                                ),
                        ],
                        $items,
                    ),
                ],
            ],
        ];

        return (int) EnterpriseOutboxMessage::query()->create([
            'event_id' => $eventId,
            'event_type' => CommercialProfitabilityProjectionRefresher::EVENT_TYPE,
            'event_version' => CommercialProfitabilityProjectionRefresher::EVENT_VERSION,
            'payload' => json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'status' => 'pending',
            'attempt_count' => 0,
            'available_at' => now(),
        ])->getKey();
    }
}
