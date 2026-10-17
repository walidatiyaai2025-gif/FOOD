<?php

namespace Tests\Feature;

use App\Enterprise\Wms\ReorderPolicyService;
use App\Enterprise\Wms\WarehouseLocationService;
use App\Enterprise\Wms\WmsDemandPlanningService;
use App\Models\Role;
use App\Models\User;
use App\Models\UserStoreRole;
use Carbon\CarbonImmutable;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\Enterprise\Concerns\EnablesEnterpriseWms;
use Tests\TestCase;

final class EnterpriseWmsProfessionalCompletionTest extends TestCase
{
    use EnablesEnterpriseWms;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_location_metadata_and_provenance_never_create_a_second_stock_balance(): void
    {
        $fixture = $this->fixture();
        $actor = $this->inventoryUser($fixture['store_id'], 'en');
        $locations = app(WarehouseLocationService::class);

        foreach ([
            'enterprise_warehouse_zones',
            'enterprise_warehouse_bins',
            'enterprise_inventory_bin_assignments',
            'enterprise_wms_bin_provenance',
        ] as $table) {
            $columns = Schema::getColumnListing($table);
            $this->assertNotContains('quantity', $columns);
            $this->assertNotContains('reserved_quantity', $columns);
            $this->assertNotContains('available_quantity', $columns);
        }

        $zone = $locations->saveZone($actor, $fixture['source_warehouse_id'], 'PICK', 'Picking', true);
        $bin = $locations->saveBin($actor, $fixture['source_warehouse_id'], (int) $zone->id, 'A-01', 'Aisle A 01', true);
        $locations->assignInventory($actor, $fixture['source_inventory_id'], (int) $bin->id);

        $this->assertSame(
            10.0,
            (float) DB::table('inventories')->where('id', $fixture['source_inventory_id'])->value('quantity'),
        );
        $this->assertDatabaseHas('enterprise_inventory_bin_assignments', [
            'inventory_id' => $fixture['source_inventory_id'],
            'warehouse_id' => $fixture['source_warehouse_id'],
            'bin_id' => $bin->id,
        ]);

        $this->actingAs($actor)->post(route('admin.erp.wms.adjustments.store'), [
            'store_id' => $fixture['store_id'],
            'warehouse_id' => $fixture['source_warehouse_id'],
            'product_id' => $fixture['product_id'],
            'quantity_delta' => -1,
            'classification' => 'damage',
            'reason' => 'Damaged carton',
            'request_key' => 'wms-bin-provenance-adjustment',
        ])->assertRedirect();

        $movementId = (int) DB::table('stock_movements')
            ->where('inventory_id', $fixture['source_inventory_id'])
            ->where('reference_type', 'wms_workbench_adjustment')
            ->value('id');

        $this->assertGreaterThan(0, $movementId);
        $this->assertDatabaseHas('enterprise_wms_bin_provenance', [
            'subject_type' => 'stock_movement',
            'subject_id' => $movementId,
            'bin_id' => $bin->id,
            'role' => 'adjustment',
        ]);
        $this->assertSame(
            9.0,
            (float) DB::table('inventories')->where('id', $fixture['source_inventory_id'])->value('quantity'),
        );
    }

    public function test_demand_plan_uses_authoritative_sales_and_requires_explicit_approval(): void
    {
        $fixture = $this->fixture();
        $actor = $this->inventoryUser($fixture['store_id'], 'en');
        $asOf = CarbonImmutable::now('Asia/Kuwait');

        $this->sale(
            $fixture['store_id'],
            $fixture['product_id'],
            14,
            $asOf->subDay(),
            'delivered',
            'WMS-DEMAND-VALID',
        );
        $this->sale(
            $fixture['store_id'],
            $fixture['product_id'],
            100,
            $asOf->subDay(),
            'cancelled',
            'WMS-DEMAND-CANCELLED',
        );

        app(ReorderPolicyService::class)->upsert(
            $actor,
            $fixture['source_warehouse_id'],
            $fixture['product_id'],
            [
                'minimum_quantity' => 12,
                'reorder_quantity' => 8,
                'maximum_quantity' => 40,
                'is_active' => true,
            ],
        );

        $plan = app(WmsDemandPlanningService::class)->plan(
            $actor,
            $fixture['store_id'],
            $fixture['source_warehouse_id'],
            50,
            $asOf,
        );
        $row = $plan->firstWhere('product_id', $fixture['product_id']);

        $this->assertIsArray($row);
        $this->assertSame(14.0, $row['inputs']['sales_units']['days_1_7']);
        $this->assertSame(14.0, $row['inputs']['sales_units']['days_1_30']);
        $this->assertSame(1.0, $row['inputs']['velocity_units_per_day']);
        $this->assertSame(9.0, $row['inputs']['available']);
        $this->assertSame(8.0, $row['recommended_quantity']);
        $this->assertSame('reorder', $row['state']);
        $this->assertSame('policy_default', $row['inputs']['lead_time_source']);

        $this->assertSame(0, DB::table('enterprise_purchase_requests')->count());
        $approval = app(WmsDemandPlanningService::class)->approve(
            $actor,
            $fixture['store_id'],
            $fixture['source_warehouse_id'],
            $fixture['product_id'],
            'Approved from WMS demand review.',
        );

        $this->assertSame('approved', $approval->status);
        $this->assertSame(8.0, (float) $approval->recommended_quantity);
        $this->assertSame(0, DB::table('enterprise_purchase_requests')->count());
    }

    public function test_overview_reports_locations_and_record_360_share_the_existing_wms_route(): void
    {
        $fixture = $this->fixture();
        $actor = $this->inventoryUser($fixture['store_id'], 'en');
        $locations = app(WarehouseLocationService::class);
        $zone = $locations->saveZone($actor, $fixture['source_warehouse_id'], 'RECV', 'Receiving', true);
        $bin = $locations->saveBin($actor, $fixture['source_warehouse_id'], (int) $zone->id, 'R-01', 'Receiving 01', true);
        $locations->assignInventory($actor, $fixture['source_inventory_id'], (int) $bin->id);

        $base = [
            'store_id' => $fixture['store_id'],
            'warehouse_id' => $fixture['source_warehouse_id'],
        ];

        $this->actingAs($actor)
            ->get(route('admin.erp.wms.index', $base + ['tab' => 'overview']))
            ->assertOk()
            ->assertSee('data-foodex-module-overview', false)
            ->assertSee('data-foodex-module-chart', false)
            ->assertSee('Inventory value')
            ->assertSee('Stock-status distribution');

        $this->actingAs($actor)
            ->get(route('admin.erp.wms.index', $base + [
                'tab' => 'overview',
                'inventory_id' => $fixture['source_inventory_id'],
            ]))
            ->assertOk()
            ->assertSee('data-wms-record-360', false)
            ->assertSee('Inventory Record 360')
            ->assertSee('R-01');

        $this->actingAs($actor)
            ->get(route('admin.erp.wms.index', $base + ['tab' => 'locations']))
            ->assertOk()
            ->assertSee('data-wms-locations', false)
            ->assertSee('Warehouse locations & bins');

        $this->actingAs($actor)
            ->get(route('admin.erp.wms.index', $base + ['tab' => 'planning']))
            ->assertOk()
            ->assertSee('data-wms-demand-plan', false)
            ->assertSee('Demand & replenishment planning');

        $this->actingAs($actor)
            ->get(route('admin.erp.wms.index', $base + ['tab' => 'reports']))
            ->assertOk()
            ->assertSee('data-wms-reports', false)
            ->assertSee('Transfer performance')
            ->assertSee('Reorder / demand plan');
    }

    public function test_wms_completion_keeps_ar_en_translation_key_parity_and_report_contract(): void
    {
        $en = require lang_path('en/enterprise_wms.php');
        $ar = require lang_path('ar/enterprise_wms.php');

        $flatten = static function (array $values, string $prefix = '') use (&$flatten): array {
            $keys = [];
            foreach ($values as $key => $value) {
                $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;
                if (is_array($value)) {
                    array_push($keys, ...$flatten($value, $path));
                } else {
                    $keys[] = $path;
                }
            }
            sort($keys);

            return $keys;
        };

        $this->assertSame($flatten($en), $flatten($ar));

        $reporting = file_get_contents(app_path('Enterprise/Wms/WmsReportingService.php'));
        foreach ([
            'wms.inventory_summary',
            'wms.low_stock',
            'wms.stock_aging',
            'wms.lot_expiry',
            'wms.count_variance',
            'wms.adjustments',
            'wms.transfer_performance',
            'wms.demand_plan',
            'largeExportHandoff',
        ] as $needle) {
            $this->assertStringContainsString($needle, $reporting);
        }

        $migration = file_get_contents(database_path('migrations/2026_10_17_123500_create_enterprise_wms_location_planning_metadata.php'));
        $this->assertStringNotContainsString("decimal('quantity'", $migration);
        $this->assertStringNotContainsString("decimal('reserved_quantity'", $migration);
    }

    /** @return array<string,int> */
    private function fixture(): array
    {
        $storeTypeId = (int) DB::table('store_types')->where('code', 'B2C')->value('id');
        $storeId = (int) DB::table('stores')->insertGetId([
            'store_type_id' => $storeTypeId,
            'code' => 'WMS-PRO-COMP',
            'name' => 'WMS Professional Store',
            'is_active' => true,
            'created_at' => now()->subDays(60),
            'updated_at' => now(),
        ]);
        $unitId = (int) DB::table('units')->orderBy('id')->value('id');
        $catalogId = (int) DB::table('catalogs')->insertGetId([
            'store_id' => $storeId,
            'channel' => 'b2c',
            'code' => 'wms-professional',
            'name' => 'WMS Professional Catalog',
            'is_active' => true,
            'is_migration_quarantine' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $productId = (int) DB::table('products')->insertGetId([
            'catalog_id' => $catalogId,
            'unit_id' => $unitId,
            'sku' => 'WMS-PRO-SKU',
            'name' => 'WMS Professional Product',
            'is_active' => true,
            'created_at' => now()->subDays(60),
            'updated_at' => now(),
        ]);
        $sourceWarehouseId = (int) DB::table('warehouses')->insertGetId([
            'store_id' => $storeId,
            'code' => 'WMS-PRO-SOURCE',
            'name' => 'Source Warehouse',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $targetWarehouseId = (int) DB::table('warehouses')->insertGetId([
            'store_id' => $storeId,
            'code' => 'WMS-PRO-TARGET',
            'name' => 'Target Warehouse',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $sourceInventoryId = (int) DB::table('inventories')->insertGetId([
            'warehouse_id' => $sourceWarehouseId,
            'product_id' => $productId,
            'quantity' => 10,
            'reserved_quantity' => 1,
            'created_at' => now()->subDays(30),
            'updated_at' => now(),
        ]);
        $targetInventoryId = (int) DB::table('inventories')->insertGetId([
            'warehouse_id' => $targetWarehouseId,
            'product_id' => $productId,
            'quantity' => 2,
            'reserved_quantity' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->enableEnterpriseWmsForStore($storeId);

        return [
            'store_id' => $storeId,
            'product_id' => $productId,
            'source_warehouse_id' => $sourceWarehouseId,
            'target_warehouse_id' => $targetWarehouseId,
            'source_inventory_id' => $sourceInventoryId,
            'target_inventory_id' => $targetInventoryId,
        ];
    }

    private function inventoryUser(int $storeId, string $locale): User
    {
        $user = User::query()->create([
            'name' => 'WMS Professional Operator',
            'email' => 'wms-professional-'.strtolower($locale).'@example.test',
            'password' => 'password',
            'locale' => $locale,
            'is_active' => true,
        ]);
        $this->grantPlatformErpAccess($user);
        $role = Role::query()->where('code', 'RETAIL_INVENTORY')->firstOrFail();
        UserStoreRole::query()->create([
            'user_id' => $user->id,
            'store_id' => $storeId,
            'role_id' => $role->id,
        ]);

        $platformRole = Role::query()->firstOrCreate(
            ['code' => 'ERP_PLATFORM_TEST'],
            [
                'name' => 'ERP Platform Test',
                'scope' => 'global',
                'is_system' => false,
                'is_active' => true,
            ],
        );
        $user->roles()->syncWithoutDetaching([$platformRole->id]);

        return $user;
    }

    private function sale(
        int $storeId,
        int $productId,
        float $quantity,
        CarbonImmutable $at,
        string $status,
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
            'status' => $status,
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
}
