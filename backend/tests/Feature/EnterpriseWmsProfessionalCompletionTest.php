<?php

namespace Tests\Feature;

use App\Enterprise\Modules\ModuleRegistry;
use App\Enterprise\Wms\ReorderPolicyService;
use App\Enterprise\Wms\WarehouseLocationService;
use App\Enterprise\Wms\WmsDemandPlanningService;
use App\Models\Role;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\Enterprise\Concerns\EnablesEnterpriseWms;
use Tests\Support\SyntheticErpWorkflowEvidence;
use Tests\TestCase;

final class EnterpriseWmsProfessionalCompletionTest extends TestCase
{
    use EnablesEnterpriseWms;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
        // Explicit test-only approval of the exact ERP module version; never a runtime default.
        config()->set('enterprise_module_readiness.certified_versions.inventory_wms', app(ModuleRegistry::class)->manifest('inventory_wms')->version);
        // Signed, synthetic P135 evidence is only needed when that other PR's
        // test helper is present. Neither standalone P110 nor runtime changes
        // the real ERP certification/attestation configuration.
        if (class_exists(SyntheticErpWorkflowEvidence::class)) {
            SyntheticErpWorkflowEvidence::install();
        }
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

    public function test_record_360_shows_uncertified_stock_title_in_ar_en_without_tenant_leak_or_mutation(): void
    {
        $fixture = $this->fixture();
        $actor = $this->inventoryUser($fixture['store_id'], 'en');
        $url = route('admin.erp.wms.index', [
            'tab' => 'overview',
            'store_id' => $fixture['store_id'],
            'warehouse_id' => $fixture['source_warehouse_id'],
            'inventory_id' => $fixture['source_inventory_id'],
        ]);

        foreach (['en', 'ar'] as $locale) {
            $actor->forceFill(['locale' => $locale])->save();
            $this->actingAs($actor->fresh())->get($url)
                ->assertOk()
                ->assertSee('data-v2-owner-title-report', false)
                ->assertSee('data-v2-owner-state="UNKNOWN"', false)
                ->assertSee('data-v2-owner-certification="unverified"', false)
                ->assertSee('data-v2-stock-custodian="warehouse"', false)
                ->assertSee('data-v2-owner-setup-readiness="hold"', false)
                ->assertSee('data-v2-owner-setup-help', false)
                ->assertSee('data-v2-owner-evidence-recheck', false)
                ->assertSee(__('enterprise_wms.record360.ownership.setup_readiness_label', [], $locale))
                ->assertSee(__('enterprise_wms.record360.ownership.setup_hold', [], $locale))
                ->assertSee(__('enterprise_wms.record360.ownership.setup_source', [], $locale))
                ->assertSee(__('enterprise_wms.record360.ownership.setup_example', [], $locale))
                ->assertSee(__('enterprise_wms.record360.ownership.setup_default', [], $locale))
                ->assertSee(__('enterprise_wms.record360.ownership.setup_validation', [], $locale))
                ->assertSee(__('enterprise_wms.record360.ownership.setup_permissions', [], $locale))
                ->assertSee(__('enterprise_wms.record360.ownership.setup_save_boundary', [], $locale))
                ->assertSee(__('enterprise_wms.record360.ownership.setup_recheck_help', [], $locale))
                ->assertDontSee('name="owner_kind"', false)
                ->assertDontSee('name="evidence_reference"', false)
                ->assertDontSee('data-v2-owner-setup-readiness="verified"', false)
                ->assertSee('data-v2-legal-valuation-status="unavailable"', false)
                ->assertSee(__('enterprise_wms.record360.ownership.custodian', [], $locale))
                ->assertSee(__('enterprise_wms.record360.ownership.warehouse_custodian', [], $locale))
                ->assertSee(__('enterprise_wms.record360.ownership.owner_valuation_unavailable', [], $locale))
                ->assertSee(__('enterprise_wms.record360.ownership.notice', [], $locale))
                ->assertSee(__('enterprise_wms.record360.ownership.states.UNKNOWN', [], $locale))
                ->assertSee(__('enterprise_wms.record360.ownership.kinds.UNKNOWN', [], $locale))
                ->assertDontSee('data-v2-certified-title', false);
        }

        $this->assertSame(0, DB::table('enterprise_inventory_owner_positions')
            ->where('inventory_id', $fixture['source_inventory_id'])->count());

        $checker = User::query()->create([
            'name' => 'Unverified Legal Title Checker',
            'email' => 'wms-pro-owner-checker@example.test',
            'password' => 'password',
            'is_active' => true,
        ]);
        DB::table('enterprise_inventory_owner_positions')->insert([
            'inventory_id' => $fixture['source_inventory_id'],
            'owner_key' => 'FOODEX',
            'owner_kind' => 'FOODEX',
            'supplier_id' => null,
            'quantity' => 10,
            'reserved_quantity' => 1,
            'source_inventory_updated_at' => DB::table('inventories')
                ->where('id', $fixture['source_inventory_id'])->value('updated_at'),
            'evidence_type' => 'untrusted_free_text',
            'evidence_reference' => 'NOT-A-VERIFIED-DEED',
            'proposed_by' => $actor->id,
            'certified_by' => $checker->id,
            'certified_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        foreach (['en', 'ar'] as $locale) {
            $actor->forceFill(['locale' => $locale])->save();
            $this->actingAs($actor->fresh())->get($url)
                ->assertOk()
                ->assertSee('data-v2-owner-state="UNVERIFIED"', false)
                ->assertSee('data-v2-owner-setup-readiness="hold"', false)
                ->assertSee(__('enterprise_wms.record360.ownership.setup_hold', [], $locale))
                ->assertDontSee('data-v2-owner-setup-readiness="verified"', false)
                ->assertSee('data-v2-owner-certification="unverified"', false)
                ->assertSee(__('enterprise_wms.record360.ownership.states.UNVERIFIED', [], $locale))
                ->assertSee(__('enterprise_wms.record360.ownership.kinds.FOODEX', [], $locale))
                ->assertDontSee('data-v2-certified-title', false);
        }

        $retailStoreTypeId = (int) DB::table('store_types')->where('code', 'B2C')->value('id');
        $foreignStoreId = (int) DB::table('stores')->insertGetId([
            'store_type_id' => $retailStoreTypeId,
            'code' => 'WMS-P110-FOREIGN',
            'name' => 'Out Of Scope WMS Store',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $foreignWarehouseId = (int) DB::table('warehouses')->insertGetId([
            'store_id' => $foreignStoreId,
            'code' => 'WMS-P110-FOREIGN',
            'name' => 'Foreign Warehouse',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $foreignInventoryId = (int) DB::table('inventories')->insertGetId([
            'warehouse_id' => $foreignWarehouseId,
            'product_id' => $fixture['product_id'],
            'quantity' => 1000,
            'reserved_quantity' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->actingAs($actor->fresh())->get(route('admin.erp.wms.index', [
            'tab' => 'overview',
            'store_id' => $fixture['store_id'],
            'warehouse_id' => $fixture['source_warehouse_id'],
            'inventory_id' => $foreignInventoryId,
        ]))->assertNotFound();

        $this->assertDatabaseHas('inventories', [
            'id' => $fixture['source_inventory_id'],
            'quantity' => 10,
            'reserved_quantity' => 1,
        ]);
        $this->assertDatabaseHas('enterprise_inventory_owner_positions', [
            'inventory_id' => $fixture['source_inventory_id'],
            'owner_kind' => 'FOODEX',
            'evidence_reference' => 'NOT-A-VERIFIED-DEED',
        ]);
    }

    public function test_direct_wms_http_forged_legal_owner_cost_and_cross_store_replay_never_grant_title(): void
    {
        $fixture = $this->fixture();
        $actor = $this->inventoryUser($fixture['store_id'], 'en');
        config()->set('security.mfa.enforce_privileged', false);
        $path = route('admin.erp.wms.adjustments.store');
        $request = [
            'store_id' => $fixture['store_id'],
            'warehouse_id' => $fixture['source_warehouse_id'],
            'product_id' => $fixture['product_id'],
            'quantity_delta' => -1,
            'classification' => 'damage',
            'reason' => 'P110 direct-HTTP forged ownership protection',
            'request_key' => 'p110-forged-owner-amount-replay',
            // Untrusted fields must never reach the canonical stock writer
            // or silently certify FOODEX title, vendor identity or COGS.
            'owner_kind' => 'FOODEX',
            'owner_key' => 'FOODEX',
            'supplier_id' => 999999,
            'certified_by' => $actor->id,
            'legal_title_verified' => true,
            'stock_cost' => '99999999.99',
        ];

        foreach (['en', 'ar'] as $locale) {
            $actor->forceFill(['locale' => $locale])->save();
            $this->actingAs($actor->fresh())->post($path, $request)->assertRedirect();
        }

        $this->assertDatabaseHas('inventories', [
            'id' => $fixture['source_inventory_id'], 'quantity' => 9, 'reserved_quantity' => 1,
        ]);
        $this->assertSame(1, DB::table('stock_movements')
            ->where('inventory_id', $fixture['source_inventory_id'])
            ->where('reference_type', 'wms_workbench_adjustment')->count());
        $this->assertDatabaseHas('enterprise_inventory_owner_positions', [
            'inventory_id' => $fixture['source_inventory_id'],
            'owner_kind' => 'UNKNOWN',
            'owner_key' => 'UNKNOWN',
            'supplier_id' => null,
            'quantity' => 9,
            'reserved_quantity' => 1,
            'certified_by' => null,
        ]);
        $this->assertSame(0, DB::table('enterprise_finance_journals')->count());

        $foreignStoreId = (int) DB::table('stores')->insertGetId([
            'store_type_id' => DB::table('store_types')->where('code', 'B2C')->value('id'),
            'code' => 'WMS-P110-DIRECT-FOREIGN-STORE',
            'name' => 'Foreign WMS Direct Caller Store',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $foreignActor = $this->retailInventoryUser($foreignStoreId, 'ar');

        // A retail inventory actor bound to its B2C store must not mutate
        // wholesale stock by forging the store, warehouse and owner fields.
        $this->actingAs($foreignActor)->post($path, $request)->assertNotFound();
        $this->assertDatabaseHas('inventories', [
            'id' => $fixture['source_inventory_id'], 'quantity' => 9, 'reserved_quantity' => 1,
        ]);
        $this->assertSame(1, DB::table('stock_movements')
            ->where('inventory_id', $fixture['source_inventory_id'])
            ->where('reference_type', 'wms_workbench_adjustment')->count());
        $this->assertSame(0, DB::table('enterprise_finance_journals')->count());
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
        $storeTypeId = (int) DB::table('store_types')->where('code', 'B2B')->value('id');
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
            'channel' => 'b2b',
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
        $role = Role::query()->where('code', 'INVENTORY')->firstOrFail();
        $user->roles()->syncWithoutDetaching([$role->id]);

        return $user;
    }

    private function retailInventoryUser(int $storeId, string $locale): User
    {
        $user = User::query()->create([
            'name' => 'Retail Inventory Operator',
            'email' => 'wms-retail-scope-'.$storeId.'@example.test',
            'password' => 'password',
            'locale' => $locale,
            'is_active' => true,
        ]);
        $this->grantPlatformErpAccess($user);
        $role = Role::query()->where('code', 'RETAIL_INVENTORY')->firstOrFail();
        $user->roles()->syncWithoutDetaching([$role->id]);

        DB::table('user_store_roles')->insert([
            'user_id' => $user->id,
            'store_id' => $storeId,
            'role_id' => $role->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

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
            'type' => 'b2b',
            'name' => $number.' Customer',
            'created_at' => $at->utc()->toDateTimeString(),
            'updated_at' => $at->utc()->toDateTimeString(),
        ]);
        $orderId = (int) DB::table('orders')->insertGetId([
            'store_id' => $storeId,
            'customer_id' => $customerId,
            'order_number' => $number.'-'.$storeId,
            'channel' => 'b2b',
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
