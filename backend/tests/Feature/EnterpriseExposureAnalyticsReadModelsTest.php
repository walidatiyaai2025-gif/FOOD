<?php

namespace Tests\Feature;

use App\Enterprise\Entitlements\EntitlementScope;
use App\Enterprise\Reporting\ReadModels\FinanceExposureReadModel;
use App\Enterprise\Reporting\ReadModels\StockExposureReadModel;
use App\Enterprise\Reporting\ReportQuery;
use App\Enterprise\Reporting\ReportRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class EnterpriseExposureAnalyticsReadModelsTest extends TestCase
{
    use RefreshDatabase;

    private const AR_DOCUMENTS = 'bi_test_ar_documents';

    private const AR_ALLOCATIONS = 'bi_test_ar_allocations';

    private const AP_DOCUMENTS = 'bi_test_ap_documents';

    private const AP_ALLOCATIONS = 'bi_test_ap_allocations';

    private const INVENTORIES = 'bi_test_inventories';

    private const WAREHOUSES = 'bi_test_warehouses';

    private const VALUATION = 'bi_test_valuation_policies';

    private const MOVEMENTS = 'bi_test_stock_movements';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('enterprise_reporting.sources', [
            'ar_documents' => self::AR_DOCUMENTS,
            'ar_allocations' => self::AR_ALLOCATIONS,
            'ap_documents' => self::AP_DOCUMENTS,
            'ap_allocations' => self::AP_ALLOCATIONS,
            'inventories' => self::INVENTORIES,
            'warehouses' => self::WAREHOUSES,
            'valuation_policies' => self::VALUATION,
            'stock_movements' => self::MOVEMENTS,
        ]);

        $this->createSourceTables();
    }

    protected function tearDown(): void
    {
        foreach ([
            self::MOVEMENTS,
            self::VALUATION,
            self::INVENTORIES,
            self::WAREHOUSES,
            self::AP_ALLOCATIONS,
            self::AP_DOCUMENTS,
            self::AR_ALLOCATIONS,
            self::AR_DOCUMENTS,
        ] as $table) {
            Schema::dropIfExists($table);
        }

        parent::tearDown();
    }

    public function test_ar_exposure_reconciles_to_posted_documents_and_allocations_with_legal_entity_isolation(): void
    {
        DB::table(self::AR_DOCUMENTS)->insert([
            [
                'id' => 1,
                'legal_entity_id' => 10,
                'store_id' => 100,
                'customer_id' => 501,
                'entry_date' => '2026-10-01',
                'due_date' => '2026-10-10',
                'amount' => 100,
                'currency_code' => 'KWD',
                'status' => 'posted',
            ],
            [
                'id' => 2,
                'legal_entity_id' => 20,
                'store_id' => 100,
                'customer_id' => 501,
                'entry_date' => '2026-10-01',
                'due_date' => '2026-10-10',
                'amount' => 999,
                'currency_code' => 'KWD',
                'status' => 'posted',
            ],
        ]);
        DB::table(self::AR_ALLOCATIONS)->insert([
            'id' => 1,
            'ar_document_id' => 1,
            'allocation_date' => '2026-10-05',
            'amount' => 25,
            'status' => 'posted',
        ]);

        $report = app(ReportRegistry::class)->require('finance.ar_exposure');
        $query = ReportQuery::fromArray($report, [
            'from' => '2026-10-16',
            'to' => '2026-10-16',
            'dimensions' => ['aging_bucket', 'customer_id', 'store_id', 'currency'],
            'measures' => ['documents', 'outstanding_amount'],
            'group_by' => ['aging_bucket', 'customer_id', 'store_id', 'currency'],
        ]);

        $operationalQueries = [];
        DB::listen(static function ($event) use (&$operationalQueries): void {
            $sql = strtolower((string) $event->sql);
            if (str_contains($sql, self::AR_DOCUMENTS)) {
                $operationalQueries[] = $sql;
            }
        });

        $before = DB::table(self::AR_DOCUMENTS)->count();
        $result = app(FinanceExposureReadModel::class)->read(
            $query,
            new EntitlementScope(EntitlementScope::LEGAL_ENTITY, 10),
        );

        $this->assertCount(1, $result['rows']);
        $row = $result['rows'][0];
        $this->assertSame('1_30', $row['aging_bucket']);
        $this->assertSame(501, $row['customer_id']);
        $this->assertSame(100, $row['store_id']);
        $this->assertSame('KWD', $row['currency']);
        $this->assertSame(1, $row['documents']);
        $this->assertSame('75.000', $row['outstanding_amount']);
        $this->assertSame(self::AR_DOCUMENTS, $row['drill_down']['source']);
        $this->assertSame(10, $row['drill_down']['legal_entity_id']);
        $this->assertSame(1, count($operationalQueries));
        $this->assertSame($before, DB::table(self::AR_DOCUMENTS)->count());
    }

    public function test_ap_exposure_honors_store_supplier_and_as_of_allocation_bounds(): void
    {
        DB::table(self::AP_DOCUMENTS)->insert([
            [
                'id' => 11,
                'legal_entity_id' => 10,
                'store_id' => 100,
                'supplier_reference' => 'SUP-001',
                'entry_date' => '2026-06-01',
                'due_date' => '2026-07-01',
                'amount' => 200,
                'currency_code' => 'KWD',
                'status' => 'posted',
            ],
            [
                'id' => 12,
                'legal_entity_id' => 10,
                'store_id' => 101,
                'supplier_reference' => 'SUP-001',
                'entry_date' => '2026-06-01',
                'due_date' => '2026-07-01',
                'amount' => 500,
                'currency_code' => 'KWD',
                'status' => 'posted',
            ],
        ]);
        DB::table(self::AP_ALLOCATIONS)->insert([
            [
                'id' => 1,
                'ap_document_id' => 11,
                'allocation_date' => '2026-10-01',
                'amount' => 50,
                'status' => 'posted',
            ],
            [
                'id' => 2,
                'ap_document_id' => 11,
                'allocation_date' => '2026-10-17',
                'amount' => 20,
                'status' => 'posted',
            ],
        ]);

        $report = app(ReportRegistry::class)->require('finance.ap_exposure');
        $query = ReportQuery::fromArray($report, [
            'from' => '2026-10-16',
            'to' => '2026-10-16',
            'filters' => [
                'store_id' => 100,
                'supplier_reference' => 'SUP-001',
            ],
            'dimensions' => ['supplier_reference', 'aging_bucket', 'store_id', 'currency'],
            'measures' => ['documents', 'outstanding_amount'],
            'group_by' => ['supplier_reference', 'aging_bucket', 'store_id', 'currency'],
        ]);

        $result = app(FinanceExposureReadModel::class)->read(
            $query,
            new EntitlementScope(EntitlementScope::LEGAL_ENTITY, 10),
        );

        $this->assertCount(1, $result['rows']);
        $row = $result['rows'][0];
        $this->assertSame('SUP-001', $row['supplier_reference']);
        $this->assertSame('91_plus', $row['aging_bucket']);
        $this->assertSame(100, $row['store_id']);
        $this->assertSame('150.000', $row['outstanding_amount']);
        $this->assertSame(1, $row['documents']);
    }

    public function test_stock_exposure_matches_canonical_inventory_reservations_value_and_aging_without_cross_store_leakage(): void
    {
        $today = CarbonImmutable::today();

        DB::table(self::WAREHOUSES)->insert([
            ['id' => 1, 'store_id' => 100],
            ['id' => 2, 'store_id' => 200],
        ]);
        DB::table(self::INVENTORIES)->insert([
            [
                'id' => 1,
                'warehouse_id' => 1,
                'product_id' => 701,
                'quantity' => 10,
                'reserved_quantity' => 2,
                'created_at' => $today->subDays(150)->toDateTimeString(),
            ],
            [
                'id' => 2,
                'warehouse_id' => 2,
                'product_id' => 701,
                'quantity' => 999,
                'reserved_quantity' => 0,
                'created_at' => $today->subDays(10)->toDateTimeString(),
            ],
        ]);
        DB::table(self::VALUATION)->insert([
            [
                'id' => 1,
                'warehouse_id' => 1,
                'product_id' => 701,
                'unit_cost' => 2.5,
                'is_active' => true,
            ],
        ]);
        DB::table(self::MOVEMENTS)->insert([
            [
                'id' => 1,
                'inventory_id' => 1,
                'store_id' => 100,
                'type' => 'purchase_receipt',
                'quantity' => 10,
                'created_at' => $today->subDays(120)->toDateTimeString(),
            ],
        ]);

        $report = app(ReportRegistry::class)->require('wms.stock_exposure');
        $query = ReportQuery::fromArray($report, [
            'from' => $today->toDateString(),
            'to' => $today->toDateString(),
            'dimensions' => ['warehouse_id', 'product_id', 'aging_bucket', 'stock_status'],
            'measures' => ['inventory_rows', 'on_hand', 'reserved', 'available', 'stock_value', 'unvalued_items'],
            'group_by' => ['warehouse_id', 'product_id', 'aging_bucket', 'stock_status'],
        ]);

        $operationalQueries = [];
        DB::listen(static function ($event) use (&$operationalQueries): void {
            $sql = strtolower((string) $event->sql);
            if (str_contains($sql, self::INVENTORIES)) {
                $operationalQueries[] = $sql;
            }
        });

        $result = app(StockExposureReadModel::class)->read(
            $query,
            new EntitlementScope(EntitlementScope::STORE, 100),
        );

        $this->assertCount(1, $result['rows']);
        $row = $result['rows'][0];
        $this->assertSame(1, $row['warehouse_id']);
        $this->assertSame(701, $row['product_id']);
        $this->assertSame('91_plus', $row['aging_bucket']);
        $this->assertSame('slow', $row['stock_status']);
        $this->assertSame(1, $row['inventory_rows']);
        $this->assertSame('10.000', $row['on_hand']);
        $this->assertSame('2.000', $row['reserved']);
        $this->assertSame('8.000', $row['available']);
        $this->assertSame('25.000', $row['stock_value']);
        $this->assertSame(0, $row['unvalued_items']);
        $this->assertSame(100, $row['drill_down']['store_id']);
        $this->assertSame(1, count($operationalQueries));
    }

    public function test_stock_value_never_fabricates_missing_cost_and_historical_reserved_snapshot_fails_closed(): void
    {
        $today = CarbonImmutable::today();

        DB::table(self::WAREHOUSES)->insert(['id' => 1, 'store_id' => 100]);
        DB::table(self::INVENTORIES)->insert([
            [
                'id' => 1,
                'warehouse_id' => 1,
                'product_id' => 701,
                'quantity' => 5,
                'reserved_quantity' => 1,
                'created_at' => $today->subDays(2)->toDateTimeString(),
            ],
        ]);

        $report = app(ReportRegistry::class)->require('wms.stock_exposure');
        $query = ReportQuery::fromArray($report, [
            'from' => $today->toDateString(),
            'to' => $today->toDateString(),
            'dimensions' => [],
            'measures' => ['stock_value', 'unvalued_items'],
            'group_by' => [],
        ]);

        $result = app(StockExposureReadModel::class)->read(
            $query,
            new EntitlementScope(EntitlementScope::STORE, 100),
        );

        $this->assertSame('0.000', $result['rows'][0]['stock_value']);
        $this->assertSame(1, $result['rows'][0]['unvalued_items']);

        $yesterday = $today->subDay()->toDateString();
        $historical = ReportQuery::fromArray($report, [
            'from' => $yesterday,
            'to' => $yesterday,
            'dimensions' => [],
            'measures' => ['on_hand'],
            'group_by' => [],
        ]);

        $this->expectException(ValidationException::class);
        app(StockExposureReadModel::class)->read(
            $historical,
            new EntitlementScope(EntitlementScope::STORE, 100),
        );
    }

    public function test_report_registry_allowlists_cross_module_analytics_fields(): void
    {
        $ar = app(ReportRegistry::class)->require('finance.ar_exposure');
        $stock = app(ReportRegistry::class)->require('wms.stock_exposure');

        $this->assertSame('finance_ar_exposure', $ar->readModel);
        $this->assertSame(1, $ar->maxRangeDays);
        $this->assertContains('customer_id', $ar->dimensions);
        $this->assertContains('outstanding_amount', $ar->measures);
        $this->assertSame('wms_stock_exposure', $stock->readModel);
        $this->assertContains('unvalued_items', $stock->measures);

        $this->expectException(ValidationException::class);
        ReportQuery::fromArray($ar, [
            'dimensions' => ['raw_sql'],
            'measures' => ['documents'],
        ]);
    }

    private function createSourceTables(): void
    {
        Schema::create(self::AR_DOCUMENTS, function (Blueprint $table): void {
            $table->unsignedBigInteger('id')->primary();
            $table->unsignedBigInteger('legal_entity_id');
            $table->unsignedBigInteger('store_id');
            $table->unsignedBigInteger('customer_id');
            $table->date('entry_date');
            $table->date('due_date');
            $table->decimal('amount', 18, 3);
            $table->char('currency_code', 3);
            $table->string('status', 24);
        });
        Schema::create(self::AR_ALLOCATIONS, function (Blueprint $table): void {
            $table->unsignedBigInteger('id')->primary();
            $table->unsignedBigInteger('ar_document_id');
            $table->date('allocation_date');
            $table->decimal('amount', 18, 3);
            $table->string('status', 24);
        });
        Schema::create(self::AP_DOCUMENTS, function (Blueprint $table): void {
            $table->unsignedBigInteger('id')->primary();
            $table->unsignedBigInteger('legal_entity_id');
            $table->unsignedBigInteger('store_id');
            $table->string('supplier_reference', 128);
            $table->date('entry_date');
            $table->date('due_date');
            $table->decimal('amount', 18, 3);
            $table->char('currency_code', 3);
            $table->string('status', 24);
        });
        Schema::create(self::AP_ALLOCATIONS, function (Blueprint $table): void {
            $table->unsignedBigInteger('id')->primary();
            $table->unsignedBigInteger('ap_document_id');
            $table->date('allocation_date');
            $table->decimal('amount', 18, 3);
            $table->string('status', 24);
        });
        Schema::create(self::WAREHOUSES, function (Blueprint $table): void {
            $table->unsignedBigInteger('id')->primary();
            $table->unsignedBigInteger('store_id');
        });
        Schema::create(self::INVENTORIES, function (Blueprint $table): void {
            $table->unsignedBigInteger('id')->primary();
            $table->unsignedBigInteger('warehouse_id');
            $table->unsignedBigInteger('product_id');
            $table->decimal('quantity', 18, 3);
            $table->decimal('reserved_quantity', 18, 3)->default(0);
            $table->timestamp('created_at');
        });
        Schema::create(self::VALUATION, function (Blueprint $table): void {
            $table->unsignedBigInteger('id')->primary();
            $table->unsignedBigInteger('warehouse_id');
            $table->unsignedBigInteger('product_id');
            $table->decimal('unit_cost', 18, 3)->nullable();
            $table->boolean('is_active')->default(true);
        });
        Schema::create(self::MOVEMENTS, function (Blueprint $table): void {
            $table->unsignedBigInteger('id')->primary();
            $table->unsignedBigInteger('inventory_id');
            $table->unsignedBigInteger('store_id');
            $table->string('type', 64);
            $table->decimal('quantity', 18, 3);
            $table->timestamp('created_at');
        });
    }
}
