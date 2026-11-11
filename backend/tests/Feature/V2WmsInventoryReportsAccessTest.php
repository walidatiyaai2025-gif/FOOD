<?php

namespace Tests\Feature;

use App\Enterprise\Entitlements\EntitlementScope;
use App\Enterprise\Entitlements\ModuleEntitlementManager;
use App\Enterprise\Modules\ModuleRegistry;
use App\Models\Role;
use App\Models\User;
use App\Models\UserStoreRole;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Enterprise\Concerns\EnablesEnterpriseWms;
use Tests\TestCase;

final class V2WmsInventoryReportsAccessTest extends TestCase
{
    use EnablesEnterpriseWms;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
        // Test-only WMS readiness: do not change production certification.
        config()->set(
            'enterprise_module_readiness.certified_versions.inventory_wms',
            app(ModuleRegistry::class)->manifest('inventory_wms')->version,
        );
    }

    public function test_authorized_inventory_reports_reuse_real_wms_read_models_and_are_bilingual_without_exposing_technical_codes(): void
    {
        $fixture = $this->fixture('P308-A');
        $actor = $this->inventoryOperator($fixture['store_id']);

        $before = DB::table('inventories')->where('id', $fixture['inventory_id'])->first();
        $beforeMovements = DB::table('stock_movements')->count();

        foreach (['en', 'ar'] as $locale) {
            $actor->forceFill(['locale' => $locale])->save();
            app()->setLocale($locale);

            $response = $this->actingAs($actor)->get(route('admin.erp.wms.index', [
                'tab' => 'reports',
                'store_id' => $fixture['store_id'],
                'warehouse_id' => $fixture['warehouse_id'],
            ]));

            $response->assertOk()
                ->assertSee('data-wms-reports', false)
                ->assertSee('data-report-code="wms.inventory_summary"', false)
                ->assertSee('data-report-code="wms.low_stock"', false)
                ->assertSee('data-report-code="wms.stock_aging"', false)
                ->assertSee('data-report-code="wms.lot_expiry"', false)
                ->assertSee('data-report-code="wms.count_variance"', false)
                ->assertSee('data-report-code="wms.adjustments"', false)
                ->assertSee('data-report-code="wms.transfer_performance"', false)
                ->assertSee('data-report-code="wms.demand_plan"', false)
                ->assertSee(__('enterprise_wms.reports.open_report'))
                ->assertSee(__('enterprise_wms.reports.inventory_summary'))
                ->assertSee(__('enterprise_wms.reports.stock_aging'))
                ->assertSee('dir="'.($locale === 'ar' ? 'rtl' : 'ltr').'"', false)
                ->assertDontSee('wms_inventory_summary')
                ->assertDontSee('wms_stock_aging');
        }

        $after = DB::table('inventories')->where('id', $fixture['inventory_id'])->first();
        $this->assertSame((string) $before->quantity, (string) $after->quantity);
        $this->assertSame((string) $before->reserved_quantity, (string) $after->reserved_quantity);
        $this->assertSame($beforeMovements, DB::table('stock_movements')->count());
    }

    public function test_inventory_reports_deny_cross_store_and_foreign_warehouse_requests(): void
    {
        $a = $this->fixture('P308-A');
        $b = $this->fixture('P308-B');
        $actor = $this->inventoryOperator($a['store_id']);

        $this->actingAs($actor)->get(route('admin.erp.wms.index', [
            'tab' => 'reports',
            'store_id' => $b['store_id'],
            'warehouse_id' => $b['warehouse_id'],
        ]))->assertForbidden();

        $this->actingAs($actor)->get(route('admin.erp.wms.index', [
            'tab' => 'reports',
            'store_id' => $a['store_id'],
            'warehouse_id' => $b['warehouse_id'],
        ]))->assertNotFound();
    }

    public function test_inventory_reports_deny_role_without_inventory_view_and_revoked_module_entitlement(): void
    {
        $fixture = $this->fixture('P308-A');
        $route = route('admin.erp.wms.index', [
            'tab' => 'reports',
            'store_id' => $fixture['store_id'],
            'warehouse_id' => $fixture['warehouse_id'],
        ]);

        $unprivileged = User::factory()->create(['is_active' => true]);
        $unprivileged->roles()->attach(Role::query()
            ->where('code', 'CUSTOMER_SERVICE_AGENT')->firstOrFail());
        $this->actingAs($unprivileged)->get($route)->assertForbidden();

        $operator = $this->inventoryOperator($fixture['store_id']);
        $this->actingAs($operator)->get($route)->assertOk();

        app(ModuleEntitlementManager::class)->set(
            'inventory_wms',
            new EntitlementScope(EntitlementScope::STORE, $fixture['store_id']),
            false,
        );

        $this->actingAs($operator)->get($route)->assertNotFound();
    }

    /** @return array{store_id:int,warehouse_id:int,inventory_id:int} */
    private function fixture(string $code): array
    {
        $storeTypeId = (int) DB::table('store_types')->where('code', 'B2C')->value('id');
        $storeId = (int) DB::table('stores')->insertGetId([
            'store_type_id' => $storeTypeId,
            'code' => $code,
            'name' => 'P308 '.$code,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $unitId = (int) DB::table('units')->orderBy('id')->value('id');
        $catalogId = (int) DB::table('catalogs')->insertGetId([
            'store_id' => $storeId,
            'channel' => 'b2c',
            'code' => 'catalog-'.$code,
            'name' => 'P308 Catalog '.$code,
            'is_active' => true,
            'is_migration_quarantine' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $productId = (int) DB::table('products')->insertGetId([
            'catalog_id' => $catalogId,
            'unit_id' => $unitId,
            'sku' => 'SKU-'.$code,
            'name' => 'P308 Product '.$code,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $warehouseId = (int) DB::table('warehouses')->insertGetId([
            'store_id' => $storeId,
            'code' => 'WH-'.$code,
            'name' => 'P308 Warehouse '.$code,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $inventoryId = (int) DB::table('inventories')->insertGetId([
            'warehouse_id' => $warehouseId,
            'product_id' => $productId,
            'quantity' => 10,
            'reserved_quantity' => 2,
            'created_at' => now()->subDays(45),
            'updated_at' => now(),
        ]);

        $this->enableEnterpriseWmsForStore($storeId);

        return [
            'store_id' => $storeId,
            'warehouse_id' => $warehouseId,
            'inventory_id' => $inventoryId,
        ];
    }

    private function inventoryOperator(int $storeId): User
    {
        $operator = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $this->grantPlatformErpAccess($operator);
        UserStoreRole::query()->create([
            'user_id' => $operator->getKey(),
            'store_id' => $storeId,
            'role_id' => Role::query()->where('code', 'RETAIL_INVENTORY')->firstOrFail()->getKey(),
        ]);

        return $operator;
    }
}
