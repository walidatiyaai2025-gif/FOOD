<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Models\UserStoreRole;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Enterprise\Concerns\EnablesEnterpriseWms;
use Tests\TestCase;

final class EnterpriseWmsWorkbenchTest extends TestCase
{
    use EnablesEnterpriseWms;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_authorized_operator_sees_localized_authoritative_stock_workspace(): void
    {
        $fixture = $this->fixture();
        $user = $this->inventoryUser($fixture['store_id'], 'en');

        $response = $this->actingAs($user)->get(route('admin.erp.wms.index', [
            'store_id' => $fixture['store_id'],
            'warehouse_id' => $fixture['source_warehouse_id'],
        ]));

        $response
            ->assertOk()
            ->assertSee('WMS Workbench')
            ->assertSee('Source Warehouse')
            ->assertSee('WMS Product')
            ->assertSee('dir="ltr"', false)
            ->assertSee('data-enterprise-wms="true"', false);

        $user->update(['locale' => 'ar']);

        $this->actingAs($user)
            ->get(route('admin.erp.wms.index', [
                'store_id' => $fixture['store_id'],
                'warehouse_id' => $fixture['source_warehouse_id'],
            ]))
            ->assertOk()
            ->assertSee('مساحة عمل المخازن WMS')
            ->assertSee('dir="rtl"', false);
    }

    public function test_workbench_adjustment_posts_to_canonical_inventory_and_stock_movement(): void
    {
        $fixture = $this->fixture();
        $user = $this->inventoryUser($fixture['store_id'], 'en');

        $this->actingAs($user)->post(route('admin.erp.wms.adjustments.store'), [
            'store_id' => $fixture['store_id'],
            'warehouse_id' => $fixture['source_warehouse_id'],
            'product_id' => $fixture['product_id'],
            'quantity_delta' => -2,
            'classification' => 'damage',
            'reason' => 'Damaged during warehouse handling',
            'notes' => 'Verified by warehouse operator.',
            'request_key' => 'ui-adjustment-001',
        ])->assertRedirect();

        $this->assertSame(
            8.0,
            (float) DB::table('inventories')
                ->where('id', $fixture['source_inventory_id'])
                ->value('quantity'),
        );
        $this->assertDatabaseHas('stock_movements', [
            'inventory_id' => $fixture['source_inventory_id'],
            'store_id' => $fixture['store_id'],
            'type' => 'damage',
            'quantity' => -2,
            'reference_type' => 'wms_workbench_adjustment',
        ]);
    }

    public function test_workbench_rejects_cross_store_context_even_when_ids_are_submitted_directly(): void
    {
        $fixture = $this->fixture();
        $user = $this->inventoryUser($fixture['store_id'], 'en');

        $this->actingAs($user)
            ->get(route('admin.erp.wms.index', [
                'store_id' => $fixture['other_store_id'],
                'warehouse_id' => $fixture['other_store_warehouse_id'],
            ]))
            ->assertNotFound();

        $this->actingAs($user)
            ->post(route('admin.erp.wms.adjustments.store'), [
                'store_id' => $fixture['other_store_id'],
                'warehouse_id' => $fixture['other_store_warehouse_id'],
                'product_id' => $fixture['other_product_id'],
                'quantity_delta' => -1,
                'classification' => 'loss',
                'reason' => 'Cross-store attempt',
                'request_key' => 'cross-store-attempt',
            ])
            ->assertNotFound();
    }

    /** @return array<string, int> */
    private function fixture(): array
    {
        $storeTypeId = (int) DB::table('store_types')->where('code', 'B2C')->value('id');
        $storeId = (int) DB::table('stores')->insertGetId([
            'store_type_id' => $storeTypeId,
            'code' => 'WMS-UI',
            'name' => 'WMS UI Store',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $otherStoreId = (int) DB::table('stores')->insertGetId([
            'store_type_id' => $storeTypeId,
            'code' => 'WMS-UI-OTHER',
            'name' => 'Other WMS UI Store',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $unitId = (int) DB::table('units')->insertGetId([
            'code' => 'EA-WMS-UI',
            'name' => 'Each',
            'decimal_places' => 3,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $catalogId = (int) DB::table('catalogs')->insertGetId([
            'store_id' => $storeId,
            'channel' => 'b2c',
            'code' => 'wms-ui',
            'name' => 'WMS UI Catalog',
            'is_active' => true,
            'is_migration_quarantine' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $otherCatalogId = (int) DB::table('catalogs')->insertGetId([
            'store_id' => $otherStoreId,
            'channel' => 'b2c',
            'code' => 'wms-ui-other',
            'name' => 'Other WMS UI Catalog',
            'is_active' => true,
            'is_migration_quarantine' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $productId = (int) DB::table('products')->insertGetId([
            'catalog_id' => $catalogId,
            'unit_id' => $unitId,
            'sku' => 'WMS-UI-P1',
            'name' => 'WMS Product',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $otherProductId = (int) DB::table('products')->insertGetId([
            'catalog_id' => $otherCatalogId,
            'unit_id' => $unitId,
            'sku' => 'WMS-UI-P2',
            'name' => 'Other WMS Product',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $sourceWarehouseId = (int) DB::table('warehouses')->insertGetId([
            'store_id' => $storeId,
            'code' => 'WMS-UI-SOURCE',
            'name' => 'Source Warehouse',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $targetWarehouseId = (int) DB::table('warehouses')->insertGetId([
            'store_id' => $storeId,
            'code' => 'WMS-UI-TARGET',
            'name' => 'Target Warehouse',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $otherStoreWarehouseId = (int) DB::table('warehouses')->insertGetId([
            'store_id' => $otherStoreId,
            'code' => 'WMS-UI-OTHER-WH',
            'name' => 'Other Warehouse',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $sourceInventoryId = (int) DB::table('inventories')->insertGetId([
            'warehouse_id' => $sourceWarehouseId,
            'product_id' => $productId,
            'quantity' => 10,
            'reserved_quantity' => 1,
            'created_at' => now()->subDays(15),
            'updated_at' => now(),
        ]);
        DB::table('inventories')->insert([
            [
                'warehouse_id' => $targetWarehouseId,
                'product_id' => $productId,
                'quantity' => 2,
                'reserved_quantity' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'warehouse_id' => $otherStoreWarehouseId,
                'product_id' => $otherProductId,
                'quantity' => 5,
                'reserved_quantity' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $this->enableEnterpriseWmsForStore($storeId);
        $this->enableEnterpriseWmsForStore($otherStoreId);

        return [
            'store_id' => $storeId,
            'other_store_id' => $otherStoreId,
            'source_warehouse_id' => $sourceWarehouseId,
            'target_warehouse_id' => $targetWarehouseId,
            'other_store_warehouse_id' => $otherStoreWarehouseId,
            'product_id' => $productId,
            'other_product_id' => $otherProductId,
            'source_inventory_id' => $sourceInventoryId,
        ];
    }

    private function inventoryUser(int $storeId, string $locale): User
    {
        $user = User::query()->create([
            'name' => 'WMS UI Operator',
            'email' => 'wms-ui-'.strtolower($locale).'@example.test',
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
}
